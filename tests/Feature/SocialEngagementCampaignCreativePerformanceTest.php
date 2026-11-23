<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\GoogleAnalyticsConnection;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use App\Services\SocialEngagementCampaignCreativePerformanceReport;
use App\Services\SocialPostGoogleAnalyticsAttributionReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementCampaignCreativePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creative_groups_aggregate_all_posts_and_attribute_ga4_once_per_utm_value(): void
    {
        $user = User::factory()->create();
        $connection = $this->createGoogleAnalyticsConnection($user);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Spring launch',
            'social_platform' => 'instagram',
        ]);

        for ($index = 0; $index < 27; $index++) {
            $utmContent = match ($index) {
                0, 26 => 'shared-creative',
                25 => 'matched-creative',
                24 => null,
                default => 'creative-'.$index,
            };

            $this->createPost($target, $index, $utmContent, now()->subSeconds(26 - $index));
        }

        $this->createGoogleAnalyticsReport($connection, $target, [
            ['ad_content' => 'shared-creative', 'sessions' => 99, 'engaged_sessions' => 50, 'key_events' => '7.0000', 'total_revenue' => '45.0000'],
            ['ad_content' => 'matched-creative', 'sessions' => 125, 'engaged_sessions' => 90, 'key_events' => '8.0000', 'total_revenue' => '12345.6700'],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.posts', $target))
            ->assertOk()
            ->assertSee('素材成效彙總')
            ->assertSee('完整互動合計')
            ->assertViewHas('creativeGroups', function (array $groups): bool {
                $sharedCreative = collect($groups)->firstWhere('utm_content', 'shared-creative');
                $matchedCreative = collect($groups)->firstWhere('utm_content', 'matched-creative');
                $unconfiguredCreative = collect($groups)->firstWhere('utm_content', null);

                $this->assertNotNull($sharedCreative);
                $this->assertSame(2, $sharedCreative['post_count']);
                $this->assertSame(2, $sharedCreative['complete_read_count']);
                $this->assertSame(16, $sharedCreative['public_interaction_total']);
                $this->assertSame(8.0, $sharedCreative['average_public_interactions']);
                $this->assertSame('matched', $sharedCreative['google_analytics_attribution']['status']);
                $this->assertSame(99, $sharedCreative['google_analytics_attribution']['sessions']);
                $this->assertSame('7.0000', $sharedCreative['google_analytics_attribution']['key_events']);

                $this->assertNotNull($matchedCreative);
                $this->assertSame(125, $matchedCreative['google_analytics_attribution']['sessions']);

                $this->assertNotNull($unconfiguredCreative);
                $this->assertSame(1, $unconfiguredCreative['post_count']);
                $this->assertSame('not_configured', $unconfiguredCreative['google_analytics_attribution']['status']);

                return true;
            })
            ->assertViewHas('posts', function (array $posts): bool {
                foreach ($posts as $post) {
                    if ($post['utm_content'] === 'shared-creative') {
                        $this->assertSame('duplicate_utm_content', $post['google_analytics_attribution']['status']);
                        $this->assertNull($post['google_analytics_attribution']['sessions']);
                    }
                }

                return true;
            });

        $response->assertSee('共用相同素材值的貼文會合併互動');
    }

    public function test_creative_groups_follow_campaign_dates_but_keep_ga4_values_at_the_group_level(): void
    {
        $user = User::factory()->create();
        $connection = $this->createGoogleAnalyticsConnection($user);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'instagram',
            'starts_on' => '2026-11-16',
            'ends_on' => '2026-11-16',
        ]);

        $this->createPost($target, 1, 'same-campaign-creative', '2026-11-15T16:00:00Z');
        $this->createPost($target, 2, 'same-campaign-creative', '2026-11-16T15:59:59Z');
        $this->createPost($target, 3, 'same-campaign-creative', '2026-11-16T16:00:00Z');
        $this->createPost($target, 4, 'same-campaign-creative', null);
        $this->createPost($target, 5, 'stale-config', '2026-11-16T08:00:00Z', configVersion: 2, runConfigVersion: 1);
        $this->createPost($target, 6, 'other-platform', '2026-11-16T08:00:00Z', platform: 'facebook');
        $this->createGoogleAnalyticsReport($connection, $target, [
            ['ad_content' => 'same-campaign-creative', 'sessions' => 64],
        ]);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.posts', $target))
            ->assertOk()
            ->assertViewHas('creativeGroups', function (array $groups): bool {
                $creative = collect($groups)->firstWhere('utm_content', 'same-campaign-creative');

                $this->assertNotNull($creative);
                $this->assertSame(2, $creative['post_count']);
                $this->assertSame(16, $creative['public_interaction_total']);
                $this->assertSame(64, $creative['google_analytics_attribution']['sessions']);
                $this->assertCount(1, $groups);

                return true;
            });
    }

    public function test_creative_ga4_status_distinguishes_expired_truncated_and_ambiguous_reports(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Autumn launch',
            'social_platform' => 'instagram',
        ]);
        $this->createPost($target, 1, 'creative-a', now());
        $this->createPost($target, 2, 'creative-b', now());
        $this->createPost($target, 3, 'creative-c', now());
        $service = app(SocialEngagementCampaignCreativePerformanceReport::class);
        $attributionReport = app(SocialPostGoogleAnalyticsAttributionReport::class);

        $expired = $service->build(
            $target,
            ['status' => 'complete', 'truncated' => false, 'rows' => [['ad_content' => 'creative-a', 'sessions' => 3]]],
            CarbonImmutable::now()->subSecond(),
            $attributionReport,
        );
        $truncated = $service->build(
            $target,
            ['status' => 'complete', 'truncated' => true, 'rows' => []],
            CarbonImmutable::now()->addDay(),
            $attributionReport,
        );
        $unmatched = $service->build(
            $target,
            ['status' => 'complete', 'truncated' => false, 'rows' => []],
            CarbonImmutable::now()->addDay(),
            $attributionReport,
        );
        $ambiguous = $service->build(
            $target,
            [
                'status' => 'complete',
                'truncated' => false,
                'rows' => [
                    ['ad_content' => 'creative-c', 'sessions' => 4],
                    ['ad_content' => 'creative-c', 'sessions' => 5],
                ],
            ],
            CarbonImmutable::now()->addDay(),
            $attributionReport,
        );

        $this->assertSame('report_expired', $this->creativeByUtm($expired['groups'], 'creative-a')['google_analytics_attribution']['status']);
        $this->assertNull($this->creativeByUtm($expired['groups'], 'creative-a')['google_analytics_attribution']['sessions']);
        $this->assertSame('ga4_report_truncated', $this->creativeByUtm($truncated['groups'], 'creative-b')['google_analytics_attribution']['status']);
        $this->assertSame('no_ga4_match', $this->creativeByUtm($unmatched['groups'], 'creative-b')['google_analytics_attribution']['status']);
        $this->assertSame('ambiguous_ga4_rows', $this->creativeByUtm($ambiguous['groups'], 'creative-c')['google_analytics_attribution']['status']);
    }

    private function createGoogleAnalyticsConnection(User $user): GoogleAnalyticsConnection
    {
        return GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-token',
        ]);
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
            'starts_on' => $target->starts_on?->toDateString() ?? '2026-11-01',
            'ends_on' => $target->ends_on?->toDateString() ?? '2026-11-30',
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

    private function createPost(
        SocialEngagementTarget $target,
        int $index,
        ?string $utmContent,
        mixed $publishedAt,
        ?string $platform = null,
        int $configVersion = 1,
        ?int $runConfigVersion = null,
    ): Monitor {
        $url = ($platform ?? $target->social_platform) === 'facebook'
            ? 'https://www.facebook.com/posts/creative-'.$index
            : 'https://www.instagram.com/reel/creative-'.$index.'/';
        $monitor = Monitor::factory()->create([
            'name' => 'Creative post '.$index,
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
            ],
        ]);

        return $monitor;
    }

    /** @param list<array<string, mixed>> $groups
     * @return array<string, mixed>
     */
    private function creativeByUtm(array $groups, string $utmContent): array
    {
        foreach ($groups as $group) {
            if ($group['utm_content'] === $utmContent) {
                return $group;
            }
        }

        $this->fail('Expected creative group was not found.');
    }
}
