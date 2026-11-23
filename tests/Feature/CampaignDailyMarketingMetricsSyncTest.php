<?php

namespace Tests\Feature;

use App\Models\GoogleAnalyticsCampaignSnapshot;
use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use App\Services\GoogleAnalyticsCampaignReportSync;
use App\Services\GoogleAnalyticsDataApi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignDailyMarketingMetricsSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_ga4_daily_rows_are_saved_on_the_current_report_without_entering_cumulative_snapshots(): void
    {
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->getKey(),
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Summer campaign',
            'social_platform' => 'instagram',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_campaign_name' => 'summer-launch',
        ]);
        $api = \Mockery::mock(GoogleAnalyticsDataApi::class);
        $api->shouldReceive('campaignReport')
            ->once()
            ->andReturn([
                'sessions' => 120,
                'engaged_sessions' => 90,
                'key_events' => '8.5000',
                'total_revenue' => '1250.7500',
                'currency_code' => 'TWD',
                'source_medium_breakdown' => [],
                'source_medium_breakdown_truncated' => false,
                'landing_page_report' => [
                    'status' => 'complete',
                    'window_start_on' => '2026-11-01',
                    'window_end_on' => '2026-11-30',
                    'currency_code' => 'TWD',
                    'truncated' => false,
                    'rows' => [],
                ],
                'event_name_report' => [
                    'status' => 'complete',
                    'window_start_on' => '2026-11-01',
                    'window_end_on' => '2026-11-30',
                    'currency_code' => 'TWD',
                    'truncated' => false,
                    'rows' => [],
                ],
                'item_report' => [
                    'status' => 'complete',
                    'window_start_on' => '2026-11-01',
                    'window_end_on' => '2026-11-30',
                    'currency_code' => 'TWD',
                    'truncated' => false,
                    'rows' => [],
                ],
                'ad_content_report' => [
                    'status' => 'complete',
                    'window_start_on' => '2026-11-01',
                    'window_end_on' => '2026-11-30',
                    'currency_code' => 'TWD',
                    'truncated' => false,
                    'rows' => [],
                ],
                'new_vs_returning_report' => [
                    'status' => 'complete',
                    'window_start_on' => '2026-11-01',
                    'window_end_on' => '2026-11-22',
                    'currency_code' => 'TWD',
                    'rows' => [],
                ],
                'daily_metrics' => [[
                    'date' => '2026-11-01',
                    'sessions' => 12,
                    'engaged_sessions' => 9,
                    'key_events' => '1.5000',
                    'total_revenue' => '250.7500',
                ]],
            ]);
        $this->app->instance(GoogleAnalyticsDataApi::class, $api);

        $report = app(GoogleAnalyticsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame([
            'status' => 'complete',
            'requested_start_on' => '2026-11-01',
            'requested_end_on' => '2026-11-30',
            'coverage_start_on' => '2026-11-01',
            'coverage_end_on' => '2026-11-30',
            'rows' => [[
                'date' => '2026-11-01',
                'sessions' => 12,
                'engaged_sessions' => 9,
                'key_events' => '1.5000',
                'total_revenue' => '250.7500',
            ]],
        ], $report->fresh()->daily_metrics);
        $this->assertSame('complete', $report->fresh()->landing_page_report['status']);
        $this->assertSame([], $report->fresh()->landing_page_report['rows']);

        $cumulativeSnapshot = GoogleAnalyticsCampaignSnapshot::query()->sole();
        $this->assertSame(120, $cumulativeSnapshot->sessions);
        $this->assertArrayNotHasKey('daily_metrics', $cumulativeSnapshot->getAttributes());
    }

    public function test_ga4_report_is_not_considered_fresh_until_daily_metrics_have_been_synced(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Summer campaign',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_campaign_name' => 'summer-launch',
        ]);
        $legacyReport = new SocialEngagementTargetGoogleAnalyticsReport([
            'attribution_mode' => 'manual_utm',
            'campaign_name' => 'summer-launch',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'sessions' => 10,
            'engaged_sessions' => 8,
            'key_events' => '1.0000',
            'total_revenue' => '20.0000',
            'daily_metrics' => null,
            'synced_at' => CarbonImmutable::now(),
        ]);
        $completedEmptyReport = new SocialEngagementTargetGoogleAnalyticsReport([
            'attribution_mode' => 'manual_utm',
            'campaign_name' => 'summer-launch',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'sessions' => 10,
            'engaged_sessions' => 8,
            'key_events' => '1.0000',
            'total_revenue' => '20.0000',
            'daily_metrics' => [
                'status' => 'complete',
                'requested_start_on' => '2026-11-01',
                'requested_end_on' => '2026-11-30',
                'coverage_start_on' => '2026-11-01',
                'coverage_end_on' => '2026-11-30',
                'rows' => [],
            ],
            'synced_at' => CarbonImmutable::now(),
        ]);

        $sync = app(GoogleAnalyticsCampaignReportSync::class);

        $this->assertFalse($sync->isSnapshotFresh($legacyReport, $target, CarbonImmutable::now()));
        $this->assertTrue($sync->isSnapshotFresh($completedEmptyReport, $target, CarbonImmutable::now()));
    }
}
