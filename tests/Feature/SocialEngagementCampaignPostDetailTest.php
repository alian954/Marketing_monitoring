<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\GoogleAnalyticsConnection;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementCampaignPostDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_post_attribution_page_paginates_all_posts_preserves_sort_and_matches_ga4(): void
    {
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-token',
        ]);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Spring launch',
            'social_platform' => 'instagram',
        ]);

        for ($index = 0; $index < 27; $index++) {
            $utmContent = match ($index) {
                0, 26 => 'duplicate-creative',
                25 => 'matched-creative',
                default => 'creative-'.$index,
            };

            $this->createPost($target, $index, $utmContent, now()->subSeconds(26 - $index));
        }

        $this->createGoogleAnalyticsReport($connection, $target, [
            [
                'ad_content' => 'duplicate-creative',
                'sessions' => 99,
            ],
            [
                'ad_content' => 'matched-creative',
                'sessions' => 125,
                'engaged_sessions' => 90,
                'engagement_rate' => '0.72',
                'key_events' => '8.0000',
                'total_revenue' => '12345.6700',
            ],
        ]);

        $pageOne = $this->actingAs($user)
            ->get(route('social-engagement-targets.posts', $target))
            ->assertOk()
            ->assertSee('完整貼文歸因')
            ->assertSee('Campaign post 26')
            ->assertSee('已依 UTM 對應')
            ->assertSee('125')
            ->assertSee('TWD 12,345.67')
            ->assertSee('page=2')
            ->assertDontSee('Campaign post 00');

        $pageOne->assertSee(route('social-engagement-targets.report', $target));

        $this->get(route('social-engagement-targets.posts', $target).'?page=2')
            ->assertOk()
            ->assertSee('Campaign post 00')
            ->assertSee('Campaign post 01')
            ->assertSee('多篇貼文使用此素材值')
            ->assertDontSee('Campaign post 26');

        $this->get(route('social-engagement-targets.posts', $target).'?sort=name')
            ->assertOk()
            ->assertSeeInOrder(['Campaign post 00', 'Campaign post 01', 'Campaign post 02'])
            ->assertSee('sort=name&amp;page=2', false)
            ->assertDontSee('Campaign post 26');
    }

    public function test_campaign_period_uses_taipei_boundaries_and_excludes_wrong_platform_unknown_date_and_stale_config(): void
    {
        $user = User::factory()->create();
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'instagram',
            'starts_on' => '2026-11-16',
            'ends_on' => '2026-11-16',
        ]);

        $this->createPost($target, 1, 'start-inclusive', '2026-11-15T16:00:00Z');
        $this->createPost($target, 2, 'end-inclusive', '2026-11-16T15:59:59Z');
        $this->createPost($target, 3, 'within-period', '2026-11-16T08:00:00Z');
        $this->createPost($target, 4, 'before-period', '2026-11-15T15:59:59Z');
        $this->createPost($target, 5, 'after-period', '2026-11-16T16:00:00Z');
        $this->createPost($target, 6, 'unknown-date', null);
        $this->createPost($target, 7, 'other-platform', '2026-11-16T08:00:00Z', platform: 'facebook');
        $this->createPost($target, 8, 'stale-config', '2026-11-16T08:00:00Z', configVersion: 2, runConfigVersion: 1);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.posts', $target))
            ->assertOk()
            ->assertSee('共 3 篇貼文')
            ->assertSee('Campaign post 01')
            ->assertSee('Campaign post 02')
            ->assertSee('Campaign post 03')
            ->assertDontSee('Campaign post 04')
            ->assertDontSee('Campaign post 05')
            ->assertDontSee('Campaign post 06')
            ->assertDontSee('Campaign post 07')
            ->assertDontSee('Campaign post 08');
    }

    public function test_publish_sort_uses_the_publication_date_from_the_latest_capture_and_invalid_sort_falls_back(): void
    {
        $user = User::factory()->create();
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Autumn launch',
            'social_platform' => 'instagram',
        ]);
        $olderPost = $this->createPost($target, 1, null, '2026-11-14T08:00:00Z', 'Post A');
        CheckRun::factory()->for($olderPost)->create([
            'config_version' => $olderPost->config_version,
            'finished_at' => '2026-11-21T12:00:00Z',
            'response_published_at' => '2026-11-16T08:00:00Z',
            'response_public_engagement_metrics' => ['likes' => 4, 'comments' => 1],
        ]);
        $this->createPost($target, 2, null, '2026-11-15T08:00:00Z', 'Post B');

        $this->actingAs($user)
            ->get(route('social-engagement-targets.posts', $target).'?sort=invalid')
            ->assertOk()
            ->assertSeeInOrder(['Post B', 'Post A']);
    }

    public function test_full_post_attribution_page_requires_authentication(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->get(route('social-engagement-targets.posts', $target))
            ->assertRedirect(route('login'));
    }

    public function test_campaign_report_links_to_the_complete_post_attribution_page(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Winter launch',
            'social_platform' => 'instagram',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee(route('social-engagement-targets.posts', $target))
            ->assertSee('完整貼文歸因');
    }

    private function createPost(
        SocialEngagementTarget $target,
        int $index,
        ?string $utmContent,
        mixed $publishedAt,
        ?string $name = null,
        ?string $platform = null,
        int $configVersion = 1,
        ?int $runConfigVersion = null,
    ): Monitor {
        $url = match ($platform ?? $target->social_platform) {
            'facebook' => 'https://www.facebook.com/posts/campaign-'.$index,
            'threads' => 'https://www.threads.com/@campaign/post/'.$index,
            'dcard' => 'https://www.dcard.tw/f/makeup/p/'.$index,
            default => 'https://www.instagram.com/reel/campaign-'.$index.'/',
        };
        $monitor = Monitor::factory()->create([
            'name' => $name ?? sprintf('Campaign post %02d', $index),
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $target->tag,
            'utm_content' => $utmContent,
            'config_version' => $configVersion,
        ]);

        CheckRun::factory()->for($monitor)->create([
            'config_version' => $runConfigVersion ?? $monitor->config_version,
            'status' => 'completed',
            'response_published_at' => $publishedAt,
            'response_public_engagement_metrics' => [
                'views' => 1000,
                'likes' => 5,
                'comments' => 3,
                'shares' => 1,
                'metric_sources' => [
                    'views' => 'public_page',
                    'likes' => 'public_page',
                    'comments' => 'public_page',
                    'shares' => 'public_page',
                ],
            ],
        ]);

        return $monitor;
    }

    /** @param list<array<string, int|string>> $rows */
    private function createGoogleAnalyticsReport(
        GoogleAnalyticsConnection $connection,
        SocialEngagementTarget $target,
        array $rows,
    ): SocialEngagementTargetGoogleAnalyticsReport {
        return SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'attribution_mode' => 'manual_utm',
            'campaign_id' => null,
            'source_name' => null,
            'medium_name' => null,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'sessions' => 125,
            'engaged_sessions' => 90,
            'key_events' => '8.0000',
            'total_revenue' => '12345.6700',
            'currency_code' => 'TWD',
            'ad_content_report' => [
                'status' => 'complete',
                'currency_code' => 'TWD',
                'truncated' => false,
                'rows' => $rows,
            ],
            'ad_content_report_expires_at' => now()->addDay(),
            'synced_at' => now(),
        ]);
    }
}
