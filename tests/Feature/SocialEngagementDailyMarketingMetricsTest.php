<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsClientProperty;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingClientAccount;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementDailyMarketingMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_shows_provider_daily_rows_missing_metrics_explicit_zero_and_partial_coverage(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'ga_status' => 'complete',
            'ga_rows' => [[
                'date' => '2026-11-02',
                'sessions' => 0,
                'engaged_sessions' => null,
                'key_events' => '0.0000',
                'total_revenue' => null,
            ]],
            'meta_status' => 'partial',
            'meta_coverage_start_on' => '2026-11-04',
            'meta_coverage_end_on' => '2026-11-28',
            'meta_rows' => [[
                'date' => '2026-11-04',
                'spend_amount' => '0.0000',
                'impressions' => 0,
                'reach' => 0,
                'clicks' => 0,
                'actions' => [['action_type' => 'omni_purchase', 'value' => '0.0000']],
                'action_values' => [],
            ]],
            'ads_status' => 'unsupported',
            'ads_coverage_start_on' => null,
            'ads_coverage_end_on' => null,
            'ads_rows' => [],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('逐日行銷成效')
            ->assertSee('逐日顯示平台數據與衍生效率指標')
            ->assertSee('GA4 逐日成效')
            ->assertSee('Meta Ads 逐日成效')
            ->assertSee('Google Ads 逐日成效')
            ->assertSee('GA4 每日工作階段趨勢')
            ->assertSee('Meta Ads 每日花費趨勢')
            ->assertSee('未回傳的日期不補為零')
            ->assertSee('部分涵蓋')
            ->assertSee('不支援')
            ->assertSee('2026-11-04 至 2026-11-28')
            ->assertSee('API 無逐日列。')
            ->assertSee(route('social-engagement-targets.daily-marketing-performance', $target));

        $dailyReport = $response->viewData('dailyMarketingPerformance');

        $this->assertSame(0, $dailyReport['google_analytics']['rows'][0]['sessions']);
        $this->assertNull($dailyReport['google_analytics']['rows'][0]['engaged_sessions']);
        $this->assertSame('0.0000', $dailyReport['google_analytics']['rows'][0]['key_events']);
        $this->assertNull($dailyReport['google_analytics']['rows'][0]['total_revenue']);
        $this->assertSame(0, $dailyReport['meta_marketing']['rows'][0]['impressions']);
        $this->assertSame('0.0000', $dailyReport['meta_marketing']['rows'][0]['omni_purchase_count']);
        $this->assertNull($dailyReport['meta_marketing']['rows'][0]['frequency']);
        $this->assertNull($dailyReport['meta_marketing']['rows'][0]['ctr_percent']);
        $this->assertNull($dailyReport['meta_marketing']['rows'][0]['cost_per_click']);
        $this->assertNull($dailyReport['meta_marketing']['rows'][0]['cost_per_thousand_impressions']);
        $this->assertNull($dailyReport['meta_marketing']['rows'][0]['cost_per_action']);
        $this->assertSame([], $dailyReport['google_ads']['rows']);
        $this->assertTrue($dailyReport['google_ads']['snapshot_available']);
    }

    public function test_report_calculates_and_displays_daily_paid_media_efficiency_metrics(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'meta_rows' => [[
                'date' => '2026-11-05',
                'spend_amount' => '25.5000',
                'impressions' => 100,
                'reach' => 75,
                'clicks' => 5,
                'actions' => [['action_type' => 'omni_purchase', 'value' => '2.0000']],
                'action_values' => [['action_type' => 'omni_purchase', 'value' => '60.0000']],
            ]],
            'ads_rows' => [[
                'date' => '2026-11-06',
                'spend_amount' => '10.0000',
                'impressions' => 80,
                'clicks' => 4,
                'conversions' => '2.0000',
                'conversions_value' => '60.0000',
            ]],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('頻率')
            ->assertSee('點擊率')
            ->assertSee('每次點擊成本')
            ->assertSee('每千次曝光成本')
            ->assertSee('每次購買成本')
            ->assertSee('轉換率')
            ->assertSee('1.33 次／人')
            ->assertSee('5.00%')
            ->assertSee('5.1000 TWD')
            ->assertSee('255.0000 TWD')
            ->assertSee('12.7500 TWD')
            ->assertSee('2.5000 TWD')
            ->assertSee('125.0000 TWD')
            ->assertSee('50.00%');

        $dailyReport = $response->viewData('dailyMarketingPerformance');
        $metaRow = $dailyReport['meta_marketing']['rows'][0];
        $googleAdsRow = $dailyReport['google_ads']['rows'][0];

        $this->assertSame(1.3333, $metaRow['frequency']);
        $this->assertSame(5.0, $metaRow['ctr_percent']);
        $this->assertSame(5.1, $metaRow['cost_per_click']);
        $this->assertSame(255.0, $metaRow['cost_per_thousand_impressions']);
        $this->assertSame(12.75, $metaRow['cost_per_action']);
        $this->assertSame(5.0, $googleAdsRow['ctr_percent']);
        $this->assertSame(2.5, $googleAdsRow['cost_per_click']);
        $this->assertSame(125.0, $googleAdsRow['cost_per_thousand_impressions']);
        $this->assertSame(5.0, $googleAdsRow['cost_per_action']);
        $this->assertSame(50.0, $googleAdsRow['conversion_rate_percent']);
    }

    public function test_daily_paid_media_efficiency_metrics_are_null_when_denominators_are_missing_or_zero(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'meta_rows' => [[
                'date' => '2026-11-05',
                'spend_amount' => '10.0000',
                'impressions' => 0,
                'reach' => 0,
                'clicks' => null,
                'actions' => [],
                'action_values' => [],
            ]],
            'ads_rows' => [[
                'date' => '2026-11-06',
                'spend_amount' => '10.0000',
                'impressions' => 0,
                'clicks' => null,
                'conversions' => '0.0000',
                'conversions_value' => '0.0000',
            ]],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();
        $dailyReport = $response->viewData('dailyMarketingPerformance');
        $metaRow = $dailyReport['meta_marketing']['rows'][0];
        $googleAdsRow = $dailyReport['google_ads']['rows'][0];

        foreach (['frequency', 'ctr_percent', 'cost_per_click', 'cost_per_thousand_impressions', 'cost_per_action'] as $metric) {
            $this->assertNull($metaRow[$metric]);
        }

        foreach (['ctr_percent', 'cost_per_click', 'cost_per_thousand_impressions', 'cost_per_action', 'conversion_rate_percent'] as $metric) {
            $this->assertNull($googleAdsRow[$metric]);
        }
    }

    public function test_daily_csv_download_is_authenticated_long_format_provider_specific_and_formula_safe(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'ga_status' => '=2+2',
            'ga_rows' => [[
                'date' => '2026-11-02',
                'sessions' => 0,
                'engaged_sessions' => 0,
                'key_events' => '0.0000',
                'total_revenue' => '0.0000',
            ]],
            'meta_rows' => [[
                'date' => '2026-11-05',
                'spend_amount' => '25.5000',
                'impressions' => 100,
                'reach' => 75,
                'clicks' => 5,
                'actions' => [['action_type' => 'omni_purchase', 'value' => '2.0000']],
                'action_values' => [['action_type' => 'omni_purchase', 'value' => '60.0000']],
            ]],
            'ads_rows' => [[
                'date' => '2026-11-06',
                'spend_amount' => '10.0000',
                'impressions' => 80,
                'clicks' => 4,
                'conversions' => '2.0000',
                'conversions_value' => '60.0000',
                'search_impression_share' => '0.0999',
                'search_budget_lost_impression_share' => '0.9001',
                'search_rank_lost_impression_share' => '0.2345',
            ]],
            'ads_channel_type' => 'SEARCH',
        ]);

        $this->get(route('social-engagement-targets.daily-marketing-performance', $target))
            ->assertRedirect(route('login'));

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.daily-marketing-performance', $target))
            ->assertDownload('campaign-daily-marketing-performance-'.$target->id.'.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csvRows = $this->csvRows($response->streamedContent());

        $this->assertCount(7, $csvRows);
        $this->assertSame([
            'source',
            'date',
            'status',
            'requested_start_on',
            'requested_end_on',
            'coverage_start_on',
            'coverage_end_on',
            'currency_code',
            'synced_at_asia_taipei',
            'sessions',
            'engaged_sessions',
            'key_events',
            'total_revenue',
            'spend_amount',
            'impressions',
            'reach',
            'clicks',
            'omni_purchase_count',
            'omni_purchase_value',
            'purchase_roas',
            'conversions',
            'conversions_value',
            'conversion_value_roas',
            'search_impression_share',
            'search_budget_lost_impression_share',
            'search_rank_lost_impression_share',
            'frequency',
            'ctr_percent',
            'cost_per_click',
            'cost_per_thousand_impressions',
            'cost_per_action',
            'conversion_rate_percent',
            'meta_spend_amount',
            'meta_currency_code',
            'google_ads_spend_amount',
            'google_ads_currency_code',
            'combined_spend_amount',
            'combined_spend_currency',
            'ga4_revenue',
            'ga4_currency_code',
            'daily_roas',
            'roas_status',
            'roas_spend_amount',
            'roas_spend_currency',
            'roas_spend_source',
        ], $csvRows[0]);

        $fields = array_combine($csvRows[0], $csvRows[1]);
        $this->assertSame('GA4', $fields['source']);
        $this->assertSame('2026-11-02', $fields['date']);
        $this->assertSame("'=2+2", $fields['status']);
        $this->assertSame('0', $fields['sessions']);
        $this->assertSame('TWD', $fields['currency_code']);
        $this->assertSame('', $fields['spend_amount']);

        $metaFields = array_combine($csvRows[0], $csvRows[2]);
        $this->assertSame('Meta Ads', $metaFields['source']);
        $this->assertSame('2026-11-05', $metaFields['date']);
        $this->assertSame('25.5000', $metaFields['spend_amount']);
        $this->assertSame('1.3333', $metaFields['frequency']);
        $this->assertSame('5', $metaFields['ctr_percent']);
        $this->assertSame('5.1', $metaFields['cost_per_click']);
        $this->assertSame('255', $metaFields['cost_per_thousand_impressions']);
        $this->assertSame('12.75', $metaFields['cost_per_action']);
        $this->assertSame('', $metaFields['conversion_rate_percent']);
        $this->assertSame('', $metaFields['sessions']);

        $adsFields = array_combine($csvRows[0], $csvRows[3]);
        $this->assertSame('Google Ads', $adsFields['source']);
        $this->assertSame('2026-11-06', $adsFields['date']);
        $this->assertSame('2.0000', $adsFields['conversions']);
        $this->assertSame('0.0999', $adsFields['search_impression_share']);
        $this->assertSame('0.9001', $adsFields['search_budget_lost_impression_share']);
        $this->assertSame('0.2345', $adsFields['search_rank_lost_impression_share']);
        $this->assertSame('', $adsFields['frequency']);
        $this->assertSame('5', $adsFields['ctr_percent']);
        $this->assertSame('2.5', $adsFields['cost_per_click']);
        $this->assertSame('125', $adsFields['cost_per_thousand_impressions']);
        $this->assertSame('5', $adsFields['cost_per_action']);
        $this->assertSame('50', $adsFields['conversion_rate_percent']);
        $this->assertSame('2026-11-01', $adsFields['requested_start_on']);

        $combinedFields = array_combine($csvRows[0], $csvRows[4]);
        $this->assertSame('每日 GA4 ROAS', $combinedFields['source']);
        $this->assertSame('2026-11-06', $combinedFields['date']);
        $this->assertSame('missing_ga4', $combinedFields['roas_status']);
        $this->assertSame('2026-11-30', $adsFields['coverage_end_on']);
    }

    public function test_report_renders_google_ads_daily_trend_with_accessible_svg_labels(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'ads_status' => 'complete',
            'ads_rows' => [[
                'date' => '2026-11-06',
                'spend_amount' => '30.0000',
                'impressions' => 300,
                'clicks' => 10,
                'conversions' => '2.0000',
                'conversions_value' => '90.0000',
            ]],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Google Ads 每日花費趨勢')
            ->assertSee('2026-11-06：花費 TWD 30.00')
            ->assertSee('role="img"', false)
            ->assertSee(
                'aria-labelledby="campaign-report-daily-google_ads-chart-title campaign-report-daily-google_ads-chart-description"',
                false,
            );

        $trendChart = $response->viewData('dailyMarketingPerformance')['google_ads']['trend_chart'];
        $this->assertSame(['2026-11-06'], array_column($trendChart['points'], 'date'));
        $this->assertSame('TWD 30.00', $trendChart['maximum_label']);
    }

    public function test_report_formats_search_share_boundaries_and_missing_search_metrics(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'ads_status' => 'complete',
            'ads_channel_type' => 'SEARCH',
            'ads_rows' => [
                [
                    'date' => '2026-11-06',
                    'spend_amount' => '30.0000',
                    'impressions' => 300,
                    'clicks' => 10,
                    'conversions' => '2.0000',
                    'conversions_value' => '90.0000',
                    'search_impression_share' => '0.0999',
                    'search_budget_lost_impression_share' => '0.9001',
                    'search_rank_lost_impression_share' => '0.2345',
                ],
                [
                    'date' => '2026-11-05',
                    'spend_amount' => '0.0000',
                    'impressions' => 0,
                    'clicks' => 0,
                    'conversions' => '0.0000',
                    'conversions_value' => '0.0000',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('搜尋曝光比重')
            ->assertSee('預算流失比重')
            ->assertSee('排名流失比重')
            ->assertSee('&lt;10%', false)
            ->assertSee('&gt;90%', false)
            ->assertSee('23.45%')
            ->assertSee('平台未提供')
            ->assertSee('搜尋指標判讀');

        $googleAdsDailyReport = $response->viewData('dailyMarketingPerformance')['google_ads'];
        $this->assertSame('SEARCH', $googleAdsDailyReport['campaign_channel_type']);
        $this->assertSame('0.0999', $googleAdsDailyReport['rows'][0]['search_impression_share']);
        $this->assertNull($googleAdsDailyReport['rows'][1]['search_impression_share']);
    }

    public function test_report_marks_search_metrics_not_applicable_for_non_search_campaigns(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'ads_channel_type' => 'DISPLAY',
            'ads_rows' => [[
                'date' => '2026-11-06',
                'spend_amount' => '30.0000',
                'impressions' => 300,
                'clicks' => 10,
                'conversions' => '2.0000',
                'conversions_value' => '90.0000',
            ]],
        ]);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('不適用');
    }

    public function test_report_labels_legacy_search_metrics_as_not_synced_when_campaign_type_is_unknown(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'ads_rows' => [[
                'date' => '2026-11-06',
                'spend_amount' => '30.0000',
                'impressions' => 300,
                'clicks' => 10,
                'conversions' => '2.0000',
                'conversions_value' => '90.0000',
            ]],
        ]);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('尚未同步搜尋指標');
    }

    public function test_report_treats_unspecified_google_ads_channel_as_unknown(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'ads_channel_type' => 'UNSPECIFIED',
            'ads_rows' => [[
                'date' => '2026-11-06',
                'spend_amount' => '30.0000',
                'impressions' => 300,
                'clicks' => 10,
                'conversions' => '2.0000',
                'conversions_value' => '90.0000',
            ]],
        ]);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('尚未同步搜尋指標')
            ->assertDontSee('不適用');
    }

    public function test_report_does_not_expose_daily_metrics_from_another_users_connections(): void
    {
        $viewer = User::factory()->create();
        $otherUser = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($otherUser, $target, [
            'ga_rows' => [[
                'date' => '2026-11-02',
                'sessions' => 987654321,
                'engaged_sessions' => 987654321,
                'key_events' => '987654321.0000',
                'total_revenue' => '987654321.0000',
            ]],
            'meta_rows' => [[
                'date' => '2026-11-03',
                'spend_amount' => '987654321.0000',
                'impressions' => 987654321,
                'reach' => 987654321,
                'clicks' => 987654321,
                'actions' => [],
                'action_values' => [],
            ]],
            'ads_rows' => [[
                'date' => '2026-11-04',
                'spend_amount' => '987654321.0000',
                'impressions' => 987654321,
                'clicks' => 987654321,
                'conversions' => '987654321.0000',
                'conversions_value' => '987654321.0000',
            ]],
        ]);

        $response = $this->actingAs($viewer)
            ->get(route('social-engagement-targets.report', $target))
            ->assertNotFound();

        $this->assertStringNotContainsString('987654321', $response->getContent());
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function createTarget(array $options = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create($options + [
            'tag' => 'Daily campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_campaign_name' => 'Daily campaign',
            'google_ads_campaign_id' => 'ads-123',
            'meta_campaign_id' => 'meta-123',
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function createDailyReports(User $user, SocialEngagementTarget $target, array $options = []): void
    {
        if ($target->user_id === null) {
            $target->forceFill([
                'user_id' => $user->id,
                'client_id' => Client::ensureDefaultFor($user)->id,
            ])->save();
        }

        $startDate = $target->starts_on->toDateString();
        $endDate = $target->ends_on->toDateString();
        $requestedDates = [
            'requested_start_on' => $startDate,
            'requested_end_on' => $endDate,
        ];

        $analyticsConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'ga-refresh-token',
        ]);
        GoogleAnalyticsClientProperty::query()->create([
            'google_analytics_connection_id' => $analyticsConnection->id,
            'client_id' => $target->client_id,
            'property_id' => $analyticsConnection->property_id,
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $analyticsConnection->id,
            'property_id' => $analyticsConnection->property_id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'attribution_mode' => 'google_ads_auto_tagging',
            'campaign_id' => $target->google_ads_campaign_id,
            'starts_on' => $startDate,
            'ends_on' => $endDate,
            'sessions' => 0,
            'engaged_sessions' => 0,
            'key_events' => '0.0000',
            'total_revenue' => '0.0000',
            'currency_code' => 'TWD',
            'daily_metrics' => [
                'status' => $options['ga_status'] ?? 'complete',
                ...$requestedDates,
                'coverage_start_on' => $options['ga_coverage_start_on'] ?? $startDate,
                'coverage_end_on' => $options['ga_coverage_end_on'] ?? $endDate,
                'rows' => $options['ga_rows'] ?? [],
            ],
            'synced_at' => now(),
        ]);

        $metaConnection = MetaMarketingConnection::factory()->create([
            'user_id' => $user->id,
            'ad_account_id' => '12345',
            'ad_account_name' => 'Daily Meta account',
            'currency_code' => 'TWD',
        ]);
        MetaMarketingClientAccount::query()->create([
            'meta_marketing_connection_id' => $metaConnection->id,
            'client_id' => $target->client_id,
            'ad_account_id' => $metaConnection->ad_account_id,
            'ad_account_name' => $metaConnection->ad_account_name,
            'currency_code' => $metaConnection->currency_code,
        ]);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $metaConnection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => $metaConnection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Daily Meta campaign',
            'starts_on' => $startDate,
            'ends_on' => $endDate,
            'spend_amount' => '0.0000',
            'impressions' => 0,
            'reach' => 0,
            'clicks' => 0,
            'actions' => [],
            'action_values' => [],
            'currency_code' => 'TWD',
            'daily_metrics' => [
                'status' => $options['meta_status'] ?? 'complete',
                ...$requestedDates,
                'coverage_start_on' => $options['meta_coverage_start_on'] ?? $startDate,
                'coverage_end_on' => $options['meta_coverage_end_on'] ?? $endDate,
                'rows' => $options['meta_rows'] ?? [],
            ],
            'synced_at' => now(),
        ]);

        $adsConnection = GoogleAdsConnection::factory()->create([
            'user_id' => $user->id,
            'customer_id' => '1234567890',
            'currency_code' => 'TWD',
        ]);
        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $adsConnection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => $adsConnection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Daily Google Ads campaign',
            'starts_on' => $startDate,
            'ends_on' => $endDate,
            'spend_amount' => '0.0000',
            'impressions' => 0,
            'clicks' => 0,
            'conversions' => '0.0000',
            'conversions_value' => '0.0000',
            'currency_code' => 'TWD',
            'daily_metrics' => [
                'status' => $options['ads_status'] ?? 'complete',
                'campaign_channel_type' => $options['ads_channel_type'] ?? null,
                ...$requestedDates,
                'coverage_start_on' => $options['ads_coverage_start_on'] ?? $startDate,
                'coverage_end_on' => $options['ads_coverage_end_on'] ?? $endDate,
                'rows' => $options['ads_rows'] ?? [],
            ],
            'synced_at' => now(),
        ]);
    }

    /**
     * @return list<list<string|null>>
     */
    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }
}
