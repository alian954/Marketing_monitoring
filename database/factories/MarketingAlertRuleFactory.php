<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\MarketingAlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingAlertRule>
 */
class MarketingAlertRuleFactory extends Factory
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
            'metric' => MarketingAlertRule::METRIC_ROAS_BELOW,
            'threshold' => '1',
            'recipients' => fake()->safeEmail(),
            'webhook_url' => null,
            'webhook_channel' => null,
            'paused_at' => null,
        ];
    }

    public function metric(string $metric, ?string $threshold = null): static
    {
        return $this->state(fn (): array => ['metric' => $metric, 'threshold' => $threshold]);
    }
}
