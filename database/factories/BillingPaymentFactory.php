<?php

namespace Database\Factories;

use App\Models\BillingOrder;
use App\Models\BillingPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingPayment>
 */
class BillingPaymentFactory extends Factory
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
            'method' => BillingPayment::METHOD_BANK_TRANSFER,
            'amount_minor' => 100000,
            'currency_code' => 'TWD',
            'received_at' => now(),
            'reference' => null,
        ];
    }
}
