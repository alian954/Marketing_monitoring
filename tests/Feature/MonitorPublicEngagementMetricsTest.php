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

    public function test_dashboard_summarizes_latest_public_engagement_by_tag_with_metric_coverage(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $summerPost = $this->createSocialMonitor('CAMPAIGN001', 'summer, brand');
        $summerPostWithoutLikes = $this->createSocialMonitor('CAMPAIGN002', 'summer');
        $untaggedPost = $this->createSocialMonitor('CAMPAIGN003');
        $websiteUrl = 'https://example.test/summer-campaign';
        Monitor::factory()->create([
            'url' => $websiteUrl,
            'url_hash' => Monitor::fingerprintUrl($websiteUrl),
            'label' => 'summer',
        ]);

        $this->createMetricRun($summerPost, now()->subHour(), ['views' => 9000, 'likes' => 100, 'comments' => 80, 'shares' => 50, 'source' => 'json_ld']);
        $this->createMetricRun($summerPost, now(), ['views' => 1500, 'likes' => 30, 'comments' => 8, 'shares' => 3, 'source' => 'json_ld']);
        $this->createMetricRun($summerPostWithoutLikes, now(), ['views' => 500, 'likes' => null, 'comments' => 4, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($untaggedPost, now(), ['views' => null, 'likes' => 8, 'comments' => null, 'shares' => null, 'source' => 'json_ld']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('標籤成效比較')
            ->assertSee('跨標籤的數字會重複計入')
            ->assertSee(route('monitors.index', ['label' => 'summer']), false)
            ->assertSee('<th scope="col">留言</th>', false)
            ->assertSee('2,000')
            ->assertSee('(1/2 篇)')
            ->assertSee('未標籤');

        $summaries = collect($response->viewData('socialEngagementSummaries'))->keyBy('tag');

        $this->assertCount(3, $summaries);
        $this->assertSame(2, $summaries['summer']['monitor_count']);
        $this->assertSame(['total' => 2000, 'available' => 2], $summaries['summer']['metrics']['views']);
        $this->assertSame(['total' => 30, 'available' => 1], $summaries['summer']['metrics']['likes']);
        $this->assertSame(['total' => 12, 'available' => 2], $summaries['summer']['metrics']['comments']);
        $this->assertSame(['total' => 3, 'available' => 2], $summaries['summer']['metrics']['shares']);
        $this->assertSame(1, $summaries['brand']['monitor_count']);
        $this->assertSame(1, $summaries['未標籤']['monitor_count']);
        $this->assertSame(['total' => 0, 'available' => 0], $summaries['未標籤']['metrics']['views']);
        $this->assertSame(['total' => 8, 'available' => 1], $summaries['未標籤']['metrics']['likes']);
        $this->assertSame(now()->toIso8601String(), $summaries['summer']['latest_at']->toIso8601String());
    }

    public function test_social_engagement_csv_exports_latest_values_with_formula_protection(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $monitor = $this->createSocialMonitor('CSVPOST001', '=1+1,summer');
        $monitor->forceFill(['name' => '=SUM(1,1)'])->save();
        $monitorWithoutMetrics = $this->createSocialMonitor('CSVPOST002');
        $websiteUrl = 'https://example.test/not-a-social-post';
        Monitor::factory()->create([
            'url' => $websiteUrl,
            'url_hash' => Monitor::fingerprintUrl($websiteUrl),
        ]);
        $this->createMetricRun($monitor, now()->subHour(), ['views' => 900, 'likes' => 90, 'comments' => 9, 'shares' => 3, 'source' => 'json_ld']);
        $this->createMetricRun($monitor, now(), ['views' => 0, 'likes' => null, 'comments' => 5, 'shares' => 1, 'source' => 'json_ld']);

        $this->get(route('monitors.social-engagement.export'))->assertRedirect(route('login'));

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.social-engagement.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename=social-engagement-2026-11-22.csv');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame("'=SUM(1,1)", $rows[0]['monitor_name']);
        $this->assertSame('Instagram', $rows[0]['platform']);
        $this->assertSame("'=1+1, summer", $rows[0]['tags']);
        $this->assertSame('0', $rows[0]['public_views']);
        $this->assertSame('', $rows[0]['public_likes']);
        $this->assertSame('5', $rows[0]['public_comments']);
        $this->assertSame('1', $rows[0]['public_shares']);
        $this->assertSame('json_ld', $rows[0]['metrics_source']);
        $this->assertSame(now()->utc()->toIso8601String(), $rows[0]['collected_at_utc']);
        $this->assertSame($monitorWithoutMetrics->name, $rows[1]['monitor_name']);
        $this->assertSame('', $rows[1]['public_views']);
        $this->assertSame('', $rows[1]['collected_at_utc']);
    }

    private function createSocialMonitor(string $postId = 'POST123', ?string $label = null): Monitor
    {
        $url = 'https://www.instagram.com/reel/'.$postId.'/';

        return Monitor::factory()->create([
            'name' => 'Instagram campaign reel',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $label,
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

    /** @return list<array<string, string|null>> */
    private function csvRows(string $content): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $headers = fgetcsv($stream, null, ',', '"', '');
        $rows = [];

        if ($headers !== false) {
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $rows[] = array_combine($headers, $values);
            }
        }

        fclose($stream);

        return $rows;
    }
}
