<?php

namespace Database\Factories;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentEvent>
 */
class IncidentEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'check_run_id' => CheckRun::factory(),
            'event_type' => 'observed',
            'evidence' => ['status' => 500],
            'created_at' => now(),
        ];
    }
}
