<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>重設密碼 · 監測台</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="login-page">
        <div class="login-wrap">
            <div class="login-brand">
                <a class="brand" href="{{ route('login') }}" aria-label="監測台">
                    <span class="brand-mark" aria-hidden="true">M</span>
                    <span class="brand-name">監測<span>台</span></span>
                </a>
            </div>

            <section class="login-card" aria-labelledby="forgot-password-heading">
                <p class="eyebrow">帳戶協助</p>
                <h1 id="forgot-password-heading" class="login-heading">忘記密碼</h1>
                <p class="login-description">輸入帳戶電子郵件；如果帳戶存在，我們會寄出重設密碼連結。</p>

                <x-flash-messages />

                <form action="{{ route('password.email') }}" method="POST" aria-labelledby="forgot-password-heading">
                    @csrf
                    <div class="login-fields">
                        <div class="field-group">
                            <label class="field-label" for="email">電子郵件</label>
                            <input
                                class="field-input @error('email') is-invalid @enderror"
                                id="email"
                                name="email"
                                type="email"
                                value="{{ old('email') }}"
                                autocomplete="email"
                                inputmode="email"
                                maxlength="254"
                                required
                                autofocus
                                @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif
                            >
                            @error('email')
                                <p id="email-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <button class="button button-primary login-submit" type="submit">寄送重設連結</button>
                </form>
                <p class="login-footer"><a class="strong-link" href="{{ route('login') }}">返回登入</a></p>
            </section>

            <p class="login-footer">重設連結有效時間為 {{ config('auth.passwords.users.expire') }} 分鐘。</p>
        </div>
    </main>
</body>
</html>
