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

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
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
        return $this->workspaceMembership?->role === WorkspaceMembership::ROLE_READ_ONLY;
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
