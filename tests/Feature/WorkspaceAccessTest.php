<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\Organization;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_and_revoke_a_hashed_invitation(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('team.index'))
            ->assertOk()
            ->assertSee('組織成員')
            ->assertSee('name="role"', false)
            ->assertSee('value="read_only"', false)
            ->assertSee('value="editor"', false);

        $this->post(route('team.invitations.store'), ['email' => 'member@example.test'])
            ->assertRedirect(route('team.index'))
            ->assertSessionHas('createdInviteUrl');

        $inviteUrl = (string) session('createdInviteUrl');
        $token = basename((string) parse_url($inviteUrl, PHP_URL_PATH));
        $invitation = WorkspaceInvitation::query()->firstOrFail();

        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame('member@example.test', $invitation->email);
        $this->assertSame(WorkspaceMembership::ROLE_READ_ONLY, $invitation->role);
        $this->assertSame(
            $invitation->created_at->copy()->addDays(WorkspaceInvitation::EXPIRATION_DAYS)->timestamp,
            $invitation->expires_at->timestamp,
        );

        $this->get(route('team.index'))
            ->assertOk()
            ->assertSee($inviteUrl)
            ->assertSee('member@example.test');

        $this->delete(route('team.invitations.destroy', $invitation))
            ->assertRedirect(route('team.index'));

        $this->assertNotNull($invitation->fresh()->revoked_at);
    }

    public function test_new_user_can_accept_an_invitation_and_becomes_a_read_only_member(): void
    {
        $owner = User::factory()->create(['name' => '工作區擁有者']);
        $this->actingAs($owner)->post(route('team.invitations.store'), ['email' => 'new.member@example.test']);
        $inviteUrl = (string) session('createdInviteUrl');
        $token = basename((string) parse_url($inviteUrl, PHP_URL_PATH));

        $this->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->get(route('team.invitations.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('加入 工作區擁有者 的組織')
            ->assertSee('new.member@example.test');

        $this->post(route('team.invitations.accept', ['token' => $token]), [
            'name' => '唯讀同事',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
        ])->assertRedirect(route('dashboard'));

        $member = User::query()->where('email', 'new.member@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($member);
        $this->assertTrue(Hash::check('a-secure-password-123', $member->password));
        $this->assertSame($owner->id, $member->workspaceOwnerId());
        $this->assertSame(WorkspaceMembership::ROLE_READ_ONLY, $member->workspaceMembership?->role);
        $this->assertNotNull(WorkspaceInvitation::query()->firstOrFail()->fresh()->accepted_at);

        $this->get(route('team.invitations.show', ['token' => $token]))
            ->assertRedirect(route('dashboard'));

        $this->post(route('logout'));
        $this->get(route('team.invitations.show', ['token' => $token]))
            ->assertNotFound();
    }

    public function test_owner_can_invite_an_editor_who_can_manage_campaigns_but_not_workspace_settings(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $owner = User::factory()->create(['name' => '品牌工作區']);
        $ownerClient = $owner->clients()->create(['name' => '品牌客戶']);
        $this->actingAs($owner);
        Monitor::factory()->create([
            'name' => '品牌 Facebook 貼文',
            'url' => 'https://www.facebook.com/posts/EDITOR123',
            'url_hash' => Monitor::fingerprintUrl('https://www.facebook.com/posts/EDITOR123'),
            'label' => 'Editor launch',
            'client_id' => $ownerClient->id,
        ]);

        $this->post(route('team.invitations.store'), [
            'email' => 'editor@example.test',
            'role' => WorkspaceMembership::ROLE_EDITOR,
        ])
            ->assertRedirect(route('team.index'))
            ->assertSessionHas('createdInviteUrl');

        $inviteUrl = (string) session('createdInviteUrl');
        $token = basename((string) parse_url($inviteUrl, PHP_URL_PATH));
        $invitation = WorkspaceInvitation::query()->where('email', 'editor@example.test')->firstOrFail();

        $this->assertSame(WorkspaceMembership::ROLE_EDITOR, $invitation->role);

        $this->get(route('team.index'))
            ->assertOk()
            ->assertSee('editor@example.test')
            ->assertSee('可編輯');

        $this->post(route('logout'))
            ->assertRedirect(route('login'));
        $this->get(route('team.invitations.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('可編輯成員')
            ->assertSee('組織管理與整合設定仍由擁有者負責');
        $this->post(route('team.invitations.accept', ['token' => $token]), [
            'name' => '行銷編輯',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
        ])->assertRedirect(route('dashboard'));

        $editor = User::query()->where('email', 'editor@example.test')->firstOrFail();
        $this->assertSame(WorkspaceMembership::ROLE_EDITOR, $editor->workspaceMembership?->role);
        $this->assertTrue($editor->canManageWorkspace());

        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('可編輯成員')
            ->assertDontSee(route('integrations.google-analytics.edit'), false)
            ->assertDontSee(route('team.index'), false);

        $this->post(route('clients.store'), ['name' => '編輯者新增客戶'])
            ->assertRedirect(route('clients.index'));
        $this->assertDatabaseHas('clients', [
            'user_id' => $owner->id,
            'name' => '編輯者新增客戶',
        ]);

        $this->post(route('monitors.store'), [
            'name' => '編輯者建立監測',
            'url' => 'http://fixture.test/editor-post',
            'label' => 'Editor launch',
            'client_id' => $ownerClient->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('monitors', [
            'user_id' => $owner->id,
            'client_id' => $ownerClient->id,
            'name' => '編輯者建立監測',
        ]);

        $this->post(route('social-engagement-targets.store'), [
            'tag' => 'Editor launch',
            'social_platform' => 'facebook',
            'client_id' => $ownerClient->id,
            'target_interactions' => 100,
        ])->assertRedirect();
        $this->assertDatabaseHas('social_engagement_targets', [
            'user_id' => $owner->id,
            'client_id' => $ownerClient->id,
            'tag' => 'Editor launch',
            'social_platform' => 'facebook',
        ]);

        $this->get(route('team.index'))->assertForbidden();
        $this->patch(route('team.members.role.update', $editor->workspaceMembership), [
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
        ])->assertForbidden();
        $this->get(route('integrations.google-analytics.edit'))->assertForbidden();
        $this->get(route('integrations.google-ads.edit'))->assertForbidden();
        $this->get(route('integrations.meta-marketing.edit'))->assertForbidden();
        $this->get(route('status-pages.edit'))->assertForbidden();
    }

    public function test_owner_cannot_create_an_invitation_with_an_unknown_role(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->from(route('team.index'))
            ->followingRedirects()
            ->post(route('team.invitations.store'), [
                'email' => 'invalid-role@example.test',
                'role' => 'administrator',
            ])
            ->assertOk()
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('invite-role-error', false)
            ->assertSee('請選擇有效的組織權限。');

        $this->assertDatabaseMissing('workspace_invitations', [
            'owner_user_id' => $owner->id,
            'email' => 'invalid-role@example.test',
        ]);
    }

    public function test_owner_can_change_an_active_members_role_and_the_new_permission_takes_effect(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $membership = WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
        ]);

        $this->actingAs($owner)
            ->get(route('team.index'))
            ->assertOk()
            ->assertSee('member-role-'.$membership->id, false)
            ->assertSee('唯讀');

        $this->patch(route('team.members.role.update', $membership), [
            'role' => WorkspaceMembership::ROLE_EDITOR,
        ])->assertRedirect(route('team.index'));

        $this->assertSame(WorkspaceMembership::ROLE_EDITOR, $membership->fresh()->role);

        $this->actingAs($member)
            ->post(route('clients.store'), ['name' => '升級後可新增客戶'])
            ->assertRedirect(route('clients.index'));
        $this->assertDatabaseHas('clients', [
            'user_id' => $owner->id,
            'name' => '升級後可新增客戶',
        ]);

        $this->actingAs($owner)
            ->patch(route('team.members.role.update', $membership), [
                'role' => WorkspaceMembership::ROLE_READ_ONLY,
            ])->assertRedirect(route('team.index'));

        $this->actingAs($member->fresh())
            ->post(route('clients.store'), ['name' => '降級後不可新增客戶'])
            ->assertForbidden();
        $this->assertDatabaseMissing('clients', [
            'user_id' => $owner->id,
            'name' => '降級後不可新增客戶',
        ]);
    }

    public function test_owner_cannot_update_a_membership_to_an_unknown_role(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $membership = WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
        ]);

        $this->actingAs($owner)
            ->patch(route('team.members.role.update', $membership), ['role' => 'administrator'])
            ->assertSessionHasErrors('role');

        $this->assertSame(WorkspaceMembership::ROLE_READ_ONLY, $membership->fresh()->role);
    }

    public function test_expired_and_revoked_invitation_tokens_cannot_be_used(): void
    {
        $owner = User::factory()->create();
        $expiredToken = Str::random(64);
        $revokedToken = Str::random(64);

        WorkspaceInvitation::factory()->create([
            'owner_user_id' => $owner->id,
            'email' => 'expired@example.test',
            'token_hash' => hash('sha256', $expiredToken),
            'expires_at' => now()->subMinute(),
        ]);
        WorkspaceInvitation::factory()->create([
            'owner_user_id' => $owner->id,
            'email' => 'revoked@example.test',
            'token_hash' => hash('sha256', $revokedToken),
            'revoked_at' => now(),
        ]);

        $this->get(route('team.invitations.show', ['token' => $expiredToken]))->assertNotFound();
        $this->get(route('team.invitations.show', ['token' => $revokedToken]))->assertNotFound();
        $this->post(route('team.invitations.accept', ['token' => $expiredToken]), [
            'name' => '測試成員',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'expired@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'revoked@example.test']);
    }

    public function test_owner_cannot_invite_themselves_or_revoke_another_workspaces_records(): void
    {
        $owner = User::factory()->create(['email' => 'already.registered@example.test']);
        $otherOwner = User::factory()->create();
        $existingUser = $owner;
        $otherInvitation = WorkspaceInvitation::factory()->create(['owner_user_id' => $otherOwner->id]);
        $otherMembership = WorkspaceMembership::factory()->create(['owner_user_id' => $otherOwner->id]);

        $this->actingAs($owner)
            ->post(route('team.invitations.store'), ['email' => $existingUser->email])
            ->assertSessionHasErrors('email');

        $this->delete(route('team.invitations.destroy', $otherInvitation))
            ->assertNotFound();
        $this->delete(route('team.members.destroy', $otherMembership))
            ->assertNotFound();
        $this->patch(route('team.members.role.update', $otherMembership), [
            'role' => WorkspaceMembership::ROLE_EDITOR,
        ])->assertNotFound();
        $this->get(route('team.members.clients.edit', $otherMembership))->assertNotFound();
        $this->patch(route('team.members.clients.update', $otherMembership), [
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ])->assertNotFound();
        $this->get(route('integrations.google-analytics.edit'))
            ->assertOk();
        $this->post(route('clients.store'), ['name' => '擁有者的新客戶'])
            ->assertRedirect(route('clients.index'));

        $this->assertDatabaseMissing('workspace_invitations', [
            'owner_user_id' => $owner->id,
            'email' => $existingUser->email,
        ]);
        $this->assertNull($otherInvitation->fresh()->revoked_at);
        $this->assertNull($otherMembership->fresh()->revoked_at);
        $this->assertDatabaseHas('clients', [
            'user_id' => $owner->id,
            'name' => '擁有者的新客戶',
        ]);
    }

    public function test_member_reads_the_owner_workspace_without_exposing_another_workspace(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $ownerMonitor = Monitor::factory()->create([
            'name' => '擁有者監測項目',
            'url' => 'https://owner.example.test/',
            'url_hash' => Monitor::fingerprintUrl('https://owner.example.test/'),
        ]);
        $ownerMonitor->forceFill(['user_id' => $owner->id])->save();
        $otherOwnerMonitor = Monitor::factory()->create([
            'name' => '其他客戶監測項目',
            'url' => 'https://other-owner.example.test/',
            'url_hash' => Monitor::fingerprintUrl('https://other-owner.example.test/'),
        ]);
        $otherOwnerMonitor->forceFill(['user_id' => $otherOwner->id])->save();
        $ownerClient = Client::factory()->for($owner)->create(['name' => '擁有者客戶']);
        Client::factory()->for($otherOwner)->create(['name' => '其他客戶']);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
        ]);

        $this->actingAs($member)
            ->get(route('monitors.index'))
            ->assertOk()
            ->assertSee('擁有者監測項目')
            ->assertDontSee('其他客戶監測項目')
            ->assertSee('唯讀成員')
            ->assertDontSee(route('integrations.google-analytics.edit'), false);

        $this->get(route('clients.index'))
            ->assertOk()
            ->assertSee($ownerClient->name)
            ->assertDontSee('其他客戶');
    }

    public function test_read_only_members_cannot_write_or_manage_integrations_but_can_change_password_and_logout(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
        ]);

        $this->actingAs($member)
            ->post(route('clients.store'), ['name' => '不應建立'])
            ->assertForbidden();

        $this->actingAs($member)
            ->post(route('monitors.store'), [])
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('team.index'))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('integrations.google-analytics.edit'))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('integrations.google-ads.edit'))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('integrations.meta-marketing.edit'))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('status-pages.edit'))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('account.password.edit'))
            ->assertOk()
            ->assertSee('account-password-form');

        $this->put(route('account.password.update'), [
            'current_password' => 'password',
            'password' => 'another-secure-password-123',
            'password_confirmation' => 'another-secure-password-123',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('another-secure-password-123', $member->fresh()->password));

        $this->post(route('logout'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_revoking_a_member_restores_their_own_isolated_workspace(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $membership = WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
        ]);

        $ownerMonitor = Monitor::factory()->create([
            'name' => '原工作區資料',
            'url' => 'https://owner.example.test/',
            'url_hash' => Monitor::fingerprintUrl('https://owner.example.test/'),
        ]);
        $ownerMonitor->forceFill(['user_id' => $owner->id])->save();

        $this->actingAs($owner)
            ->delete(route('team.members.destroy', $membership))
            ->assertRedirect(route('team.index'));

        $this->actingAs($member)
            ->get(route('monitors.index'))
            ->assertOk()
            ->assertDontSee('原工作區資料');
    }

    public function test_invitation_limits_a_new_member_to_selected_clients(): void
    {
        $owner = User::factory()->create(['name' => '品牌擁有者']);
        $selectedClient = $owner->clients()->create(['name' => '指定品牌']);
        $otherClient = $owner->clients()->create(['name' => '其他品牌']);

        $this->actingAs($owner)
            ->post(route('team.invitations.store'), [
                'email' => 'limited.member@example.test',
                'client_scope' => WorkspaceMembership::CLIENT_SCOPE_SELECTED,
                'client_ids' => [$selectedClient->id],
            ])
            ->assertRedirect(route('team.index'))
            ->assertSessionHas('createdInviteUrl');

        $inviteUrl = (string) session('createdInviteUrl');
        $token = basename((string) parse_url($inviteUrl, PHP_URL_PATH));
        $invitation = WorkspaceInvitation::query()->where('email', 'limited.member@example.test')->firstOrFail();

        $this->assertSame(WorkspaceMembership::CLIENT_SCOPE_SELECTED, $invitation->client_scope);
        $this->assertSame([$selectedClient->id], $invitation->client_ids);

        $this->post(route('logout'))
            ->assertRedirect(route('login'));
        $this->get(route('team.invitations.show', ['token' => $token]))
            ->assertOk()
            ->assertSee($selectedClient->name)
            ->assertDontSee($otherClient->name);
        $this->post(route('team.invitations.accept', ['token' => $token]), [
            'name' => '客戶協作者',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
        ])->assertRedirect(route('dashboard'));

        $member = User::query()->where('email', 'limited.member@example.test')->firstOrFail();
        $membership = $member->workspaceMembership;

        $this->assertSame(WorkspaceMembership::CLIENT_SCOPE_SELECTED, $membership?->client_scope);
        $this->assertSame([$selectedClient->id], $member->workspaceClientIds());
        $this->assertDatabaseHas('workspace_membership_clients', [
            'workspace_membership_id' => $membership?->id,
            'client_id' => $selectedClient->id,
        ]);
    }

    public function test_invitation_rejects_clients_from_another_workspace(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $otherClient = $otherOwner->clients()->create(['name' => '不屬於邀請者']);

        $this->actingAs($owner)
            ->from(route('team.index'))
            ->post(route('team.invitations.store'), [
                'email' => 'invalid-scope@example.test',
                'client_scope' => WorkspaceMembership::CLIENT_SCOPE_SELECTED,
                'client_ids' => [$otherClient->id],
            ])
            ->assertRedirect(route('team.index'))
            ->assertSessionHasErrors('client_ids');

        $this->assertDatabaseMissing('workspace_invitations', [
            'owner_user_id' => $owner->id,
            'email' => 'invalid-scope@example.test',
        ]);
    }

    public function test_invitation_requires_at_least_one_client_for_a_selected_scope(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->from(route('team.index'))
            ->post(route('team.invitations.store'), [
                'email' => 'empty-scope@example.test',
                'client_scope' => WorkspaceMembership::CLIENT_SCOPE_SELECTED,
                'client_ids' => [],
            ])
            ->assertRedirect(route('team.index'))
            ->assertSessionHasErrors('client_ids');

        $this->assertDatabaseMissing('workspace_invitations', [
            'owner_user_id' => $owner->id,
            'email' => 'empty-scope@example.test',
        ]);
    }

    public function test_invitation_fails_if_all_selected_clients_were_deleted_before_acceptance(): void
    {
        $owner = User::factory()->create();
        $selectedClient = $owner->clients()->create(['name' => '即將刪除的客戶']);

        $this->actingAs($owner)
            ->post(route('team.invitations.store'), [
                'email' => 'stale-scope@example.test',
                'client_scope' => WorkspaceMembership::CLIENT_SCOPE_SELECTED,
                'client_ids' => [$selectedClient->id],
            ])
            ->assertRedirect(route('team.index'));

        $token = basename((string) parse_url((string) session('createdInviteUrl'), PHP_URL_PATH));
        $selectedClient->delete();
        $this->post(route('logout'));

        $this->from(route('team.invitations.show', ['token' => $token]))
            ->post(route('team.invitations.accept', ['token' => $token]), [
                'name' => '客戶協作者',
                'password' => 'a-secure-password-123',
                'password_confirmation' => 'a-secure-password-123',
            ])->assertRedirect(route('team.invitations.show', ['token' => $token]))
            ->assertSessionHasErrors('client_scope');

        $this->assertDatabaseMissing('users', ['email' => 'stale-scope@example.test']);
    }

    public function test_owner_can_limit_member_access_and_cannot_delete_an_assigned_client(): void
    {
        $owner = User::factory()->create();
        $selectedClient = $owner->clients()->create(['name' => '已指派客戶']);
        $otherClient = $owner->clients()->create(['name' => '未指派客戶']);
        $member = User::factory()->create();
        $membership = WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
        ]);

        $this->actingAs($owner)
            ->get(route('team.members.clients.edit', $membership))
            ->assertOk()
            ->assertSee($member->email)
            ->assertSee($selectedClient->name)
            ->assertSee($otherClient->name);

        $this->patch(route('team.members.clients.update', $membership), [
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_SELECTED,
            'client_ids' => [$selectedClient->id],
        ])->assertRedirect(route('team.index'));

        $this->assertSame(WorkspaceMembership::CLIENT_SCOPE_SELECTED, $membership->fresh()->client_scope);
        $this->assertDatabaseHas('workspace_membership_clients', [
            'workspace_membership_id' => $membership->id,
            'client_id' => $selectedClient->id,
        ]);

        $this->actingAs($member->fresh())
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee($selectedClient->name)
            ->assertDontSee($otherClient->name);

        $this->actingAs($owner)
            ->from(route('clients.index'))
            ->delete(route('clients.destroy', $selectedClient))
            ->assertRedirect(route('clients.index'))
            ->assertSessionHasErrorsIn('clientManagement', ['client' => '此客戶仍指派給工作區成員，請先調整成員的客戶範圍。']);

        $this->assertModelExists($selectedClient->fresh());

        $this->patch(route('team.members.clients.update', $membership), [
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ])->assertRedirect(route('team.index'));

        $this->assertSame(WorkspaceMembership::CLIENT_SCOPE_ALL, $membership->fresh()->client_scope);
        $this->assertDatabaseMissing('workspace_membership_clients', [
            'workspace_membership_id' => $membership->id,
            'client_id' => $selectedClient->id,
        ]);
    }

    public function test_selected_member_only_sees_assigned_monitors_campaigns_and_notifications(): void
    {
        $owner = User::factory()->create();
        $selectedClient = $owner->clients()->create(['name' => '可見客戶']);
        $otherClient = $owner->clients()->create(['name' => '隱藏客戶']);
        $this->actingAs($owner);

        $selectedMonitor = Monitor::factory()->create([
            'name' => '可見監測',
            'url' => 'https://selected.example.test/',
            'url_hash' => Monitor::fingerprintUrl('https://selected.example.test/'),
            'client_id' => $selectedClient->id,
        ]);
        $otherMonitor = Monitor::factory()->create([
            'name' => '隱藏監測',
            'url' => 'https://restricted.example.test/',
            'url_hash' => Monitor::fingerprintUrl('https://restricted.example.test/'),
            'client_id' => $otherClient->id,
        ]);
        $selectedTarget = SocialEngagementTarget::factory()->create([
            'tag' => '可見活動',
            'social_platform' => 'facebook',
            'client_id' => $selectedClient->id,
        ]);
        $otherTarget = SocialEngagementTarget::factory()->create([
            'tag' => '隱藏活動',
            'social_platform' => 'facebook',
            'client_id' => $otherClient->id,
        ]);
        NotificationOutbox::factory()->create([
            'incident_id' => null,
            'monitor_id' => $selectedMonitor->id,
            'subject' => '可見通知',
        ]);
        NotificationOutbox::factory()->create([
            'incident_id' => null,
            'monitor_id' => $otherMonitor->id,
            'subject' => '隱藏通知',
        ]);

        $member = User::factory()->create();
        $membership = WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_EDITOR,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_SELECTED,
        ]);
        $membership->clients()->sync([$selectedClient->id]);

        $selectedCampaignMonitor = Monitor::factory()->create([
            'name' => '可見活動貼文',
            'url' => 'https://www.facebook.com/posts/SELECTEDCAMPAIGN',
            'url_hash' => Monitor::fingerprintUrl('https://www.facebook.com/posts/SELECTEDCAMPAIGN'),
            'label' => '指定活動',
            'client_id' => $selectedClient->id,
        ]);
        $otherCampaignMonitor = Monitor::factory()->create([
            'name' => '隱藏活動貼文',
            'url' => 'https://www.facebook.com/posts/OTHERCAMPAIGN',
            'url_hash' => Monitor::fingerprintUrl('https://www.facebook.com/posts/OTHERCAMPAIGN'),
            'label' => '隱藏活動',
            'client_id' => $otherClient->id,
        ]);

        $this->actingAs($member)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee($selectedClient->name)
            ->assertDontSee($otherClient->name)
            ->assertDontSee('client-create-title');

        $this->get(route('monitors.index'))
            ->assertOk()
            ->assertSee($selectedMonitor->name)
            ->assertDontSee($otherMonitor->name);

        $this->get(route('social-engagement-targets.report', $selectedTarget))
            ->assertOk()
            ->assertSee($selectedTarget->tag);
        $this->get(route('social-engagement-targets.report', $otherTarget))
            ->assertNotFound();

        $this->get(route('notifications.index'))
            ->assertOk()
            ->assertSee($selectedMonitor->name)
            ->assertDontSee($otherMonitor->name);

        $this->post(route('clients.store'), ['name' => '不可新增客戶'])
            ->assertForbidden();

        $this->post(route('monitors.store'), [
            'name' => '指派客戶新監測',
            'url' => 'https://selected-new.example.test/',
            'client_id' => $selectedClient->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('monitors', [
            'user_id' => $owner->id,
            'client_id' => $selectedClient->id,
            'name' => '指派客戶新監測',
        ]);

        $this->post(route('monitors.store'), [
            'name' => '不應建立的監測',
            'url' => 'https://restricted-new.example.test/',
            'client_id' => $otherClient->id,
        ])->assertRedirect()->assertSessionHasErrors('client_id');
        $this->assertDatabaseMissing('monitors', [
            'name' => '不應建立的監測',
        ]);

        $this->post(route('social-engagement-targets.store'), [
            'tag' => '指定活動',
            'social_platform' => 'facebook',
            'client_id' => $selectedClient->id,
            'target_interactions' => 100,
        ])->assertRedirect();
        $this->assertDatabaseHas('social_engagement_targets', [
            'client_id' => $selectedClient->id,
            'tag' => '指定活動',
            'social_platform' => 'facebook',
        ]);

        $this->post(route('social-engagement-targets.store'), [
            'tag' => '不應建立活動',
            'social_platform' => 'facebook',
            'client_id' => $otherClient->id,
            'target_interactions' => 100,
        ])->assertRedirect()->assertSessionHasErrorsIn('socialEngagementTarget', ['client_id']);
        $this->assertDatabaseMissing('social_engagement_targets', [
            'client_id' => $otherClient->id,
            'tag' => '不應建立活動',
        ]);

        $this->assertModelExists($selectedCampaignMonitor);
        $this->assertModelExists($otherCampaignMonitor);
    }

    public function test_selected_membership_with_no_clients_fails_closed(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_SELECTED,
        ]);

        $this->actingAs($member)
            ->get(route('clients.index'))
            ->assertNotFound();

        $this->assertSame([], $member->workspaceClientIds());
        $this->assertSame(0, Client::query()->count());
        $this->assertSame(0, Monitor::query()->count());
        $this->assertSame(0, SocialEngagementTarget::query()->count());
    }

    public function test_manager_password_reset_ignores_read_only_workspace_members(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['is_platform_admin' => true])->save();
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
        ]);

        $this->artisan('monitor:create-manager', ['--reset' => true])
            ->expectsQuestion('密碼（至少 12 個字元）', 'new-manager-password-123')
            ->expectsQuestion('再次輸入密碼', 'new-manager-password-123')
            ->expectsOutput('管理者密碼已重設。')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('new-manager-password-123', $owner->fresh()->password));
        $this->assertTrue(Hash::check('password', $member->fresh()->password));
    }

    public function test_workspace_seat_limit_counts_accounts_and_pending_invitations_and_releases_revoked_seats(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->create(['seat_limit' => 3]);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'accepted_at' => now(),
        ]);
        $owner->workspaceInvitations()->create([
            'email' => 'expired-seat@example.test',
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
            'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($owner)
            ->get(route('team.index'))
            ->assertOk()
            ->assertSee('席次 2 / 3，含待接受邀請。');

        $this->post(route('team.invitations.store'), ['email' => 'pending-seat@example.test'])
            ->assertRedirect(route('team.index'))
            ->assertSessionHas('createdInviteUrl');

        $pendingInvitation = WorkspaceInvitation::query()
            ->where('owner_user_id', $owner->id)
            ->where('email', 'pending-seat@example.test')
            ->whereNull('revoked_at')
            ->firstOrFail();
        $this->assertSame(3, $organization->fresh()->occupiedSeats());

        $this->get(route('team.index'))
            ->assertOk()
            ->assertSee('席次 3 / 3，含待接受邀請。');

        $this->post(route('team.invitations.store'), ['email' => 'another-seat@example.test'])
            ->assertRedirect(route('team.index'))
            ->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('workspace_invitations', [
            'owner_user_id' => $owner->id,
            'email' => 'another-seat@example.test',
        ]);

        $this->post(route('team.invitations.store'), ['email' => 'pending-seat@example.test'])
            ->assertRedirect(route('team.index'))
            ->assertSessionHas('createdInviteUrl');
        $this->assertNotNull($pendingInvitation->fresh()->revoked_at);
        $this->assertSame(3, $organization->fresh()->occupiedSeats());

        $currentInvitation = WorkspaceInvitation::query()
            ->where('owner_user_id', $owner->id)
            ->where('email', 'pending-seat@example.test')
            ->whereNull('revoked_at')
            ->firstOrFail();
        $this->delete(route('team.invitations.destroy', $currentInvitation))
            ->assertRedirect(route('team.index'));
        $this->assertSame(2, $organization->fresh()->occupiedSeats());

        $this->delete(route('team.members.destroy', $member->workspaceMembership))
            ->assertRedirect(route('team.index'));
        $this->assertSame(1, $organization->fresh()->occupiedSeats());
    }

    public function test_accepting_a_reserved_invitation_converts_it_into_a_member_seat(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->create(['seat_limit' => 2]);

        $this->actingAs($owner)
            ->post(route('team.invitations.store'), ['email' => 'seat-member@example.test'])
            ->assertRedirect(route('team.index'));

        $token = basename((string) parse_url((string) session('createdInviteUrl'), PHP_URL_PATH));
        $invitation = WorkspaceInvitation::query()->where('email', 'seat-member@example.test')->firstOrFail();
        $this->assertSame(2, $organization->fresh()->occupiedSeats());

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('team.invitations.accept', ['token' => $token]), [
            'name' => 'Seat Member',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
        ])->assertRedirect(route('dashboard'));

        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame(2, $organization->fresh()->occupiedSeats());

        $this->actingAs($owner)
            ->from(route('team.index'))
            ->post(route('team.invitations.store'), ['email' => 'over-limit-member@example.test'])
            ->assertRedirect(route('team.index'))
            ->assertSessionHasErrors('email');
    }
}
