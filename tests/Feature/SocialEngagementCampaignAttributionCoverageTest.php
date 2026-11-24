<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Services\SocialEngagementCampaignAttributionCoverageReport;
use App\Services\SocialPostGoogleAnalyticsAttributionReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementCampaignAttributionCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_coverage_counts_period_posts_and_duplicate_utm_values_across_chunks(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 04:00:00', 'UTC'));
        $campaignDate = now()->toDateString();
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'instagram',
            'starts_on' => $campaignDate,
            'ends_on' => $campaignDate,
        ]);

        for ($index = 0; $index < 251; $index++) {
            $utmContent = match ($index) {
                0, 250 => 'duplicate-creative',
                1 => 'matched-creative',
                2 => 'unmatched-creative',
                3 => null,
                4 => 'ambiguous-creative',
                default => 'creative-'.$index,
            };

            $this->createPost($target, $index, $utmContent, now());
        }

        $this->createPost($target, 251, 'outside-period', now()->subDay());
        $this->createPost($target, 252, 'unknown-date', null);
        $this->createPost($target, 253, 'other-platform', now(), 'facebook');

        $summary = app(SocialEngagementCampaignAttributionCoverageReport::class)->build(
            $target,
            [
                'status' => 'complete',
                'truncated' => false,
                'rows' => [
                    ['ad_content' => 'matched-creative', 'sessions' => 25],
                    ['ad_content' => 'ambiguous-creative', 'sessions' => 10],
                    ['ad_content' => 'ambiguous-creative', 'sessions' => 11],
                ],
            ],
            CarbonImmutable::now()->addDay(),
            app(SocialPostGoogleAnalyticsAttributionReport::class),
        );

        $this->assertSame(251, $summary['tracked_post_count']);
        $this->assertSame(250, $summary['utm_configured_post_count']);
        $this->assertSame(1, $summary['utm_missing_post_count']);
        $this->assertSame(99.6, $summary['utm_coverage_percent']);
        $this->assertSame(248, $summary['ga4_unique_utm_post_count']);
        $this->assertSame(1, $summary['ga4_matched_post_count']);
        $this->assertSame(246, $summary['ga4_no_match_post_count']);
        $this->assertSame(2, $summary['ga4_duplicate_utm_post_count']);
        $this->assertSame(1, $summary['ga4_unresolved_post_count']);
        $this->assertSame(0.4, $summary['ga4_match_coverage_percent']);
        $this->assertSame('available', $summary['ga4_report_status']);
        $this->assertSame(1, $summary['status_counts']['ambiguous_ga4_rows']);
    }

    public function test_ga4_match_coverage_is_not_reported_for_incomplete_or_stale_reports(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'instagram',
        ]);
        $this->createPost($target, 1, 'creative-a', now());

        $cases = [
            [null, null, 'unavailable'],
            [['status' => 'unavailable', 'rows' => []], null, 'unavailable'],
            [['status' => 'thresholded', 'rows' => []], null, 'thresholded'],
            [['status' => 'complete', 'truncated' => false, 'rows' => []], now()->subSecond()->toImmutable(), 'expired'],
            [['status' => 'complete', 'truncated' => true, 'rows' => []], now()->addDay()->toImmutable(), 'truncated'],
        ];

        foreach ($cases as [$adContentReport, $expiresAt, $expectedStatus]) {
            $summary = app(SocialEngagementCampaignAttributionCoverageReport::class)->build(
                $target,
                $adContentReport,
                $expiresAt,
                app(SocialPostGoogleAnalyticsAttributionReport::class),
            );

            $this->assertSame($expectedStatus, $summary['ga4_report_status']);
            $this->assertNull($summary['ga4_match_coverage_percent']);
        }
    }

    public function test_empty_campaign_has_no_utm_or_ga4_coverage_percentages(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Summer launch',
            'social_platform' => 'instagram',
        ]);

        $summary = app(SocialEngagementCampaignAttributionCoverageReport::class)->build(
            $target,
            ['status' => 'complete', 'truncated' => false, 'rows' => []],
            CarbonImmutable::now()->addDay(),
            app(SocialPostGoogleAnalyticsAttributionReport::class),
        );

        $this->assertSame(0, $summary['tracked_post_count']);
        $this->assertNull($summary['utm_coverage_percent']);
        $this->assertNull($summary['ga4_match_coverage_percent']);
    }

    private function createPost(
        SocialEngagementTarget $target,
        int $index,
        ?string $utmContent,
        mixed $publishedAt,
        string $platform = 'instagram',
    ): Monitor {
        $url = match ($platform) {
            'facebook' => 'https://www.facebook.com/posts/campaign-'.$index,
            default => 'https://www.instagram.com/reel/campaign-'.$index.'/',
        };
        $monitor = Monitor::factory()->create([
            'name' => 'Campaign post '.$index,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $target->tag,
            'utm_content' => $utmContent,
        ]);

        CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'status' => 'completed',
            'response_published_at' => $publishedAt,
            'response_public_engagement_metrics' => [
                'views' => 100,
                'likes' => 10,
                'comments' => 2,
                'shares' => 1,
            ],
        ]);

        return $monitor;
    }
}
