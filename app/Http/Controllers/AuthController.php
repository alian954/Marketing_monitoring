<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function showLinkRequestForm(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
        ], [
            'email.required' => '請輸入電子郵件地址。',
            'email.email' => '請輸入有效的電子郵件地址。',
            'email.max' => '電子郵件地址不得超過 254 個字元。',
        ]);

        Password::sendResetLink([
            'email' => mb_strtolower(trim($validated['email'])),
        ]);

        return back()->with('status', '如果這個電子郵件對應監測台帳戶，我們會寄送重設密碼連結。請查看收件匣與垃圾郵件。');
    }

    public function showResetForm(Request $request, string $token): View
    {
        $email = $request->query('email');
        $email = is_string($email) ? mb_substr(trim($email), 0, 254) : '';

        return view('auth.reset-password', compact('token', 'email'));
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ], [
            'token.required' => '重設連結無效或已逾期，請重新申請。',
            'email.required' => '請輸入電子郵件地址。',
            'email.email' => '請輸入有效的電子郵件地址。',
            'email.max' => '電子郵件地址不得超過 254 個字元。',
            'password.min' => '新密碼至少需要 12 個字元。',
            'password.confirmed' => '兩次輸入的新密碼不一致。',
        ]);
        $validated['email'] = mb_strtolower(trim($validated['email']));

        $status = Password::reset(
            $validated,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors([
                'email' => '重設連結無效或已逾期，請重新申請。',
            ]);
        }

        return redirect()->route('login')->with('status', '密碼已重設，請使用新密碼登入。');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $credentials['email'] = mb_strtolower(trim($credentials['email']));
        $key = 'login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => '登入嘗試次數過多，請於一分鐘後再試。']);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => '電子郵件或密碼不正確。']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
