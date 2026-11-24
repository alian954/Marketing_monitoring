<?php

namespace Tests\Feature;

use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\User;
use App\Services\GoogleAdsAuctionInsightPerformanceReport;
use App\Services\GoogleAdsCampaignReportSync;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsAuctionInsightsReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_encrypts_auction_insights_and_sets_a_thirty_day_expiry(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        Http::preventStrayRequests();
        Http::fake(fn (ClientRequest $request) => $this->googleAdsResponse($request));

        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('SEARCH', $report->daily_metrics['campaign_channel_type']);
        $this->assertSame('complete', $report->auction_insights['status']);
        $this->assertSame('competitor.example', $report->auction_insights['rows'][0]['domain']);
        $this->assertTrue($report->auction_insights_expires_at->isFuture());
        $this->assertEqualsWithDelta(30, CarbonImmutable::now()->diffInDays($report->auction_insights_expires_at), 1);
        $this->assertStringNotContainsString('competitor.example', $report->getRawOriginal('auction_insights'));
        $this->assertArrayNotHasKey('auction_insights', $report->toArray());
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->data()['query'] ?? '', 'segments.auction_insight_domain'));
    }

    public function test_auction_insight_failure_does_not_block_campaign_metrics(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->data()['query'] ?? '', 'segments.auction_insight_domain')) {
                return Http::response(['error' => ['message' => 'Auction report unavailable.']], 403);
            }

            return $this->googleAdsResponse($request);
        });

        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('250.7500', $report->spend_amount);
        $this->assertSame('unavailable', $report->auction_insights['status']);
        $this->assertNull($report->auction_insights_expires_at);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 1);
    }

    public function test_auction_insight_report_sorts_rows_and_marks_expired_data(): void
    {
        $snapshot = [
            'status' => 'complete',
            'window_start_on' => '2026-10-24',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [
                ['domain' => 'low-share.example', 'impression_share' => 0.0999, 'overlap_rate' => 0.35, 'position_above_rate' => 0.25, 'outranking_share' => 0.0999, 'top_impression_percentage' => 0.4, 'absolute_top_impression_percentage' => 0.2],
                ['domain' => 'high-overlap.example', 'impression_share' => 0.42, 'overlap_rate' => 0.8, 'position_above_rate' => 0.3, 'outranking_share' => 0.65, 'top_impression_percentage' => 0.55, 'absolute_top_impression_percentage' => 0.24],
                ['domain' => 'no-data.example', 'impression_share' => null, 'overlap_rate' => null, 'position_above_rate' => null, 'outranking_share' => null, 'top_impression_percentage' => null, 'absolute_top_impression_percentage' => null],
            ],
        ];
        $builder = new GoogleAdsAuctionInsightPerformanceReport;

        $report = $builder->build($snapshot, CarbonImmutable::now()->addDay(), 'overlap_rate');

        $this->assertSame(['high-overlap.example', 'low-share.example', 'no-data.example'], array_column($report['rows'], 'domain'));
        $this->assertSame('<10%', $report['rows'][1]['impression_share_label']);
        $this->assertSame('<10%', $report['rows'][1]['outranking_share_label']);
        $this->assertSame('expired', $builder->build($snapshot, CarbonImmutable::now()->subSecond())['status']);
    }

    public function test_report_page_and_csv_are_owner_scoped_and_formula_safe(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $report = $this->createReport($connection, $target, [
            'auction_insights' => $this->auctionSnapshot('=1+1.example'),
            'auction_insights_expires_at' => now()->addDays(10),
        ]);

        $this->assertStringNotContainsString('=1+1.example', $report->getRawOriginal('auction_insights'));
        $this->assertArrayNotHasKey('auction_insights', $report->toArray());
        $this->get(route('social-engagement-targets.google-ads.auction-insights', $target))
            ->assertRedirect(route('login'));

        $page = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', [$target, 'google_ads_auction_sort' => 'domain']))
            ->assertOk()
            ->assertSee('Google Ads 搜尋競價洞察')
            ->assertSee('=1+1.example')
            ->assertSee('&lt;10%', false)
            ->assertSee('匯出競價洞察 CSV');
        $this->assertSame('complete', $page->viewData('googleAdsAuctionInsightReport')['status']);

        $csvResponse = $this->get(route('social-engagement-targets.google-ads.auction-insights', $target))
            ->assertDownload('google-ads-auction-insights-'.$target->id.'.csv');
        $csvRows = $this->csvRows($csvResponse->streamedContent());
        $this->assertSame('competitor_domain', $csvRows[0][0]);
        $this->assertSame("'=1+1.example", $csvRows[1][0]);
        $this->assertSame('<10%', $csvRows[1][1]);

        $otherUser = User::factory()->create();
        $this->createConnection($otherUser);
        $this->actingAs($otherUser)
            ->get(route('social-engagement-targets.google-ads.auction-insights', $target))
            ->assertNotFound();
    }

    public function test_expired_auction_insight_data_is_not_exported_and_is_cleared_by_schedule(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Asia/Taipei'));
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget(['ends_on' => '2026-11-15']);
        $report = $this->createReport($connection, $target, [
            'auction_insights' => $this->auctionSnapshot('competitor.example'),
            'auction_insights_expires_at' => now()->subSecond(),
        ]);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.google-ads.auction-insights', $target))
            ->assertNotFound();

        Artisan::call('google-ads:sync-due');

        $this->assertNull($report->fresh()->auction_insights);
        $this->assertNull($report->fresh()->auction_insights_expires_at);
    }

    private function createConnection(User $user): GoogleAdsConnection
    {
        $connection = GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'refresh-token',
            'customer_id' => '1234567890',
            'customer_name' => 'Example Ads',
            'currency_code' => 'TWD',
        ]);

        $this->trackGoogleAdsFixtureConnection($connection);

        return $connection;
    }

    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Winter launch',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'google_ads_campaign_id' => '987654321',
            ...$attributes,
        ]);

        $this->bindGoogleAdsTargetFixture($target);

        return $target;
    }

    private function createReport(
        GoogleAdsConnection $connection,
        SocialEngagementTarget $target,
        array $attributes = [],
    ): SocialEngagementTargetGoogleAdsReport {
        return SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => $connection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Winter launch',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '50.0000',
            'impressions' => 1000,
            'clicks' => 50,
            'conversions' => '2.0000',
            'conversions_value' => '150.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
            ...$attributes,
        ]);
    }

    private function googleAdsResponse(ClientRequest $request): PromiseInterface
    {
        $query = $request->data()['query'] ?? '';

        if (str_contains($query, 'segments.auction_insight_domain')) {
            return Http::response([['results' => [$this->auctionApiRow()]]]);
        }

        if (str_contains($query, 'campaign.name')) {
            return Http::response([[
                'results' => [[
                    'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
                    'campaign' => [
                        'id' => '987654321',
                        'name' => 'Winter launch',
                        'advertisingChannelType' => 'SEARCH',
                    ],
                    'metrics' => [
                        'costMicros' => '250750000',
                        'impressions' => '12000',
                        'clicks' => '480',
                        'conversions' => '12.5',
                        'conversionsValue' => '1003',
                    ],
                ]],
            ]]);
        }

        return Http::response([['results' => []]]);
    }

    /** @return array<string, mixed> */
    private function auctionApiRow(): array
    {
        return [
            'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
            'campaign' => ['id' => '987654321'],
            'segments' => ['auctionInsightDomain' => 'competitor.example'],
            'metrics' => [
                'auctionInsightSearchImpressionShare' => '0.0999',
                'auctionInsightSearchOverlapRate' => '0.35',
                'auctionInsightSearchPositionAboveRate' => '0.25',
                'auctionInsightSearchOutrankingShare' => '0.0999',
                'auctionInsightSearchTopImpressionPercentage' => '0.4',
                'auctionInsightSearchAbsoluteTopImpressionPercentage' => '0.2',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function auctionSnapshot(string $domain): array
    {
        return [
            'status' => 'complete',
            'window_start_on' => '2026-10-24',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [[
                'domain' => $domain,
                'impression_share' => 0.0999,
                'impression_share_is_below_10_percent' => true,
                'overlap_rate' => 0.35,
                'position_above_rate' => 0.25,
                'outranking_share' => 0.0999,
                'outranking_share_is_below_10_percent' => true,
                'top_impression_percentage' => 0.4,
                'absolute_top_impression_percentage' => 0.2,
            ]],
        ];
    }

    /** @return list<list<string>> */
    private function csvRows(string $content): array
    {
        $lines = str_getcsv($content, "\n");

        return array_map(static fn (string $line): array => str_getcsv($line), $lines);
    }
}
