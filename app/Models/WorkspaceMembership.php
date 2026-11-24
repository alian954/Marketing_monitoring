<?php

namespace App\Models;

use Database\Factories\WorkspaceMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['owner_user_id', 'member_user_id', 'role', 'accepted_at', 'revoked_at'])]
class WorkspaceMembership extends Model
{
    public const ROLE_READ_ONLY = 'read_only';

    /** @use HasFactory<WorkspaceMembershipFactory> */
    use HasFactory;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function memberUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
