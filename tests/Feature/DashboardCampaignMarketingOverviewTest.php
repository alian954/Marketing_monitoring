<?php

namespace Tests\Feature;

use App\Models\CheckRun;
use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use App\Services\CampaignPortfolioMarketingSummary;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardCampaignMarketingOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summarizes_marketing_metrics_for_the_current_users_connections(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createGoogleAnalyticsReport($user, $target, [
            'sessions' => 120,
            'total_revenue' => '900.0000',
        ]);
        $this->createMetaMarketingReport($user, $target, [
            'spend_amount' => '100.0000',
            'action_values' => [['action_type' => 'omni_purchase', 'value' => '450.0000']],
        ]);
        $this->createGoogleAdsReport($user, $target, [
            'spend_amount' => '200.0000',
            'conversions_value' => '300.0000',
        ]);
        $this->createPublicEngagementPost($target, 'overview-cost');

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('活動行銷成效')
            ->assertSee('跨活動行銷數據總覽')
            ->assertSee('廣告平台歸因 ROAS')
            ->assertSee('平台歸因價值可能重疊')
            ->assertSee('450.00')
            ->assertSee('300.00')
            ->assertSee('4.50x')
            ->assertSee('1.50x')
            ->assertSee('同幣別 ROAS 排名')
            ->assertSee('跨活動預算節奏')
            ->assertSee('社群貼文互動成本')
            ->assertSee('120 工作階段')
            ->assertSee('每次參與工作階段成本：2.5000 TWD')
            ->assertSee('900.00')
            ->assertSee('300.00 TWD')
            ->assertSee('4.50x')
            ->assertSee('95 次互動')
            ->assertSee('3.1579 TWD／次')
            ->assertSee('1.0526 TWD')
            ->assertSee('2.1053 TWD')
            ->assertSee('1/1 篇')
            ->assertSee('30.0%');

        $marketing = $response->viewData('socialEngagementTargets')[0]['marketing'];

        $this->assertSame('fresh', $marketing['google_analytics']['status']);
        $this->assertSame('fresh', $marketing['meta_marketing']['status']);
        $this->assertSame('fresh', $marketing['google_ads']['status']);
        $this->assertSame(120, $marketing['google_analytics']['sessions']);
        $this->assertSame('300.0000', $marketing['efficiency']['ad_spend_amount']);
        $this->assertSame('google_ads', $marketing['efficiency']['roas_spend_source']);
        $this->assertSame('Google Ads 花費', $marketing['efficiency']['roas_spend_source_label']);
        $this->assertSame('200.0000', $marketing['efficiency']['roas_spend_amount']);
        $this->assertSame(4.5, $marketing['efficiency']['roas']);
        $this->assertSame(2.5, $marketing['efficiency']['cost_per_engaged_session']);
        $this->assertSame('available', $marketing['efficiency']['cost_per_engaged_session_status']);
        $this->assertSame(30.0, $marketing['efficiency']['budget_utilization_percent']);
        $this->assertSame(95, $marketing['public_engagement']['interaction_total']);
        $this->assertSame(100.0, $marketing['public_engagement']['coverage_percent']);
        $this->assertSame(3.1579, $marketing['efficiency']['cost_per_public_interaction']);
        $this->assertArrayHasKey('meta_marketing', $marketing['efficiency']['portfolio_interaction_cost_ranks']);
        $this->assertArrayHasKey('google_ads', $marketing['efficiency']['portfolio_interaction_cost_ranks']);
        $this->assertArrayNotHasKey('combined_paid_ads', $marketing['efficiency']['portfolio_interaction_cost_ranks']);
        $portfolio = $response->viewData('campaignPortfolioMarketingSummary');
        $this->assertSame(1, $portfolio['target_count']);
        $this->assertSame(900.0, $portfolio['currency_groups'][0]['ga4_revenue']);
        $this->assertSame(4.5, $portfolio['currency_groups'][0]['roas']);
        $this->assertSame(1, $portfolio['currency_groups'][0]['roas_campaign_count']);
        $nativeRoasGroups = collect($portfolio['native_roas_groups'])->keyBy('source');
        $this->assertSame(450.0, $nativeRoasGroups['meta_marketing']['conversion_value_amount']);
        $this->assertSame(100.0, $nativeRoasGroups['meta_marketing']['matched_spend_amount']);
        $this->assertSame(4.5, $nativeRoasGroups['meta_marketing']['roas']);
        $this->assertSame(300.0, $nativeRoasGroups['google_ads']['conversion_value_amount']);
        $this->assertSame(200.0, $nativeRoasGroups['google_ads']['matched_spend_amount']);
        $this->assertSame(1.5, $nativeRoasGroups['google_ads']['roas']);
        $this->assertSame(1000.0, $portfolio['budget_currency_groups'][0]['budget_amount']);
        $this->assertSame(300.0, $portfolio['budget_currency_groups'][0]['current_spend_amount']);
        $this->assertSame(1, $portfolio['budget_currency_groups'][0]['projected_campaign_count']);
        $this->assertSame(1, $portfolio['budget_coverage']['projected_campaigns']);
        $this->assertSame(1, $response->viewData('campaignPortfolioMarketingSummary')['budget_coverage']['configured_campaigns']);
        $this->assertSame(1, $marketing['efficiency']['portfolio_roas_rank']['rank']);
        $this->assertSame('TWD', $marketing['efficiency']['portfolio_roas_rank']['currency_code']);
        $interactionCosts = collect($portfolio['interaction_cost_groups'])->keyBy('source');
        $this->assertSame(1.0526, $interactionCosts['meta_marketing']['cost_per_public_interaction']);
        $this->assertSame(2.1053, $interactionCosts['google_ads']['cost_per_public_interaction']);
    }

    public function test_dashboard_marks_engaged_session_cost_unavailable_for_stale_google_ads_spend(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createGoogleAnalyticsReport($user, $target);
        $this->createGoogleAdsReport($user, $target, ['synced_at' => now()->subDays(2)]);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('每次參與工作階段成本：花費資料已過期');
        $marketing = $response->viewData('socialEngagementTargets')[0]['marketing'];

        $this->assertNull($marketing['efficiency']['cost_per_engaged_session']);
        $this->assertSame('stale_spend', $marketing['efficiency']['cost_per_engaged_session_status']);
    }

    public function test_dashboard_shows_ga4_business_goal_pacing_and_portfolio_coverage(): void
    {
        $this->travelTo('2026-11-15 12:00:00');
        $user = User::factory()->create();
        $connection = $this->createGoogleAnalyticsConnection($user);
        $aheadTarget = $this->createTarget([
            'tag' => 'Goal ahead',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => null,
            'ga4_goal_metric' => 'key_events',
            'ga4_goal_value' => '20.0000',
            'ga4_goal_currency' => null,
        ]);
        $behindTarget = $this->createTarget([
            'tag' => 'Goal behind',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => null,
            'ga4_goal_metric' => 'total_revenue',
            'ga4_goal_value' => '1000.0000',
            'ga4_goal_currency' => 'TWD',
        ]);
        $staleTarget = $this->createTarget([
            'tag' => 'Goal stale',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => null,
            'ga4_goal_metric' => 'key_events',
            'ga4_goal_value' => '20.0000',
            'ga4_goal_currency' => null,
        ]);
        $mismatchedTarget = $this->createTarget([
            'tag' => 'Goal currency mismatch',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => null,
            'ga4_goal_metric' => 'total_revenue',
            'ga4_goal_value' => '1000.0000',
            'ga4_goal_currency' => 'TWD',
        ]);
        $notStartedTarget = $this->createTarget([
            'tag' => 'Goal not started',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => null,
            'starts_on' => '2026-11-16',
            'ends_on' => '2026-11-30',
            'ga4_goal_metric' => 'key_events',
            'ga4_goal_value' => '20.0000',
            'ga4_goal_currency' => null,
        ]);
        $completedTarget = $this->createTarget([
            'tag' => 'Goal completed',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => null,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-14',
            'ga4_goal_metric' => 'key_events',
            'ga4_goal_value' => '20.0000',
            'ga4_goal_currency' => null,
        ]);
        $unconfiguredTarget = $this->createTarget([
            'tag' => 'Goal not configured',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => null,
        ]);

        $this->createGoalGoogleAnalyticsReport($connection, $aheadTarget, ['key_events' => '12.5000']);
        $this->createGoalGoogleAnalyticsReport($connection, $behindTarget, ['total_revenue' => '300.0000']);
        $this->createGoalGoogleAnalyticsReport($connection, $staleTarget, [
            'key_events' => '12.0000',
            'synced_at' => now()->subDays(2),
        ]);
        $this->createGoalGoogleAnalyticsReport($connection, $mismatchedTarget, [
            'total_revenue' => '800.0000',
            'currency_code' => 'USD',
        ]);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('GA4 目標進度')
            ->assertSee('領先 1')
            ->assertSee('落後 1')
            ->assertSee('符合進度 0')
            ->assertSee('尚未開始 1')
            ->assertSee('已結束 1')
            ->assertSee('進度資料不足 2')
            ->assertSee('GA4 資料超過 24 小時，請更新後查看達成率')
            ->assertSee('目標與 GA4 幣別不同，無法比較')
            ->assertSee('活動尚未開始')
            ->assertSee('活動已結束');

        $marketingByTag = collect($response->viewData('socialEngagementTargets'))->keyBy('tag');
        $aheadGoal = $marketingByTag['Goal ahead']['marketing']['ga4_business_goal'];
        $behindGoal = $marketingByTag['Goal behind']['marketing']['ga4_business_goal'];
        $staleGoal = $marketingByTag['Goal stale']['marketing']['ga4_business_goal'];
        $mismatchedGoal = $marketingByTag['Goal currency mismatch']['marketing']['ga4_business_goal'];
        $notStartedGoal = $marketingByTag['Goal not started']['marketing']['ga4_business_goal'];
        $completedGoal = $marketingByTag['Goal completed']['marketing']['ga4_business_goal'];
        $unconfiguredGoal = $marketingByTag['Goal not configured']['marketing']['ga4_business_goal'];

        $this->assertSame(62.5, $aheadGoal['completion_percent']);
        $this->assertSame('ahead', $aheadGoal['time_pacing']['status']);
        $this->assertSame(30.0, $behindGoal['completion_percent']);
        $this->assertSame('behind', $behindGoal['time_pacing']['status']);
        $this->assertSame('stale', $staleGoal['status']);
        $this->assertNull($staleGoal['completion_percent']);
        $this->assertSame('currency_mismatch', $mismatchedGoal['status']);
        $this->assertNull($mismatchedGoal['completion_percent']);
        $this->assertSame('not_started', $notStartedGoal['time_pacing']['status']);
        $this->assertSame('completed', $completedGoal['time_pacing']['status']);
        $this->assertSame('not_configured', $unconfiguredGoal['status']);

        $portfolioCoverage = $response->viewData('campaignPortfolioMarketingSummary')['ga4_business_goal_coverage'];
        $this->assertSame(6, $portfolioCoverage['configured_campaigns']);
        $this->assertSame(2, $portfolioCoverage['available_campaigns']);
        $this->assertSame(4, $portfolioCoverage['unavailable_campaigns']);
        $this->assertSame(1, $portfolioCoverage['ahead_campaigns']);
        $this->assertSame(1, $portfolioCoverage['behind_campaigns']);
        $this->assertSame(1, $portfolioCoverage['not_started_campaigns']);
        $this->assertSame(1, $portfolioCoverage['completed_campaigns']);
        $this->assertSame(2, $portfolioCoverage['data_unavailable_campaigns']);
    }

    public function test_campaign_portfolio_groups_currencies_calculates_weighted_roas_and_reports_coverage(): void
    {
        $targets = [
            $this->createTarget(['tag' => 'Portfolio TWD one', 'google_ads_campaign_id' => 'ads-portfolio-1', 'meta_campaign_id' => 'meta-portfolio-1']),
            $this->createTarget(['tag' => 'Portfolio TWD two', 'google_ads_campaign_id' => 'ads-portfolio-2', 'meta_campaign_id' => 'meta-portfolio-2']),
            $this->createTarget(['tag' => 'Portfolio USD', 'google_ads_campaign_id' => 'ads-portfolio-usd', 'meta_campaign_id' => null]),
            $this->createTarget(['tag' => 'Portfolio zero values', 'google_ads_campaign_id' => 'ads-portfolio-zero', 'meta_campaign_id' => 'meta-portfolio-zero']),
            $this->createTarget(['tag' => 'Portfolio missing values', 'google_ads_campaign_id' => 'ads-portfolio-missing', 'meta_campaign_id' => 'meta-portfolio-missing']),
            $this->createTarget(['tag' => 'Portfolio TWD tie', 'google_ads_campaign_id' => 'ads-portfolio-tie', 'meta_campaign_id' => 'meta-portfolio-tie']),
        ];
        $marketingSummaries = [
            $targets[0]->id => $this->portfolioMarketingSummary(
                ['status' => 'fresh', 'revenue' => '900.0000', 'currency_code' => 'TWD'],
                ['status' => 'fresh', 'spend_amount' => '100.0000', 'currency_code' => 'TWD'],
                ['status' => 'fresh', 'spend_amount' => '200.0000', 'currency_code' => 'TWD'],
                ['roas' => 4.5, 'roas_spend_amount' => '200.0000', 'roas_spend_currency' => 'TWD', 'roas_spend_source' => 'google_ads'],
            ),
            $targets[1]->id => $this->portfolioMarketingSummary(
                ['status' => 'stale', 'revenue' => '100.0000', 'currency_code' => 'TWD'],
                ['status' => 'stale', 'spend_amount' => '50.0000', 'currency_code' => 'TWD'],
                ['status' => 'stale', 'spend_amount' => '100.0000', 'currency_code' => 'TWD'],
                ['roas' => 1.0, 'roas_spend_amount' => '100.0000', 'roas_spend_currency' => 'TWD', 'roas_spend_source' => 'google_ads'],
            ),
            $targets[2]->id => $this->portfolioMarketingSummary(
                ['status' => 'fresh', 'revenue' => '80.0000', 'currency_code' => 'USD'],
                ['status' => 'not_configured'],
                ['status' => 'fresh', 'spend_amount' => '20.0000', 'currency_code' => 'USD'],
                ['roas' => 4.0, 'roas_spend_amount' => '20.0000', 'roas_spend_currency' => 'USD', 'roas_spend_source' => 'google_ads'],
            ),
            $targets[3]->id => $this->portfolioMarketingSummary(
                ['status' => 'fresh', 'revenue' => '0.0000', 'currency_code' => 'TWD'],
                ['status' => 'fresh', 'spend_amount' => '0.0000', 'currency_code' => 'TWD'],
                ['status' => 'fresh', 'spend_amount' => '0.0000', 'currency_code' => 'TWD'],
            ),
            $targets[4]->id => $this->portfolioMarketingSummary(
                ['status' => 'missing'],
                ['status' => 'missing'],
                ['status' => 'missing'],
            ),
            $targets[5]->id => $this->portfolioMarketingSummary(
                ['status' => 'fresh', 'revenue' => '450.0000', 'currency_code' => 'TWD'],
                ['status' => 'fresh', 'spend_amount' => '10.0000', 'currency_code' => 'TWD'],
                ['status' => 'fresh', 'spend_amount' => '100.0000', 'currency_code' => 'TWD'],
                ['roas' => 4.5, 'roas_spend_amount' => '100.0000', 'roas_spend_currency' => 'TWD', 'roas_spend_source' => 'google_ads'],
            ),
        ];

        $portfolio = app(CampaignPortfolioMarketingSummary::class)->build(
            new EloquentCollection($targets),
            $marketingSummaries,
        );
        $currencyGroups = collect($portfolio['currency_groups'])->keyBy('currency_code');
        $twd = $currencyGroups->get('TWD');
        $usd = $currencyGroups->get('USD');

        $this->assertSame(6, $portfolio['target_count']);
        $this->assertSame(1450.0, $twd['ga4_revenue']);
        $this->assertSame(160.0, $twd['meta_spend']);
        $this->assertSame(400.0, $twd['google_ads_spend']);
        $this->assertSame(3.625, $twd['roas']);
        $this->assertSame(3, $twd['roas_campaign_count']);
        $this->assertSame(80.0, $usd['ga4_revenue']);
        $this->assertSame(20.0, $usd['google_ads_spend']);
        $this->assertSame(4.0, $usd['roas']);
        $this->assertSame(5, $portfolio['coverage']['google_analytics']['available_campaigns']);
        $this->assertSame(4, $portfolio['coverage']['google_analytics']['fresh_campaigns']);
        $this->assertSame(1, $portfolio['coverage']['google_analytics']['stale_campaigns']);
        $this->assertSame(1, $portfolio['coverage']['google_analytics']['missing_campaigns']);
        $this->assertSame(1, $portfolio['campaign_ranks'][$targets[0]->id]['rank']);
        $this->assertSame(1, $portfolio['campaign_ranks'][$targets[5]->id]['rank']);
        $this->assertSame(3, $portfolio['campaign_ranks'][$targets[1]->id]['rank']);
        $this->assertSame(1, $portfolio['campaign_ranks'][$targets[2]->id]['rank']);
    }

    public function test_campaign_portfolio_calculates_native_paid_media_roas_by_source_and_currency(): void
    {
        $metaTarget = $this->createTarget([
            'tag' => 'Native Meta and Google',
            'meta_campaign_id' => 'meta-native-first',
            'google_ads_campaign_id' => 'google-native-twd',
        ]);
        $staleDuplicate = $this->createTarget([
            'tag' => 'Native Meta duplicate stale',
            'meta_campaign_id' => 'meta-native-shared',
            'google_ads_campaign_id' => null,
        ]);
        $freshDuplicate = $this->createTarget([
            'tag' => 'Native Meta duplicate fresh',
            'meta_campaign_id' => 'meta-native-shared',
            'google_ads_campaign_id' => null,
        ]);
        $zeroValueTarget = $this->createTarget([
            'tag' => 'Native Meta zero value',
            'meta_campaign_id' => 'meta-native-zero-value',
            'google_ads_campaign_id' => null,
        ]);
        $missingValueTarget = $this->createTarget([
            'tag' => 'Native Meta missing value',
            'meta_campaign_id' => 'meta-native-missing-value',
            'google_ads_campaign_id' => null,
        ]);
        $zeroSpendTarget = $this->createTarget([
            'tag' => 'Native Meta zero spend',
            'meta_campaign_id' => 'meta-native-zero-spend',
            'google_ads_campaign_id' => null,
        ]);
        $negativeSpendTarget = $this->createTarget([
            'tag' => 'Native Meta negative spend',
            'meta_campaign_id' => 'meta-native-negative-spend',
            'google_ads_campaign_id' => null,
        ]);
        $googleUsdTarget = $this->createTarget([
            'tag' => 'Native Google USD',
            'meta_campaign_id' => null,
            'google_ads_campaign_id' => 'google-native-usd',
        ]);
        $marketingSummaries = [
            $metaTarget->id => $this->portfolioMarketingSummary(
                metaMarketing: ['status' => 'fresh', 'spend_amount' => '100.0000', 'currency_code' => 'TWD'],
                googleAds: ['status' => 'fresh', 'spend_amount' => '200.0000', 'currency_code' => 'TWD'],
                efficiency: ['meta_ads_omni_purchase_value' => '300.0000', 'google_ads_conversion_value' => '500.0000'],
            ),
            $staleDuplicate->id => $this->portfolioMarketingSummary(
                metaMarketing: ['status' => 'stale', 'spend_amount' => '80.0000', 'currency_code' => 'TWD'],
                efficiency: ['meta_ads_omni_purchase_value' => '900.0000'],
            ),
            $freshDuplicate->id => $this->portfolioMarketingSummary(
                metaMarketing: ['status' => 'fresh', 'spend_amount' => '100.0000', 'currency_code' => 'TWD'],
                efficiency: ['meta_ads_omni_purchase_value' => '400.0000'],
            ),
            $zeroValueTarget->id => $this->portfolioMarketingSummary(
                metaMarketing: ['status' => 'fresh', 'spend_amount' => '50.0000', 'currency_code' => 'TWD'],
                efficiency: ['meta_ads_omni_purchase_value' => '0.0000'],
            ),
            $missingValueTarget->id => $this->portfolioMarketingSummary(
                metaMarketing: ['status' => 'fresh', 'spend_amount' => '25.0000', 'currency_code' => 'TWD'],
            ),
            $zeroSpendTarget->id => $this->portfolioMarketingSummary(
                metaMarketing: ['status' => 'fresh', 'spend_amount' => '0.0000', 'currency_code' => 'TWD'],
                efficiency: ['meta_ads_omni_purchase_value' => '100.0000'],
            ),
            $negativeSpendTarget->id => $this->portfolioMarketingSummary(
                metaMarketing: ['status' => 'fresh', 'spend_amount' => '-20.0000', 'currency_code' => 'TWD'],
                efficiency: ['meta_ads_omni_purchase_value' => '100.0000'],
            ),
            $googleUsdTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'stale', 'spend_amount' => '40.0000', 'currency_code' => 'USD'],
                efficiency: ['google_ads_conversion_value' => '200.0000'],
            ),
        ];

        $portfolio = app(CampaignPortfolioMarketingSummary::class)->build(
            new EloquentCollection([
                $metaTarget,
                $staleDuplicate,
                $freshDuplicate,
                $zeroValueTarget,
                $missingValueTarget,
                $zeroSpendTarget,
                $negativeSpendTarget,
                $googleUsdTarget,
            ]),
            $marketingSummaries,
        );
        $nativeRoasGroups = collect($portfolio['native_roas_groups'])
            ->keyBy(fn (array $group): string => $group['source'].'|'.$group['currency_code']);
        $metaTwd = $nativeRoasGroups['meta_marketing|TWD'];
        $googleTwd = $nativeRoasGroups['google_ads|TWD'];
        $googleUsd = $nativeRoasGroups['google_ads|USD'];

        $this->assertSame(700.0, $metaTwd['conversion_value_amount']);
        $this->assertSame(250.0, $metaTwd['matched_spend_amount']);
        $this->assertSame(2.8, $metaTwd['roas']);
        $this->assertSame(6, $metaTwd['campaign_count']);
        $this->assertSame(3, $metaTwd['comparable_campaign_count']);
        $this->assertSame(3, $metaTwd['missing_campaign_count']);
        $this->assertSame(1, $metaTwd['duplicate_mappings_excluded']);
        $this->assertSame(0, $metaTwd['stale_campaign_count']);
        $this->assertSame(500.0, $googleTwd['conversion_value_amount']);
        $this->assertSame(200.0, $googleTwd['matched_spend_amount']);
        $this->assertSame(2.5, $googleTwd['roas']);
        $this->assertSame('USD', $googleUsd['currency_code']);
        $this->assertSame(5.0, $googleUsd['roas']);
        $this->assertSame(1, $googleUsd['stale_campaign_count']);
        $this->assertSame(6, $portfolio['native_roas_coverage']['meta_marketing']['configured_campaigns']);
        $this->assertSame(3, $portfolio['native_roas_coverage']['meta_marketing']['comparable_campaigns']);
        $this->assertSame(3, $portfolio['native_roas_coverage']['meta_marketing']['missing_campaigns']);
        $this->assertSame(1, $portfolio['native_roas_coverage']['meta_marketing']['duplicate_mappings_excluded']);
    }

    public function test_campaign_portfolio_excludes_duplicate_campaign_mappings_and_prefers_fresh_snapshots(): void
    {
        $staleTarget = $this->createTarget([
            'tag' => 'Portfolio duplicate stale',
            'google_ads_campaign_id' => 'ads-duplicate',
            'meta_campaign_id' => 'meta-duplicate',
            'ends_on' => '2026-11-15',
        ]);
        $freshTarget = $this->createTarget([
            'tag' => 'Portfolio duplicate fresh',
            'google_ads_campaign_id' => 'ads-duplicate',
            'meta_campaign_id' => 'meta-duplicate',
        ]);
        $marketingSummaries = [
            $staleTarget->id => $this->portfolioMarketingSummary(
                ['status' => 'stale', 'revenue' => '900.0000', 'currency_code' => 'TWD'],
                ['status' => 'stale', 'spend_amount' => '80.0000', 'currency_code' => 'TWD'],
                ['status' => 'stale', 'spend_amount' => '200.0000', 'currency_code' => 'TWD'],
                ['roas' => 4.5, 'roas_spend_amount' => '200.0000', 'roas_spend_currency' => 'TWD', 'roas_spend_source' => 'google_ads'],
            ),
            $freshTarget->id => $this->portfolioMarketingSummary(
                ['status' => 'fresh', 'revenue' => '300.0000', 'currency_code' => 'TWD'],
                ['status' => 'fresh', 'spend_amount' => '50.0000', 'currency_code' => 'TWD'],
                ['status' => 'fresh', 'spend_amount' => '100.0000', 'currency_code' => 'TWD'],
                ['roas' => 3.0, 'roas_spend_amount' => '100.0000', 'roas_spend_currency' => 'TWD', 'roas_spend_source' => 'google_ads'],
            ),
        ];

        $portfolio = app(CampaignPortfolioMarketingSummary::class)->build(
            new EloquentCollection([$staleTarget, $freshTarget]),
            $marketingSummaries,
        );
        $twd = $portfolio['currency_groups'][0];

        $this->assertSame(300.0, $twd['ga4_revenue']);
        $this->assertSame(50.0, $twd['meta_spend']);
        $this->assertSame(100.0, $twd['google_ads_spend']);
        $this->assertSame(3.0, $twd['roas']);
        $this->assertSame(1, $portfolio['coverage']['google_analytics']['configured_campaigns']);
        $this->assertSame(1, $portfolio['coverage']['google_analytics']['duplicate_mappings_excluded']);
        $this->assertSame(1, $portfolio['campaign_ranks'][$freshTarget->id]['rank']);
        $this->assertArrayNotHasKey($staleTarget->id, $portfolio['campaign_ranks']);

        $mappingAuditRows = collect($portfolio['mapping_audit_rows']);
        $this->assertCount(2, $mappingAuditRows);
        $paidMapping = $mappingAuditRows->firstWhere('type', 'paid_media');
        $this->assertSame(['Portfolio duplicate stale', 'Portfolio duplicate fresh'], $paidMapping['target_tags']);
        $this->assertSame(1, $paidMapping['merged_count']);
        $paidMappingIds = array_column($paidMapping['mappings'], 'campaign_id');
        sort($paidMappingIds);
        $this->assertSame(['ads-duplicate', 'meta-duplicate'], $paidMappingIds);
        $this->assertSame(['Portfolio duplicate fresh', 'Portfolio duplicate fresh'], array_column($paidMapping['mappings'], 'preferred_tag'));
        $this->assertSame(['fresh', 'fresh'], array_column($paidMapping['mappings'], 'freshness_status'));

        $ga4Mapping = $mappingAuditRows->firstWhere('type', 'google_analytics');
        $this->assertSame('ads-duplicate', $ga4Mapping['mappings'][0]['campaign_id']);
        $this->assertSame('google_ads_auto_tagging', $ga4Mapping['mappings'][0]['attribution_mode']);
        $this->assertSame('Portfolio duplicate fresh', $ga4Mapping['mappings'][0]['preferred_tag']);
    }

    public function test_campaign_portfolio_audits_shared_manual_ga4_utm_tuple(): void
    {
        $firstTarget = $this->createTarget([
            'tag' => 'Manual UTM first',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => null,
            'ga4_campaign_name' => 'spring-launch',
            'ga4_source_name' => 'newsletter',
            'ga4_medium_name' => 'email',
        ]);
        $secondTarget = $this->createTarget([
            'tag' => 'Manual UTM second',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => null,
            'ga4_campaign_name' => 'spring-launch',
            'ga4_source_name' => 'newsletter',
            'ga4_medium_name' => 'email',
        ]);
        $thirdTarget = $this->createTarget([
            'tag' => 'Manual UTM separate',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => null,
            'ga4_campaign_name' => 'spring-launch',
            'ga4_source_name' => 'newsletter',
            'ga4_medium_name' => 'social',
        ]);

        $portfolio = app(CampaignPortfolioMarketingSummary::class)->build(
            new EloquentCollection([$firstTarget, $secondTarget, $thirdTarget]),
            [
                $firstTarget->id => $this->portfolioMarketingSummary(['status' => 'fresh', 'revenue' => '100.0000', 'currency_code' => 'TWD']),
                $secondTarget->id => $this->portfolioMarketingSummary(['status' => 'stale', 'revenue' => '900.0000', 'currency_code' => 'TWD']),
                $thirdTarget->id => $this->portfolioMarketingSummary(['status' => 'fresh', 'revenue' => '50.0000', 'currency_code' => 'TWD']),
            ],
        );

        $mappingRows = collect($portfolio['mapping_audit_rows']);
        $this->assertCount(1, $mappingRows);
        $mapping = $mappingRows->sole();
        $this->assertSame('google_analytics', $mapping['type']);
        $this->assertSame(['Manual UTM first', 'Manual UTM second'], $mapping['target_tags']);
        $this->assertSame(1, $mapping['merged_count']);
        $this->assertSame([
            'attribution_mode' => 'manual_utm',
            'campaign_name' => 'spring-launch',
            'source_name' => 'newsletter',
            'medium_name' => 'email',
        ], array_intersect_key($mapping['mappings'][0], array_flip([
            'attribution_mode',
            'campaign_name',
            'source_name',
            'medium_name',
        ])));
        $this->assertSame('Manual UTM first', $mapping['mappings'][0]['preferred_tag']);
        $this->assertSame(150.0, $portfolio['currency_groups'][0]['ga4_revenue']);
        $this->assertSame(2, $portfolio['coverage']['google_analytics']['configured_campaigns']);
    }

    public function test_campaign_portfolio_merges_overlapping_paid_ids_into_one_logical_mapping(): void
    {
        $firstTarget = $this->createTarget([
            'tag' => 'Overlap first',
            'meta_campaign_id' => 'meta-overlap-first',
            'google_ads_campaign_id' => 'ads-overlap-first',
        ]);
        $secondTarget = $this->createTarget([
            'tag' => 'Overlap middle',
            'meta_campaign_id' => 'meta-overlap-first',
            'google_ads_campaign_id' => 'ads-overlap-second',
        ]);
        $thirdTarget = $this->createTarget([
            'tag' => 'Overlap last',
            'meta_campaign_id' => 'meta-overlap-last',
            'google_ads_campaign_id' => 'ads-overlap-second',
        ]);

        $portfolio = app(CampaignPortfolioMarketingSummary::class)->build(
            new EloquentCollection([$firstTarget, $secondTarget, $thirdTarget]),
            [
                $firstTarget->id => $this->portfolioMarketingSummary(
                    ['status' => 'fresh', 'revenue' => '100.0000', 'currency_code' => 'TWD'],
                    ['status' => 'fresh', 'spend_amount' => '10.0000', 'currency_code' => 'TWD'],
                    ['status' => 'fresh', 'spend_amount' => '5.0000', 'currency_code' => 'TWD'],
                ),
                $secondTarget->id => $this->portfolioMarketingSummary(
                    ['status' => 'fresh', 'revenue' => '200.0000', 'currency_code' => 'TWD'],
                    ['status' => 'fresh', 'spend_amount' => '20.0000', 'currency_code' => 'TWD'],
                    ['status' => 'fresh', 'spend_amount' => '15.0000', 'currency_code' => 'TWD'],
                ),
                $thirdTarget->id => $this->portfolioMarketingSummary(
                    ['status' => 'fresh', 'revenue' => '300.0000', 'currency_code' => 'TWD'],
                    ['status' => 'fresh', 'spend_amount' => '30.0000', 'currency_code' => 'TWD'],
                    ['status' => 'fresh', 'spend_amount' => '25.0000', 'currency_code' => 'TWD'],
                ),
            ],
        );

        $paidRows = collect($portfolio['mapping_audit_rows'])->where('type', 'paid_media')->values();
        $this->assertCount(1, $paidRows);
        $paidMapping = $paidRows->sole();
        $this->assertSame(['Overlap first', 'Overlap middle', 'Overlap last'], $paidMapping['target_tags']);
        $this->assertSame(2, $paidMapping['merged_count']);
        $paidMappingIds = array_column($paidMapping['mappings'], 'campaign_id');
        sort($paidMappingIds);
        $this->assertSame(['ads-overlap-second', 'meta-overlap-first'], $paidMappingIds);
        $this->assertSame(1, $portfolio['budget_coverage']['configured_campaigns']);
        $this->assertSame(2, $portfolio['budget_coverage']['duplicate_mappings_excluded']);
        $this->assertSame(40.0, $portfolio['currency_groups'][0]['meta_spend']);
        $this->assertSame(20.0, $portfolio['currency_groups'][0]['google_ads_spend']);
        $this->assertSame(2, $portfolio['coverage']['meta_marketing']['configured_campaigns']);
        $this->assertSame(2, $portfolio['coverage']['google_ads']['configured_campaigns']);
    }

    public function test_dashboard_shows_empty_state_when_no_campaign_mappings_are_shared(): void
    {
        $user = User::factory()->create();
        $this->createTarget(['tag' => 'Unique campaign mapping']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('活動共用映射')
            ->assertSee('目前沒有共用映射。');
    }

    public function test_dashboard_lists_shared_campaign_ids_and_merged_target_tags(): void
    {
        $user = User::factory()->create();
        $this->createTarget([
            'tag' => 'Shared mapping first',
            'meta_campaign_id' => 'meta-shared-dashboard',
            'google_ads_campaign_id' => 'ads-shared-dashboard',
        ]);
        $this->createTarget([
            'tag' => 'Shared mapping second',
            'meta_campaign_id' => 'meta-shared-dashboard',
            'google_ads_campaign_id' => 'ads-shared-dashboard',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('活動共用映射')
            ->assertSee('共用的廣告活動 ID 或 GA4 歸因條件會合併計算')
            ->assertSee('Meta Ads 活動 ID：')
            ->assertSee('Google Ads 活動 ID：')
            ->assertSee('ads-shared-dashboard')
            ->assertSee('meta-shared-dashboard')
            ->assertSee('Shared mapping first、Shared mapping second')
            ->assertSee('已合併 1 筆');
    }

    public function test_campaign_mapping_audit_uses_each_users_own_ga4_report_freshness(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $firstTarget = $this->createTarget([
            'tag' => 'User scoped first',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => 'user-scoped-meta',
            'ga4_campaign_name' => 'user-scoped-campaign',
            'ga4_source_name' => 'newsletter',
            'ga4_medium_name' => 'email',
        ]);
        $secondTarget = $this->createTarget([
            'tag' => 'User scoped second',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => 'user-scoped-meta',
            'ga4_campaign_name' => 'user-scoped-campaign',
            'ga4_source_name' => 'newsletter',
            'ga4_medium_name' => 'email',
        ]);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstUserConnection = $this->createGoogleAnalyticsConnection($firstUser);
        $secondUserConnection = $this->createGoogleAnalyticsConnection($secondUser);

        $this->createGoalGoogleAnalyticsReport($firstUserConnection, $firstTarget, ['total_revenue' => '120.0000', 'synced_at' => now()]);
        $this->createGoalGoogleAnalyticsReport($firstUserConnection, $secondTarget, ['total_revenue' => '900.0000', 'synced_at' => now()->subDays(2)]);
        $this->createGoalGoogleAnalyticsReport($secondUserConnection, $firstTarget, ['total_revenue' => '800.0000', 'synced_at' => now()->subDays(2)]);
        $this->createGoalGoogleAnalyticsReport($secondUserConnection, $secondTarget, ['total_revenue' => '300.0000', 'synced_at' => now()]);

        $firstUserPortfolio = $this->actingAs($firstUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->viewData('campaignPortfolioMarketingSummary');
        $secondUserPortfolio = $this->actingAs($secondUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->viewData('campaignPortfolioMarketingSummary');

        $firstUserGa4Mapping = collect($firstUserPortfolio['mapping_audit_rows'])->firstWhere('type', 'google_analytics');
        $secondUserGa4Mapping = collect($secondUserPortfolio['mapping_audit_rows'])->firstWhere('type', 'google_analytics');
        $this->assertSame('User scoped first', $firstUserGa4Mapping['mappings'][0]['preferred_tag']);
        $this->assertSame('User scoped second', $secondUserGa4Mapping['mappings'][0]['preferred_tag']);
        $this->assertSame(120.0, $firstUserPortfolio['currency_groups'][0]['ga4_revenue']);
        $this->assertSame(300.0, $secondUserPortfolio['currency_groups'][0]['ga4_revenue']);
    }

    public function test_campaign_portfolio_aggregates_budget_pacing_without_double_counting_shared_ad_campaigns(): void
    {
        $firstTarget = $this->createTarget([
            'tag' => 'Budget primary mapping',
            'google_ads_campaign_id' => 'shared-budget-campaign',
            'meta_campaign_id' => 'meta-budget-first',
            'campaign_budget_amount' => '1000.0000',
        ]);
        $duplicateTarget = $this->createTarget([
            'tag' => 'Budget duplicate mapping',
            'google_ads_campaign_id' => 'shared-budget-campaign',
            'meta_campaign_id' => 'meta-budget-second',
            'campaign_budget_amount' => '1000.0000',
        ]);
        $secondTarget = $this->createTarget([
            'tag' => 'Budget separate mapping',
            'google_ads_campaign_id' => 'separate-budget-campaign',
            'meta_campaign_id' => null,
            'campaign_budget_amount' => '500.0000',
        ]);

        $marketingSummaries = [
            $firstTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'fresh'],
                efficiency: [
                    'ad_spend_amount' => '900.0000',
                    'ad_spend_currency' => 'TWD',
                    'ad_spend_source' => 'google_ads',
                    'budget_pacing_difference_amount' => 100.0,
                    'budget_projected_spend_amount' => 1300.0,
                    'budget_projected_overrun_amount' => 300.0,
                ],
            ),
            $duplicateTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'stale'],
                efficiency: [
                    'ad_spend_amount' => '700.0000',
                    'ad_spend_currency' => 'TWD',
                    'ad_spend_source' => 'google_ads',
                    'budget_pacing_difference_amount' => 50.0,
                    'budget_projected_spend_amount' => 1200.0,
                    'budget_projected_overrun_amount' => 200.0,
                ],
            ),
            $secondTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'fresh'],
                efficiency: [
                    'ad_spend_amount' => '400.0000',
                    'ad_spend_currency' => 'TWD',
                    'ad_spend_source' => 'google_ads',
                    'budget_pacing_difference_amount' => -50.0,
                    'budget_projected_spend_amount' => 450.0,
                    'budget_projected_overrun_amount' => -50.0,
                ],
            ),
        ];

        $portfolio = app(CampaignPortfolioMarketingSummary::class)->build(
            new EloquentCollection([$firstTarget, $duplicateTarget, $secondTarget]),
            $marketingSummaries,
        );
        $twd = $portfolio['budget_currency_groups'][0];

        $this->assertSame(2, $portfolio['budget_coverage']['configured_campaigns']);
        $this->assertSame(1, $portfolio['budget_coverage']['duplicate_mappings_excluded']);
        $this->assertSame(2, $portfolio['budget_coverage']['spend_campaigns']);
        $this->assertSame(2, $portfolio['budget_coverage']['fresh_spend_campaigns']);
        $this->assertSame(1500.0, $twd['budget_amount']);
        $this->assertSame(1300.0, $twd['current_spend_amount']);
        $this->assertSame(50.0, $twd['pacing_difference_amount']);
        $this->assertSame(1750.0, $twd['projected_spend_amount']);
        $this->assertSame(300.0, $twd['projected_over_budget_amount']);
        $this->assertSame(50.0, $twd['projected_under_budget_amount']);
    }

    public function test_dashboard_sorts_fresh_budget_forecasts_by_overrun_percent_and_keeps_within_budget_rows(): void
    {
        $this->travelTo('2026-11-15 12:00:00');
        $user = User::factory()->create();
        $googleAdsConnection = $this->createGoogleAdsConnection($user);
        $highRiskTarget = $this->createTarget([
            'tag' => 'High budget risk',
            'google_ads_campaign_id' => 'high-budget-risk',
            'meta_campaign_id' => null,
            'campaign_budget_amount' => '1000.0000',
        ]);
        $moderateRiskTarget = $this->createTarget([
            'tag' => 'Moderate budget risk',
            'google_ads_campaign_id' => 'moderate-budget-risk',
            'meta_campaign_id' => null,
            'campaign_budget_amount' => '1000.0000',
        ]);
        $withinBudgetTarget = $this->createTarget([
            'tag' => 'Within budget',
            'google_ads_campaign_id' => 'within-budget',
            'meta_campaign_id' => null,
            'campaign_budget_amount' => '1000.0000',
        ]);
        $this->createGoogleAdsReportForConnection($googleAdsConnection, $highRiskTarget, ['spend_amount' => '700.0000']);
        $this->createGoogleAdsReportForConnection($googleAdsConnection, $moderateRiskTarget, ['spend_amount' => '600.0000']);
        $this->createGoogleAdsReportForConnection($googleAdsConnection, $withinBudgetTarget, ['spend_amount' => '400.0000']);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('逐活動預算預測')
            ->assertSee('預估超支比例較高者優先')
            ->assertSee('資料 24 小時內更新')
            ->assertSee('可能超支')
            ->assertSee('TWD 1,400.00');
        $forecastRows = $response->viewData('campaignPortfolioMarketingSummary')['budget_risk_rows'];
        $forecastsByTag = collect($forecastRows)->keyBy('tag');

        $this->assertSame(['High budget risk', 'Moderate budget risk', 'Within budget'], array_column($forecastRows, 'tag'));
        $this->assertSame(40.0, $forecastsByTag['High budget risk']['projected_overrun_percent']);
        $this->assertSame(700.0, $forecastsByTag['High budget risk']['spend_amount']);
        $this->assertSame(1400.0, $forecastsByTag['High budget risk']['projected_spend_amount']);
        $this->assertSame('fresh', $forecastsByTag['High budget risk']['freshness_status']);
        $this->assertSame('projected_under_budget', $forecastsByTag['Within budget']['status']);
        $this->assertSame(-200.0, $forecastsByTag['Within budget']['projected_overrun_amount']);
    }

    public function test_dashboard_budget_forecasts_exclude_duplicates_and_unavailable_or_inactive_campaigns(): void
    {
        $this->travelTo('2026-11-15 12:00:00');
        $user = User::factory()->create();
        $googleAdsConnection = $this->createGoogleAdsConnection($user);
        $validTarget = $this->createTarget([
            'tag' => 'Valid duplicate mapping',
            'google_ads_campaign_id' => 'shared-risk-campaign',
            'meta_campaign_id' => null,
        ]);
        $duplicateTarget = $this->createTarget([
            'tag' => 'Duplicate mapping',
            'google_ads_campaign_id' => 'shared-risk-campaign',
            'meta_campaign_id' => null,
        ]);
        $staleTarget = $this->createTarget([
            'tag' => 'Stale budget data',
            'google_ads_campaign_id' => 'stale-budget-data',
            'meta_campaign_id' => null,
        ]);
        $this->createTarget([
            'tag' => 'Missing budget data',
            'google_ads_campaign_id' => 'missing-budget-data',
            'meta_campaign_id' => null,
        ]);
        $currencyMismatchTarget = $this->createTarget([
            'tag' => 'Mismatched budget currency',
            'google_ads_campaign_id' => 'mismatched-budget-currency',
            'meta_campaign_id' => null,
            'campaign_budget_currency' => 'USD',
        ]);
        $notStartedTarget = $this->createTarget([
            'tag' => 'Not started budget',
            'google_ads_campaign_id' => 'not-started-budget',
            'meta_campaign_id' => null,
            'starts_on' => '2026-11-16',
        ]);
        $completedTarget = $this->createTarget([
            'tag' => 'Completed budget',
            'google_ads_campaign_id' => 'completed-budget',
            'meta_campaign_id' => null,
            'ends_on' => '2026-11-14',
        ]);
        $noBudgetTarget = $this->createTarget([
            'tag' => 'No campaign budget',
            'google_ads_campaign_id' => 'no-campaign-budget',
            'meta_campaign_id' => null,
            'campaign_budget_amount' => null,
            'campaign_budget_currency' => null,
        ]);
        $this->createGoogleAdsReportForConnection($googleAdsConnection, $validTarget, ['spend_amount' => '700.0000']);
        $this->createGoogleAdsReportForConnection($googleAdsConnection, $duplicateTarget, ['spend_amount' => '700.0000']);
        $this->createGoogleAdsReportForConnection($googleAdsConnection, $staleTarget, [
            'spend_amount' => '700.0000',
            'synced_at' => now()->subDay()->subSecond(),
        ]);
        $this->createGoogleAdsReportForConnection($googleAdsConnection, $currencyMismatchTarget, ['spend_amount' => '700.0000']);
        $this->createGoogleAdsReportForConnection($googleAdsConnection, $notStartedTarget, ['spend_amount' => '700.0000']);
        $this->createGoogleAdsReportForConnection($googleAdsConnection, $completedTarget, ['spend_amount' => '700.0000']);
        $this->createGoogleAdsReportForConnection($googleAdsConnection, $noBudgetTarget, ['spend_amount' => '700.0000']);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
        $forecastRows = $response->viewData('campaignPortfolioMarketingSummary')['budget_risk_rows'];

        $this->assertSame(['Valid duplicate mapping'], array_column($forecastRows, 'tag'));
        $this->assertSame($validTarget->id, $forecastRows[0]['target_id']);
        $this->assertSame(1, count($forecastRows));
    }

    public function test_campaign_portfolio_calculates_source_specific_interaction_costs_with_deduplication_and_coverage(): void
    {
        $sharedCampaignTarget = $this->createTarget([
            'tag' => 'Interaction shared fresh',
            'google_ads_campaign_id' => 'interaction-shared',
            'meta_campaign_id' => null,
        ]);
        $duplicateCampaignTarget = $this->createTarget([
            'tag' => 'Interaction shared stale',
            'google_ads_campaign_id' => 'interaction-shared',
            'meta_campaign_id' => null,
        ]);
        $separateCampaignTarget = $this->createTarget([
            'tag' => 'Interaction separate',
            'google_ads_campaign_id' => 'interaction-separate',
            'meta_campaign_id' => null,
        ]);
        $missingInteractionTarget = $this->createTarget([
            'tag' => 'Interaction missing',
            'google_ads_campaign_id' => 'interaction-missing',
            'meta_campaign_id' => null,
        ]);
        $zeroInteractionTarget = $this->createTarget([
            'tag' => 'Interaction zero',
            'google_ads_campaign_id' => 'interaction-zero',
            'meta_campaign_id' => null,
        ]);
        $zeroOnlyTarget = $this->createTarget([
            'tag' => 'Interaction zero only',
            'google_ads_campaign_id' => 'interaction-zero-only',
            'meta_campaign_id' => null,
        ]);
        $manualTarget = $this->createTarget([
            'tag' => 'Interaction manual spend',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => null,
        ]);
        $tiedCampaignTarget = $this->createTarget([
            'tag' => 'Interaction tied cost',
            'google_ads_campaign_id' => 'interaction-tied',
            'meta_campaign_id' => null,
        ]);
        $usdCampaignTarget = $this->createTarget([
            'tag' => 'Interaction USD',
            'google_ads_campaign_id' => 'interaction-usd',
            'meta_campaign_id' => null,
        ]);
        $metaCampaignTarget = $this->createTarget([
            'tag' => 'Interaction Meta',
            'google_ads_campaign_id' => null,
            'meta_campaign_id' => 'interaction-meta',
        ]);
        $zeroSpendTarget = $this->createTarget([
            'tag' => 'Interaction zero spend',
            'google_ads_campaign_id' => 'interaction-zero-spend',
            'meta_campaign_id' => null,
        ]);
        $negativeSpendTarget = $this->createTarget([
            'tag' => 'Interaction negative spend',
            'google_ads_campaign_id' => 'interaction-negative-spend',
            'meta_campaign_id' => null,
        ]);
        $partialCoverageTarget = $this->createTarget([
            'tag' => 'Interaction partial coverage',
            'google_ads_campaign_id' => 'interaction-partial',
            'meta_campaign_id' => null,
        ]);
        $staleCampaignTarget = $this->createTarget([
            'tag' => 'Interaction stale data',
            'google_ads_campaign_id' => 'interaction-stale',
            'meta_campaign_id' => null,
        ]);
        $targets = new EloquentCollection([
            $sharedCampaignTarget,
            $duplicateCampaignTarget,
            $separateCampaignTarget,
            $missingInteractionTarget,
            $zeroInteractionTarget,
            $zeroOnlyTarget,
            $manualTarget,
            $tiedCampaignTarget,
            $usdCampaignTarget,
            $metaCampaignTarget,
            $zeroSpendTarget,
            $negativeSpendTarget,
            $partialCoverageTarget,
            $staleCampaignTarget,
        ]);
        $marketingSummaries = [
            $sharedCampaignTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'fresh', 'spend_amount' => '200.0000', 'currency_code' => 'TWD'],
                publicEngagement: ['interaction_total' => 100, 'available_posts' => 2, 'tracked_posts' => 2],
            ),
            $duplicateCampaignTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'stale', 'spend_amount' => '999.0000', 'currency_code' => 'TWD'],
                publicEngagement: ['interaction_total' => 900, 'available_posts' => 4, 'tracked_posts' => 4],
            ),
            $separateCampaignTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'fresh', 'spend_amount' => '50.0000', 'currency_code' => 'TWD'],
                publicEngagement: ['interaction_total' => 100, 'available_posts' => 2, 'tracked_posts' => 2],
            ),
            $missingInteractionTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'fresh', 'spend_amount' => '80.0000', 'currency_code' => 'TWD'],
                publicEngagement: ['interaction_total' => null, 'available_posts' => 0, 'tracked_posts' => 2],
            ),
            $zeroInteractionTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'fresh', 'spend_amount' => '10.0000', 'currency_code' => 'TWD'],
                publicEngagement: ['interaction_total' => 0, 'available_posts' => 1, 'tracked_posts' => 1],
            ),
            $zeroOnlyTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'fresh', 'spend_amount' => '12.0000', 'currency_code' => 'EUR'],
                publicEngagement: ['interaction_total' => 0, 'available_posts' => 1, 'tracked_posts' => 1],
            ),
            $manualTarget->id => $this->portfolioMarketingSummary(
                publicEngagement: ['interaction_total' => 25, 'available_posts' => 1, 'tracked_posts' => 1],
                efficiency: ['ad_spend_source' => 'manual', 'ad_spend_amount' => '5.0000', 'ad_spend_currency' => 'TWD'],
            ),
            $tiedCampaignTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'fresh', 'spend_amount' => '25.0000', 'currency_code' => 'TWD'],
                publicEngagement: ['interaction_total' => 50, 'available_posts' => 2, 'tracked_posts' => 2],
            ),
            $usdCampaignTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'fresh', 'spend_amount' => '20.0000', 'currency_code' => 'USD'],
                publicEngagement: ['interaction_total' => 10, 'available_posts' => 1, 'tracked_posts' => 1],
            ),
            $metaCampaignTarget->id => $this->portfolioMarketingSummary(
                metaMarketing: ['status' => 'fresh', 'spend_amount' => '10.0000', 'currency_code' => 'TWD'],
                publicEngagement: ['interaction_total' => 50, 'available_posts' => 1, 'tracked_posts' => 1],
            ),
            $zeroSpendTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'fresh', 'spend_amount' => '0.0000', 'currency_code' => 'TWD'],
                publicEngagement: ['interaction_total' => 10, 'available_posts' => 1, 'tracked_posts' => 1],
            ),
            $negativeSpendTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'fresh', 'spend_amount' => '-1.0000', 'currency_code' => 'TWD'],
                publicEngagement: ['interaction_total' => 10, 'available_posts' => 1, 'tracked_posts' => 1],
            ),
            $partialCoverageTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'fresh', 'spend_amount' => '10.0000', 'currency_code' => 'TWD'],
                publicEngagement: ['interaction_total' => 50, 'available_posts' => 1, 'tracked_posts' => 2],
            ),
            $staleCampaignTarget->id => $this->portfolioMarketingSummary(
                googleAds: ['status' => 'stale', 'spend_amount' => '30.0000', 'currency_code' => 'TWD'],
                publicEngagement: ['interaction_total' => 10, 'available_posts' => 1, 'tracked_posts' => 1],
            ),
        ];

        $portfolio = app(CampaignPortfolioMarketingSummary::class)->build($targets, $marketingSummaries);
        $interactionCosts = collect($portfolio['interaction_cost_groups'])->keyBy(
            static fn (array $group): string => $group['source'].'|'.$group['currency_code'],
        );
        $googleAdsTwd = $interactionCosts['google_ads|TWD'];

        $this->assertSame(9, $googleAdsTwd['campaign_count']);
        $this->assertSame(8, $googleAdsTwd['comparable_campaign_count']);
        $this->assertSame(324.0, $googleAdsTwd['comparable_spend_amount']);
        $this->assertSame(330, $googleAdsTwd['public_interactions']);
        $this->assertSame(0.9818, $googleAdsTwd['cost_per_public_interaction']);
        $this->assertSame(11, $googleAdsTwd['available_post_count']);
        $this->assertSame(14, $googleAdsTwd['tracked_post_count']);
        $this->assertSame(78.6, $googleAdsTwd['coverage_percent']);
        $this->assertNull($interactionCosts['google_ads|EUR']['cost_per_public_interaction']);
        $this->assertSame(0.2, $interactionCosts['manual|TWD']['cost_per_public_interaction']);

        $ranks = $portfolio['campaign_interaction_cost_ranks'];
        $this->assertSame(1, $ranks[$zeroSpendTarget->id]['google_ads']['rank']);
        $this->assertSame(0.0, $ranks[$zeroSpendTarget->id]['google_ads']['cost_per_public_interaction']);
        $this->assertSame(2, $ranks[$separateCampaignTarget->id]['google_ads']['rank']);
        $this->assertSame(2, $ranks[$tiedCampaignTarget->id]['google_ads']['rank']);
        $this->assertSame(4, $ranks[$sharedCampaignTarget->id]['google_ads']['rank']);
        $this->assertSame('fresh', $ranks[$sharedCampaignTarget->id]['google_ads']['status']);
        $this->assertSame(5, $ranks[$staleCampaignTarget->id]['google_ads']['rank']);
        $this->assertSame('stale', $ranks[$staleCampaignTarget->id]['google_ads']['status']);
        $this->assertSame(1, $ranks[$usdCampaignTarget->id]['google_ads']['rank']);
        $this->assertSame('USD', $ranks[$usdCampaignTarget->id]['google_ads']['currency_code']);
        $this->assertSame(1, $ranks[$metaCampaignTarget->id]['meta_marketing']['rank']);
        $this->assertSame(1, $ranks[$manualTarget->id]['manual']['rank']);
        $this->assertArrayNotHasKey($duplicateCampaignTarget->id, $ranks);
        $this->assertArrayNotHasKey($missingInteractionTarget->id, $ranks);
        $this->assertArrayNotHasKey($zeroInteractionTarget->id, $ranks);
        $this->assertArrayNotHasKey($zeroOnlyTarget->id, $ranks);
        $this->assertArrayNotHasKey($negativeSpendTarget->id, $ranks);
        $this->assertArrayNotHasKey($partialCoverageTarget->id, $ranks);
    }

    public function test_dashboard_displays_the_matching_complete_source_and_currency_interaction_rank(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget([
            'tag' => 'Google Ads interaction rank',
            'meta_campaign_id' => null,
        ]);
        $this->createGoogleAdsReport($user, $target, ['spend_amount' => '200.0000']);
        $this->createPublicEngagementPost($target, 'interaction-rank');

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Google Ads／TWD 成本排名 #1');

        $efficiency = $response->viewData('socialEngagementTargets')[0]['marketing']['efficiency'];
        $rank = $efficiency['portfolio_interaction_cost_ranks']['google_ads'];

        $this->assertSame(1, $rank['rank']);
        $this->assertSame('TWD', $rank['currency_code']);
        $this->assertSame(2.1053, $rank['cost_per_public_interaction']);
    }

    public function test_dashboard_portfolio_follows_the_selected_social_platform_filter(): void
    {
        $user = User::factory()->create();
        $this->createTarget(['tag' => 'Facebook campaign', 'social_platform' => 'facebook']);
        $this->createTarget(['tag' => 'Instagram campaign', 'social_platform' => 'instagram']);

        $response = $this->actingAs($user)
            ->get(route('dashboard', ['engagement_platform' => 'instagram']))
            ->assertOk();

        $this->assertSame('instagram', $response->viewData('socialEngagementSelectedPlatform'));
        $this->assertSame(1, $response->viewData('campaignPortfolioMarketingSummary')['target_count']);
        $this->assertSame('Instagram campaign', $response->viewData('socialEngagementTargets')[0]['tag']);
    }

    public function test_dashboard_distinguishes_zero_missing_stale_and_unconnected_metrics(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createGoogleAnalyticsReport($user, $target, [
            'sessions' => 0,
            'total_revenue' => '0.0000',
            'synced_at' => now()->subDays(2),
        ]);
        $this->createMetaConnection($user);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('0 工作階段')
            ->assertSee('資料過期')
            ->assertSee('尚無花費資料')
            ->assertSee('尚無資料')
            ->assertSee('未連結');

        $marketing = $response->viewData('socialEngagementTargets')[0]['marketing'];

        $this->assertSame('stale', $marketing['google_analytics']['status']);
        $this->assertSame(0, $marketing['google_analytics']['sessions']);
        $this->assertSame('missing', $marketing['meta_marketing']['status']);
        $this->assertNull($marketing['meta_marketing']['spend_amount']);
        $this->assertSame('not_connected', $marketing['google_ads']['status']);
    }

    public function test_dashboard_keeps_total_spend_separate_and_calculates_roas_for_matching_currencies(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createGoogleAnalyticsReport($user, $target, [
            'total_revenue' => '900.0000',
            'currency_code' => 'USD',
        ]);
        $this->createMetaMarketingReport($user, $target, ['spend_amount' => '100.0000', 'currency_code' => 'TWD']);
        $this->createGoogleAdsReport($user, $target, ['spend_amount' => '200.0000', 'currency_code' => 'USD']);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('幣別不同，未合併')
            ->assertSee('分母：Google Ads 花費 200.00 USD')
            ->assertSee('4.50x');

        $efficiency = $response->viewData('socialEngagementTargets')[0]['marketing']['efficiency'];

        $this->assertTrue($efficiency['platform_currency_mismatch']);
        $this->assertNull($efficiency['ad_spend_amount']);
        $this->assertSame('google_ads', $efficiency['roas_spend_source']);
        $this->assertSame('Google Ads 花費', $efficiency['roas_spend_source_label']);
        $this->assertSame(4.5, $efficiency['roas']);
        $this->assertNull($efficiency['roas_unavailable_reason']);
    }

    public function test_dashboard_marks_unconfigured_ad_platforms_before_connection_status(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget([
            'meta_campaign_id' => null,
            'google_ads_campaign_id' => null,
            'starts_on' => null,
            'ends_on' => null,
        ]);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Meta Ads：未設定活動')
            ->assertSee('Google Ads：未設定活動');

        $marketing = $response->viewData('socialEngagementTargets')[0]['marketing'];

        $this->assertSame('not_configured', $marketing['meta_marketing']['status']);
        $this->assertSame('not_configured', $marketing['google_ads']['status']);
        $this->assertSame('not_configured', $marketing['google_analytics']['status']);
    }

    public function test_dashboard_never_displays_marketing_reports_from_another_users_connections(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $reportOwner = User::factory()->create();
        $viewer = User::factory()->create();
        $target = $this->createTarget();
        $this->createGoogleAnalyticsReport($reportOwner, $target, ['sessions' => 98765, 'total_revenue' => '98765.4300']);
        $this->createMetaMarketingReport($reportOwner, $target, ['spend_amount' => '98765.4300']);
        $this->createGoogleAdsReport($reportOwner, $target, ['spend_amount' => '98765.4300']);
        $this->createGoogleAnalyticsConnection($viewer);
        $this->createMetaConnection($viewer);
        $this->createGoogleAdsConnection($viewer);

        $response = $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('98,765.43')
            ->assertDontSee('98,765 工作階段');

        $marketing = $response->viewData('socialEngagementTargets')[0]['marketing'];

        $this->assertSame('missing', $marketing['google_analytics']['status']);
        $this->assertSame('missing', $marketing['meta_marketing']['status']);
        $this->assertSame('missing', $marketing['google_ads']['status']);
        $this->assertNull($marketing['efficiency']['ad_spend_amount']);
        $this->assertNull($marketing['efficiency']['roas']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTarget(array $overrides = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create($overrides + [
            'tag' => 'Marketing overview campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_campaign_name' => 'Marketing overview campaign',
            'google_ads_campaign_id' => 'ads-123',
            'meta_campaign_id' => 'meta-123',
            'campaign_budget_amount' => '1000.0000',
            'campaign_budget_currency' => 'TWD',
        ]);
    }

    private function createPublicEngagementPost(SocialEngagementTarget $target, string $slug): Monitor
    {
        $url = match ($target->social_platform) {
            'instagram' => 'https://www.instagram.com/reel/'.$slug.'/',
            'threads' => 'https://www.threads.com/@brand/post/'.$slug,
            'dcard' => 'https://www.dcard.tw/f/marketing/p/'.$slug,
            default => 'https://www.facebook.com/posts/'.$slug,
        };
        $monitor = Monitor::factory()->create([
            'name' => 'Marketing overview post',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
            'label' => $target->tag,
            'expected_text' => 'Campaign announcement',
        ]);

        CheckRun::factory()->for($monitor)->create([
            'config_version' => $monitor->config_version,
            'status' => 'completed',
            'response_published_at' => now(),
            'response_public_engagement_metrics' => [
                'views' => 1000,
                'likes' => 80,
                'comments' => 10,
                'shares' => 5,
            ],
        ]);

        return $monitor;
    }

    /**
     * @param  array<string, mixed>  $googleAnalytics
     * @param  array<string, mixed>  $metaMarketing
     * @param  array<string, mixed>  $googleAds
     * @param  array<string, mixed>  $efficiency
     * @return array<string, mixed>
     */
    private function portfolioMarketingSummary(
        array $googleAnalytics = [],
        array $metaMarketing = [],
        array $googleAds = [],
        array $efficiency = [],
        array $publicEngagement = [],
    ): array {
        return [
            'google_analytics' => array_replace([
                'status' => 'not_configured',
                'revenue' => null,
                'currency_code' => null,
            ], $googleAnalytics),
            'meta_marketing' => array_replace([
                'status' => 'not_configured',
                'spend_amount' => null,
                'currency_code' => null,
            ], $metaMarketing),
            'google_ads' => array_replace([
                'status' => 'not_configured',
                'spend_amount' => null,
                'currency_code' => null,
            ], $googleAds),
            'public_engagement' => array_replace([
                'interaction_total' => null,
                'available_posts' => 0,
                'tracked_posts' => 0,
            ], $publicEngagement),
            'efficiency' => array_replace([
                'roas' => null,
                'roas_spend_amount' => null,
                'roas_spend_currency' => null,
                'roas_spend_source' => null,
            ], $efficiency),
        ];
    }

    private function createGoogleAnalyticsConnection(User $user): GoogleAnalyticsConnection
    {
        return GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'ga-refresh-token',
        ]);
    }

    private function createGoogleAnalyticsReport(User $user, SocialEngagementTarget $target, array $overrides = []): void
    {
        $connection = $this->createGoogleAnalyticsConnection($user);

        SocialEngagementTargetGoogleAnalyticsReport::query()->create($overrides + [
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'attribution_mode' => 'google_ads_auto_tagging',
            'campaign_id' => $target->google_ads_campaign_id,
            'source_name' => null,
            'medium_name' => null,
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
            'sessions' => 100,
            'engaged_sessions' => 80,
            'key_events' => '12.0000',
            'total_revenue' => '900.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createGoalGoogleAnalyticsReport(
        GoogleAnalyticsConnection $connection,
        SocialEngagementTarget $target,
        array $overrides = [],
    ): void {
        SocialEngagementTargetGoogleAnalyticsReport::query()->create(array_replace([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'attribution_mode' => $target->googleAnalyticsAttributionMode(),
            'campaign_id' => null,
            'source_name' => $target->googleAnalyticsSourceName(),
            'medium_name' => $target->googleAnalyticsMediumName(),
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
            'sessions' => 100,
            'engaged_sessions' => 80,
            'key_events' => '12.5000',
            'total_revenue' => '900.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ], $overrides));
    }

    private function createMetaConnection(User $user): MetaMarketingConnection
    {
        return MetaMarketingConnection::factory()->create([
            'user_id' => $user->id,
            'ad_account_id' => 'act_12345',
            'currency_code' => 'TWD',
        ]);
    }

    private function createMetaMarketingReport(User $user, SocialEngagementTarget $target, array $overrides = []): void
    {
        $connection = $this->createMetaConnection($user);

        SocialEngagementTargetMetaMarketingReport::query()->create($overrides + [
            'meta_marketing_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => $connection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Marketing overview Meta campaign',
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 50,
            'actions' => [],
            'action_values' => [],
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
    }

    private function createGoogleAdsConnection(User $user): GoogleAdsConnection
    {
        return GoogleAdsConnection::factory()->create([
            'user_id' => $user->id,
            'customer_id' => '1234567890',
            'currency_code' => 'TWD',
        ]);
    }

    private function createGoogleAdsReport(User $user, SocialEngagementTarget $target, array $overrides = []): void
    {
        $connection = $this->createGoogleAdsConnection($user);

        SocialEngagementTargetGoogleAdsReport::query()->create($overrides + [
            'google_ads_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => $connection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Marketing overview Google Ads campaign',
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
            'spend_amount' => '200.0000',
            'impressions' => 2000,
            'clicks' => 100,
            'conversions' => '10.0000',
            'conversions_value' => '300.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createGoogleAdsReportForConnection(
        GoogleAdsConnection $connection,
        SocialEngagementTarget $target,
        array $overrides = [],
    ): SocialEngagementTargetGoogleAdsReport {
        return SocialEngagementTargetGoogleAdsReport::query()->create(array_replace([
            'google_ads_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => $connection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => $target->tag,
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
            'spend_amount' => '700.0000',
            'impressions' => 2000,
            'clicks' => 100,
            'conversions' => '10.0000',
            'conversions_value' => '300.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ], $overrides));
    }
}
