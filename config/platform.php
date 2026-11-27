<?php

use Illuminate\Support\Str;

return [
    /*
     * The admin console (/admin). Set to false on the customer-facing deployment so the console
     * routes are never registered there; run the console as its own deployment instead.
     */
    'console_enabled' => (bool) env('PLATFORM_CONSOLE_ENABLED', true),

    /*
     * Dedicated host for the console, e.g. admin.example.com. When set, the console only answers on
     * this host and the host serves nothing else. Leave empty to serve /admin on any host (local dev).
     */
    'console_domain' => ($consoleDomain = mb_strtolower(trim((string) env('PLATFORM_CONSOLE_DOMAIN', '')))) === ''
        ? null
        : $consoleDomain,

    /*
     * The console keeps its own session cookie, so a customer session and an admin session never share state.
     * The customer name mirrors config/session.php and is restored for every non-console request.
     */
    'customer_session_cookie' => env(
        'SESSION_COOKIE',
        Str::slug((string) env('APP_NAME', 'laravel')).'-session',
    ),
    'console_session_cookie' => env(
        'PLATFORM_CONSOLE_SESSION_COOKIE',
        Str::slug((string) env('APP_NAME', 'laravel')).'-admin-session',
    ),
];
