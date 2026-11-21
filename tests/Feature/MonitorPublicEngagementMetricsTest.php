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

    public function test_dashboard_distinguishes_public_engagement_capture_statuses(): void
    {
        $this->travelTo('2026-11-22 12:00:00');

        $restrictedPost = $this->createSocialMonitor('RESTRICTED001');
        CheckRun::factory()->for($restrictedPost)->create([
            'config_version' => $restrictedPost->config_version,
            'finished_at' => now(),
            'normal_status' => 403,
            'error_type' => 'social_access_required',
        ]);

        $postWithPreviousMetrics = $this->createSocialMonitor('PREVIOUS001');
        $this->createMetricRun($postWithPreviousMetrics, now()->subHour(), ['views' => 4800, 'likes' => 160, 'comments' => 13, 'shares' => 4, 'source' => 'json_ld']);
        CheckRun::factory()->for($postWithPreviousMetrics)->create([
            'config_version' => $postWithPreviousMetrics->config_version,
            'finished_at' => now(),
            'normal_status' => 200,
        ]);

        $postWithoutMetrics = $this->createSocialMonitor('UNREADABLE001');
        CheckRun::factory()->for($postWithoutMetrics)->create([
            'config_version' => $postWithoutMetrics->config_version,
            'finished_at' => now(),
            'normal_status' => 200,
        ]);

        $postWithCurrentMetrics = $this->createSocialMonitor('CURRENT001');
        $this->createMetricRun($postWithCurrentMetrics, now(), ['views' => 5320, 'likes' => 184, 'comments' => 19, 'shares' => 7, 'source' => 'json_ld']);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<th scope="col">擷取狀態</th>', false)
            ->assertSee('平台限制存取 · HTTP 403')
            ->assertSee('本次未讀到互動數，顯示前次資料')
            ->assertSee('HTTP 200 · 未辨識互動數')
            ->assertSee('已擷取')
            ->assertSee('4,800');
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
            ->assertSee('跨標籤可能重複計入')
            ->assertSee('觀看互動率＝（讚＋留言＋分享）總和÷觀看總和')
            ->assertSee(route('monitors.index', ['label' => 'summer']), false)
            ->assertSee('<th scope="col">留言</th>', false)
            ->assertSee('2,000')
            ->assertSee('(1/2 篇)')
            ->assertSee('觀看互動率')
            ->assertSee('2.73%')
            ->assertSee('未標籤');

        $summaries = collect($response->viewData('socialEngagementSummaries'))->keyBy('tag');

        $this->assertCount(3, $summaries);
        $this->assertSame(2, $summaries['summer']['monitor_count']);
        $this->assertSame(['total' => 2000, 'available' => 2], $summaries['summer']['metrics']['views']);
        $this->assertSame(['total' => 30, 'available' => 1], $summaries['summer']['metrics']['likes']);
        $this->assertSame(['total' => 12, 'available' => 2], $summaries['summer']['metrics']['comments']);
        $this->assertSame(['total' => 3, 'available' => 2], $summaries['summer']['metrics']['shares']);
        $this->assertSame(['interactions' => 41, 'views' => 1500, 'available' => 1], $summaries['summer']['engagement_rate']);
        $this->assertSame(1, $summaries['brand']['monitor_count']);
        $this->assertSame(1, $summaries['未標籤']['monitor_count']);
        $this->assertSame(['total' => 0, 'available' => 0], $summaries['未標籤']['metrics']['views']);
        $this->assertSame(['total' => 8, 'available' => 1], $summaries['未標籤']['metrics']['likes']);
        $this->assertSame(now()->toIso8601String(), $summaries['summer']['latest_at']->toIso8601String());
    }

    public function test_dashboard_summarizes_seven_day_metric_changes_by_tag_with_coverage(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $summerPost = $this->createSocialMonitor('WEEKLY001', 'summer, brand');
        $secondSummerPost = $this->createSocialMonitor('WEEKLY002', 'summer');
        $singleSnapshotPost = $this->createSocialMonitor('WEEKLY003', 'summer');
        $staleConfigPost = $this->createSocialMonitor('WEEKLY004', 'summer');

        $this->createMetricRun($summerPost, now()->subDays(8), ['views' => 10000, 'likes' => 1000, 'comments' => 100, 'shares' => 50, 'source' => 'json_ld']);
        $this->createMetricRun($summerPost, now()->subDays(6), ['views' => 1000, 'likes' => 100, 'comments' => 20, 'shares' => 10, 'source' => 'json_ld']);
        $this->createMetricRun($summerPost, now(), ['views' => 1500, 'likes' => 130, 'comments' => 29, 'shares' => 15, 'source' => 'json_ld']);
        $this->createMetricRun($secondSummerPost, now()->subDays(6), ['views' => 400, 'likes' => 50, 'comments' => 4, 'shares' => 2, 'source' => 'json_ld']);
        $this->createMetricRun($secondSummerPost, now(), ['views' => 600, 'likes' => 70, 'comments' => 9, 'shares' => 3, 'source' => 'json_ld']);
        $this->createMetricRun($singleSnapshotPost, now(), ['views' => 100, 'likes' => 20, 'comments' => 1, 'shares' => 0, 'source' => 'json_ld']);
        $staleConfigRun = $this->createMetricRun($staleConfigPost, now()->subDays(6), ['views' => 100, 'likes' => 20, 'comments' => 1, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($staleConfigPost, now(), ['views' => 200, 'likes' => 30, 'comments' => 2, 'shares' => 1, 'source' => 'json_ld']);
        $staleConfigRun->forceFill(['config_version' => $staleConfigPost->config_version - 1])->save();
        $staleConfigPost->checkRuns()->latest('id')->firstOrFail()->forceFill([
            'config_version' => $staleConfigPost->config_version - 1,
        ])->save();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('近 7 天互動變化')
            ->assertSee('只計有兩次讀值的貼文')
            ->assertSee('+700')
            ->assertSee('+50');

        $summaries = collect($response->viewData('socialEngagementChangeSummaries'))->keyBy('tag');

        $this->assertCount(2, $summaries);
        $this->assertSame(['change' => 700, 'available' => 2], $summaries['summer']['metrics']['views']);
        $this->assertSame(['change' => 50, 'available' => 2], $summaries['summer']['metrics']['likes']);
        $this->assertSame(['change' => 14, 'available' => 2], $summaries['summer']['metrics']['comments']);
        $this->assertSame(['change' => 6, 'available' => 2], $summaries['summer']['metrics']['shares']);
        $this->assertSame(['change' => 500, 'available' => 1], $summaries['brand']['metrics']['views']);
        $this->assertSame(['change' => 30, 'available' => 1], $summaries['brand']['metrics']['likes']);
    }

    public function test_social_engagement_csv_exports_latest_values_with_formula_protection(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $monitor = $this->createSocialMonitor('CSVPOST001', '=1+1,summer');
        $monitor->forceFill(['name' => '=SUM(1,1)'])->save();
        $monitorWithoutMetrics = $this->createSocialMonitor('CSVPOST002');
        $monitorWithRate = $this->createSocialMonitor('CSVPOST003');
        $websiteUrl = 'https://example.test/not-a-social-post';
        Monitor::factory()->create([
            'url' => $websiteUrl,
            'url_hash' => Monitor::fingerprintUrl($websiteUrl),
        ]);
        $this->createMetricRun($monitor, now()->subHour(), ['views' => 900, 'likes' => 90, 'comments' => 9, 'shares' => 3, 'source' => 'json_ld']);
        $this->createMetricRun($monitor, now(), ['views' => 0, 'likes' => null, 'comments' => 5, 'shares' => 1, 'source' => 'json_ld']);
        $this->createMetricRun($monitorWithRate, now(), ['views' => 10, 'likes' => 1, 'comments' => 2, 'shares' => 3, 'source' => 'json_ld']);

        $this->get(route('monitors.social-engagement.export'))->assertRedirect(route('login'));

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.social-engagement.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename=social-engagement-2026-11-22.csv');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertCount(3, $rows);
        $this->assertSame("'=SUM(1,1)", $rows[0]['monitor_name']);
        $this->assertSame('Instagram', $rows[0]['platform']);
        $this->assertSame("'=1+1, summer", $rows[0]['tags']);
        $this->assertSame('0', $rows[0]['public_views']);
        $this->assertSame('', $rows[0]['public_likes']);
        $this->assertSame('5', $rows[0]['public_comments']);
        $this->assertSame('1', $rows[0]['public_shares']);
        $this->assertSame('', $rows[0]['public_engagement_rate_percent']);
        $this->assertSame('json_ld', $rows[0]['metrics_source']);
        $this->assertSame(now()->utc()->toIso8601String(), $rows[0]['collected_at_utc']);
        $this->assertSame($monitorWithoutMetrics->name, $rows[1]['monitor_name']);
        $this->assertSame('', $rows[1]['public_views']);
        $this->assertSame('', $rows[1]['public_engagement_rate_percent']);
        $this->assertSame('', $rows[1]['collected_at_utc']);
        $this->assertSame($monitorWithRate->name, $rows[2]['monitor_name']);
        $this->assertSame('60.00', $rows[2]['public_engagement_rate_percent']);
    }

    public function test_social_engagement_history_csv_limits_rows_to_recent_current_social_checks(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $monitor = $this->createSocialMonitor('HISTORY001', 'campaign');
        $staleConfigMonitor = $this->createSocialMonitor('HISTORY002', 'campaign');
        $oldMonitor = $this->createSocialMonitor('HISTORY003', 'campaign');
        $websiteUrl = 'https://example.test/history';
        $website = Monitor::factory()->create([
            'url' => $websiteUrl,
            'url_hash' => Monitor::fingerprintUrl($websiteUrl),
        ]);

        $earlierRun = $this->createMetricRun($monitor, now()->subDays(20), ['views' => 1000, 'likes' => 50, 'comments' => 5, 'shares' => 10, 'source' => 'json_ld']);
        $latestRun = $this->createMetricRun($monitor, now()->subDays(5), ['views' => 1500, 'likes' => 90, 'comments' => 12, 'shares' => 18, 'source' => 'json_ld']);
        $staleConfigRun = $this->createMetricRun($staleConfigMonitor, now()->subDay(), ['views' => 100, 'likes' => 10, 'comments' => 1, 'shares' => 2, 'source' => 'json_ld']);
        $staleConfigRun->forceFill(['config_version' => $staleConfigMonitor->config_version - 1])->save();
        $oldRun = $this->createMetricRun($oldMonitor, now()->subDays(31), ['views' => 100, 'likes' => 10, 'comments' => 1, 'shares' => 2, 'source' => 'json_ld']);
        $dailyRun = $this->createMetricRun($monitor, now()->subDay(), ['views' => 100, 'likes' => 10, 'comments' => 1, 'shares' => 2, 'source' => 'json_ld']);
        $dailyRun->forceFill(['source' => 'domain_daily'])->save();
        $this->createMetricRun($website, now()->subDay(), ['views' => 100, 'likes' => 10, 'comments' => 1, 'shares' => 2, 'source' => 'json_ld']);

        $this->get(route('monitors.social-engagement.export.history'))->assertRedirect(route('login'));

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.social-engagement.export.history'))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=social-engagement-history-2026-11-22.csv');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame((string) $earlierRun->id, $rows[0]['check_run_id']);
        $this->assertSame((string) $latestRun->id, $rows[1]['check_run_id']);
        $this->assertSame($monitor->name, $rows[0]['monitor_name']);
        $this->assertSame('campaign', $rows[1]['tags']);
        $this->assertSame('6.50', $rows[0]['public_engagement_rate_percent']);
        $this->assertSame('8.00', $rows[1]['public_engagement_rate_percent']);
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
