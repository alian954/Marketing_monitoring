<?php

namespace Tests\Feature;

use App\Models\GoogleAnalyticsCampaignSnapshot;
use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use App\Services\GoogleAnalyticsItemPerformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAnalyticsItemReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_stores_encrypted_item_performance_and_page_exports_formula_safe_csv(): void
    {
        [$user, $target] = $this->syncReport([
            'metadata' => ['currencyCode' => 'TWD'],
            'rowCount' => 2,
            'rows' => [
                $this->itemRow('Spring jacket', '120', '40', '12', '24000'),
                $this->itemRow('=HYPERLINK("https://evil.example","click")', '20', '2', '1', '800'),
            ],
        ]);

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $campaignSnapshot = GoogleAnalyticsCampaignSnapshot::query()->sole();
        $this->assertSame('complete', $report->item_report['status']);
        $this->assertSame('2026-11-01', $report->item_report['window_start_on']);
        $this->assertSame('2026-11-22', $report->item_report['window_end_on']);
        $this->assertSame('Spring jacket', $report->item_report['rows'][0]['item_name']);
        $this->assertSame('TWD', $report->item_report['currency_code']);
        $this->assertTrue($report->item_report_expires_at->equalTo(now()->addDays(30)));
        $this->assertArrayNotHasKey('item_report', $report->toArray());
        $this->assertArrayNotHasKey('item_report', $campaignSnapshot->getAttributes());

        $encryptedReport = DB::table('social_engagement_target_google_analytics_reports')
            ->where('id', $report->getKey())
            ->value('item_report');
        $this->assertIsString($encryptedReport);
        $this->assertStringNotContainsString('Spring jacket', $encryptedReport);

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dimensions'] === [['name' => 'itemName']]
            && $request->data()['metrics'] === [
                ['name' => 'itemsViewed'],
                ['name' => 'itemsAddedToCart'],
                ['name' => 'itemsPurchased'],
                ['name' => 'itemRevenue'],
            ]
            && $request->hasHeader('Authorization', 'Bearer ga4-access-token'));

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('商品成效')
            ->assertSee('Spring jacket')
            ->assertSee('購買數是商品件數')
            ->assertSee('商品資料由 GA4 電商事件提供')
            ->assertSee(route('social-engagement-targets.google-analytics.items', $target));

        $csvResponse = $this->get(route('social-engagement-targets.google-analytics.items', $target))
            ->assertDownload('ga4-items-'.$target->getKey().'.csv');
        $csv = $csvResponse->streamedContent();
        $this->assertStringContainsString('item_name,items_viewed,items_added_to_cart,items_purchased,item_revenue', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_item_query_failure_keeps_campaign_totals_available(): void
    {
        [$user, $target] = $this->syncReport([], 403);

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $this->assertSame(2400, $report->sessions);
        $this->assertSame('unavailable', $report->item_report['status']);
        $this->assertSame([], $report->item_report['rows']);
        $this->assertNull($report->item_report_expires_at);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('目前無法讀取商品明細；活動總成效仍可查看');
    }

    public function test_item_report_sorts_rows_and_hides_expired_data(): void
    {
        $snapshot = [
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'truncated' => true,
            'rows' => [
                ['item_name' => 'Cap', 'items_viewed' => 200, 'items_added_to_cart' => 20, 'items_purchased' => 5, 'item_revenue' => '2500.00'],
                ['item_name' => 'Jacket', 'items_viewed' => 400, 'items_added_to_cart' => 40, 'items_purchased' => 10, 'item_revenue' => '1200.00'],
            ],
        ];

        $builder = new GoogleAnalyticsItemPerformanceReport;
        $sortedReport = $builder->build($snapshot, CarbonImmutable::now()->addDay(), 'items_purchased');
        $this->assertSame('Jacket', $sortedReport['rows'][0]['item_name']);
        $this->assertTrue($sortedReport['truncated']);

        $expiredReport = $builder->build($snapshot, CarbonImmutable::now()->subSecond());
        $this->assertSame('expired', $expiredReport['status']);
        $this->assertSame([], $expiredReport['rows']);
    }

    public function test_expired_item_snapshots_are_removed_by_scheduled_sync(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        $user = User::factory()->create();
        $target = $this->createTarget([
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ]);
        $connection = $this->createConnection($user);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'campaign_name' => 'spring_launch_2026',
            'attribution_mode' => 'manual_utm',
            'source_name' => 'facebook_ads',
            'medium_name' => 'paid_social',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
            'sessions' => 20,
            'engaged_sessions' => 10,
            'key_events' => '1',
            'total_revenue' => '1',
            'source_medium_breakdown' => [],
            'daily_metrics' => ['status' => 'complete', 'rows' => []],
            'item_report' => ['status' => 'complete', 'rows' => [['item_name' => 'private item']]],
            'item_report_expires_at' => now()->subSecond(),
            'synced_at' => now()->subDay(),
        ]);
        Http::preventStrayRequests();

        $this->artisan('google-analytics:sync-due')->assertExitCode(0);

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $this->assertNull($report->item_report);
        $this->assertNull($report->item_report_expires_at);
        Http::assertNothingSent();
    }

    public function test_item_csv_export_requires_authentication_and_owned_ga4_connection(): void
    {
        $target = $this->createTarget();
        Http::preventStrayRequests();

        $this->get(route('social-engagement-targets.google-analytics.items', $target))
            ->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.google-analytics.items', $target))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $itemPayload @return array{User, SocialEngagementTarget} */
    private function syncReport(array $itemPayload, int $itemStatus = 200): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake(function (ClientRequest $request) use ($itemPayload, $itemStatus) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'ga4-access-token']);
            }

            $dimensions = array_column($request->data()['dimensions'] ?? [], 'name');

            if ($dimensions === ['itemName']) {
                return Http::response($itemPayload, $itemStatus);
            }

            if ($dimensions === ['landingPage'] || $dimensions === ['eventName']) {
                return Http::response(['metadata' => ['currencyCode' => 'TWD'], 'rowCount' => 0, 'rows' => []]);
            }

            if ($dimensions === ['date']) {
                return Http::response([
                    'rows' => [[
                        'dimensionValues' => [['value' => '20261102']],
                        'metricValues' => [
                            ['value' => '2400'],
                            ['value' => '1800'],
                            ['value' => '37.5'],
                            ['value' => '12890.25'],
                        ],
                    ]],
                    'rowCount' => 1,
                    'metadata' => ['currencyCode' => 'TWD'],
                ]);
            }

            return Http::response([
                'rows' => [[
                    'dimensionValues' => [
                        ['value' => 'spring_launch_2026'],
                        ['value' => 'Paid Social'],
                        ['value' => 'facebook_ads'],
                        ['value' => 'paid_social'],
                    ],
                    'metricValues' => [
                        ['value' => '2400'],
                        ['value' => '1800'],
                        ['value' => '37.5'],
                        ['value' => '12890.25'],
                    ],
                ]],
                'totals' => [[
                    'metricValues' => [
                        ['value' => '2400'],
                        ['value' => '1800'],
                        ['value' => '37.5'],
                        ['value' => '12890.25'],
                    ],
                ]],
                'rowCount' => 1,
                'metadata' => ['currencyCode' => 'TWD'],
            ]);
        });

        $user = User::factory()->create();
        $target = $this->createTarget([
            'ga4_campaign_name' => 'spring_launch_2026',
            'ga4_source_name' => 'facebook_ads',
            'ga4_medium_name' => 'paid_social',
        ]);
        $this->createConnection($user);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', 'GA4 活動成效已同步。');

        return [$user, $target];
    }

    /** @param array<string, mixed> $attributes */
    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create($attributes + [
            'tag' => 'GA4 item campaign',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
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
    private function itemRow(string $itemName, string $views, string $cart, string $purchases, string $revenue): array
    {
        return [
            'dimensionValues' => [['value' => $itemName]],
            'metricValues' => [
                ['value' => $views],
                ['value' => $cart],
                ['value' => $purchases],
                ['value' => $revenue],
            ],
        ];
    }
}
