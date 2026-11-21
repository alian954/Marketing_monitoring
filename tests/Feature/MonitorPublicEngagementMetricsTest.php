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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
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
            ->assertSee('期間淨變化')
            ->assertSee('5,320')
            ->assertSee('+520')
            ->assertSee('10.83%')
            ->assertSee('較前次 +520（+10.83%）')
            ->assertSee('較前次 +24（+15.00%）')
            ->assertSee('4,800');
    }

    public function test_monitor_engagement_history_period_can_be_selected_and_exported_for_one_post(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Config::set('monitoring.retention_days', 90);

        $monitor = $this->createSocialMonitor('PERIOD001', 'campaign');
        $otherMonitor = $this->createSocialMonitor('PERIOD002', 'campaign');
        $this->createMetricRun($monitor, now()->subDays(45), ['views' => 4500, 'likes' => 45, 'comments' => 4, 'shares' => 1, 'source' => 'json_ld']);
        $this->createMetricRun($monitor, now()->subDays(20), ['views' => 2000, 'likes' => 20, 'comments' => 2, 'shares' => 1, 'source' => 'json_ld']);
        $recentRun = $this->createMetricRun($monitor, now()->subDays(5), ['views' => 500, 'likes' => 5, 'comments' => 1, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($otherMonitor, now()->subDay(), ['views' => 1000, 'likes' => 10, 'comments' => 2, 'shares' => 1, 'source' => 'json_ld']);

        $user = User::factory()->create();
        $detailResponse = $this->actingAs($user)
            ->get(route('monitors.show', ['monitor' => $monitor, 'engagement_period' => 7]))
            ->assertOk()
            ->assertSee('公開互動數歷史期間')
            ->assertSee(route('monitors.social-engagement.export.monitor-history', ['monitor' => $monitor, 'engagement_period' => 7]), false);

        $this->assertSame(7, $detailResponse->viewData('publicEngagementHistoryPeriodDays'));
        $this->assertSame([$recentRun->id], $detailResponse->viewData('publicEngagementRows')->pluck('run.id')->all());
        $this->assertNull($detailResponse->viewData('publicEngagementPeriodChanges')['views']['change']);

        $csvResponse = $this->actingAs($user)
            ->get(route('monitors.social-engagement.export.monitor-history', ['monitor' => $monitor, 'engagement_period' => 7]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=social-engagement-history-monitor-'.$monitor->id.'-7-days-2026-11-22.csv');
        $rows = $this->csvRows($csvResponse->streamedContent());

        $this->assertCount(1, $rows);
        $this->assertSame((string) $recentRun->id, $rows[0]['check_run_id']);
        $this->assertSame($monitor->name, $rows[0]['monitor_name']);

        $fallbackResponse = $this->actingAs($user)
            ->get(route('monitors.show', ['monitor' => $monitor, 'engagement_period' => 14]))
            ->assertOk();

        $this->assertSame(30, $fallbackResponse->viewData('publicEngagementHistoryPeriodDays'));
        $this->assertCount(2, $fallbackResponse->viewData('publicEngagementRows'));
        $fallbackPeriodChanges = $fallbackResponse->viewData('publicEngagementPeriodChanges');
        $this->assertSame(-1500, $fallbackPeriodChanges['views']['change']);
        $this->assertSame(-75.0, $fallbackPeriodChanges['views']['change_percentage']);
    }

    public function test_engagement_percentage_handles_a_zero_baseline_without_dividing_by_zero(): void
    {
        $monitor = $this->createSocialMonitor();
        $this->createMetricRun($monitor, now()->subHour(), ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($monitor, now(), ['views' => 125, 'likes' => 3, 'comments' => 0, 'shares' => 0, 'source' => 'json_ld']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.show', $monitor))
            ->assertOk()
            ->assertSee('較前次 +125（前次為 0，無法計算比例）')
            ->assertSee('較前次 +3（前次為 0，無法計算比例）')
            ->assertSee('與前次相同（前次為 0，無法計算比例）');

        $periodChanges = $response->viewData('publicEngagementPeriodChanges');
        $this->assertSame(125, $periodChanges['views']['change']);
        $this->assertNull($periodChanges['views']['change_percentage']);
        $this->assertTrue($periodChanges['views']['start_value_was_zero']);
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

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('公開互動數')
            ->assertSee($monitor->name)
            ->assertSee('5,320')
            ->assertSee('184')
            ->assertSee('19')
            ->assertSee('7')
            ->assertSee('210')
            ->assertSee('3.95%');

        $monitorRow = $response->viewData('socialEngagementMonitors')->firstWhere('id', $monitor->id);
        $this->assertSame(210, $monitorRow?->getAttribute('social_engagement_interaction_total'));
        $this->assertSame(3.95, $monitorRow?->getAttribute('social_engagement_rate_percent'));
    }

    public function test_dashboard_hides_post_interaction_rates_when_counts_are_incomplete_or_views_are_zero(): void
    {
        $postWithMissingCount = $this->createSocialMonitor('PARTIAL001');
        $this->createMetricRun($postWithMissingCount, now(), ['views' => 100, 'likes' => 10, 'comments' => null, 'shares' => 2, 'source' => 'json_ld']);

        $postWithZeroViews = $this->createSocialMonitor('ZEROVIEWS001');
        $this->createMetricRun($postWithZeroViews, now(), ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'source' => 'json_ld']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk();
        $monitorRows = $response->viewData('socialEngagementMonitors')->keyBy('id');

        $this->assertNull($monitorRows[$postWithMissingCount->id]->getAttribute('social_engagement_interaction_total'));
        $this->assertNull($monitorRows[$postWithMissingCount->id]->getAttribute('social_engagement_rate_percent'));
        $this->assertSame(0, $monitorRows[$postWithZeroViews->id]->getAttribute('social_engagement_interaction_total'));
        $this->assertNull($monitorRows[$postWithZeroViews->id]->getAttribute('social_engagement_rate_percent'));
    }

    public function test_dashboard_paginates_all_social_posts_and_preserves_analytics_filters(): void
    {
        Config::set('monitoring.retention_days', 90);

        for ($postNumber = 1; $postNumber <= 51; $postNumber++) {
            $this->createSocialMonitor('PAGE'.str_pad((string) $postNumber, 3, '0', STR_PAD_LEFT));
        }

        $user = User::factory()->create();
        $firstPageResponse = $this->actingAs($user)
            ->get(route('dashboard', [
                'engagement_platform' => 'instagram',
                'engagement_period' => 30,
            ]))
            ->assertOk()
            ->assertSee('顯示第 1–50 筆，共 51 筆')
            ->assertSee('engagement_page=2', false)
            ->assertSee('engagement_platform=instagram', false)
            ->assertSee('engagement_period=30', false);
        $firstPage = $firstPageResponse->viewData('socialEngagementMonitors');

        $this->assertSame(51, $firstPage->total());
        $this->assertCount(50, $firstPage->getCollection());

        $secondPageResponse = $this->actingAs($user)
            ->get(route('dashboard', [
                'engagement_platform' => 'instagram',
                'engagement_period' => 30,
                'engagement_page' => 2,
            ]))
            ->assertOk()
            ->assertSee('顯示第 51–51 筆，共 51 筆');
        $secondPage = $secondPageResponse->viewData('socialEngagementMonitors');

        $this->assertCount(1, $secondPage->getCollection());
        $this->assertSame(2, $secondPage->currentPage());
        $this->assertSame(51, $secondPage->total());
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
            ->assertSee('engagement_tag=tag%3Asummer', false)
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
        $this->assertSame(41, $summaries['summer']['interaction_total']);
        $this->assertSame(1, $summaries['summer']['interaction_count']);
        $this->assertSame(41.0, $summaries['summer']['average_interactions_per_post']);
        $this->assertSame(1, $summaries['brand']['monitor_count']);
        $this->assertSame(1, $summaries['未標籤']['monitor_count']);
        $this->assertSame(['total' => 0, 'available' => 0], $summaries['未標籤']['metrics']['views']);
        $this->assertSame(['total' => 8, 'available' => 1], $summaries['未標籤']['metrics']['likes']);
        $this->assertSame(now()->toIso8601String(), $summaries['summer']['latest_at']->toIso8601String());
    }

    public function test_dashboard_sorts_tag_summaries_by_weighted_view_engagement_rate(): void
    {
        $highRatePost = $this->createSocialMonitor('RATEHIGH001', 'high rate');
        $middleRatePost = $this->createSocialMonitor('RATEMID001', 'middle rate');
        $lowRatePost = $this->createSocialMonitor('RATELOW001', 'low rate');
        $incompletePost = $this->createSocialMonitor('RATEEMPTY001', 'unavailable rate');

        $this->createMetricRun($highRatePost, now(), ['views' => 100, 'likes' => 15, 'comments' => 5, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($middleRatePost, now(), ['views' => 1000, 'likes' => 30, 'comments' => 15, 'shares' => 5, 'source' => 'json_ld']);
        $this->createMetricRun($lowRatePost, now(), ['views' => 1000, 'likes' => 8, 'comments' => 1, 'shares' => 1, 'source' => 'json_ld']);
        $this->createMetricRun($incompletePost, now(), ['views' => 500, 'likes' => 30, 'comments' => null, 'shares' => 2, 'source' => 'json_ld']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', [
                'engagement_platform' => 'instagram',
                'engagement_period' => 30,
                'engagement_tag_sort' => 'engagement_rate',
            ]))
            ->assertOk()
            ->assertSee('標籤成效排序')
            ->assertSee('engagement_tag_sort=engagement_rate', false)
            ->assertSee('engagement_platform=instagram', false)
            ->assertSee('engagement_period=30', false);

        $this->assertSame('engagement_rate', $response->viewData('socialEngagementTagSort'));
        $this->assertSame(
            ['high rate', 'middle rate', 'low rate', 'unavailable rate'],
            array_column($response->viewData('socialEngagementSummaries'), 'tag'),
        );

        $invalidSortResponse = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_tag_sort' => 'unknown']))
            ->assertOk();

        $this->assertSame('tag', $invalidSortResponse->viewData('socialEngagementTagSort'));
        $this->assertSame(
            ['high rate', 'low rate', 'middle rate', 'unavailable rate'],
            array_column($invalidSortResponse->viewData('socialEngagementSummaries'), 'tag'),
        );
    }

    public function test_dashboard_sorts_tag_summaries_by_average_interactions_per_complete_post(): void
    {
        $summerFirstPost = $this->createSocialMonitor('AVERAGESUM001', 'summer');
        $summerSecondPost = $this->createSocialMonitor('AVERAGESUM002', 'summer');
        $winterPost = $this->createSocialMonitor('AVERAGEWIN001', 'winter');
        $incompletePost = $this->createSocialMonitor('AVERAGEINC001', 'unavailable');

        $this->createMetricRun($summerFirstPost, now(), ['views' => 500, 'likes' => 40, 'comments' => 10, 'shares' => 10, 'source' => 'json_ld']);
        $this->createMetricRun($summerSecondPost, now(), ['views' => null, 'likes' => 20, 'comments' => 5, 'shares' => 5, 'source' => 'json_ld']);
        $this->createMetricRun($winterPost, now(), ['views' => null, 'likes' => 20, 'comments' => 5, 'shares' => 5, 'source' => 'json_ld']);
        $this->createMetricRun($incompletePost, now(), ['views' => 500, 'likes' => 1000, 'comments' => null, 'shares' => 10, 'source' => 'json_ld']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_tag_sort' => 'average_interactions']))
            ->assertOk()
            ->assertSee('平均互動／篇')
            ->assertSee('平均互動／篇由高到低')
            ->assertSee('engagement_tag_sort=average_interactions', false);

        $summaries = collect($response->viewData('socialEngagementSummaries'))->keyBy('tag');

        $this->assertSame(['summer', 'winter', 'unavailable'], array_column($response->viewData('socialEngagementSummaries'), 'tag'));
        $this->assertSame(45.0, $summaries['summer']['average_interactions_per_post']);
        $this->assertSame(2, $summaries['summer']['interaction_count']);
        $this->assertSame(30.0, $summaries['winter']['average_interactions_per_post']);
        $this->assertNull($summaries['unavailable']['average_interactions_per_post']);
        $this->assertSame(0, $summaries['unavailable']['interaction_count']);
    }

    public function test_dashboard_ranks_complete_interactions_using_selected_platform_and_tag(): void
    {
        $facebookPost = $this->createSocialMonitorForPlatform('facebook', 'RANKFB001', 'summer');
        $instagramPost = $this->createSocialMonitorForPlatform('instagram', 'RANKIG001', 'summer');
        $threadsPost = $this->createSocialMonitorForPlatform('threads', 'RANKTH001', 'winter');
        $incompletePost = $this->createSocialMonitorForPlatform('facebook', 'RANKMISS001', 'summer');
        $thirdRankedPost = $this->createSocialMonitorForPlatform('facebook', 'RANKTHIRD001', 'summer');
        $fourthRankedPost = $this->createSocialMonitorForPlatform('facebook', 'RANKFOURTH001', 'summer');
        $fifthRankedPost = $this->createSocialMonitorForPlatform('facebook', 'RANKFIFTH001', 'summer');
        $sixthRankedPost = $this->createSocialMonitorForPlatform('facebook', 'RANKSIXTH001', 'summer');

        for ($index = 1; $index <= 51; $index++) {
            $decoyPost = $this->createSocialMonitorForPlatform('facebook', 'RANKDECOY'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'summer');
            $decoyPost->forceFill(['name' => 'A Ranked Out Decoy '.str_pad((string) $index, 3, '0', STR_PAD_LEFT)])->save();
        }

        $this->createMetricRun($facebookPost, now(), ['views' => 1000, 'likes' => 2, 'comments' => 1, 'shares' => 1, 'source' => 'json_ld']);
        $this->createMetricRun($instagramPost, now(), ['views' => 200, 'likes' => 10, 'comments' => 5, 'shares' => 5, 'source' => 'json_ld']);
        $this->createMetricRun($threadsPost, now(), ['views' => 500, 'likes' => 100, 'comments' => 10, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($incompletePost, now(), ['views' => 500, 'likes' => 40, 'comments' => null, 'shares' => 20, 'source' => 'json_ld']);
        $this->createMetricRun($thirdRankedPost, now(), ['views' => 1000, 'likes' => 3, 'comments' => 0, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($fourthRankedPost, now(), ['views' => 1000, 'likes' => 2, 'comments' => 0, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($fifthRankedPost, now(), ['views' => 1000, 'likes' => 1, 'comments' => 0, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($sixthRankedPost, now(), ['views' => 1000, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'source' => 'json_ld']);

        $user = User::factory()->create();
        $allPlatformsResponse = $this->actingAs($user)
            ->get(route('dashboard', ['engagement_tag' => 'tag:summer']))
            ->assertOk()
            ->assertSee('互動量排行')
            ->assertSee('10.00%')
            ->assertSee('0.40%');
        $topPosts = $allPlatformsResponse->viewData('socialEngagementTopPosts');
        $firstPageMonitorIds = $allPlatformsResponse->viewData('socialEngagementMonitors')->getCollection()->modelKeys();

        $this->assertCount(5, $topPosts);
        $this->assertSame([
            $instagramPost->id,
            $facebookPost->id,
            $thirdRankedPost->id,
            $fourthRankedPost->id,
            $fifthRankedPost->id,
        ], array_column($topPosts, 'id'));
        $this->assertNotContains($facebookPost->id, $firstPageMonitorIds);
        $this->assertNotContains($sixthRankedPost->id, array_column($topPosts, 'id'));
        $this->assertSame(20, $topPosts[0]['interaction_total']);
        $this->assertSame(4, $topPosts[1]['interaction_total']);

        $facebookOnlyResponse = $this->actingAs($user)
            ->get(route('dashboard', [
                'engagement_platform' => 'facebook',
                'engagement_tag' => 'tag:summer',
            ]))
            ->assertOk();

        $facebookOnlyTopPosts = $facebookOnlyResponse->viewData('socialEngagementTopPosts');
        $this->assertCount(5, $facebookOnlyTopPosts);
        $this->assertSame($facebookPost->id, $facebookOnlyTopPosts[0]['id']);
        $this->assertSame('Facebook', $facebookOnlyTopPosts[0]['platform']);
    }

    public function test_dashboard_compares_engagement_for_the_same_tag_across_platforms(): void
    {
        $facebookPost = $this->createSocialMonitorForPlatform('facebook', 'CROSSFB001', 'summer');
        $instagramPost = $this->createSocialMonitorForPlatform('instagram', 'CROSSIG001', 'summer');
        $threadsPost = $this->createSocialMonitorForPlatform('threads', 'CROSSTH001', 'summer');
        $dcardPost = $this->createSocialMonitorForPlatform('dcard', 'CROSSDC001', 'winter');

        $this->createMetricRun($facebookPost, now(), ['views' => 1000, 'likes' => 20, 'comments' => 4, 'shares' => 1, 'source' => 'json_ld']);
        $this->createMetricRun($instagramPost, now(), ['views' => 1000, 'likes' => 30, 'comments' => 10, 'shares' => 5, 'source' => 'json_ld']);
        $this->createMetricRun($threadsPost, now(), ['views' => null, 'likes' => 8, 'comments' => 2, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($dcardPost, now(), ['views' => 500, 'likes' => 10, 'comments' => 2, 'shares' => 3, 'source' => 'json_ld']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', [
                'engagement_platform' => 'facebook',
                'engagement_tag' => 'tag:summer',
            ]))
            ->assertOk()
            ->assertSee('活動標籤跨平台比較')
            ->assertSee('4.50%')
            ->assertSee('2.50%');

        $comparisonSummaries = $response->viewData('socialEngagementComparisonSummaries');

        $this->assertCount(1, $comparisonSummaries);
        $this->assertSame('summer', $comparisonSummaries[0]['tag']);
        $this->assertSame(25, $comparisonSummaries[0]['platforms']['facebook']['interaction_total']);
        $this->assertSame(25.0, $comparisonSummaries[0]['platforms']['facebook']['average_interactions_per_post']);
        $this->assertSame(['interactions' => 45, 'views' => 1000, 'available' => 1], $comparisonSummaries[0]['platforms']['instagram']['engagement_rate']);
        $this->assertSame(45.0, $comparisonSummaries[0]['platforms']['instagram']['average_interactions_per_post']);
        $this->assertSame(10, $comparisonSummaries[0]['platforms']['threads']['interaction_total']);
        $this->assertSame(10.0, $comparisonSummaries[0]['platforms']['threads']['average_interactions_per_post']);
        $this->assertSame(0, $comparisonSummaries[0]['platforms']['threads']['engagement_rate']['available']);
        $this->assertArrayNotHasKey('dcard', $comparisonSummaries[0]['platforms']);
    }

    public function test_dashboard_tag_filter_scopes_posts_summaries_and_engagement_csv_exports(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Config::set('monitoring.retention_days', 90);
        $facebookSummerPost = $this->createSocialMonitorForPlatform('facebook', 'TAGFB001', 'summer');
        $instagramSummerPost = $this->createSocialMonitor('TAGIG001', 'summer');
        $instagramWinterPost = $this->createSocialMonitor('TAGWINTER001', 'winter');
        $threadsUntaggedPost = $this->createSocialMonitorForPlatform('threads', 'TAGNONE001');

        foreach ([$facebookSummerPost, $instagramSummerPost, $instagramWinterPost, $threadsUntaggedPost] as $monitor) {
            $this->createMetricRun($monitor, now()->subDays(2), ['views' => 100, 'likes' => 10, 'comments' => 2, 'shares' => 1, 'source' => 'json_ld']);
            $this->createMetricRun($monitor, now(), ['views' => 150, 'likes' => 15, 'comments' => 3, 'shares' => 2, 'source' => 'json_ld']);
        }

        $user = User::factory()->create();
        $response = $this->actingAs($user)
            ->get(route('dashboard', [
                'engagement_platform' => 'all',
                'engagement_tag' => 'tag:summer',
                'engagement_period' => 7,
            ]))
            ->assertOk()
            ->assertSee('<option value="tag:summer" selected>summer</option>', false)
            ->assertSee('engagement_tag=tag%3Asummer', false);

        $this->assertEqualsCanonicalizing(
            [$facebookSummerPost->id, $instagramSummerPost->id],
            $response->viewData('socialEngagementMonitors')->getCollection()->modelKeys(),
        );
        $this->assertSame(['summer'], array_column($response->viewData('socialEngagementSummaries'), 'tag'));
        $this->assertSame(2, $response->viewData('socialEngagementSummaries')[0]['monitor_count']);

        $latestRows = $this->actingAs($user)
            ->get(route('monitors.social-engagement.export', [
                'engagement_platform' => 'all',
                'engagement_tag' => 'tag:summer',
            ]))
            ->assertOk()
            ->streamedContent();
        $latestRows = $this->csvRows($latestRows);
        $this->assertCount(2, $latestRows);
        $this->assertSame(['summer', 'summer'], array_column($latestRows, 'tags'));

        $historyRows = $this->actingAs($user)
            ->get(route('monitors.social-engagement.export.history', [
                'engagement_platform' => 'all',
                'engagement_tag' => 'tag:summer',
                'engagement_period' => 7,
            ]))
            ->assertOk()
            ->streamedContent();
        $historyRows = $this->csvRows($historyRows);
        $this->assertCount(4, $historyRows);
        $this->assertSame(['summer', 'summer', 'summer', 'summer'], array_column($historyRows, 'tags'));

        $facebookOnlyResponse = $this->actingAs($user)
            ->get(route('dashboard', [
                'engagement_platform' => 'facebook',
                'engagement_tag' => 'tag:summer',
                'engagement_period' => 7,
            ]))
            ->assertOk();
        $this->assertSame([$facebookSummerPost->id], $facebookOnlyResponse->viewData('socialEngagementMonitors')->getCollection()->modelKeys());

        $untaggedLatestRows = $this->actingAs($user)
            ->get(route('monitors.social-engagement.export', ['engagement_tag' => 'unlabeled']))
            ->assertOk()
            ->streamedContent();
        $untaggedLatestRows = $this->csvRows($untaggedLatestRows);
        $this->assertCount(1, $untaggedLatestRows);
        $this->assertSame('', $untaggedLatestRows[0]['tags']);

        $untaggedHistoryRows = $this->actingAs($user)
            ->get(route('monitors.social-engagement.export.history', [
                'engagement_tag' => 'unlabeled',
                'engagement_period' => 7,
            ]))
            ->assertOk()
            ->streamedContent();
        $untaggedHistoryRows = $this->csvRows($untaggedHistoryRows);
        $this->assertCount(2, $untaggedHistoryRows);
        $this->assertSame(['', ''], array_column($untaggedHistoryRows, 'tags'));

        $untaggedResponse = $this->actingAs($user)
            ->get(route('dashboard', ['engagement_tag' => 'unlabeled']))
            ->assertOk()
            ->assertSee('<option value="unlabeled" selected>未標籤</option>', false);

        $this->assertSame([$threadsUntaggedPost->id], $untaggedResponse->viewData('socialEngagementMonitors')->getCollection()->modelKeys());
        $this->assertSame(['未標籤'], array_column($untaggedResponse->viewData('socialEngagementSummaries'), 'tag'));
    }

    public function test_dashboard_platform_filter_scopes_engagement_data_and_preserves_other_platform_health_counts(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $platformMetrics = [
            'facebook' => [
                ['views' => 100, 'likes' => 10, 'comments' => 2, 'shares' => 1, 'source' => 'json_ld'],
                ['views' => 150, 'likes' => 14, 'comments' => 3, 'shares' => 2, 'source' => 'json_ld'],
            ],
            'instagram' => [
                ['views' => 1000, 'likes' => 100, 'comments' => 30, 'shares' => 10, 'source' => 'json_ld'],
                ['views' => 2000, 'likes' => 250, 'comments' => 40, 'shares' => 20, 'source' => 'json_ld'],
            ],
            'threads' => [
                ['views' => 500, 'likes' => 80, 'comments' => 15, 'shares' => 5, 'source' => 'json_ld'],
                ['views' => 900, 'likes' => 125, 'comments' => 25, 'shares' => 10, 'source' => 'json_ld'],
            ],
            'dcard' => [
                ['views' => null, 'likes' => 20, 'comments' => 3, 'shares' => 2, 'source' => 'rendered_dom'],
                ['views' => null, 'likes' => 29, 'comments' => 5, 'shares' => 4, 'source' => 'rendered_dom'],
            ],
        ];
        $monitors = [];

        foreach ($platformMetrics as $platform => [$previousMetrics, $latestMetrics]) {
            $postId = $platform === 'dcard' ? '262476944' : 'FILTER'.strtoupper($platform);
            $monitor = $this->createSocialMonitorForPlatform($platform, $postId, 'summer');
            $monitors[$platform] = $monitor;
            $this->createMetricRun($monitor, now()->subDays(2), $previousMetrics);
            $this->createMetricRun($monitor, now(), $latestMetrics);
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_period' => 7, 'engagement_platform' => 'facebook']))
            ->assertOk()
            ->assertSee('<option value="facebook" selected>Facebook</option>', false)
            ->assertSee('name="engagement_period" value="7"', false)
            ->assertSee(route('dashboard', ['engagement_period' => 30, 'engagement_platform' => 'facebook']))
            ->assertSee(route('monitors.social-engagement.export', ['engagement_platform' => 'facebook']), false)
            ->assertSee(route('monitors.social-engagement.export.history', ['engagement_platform' => 'facebook']), false);

        $this->assertSame([$monitors['facebook']->id], $response->viewData('socialEngagementMonitors')->modelKeys());
        $this->assertSame(1, $response->viewData('socialEngagementSummaries')[0]['monitor_count']);
        $this->assertSame(['total' => 14, 'available' => 1], $response->viewData('socialEngagementSummaries')[0]['metrics']['likes']);
        $this->assertSame(['change' => 4, 'available' => 1], Arr::only(
            $response->viewData('socialEngagementChangeSummaries')[0]['metrics']['likes'],
            ['change', 'available'],
        ));
        $this->assertSame(1, $response->viewData('platformCounts')['instagram']['total']);
        $this->assertSame(1, $response->viewData('platformCounts')['threads']['total']);
        $this->assertSame(1, $response->viewData('platformCounts')['dcard']['total']);

        $invalidPlatformResponse = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_platform' => 'unknown']))
            ->assertOk()
            ->assertSee('<option value="all" selected>全部平台</option>', false);

        $this->assertCount(4, $invalidPlatformResponse->viewData('socialEngagementMonitors'));
        $this->assertCount(1, $invalidPlatformResponse->viewData('socialEngagementSummaries'));
        $this->assertSame(4, $invalidPlatformResponse->viewData('socialEngagementSummaries')[0]['monitor_count']);
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
        $this->assertSame(['change' => 700, 'available' => 2], Arr::only($summaries['summer']['metrics']['views'], ['change', 'available']));
        $this->assertSame(['change' => 50, 'available' => 2], Arr::only($summaries['summer']['metrics']['likes'], ['change', 'available']));
        $this->assertSame(['change' => 14, 'available' => 2], Arr::only($summaries['summer']['metrics']['comments'], ['change', 'available']));
        $this->assertSame(['change' => 6, 'available' => 2], Arr::only($summaries['summer']['metrics']['shares'], ['change', 'available']));
        $this->assertSame(['change' => 500, 'available' => 1], Arr::only($summaries['brand']['metrics']['views'], ['change', 'available']));
        $this->assertSame(['change' => 30, 'available' => 1], Arr::only($summaries['brand']['metrics']['likes'], ['change', 'available']));
    }

    public function test_dashboard_summarizes_engagement_changes_for_the_selected_period(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $postWithThirtyDayHistory = $this->createSocialMonitor('PERIOD03001', 'summer');
        $postWithNinetyDayHistory = $this->createSocialMonitor('PERIOD09001', 'summer');
        $postWithOlderHistory = $this->createSocialMonitor('PERIOD10001', 'summer');
        $user = User::factory()->create();

        $this->createMetricRun($postWithThirtyDayHistory, now()->subDays(20), ['views' => 100, 'likes' => 10, 'comments' => 2, 'shares' => 1, 'source' => 'json_ld']);
        $this->createMetricRun($postWithThirtyDayHistory, now(), ['views' => 500, 'likes' => 40, 'comments' => 8, 'shares' => 3, 'source' => 'json_ld']);
        $this->createMetricRun($postWithNinetyDayHistory, now()->subDays(60), ['views' => 200, 'likes' => 20, 'comments' => 4, 'shares' => 2, 'source' => 'json_ld']);
        $this->createMetricRun($postWithNinetyDayHistory, now(), ['views' => 350, 'likes' => 30, 'comments' => 6, 'shares' => 3, 'source' => 'json_ld']);
        $this->createMetricRun($postWithOlderHistory, now()->subDays(100), ['views' => 50, 'likes' => 5, 'comments' => 1, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($postWithOlderHistory, now(), ['views' => 600, 'likes' => 25, 'comments' => 4, 'shares' => 1, 'source' => 'json_ld']);

        $thirtyDayResponse = $this->actingAs($user)
            ->get(route('dashboard', ['engagement_period' => 30]))
            ->assertOk()
            ->assertSee('近 30 天互動變化')
            ->assertSee('aria-current="page"', false);
        $this->assertTrue($thirtyDayResponse->viewData('socialEngagementChangeComparisonAvailable'));
        $thirtyDaySummaries = collect($thirtyDayResponse->viewData('socialEngagementChangeSummaries'))->keyBy('tag');

        $this->assertSame(['change' => 400, 'available' => 1], Arr::only($thirtyDaySummaries['summer']['metrics']['views'], ['change', 'available']));

        $ninetyDayResponse = $this->actingAs($user)
            ->get(route('dashboard', ['engagement_period' => 90]))
            ->assertOk()
            ->assertSee('近 90 天互動變化')
            ->assertSee('前期比較需保留 180 天；目前設定保留 100 天。');
        $this->assertFalse($ninetyDayResponse->viewData('socialEngagementChangeComparisonAvailable'));
        $ninetyDaySummaries = collect($ninetyDayResponse->viewData('socialEngagementChangeSummaries'))->keyBy('tag');

        $this->assertSame(['change' => 550, 'available' => 2], Arr::only($ninetyDaySummaries['summer']['metrics']['views'], ['change', 'available']));
    }

    public function test_dashboard_compares_equal_period_changes_for_the_same_posts(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $postWithBothPeriods = $this->createSocialMonitor('COMPARE001', 'summer');
        $postWithCurrentPeriodOnly = $this->createSocialMonitor('COMPARE002', 'summer');
        $user = User::factory()->create();

        $this->createMetricRun($postWithBothPeriods, now()->subDays(13), ['views' => 100, 'likes' => 10, 'comments' => 2, 'shares' => 1, 'source' => 'json_ld']);
        $this->createMetricRun($postWithBothPeriods, now()->subDays(8), ['views' => 200, 'likes' => 20, 'comments' => 4, 'shares' => 2, 'source' => 'json_ld']);
        $this->createMetricRun($postWithBothPeriods, now()->subDays(6), ['views' => 300, 'likes' => 30, 'comments' => 6, 'shares' => 3, 'source' => 'json_ld']);
        $this->createMetricRun($postWithBothPeriods, now(), ['views' => 600, 'likes' => 60, 'comments' => 12, 'shares' => 4, 'source' => 'json_ld']);
        $this->createMetricRun($postWithCurrentPeriodOnly, now()->subDays(6), ['views' => 100, 'likes' => 10, 'comments' => 3, 'shares' => 0, 'source' => 'json_ld']);
        $this->createMetricRun($postWithCurrentPeriodOnly, now(), ['views' => 150, 'likes' => 15, 'comments' => 4, 'shares' => 1, 'source' => 'json_ld']);

        $response = $this->actingAs($user)
            ->get(route('dashboard', ['engagement_period' => 7]))
            ->assertOk()
            ->assertSee('同時有兩期讀值的貼文')
            ->assertSee('較前期');
        $summaries = collect($response->viewData('socialEngagementChangeSummaries'))->keyBy('tag');

        $this->assertSame(['change' => 350, 'available' => 2], Arr::only($summaries['summer']['metrics']['views'], ['change', 'available']));
        $this->assertSame([
            'current_change' => 300,
            'previous_change' => 100,
            'difference' => 200,
            'available' => 1,
        ], $summaries['summer']['metrics']['views']['comparison']);
        $this->assertSame([
            'current_change' => 30,
            'previous_change' => 10,
            'difference' => 20,
            'available' => 1,
        ], $summaries['summer']['metrics']['likes']['comparison']);
        $this->assertSame([
            'current_change' => 6,
            'previous_change' => 2,
            'difference' => 4,
            'available' => 1,
        ], $summaries['summer']['metrics']['comments']['comparison']);
        $this->assertSame([
            'current_change' => 1,
            'previous_change' => 1,
            'difference' => 0,
            'available' => 1,
        ], $summaries['summer']['metrics']['shares']['comparison']);
    }

    public function test_dashboard_limits_engagement_period_options_to_the_data_retention_period(): void
    {
        Config::set('monitoring.retention_days', 30);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('近 7 天')
            ->assertSee('近 30 天')
            ->assertDontSee('近 90 天');

        $this->actingAs($user)
            ->get(route('dashboard', ['engagement_period' => 90]))
            ->assertOk()
            ->assertSee('近 7 天互動變化')
            ->assertDontSee('近 90 天');

        $thirtyDayResponse = $this->actingAs($user)
            ->get(route('dashboard', ['engagement_period' => 30]))
            ->assertOk()
            ->assertSee('前期比較需保留 60 天；目前設定保留 30 天。');

        $this->assertFalse($thirtyDayResponse->viewData('socialEngagementChangeComparisonAvailable'));
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

    public function test_social_engagement_csv_exports_can_be_scoped_to_the_selected_platform(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $facebook = $this->createSocialMonitorForPlatform('facebook', 'EXPORTFB001', 'campaign');
        $instagram = $this->createSocialMonitorForPlatform('instagram', 'EXPORTIG001', 'campaign');
        $this->createMetricRun($facebook, now()->subHour(), ['views' => 100, 'likes' => 10, 'comments' => 2, 'shares' => 1, 'source' => 'json_ld']);
        $this->createMetricRun($facebook, now(), ['views' => 150, 'likes' => 15, 'comments' => 3, 'shares' => 2, 'source' => 'json_ld']);
        $this->createMetricRun($instagram, now()->subHour(), ['views' => 1000, 'likes' => 100, 'comments' => 20, 'shares' => 10, 'source' => 'json_ld']);
        $this->createMetricRun($instagram, now(), ['views' => 1500, 'likes' => 150, 'comments' => 30, 'shares' => 15, 'source' => 'json_ld']);
        $user = User::factory()->create();

        $latestRows = $this->actingAs($user)
            ->get(route('monitors.social-engagement.export', ['engagement_platform' => 'facebook']))
            ->assertOk()
            ->streamedContent();
        $latestRows = $this->csvRows($latestRows);

        $this->assertCount(1, $latestRows);
        $this->assertSame('Facebook', $latestRows[0]['platform']);
        $this->assertSame($facebook->name, $latestRows[0]['monitor_name']);

        $historyRows = $this->actingAs($user)
            ->get(route('monitors.social-engagement.export.history', ['engagement_platform' => 'facebook']))
            ->assertOk()
            ->streamedContent();
        $historyRows = $this->csvRows($historyRows);

        $this->assertCount(2, $historyRows);
        $this->assertSame(['Facebook', 'Facebook'], array_column($historyRows, 'platform'));
        $this->assertSame([$facebook->name, $facebook->name], array_column($historyRows, 'monitor_name'));
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
            ->assertHeader('Content-Disposition', 'attachment; filename=social-engagement-history-30-days-2026-11-22.csv');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame((string) $earlierRun->id, $rows[0]['check_run_id']);
        $this->assertSame((string) $latestRun->id, $rows[1]['check_run_id']);
        $this->assertSame($monitor->name, $rows[0]['monitor_name']);
        $this->assertSame('campaign', $rows[1]['tags']);
        $this->assertSame('6.50', $rows[0]['public_engagement_rate_percent']);
        $this->assertSame('8.00', $rows[1]['public_engagement_rate_percent']);

        $shortPeriodRows = $this->actingAs(User::factory()->create())
            ->get(route('monitors.social-engagement.export.history', ['engagement_period' => 7]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=social-engagement-history-7-days-2026-11-22.csv')
            ->streamedContent();
        $shortPeriodRows = $this->csvRows($shortPeriodRows);

        $this->assertCount(1, $shortPeriodRows);
        $this->assertSame((string) $latestRun->id, $shortPeriodRows[0]['check_run_id']);
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

    private function createSocialMonitorForPlatform(string $platform, string $postId, ?string $label = null): Monitor
    {
        $url = match ($platform) {
            'facebook' => 'https://www.facebook.com/share/p/'.$postId.'/',
            'instagram' => 'https://www.instagram.com/reel/'.$postId.'/',
            'threads' => 'https://www.threads.com/share/'.$postId,
            'dcard' => 'https://www.dcard.tw/f/makeup/p/'.$postId,
            default => throw new \InvalidArgumentException('Unsupported social platform.'),
        };

        return Monitor::factory()->create([
            'name' => ucfirst($platform).' campaign post',
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
