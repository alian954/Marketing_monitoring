<?php

namespace App\Http\Middleware;

use App\Services\PlatformConsole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the admin console apart from the customer platform: the console host serves only /admin,
 * and console requests use their own session cookie. Runs before the session starts.
 */
class IsolatePlatformConsole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (PlatformConsole::isConsoleHost($request) && ! PlatformConsole::isConsolePath($request->path())) {
            if ($request->path() === '/' && $request->isMethodSafe()) {
                return redirect('/'.PlatformConsole::PATH_PREFIX);
            }

            abort(404);
        }

        $cookieName = PlatformConsole::isConsoleRequest($request)
            ? config('platform.console_session_cookie')
            : config('platform.customer_session_cookie');
        Config::set('session.cookie', $cookieName);
        // A long-lived worker may already hold a session store named after the previous request.
        app('session')->driver()->setName($cookieName);

        return $next($request);
    }
}
