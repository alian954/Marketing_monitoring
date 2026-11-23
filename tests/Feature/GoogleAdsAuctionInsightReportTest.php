<?php

namespace Tests\Feature;

use App\Services\GoogleAdsApiException;
use App\Services\GoogleAdsDataApi;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsAuctionInsightReportTest extends TestCase
{
    public function test_api_reads_search_auction_insights_and_preserves_censored_share_meaning(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [
                    $this->auctionInsightRow('low-share.example', '0.0999', '0.35', '0.25', '0.0999', '0.4', '0.2'),
                    $this->auctionInsightRow('high-overlap.example', '0.42', '0.8', '0.3', '0.65', '0.55', '0.24'),
                    $this->auctionInsightRow('no-data.example', '0', '0', '0', '0', '0', '0', [
                        'metrics' => [
                            'auctionInsightSearchImpressionShare' => null,
                            'auctionInsightSearchOverlapRate' => null,
                            'auctionInsightSearchPositionAboveRate' => null,
                            'auctionInsightSearchOutrankingShare' => null,
                            'auctionInsightSearchTopImpressionPercentage' => null,
                            'auctionInsightSearchAbsoluteTopImpressionPercentage' => null,
                        ],
                    ]),
                ],
            ]]),
        ]);

        $report = (new GoogleAdsDataApi)->campaignAuctionInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2026-08-01',
            '2026-12-30',
            'TWD',
            'SEARCH',
            '2345678901',
        );

        $this->assertSame('complete', $report['status']);
        $this->assertSame('2026-10-24', $report['window_start_on']);
        $this->assertSame('2026-11-22', $report['window_end_on']);
        $this->assertSame('high-overlap.example', $report['rows'][0]['domain']);
        $this->assertSame(0.8, $report['rows'][0]['overlap_rate']);
        $this->assertSame(0.0999, $report['rows'][1]['impression_share']);
        $this->assertTrue($report['rows'][1]['impression_share_is_below_10_percent']);
        $this->assertSame(0.0999, $report['rows'][1]['outranking_share']);
        $this->assertTrue($report['rows'][1]['outranking_share_is_below_10_percent']);
        $this->assertSame(0.35, $report['rows'][1]['overlap_rate']);
        $this->assertSame('no-data.example', $report['rows'][2]['domain']);
        $this->assertNull($report['rows'][2]['overlap_rate']);
        $this->assertFalse($report['rows'][2]['impression_share_is_below_10_percent']);

        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_ends_with($request->url(), '/customers/1234567890/googleAds:searchStream')
                && $request->hasHeader('Authorization', 'Bearer access-token')
                && $request->hasHeader('login-customer-id', '2345678901')
                && str_contains($query, 'segments.auction_insight_domain')
                && str_contains($query, 'metrics.auction_insight_search_impression_share')
                && str_contains($query, 'metrics.auction_insight_search_overlap_rate')
                && str_contains($query, 'metrics.auction_insight_search_position_above_rate')
                && str_contains($query, 'metrics.auction_insight_search_outranking_share')
                && str_contains($query, 'metrics.auction_insight_search_top_impression_percentage')
                && str_contains($query, 'metrics.auction_insight_search_absolute_top_impression_percentage')
                && str_contains($query, "segments.date BETWEEN '2026-10-24' AND '2026-11-22'")
                && str_contains($query, 'ORDER BY metrics.auction_insight_search_overlap_rate DESC')
                && str_contains($query, 'LIMIT 201');
        });
        Http::assertSentCount(1);
    }

    public function test_api_caps_competitor_rows_and_skips_unsupported_campaigns_and_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $rows = [];

        for ($index = 0; $index < 201; $index++) {
            $rows[] = $this->auctionInsightRow('competitor-'.$index.'.example', '0.2', (string) ($index / 1000), '0.3', '0.4', '0.5', '0.1');
        }

        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([['results' => $rows]]),
        ]);

        $dataApi = new GoogleAdsDataApi;
        $report = $dataApi->campaignAuctionInsights(
            'access-token', '1234567890', '987654321', '2026-10-01', '2026-11-30', 'TWD', 'SEARCH',
        );
        $unsupportedCampaign = $dataApi->campaignAuctionInsights(
            'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD', 'PERFORMANCE_MAX',
        );
        $futurePeriod = $dataApi->campaignAuctionInsights(
            'access-token', '1234567890', '987654321', '2026-11-23', '2026-11-30', 'TWD', 'SEARCH',
        );
        $unsupportedHistory = $dataApi->campaignAuctionInsights(
            'access-token', '1234567890', '987654321', '2019-01-01', '2020-01-31', 'TWD', 'SEARCH',
        );

        $this->assertSame('2026-10-24', $report['window_start_on']);
        $this->assertSame('2026-11-22', $report['window_end_on']);
        $this->assertTrue($report['truncated']);
        $this->assertCount(200, $report['rows']);
        $this->assertSame('unsupported', $unsupportedCampaign['status']);
        $this->assertSame('no_complete_days', $futurePeriod['status']);
        $this->assertSame('unsupported', $unsupportedHistory['status']);
        Http::assertSentCount(1);
    }

    public function test_api_rejects_rows_for_another_campaign(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [$this->auctionInsightRow('competitor.example', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7', [
                    'campaign' => ['id' => '987654322'],
                ])],
            ]]),
        ]);

        $this->expectException(GoogleAdsApiException::class);

        (new GoogleAdsDataApi)->campaignAuctionInsights(
            'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD', 'SEARCH',
        );
    }

    public function test_api_rejects_invalid_share_values_and_duplicate_domains(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [$this->auctionInsightRow('competitor.example', '0.08', '0.3', '0.4', '0.5', '0.6', '0.7')],
            ]]),
        ]);

        try {
            (new GoogleAdsDataApi)->campaignAuctionInsights(
                'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD', 'SEARCH',
            );
            $this->fail('Expected GoogleAdsApiException for an invalid impression share.');
        } catch (GoogleAdsApiException $exception) {
            $this->assertStringContainsString('競價洞察曝光比重', $exception->getMessage());
        }

        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [
                    $this->auctionInsightRow('competitor.example', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7'),
                    $this->auctionInsightRow('competitor.example', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7'),
                ],
            ]]),
        ]);

        $this->expectException(GoogleAdsApiException::class);
        (new GoogleAdsDataApi)->campaignAuctionInsights(
            'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD', 'SEARCH',
        );
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function auctionInsightRow(
        string $domain,
        string $impressionShare,
        string $overlapRate,
        string $positionAboveRate,
        string $outrankingShare,
        string $topImpressionPercentage,
        string $absoluteTopImpressionPercentage,
        array $overrides = [],
    ): array {
        return array_replace_recursive([
            'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
            'campaign' => ['id' => '987654321'],
            'segments' => ['auctionInsightDomain' => $domain],
            'metrics' => [
                'auctionInsightSearchImpressionShare' => $impressionShare,
                'auctionInsightSearchOverlapRate' => $overlapRate,
                'auctionInsightSearchPositionAboveRate' => $positionAboveRate,
                'auctionInsightSearchOutrankingShare' => $outrankingShare,
                'auctionInsightSearchTopImpressionPercentage' => $topImpressionPercentage,
                'auctionInsightSearchAbsoluteTopImpressionPercentage' => $absoluteTopImpressionPercentage,
            ],
        ], $overrides);
    }
}
