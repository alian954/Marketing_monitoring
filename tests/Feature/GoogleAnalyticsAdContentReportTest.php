<?php

namespace Tests\Feature;

use App\Models\GoogleAnalyticsCampaignSnapshot;
use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use App\Services\GoogleAnalyticsAdContentPerformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAnalyticsAdContentReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_stores_encrypted_ad_content_and_page_exports_formula_safe_csv(): void
    {
        [$user, $target] = $this->syncReport([
            'metadata' => ['currencyCode' => 'TWD'],
            'rowCount' => 2,
            'rows' => [
                $this->adContentRow('video-15s-blue', '120', '90', '0.75', '10', '500.25'),
                $this->adContentRow('=HYPERLINK("https://evil.example","click")', '20', '4', '0.2', '1', '10'),
            ],
        ]);

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $campaignSnapshot = GoogleAnalyticsCampaignSnapshot::query()->sole();
        $this->assertSame('complete', $report->ad_content_report['status']);
        $this->assertSame('2026-11-01', $report->ad_content_report['window_start_on']);
        $this->assertSame('2026-11-22', $report->ad_content_report['window_end_on']);
        $this->assertSame('TWD', $report->ad_content_report['currency_code']);
        $this->assertSame('video-15s-blue', $report->ad_content_report['rows'][0]['ad_content']);
        $this->assertTrue($report->ad_content_report_expires_at->equalTo(now()->addDays(30)));
        $this->assertArrayNotHasKey('ad_content_report', $report->toArray());
        $this->assertArrayNotHasKey('ad_content_report', $campaignSnapshot->getAttributes());

        $encryptedReport = DB::table('social_engagement_target_google_analytics_reports')
            ->where('id', $report->getKey())
            ->value('ad_content_report');
        $this->assertIsString($encryptedReport);
        $this->assertStringNotContainsString('video-15s-blue', $encryptedReport);

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dimensions'] === [['name' => 'sessionManualAdContent']]
            && $request->hasHeader('Authorization', 'Bearer ga4-access-token'));

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('UTM 素材成效')
            ->assertSee('video-15s-blue')
            ->assertSee('75.00%')
            ->assertSee('GA4 未提供素材值')
            ->assertSee(route('social-engagement-targets.google-analytics.ad-content', $target));

        $csvResponse = $this->get(route('social-engagement-targets.google-analytics.ad-content', $target))
            ->assertDownload('ga4-ad-content-'.$target->getKey().'.csv');
        $csv = $csvResponse->streamedContent();
        $this->assertStringContainsString('ad_content,sessions,engaged_sessions,engagement_rate,key_events,total_revenue', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_ad_content_query_failure_keeps_campaign_totals_available(): void
    {
        [$user, $target] = $this->syncReport([], 403);

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $this->assertSame(2400, $report->sessions);
        $this->assertSame('unavailable', $report->ad_content_report['status']);
        $this->assertSame([], $report->ad_content_report['rows']);
        $this->assertNull($report->ad_content_report_expires_at);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('目前無法讀取 UTM 素材明細；活動總成效仍可查看');
    }

    public function test_ad_content_report_sorts_rows_and_hides_expired_data(): void
    {
        $snapshot = [
            'status' => 'complete',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-22',
            'currency_code' => 'TWD',
            'truncated' => true,
            'rows' => [
                ['ad_content' => 'video-a', 'sessions' => 200, 'engaged_sessions' => 160, 'engagement_rate' => '0.8', 'key_events' => '10', 'total_revenue' => '2500.00'],
                ['ad_content' => 'video-b', 'sessions' => 400, 'engaged_sessions' => 200, 'engagement_rate' => '0.5', 'key_events' => '15', 'total_revenue' => '1200.00'],
            ],
        ];

        $builder = new GoogleAnalyticsAdContentPerformanceReport;
        $sortedReport = $builder->build($snapshot, CarbonImmutable::now()->addDay(), 'sessions');
        $this->assertSame('video-b', $sortedReport['rows'][0]['ad_content']);
        $this->assertTrue($sortedReport['truncated']);

        $expiredReport = $builder->build($snapshot, CarbonImmutable::now()->subSecond());
        $this->assertSame('expired', $expiredReport['status']);
        $this->assertSame([], $expiredReport['rows']);
    }

    public function test_expired_ad_content_snapshots_are_removed_by_scheduled_sync(): void
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
            'ad_content_report' => ['status' => 'complete', 'rows' => [['ad_content' => 'private creative']]],
            'ad_content_report_expires_at' => now()->subSecond(),
            'synced_at' => now()->subDay(),
        ]);
        Http::preventStrayRequests();

        $this->artisan('google-analytics:sync-due')->assertExitCode(0);

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $this->assertNull($report->ad_content_report);
        $this->assertNull($report->ad_content_report_expires_at);
        Http::assertNothingSent();
    }

    public function test_auto_tagged_campaign_displays_ad_content_as_not_applicable(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(['google_ads_campaign_id' => '123456789']);
        $connection = $this->createConnection($user);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'attribution_mode' => 'google_ads_auto_tagging',
            'campaign_id' => '123456789',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 10,
            'engaged_sessions' => 8,
            'key_events' => '1',
            'total_revenue' => '20',
            'synced_at' => now(),
            'ad_content_report' => [
                'status' => 'not_applicable',
                'window_start_on' => null,
                'window_end_on' => null,
                'currency_code' => null,
                'truncated' => false,
                'rows' => [],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('此活動使用 Google Ads 自動標記，不適用 UTM 素材拆分');
    }

    public function test_ad_content_csv_requires_authentication_and_owned_ga4_connection(): void
    {
        $target = $this->createTarget();
        Http::preventStrayRequests();

        $this->get(route('social-engagement-targets.google-analytics.ad-content', $target))
            ->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.google-analytics.ad-content', $target))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $adContentPayload @return array{User, SocialEngagementTarget} */
    private function syncReport(array $adContentPayload, int $adContentStatus = 200): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake(function (ClientRequest $request) use ($adContentPayload, $adContentStatus) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'ga4-access-token']);
            }

            $dimensions = array_column($request->data()['dimensions'] ?? [], 'name');

            if ($dimensions === ['sessionManualAdContent']) {
                return Http::response($adContentPayload, $adContentStatus);
            }

            if ($dimensions === ['landingPage'] || $dimensions === ['eventName'] || $dimensions === ['itemName']) {
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
            'tag' => 'GA4 ad content campaign',
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
    private function adContentRow(string $content, string $sessions, string $engagedSessions, string $engagementRate, string $keyEvents, string $revenue): array
    {
        return [
            'dimensionValues' => [['value' => $content]],
            'metricValues' => [
                ['value' => $sessions],
                ['value' => $engagedSessions],
                ['value' => $engagementRate],
                ['value' => $keyEvents],
                ['value' => $revenue],
            ],
        ];
    }
}
