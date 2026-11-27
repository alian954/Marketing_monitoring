<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps platform administrators out of the customer platform; they work in the /admin console instead.
 */
class EnsureCustomerAccount
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User || ! $user->isPlatformAdmin()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login')->with('status', '平台管理員帳號請由管理後台登入。');
    }
}
