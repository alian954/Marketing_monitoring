<?php

namespace Tests\Feature;

use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\SocialEngagementTargetPaidAdSnapshot;
use App\Models\User;
use App\Services\MetaMarketingApiException;
use App\Services\MetaMarketingDataApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SocialEngagementTargetMetaMarketingTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_sync_requires_authentication(): void
    {
        $target = $this->createTarget();

        $this->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('login'));
    }

    public function test_meta_snapshot_is_scoped_to_the_current_users_connection(): void
    {
        $viewer = User::factory()->create();
        $otherUser = User::factory()->create();
        $target = $this->createTarget();
        $this->createConnection($viewer);
        $otherConnection = $this->createConnection($otherUser);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $otherConnection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => '123456789',
            'campaign_id' => '987654321',
            'campaign_name' => 'Private other-user campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '98765.4321',
            'impressions' => 1000000,
            'reach' => 800000,
            'clicks' => 40000,
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($viewer)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('尚無 Meta Ads 讀值')
            ->assertDontSee('Private other-user campaign')
            ->assertDontSee('98,765.4321');

        $this->assertNull($response->viewData('metaMarketingReport'));
    }

    public function test_meta_sync_saves_campaign_insights_and_uses_meta_spend_for_efficiency(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push([
                    'data' => [[
                        'account_id' => '123456789',
                        'campaign_id' => '987654321',
                        'campaign_name' => 'Winter product launch',
                        'spend' => '250.75',
                        'impressions' => '12000',
                        'reach' => '9000',
                        'clicks' => '480',
                        'actions' => [
                            ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '2'],
                            ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '0.5'],
                            ['action_type' => '<script>alert(1)</script>', 'value' => '1'],
                        ],
                        'action_values' => [
                            ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '125.50'],
                        ],
                        'account_currency' => 'USD',
                    ]],
                ])
                ->push([
                    'data' => [$this->dailyInsightRow([
                        'campaign_name' => 'Winter product launch',
                        'spend' => '250.75',
                        'impressions' => '12000',
                        'reach' => '9000',
                        'clicks' => '480',
                        'actions' => [
                            ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '2'],
                            ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '0.5'],
                            ['action_type' => '<script>alert(1)</script>', 'value' => '1'],
                        ],
                        'action_values' => [
                            ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '125.50'],
                        ],
                        'account_currency' => 'USD',
                    ])],
                ])
                ->push(['data' => []])
                ->push(['data' => []]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget([
            'ad_spend_amount' => '999.0000',
            'ad_spend_currency' => 'TWD',
        ]);
        $connection = $this->createConnection($user);
        $connection->update(['currency_code' => 'USD']);
        $gaConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'ga-refresh-token',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $gaConnection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->tag,
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

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', 'Meta Ads 活動成效已同步。');

        $snapshot = SocialEngagementTargetMetaMarketingReport::query()->sole();
        $this->assertSame($connection->id, $snapshot->meta_marketing_connection_id);
        $this->assertSame('987654321', $snapshot->campaign_id);
        $this->assertSame('Winter product launch', $snapshot->campaign_name);
        $this->assertSame('250.7500', $snapshot->spend_amount);
        $this->assertSame(12000, $snapshot->impressions);
        $this->assertSame(9000, $snapshot->reach);
        $this->assertSame(480, $snapshot->clicks);
        $this->assertSame([
            ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '2.0000'],
            ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '0.5000'],
            ['action_type' => '<script>alert(1)</script>', 'value' => '1.0000'],
        ], $snapshot->actions);
        $this->assertSame([
            ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '125.5000'],
        ], $snapshot->action_values);
        $this->assertSame('USD', $snapshot->currency_code);
        $this->assertSame([
            [
                'date' => '2026-11-01',
                'account_id' => '123456789',
                'campaign_id' => '987654321',
                'campaign_name' => 'Winter product launch',
                'spend_amount' => '250.7500',
                'impressions' => 12000,
                'reach' => 9000,
                'clicks' => 480,
                'actions' => [
                    ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '2.0000'],
                    ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '0.5000'],
                    ['action_type' => '<script>alert(1)</script>', 'value' => '1.0000'],
                ],
                'action_values' => [
                    ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '125.5000'],
                ],
                'currency_code' => 'USD',
            ],
        ], $snapshot->daily_metrics['rows']);
        $this->assertSame('complete', $snapshot->daily_metrics['status']);
        $this->assertSame('2026-11-01', $snapshot->daily_metrics['coverage_start_on']);
        $this->assertSame('2026-11-30', $snapshot->daily_metrics['coverage_end_on']);
        $this->assertSame('complete', $snapshot->ad_insights['status']);
        $this->assertSame([], $snapshot->ad_insights['rows']);
        $this->assertTrue($snapshot->ad_insights_expires_at->isFuture());
        $this->assertNotNull($snapshot->synced_at);
        $paidAdSnapshot = SocialEngagementTargetPaidAdSnapshot::query()->sole();
        $this->assertSame(SocialEngagementTargetPaidAdSnapshot::SOURCE_META_MARKETING, $paidAdSnapshot->source);
        $this->assertSame($connection->id, $paidAdSnapshot->meta_marketing_connection_id);
        $this->assertSame($connection->ad_account_id, $paidAdSnapshot->account_id);
        $this->assertSame('250.7500', $paidAdSnapshot->spend_amount);
        $this->assertSame(9000, $paidAdSnapshot->reach);
        $this->assertSame($snapshot->actions, $paidAdSnapshot->actions);
        $this->assertSame($snapshot->action_values, $paidAdSnapshot->action_values);
        $this->assertNull($paidAdSnapshot->conversions);
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://graph.facebook.com/v27.0/act_123456789/insights')
                && $request->hasHeader('Authorization', 'Bearer authorized-token')
                && $query['level'] === 'campaign'
                && $query['fields'] === 'account_id,campaign_id,campaign_name,spend,impressions,reach,clicks,actions,action_values,account_currency'
                && json_decode($query['filtering'], true)[0]['value'] === '987654321'
                && json_decode($query['time_range'], true) === ['since' => '2026-11-01', 'until' => '2026-11-30'];
        });
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://graph.facebook.com/v27.0/act_123456789/insights')
                && ($query['time_increment'] ?? null) === '1'
                && ($query['fields'] ?? null) === 'date_start,date_stop,account_id,campaign_id,campaign_name,spend,impressions,reach,clicks,actions,action_values,account_currency';
        });
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['level'] ?? null) === 'ad'
                && ($query['limit'] ?? null) === '100'
                && json_decode($query['filtering'], true)[0]['value'] === '987654321';
        });
        Http::assertSentCount(4);

        $reportResponse = $this->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Meta Ads 投放成效')
            ->assertSee('12,000')
            ->assertSee('9,000')
            ->assertSee('480')
            ->assertSee('250.7500 USD')
            ->assertSee('Meta Ads')
            ->assertSee('Meta Ads 歷史讀值')
            ->assertSee('Meta Ads 行動成效')
            ->assertSee('offsite_conversion.fb_pixel_purchase')
            ->assertSee('125.5000 USD')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('基準')
            ->assertSee('4.00x');

        $analysis = $reportResponse->viewData('adSpendAnalysis');
        $this->assertCount(3, $reportResponse->viewData('metaMarketingReport')->actions);
        $this->assertSame('meta_marketing', $analysis['ad_spend_source']);
        $this->assertSame(250.75, (float) $analysis['ad_spend_amount']);
        $this->assertSame(4.0, $analysis['roas']);
        $this->assertSame(5.015, $analysis['cost_per_session']);
        $this->assertSame(25.075, $analysis['cost_per_key_event']);
    }

    public function test_meta_insights_preserve_large_decimal_precision_for_spend_and_actions(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push([
                    'data' => [[
                        'account_id' => '123456789',
                        'campaign_id' => '987654321',
                        'campaign_name' => 'Precision campaign',
                        'spend' => '576460752303.42354',
                        'impressions' => '1000',
                        'reach' => '800',
                        'clicks' => '40',
                        'actions' => [['action_type' => 'purchase', 'value' => '576460752303.42354']],
                        'action_values' => [['action_type' => 'purchase', 'value' => '576460752303.42354']],
                        'account_currency' => 'TWD',
                    ]],
                ])
                ->push([
                    'data' => [$this->dailyInsightRow([
                        'campaign_name' => 'Precision campaign',
                        'spend' => '576460752303.42354',
                        'impressions' => '1000',
                        'reach' => '800',
                        'clicks' => '40',
                        'actions' => [['action_type' => 'purchase', 'value' => '576460752303.42354']],
                        'action_values' => [['action_type' => 'purchase', 'value' => '576460752303.42354']],
                    ])],
                ]),
        ]);

        $insights = (new MetaMarketingDataApi)->campaignInsights(
            'access-token',
            '123456789',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );

        $this->assertSame('576460752303.4235', $insights['spend_amount']);
        $this->assertSame('576460752303.4235', $insights['actions'][0]['value']);
        $this->assertSame('576460752303.4235', $insights['action_values'][0]['value']);
        $this->assertSame('576460752303.4235', $insights['daily_metrics'][0]['spend_amount']);
        $this->assertSame('576460752303.4235', $insights['daily_metrics'][0]['actions'][0]['value']);
        Http::assertSentCount(2);
    }

    public function test_meta_daily_insights_reads_all_pages_and_returns_normalized_rows_by_date(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push([
                    'data' => [$this->dailyInsightRow([
                        'date_start' => '2026-11-02',
                        'date_stop' => '2026-11-02',
                        'spend' => '90.12567',
                        'impressions' => '1200',
                        'reach' => '900',
                        'clicks' => '48',
                        'actions' => [['action_type' => 'purchase', 'value' => '2.5']],
                        'action_values' => [['action_type' => 'purchase', 'value' => '125.5']],
                    ])],
                    'paging' => [
                        'cursors' => ['after' => 'daily-after-page-one'],
                        'next' => 'https://graph.facebook.com/v27.0/act_123456789/insights?after=daily-after-page-one',
                    ],
                ])
                ->push([
                    'data' => [$this->dailyInsightRow([
                        'date_start' => '2026-11-01',
                        'date_stop' => '2026-11-01',
                        'spend' => '10.2',
                        'impressions' => '100',
                        'reach' => '80',
                        'clicks' => '4',
                    ])],
                ]),
        ]);

        $dailyInsights = (new MetaMarketingDataApi)->campaignDailyInsights(
            'access-token',
            '123456789',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );

        $this->assertSame([
            [
                'date' => '2026-11-01',
                'account_id' => '123456789',
                'campaign_id' => '987654321',
                'campaign_name' => 'Daily Meta campaign',
                'spend_amount' => '10.2000',
                'impressions' => 100,
                'reach' => 80,
                'clicks' => 4,
                'actions' => [],
                'action_values' => [],
                'currency_code' => 'TWD',
            ],
            [
                'date' => '2026-11-02',
                'account_id' => '123456789',
                'campaign_id' => '987654321',
                'campaign_name' => 'Daily Meta campaign',
                'spend_amount' => '90.1257',
                'impressions' => 1200,
                'reach' => 900,
                'clicks' => 48,
                'actions' => [['action_type' => 'purchase', 'value' => '2.5000']],
                'action_values' => [['action_type' => 'purchase', 'value' => '125.5000']],
                'currency_code' => 'TWD',
            ],
        ], $dailyInsights);

        Http::assertSentCount(2);
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://graph.facebook.com/v27.0/act_123456789/insights')
                && $request->hasHeader('Authorization', 'Bearer access-token')
                && $query['level'] === 'campaign'
                && $query['time_increment'] === '1'
                && $query['limit'] === '100'
                && $query['fields'] === 'date_start,date_stop,account_id,campaign_id,campaign_name,spend,impressions,reach,clicks,actions,action_values,account_currency'
                && json_decode($query['filtering'], true)[0]['value'] === '987654321'
                && json_decode($query['time_range'], true) === ['since' => '2026-11-01', 'until' => '2026-11-30'];
        });
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['after'] ?? null) === 'daily-after-page-one'
                && $query['time_increment'] === '1';
        });
    }

    public function test_meta_daily_insights_returns_an_empty_list_when_the_campaign_has_no_daily_rows(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response(['data' => []]),
        ]);

        $dailyInsights = (new MetaMarketingDataApi)->campaignDailyInsights(
            'access-token',
            '123456789',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );

        $this->assertSame([], $dailyInsights);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidMetaDailyInsightRows')]
    public function test_meta_daily_insights_rejects_rows_with_invalid_dates_or_identity(array $row): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response(['data' => [$row]]),
        ]);
        $this->expectException(MetaMarketingApiException::class);

        (new MetaMarketingDataApi)->campaignDailyInsights(
            'access-token',
            '123456789',
            '987654321',
            '2026-11-01',
            '2026-11-30',
            'TWD',
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidMetaDailyInsightRows(): iterable
    {
        $validRow = [
            'date_start' => '2026-11-01',
            'date_stop' => '2026-11-01',
            'account_id' => '123456789',
            'campaign_id' => '987654321',
            'campaign_name' => 'Daily Meta campaign',
            'spend' => '10',
            'impressions' => '100',
            'reach' => '80',
            'clicks' => '4',
            'actions' => [],
            'action_values' => [],
            'account_currency' => 'TWD',
        ];

        yield 'date is not ISO format' => [array_replace($validRow, ['date_start' => '2026-11-1', 'date_stop' => '2026-11-1'])];
        yield 'date is outside the requested range' => [array_replace($validRow, ['date_start' => '2026-12-01', 'date_stop' => '2026-12-01'])];
        yield 'date start and stop differ' => [array_replace($validRow, ['date_stop' => '2026-11-02'])];
        yield 'account does not match request' => [array_replace($validRow, ['account_id' => '111111111'])];
        yield 'campaign does not match request' => [array_replace($validRow, ['campaign_id' => '111111111'])];
        yield 'currency does not match account' => [array_replace($validRow, ['account_currency' => 'USD'])];
    }

    public function test_meta_daily_insights_rejects_duplicate_dates_across_pages(): void
    {
        Http::preventStrayRequests();
        $row = $this->dailyInsightRow([
            'date_start' => '2026-11-01',
            'date_stop' => '2026-11-01',
        ]);
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::sequence()
                ->push([
                    'data' => [$row],
                    'paging' => [
                        'cursors' => ['after' => 'duplicate-date-cursor'],
                        'next' => 'https://graph.facebook.com/v27.0/act_123456789/insights?after=duplicate-date-cursor',
                    ],
                ])
                ->push(['data' => [$row]]),
        ]);

        try {
            (new MetaMarketingDataApi)->campaignDailyInsights(
                'access-token',
                '123456789',
                '987654321',
                '2026-11-01',
                '2026-11-30',
                'TWD',
            );
            $this->fail('Duplicate daily rows should be rejected.');
        } catch (MetaMarketingApiException $exception) {
            $this->assertStringContainsString('重複', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_meta_daily_insights_fails_instead_of_returning_a_partial_result_after_the_page_limit(): void
    {
        Http::preventStrayRequests();
        $sequence = Http::sequence();
        $startsOn = '2026-01-01';

        for ($pageNumber = 0; $pageNumber < 100; $pageNumber++) {
            $date = (new \DateTimeImmutable($startsOn))->modify('+'.$pageNumber.' days')->format('Y-m-d');
            $sequence->push([
                'data' => [$this->dailyInsightRow([
                    'date_start' => $date,
                    'date_stop' => $date,
                ])],
                'paging' => [
                    'cursors' => ['after' => 'page-cursor-'.$pageNumber],
                    'next' => 'https://graph.facebook.com/v27.0/act_123456789/insights?after=page-cursor-'.$pageNumber,
                ],
            ]);
        }
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => $sequence,
        ]);

        try {
            (new MetaMarketingDataApi)->campaignDailyInsights(
                'access-token',
                '123456789',
                '987654321',
                $startsOn,
                '2026-04-10',
                'TWD',
            );
            $this->fail('The page cap should reject an incomplete result.');
        } catch (MetaMarketingApiException $exception) {
            $this->assertStringContainsString('分頁上限', $exception->getMessage());
        }

        Http::assertSentCount(100);
    }

    public function test_meta_sync_saves_zero_metrics_when_the_campaign_has_no_insights(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response(['data' => []]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createConnection($user);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status');

        $snapshot = SocialEngagementTargetMetaMarketingReport::query()->sole();
        $this->assertSame('0.0000', $snapshot->spend_amount);
        $this->assertSame(0, $snapshot->impressions);
        $this->assertSame(0, $snapshot->reach);
        $this->assertSame(0, $snapshot->clicks);
        $this->assertSame([], $snapshot->actions);
        $this->assertSame([], $snapshot->action_values);
        $this->assertSame('TWD', $snapshot->currency_code);
    }

    public function test_meta_sync_rejects_malformed_action_metrics_without_saving_a_report(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'data' => [[
                    'account_id' => '123456789',
                    'campaign_id' => '987654321',
                    'campaign_name' => 'Malformed actions campaign',
                    'spend' => '10',
                    'impressions' => '100',
                    'reach' => '80',
                    'clicks' => '4',
                    'actions' => [
                        ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => 'unknown'],
                    ],
                    'account_currency' => 'TWD',
                ]],
            ]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $connection = $this->createConnection($user);
        $previousReport = SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => $connection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Last successful read',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '75.0000',
            'impressions' => 750,
            'reach' => 600,
            'clicks' => 30,
            'currency_code' => 'TWD',
            'synced_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['meta_marketing']);

        $this->assertSame('Last successful read', $previousReport->fresh()->campaign_name);
        $this->assertSame('75.0000', $previousReport->fresh()->spend_amount);
        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 1);
        $this->assertDatabaseCount('social_engagement_target_paid_ad_snapshots', 0);
    }

    public function test_meta_sync_reports_missing_connection_campaign_and_date_settings(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(['meta_campaign_id' => null]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['meta_marketing']);

        $this->createConnection($user);

        $this->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['meta_marketing']);
        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 0);
    }

    public function test_meta_sync_requires_a_current_access_token(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $connection = $this->createConnection($user);
        $connection->token_expires_at = now()->subMinute();
        $connection->save();

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['meta_marketing']);

        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 0);
        Http::assertNothingSent();
    }

    public function test_meta_api_errors_preserve_the_last_successful_snapshot(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'error' => ['code' => 613, 'message' => 'Call limit reached'],
            ], 429),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $connection = $this->createConnection($user);
        $snapshot = SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => '123456789',
            'campaign_id' => '987654321',
            'campaign_name' => 'Original campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 40,
            'currency_code' => 'TWD',
            'synced_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['meta_marketing']);

        $this->assertSame(1000, $snapshot->fresh()->impressions);
        $this->assertSame('100.0000', $snapshot->fresh()->spend_amount);
        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 1);
    }

    public function test_meta_insights_for_another_campaign_are_rejected_without_saving(): void
    {
        Http::fake([
            'https://graph.facebook.com/v27.0/act_123456789/insights*' => Http::response([
                'data' => [[
                    'account_id' => '123456789',
                    'campaign_id' => '111111111',
                    'campaign_name' => 'Unexpected campaign',
                    'spend' => '100',
                    'impressions' => '1000',
                    'reach' => '800',
                    'clicks' => '40',
                    'account_currency' => 'TWD',
                ]],
            ]),
        ]);
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createConnection($user);

        $this->actingAs($user)
            ->post(route('social-engagement-targets.meta-marketing.sync', $target))
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHasErrors(['meta_marketing']);

        $this->assertDatabaseCount('social_engagement_target_meta_marketing_reports', 0);
    }

    public function test_target_form_saves_a_numeric_meta_campaign_id(): void
    {
        $this->createSocialMonitor('facebook', 'META-POST-1', 'Meta campaign');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Meta Ads 活動 ID');

        $this->post(route('social-engagement-targets.store'), [
            'tag' => 'Meta campaign',
            'social_platform' => 'facebook',
            'target_interactions' => 500,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
        ])->assertRedirect(route('dashboard', [
            'engagement_platform' => 'facebook',
            'engagement_tag' => 'tag:Meta campaign',
        ]));

        $this->assertSame('987654321', SocialEngagementTarget::query()->sole()->meta_campaign_id);
    }

    public function test_target_form_rejects_a_non_numeric_meta_campaign_id(): void
    {
        $this->createSocialMonitor('facebook', 'META-POST-2', 'Meta campaign');

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('social-engagement-targets.store'), [
                'tag' => 'Meta campaign',
                'social_platform' => 'facebook',
                'target_interactions' => 500,
                'meta_campaign_id' => 'campaign-123',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('socialEngagementTarget', ['meta_campaign_id']);

        $this->assertDatabaseCount('social_engagement_targets', 0);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function dailyInsightRow(array $attributes = []): array
    {
        return array_replace([
            'date_start' => '2026-11-01',
            'date_stop' => '2026-11-01',
            'account_id' => '123456789',
            'campaign_id' => '987654321',
            'campaign_name' => 'Daily Meta campaign',
            'spend' => '10',
            'impressions' => '100',
            'reach' => '80',
            'clicks' => '4',
            'actions' => [],
            'action_values' => [],
            'account_currency' => 'TWD',
        ], $attributes);
    }

    private function createTarget(array $attributes = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create(array_merge([
            'tag' => 'Winter campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '987654321',
        ], $attributes));
    }

    private function createConnection(User $user): MetaMarketingConnection
    {
        return MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'authorized-token',
            'ad_account_id' => '123456789',
            'ad_account_name' => 'Example Ads',
            'currency_code' => 'TWD',
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
