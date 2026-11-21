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
