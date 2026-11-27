<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Client;
use App\Models\DnsChangeEvent;
use App\Models\DomainRegistrationObservation;
use App\Models\GoogleAdsClientAccount;
use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsClientProperty;
use App\Models\GoogleAnalyticsConnection;
use App\Models\GoogleSearchConsoleClientSite;
use App\Models\GoogleSearchConsoleConnection;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\MaintenanceWindow;
use App\Models\MetaMarketingClientAccount;
use App\Models\MetaMarketingConnection;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\Organization;
use App\Models\RobotsObservation;
use App\Models\SocialEngagementTarget;
use App\Models\StatusPage;
use App\Models\TlsObservation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
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

    public function test_platform_admin_who_owns_an_organization_uses_its_workspace_until_another_is_opened(): void
    {
        $platformAdmin = User::factory()->create();
        $platformAdmin->forceFill(['is_platform_admin' => true])->save();
        $ownOrganization = Organization::factory()->for($platformAdmin, 'owner')->create();
        $ownWorkspace = Workspace::factory()->for($ownOrganization)->create();
        $ownClient = Client::withoutGlobalScopes()->forceCreate([
            'user_id' => $platformAdmin->id,
            'workspace_id' => $ownWorkspace->id,
            'name' => Client::DEFAULT_NAME,
        ]);
        $otherOwner = User::factory()->create();
        $otherOrganization = Organization::factory()->for($otherOwner, 'owner')->create();
        Workspace::factory()->for($otherOrganization)->create();
        Client::withoutGlobalScopes()->forceCreate([
            'user_id' => $otherOwner->id,
            'workspace_id' => Workspace::idForOwnerUserId($otherOwner->id),
            'name' => '其他代理商客戶',
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('dashboard'))
            ->assertOk();
        $this->get(route('clients.index'))
            ->assertOk()
            ->assertSee($ownClient->name)
            ->assertDontSee('其他代理商客戶');

        $this->assertSame(1, Client::withoutGlobalScopes()->where('user_id', $platformAdmin->id)->count());
        $this->assertSame($ownWorkspace->id, $platformAdmin->fresh()->currentWorkspace()?->id);

        $this->actingAs($platformAdmin->fresh())
            ->withSession(['platform_organization_id' => $otherOrganization->id])
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee('其他代理商客戶');
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

    public function test_monitoring_roots_history_and_integrations_are_scoped_by_their_workspace(): void
    {
        $firstOwner = User::factory()->create();
        $firstOrganization = Organization::factory()->for($firstOwner, 'owner')->create();
        $firstWorkspace = Workspace::factory()->for($firstOrganization)->create();
        $secondOwner = User::factory()->create();
        $secondOrganization = Organization::factory()->for($secondOwner, 'owner')->create();
        $secondWorkspace = Workspace::factory()->for($secondOrganization)->create();

        $this->actingAs($firstOwner);
        $firstMonitor = Monitor::factory()->create(['name' => 'first workspace monitor']);
        $firstCheckRun = CheckRun::factory()->for($firstMonitor)->create();
        $firstIncident = Incident::factory()->for($firstMonitor)->create();
        $firstIncidentEvent = IncidentEvent::factory()
            ->for($firstIncident)
            ->for($firstCheckRun, 'checkRun')
            ->create();
        $firstMaintenanceWindow = MaintenanceWindow::factory()->for($firstMonitor)->create();
        $firstTlsObservation = TlsObservation::factory()->for($firstMonitor)->create();
        $firstDnsChangeEvent = DnsChangeEvent::factory()->forCheckRun($firstCheckRun)->create();
        $firstRobotsObservation = RobotsObservation::factory()->for($firstMonitor)->create();
        $firstDomainRegistrationObservation = DomainRegistrationObservation::factory()->for($firstMonitor)->create();
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
        $firstGoogleAnalyticsConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $firstOwner->id,
            'refresh_token' => 'first-ga-token',
        ]);
        $firstGoogleSearchConsoleConnection = GoogleSearchConsoleConnection::factory()->for($firstOwner)->create();
        $firstMetaMarketingConnection = MetaMarketingConnection::factory()->create(['user_id' => $firstOwner->id]);
        $firstGoogleAdsConnection = GoogleAdsConnection::factory()->create(['user_id' => $firstOwner->id]);
        $firstClient = Client::query()->create(['name' => '第一工作區整合客戶']);
        $firstGoogleAnalyticsProperty = $firstGoogleAnalyticsConnection->clientProperties()->create([
            'client_id' => $firstClient->id,
            'property_id' => '10001',
        ]);
        $firstGoogleSearchConsoleSite = $firstGoogleSearchConsoleConnection->clientSites()->create([
            'client_id' => $firstClient->id,
            'site_url' => 'https://first.example.com/',
        ]);
        $firstMetaMarketingAccount = $firstMetaMarketingConnection->clientAccounts()->create([
            'client_id' => $firstClient->id,
            'ad_account_id' => 'act_10001',
            'ad_account_name' => '第一工作區廣告帳戶',
            'currency_code' => 'TWD',
        ]);
        $firstGoogleAdsAccount = $firstGoogleAdsConnection->clientAccounts()->create([
            'client_id' => $firstClient->id,
            'customer_id' => '10001',
            'customer_name' => '第一工作區 Google Ads 帳戶',
            'currency_code' => 'TWD',
        ]);

        $this->actingAs($secondOwner);
        $secondMonitor = Monitor::factory()->create(['name' => 'second workspace monitor']);
        $secondCheckRun = CheckRun::factory()->for($secondMonitor)->create();
        $secondIncident = Incident::factory()->for($secondMonitor)->create();
        $secondIncidentEvent = IncidentEvent::factory()
            ->for($secondIncident)
            ->for($secondCheckRun, 'checkRun')
            ->create();
        $secondMaintenanceWindow = MaintenanceWindow::factory()->for($secondMonitor)->create();
        $secondTlsObservation = TlsObservation::factory()->for($secondMonitor)->create();
        $secondDnsChangeEvent = DnsChangeEvent::factory()->forCheckRun($secondCheckRun)->create();
        $secondRobotsObservation = RobotsObservation::factory()->for($secondMonitor)->create();
        $secondDomainRegistrationObservation = DomainRegistrationObservation::factory()->for($secondMonitor)->create();
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
        $secondGoogleAnalyticsConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $secondOwner->id,
            'refresh_token' => 'second-ga-token',
        ]);
        $secondGoogleSearchConsoleConnection = GoogleSearchConsoleConnection::factory()->for($secondOwner)->create();
        $secondMetaMarketingConnection = MetaMarketingConnection::factory()->create(['user_id' => $secondOwner->id]);
        $secondGoogleAdsConnection = GoogleAdsConnection::factory()->create(['user_id' => $secondOwner->id]);
        $secondClient = Client::query()->create(['name' => '第二工作區整合客戶']);
        $secondGoogleAnalyticsProperty = $secondGoogleAnalyticsConnection->clientProperties()->create([
            'client_id' => $secondClient->id,
            'property_id' => '20001',
        ]);
        $secondGoogleSearchConsoleSite = $secondGoogleSearchConsoleConnection->clientSites()->create([
            'client_id' => $secondClient->id,
            'site_url' => 'https://second.example.com/',
        ]);
        $secondMetaMarketingAccount = $secondMetaMarketingConnection->clientAccounts()->create([
            'client_id' => $secondClient->id,
            'ad_account_id' => 'act_20001',
            'ad_account_name' => '第二工作區廣告帳戶',
            'currency_code' => 'TWD',
        ]);
        $secondGoogleAdsAccount = $secondGoogleAdsConnection->clientAccounts()->create([
            'client_id' => $secondClient->id,
            'customer_id' => '20001',
            'customer_name' => '第二工作區 Google Ads 帳戶',
            'currency_code' => 'TWD',
        ]);

        $this->assertSame($firstWorkspace->id, $firstMonitor->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstTarget->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstStatusPage->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstOutbox->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstGoogleAnalyticsConnection->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstGoogleSearchConsoleConnection->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstMetaMarketingConnection->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstGoogleAdsConnection->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondMonitor->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondTarget->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondStatusPage->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondOutbox->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondGoogleAnalyticsConnection->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondGoogleSearchConsoleConnection->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondMetaMarketingConnection->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondGoogleAdsConnection->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstGoogleAnalyticsProperty->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstGoogleSearchConsoleSite->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstMetaMarketingAccount->workspace_id);
        $this->assertSame($firstWorkspace->id, $firstGoogleAdsAccount->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondGoogleAnalyticsProperty->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondGoogleSearchConsoleSite->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondMetaMarketingAccount->workspace_id);
        $this->assertSame($secondWorkspace->id, $secondGoogleAdsAccount->workspace_id);
        $this->assertSame($firstMonitor->id, $firstCheckRun->monitor_id);
        $this->assertSame($firstMonitor->id, $firstIncident->monitor_id);
        $this->assertSame($firstIncident->id, $firstIncidentEvent->incident_id);
        $this->assertSame($firstMonitor->id, $firstMaintenanceWindow->monitor_id);
        $this->assertSame($firstMonitor->id, $firstTlsObservation->monitor_id);
        $this->assertSame($firstMonitor->id, $firstDnsChangeEvent->monitor_id);
        $this->assertSame($firstMonitor->id, $firstRobotsObservation->monitor_id);
        $this->assertSame($firstMonitor->id, $firstDomainRegistrationObservation->monitor_id);
        $this->assertArrayNotHasKey('refresh_token', $firstGoogleAnalyticsConnection->toArray());
        $this->assertArrayNotHasKey('refresh_token', $firstGoogleSearchConsoleConnection->toArray());
        $this->assertArrayNotHasKey('access_token', $firstMetaMarketingConnection->toArray());
        $this->assertArrayNotHasKey('refresh_token', $firstGoogleAdsConnection->toArray());

        $this->actingAs($firstOwner);
        $this->assertSame([$firstMonitor->id], Monitor::query()->pluck('id')->all());
        $this->assertSame([$firstCheckRun->id], CheckRun::query()->pluck('id')->all());
        $this->assertSame([$firstIncident->id], Incident::query()->pluck('id')->all());
        $this->assertSame([$firstIncidentEvent->id], IncidentEvent::query()->pluck('id')->all());
        $this->assertSame([$firstMaintenanceWindow->id], MaintenanceWindow::query()->pluck('id')->all());
        $this->assertSame([$firstTlsObservation->id], TlsObservation::query()->pluck('id')->all());
        $this->assertSame([$firstDnsChangeEvent->id], DnsChangeEvent::query()->pluck('id')->all());
        $this->assertSame([$firstRobotsObservation->id], RobotsObservation::query()->pluck('id')->all());
        $this->assertSame([
            $firstDomainRegistrationObservation->id,
        ], DomainRegistrationObservation::query()->pluck('id')->all());
        $this->assertSame([$firstTarget->id], SocialEngagementTarget::query()->pluck('id')->all());
        $this->assertSame([$firstStatusPage->id], StatusPage::query()->pluck('id')->all());
        $this->assertSame([$firstOutbox->id], NotificationOutbox::query()->pluck('id')->all());
        $this->assertSame([$firstGoogleAnalyticsConnection->id], GoogleAnalyticsConnection::query()->pluck('id')->all());
        $this->assertSame([$firstGoogleSearchConsoleConnection->id], GoogleSearchConsoleConnection::query()->pluck('id')->all());
        $this->assertSame([$firstMetaMarketingConnection->id], MetaMarketingConnection::query()->pluck('id')->all());
        $this->assertSame([$firstGoogleAdsConnection->id], GoogleAdsConnection::query()->pluck('id')->all());
        $this->assertSame([$firstGoogleAnalyticsProperty->id], GoogleAnalyticsClientProperty::query()->pluck('id')->all());
        $this->assertSame([$firstGoogleSearchConsoleSite->id], GoogleSearchConsoleClientSite::query()->pluck('id')->all());
        $this->assertSame([$firstMetaMarketingAccount->id], MetaMarketingClientAccount::query()->pluck('id')->all());
        $this->assertSame([$firstGoogleAdsAccount->id], GoogleAdsClientAccount::query()->pluck('id')->all());
    }

    public function test_provider_client_mapping_cannot_connect_records_from_different_workspaces(): void
    {
        $firstOwner = User::factory()->create();
        $firstOrganization = Organization::factory()->for($firstOwner, 'owner')->create();
        Workspace::factory()->for($firstOrganization)->create();
        $secondOwner = User::factory()->create();
        $secondOrganization = Organization::factory()->for($secondOwner, 'owner')->create();
        Workspace::factory()->for($secondOrganization)->create();

        $this->actingAs($firstOwner);
        $firstConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $firstOwner->id,
            'refresh_token' => 'first-workspace-token',
        ]);

        $this->actingAs($secondOwner);
        $secondClient = Client::query()->create(['name' => '目標工作區客戶']);

        $this->expectException(QueryException::class);

        GoogleAnalyticsClientProperty::withoutGlobalScopes()->create([
            'google_analytics_connection_id' => $firstConnection->id,
            'client_id' => $secondClient->id,
            'property_id' => '20002',
        ]);
    }
}
