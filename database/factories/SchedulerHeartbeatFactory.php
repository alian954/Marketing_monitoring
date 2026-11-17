<?php

namespace Database\Factories;

use App\Models\SchedulerHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchedulerHeartbeat>
 */
class SchedulerHeartbeatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_key' => fake()->unique()->slug(2),
            'last_started_at' => now(),
            'last_succeeded_at' => now(),
            'last_error' => null,
        ];
    }
}
