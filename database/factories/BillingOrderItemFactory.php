<?php

namespace Database\Factories;

use App\Models\BillingOrder;
use App\Models\BillingOrderItem;
use App\Models\ProductPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingOrderItem>
 */
class BillingOrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_order_id' => BillingOrder::factory(),
            'organization_id' => static fn (array $attributes): int => (int) BillingOrder::query()
                ->whereKey($attributes['billing_order_id'])
                ->value('organization_id'),
            'product_plan_id' => ProductPlan::factory(),
            'product_id' => static fn (array $attributes): int => (int) ProductPlan::query()
                ->whereKey($attributes['product_plan_id'])
                ->value('product_id'),
            'description' => fake()->words(3, true),
            'quantity' => 1,
            'unit_amount_minor' => 100000,
            'amount_minor' => 100000,
            'service_starts_on' => now('Asia/Taipei')->toDateString(),
            'service_ends_on' => now('Asia/Taipei')->addMonth()->subDay()->toDateString(),
        ];
    }
}
