<?php

namespace Database\Factories;

use App\Models\BillingOrder;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillingOrder>
 */
class BillingOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'order_number' => 'ORD-'.now('Asia/Taipei')->format('Ymd').'-'.Str::upper(Str::random(8)),
            'status' => BillingOrder::STATUS_ISSUED,
            'currency_code' => 'TWD',
            'total_minor' => 100000,
            'issued_at' => now(),
        ];
    }
}
