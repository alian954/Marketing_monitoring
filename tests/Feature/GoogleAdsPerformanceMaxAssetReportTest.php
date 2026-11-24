<?php

namespace Tests\Feature;

use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\User;
use App\Services\GoogleAdsApiException;
use App\Services\GoogleAdsCampaignReportSync;
use App\Services\GoogleAdsDataApi;
use App\Services\GoogleAdsPerformanceMaxAssetPerformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsPerformanceMaxAssetReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_reads_and_limits_asset_level_metrics_for_last_thirty_complete_days(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        $rawRows = [];

        for ($index = 0; $index < 201; $index++) {
            $rawRows[] = $this->assetApiRow($index, impressions: (string) (201 - $index));
        }

        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([['results' => $rawRows]]),
        ]);

        $report = (new GoogleAdsDataApi)->campaignPerformanceMaxAssetInsights(
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
        $this->assertSame('Headline 0', $report['rows'][0]['content']);
        $this->assertSame('400000000', $report['rows'][0]['asset_group_id']);
        $this->assertSame('700000000', $report['rows'][0]['asset_id']);
        $this->assertSame('ENABLED', $report['rows'][0]['status']);
        $this->assertSame(['ASSET_APPROVED_LABELED'], $report['rows'][0]['primary_status_reasons']);
        $this->assertSame('25.5000', $report['rows'][0]['spend_amount']);
        $this->assertSame('2.0000', $report['rows'][0]['conversions']);
        $this->assertSame('120.0000', $report['rows'][0]['conversions_value']);
        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_ends_with($request->url(), '/customers/1234567890/googleAds:searchStream')
                && $request->hasHeader('Authorization', 'Bearer access-token')
                && str_contains($query, 'FROM asset_group_asset')
                && str_contains($query, 'asset_group_asset.primary_status_reasons')
                && str_contains($query, 'asset.text_asset.text')
                && str_contains($query, 'asset.youtube_video_asset.youtube_video_id')
                && str_contains($query, "campaign.advertising_channel_type = 'PERFORMANCE_MAX'")
                && str_contains($query, "asset_group_asset.status != 'REMOVED'")
                && str_contains($query, "segments.date BETWEEN '2026-10-24' AND '2026-11-22'")
                && str_contains($query, 'ORDER BY metrics.impressions DESC')
                && str_ends_with($query, 'LIMIT 201');
        });
    }

    public function test_api_handles_multiple_asset_subtypes_without_requiring_optional_content(): void
    {
        Http::preventStrayRequests();
        $imageRow = $this->assetApiRow(1);
        $imageRow['asset'] = ['id' => '700000001', 'name' => 'Spring square image', 'type' => 'IMAGE'];
        $imageRow['assetGroupAsset']['fieldType'] = 'MARKETING_IMAGE';
        $videoRow = $this->assetApiRow(2);
        $videoRow['asset'] = [
            'id' => '700000002',
            'type' => 'YOUTUBE_VIDEO',
            'youtubeVideoAsset' => ['youtubeVideoId' => 'AbCdEfGhIJK'],
        ];
        $videoRow['assetGroupAsset']['fieldType'] = 'YOUTUBE_VIDEO';
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([['results' => [
                $this->assetApiRow(0),
                $imageRow,
                $videoRow,
            ]]]),
        ]);

        $report = (new GoogleAdsDataApi)->campaignPerformanceMaxAssetInsights(
            'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD',
        );

        $this->assertSame('complete', $report['status']);
        $this->assertSame('Headline 0', $report['rows'][0]['content']);
        $this->assertSame('Spring square image', $report['rows'][1]['content']);
        $this->assertSame('YouTube 影片 AbCdEfGhIJK', $report['rows'][2]['content']);
        $this->assertSame('AbCdEfGhIJK', $report['rows'][2]['youtube_video_id']);
    }

    public function test_api_skips_unsupported_ranges_and_rejects_mismatched_or_removed_rows(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        $dataApi = new GoogleAdsDataApi;

        $futurePeriod = $dataApi->campaignPerformanceMaxAssetInsights(
            'access-token', '1234567890', '987654321', '2026-11-23', '2026-12-01', 'TWD',
        );
        $oldPeriod = $dataApi->campaignPerformanceMaxAssetInsights(
            'access-token', '1234567890', '987654321', '2019-01-01', '2020-01-31', 'TWD',
        );
        $this->assertSame('no_complete_days', $futurePeriod['status']);
        $this->assertSame('unsupported', $oldPeriod['status']);
        Http::assertNothingSent();

        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push([['results' => [$this->assetApiRow(0, overrides: ['campaign' => ['id' => '987654322']])]]])
                ->push([['results' => [$this->assetApiRow(1, overrides: [
                    'assetGroupAsset' => ['status' => 'REMOVED'],
                ])]]]),
        ]);

        foreach ([0, 1] as $index) {
            try {
                $dataApi->campaignPerformanceMaxAssetInsights(
                    'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD',
                );
                $this->fail('Mismatched and removed Performance Max asset rows must be rejected.');
            } catch (GoogleAdsApiException $exception) {
                $this->assertStringContainsString('Performance Max', $exception->getMessage(), 'Invalid row '.$index.' should be rejected.');
            }
        }
    }

    public function test_sync_encrypts_pmax_asset_data_and_isolates_failures_from_campaign_summary(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        app()->instance(GoogleAdsDataApi::class, $this->mockSyncDataApi('PERFORMANCE_MAX', $this->assetSnapshot()));

        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('complete', $report->pmax_asset_performance['status']);
        $this->assertSame('Headline 0', $report->pmax_asset_performance['rows'][0]['content']);
        $this->assertTrue($report->pmax_asset_performance_expires_at->isFuture());
        $this->assertSame('PERFORMANCE_MAX', $report->daily_metrics['campaign_channel_type']);
        $this->assertStringNotContainsString('Headline 0', $report->getRawOriginal('pmax_asset_performance'));
        $this->assertArrayNotHasKey('pmax_asset_performance', $report->toArray());

        $failingUser = User::factory()->create();
        $failingConnection = $this->createConnection($failingUser);
        $failingTarget = $this->createTarget(['tag' => 'Winter campaign failure']);
        app()->instance(GoogleAdsDataApi::class, $this->mockSyncDataApi('PERFORMANCE_MAX', null, true));
        $failedAssetReport = app(GoogleAdsCampaignReportSync::class)->sync($failingConnection, $failingTarget, 'access-token');

        $this->assertSame('250.7500', $failedAssetReport->spend_amount);
        $this->assertSame('unavailable', $failedAssetReport->pmax_asset_performance['status']);
        $this->assertNull($failedAssetReport->pmax_asset_performance_expires_at);
    }

    public function test_sync_only_queries_pmax_assets_for_performance_max_campaigns(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        app()->instance(GoogleAdsDataApi::class, $this->mockSyncDataApi('SEARCH', null));

        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('unsupported_campaign', $report->pmax_asset_performance['status']);
        $this->assertNull($report->pmax_asset_performance_expires_at);
    }

    public function test_report_calculates_asset_efficiency_sorts_and_hides_expired_data(): void
    {
        $snapshot = $this->assetSnapshot();
        $snapshot['rows'][] = [
            ...$snapshot['rows'][0],
            'asset_group_id' => '400000002',
            'asset_group_name' => 'Second asset group',
            'asset_id' => '700000002',
            'content' => 'Lower volume headline',
            'impressions' => 400,
            'clicks' => 10,
            'spend_amount' => '10.0000',
            'conversions' => '1.0000',
            'conversions_value' => '5.0000',
        ];

        $builder = new GoogleAdsPerformanceMaxAssetPerformanceReport;
        $report = $builder->build($snapshot, CarbonImmutable::now()->addDay(), 'roas');

        $this->assertSame('roas', $report['sort']);
        $this->assertSame('Headline 0', $report['rows'][0]['content']);
        $this->assertSame('標題', $report['rows'][0]['field_type_label']);
        $this->assertSame('文字', $report['rows'][0]['asset_type_label']);
        $this->assertSame('可放送', $report['rows'][0]['primary_status_label']);
        $this->assertSame('廣告主提供', $report['rows'][0]['source_label']);
        $this->assertSame(5.0, $report['rows'][0]['ctr_percent']);
        $this->assertSame(0.51, $report['rows'][0]['cost_per_click']);
        $this->assertSame(12.75, $report['rows'][0]['cost_per_conversion']);
        $this->assertSame(4.7059, $report['rows'][0]['roas']);
        $this->assertSame('expired', $builder->build($snapshot, CarbonImmutable::now()->subSecond())['status']);
        $this->assertSame([], $builder->build($snapshot, CarbonImmutable::now()->subSecond())['rows']);
    }

    public function test_report_page_csv_export_formula_safety_and_owner_scope(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $assetContent = '=HYPERLINK("https://example.test","bad")';
        $snapshot = $this->assetSnapshot($assetContent);
        $report = $this->createReport($connection, $target, [
            'daily_metrics' => [
                'status' => 'complete',
                'campaign_channel_type' => 'PERFORMANCE_MAX',
                'requested_start_on' => $target->starts_on->toDateString(),
                'requested_end_on' => $target->ends_on->toDateString(),
                'coverage_start_on' => $target->starts_on->toDateString(),
                'coverage_end_on' => $target->ends_on->toDateString(),
                'rows' => [],
            ],
            'pmax_asset_performance' => $snapshot,
            'pmax_asset_performance_expires_at' => now()->addDays(30),
        ]);

        $this->assertStringNotContainsString($assetContent, $report->getRawOriginal('pmax_asset_performance'));
        $this->assertArrayNotHasKey('pmax_asset_performance', $report->toArray());

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Google Ads Performance Max 素材成效')
            ->assertSee('匯出素材 CSV')
            ->assertSee($assetContent)
            ->assertSee('不代表單則廣告表現');

        $csvResponse = $this->get(route('social-engagement-targets.google-ads.performance-max-assets', $target))->assertOk();
        $csvLines = str_getcsv($csvResponse->streamedContent(), "\n");
        $csvFields = str_getcsv($csvLines[1]);
        $this->assertSame("'".$assetContent, $csvFields[6]);

        $otherUser = User::factory()->create();
        $this->createConnection($otherUser);
        $this->actingAs($otherUser)
            ->get(route('social-engagement-targets.google-ads.performance-max-assets', $target))
            ->assertNotFound();
    }

    public function test_scheduled_sync_clears_expired_pmax_asset_snapshots(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget(['starts_on' => '2026-09-01', 'ends_on' => '2026-10-01']);
        $report = $this->createReport($connection, $target, [
            'pmax_asset_performance' => ['status' => 'complete', 'rows' => []],
            'pmax_asset_performance_expires_at' => now()->subSecond(),
        ]);

        Artisan::call('google-ads:sync-due');

        $this->assertNull($report->fresh()->pmax_asset_performance);
        $this->assertNull($report->fresh()->pmax_asset_performance_expires_at);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function assetApiRow(
        int $index,
        string $impressions = '1000',
        string $content = 'Headline 0',
        array $overrides = [],
    ): array {
        return array_replace_recursive([
            'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
            'campaign' => ['id' => '987654321', 'advertisingChannelType' => 'PERFORMANCE_MAX'],
            'assetGroup' => ['id' => (string) (400000000 + $index), 'name' => 'Retail group '.$index],
            'assetGroupAsset' => [
                'fieldType' => 'HEADLINE',
                'status' => 'ENABLED',
                'source' => 'ADVERTISER',
                'primaryStatus' => 'ELIGIBLE',
                'primaryStatusReasons' => ['ASSET_APPROVED_LABELED'],
            ],
            'asset' => [
                'id' => (string) (700000000 + $index),
                'name' => 'Headline asset '.$index,
                'type' => 'TEXT',
                'textAsset' => ['text' => $content],
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
    private function assetSnapshot(string $content = 'Headline 0'): array
    {
        return [
            'status' => 'complete',
            'window_start_on' => '2026-10-24',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [[
                'asset_group_id' => '400000000',
                'asset_group_name' => 'Retail group 0',
                'asset_id' => '700000000',
                'asset_name' => 'Headline asset 0',
                'field_type' => 'HEADLINE',
                'asset_type' => 'TEXT',
                'content' => $content,
                'youtube_video_id' => null,
                'status' => 'ENABLED',
                'primary_status' => 'ELIGIBLE',
                'primary_status_reasons' => ['ASSET_APPROVED_LABELED'],
                'source' => 'ADVERTISER',
                'impressions' => 1000,
                'clicks' => 50,
                'spend_amount' => '25.5000',
                'conversions' => '2.0000',
                'conversions_value' => '120.0000',
            ]],
        ];
    }

    private function mockSyncDataApi(string $channelType, ?array $assetSnapshot, bool $assetFailure = false): GoogleAdsDataApi
    {
        $dataApi = \Mockery::mock(GoogleAdsDataApi::class);
        $dataApi->shouldReceive('campaignInsights')->once()->andReturn([
            'campaign_id' => '987654321',
            'campaign_name' => 'Winter campaign',
            'campaign_channel_type' => $channelType,
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
        $dataApi->shouldReceive('campaignDeliveryUnitInsights')->once()->andReturn($this->emptyReportSnapshot());
        $dataApi->shouldReceive('campaignDeviceInsights')->once()->andReturn($this->emptyReportSnapshot());
        $dataApi->shouldReceive('campaignAuctionInsights')->once()->andReturn($this->emptyReportSnapshot());

        if ($channelType === 'SEARCH') {
            $dataApi->shouldReceive('campaignResponsiveSearchAdAssetInsights')->once()->andReturn($this->emptyReportSnapshot());
            $dataApi->shouldNotReceive('campaignPerformanceMaxAssetInsights');
        } else {
            $dataApi->shouldNotReceive('campaignResponsiveSearchAdAssetInsights');

            if ($assetFailure) {
                $dataApi->shouldReceive('campaignPerformanceMaxAssetInsights')
                    ->once()
                    ->andThrow(new GoogleAdsApiException('PMax 素材讀取失敗'));
            } else {
                $dataApi->shouldReceive('campaignPerformanceMaxAssetInsights')
                    ->once()
                    ->andReturn($assetSnapshot);
            }
        }

        return $dataApi;
    }

    /** @return array<string, mixed> */
    private function emptyReportSnapshot(): array
    {
        return [
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [],
        ];
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
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ], $attributes));
    }
}
