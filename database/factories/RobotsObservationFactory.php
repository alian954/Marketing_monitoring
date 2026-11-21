<?php

namespace Database\Factories;

use App\Models\Monitor;
use App\Models\RobotsObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RobotsObservation>
 */
class RobotsObservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'observed_on' => today(),
            'config_version' => 1,
            'file_state' => 'available',
            'http_status' => 200,
            'error_type' => null,
            'policy_hash' => hash('sha256', 'robots-policy'),
            'crawler_decisions' => [
                'Googlebot' => ['allowed' => true, 'matching_rule' => null],
                'AdsBot-Google' => ['allowed' => true, 'matching_rule' => null],
                'FacebookExternalHit' => ['allowed' => true, 'matching_rule' => null],
                'Twitterbot' => ['allowed' => true, 'matching_rule' => null],
            ],
            'rule_count' => 0,
            'sitemap_count' => 0,
            'policy_changed' => null,
            'checked_at' => now(),
        ];
    }
}
