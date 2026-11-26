<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait BelongsToAuthenticatedWorkspace
{
    protected static function bootBelongsToAuthenticatedWorkspace(): void
    {
        static::addGlobalScope(new AuthenticatedWorkspaceScope);

        static::creating(static function (Model $model): void {
            $user = Auth::user();

            if ($model->user_id === null && $user instanceof User) {
                $model->forceFill(['user_id' => $user->workspaceOwnerId()]);
            }

            if ($model->workspace_id === null && is_numeric($model->user_id)) {
                $workspaceId = Workspace::idForOwnerUserId((int) $model->user_id);

                if ($workspaceId !== null) {
                    $model->forceFill(['workspace_id' => $workspaceId]);
                }
            }
        });
    }
}
