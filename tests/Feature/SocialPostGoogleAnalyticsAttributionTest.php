<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\GoogleAnalyticsConnection;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use App\Services\SocialPostGoogleAnalyticsAttributionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialPostGoogleAnalyticsAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_utm_content_matches_exact_ga4_ad_content_metrics(): void
    {
        $target = $this->campaignTarget();
        $post = $this->socialPost($target, 'video-15s-blue');

        $attributedPosts = app(SocialPostGoogleAnalyticsAttributionReport::class)->attachToPosts(
            $target,
            [['id' => $post->id, 'name' => $post->name]],
            $this->completeAdContentReport('video-15s-blue'),
            now()->addDay()->toImmutable(),
        );

        $attribution = $attributedPosts[0]['google_analytics_attribution'];

        $this->assertSame('matched', $attribution['status']);
        $this->assertSame('video-15s-blue', $attribution['utm_content']);
        $this->assertSame(125, $attribution['sessions']);
        $this->assertSame(90, $attribution['engaged_sessions']);
        $this->assertSame('0.72', $attribution['engagement_rate']);
        $this->assertSame('8.0000', $attribution['key_events']);
        $this->assertSame('12345.6700', $attribution['total_revenue']);
        $this->assertSame('TWD', $attribution['currency_code']);
    }

    public function test_duplicate_campaign_utm_content_is_not_double_attributed(): void
    {
        $target = $this->campaignTarget();
        $topPost = $this->socialPost($target, 'shared-creative');
        $this->socialPost($target, 'shared-creative', 'Another campaign post');

        $attributedPosts = app(SocialPostGoogleAnalyticsAttributionReport::class)->attachToPosts(
            $target,
            [['id' => $topPost->id, 'name' => $topPost->name]],
            $this->completeAdContentReport('shared-creative'),
            now()->addDay()->toImmutable(),
        );

        $attribution = $attributedPosts[0]['google_analytics_attribution'];

        $this->assertSame('duplicate_utm_content', $attribution['status']);
        $this->assertNull($attribution['sessions']);
        $this->assertNull($attribution['total_revenue']);
    }

    public function test_attribution_marks_missing_report_expired_report_and_unmatched_content(): void
    {
        $target = $this->campaignTarget();
        $post = $this->socialPost($target, 'creative-a');
        $service = app(SocialPostGoogleAnalyticsAttributionReport::class);
        $posts = [['id' => $post->id, 'name' => $post->name]];

        $withoutReport = $service->attachToPosts($target, $posts, null, null);
        $expiredReport = $service->attachToPosts($target, $posts, $this->completeAdContentReport('creative-a'), now()->subSecond()->toImmutable());
        $unmatchedReport = $service->attachToPosts($target, $posts, $this->completeAdContentReport('different-creative'), now()->addDay()->toImmutable());
        $truncatedReportData = $this->completeAdContentReport('different-creative');
        $truncatedReportData['truncated'] = true;
        $truncatedReport = $service->attachToPosts($target, $posts, $truncatedReportData, now()->addDay()->toImmutable());

        $this->assertSame('report_unavailable', $withoutReport[0]['google_analytics_attribution']['status']);
        $this->assertSame('report_expired', $expiredReport[0]['google_analytics_attribution']['status']);
        $this->assertSame('no_ga4_match', $unmatchedReport[0]['google_analytics_attribution']['status']);
        $this->assertSame('ga4_report_truncated', $truncatedReport[0]['google_analytics_attribution']['status']);
    }

    public function test_missing_utm_and_ga4_thresholding_are_reported_without_fabricating_metrics(): void
    {
        $target = $this->campaignTarget();
        $postWithoutUtm = $this->socialPost($target, '');
        $postWithoutUtm->forceFill(['utm_content' => null])->save();
        $postWithUtm = $this->socialPost($target, 'thresholded-creative');
        $service = app(SocialPostGoogleAnalyticsAttributionReport::class);

        $withoutUtm = $service->attachToPosts(
            $target,
            [['id' => $postWithoutUtm->id, 'name' => $postWithoutUtm->name]],
            null,
            null,
        );
        $thresholded = $service->attachToPosts(
            $target,
            [['id' => $postWithUtm->id, 'name' => $postWithUtm->name]],
            ['status' => 'thresholded', 'rows' => [], 'currency_code' => 'TWD'],
            null,
        );

        $this->assertSame('not_configured', $withoutUtm[0]['google_analytics_attribution']['status']);
        $this->assertSame('thresholded', $thresholded[0]['google_analytics_attribution']['status']);
        $this->assertNull($thresholded[0]['google_analytics_attribution']['sessions']);
    }

    public function test_campaign_report_displays_per_post_ga4_attribution(): void
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
            'starts_on' => now()->subDays(3)->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString(),
            'ga4_campaign_name' => 'spring-launch',
            'ga4_source_name' => 'instagram',
            'ga4_medium_name' => 'social',
        ]);
        $post = $this->socialPost($target, 'ig-reel-01');
        CheckRun::factory()->for($post)->create([
            'config_version' => $post->config_version,
            'status' => 'completed',
            'response_published_at' => now(),
            'response_public_engagement_metrics' => [
                'views' => 1000,
                'likes' => 80,
                'comments' => 10,
                'shares' => null,
            ],
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => 'spring-launch',
            'attribution_mode' => 'manual_utm',
            'campaign_id' => null,
            'source_name' => 'instagram',
            'medium_name' => 'social',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 100,
            'engaged_sessions' => 80,
            'key_events' => 5,
            'total_revenue' => 1000,
            'currency_code' => 'TWD',
            'ad_content_report' => $this->completeAdContentReport('ig-reel-01'),
            'ad_content_report_expires_at' => now()->addDays(7),
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('GA4 工作階段')
            ->assertSee('GA4 重要事件')
            ->assertSee('ig-reel-01')
            ->assertSee('已依 UTM 對應')
            ->assertSee('1,000.00');
    }

    public function test_changing_only_utm_content_preserves_monitor_configuration_version_and_queued_runs(): void
    {
        $url = 'https://www.instagram.com/reel/reel-utm-edit/';
        $monitor = Monitor::factory()->create([
            'name' => 'Instagram launch post',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => 'Spring launch',
            'expected_text' => 'Launch announcement',
            'utm_content' => 'old-creative',
        ]);
        $queuedRun = CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'status' => 'queued',
        ]);

        $this->actingAs(User::factory()->create())
            ->put(route('monitors.update', $monitor), [
                'name' => $monitor->name,
                'url' => '',
                'label' => $monitor->tagInputValue(),
                'expected_text' => $monitor->expected_text,
                'expected_text_mode' => $monitor->expected_text_mode,
                'utm_content' => 'new-creative',
            ])
            ->assertRedirect(route('monitors.show', $monitor))
            ->assertSessionHasNoErrors();

        $monitor->refresh();

        $this->assertSame('new-creative', $monitor->utm_content);
        $this->assertSame(1, $monitor->config_version);
        $this->assertSame('queued', $queuedRun->fresh()->status);
    }

    private function campaignTarget(): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'instagram',
        ]);
    }

    private function socialPost(SocialEngagementTarget $target, string $utmContent, ?string $name = null): Monitor
    {
        $url = 'https://www.instagram.com/reel/'.fake()->unique()->slug().'/';

        return Monitor::factory()->create([
            'name' => $name ?? 'Campaign post',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $target->tag,
            'expected_text' => 'Campaign announcement',
            'utm_content' => $utmContent,
        ]);
    }

    /** @return array{status: string, currency_code: string, rows: list<array<string, mixed>>} */
    private function completeAdContentReport(string $adContent): array
    {
        return [
            'status' => 'complete',
            'currency_code' => 'TWD',
            'rows' => [[
                'ad_content' => $adContent,
                'sessions' => 125,
                'engaged_sessions' => 90,
                'engagement_rate' => '0.72',
                'key_events' => '8.0000',
                'total_revenue' => '12345.6700',
            ]],
        ];
    }
}
