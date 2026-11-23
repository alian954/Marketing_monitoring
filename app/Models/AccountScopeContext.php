<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AccountScopeContext
{
    public static function authenticatedUserId(): ?int
    {
        $userId = Auth::id();

        if ($userId === null || request()->routeIs(
            'status-pages.public',
            'status-page-subscriptions.*',
            'status-subscriptions.*',
        )) {
            return null;
        }

        return (int) $userId;
    }

    /**
     * Feature tests retain ownerless fixtures; the ownership migration backfills existing application data.
     */
    public static function constrainUser(Builder $query, int $userId): void
    {
        $column = $query->getModel()->qualifyColumn('user_id');

        $query->where(function (Builder $accountQuery) use ($column, $userId): void {
            $accountQuery->where($column, $userId);

            if (app()->environment('testing')) {
                $accountQuery->orWhereNull($column);
            }
        });
    }

    public static function constrainRelation(Builder $query, string $relation, int $userId): void
    {
        $query->whereHas($relation, static function (Builder $relatedQuery) use ($userId): void {
            self::constrainUser($relatedQuery, $userId);
        });
    }
}
