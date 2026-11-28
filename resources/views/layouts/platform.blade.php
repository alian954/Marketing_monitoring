<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', '組織管理') · 監測台管理後台</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="platform-console">
    <a class="skip-link" href="#main-content">跳至主要內容</a>
    <div class="app-shell">
        <header class="site-header platform-header">
            <div class="header-inner">
                <a class="brand" href="{{ route('platform.index') }}" aria-label="監測台管理後台首頁">
                    <span class="brand-mark" aria-hidden="true">M</span>
                    <span class="brand-name">監測<span>台</span></span>
                    <span class="platform-badge">管理後台</span>
                </a>

                <nav class="primary-nav" aria-label="管理後台導覽">
                    <a class="nav-link" href="{{ route('platform.index') }}" @if (request()->routeIs('platform.index', 'platform.organizations.*')) aria-current="page" @endif>組織管理</a>
                    <a class="nav-link" href="{{ route('platform.products.index') }}" @if (request()->routeIs('platform.products.*')) aria-current="page" @endif>產品與功能</a>
                    <a class="nav-link" href="{{ route('platform.trial-requests.index') }}" @if (request()->routeIs('platform.trial-requests.*')) aria-current="page" @endif>試用申請</a>
                    <a class="nav-link" href="{{ route('platform.entitlement-migration.index') }}" @if (request()->routeIs('platform.entitlement-migration.*')) aria-current="page" @endif>授權切換</a>
                    <a class="nav-link" href="{{ route('platform.audit-events.index') }}" @if (request()->routeIs('platform.audit-events.*')) aria-current="page" @endif>稽核紀錄</a>
                    <a class="nav-link" href="{{ route('platform.system.index') }}" @if (request()->routeIs('platform.system.*')) aria-current="page" @endif>系統狀態</a>
                </nav>

                <div class="user-actions">
                    <a class="account-label" href="{{ route('platform.account.security') }}" @if (request()->routeIs('platform.account.*')) aria-current="page" @endif>{{ auth('platform')->user()?->email }}</a>
                    <form class="logout-form" action="{{ route('platform.logout') }}" method="POST">
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

        <footer class="page-footer">監測台管理後台 · 僅限平台管理員</footer>
    </div>
</body>
</html>
