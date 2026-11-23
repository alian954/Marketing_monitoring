<?php

namespace Tests\Feature;

use App\Services\GoogleAnalyticsDataApi;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAnalyticsItemPerformanceReportTest extends TestCase
{
    public function test_campaign_item_report_queries_completed_campaign_days_and_normalizes_item_metrics(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReports([
            'metadata' => ['currencyCode' => 'TWD'],
            'rowCount' => 1,
            'rows' => [
                $this->itemRow('Lipstick set', '12', '5', '2', '990.5'),
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
            'rows' => [[
                'item_name' => 'Lipstick set',
                'items_viewed' => 12,
                'items_added_to_cart' => 5,
                'items_purchased' => 2,
                'item_revenue' => '990.5000',
            ]],
        ], $report['item_report']);

        Http::assertSentCount(7);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dateRanges'] === [[
                'startDate' => '2026-11-01',
                'endDate' => '2026-11-22',
            ]]
            && $request->data()['dimensions'] === [['name' => 'itemName']]
            && $request->data()['metrics'] === [
                ['name' => 'itemsViewed'],
                ['name' => 'itemsAddedToCart'],
                ['name' => 'itemsPurchased'],
                ['name' => 'itemRevenue'],
            ]
            && $request->data()['dimensionFilter'] === [
                'andGroup' => ['expressions' => [
                    [
                        'filter' => [
                            'fieldName' => 'sessionManualCampaignName',
                            'stringFilter' => [
                                'matchType' => 'EXACT',
                                'value' => 'spring-launch',
                                'caseSensitive' => true,
                            ],
                        ],
                    ],
                    [
                        'filter' => [
                            'fieldName' => 'sessionManualSource',
                            'stringFilter' => [
                                'matchType' => 'EXACT',
                                'value' => 'facebook',
                                'caseSensitive' => true,
                            ],
                        ],
                    ],
                    [
                        'filter' => [
                            'fieldName' => 'sessionManualMedium',
                            'stringFilter' => [
                                'matchType' => 'EXACT',
                                'value' => 'paid_social',
                                'caseSensitive' => true,
                            ],
                        ],
                    ],
                ]],
            ]
            && $request->data()['limit'] === 201
            && $request->hasHeader('Authorization', 'Bearer access-token'));
    }

    public function test_campaign_item_report_stops_at_the_campaign_end_date(): void
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

        $this->assertSame('2026-11-10', $report['item_report']['window_end_on']);
        Http::assertSent(fn (ClientRequest $request): bool => ($request->data()['dimensions'] ?? null) === [['name' => 'itemName']]
            && $request->data()['dateRanges'] === [[
                'startDate' => '2026-11-01',
                'endDate' => '2026-11-10',
            ]]);
    }

    public function test_campaign_item_report_is_unavailable_without_completed_campaign_days(): void
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
        ], $report['item_report']);
        Http::assertSentCount(4);
        Http::assertNotSent(fn (ClientRequest $request): bool => ($request->data()['dimensions'] ?? null) === [['name' => 'itemName']]);
    }

    public function test_unsupported_campaign_item_report_does_not_block_campaign_totals(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReports(['rows' => []], itemStatus: 400);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'spring-launch',
            '2026-11-01',
            '2026-11-30',
        );

        $this->assertSame(12, $report['sessions']);
        $this->assertSame('unavailable', $report['item_report']['status']);
        $this->assertSame('2026-11-01', $report['item_report']['window_start_on']);
        $this->assertSame('2026-11-22', $report['item_report']['window_end_on']);
        Http::assertSentCount(7);
    }

    public function test_campaign_item_report_caps_rows_and_marks_ga4_aggregation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $itemRows = [];

        for ($index = 0; $index < 201; $index++) {
            $itemRows[] = $this->itemRow('product_'.$index, (string) $index, '0', '0', '0');
        }

        $this->fakeCampaignReports([
            'metadata' => ['dataLossFromOtherRow' => true],
            'rowCount' => 205,
            'rows' => $itemRows,
        ]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'spring-launch',
            '2026-11-01',
            '2026-11-30',
        );

        $this->assertTrue($report['item_report']['truncated']);
        $this->assertCount(200, $report['item_report']['rows']);
        $this->assertSame('product_199', $report['item_report']['rows'][199]['item_name']);
    }

    public function test_malformed_campaign_item_rows_are_marked_unavailable(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReports([
            'rows' => [[
                'dimensionValues' => [['value' => 'missing-metric-fields']],
                'metricValues' => [['value' => '10']],
            ]],
        ]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'spring-launch',
            '2026-11-01',
            '2026-11-30',
        );

        $this->assertSame('unavailable', $report['item_report']['status']);
        $this->assertSame([], $report['item_report']['rows']);
    }

    public function test_thresholded_campaign_item_report_preserves_currency_and_returns_no_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $this->fakeCampaignReports([
            'metadata' => [
                'currencyCode' => 'TWD',
                'subjectToThresholding' => true,
            ],
            'rows' => [],
        ]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'spring-launch',
            '2026-11-01',
            '2026-11-30',
        );

        $this->assertSame('thresholded', $report['item_report']['status']);
        $this->assertSame('TWD', $report['item_report']['currency_code']);
        $this->assertSame([], $report['item_report']['rows']);
    }

    /** @param array<string, mixed> $itemPayload */
    private function fakeCampaignReports(array $itemPayload, int $itemStatus = 200): void
    {
        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => function (ClientRequest $request) use ($itemPayload, $itemStatus) {
                $dimensions = $request->data()['dimensions'] ?? [];

                if ($dimensions === [['name' => 'itemName']]) {
                    return Http::response($itemPayload, $itemStatus);
                }

                if (in_array($dimensions, [
                    [['name' => 'date']],
                    [['name' => 'landingPage']],
                    [['name' => 'eventName']],
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

    /** @return array{dimensionValues: list<array{value: string}>, metricValues: list<array{value: string}>} */
    private function itemRow(string $name, string $viewed, string $added, string $purchased, string $revenue): array
    {
        return [
            'dimensionValues' => [['value' => $name]],
            'metricValues' => [
                ['value' => $viewed],
                ['value' => $added],
                ['value' => $purchased],
                ['value' => $revenue],
            ],
        ];
    }
}
