<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FleetAvailabilityExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_export_availability_for_enabled_monitors(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);

        $availableMonitor = $this->createMonitor('=Core marketing site', 300);
        $this->createScheduledRun($availableMonitor, now()->subMinutes(5), 200, 300);
        MaintenanceWindow::factory()->for($availableMonitor)->create([
            'starts_at' => now()->subMinute(),
            'ends_at' => now(),
        ]);

        $unavailableMonitor = $this->createMonitor('Campaign landing page', 60);
        $this->createScheduledRun($unavailableMonitor, now()->subMinute(), 503, 60);

        $monitorWithoutSamples = $this->createMonitor('Newly enabled monitor');
        $pausedMonitor = $this->createMonitor('Paused monitor', 900, false);
        $this->createScheduledRun($pausedMonitor, now()->subMinute(), 200, 900);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('匯出全部啟用項目可用率 CSV')
            ->assertSee(route('monitors.availability.export.fleet'));

        $response = $this->get(route('monitors.availability.export.fleet'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename=fleet-availability-report.csv');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertCount(9, $rows);
        $this->assertSame([
            'monitor_id',
            'monitor_name',
            'period',
            'as_of_utc',
            'availability_percent',
            'slo_target_percent',
            'slo_allowed_downtime_minutes',
            'slo_observed_downtime_minutes',
            'slo_remaining_error_budget_minutes',
            'slo_error_budget_used_percent',
            'coverage_percent',
            'estimated_up_minutes',
            'estimated_down_minutes',
            'observed_minutes',
            'eligible_minutes',
            'maintenance_excluded_minutes',
            'legacy_interval_fallback',
        ], array_keys($rows[0]));

        $availableRows = collect($rows)->where('monitor_id', (string) $availableMonitor->id)->keyBy('period');
        $unavailableRows = collect($rows)->where('monitor_id', (string) $unavailableMonitor->id)->keyBy('period');
        $emptyRows = collect($rows)->where('monitor_id', (string) $monitorWithoutSamples->id)->keyBy('period');

        $this->assertCount(3, $availableRows);
        $this->assertSame("'=Core marketing site", $availableRows['24 小時']['monitor_name']);
        $this->assertSame('2026-11-21T12:00:00+00:00', $availableRows['24 小時']['as_of_utc']);
        $this->assertSame('100.00', $availableRows['24 小時']['availability_percent']);
        $this->assertSame('99.90', $availableRows['24 小時']['slo_target_percent']);
        $this->assertSame('0.28', $availableRows['24 小時']['coverage_percent']);
        $this->assertSame('4.00', $availableRows['24 小時']['estimated_up_minutes']);
        $this->assertSame('0.00', $availableRows['24 小時']['estimated_down_minutes']);
        $this->assertSame('4.00', $availableRows['24 小時']['observed_minutes']);
        $this->assertSame('1.00', $availableRows['24 小時']['maintenance_excluded_minutes']);
        $this->assertSame('0', $availableRows['24 小時']['legacy_interval_fallback']);

        $this->assertSame('0.00', $unavailableRows['24 小時']['availability_percent']);
        $this->assertSame('1.00', $unavailableRows['24 小時']['estimated_down_minutes']);
        $this->assertSame('0.44', $unavailableRows['24 小時']['slo_remaining_error_budget_minutes']);
        $this->assertSame('', $emptyRows['24 小時']['availability_percent']);
        $this->assertSame('', $emptyRows['24 小時']['slo_observed_downtime_minutes']);
        $this->assertSame('0.00', $emptyRows['24 小時']['coverage_percent']);
        $this->assertSame('0.00', $emptyRows['24 小時']['observed_minutes']);
        $this->assertNotContains((string) $pausedMonitor->id, array_column($rows, 'monitor_id'));
    }

    public function test_fleet_availability_export_requires_authentication(): void
    {
        $this->get(route('monitors.availability.export.fleet'))
            ->assertRedirect(route('login'));
    }

    public function test_fleet_availability_export_returns_only_headers_when_all_monitors_are_paused(): void
    {
        $this->createMonitor('Paused monitor', 900, false);

        $content = $this->actingAs(User::factory()->create())
            ->get(route('monitors.availability.export.fleet'))
            ->assertOk()
            ->streamedContent();
        $header = str_getcsv(trim($content));

        $this->assertSame([
            'monitor_id',
            'monitor_name',
            'period',
            'as_of_utc',
            'availability_percent',
            'slo_target_percent',
            'slo_allowed_downtime_minutes',
            'slo_observed_downtime_minutes',
            'slo_remaining_error_budget_minutes',
            'slo_error_budget_used_percent',
            'coverage_percent',
            'estimated_up_minutes',
            'estimated_down_minutes',
            'observed_minutes',
            'eligible_minutes',
            'maintenance_excluded_minutes',
            'legacy_interval_fallback',
        ], $header);
    }

    public function test_fleet_availability_export_streams_monitors_across_batch_boundaries(): void
    {
        for ($monitorNumber = 1; $monitorNumber <= 501; $monitorNumber++) {
            $this->createMonitor(sprintf('Monitor %03d', $monitorNumber));
        }

        $content = $this->actingAs(User::factory()->create())
            ->get(route('monitors.availability.export.fleet'))
            ->assertOk()
            ->streamedContent();
        $rows = $this->csvRows($content);

        $this->assertCount(501 * 3, $rows);
        $this->assertCount(501, array_unique(array_column($rows, 'monitor_id')));
        $this->assertSame('Monitor 001', $rows[0]['monitor_name']);
        $this->assertSame('Monitor 501', $rows[array_key_last($rows)]['monitor_name']);
    }

    private function createMonitor(string $name, int $intervalSeconds = 900, bool $enabled = true): Monitor
    {
        $url = 'https://'.strtolower(str_replace(' ', '-', ltrim($name, '='))).'.example.test/';

        return Monitor::factory()->create([
            'name' => $name,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'interval_seconds' => $intervalSeconds,
            'enabled' => $enabled,
        ]);
    }

    private function createScheduledRun(Monitor $monitor, Carbon $finishedAt, int $normalStatus, int $intervalSeconds): CheckRun
    {
        return CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => json_encode([
                'accepted_status_codes' => [],
                'expected_text' => null,
                'expected_text_mode' => 'present',
                'interval_seconds' => $intervalSeconds,
            ], JSON_THROW_ON_ERROR),
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => $finishedAt,
            'finished_at' => $finishedAt,
            'normal_status' => $normalStatus,
            'error_type' => null,
        ]);
    }

    /** @return list<array<string, string>> */
    private function csvRows(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $headers = str_getcsv(array_shift($lines));
        $rows = array_map(static fn (string $line): array => str_getcsv($line), $lines);

        return array_map(static fn (array $row): array => array_combine($headers, $row), $rows);
    }
}
