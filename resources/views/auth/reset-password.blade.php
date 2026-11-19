<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>設定新密碼 · 監測台</title>
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

            <section class="login-card" aria-labelledby="reset-password-heading">
                <p class="eyebrow">帳戶協助</p>
                <h1 id="reset-password-heading" class="login-heading">設定新密碼</h1>
                <p class="login-description">請輸入收到重設連結的電子郵件，並設定至少 12 個字元的新密碼。</p>

                <x-flash-messages />

                <form action="{{ route('password.update') }}" method="POST" aria-labelledby="reset-password-heading">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <div class="login-fields">
                        <div class="field-group">
                            <label class="field-label" for="email">電子郵件</label>
                            <input
                                class="field-input @error('email') is-invalid @enderror"
                                id="email"
                                name="email"
                                type="email"
                                value="{{ old('email', $email) }}"
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

                        <div class="field-group">
                            <label class="field-label" for="password">新密碼</label>
                            <p id="password-help" class="field-help">至少 12 個字元。</p>
                            <input
                                class="field-input @error('password') is-invalid @enderror"
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="new-password"
                                minlength="12"
                                required
                                @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-help password-error" @else aria-describedby="password-help" @endif
                            >
                            @error('password')
                                <p id="password-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="password_confirmation">再次輸入新密碼</label>
                            <input
                                class="field-input @error('password') is-invalid @enderror"
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                minlength="12"
                                required
                                @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-confirmation-error" @endif
                            >
                            @error('password')
                                <p id="password-confirmation-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <button class="button button-primary login-submit" type="submit">重設密碼</button>
                </form>
                <p class="login-footer"><a class="strong-link" href="{{ route('password.request') }}">重新申請重設連結</a></p>
            </section>

            <p class="login-footer"><a class="strong-link" href="{{ route('login') }}">返回登入</a></p>
        </div>
    </main>
</body>
</html>
