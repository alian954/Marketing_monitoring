<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\IsolatePlatformConsole;
use App\Services\PlatformConsole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $isConsoleRequest = static fn (Request $request): bool => PlatformConsole::isConsoleRequest($request);

        $middleware->web(prepend: [IsolatePlatformConsole::class]);
        // Token authentication must run before route model binding so bound clients are workspace scoped.
        $middleware->api(prepend: [AuthenticateApiToken::class]);
        $middleware->prependToPriorityList(SubstituteBindings::class, AuthenticateApiToken::class);
        $middleware->validateCsrfTokens(except: ['webhooks/line/*']);
        $middleware->redirectGuestsTo(
            static fn (Request $request): string => $isConsoleRequest($request) ? route('platform.login') : route('login'),
        );
        $middleware->redirectUsersTo(
            static fn (Request $request): string => $isConsoleRequest($request) ? route('platform.index') : route('dashboard'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['notification_slack_webhook', 'notification_discord_webhook', 'notification_teams_webhook', 'channel_access_token', 'channel_secret']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
