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

class MonitorAvailabilityExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_export_the_same_availability_metrics_shown_on_monitor_details(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        $monitor = Monitor::factory()->create([
            'name' => '=Campaign status',
            'interval_seconds' => 300,
        ]);
        $this->createScheduledRun($monitor, now()->subMinutes(10), 200);
        $this->createScheduledRun($monitor, now()->subMinutes(5), 500);
        MaintenanceWindow::factory()->for($monitor)->create([
            'starts_at' => now()->subMinutes(4),
            'ends_at' => now()->subMinutes(3),
        ]);

        $user = User::factory()->create();
        $detailResponse = $this->actingAs($user)
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('匯出可用率 CSV')
            ->assertSee(route('monitors.availability.export', $monitor));
        $detailStat = collect($detailResponse->viewData('availabilityStats'))->firstWhere('label', '24 小時');

        $response = $this->get(route('monitors.availability.export', $monitor))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $rows = $this->csvRows($content);
        $firstPeriod = collect($rows)->firstWhere('period', '24 小時');

        $this->assertSame([
            'monitor_name',
            'period',
            'as_of_utc',
            'availability_percent',
            'coverage_percent',
            'estimated_up_minutes',
            'estimated_down_minutes',
            'observed_minutes',
            'eligible_minutes',
            'maintenance_excluded_minutes',
            'legacy_interval_fallback',
        ], array_keys($firstPeriod));
        $this->assertCount(3, $rows);
        $this->assertSame("'=Campaign status", $firstPeriod['monitor_name']);
        $this->assertSame('2026-11-19T12:00:00+00:00', $firstPeriod['as_of_utc']);
        $this->assertSame(number_format($detailStat['availability_rate'], 2, '.', ''), $firstPeriod['availability_percent']);
        $this->assertSame(number_format($detailStat['coverage_rate'], 2, '.', ''), $firstPeriod['coverage_percent']);
        $this->assertSame(number_format($detailStat['up_minutes'], 2, '.', ''), $firstPeriod['estimated_up_minutes']);
        $this->assertSame(number_format($detailStat['observed_minutes'] - $detailStat['up_minutes'], 2, '.', ''), $firstPeriod['estimated_down_minutes']);
        $this->assertSame(number_format($detailStat['observed_minutes'], 2, '.', ''), $firstPeriod['observed_minutes']);
        $this->assertSame(number_format($detailStat['eligible_minutes'], 2, '.', ''), $firstPeriod['eligible_minutes']);
        $this->assertSame(number_format($detailStat['maintenance_excluded_minutes'], 2, '.', ''), $firstPeriod['maintenance_excluded_minutes']);
        $this->assertSame('0', $firstPeriod['legacy_interval_fallback']);
    }

    public function test_availability_report_requires_authentication_and_exports_empty_periods(): void
    {
        $monitor = Monitor::factory()->create();

        $this->get(route('monitors.availability.export', $monitor))
            ->assertRedirect(route('login'));

        $content = $this->actingAs(User::factory()->create())
            ->get(route('monitors.availability.export', $monitor))
            ->assertOk()
            ->streamedContent();
        $rows = $this->csvRows($content);

        $this->assertCount(3, $rows);
        $this->assertSame('', $rows[0]['availability_percent']);
        $this->assertSame('0.00', $rows[0]['coverage_percent']);
        $this->assertSame('0.00', $rows[0]['observed_minutes']);
    }

    private function createScheduledRun(Monitor $monitor, Carbon $finishedAt, int $statusCode): CheckRun
    {
        return CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => json_encode([
                'accepted_status_codes' => [],
                'expected_text' => null,
                'expected_text_mode' => 'present',
                'interval_seconds' => 300,
            ], JSON_THROW_ON_ERROR),
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => $finishedAt,
            'finished_at' => $finishedAt,
            'normal_status' => $statusCode,
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
