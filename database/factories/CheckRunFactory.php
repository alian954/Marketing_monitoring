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
            'config_snapshot' => json_encode(['name' => 'Example landing page', 'url' => 'https://example.com/landing', 'accepted_status_codes' => [], 'expected_text' => null, 'expected_text_mode' => 'present', 'response_time_threshold_ms' => null, 'timeout_seconds' => 10, 'confirmation_delay_seconds' => (int) config('monitoring.confirmation_delay_seconds'), 'interval_seconds' => (int) config('monitoring.interval_seconds'), 'allowed_redirect_hosts' => []], JSON_THROW_ON_ERROR),
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
            'normal_elapsed_ms' => 35,
            'final_url' => 'https://example.com/landing',
            'redirects' => [],
            'resolved_public_ips' => ['93.184.216.34'],
            'monitor_host_ips' => ['93.184.216.34'],
            'expected_text_found' => null,
            'response_visible_text_changed' => null,
            'expected_response_header_found' => null,
            'response_robots_directives' => null,
            'tls_expires_at' => now()->addDays(90),
        ];
    }
}
