<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformAuditAction;
use App\Models\PlatformAuditEvent;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Services\PlatformAuditLogger;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class PlatformAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_organization_records_its_settings_without_the_initial_password(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post(route('platform.organizations.store'), [
                'name' => '稽核代理商',
                'owner_name' => '負責人',
                'owner_email' => 'audit-owner@example.test',
                'owner_password' => 'initial-password-123',
                'owner_password_confirmation' => 'initial-password-123',
                'seat_limit' => 3,
                'plan_name' => '試用方案',
            ])
            ->assertSessionHasNoErrors();

        $organization = Organization::query()->where('name', '稽核代理商')->sole();
        $event = PlatformAuditEvent::query()->sole();

        $this->assertSame(PlatformAuditAction::OrganizationCreated, $event->action);
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame($organization->workspace->id, $event->workspace_id);
        $this->assertSame('organization', $event->subject_type);
        $this->assertSame($organization->id, $event->subject_id);
        $this->assertSame('試用方案', $event->metadata['plan_name']);
        $this->assertSame(3, $event->metadata['seat_limit']);
        $this->assertStringNotContainsString('initial-password-123', (string) json_encode($event->metadata));
        $this->assertNotNull($event->ip_address);
    }

    public function test_updating_an_organization_records_only_changed_fields(): void
    {
        $organization = Organization::factory()->create(['name' => '原名稱', 'seat_limit' => 5, 'plan_name' => '標準']);
        $workspace = Workspace::factory()->create(['organization_id' => $organization->id]);
        $payload = [
            'name' => '原名稱',
            'seat_limit' => 8,
            'plan_name' => '標準',
            'is_enabled' => '0',
        ];

        $this->actingAs($this->platformAdmin())
            ->patch(route('platform.organizations.update', $organization), $payload)
            ->assertSessionHasNoErrors();

        $event = PlatformAuditEvent::query()->sole();
        $this->assertSame(PlatformAuditAction::OrganizationUpdated, $event->action);
        $this->assertSame($workspace->id, $event->workspace_id);
        $this->assertSame([
            'seat_limit' => ['before' => 5, 'after' => 8],
            'is_enabled' => ['before' => true, 'after' => false],
        ], $event->metadata['changes']);

        $this->patch(route('platform.organizations.update', $organization), $payload)
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PlatformAuditEvent::query()->count());
    }

    public function test_platform_admin_entering_an_organization_is_recorded(): void
    {
        $organization = Organization::factory()->create();
        $workspace = Workspace::factory()->create(['organization_id' => $organization->id]);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post(route('platform.organizations.open', $organization))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('platform_audit_events', [
            'action' => PlatformAuditAction::OrganizationOpenedByPlatformAdmin->value,
            'actor_user_id' => $admin->id,
            'workspace_id' => $workspace->id,
            'subject_id' => $organization->id,
        ]);
    }

    public function test_product_lifecycle_change_is_recorded_with_before_and_after_values(): void
    {
        $this->seed(ProductCatalogSeeder::class);
        $product = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();

        $this->actingAs($this->platformAdmin())
            ->patch(route('platform.products.lifecycle.update', $product), ['lifecycle' => 'maintenance'])
            ->assertRedirect(route('platform.products.index'));

        $event = PlatformAuditEvent::query()->sole();
        $this->assertSame(PlatformAuditAction::ProductLifecycleUpdated, $event->action);
        $this->assertNull($event->workspace_id);
        $this->assertSame('website-monitoring', $event->metadata['product_code']);
        $this->assertSame(['lifecycle' => ['before' => 'active', 'after' => 'maintenance']], $event->metadata['changes']);
    }

    public function test_member_management_and_invitation_acceptance_are_recorded_in_the_owner_workspace(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $workspace = Workspace::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner)
            ->post(route('team.invitations.store'), ['email' => 'new.member@example.test'])
            ->assertSessionHasNoErrors();
        $token = basename((string) parse_url((string) session('createdInviteUrl'), PHP_URL_PATH));
        $this->post(route('logout'));

        $this->post(route('team.invitations.accept', ['token' => $token]), [
            'name' => '新成員',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
        ])->assertRedirect(route('dashboard'));
        $member = User::query()->where('email', 'new.member@example.test')->sole();
        $membership = WorkspaceMembership::query()->where('member_user_id', $member->id)->sole();
        $this->post(route('logout'));

        $this->actingAs($owner)
            ->patch(route('team.members.role.update', $membership), ['role' => WorkspaceMembership::ROLE_EDITOR])
            ->assertRedirect(route('team.index'));
        $this->delete(route('team.members.destroy', $membership))
            ->assertRedirect(route('team.index'));

        $events = PlatformAuditEvent::query()->orderBy('id')->get();
        $this->assertSame([
            PlatformAuditAction::MemberInvitationCreated,
            PlatformAuditAction::MemberInvitationAccepted,
            PlatformAuditAction::MemberRoleUpdated,
            PlatformAuditAction::MemberRevoked,
        ], $events->pluck('action')->all());
        $this->assertSame([$workspace->id], $events->pluck('workspace_id')->unique()->values()->all());
        $this->assertSame($member->id, $events[1]->actor_user_id);
        $this->assertTrue($events[1]->metadata['created_account']);
        $this->assertSame(['role' => ['before' => 'read_only', 'after' => 'editor']], $events[2]->metadata['changes']);
        $this->assertSame($owner->id, $events[3]->actor_user_id);
        $this->assertStringNotContainsString($token, (string) json_encode($events->pluck('metadata')));
        $this->assertStringNotContainsString(
            (string) WorkspaceInvitation::query()->sole()->token_hash,
            (string) json_encode($events->pluck('metadata')),
        );
    }

    public function test_audit_logger_drops_sensitive_keys_and_keeps_regular_fields(): void
    {
        $event = app(PlatformAuditLogger::class)->record(PlatformAuditAction::ProductPlanCreated, null, [
            'feature_key' => 'monitoring.targets',
            'password' => 'plain',
            'client_secret' => 'secret-value',
            'apiKey' => 'key-value',
            'nested' => ['access_token' => 'token-value', 'plan_code' => 'pro'],
        ]);

        $this->assertSame([
            'feature_key' => 'monitoring.targets',
            'nested' => ['plan_code' => 'pro'],
        ], $event->metadata);
    }

    public function test_audit_events_are_append_only(): void
    {
        $event = PlatformAuditEvent::factory()->create();

        try {
            $event->update(['action' => PlatformAuditAction::MemberRevoked]);
            $this->fail('Audit events must not be updated.');
        } catch (LogicException) {
        }

        try {
            $event->delete();
            $this->fail('Audit events must not be deleted.');
        } catch (LogicException) {
        }

        $this->assertSame(PlatformAuditAction::OrganizationUpdated, $event->fresh()->action);
    }

    public function test_only_platform_admins_can_view_and_filter_the_audit_trail(): void
    {
        $organization = Organization::factory()->create(['name' => '目標組織']);
        $workspace = Workspace::factory()->create(['organization_id' => $organization->id]);
        $otherWorkspace = Workspace::factory()->create();
        PlatformAuditEvent::factory()->create([
            'workspace_id' => $workspace->id,
            'action' => PlatformAuditAction::MemberRoleUpdated,
            'metadata' => ['changes' => ['role' => ['before' => 'read_only', 'after' => 'editor']]],
        ]);
        PlatformAuditEvent::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'action' => PlatformAuditAction::MemberRevoked,
            'metadata' => ['role' => 'other-workspace-marker'],
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('platform.audit-events.index'))
            ->assertForbidden();

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.audit-events.index'))
            ->assertOk()
            ->assertSee('變更成員權限')
            ->assertSee('撤銷成員存取');

        $this->get(route('platform.audit-events.index', ['organization_id' => $organization->id]))
            ->assertOk()
            ->assertSee('權限：唯讀 → 可編輯')
            ->assertDontSee('other-workspace-marker');

        $this->get(route('platform.audit-events.index', ['action' => PlatformAuditAction::MemberRevoked->value]))
            ->assertOk()
            ->assertSee('other-workspace-marker')
            ->assertDontSee('唯讀 → 可編輯');

        $this->get(route('platform.audit-events.index', ['action' => 'unknown.action']))
            ->assertSessionHasErrors('action');
    }

    public function test_workspace_owner_sees_only_their_workspace_activity_with_platform_staff_masked(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $workspace = Workspace::factory()->create(['organization_id' => $organization->id]);
        $admin = $this->platformAdmin();
        $admin->forceFill(['email' => 'staff-only@example.test'])->save();
        PlatformAuditEvent::factory()->create([
            'workspace_id' => $workspace->id,
            'actor_user_id' => $admin->id,
            'action' => PlatformAuditAction::OrganizationOpenedByPlatformAdmin,
            'metadata' => [],
        ]);
        PlatformAuditEvent::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
            'action' => PlatformAuditAction::MemberRevoked,
            'metadata' => ['role' => 'other-workspace-marker'],
        ]);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create(['owner_user_id' => $owner->id, 'member_user_id' => $member->id]);
        PlatformAuditEvent::factory()->create([
            'workspace_id' => $workspace->id,
            'actor_user_id' => $owner->id,
            'action' => PlatformAuditAction::MemberRoleUpdated,
            'metadata' => ['member_user_id' => $member->id],
        ]);

        $this->actingAs($owner)
            ->get(route('team.activity.index'))
            ->assertOk()
            ->assertSee('成員：'.$member->name)
            ->assertSee('平台管理員進入組織')
            ->assertSee('平台管理員')
            ->assertDontSee('staff-only@example.test')
            ->assertDontSee($admin->name)
            ->assertDontSee('other-workspace-marker');

        $this->actingAs($member)
            ->get(route('team.activity.index'))
            ->assertForbidden();
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }
}
