<?php

namespace App\Models;

use Database\Factories\WorkspaceInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['owner_user_id', 'email', 'role', 'client_scope', 'client_ids', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class WorkspaceInvitation extends Model
{
    public const EXPIRATION_DAYS = 7;

    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function roleLabel(): string
    {
        return $this->role === WorkspaceMembership::ROLE_EDITOR ? '可編輯' : '唯讀';
    }

    public function clientScopeLabel(): string
    {
        return $this->client_scope === WorkspaceMembership::CLIENT_SCOPE_SELECTED ? '指定客戶' : '全部客戶';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'client_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
