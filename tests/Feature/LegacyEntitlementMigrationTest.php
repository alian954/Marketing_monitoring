<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlanEntitlement;
use App\Models\PlatformAuditAction;
use App\Models\PlatformAuditEvent;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Services\ProductRegistry;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LegacyEntitlementMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-28 10:00:00', 'Asia/Taipei'));
    }

    public function test_page_lists_legacy_organizations_with_their_readiness(): void
    {
        Organization::factory()->create(['name' => '舊方案組織', 'plan_name' => '標準方案', 'subscription_ends_on' => '2027-03-31']);
        Organization::factory()->create(['name' => '已到期組織', 'subscription_ends_on' => '2026-10-31']);
        Organization::factory()->create(['name' => '已啟用組織', 'entitlements_enforced_at' => now()]);
        $this->fullPlans();

        $this->actingAs($this->platformAdmin(), 'platform')
            ->get(route('platform.entitlement-migration.index'))
            ->assertOk()
            ->assertSee('舊方案組織')
            ->assertSee('標準方案')
            ->assertSee('到期 2027/3/31')
            ->assertSee('會失去')
            ->assertSee('已停用或到期')
            ->assertDontSee('已啟用組織')
            ->assertSee('為勾選組織建立對照訂閱');
    }

    public function test_mapping_creates_subscriptions_ending_with_the_legacy_term_then_batch_enforcement_keeps_every_feature(): void
    {
        $organization = Organization::factory()->create(['name' => '舊方案組織', 'subscription_ends_on' => '2027-03-31']);
        $openEndedOrganization = Organization::factory()->create(['name' => '無到期組織', 'subscription_ends_on' => null]);
        $plans = $this->fullPlans();
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform')
            ->post(route('platform.entitlement-migration.enforce'), ['organization_ids' => [$organization->id]])
            ->assertRedirect(route('platform.entitlement-migration.index'))
            ->assertSessionHas('status', '已為 0 個組織啟用訂閱授權。略過啟用後會失去功能的組織：舊方案組織。');
        $this->assertFalse($organization->fresh()->enforcesEntitlements());

        $this->post(route('platform.entitlement-migration.subscriptions'), [
            'organization_ids' => [$organization->id, $openEndedOrganization->id],
            'plan_ids' => $plans,
        ])->assertRedirect(route('platform.entitlement-migration.index'))
            ->assertSessionHas('status', '已為 2 個組織建立 '.(count($plans) * 2).' 項對照訂閱；已有該產品訂閱、已停用或已到期的組織會略過。');

        $item = SubscriptionItem::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('2027-04-01 00:00', $item->ends_at->setTimezone('Asia/Taipei')->format('Y-m-d H:i'));
        $this->assertNull(SubscriptionItem::query()->where('organization_id', $openEndedOrganization->id)->firstOrFail()->ends_at);
        $this->assertSame(1, $organization->subscriptions()->count());
        $this->assertTrue(PlatformAuditEvent::query()->where('action', PlatformAuditAction::SubscriptionItemCreated)->get()->every(
            static fn (PlatformAuditEvent $event): bool => $event->metadata['source'] === 'legacy_migration',
        ));

        $this->post(route('platform.entitlement-migration.subscriptions'), [
            'organization_ids' => [$organization->id],
            'plan_ids' => $plans,
        ])->assertSessionHas('status', '已為 0 個組織建立 0 項對照訂閱；已有該產品訂閱、已停用或已到期的組織會略過。');

        $this->post(route('platform.entitlement-migration.enforce'), ['organization_ids' => [$organization->id, $openEndedOrganization->id]])
            ->assertSessionHas('status', '已為 2 個組織啟用訂閱授權。');
        $this->assertTrue($organization->fresh()->enforcesEntitlements());

        $this->actingAs(User::query()->findOrFail($organization->owner_user_id))
            ->get(route('monitors.index'))
            ->assertOk();
    }

    public function test_expired_or_disabled_organizations_and_invalid_plans_are_not_mapped(): void
    {
        $expired = Organization::factory()->create(['subscription_ends_on' => '2026-11-27']);
        $disabled = Organization::factory()->create(['is_enabled' => false]);
        $plans = $this->fullPlans();
        $otherProductPlanId = reset($plans);
        $mismatchedProductId = array_key_last($plans);

        $this->actingAs($this->platformAdmin(), 'platform')
            ->post(route('platform.entitlement-migration.subscriptions'), [
                'organization_ids' => [$expired->id, $disabled->id],
                'plan_ids' => $plans,
            ])->assertSessionHas('status', '已為 0 個組織建立 0 項對照訂閱；已有該產品訂閱、已停用或已到期的組織會略過。');

        $this->post(route('platform.entitlement-migration.subscriptions'), [
            'organization_ids' => [$expired->id],
            'plan_ids' => [$mismatchedProductId => $otherProductPlanId],
        ])->assertSessionHasErrors('plan_ids');
        $this->post(route('platform.entitlement-migration.subscriptions'), [
            'organization_ids' => [$expired->id],
            'plan_ids' => [array_key_first($plans) => ''],
        ])->assertSessionHasErrors('plan_ids');

        $this->assertDatabaseCount('subscription_items', 0);
    }

    public function test_customers_cannot_reach_the_migration_page(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('platform.entitlement-migration.enforce'), ['organization_ids' => [$organization->id]])
            ->assertRedirect(route('platform.login'));
        $this->assertFalse($organization->fresh()->enforcesEntitlements());
    }

    /**
     * One active plan per registered product granting all of its features.
     *
     * @return array<int, int> Plan IDs keyed by product ID.
     */
    private function fullPlans(): array
    {
        $plans = [];

        foreach (app(ProductRegistry::class)->all() as $productCode => $manifest) {
            $product = Product::query()->where('product_code', $productCode)->firstOrFail();
            $plan = ProductPlan::factory()->for($product)->create([
                'status' => 'active',
                'amount_minor' => 0,
                'currency_code' => 'TWD',
            ]);

            foreach ($manifest['feature_keys'] as $featureKey) {
                PlanEntitlement::query()->create([
                    'product_id' => $product->id,
                    'product_plan_id' => $plan->id,
                    'feature_key' => $featureKey,
                    'is_enabled' => true,
                    'limit_value' => null,
                ]);
            }

            $plans[$product->id] = $plan->id;
        }

        return $plans;
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }
}
