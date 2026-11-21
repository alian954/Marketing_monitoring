<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSocialEngagementTrendTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_aggregates_daily_interaction_changes_for_the_selected_platform_and_tag(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $firstCampaignPost = $this->createSocialMonitor('facebook', 'TREND001', 'campaign');
        $secondCampaignPost = $this->createSocialMonitor('facebook', 'TREND002', 'campaign');
        $otherTagPost = $this->createSocialMonitor('facebook', 'TREND003', 'other');
        $instagramPost = $this->createSocialMonitor('instagram', 'TREND004', 'campaign');
        $staleConfigPost = $this->createSocialMonitor('facebook', 'TREND005', 'campaign');

        $this->createMetricRun($firstCampaignPost, '2026-11-16 09:00:00', ['likes' => 10, 'comments' => 2, 'shares' => 3]);
        $this->createMetricRun($firstCampaignPost, '2026-11-17 09:00:00', ['likes' => 15, 'comments' => 3, 'shares' => 4]);
        $this->createMetricRun($firstCampaignPost, '2026-11-17 14:00:00', ['likes' => 17, 'comments' => 3, 'shares' => 5]);
        $this->createMetricRun($firstCampaignPost, '2026-11-19 09:00:00', ['likes' => 14, 'comments' => 2, 'shares' => 2]);
        $this->createMetricRun($firstCampaignPost, '2026-11-20 09:00:00', ['likes' => 20, 'comments' => 3, 'shares' => null]);
        $this->createMetricRun($secondCampaignPost, '2026-11-16 10:00:00', ['likes' => 5, 'comments' => 1, 'shares' => 0]);
        $this->createMetricRun($secondCampaignPost, '2026-11-17 10:00:00', ['likes' => 8, 'comments' => 2, 'shares' => 0]);
        $this->createMetricRun($secondCampaignPost, '2026-11-19 10:00:00', ['likes' => 9, 'comments' => 2, 'shares' => 0]);
        $this->createMetricRun($otherTagPost, '2026-11-16 11:00:00', ['likes' => 100, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($otherTagPost, '2026-11-17 11:00:00', ['likes' => 300, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($instagramPost, '2026-11-16 12:00:00', ['likes' => 200, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($instagramPost, '2026-11-17 12:00:00', ['likes' => 700, 'comments' => 0, 'shares' => 0]);
        $stalePreviousRun = $this->createMetricRun($staleConfigPost, '2026-11-16 13:00:00', ['likes' => 500, 'comments' => 0, 'shares' => 0]);
        $staleLatestRun = $this->createMetricRun($staleConfigPost, '2026-11-17 13:00:00', ['likes' => 900, 'comments' => 0, 'shares' => 0]);
        $stalePreviousRun->forceFill(['config_version' => $staleConfigPost->config_version - 1])->save();
        $staleLatestRun->forceFill(['config_version' => $staleConfigPost->config_version - 1])->save();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', [
                'engagement_platform' => 'facebook',
                'engagement_tag' => 'tag:campaign',
                'engagement_period' => 7,
            ]))
            ->assertOk()
            ->assertSee('每日互動淨變化')
            ->assertSee('+14')
            ->assertSee('-6');

        $trendByDate = collect($response->viewData('socialEngagementTrend'))->keyBy('date');

        $this->assertSame(['change' => 14, 'posts' => 2], [
            'change' => $trendByDate['2026-11-17']['change'],
            'posts' => $trendByDate['2026-11-17']['posts'],
        ]);
        $this->assertSame(['change' => -6, 'posts' => 2], [
            'change' => $trendByDate['2026-11-19']['change'],
            'posts' => $trendByDate['2026-11-19']['posts'],
        ]);
        $this->assertSame(['change' => 7, 'posts' => 1], [
            'change' => $trendByDate['2026-11-20']['change'],
            'posts' => $trendByDate['2026-11-20']['posts'],
        ]);
        $this->assertTrue($response->viewData('socialEngagementTrendAvailable'));
    }

    public function test_dashboard_shows_no_daily_growth_without_two_interaction_observations(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $campaignPost = $this->createSocialMonitor('dcard', '262476944', 'campaign');

        $this->createMetricRun($campaignPost, '2026-11-20 09:00:00', ['likes' => 20, 'comments' => 3, 'shares' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_platform' => 'dcard', 'engagement_period' => 7]))
            ->assertOk()
            ->assertSee('尚無每日互動增量');

        $this->assertFalse($response->viewData('socialEngagementTrendAvailable'));
        $this->assertSame(0, collect($response->viewData('socialEngagementTrend'))->sum('posts'));
    }

    public function test_dcard_daily_trend_uses_available_likes_and_comments_without_shares(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $campaignPost = $this->createSocialMonitor('dcard', '262476944', 'campaign');

        $this->createMetricRun($campaignPost, '2026-11-16 09:00:00', ['likes' => 20, 'comments' => 3, 'shares' => null]);
        $this->createMetricRun($campaignPost, '2026-11-20 09:00:00', ['likes' => 28, 'comments' => 4, 'shares' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', [
                'engagement_platform' => 'dcard',
                'engagement_tag' => 'tag:campaign',
                'engagement_period' => 7,
            ]))
            ->assertOk()
            ->assertSee('+9');

        $trendByDate = collect($response->viewData('socialEngagementTrend'))->keyBy('date');

        $this->assertSame(['change' => 9, 'posts' => 1], [
            'change' => $trendByDate['2026-11-20']['change'],
            'posts' => $trendByDate['2026-11-20']['posts'],
        ]);
    }

    public function test_dashboard_ranks_posts_by_period_net_interaction_change_with_selected_filters(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $posts = [];

        foreach (range(1, 6) as $index) {
            $posts[$index] = $this->createSocialMonitor('facebook', 'GROWTH00'.$index, 'campaign');
            $startingLikes = 10 + $index;
            $endingLikes = $startingLikes + 6 - $index;

            $this->createMetricRun($posts[$index], '2026-11-16 09:00:00', [
                'likes' => $startingLikes,
                'comments' => 2,
                'shares' => 1,
            ]);
            $this->createMetricRun($posts[$index], '2026-11-20 09:00:00', [
                'likes' => $endingLikes,
                'comments' => 2,
                'shares' => 1,
            ]);
        }

        $otherTagPost = $this->createSocialMonitor('facebook', 'GROWTHOTHER', 'other');
        $this->createMetricRun($otherTagPost, '2026-11-16 10:00:00', ['likes' => 10, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($otherTagPost, '2026-11-20 10:00:00', ['likes' => 1_000, 'comments' => 0, 'shares' => 0]);

        $otherPlatformPost = $this->createSocialMonitor('instagram', 'GROWTHINSTAGRAM', 'campaign');
        $this->createMetricRun($otherPlatformPost, '2026-11-16 11:00:00', ['likes' => 10, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($otherPlatformPost, '2026-11-20 11:00:00', ['likes' => 1_000, 'comments' => 0, 'shares' => 0]);

        $staleConfigPost = $this->createSocialMonitor('facebook', 'GROWTHSTALE', 'campaign');
        $staleFirstRun = $this->createMetricRun($staleConfigPost, '2026-11-16 12:00:00', ['likes' => 10, 'comments' => 0, 'shares' => 0]);
        $staleLatestRun = $this->createMetricRun($staleConfigPost, '2026-11-20 12:00:00', ['likes' => 1_000, 'comments' => 0, 'shares' => 0]);
        $staleFirstRun->forceFill(['config_version' => $staleConfigPost->config_version - 1])->save();
        $staleLatestRun->forceFill(['config_version' => $staleConfigPost->config_version - 1])->save();

        $singleObservationPost = $this->createSocialMonitor('facebook', 'GROWTHSINGLE', 'campaign');
        $this->createMetricRun($singleObservationPost, '2026-11-20 13:00:00', ['likes' => 1_000, 'comments' => 0, 'shares' => 0]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', [
                'engagement_platform' => 'facebook',
                'engagement_tag' => 'tag:campaign',
                'engagement_period' => 7,
            ]))
            ->assertOk()
            ->assertSee('單篇互動變化排行')
            ->assertSee('+5');

        $growthPosts = $response->viewData('socialEngagementGrowthPosts');

        $this->assertSame([
            ['id' => $posts[1]->id, 'interaction_change' => 5],
            ['id' => $posts[2]->id, 'interaction_change' => 4],
            ['id' => $posts[3]->id, 'interaction_change' => 3],
            ['id' => $posts[4]->id, 'interaction_change' => 2],
            ['id' => $posts[5]->id, 'interaction_change' => 1],
        ], array_map(static fn (array $post): array => [
            'id' => $post['id'],
            'interaction_change' => $post['interaction_change'],
        ], $growthPosts));
    }

    public function test_dcard_growth_ranking_uses_available_interaction_metrics_when_shares_are_missing(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $campaignPost = $this->createSocialMonitor('dcard', '262476944', 'campaign');
        $this->createMetricRun($campaignPost, '2026-11-16 09:00:00', ['likes' => 20, 'comments' => 3, 'shares' => null]);
        $this->createMetricRun($campaignPost, '2026-11-20 09:00:00', ['likes' => 28, 'comments' => 4, 'shares' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', [
                'engagement_platform' => 'dcard',
                'engagement_period' => 7,
            ]))
            ->assertOk()
            ->assertSee('單篇互動變化排行')
            ->assertSee('+9')
            ->assertSee('2/3 項')
            ->assertSee('無雙期可比較指標');

        $growthPosts = $response->viewData('socialEngagementGrowthPosts');

        $this->assertSame(1, count($growthPosts));
        $this->assertSame($campaignPost->id, $growthPosts[0]['id']);
        $this->assertSame(['likes' => 8, 'comments' => 1, 'shares' => null], $growthPosts[0]['metric_changes']);
        $this->assertSame(2, $growthPosts[0]['available_metrics']);
        $this->assertSame(23, $growthPosts[0]['starting_interactions']);
        $this->assertSame(39.1, $growthPosts[0]['interaction_growth_rate']);
        $this->assertSame(0, $growthPosts[0]['period_comparable_metrics']);
        $this->assertNull($growthPosts[0]['period_interaction_change_difference']);
        $response->assertSee('39.1%');
    }

    public function test_dcard_platform_totals_use_public_likes_and_comments_without_shares(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $campaignPost = $this->createSocialMonitor('dcard', '262476944', 'campaign');
        $metricRun = $this->createMetricRun($campaignPost, '2026-11-20 09:00:00', [
            'likes' => 28,
            'comments' => 4,
            'shares' => null,
        ]);
        $metricRun->forceFill([
            'response_public_engagement_metrics' => [
                ...$metricRun->response_public_engagement_metrics,
                'views' => 100,
            ],
        ])->save();
        $user = User::factory()->create();

        $dcardResponse = $this->actingAs($user)
            ->get(route('dashboard', ['engagement_platform' => 'dcard']))
            ->assertOk()
            ->assertSee('讚＋留言合計由高到低')
            ->assertSee('互動合計（讚＋留言）')
            ->assertSee('依 讚＋留言 計');
        $dcardTopPosts = $dcardResponse->viewData('socialEngagementTopPosts');
        $dcardTagSummary = collect($dcardResponse->viewData('socialEngagementSummaries'))->keyBy('tag')['campaign'];
        $dcardComparison = collect($dcardResponse->viewData('socialEngagementComparisonSummaries'))
            ->keyBy('tag')['campaign']['platforms']['dcard'];
        $dcardMonitor = $dcardResponse->viewData('socialEngagementMonitors')->first();

        $this->assertCount(1, $dcardTopPosts);
        $this->assertSame($campaignPost->id, $dcardTopPosts[0]['id']);
        $this->assertSame(32, $dcardTopPosts[0]['interaction_total']);
        $this->assertNull($dcardTopPosts[0]['shares']);
        $this->assertSame(['likes', 'comments'], $dcardTopPosts[0]['interaction_metric_keys']);
        $this->assertSame(32.0, $dcardTopPosts[0]['engagement_rate_percent']);
        $this->assertSame(32, $dcardTagSummary['interaction_total']);
        $this->assertSame(1, $dcardTagSummary['interaction_count']);
        $this->assertSame(32.0, $dcardTagSummary['average_interactions_per_post']);
        $this->assertSame('讚＋留言', $dcardComparison['interaction_scope_label']);
        $this->assertSame(32, $dcardComparison['interaction_total']);
        $this->assertSame(1, $dcardComparison['engagement_rate']['available']);
        $this->assertSame(32, $dcardMonitor->getAttribute('social_engagement_interaction_total'));
        $this->assertSame(32.0, $dcardMonitor->getAttribute('social_engagement_rate_percent'));

        $allPlatformsResponse = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
        $allPlatformsTagSummary = collect($allPlatformsResponse->viewData('socialEngagementSummaries'))
            ->keyBy('tag')['campaign'];
        $allPlatformsComparison = collect($allPlatformsResponse->viewData('socialEngagementComparisonSummaries'))
            ->keyBy('tag')['campaign']['platforms']['dcard'];

        $this->assertSame([], $allPlatformsResponse->viewData('socialEngagementTopPosts'));
        $this->assertSame(0, $allPlatformsTagSummary['interaction_count']);
        $this->assertNull($allPlatformsTagSummary['average_interactions_per_post']);
        $this->assertSame(32, $allPlatformsComparison['interaction_total']);

        $latestExport = $this->actingAs($user)
            ->get(route('monitors.social-engagement.export', ['engagement_platform' => 'dcard']))
            ->assertOk();
        $latestRows = $this->csvRows($latestExport->streamedContent());

        $this->assertSame('32', $latestRows[0]['public_interaction_total']);
        $this->assertSame('likes+comments', $latestRows[0]['interaction_metric_scope']);
        $this->assertSame('32.00', $latestRows[0]['public_engagement_rate_percent']);

        $historyExport = $this->actingAs($user)
            ->get(route('monitors.social-engagement.export.history', ['engagement_platform' => 'dcard', 'engagement_period' => 7]))
            ->assertOk();
        $historyRows = $this->csvRows($historyExport->streamedContent());

        $this->assertSame('32', $historyRows[0]['public_interaction_total']);
        $this->assertSame('likes+comments', $historyRows[0]['interaction_metric_scope']);
        $this->assertSame('32.00', $historyRows[0]['public_engagement_rate_percent']);
    }

    public function test_growth_rate_is_unavailable_for_zero_baselines_and_negative_for_declining_interactions(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $zeroBaselinePost = $this->createSocialMonitor('facebook', 'GROWTHZERO', 'campaign');
        $decliningPost = $this->createSocialMonitor('threads', 'GROWTHDOWN', 'campaign');

        $this->createMetricRun($zeroBaselinePost, '2026-11-16 09:00:00', ['likes' => 0, 'comments' => 0, 'shares' => null]);
        $this->createMetricRun($zeroBaselinePost, '2026-11-20 09:00:00', ['likes' => 3, 'comments' => 0, 'shares' => null]);
        $this->createMetricRun($decliningPost, '2026-11-16 10:00:00', ['likes' => 10, 'comments' => 3, 'shares' => null]);
        $this->createMetricRun($decliningPost, '2026-11-20 10:00:00', ['likes' => 6, 'comments' => 2, 'shares' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', [
                'engagement_tag' => 'tag:campaign',
                'engagement_period' => 7,
            ]))
            ->assertOk()
            ->assertSee('無法計算')
            ->assertSee('-38.5%');

        $growthPosts = collect($response->viewData('socialEngagementGrowthPosts'))->keyBy('id');

        $this->assertSame(0, $growthPosts[$zeroBaselinePost->id]['starting_interactions']);
        $this->assertNull($growthPosts[$zeroBaselinePost->id]['interaction_growth_rate']);
        $this->assertSame(13, $growthPosts[$decliningPost->id]['starting_interactions']);
        $this->assertSame(-38.5, $growthPosts[$decliningPost->id]['interaction_growth_rate']);
    }

    public function test_dashboard_compares_post_interaction_change_with_the_previous_period_for_matching_metrics(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $campaignPost = $this->createSocialMonitor('dcard', '262476944', 'campaign');

        $this->createMetricRun($campaignPost, '2026-11-10 09:00:00', ['likes' => 20, 'comments' => 4, 'shares' => 5]);
        $this->createMetricRun($campaignPost, '2026-11-14 09:00:00', ['likes' => 26, 'comments' => 8, 'shares' => null]);
        $this->createMetricRun($campaignPost, '2026-11-16 09:00:00', ['likes' => 30, 'comments' => 10, 'shares' => 5]);
        $this->createMetricRun($campaignPost, '2026-11-20 09:00:00', ['likes' => 33, 'comments' => 12, 'shares' => 15]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', [
                'engagement_tag' => 'tag:campaign',
                'engagement_period' => 7,
            ]))
            ->assertOk()
            ->assertSee('較前期變化差');

        $growthPosts = $response->viewData('socialEngagementGrowthPosts');

        $this->assertCount(1, $growthPosts);
        $this->assertSame(15, $growthPosts[0]['interaction_change']);
        $this->assertSame(5, $growthPosts[0]['comparable_current_interaction_change']);
        $this->assertSame(10, $growthPosts[0]['previous_period_interaction_change']);
        $this->assertSame(-5, $growthPosts[0]['period_interaction_change_difference']);
        $this->assertSame(2, $growthPosts[0]['period_comparable_metrics']);
    }

    public function test_dashboard_can_sort_post_growth_by_rate_and_places_zero_baselines_last(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $highRatePost = $this->createSocialMonitor('facebook', 'GROWTHRATEHIGH', 'campaign');
        $highVolumePost = $this->createSocialMonitor('facebook', 'GROWTHVOLUME', 'campaign');
        $zeroBaselinePost = $this->createSocialMonitor('facebook', 'GROWTHRATENULL', 'campaign');
        $otherTagPost = $this->createSocialMonitor('facebook', 'GROWTHRATEOTHER', 'other');
        $otherPlatformPost = $this->createSocialMonitor('threads', 'GROWTHRATEPLATFORM', 'campaign');

        $this->createMetricRun($highRatePost, '2026-11-16 09:00:00', ['likes' => 10, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($highRatePost, '2026-11-20 09:00:00', ['likes' => 30, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($highVolumePost, '2026-11-16 10:00:00', ['likes' => 100, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($highVolumePost, '2026-11-20 10:00:00', ['likes' => 150, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($zeroBaselinePost, '2026-11-16 11:00:00', ['likes' => 0, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($zeroBaselinePost, '2026-11-20 11:00:00', ['likes' => 60, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($otherTagPost, '2026-11-16 12:00:00', ['likes' => 1, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($otherTagPost, '2026-11-20 12:00:00', ['likes' => 10_000, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($otherPlatformPost, '2026-11-16 13:00:00', ['likes' => 1, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($otherPlatformPost, '2026-11-20 13:00:00', ['likes' => 10_000, 'comments' => 0, 'shares' => 0]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', [
                'engagement_platform' => 'facebook',
                'engagement_tag' => 'tag:campaign',
                'engagement_period' => 7,
                'engagement_growth_sort' => 'growth_rate',
            ]))
            ->assertOk()
            ->assertSee('依互動成長率')
            ->assertSee('匯出完整排行 CSV')
            ->assertSee(e(route('monitors.social-engagement.export.growth', [
                'engagement_platform' => 'facebook',
                'engagement_tag' => 'tag:campaign',
                'engagement_period' => 7,
                'engagement_growth_sort' => 'growth_rate',
            ])), false)
            ->assertSee('name="engagement_growth_sort" value="growth_rate"', false)
            ->assertSee('engagement_growth_sort=change', false);

        $growthPosts = $response->viewData('socialEngagementGrowthPosts');

        $this->assertSame('growth_rate', $response->viewData('socialEngagementGrowthPostSort'));
        $this->assertSame([
            $highRatePost->id,
            $highVolumePost->id,
            $zeroBaselinePost->id,
        ], array_column($growthPosts, 'id'));
        $this->assertNotContains($otherTagPost->id, array_column($growthPosts, 'id'));
        $this->assertNotContains($otherPlatformPost->id, array_column($growthPosts, 'id'));
    }

    public function test_dashboard_defaults_an_unknown_growth_sort_to_net_change(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard', ['engagement_growth_sort' => 'unknown']))
            ->assertOk();

        $this->assertSame('change', $response->viewData('socialEngagementGrowthPostSort'));
    }

    public function test_growth_csv_exports_all_rows_with_selected_filters_and_rate_order(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $highRatePost = $this->createSocialMonitor('facebook', 'EXPORTRATE', 'campaign');
        $highVolumePost = $this->createSocialMonitor('facebook', 'EXPORTVOLUME', 'campaign');
        $zeroBaselinePost = $this->createSocialMonitor('facebook', 'EXPORTZERO', 'campaign');
        $otherTagPost = $this->createSocialMonitor('facebook', 'EXPORTOTHER', 'other');
        $otherPlatformPost = $this->createSocialMonitor('threads', 'EXPORTTHREADS', 'campaign');
        $privateUrl = 'https://www.facebook.com/brand/posts/EXPORTRATE?token=private-export-token';
        $highRatePost->forceFill([
            'name' => '=SUM(1,1)',
            'url' => $privateUrl,
            'url_hash' => Monitor::fingerprintUrl($privateUrl),
        ])->save();

        $this->createMetricRun($highRatePost, '2026-11-16 09:00:00', ['likes' => 10, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($highRatePost, '2026-11-20 09:00:00', ['likes' => 30, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($highVolumePost, '2026-11-16 10:00:00', ['likes' => 100, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($highVolumePost, '2026-11-20 10:00:00', ['likes' => 150, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($zeroBaselinePost, '2026-11-16 11:00:00', ['likes' => 0, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($zeroBaselinePost, '2026-11-20 11:00:00', ['likes' => 60, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($otherTagPost, '2026-11-16 12:00:00', ['likes' => 1, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($otherTagPost, '2026-11-20 12:00:00', ['likes' => 10_000, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($otherPlatformPost, '2026-11-16 13:00:00', ['likes' => 1, 'comments' => 0, 'shares' => 0]);
        $this->createMetricRun($otherPlatformPost, '2026-11-20 13:00:00', ['likes' => 10_000, 'comments' => 0, 'shares' => 0]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitors.social-engagement.export.growth', [
                'engagement_platform' => 'facebook',
                'engagement_tag' => 'tag:campaign',
                'engagement_period' => 7,
                'engagement_growth_sort' => 'growth_rate',
            ]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=social-engagement-growth-7-days-2026-11-22.csv');
        $csv = $response->streamedContent();
        $rows = $this->csvRows($csv);

        $this->assertSame([
            (string) $highRatePost->id,
            (string) $highVolumePost->id,
            (string) $zeroBaselinePost->id,
        ], array_column($rows, 'monitor_id'));
        $this->assertSame("'=SUM(1,1)", $rows[0]['monitor_name']);
        $this->assertSame('10', $rows[0]['starting_interactions']);
        $this->assertSame('30', $rows[0]['ending_interactions']);
        $this->assertSame('20', $rows[0]['interaction_net_change']);
        $this->assertSame('200.0', $rows[0]['interaction_growth_rate_percent']);
        $this->assertSame('', $rows[2]['interaction_growth_rate_percent']);
        $this->assertStringNotContainsString('private-export-token', $csv);
        $exportedMonitorIds = array_column($rows, 'monitor_id');
        $this->assertNotContains((string) $otherTagPost->id, $exportedMonitorIds);
        $this->assertNotContains((string) $otherPlatformPost->id, $exportedMonitorIds);
    }

    public function test_growth_csv_export_requires_authentication(): void
    {
        $this->get(route('monitors.social-engagement.export.growth'))
            ->assertRedirect(route('login'));
    }

    /** @param array{likes: int|null, comments: int|null, shares: int|null} $metrics */
    private function createMetricRun(Monitor $monitor, string $finishedAt, array $metrics): CheckRun
    {
        $timestamp = CarbonImmutable::parse($finishedAt, 'Asia/Taipei');

        return CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'finished_at' => $timestamp,
            'started_at' => $timestamp,
            'scheduled_at' => $timestamp,
            'response_public_engagement_metrics' => [
                'views' => null,
                ...$metrics,
                'source' => 'rendered_dom',
            ],
        ]);
    }

    private function createSocialMonitor(string $platform, string $postId, string $tag): Monitor
    {
        $url = match ($platform) {
            'facebook' => 'https://www.facebook.com/brand/posts/'.$postId,
            'instagram' => 'https://www.instagram.com/p/'.$postId.'/',
            'threads' => 'https://www.threads.com/@brand/post/'.$postId,
            'dcard' => 'https://www.dcard.tw/f/makeup/p/'.$postId,
        };

        return Monitor::factory()->create([
            'name' => $platform.' '.$postId,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $tag,
        ]);
    }

    /** @return list<array<string, string|null>> */
    private function csvRows(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        $headers = str_getcsv((string) array_shift($lines));

        return array_map(static function (string $line) use ($headers): array {
            $values = str_getcsv($line);

            return array_combine($headers, $values) ?: [];
        }, $lines);
    }
}
