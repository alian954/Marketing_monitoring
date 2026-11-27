<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientAdPlatformDailyMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientAdPlatformDailyMetric>
 */
class ClientAdPlatformDailyMetricFactory extends Factory
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
            'platform' => ClientAdPlatformDailyMetric::PLATFORM_TIKTOK,
            'metric_on' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'campaign_name' => fake()->words(3, true),
            'currency_code' => 'TWD',
            'spend' => (string) fake()->numberBetween(100, 5000),
            'impressions' => fake()->numberBetween(1000, 50000),
            'clicks' => fake()->numberBetween(10, 1000),
            'conversions' => (string) fake()->numberBetween(0, 20),
            'conversion_value' => (string) fake()->numberBetween(0, 20000),
        ];
    }
}
