<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'public_id'])]
#[Hidden(['organization_id'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function idForOwnerUserId(int $ownerUserId): ?int
    {
        $workspaceId = static::query()
            ->whereHas('organization', static fn (Builder $query): Builder => $query->where('owner_user_id', $ownerUserId))
            ->value('id');

        return is_numeric($workspaceId) ? (int) $workspaceId : null;
    }
}
