<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex, nofollow">
    <title>管理後台登入 · 監測台</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="platform-console">
    <main class="login-page">
        <div class="login-wrap">
            <div class="login-brand">
                <a class="brand" href="{{ route('platform.login') }}" aria-label="監測台管理後台">
                    <span class="brand-mark" aria-hidden="true">M</span>
                    <span class="brand-name">監測<span>台</span></span>
                    <span class="platform-badge">管理後台</span>
                </a>
            </div>

            <section class="login-card" aria-labelledby="login-heading">
                <p class="eyebrow">Platform administration</p>
                <h1 id="login-heading" class="login-heading">管理後台登入</h1>
                <p class="login-description">管理組織、方案訂閱、試用申請與稽核紀錄。客戶帳號請改由<a class="strong-link" href="{{ route('login') }}">客戶平台</a>登入。</p>

                @if (session('status'))
                    <div class="alert alert-warning" role="status">{{ session('status') }}</div>
                @endif

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

                <form action="{{ route('platform.login.store') }}" method="POST">
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

                    <button class="button button-primary login-submit" type="submit">登入</button>
                </form>
                <p class="login-footer">忘記密碼請由主機執行 <code>php artisan monitor:create-manager --reset</code> 重設。</p>
            </section>

            <p class="login-footer">僅限平台管理員使用，登入行為會受到頻率限制。</p>
        </div>
    </main>
</body>
</html>
