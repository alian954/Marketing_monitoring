<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultipleWorkspaceMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_account_logs_in_to_join_another_workspace_and_switches_between_them(): void
    {
        $agencyOwner = User::factory()->create(['name' => '代理商擁有者']);
        Organization::factory()->create(['owner_user_id' => $agencyOwner->id, 'name' => '代理商組織']);
        Client::factory()->for($agencyOwner)->create(['name' => '代理商客戶']);
        $consultant = User::factory()->create(['email' => 'consultant@example.test']);
        Organization::factory()->create(['owner_user_id' => $consultant->id, 'name' => '顧問自有組織']);
        Client::factory()->for($consultant)->create(['name' => '顧問自有客戶']);

        $this->actingAs($agencyOwner)
            ->post(route('team.invitations.store'), [
                'email' => 'Consultant@Example.test',
                'role' => WorkspaceMembership::ROLE_EDITOR,
            ])
            ->assertRedirect(route('team.index'))
            ->assertSessionHasNoErrors();
        $token = basename((string) parse_url((string) session('createdInviteUrl'), PHP_URL_PATH));
        $this->post(route('logout'));

        $this->get(route('team.invitations.show', ['token' => $token]))
            ->assertRedirect(route('team.invitations.join.show', ['token' => $token]));
        $this->get(route('team.invitations.join.show', ['token' => $token]))
            ->assertRedirect(route('login'));

        $this->post(route('login.store'), ['email' => $consultant->email, 'password' => 'password'])
            ->assertRedirect(route('team.invitations.join.show', ['token' => $token]));
        $this->get(route('team.invitations.join.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('加入 代理商組織')
            ->assertSee('以目前帳戶加入組織');

        $this->post(route('team.invitations.join', ['token' => $token]))
            ->assertRedirect(route('dashboard'));

        $membership = WorkspaceMembership::query()->where('member_user_id', $consultant->id)->sole();
        $this->assertSame($agencyOwner->id, $membership->owner_user_id);
        $this->assertSame(WorkspaceMembership::ROLE_EDITOR, $membership->role);
        $this->assertNotNull(WorkspaceInvitation::query()->sole()->accepted_at);

        $this->get(route('clients.index'))
            ->assertOk()
            ->assertSee('代理商客戶')
            ->assertDontSee('顧問自有客戶')
            ->assertSee('顧問自有組織 · 擁有者')
            ->assertSee('代理商組織 · 可編輯成員');
        $this->get(route('team.index'))->assertForbidden();

        $this->post(route('workspaces.switch'), ['workspace' => 'own'])
            ->assertRedirect(route('dashboard'));
        $this->get(route('clients.index'))
            ->assertOk()
            ->assertSee('顧問自有客戶')
            ->assertDontSee('代理商客戶');
        $this->get(route('team.index'))->assertOk();

        $this->post(route('workspaces.switch'), ['workspace' => (string) $membership->id])
            ->assertRedirect(route('dashboard'));
        $this->get(route('clients.index'))
            ->assertOk()
            ->assertSee('代理商客戶')
            ->assertDontSee('顧問自有客戶');
    }

    public function test_owner_of_an_organization_defaults_to_their_own_workspace_after_login(): void
    {
        $agencyOwner = User::factory()->create();
        Client::factory()->for($agencyOwner)->create(['name' => '代理商客戶']);
        $consultant = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $consultant->id]);
        Client::factory()->for($consultant)->create(['name' => '顧問自有客戶']);
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $agencyOwner->id,
            'member_user_id' => $consultant->id,
        ]);

        $this->actingAs($consultant)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee('顧問自有客戶')
            ->assertDontSee('代理商客戶');
    }

    public function test_switching_rejects_workspaces_the_user_does_not_belong_to(): void
    {
        $agencyOwner = User::factory()->create();
        $strangerOwner = User::factory()->create();
        Client::factory()->for($strangerOwner)->create(['name' => '陌生組織客戶']);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $agencyOwner->id,
            'member_user_id' => $member->id,
        ]);
        $strangerMembership = WorkspaceMembership::factory()->create(['owner_user_id' => $strangerOwner->id]);
        $revokedMembership = WorkspaceMembership::factory()->create([
            'owner_user_id' => $strangerOwner->id,
            'member_user_id' => User::factory()->create()->id,
            'revoked_at' => now(),
        ]);

        $this->actingAs($member)
            ->post(route('workspaces.switch'), ['workspace' => (string) $strangerMembership->id])
            ->assertNotFound();
        $this->post(route('workspaces.switch'), ['workspace' => (string) $revokedMembership->id])
            ->assertNotFound();
        $this->post(route('workspaces.switch'), ['workspace' => 'own'])
            ->assertNotFound();

        $this->withSession([User::CURRENT_WORKSPACE_SESSION_KEY => $strangerOwner->id])
            ->get(route('clients.index'))
            ->assertOk()
            ->assertDontSee('陌生組織客戶');
        $this->assertSame($agencyOwner->id, $member->fresh()->workspaceOwnerId());
    }

    public function test_read_only_member_can_switch_and_join_without_write_access(): void
    {
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();
        Client::factory()->for($secondOwner)->create(['name' => '第二組織客戶']);
        $member = User::factory()->create(['email' => 'viewer@example.test']);
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $firstOwner->id,
            'member_user_id' => $member->id,
        ]);
        $token = str_repeat('b', 64);
        WorkspaceInvitation::factory()->create([
            'owner_user_id' => $secondOwner->id,
            'email' => 'viewer@example.test',
            'token_hash' => hash('sha256', $token),
        ]);

        $this->actingAs($member)
            ->post(route('team.invitations.join', ['token' => $token]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->get(route('clients.index'))
            ->assertOk()
            ->assertSee('第二組織客戶');
        $this->post(route('clients.store'), ['name' => '不應建立'])->assertForbidden();

        $firstMembership = WorkspaceMembership::query()
            ->where('owner_user_id', $firstOwner->id)
            ->where('member_user_id', $member->id)
            ->sole();
        $this->post(route('workspaces.switch'), ['workspace' => (string) $firstMembership->id])
            ->assertRedirect(route('dashboard'));
        $this->get(route('clients.index'))
            ->assertOk()
            ->assertDontSee('第二組織客戶');
    }

    public function test_invitation_can_only_be_joined_by_the_invited_account(): void
    {
        $owner = User::factory()->create();
        User::factory()->create(['email' => 'invitee@example.test']);
        $otherUser = User::factory()->create(['email' => 'someone.else@example.test']);
        $token = str_repeat('c', 64);
        WorkspaceInvitation::factory()->create([
            'owner_user_id' => $owner->id,
            'email' => 'invitee@example.test',
            'token_hash' => hash('sha256', $token),
        ]);

        $this->actingAs($otherUser)
            ->get(route('team.invitations.join.show', ['token' => $token]))
            ->assertForbidden();
        $this->post(route('team.invitations.join', ['token' => $token]))
            ->assertForbidden();

        $this->assertDatabaseCount('workspace_memberships', 0);
        $this->assertNull(WorkspaceInvitation::query()->sole()->accepted_at);
    }

    public function test_invitations_reject_active_members_and_platform_admins(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'member@example.test']);
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
        ]);
        $platformAdmin = User::factory()->create(['email' => 'admin@example.test']);
        $platformAdmin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($owner)
            ->post(route('team.invitations.store'), ['email' => 'member@example.test'])
            ->assertSessionHasErrors(['email' => '此帳戶已是組織成員。']);
        $this->post(route('team.invitations.store'), ['email' => 'admin@example.test'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('workspace_invitations', 0);
    }

    public function test_revoked_member_can_be_invited_again_and_reuses_the_membership_record(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create(['name' => '指定客戶']);
        $member = User::factory()->create(['email' => 'returning@example.test']);
        $revokedMembership = WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_EDITOR,
            'revoked_at' => now()->subDay(),
        ]);
        $token = str_repeat('d', 64);
        WorkspaceInvitation::factory()->create([
            'owner_user_id' => $owner->id,
            'email' => 'returning@example.test',
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_SELECTED,
            'client_ids' => [$client->id],
            'token_hash' => hash('sha256', $token),
        ]);

        $this->actingAs($member)
            ->post(route('team.invitations.join', ['token' => $token]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $membership = WorkspaceMembership::query()->where('member_user_id', $member->id)->sole();
        $this->assertTrue($membership->is($revokedMembership));
        $this->assertNull($membership->revoked_at);
        $this->assertSame(WorkspaceMembership::ROLE_READ_ONLY, $membership->role);
        $this->assertSame(WorkspaceMembership::CLIENT_SCOPE_SELECTED, $membership->client_scope);
        $this->assertSame([$client->id], $membership->clients()->pluck('clients.id')->all());
    }

    public function test_suspended_selected_workspace_falls_back_to_an_accessible_one(): void
    {
        $activeOwner = User::factory()->create();
        Client::factory()->for($activeOwner)->create(['name' => '可用組織客戶']);
        $suspendedOwner = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $suspendedOwner->id, 'is_enabled' => false]);
        Client::factory()->for($suspendedOwner)->create(['name' => '停用組織客戶']);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $suspendedOwner->id,
            'member_user_id' => $member->id,
            'accepted_at' => now()->subDays(2),
        ]);
        $activeMembership = WorkspaceMembership::factory()->create([
            'owner_user_id' => $activeOwner->id,
            'member_user_id' => $member->id,
            'accepted_at' => now()->subDay(),
        ]);

        $this->actingAs($member)
            ->withSession([User::CURRENT_WORKSPACE_SESSION_KEY => $suspendedOwner->id])
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee('可用組織客戶')
            ->assertDontSee('停用組織客戶');
        $this->assertAuthenticatedAs($member);
        $this->assertSame($activeOwner->id, $member->workspaceOwnerId());
        $this->assertSame($activeMembership->id, $member->workspaceMembership?->id);
    }
}
