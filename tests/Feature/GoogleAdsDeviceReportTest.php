<?php

namespace Tests\Feature;

use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\User;
use App\Services\GoogleAdsApiException;
use App\Services\GoogleAdsCampaignReportSync;
use App\Services\GoogleAdsDataApi;
use App\Services\GoogleAdsDevicePerformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsDeviceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_reads_device_performance_for_the_last_thirty_complete_days(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [
                    $this->deviceResult('MOBILE', '125500000', '1200', '60', '6', '300'),
                    $this->deviceResult('DESKTOP', '200000000', '1500', '45', '4', '250'),
                    $this->deviceResult('UNSPECIFIED', '1000000', '1', '0', '0', '0'),
                ],
            ]]),
        ]);

        $report = (new GoogleAdsDataApi)->campaignDeviceInsights(
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
        $this->assertSame(['DESKTOP', 'MOBILE', 'UNSPECIFIED'], array_column($report['rows'], 'device'));
        $this->assertSame('200.0000', $report['rows'][0]['spend_amount']);
        $this->assertSame('6.0000', $report['rows'][1]['conversions']);
        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return $request->hasHeader('Authorization', 'Bearer access-token')
                && str_contains($query, 'customer.currency_code, campaign.id, segments.device')
                && str_contains($query, 'metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value')
                && str_contains($query, 'FROM campaign')
                && str_contains($query, "segments.date BETWEEN '2026-10-24' AND '2026-11-22'")
                && str_contains($query, 'LIMIT 21');
        });
        Http::assertSentCount(1);
    }

    public function test_api_returns_empty_statuses_without_querying_outside_supported_dates(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        Http::preventStrayRequests();
        $dataApi = new GoogleAdsDataApi;

        $futurePeriod = $dataApi->campaignDeviceInsights(
            'access-token', '1234567890', '987654321', '2026-11-23', '2026-11-30', 'TWD',
        );
        $oldPeriod = $dataApi->campaignDeviceInsights(
            'access-token', '1234567890', '987654321', '2019-01-01', '2020-01-31', 'TWD',
        );

        $this->assertSame('no_complete_days', $futurePeriod['status']);
        $this->assertSame('unsupported', $oldPeriod['status']);
        Http::assertNothingSent();
    }

    public function test_api_rejects_rows_for_another_customer_or_campaign(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [$this->deviceResult('MOBILE', '1000000', '10', '1', '1', '50', [
                    'campaign' => ['id' => '987654322'],
                ])],
            ]]),
        ]);

        $this->expectException(GoogleAdsApiException::class);

        (new GoogleAdsDataApi)->campaignDeviceInsights(
            'access-token', '1234567890', '987654321', '2026-11-01', '2026-11-30', 'TWD',
        );
    }

    public function test_device_report_calculates_metrics_sorts_rows_and_marks_expired_data(): void
    {
        $snapshot = [
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [
                ['device' => 'DESKTOP', 'impressions' => 1500, 'clicks' => 45, 'spend_amount' => '200', 'conversions' => '4', 'conversions_value' => '250'],
                ['device' => 'MOBILE', 'impressions' => 1200, 'clicks' => 60, 'spend_amount' => '125.5', 'conversions' => '6', 'conversions_value' => '300'],
                ['device' => 'UNSPECIFIED', 'impressions' => 1, 'clicks' => 0, 'spend_amount' => '1', 'conversions' => '0', 'conversions_value' => '0'],
            ],
        ];

        $report = (new GoogleAdsDevicePerformanceReport)->build($snapshot, CarbonImmutable::now()->addDay(), 'conversions');

        $this->assertSame('MOBILE', $report['rows'][0]['device']);
        $this->assertSame('手機', $report['rows'][0]['device_label']);
        $this->assertSame(5.0, $report['rows'][0]['ctr_percent']);
        $this->assertSame(20.9167, $report['rows'][0]['cost_per_conversion']);
        $this->assertSame(2.3904, $report['rows'][0]['roas']);
        $this->assertSame('未分類', $report['rows'][2]['device_label']);
        $this->assertSame('expired', (new GoogleAdsDevicePerformanceReport)->build(
            $snapshot,
            CarbonImmutable::now()->subSecond(),
        )['status']);
    }

    public function test_device_report_failure_does_not_block_campaign_summary_or_delivery_units(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignMetricsPayload())
                ->push([])
                ->push([])
                ->push([])
                ->push([])
                ->push(['error' => ['message' => 'Device report unavailable.']], 403),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();

        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('250.7500', $report->spend_amount);
        $this->assertSame('complete', $report->delivery_unit_performance['status']);
        $this->assertSame('unavailable', $report->device_performance['status']);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 1);
        Http::assertSentCount(6);
    }

    public function test_successful_device_report_is_encrypted_and_expires_after_thirty_days(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignMetricsPayload())
                ->push([])
                ->push([])
                ->push([])
                ->push([])
                ->push([['results' => [$this->deviceResult('MOBILE', '125500000', '1200', '60', '6', '300')]]]),
        ]);
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();

        $report = app(GoogleAdsCampaignReportSync::class)->sync($connection, $target, 'access-token');

        $this->assertSame('complete', $report->device_performance['status']);
        $this->assertSame('MOBILE', $report->device_performance['rows'][0]['device']);
        $this->assertSame('125.5000', $report->device_performance['rows'][0]['spend_amount']);
        $this->assertTrue($report->device_performance_expires_at->isFuture());
        $this->assertStringNotContainsString('MOBILE', $report->getRawOriginal('device_performance'));
        $this->assertArrayNotHasKey('device_performance', $report->toArray());
        Http::assertSentCount(6);
    }

    public function test_device_report_view_and_csv_are_scoped_and_formula_safe(): void
    {
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget();
        $report = SocialEngagementTargetGoogleAdsReport::query()->create([
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
            'device_performance' => [
                'status' => 'complete',
                'window_start_on' => '2026-11-01',
                'window_end_on' => '2026-11-22',
                'currency_code' => 'TWD',
                'truncated' => false,
                'rows' => [[
                    'device' => '=1+1',
                    'impressions' => 1000,
                    'clicks' => 50,
                    'spend_amount' => '50.0000',
                    'conversions' => '2.0000',
                    'conversions_value' => '150.0000',
                ]],
            ],
            'device_performance_expires_at' => now()->addDays(10),
            'synced_at' => now(),
        ]);

        $this->assertStringNotContainsString('=1+1', $report->getRawOriginal('device_performance'));
        $this->assertArrayNotHasKey('device_performance', $report->toArray());
        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Google Ads 裝置成效')
            ->assertSee('未分類')
            ->assertSee('匯出裝置 CSV');

        $csvResponse = $this->get(route('social-engagement-targets.google-ads.devices', $target))->assertOk();
        $csvLines = str_getcsv($csvResponse->streamedContent(), "\n");
        $csvFields = str_getcsv($csvLines[1]);
        $this->assertSame("'=1+1", $csvFields[0]);

        $otherUser = User::factory()->create();
        $this->createConnection($otherUser);
        $this->actingAs($otherUser)
            ->get(route('social-engagement-targets.google-ads.devices', $target))
            ->assertNotFound();
    }

    public function test_expired_device_snapshots_are_cleared_by_the_google_ads_sync_schedule(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Asia/Taipei'));
        $user = User::factory()->create();
        $connection = $this->createConnection($user);
        $target = $this->createTarget(['ends_on' => '2026-10-01']);
        $report = SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => $connection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Winter launch',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '0',
            'impressions' => 0,
            'clicks' => 0,
            'conversions' => '0',
            'conversions_value' => '0',
            'currency_code' => 'TWD',
            'device_performance' => ['status' => 'complete', 'rows' => []],
            'device_performance_expires_at' => now()->subSecond(),
            'synced_at' => now(),
        ]);

        Artisan::call('google-ads:sync-due');

        $this->assertNull($report->fresh()->device_performance);
        $this->assertNull($report->fresh()->device_performance_expires_at);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function deviceResult(
        string $device,
        string $costMicros,
        string $impressions,
        string $clicks,
        string $conversions,
        string $conversionsValue,
        array $overrides = [],
    ): array {
        return array_replace([
            'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
            'campaign' => ['id' => '987654321'],
            'segments' => ['device' => $device],
            'metrics' => [
                'costMicros' => $costMicros,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'conversions' => $conversions,
                'conversionsValue' => $conversionsValue,
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function campaignMetricsPayload(): array
    {
        return [[
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
        ]];
    }

    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create([
            'tag' => 'Winter launch',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'google_ads_campaign_id' => '987654321',
            ...$attributes,
        ]);
    }

    private function createConnection(User $user): GoogleAdsConnection
    {
        return GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'refresh-token',
            'customer_id' => '1234567890',
            'customer_name' => 'Example Ads',
            'currency_code' => 'TWD',
        ]);
    }
}
