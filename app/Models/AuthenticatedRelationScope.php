<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class AuthenticatedRelationScope implements Scope
{
    public function __construct(private readonly string $relation) {}

    public function apply(Builder $builder, Model $model): void
    {
        $userId = AccountScopeContext::authenticatedUserId();

        if ($userId !== null) {
            AccountScopeContext::constrainRelation($builder, $this->relation, $userId);
        }
    }
}
