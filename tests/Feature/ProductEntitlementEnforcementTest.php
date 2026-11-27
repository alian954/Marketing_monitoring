<?php

namespace Tests\Feature;

use App\Jobs\CheckTlsCertificate;
use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\PlanEntitlement;
use App\Models\PlatformAuditAction;
use App\Models\PlatformAuditEvent;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\StatusPage;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\IncidentManager;
use App\Services\Probe\ProbeClient;
use App\Services\ProductRegistry;
use App\Services\RunScheduler;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductEntitlementEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_legacy_organizations_keep_full_product_access_without_a_subscription(): void
    {
        $owner = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $owner->id]);

        $this->actingAs($owner)->get(route('monitors.index'))->assertOk();
        $this->get(route('clients.performance'))->assertOk();
    }

    public function test_enforced_organization_only_reaches_features_its_subscription_grants(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create([
            'owner_user_id' => $owner->id,
            'entitlements_enforced_at' => now(),
        ]);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create(['owner_user_id' => $owner->id, 'member_user_id' => $member->id]);

        $this->actingAs($owner)
            ->get(route('monitors.index'))
            ->assertForbidden()
            ->assertSee('此功能未包含在組織目前的訂閱方案中');

        $this->subscribe($organization, 'website-monitoring', 'monitoring.targets');

        $this->get(route('monitors.index'))->assertOk();
        $this->get(route('incidents.index'))->assertForbidden();
        $this->get(route('clients.performance'))->assertForbidden();
        $this->get(route('clients.index'))->assertOk();

        $this->actingAs($member)->get(route('monitors.index'))->assertOk();
        $this->get(route('clients.performance'))->assertForbidden();
    }

    public function test_platform_admin_is_not_limited_when_viewing_an_enforced_organization(): void
    {
        $organization = Organization::factory()->create(['entitlements_enforced_at' => now()]);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post(route('platform.organizations.open', $organization))
            ->assertRedirect(route('dashboard'));
        $this->get(route('monitors.index'))->assertOk();
    }

    public function test_only_platform_admins_toggle_enforcement_and_changes_are_audited(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);

        $this->actingAs($owner)
            ->patch(route('platform.organizations.entitlement-enforcement.update', $organization), ['enforce' => '1'])
            ->assertForbidden();
        $this->assertFalse($organization->fresh()->enforcesEntitlements());

        $this->actingAs($this->platformAdmin())
            ->patch(route('platform.organizations.entitlement-enforcement.update', $organization), ['enforce' => '1'])
            ->assertRedirect(route('platform.index'));
        $this->assertTrue($organization->fresh()->enforcesEntitlements());

        $this->patch(route('platform.organizations.entitlement-enforcement.update', $organization), ['enforce' => '1'])
            ->assertRedirect(route('platform.index'));
        $this->patch(route('platform.organizations.entitlement-enforcement.update', $organization), ['enforce' => '0'])
            ->assertRedirect(route('platform.index'));
        $this->assertFalse($organization->fresh()->enforcesEntitlements());

        $events = PlatformAuditEvent::query()->where('action', PlatformAuditAction::OrganizationUpdated)->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['entitlements_enforced' => ['before' => false, 'after' => true]], $events[0]->metadata['changes']);
        $this->assertSame(['entitlements_enforced' => ['before' => true, 'after' => false]], $events[1]->metadata['changes']);

        $this->get(route('platform.index'))->assertOk()->assertSee('啟用訂閱授權');
    }

    public function test_background_monitoring_skips_enforced_organizations_without_the_feature(): void
    {
        Queue::fake();
        $legacyMonitor = $this->ownedMonitor(['entitlements_enforced_at' => null], 'https://legacy.example/');
        $unentitledMonitor = $this->ownedMonitor(['entitlements_enforced_at' => now()], 'https://unentitled.example/');
        $entitledMonitor = $this->ownedMonitor(['entitlements_enforced_at' => now()], 'https://entitled.example/');
        $this->subscribe(
            Organization::query()->where('owner_user_id', $entitledMonitor->user_id)->sole(),
            'website-monitoring',
            'monitoring.targets',
        );

        $this->artisan('monitors:dispatch-due')->assertExitCode(0);
        $this->artisan('monitors:dispatch-tls')->assertExitCode(0);

        $dispatchedMonitorIds = CheckRun::query()->orderBy('monitor_id')->pluck('monitor_id')->all();
        $this->assertSame([$legacyMonitor->id, $entitledMonitor->id], $dispatchedMonitorIds);
        Queue::assertPushed(CheckTlsCertificate::class, 2);
        $this->assertNotContains($unentitledMonitor->id, $dispatchedMonitorIds);
    }

    public function test_readiness_report_lists_features_a_legacy_organization_would_lose(): void
    {
        $readyOrganization = Organization::factory()->create(['name' => '已備妥組織']);
        foreach (app(ProductRegistry::class)->all() as $productCode => $manifest) {
            $this->subscribe($readyOrganization, $productCode, ...$manifest['feature_keys']);
        }
        $partialOrganization = Organization::factory()->create(['name' => '部分訂閱組織']);
        $this->subscribe($partialOrganization, 'website-monitoring', 'monitoring.targets');
        Organization::factory()->create(['name' => '已啟用組織', 'entitlements_enforced_at' => now()]);

        $this->artisan('products:entitlement-readiness')
            ->expectsOutputToContain('可啟用')
            ->expectsOutputToContain('會失去 11 項')
            ->doesntExpectOutputToContain('已啟用組織')
            ->expectsOutputToContain('共 2 個組織，其中 1 個啟用後不會失去功能。')
            ->assertExitCode(0);

        $this->assertNull($partialOrganization->fresh()->entitlements_enforced_at);
    }

    public function test_queued_checks_pause_when_the_organization_is_no_longer_entitled(): void
    {
        $monitor = $this->ownedMonitor(['entitlements_enforced_at' => now()], 'https://queued.example/');
        $run = CheckRun::factory()->for($monitor)->create(['status' => 'queued']);
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldNotReceive('inspect');

        (new RunMonitorCheck($run->id))->handle($probe, app(IncidentManager::class), app(RunScheduler::class));

        $this->assertSame('paused', $run->fresh()->status);
        $this->assertNull(app(RunScheduler::class)->enqueue($monitor, 'manual'));
    }

    public function test_public_entry_points_follow_the_owner_organization_entitlements(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create([
            'owner_user_id' => $owner->id,
            'entitlements_enforced_at' => now(),
        ]);
        $statusToken = Str::random(64);
        StatusPage::factory()->create([
            'setting_key' => 'default',
            'public_token' => $statusToken,
            'public_token_hash' => hash('sha256', $statusToken),
            'is_published' => true,
        ])->forceFill(['user_id' => $owner->id])->save();
        Monitor::factory()->create(['public_status' => true])->forceFill(['user_id' => $owner->id])->save();
        $shareToken = str_repeat('b', 64);
        ClientReportShare::factory()
            ->for(Client::factory()->for($owner))
            ->create(['token_hash' => hash('sha256', $shareToken)]);

        $this->get(route('status-pages.public', ['token' => $statusToken]))->assertNotFound();
        $this->get(route('client-report-shares.public', ['token' => $shareToken]))->assertNotFound();
        $this->get(route('client-report-shares.public.export', ['token' => $shareToken]))->assertNotFound();

        $this->subscribe($organization, 'website-monitoring', 'monitoring.status_pages');
        $this->get(route('status-pages.public', ['token' => $statusToken]))->assertOk();
        $this->get(route('client-report-shares.public', ['token' => $shareToken]))->assertNotFound();

        $organization->forceFill(['entitlements_enforced_at' => null])->save();
        $this->get(route('client-report-shares.public', ['token' => $shareToken]))->assertOk();
    }

    /**
     * @param  array<string, mixed>  $organizationAttributes
     */
    private function ownedMonitor(array $organizationAttributes, string $url): Monitor
    {
        $owner = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $owner->id, ...$organizationAttributes]);
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'client_id' => Client::ensureDefaultFor($owner)->getKey(),
            'next_check_at' => now()->subMinute(),
        ]);
        $monitor->forceFill(['user_id' => $owner->id])->save();

        return $monitor;
    }

    private function subscribe(Organization $organization, string $productCode, string ...$featureKeys): void
    {
        $product = Product::query()->where('product_code', $productCode)->firstOrFail();
        $plan = ProductPlan::factory()->for($product)->create([
            'status' => 'active',
            'amount_minor' => 9900,
            'currency_code' => 'TWD',
        ]);
        foreach ($featureKeys as $featureKey) {
            PlanEntitlement::query()->create([
                'product_id' => $product->id,
                'product_plan_id' => $plan->id,
                'feature_key' => $featureKey,
                'is_enabled' => true,
                'limit_value' => null,
            ]);
        }
        $subscription = Subscription::query()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'current_period_starts_at' => now()->subDay(),
            'current_period_ends_at' => now()->addMonth(),
            'cancel_at_period_end' => false,
        ]);
        SubscriptionItem::query()->create([
            'organization_id' => $organization->id,
            'subscription_id' => $subscription->id,
            'product_id' => $product->id,
            'product_plan_id' => $plan->id,
            'status' => 'active',
            'quantity' => 1,
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
        ]);
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }
}
