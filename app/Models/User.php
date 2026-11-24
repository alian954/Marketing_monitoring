<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\PasswordResetNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @var list<int>|null */
    private ?array $workspaceClientIdsCache = null;

    private bool $workspaceClientIdsResolved = false;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function monitors(): HasMany
    {
        return $this->workspaceOwner()->hasMany(Monitor::class);
    }

    public function clients(): HasMany
    {
        return $this->workspaceOwner()->hasMany(Client::class);
    }

    public function socialEngagementTargets(): HasMany
    {
        return $this->workspaceOwner()->hasMany(SocialEngagementTarget::class);
    }

    public function statusPage(): HasOne
    {
        return $this->workspaceOwner()->hasOne(StatusPage::class);
    }

    public function workspaceMembership(): HasOne
    {
        return $this->hasOne(WorkspaceMembership::class, 'member_user_id')->whereNull('revoked_at');
    }

    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class, 'owner_user_id');
    }

    public function workspaceInvitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class, 'owner_user_id');
    }

    public function workspaceOwner(): self
    {
        return $this->workspaceMembership?->owner ?? $this;
    }

    public function workspaceOwnerId(): int
    {
        return (int) ($this->workspaceMembership?->owner_user_id ?? $this->getKey());
    }

    public function isWorkspaceOwner(): bool
    {
        return $this->workspaceMembership === null;
    }

    public function isReadOnlyWorkspaceMember(): bool
    {
        $membership = $this->workspaceMembership;

        return $membership !== null && $membership->role !== WorkspaceMembership::ROLE_EDITOR;
    }

    public function canManageWorkspace(): bool
    {
        return $this->isWorkspaceOwner()
            || $this->workspaceMembership?->role === WorkspaceMembership::ROLE_EDITOR;
    }

    /** @return list<int>|null Null means the member can access all workspace clients. */
    public function workspaceClientIds(): ?array
    {
        if ($this->workspaceClientIdsResolved) {
            return $this->workspaceClientIdsCache;
        }

        $membership = $this->workspaceMembership;

        if ($membership === null || $membership->client_scope !== WorkspaceMembership::CLIENT_SCOPE_SELECTED) {
            $this->workspaceClientIdsResolved = true;

            return null;
        }

        $this->workspaceClientIdsCache = DB::table('workspace_membership_clients')
            ->where('workspace_membership_id', $membership->getKey())
            ->orderBy('client_id')
            ->pluck('client_id')
            ->map(static fn (mixed $clientId): int => (int) $clientId)
            ->all();
        $this->workspaceClientIdsResolved = true;

        return $this->workspaceClientIdsCache;
    }

    public function canCreateClient(): bool
    {
        return $this->isWorkspaceOwner()
            || ($this->canManageWorkspace() && $this->workspaceClientIds() === null);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] mixed $token): void
    {
        if (! is_string($token)) {
            throw new \InvalidArgumentException('The password reset token must be a string.');
        }

        $this->notify(new PasswordResetNotification($token));
    }

    public function googleAnalyticsConnection(): HasOne
    {
        return $this->workspaceOwner()->hasOne(GoogleAnalyticsConnection::class);
    }

    public function metaMarketingConnection(): HasOne
    {
        return $this->workspaceOwner()->hasOne(MetaMarketingConnection::class);
    }

    public function googleAdsConnection(): HasOne
    {
        return $this->workspaceOwner()->hasOne(GoogleAdsConnection::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
