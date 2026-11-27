<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title', '監測總覽') · 監測台</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @class(['is-read-only-member' => auth()->user()?->isReadOnlyWorkspaceMember()])>
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
                    <a class="nav-link" href="{{ route('clients.index') }}" @if (request()->routeIs('clients.*')) aria-current="page" @endif>客戶</a>
                    <a class="nav-link" href="{{ route('notifications.index') }}" @if (request()->routeIs('notifications.*')) aria-current="page" @endif>通知紀錄</a>
                    @if (auth()->user()?->isWorkspaceOwner())
                        <a class="nav-link" href="{{ route('team.index') }}" @if (request()->routeIs('team.*')) aria-current="page" @endif>組織成員</a>
                    @endif
                    @if (auth()->user()?->isWorkspaceOwner())
                        <a class="nav-link" href="{{ route('integrations.google-analytics.edit') }}" @if (request()->routeIs('integrations.google-analytics.*')) aria-current="page" @endif>GA4 整合</a>
                        <a class="nav-link" href="{{ route('integrations.google-search-console.edit') }}" @if (request()->routeIs('integrations.google-search-console.*')) aria-current="page" @endif>Search Console</a>
                        <a class="nav-link" href="{{ route('integrations.google-ads.edit') }}" @if (request()->routeIs('integrations.google-ads.*')) aria-current="page" @endif>Google Ads 整合</a>
                        <a class="nav-link" href="{{ route('integrations.meta-marketing.edit') }}" @if (request()->routeIs('integrations.meta-marketing.*')) aria-current="page" @endif>Meta Ads 整合</a>
                    @endif
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
            @php($availableWorkspaces = auth()->user()?->availableWorkspaces() ?? [])
            @if (count($availableWorkspaces) > 1)
                <form class="workspace-switcher" action="{{ route('workspaces.switch') }}" method="POST">
                    @csrf
                    <label class="workspace-switcher-label" for="workspace-switcher-select">目前組織</label>
                    <select class="field-select" id="workspace-switcher-select" name="workspace">
                        @foreach ($availableWorkspaces as $availableWorkspace)
                            <option value="{{ $availableWorkspace['key'] }}" @selected($availableWorkspace['isCurrent']) @disabled(! $availableWorkspace['isAccessible'])>
                                {{ $availableWorkspace['name'] }} · {{ $availableWorkspace['roleLabel'] }}@if (! $availableWorkspace['isAccessible'])（已停用）@endif
                            </option>
                        @endforeach
                    </select>
                    <button class="button button-secondary button-small" type="submit">切換</button>
                </form>
            @endif
            <x-flash-messages />
            @if (auth()->user()?->isReadOnlyWorkspaceMember())
                <div class="read-only-banner" role="status">唯讀成員 · 僅能檢視授權的組織資料</div>
            @elseif (! auth()->user()?->isWorkspaceOwner())
                <div class="read-only-banner" role="status">可編輯成員 · 可維護監測項目與活動</div>
            @endif
            @yield('content')
        </main>

        <footer class="page-footer">多組織網站監測平台</footer>
    </div>
</body>
</html>
