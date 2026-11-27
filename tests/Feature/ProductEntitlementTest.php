<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlanEntitlement;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Services\ProductEntitlementService;
use Carbon\CarbonImmutable;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_it_grants_a_declared_feature_with_the_plan_limit_to_an_active_subscription(): void
    {
        $fixture = $this->createSubscriptionFixture(limitValue: 25);

        $grant = app(ProductEntitlementService::class)->resolve(
            $fixture['organization'],
            'website-monitoring',
            'monitoring.targets',
        );

        $this->assertTrue($grant->isGranted);
        $this->assertSame(25, $grant->limitValue);
    }

    public function test_it_denies_features_without_an_active_product_subscription(): void
    {
        $fixture = $this->createSubscriptionFixture(subscriptionStatus: 'canceled');

        $grant = app(ProductEntitlementService::class)->resolve(
            $fixture['organization'],
            'website-monitoring',
            'monitoring.targets',
        );

        $this->assertFalse($grant->isGranted);
        $this->assertNull($grant->limitValue);
    }

    public function test_it_denies_a_trial_after_its_end_time(): void
    {
        $fixture = $this->createSubscriptionFixture(
            subscriptionStatus: 'trialing',
            trialEndsAt: now()->subSecond()->toDateTimeString(),
        );

        $grant = app(ProductEntitlementService::class)->resolve(
            $fixture['organization'],
            'website-monitoring',
            'monitoring.targets',
        );

        $this->assertFalse($grant->isGranted);
    }

    public function test_it_grants_an_unexpired_trial_and_denies_an_expired_billing_period(): void
    {
        $trial = $this->createSubscriptionFixture(
            subscriptionStatus: 'trialing',
            trialEndsAt: now()->addDay()->toDateTimeString(),
        );
        $service = app(ProductEntitlementService::class);

        $this->assertTrue($service->resolve(
            $trial['organization'],
            'website-monitoring',
            'monitoring.targets',
        )->isGranted);

        $expired = $this->createSubscriptionFixture();
        $expired['subscription']->update(['current_period_ends_at' => now()->subSecond()]);

        $this->assertFalse($service->resolve(
            $expired['organization'],
            'website-monitoring',
            'monitoring.targets',
        )->isGranted);
    }

    public function test_it_denies_features_that_are_not_declared_or_enabled(): void
    {
        $fixture = $this->createSubscriptionFixture(enabled: false);
        $service = app(ProductEntitlementService::class);

        $this->assertFalse($service->resolve(
            $fixture['organization'],
            'website-monitoring',
            'monitoring.targets',
        )->isGranted);
        $this->assertFalse($service->resolve(
            $fixture['organization'],
            'website-monitoring',
            'monitoring.not_registered',
        )->isGranted);
    }

    public function test_the_catalog_seeder_can_be_run_more_than_once(): void
    {
        $this->artisan('products:sync')->assertExitCode(0);
        $this->artisan('products:sync')->assertExitCode(0);

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('product_features', 12);
    }

    public function test_it_does_not_grant_a_feature_when_the_legacy_organization_is_disabled(): void
    {
        $fixture = $this->createSubscriptionFixture();
        $fixture['organization']->update(['is_enabled' => false]);

        $grant = app(ProductEntitlementService::class)->resolve(
            $fixture['organization']->fresh(),
            'website-monitoring',
            'monitoring.targets',
        );

        $this->assertFalse($grant->isGranted);
    }

    public function test_it_uses_the_requested_time_for_the_legacy_organization_expiry(): void
    {
        $fixture = $this->createSubscriptionFixture();
        $evaluationTime = CarbonImmutable::now('Asia/Taipei')->addDays(2);
        $fixture['organization']->update([
            'subscription_ends_on' => $evaluationTime->subDay()->toDateString(),
        ]);

        $grant = app(ProductEntitlementService::class)->resolve(
            $fixture['organization']->fresh(),
            'website-monitoring',
            'monitoring.targets',
            $evaluationTime,
        );

        $this->assertFalse($grant->isGranted);
    }

    public function test_it_denies_features_for_a_product_disabled_in_the_catalog(): void
    {
        $fixture = $this->createSubscriptionFixture();
        $fixture['product']->update(['lifecycle' => 'maintenance']);

        $grant = app(ProductEntitlementService::class)->resolve(
            $fixture['organization'],
            'website-monitoring',
            'monitoring.targets',
        );

        $this->assertFalse($grant->isGranted);
    }

    public function test_subscription_items_cannot_cross_organization_or_product_plan_boundaries(): void
    {
        $fixture = $this->createSubscriptionFixture();
        $otherOrganization = Organization::factory()->create();
        $marketingProduct = Product::query()->where('product_code', 'marketing-insights')->firstOrFail();
        $marketingPlan = ProductPlan::factory()->create(['product_id' => $marketingProduct->id]);

        try {
            SubscriptionItem::query()->create([
                'organization_id' => $otherOrganization->id,
                'subscription_id' => $fixture['subscription']->id,
                'product_id' => $fixture['product']->id,
                'product_plan_id' => $fixture['plan']->id,
                'status' => 'active',
                'quantity' => 1,
                'starts_at' => now()->subMinute(),
            ]);

            $this->fail('A subscription item was allowed to cross organization boundaries.');
        } catch (QueryException) {
            $this->assertDatabaseCount('subscription_items', 1);
        }

        try {
            SubscriptionItem::query()->create([
                'organization_id' => $fixture['organization']->id,
                'subscription_id' => $fixture['subscription']->id,
                'product_id' => $fixture['product']->id,
                'product_plan_id' => $marketingPlan->id,
                'status' => 'active',
                'quantity' => 1,
                'starts_at' => now()->subMinute(),
            ]);

            $this->fail('A subscription item was allowed to reference another product plan.');
        } catch (QueryException) {
            $this->assertDatabaseCount('subscription_items', 1);
        }
    }

    /**
     * @return array{
     *     organization: Organization,
     *     product: Product,
     *     plan: ProductPlan,
     *     subscription: Subscription,
     *     item: SubscriptionItem
     * }
     */
    private function createSubscriptionFixture(
        string $subscriptionStatus = 'active',
        bool $enabled = true,
        ?int $limitValue = null,
        ?string $trialEndsAt = null,
    ): array {
        $organization = Organization::factory()->create();
        $product = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();
        $plan = ProductPlan::factory()->create(['product_id' => $product->id]);
        PlanEntitlement::factory()->create([
            'product_id' => $product->id,
            'product_plan_id' => $plan->id,
            'feature_key' => 'monitoring.targets',
            'is_enabled' => $enabled,
            'limit_value' => $limitValue,
        ]);
        $subscription = Subscription::factory()->create([
            'organization_id' => $organization->id,
            'status' => $subscriptionStatus,
            'trial_ends_at' => $trialEndsAt,
        ]);
        $item = SubscriptionItem::factory()->create([
            'subscription_id' => $subscription->id,
            'product_id' => $product->id,
            'product_plan_id' => $plan->id,
        ]);

        return compact('organization', 'product', 'plan', 'subscription', 'item');
    }
}
