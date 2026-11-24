<?php

namespace App\Models;

use Database\Factories\WorkspaceMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['owner_user_id', 'member_user_id', 'role', 'client_scope', 'accepted_at', 'revoked_at'])]
class WorkspaceMembership extends Model
{
    public const ROLE_READ_ONLY = 'read_only';

    public const ROLE_EDITOR = 'editor';

    public const CLIENT_SCOPE_ALL = 'all';

    public const CLIENT_SCOPE_SELECTED = 'selected';

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

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'workspace_membership_clients')->withTimestamps();
    }

    public function roleLabel(): string
    {
        return $this->role === self::ROLE_EDITOR ? '可編輯' : '唯讀';
    }

    public function clientScopeLabel(): string
    {
        return $this->client_scope === self::CLIENT_SCOPE_SELECTED ? '指定客戶' : '全部客戶';
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
