<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionItem>
 */
class SubscriptionItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'subscription_id' => Subscription::factory(),
            'product_id' => $product = Product::factory(),
            'product_plan_id' => ProductPlan::factory()->for($product),
            'status' => 'active',
            'quantity' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(static function (SubscriptionItem $item): void {
            $item->organization_id = Subscription::query()
                ->findOrFail($item->subscription_id)
                ->organization_id;
        });
    }
}
