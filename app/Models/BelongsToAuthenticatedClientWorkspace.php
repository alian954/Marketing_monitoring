<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

trait BelongsToAuthenticatedClientWorkspace
{
    protected static function bootBelongsToAuthenticatedClientWorkspace(): void
    {
        static::addGlobalScope(new AuthenticatedWorkspaceRelationScope('client'));
        static::addGlobalScope(new WorkspaceClientScope);
        static::addGlobalScope(new SelectedClientScope);

        static::creating(static function (Model $model): void {
            if ($model->workspace_id !== null || ! is_numeric($model->client_id)) {
                return;
            }

            $client = Client::withoutGlobalScopes()->find((int) $model->client_id);
            $workspaceId = $client?->workspace_id;

            if ($workspaceId === null && is_numeric($client?->user_id)) {
                $workspaceId = Workspace::idForOwnerUserId((int) $client->user_id);
            }

            if ($workspaceId !== null) {
                $model->forceFill(['workspace_id' => $workspaceId]);
            }
        });
    }
}
