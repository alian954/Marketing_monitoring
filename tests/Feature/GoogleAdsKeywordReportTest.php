<?php

namespace Tests\Feature;

use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\User;
use App\Services\GoogleAdsApiException;
use App\Services\GoogleAdsCampaignReportSync;
use App\Services\GoogleAdsDataApi;
use App\Services\GoogleAdsKeywordPerformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsKeywordReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_reads_keyword_metrics_and_search_share_diagnostics_for_the_last_thirty_complete_days(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [$this->keywordResult()],
            ]]),
        ]);

        $report = (new GoogleAdsDataApi)->campaignKeywordInsights(
            'access-token', '1234567890', '987654321', '2026-08-01', '2026-12-30', 'TWD', 'SEARCH',
        );

        $this->assertSame('complete', $report['status']);
        $this->assertSame('2026-10-24', $report['window_start_on']);
        $this->assertSame('2026-11-22', $report['window_end_on']);
        $this->assertSame('=2+2', $report['rows'][0]['keyword_text']);
        $this->assertSame('PHRASE', $report['rows'][0]['match_type']);
        $this->assertSame('255.0000', $report['rows'][0]['spend_amount']);
        $this->assertSame(0.0999, $report['rows'][0]['search_impression_share']);
        $this->assertSame(0.9001, $report['rows'][0]['search_budget_lost_impression_share']);
        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_contains($query, 'FROM keyword_view')
                && str_contains($query, 'ad_group_criterion.keyword.text')
                && str_contains($query, 'ad_group_criterion.keyword.match_type')
                && str_contains($query, 'metrics.search_impression_share')
                && str_contains($query, 'metrics.search_budget_lost_impression_share')
                && str_contains($query, 'metrics.search_rank_lost_impression_share')
                && str_contains($query, "segments.date BETWEEN '2026-10-24' AND '2026-11-22'")
                && str_contains($query, 'LIMIT 201');
        });
    }

    public function test_api_caps_keyword_rows_and_skips_unsupported_campaigns_and_future_periods(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        $rows = [];

        for ($index = 0; $index < 201; $index++) {
            $rows[] = $this->keywordResult([
                'adGroupCriterion' => [
                    'criterionId' => (string) (700000000 + $index),
                    'keyword' => ['text' => 'keyword '.$index, 'matchType' => 'EXACT'],
                    'status' => 'ENABLED',
                ],
            ]);
        }

        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([['results' => $rows]]),
        ]);
        $dataApi = new GoogleAdsDataApi;
        $report = $dataApi->campaignKeywordInsights(
            'access-token', '1234567890', '987654321', '2026-10-01', '2026-11-30', 'TWD', 'SEARCH',
        );
        $unsupported = $dataApi->campaignKeywordInsights(
            'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD', 'PERFORMANCE_MAX',
        );
        $future = $dataApi->campaignKeywordInsights(
            'access-token', '1234567890', '987654321', '2026-11-23', '2026-11-30', 'TWD', 'SEARCH',
        );

        $this->assertTrue($report['truncated']);
        $this->assertCount(200, $report['rows']);
        $this->assertSame('unsupported', $unsupported['status']);
        $this->assertSame('no_complete_days', $future['status']);
        Http::assertSentCount(1);
    }

    public function test_api_rejects_keyword_rows_for_another_campaign(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [$this->keywordResult(['campaign' => ['id' => '987654322']])],
            ]]),
        ]);

        $this->expectException(GoogleAdsApiException::class);

        (new GoogleAdsDataApi)->campaignKeywordInsights(
            'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD', 'SEARCH',
        );
    }

    public function test_keyword_report_calculates_metrics_sorts_rows_and_displays_clamped_share_values(): void
    {
        $snapshot = [
            'status' => 'complete',
            'window_start_on' => '2026-10-24',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'truncated' => true,
            'rows' => [
                $this->keywordSnapshotRow('lower return', '20.0000', '1.0000', '20.0000', 0.0999, 0.9001, 0.9001),
                $this->keywordSnapshotRow('higher return', '10.0000', '2.0000', '60.0000', 0.3, 0.2, 0.25),
            ],
        ];

        $report = (new GoogleAdsKeywordPerformanceReport)->build($snapshot, CarbonImmutable::now()->addDay(), 'roas');
        $this->assertSame('higher return', $report['rows'][0]['keyword_text']);
        $this->assertSame(6.0, $report['rows'][0]['roas']);
        $this->assertSame(5.0, $report['rows'][0]['ctr_percent']);
        $this->assertSame('<10%', $report['rows'][1]['search_impression_share_label']);
        $this->assertSame('>90%', $report['rows'][1]['search_budget_lost_impression_share_label']);
        $this->assertSame('已暫停', $report['rows'][1]['criterion_status_label']);
        $this->assertTrue($report['truncated']);

        $expiredReport = (new GoogleAdsKeywordPerformanceReport)->build($snapshot, CarbonImmutable::now()->subSecond());
        $this->assertSame('expired', $expiredReport['status']);
        $this->assertSame([], $expiredReport['rows']);
    }

    public function test_sync_encrypts_keyword_data_and_isolates_keyword_api_failure(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignMetricsPayload())
                ->push([])
                ->push([])
                ->push([])
                ->push([['results' => [$this->keywordResult()]]])
                ->push([])
                ->push([])
                ->push([])
                ->push([]),
        ]);

        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('250.7500', $report->spend_amount);
        $this->assertSame('complete', $report->keyword_performance['status']);
        $this->assertSame('=2+2', $report->keyword_performance['rows'][0]['keyword_text']);
        $this->assertTrue($report->keyword_performance_expires_at->isFuture());
        $this->assertStringNotContainsString('=2+2', $report->getRawOriginal('keyword_performance'));
        $this->assertArrayNotHasKey('keyword_performance', $report->toArray());
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->data()['query'] ?? '', 'FROM keyword_view'));
        Http::assertSentCount(9);
    }

    public function test_keyword_api_failure_does_not_block_campaign_metrics(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignMetricsPayload())
                ->push([])
                ->push([])
                ->push([])
                ->push(['error' => ['message' => 'Keyword report unavailable.']], 403)
                ->push([])
                ->push([])
                ->push([])
                ->push([]),
        ]);
        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('250.7500', $report->spend_amount);
        $this->assertSame('unavailable', $report->keyword_performance['status']);
        $this->assertNull($report->keyword_performance_expires_at);
        $this->assertStringContainsString('Google Ads 專案或帳戶沒有 API 讀取權限', $report->keyword_performance['message']);
    }

    public function test_keyword_report_page_and_csv_are_owner_scoped_and_formula_safe(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $this->createReport($connection, $target, [
            'keyword_performance' => [
                'status' => 'complete',
                'window_start_on' => '2026-10-24',
                'window_end_on' => '2026-11-22',
                'currency_code' => 'TWD',
                'truncated' => false,
                'rows' => [$this->keywordSnapshotRow('=2+2', '25.0000', '0.0000', '0.0000', 0.0999, 0.9001, 0.9001)],
            ],
            'keyword_performance_expires_at' => now()->addDays(30),
        ]);

        $this->get(route('social-engagement-targets.google-ads.keywords', $target))
            ->assertRedirect(route('login'));
        $pageResponse = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', [$target, 'google_ads_keyword_sort' => 'impression_share']))
            ->assertOk();
        $this->assertNotNull($pageResponse->viewData('googleAdsReport'));
        $pageResponse
            ->assertSee('Google Ads 關鍵字成效')
            ->assertSee('搜尋字詞則是使用者實際輸入的查詢')
            ->assertSee('&lt;10%', false)
            ->assertSee('&gt;90%', false);

        $csvResponse = $this->actingAs($user)
            ->get(route('social-engagement-targets.google-ads.keywords', $target))
            ->assertDownload('google-ads-keywords-'.$target->id.'.csv');
        $csvRows = $this->csvRows($csvResponse->streamedContent());
        $this->assertSame('keyword', $csvRows[0][0]);
        $this->assertSame("'=2+2", $csvRows[1][0]);
        $this->assertSame('<10%', $csvRows[1][13]);

        $otherUser = User::factory()->create();
        $otherConnection = $this->createConnection($otherUser);
        $otherTarget = SocialEngagementTarget::factory()->create([
            'tag' => 'Other keyword campaign',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'google_ads_campaign_id' => '987654321',
        ]);
        $this->createReport($otherConnection, $otherTarget, [
            'keyword_performance' => ['status' => 'complete', 'rows' => []],
            'keyword_performance_expires_at' => now()->addDays(30),
        ]);
        $this->actingAs($user)
            ->get(route('social-engagement-targets.google-ads.keywords', $otherTarget))
            ->assertNotFound();
    }

    public function test_scheduled_sync_clears_expired_keyword_snapshots(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $report = $this->createReport($connection, $target, [
            'keyword_performance' => ['status' => 'complete', 'rows' => [$this->keywordSnapshotRow('keyword')]],
            'keyword_performance_expires_at' => now()->subSecond(),
            'synced_at' => now()->subHours(2),
        ]);

        $this->artisan('google-ads:sync-due')
            ->expectsOutputToContain('略過未設定帳戶 0 個，略過近期讀值 1 個')
            ->assertExitCode(0);

        $this->assertNull($report->fresh()->keyword_performance);
        $this->assertNull($report->fresh()->keyword_performance_expires_at);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function keywordResult(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
            'campaign' => ['id' => '987654321'],
            'adGroup' => ['id' => '555555555', 'name' => 'Brand ad group'],
            'adGroupCriterion' => [
                'criterionId' => '700000001',
                'keyword' => ['text' => '=2+2', 'matchType' => 'PHRASE'],
                'status' => 'ENABLED',
            ],
            'metrics' => [
                'costMicros' => '255000000',
                'impressions' => '1000',
                'clicks' => '50',
                'conversions' => '5',
                'conversionsValue' => '100',
                'searchImpressionShare' => '0.0999',
                'searchBudgetLostImpressionShare' => '0.9001',
                'searchRankLostImpressionShare' => '0.25',
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function campaignMetricsPayload(): array
    {
        return [[
            'results' => [[
                'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
                'campaign' => ['id' => '987654321', 'name' => 'Winter launch', 'advertisingChannelType' => 'SEARCH'],
                'metrics' => [
                    'costMicros' => '250750000', 'impressions' => '12000', 'clicks' => '480',
                    'conversions' => '12.5', 'conversionsValue' => '1003',
                ],
            ]],
        ]];
    }

    /** @return array<string, mixed> */
    private function keywordSnapshotRow(
        string $keyword = 'brand exact',
        string $spend = '20.0000',
        string $conversions = '1.0000',
        string $value = '20.0000',
        ?float $impressionShare = 0.25,
        ?float $budgetLostShare = 0.2,
        ?float $rankLostShare = 0.25,
    ): array {
        return [
            'keyword_text' => $keyword,
            'match_type' => 'EXACT',
            'criterion_status' => $keyword === 'lower return' ? 'PAUSED' : 'ENABLED',
            'criterion_id' => '700000001',
            'ad_group_id' => '555555555',
            'ad_group_name' => 'Brand ad group',
            'impressions' => 100,
            'clicks' => 5,
            'spend_amount' => $spend,
            'conversions' => $conversions,
            'conversions_value' => $value,
            'search_impression_share' => $impressionShare,
            'search_budget_lost_impression_share' => $budgetLostShare,
            'search_rank_lost_impression_share' => $rankLostShare,
        ];
    }

    private function createTarget(): SocialEngagementTarget
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Winter launch',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'google_ads_campaign_id' => '987654321',
        ]);

        $this->bindGoogleAdsTargetFixture($target);

        return $target;
    }

    private function createConnection(User $user): GoogleAdsConnection
    {
        $connection = GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'authorized-refresh-token',
            'customer_id' => '1234567890',
            'customer_name' => 'Example Ads',
            'currency_code' => 'TWD',
        ]);

        $this->trackGoogleAdsFixtureConnection($connection);

        return $connection;
    }

    /** @param array<string, mixed> $attributes */
    private function createReport(
        GoogleAdsConnection $connection,
        SocialEngagementTarget $target,
        array $attributes = [],
    ): SocialEngagementTargetGoogleAdsReport {
        return SocialEngagementTargetGoogleAdsReport::query()->create(array_merge([
            'google_ads_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => $connection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Winter launch',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'clicks' => 40,
            'conversions' => '2.0000',
            'conversions_value' => '200.0000',
            'currency_code' => 'TWD',
            'daily_metrics' => ['status' => 'complete', 'rows' => []],
            'synced_at' => now(),
        ], $attributes));
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }
}
