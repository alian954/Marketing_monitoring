<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>登入 · 監測台</title>
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

            <section class="login-card" aria-labelledby="login-heading">
                <p class="eyebrow">專屬工作區</p>
                <h1 id="login-heading" class="login-heading">歡迎回來</h1>
                <p class="login-description">登入以查看網站監測狀態與事件紀錄。</p>

                @if ($errors->any())
                    <div class="alert alert-error" role="alert" aria-labelledby="login-error-title">
                        <p id="login-error-title" class="alert-title">登入失敗</p>
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('login.store') }}" method="POST">
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
                                autocomplete="username"
                                inputmode="email"
                                required
                                autofocus
                                aria-describedby="{{ $errors->has('email') ? 'email-error' : '' }}"
                            >
                            @error('email')
                                <p id="email-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="password">密碼</label>
                            <input
                                class="field-input @error('password') is-invalid @enderror"
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                required
                                aria-describedby="{{ $errors->has('password') ? 'password-error' : '' }}"
                            >
                            @error('password')
                                <p id="password-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <label class="field-check login-remember">
                        <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                        <span>在此裝置保持登入</span>
                    </label>

                    <button class="button button-primary login-submit" type="submit">登入</button>
                </form>
                <p class="login-footer"><a class="strong-link" href="{{ route('password.request') }}">忘記密碼？</a></p>
            </section>

            <p class="login-footer">此工作區僅供監測帳戶使用。</p>
        </div>
    </main>
</body>
</html>
