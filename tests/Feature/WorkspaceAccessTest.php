<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Monitor;
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
            ->assertSee('工作區成員')
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
            ->assertSee('加入 工作區擁有者 的工作區')
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
            ->assertSee('工作區管理與整合設定仍由擁有者負責');
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
            ->assertSee('請選擇有效的工作區權限。');

        $this->assertDatabaseMissing('workspace_invitations', [
            'owner_user_id' => $owner->id,
            'email' => 'invalid-role@example.test',
        ]);
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

    public function test_owner_cannot_invite_an_existing_account_or_revoke_another_workspaces_records(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $existingUser = User::factory()->create(['email' => 'already.registered@example.test']);
        $otherInvitation = WorkspaceInvitation::factory()->create(['owner_user_id' => $otherOwner->id]);
        $otherMembership = WorkspaceMembership::factory()->create(['owner_user_id' => $otherOwner->id]);

        $this->actingAs($owner)
            ->post(route('team.invitations.store'), ['email' => $existingUser->email])
            ->assertSessionHasErrors('email');

        $this->delete(route('team.invitations.destroy', $otherInvitation))
            ->assertNotFound();
        $this->delete(route('team.members.destroy', $otherMembership))
            ->assertNotFound();
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

    public function test_manager_password_reset_ignores_read_only_workspace_members(): void
    {
        $owner = User::factory()->create();
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
}
