<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
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

        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => '此電子郵件已有帳戶，請使用尚未註冊的電子郵件邀請。',
            ]);
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

            $owner->workspaceInvitations()->create([
                'email' => $email,
                'role' => $validated['role'] ?? WorkspaceMembership::ROLE_READ_ONLY,
                'client_scope' => $clientScope,
                'client_ids' => $clientIds,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(WorkspaceInvitation::EXPIRATION_DAYS),
            ]);
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
        $workspaceMembership->update(['role' => $validated['role']]);

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

            $workspaceMembership->update(['client_scope' => $clientScope]);
            $workspaceMembership->clients()->sync($clientIds);
        });

        return redirect()->route('team.index')->with('status', '成員的客戶存取範圍已更新。');
    }

    public function showInvitation(string $token): View
    {
        $invitation = $this->findAvailableInvitation($token);
        $invitationClients = $invitation->client_scope === WorkspaceMembership::CLIENT_SCOPE_SELECTED
            ? Client::query()
                ->where('user_id', $invitation->owner_user_id)
                ->whereIn('id', $invitation->client_ids ?? [])
                ->orderBy('name')
                ->get(['id', 'name'])
            : collect();

        return view('workspace-access.accept', [
            'invitation' => $invitation,
            'invitationClients' => $invitationClients,
        ]);
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
            ) {
                throw (new ModelNotFoundException)->setModel(WorkspaceInvitation::class, [$invitation->getKey()]);
            }

            if ($organization !== null && ! $organization->isAccessible()) {
                throw (new ModelNotFoundException)->setModel(WorkspaceInvitation::class, [$invitation->getKey()]);
            }

            if (User::query()->whereRaw('lower(email) = ?', [$lockedInvitation->email])->exists()) {
                throw ValidationException::withMessages([
                    'email' => '此電子郵件已有帳戶，無法接受此邀請。',
                ]);
            }

            $clientScope = $lockedInvitation->client_scope ?? WorkspaceMembership::CLIENT_SCOPE_ALL;
            $clientIds = [];

            if ($clientScope === WorkspaceMembership::CLIENT_SCOPE_SELECTED) {
                $clientIds = Client::query()
                    ->where('user_id', $lockedInvitation->owner_user_id)
                    ->whereIn('id', $lockedInvitation->client_ids ?? [])
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(static fn (mixed $clientId): int => (int) $clientId)
                    ->all();

                if ($clientIds === []) {
                    throw ValidationException::withMessages([
                        'client_scope' => '邀請指定的客戶已不存在，請聯絡擁有者重新建立邀請。',
                    ]);
                }
            }

            $member = User::query()->create([
                'name' => $validated['name'],
                'email' => $lockedInvitation->email,
                'password' => Hash::make($validated['password']),
            ]);
            $membership = $member->workspaceMembership()->create([
                'owner_user_id' => $lockedInvitation->owner_user_id,
                'role' => $lockedInvitation->role ?? WorkspaceMembership::ROLE_READ_ONLY,
                'client_scope' => $clientScope,
                'accepted_at' => now(),
            ]);
            $membership->clients()->sync($clientIds);
            $lockedInvitation->forceFill(['accepted_at' => now()])->save();

            return $member;
        });

        Auth::login($member);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', '已加入組織。');
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
