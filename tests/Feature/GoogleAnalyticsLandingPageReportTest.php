<?php

namespace Tests\Feature;

use App\Models\GoogleAnalyticsCampaignSnapshot;
use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAnalyticsLandingPageReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_saves_encrypted_landing_page_performance_and_exports_path_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        [$user, $target] = $this->syncReport([
            'metadata' => ['currencyCode' => 'TWD'],
            'rowCount' => 3,
            'rows' => [
                $this->landingPageRow('/campaign/spring?email=private@example.com', '120', '90', '0.75', '0.25', '10', '500'),
                $this->landingPageRow('=SUM(1,1)', '40', '10', '0.25', '0.75', '4', '1400'),
                $this->landingPageRow('/zero-key-events', '60', '40', '0.66', '0.34', '0', '10'),
            ],
        ]);

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $campaignSnapshot = GoogleAnalyticsCampaignSnapshot::query()->sole();
        $this->assertSame('complete', $report->landing_page_report['status']);
        $this->assertSame('/campaign/spring', $report->landing_page_report['rows'][0]['landing_page']);
        $this->assertSame('TWD', $report->landing_page_report['currency_code']);
        $this->assertSame('2026-11-01', $report->landing_page_report['window_start_on']);
        $this->assertTrue($report->landing_page_expires_at->equalTo(now()->addDays(30)));
        $this->assertArrayNotHasKey('landing_page_report', $report->toArray());
        $this->assertArrayNotHasKey('landing_page_report', $campaignSnapshot->getAttributes());

        $encryptedReport = DB::table('social_engagement_target_google_analytics_reports')
            ->where('id', $report->getKey())
            ->value('landing_page_report');
        $this->assertIsString($encryptedReport);
        $this->assertStringNotContainsString('/campaign/spring', $encryptedReport);
        $this->assertStringNotContainsString('private@example.com', $encryptedReport);

        Http::assertSentCount(8);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dimensions'] === [['name' => 'landingPage']]
            && $request->data()['metrics'] === [
                ['name' => 'sessions'],
                ['name' => 'engagedSessions'],
                ['name' => 'engagementRate'],
                ['name' => 'bounceRate'],
                ['name' => 'keyEvents'],
                ['name' => 'totalRevenue'],
            ]
            && $request->data()['dimensionFilter']['andGroup']['expressions'][0]['filter']['stringFilter']['value'] === 'spring_launch_2026'
            && $request->data()['limit'] === 201
            && $request->hasHeader('Authorization', 'Bearer ga4-access-token'));

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('登陸頁成效')
            ->assertSee('每工作階段營收')
            ->assertSee('每 100 次工作階段重要事件數')
            ->assertSee('不是轉換率')
            ->assertSee('工作階段')
            ->assertSee('參與率')
            ->assertSee('75.00%')
            ->assertSee('TWD 4.1667')
            ->assertSee('TWD 35.0000')
            ->assertSee('/campaign/spring')
            ->assertDontSee('private@example.com')
            ->assertSee(route('social-engagement-targets.google-analytics.landing-pages', $target));

        $sortedResponse = $this->get(route('social-engagement-targets.report', [
            'socialEngagementTarget' => $target,
            'landing_page_sort' => 'total_revenue',
        ]));
        $sortedResponse->assertOk()->assertSeeInOrder(['=SUM(1,1)', '/campaign/spring']);

        $efficiencySortedResponse = $this->get(route('social-engagement-targets.report', [
            'socialEngagementTarget' => $target,
            'landing_page_sort' => 'revenue_per_session',
        ]));
        $efficiencySortedResponse->assertOk()->assertSeeInOrder(['=SUM(1,1)', '/campaign/spring']);

        $keyEventRateSortedResponse = $this->get(route('social-engagement-targets.report', [
            'socialEngagementTarget' => $target,
            'landing_page_sort' => 'key_events_per_100_sessions',
        ]));
        $keyEventRateSortedResponse->assertOk()->assertSeeInOrder(['=SUM(1,1)', '/campaign/spring']);

        $csvResponse = $this->get(route('social-engagement-targets.google-analytics.landing-pages', $target))
            ->assertDownload('ga4-landing-pages-'.$target->getKey().'.csv');
        $csv = $csvResponse->streamedContent();
        $this->assertStringContainsString('landing_page,sessions,engaged_sessions,engagement_rate_percent,bounce_rate_percent', $csv);
        $this->assertStringContainsString('total_revenue,revenue_per_session,revenue_per_session_status_label,currency_code', $csv);
        $this->assertStringContainsString('key_events_per_100_sessions,key_events_per_100_sessions_status_label', $csv);
        $this->assertStringContainsString("'=SUM(1,1)", $csv);
        $this->assertStringNotContainsString('private@example.com', $csv);
        $csvLines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $csvHeaders = str_getcsv(array_shift($csvLines) ?: '');
        $csvRows = array_map(static function (string $line) use ($csvHeaders): array {
            $row = array_combine($csvHeaders, str_getcsv($line));

            return is_array($row) ? $row : [];
        }, $csvLines);
        $csvRowsByLandingPage = collect($csvRows)->keyBy('landing_page');

        $this->assertSame('4.1667', $csvRowsByLandingPage['/campaign/spring']['revenue_per_session']);
        $this->assertSame('35.0000', $csvRowsByLandingPage["'=SUM(1,1)"]['revenue_per_session']);
        $this->assertSame('8.3333', $csvRowsByLandingPage['/campaign/spring']['key_events_per_100_sessions']);
        $this->assertSame('10.0000', $csvRowsByLandingPage["'=SUM(1,1)"]['key_events_per_100_sessions']);
        $this->assertSame('0.0000', $csvRowsByLandingPage['/zero-key-events']['key_events_per_100_sessions']);
        $this->assertSame('可計算', $csvRowsByLandingPage['/campaign/spring']['key_events_per_100_sessions_status_label']);
        $this->assertSame('TWD', $csvRowsByLandingPage['/campaign/spring']['currency_code']);
    }

    public function test_landing_page_without_sessions_has_no_revenue_per_session_in_report_or_csv(): void
    {
        [$user, $target] = $this->syncReport([
            'metadata' => ['currencyCode' => 'TWD'],
            'rowCount' => 1,
            'rows' => [$this->landingPageRow('/no-sessions', '0', '0', '0', '0', '0', '500')],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('每工作階段營收')
            ->assertSee('工作階段為 0');
        $report = $response->viewData('googleAnalyticsLandingPageReport');

        $this->assertNull($report['rows'][0]['revenue_per_session']);
        $this->assertNull($report['rows'][0]['key_events_per_100_sessions']);
        $this->assertSame('zero_sessions', $report['rows'][0]['key_events_per_100_sessions_status']);

        $csvResponse = $this->get(route('social-engagement-targets.google-analytics.landing-pages', $target))
            ->assertDownload('ga4-landing-pages-'.$target->getKey().'.csv');
        $csvRows = array_map('str_getcsv', preg_split('/\r\n|\r|\n/', trim($csvResponse->streamedContent())) ?: []);
        $headers = array_shift($csvRows);
        $row = array_combine($headers, $csvRows[0]);

        $this->assertSame('', $row['revenue_per_session']);
        $this->assertSame('', $row['key_events_per_100_sessions']);
        $this->assertSame('工作階段為 0', $row['key_events_per_100_sessions_status_label']);
    }

    public function test_landing_page_key_event_rate_keeps_rows_with_missing_or_invalid_key_events(): void
    {
        [$user, $target] = $this->syncReport([
            'metadata' => ['currencyCode' => 'TWD'],
            'rowCount' => 2,
            'rows' => [
                $this->landingPageRow('/missing-key-events', '10', '8', '0.8', '0.2', '1', '100'),
                $this->landingPageRow('/invalid-key-events', '20', '10', '0.5', '0.5', '1', '200'),
            ],
        ]);
        $analyticsReport = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $landingPageReport = $analyticsReport->landing_page_report;
        unset($landingPageReport['rows'][0]['key_events']);
        $landingPageReport['rows'][1]['key_events'] = '1e999';
        $analyticsReport->landing_page_report = $landingPageReport;
        $analyticsReport->save();

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', [
                'socialEngagementTarget' => $target,
                'landing_page_sort' => 'key_events_per_100_sessions',
            ]))
            ->assertOk()
            ->assertSee('/missing-key-events')
            ->assertSee('/invalid-key-events');
        $report = $response->viewData('googleAnalyticsLandingPageReport');
        $this->assertCount(2, $report['rows']);
        $rowsByLandingPage = collect($report['rows'])->keyBy('landing_page');
        $this->assertNull($rowsByLandingPage['/missing-key-events']['key_events']);
        $this->assertSame('missing_key_events', $rowsByLandingPage['/missing-key-events']['key_events_per_100_sessions_status']);
        $this->assertNull($rowsByLandingPage['/invalid-key-events']['key_events']);
        $this->assertSame('invalid_key_events', $rowsByLandingPage['/invalid-key-events']['key_events_per_100_sessions_status']);

        $csvResponse = $this->get(route('social-engagement-targets.google-analytics.landing-pages', $target))
            ->assertDownload('ga4-landing-pages-'.$target->getKey().'.csv');
        $csvLines = preg_split('/\r\n|\r|\n/', trim($csvResponse->streamedContent())) ?: [];
        $csvHeaders = str_getcsv(array_shift($csvLines) ?: '');
        $csvRows = array_map(static function (string $line) use ($csvHeaders): array {
            $row = array_combine($csvHeaders, str_getcsv($line));

            return is_array($row) ? $row : [];
        }, $csvLines);
        $csvRowsByLandingPage = collect($csvRows)->keyBy('landing_page');

        $this->assertSame('', $csvRowsByLandingPage['/missing-key-events']['key_events_per_100_sessions']);
        $this->assertSame('缺少重要事件數', $csvRowsByLandingPage['/missing-key-events']['key_events_per_100_sessions_status_label']);
        $this->assertSame('', $csvRowsByLandingPage['/invalid-key-events']['key_events_per_100_sessions']);
        $this->assertSame('重要事件數無效', $csvRowsByLandingPage['/invalid-key-events']['key_events_per_100_sessions_status_label']);
    }

    public function test_invalid_landing_page_metrics_are_excluded_from_revenue_per_session_report(): void
    {
        [$user, $target] = $this->syncReport([
            'metadata' => ['currencyCode' => 'TWD'],
            'rowCount' => 1,
            'rows' => [$this->landingPageRow('/valid', '10', '8', '0.8', '0.2', '1', '100')],
        ]);
        $analyticsReport = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $landingPageReport = $analyticsReport->landing_page_report;
        $landingPageReport['rows'][0]['total_revenue'] = '1e999';
        $analyticsReport->landing_page_report = $landingPageReport;
        $analyticsReport->save();

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('此活動期間沒有可用的登陸頁數據。');

        $this->assertSame([], $response->viewData('googleAnalyticsLandingPageReport')['rows']);
    }

    public function test_landing_page_with_unknown_currency_does_not_show_revenue_per_session(): void
    {
        [$user, $target] = $this->syncReport([
            'metadata' => ['currencyCode' => 'TWD'],
            'rowCount' => 1,
            'rows' => [$this->landingPageRow('/unknown-currency', '10', '8', '0.8', '0.2', '1', '100')],
        ]);
        $analyticsReport = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $landingPageReport = $analyticsReport->landing_page_report;
        $landingPageReport['currency_code'] = null;
        $analyticsReport->landing_page_report = $landingPageReport;
        $analyticsReport->save();

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('每工作階段營收')
            ->assertSee('幣別未知');
        $landingPage = $response->viewData('googleAnalyticsLandingPageReport')['rows'][0];

        $this->assertNull($landingPage['revenue_per_session']);
        $this->assertSame('currency_unknown', $landingPage['revenue_per_session_status']);
    }

    public function test_expired_or_missing_expiry_landing_page_data_is_not_used(): void
    {
        [$user, $target] = $this->syncReport([
            'metadata' => ['currencyCode' => 'TWD'],
            'rowCount' => 1,
            'rows' => [$this->landingPageRow('/expired', '10', '8', '0.8', '0.2', '1', '100')],
        ]);
        $analyticsReport = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $analyticsReport->landing_page_expires_at = now()->subSecond();
        $analyticsReport->save();

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('登陸頁明細已超過 30 天保存期限');

        $this->assertSame('expired', $response->viewData('googleAnalyticsLandingPageReport')['status']);
        $this->assertSame([], $response->viewData('googleAnalyticsLandingPageReport')['rows']);
        $this->get(route('social-engagement-targets.google-analytics.landing-pages', $target))
            ->assertNotFound();

        $analyticsReport->landing_page_expires_at = null;
        $analyticsReport->save();

        $missingExpiryResponse = $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('登陸頁明細已超過 30 天保存期限');
        $this->assertSame('expired', $missingExpiryResponse->viewData('googleAnalyticsLandingPageReport')['status']);
        $this->assertSame([], $missingExpiryResponse->viewData('googleAnalyticsLandingPageReport')['rows']);
    }

    public function test_sync_limits_landing_page_rows_and_marks_aggregated_data_as_truncated(): void
    {
        $rows = [];

        for ($index = 1; $index <= 201; $index++) {
            $rows[] = $this->landingPageRow(
                '/page/'.$index,
                (string) (202 - $index),
                '1',
                '0.5',
                '0.5',
                '1',
                '1',
            );
        }

        [$user, $target] = $this->syncReport([
            'metadata' => [
                'currencyCode' => 'TWD',
                'dataLossFromOtherRow' => true,
            ],
            'rowCount' => 450,
            'rows' => $rows,
        ]);

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $this->assertSame(200, count($report->landing_page_report['rows']));
        $this->assertSame('/page/1', $report->landing_page_report['rows'][0]['landing_page']);
        $this->assertSame('/page/200', $report->landing_page_report['rows'][199]['landing_page']);
        $this->assertTrue($report->landing_page_report['truncated']);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('最多顯示 200 個登陸頁')
            ->assertSee('高基數資料');
    }

    public function test_thresholded_landing_page_data_does_not_block_campaign_totals_or_become_zero(): void
    {
        [$user, $target] = $this->syncReport([
            'metadata' => [
                'currencyCode' => 'TWD',
                'subjectToThresholding' => true,
            ],
            'rowCount' => 0,
            'rows' => [],
        ]);

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $this->assertSame(2400, $report->sessions);
        $this->assertSame('thresholded', $report->landing_page_report['status']);
        $this->assertSame([], $report->landing_page_report['rows']);
        $this->assertNull($report->landing_page_expires_at);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('GA4 因資料門檻未提供登陸頁數據');
    }

    public function test_landing_page_query_failure_keeps_campaign_report_available(): void
    {
        [$user, $target] = $this->syncReport([], 503);

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $this->assertSame(2400, $report->sessions);
        $this->assertSame('unavailable', $report->landing_page_report['status']);
        $this->assertSame([], $report->landing_page_report['rows']);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('目前無法讀取登陸頁數據；活動總成效仍可查看');
    }

    public function test_expired_landing_page_snapshots_are_removed_by_the_scheduled_sync_command(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        $user = User::factory()->create();
        $target = $this->createTarget([
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ]);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->getKey(),
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'campaign_name' => 'spring_launch_2026',
            'attribution_mode' => 'manual_utm',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
            'sessions' => 20,
            'engaged_sessions' => 10,
            'key_events' => '1',
            'total_revenue' => '1',
            'source_medium_breakdown' => [],
            'daily_metrics' => ['status' => 'complete', 'rows' => []],
            'landing_page_report' => ['status' => 'complete', 'rows' => ['/private/path']],
            'landing_page_expires_at' => now()->subSecond(),
            'synced_at' => now()->subDay(),
        ]);
        Http::fake();

        $this->artisan('google-analytics:sync-due')->assertExitCode(0);

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $this->assertNull($report->landing_page_report);
        $this->assertNull($report->landing_page_expires_at);
        Http::assertNothingSent();
    }

    public function test_landing_page_csv_export_requires_authentication_and_an_owned_ga4_connection(): void
    {
        $target = $this->createTarget();
        Http::fake();

        $this->get(route('social-engagement-targets.google-analytics.landing-pages', $target))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('social-engagement-targets.google-analytics.landing-pages', $target))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $landingPagePayload @return array{User, SocialEngagementTarget} */
    private function syncReport(array $landingPagePayload, int $landingPageStatus = 200): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Taipei'));
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake(function (ClientRequest $request) use ($landingPagePayload, $landingPageStatus) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'ga4-access-token']);
            }

            $dimensions = array_column($request->data()['dimensions'] ?? [], 'name');

            if ($dimensions === ['landingPage']) {
                return Http::response($landingPagePayload, $landingPageStatus);
            }

            if ($dimensions === ['eventName']) {
                return Http::response(['rows' => []]);
            }

            if ($dimensions === ['itemName']) {
                return Http::response(['rows' => []]);
            }

            if ($dimensions === ['sessionManualAdContent']) {
                return Http::response(['rows' => []]);
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
        GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->getKey(),
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

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
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
    }

    /** @return array<string, mixed> */
    private function landingPageRow(
        string $landingPage,
        string $sessions,
        string $engagedSessions,
        string $engagementRate,
        string $bounceRate,
        string $keyEvents,
        string $totalRevenue,
    ): array {
        return [
            'dimensionValues' => [['value' => $landingPage]],
            'metricValues' => [
                ['value' => $sessions],
                ['value' => $engagedSessions],
                ['value' => $engagementRate],
                ['value' => $bounceRate],
                ['value' => $keyEvents],
                ['value' => $totalRevenue],
            ],
        ];
    }
}
