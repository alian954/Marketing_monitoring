<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\BillingOrder;
use App\Models\CheckRun;
use App\Models\Client;
use App\Models\ClientLead;
use App\Models\GoogleAdsConnection;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\PlatformAuditAction;
use App\Models\PlatformAuditEvent;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrganizationDataPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_deletes_workspace_data_but_keeps_billing_audit_and_other_organizations(): void
    {
        ['organization' => $organization, 'owner' => $owner, 'member' => $member] = $this->organizationWithData('退租客戶', isEnabled: false);
        ['organization' => $otherOrganization, 'owner' => $otherOwner] = $this->organizationWithData('其他客戶', isEnabled: true);
        $order = BillingOrder::factory()->create(['organization_id' => $organization->id]);
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform')
            ->get(route('platform.organizations.show', $organization))
            ->assertOk()
            ->assertSee('退租資料清除')
            ->assertSee('永久清除組織資料');

        $this->delete(route('platform.organizations.data.purge', $organization), [
            'confirmation_name' => '退租客戶',
            'current_password' => 'admin-password-123',
        ])->assertRedirect(route('platform.organizations.show', $organization))
            ->assertSessionHas('status');

        foreach (['clients', 'monitors', 'social_engagement_targets', 'google_ads_connections', 'workspace_memberships', 'workspace_invitations', 'api_tokens'] as $table) {
            $this->assertSame(0, DB::table($table)->where($table === 'api_tokens' ? 'workspace_owner_user_id' : ($table === 'workspace_memberships' || $table === 'workspace_invitations' ? 'owner_user_id' : 'user_id'), $owner->id)->count(), $table);
        }
        $this->assertSame(1, DB::table('client_leads')->count(), 'child rows cascade with their client');
        $this->assertSame(1, DB::table('check_runs')->count());
        $this->assertSame(1, DB::table('incidents')->count());
        $this->assertSame(1, DB::table('clients')->where('user_id', $otherOwner->id)->count());
        $this->assertSame(1, DB::table('workspace_memberships')->where('owner_user_id', $otherOwner->id)->count());

        $organization->refresh();
        $this->assertNotNull($organization->data_purged_at);
        $this->assertFalse($organization->is_enabled);
        $this->assertTrue($otherOrganization->fresh()->is_enabled);
        $this->assertNotNull($order->fresh());
        $this->assertNotNull(User::query()->find($member->id));
        $audit = PlatformAuditEvent::query()->where('action', PlatformAuditAction::OrganizationDataPurged)->sole();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame(1, $audit->metadata['deleted_counts']['客戶（含名單、訂單、報表、警示與廣告帳戶對應）']);

        $this->get(route('platform.organizations.show', $organization))
            ->assertOk()
            ->assertSee('資料已於')
            ->assertDontSee('永久清除組織資料');
        $this->delete(route('platform.organizations.data.purge', $organization), [
            'confirmation_name' => '退租客戶',
            'current_password' => 'admin-password-123',
        ])->assertSessionHasErrors('confirmation_name');
    }

    public function test_purge_requires_a_disabled_organization_its_exact_name_and_the_admin_password(): void
    {
        ['organization' => $activeOrganization] = $this->organizationWithData('使用中客戶', isEnabled: true);
        ['organization' => $disabledOrganization] = $this->organizationWithData('停用客戶', isEnabled: false);
        $this->actingAs($this->platformAdmin(), 'platform');

        $this->get(route('platform.organizations.show', $activeOrganization))
            ->assertOk()
            ->assertSee('只能清除已停用或訂閱已到期的組織')
            ->assertDontSee('永久清除組織資料');
        $this->delete(route('platform.organizations.data.purge', $activeOrganization), [
            'confirmation_name' => '使用中客戶',
            'current_password' => 'admin-password-123',
        ])->assertSessionHasErrors(['confirmation_name' => '只能清除已停用或訂閱已到期的組織，請先停用此組織。']);

        $this->delete(route('platform.organizations.data.purge', $disabledOrganization), [
            'confirmation_name' => '停用',
            'current_password' => 'admin-password-123',
        ])->assertSessionHasErrors('confirmation_name');
        $this->delete(route('platform.organizations.data.purge', $disabledOrganization), [
            'confirmation_name' => '停用客戶',
            'current_password' => 'wrong-password',
        ])->assertSessionHasErrors('current_password');

        $this->assertNull($disabledOrganization->fresh()->data_purged_at);
        $this->assertSame(2, DB::table('clients')->count());
    }

    public function test_customers_cannot_purge_organizations(): void
    {
        ['organization' => $organization, 'owner' => $owner] = $this->organizationWithData('停用客戶', isEnabled: false);

        $this->actingAs($owner)
            ->delete(route('platform.organizations.data.purge', $organization), ['confirmation_name' => '停用客戶'])
            ->assertRedirect(route('platform.login'));

        $this->assertNull($organization->fresh()->data_purged_at);
    }

    /**
     * @return array{organization: Organization, owner: User, member: User}
     */
    private function organizationWithData(string $name, bool $isEnabled): array
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id, 'name' => $name, 'is_enabled' => $isEnabled]);
        $client = Client::factory()->for($owner)->create();
        ClientLead::factory()->for($client)->create();
        $monitor = Monitor::factory()->create(['client_id' => $client->id]);
        $monitor->forceFill(['user_id' => $owner->id])->save();
        CheckRun::factory()->for($monitor)->create();
        Incident::factory()->create(['monitor_id' => $monitor->id]);
        SocialEngagementTarget::factory()->create(['client_id' => $client->id])->forceFill(['user_id' => $owner->id])->save();
        GoogleAdsConnection::factory()->create()->forceFill(['user_id' => $owner->id])->save();
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create(['owner_user_id' => $owner->id, 'member_user_id' => $member->id]);
        WorkspaceInvitation::factory()->create(['owner_user_id' => $owner->id]);
        ApiToken::issue($owner, '報表串接', null);

        return compact('organization', 'owner', 'member');
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create(['password' => Hash::make('admin-password-123')]);
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }
}
