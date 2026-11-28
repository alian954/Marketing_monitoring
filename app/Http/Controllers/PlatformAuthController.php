<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PlatformConsole;
use App\Services\TwoFactorLoginChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sign-in for the platform administration console, kept apart from the customer sign-in.
 */
class PlatformAuthController extends Controller
{
    public const GUARD = 'platform';

    public function __construct(private TwoFactorLoginChallenge $twoFactorLoginChallenge) {}

    public function create(): View
    {
        return view('platform.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ], [
            'email.required' => '請輸入電子郵件地址。',
            'email.email' => '請輸入有效的電子郵件地址。',
            'password.required' => '請輸入密碼。',
        ]);
        $credentials['email'] = mb_strtolower(trim($credentials['email']));
        $key = 'platform-login-failed:'.hash_hmac(
            'sha256',
            $credentials['email'].'|'.$request->ip(),
            (string) config('app.key'),
        );

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => '登入嘗試次數過多，請於一分鐘後再試。']);
        }

        $guard = Auth::guard(self::GUARD);

        if (! $guard->validate($credentials)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => '電子郵件或密碼不正確。']);
        }

        $administrator = $guard->getLastAttempted();

        if (! $administrator instanceof User || ! $administrator->isPlatformAdmin()) {
            // Customer accounts get the same answer as a wrong password, so the console does not reveal who is an administrator.
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => '電子郵件或密碼不正確。']);
        }

        RateLimiter::clear($key);

        if (config('hashing.rehash_on_login', true)) {
            $guard->getProvider()->rehashPasswordIfRequired($administrator, $credentials);
        }

        if ($administrator->hasTwoFactorEnabled()) {
            $this->twoFactorLoginChallenge->begin($request, $administrator, self::GUARD, false);

            return redirect()->route('platform.two-factor.challenge');
        }

        $guard->login($administrator);

        return $this->completeLogin($request);
    }

    /**
     * Finish a console sign-in after every check, including the authenticator code, has passed.
     */
    public function completeLogin(Request $request): RedirectResponse
    {
        $request->session()->regenerate();

        return redirect()->to($this->intendedConsoleUrl($request));
    }

    private function intendedConsoleUrl(Request $request): string
    {
        $intendedUrl = $request->session()->pull('url.intended');

        return is_string($intendedUrl) && PlatformConsole::isConsoleUrl($intendedUrl)
            ? $intendedUrl
            : route('platform.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard(self::GUARD)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
