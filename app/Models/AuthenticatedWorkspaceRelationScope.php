<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class AuthenticatedWorkspaceRelationScope implements Scope
{
    public function __construct(private readonly string $relation) {}

    public function apply(Builder $builder, Model $model): void
    {
        $userId = AccountScopeContext::authenticatedUserId();

        if ($userId === null) {
            return;
        }

        $workspaceId = AccountScopeContext::authenticatedWorkspaceId();
        $workspaceColumn = $model->qualifyColumn('workspace_id');
        $relation = $this->relation;

        $builder->where(function (Builder $scopeQuery) use ($userId, $workspaceId, $workspaceColumn, $relation): void {
            if ($workspaceId !== null) {
                $scopeQuery->where($workspaceColumn, $workspaceId);
            }

            $scopeQuery->orWhere(function (Builder $legacyQuery) use ($userId, $workspaceColumn, $relation): void {
                $legacyQuery->whereNull($workspaceColumn)
                    ->whereHas($relation, static function (Builder $relatedQuery) use ($userId): void {
                        AccountScopeContext::constrainUser($relatedQuery, $userId);
                    });
            });
        });
    }
}
