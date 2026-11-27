<?php

namespace Database\Factories;

use App\Models\PlanEntitlement;
use App\Models\Product;
use App\Models\ProductFeature;
use App\Models\ProductPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanEntitlement>
 */
class PlanEntitlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => $product = Product::factory(),
            'product_plan_id' => ProductPlan::factory()->for($product),
            'feature_key' => 'test.feature-'.$this->faker->unique()->word(),
            'is_enabled' => true,
            'limit_value' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(static function (PlanEntitlement $entitlement): void {
            ProductFeature::query()->firstOrCreate([
                'product_id' => $entitlement->product_id,
                'feature_key' => $entitlement->feature_key,
            ]);
        });
    }
}
