<?php

namespace App\Http\Controllers;

use App\Services\TwoFactorAuthenticator;
use App\Services\TwoFactorLoginChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Second sign-in step for accounts with an authenticator app, shared by the customer platform and the console.
 */
class TwoFactorChallengeController extends Controller
{
    private const MAX_ATTEMPTS_PER_MINUTE = 5;

    public function __construct(
        private TwoFactorLoginChallenge $loginChallenge,
        private TwoFactorAuthenticator $authenticator,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        if ($this->loginChallenge->pendingUser($request, $this->guardName($request)) === null) {
            return $this->restartLogin($request);
        }

        return view('auth.two-factor.challenge', [
            'isConsole' => $this->isConsole($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $guardName = $this->guardName($request);
        $user = $this->loginChallenge->pendingUser($request, $guardName);

        if ($user === null) {
            return $this->restartLogin($request);
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:40'],
        ]);
        $code = trim((string) ($validated['code'] ?? ''));
        $recoveryCode = trim((string) ($validated['recovery_code'] ?? ''));

        if ($code === '' && $recoveryCode === '') {
            throw ValidationException::withMessages(['code' => '請輸入驗證器 App 顯示的 6 位數驗證碼，或一組復原碼。']);
        }

        $key = 'two-factor-failed:'.$guardName.':'.$user->getKey().'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS_PER_MINUTE)) {
            throw ValidationException::withMessages(['code' => '驗證嘗試次數過多，請於一分鐘後再試。']);
        }

        $usedRecoveryCode = $code === '' && $this->authenticator->consumeRecoveryCode($user, $recoveryCode);
        $passed = $usedRecoveryCode || ($code !== '' && $this->authenticator->verifyAndConsumeCode($user, $code));

        if (! $passed) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                $code === '' ? 'recovery_code' : 'code' => $code === '' ? '復原碼不正確或已使用過。' : '驗證碼不正確或已使用過，請等待下一組驗證碼。',
            ]);
        }

        RateLimiter::clear($key);
        $remember = $this->loginChallenge->remember($request, $guardName);
        $this->loginChallenge->clear($request, $guardName);

        if (! $this->isConsole($request) && ($user->isPlatformAdmin() || ! $user->hasActiveOrganization())) {
            throw ValidationException::withMessages(['code' => '所屬組織的訂閱已到期或已停用，登入功能已暫停。']);
        }

        if ($this->isConsole($request) && ! $user->isPlatformAdmin()) {
            return $this->restartLogin($request);
        }

        Auth::guard($guardName)->login($user, $remember);

        $response = $this->isConsole($request)
            ? app(PlatformAuthController::class)->completeLogin($request)
            : app(AuthController::class)->completeLogin($request);

        return $usedRecoveryCode
            ? $response->with('status', '已使用一組復原碼登入，剩餘 '.$user->remainingTwoFactorRecoveryCodeCount().' 組。復原碼用完前請到帳戶安全重新產生。')
            : $response;
    }

    private function restartLogin(Request $request): RedirectResponse
    {
        return redirect()
            ->route($this->isConsole($request) ? 'platform.login' : 'login')
            ->with('status', '登入驗證已逾時或尚未輸入密碼，請重新登入。');
    }

    private function isConsole(Request $request): bool
    {
        return $request->routeIs('platform.*');
    }

    private function guardName(Request $request): string
    {
        return $this->isConsole($request) ? PlatformAuthController::GUARD : 'web';
    }
}
