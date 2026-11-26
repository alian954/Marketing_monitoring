<?php

namespace Tests\Feature;

use App\Models\Organization;
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
}
