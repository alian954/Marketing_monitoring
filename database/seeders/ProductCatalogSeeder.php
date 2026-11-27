<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductFeature;
use App\Services\ProductRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;

class ProductCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $registry = app(ProductRegistry::class);
        $manifests = $registry->all();

        foreach ($manifests as $manifest) {
            if (! Route::has($manifest['entry_route'])) {
                throw new LogicException("The product entry route [{$manifest['entry_route']}] is not registered.");
            }
        }

        DB::transaction(function () use ($manifests): void {
            foreach ($manifests as $manifest) {
                $product = Product::query()->updateOrCreate(
                    ['product_code' => $manifest['product_code']],
                    [
                        'name' => $manifest['name'],
                        'version' => $manifest['version'],
                        'manifest_version' => $manifest['manifest_version'],
                        'lifecycle' => $manifest['lifecycle'],
                        'entry_route' => $manifest['entry_route'],
                    ],
                );

                foreach ($manifest['feature_keys'] as $featureKey) {
                    ProductFeature::query()->firstOrCreate([
                        'product_id' => $product->id,
                        'feature_key' => $featureKey,
                    ]);
                }
            }
        });
    }
}
