<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', '服務狀態') · 公開狀態頁</title>
    @vite(['resources/css/app.css'])
</head>
<body class="public-status-body @yield('body-class')">
    <a class="skip-link" href="#main-content">跳至主要內容</a>
    <main id="main-content" class="public-status-shell">
        @yield('content')
        <footer class="public-status-footer">@yield('footer', '狀態資料由網站監測服務提供')</footer>
    </main>
    @stack('scripts')
</body>
</html>
