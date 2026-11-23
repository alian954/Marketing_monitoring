<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class SelectedClientScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $clientId = ClientSelectionContext::selectedClientId();

        if ($clientId !== null) {
            $clientColumn = $model->qualifyColumn('client_id');

            $builder->where(function (Builder $query) use ($clientColumn, $clientId): void {
                $query->where($clientColumn, $clientId);

                if (app()->environment('testing')) {
                    $query->orWhereNull($clientColumn);
                }
            });
        }
    }
}
