<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WorkspaceClientScope implements Scope
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

        $clientColumn = $model instanceof Client
            ? $model->qualifyColumn('id')
            : $model->qualifyColumn('client_id');

        $builder->whereIn($clientColumn, $clientIds);
    }
}
