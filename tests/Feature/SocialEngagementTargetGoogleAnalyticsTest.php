<?php

namespace Tests\Feature;

use App\Models\GoogleAnalyticsCampaignSnapshot;
use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use App\Services\GoogleAnalyticsApiException;
use App\Services\GoogleAnalyticsCampaignReportSync;
use App\Services\GoogleAnalyticsDataApi;
use Carbon\CarbonImmutable;
use Database\Seeders\GoogleAnalyticsCampaignSnapshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SocialEngagementTargetGoogleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ga4_sync_requires_authentication_and_does_not_make_an_api_call(): void
    {
        $target = $this->createTarget();
        Http::fake();

        $this->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_ga4_sync_requires_a_connected_account_property_and_campaign_period(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $target = $this->createTarget();

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_analytics']);

        GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'encrypted-refresh-token',
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_analytics']);

        $connection = $user->googleAnalyticsConnection()->firstOrFail();
        $connection->property_id = '123456789';
        $connection->save();

        $target->update(['starts_on' => null, 'ends_on' => null]);
        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_analytics']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('social_engagement_target_google_analytics_reports', 0);
    }

    public function test_ga4_sync_queries_campaign_metrics_and_renders_the_private_snapshot(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:30:00', 'Asia/Taipei'));
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'short-lived-access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => $this->campaignReportResponse([
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
            ]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget([
            'tag' => 'Spring launch posts',
            'ga4_campaign_name' => 'spring_launch_2026',
            'ga4_source_name' => 'facebook_ads',
            'ga4_medium_name' => 'paid_social',
        ]);
        GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', 'GA4 活動成效已同步。');

        $snapshot = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $dailySnapshot = GoogleAnalyticsCampaignSnapshot::query()->sole();
        $this->assertSame('spring_launch_2026', $snapshot->campaign_name);
        $this->assertSame('facebook_ads', $snapshot->source_name);
        $this->assertSame('paid_social', $snapshot->medium_name);
        $this->assertSame(2400, $snapshot->sessions);
        $this->assertSame(1800, $snapshot->engaged_sessions);
        $this->assertSame('37.5000', $snapshot->key_events);
        $this->assertSame('12890.2500', $snapshot->total_revenue);
        $this->assertSame('TWD', $snapshot->currency_code);
        $this->assertSame('Paid Social', $snapshot->source_medium_breakdown[0]['channel_group']);
        $this->assertSame('facebook_ads', $snapshot->source_medium_breakdown[0]['source_name']);
        $this->assertSame('paid_social', $snapshot->source_medium_breakdown[0]['medium_name']);
        $this->assertSame(2400, $snapshot->source_medium_breakdown[0]['sessions']);
        $this->assertFalse($snapshot->source_medium_breakdown_truncated);
        $this->assertNotNull($snapshot->synced_at);
        $this->assertSame('2026-11-22', $dailySnapshot->snapshot_date->toDateString());
        $this->assertSame('spring_launch_2026', $dailySnapshot->campaign_name);
        $this->assertSame(2400, $dailySnapshot->sessions);
        $this->assertSame('TWD', $dailySnapshot->currency_code);
        $this->assertSame($snapshot->source_medium_breakdown, $dailySnapshot->source_medium_breakdown);

        Http::assertSentCount(4);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dimensions'] === [
                ['name' => 'sessionManualCampaignName'],
                ['name' => 'sessionDefaultChannelGroup'],
                ['name' => 'sessionManualSource'],
                ['name' => 'sessionManualMedium'],
            ]
            && $request->data()['dateRanges'] === [[
                'startDate' => '2026-11-01',
                'endDate' => '2026-11-30',
            ]]
            && $request->data()['dimensionFilter']['andGroup']['expressions'][0]['filter']['stringFilter']['value'] === 'spring_launch_2026'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][1]['filter']['fieldName'] === 'sessionManualSource'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][1]['filter']['stringFilter']['value'] === 'facebook_ads'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][2]['filter']['fieldName'] === 'sessionManualMedium'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][2]['filter']['stringFilter']['value'] === 'paid_social'
            && $request->hasHeader('Authorization', 'Bearer short-lived-access-token'));
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dimensions'] === [['name' => 'date']]
            && $request->data()['metrics'] === [
                ['name' => 'sessions'],
                ['name' => 'engagedSessions'],
                ['name' => 'keyEvents'],
                ['name' => 'totalRevenue'],
            ]
            && $request->data()['dateRanges'] === [[
                'startDate' => '2026-11-01',
                'endDate' => '2026-11-30',
            ]]
            && $request->data()['dimensionFilter']['andGroup']['expressions'][0]['filter']['stringFilter']['value'] === 'spring_launch_2026'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][1]['filter']['fieldName'] === 'sessionManualSource'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][1]['filter']['stringFilter']['value'] === 'facebook_ads'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][2]['filter']['fieldName'] === 'sessionManualMedium'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][2]['filter']['stringFilter']['value'] === 'paid_social'
            && $request->hasHeader('Authorization', 'Bearer short-lived-access-token'));
        $reportResponse = $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('GA4 導流成效')
            ->assertSee('2,400')
            ->assertSee('1,800')
            ->assertSee('37.50')
            ->assertSee('12,890.25')
            ->assertSee('TWD')
            ->assertSee('spring_launch_2026')
            ->assertSee('facebook_ads')
            ->assertSee('paid_social')
            ->assertSee('渠道、來源與媒介成效')
            ->assertSee('Paid Social')
            ->assertSee('工作階段')
            ->assertSee('facebook_ads')
            ->assertSee(route('social-engagement-targets.google-analytics.channels', $target))
            ->assertSee('匯出明細 CSV')
            ->assertSee('GA4 累計成效變化')
            ->assertSee('更新於')
            ->assertSee('2026/11/22 06:30')
            ->assertSee('匯出趨勢 CSV')
            ->assertSee('基準');

        $this->assertNull($reportResponse->viewData('report')['interaction_total']);

        $campaignCsv = $this->actingAs($user)
            ->get(route('social-engagement-targets.report.export', $target))
            ->assertDownload('campaign-report-'.$target->id.'.csv')
            ->streamedContent();
        $this->assertStringContainsString('ga4_medium_name', $campaignCsv);
        $this->assertStringContainsString('ga4_source_medium_breakdown_json', $campaignCsv);
        $this->assertStringContainsString('paid_social', $campaignCsv);

        Http::assertSentCount(4);
    }

    public function test_ga4_campaign_report_preserves_large_revenue_decimal_precision(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => $this->campaignReportResponse([
                'rows' => [],
                'totals' => [[
                    'metricValues' => [
                        ['value' => '1'],
                        ['value' => '1'],
                        ['value' => '1.23445'],
                        ['value' => '576460752303.42354'],
                    ],
                ]],
                'rowCount' => 0,
                'metadata' => ['currencyCode' => 'TWD'],
            ]),
        ]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'precision-check',
            '2026-11-01',
            '2026-11-30',
        );

        $this->assertSame('1.2345', $report['key_events']);
        $this->assertSame('576460752303.4235', $report['total_revenue']);
        $this->assertSame([], $report['source_medium_breakdown']);
        $this->assertSame([], $report['daily_metrics']);
        Http::assertSentCount(3);
    }

    public function test_ga4_sync_matches_google_ads_auto_tagged_campaign_by_id_without_manual_utm_filters(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 06:30:00', 'Asia/Taipei'));
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => $this->campaignReportResponse([
                'rows' => [[
                    'dimensionValues' => [
                        ['value' => '123456789012'],
                        ['value' => 'Paid Search'],
                        ['value' => 'google'],
                        ['value' => 'cpc'],
                    ],
                    'metricValues' => [
                        ['value' => '240'],
                        ['value' => '180'],
                        ['value' => '12.5'],
                        ['value' => '800.25'],
                    ],
                ]],
                'totals' => [[
                    'metricValues' => [
                        ['value' => '240'],
                        ['value' => '180'],
                        ['value' => '12.5'],
                        ['value' => '800.25'],
                    ],
                ]],
                'rowCount' => 1,
                'metadata' => ['currencyCode' => 'TWD'],
            ]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget([
            'ga4_campaign_name' => 'same-name-used-by-other-campaigns',
            'ga4_source_name' => 'old_utm_source',
            'ga4_medium_name' => 'old_utm_medium',
            'google_ads_campaign_id' => '123456789012',
        ]);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
        $previousManualSnapshot = $this->createTrendSnapshot(
            $connection->id,
            $target,
            '2026-11-21',
            50,
            '120.0000',
            'TWD',
        );
        $previousManualSnapshot->update(['source_name' => null, 'medium_name' => null]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', 'GA4 活動成效已同步。');

        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $snapshot = GoogleAnalyticsCampaignSnapshot::query()
            ->where('attribution_mode', 'google_ads_auto_tagging')
            ->sole();
        $this->assertSame('google_ads_auto_tagging', $report->attribution_mode);
        $this->assertSame('123456789012', $report->campaign_id);
        $this->assertNull($report->source_name);
        $this->assertNull($report->medium_name);
        $this->assertSame(240, $report->sessions);
        $this->assertSame('google_ads_auto_tagging', $snapshot->attribution_mode);
        $this->assertSame('123456789012', $snapshot->campaign_id);

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dimensions'] === [
                ['name' => 'sessionGoogleAdsCampaignId'],
                ['name' => 'sessionDefaultChannelGroup'],
                ['name' => 'sessionSource'],
                ['name' => 'sessionMedium'],
            ]
            && $request->data()['dimensionFilter']['filter']['fieldName'] === 'sessionGoogleAdsCampaignId'
            && $request->data()['dimensionFilter']['filter']['stringFilter']['value'] === '123456789012'
            && $request->data()['dimensionFilter']['filter']['stringFilter']['matchType'] === 'EXACT');
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dimensions'] === [['name' => 'date']]
            && $request->data()['dimensionFilter']['filter']['fieldName'] === 'sessionGoogleAdsCampaignId'
            && $request->data()['dimensionFilter']['filter']['stringFilter']['value'] === '123456789012'
            && $request->data()['dimensionFilter']['filter']['stringFilter']['matchType'] === 'EXACT');

        $reportPage = $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('比對 Google Ads 活動 ID')
            ->assertSee('123456789012')
            ->assertSee('GA4 自動標記歸因')
            ->assertSee('使用 GA4 自動標記歸因')
            ->assertDontSee('比對活動名稱：');
        $trend = $reportPage->viewData('googleAnalyticsTrend');
        $this->assertCount(1, $trend);
        $this->assertSame(240, $trend[0]['sessions']);
        $this->assertSame('google_ads_auto_tagging', $trend[0]['attribution_mode']);

        $campaignCsv = $this->get(route('social-engagement-targets.report.export', $target))
            ->assertDownload('campaign-report-'.$target->id.'.csv')
            ->streamedContent();
        $this->assertStringContainsString('ga4_attribution_mode', $campaignCsv);
        $this->assertStringContainsString('google_ads_auto_tagging', $campaignCsv);
        $this->assertStringContainsString('123456789012', $campaignCsv);

        $sync = app(GoogleAnalyticsCampaignReportSync::class);
        $this->assertTrue($sync->isSnapshotFresh($report, $target, CarbonImmutable::now()));
        $target->forceFill(['ga4_campaign_name' => 'changed-unused-name'])->save();
        $this->assertTrue($sync->isSnapshotFresh($report, $target->fresh(), CarbonImmutable::now()));
        $target->forceFill(['google_ads_campaign_id' => '123456789013'])->save();
        $this->assertFalse($sync->isSnapshotFresh($report, $target->fresh(), CarbonImmutable::now()));
    }

    public function test_ga4_campaign_report_returns_source_medium_rows_and_marks_truncated_results(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => $this->campaignReportResponse([
                'rows' => [
                    [
                        'dimensionValues' => [
                            ['value' => 'spring-launch'],
                            ['value' => 'Paid Social'],
                            ['value' => 'facebook'],
                            ['value' => 'paid_social'],
                        ],
                        'metricValues' => [
                            ['value' => '24'],
                            ['value' => '20'],
                            ['value' => '3.5'],
                            ['value' => '120.25'],
                        ],
                    ],
                    [
                        'dimensionValues' => [
                            ['value' => 'spring-launch'],
                            ['value' => 'Email'],
                            ['value' => 'newsletter'],
                            ['value' => 'email'],
                        ],
                        'metricValues' => [
                            ['value' => '16'],
                            ['value' => '11'],
                            ['value' => '2'],
                            ['value' => '80.5'],
                        ],
                    ],
                    [
                        'dimensionValues' => [
                            ['value' => 'RESERVED_TOTAL'],
                            ['value' => 'RESERVED_TOTAL'],
                            ['value' => 'RESERVED_TOTAL'],
                            ['value' => 'RESERVED_TOTAL'],
                        ],
                        'metricValues' => [
                            ['value' => '40'],
                            ['value' => '31'],
                            ['value' => '5.5'],
                            ['value' => '200.75'],
                        ],
                    ],
                ],
                'totals' => [[
                    'metricValues' => [
                        ['value' => '40'],
                        ['value' => '31'],
                        ['value' => '5.5'],
                        ['value' => '200.75'],
                    ],
                ]],
                'rowCount' => 10001,
                'metadata' => ['currencyCode' => 'TWD'],
            ]),
        ]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'spring-launch',
            '2026-11-01',
            '2026-11-30',
        );

        $this->assertSame(40, $report['sessions']);
        $this->assertSame(31, $report['engaged_sessions']);
        $this->assertSame('5.5000', $report['key_events']);
        $this->assertSame('200.7500', $report['total_revenue']);
        $this->assertSame([
            [
                'channel_group' => 'Paid Social',
                'source_name' => 'facebook',
                'medium_name' => 'paid_social',
                'sessions' => 24,
                'engaged_sessions' => 20,
                'key_events' => '3.5000',
                'total_revenue' => '120.2500',
            ],
            [
                'channel_group' => 'Email',
                'source_name' => 'newsletter',
                'medium_name' => 'email',
                'sessions' => 16,
                'engaged_sessions' => 11,
                'key_events' => '2.0000',
                'total_revenue' => '80.5000',
            ],
        ], $report['source_medium_breakdown']);
        $this->assertTrue($report['source_medium_breakdown_truncated']);
        $this->assertSame([], $report['daily_metrics']);
    }

    public function test_ga4_campaign_report_returns_sorted_daily_metrics_and_keeps_aggregate_totals(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => $this->campaignReportResponse(
                $this->reportPayload(30, 20, '4.5', '100.25', 'TWD'),
                [
                    'rows' => [
                        [
                            'dimensionValues' => [['value' => '20261103']],
                            'metricValues' => [
                                ['value' => '7'],
                                ['value' => '5'],
                                ['value' => '1.25'],
                                ['value' => '50.12345'],
                            ],
                        ],
                        [
                            'dimensionValues' => [['value' => '20261101']],
                            'metricValues' => [
                                ['value' => '11'],
                                ['value' => '8'],
                                ['value' => '2.5'],
                                ['value' => '30.1'],
                            ],
                        ],
                    ],
                    'rowCount' => 2,
                ],
            ),
        ]);

        $report = (new GoogleAnalyticsDataApi)->campaignReport(
            'access-token',
            '123456789',
            'spring-launch',
            '2026-11-01',
            '2026-11-03',
            'facebook',
            'paid_social',
        );

        $this->assertSame(30, $report['sessions']);
        $this->assertSame(20, $report['engaged_sessions']);
        $this->assertSame('4.5000', $report['key_events']);
        $this->assertSame('100.2500', $report['total_revenue']);
        $this->assertSame([
            [
                'date' => '2026-11-01',
                'sessions' => 11,
                'engaged_sessions' => 8,
                'key_events' => '2.5000',
                'total_revenue' => '30.1000',
            ],
            [
                'date' => '2026-11-03',
                'sessions' => 7,
                'engaged_sessions' => 5,
                'key_events' => '1.2500',
                'total_revenue' => '50.1235',
            ],
        ], $report['daily_metrics']);
        Http::assertSentCount(3);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dimensions'] === [['name' => 'date']]
            && $request->data()['dateRanges'] === [[
                'startDate' => '2026-11-01',
                'endDate' => '2026-11-03',
            ]]
            && $request->data()['metrics'] === [
                ['name' => 'sessions'],
                ['name' => 'engagedSessions'],
                ['name' => 'keyEvents'],
                ['name' => 'totalRevenue'],
            ]
            && $request->data()['dimensionFilter']['andGroup']['expressions'][0]['filter']['stringFilter']['value'] === 'spring-launch'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][1]['filter']['fieldName'] === 'sessionManualSource'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][1]['filter']['stringFilter']['value'] === 'facebook'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][2]['filter']['fieldName'] === 'sessionManualMedium'
            && $request->data()['dimensionFilter']['andGroup']['expressions'][2]['filter']['stringFilter']['value'] === 'paid_social');
    }

    public function test_ga4_campaign_report_rejects_malformed_daily_rows(): void
    {
        Http::preventStrayRequests();
        $validMetrics = [
            ['value' => '1'],
            ['value' => '1'],
            ['value' => '1.5'],
            ['value' => '2.5'],
        ];
        $validDailyRow = static fn (string $date): array => [
            'dimensionValues' => [['value' => $date]],
            'metricValues' => $validMetrics,
        ];
        $invalidDailyRows = [
            'malformed date' => [$validDailyRow('20260230')],
            'date outside range' => [$validDailyRow('20261031')],
            'wrong metric count' => [[
                'dimensionValues' => [['value' => '20261101']],
                'metricValues' => array_slice($validMetrics, 0, 3),
            ]],
            'duplicate date' => [$validDailyRow('20261101'), $validDailyRow('20261101')],
        ];

        foreach ($invalidDailyRows as $case => $rows) {
            Http::fake([
                'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => $this->campaignReportResponse(
                    $this->reportPayload(1, 1, '1', '1', 'TWD'),
                    ['rows' => $rows, 'rowCount' => count($rows)],
                ),
            ]);

            try {
                (new GoogleAnalyticsDataApi)->campaignReport(
                    'access-token',
                    '123456789',
                    'spring-launch',
                    '2026-11-01',
                    '2026-11-30',
                );
                $this->fail('Expected malformed daily data to be rejected: '.$case);
            } catch (GoogleAnalyticsApiException) {
                Http::assertSentCount(2);
            }
        }
    }

    public function test_ga4_campaign_report_rejects_invalid_requested_dates_before_api_calls(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        try {
            (new GoogleAnalyticsDataApi)->campaignReport(
                'access-token',
                '123456789',
                'spring-launch',
                '2026-11-30',
                '2026-11-01',
            );
            $this->fail('Expected reversed campaign dates to be rejected.');
        } catch (GoogleAnalyticsApiException) {
            Http::assertNothingSent();
        }
    }

    public function test_ga4_report_history_ignores_snapshots_with_a_different_medium(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00', 'Asia/Taipei'));
        $user = User::factory()->create();
        $target = $this->createTarget([
            'ga4_campaign_name' => 'spring_launch_2026',
            'ga4_source_name' => 'facebook_ads',
            'ga4_medium_name' => 'paid_social',
        ]);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

        $otherMediumSnapshot = $this->createTrendSnapshot(
            $connection->id,
            $target,
            '2026-11-21',
            10,
            '100.0000',
            'TWD',
        );
        $otherMediumSnapshot->update(['medium_name' => 'organic_social']);
        $this->createTrendSnapshot(
            $connection->id,
            $target,
            '2026-11-22',
            25,
            '300.0000',
            'TWD',
        );
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'source_name' => $target->googleAnalyticsSourceName(),
            'medium_name' => $target->googleAnalyticsMediumName(),
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 25,
            'engaged_sessions' => 20,
            'key_events' => '3.0000',
            'total_revenue' => '300.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('paid_social')
            ->assertDontSee('organic_social');

        $trend = $response->viewData('googleAnalyticsTrend');
        $this->assertCount(1, $trend);
        $this->assertSame('2026-11-22', $trend[0]['snapshot_date']->toDateString());
    }

    public function test_ga4_sync_uses_the_social_target_tag_when_no_utm_campaign_name_is_set(): void
    {
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => $this->campaignReportResponse([
                'rows' => [],
                'metadata' => ['currencyCode' => 'JPY'],
            ]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget(['tag' => 'Autumn promotion']);
        GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status');

        $snapshot = SocialEngagementTargetGoogleAnalyticsReport::query()->sole();
        $this->assertSame('Autumn promotion', $snapshot->campaign_name);
        $this->assertSame(0, $snapshot->sessions);
        $this->assertSame(0, $snapshot->engaged_sessions);
        $this->assertSame('0.0000', $snapshot->key_events);
        $this->assertSame('0.0000', $snapshot->total_revenue);
        $this->assertSame('JPY', $snapshot->currency_code);
        $this->assertSame([], $snapshot->source_medium_breakdown);

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), ':runReport')
            && $request->data()['dimensionFilter']['filter']['stringFilter']['value'] === 'Autumn promotion');
    }

    public function test_ga4_quota_error_keeps_the_last_successful_snapshot(): void
    {
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => Http::response([
                'error' => ['message' => 'RESOURCE_EXHAUSTED'],
            ], 429),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
        $snapshot = SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->tag,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 12,
            'engaged_sessions' => 8,
            'key_events' => '2.0000',
            'total_revenue' => '500.0000',
            'currency_code' => 'TWD',
            'synced_at' => now()->subHour(),
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_analytics']);

        $this->assertSame(12, $snapshot->fresh()->sessions);
        $this->assertSame('500.0000', $snapshot->fresh()->total_revenue);
        $this->assertDatabaseCount('social_engagement_target_google_analytics_reports', 1);
    }

    public function test_ga4_thresholded_data_is_not_saved_as_zero(): void
    {
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => Http::response([
                'rows' => [],
                'metadata' => [
                    'currencyCode' => 'TWD',
                    'subjectToThresholding' => true,
                ],
            ]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_analytics']);

        $this->assertDatabaseCount('social_engagement_target_google_analytics_reports', 0);
    }

    public function test_ga4_report_snapshot_is_scoped_to_the_current_users_connection(): void
    {
        $viewer = User::factory()->create();
        $otherUser = User::factory()->create();
        $target = $this->createTarget();
        $viewerConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $viewer->id,
            'property_id' => '123456789',
            'refresh_token' => 'viewers-token',
        ]);
        $otherConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $otherUser->id,
            'property_id' => '987654321',
            'refresh_token' => 'other-users-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $otherConnection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->tag,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 987654321,
            'engaged_sessions' => 987654320,
            'key_events' => '99.0000',
            'total_revenue' => '1000000.0000',
            'currency_code' => 'USD',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($viewer)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('尚無 GA4 讀值');

        $this->assertNull($response->viewData('googleAnalyticsReport'));
        $this->assertStringNotContainsString('987,654,321', $response->getContent());
        $this->assertStringNotContainsString('1,000,000.00', $response->getContent());
    }

    public function test_ga4_sync_updates_one_snapshot_per_day_and_exports_scoped_trend_deltas(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00:00', 'Asia/Taipei'));
        config([
            'services.google_analytics.client_id' => 'client-id',
            'services.google_analytics.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => Http::sequence()
                ->push($this->reportPayload(100, 50, '10', '1000', 'TWD'))
                ->push(['rows' => []])
                ->push(['rows' => []])
                ->push($this->reportPayload(130, 70, '12', '1200', 'TWD'))
                ->push(['rows' => []])
                ->push(['rows' => []])
                ->push($this->reportPayload(155, 80, '16.5', '1500', 'TWD'))
                ->push(['rows' => []])
                ->push(['rows' => []]),
        ]);

        $user = User::factory()->create();
        $target = $this->createTarget([
            'tag' => 'Trend campaign',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target));

        $this->assertDatabaseCount('google_analytics_campaign_snapshots', 1);
        $this->assertDatabaseHas('google_analytics_campaign_snapshots', [
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'snapshot_date' => '2026-11-20',
            'sessions' => 100,
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'Asia/Taipei'));
        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target));

        $this->assertDatabaseCount('google_analytics_campaign_snapshots', 1);
        $this->assertDatabaseHas('google_analytics_campaign_snapshots', [
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'snapshot_date' => '2026-11-20',
            'sessions' => 130,
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-11-21 06:30:00', 'Asia/Taipei'));
        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-analytics.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target));

        $otherUser = User::factory()->create();
        $otherConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $otherUser->id,
            'property_id' => '987654321',
            'refresh_token' => 'other-refresh-token',
        ]);
        GoogleAnalyticsCampaignSnapshot::query()->create([
            'google_analytics_connection_id' => $otherConnection->id,
            'social_engagement_target_id' => $target->id,
            'snapshot_date' => '2026-11-21',
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'source_name' => null,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 999999,
            'engaged_sessions' => 999998,
            'key_events' => '99.0000',
            'total_revenue' => '999999.0000',
            'currency_code' => 'USD',
            'synced_at' => now(),
        ]);

        $reportResponse = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('155')
            ->assertSee('+25')
            ->assertSee('+10')
            ->assertSee('+4.50')
            ->assertSee('+300.00 TWD')
            ->assertDontSee('999,999');

        $trend = $reportResponse->viewData('googleAnalyticsTrend');
        $this->assertCount(2, $trend);
        $this->assertNull($trend[0]['sessions_change']);
        $this->assertSame(25, $trend[1]['sessions_change']);
        $this->assertSame(10, $trend[1]['engaged_sessions_change']);
        $this->assertSame(4.5, $trend[1]['key_events_change']);
        $this->assertSame(300.0, $trend[1]['total_revenue_change']);

        $csvResponse = $this->actingAs($user)
            ->get(route('social-engagement-targets.google-analytics.history', $target))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('ga4-campaign-trend-'.$target->id.'.csv', (string) $csvResponse->headers->get('content-disposition'));
        $csvLines = preg_split('/\r\n|\n|\r/', trim($csvResponse->streamedContent()));
        $this->assertCount(3, $csvLines);
        $this->assertSame('sessions_change_since_previous', str_getcsv($csvLines[0])[5]);
        $this->assertStringContainsString(',25,', $csvLines[2]);
        $this->assertStringNotContainsString('999999', $csvResponse->streamedContent());
        Http::assertSentCount(12);
    }

    public function test_campaign_report_marks_ga4_read_stale_after_one_day(): void
    {
        $this->travelTo('2026-11-22 08:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
        $report = SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'source_name' => $target->googleAnalyticsSourceName(),
            'medium_name' => $target->googleAnalyticsMediumName(),
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 100,
            'engaged_sessions' => 80,
            'key_events' => '3.0000',
            'total_revenue' => '1000.0000',
            'currency_code' => 'TWD',
            'synced_at' => now()->subDay()->subSecond(),
        ]);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('GA4 超過 24 小時未成功更新');

        $report->update(['synced_at' => now()->subDay()->addSecond()]);

        $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertDontSee('GA4 超過 24 小時未成功更新');
    }

    public function test_ga4_trend_does_not_compare_revenue_when_currency_changes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-21 06:30:00', 'Asia/Taipei'));
        $user = User::factory()->create();
        $target = $this->createTarget(['tag' => 'Currency campaign']);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
        $this->createTrendSnapshot($connection->id, $target, '2026-11-20', 100, '1000.0000', 'TWD');
        $this->createTrendSnapshot($connection->id, $target, '2026-11-21', 120, '1500.0000', 'USD');
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 120,
            'engaged_sessions' => 80,
            'key_events' => '3.0000',
            'total_revenue' => '1500.0000',
            'currency_code' => 'USD',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('幣別無法比較');

        $trend = $response->viewData('googleAnalyticsTrend');
        $this->assertFalse($trend[1]['revenue_currency_comparable']);
        $this->assertNull($trend[1]['total_revenue_change']);
    }

    public function test_ga4_trend_export_requires_authentication(): void
    {
        $target = $this->createTarget();
        Http::fake();

        $this->get(route('social-engagement-targets.google-analytics.history', $target))
            ->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_ga4_campaign_snapshot_seeder_backfills_current_reports_idempotently(): void
    {
        $target = $this->createTarget(['tag' => 'Seed campaign']);
        $breakdown = [[
            'channel_group' => 'Paid Social',
            'source_name' => 'facebook',
            'medium_name' => 'paid_social',
            'sessions' => 200,
            'engaged_sessions' => 150,
            'key_events' => '12.0000',
            'total_revenue' => '800.0000',
        ]];
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => User::factory()->create()->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 200,
            'engaged_sessions' => 150,
            'key_events' => '12.0000',
            'total_revenue' => '800.0000',
            'source_medium_breakdown' => $breakdown,
            'source_medium_breakdown_truncated' => true,
            'currency_code' => 'TWD',
            'synced_at' => CarbonImmutable::parse('2026-11-20 06:30:00', 'Asia/Taipei'),
        ]);

        app(GoogleAnalyticsCampaignSnapshotSeeder::class)->run();
        app(GoogleAnalyticsCampaignSnapshotSeeder::class)->run();

        $this->assertDatabaseCount('google_analytics_campaign_snapshots', 1);
        $this->assertDatabaseHas('google_analytics_campaign_snapshots', [
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'snapshot_date' => '2026-11-20',
            'sessions' => 200,
        ]);
        $snapshot = GoogleAnalyticsCampaignSnapshot::query()->sole();
        $this->assertSame($breakdown, $snapshot->source_medium_breakdown);
        $this->assertTrue($snapshot->source_medium_breakdown_truncated);
    }

    public function test_campaign_snapshot_migration_backfills_the_latest_ga4_read_in_taipei_time(): void
    {
        $target = $this->createTarget(['tag' => 'Migration campaign']);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => User::factory()->create()->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 300,
            'engaged_sessions' => 200,
            'key_events' => '18.0000',
            'total_revenue' => '900.0000',
            'currency_code' => 'TWD',
            'synced_at' => CarbonImmutable::parse('2026-11-20 16:30:00', 'UTC'),
        ]);

        Schema::dropIfExists('google_analytics_campaign_snapshots');
        $migration = require database_path('migrations/2026_11_22_033240_create_google_analytics_campaign_snapshots_table.php');
        $migration->up();

        $this->assertDatabaseHas('google_analytics_campaign_snapshots', [
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'snapshot_date' => '2026-11-21',
            'sessions' => 300,
        ]);
    }

    public function test_ga4_trend_displays_the_latest_thirty_reads_with_a_previous_baseline(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'Asia/Taipei'));
        $user = User::factory()->create();
        $target = $this->createTarget([
            'tag' => 'Long-running campaign',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-12-31',
        ]);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

        for ($day = 0; $day <= 30; $day++) {
            $date = CarbonImmutable::parse('2026-11-01', 'Asia/Taipei')->addDays($day);
            $this->createTrendSnapshot(
                $connection->id,
                $target,
                $date->toDateString(),
                $day + 1,
                (string) ($day * 100),
                'TWD',
            );
        }

        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 31,
            'engaged_sessions' => 11,
            'key_events' => '3.0000',
            'total_revenue' => '3000.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();
        $trend = $response->viewData('googleAnalyticsTrend');

        $this->assertCount(30, $trend);
        $this->assertSame('2026-11-02', $trend[0]['snapshot_date']->toDateString());
        $this->assertSame(2, $trend[0]['sessions']);
        $this->assertSame(1, $trend[0]['sessions_change']);
        $this->assertSame(31, $trend[29]['sessions']);
    }

    /** @param array<string, mixed> $attributes */
    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create($attributes + [
            'tag' => 'Spring launch',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
    }

    /**
     * @param  array<string, mixed>  $aggregatePayload
     * @param  array<string, mixed>  $dailyPayload
     */
    private function campaignReportResponse(
        array $aggregatePayload,
        array $dailyPayload = ['rows' => []],
    ): \Closure {
        return static function (ClientRequest $request) use ($aggregatePayload, $dailyPayload) {
            $isDailyRequest = ($request->data()['dimensions'] ?? null) === [['name' => 'date']];

            return Http::response($isDailyRequest ? $dailyPayload : $aggregatePayload);
        };
    }

    /** @return array<string, mixed> */
    private function reportPayload(int $sessions, int $engagedSessions, string $keyEvents, string $revenue, string $currency): array
    {
        return [
            'rows' => [[
                'dimensionValues' => [
                    ['value' => 'campaign'],
                    ['value' => 'Direct'],
                    ['value' => '(not set)'],
                    ['value' => '(not set)'],
                ],
                'metricValues' => [
                    ['value' => (string) $sessions],
                    ['value' => (string) $engagedSessions],
                    ['value' => $keyEvents],
                    ['value' => $revenue],
                ],
            ]],
            'totals' => [[
                'metricValues' => [
                    ['value' => (string) $sessions],
                    ['value' => (string) $engagedSessions],
                    ['value' => $keyEvents],
                    ['value' => $revenue],
                ],
            ]],
            'rowCount' => 1,
            'metadata' => ['currencyCode' => $currency],
        ];
    }

    private function createTrendSnapshot(
        int $connectionId,
        SocialEngagementTarget $target,
        string $date,
        int $sessions,
        string $revenue,
        string $currency,
    ): GoogleAnalyticsCampaignSnapshot {
        return GoogleAnalyticsCampaignSnapshot::query()->create([
            'google_analytics_connection_id' => $connectionId,
            'social_engagement_target_id' => $target->id,
            'snapshot_date' => $date,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'source_name' => $target->googleAnalyticsSourceName(),
            'medium_name' => $target->googleAnalyticsMediumName(),
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => $sessions,
            'engaged_sessions' => max(0, $sessions - 20),
            'key_events' => '3.0000',
            'total_revenue' => $revenue,
            'currency_code' => $currency,
            'synced_at' => CarbonImmutable::parse($date.' 06:30:00', 'Asia/Taipei'),
        ]);
    }
}
