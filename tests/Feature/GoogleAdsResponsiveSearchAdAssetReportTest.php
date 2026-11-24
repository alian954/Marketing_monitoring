<?php

namespace Tests\Feature;

use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\User;
use App\Services\GoogleAdsApiException;
use App\Services\GoogleAdsCampaignReportSync;
use App\Services\GoogleAdsDataApi;
use App\Services\GoogleAdsResponsiveSearchAdAssetPerformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsResponsiveSearchAdAssetReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_reads_and_limits_rsa_asset_metrics_for_the_last_thirty_complete_days(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        $rawRows = [];

        for ($index = 0; $index < 201; $index++) {
            $rawRows[] = $this->assetApiRow(
                $index,
                impressions: (string) (201 - $index),
                text: 'Headline '.$index,
            );
        }

        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([['results' => $rawRows]]),
        ]);

        $report = (new GoogleAdsDataApi)->campaignResponsiveSearchAdAssetInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2026-08-01',
            '2026-12-30',
            'TWD',
        );

        $this->assertSame('complete', $report['status']);
        $this->assertSame('2026-10-24', $report['window_start_on']);
        $this->assertSame('2026-11-22', $report['window_end_on']);
        $this->assertSame('TWD', $report['currency_code']);
        $this->assertTrue($report['truncated']);
        $this->assertCount(200, $report['rows']);
        $this->assertSame('Headline 0', $report['rows'][0]['text']);
        $this->assertSame('701000000', $report['rows'][0]['asset_id']);
        $this->assertSame('300000000', $report['rows'][0]['ad_id']);
        $this->assertSame('25.5000', $report['rows'][0]['spend_amount']);
        $this->assertSame('2.0000', $report['rows'][0]['conversions']);
        $this->assertSame('120.0000', $report['rows'][0]['conversions_value']);
        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_ends_with($request->url(), '/customers/1234567890/googleAds:searchStream')
                && $request->hasHeader('Authorization', 'Bearer access-token')
                && str_contains($query, 'asset.text_asset.text')
                && str_contains($query, 'ad_group_ad_asset_view.performance_label')
                && str_contains($query, 'ad_group_ad_asset_view.enabled')
                && str_contains($query, 'ad_group_ad.ad.type,')
                && str_contains($query, "ad_group_ad.ad.type = 'RESPONSIVE_SEARCH_AD'")
                && str_contains($query, 'ad_group_ad_asset_view.enabled = TRUE')
                && str_contains($query, "segments.date BETWEEN '2026-10-24' AND '2026-11-22'")
                && str_contains($query, 'ORDER BY metrics.impressions DESC')
                && str_ends_with($query, 'LIMIT 201');
        });
    }

    public function test_api_skips_unsupported_ranges_and_rejects_mismatched_account_data(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        $dataApi = new GoogleAdsDataApi;

        $futurePeriod = $dataApi->campaignResponsiveSearchAdAssetInsights(
            'access-token', '1234567890', '987654321', '2026-11-23', '2026-12-01', 'TWD',
        );
        $oldPeriod = $dataApi->campaignResponsiveSearchAdAssetInsights(
            'access-token', '1234567890', '987654321', '2019-01-01', '2020-01-31', 'TWD',
        );

        $this->assertSame('no_complete_days', $futurePeriod['status']);
        $this->assertSame('unsupported', $oldPeriod['status']);
        Http::assertNothingSent();

        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [$this->assetApiRow(0, overrides: ['campaign' => ['id' => '987654322']])],
            ]]),
        ]);

        try {
            $dataApi->campaignResponsiveSearchAdAssetInsights(
                'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD',
            );
            $this->fail('A row for another campaign must be rejected.');
        } catch (GoogleAdsApiException $exception) {
            $this->assertStringContainsString('不同帳戶、活動', $exception->getMessage());
        }
    }

    public function test_api_reports_success_when_no_responsive_search_ad_assets_are_available(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([['results' => []]]),
        ]);

        $report = (new GoogleAdsDataApi)->campaignResponsiveSearchAdAssetInsights(
            'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD',
        );

        $this->assertSame('complete', $report['status']);
        $this->assertSame([], $report['rows']);
        $this->assertFalse($report['truncated']);
    }

    public function test_api_rejects_disabled_or_non_responsive_search_ad_rows(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push([['results' => [$this->assetApiRow(0, overrides: [
                    'adGroupAdAssetView' => ['enabled' => false],
                ])]]])
                ->push([['results' => [$this->assetApiRow(1, overrides: [
                    'adGroupAd' => ['ad' => ['type' => 'EXPANDED_TEXT_AD']],
                ])]]]),
        ]);

        $dataApi = new GoogleAdsDataApi;

        foreach ([0, 1] as $index) {
            try {
                $dataApi->campaignResponsiveSearchAdAssetInsights(
                    'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD',
                );
                $this->fail('Disabled and non-responsive search ad assets must be rejected.');
            } catch (GoogleAdsApiException $exception) {
                $this->assertStringContainsString('未啟用或非響應式', $exception->getMessage(), 'Invalid row '.$index.' should be rejected.');
            }
        }
    }

    public function test_sync_encrypts_successful_asset_data_and_expires_it_after_thirty_days(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $dataApi = $this->mockSyncDataApi($this->assetSnapshot());
        app()->instance(GoogleAdsDataApi::class, $dataApi);

        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('complete', $report->responsive_search_ad_asset_performance['status']);
        $this->assertSame('Headline 0', $report->responsive_search_ad_asset_performance['rows'][0]['text']);
        $this->assertTrue($report->responsive_search_ad_asset_performance_expires_at->isFuture());
        $this->assertStringNotContainsString('Headline 0', $report->getRawOriginal('responsive_search_ad_asset_performance'));
        $this->assertArrayNotHasKey('responsive_search_ad_asset_performance', $report->toArray());
    }

    public function test_asset_report_failure_does_not_block_campaign_summary_sync(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $dataApi = $this->mockSyncDataApi(null, true);
        app()->instance(GoogleAdsDataApi::class, $dataApi);

        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('250.7500', $report->spend_amount);
        $this->assertSame('unavailable', $report->responsive_search_ad_asset_performance['status']);
        $this->assertStringContainsString('素材讀取失敗', $report->responsive_search_ad_asset_performance['message']);
        $this->assertNull($report->responsive_search_ad_asset_performance_expires_at);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 1);
    }

    public function test_report_calculates_metrics_sorts_rows_and_hides_expired_data(): void
    {
        $snapshot = $this->assetSnapshot();
        $snapshot['rows'][] = [
            ...$snapshot['rows'][0],
            'asset_id' => '701000002',
            'ad_id' => '300000002',
            'text' => 'Lower impression headline',
            'impressions' => 200,
            'clicks' => 2,
            'spend_amount' => '10.0000',
            'conversions' => '1.0000',
            'conversions_value' => '30.0000',
        ];

        $builder = new GoogleAdsResponsiveSearchAdAssetPerformanceReport;
        $report = $builder->build($snapshot, CarbonImmutable::now()->addDay(), 'roas');

        $this->assertSame('roas', $report['sort']);
        $this->assertSame('Headline 0', $report['rows'][0]['text']);
        $this->assertSame('標題', $report['rows'][0]['field_type_label']);
        $this->assertSame('廣告主提供', $report['rows'][0]['source_label']);
        $this->assertSame('最佳', $report['rows'][0]['performance_label_label']);
        $this->assertSame(5.0, $report['rows'][0]['ctr_percent']);
        $this->assertSame(0.51, $report['rows'][0]['cost_per_click']);
        $this->assertSame(12.75, $report['rows'][0]['cost_per_conversion']);
        $this->assertSame(4.7059, $report['rows'][0]['roas']);
        $this->assertSame('expired', $builder->build($snapshot, CarbonImmutable::now()->subSecond())['status']);
        $this->assertSame([], $builder->build($snapshot, CarbonImmutable::now()->subSecond())['rows']);
    }

    public function test_report_page_csv_export_and_owner_scope_are_correct(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $assetText = '=1+1';
        $snapshot = $this->assetSnapshot($assetText);
        $report = $this->createReport($connection, $target, [
            'responsive_search_ad_asset_performance' => $snapshot,
            'responsive_search_ad_asset_performance_expires_at' => now()->addDays(30),
        ]);

        $this->assertStringNotContainsString($assetText, $report->getRawOriginal('responsive_search_ad_asset_performance'));
        $this->assertArrayNotHasKey('responsive_search_ad_asset_performance', $report->toArray());

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Google Ads 搜尋廣告素材成效')
            ->assertSee('匯出素材 CSV')
            ->assertSee($assetText);

        $csvResponse = $this->get(route('social-engagement-targets.google-ads.responsive-search-ad-assets', $target))->assertOk();
        $csvLines = str_getcsv($csvResponse->streamedContent(), "\n");
        $csvFields = str_getcsv($csvLines[1]);
        $this->assertSame("'".$assetText, $csvFields[5]);

        $otherUser = User::factory()->create();
        $this->createConnection($otherUser);
        $this->actingAs($otherUser)
            ->get(route('social-engagement-targets.google-ads.responsive-search-ad-assets', $target))
            ->assertNotFound();
    }

    public function test_scheduled_sync_clears_expired_asset_performance_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget(['starts_on' => '2026-09-01', 'ends_on' => '2026-10-01']);
        $report = $this->createReport($connection, $target, [
            'responsive_search_ad_asset_performance' => ['status' => 'complete', 'rows' => []],
            'responsive_search_ad_asset_performance_expires_at' => now()->subSecond(),
        ]);

        Artisan::call('google-ads:sync-due');

        $this->assertNull($report->fresh()->responsive_search_ad_asset_performance);
        $this->assertNull($report->fresh()->responsive_search_ad_asset_performance_expires_at);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function assetApiRow(
        int $index,
        string $impressions = '1000',
        string $text = 'Headline 0',
        array $overrides = [],
    ): array {
        return array_replace_recursive([
            'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
            'campaign' => ['id' => '987654321'],
            'adGroup' => ['id' => '500000001', 'name' => 'Brand Search'],
            'adGroupAd' => ['ad' => ['id' => (string) (300000000 + $index), 'type' => 'RESPONSIVE_SEARCH_AD']],
            'asset' => ['id' => (string) (701000000 + $index), 'textAsset' => ['text' => $text]],
            'adGroupAdAssetView' => [
                'enabled' => true,
                'fieldType' => 'HEADLINE',
                'source' => 'ADVERTISER',
                'pinnedField' => 'UNSPECIFIED',
                'performanceLabel' => 'BEST',
            ],
            'metrics' => [
                'costMicros' => '25500000',
                'impressions' => $impressions,
                'clicks' => '50',
                'conversions' => '2',
                'conversionsValue' => '120',
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function assetSnapshot(string $text = 'Headline 0'): array
    {
        return [
            'status' => 'complete',
            'window_start_on' => '2026-10-24',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [[
                'ad_group_id' => '500000001',
                'ad_group_name' => 'Brand Search',
                'ad_id' => '300000000',
                'asset_id' => '701000000',
                'text' => $text,
                'field_type' => 'HEADLINE',
                'source' => 'ADVERTISER',
                'pinned_field' => 'UNSPECIFIED',
                'performance_label' => 'BEST',
                'impressions' => 1000,
                'clicks' => 50,
                'spend_amount' => '25.5000',
                'conversions' => '2.0000',
                'conversions_value' => '120.0000',
            ]],
        ];
    }

    private function mockSyncDataApi(?array $assetSnapshot, bool $assetFailure = false): GoogleAdsDataApi
    {
        $dataApi = \Mockery::mock(GoogleAdsDataApi::class);
        $dataApi->shouldReceive('campaignInsights')->once()->andReturn([
            'campaign_id' => '987654321',
            'campaign_name' => 'Winter campaign',
            'campaign_channel_type' => 'SEARCH',
            'spend_amount' => '250.7500',
            'impressions' => 12000,
            'clicks' => 480,
            'conversions' => '12.5000',
            'conversions_value' => '1003.0000',
            'conversion_actions' => [],
            'currency_code' => 'TWD',
            'daily_metrics' => [],
            'daily_metrics_status' => 'complete',
            'daily_metrics_coverage_start_on' => '2026-11-01',
            'daily_metrics_coverage_end_on' => '2026-11-30',
        ]);
        $dataApi->shouldReceive('campaignSearchTermInsights')->once()->andReturn($this->emptyReportSnapshot());
        $dataApi->shouldReceive('campaignKeywordInsights')->once()->andReturn($this->emptyReportSnapshot());
        $dataApi->shouldReceive('campaignDeliveryUnitInsights')->once()->andReturn($this->emptyReportSnapshot('unit_type', 'ad_group'));
        $dataApi->shouldReceive('campaignDeviceInsights')->once()->andReturn($this->emptyReportSnapshot());
        $dataApi->shouldReceive('campaignAuctionInsights')->once()->andReturn($this->emptyReportSnapshot());

        if ($assetFailure) {
            $dataApi->shouldReceive('campaignResponsiveSearchAdAssetInsights')
                ->once()
                ->andThrow(new GoogleAdsApiException('素材讀取失敗'));
        } else {
            $dataApi->shouldReceive('campaignResponsiveSearchAdAssetInsights')
                ->once()
                ->andReturn($assetSnapshot);
        }

        return $dataApi;
    }

    /** @return array<string, mixed> */
    private function emptyReportSnapshot(?string $extraKey = null, ?string $extraValue = null): array
    {
        $snapshot = [
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [],
        ];

        if ($extraKey !== null) {
            $snapshot[$extraKey] = $extraValue;
        }

        return $snapshot;
    }

    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        $target = SocialEngagementTarget::factory()->create(array_merge([
            'tag' => 'Winter campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'google_ads_campaign_id' => '987654321',
        ], $attributes));

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
            'campaign_name' => 'Winter campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '25.0000',
            'impressions' => 1000,
            'clicks' => 50,
            'conversions' => '2.0000',
            'conversions_value' => '120.0000',
            'conversion_actions' => [],
            'currency_code' => 'TWD',
            'daily_metrics' => ['status' => 'complete', 'rows' => []],
            'synced_at' => now(),
        ], $attributes));
    }
}
