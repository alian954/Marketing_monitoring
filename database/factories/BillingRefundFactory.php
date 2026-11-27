<?php

namespace Database\Factories;

use App\Models\BillingOrder;
use App\Models\BillingPayment;
use App\Models\BillingRefund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingRefund>
 */
class BillingRefundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_order_id' => BillingOrder::factory()->state(['status' => BillingOrder::STATUS_PAID]),
            'organization_id' => static fn (array $attributes): int => (int) BillingOrder::query()
                ->whereKey($attributes['billing_order_id'])
                ->value('organization_id'),
            'method' => BillingPayment::METHOD_BANK_TRANSFER,
            'amount_minor' => 1000,
            'currency_code' => 'TWD',
            'refunded_at' => now(),
            'reason' => fake()->sentence(),
            'reference' => null,
        ];
    }
}
