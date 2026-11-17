<?php

namespace Database\Factories;

use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Monitor>
 */
class MonitorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'url' => 'https://example.com/landing',
            'url_hash' => Monitor::fingerprintUrl('https://example.com/landing'),
            'label' => null,
            'enabled' => true,
            'config_version' => 1,
            'allowed_redirect_hosts' => [],
            'expected_text' => null,
            'interval_seconds' => 900,
            'next_check_at' => now(),
            'last_checked_at' => null,
        ];
    }
}
