<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeResult;
use App\Services\RunScheduler;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorPublicEngagementMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_social_check_stores_public_engagement_metrics(): void
    {
        Queue::fake([RunMonitorCheck::class]);
        $url = 'https://www.facebook.com/share/p/PUBLIC123/';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => 'Public campaign',
        ]);
        $run = app(RunScheduler::class)->enqueue($monitor, 'manual');
        $this->assertNotNull($run);
        $metrics = ['views' => 5320, 'likes' => 184, 'comments' => 19, 'shares' => 7, 'source' => 'json_ld'];
        $probe = $this->mock(ProbeClient::class);
        $probe->shouldReceive('inspect')->once()->andReturn(new ProbeResult(
            200,
            35,
            $url,
            [],
            ['93.184.216.34'],
            null,
            null,
            true,
            null,
            publicEngagementMetrics: $metrics,
        ));

        (new RunMonitorCheck($run->id))->handle(
            $probe,
            app(IncidentManager::class),
            app(RunScheduler::class),
        );

        $this->assertSame($metrics, $run->fresh()->response_public_engagement_metrics);
    }

    public function test_monitor_details_show_recent_interaction_values_and_change_history(): void
    {
        $monitor = $this->createSocialMonitor();
        $this->createMetricRun($monitor, now()->subHour(), ['views' => 4800, 'likes' => 160, 'comments' => 13, 'shares' => 4, 'source' => 'json_ld']);
        $this->createMetricRun($monitor, now(), ['views' => 5320, 'likes' => 184, 'comments' => 19, 'shares' => 7, 'source' => 'json_ld']);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('公開互動數')
            ->assertSee('近 30 天原始回應可辨識的觀看、讚、留言與分享數，並顯示相較上次可讀取數值的變化比例。')
            ->assertSee('5,320')
            ->assertSee('較前次 +520（+10.83%）')
            ->assertSee('較前次 +24（+15.00%）')
            ->assertSee('4,800');
    }

    public function test_engagement_percentage_handles_a_zero_baseline_without_dividing_by_zero(): void
    {
        $monitor = $this->createSocialMonitor();
        $this->createMetricRun($monitor, now()->subHour(), ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($monitor, now(), ['views' => 125, 'likes' => 3, 'comments' => 0, 'shares' => 0, 'source' => 'json_ld']);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('較前次 +125（前次為 0，無法計算比例）')
            ->assertSee('較前次 +3（前次為 0，無法計算比例）')
            ->assertSee('與前次相同（前次為 0，無法計算比例）');
    }

    public function test_missing_latest_metric_is_not_reported_as_missing_a_previous_value(): void
    {
        $monitor = $this->createSocialMonitor();
        $this->createMetricRun($monitor, now()->subHour(), ['views' => 520, 'likes' => 20, 'comments' => 3, 'shares' => 1, 'source' => 'json_ld']);
        $this->createMetricRun($monitor, now(), ['views' => null, 'likes' => 25, 'comments' => 4, 'shares' => 1, 'source' => 'json_ld']);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('本次未提供數值，無法比較')
            ->assertSee('較前次 +5（+25.00%）');
    }

    public function test_dashboard_shows_the_latest_public_interaction_snapshot(): void
    {
        $monitor = $this->createSocialMonitor();
        $this->createMetricRun($monitor, now(), ['views' => 5320, 'likes' => 184, 'comments' => 19, 'shares' => 7, 'source' => 'json_ld']);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('公開互動數')
            ->assertSee($monitor->name)
            ->assertSee('5,320')
            ->assertSee('184')
            ->assertSee('19')
            ->assertSee('7');
    }

    private function createSocialMonitor(): Monitor
    {
        $url = 'https://www.instagram.com/reel/POST123/';

        return Monitor::factory()->create([
            'name' => 'Instagram campaign reel',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'expected_text' => 'Campaign announcement',
        ]);
    }

    /** @param array{views: ?int, likes: ?int, comments: ?int, shares: ?int, source: string} $metrics */
    private function createMetricRun(Monitor $monitor, DateTimeInterface $finishedAt, array $metrics): CheckRun
    {
        return CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'config_snapshot' => json_encode([
                'name' => $monitor->name,
                'url' => $monitor->url,
                'accepted_status_codes' => [],
                'expected_text' => $monitor->expected_text,
                'expected_text_mode' => 'present',
                'response_time_threshold_ms' => null,
                'timeout_seconds' => 10,
                'confirmation_delay_seconds' => 60,
                'interval_seconds' => 900,
                'allowed_redirect_hosts' => [],
            ], JSON_THROW_ON_ERROR),
            'source' => 'scheduled',
            'status' => 'completed',
            'scheduled_at' => $finishedAt,
            'started_at' => $finishedAt,
            'finished_at' => $finishedAt,
            'normal_status' => 200,
            'response_public_engagement_metrics' => $metrics,
        ]);
    }
}
