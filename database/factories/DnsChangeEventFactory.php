<?php

namespace Database\Factories;

use App\Models\CheckRun;
use App\Models\DnsChangeEvent;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsChangeEvent>
 */
class DnsChangeEventFactory extends Factory
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
            'check_run_id' => null,
            'previous_ips' => ['93.184.216.34'],
            'current_ips' => ['93.184.216.35'],
            'monitor_host_only' => true,
            'detected_at' => now(),
        ];
    }

    public function forCheckRun(CheckRun $checkRun): static
    {
        return $this->state([
            'monitor_id' => $checkRun->monitor_id,
            'check_run_id' => $checkRun->id,
        ]);
    }
}
