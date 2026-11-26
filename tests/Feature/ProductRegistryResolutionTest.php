<?php

namespace Tests\Feature;

use App\Services\ProductRegistry;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductRegistryResolutionTest extends TestCase
{
    public function test_product_registry_is_available_from_the_application_container(): void
    {
        $registry = $this->app->make(ProductRegistry::class);

        $this->assertSame('網站可靠度監測', $registry->find('website-monitoring')['name']);
        $this->assertSame('行銷成效分析', $registry->find('marketing-insights')['name']);
        $this->assertSame($registry, $this->app->make(ProductRegistry::class));
    }

    public function test_every_registered_product_entry_route_exists(): void
    {
        $registry = $this->app->make(ProductRegistry::class);

        foreach ($registry->all() as $manifest) {
            $this->assertTrue(Route::has($manifest['entry_route']), $manifest['product_code']);
        }
    }
}
