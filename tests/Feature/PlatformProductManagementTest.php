<?php

namespace Tests\Feature;

use App\Models\PlanEntitlement;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_only_platform_admins_can_open_the_product_feature_catalog(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('platform.products.index'))
            ->assertForbidden();

        $this->actingAs($this->platformAdmin())
            ->get(route('platform.products.index'))
            ->assertOk()
            ->assertSee('產品與功能')
            ->assertSee('已登錄功能')
            ->assertSee('封存項目：方案與訂閱設定')
            ->assertSee('公開貼文監測');
    }

    public function test_platform_admin_can_create_a_draft_plan_with_exact_price_and_feature_limits(): void
    {
        $product = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();
        $featureSettings = $product->features->mapWithKeys(static fn ($feature): array => [
            $feature->id => ['is_enabled' => '0', 'limit_value' => ''],
        ])->all();
        $targetFeature = $product->features->firstWhere('feature_key', 'monitoring.targets');
        $featureSettings[$targetFeature->id] = ['is_enabled' => '1', 'limit_value' => '25'];

        $this->actingAs($this->platformAdmin())
            ->post(route('platform.products.plans.store', $product), [
                'product_id' => $product->id,
                'plan_code' => 'starter',
                'version' => 1,
                'name' => '入門方案',
                'status' => 'draft',
                'billing_interval' => 'monthly',
                'price' => '1299.90',
                'currency_code' => 'TWD',
                'features' => $featureSettings,
            ])
            ->assertRedirect(route('platform.products.index'))
            ->assertSessionHasNoErrors();

        $plan = ProductPlan::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(129990, $plan->amount_minor);
        $this->assertSame('TWD', $plan->currency_code);
        $this->assertSame('draft', $plan->status);
        $this->assertDatabaseHas('plan_entitlements', [
            'product_plan_id' => $plan->id,
            'feature_key' => 'monitoring.targets',
            'is_enabled' => true,
            'limit_value' => 25,
        ]);
        $this->assertSame($product->features->count(), $plan->entitlements()->count());
    }

    public function test_an_active_plan_requires_a_price_and_at_least_one_enabled_feature(): void
    {
        $product = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();
        $featureSettings = $product->features->mapWithKeys(static fn ($feature): array => [
            $feature->id => ['is_enabled' => '0', 'limit_value' => ''],
        ])->all();

        $this->actingAs($this->platformAdmin())
            ->from(route('platform.products.index'))
            ->post(route('platform.products.plans.store', $product), [
                'product_id' => $product->id,
                'plan_code' => 'unpriced',
                'version' => 1,
                'name' => '未定價方案',
                'status' => 'active',
                'billing_interval' => 'monthly',
                'price' => '',
                'currency_code' => '',
                'features' => $featureSettings,
            ])
            ->assertSessionHasErrorsIn('productPlan', ['status']);

        $this->assertDatabaseCount('product_plans', 0);
    }

    public function test_a_plan_cannot_claim_undeclared_features_or_reuse_a_product_version(): void
    {
        $product = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();
        $plan = ProductPlan::factory()->for($product)->create(['plan_code' => 'starter', 'version' => 1]);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->from(route('platform.products.index'))
            ->post(route('platform.products.plans.store', $product), [
                'product_id' => $product->id,
                'plan_code' => 'starter',
                'version' => 1,
                'name' => '重複方案',
                'status' => 'draft',
                'billing_interval' => 'monthly',
                'features' => [999999 => ['is_enabled' => '1', 'limit_value' => '']],
            ])
            ->assertSessionHasErrorsIn('productPlan', ['version', 'features']);

        $this->assertDatabaseHas('product_plans', ['id' => $plan->id]);
        $this->assertDatabaseCount('product_plans', 1);
    }

    public function test_a_plan_can_only_be_activated_after_it_has_a_price_and_enabled_entitlement(): void
    {
        $product = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();
        $plan = ProductPlan::factory()->for($product)->create([
            'status' => 'draft',
            'amount_minor' => null,
            'currency_code' => null,
        ]);

        $this->actingAs($this->platformAdmin())
            ->patch(route('platform.products.plans.update', $plan), ['status' => 'active'])
            ->assertSessionHasErrorsIn('productPlanStatus', ['status']);

        $plan->update(['amount_minor' => 5000, 'currency_code' => 'TWD']);
        PlanEntitlement::factory()->create([
            'product_id' => $product->id,
            'product_plan_id' => $plan->id,
            'feature_key' => 'monitoring.targets',
            'is_enabled' => true,
        ]);

        $this->actingAs($this->platformAdmin())
            ->patch(route('platform.products.plans.update', $plan), ['status' => 'active'])
            ->assertRedirect(route('platform.products.index'));

        $this->assertDatabaseHas('product_plans', ['id' => $plan->id, 'status' => 'active']);
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }
}
