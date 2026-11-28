<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex, nofollow">
    <title>雙因素驗證 · 監測台{{ $isConsole ? '管理後台' : '' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @if ($isConsole) class="platform-console" @endif>
    <main class="login-page">
        <div class="login-wrap">
            <div class="login-brand">
                <a class="brand" href="{{ route($isConsole ? 'platform.login' : 'login') }}" aria-label="{{ $isConsole ? '監測台管理後台' : '監測台' }}">
                    <span class="brand-mark" aria-hidden="true">M</span>
                    <span class="brand-name">監測<span>台</span></span>
                    @if ($isConsole)
                        <span class="platform-badge">管理後台</span>
                    @endif
                </a>
            </div>

            <section class="login-card" aria-labelledby="two-factor-heading">
                <p class="eyebrow">第二道驗證</p>
                <h1 id="two-factor-heading" class="login-heading">輸入驗證碼</h1>
                <p class="login-description">請開啟驗證器 App，輸入此帳戶目前顯示的 6 位數驗證碼。5 分鐘內未完成需重新輸入密碼。</p>

                @if ($errors->any())
                    <div class="alert alert-error" role="alert" aria-labelledby="two-factor-error-title">
                        <p id="two-factor-error-title" class="alert-title">驗證失敗</p>
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route($isConsole ? 'platform.two-factor.challenge.store' : 'two-factor.challenge.store') }}" method="POST">
                    @csrf
                    <div class="login-fields">
                        <div class="field-group">
                            <label class="field-label" for="code">驗證碼</label>
                            <input
                                class="field-input @error('code') is-invalid @enderror"
                                id="code"
                                name="code"
                                type="text"
                                inputmode="numeric"
                                pattern="[0-9 ]*"
                                maxlength="20"
                                autocomplete="one-time-code"
                                autofocus
                                @if ($errors->has('code')) aria-invalid="true" @endif
                            >
                        </div>
                    </div>
                    <button class="button button-primary login-submit" type="submit">驗證並登入</button>
                </form>

                <details class="spaced-top">
                    <summary>無法使用手機？改用復原碼</summary>
                    <form action="{{ route($isConsole ? 'platform.two-factor.challenge.store' : 'two-factor.challenge.store') }}" method="POST">
                        @csrf
                        <div class="login-fields">
                            <div class="field-group">
                                <label class="field-label" for="recovery_code">復原碼</label>
                                <p id="recovery-code-help" class="field-help">啟用時保存的復原碼，每組只能使用一次。</p>
                                <input
                                    class="field-input @error('recovery_code') is-invalid @enderror"
                                    id="recovery_code"
                                    name="recovery_code"
                                    type="text"
                                    maxlength="40"
                                    autocomplete="off"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    aria-describedby="recovery-code-help"
                                    @if ($errors->has('recovery_code')) aria-invalid="true" @endif
                                >
                            </div>
                        </div>
                        <button class="button button-secondary login-submit" type="submit">使用復原碼登入</button>
                    </form>
                </details>
            </section>

            <p class="login-footer"><a class="strong-link" href="{{ route($isConsole ? 'platform.login' : 'login') }}">返回登入</a></p>
        </div>
    </main>
</body>
</html>
