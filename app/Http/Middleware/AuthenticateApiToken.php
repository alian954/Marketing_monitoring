<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates read-only API requests by bearer token and acts as the token's creator, so the
 * same workspace and client scopes as the web app apply to everything the API returns.
 *
 * Runs before route model binding, otherwise bound clients would be resolved without a scope.
 */
class AuthenticateApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainText = $request->bearerToken();

        if (! is_string($plainText) || ! str_starts_with($plainText, ApiToken::PREFIX)) {
            return $this->unauthorized();
        }

        $token = ApiToken::query()->with('user')->where('token_hash', ApiToken::hash($plainText))->first();
        $user = $token?->user;

        if ($token === null || $token->isExpired() || ! $user instanceof User || $user->isPlatformAdmin()) {
            return $this->unauthorized();
        }

        Auth::guard('web')->setUser($user);

        if ($user->workspaceOwnerId() !== (int) $token->workspace_owner_user_id) {
            return response()->json(['message' => '此 API 金鑰所屬的工作區已無法存取。'], 403);
        }

        if (! Organization::isAccessibleForOwner((int) $token->workspace_owner_user_id)) {
            return response()->json(['message' => '組織已停用或試用已到期。'], 403);
        }

        if ($token->last_used_at === null || $token->last_used_at->lessThan(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'API 金鑰無效或已過期。'], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}
