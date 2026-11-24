<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WorkspaceClientRelationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $clientIds = AccountScopeContext::authenticatedClientIds();

        if ($clientIds === null) {
            return;
        }

        if ($clientIds === []) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(function (Builder $query) use ($clientIds): void {
            $query->whereHas('monitor', static fn (Builder $monitorQuery): Builder => $monitorQuery->whereIn('client_id', $clientIds))
                ->orWhereHas('incident.monitor', static fn (Builder $monitorQuery): Builder => $monitorQuery->whereIn('client_id', $clientIds));
        });
    }
}
