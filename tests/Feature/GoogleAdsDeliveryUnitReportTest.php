<?php

namespace Tests\Feature;

use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\User;
use App\Services\GoogleAdsApiException;
use App\Services\GoogleAdsCampaignReportSync;
use App\Services\GoogleAdsDataApi;
use App\Services\GoogleAdsDeliveryUnitPerformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsDeliveryUnitReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_reads_top_200_ad_groups_for_the_last_thirty_complete_days(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        $rows = [];

        for ($index = 0; $index < 201; $index++) {
            $rows[] = $this->deliveryUnitRow('ad_group', $index, (string) (201 - $index).'000000');
        }

        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([['results' => $rows]]),
        ]);

        $report = (new GoogleAdsDataApi)->campaignDeliveryUnitInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2026-08-01',
            '2026-12-30',
            'TWD',
            'SEARCH',
        );

        $this->assertSame('complete', $report['status']);
        $this->assertSame('2026-10-24', $report['window_start_on']);
        $this->assertSame('2026-11-22', $report['window_end_on']);
        $this->assertSame('ad_group', $report['unit_type']);
        $this->assertTrue($report['truncated']);
        $this->assertCount(200, $report['rows']);
        $this->assertSame('700000000', $report['rows'][0]['unit_id']);
        $this->assertSame('201.0000', $report['rows'][0]['spend_amount']);
        $this->assertSame('700000199', $report['rows'][199]['unit_id']);
        $this->assertSame('2.0000', $report['rows'][0]['conversions']);
        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_ends_with($request->url(), '/customers/1234567890/googleAds:searchStream')
                && $request->hasHeader('Authorization', 'Bearer access-token')
                && str_contains($query, 'ad_group.id, ad_group.name, ad_group.status')
                && str_contains($query, 'FROM ad_group')
                && str_contains($query, "segments.date BETWEEN '2026-10-24' AND '2026-11-22'")
                && str_contains($query, 'ORDER BY metrics.cost_micros DESC, ad_group.id ASC LIMIT 201')
                && ! str_contains($query, 'segments.date,');
        });
    }

    public function test_api_uses_asset_groups_for_performance_max_campaigns(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [$this->deliveryUnitRow('asset_group', 1, '50000000')],
            ]]),
        ]);

        $report = (new GoogleAdsDataApi)->campaignDeliveryUnitInsights(
            'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD', 'PERFORMANCE_MAX',
        );

        $this->assertSame('asset_group', $report['unit_type']);
        $this->assertSame('700000001', $report['rows'][0]['unit_id']);
        $this->assertSame('complete', $report['status']);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->data()['query'] ?? '', 'FROM asset_group')
            && str_contains($request->data()['query'] ?? '', 'asset_group.status'));
    }

    public function test_api_skips_periods_without_complete_days_or_supported_history(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        $dataApi = new GoogleAdsDataApi;

        $futurePeriod = $dataApi->campaignDeliveryUnitInsights(
            'access-token', '1234567890', '987654321', '2026-11-23', '2026-11-30', 'TWD', 'SEARCH',
        );
        $oldPeriod = $dataApi->campaignDeliveryUnitInsights(
            'access-token', '1234567890', '987654321', '2019-01-01', '2020-01-31', 'TWD', 'PERFORMANCE_MAX',
        );

        $this->assertSame('no_complete_days', $futurePeriod['status']);
        $this->assertSame('unsupported', $oldPeriod['status']);
        $this->assertSame('asset_group', $oldPeriod['unit_type']);
        Http::assertNothingSent();
    }

    public function test_api_rejects_data_for_a_different_customer_or_campaign(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [$this->deliveryUnitRow('ad_group', 0, '1000000', [
                    'campaign' => ['id' => '987654322'],
                ])],
            ]]),
        ]);

        $this->expectException(GoogleAdsApiException::class);

        (new GoogleAdsDataApi)->campaignDeliveryUnitInsights(
            'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD', 'SEARCH',
        );
    }

    public function test_delivery_unit_fetch_failure_does_not_block_campaign_summary(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignMetricsPayload())
                ->push([])
                ->push([])
                ->push([])
                ->push([])
                ->push(['error' => ['message' => 'No ad group permission.']], 403)
                ->push([])
                ->push([])
                ->push([]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();

        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('250.7500', $report->spend_amount);
        $this->assertSame('complete', $report->search_terms_data['status']);
        $this->assertSame('unavailable', $report->delivery_unit_performance['status']);
        $this->assertStringContainsString('Google Ads 專案或帳戶沒有 API 讀取權限', $report->delivery_unit_performance['message']);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 1);
    }

    public function test_successful_delivery_unit_data_is_encrypted_and_expires_after_thirty_days(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignMetricsPayload())
                ->push([])
                ->push([])
                ->push([])
                ->push([])
                ->push([['results' => [$this->deliveryUnitRow('ad_group', 0, '25500000')]]])
                ->push([])
                ->push([])
                ->push([]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();

        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('complete', $report->delivery_unit_performance['status']);
        $this->assertSame('Brand group 0', $report->delivery_unit_performance['rows'][0]['unit_name']);
        $this->assertSame('25.5000', $report->delivery_unit_performance['rows'][0]['spend_amount']);
        $this->assertTrue($report->delivery_unit_performance_expires_at->isFuture());
        $this->assertStringNotContainsString('Brand group 0', $report->getRawOriginal('delivery_unit_performance'));
        $this->assertArrayNotHasKey('delivery_unit_performance', $report->toArray());
        Http::assertSentCount(9);
    }

    public function test_delivery_unit_report_calculates_metrics_sorts_rows_and_marks_expired_data(): void
    {
        $builder = new GoogleAdsDeliveryUnitPerformanceReport;
        $snapshot = [
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'unit_type' => 'asset_group',
            'truncated' => false,
            'rows' => [
                [
                    'unit_id' => '1', 'unit_name' => 'Group one', 'status' => 'ENABLED', 'impressions' => 1000,
                    'clicks' => 50, 'spend_amount' => '25.0000', 'conversions' => '2.0000', 'conversions_value' => '100.0000',
                ],
                [
                    'unit_id' => '2', 'unit_name' => 'Group two', 'status' => 'PAUSED', 'impressions' => 1000,
                    'clicks' => 10, 'spend_amount' => '20.0000', 'conversions' => '1.0000', 'conversions_value' => '60.0000',
                ],
            ],
        ];

        $report = $builder->build($snapshot, CarbonImmutable::now()->addDay(), 'roas');

        $this->assertSame('asset_group', $report['unit_type']);
        $this->assertSame('roas', $report['sort']);
        $this->assertSame('1', $report['rows'][0]['unit_id']);
        $this->assertSame(5.0, $report['rows'][0]['ctr_percent']);
        $this->assertSame(0.5, $report['rows'][0]['cost_per_click']);
        $this->assertSame(12.5, $report['rows'][0]['cost_per_conversion']);
        $this->assertSame(4.0, $report['rows'][0]['roas']);
        $this->assertSame('expired', $builder->build($snapshot, CarbonImmutable::now()->subSecond())['status']);
        $this->assertSame([], $builder->build($snapshot, CarbonImmutable::now()->subSecond())['rows']);
    }

    public function test_report_view_and_csv_export_are_scoped_and_formula_safe(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $unitName = '=HYPERLINK("https://example.test","brand")';
        $report = $this->createReport($connection, $target, [
            'delivery_unit_performance' => [
                'status' => 'complete',
                'window_start_on' => '2026-10-24',
                'window_end_on' => '2026-11-22',
                'currency_code' => 'TWD',
                'unit_type' => 'ad_group',
                'truncated' => false,
                'rows' => [$this->normalizedDeliveryUnitRow($unitName)],
            ],
            'delivery_unit_performance_expires_at' => now()->addDays(30),
        ]);

        $this->assertStringNotContainsString($unitName, $report->getRawOriginal('delivery_unit_performance'));
        $this->assertArrayNotHasKey('delivery_unit_performance', $report->toArray());

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Google Ads 投放單位成效')
            ->assertSee('匯出投放單位 CSV')
            ->assertSee('Google Ads 廣告群組成效');

        $csvResponse = $this->get(route('social-engagement-targets.google-ads.delivery-units', $target))->assertOk();
        $csvLines = str_getcsv($csvResponse->streamedContent(), "\n");
        $csvFields = str_getcsv($csvLines[1]);
        $this->assertSame("'".$unitName, $csvFields[2]);

        $otherUser = User::factory()->create();
        $this->createConnection($otherUser);
        $this->actingAs($otherUser)
            ->get(route('social-engagement-targets.google-ads.delivery-units', $target))
            ->assertNotFound();
    }

    public function test_scheduled_sync_clears_expired_delivery_unit_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget(['starts_on' => '2026-09-01', 'ends_on' => '2026-10-01']);
        $report = $this->createReport($connection, $target, [
            'delivery_unit_performance' => ['status' => 'complete', 'rows' => []],
            'delivery_unit_performance_expires_at' => now()->subSecond(),
        ]);

        Artisan::call('google-ads:sync-due');

        $this->assertNull($report->fresh()->delivery_unit_performance);
        $this->assertNull($report->fresh()->delivery_unit_performance_expires_at);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function deliveryUnitRow(string $resourceType, int $index, string $costMicros, array $overrides = []): array
    {
        $resourceKey = $resourceType === 'asset_group' ? 'assetGroup' : 'adGroup';

        return array_replace([
            'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
            'campaign' => ['id' => '987654321'],
            $resourceKey => [
                'id' => (string) (700000000 + $index),
                'name' => $resourceType === 'asset_group' ? 'Brand asset group '.$index : 'Brand group '.$index,
                'status' => 'ENABLED',
            ],
            'metrics' => [
                'costMicros' => $costMicros,
                'impressions' => '1000',
                'clicks' => '50',
                'conversions' => '2',
                'conversionsValue' => '120',
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function normalizedDeliveryUnitRow(string $unitName): array
    {
        return [
            'unit_id' => '555555555',
            'unit_name' => $unitName,
            'status' => 'ENABLED',
            'impressions' => 1000,
            'clicks' => 50,
            'spend_amount' => '25.0000',
            'conversions' => '2.0000',
            'conversions_value' => '120.0000',
        ];
    }

    /** @return array{0: array{results: list<array<string, mixed>>}} */
    private function campaignMetricsPayload(): array
    {
        return [[
            'results' => [[
                'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
                'campaign' => [
                    'id' => '987654321',
                    'name' => 'Winter campaign',
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
        ]];
    }

    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create(array_merge([
            'tag' => 'Winter campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'google_ads_campaign_id' => '987654321',
        ], $attributes));
    }

    private function createConnection(User $user): GoogleAdsConnection
    {
        return GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'authorized-refresh-token',
            'customer_id' => '1234567890',
            'customer_name' => 'Example Ads',
            'currency_code' => 'TWD',
        ]);
    }

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
