<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientMonthlyBudget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientMonthlyBudget>
 */
class ClientMonthlyBudgetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'month' => now('Asia/Taipei')->format('Y-m'),
            'currency_code' => 'TWD',
            'total_budget' => '30000.00',
            'google_ads_budget' => null,
            'meta_marketing_budget' => null,
            'note' => null,
        ];
    }
}
