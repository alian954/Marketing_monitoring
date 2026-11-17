<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
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
            'config_version' => 1,
            'rule_key' => 'http',
            'severity' => 'critical',
            'state' => Incident::STATE_OPEN,
            'classification' => null,
            'active_key' => hash('sha256', (string) Str::uuid()),
            'note' => null,
            'failure_count' => 2,
            'recovery_count' => 0,
            'confirm_after' => null,
            'opened_at' => now(),
            'last_seen_at' => now(),
            'resolved_at' => null,
        ];
    }
}
