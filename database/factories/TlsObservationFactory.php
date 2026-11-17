<?php

namespace Database\Factories;

use App\Models\Monitor;
use App\Models\TlsObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TlsObservation>
 */
class TlsObservationFactory extends Factory
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
            'expires_at' => now()->addDays(90),
            'error_type' => null,
        ];
    }
}
