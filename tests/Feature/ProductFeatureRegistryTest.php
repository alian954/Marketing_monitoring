<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceProductFeatureAvailability;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductRegistry;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductFeatureRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_registered_feature_routes_exist_and_resolve_to_declared_features(): void
    {
        $registry = app(ProductRegistry::class);

        foreach ($registry->all() as $productCode => $manifest) {
            foreach ($manifest['route_features'] ?? [] as $routeName => $featureKeys) {
                $this->assertTrue(Route::has($routeName), "Missing registered route [{$routeName}].");

                foreach ($featureKeys as $featureKey) {
                    $this->assertContains($featureKey, $manifest['feature_keys']);
                    $this->assertContains(
                        ['product_code' => $productCode, 'feature_key' => $featureKey],
                        $registry->featureRequirementsForRoute($routeName),
                    );
                }
            }
        }
    }

    public function test_every_product_specific_route_including_public_entry_points_is_mapped_to_a_feature(): void
    {
        $registry = app(ProductRegistry::class);
        $routePrefixes = [
            'client-report-shares.',
            'clients.performance',
            'clients.report-shares.',
            'incidents.',
            'integrations.',
            'monitors.',
            'notifications.',
            'social-engagement-targets.',
            'social-post-maturity.',
            'status-page-subscriptions.',
            'status-pages.',
        ];

        foreach (Route::getRoutes() as $route) {
            $routeName = $route->getName();

            if (! is_string($routeName)) {
                continue;
            }

            // Recipients must always be able to unsubscribe, whatever the product lifecycle or entitlement.
            if (in_array($routeName, ['status-page-subscriptions.unsubscribe', 'status-page-subscriptions.unsubscribe.store'], true)) {
                continue;
            }

            if ($registry->featureRequirementsForRoute($routeName) !== []) {
                $this->assertContains(
                    EnforceProductFeatureAvailability::class,
                    $route->gatherMiddleware(),
                    "Product route [{$routeName}] must enforce product availability.",
                );
            }

            foreach ($routePrefixes as $prefix) {
                if ($routeName === $prefix || str_starts_with($routeName, $prefix)) {
                    $this->assertNotSame([], $registry->featureRequirementsForRoute($routeName), "Unmapped product route [{$routeName}].");

                    break;
                }
            }
        }
    }

    public function test_product_maintenance_blocks_its_features_but_not_the_central_catalog(): void
    {
        $product = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();
        $product->update(['lifecycle' => 'maintenance']);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('monitors.index'))->assertStatus(503);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)->get(route('platform.products.index'))->assertOk();
    }

    public function test_unsynced_product_catalog_preserves_existing_registered_features(): void
    {
        Product::query()->where('product_code', 'website-monitoring')->delete();

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.index'))
            ->assertOk();
    }

    public function test_retired_product_features_return_not_found(): void
    {
        Product::query()->where('product_code', 'marketing-insights')->update(['lifecycle' => 'retired']);

        $this->actingAs(User::factory()->create())
            ->get(route('clients.performance'))
            ->assertNotFound();
    }

    public function test_catalog_sync_preserves_an_operational_product_lifecycle(): void
    {
        $product = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();
        $product->update(['lifecycle' => 'maintenance']);

        $this->artisan('products:sync')->assertExitCode(0);

        $this->assertSame('maintenance', $product->fresh()->lifecycle);
    }

    public function test_only_platform_admins_can_change_product_lifecycle(): void
    {
        $product = Product::query()->where('product_code', 'website-monitoring')->firstOrFail();
        $payload = ['product_id' => $product->id, 'lifecycle' => 'maintenance'];

        $this->actingAs(User::factory()->create())
            ->patch(route('platform.products.lifecycle.update', $product), $payload)
            ->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)
            ->patch(route('platform.products.lifecycle.update', $product), $payload)
            ->assertRedirect(route('platform.products.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('maintenance', $product->fresh()->lifecycle);
    }
}
