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
#[Hidden(['password', 'remember_token', 'is_platform_admin'])]
class User extends Authenticatable
{
    /** @var list<int>|null */
    private ?array $workspaceClientIdsCache = null;

    private bool $workspaceClientIdsResolved = false;

    private ?Workspace $currentWorkspaceCache = null;

    private bool $currentWorkspaceResolved = false;

    /** @var array{membershipOwnerUserId: int|null}|null */
    private ?array $activeWorkspaceSelectionCache = null;

    public const CURRENT_WORKSPACE_SESSION_KEY = 'current_workspace_owner_id';

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

    /**
     * The membership used for the workspace currently selected in this session; none while working in the user's own workspace.
     */
    public function workspaceMembership(): HasOne
    {
        $relation = $this->hasOne(WorkspaceMembership::class, 'member_user_id')->whereNull('revoked_at');
        $membershipOwnerUserId = $this->activeMembershipOwnerUserId();

        return $membershipOwnerUserId === null
            ? $relation->whereRaw('1 = 0')
            : $relation->where('owner_user_id', $membershipOwnerUserId);
    }

    /**
     * Active memberships this user holds in other owners' workspaces.
     */
    public function joinedWorkspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class, 'member_user_id')
            ->whereNull('revoked_at')
            ->orderBy('accepted_at')
            ->orderBy('id');
    }

    public function ownsOrganization(): bool
    {
        return Organization::query()->where('owner_user_id', $this->getKey())->exists();
    }

    /**
     * Whether the user may work in their own workspace: owners of an organization, or accounts without other memberships.
     */
    public function canUseOwnWorkspace(): bool
    {
        return $this->ownsOrganization() || ! $this->joinedWorkspaceMemberships()->exists();
    }

    /**
     * @return list<array{key: string, name: string, roleLabel: string, isCurrent: bool, isAccessible: bool}>
     */
    public function availableWorkspaces(): array
    {
        if ($this->isPlatformAdmin()) {
            return [];
        }

        $currentOwnerId = $this->workspaceOwnerId();
        $workspaces = [];

        if ($this->canUseOwnWorkspace()) {
            $ownOrganization = Organization::query()->where('owner_user_id', $this->getKey())->first();
            $workspaces[] = [
                'key' => 'own',
                'name' => $ownOrganization?->name ?? $this->name.' 的組織',
                'roleLabel' => '擁有者',
                'isCurrent' => $currentOwnerId === (int) $this->getKey(),
                'isAccessible' => $ownOrganization?->isAccessible() ?? true,
            ];
        }

        $memberships = $this->joinedWorkspaceMemberships()->with('owner:id,name')->get();
        $organizations = Organization::query()
            ->whereIn('owner_user_id', $memberships->pluck('owner_user_id'))
            ->get()
            ->keyBy('owner_user_id');

        foreach ($memberships as $membership) {
            $organization = $organizations->get($membership->owner_user_id);
            $workspaces[] = [
                'key' => (string) $membership->getKey(),
                'name' => $organization?->name ?? ($membership->owner?->name ?? '未命名').' 的組織',
                'roleLabel' => $membership->roleLabel().'成員',
                'isCurrent' => $currentOwnerId === (int) $membership->owner_user_id,
                'isAccessible' => $organization?->isAccessible() ?? true,
            ];
        }

        return $workspaces;
    }

    /**
     * Resolve the workspace owner for a switcher key, or null when the key is not one of this user's workspaces.
     */
    public function workspaceOwnerIdForSwitchKey(string $key): ?int
    {
        if ($key === 'own') {
            return $this->canUseOwnWorkspace() ? (int) $this->getKey() : null;
        }

        if (! ctype_digit($key)) {
            return null;
        }

        $ownerUserId = $this->joinedWorkspaceMemberships()->whereKey((int) $key)->value('owner_user_id');

        return is_numeric($ownerUserId) ? (int) $ownerUserId : null;
    }

    public function forgetWorkspaceContext(): void
    {
        $this->activeWorkspaceSelectionCache = null;
        $this->workspaceClientIdsCache = null;
        $this->workspaceClientIdsResolved = false;
        $this->currentWorkspaceCache = null;
        $this->currentWorkspaceResolved = false;
        $this->unsetRelation('workspaceMembership');
    }

    /**
     * Owner of the joined workspace in use, or null for the user's own workspace.
     *
     * A valid, accessible session selection wins. Otherwise the user's own organization is preferred,
     * then the earliest joined workspace; accessible workspaces are preferred over suspended ones.
     */
    private function activeMembershipOwnerUserId(): ?int
    {
        if ($this->activeWorkspaceSelectionCache !== null) {
            return $this->activeWorkspaceSelectionCache['membershipOwnerUserId'];
        }

        if (! $this->exists) {
            return null;
        }

        $ownUserId = (int) $this->getKey();
        $joinedOwnerIds = $this->joinedWorkspaceMemberships()
            ->pluck('owner_user_id')
            ->map(static fn (mixed $ownerUserId): int => (int) $ownerUserId)
            ->all();

        $candidateOwnerIds = [];

        if ($joinedOwnerIds === [] || $this->ownsOrganization()) {
            $candidateOwnerIds[] = $ownUserId;
        }

        array_push($candidateOwnerIds, ...$joinedOwnerIds);

        $selectedOwnerId = request()->hasSession()
            ? request()->session()->get(self::CURRENT_WORKSPACE_SESSION_KEY)
            : null;
        $selectedOwnerId = is_numeric($selectedOwnerId) ? (int) $selectedOwnerId : null;

        if ($selectedOwnerId !== null && in_array($selectedOwnerId, $candidateOwnerIds, true)) {
            array_unshift($candidateOwnerIds, $selectedOwnerId);
        }

        $resolvedOwnerId = $candidateOwnerIds[0];

        foreach (array_values(array_unique($candidateOwnerIds)) as $candidateOwnerId) {
            if (Organization::isAccessibleForOwner($candidateOwnerId)) {
                $resolvedOwnerId = $candidateOwnerId;

                break;
            }
        }

        $membershipOwnerUserId = $resolvedOwnerId === $ownUserId ? null : $resolvedOwnerId;
        $this->activeWorkspaceSelectionCache = ['membershipOwnerUserId' => $membershipOwnerUserId];

        return $membershipOwnerUserId;
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

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    public function workspaceOrganization(): ?Organization
    {
        return Organization::query()->where('owner_user_id', $this->workspaceOwnerId())->first();
    }

    public function currentWorkspace(): ?Workspace
    {
        if ($this->currentWorkspaceResolved) {
            return $this->currentWorkspaceCache;
        }

        $this->currentWorkspaceCache = $this->workspaceOrganization()?->workspace;
        $this->currentWorkspaceResolved = true;

        return $this->currentWorkspaceCache;
    }

    public function hasActiveOrganization(): bool
    {
        if ($this->isPlatformAdmin()) {
            return true;
        }

        return $this->workspaceOrganization()?->isAccessible() ?? true;
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

    public function googleSearchConsoleConnection(): HasOne
    {
        return $this->workspaceOwner()->hasOne(GoogleSearchConsoleConnection::class);
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
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
