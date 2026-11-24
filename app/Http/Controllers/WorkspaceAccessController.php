<?php

namespace App\Http\Controllers;

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
        $owner = $request->user();
        abort_unless($owner instanceof User, 401);

        return view('workspace-access.index', [
            'memberships' => $owner->workspaceMemberships()
                ->whereNull('revoked_at')
                ->with('memberUser:id,name,email')
                ->orderByDesc('accepted_at')
                ->get(),
            'invitations' => $owner->workspaceInvitations()
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->orderBy('expires_at')
                ->get(),
        ]);
    }

    public function storeInvitation(Request $request): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 401);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'role' => ['sometimes', 'string', Rule::in([
                WorkspaceMembership::ROLE_READ_ONLY,
                WorkspaceMembership::ROLE_EDITOR,
            ])],
        ], [
            'email.required' => '請輸入受邀者的電子郵件。',
            'email.email' => '請輸入有效的電子郵件。',
            'email.max' => '電子郵件不得超過 254 個字元。',
            'role.in' => '請選擇有效的工作區權限。',
        ]);
        $email = mb_strtolower(trim($validated['email']));

        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => '此電子郵件已有帳戶，請使用尚未註冊的電子郵件邀請。',
            ]);
        }

        $owner->workspaceInvitations()
            ->whereRaw('lower(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $token = Str::random(64);
        $owner->workspaceInvitations()->create([
            'email' => $email,
            'role' => $validated['role'] ?? WorkspaceMembership::ROLE_READ_ONLY,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(WorkspaceInvitation::EXPIRATION_DAYS),
        ]);

        return redirect()->route('team.index')->with(
            'createdInviteUrl',
            route('team.invitations.show', ['token' => $token]),
        );
    }

    public function destroyInvitation(Request $request, int $invitation): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 401);

        $workspaceInvitation = $owner->workspaceInvitations()
            ->whereKey($invitation)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->firstOrFail();
        $workspaceInvitation->forceFill(['revoked_at' => now()])->save();

        return redirect()->route('team.index')->with('status', '邀請連結已撤銷。');
    }

    public function destroyMember(Request $request, int $membership): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 401);

        $workspaceMembership = $owner->workspaceMemberships()
            ->whereKey($membership)
            ->whereNull('revoked_at')
            ->firstOrFail();
        $workspaceMembership->forceFill(['revoked_at' => now()])->save();

        return redirect()->route('team.index')->with('status', '成員存取權已撤銷。');
    }

    public function updateMemberRole(Request $request, int $membership): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 401);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in([
                WorkspaceMembership::ROLE_READ_ONLY,
                WorkspaceMembership::ROLE_EDITOR,
            ])],
        ], [
            'role.required' => '請選擇成員權限。',
            'role.in' => '請選擇有效的工作區權限。',
        ]);

        $workspaceMembership = $owner->workspaceMemberships()
            ->whereKey($membership)
            ->whereNull('revoked_at')
            ->firstOrFail();
        $workspaceMembership->update(['role' => $validated['role']]);

        return redirect()->route('team.index')->with('status', '成員權限已更新。');
    }

    public function showInvitation(string $token): View
    {
        return view('workspace-access.accept', [
            'invitation' => $this->findAvailableInvitation($token),
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

            if (User::query()->whereRaw('lower(email) = ?', [$lockedInvitation->email])->exists()) {
                throw ValidationException::withMessages([
                    'email' => '此電子郵件已有帳戶，無法接受此邀請。',
                ]);
            }

            $member = User::query()->create([
                'name' => $validated['name'],
                'email' => $lockedInvitation->email,
                'password' => Hash::make($validated['password']),
            ]);
            $member->workspaceMembership()->create([
                'owner_user_id' => $lockedInvitation->owner_user_id,
                'role' => $lockedInvitation->role ?? WorkspaceMembership::ROLE_READ_ONLY,
                'accepted_at' => now(),
            ]);
            $lockedInvitation->forceFill(['accepted_at' => now()])->save();

            return $member;
        });

        Auth::login($member);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', '已加入工作區。');
    }

    private function findAvailableInvitation(string $token): WorkspaceInvitation
    {
        return WorkspaceInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->with('owner:id,name')
            ->firstOrFail();
    }
}
