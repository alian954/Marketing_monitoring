<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class AuthenticatedWorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $userId = AccountScopeContext::authenticatedUserId();

        if ($userId !== null) {
            AccountScopeContext::constrainWorkspaceAndLegacyUser(
                $builder,
                $userId,
                AccountScopeContext::authenticatedWorkspaceId(),
            );
        }
    }
}
