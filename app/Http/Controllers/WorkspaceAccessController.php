<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Organization;
use App\Models\PlatformAuditAction;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Services\PlatformAuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkspaceAccessController extends Controller
{
    public function __construct(private PlatformAuditLogger $auditLogger) {}

    public function index(Request $request): View
    {
        $owner = $this->workspaceOwnerFor($request);
        $organization = $owner->workspaceOrganization();
        $memberships = $owner->workspaceMemberships()
            ->whereNull('revoked_at')
            ->with(['memberUser:id,name,email', 'clients:id,name'])
            ->orderByDesc('accepted_at')
            ->get();
        $invitations = $owner->workspaceInvitations()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get();

        return view('workspace-access.index', [
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'memberships' => $memberships,
            'invitations' => $invitations,
            'seatLimit' => $organization?->seat_limit,
            'occupiedSeatCount' => 1 + $memberships->count() + $invitations->count(),
        ]);
    }

    public function storeInvitation(Request $request): RedirectResponse
    {
        $owner = $this->workspaceOwnerFor($request);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'role' => ['sometimes', 'string', Rule::in([
                WorkspaceMembership::ROLE_READ_ONLY,
                WorkspaceMembership::ROLE_EDITOR,
            ])],
        ] + $this->clientScopeValidationRules($owner), [
            'email.required' => '請輸入受邀者的電子郵件。',
            'email.email' => '請輸入有效的電子郵件。',
            'email.max' => '電子郵件不得超過 254 個字元。',
            'role.in' => '請選擇有效的組織權限。',
        ] + $this->clientScopeValidationMessages());
        $email = mb_strtolower(trim($validated['email']));
        $clientScope = $validated['client_scope'] ?? WorkspaceMembership::CLIENT_SCOPE_ALL;
        $clientIds = $clientScope === WorkspaceMembership::CLIENT_SCOPE_SELECTED
            ? array_map('intval', $validated['client_ids'])
            : null;

        $existingAccount = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($existingAccount !== null) {
            $this->ensureExistingAccountCanBeInvited($owner, $existingAccount);
        }

        $token = Str::random(64);
        DB::transaction(function () use ($owner, $email, $validated, $clientScope, $clientIds, $token): void {
            $organization = Organization::query()
                ->where('owner_user_id', $owner->getKey())
                ->lockForUpdate()
                ->first();

            if ($organization !== null && ! $organization->hasAvailableSeatForInvitation($email)) {
                throw ValidationException::withMessages([
                    'email' => '組織席次已滿，請聯絡平台管理員調整席次後再邀請。',
                ]);
            }

            $owner->workspaceInvitations()
                ->whereRaw('lower(email) = ?', [$email])
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $workspaceInvitation = $owner->workspaceInvitations()->create([
                'email' => $email,
                'role' => $validated['role'] ?? WorkspaceMembership::ROLE_READ_ONLY,
                'client_scope' => $clientScope,
                'client_ids' => $clientIds,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(WorkspaceInvitation::EXPIRATION_DAYS),
            ]);
            $this->auditLogger->record(PlatformAuditAction::MemberInvitationCreated, $workspaceInvitation, [
                'role' => $workspaceInvitation->role,
                'client_scope' => $workspaceInvitation->client_scope,
                'client_ids' => $clientIds ?? [],
            ], (int) $owner->getKey());
        });

        return redirect()->route('team.index')->with(
            'createdInviteUrl',
            route('team.invitations.show', ['token' => $token]),
        );
    }

    public function destroyInvitation(Request $request, int $invitation): RedirectResponse
    {
        $owner = $this->workspaceOwnerFor($request);

        DB::transaction(function () use ($owner, $invitation): void {
            Organization::query()
                ->where('owner_user_id', $owner->getKey())
                ->lockForUpdate()
                ->first();

            $workspaceInvitation = $owner->workspaceInvitations()
                ->whereKey($invitation)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->firstOrFail();
            $workspaceInvitation->forceFill(['revoked_at' => now()])->save();
            $this->auditLogger->record(
                PlatformAuditAction::MemberInvitationRevoked,
                $workspaceInvitation,
                [],
                (int) $owner->getKey(),
            );
        });

        return redirect()->route('team.index')->with('status', '邀請連結已撤銷。');
    }

    public function destroyMember(Request $request, int $membership): RedirectResponse
    {
        $owner = $this->workspaceOwnerFor($request);

        DB::transaction(function () use ($owner, $membership): void {
            Organization::query()
                ->where('owner_user_id', $owner->getKey())
                ->lockForUpdate()
                ->first();

            $workspaceMembership = $owner->workspaceMemberships()
                ->whereKey($membership)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->firstOrFail();
            $workspaceMembership->forceFill(['revoked_at' => now()])->save();
            $this->auditLogger->record(PlatformAuditAction::MemberRevoked, $workspaceMembership, [
                'member_user_id' => $workspaceMembership->member_user_id,
                'role' => $workspaceMembership->role,
            ], (int) $owner->getKey());
        });

        return redirect()->route('team.index')->with('status', '成員存取權已撤銷。');
    }

    public function updateMemberRole(Request $request, int $membership): RedirectResponse
    {
        $owner = $this->workspaceOwnerFor($request);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in([
                WorkspaceMembership::ROLE_READ_ONLY,
                WorkspaceMembership::ROLE_EDITOR,
            ])],
        ], [
            'role.required' => '請選擇成員權限。',
            'role.in' => '請選擇有效的組織權限。',
        ]);

        $workspaceMembership = $owner->workspaceMemberships()
            ->whereKey($membership)
            ->whereNull('revoked_at')
            ->firstOrFail();
        $previousRole = $workspaceMembership->role;
        $workspaceMembership->update(['role' => $validated['role']]);

        if ($previousRole !== $workspaceMembership->role) {
            $this->auditLogger->record(PlatformAuditAction::MemberRoleUpdated, $workspaceMembership, [
                'member_user_id' => $workspaceMembership->member_user_id,
                'changes' => $this->auditLogger->changes(
                    ['role' => $previousRole],
                    ['role' => $workspaceMembership->role],
                ),
            ], (int) $owner->getKey());
        }

        return redirect()->route('team.index')->with('status', '成員權限已更新。');
    }

    public function editMemberClients(Request $request, int $membership): View
    {
        $owner = $this->workspaceOwnerFor($request);

        $workspaceMembership = $owner->workspaceMemberships()
            ->whereKey($membership)
            ->whereNull('revoked_at')
            ->with(['memberUser:id,name,email', 'clients:id,name'])
            ->firstOrFail();

        return view('workspace-access.clients', [
            'membership' => $workspaceMembership,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'selectedClientIds' => $workspaceMembership->clients->modelKeys(),
        ]);
    }

    public function updateMemberClients(Request $request, int $membership): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 401);

        $validated = $request->validate(
            $this->clientScopeValidationRules($owner, true),
            $this->clientScopeValidationMessages(),
        );
        $clientScope = $validated['client_scope'];
        $clientIds = $clientScope === WorkspaceMembership::CLIENT_SCOPE_SELECTED
            ? array_map('intval', $validated['client_ids'])
            : [];

        DB::transaction(function () use ($owner, $membership, $clientScope, $clientIds): void {
            $workspaceMembership = $owner->workspaceMemberships()
                ->whereKey($membership)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->firstOrFail();

            $previousClientScope = $workspaceMembership->client_scope;
            $previousClientIds = DB::table('workspace_membership_clients')
                ->where('workspace_membership_id', $workspaceMembership->getKey())
                ->orderBy('client_id')
                ->pluck('client_id')
                ->map(static fn (mixed $clientId): int => (int) $clientId)
                ->all();

            $workspaceMembership->update(['client_scope' => $clientScope]);
            $workspaceMembership->clients()->sync($clientIds);

            sort($clientIds);

            if ($previousClientScope !== $clientScope || $previousClientIds !== $clientIds) {
                $this->auditLogger->record(PlatformAuditAction::MemberClientScopeUpdated, $workspaceMembership, [
                    'member_user_id' => $workspaceMembership->member_user_id,
                    'before' => ['client_scope' => $previousClientScope, 'client_ids' => $previousClientIds],
                    'after' => ['client_scope' => $clientScope, 'client_ids' => $clientIds],
                ], (int) $workspaceMembership->owner_user_id);
            }
        });

        return redirect()->route('team.index')->with('status', '成員的客戶存取範圍已更新。');
    }

    public function showInvitation(string $token): View|RedirectResponse
    {
        $invitation = $this->findAvailableInvitation($token);

        if ($this->invitedAccount($invitation) !== null) {
            return redirect()->route('team.invitations.join.show', ['token' => $token]);
        }

        return view('workspace-access.accept', [
            'invitation' => $invitation,
            'organizationName' => $this->invitationOrganizationName($invitation),
            'invitationClients' => $this->invitationClients($invitation),
        ]);
    }

    public function showJoinInvitation(Request $request, string $token): View
    {
        $invitation = $this->findAvailableInvitation($token);
        $user = $this->invitedAuthenticatedUser($request, $invitation);

        return view('workspace-access.join', [
            'invitation' => $invitation,
            'organizationName' => $this->invitationOrganizationName($invitation),
            'invitationClients' => $this->invitationClients($invitation),
            'invitedUser' => $user,
        ]);
    }

    public function joinInvitation(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->findAvailableInvitation($token);
        $user = $this->invitedAuthenticatedUser($request, $invitation);

        DB::transaction(function () use ($invitation, $user): void {
            $lockedInvitation = $this->lockAcceptableInvitation($invitation);
            $owner = User::query()->findOrFail($lockedInvitation->owner_user_id);
            $this->ensureExistingAccountCanBeInvited($owner, $user, 'invitation');
            $clientScope = $lockedInvitation->client_scope ?? WorkspaceMembership::CLIENT_SCOPE_ALL;
            $clientIds = $this->acceptedInvitationClientIds($lockedInvitation, $clientScope);

            $membership = WorkspaceMembership::query()
                ->where('owner_user_id', $lockedInvitation->owner_user_id)
                ->where('member_user_id', $user->getKey())
                ->lockForUpdate()
                ->first() ?? new WorkspaceMembership([
                    'owner_user_id' => $lockedInvitation->owner_user_id,
                    'member_user_id' => $user->getKey(),
                ]);
            $membership->forceFill([
                'role' => $lockedInvitation->role ?? WorkspaceMembership::ROLE_READ_ONLY,
                'client_scope' => $clientScope,
                'accepted_at' => now(),
                'revoked_at' => null,
            ])->save();
            $membership->clients()->sync($clientIds);
            $lockedInvitation->forceFill(['accepted_at' => now()])->save();
            $this->recordInvitationAccepted($lockedInvitation, $membership, $user, false);
        });

        $request->session()->put(User::CURRENT_WORKSPACE_SESSION_KEY, (int) $invitation->owner_user_id);
        $user->forgetWorkspaceContext();

        return redirect()->route('dashboard')->with('status', '已加入組織，目前正在使用該組織的工作區。');
    }

    public function acceptInvitation(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:12', 'max:200', 'confirmed'],
        ], [
            'name.required' => '請輸入姓名。',
            'name.max' => '姓名不得超過 120 個字元。',
            'password.min' => '密碼至少需要 12 個字元。',
            'password.max' => '密碼不得超過 200 個字元。',
            'password.confirmed' => '兩次輸入的密碼不一致。',
        ]);
        $invitation = $this->findAvailableInvitation($token);

        $member = DB::transaction(function () use ($invitation, $validated): User {
            $lockedInvitation = $this->lockAcceptableInvitation($invitation);

            if (User::query()->whereRaw('lower(email) = ?', [$lockedInvitation->email])->exists()) {
                throw ValidationException::withMessages([
                    'email' => '此電子郵件已有帳戶，請登入後接受此邀請。',
                ]);
            }

            $clientScope = $lockedInvitation->client_scope ?? WorkspaceMembership::CLIENT_SCOPE_ALL;
            $clientIds = $this->acceptedInvitationClientIds($lockedInvitation, $clientScope);

            $member = User::query()->create([
                'name' => $validated['name'],
                'email' => $lockedInvitation->email,
                'password' => Hash::make($validated['password']),
            ]);
            $membership = WorkspaceMembership::query()->create([
                'owner_user_id' => $lockedInvitation->owner_user_id,
                'member_user_id' => $member->getKey(),
                'role' => $lockedInvitation->role ?? WorkspaceMembership::ROLE_READ_ONLY,
                'client_scope' => $clientScope,
                'accepted_at' => now(),
            ]);
            $membership->clients()->sync($clientIds);
            $lockedInvitation->forceFill(['accepted_at' => now()])->save();
            $this->recordInvitationAccepted($lockedInvitation, $membership, $member, true);

            return $member;
        });

        Auth::login($member);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', '已加入組織。');
    }

    private function recordInvitationAccepted(
        WorkspaceInvitation $invitation,
        WorkspaceMembership $membership,
        User $member,
        bool $createdAccount,
    ): void {
        $this->auditLogger->record(PlatformAuditAction::MemberInvitationAccepted, $membership, [
            'invitation_id' => $invitation->getKey(),
            'member_user_id' => $member->getKey(),
            'role' => $membership->role,
            'client_scope' => $membership->client_scope,
            'created_account' => $createdAccount,
        ], (int) $invitation->owner_user_id, $member);
    }

    private function lockAcceptableInvitation(WorkspaceInvitation $invitation): WorkspaceInvitation
    {
        $organization = Organization::query()
            ->where('owner_user_id', $invitation->owner_user_id)
            ->lockForUpdate()
            ->first();
        $lockedInvitation = WorkspaceInvitation::query()
            ->whereKey($invitation->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $lockedInvitation->accepted_at !== null
            || $lockedInvitation->revoked_at !== null
            || $lockedInvitation->expires_at->isPast()
            || ($organization !== null && ! $organization->isAccessible())
        ) {
            throw (new ModelNotFoundException)->setModel(WorkspaceInvitation::class, [$invitation->getKey()]);
        }

        return $lockedInvitation;
    }

    /**
     * Resolve the invitation's clients in the inviting workspace; the accepting account may already be scoped to another workspace.
     *
     * @return list<int>
     */
    private function acceptedInvitationClientIds(WorkspaceInvitation $invitation, string $clientScope): array
    {
        if ($clientScope !== WorkspaceMembership::CLIENT_SCOPE_SELECTED) {
            return [];
        }

        $clientIds = Client::withoutGlobalScopes()
            ->where('user_id', $invitation->owner_user_id)
            ->whereIn('id', $invitation->client_ids ?? [])
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $clientId): int => (int) $clientId)
            ->all();

        if ($clientIds === []) {
            throw ValidationException::withMessages([
                'client_scope' => '邀請指定的客戶已不存在，請聯絡擁有者重新建立邀請。',
            ]);
        }

        return $clientIds;
    }

    private function invitationOrganizationName(WorkspaceInvitation $invitation): string
    {
        return Organization::query()->where('owner_user_id', $invitation->owner_user_id)->value('name')
            ?? $invitation->owner->name.' 的組織';
    }

    /** @return Collection<int, Client> */
    private function invitationClients(WorkspaceInvitation $invitation): Collection
    {
        if ($invitation->client_scope !== WorkspaceMembership::CLIENT_SCOPE_SELECTED) {
            return new Collection;
        }

        return Client::withoutGlobalScopes()
            ->where('user_id', $invitation->owner_user_id)
            ->whereIn('id', $invitation->client_ids ?? [])
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function invitedAccount(WorkspaceInvitation $invitation): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])->first();
    }

    private function invitedAuthenticatedUser(Request $request, WorkspaceInvitation $invitation): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(
            mb_strtolower($user->email) === mb_strtolower($invitation->email),
            403,
            '此邀請不是寄給目前登入的帳戶，請改用受邀電子郵件登入。',
        );

        return $user;
    }

    /**
     * @throws ValidationException
     */
    private function ensureExistingAccountCanBeInvited(User $owner, User $account, string $errorKey = 'email'): void
    {
        if ($account->is($owner)) {
            throw ValidationException::withMessages([$errorKey => '不能邀請組織擁有者自己。']);
        }

        if ($account->isPlatformAdmin()) {
            throw ValidationException::withMessages([$errorKey => '平台管理員帳戶不能加入組織，請改用總管理平台檢視組織。']);
        }

        $isActiveMember = WorkspaceMembership::query()
            ->where('owner_user_id', $owner->getKey())
            ->where('member_user_id', $account->getKey())
            ->whereNull('revoked_at')
            ->exists();

        if ($isActiveMember) {
            throw ValidationException::withMessages([$errorKey => '此帳戶已是組織成員。']);
        }
    }

    private function findAvailableInvitation(string $token): WorkspaceInvitation
    {
        $invitation = WorkspaceInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->with('owner:id,name')
            ->firstOrFail();

        $organization = Organization::query()
            ->where('owner_user_id', $invitation->owner_user_id)
            ->first();

        abort_unless($organization === null || $organization->isAccessible(), 404);

        return $invitation;
    }

    private function workspaceOwnerFor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->workspaceOwner();
    }

    /** @return array<string, array<int, mixed>> */
    private function clientScopeValidationRules(User $owner, bool $clientScopeRequired = false): array
    {
        return [
            'client_scope' => [$clientScopeRequired ? 'required' : 'sometimes', 'string', Rule::in([
                WorkspaceMembership::CLIENT_SCOPE_ALL,
                WorkspaceMembership::CLIENT_SCOPE_SELECTED,
            ])],
            'client_ids' => [
                'exclude_unless:client_scope,selected',
                'required',
                'array',
                'list',
                'min:1',
                Rule::exists('clients', 'id')->where('user_id', $owner->id),
            ],
            'client_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    private function clientScopeValidationMessages(): array
    {
        return [
            'client_scope.required' => '請選擇客戶存取範圍。',
            'client_scope.in' => '請選擇有效的客戶存取範圍。',
            'client_ids.required' => '請至少選擇一個客戶。',
            'client_ids.array' => '客戶選擇格式無效。',
            'client_ids.list' => '客戶選擇格式無效。',
            'client_ids.min' => '指定客戶時，至少需要選擇一個客戶。',
            'client_ids.exists' => '選取的客戶不存在或不屬於此組織。',
            'client_ids.*.integer' => '客戶選擇格式無效。',
            'client_ids.*.distinct' => '不可重複選擇同一個客戶。',
        ];
    }
}
