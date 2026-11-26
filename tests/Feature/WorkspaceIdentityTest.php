<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\Organization;
use App\Models\SocialEngagementTarget;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_identity_is_shared_by_its_owner_and_active_members(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->create();
        $workspace = Workspace::factory()->for($organization)->create();
        $member = User::factory()->create();

        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
        ]);

        $this->assertSame($workspace->id, $owner->fresh()->currentWorkspace()?->id);
        $this->assertSame($workspace->id, $member->fresh()->currentWorkspace()?->id);
        $this->assertArrayNotHasKey('organization_id', $workspace->toArray());
        $this->assertArrayHasKey('public_id', $workspace->toArray());
    }

    public function test_platform_admin_resolves_the_workspace_selected_for_support(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->for($owner, 'owner')->create();
        $workspace = Workspace::factory()->for($organization)->create();
        $platformAdmin = User::factory()->create();
        $platformAdmin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($platformAdmin)
            ->withSession(['platform_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame($workspace->id, $platformAdmin->fresh()->currentWorkspace()?->id);
    }

    public function test_workspace_seeder_repairs_missing_mappings_without_replacing_existing_ones(): void
    {
        $mappedOrganization = Organization::factory()->create();
        $missingWorkspaceOrganization = Organization::factory()->create();
        $existingWorkspace = Workspace::factory()->for($mappedOrganization)->create();

        (new WorkspaceSeeder)->run();

        $this->assertSame($existingWorkspace->id, $mappedOrganization->fresh()->workspace?->id);
        $repairedWorkspace = $missingWorkspaceOrganization->fresh()->workspace;
        $this->assertNotNull($repairedWorkspace);
        $this->assertSame($missingWorkspaceOrganization->id, $repairedWorkspace->organization_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $repairedWorkspace->public_id);

        (new WorkspaceSeeder)->run();

        $this->assertDatabaseCount('workspaces', 2);
    }

    public function test_client_directory_is_scoped_by_workspace_and_hides_other_workspace_records(): void
    {
        $firstOwner = User::factory()->create();
        $firstOrganization = Organization::factory()->for($firstOwner, 'owner')->create();
        $firstWorkspace = Workspace::factory()->for($firstOrganization)->create();
        $secondOwner = User::factory()->create();
        $secondOrganization = Organization::factory()->for($secondOwner, 'owner')->create();
        $secondWorkspace = Workspace::factory()->for($secondOrganization)->create();

        $this->actingAs($firstOwner);
        $firstClient = Client::query()->create(['name' => '第一工作區客戶']);
        $this->actingAs($secondOwner);
        $secondClient = Client::query()->create(['name' => '第二工作區客戶']);

        $this->assertSame($firstWorkspace->id, $firstClient->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondClient->workspace_id);

        $this->actingAs($firstOwner)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee('第一工作區客戶')
            ->assertDontSee('第二工作區客戶');

        $this->delete(route('clients.destroy', $secondClient))->assertNotFound();
        $this->assertModelExists($secondClient->fresh());
    }

    public function test_monitoring_roots_are_mapped_to_and_scoped_by_their_workspace(): void
    {
        $firstOwner = User::factory()->create();
        $firstOrganization = Organization::factory()->for($firstOwner, 'owner')->create();
        $firstWorkspace = Workspace::factory()->for($firstOrganization)->create();
        $secondOwner = User::factory()->create();
        $secondOrganization = Organization::factory()->for($secondOwner, 'owner')->create();
        $secondWorkspace = Workspace::factory()->for($secondOrganization)->create();

        $this->actingAs($firstOwner);
        $firstMonitor = Monitor::factory()->create(['name' => 'first workspace monitor']);
        $firstTarget = SocialEngagementTarget::factory()->create([
            'tag' => 'first workspace campaign',
            'social_platform' => 'threads',
        ]);
        $firstStatusPage = StatusPage::factory()->create();
        $firstOutbox = NotificationOutbox::factory()->create([
            'incident_id' => null,
            'monitor_id' => null,
            'status_page_subscription_id' => null,
        ]);

        $this->actingAs($secondOwner);
        $secondMonitor = Monitor::factory()->create(['name' => 'second workspace monitor']);
        $secondTarget = SocialEngagementTarget::factory()->create([
            'tag' => 'second workspace campaign',
            'social_platform' => 'threads',
        ]);
        $secondStatusPage = StatusPage::factory()->create();
        $secondOutbox = NotificationOutbox::factory()->create([
            'incident_id' => null,
            'monitor_id' => null,
            'status_page_subscription_id' => null,
        ]);

        $this->assertSame($firstWorkspace->id, $firstMonitor->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstTarget->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstStatusPage->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstOutbox->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondMonitor->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondTarget->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondStatusPage->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondOutbox->workspace_id);

        $this->actingAs($firstOwner);
        $this->assertSame([$firstMonitor->id], Monitor::query()->pluck('id')->all());
        $this->assertSame([$firstTarget->id], SocialEngagementTarget::query()->pluck('id')->all());
        $this->assertSame([$firstStatusPage->id], StatusPage::query()->pluck('id')->all());
        $this->assertSame([$firstOutbox->id], NotificationOutbox::query()->pluck('id')->all());
    }
}
