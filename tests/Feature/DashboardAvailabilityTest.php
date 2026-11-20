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

class DashboardAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_aggregates_availability_by_observed_time_and_excludes_maintenance_and_paused_monitors(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        $availableMonitor = $this->createMonitor('available', 300);
        $unavailableMonitor = $this->createMonitor('unavailable', 60);
        $pausedMonitor = $this->createMonitor('paused', 900, false);
        $this->createScheduledRun($availableMonitor, now()->subMinutes(5), 200, 300);
        $this->createScheduledRun($unavailableMonitor, now()->subMinute(), 500, 60);
        $this->createScheduledRun($pausedMonitor, now()->subMinutes(2), 200, 900);
        MaintenanceWindow::factory()->for($availableMonitor)->create([
            'starts_at' => now()->subMinutes(2),
            'ends_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('整體監測品質')
            ->assertSee('80.00%')
            ->assertSee('0.17%');

        $fleetAvailability = $response->viewData('fleetAvailability');
        $dailyStatistic = collect($fleetAvailability['statistics'])->firstWhere('label', '24 小時');

        $this->assertSame(2, $fleetAvailability['monitor_count']);
        $this->assertSame(80.0, $dailyStatistic['availability_rate']);
        $this->assertSame(0.17, $dailyStatistic['coverage_rate']);
        $this->assertSame(4.0, $dailyStatistic['up_minutes']);
        $this->assertSame(1.0, $dailyStatistic['unavailable_minutes']);
        $this->assertSame(5.0, $dailyStatistic['observed_minutes']);
        $this->assertSame(2879.0, $dailyStatistic['eligible_minutes']);
        $this->assertSame(1.0, $dailyStatistic['maintenance_excluded_minutes']);
    }

    public function test_dashboard_shows_zero_coverage_when_enabled_monitors_have_no_completed_scheduled_checks(): void
    {
        $this->createMonitor('no-runs');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('整體監測品質')
            ->assertSee('0.00%')
            ->assertSee('觀測／應監測分鐘')
            ->assertSee('1,440.0');

        $dailyStatistic = collect($response->viewData('fleetAvailability')['statistics'])->firstWhere('label', '24 小時');

        $this->assertNull($dailyStatistic['availability_rate']);
        $this->assertSame(0.0, $dailyStatistic['coverage_rate']);
        $this->assertSame(0.0, $dailyStatistic['observed_minutes']);
    }

    public function test_dashboard_explains_that_availability_is_not_applicable_when_every_monitor_is_paused(): void
    {
        $this->createMonitor('all-paused', 900, false);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('沒有啟用中的監測項目');

        $fleetAvailability = $response->viewData('fleetAvailability');
        $dailyStatistic = collect($fleetAvailability['statistics'])->firstWhere('label', '24 小時');

        $this->assertSame(0, $fleetAvailability['monitor_count']);
        $this->assertNull($dailyStatistic['availability_rate']);
        $this->assertNull($dailyStatistic['coverage_rate']);
    }

    public function test_dashboard_shows_coverage_as_not_applicable_when_maintenance_excludes_the_entire_period(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $monitor = $this->createMonitor('full-maintenance');
        MaintenanceWindow::factory()->for($monitor)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('整體監測品質')
            ->assertSee('維護排除分鐘')
            ->assertSee('1,440.0');

        $dailyStatistic = collect($response->viewData('fleetAvailability')['statistics'])->firstWhere('label', '24 小時');

        $this->assertNull($dailyStatistic['availability_rate']);
        $this->assertNull($dailyStatistic['coverage_rate']);
        $this->assertSame(0.0, $dailyStatistic['eligible_minutes']);
        $this->assertSame(0.0, $dailyStatistic['unavailable_minutes']);
        $this->assertSame(1440.0, $dailyStatistic['maintenance_excluded_minutes']);
    }

    public function test_dashboard_compares_fleet_availability_across_24_hours_7_days_and_30_days(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        Config::set('monitoring.stale_grace_seconds', 0);
        $monitor = $this->createMonitor('availability-windows', 300);
        $this->createScheduledRun($monitor, now()->subDays(20), 503, 300);
        $this->createScheduledRun($monitor, now()->subDays(15), 503, 300);
        $this->createScheduledRun($monitor, now()->subDays(10), 503, 300);
        $this->createScheduledRun($monitor, now()->subDays(2), 200, 300);
        $this->createScheduledRun($monitor, now()->subMinutes(30), 503, 300);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('最近 24 小時、7 天與 30 天')
            ->assertSee('估算不可用分鐘')
            ->assertSee('觀測／應監測分鐘');

        $statistics = collect($response->viewData('fleetAvailability')['statistics'])->keyBy('label');

        $this->assertSame(['24 小時', '7 天', '30 天'], $statistics->keys()->all());
        $this->assertSame(0.0, $statistics['24 小時']['availability_rate']);
        $this->assertSame(5.0, $statistics['24 小時']['unavailable_minutes']);
        $this->assertSame(50.0, $statistics['7 天']['availability_rate']);
        $this->assertSame(5.0, $statistics['7 天']['unavailable_minutes']);
        $this->assertSame(20.0, $statistics['30 天']['availability_rate']);
        $this->assertSame(20.0, $statistics['30 天']['unavailable_minutes']);
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
        ]);
    }

    private function createMonitor(string $key, int $intervalSeconds = 900, bool $enabled = true): Monitor
    {
        $url = 'https://'.$key.'.example.test/';

        return Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'interval_seconds' => $intervalSeconds,
            'enabled' => $enabled,
        ]);
    }
}
