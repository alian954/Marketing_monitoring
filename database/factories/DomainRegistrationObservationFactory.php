<?php

namespace Database\Factories;

use App\Models\DomainRegistrationObservation;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DomainRegistrationObservation>
 */
class DomainRegistrationObservationFactory extends Factory
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
            'domain_name' => fake()->domainName(),
            'expires_at' => now()->addDays(90),
            'error_type' => null,
        ];
    }
}
