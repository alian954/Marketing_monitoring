<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\User;
use App\Services\RunScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_weights_availability_by_check_duration_and_excludes_maintenance(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        $monitor = Monitor::factory()->create(['interval_seconds' => 300]);
        $this->createScheduledRun($monitor, now()->subMinutes(10), 200);
        $this->createScheduledRun($monitor, now()->subMinutes(5), 500);
        MaintenanceWindow::factory()->for($monitor)->create([
            'starts_at' => now()->subMinutes(4),
            'ends_at' => now()->subMinutes(3),
        ]);
        MaintenanceWindow::factory()->for($monitor)->create([
            'starts_at' => now()->subWeek()->subMinutes(2),
            'ends_at' => now()->subWeek()->subMinute(),
            'repeat_weekly' => true,
            'repeat_until' => now()->addDay()->toDateString(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('估算時間可用率')
            ->assertSee('估算不可用')
            ->assertSee('維護時段不計入觀測')
            ->assertSee('62.50%')
            ->assertSee('0.56%');
        $dailyStat = collect($response->viewData('availabilityStats'))->firstWhere('label', '24 小時');

        $this->assertSame(62.5, $dailyStat['availability_rate']);
        $this->assertSame(3.0, $dailyStat['unavailable_minutes']);
        $this->assertSame(0.56, $dailyStat['coverage_rate']);
        $this->assertSame(8.0, $dailyStat['observed_minutes']);
        $this->assertSame(5.0, $dailyStat['up_minutes']);
        $this->assertSame(1438.0, $dailyStat['eligible_minutes']);
        $this->assertSame(2.0, $dailyStat['maintenance_excluded_minutes']);
    }

    public function test_detail_does_not_count_job_failures_or_stale_gaps_as_available_or_down(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        $monitor = Monitor::factory()->create(['interval_seconds' => 300]);
        $this->createScheduledRun($monitor, now()->subHour(), 200);
        $this->createScheduledRun($monitor, now()->subMinutes(5), null, 'failed', 'queue_job_failed');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();
        $dailyStat = collect($response->viewData('availabilityStats'))->firstWhere('label', '24 小時');

        $this->assertSame(100.0, $dailyStat['availability_rate']);
        $this->assertSame(0.35, $dailyStat['coverage_rate']);
        $this->assertSame(5.0, $dailyStat['observed_minutes']);
    }

    public function test_incomplete_content_check_is_excluded_from_availability_observations(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        $monitor = Monitor::factory()->create(['interval_seconds' => 300]);
        $this->createScheduledRun($monitor, now()->subMinutes(10), 200);
        $incompleteRun = CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => json_encode([
                'accepted_status_codes' => [],
                'expected_text' => 'Campaign headline',
                'expected_text_mode' => 'present',
                'interval_seconds' => 300,
            ], JSON_THROW_ON_ERROR),
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(5),
            'normal_status' => 200,
            'error_type' => 'body_too_large',
            'expected_text_found' => null,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();
        $dailyStat = collect($response->viewData('availabilityStats'))->firstWhere('label', '24 小時');

        $this->assertTrue($incompleteRun->hasIndeterminateContentMatch());
        $this->assertSame(100.0, $dailyStat['availability_rate']);
        $this->assertSame(0.35, $dailyStat['coverage_rate']);
        $this->assertSame(5.0, $dailyStat['observed_minutes']);
    }

    public function test_incomplete_response_size_check_is_excluded_from_availability_observations(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        $monitor = Monitor::factory()->create([
            'interval_seconds' => 300,
            'min_response_body_bytes' => 100,
            'max_response_body_bytes' => 500,
        ]);
        $snapshot = json_encode([
            'accepted_status_codes' => [],
            'expected_text' => null,
            'expected_text_mode' => 'present',
            'min_response_body_bytes' => 100,
            'max_response_body_bytes' => 500,
            'interval_seconds' => 300,
        ], JSON_THROW_ON_ERROR);
        $this->createScheduledRun($monitor, now()->subMinutes(10), 200)->forceFill([
            'config_snapshot' => $snapshot,
            'response_body_bytes' => 200,
        ])->save();
        $incompleteRun = $this->createScheduledRun($monitor, now()->subMinutes(5), 200)->forceFill([
            'config_snapshot' => $snapshot,
            'response_body_bytes' => null,
        ]);
        $incompleteRun->save();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();
        $dailyStat = collect($response->viewData('availabilityStats'))->firstWhere('label', '24 小時');

        $this->assertTrue($incompleteRun->fresh()->hasIndeterminateResponseSizeCheck());
        $this->assertSame(100.0, $dailyStat['availability_rate']);
        $this->assertSame(0.35, $dailyStat['coverage_rate']);
        $this->assertSame(5.0, $dailyStat['observed_minutes']);
    }

    public function test_detail_reports_unknown_availability_when_no_scheduled_check_has_finished(): void
    {
        $monitor = Monitor::factory()->create();
        CheckRun::factory()->for($monitor)->create([
            'status' => 'queued',
            'finished_at' => null,
        ]);
        CheckRun::factory()->for($monitor)->create([
            'source' => 'manual',
            'status' => 'completed',
            'finished_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('—');
        $dailyStat = collect($response->viewData('availabilityStats'))->firstWhere('label', '24 小時');

        $this->assertNull($dailyStat['availability_rate']);
        $this->assertSame(0.0, $dailyStat['coverage_rate']);
        $this->assertSame(0.0, $dailyStat['observed_minutes']);
    }

    public function test_detail_uses_the_current_interval_for_legacy_check_snapshots(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        $monitor = Monitor::factory()->create(['interval_seconds' => 300]);
        $this->createScheduledRun($monitor, now()->subMinutes(5), 200, intervalSeconds: null);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('部分舊檢查紀錄未保存當時頻率');
        $dailyStat = collect($response->viewData('availabilityStats'))->firstWhere('label', '24 小時');

        $this->assertTrue($dailyStat['legacy_interval_fallback']);
        $this->assertSame(5.0, $dailyStat['observed_minutes']);
    }

    public function test_scheduler_snapshots_the_interval_for_availability_calculation(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $monitor = Monitor::factory()->create(['interval_seconds' => 300]);

        $run = app(RunScheduler::class)->enqueue($monitor);
        $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(300, $snapshot['interval_seconds']);
    }

    public function test_availability_uses_the_interval_saved_with_each_check(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        $monitor = Monitor::factory()->create(['interval_seconds' => 300]);
        $this->createScheduledRun($monitor, now()->subMinutes(10), 200, intervalSeconds: 600);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk();
        $dailyStat = collect($response->viewData('availabilityStats'))->firstWhere('label', '24 小時');

        $this->assertSame(10.0, $dailyStat['observed_minutes']);
    }

    private function createScheduledRun(
        Monitor $monitor,
        Carbon $finishedAt,
        ?int $statusCode,
        string $status = 'completed',
        ?string $errorType = null,
        ?int $intervalSeconds = 300,
    ): CheckRun {
        $snapshot = [
            'accepted_status_codes' => [],
            'expected_text' => null,
            'expected_text_mode' => 'present',
        ];

        if ($intervalSeconds !== null) {
            $snapshot['interval_seconds'] = $intervalSeconds;
        }

        return CheckRun::factory()->for($monitor)->create([
            'config_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'source' => 'scheduled',
            'status' => $status,
            'scheduled_at' => $finishedAt,
            'finished_at' => $finishedAt,
            'normal_status' => $statusCode,
            'error_type' => $errorType,
        ]);
    }
}
