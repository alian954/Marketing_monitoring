<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectReadOnlyWorkspaceMemberWrites
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && ! $request->routeIs('platform.*')
            && ! $request->isMethodSafe()
            && ! $request->routeIs(
                'logout',
                'account.password.update',
                'social-engagement-targets.report.utm-link-preview',
            )) {
            abort_unless($user->canManageWorkspace(), 403, '工作區成員僅有檢視權限。');
        }

        return $next($request);
    }
}
