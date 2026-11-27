<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'owner_user_id', 'subscription_ends_on', 'seat_limit', 'plan_name', 'client_limit', 'monitor_limit', 'campaign_limit', 'is_enabled'])]
#[Hidden(['owner_user_id'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function workspace(): HasOne
    {
        return $this->hasOne(Workspace::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscriptionItems(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'user_id', 'owner_user_id');
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class, 'user_id', 'owner_user_id');
    }

    public function socialEngagementTargets(): HasMany
    {
        return $this->hasMany(SocialEngagementTarget::class, 'user_id', 'owner_user_id');
    }

    public function activeMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class, 'owner_user_id', 'owner_user_id')
            ->whereNull('revoked_at');
    }

    public function occupiedSeats(?string $excludingPendingInvitationEmail = null): int
    {
        $pendingInvitations = WorkspaceInvitation::query()
            ->where('owner_user_id', $this->owner_user_id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());

        if ($excludingPendingInvitationEmail !== null) {
            $pendingInvitations->whereRaw('lower(email) <> ?', [mb_strtolower(trim($excludingPendingInvitationEmail))]);
        }

        return 1 + $this->activeMemberships()->count() + $pendingInvitations->count();
    }

    public function hasAvailableSeatForInvitation(string $email): bool
    {
        return $this->seat_limit === null || $this->occupiedSeats($email) < $this->seat_limit;
    }

    public function clientUsageCount(): int
    {
        return Client::withoutGlobalScopes()
            ->where('user_id', $this->owner_user_id)
            ->where('name', '<>', Client::DEFAULT_NAME)
            ->count();
    }

    public function monitorUsageCount(): int
    {
        return Monitor::withoutGlobalScopes()->where('user_id', $this->owner_user_id)->count();
    }

    public function campaignUsageCount(): int
    {
        return SocialEngagementTarget::withoutGlobalScopes()->where('user_id', $this->owner_user_id)->count();
    }

    public function hasAvailableClient(): bool
    {
        return $this->client_limit === null || $this->clientUsageCount() < $this->client_limit;
    }

    public function hasAvailableMonitor(): bool
    {
        return $this->monitor_limit === null || $this->monitorUsageCount() < $this->monitor_limit;
    }

    public function hasAvailableCampaign(): bool
    {
        return $this->campaign_limit === null || $this->campaignUsageCount() < $this->campaign_limit;
    }

    /** @return Builder<Organization> */
    public static function inaccessibleOwnerIds(string $asOfDate): Builder
    {
        return static::query()
            ->where(function (Builder $query) use ($asOfDate): void {
                $query->where('is_enabled', false)
                    ->orWhere(function (Builder $query) use ($asOfDate): void {
                        $query->whereNotNull('subscription_ends_on')
                            ->whereDate('subscription_ends_on', '<', $asOfDate);
                    });
            })
            ->select('owner_user_id');
    }

    public static function isAccessibleForOwner(?int $ownerUserId, ?string $asOfDate = null): bool
    {
        if ($ownerUserId === null) {
            return true;
        }

        return ! static::inaccessibleOwnerIds($asOfDate ?? now('Asia/Taipei')->toDateString())
            ->where('owner_user_id', $ownerUserId)
            ->exists();
    }

    public function isAccessible(): bool
    {
        return $this->is_enabled
            && ($this->subscription_ends_on === null
                || now('Asia/Taipei')->toDateString() <= $this->subscription_ends_on->toDateString());
    }

    public function statusLabel(): string
    {
        if (! $this->is_enabled) {
            return '已手動停用';
        }

        if ($this->subscription_ends_on !== null
            && now('Asia/Taipei')->toDateString() > $this->subscription_ends_on->toDateString()) {
            return '訂閱已到期';
        }

        return '使用中';
    }

    /**
     * Whether product features for this organization are gated by its subscription entitlements.
     */
    public function enforcesEntitlements(): bool
    {
        return $this->entitlements_enforced_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entitlements_enforced_at' => 'immutable_datetime',
            'subscription_ends_on' => 'date',
            'seat_limit' => 'integer',
            'client_limit' => 'integer',
            'monitor_limit' => 'integer',
            'campaign_limit' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }
}
