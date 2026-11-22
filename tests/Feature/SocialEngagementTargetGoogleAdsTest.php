<?php

namespace Tests\Feature;

use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\SocialEngagementTargetPaidAdSnapshot;
use App\Models\User;
use App\Services\GoogleAdsApiException;
use App\Services\GoogleAdsDataApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialEngagementTargetGoogleAdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_ads_sync_requires_authentication(): void
    {
        $target = $this->createTarget();

        $this->post(route('social-engagement-targets.google-ads.sync', $target))
            ->assertRedirect(route('login'));
    }

    public function test_google_ads_snapshot_is_scoped_to_the_current_users_connection(): void
    {
        $viewer = User::factory()->create();
        $otherUser = User::factory()->create();
        $target = $this->createTarget();
        $this->createConnection($viewer);
        $otherConnection = $this->createConnection($otherUser);
        $this->createReport($otherConnection, $target, [
            'campaign_name' => 'Private other-user campaign',
            'spend_amount' => '98765.4321',
        ]);

        $response = $this->actingAs($viewer)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('尚無 Google Ads 讀值')
            ->assertDontSee('Private other-user campaign')
            ->assertDontSee('98,765.4321');

        $this->assertNull($response->viewData('googleAdsReport'));
    }

    public function test_google_ads_campaign_list_can_be_loaded_and_applied_to_the_current_target(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [
                    [
                        'customer' => ['id' => '1234567890'],
                        'campaign' => [
                            'id' => '987654322',
                            'name' => 'Winter campaign',
                            'status' => 'ENABLED',
                            'advertisingChannelType' => 'SEARCH',
                        ],
                    ],
                    [
                        'customer' => ['id' => '1234567890'],
                        'campaign' => [
                            'id' => '987654321',
                            'name' => 'Promo <Spring> & Summer',
                            'status' => 'PAUSED',
                            'advertisingChannelType' => 'PERFORMANCE_MAX',
                        ],
                    ],
                ],
            ]]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget(['google_ads_campaign_id' => '987654323']);
        $otherTarget = $this->createTarget(['tag' => 'Other campaign', 'google_ads_campaign_id' => '987654324']);
        $this->createConnection($user, ['login_customer_id' => '0987654321']);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-ads.campaign-options', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('google_ads_campaign_options');

        $response = $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('選擇 Google Ads 活動')
            ->assertSee('Winter campaign · 987654322 · ENABLED · SEARCH')
            ->assertSee('&lt;Spring&gt; &amp; Summer', false);

        $this->assertSame([
            [
                'campaign_id' => '987654321',
                'campaign_name' => 'Promo <Spring> & Summer',
                'status' => 'PAUSED',
                'channel_type' => 'PERFORMANCE_MAX',
            ],
            [
                'campaign_id' => '987654322',
                'campaign_name' => 'Winter campaign',
                'status' => 'ENABLED',
                'channel_type' => 'SEARCH',
            ],
        ], $response->viewData('googleAdsCampaignOptions'));

        $this->from(route('social-engagement-targets.report', $target))
            ->put(route('social-engagement-targets.google-ads.campaign.update', $target), [
                'google_ads_campaign_id' => '987654321',
            ])
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', 'Google Ads 活動已套用至此活動目標。');

        $this->assertSame('987654321', $target->fresh()->google_ads_campaign_id);
        $this->assertSame('987654324', $otherTarget->fresh()->google_ads_campaign_id);
        Http::assertSentCount(2);
        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_ends_with($request->url(), '/customers/1234567890/googleAds:searchStream')
                && $request->hasHeader('Authorization', 'Bearer temporary-access-token')
                && $request->hasHeader('login-customer-id', '0987654321')
                && str_contains($query, 'campaign.advertising_channel_type')
                && str_contains($query, "campaign.status != 'REMOVED'")
                && str_contains($query, 'ORDER BY campaign.name LIMIT 500');
        });
    }

    public function test_google_ads_campaign_list_requires_authentication(): void
    {
        $target = $this->createTarget();

        $this->post(route('social-engagement-targets.google-ads.campaign-options', $target))
            ->assertRedirect(route('login'));
        $this->put(route('social-engagement-targets.google-ads.campaign.update', $target), [
            'google_ads_campaign_id' => '987654321',
        ])->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_google_ads_campaign_list_rejects_rows_from_another_customer(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [[
                    'customer' => ['id' => '9999999999'],
                    'campaign' => [
                        'id' => '987654321',
                        'name' => 'Unexpected customer campaign',
                        'status' => 'ENABLED',
                        'advertisingChannelType' => 'SEARCH',
                    ],
                ]],
            ]]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget(['google_ads_campaign_id' => '987654322']);
        $this->createConnection($user);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-ads.campaign-options', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_ads']);

        $this->assertSame('987654322', $target->fresh()->google_ads_campaign_id);
        Http::assertSentCount(2);
    }

    public function test_google_ads_campaign_selection_rejects_invalid_campaign_ids(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(['google_ads_campaign_id' => '987654321']);

        $this->actingAs($user)
            ->from(route('social-engagement-targets.report', $target))
            ->put(route('social-engagement-targets.google-ads.campaign.update', $target), [
                'google_ads_campaign_id' => 'campaign-123',
            ])
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_ads_campaign_id']);

        $this->assertSame('987654321', $target->fresh()->google_ads_campaign_id);
        Http::assertNothingSent();
    }

    public function test_google_ads_campaign_list_displays_when_no_active_campaigns_are_returned(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createConnection($user);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-ads.campaign-options', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target));

        $response = $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('此客戶帳戶沒有可選的 Google Ads 活動。');

        $this->assertSame([], $response->viewData('googleAdsCampaignOptions'));
        Http::assertSentCount(2);
    }

    public function test_google_ads_sync_saves_metrics_and_uses_google_ads_and_ga4_for_efficiency(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890', 'currencyCode' => 'USD'],
                        'campaign' => ['id' => '987654321', 'name' => 'Winter product launch'],
                        'metrics' => [
                            'costMicros' => '250750000',
                            'impressions' => '12000',
                            'clicks' => '480',
                            'conversions' => 12.5,
                            'conversionsValue' => 1003,
                        ],
                    ]],
                ]])
                ->push($this->dailyMetricsPayload([
                    ['date' => '2026-11-02', 'cost_micros' => '100000000', 'impressions' => '4000', 'clicks' => '160', 'conversions' => '4.5', 'conversions_value' => '300.25'],
                    ['date' => '2026-11-03', 'cost_micros' => '150750000', 'impressions' => '8000', 'clicks' => '320', 'conversions' => '8', 'conversions_value' => '702.75'],
                ], '1234567890', '987654321', 'USD'))
                ->push($this->conversionActionsPayload([
                    ['id' => '1002', 'name' => 'Web purchase', 'conversions' => '10.5', 'value' => '900.25'],
                    ['id' => '1001', 'name' => 'Lead & form <Submit>', 'conversions' => '7', 'value' => '80.5'],
                ])),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget([
            'ad_spend_amount' => '999.0000',
            'ad_spend_currency' => 'TWD',
        ]);
        $connection = $this->createConnection($user, ['login_customer_id' => '0987654321']);
        $this->createGoogleAnalyticsReport($user, $target);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-ads.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', 'Google Ads 活動成效已同步。');

        $snapshot = SocialEngagementTargetGoogleAdsReport::query()->sole();
        $this->assertSame($connection->id, $snapshot->google_ads_connection_id);
        $this->assertSame('1234567890', $snapshot->customer_id);
        $this->assertSame('987654321', $snapshot->campaign_id);
        $this->assertSame('Winter product launch', $snapshot->campaign_name);
        $this->assertSame('250.7500', $snapshot->spend_amount);
        $this->assertSame(12000, $snapshot->impressions);
        $this->assertSame(480, $snapshot->clicks);
        $this->assertSame('12.5000', $snapshot->conversions);
        $this->assertSame('1003.0000', $snapshot->conversions_value);
        $this->assertSame([
            [
                'conversion_action_id' => '1001',
                'conversion_action_name' => 'Lead & form <Submit>',
                'conversions' => '7.0000',
                'conversion_value' => '80.5000',
            ],
            [
                'conversion_action_id' => '1002',
                'conversion_action_name' => 'Web purchase',
                'conversions' => '10.5000',
                'conversion_value' => '900.2500',
            ],
        ], $snapshot->conversion_actions);
        $this->assertSame('USD', $snapshot->currency_code);
        $this->assertNotNull($snapshot->synced_at);
        $this->assertSame([
            'status' => 'complete',
            'requested_start_on' => '2026-11-01',
            'requested_end_on' => '2026-11-30',
            'coverage_start_on' => '2026-11-01',
            'coverage_end_on' => '2026-11-30',
            'rows' => [
                [
                    'date' => '2026-11-02',
                    'spend_amount' => '100.0000',
                    'impressions' => 4000,
                    'clicks' => 160,
                    'conversions' => '4.5000',
                    'conversions_value' => '300.2500',
                ],
                [
                    'date' => '2026-11-03',
                    'spend_amount' => '150.7500',
                    'impressions' => 8000,
                    'clicks' => 320,
                    'conversions' => '8.0000',
                    'conversions_value' => '702.7500',
                ],
            ],
        ], $snapshot->daily_metrics);
        $paidAdSnapshot = SocialEngagementTargetPaidAdSnapshot::query()->sole();
        $this->assertSame(SocialEngagementTargetPaidAdSnapshot::SOURCE_GOOGLE_ADS, $paidAdSnapshot->source);
        $this->assertSame($connection->id, $paidAdSnapshot->google_ads_connection_id);
        $this->assertSame($connection->customer_id, $paidAdSnapshot->account_id);
        $this->assertSame('250.7500', $paidAdSnapshot->spend_amount);
        $this->assertSame('12.5000', $paidAdSnapshot->conversions);
        $this->assertSame('1003.0000', $paidAdSnapshot->conversions_value);
        $this->assertSame($snapshot->conversion_actions, $paidAdSnapshot->conversion_actions);
        Http::assertSentCount(4);
        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_ends_with($request->url(), '/customers/1234567890/googleAds:searchStream')
                && $request->hasHeader('Authorization', 'Bearer temporary-access-token')
                && $request->hasHeader('login-customer-id', '0987654321')
                && str_contains($query, 'customer.id, customer.currency_code')
                && str_contains($query, 'campaign.id = 987654321')
                && str_contains($query, "segments.date BETWEEN '2026-11-01' AND '2026-11-30'");
        });
        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_ends_with($request->url(), '/customers/1234567890/googleAds:searchStream')
                && str_contains($query, 'segments.date, metrics.cost_micros')
                && str_contains($query, 'ORDER BY segments.date ASC')
                && str_contains($query, "segments.date BETWEEN '2026-11-01' AND '2026-11-30'");
        });
        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_ends_with($request->url(), '/customers/1234567890/googleAds:searchStream')
                && str_contains($query, 'segments.conversion_action')
                && str_contains($query, 'segments.conversion_action_name')
                && str_contains($query, 'campaign.id = 987654321')
                && str_contains($query, "segments.date BETWEEN '2026-11-01' AND '2026-11-30'");
        });

        $reportResponse = $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Google Ads 投放成效')
            ->assertSee('12,000')
            ->assertSee('480')
            ->assertSee('12.50')
            ->assertSee('1,003.00 USD')
            ->assertSee('Google Ads 轉換價值 ROAS')
            ->assertSee('4.00x')
            ->assertSee('Google Ads 歷史讀值')
            ->assertSee('Google Ads 轉換動作')
            ->assertSee('Lead &amp; form &lt;Submit&gt;', false)
            ->assertSee('7.00')
            ->assertSee('80.50 USD')
            ->assertSee('Web purchase')
            ->assertSee('10.50')
            ->assertSee('900.25 USD')
            ->assertSee('基準')
            ->assertSee('250.7500 USD')
            ->assertSee('Google Ads');

        $analysis = $reportResponse->viewData('adSpendAnalysis');
        $this->assertSame('google_ads', $analysis['ad_spend_source']);
        $this->assertSame('250.7500', $analysis['ad_spend_amount']);
        $this->assertSame(4.0, $analysis['roas']);
        $this->assertSame(5.015, $analysis['cost_per_session']);
        $this->assertSame(25.075, $analysis['cost_per_key_event']);
    }

    public function test_google_ads_metrics_keep_decimal_precision_for_large_values(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
                        'campaign' => ['id' => '987654321', 'name' => 'Large campaign'],
                        'metrics' => [
                            'costMicros' => '9007199254740049',
                            'impressions' => '1000',
                            'clicks' => '40',
                            'conversions' => '2',
                            'conversionsValue' => '200',
                        ],
                    ]],
                ]])
                ->push($this->dailyMetricsPayload([
                    ['date' => '2026-11-02', 'cost_micros' => '9007199254740049', 'impressions' => '1000', 'clicks' => '40', 'conversions' => '2', 'conversions_value' => '200'],
                ], '1234567890', '987654321', 'TWD'))
                ->push($this->conversionActionsPayload([])),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createConnection($user);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-ads.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', 'Google Ads 活動成效已同步。');

        $report = SocialEngagementTargetGoogleAdsReport::query()->sole();
        $paidAdSnapshot = SocialEngagementTargetPaidAdSnapshot::query()->sole();

        $this->assertSame('9007199254.7400', $report->spend_amount);
        $this->assertSame('9007199254.7400', $paidAdSnapshot->spend_amount);
    }

    public function test_google_ads_api_preserves_large_decimal_precision_for_conversions(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
                        'campaign' => ['id' => '987654321', 'name' => 'Precision campaign'],
                        'metrics' => [
                            'costMicros' => '1000000',
                            'impressions' => '1',
                            'clicks' => '1',
                            'conversions' => '576460752303.42354',
                            'conversionsValue' => '576460752303.42354',
                        ],
                    ]],
                ]])
                ->push([])
                ->push([]),
        ]);

        $insights = (new GoogleAdsDataApi)->campaignInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );

        $this->assertSame('576460752303.4235', $insights['conversions']);
        $this->assertSame('576460752303.4235', $insights['conversions_value']);
        Http::assertSentCount(3);
    }

    public function test_google_ads_daily_metrics_mark_history_before_the_37_month_window_as_partial(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890', 'currencyCode' => 'USD'],
                        'campaign' => ['id' => '987654321', 'name' => 'Long-running campaign'],
                        'segments' => ['month' => '2023-10-01'],
                        'metrics' => [
                            'costMicros' => '100000000',
                            'impressions' => '1000',
                            'clicks' => '40',
                            'conversions' => '2',
                            'conversionsValue' => '10',
                        ],
                    ]],
                ]])
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890', 'currencyCode' => 'USD'],
                        'campaign' => ['id' => '987654321', 'name' => 'Long-running campaign'],
                        'segments' => ['date' => '2023-11-01'],
                        'metrics' => [
                            'costMicros' => '200000000',
                            'impressions' => '2000',
                            'clicks' => '80',
                            'conversions' => '3',
                            'conversionsValue' => '20',
                        ],
                    ]],
                ]])
                ->push($this->dailyMetricsPayload([
                    ['date' => '2023-10-22', 'cost_micros' => '1250000', 'impressions' => '12', 'clicks' => '2', 'conversions' => '1.5', 'conversions_value' => '3.25'],
                ], '1234567890', '987654321', 'USD'))
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890'],
                        'campaign' => ['id' => '987654321'],
                        'segments' => [
                            'month' => '2023-10-01',
                            'conversionAction' => 'customers/1234567890/conversionActions/1001',
                            'conversionActionName' => 'Purchase',
                        ],
                        'metrics' => ['conversions' => '2', 'conversionsValue' => '10'],
                    ]],
                ]])
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890'],
                        'campaign' => ['id' => '987654321'],
                        'segments' => [
                            'date' => '2023-11-01',
                            'conversionAction' => 'customers/1234567890/conversionActions/1001',
                            'conversionActionName' => 'Purchase',
                        ],
                        'metrics' => ['conversions' => '3', 'conversionsValue' => '20'],
                    ]],
                ]]),
        ]);

        $insights = (new GoogleAdsDataApi)->campaignInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2022-01-01',
            '2026-11-30',
            'USD',
        );

        $this->assertSame('partial', $insights['daily_metrics_status']);
        $this->assertSame('2023-10-22', $insights['daily_metrics_coverage_start_on']);
        $this->assertSame('2026-11-30', $insights['daily_metrics_coverage_end_on']);
        $this->assertSame([
            [
                'date' => '2023-10-22',
                'spend_amount' => '1.2500',
                'impressions' => 12,
                'clicks' => 2,
                'conversions' => '1.5000',
                'conversions_value' => '3.2500',
            ],
        ], $insights['daily_metrics']);
        $this->assertSame('300.0000', $insights['spend_amount']);
        $this->assertSame(3000, $insights['impressions']);
        $this->assertSame('5.0000', $insights['conversions']);
        Http::assertSentCount(5);
    }

    public function test_google_ads_daily_metrics_mark_a_period_before_the_37_month_window_as_unsupported(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890', 'currencyCode' => 'USD'],
                        'campaign' => ['id' => '987654321', 'name' => 'Historical campaign'],
                        'segments' => ['month' => '2019-01-01'],
                        'metrics' => [
                            'costMicros' => '125500000',
                            'impressions' => '1200',
                            'clicks' => '48',
                            'conversions' => '5.25',
                            'conversionsValue' => '250.75',
                        ],
                    ]],
                ]])
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890'],
                        'campaign' => ['id' => '987654321'],
                        'segments' => [
                            'month' => '2019-01-01',
                            'conversionAction' => 'customers/1234567890/conversionActions/1001',
                            'conversionActionName' => 'Purchase',
                        ],
                        'metrics' => ['conversions' => '5.25', 'conversionsValue' => '250.75'],
                    ]],
                ]]),
        ]);

        $insights = (new GoogleAdsDataApi)->campaignInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2019-01-01',
            '2019-12-31',
            'USD',
        );

        $this->assertSame('unsupported', $insights['daily_metrics_status']);
        $this->assertSame([], $insights['daily_metrics']);
        $this->assertNull($insights['daily_metrics_coverage_start_on']);
        $this->assertNull($insights['daily_metrics_coverage_end_on']);
        $this->assertSame('125.5000', $insights['spend_amount']);
        $this->assertSame(1200, $insights['impressions']);
        $this->assertSame(48, $insights['clicks']);
        $this->assertSame('5.2500', $insights['conversions']);
        $this->assertSame('250.7500', $insights['conversions_value']);
        $this->assertSame([
            [
                'conversion_action_id' => '1001',
                'conversion_action_name' => 'Purchase',
                'conversions' => '5.2500',
                'conversion_value' => '250.7500',
            ],
        ], $insights['conversion_actions']);
        Http::assertSent(function (ClientRequest $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_contains($query, 'segments.month BETWEEN \'2019-01-01\' AND \'2019-12-01\'');
        });
        Http::assertSentCount(2);
    }

    public function test_google_ads_old_and_recent_monthly_totals_and_conversion_actions_use_matching_ranges(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890', 'currencyCode' => 'USD'],
                        'campaign' => ['id' => '987654321', 'name' => 'Long-running campaign'],
                        'segments' => ['month' => '2023-10-01'],
                        'metrics' => [
                            'costMicros' => '100000000',
                            'impressions' => '1000',
                            'clicks' => '40',
                            'conversions' => '2.5',
                            'conversionsValue' => '50',
                        ],
                    ]],
                ]])
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890', 'currencyCode' => 'USD'],
                        'campaign' => ['id' => '987654321', 'name' => 'Long-running campaign'],
                        'segments' => ['date' => '2023-11-01'],
                        'metrics' => [
                            'costMicros' => '200000000',
                            'impressions' => '2000',
                            'clicks' => '80',
                            'conversions' => '3.25',
                            'conversionsValue' => '75.5',
                        ],
                    ]],
                ]])
                ->push($this->dailyMetricsPayload([
                    ['date' => '2023-10-22', 'cost_micros' => '1000000', 'impressions' => '10', 'clicks' => '1', 'conversions' => '1', 'conversions_value' => '10'],
                ], '1234567890', '987654321', 'USD'))
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890'],
                        'campaign' => ['id' => '987654321'],
                        'segments' => [
                            'month' => '2023-10-01',
                            'conversionAction' => 'customers/1234567890/conversionActions/1001',
                            'conversionActionName' => 'Purchase',
                        ],
                        'metrics' => ['conversions' => '2.5', 'conversionsValue' => '50'],
                    ]],
                ]])
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890'],
                        'campaign' => ['id' => '987654321'],
                        'segments' => [
                            'date' => '2023-11-01',
                            'conversionAction' => 'customers/1234567890/conversionActions/1001',
                            'conversionActionName' => 'Purchase',
                        ],
                        'metrics' => ['conversions' => '3.25', 'conversionsValue' => '75.5'],
                    ]],
                ]]),
        ]);

        $insights = (new GoogleAdsDataApi)->campaignInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2022-01-01',
            '2023-11-17',
            'USD',
        );

        $this->assertSame('partial', $insights['daily_metrics_status']);
        $this->assertSame('2023-10-22', $insights['daily_metrics_coverage_start_on']);
        $this->assertSame('2023-11-17', $insights['daily_metrics_coverage_end_on']);
        $this->assertSame('300.0000', $insights['spend_amount']);
        $this->assertSame(3000, $insights['impressions']);
        $this->assertSame(120, $insights['clicks']);
        $this->assertSame('5.7500', $insights['conversions']);
        $this->assertSame('125.5000', $insights['conversions_value']);
        $this->assertSame([
            [
                'conversion_action_id' => '1001',
                'conversion_action_name' => 'Purchase',
                'conversions' => '5.7500',
                'conversion_value' => '125.5000',
            ],
        ], $insights['conversion_actions']);

        $queries = Http::recorded()->map(fn (array $record): string => $record[0]->data()['query'])->all();
        $this->assertSame([
            "SELECT campaign.id, campaign.name, customer.id, customer.currency_code, segments.month, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE campaign.id = 987654321 AND segments.month BETWEEN '2022-01-01' AND '2023-10-01' ORDER BY segments.month ASC",
            "SELECT campaign.id, campaign.name, customer.id, customer.currency_code, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE campaign.id = 987654321 AND segments.date BETWEEN '2023-11-01' AND '2023-11-17' ORDER BY segments.date ASC",
            "SELECT customer.id, customer.currency_code, campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE campaign.id = 987654321 AND segments.date BETWEEN '2023-10-22' AND '2023-11-17' ORDER BY segments.date ASC",
            "SELECT customer.id, campaign.id, segments.month, segments.conversion_action, segments.conversion_action_name, metrics.conversions, metrics.conversions_value FROM campaign WHERE campaign.id = 987654321 AND segments.month BETWEEN '2022-01-01' AND '2023-10-01'",
            "SELECT customer.id, campaign.id, segments.date, segments.conversion_action, segments.conversion_action_name, metrics.conversions, metrics.conversions_value FROM campaign WHERE campaign.id = 987654321 AND segments.date BETWEEN '2023-11-01' AND '2023-11-17'",
        ], $queries);
    }

    public function test_google_ads_rejects_partial_historical_months_before_querying(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();

        foreach ([['2019-01-15', '2019-02-28'], ['2019-01-01', '2019-02-15']] as [$startsOn, $endsOn]) {
            try {
                (new GoogleAdsDataApi)->campaignInsights(
                    'access-token',
                    '1234567890',
                    '987654321',
                    $startsOn,
                    $endsOn,
                    'USD',
                );
                $this->fail('A partial historical month cannot be represented by monthly metrics.');
            } catch (GoogleAdsApiException $exception) {
                $this->assertSame('Google Ads 較舊區間只能精確讀取完整月份，請將舊資料的開始日設為月初，結束日設為月底。', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_google_ads_rejects_a_partial_cutoff_month_end_before_querying(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();

        try {
            (new GoogleAdsDataApi)->campaignInsights(
                'access-token',
                '1234567890',
                '987654321',
                '2022-01-01',
                '2023-10-22',
                'USD',
            );
            $this->fail('A partial historical cutoff month cannot be represented by monthly metrics.');
        } catch (GoogleAdsApiException $exception) {
            $this->assertSame('Google Ads 較舊區間只能精確讀取完整月份，請將舊資料的開始日設為月初，結束日設為月底。', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_google_ads_sync_keeps_the_last_successful_report_when_historical_dates_are_not_month_aligned(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget([
            'starts_on' => '2019-01-15',
            'ends_on' => '2019-02-28',
        ]);
        $connection = $this->createConnection($user);
        $report = $this->createReport($connection, $target, [
            'campaign_name' => 'Last successful historical read',
            'spend_amount' => '456.7800',
            'impressions' => 1234,
            'clicks' => 56,
            'conversions' => '7.5000',
            'conversions_value' => '89.2500',
        ]);
        $paidAdSnapshot = SocialEngagementTargetPaidAdSnapshot::query()->create([
            'social_engagement_target_id' => $target->id,
            'google_ads_connection_id' => $connection->id,
            'source' => SocialEngagementTargetPaidAdSnapshot::SOURCE_GOOGLE_ADS,
            'account_id' => $connection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Last successful historical read',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '456.7800',
            'impressions' => 1234,
            'clicks' => 56,
            'conversions' => '7.5000',
            'conversions_value' => '89.2500',
            'currency_code' => 'TWD',
            'synced_at' => now()->subDay(),
        ]);

        $this->travelTo('2026-11-22 12:00:00');
        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-ads.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors([
                'google_ads' => 'Google Ads 較舊區間只能精確讀取完整月份，請將舊資料的開始日設為月初，結束日設為月底。',
            ]);

        $this->assertSame('456.7800', $report->fresh()->spend_amount);
        $this->assertSame(1234, $report->fresh()->impressions);
        $this->assertSame('Last successful historical read', $report->fresh()->campaign_name);
        $this->assertSame('456.7800', $paidAdSnapshot->fresh()->spend_amount);
        $this->assertSame(1, SocialEngagementTargetGoogleAdsReport::query()->count());
        $this->assertSame(1, SocialEngagementTargetPaidAdSnapshot::query()->count());
        Http::assertSentCount(1);
    }

    public function test_google_ads_daily_metrics_reject_rows_for_a_different_campaign(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890', 'currencyCode' => 'USD'],
                        'campaign' => ['id' => '987654321', 'name' => 'Current campaign'],
                        'metrics' => [
                            'costMicros' => '1000000',
                            'impressions' => '100',
                            'clicks' => '4',
                            'conversions' => '1',
                            'conversionsValue' => '10',
                        ],
                    ]],
                ]])
                ->push($this->dailyMetricsPayload([
                    ['date' => '2026-11-02', 'cost_micros' => '1000000', 'impressions' => '10', 'clicks' => '1', 'conversions' => '1', 'conversions_value' => '10'],
                ], '1234567890', '111111111', 'USD')),
        ]);

        $this->expectException(GoogleAdsApiException::class);
        (new GoogleAdsDataApi)->campaignInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'USD',
        );
    }

    public function test_google_ads_daily_metrics_reject_a_different_currency(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignMetricsPayload())
                ->push($this->dailyMetricsPayload([
                    ['date' => '2026-11-02', 'cost_micros' => '1000000', 'impressions' => '10', 'clicks' => '1', 'conversions' => '1', 'conversions_value' => '10'],
                ], '1234567890', '987654321', 'USD')),
        ]);

        $this->expectException(GoogleAdsApiException::class);
        (new GoogleAdsDataApi)->campaignInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );
    }

    public function test_google_ads_daily_metrics_reject_dates_outside_the_campaign_period(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignMetricsPayload())
                ->push($this->dailyMetricsPayload([
                    ['date' => '2026-10-31', 'cost_micros' => '1000000', 'impressions' => '10', 'clicks' => '1', 'conversions' => '1', 'conversions_value' => '10'],
                ])),
        ]);

        $this->expectException(GoogleAdsApiException::class);
        (new GoogleAdsDataApi)->campaignInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );
    }

    public function test_google_ads_daily_metrics_reject_invalid_calendar_dates(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignMetricsPayload())
                ->push($this->dailyMetricsPayload([
                    ['date' => '2026-02-30', 'cost_micros' => '1000000', 'impressions' => '10', 'clicks' => '1', 'conversions' => '1', 'conversions_value' => '10'],
                ])),
        ]);

        $this->expectException(GoogleAdsApiException::class);
        (new GoogleAdsDataApi)->campaignInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );
    }

    public function test_google_ads_daily_metrics_reject_duplicate_dates(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignMetricsPayload())
                ->push($this->dailyMetricsPayload([
                    ['date' => '2026-11-02', 'cost_micros' => '1000000', 'impressions' => '10', 'clicks' => '1', 'conversions' => '1', 'conversions_value' => '10'],
                    ['date' => '2026-11-02', 'cost_micros' => '2000000', 'impressions' => '20', 'clicks' => '2', 'conversions' => '2', 'conversions_value' => '20'],
                ])),
        ]);

        $this->expectException(GoogleAdsApiException::class);
        (new GoogleAdsDataApi)->campaignInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );
    }

    public function test_google_ads_daily_metrics_reject_negative_integer_metrics(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push($this->campaignMetricsPayload())
                ->push($this->dailyMetricsPayload([
                    ['date' => '2026-11-02', 'cost_micros' => '1000000', 'impressions' => '-1', 'clicks' => '1', 'conversions' => '1', 'conversions_value' => '10'],
                ])),
        ]);

        $this->expectException(GoogleAdsApiException::class);
        (new GoogleAdsDataApi)->campaignInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );
    }

    public function test_google_ads_sync_saves_zero_metrics_when_no_campaign_rows_are_returned(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push([])
                ->push([])
                ->push([]),
        ]);
        $user = User::factory()->create();
        $this->createTarget();
        $this->createConnection($user);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-ads.sync', SocialEngagementTarget::query()->sole()))
            ->assertRedirect(route('social-engagement-targets.report', SocialEngagementTarget::query()->sole()))
            ->assertSessionHas('status');

        $snapshot = SocialEngagementTargetGoogleAdsReport::query()->sole();
        $this->assertSame('0.0000', $snapshot->spend_amount);
        $this->assertSame(0, $snapshot->impressions);
        $this->assertSame(0, $snapshot->clicks);
        $this->assertSame('0.0000', $snapshot->conversions);
        $this->assertSame('0.0000', $snapshot->conversions_value);
        $this->assertSame([], $snapshot->conversion_actions);
        $this->assertSame('TWD', $snapshot->currency_code);
        $this->assertSame('complete', $snapshot->daily_metrics['status']);
        $this->assertSame([], $snapshot->daily_metrics['rows']);
    }

    public function test_google_ads_sync_reports_missing_connection_and_customer_campaign_or_dates(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(['google_ads_campaign_id' => null]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-ads.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_ads']);

        $connection = $this->createConnection($user, ['customer_id' => null, 'currency_code' => null]);
        $this->post(route('social-engagement-targets.google-ads.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_ads']);

        $connection->update(['customer_id' => '1234567890', 'currency_code' => 'TWD']);
        $this->post(route('social-engagement-targets.google-ads.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_ads']);

        $target->update(['google_ads_campaign_id' => '987654321', 'starts_on' => null, 'ends_on' => null]);
        $this->post(route('social-engagement-targets.google-ads.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_ads']);

        $this->assertDatabaseCount('social_engagement_target_google_ads_reports', 0);
        Http::assertNothingSent();
    }

    public function test_google_ads_api_errors_preserve_the_last_successful_snapshot(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([
                'error' => ['message' => 'Quota exhausted'],
            ], 429),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $connection = $this->createConnection($user);
        $snapshot = $this->createReport($connection, $target, [
            'campaign_name' => 'Original campaign',
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'synced_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-ads.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_ads']);

        $this->assertSame('Original campaign', $snapshot->fresh()->campaign_name);
        $this->assertSame('100.0000', $snapshot->fresh()->spend_amount);
        $this->assertSame(1000, $snapshot->fresh()->impressions);
        $this->assertDatabaseCount('social_engagement_target_google_ads_reports', 1);
    }

    public function test_google_ads_rows_for_a_different_campaign_are_rejected(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::response([[
                'results' => [[
                    'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
                    'campaign' => ['id' => '111111111', 'name' => 'Unexpected campaign'],
                    'metrics' => [
                        'costMicros' => '1000000',
                        'impressions' => '100',
                        'clicks' => '4',
                        'conversions' => '1',
                        'conversionsValue' => '10',
                    ],
                ]],
            ]]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createConnection($user);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-ads.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_ads']);

        $this->assertDatabaseCount('social_engagement_target_google_ads_reports', 0);
    }

    public function test_google_ads_conversion_action_rows_for_a_different_customer_preserve_the_last_successful_report(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access-token']),
            'https://googleads.googleapis.com/v26/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push([[
                    'results' => [[
                        'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
                        'campaign' => ['id' => '987654321', 'name' => 'Winter product launch'],
                        'metrics' => [
                            'costMicros' => '1000000',
                            'impressions' => '100',
                            'clicks' => '4',
                            'conversions' => '1',
                            'conversionsValue' => '10',
                        ],
                    ]],
                ]])
                ->push($this->dailyMetricsPayload([
                    ['date' => '2026-11-02', 'cost_micros' => '1000000', 'impressions' => '100', 'clicks' => '4', 'conversions' => '1', 'conversions_value' => '10'],
                ], '1234567890', '987654321', 'TWD'))
                ->push($this->conversionActionsPayload([
                    ['id' => '1001', 'name' => 'Private conversion', 'conversions' => '1', 'value' => '10'],
                ], '9999999999')),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $connection = $this->createConnection($user);
        $report = $this->createReport($connection, $target, [
            'campaign_name' => 'Original campaign',
            'spend_amount' => '100.0000',
            'conversion_actions' => [[
                'conversion_action_id' => '7',
                'conversion_action_name' => 'Original action',
                'conversions' => '2.0000',
                'conversion_value' => '20.0000',
            ]],
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.google-ads.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['google_ads']);

        $this->assertSame('Original campaign', $report->fresh()->campaign_name);
        $this->assertSame('100.0000', $report->fresh()->spend_amount);
        $this->assertSame('Original action', $report->fresh()->conversion_actions[0]['conversion_action_name']);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 0);
    }

    public function test_google_ads_and_meta_spend_are_combined_when_currencies_match(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(['meta_campaign_id' => '777777777']);
        $googleConnection = $this->createConnection($user, ['currency_code' => 'USD']);
        $this->createReport($googleConnection, $target, [
            'spend_amount' => '250.7500',
            'currency_code' => 'USD',
        ]);
        $metaConnection = MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'meta-token',
            'ad_account_id' => '123456789',
            'currency_code' => 'USD',
        ]);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $metaConnection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => '123456789',
            'campaign_id' => '777777777',
            'campaign_name' => 'Meta campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 40,
            'currency_code' => 'USD',
            'synced_at' => now(),
        ]);
        $this->createGoogleAnalyticsReport($user, $target);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('350.7500 USD')
            ->assertSee('Meta Ads + Google Ads');

        $analysis = $response->viewData('adSpendAnalysis');
        $this->assertSame('combined_paid_ads', $analysis['ad_spend_source']);
        $this->assertSame('350.7500', $analysis['ad_spend_amount']);
        $this->assertSame(2.86, $analysis['roas']);
    }

    public function test_google_ads_and_meta_spend_are_not_combined_when_currencies_differ(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(['meta_campaign_id' => '777777777']);
        $googleConnection = $this->createConnection($user, ['currency_code' => 'USD']);
        $this->createReport($googleConnection, $target, [
            'spend_amount' => '250.7500',
            'currency_code' => 'USD',
        ]);
        $metaConnection = MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'meta-token',
            'ad_account_id' => '123456789',
            'currency_code' => 'TWD',
        ]);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $metaConnection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => '123456789',
            'campaign_id' => '777777777',
            'campaign_name' => 'Meta campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 40,
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
        $this->createGoogleAnalyticsReport($user, $target);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Meta Ads 與 Google Ads 的花費幣別不同')
            ->assertSee('多平台幣別不同');

        $analysis = $response->viewData('adSpendAnalysis');
        $this->assertTrue($analysis['platform_currency_mismatch']);
        $this->assertNull($analysis['ad_spend_amount']);
        $this->assertNull($analysis['roas']);
    }

    public function test_google_ads_campaign_id_is_saved_and_validated_by_the_target_form(): void
    {
        $this->createSocialMonitor('facebook', 'GOOGLE-ADS-POST', 'Google campaign');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Google Ads 活動 ID');

        $this->post(route('social-engagement-targets.store'), [
            'tag' => 'Google campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 500,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'google_ads_campaign_id' => '987654321',
        ])->assertRedirect(route('dashboard', [
            'engagement_platform' => 'facebook',
            'engagement_tag' => 'tag:Google campaign',
        ]));

        $this->assertSame('987654321', SocialEngagementTarget::query()->sole()->google_ads_campaign_id);

        $this->post(route('social-engagement-targets.store'), [
            'tag' => 'Google campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 500,
            'google_ads_campaign_id' => 'campaign-123',
        ])->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', ['google_ads_campaign_id']);

        $this->assertDatabaseCount('social_engagement_targets', 1);
    }

    public function test_campaign_insights_reject_unsafe_or_reversed_dates_before_sending_a_request(): void
    {
        $dataApi = new GoogleAdsDataApi;

        try {
            $dataApi->campaignInsights(
                'access-token',
                '1234567890',
                '987654321',
                "2026-11-01' OR TRUE",
                '2026-11-30',
                'TWD',
            );
            $this->fail('An invalid date should be rejected before reaching Google Ads.');
        } catch (GoogleAdsApiException $exception) {
            $this->assertSame('Google Ads 活動日期區間無效，請重新設定開始日與結束日。', $exception->getMessage());
        }

        $this->expectException(GoogleAdsApiException::class);
        $dataApi->campaignInsights(
            'access-token',
            '1234567890',
            '987654321',
            '2026-11-30',
            '2026-11-01',
            'TWD',
        );
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

    private function createConnection(User $user, array $attributes = []): GoogleAdsConnection
    {
        return GoogleAdsConnection::query()->create(array_merge([
            'user_id' => $user->id,
            'refresh_token' => 'authorized-refresh-token',
            'customer_id' => '1234567890',
            'customer_name' => 'Example Ads',
            'currency_code' => 'TWD',
        ], $attributes));
    }

    private function createReport(
        GoogleAdsConnection $connection,
        SocialEngagementTarget $target,
        array $attributes = [],
    ): SocialEngagementTargetGoogleAdsReport {
        return SocialEngagementTargetGoogleAdsReport::query()->create(array_merge([
            'google_ads_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => $connection->customer_id ?? '1234567890',
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Current campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'clicks' => 40,
            'conversions' => '2.0000',
            'conversions_value' => '200.0000',
            'currency_code' => $connection->currency_code ?? 'TWD',
            'synced_at' => now(),
        ], $attributes));
    }

    /** @param list<array{id: string, name: string, conversions: string, value: string}> $actions
     * @return list<array{results: list<array<string, mixed>>}>
     */
    private function conversionActionsPayload(array $actions, string $customerId = '1234567890'): array
    {
        return [[
            'results' => array_map(static fn (array $action): array => [
                'customer' => ['id' => $customerId],
                'campaign' => ['id' => '987654321'],
                'segments' => [
                    'conversionAction' => 'customers/'.$customerId.'/conversionActions/'.$action['id'],
                    'conversionActionName' => $action['name'],
                ],
                'metrics' => [
                    'conversions' => $action['conversions'],
                    'conversionsValue' => $action['value'],
                ],
            ], $actions),
        ]];
    }

    /** @return list<array{results: list<array<string, mixed>>}> */
    private function campaignMetricsPayload(): array
    {
        return [[
            'results' => [[
                'customer' => ['id' => '1234567890', 'currencyCode' => 'TWD'],
                'campaign' => ['id' => '987654321', 'name' => 'Current campaign'],
                'metrics' => [
                    'costMicros' => '1000000',
                    'impressions' => '100',
                    'clicks' => '4',
                    'conversions' => '1',
                    'conversionsValue' => '10',
                ],
            ]],
        ]];
    }

    /** @param list<array{date: string, cost_micros: string, impressions: string, clicks: string, conversions: string, conversions_value: string}> $days
     * @return list<array{results: list<array<string, mixed>>}>
     */
    private function dailyMetricsPayload(
        array $days,
        string $customerId = '1234567890',
        string $campaignId = '987654321',
        string $currencyCode = 'TWD',
    ): array {
        return [[
            'results' => array_map(static fn (array $day): array => [
                'customer' => ['id' => $customerId, 'currencyCode' => $currencyCode],
                'campaign' => ['id' => $campaignId],
                'segments' => ['date' => $day['date']],
                'metrics' => [
                    'costMicros' => $day['cost_micros'],
                    'impressions' => $day['impressions'],
                    'clicks' => $day['clicks'],
                    'conversions' => $day['conversions'],
                    'conversionsValue' => $day['conversions_value'],
                ],
            ], $days),
        ]];
    }

    private function createGoogleAnalyticsReport(User $user, SocialEngagementTarget $target): void
    {
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'ga-refresh-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->tag,
            'attribution_mode' => 'google_ads_auto_tagging',
            'campaign_id' => $target->google_ads_campaign_id,
            'source_name' => null,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 50,
            'engaged_sessions' => 40,
            'key_events' => '10.0000',
            'total_revenue' => '1003.0000',
            'currency_code' => 'USD',
            'synced_at' => now(),
        ]);
    }

    private function createSocialMonitor(string $platform, string $postId, string $tag): Monitor
    {
        $url = match ($platform) {
            'facebook' => 'https://www.facebook.com/share/p/'.$postId.'/',
            'instagram' => 'https://www.instagram.com/reel/'.$postId.'/',
            'threads' => 'https://www.threads.com/share/'.$postId,
            default => throw new \InvalidArgumentException('Unsupported social platform.'),
        };

        return Monitor::factory()->create([
            'name' => ucfirst($platform).' '.$postId,
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $tag,
        ]);
    }
}
