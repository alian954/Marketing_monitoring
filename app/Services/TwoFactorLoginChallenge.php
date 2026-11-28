<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Holds a password-verified sign-in in the session until the authenticator code is entered.
 */
class TwoFactorLoginChallenge
{
    public const EXPIRES_AFTER_SECONDS = 300;

    private const SESSION_KEY_PREFIX = 'two_factor_login.';

    public function begin(Request $request, User $user, string $guard, bool $remember): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY_PREFIX.$guard, [
            'user_id' => (int) $user->getKey(),
            'remember' => $remember,
            'expires_at' => now()->addSeconds(self::EXPIRES_AFTER_SECONDS)->getTimestamp(),
        ]);
    }

    /**
     * The user waiting for a code, or null when there is no valid pending sign-in.
     */
    public function pendingUser(Request $request, string $guard): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY_PREFIX.$guard);

        if (! is_array($pending) || ! is_int($pending['user_id'] ?? null) || ! is_int($pending['expires_at'] ?? null)) {
            return null;
        }

        if ($pending['expires_at'] < now()->getTimestamp()) {
            $this->clear($request, $guard);

            return null;
        }

        $user = User::query()->find($pending['user_id']);

        return $user instanceof User && $user->hasTwoFactorEnabled() ? $user : null;
    }

    public function remember(Request $request, string $guard): bool
    {
        return (bool) ($request->session()->get(self::SESSION_KEY_PREFIX.$guard)['remember'] ?? false);
    }

    public function clear(Request $request, string $guard): void
    {
        $request->session()->forget(self::SESSION_KEY_PREFIX.$guard);
    }
}
