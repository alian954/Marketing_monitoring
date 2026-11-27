<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlanEntitlement;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Services\ProductEntitlementService;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_only_platform_admins_can_open_an_organization_product_subscription_page(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('platform.organizations.products', $organization))
            ->assertRedirect(route('platform.login'));

        $this->actingAs($this->platformAdmin(), 'platform')
            ->get(route('platform.organizations.products', $organization))
            ->assertOk()
            ->assertSee($organization->name)
            ->assertSee('封存的產品訂閱管理')
            ->assertSee('建立訂閱合約');
    }

    public function test_platform_admin_can_create_a_subscription_add_a_product_and_grant_its_feature(): void
    {
        $organization = Organization::factory()->create();
        $product = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();
        $plan = $this->createActivePlan($product, 'monitoring.targets', 35);
        $admin = $this->platformAdmin();
        $startsAt = now('Asia/Taipei')->subDay()->format('Y-m-d\TH:i');

        $this->actingAs($admin, 'platform')
            ->post(route('platform.organizations.subscriptions.store', $organization), [
                'status' => 'active',
                'starts_at' => $startsAt,
                'current_period_starts_at' => $startsAt,
                'current_period_ends_at' => now('Asia/Taipei')->addMonth()->format('Y-m-d\TH:i'),
                'trial_ends_at' => '',
            ])
            ->assertRedirect(route('platform.organizations.products', $organization))
            ->assertSessionHasNoErrors();

        $subscription = Subscription::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->actingAs($admin, 'platform')
            ->post(route('platform.organizations.subscriptions.items.store', [$organization, $subscription]), [
                'subscription_id' => $subscription->id,
                'product_plan_id' => $plan->id,
                'quantity' => 2,
                'starts_at' => now('Asia/Taipei')->subMinute()->format('Y-m-d\TH:i'),
                'ends_at' => '',
            ])
            ->assertRedirect(route('platform.organizations.products', $organization))
            ->assertSessionHasNoErrors();

        $item = SubscriptionItem::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(2, $item->quantity);
        $grant = app(ProductEntitlementService::class)->resolve($organization, 'website-monitoring', 'monitoring.targets');
        $this->assertTrue($grant->isGranted);
        $this->assertSame(35, $grant->limitValue);

        $this->actingAs($admin, 'platform')
            ->get(route('platform.organizations.products', $organization))
            ->assertOk()
            ->assertSee('網站可靠度監測')
            ->assertSee('加入產品');
    }

    public function test_subscription_times_entered_in_taiwan_time_are_stored_as_utc(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->platformAdmin(), 'platform')
            ->post(route('platform.organizations.subscriptions.store', $organization), [
                'status' => 'active',
                'starts_at' => '2026-12-01T09:00',
                'current_period_starts_at' => '2026-12-01T09:00',
                'current_period_ends_at' => '2027-01-01T09:00',
                'trial_ends_at' => '',
            ])
            ->assertSessionHasNoErrors();

        $subscription = Subscription::query()->where('organization_id', $organization->id)->sole();
        $this->assertSame('2026-12-01 01:00:00', $subscription->starts_at->utc()->toDateTimeString());
        $this->assertSame('2027-01-01 01:00:00', $subscription->current_period_ends_at->utc()->toDateTimeString());
    }

    public function test_trial_subscriptions_require_a_trial_end_time(): void
    {
        $organization = Organization::factory()->create();
        $startsAt = now('Asia/Taipei')->format('Y-m-d\TH:i');

        $this->actingAs($this->platformAdmin(), 'platform')
            ->from(route('platform.organizations.products', $organization))
            ->post(route('platform.organizations.subscriptions.store', $organization), [
                'status' => 'trialing',
                'starts_at' => $startsAt,
                'current_period_starts_at' => $startsAt,
                'current_period_ends_at' => '',
                'trial_ends_at' => '',
            ])
            ->assertSessionHasErrorsIn('subscription', ['trial_ends_at']);

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_product_can_only_be_added_once_and_an_item_cannot_change_to_another_products_plan(): void
    {
        $organization = Organization::factory()->create();
        $subscription = Subscription::factory()->create(['organization_id' => $organization->id]);
        $websiteProduct = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();
        $marketingProduct = Product::query()->where('product_code', 'marketing-insights')->firstOrFail();
        $websitePlan = $this->createActivePlan($websiteProduct, 'monitoring.targets', 10);
        $marketingPlan = $this->createActivePlan($marketingProduct, 'marketing.reports', 10);
        $item = $this->createSubscriptionItem($organization, $subscription, $websiteProduct, $websitePlan);
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform')
            ->from(route('platform.organizations.products', $organization))
            ->post(route('platform.organizations.subscriptions.items.store', [$organization, $subscription]), [
                'subscription_id' => $subscription->id,
                'product_plan_id' => $websitePlan->id,
                'quantity' => 1,
                'starts_at' => now()->format('Y-m-d\TH:i'),
                'ends_at' => '',
            ])
            ->assertSessionHasErrorsIn('subscriptionItem', ['product_plan_id']);

        $this->actingAs($admin, 'platform')
            ->from(route('platform.organizations.products', $organization))
            ->patch(route('platform.organizations.subscriptions.items.update', [$organization, $subscription, $item]), [
                'subscription_item_id' => $item->id,
                'status' => 'active',
                'product_plan_id' => $marketingPlan->id,
                'quantity' => 1,
                'starts_at' => now()->subMinute()->format('Y-m-d\TH:i'),
                'ends_at' => '',
            ])
            ->assertSessionHasErrorsIn('subscriptionItemState', ['product_plan_id']);

        $this->assertSame($websitePlan->id, $item->fresh()->product_plan_id);
    }

    public function test_subscription_and_item_routes_cannot_cross_organization_boundaries(): void
    {
        $firstOrganization = Organization::factory()->create();
        $secondOrganization = Organization::factory()->create();
        $firstSubscription = Subscription::factory()->create(['organization_id' => $firstOrganization->id]);
        $secondSubscription = Subscription::factory()->create(['organization_id' => $secondOrganization->id]);
        $product = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();
        $plan = $this->createActivePlan($product, 'monitoring.targets', 10);
        $item = $this->createSubscriptionItem($secondOrganization, $secondSubscription, $product, $plan);
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform')
            ->patch(route('platform.organizations.subscriptions.update', [$firstOrganization, $secondSubscription]), [
                'status' => 'canceled',
                'starts_at' => now()->subDay()->format('Y-m-d\TH:i'),
                'current_period_starts_at' => now()->subDay()->format('Y-m-d\TH:i'),
                'current_period_ends_at' => '',
                'trial_ends_at' => '',
                'cancel_at_period_end' => '0',
            ])
            ->assertNotFound();

        $this->actingAs($admin, 'platform')
            ->patch(route('platform.organizations.subscriptions.items.update', [$firstOrganization, $firstSubscription, $item]), [
                'status' => 'canceled',
                'product_plan_id' => $plan->id,
                'quantity' => 1,
                'starts_at' => now()->subDay()->format('Y-m-d\TH:i'),
                'ends_at' => '',
            ])
            ->assertNotFound();

        $this->assertSame('active', $secondSubscription->fresh()->status);
        $this->assertSame('active', $item->fresh()->status);
    }

    /**
     * Create an active plan that exposes one declared feature.
     */
    private function createActivePlan(Product $product, string $featureKey, int $limit): ProductPlan
    {
        $plan = ProductPlan::factory()->for($product)->create([
            'status' => 'active',
            'amount_minor' => 9900,
            'currency_code' => 'TWD',
        ]);

        PlanEntitlement::query()->create([
            'product_id' => $product->getKey(),
            'product_plan_id' => $plan->getKey(),
            'feature_key' => $featureKey,
            'is_enabled' => true,
            'limit_value' => $limit,
        ]);

        return $plan;
    }

    private function createSubscriptionItem(
        Organization $organization,
        Subscription $subscription,
        Product $product,
        ProductPlan $plan,
    ): SubscriptionItem {
        return SubscriptionItem::query()->create([
            'organization_id' => $organization->getKey(),
            'subscription_id' => $subscription->getKey(),
            'product_id' => $product->getKey(),
            'product_plan_id' => $plan->getKey(),
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
