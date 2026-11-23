<?php

namespace Tests\Feature;

use App\Services\GoogleAnalyticsDataApi;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAnalyticsAdContentPerformanceReportTest extends TestCase
{
    public function test_campaign_ad_content_report_queries_manual_utm_content_and_normalizes_metrics(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReports([
            'metadata' => ['currencyCode' => 'TWD'],
            'rowCount' => 2,
            'rows' => [
                $this->adContentRow('video-15s-blue', '120', '90', '0.75', '10', '500.25'),
                $this->adContentRow('(not set)', '20', '4', '0.2', '1', '10'),
            ],
        ]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'spring-launch',
            '2026-11-01',
            '2026-11-30',
            'facebook',
            'paid_social',
        );

        $this->assertSame([
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [
                [
                    'ad_content' => 'video-15s-blue',
                    'sessions' => 120,
                    'engaged_sessions' => 90,
                    'engagement_rate' => '0.7500',
                    'key_events' => '10.0000',
                    'total_revenue' => '500.2500',
                ],
                [
                    'ad_content' => '(not set)',
                    'sessions' => 20,
                    'engaged_sessions' => 4,
                    'engagement_rate' => '0.2000',
                    'key_events' => '1.0000',
                    'total_revenue' => '10.0000',
                ],
            ],
        ], $report['ad_content_report']);

        Http::assertSentCount(6);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dateRanges'] === [[
                'startDate' => '2026-11-01',
                'endDate' => '2026-11-22',
            ]]
            && $request->data()['dimensions'] === [['name' => 'sessionManualAdContent']]
            && $request->data()['metrics'] === [
                ['name' => 'sessions'],
                ['name' => 'engagedSessions'],
                ['name' => 'engagementRate'],
                ['name' => 'keyEvents'],
                ['name' => 'totalRevenue'],
            ]
            && $request->data()['dimensionFilter'] === [
                'andGroup' => ['expressions' => [
                    $this->manualFilter('sessionManualCampaignName', 'spring-launch'),
                    $this->manualFilter('sessionManualSource', 'facebook'),
                    $this->manualFilter('sessionManualMedium', 'paid_social'),
                ]],
            ]
            && $request->data()['orderBys'] === [
                ['metric' => ['metricName' => 'sessions'], 'desc' => true],
                ['metric' => ['metricName' => 'totalRevenue'], 'desc' => true],
                ['dimension' => ['dimensionName' => 'sessionManualAdContent']],
            ]
            && $request->data()['limit'] === 201
            && $request->hasHeader('Authorization', 'Bearer access-token'));
    }

    public function test_campaign_ad_content_report_uses_campaign_end_date_when_it_is_in_the_past(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReports(['rows' => []]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'past-campaign',
            '2026-11-01',
            '2026-11-10',
        );

        $this->assertSame('2026-11-10', $report['ad_content_report']['window_end_on']);
        Http::assertSent(fn (ClientRequest $request): bool => ($request->data()['dimensions'] ?? null) === [['name' => 'sessionManualAdContent']]
            && $request->data()['dateRanges'] === [[
                'startDate' => '2026-11-01',
                'endDate' => '2026-11-10',
            ]]);
    }

    public function test_campaign_ad_content_report_is_unavailable_without_completed_campaign_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReports(['rows' => []]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'future-campaign',
            '2026-11-23',
            '2026-11-30',
        );

        $this->assertSame([
            'status' => 'unavailable',
            'window_start_on' => null,
            'window_end_on' => null,
            'currency_code' => null,
            'truncated' => false,
            'rows' => [],
        ], $report['ad_content_report']);
        Http::assertSentCount(4);
        Http::assertNotSent(fn (ClientRequest $request): bool => ($request->data()['dimensions'] ?? null) === [['name' => 'sessionManualAdContent']]);
    }

    public function test_google_ads_auto_tagged_campaign_does_not_request_manual_ad_content(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReports(['rows' => []]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'spring-launch',
            '2026-11-01',
            '2026-11-30',
            googleAdsCampaignId: '987654321',
        );

        $this->assertSame([
            'status' => 'not_applicable',
            'window_start_on' => null,
            'window_end_on' => null,
            'currency_code' => null,
            'truncated' => false,
            'rows' => [],
        ], $report['ad_content_report']);
        Http::assertSentCount(5);
        Http::assertNotSent(fn (ClientRequest $request): bool => ($request->data()['dimensions'] ?? null) === [['name' => 'sessionManualAdContent']]);
    }

    public function test_thresholded_ad_content_report_returns_currency_without_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReports([
            'metadata' => ['currencyCode' => 'TWD', 'subjectToThresholding' => true],
            'rows' => [],
        ]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'spring-launch',
            '2026-11-01',
            '2026-11-30',
        );

        $this->assertSame('thresholded', $report['ad_content_report']['status']);
        $this->assertSame('TWD', $report['ad_content_report']['currency_code']);
        $this->assertSame([], $report['ad_content_report']['rows']);
    }

    public function test_ad_content_api_failure_does_not_block_campaign_totals(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReports([], adContentStatus: 400);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'spring-launch',
            '2026-11-01',
            '2026-11-30',
        );

        $this->assertSame(12, $report['sessions']);
        $this->assertSame('unavailable', $report['ad_content_report']['status']);
        $this->assertSame([], $report['ad_content_report']['rows']);
    }

    public function test_malformed_ad_content_rows_become_unavailable_without_blocking_totals(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReports([
            'rows' => [[
                'dimensionValues' => [['value' => 'bad-content']],
                'metricValues' => [['value' => '120']],
            ]],
        ]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'spring-launch',
            '2026-11-01',
            '2026-11-30',
        );

        $this->assertSame(12, $report['sessions']);
        $this->assertSame('unavailable', $report['ad_content_report']['status']);
        $this->assertSame([], $report['ad_content_report']['rows']);
    }

    public function test_ad_content_report_caps_rows_and_marks_ga4_aggregated_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $rows = [];

        for ($index = 0; $index < 201; $index++) {
            $rows[] = $this->adContentRow('creative_'.$index, (string) (201 - $index), '0', '0', '0', '0');
        }

        $this->fakeCampaignReports([
            'metadata' => ['dataLossFromOtherRow' => true],
            'rowCount' => 205,
            'rows' => $rows,
        ]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'spring-launch',
            '2026-11-01',
            '2026-11-30',
        );

        $this->assertTrue($report['ad_content_report']['truncated']);
        $this->assertCount(200, $report['ad_content_report']['rows']);
        $this->assertSame('creative_199', $report['ad_content_report']['rows'][199]['ad_content']);
    }

    /** @param array<string, mixed> $adContentPayload */
    private function fakeCampaignReports(array $adContentPayload, int $adContentStatus = 200): void
    {
        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => function (ClientRequest $request) use ($adContentPayload, $adContentStatus) {
                $dimensions = $request->data()['dimensions'] ?? [];

                if ($dimensions === [['name' => 'sessionManualAdContent']]) {
                    return Http::response($adContentPayload, $adContentStatus);
                }

                if (in_array($dimensions, [
                    [['name' => 'date']],
                    [['name' => 'landingPage']],
                    [['name' => 'eventName']],
                    [['name' => 'itemName']],
                ], true)) {
                    return Http::response(['rows' => []]);
                }

                return Http::response([
                    'rows' => [],
                    'totals' => [[
                        'metricValues' => [
                            ['value' => '12'],
                            ['value' => '8'],
                            ['value' => '2'],
                            ['value' => '100'],
                        ],
                    ]],
                    'rowCount' => 0,
                    'metadata' => ['currencyCode' => 'TWD'],
                ]);
            },
        ]);
    }

    /** @return array<string, mixed> */
    private function adContentRow(string $content, string $sessions, string $engaged, string $rate, string $events, string $revenue): array
    {
        return [
            'dimensionValues' => [['value' => $content]],
            'metricValues' => [
                ['value' => $sessions],
                ['value' => $engaged],
                ['value' => $rate],
                ['value' => $events],
                ['value' => $revenue],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function manualFilter(string $dimension, string $value): array
    {
        return [
            'filter' => [
                'fieldName' => $dimension,
                'stringFilter' => [
                    'matchType' => 'EXACT',
                    'value' => $value,
                    'caseSensitive' => true,
                ],
            ],
        ];
    }
}
