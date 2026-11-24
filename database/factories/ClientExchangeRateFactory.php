<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientExchangeRate>
 */
class ClientExchangeRateFactory extends Factory
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
            'source_currency_code' => 'USD',
            'reporting_currency_code' => 'TWD',
            'effective_on' => now()->toDateString(),
            'rate_to_reporting_currency' => '31.50000000',
        ];
    }
}
