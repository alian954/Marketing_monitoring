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
            ->assertSee('2/3 項');

        $growthPosts = $response->viewData('socialEngagementGrowthPosts');

        $this->assertSame(1, count($growthPosts));
        $this->assertSame($campaignPost->id, $growthPosts[0]['id']);
        $this->assertSame(['likes' => 8, 'comments' => 1, 'shares' => null], $growthPosts[0]['metric_changes']);
        $this->assertSame(2, $growthPosts[0]['available_metrics']);
        $this->assertSame(23, $growthPosts[0]['starting_interactions']);
        $this->assertSame(39.1, $growthPosts[0]['interaction_growth_rate']);
        $response->assertSee('39.1%');
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
}
