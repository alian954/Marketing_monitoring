<?php

namespace Database\Factories;

use App\Models\CheckRun;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CheckRun>
 */
class CheckRunFactory extends Factory
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
            'run_key' => hash('sha256', (string) Str::uuid()),
            'config_version' => 1,
            'config_snapshot' => json_encode(['name' => 'Example landing page', 'url' => 'https://example.com/landing', 'expected_text' => null, 'allowed_redirect_hosts' => []], JSON_THROW_ON_ERROR),
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
            'normal_status' => 200,
            'adsbot_status' => 200,
            'error_type' => null,
            'error_detail' => null,
            'adsbot_error_type' => null,
            'elapsed_ms' => 45,
            'final_url' => 'https://example.com/landing',
            'redirects' => [],
            'resolved_public_ips' => ['93.184.216.34'],
            'expected_text_found' => null,
            'tls_expires_at' => now()->addDays(90),
        ];
    }
}
