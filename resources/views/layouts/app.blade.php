<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title', '監測總覽') · 監測台</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#main-content">跳至主要內容</a>
    <div class="app-shell">
        <header class="site-header">
            <div class="header-inner">
                <a class="brand" href="{{ route('dashboard') }}" aria-label="監測台首頁">
                    <span class="brand-mark" aria-hidden="true">M</span>
                    <span class="brand-name">監測<span>台</span></span>
                </a>

                <nav class="primary-nav" aria-label="主要導覽">
                    <a class="nav-link" href="{{ route('dashboard') }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif>總覽</a>
                    <a class="nav-link" href="{{ route('monitors.index') }}" @if (request()->routeIs('monitors.*')) aria-current="page" @endif>監測項目</a>
                    <a class="nav-link" href="{{ route('notifications.index') }}" @if (request()->routeIs('notifications.*')) aria-current="page" @endif>通知紀錄</a>
                    <a class="nav-link" href="{{ route('integrations.google-analytics.edit') }}" @if (request()->routeIs('integrations.google-analytics.*')) aria-current="page" @endif>GA4 整合</a>
                    <a class="nav-link" href="{{ route('integrations.google-ads.edit') }}" @if (request()->routeIs('integrations.google-ads.*')) aria-current="page" @endif>Google Ads 整合</a>
                    <a class="nav-link" href="{{ route('integrations.meta-marketing.edit') }}" @if (request()->routeIs('integrations.meta-marketing.*')) aria-current="page" @endif>Meta Ads 整合</a>
                    <a class="nav-link" href="{{ route('features.index') }}" @if (request()->routeIs('features.*')) aria-current="page" @endif>功能說明</a>
                    <a class="nav-link" href="{{ route('account.password.edit') }}" @if (request()->routeIs('account.password.*')) aria-current="page" @endif>帳戶安全</a>
                </nav>

                <div class="user-actions">
                    <span class="account-label">{{ auth()->user()?->email }}</span>
                    <form class="logout-form" action="{{ route('logout') }}" method="POST">
                        @csrf
                    <button class="logout-button" type="submit">登出</button>
                    </form>
                </div>
            </div>
        </header>

        <main id="main-content" class="page-shell">
            <x-flash-messages />
            @yield('content')
        </main>

        <footer class="page-footer">私人網站監測工作區</footer>
    </div>
</body>
</html>
