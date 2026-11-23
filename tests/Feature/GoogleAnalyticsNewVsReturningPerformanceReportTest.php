<?php

namespace Tests\Feature;

use App\Models\GoogleAnalyticsCampaignSnapshot;
use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use App\Services\GoogleAnalyticsCampaignReportSync;
use App\Services\GoogleAnalyticsDataApi;
use App\Services\GoogleAnalyticsNewVsReturningPerformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAnalyticsNewVsReturningPerformanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_report_queries_and_normalizes_new_and_returning_visitors(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReport([
            'metadata' => ['currencyCode' => 'TWD'],
            'rowCount' => 2,
            'rows' => [
                $this->visitorRow('new', '100', '80', '5.25', '250.50'),
                $this->visitorRow('returning', '40', '30', '3', '150'),
            ],
        ]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token', '123456789', 'spring-launch', '2026-11-01', '2026-11-30', 'facebook', 'paid_social',
        );

        $this->assertSame([
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'rows' => [
                ['visitor_type' => 'new', 'sessions' => 100, 'engaged_sessions' => 80, 'key_events' => '5.2500', 'total_revenue' => '250.5000'],
                ['visitor_type' => 'returning', 'sessions' => 40, 'engaged_sessions' => 30, 'key_events' => '3.0000', 'total_revenue' => '150.0000'],
            ],
        ], $report['new_vs_returning_report']);

        Http::assertSentCount(7);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dimensions'] === [['name' => 'newVsReturning']]
            && $request->data()['metrics'] === [
                ['name' => 'sessions'], ['name' => 'engagedSessions'], ['name' => 'keyEvents'], ['name' => 'totalRevenue'],
            ]
            && $request->data()['dateRanges'] === [['startDate' => '2026-11-01', 'endDate' => '2026-11-22']]
            && $request->data()['dimensionFilter'] === ['andGroup' => ['expressions' => [
                $this->campaignFilter('sessionManualCampaignName', 'spring-launch'),
                $this->campaignFilter('sessionManualSource', 'facebook'),
                $this->campaignFilter('sessionManualMedium', 'paid_social'),
            ]]]
            && $request->data()['limit'] === 3
            && $request->hasHeader('Authorization', 'Bearer access-token'));
    }

    public function test_thresholding_does_not_hide_campaign_totals(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReport(['metadata' => ['currencyCode' => 'TWD', 'subjectToThresholding' => true], 'rows' => []]);

        $thresholdedReport = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token', '123456789', 'spring-launch', '2026-11-01', '2026-11-30',
        );
        $this->assertSame('thresholded', $thresholdedReport['new_vs_returning_report']['status']);
        $this->assertSame([], $thresholdedReport['new_vs_returning_report']['rows']);
        $this->assertSame(120, $thresholdedReport['sessions']);
    }

    public function test_google_ads_auto_tagging_uses_its_campaign_id_filter_for_new_vs_returning(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReport(['rows' => []]);

        (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token', '123456789', 'ignored-name', '2026-11-01', '2026-11-30',
            googleAdsCampaignId: '987654321',
        );

        Http::assertSent(fn (ClientRequest $request): bool => ($request->data()['dimensions'] ?? null) === [['name' => 'newVsReturning']]
            && $request->data()['dimensionFilter'] === $this->campaignFilter('sessionGoogleAdsCampaignId', '987654321'));
    }

    public function test_unsupported_breakdown_does_not_hide_campaign_totals(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReport(['error' => ['message' => 'incompatible dimensions']], 400);

        $unavailableReport = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token', '123456789', 'spring-launch', '2026-11-01', '2026-11-30',
        );
        $this->assertSame('unavailable', $unavailableReport['new_vs_returning_report']['status']);
        $this->assertSame(120, $unavailableReport['sessions']);
    }

    public function test_new_vs_returning_query_is_skipped_when_campaign_has_no_completed_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReport(['rows' => []]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token', '123456789', 'future-launch', '2026-11-23', '2026-11-30',
        );

        $this->assertSame('unavailable', $report['new_vs_returning_report']['status']);
        $this->assertNull($report['new_vs_returning_report']['window_start_on']);
        Http::assertNotSent(fn (ClientRequest $request): bool => ($request->data()['dimensions'] ?? null) === [['name' => 'newVsReturning']]);
    }

    public function test_report_builder_sorts_rows_and_ignores_invalid_data(): void
    {
        $report = app(GoogleAnalyticsNewVsReturningPerformanceReport::class)->build([
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'rows' => [
                ['visitor_type' => 'new', 'sessions' => 100, 'engaged_sessions' => 80, 'key_events' => '4', 'total_revenue' => '50'],
                ['visitor_type' => 'returning', 'sessions' => 50, 'engaged_sessions' => 25, 'key_events' => '8', 'total_revenue' => '250'],
                ['visitor_type' => 'unexpected', 'sessions' => 999, 'engaged_sessions' => 1, 'key_events' => '100', 'total_revenue' => '100'],
                ['visitor_type' => 'new', 'sessions' => -1, 'engaged_sessions' => 0, 'key_events' => '0', 'total_revenue' => '0'],
            ],
        ], CarbonImmutable::now()->addDay(), 'engagement_rate');

        $this->assertSame('complete', $report['status']);
        $this->assertSame('engagement_rate', $report['sort']);
        $this->assertSame(['new', 'returning'], array_column($report['rows'], 'visitor_type'));
        $this->assertSame(['新客', '回訪客'], array_column($report['rows'], 'visitor_label'));
        $this->assertSame('0.8000', $report['rows'][0]['engagement_rate']);
        $this->assertSame('0.5000', $report['rows'][1]['engagement_rate']);

        $expiredReport = app(GoogleAnalyticsNewVsReturningPerformanceReport::class)->build(
            ['status' => 'complete', 'rows' => [['visitor_type' => 'new', 'sessions' => 1, 'engaged_sessions' => 1, 'key_events' => '1', 'total_revenue' => '0']]],
            CarbonImmutable::now()->subSecond(),
        );
        $this->assertSame('expired', $expiredReport['status']);
        $this->assertSame([], $expiredReport['rows']);
    }

    public function test_sync_encrypts_details_and_excludes_them_from_campaign_history(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $api = \Mockery::mock(GoogleAnalyticsDataApi::class);
        $api->shouldReceive('campaignReport')->once()->andReturn($this->campaignMetrics());
        $this->app->instance(GoogleAnalyticsDataApi::class, $api);

        $report = app(GoogleAnalyticsCampaignReportSync::class)->sync($connection, $target, 'access-token');
        $storedReport = $report->fresh();
        $historySnapshot = GoogleAnalyticsCampaignSnapshot::query()->sole();

        $this->assertSame('returning', $storedReport->new_vs_returning_report['rows'][1]['visitor_type']);
        $this->assertTrue($storedReport->new_vs_returning_report_expires_at->isFuture());
        $this->assertStringNotContainsString('returning', $storedReport->getRawOriginal('new_vs_returning_report'));
        $this->assertArrayNotHasKey('new_vs_returning_report', $storedReport->toArray());
        $this->assertArrayNotHasKey('new_vs_returning_report', $historySnapshot->getAttributes());
    }

    public function test_report_page_and_csv_export_show_new_vs_returning_metrics(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'campaign_name' => 'spring-launch',
            'attribution_mode' => 'manual_utm',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'sessions' => 140,
            'engaged_sessions' => 110,
            'key_events' => '8',
            'total_revenue' => '400',
            'currency_code' => 'TWD',
            'source_medium_breakdown' => [],
            'source_medium_breakdown_truncated' => false,
            'daily_metrics' => ['rows' => []],
            'new_vs_returning_report' => [
                'status' => 'complete', 'window_start_on' => '2026-11-01', 'window_end_on' => '2026-11-22', 'currency_code' => 'TWD',
                'rows' => [
                    ['visitor_type' => 'new', 'sessions' => 100, 'engaged_sessions' => 80, 'key_events' => '5.0000', 'total_revenue' => '250.0000'],
                    ['visitor_type' => 'returning', 'sessions' => 40, 'engaged_sessions' => 30, 'key_events' => '3.0000', 'total_revenue' => '150.0000'],
                ],
            ],
            'new_vs_returning_report_expires_at' => CarbonImmutable::now()->addDays(30),
            'synced_at' => CarbonImmutable::now(),
        ]);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('新客與回訪客成效')
            ->assertSee('回訪客')
            ->assertSee('80.00%');

        $csv = $this->get(route('social-engagement-targets.google-analytics.new-vs-returning', $target))
            ->assertDownload('ga4-new-vs-returning-'.$target->getKey().'.csv')
            ->streamedContent();
        $this->assertStringContainsString('visitor_type,sessions,engaged_sessions,engagement_rate,key_events,total_revenue,currency_code,window_start_on,window_end_on', $csv);
        $this->assertStringContainsString('新客,100,80,0.8000,5.0000,250.0000,TWD,2026-11-01,2026-11-22', $csv);
    }

    public function test_sync_command_clears_expired_new_vs_returning_details(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-10']);
        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'campaign_name' => 'spring-launch',
            'attribution_mode' => 'manual_utm',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-10',
            'sessions' => 1,
            'engaged_sessions' => 1,
            'key_events' => '1',
            'total_revenue' => '1',
            'new_vs_returning_report' => ['status' => 'complete', 'rows' => []],
            'new_vs_returning_report_expires_at' => CarbonImmutable::now()->subSecond(),
            'synced_at' => CarbonImmutable::now()->subDays(10),
        ]);

        $this->artisan('google-analytics:sync-due')->assertExitCode(0);
        $this->assertNull($report->fresh()->new_vs_returning_report);
        $this->assertNull($report->fresh()->new_vs_returning_report_expires_at);
    }

    /** @param array<string, mixed> $visitorReport */
    private function fakeCampaignReport(array $visitorReport, int $visitorStatus = 200): void
    {
        Http::fake(function (ClientRequest $request) use ($visitorReport, $visitorStatus) {
            if (array_column($request->data()['dimensions'] ?? [], 'name') === ['newVsReturning']) {
                return Http::response($visitorReport, $visitorStatus);
            }

            return $this->baseReportResponse($request);
        });
    }

    private function baseReportResponse(ClientRequest $request)
    {
        $dimensions = array_column($request->data()['dimensions'] ?? [], 'name');

        if ($dimensions === ['sessionManualCampaignName', 'sessionDefaultChannelGroup', 'sessionManualSource', 'sessionManualMedium']) {
            return Http::response([
                'totals' => [['metricValues' => [
                    ['value' => '120'], ['value' => '90'], ['value' => '8'], ['value' => '400'],
                ]]],
                'rows' => [],
                'metadata' => ['currencyCode' => 'TWD'],
            ]);
        }

        return Http::response(['rows' => [], 'metadata' => ['currencyCode' => 'TWD']]);
    }

    /** @return array{dimensionValues: list<array{value: string}>, metricValues: list<array{value: string}>} */
    private function visitorRow(string $visitorType, string $sessions, string $engagedSessions, string $keyEvents, string $revenue): array
    {
        return [
            'dimensionValues' => [['value' => $visitorType]],
            'metricValues' => [['value' => $sessions], ['value' => $engagedSessions], ['value' => $keyEvents], ['value' => $revenue]],
        ];
    }

    /** @return array<string, mixed> */
    private function campaignMetrics(): array
    {
        return [
            'sessions' => 140,
            'engaged_sessions' => 110,
            'key_events' => '8.0000',
            'total_revenue' => '400.0000',
            'currency_code' => 'TWD',
            'source_medium_breakdown' => [],
            'source_medium_breakdown_truncated' => false,
            'landing_page_report' => ['status' => 'complete', 'window_start_on' => '2026-11-01', 'window_end_on' => '2026-11-30', 'currency_code' => 'TWD', 'truncated' => false, 'rows' => []],
            'event_name_report' => ['status' => 'complete', 'window_start_on' => '2026-11-01', 'window_end_on' => '2026-11-30', 'currency_code' => 'TWD', 'truncated' => false, 'rows' => []],
            'item_report' => ['status' => 'complete', 'window_start_on' => '2026-11-01', 'window_end_on' => '2026-11-30', 'currency_code' => 'TWD', 'truncated' => false, 'rows' => []],
            'ad_content_report' => ['status' => 'complete', 'window_start_on' => '2026-11-01', 'window_end_on' => '2026-11-30', 'currency_code' => 'TWD', 'truncated' => false, 'rows' => []],
            'new_vs_returning_report' => [
                'status' => 'complete',
                'window_start_on' => '2026-11-01',
                'window_end_on' => '2026-11-22',
                'currency_code' => 'TWD',
                'rows' => [
                    ['visitor_type' => 'new', 'sessions' => 100, 'engaged_sessions' => 80, 'key_events' => '5.0000', 'total_revenue' => '250.0000'],
                    ['visitor_type' => 'returning', 'sessions' => 40, 'engaged_sessions' => 30, 'key_events' => '3.0000', 'total_revenue' => '150.0000'],
                ],
            ],
            'daily_metrics' => [['date' => '2026-11-01', 'sessions' => 1, 'engaged_sessions' => 1, 'key_events' => '1.0000', 'total_revenue' => '1.0000']],
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create($attributes + [
            'tag' => 'Spring launch',
            'social_platform' => 'instagram',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_campaign_name' => 'spring-launch',
        ]);
    }

    private function createConnection(User $user): GoogleAnalyticsConnection
    {
        return GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->getKey(),
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
    }

    /** @return array<string, mixed> */
    private function campaignFilter(string $dimension, string $value): array
    {
        return [
            'filter' => [
                'fieldName' => $dimension,
                'stringFilter' => ['matchType' => 'EXACT', 'value' => $value, 'caseSensitive' => true],
            ],
        ];
    }
}
