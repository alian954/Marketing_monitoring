<?php

namespace Database\Factories;

use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceWindow>
 */
class MaintenanceWindowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDay()->startOfHour();

        return [
            'monitor_id' => Monitor::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'reason' => $this->faker->sentence(),
        ];
    }
}
