<?php

namespace App\Http\Controllers;

use App\Models\PlatformAuditAction;
use App\Models\User;
use App\Services\PlatformAuditLogger;
use App\Services\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Lets a signed-in user turn on, confirm, turn off and renew recovery codes for an authenticator app.
 */
class TwoFactorSettingsController extends Controller
{
    public const RECOVERY_CODES_SESSION_KEY = 'two_factor_recovery_codes';

    public function __construct(
        private TwoFactorAuthenticator $authenticator,
        private PlatformAuditLogger $auditLogger,
    ) {}

    /**
     * Console-only page; the customer platform shows the same section on its account security page.
     */
    public function show(Request $request): View
    {
        return view('platform.account.security', $this->viewData($request));
    }

    /**
     * @return array{twoFactorUser: User, twoFactorSetup: array{secret: string, uri: string}|null, twoFactorRoutePrefix: string}
     */
    public function viewData(Request $request): array
    {
        $user = $this->user($request);
        $setup = null;

        if (! $user->hasTwoFactorEnabled() && is_string($user->two_factor_secret) && $user->two_factor_secret !== '') {
            $setup = [
                'secret' => $this->authenticator->formatSecretForDisplay($user->two_factor_secret),
                'uri' => $this->authenticator->provisioningUri($user->two_factor_secret, (string) $user->email),
            ];
        }

        return [
            'twoFactorUser' => $user,
            'twoFactorSetup' => $setup,
            'twoFactorRoutePrefix' => $this->routePrefix($request),
        ];
    }

    public function enable(Request $request): RedirectResponse
    {
        $this->validateCurrentPassword($request);
        $user = $this->user($request);

        if ($user->hasTwoFactorEnabled()) {
            return $this->back($request)->with('status', '雙因素驗證已經啟用。');
        }

        $user->forceFill([
            'two_factor_secret' => $this->authenticator->generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();

        return $this->back($request)->with('status', '請用驗證器 App 加入帳戶，再輸入 App 顯示的驗證碼完成啟用。');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
        ], [
            'code.required' => '請輸入驗證器 App 顯示的 6 位數驗證碼。',
        ]);
        $user = $this->user($request);

        if ($user->hasTwoFactorEnabled()) {
            return $this->back($request)->with('status', '雙因素驗證已經啟用。');
        }

        if (! $this->authenticator->verifyAndConsumeCode($user, $validated['code'])) {
            throw ValidationException::withMessages(['code' => '驗證碼不正確，請確認手機時間正確後再試一次。']);
        }

        $recoveryCodes = $this->authenticator->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $this->authenticator->hashRecoveryCodes($recoveryCodes),
            'remember_token' => Str::random(60),
        ])->save();
        $this->auditLogger->record(PlatformAuditAction::TwoFactorEnabled, $user, actor: $user);

        return $this->back($request)
            ->with('status', '已啟用雙因素驗證。其他裝置的「保持登入」已失效，下次登入需輸入驗證碼。')
            ->with(self::RECOVERY_CODES_SESSION_KEY, $recoveryCodes);
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $this->validateCurrentPassword($request);
        $user = $this->user($request);

        if (! $user->hasTwoFactorEnabled()) {
            return $this->back($request)->with('status', '請先啟用雙因素驗證。');
        }

        $recoveryCodes = $this->authenticator->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $this->authenticator->hashRecoveryCodes($recoveryCodes)])->save();
        $this->auditLogger->record(PlatformAuditAction::TwoFactorRecoveryCodesRegenerated, $user, actor: $user);

        return $this->back($request)
            ->with('status', '已產生新的復原碼，舊的復原碼已失效。')
            ->with(self::RECOVERY_CODES_SESSION_KEY, $recoveryCodes);
    }

    public function disable(Request $request): RedirectResponse
    {
        $this->validateCurrentPassword($request);
        $user = $this->user($request);
        $wasEnabled = $user->hasTwoFactorEnabled();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();

        if ($wasEnabled) {
            $this->auditLogger->record(PlatformAuditAction::TwoFactorDisabled, $user, actor: $user);
        }

        return $this->back($request)->with('status', $wasEnabled ? '已停用雙因素驗證。' : '已取消雙因素驗證設定。');
    }

    private function validateCurrentPassword(Request $request): void
    {
        $request->validate([
            'current_password' => ['required', 'string', 'max:200', 'current_password:'.$this->guardName($request)],
        ], [
            'current_password.required' => '請輸入目前密碼。',
            'current_password.current_password' => '目前密碼不正確。',
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user($this->guardName($request));
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function back(Request $request): RedirectResponse
    {
        return redirect()->route($request->routeIs('platform.*') ? 'platform.account.security' : 'account.password.edit');
    }

    private function routePrefix(Request $request): string
    {
        return $request->routeIs('platform.*') ? 'platform.account.two-factor' : 'account.two-factor';
    }

    private function guardName(Request $request): string
    {
        return $request->routeIs('platform.*') ? PlatformAuthController::GUARD : 'web';
    }
}
