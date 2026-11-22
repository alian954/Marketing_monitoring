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
use App\Services\CampaignMarketingEfficiencyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementTargetAdSpendTest extends TestCase
{
    use RefreshDatabase;

    public function test_ad_spend_update_requires_authentication(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->put(route('social-engagement-targets.ad-spend.update', $target), [
            'ad_spend_amount' => '100',
            'ad_spend_currency' => 'TWD',
        ])->assertRedirect(route('login'));
    }

    public function test_ad_spend_update_normalizes_currency_and_saves_amount(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put(route('social-engagement-targets.ad-spend.update', $target), [
                'ad_spend_amount' => '1234.5678',
                'ad_spend_currency' => ' twd ',
            ])
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', '活動投放花費已儲存。');

        $this->assertDatabaseHas('social_engagement_targets', [
            'id' => $target->id,
            'ad_spend_amount' => '1234.5678',
            'ad_spend_currency' => 'TWD',
        ]);
    }

    public function test_ad_spend_can_be_cleared_by_submitting_both_fields_blank(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'ad_spend_amount' => '500.0000',
            'ad_spend_currency' => 'USD',
        ]);

        $this->actingAs(User::factory()->create())
            ->put(route('social-engagement-targets.ad-spend.update', $target), [
                'ad_spend_amount' => '',
                'ad_spend_currency' => '',
            ])
            ->assertRedirect(route('social-engagement-targets.report', $target));

        $this->assertDatabaseHas('social_engagement_targets', [
            'id' => $target->id,
            'ad_spend_amount' => null,
            'ad_spend_currency' => null,
        ]);
    }

    public function test_ad_spend_requires_amount_and_currency_together_and_rejects_invalid_values(): void
    {
        $target = SocialEngagementTarget::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach ([
            [['ad_spend_amount' => '100', 'ad_spend_currency' => ''], ['ad_spend_currency']],
            [['ad_spend_amount' => '', 'ad_spend_currency' => 'TWD'], ['ad_spend_amount']],
            [['ad_spend_amount' => '-1', 'ad_spend_currency' => 'TWD'], ['ad_spend_amount']],
            [['ad_spend_amount' => '10.12345', 'ad_spend_currency' => 'TWD'], ['ad_spend_amount']],
            [['ad_spend_amount' => '10', 'ad_spend_currency' => 'TWD1'], ['ad_spend_currency']],
        ] as [$payload, $errorFields]) {
            $this->put(route('social-engagement-targets.ad-spend.update', $target), $payload)
                ->assertSessionHasErrorsIn('socialEngagementSpend', $errorFields);
        }

        $this->assertDatabaseHas('social_engagement_targets', [
            'id' => $target->id,
            'ad_spend_amount' => null,
            'ad_spend_currency' => null,
        ]);
    }

    public function test_campaign_budget_update_requires_authentication(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->put(route('social-engagement-targets.budget.update', $target), [
            'campaign_budget_amount' => '1000',
            'campaign_budget_currency' => 'TWD',
        ])->assertRedirect(route('login'));
    }

    public function test_campaign_budget_update_normalizes_currency_and_saves_amount(): void
    {
        $target = SocialEngagementTarget::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put(route('social-engagement-targets.budget.update', $target), [
                'campaign_budget_amount' => '1234.5678',
                'campaign_budget_currency' => ' twd ',
            ])
            ->assertRedirect(route('social-engagement-targets.report', $target))
            ->assertSessionHas('status', '活動總預算已儲存。');

        $this->assertDatabaseHas('social_engagement_targets', [
            'id' => $target->id,
            'campaign_budget_amount' => '1234.5678',
            'campaign_budget_currency' => 'TWD',
        ]);
    }

    public function test_campaign_budget_can_be_cleared_by_submitting_both_fields_blank(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'campaign_budget_amount' => '500.0000',
            'campaign_budget_currency' => 'USD',
        ]);

        $this->actingAs(User::factory()->create())
            ->put(route('social-engagement-targets.budget.update', $target), [
                'campaign_budget_amount' => '',
                'campaign_budget_currency' => '',
            ])
            ->assertRedirect(route('social-engagement-targets.report', $target));

        $this->assertDatabaseHas('social_engagement_targets', [
            'id' => $target->id,
            'campaign_budget_amount' => null,
            'campaign_budget_currency' => null,
        ]);
    }

    public function test_campaign_budget_requires_amount_and_currency_together_and_rejects_invalid_values(): void
    {
        $target = SocialEngagementTarget::factory()->create();
        $this->actingAs(User::factory()->create());

        foreach ([
            [['campaign_budget_amount' => '1000', 'campaign_budget_currency' => ''], ['campaign_budget_currency']],
            [['campaign_budget_amount' => '', 'campaign_budget_currency' => 'TWD'], ['campaign_budget_amount']],
            [['campaign_budget_amount' => '-1', 'campaign_budget_currency' => 'TWD'], ['campaign_budget_amount']],
            [['campaign_budget_amount' => '10.12345', 'campaign_budget_currency' => 'TWD'], ['campaign_budget_amount']],
            [['campaign_budget_amount' => '10', 'campaign_budget_currency' => 'TWD1'], ['campaign_budget_currency']],
        ] as [$payload, $errorFields]) {
            $this->put(route('social-engagement-targets.budget.update', $target), $payload)
                ->assertSessionHasErrorsIn('socialEngagementBudget', $errorFields);
        }

        $this->assertDatabaseHas('social_engagement_targets', [
            'id' => $target->id,
            'campaign_budget_amount' => null,
            'campaign_budget_currency' => null,
        ]);
    }

    public function test_campaign_budget_report_shows_pacing_and_spend_projection(): void
    {
        $this->travelTo('2026-11-05 12:00:00');
        $target = SocialEngagementTarget::factory()->make([
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-10',
            'campaign_budget_amount' => '1000.0000',
            'campaign_budget_currency' => 'TWD',
        ]);
        $metaReport = new SocialEngagementTargetMetaMarketingReport;
        $metaReport->forceFill([
            'spend_amount' => '600.0000',
            'currency_code' => 'TWD',
        ]);

        $analysis = app(CampaignMarketingEfficiencyReport::class)->build($target, null, null, $metaReport);

        $this->assertSame(60.0, $analysis['budget_utilization_percent']);
        $this->assertSame(50.0, $analysis['budget_elapsed_percent']);
        $this->assertSame(500.0, $analysis['budget_expected_spend_amount']);
        $this->assertSame(100.0, $analysis['budget_pacing_difference_amount']);
        $this->assertSame(1200.0, $analysis['budget_projected_spend_amount']);
        $this->assertSame(200.0, $analysis['budget_projected_overrun_amount']);
        $this->assertSame('in_progress', $analysis['budget_status']);
    }

    public function test_campaign_budget_projection_is_unavailable_for_currency_or_period_mismatch(): void
    {
        $this->travelTo('2026-11-05 12:00:00');
        $target = SocialEngagementTarget::factory()->make([
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-10',
            'campaign_budget_amount' => '1000.0000',
            'campaign_budget_currency' => 'USD',
        ]);
        $metaReport = new SocialEngagementTargetMetaMarketingReport;
        $metaReport->forceFill(['spend_amount' => '600.0000', 'currency_code' => 'TWD']);

        $currencyMismatch = app(CampaignMarketingEfficiencyReport::class)->build($target, null, null, $metaReport);
        $this->assertNull($currencyMismatch['budget_utilization_percent']);
        $this->assertNull($currencyMismatch['budget_projected_spend_amount']);
        $this->assertSame('currency_mismatch', $currencyMismatch['budget_status']);

        $target->starts_on = null;
        $target->ends_on = null;
        $target->campaign_budget_currency = 'TWD';

        $missingPeriod = app(CampaignMarketingEfficiencyReport::class)->build($target, null, null, $metaReport);
        $this->assertNull($missingPeriod['budget_elapsed_percent']);
        $this->assertNull($missingPeriod['budget_expected_spend_amount']);
        $this->assertNull($missingPeriod['budget_projected_spend_amount']);
        $this->assertSame('missing_campaign_period', $missingPeriod['budget_status']);
    }

    public function test_campaign_report_calculates_costs_and_roas_from_ga4_and_complete_public_interactions(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Spring launch',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ad_spend_amount' => '100.0000',
            'ad_spend_currency' => 'TWD',
            'campaign_budget_amount' => '500.0000',
            'campaign_budget_currency' => 'TWD',
        ]);
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => 'Spring launch',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 200,
            'engaged_sessions' => 150,
            'key_events' => '10.0000',
            'total_revenue' => '650.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
        $monitor = Monitor::factory()->create([
            'name' => '活動貼文',
            'url' => 'https://www.facebook.com/share/p/EFFICIENCY001/',
            'url_hash' => Monitor::fingerprintUrl('https://www.facebook.com/share/p/EFFICIENCY001/'),
            'label' => 'Spring launch',
        ]);
        CheckRun::factory()->for($monitor)->create([
            'finished_at' => '2026-11-05 09:00:00',
            'response_published_at' => '2026-11-05T08:00:00Z',
            'response_public_engagement_metrics' => [
                'likes' => 100,
                'comments' => 20,
                'shares' => 5,
                'views' => 1000,
                'source' => 'json_ld',
            ],
        ]);
        CheckRun::factory()->for($monitor)->create([
            'finished_at' => '2026-11-21 09:00:00',
            'response_published_at' => '2026-11-05T08:00:00Z',
            'response_public_engagement_metrics' => [
                'likes' => 130,
                'comments' => 30,
                'shares' => 15,
                'views' => 1200,
                'source' => 'json_ld',
            ],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('投放成本效率')
            ->assertSee('6.50x')
            ->assertSee('0.5000 TWD')
            ->assertSee('10.0000 TWD')
            ->assertSee('0.5714 TWD')
            ->assertSee('活動期間每次淨增互動成本')
            ->assertSee('2.0000 TWD')
            ->assertSee('淨增互動 50 · 可比較 1 篇')
            ->assertSee('下降的數量會抵銷增量')
            ->assertSee('活動預算進度')
            ->assertSee('依目前進度推估總花費')
            ->assertSee('20.00%')
            ->assertSee('GA4 ROAS 與每工作階段／重要事件成本使用符合 GA4 歸因的單一來源花費');

        $analysis = $response->viewData('adSpendAnalysis');
        $this->assertSame(6.5, $analysis['roas']);
        $this->assertSame(0.5, $analysis['cost_per_session']);
        $this->assertSame(10.0, $analysis['cost_per_key_event']);
        $this->assertSame(0.5714, $analysis['cost_per_public_interaction']);
        $this->assertSame(50, $analysis['observed_campaign_interaction_change']);
        $this->assertSame(1, $analysis['comparable_interaction_post_count']);
        $this->assertSame(2.0, $analysis['cost_per_campaign_interaction_change']);
        $this->assertSame(20.0, $analysis['budget_utilization_percent']);
        $this->assertSame(73.33, $analysis['budget_elapsed_percent']);
    }

    public function test_currency_mismatch_hides_roas_while_zero_spend_does_not_divide_by_zero(): void
    {
        $report = new SocialEngagementTargetGoogleAnalyticsReport;
        $report->forceFill([
            'sessions' => 200,
            'key_events' => '10.0000',
            'total_revenue' => '650.0000',
            'currency_code' => 'USD',
        ]);
        $target = SocialEngagementTarget::factory()->make([
            'ad_spend_amount' => '100.0000',
            'ad_spend_currency' => 'TWD',
        ]);
        $service = app(CampaignMarketingEfficiencyReport::class);

        $mismatch = $service->build($target, $report, null);
        $this->assertNull($mismatch['roas']);
        $this->assertSame('currency_mismatch', $mismatch['roas_unavailable_reason']);
        $this->assertSame(0.5, $mismatch['cost_per_session']);
        $this->assertSame(10.0, $mismatch['cost_per_key_event']);
        $this->assertNull($mismatch['cost_per_public_interaction']);

        $target->ad_spend_amount = '0.0000';
        $zeroSpend = $service->build($target, $report, 25);
        $this->assertNull($zeroSpend['roas']);
        $this->assertSame('zero_spend', $zeroSpend['roas_unavailable_reason']);
        $this->assertSame(0.0, $zeroSpend['cost_per_session']);
        $this->assertSame(0.0, $zeroSpend['cost_per_key_event']);
        $this->assertSame(0.0, $zeroSpend['cost_per_public_interaction']);

        $target->ad_spend_amount = '100.0000';
        $zeroDenominators = $report->newInstance([
            'sessions' => 0,
            'key_events' => '0.0000',
            'total_revenue' => '0.0000',
            'currency_code' => 'TWD',
        ]);
        $unavailableCosts = $service->build($target, $zeroDenominators, 0);
        $this->assertSame(0.0, $unavailableCosts['roas']);
        $this->assertNull($unavailableCosts['cost_per_session']);
        $this->assertNull($unavailableCosts['cost_per_key_event']);
        $this->assertNull($unavailableCosts['cost_per_public_interaction']);
    }

    public function test_campaign_interaction_cost_uses_net_growth_and_requires_comparable_reads(): void
    {
        $target = SocialEngagementTarget::factory()->make([
            'ad_spend_amount' => '120.0000',
            'ad_spend_currency' => 'TWD',
        ]);
        $service = app(CampaignMarketingEfficiencyReport::class);

        $growing = $service->build(
            $target,
            null,
            100,
            observedCampaignInteractionChange: 30,
            comparableInteractionPostCount: 4,
        );
        $this->assertSame(30, $growing['observed_campaign_interaction_change']);
        $this->assertSame(4, $growing['comparable_interaction_post_count']);
        $this->assertSame(4.0, $growing['cost_per_campaign_interaction_change']);

        $unchanged = $service->build($target, null, 100, observedCampaignInteractionChange: 0, comparableInteractionPostCount: 2);
        $this->assertNull($unchanged['cost_per_campaign_interaction_change']);

        $declining = $service->build($target, null, 100, observedCampaignInteractionChange: -5, comparableInteractionPostCount: 1);
        $this->assertNull($declining['cost_per_campaign_interaction_change']);

        $withoutPairs = $service->build($target, null, 100);
        $this->assertNull($withoutPairs['observed_campaign_interaction_change']);
        $this->assertSame(0, $withoutPairs['comparable_interaction_post_count']);
        $this->assertNull($withoutPairs['cost_per_campaign_interaction_change']);
    }

    public function test_campaign_report_displays_channel_specific_ad_efficiency_metrics(): void
    {
        $user = User::factory()->create();
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Efficiency campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'meta_campaign_id' => '123456789',
            'google_ads_campaign_id' => '987654321',
        ]);
        $metaConnection = MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'meta-token',
            'ad_account_id' => '123456789',
            'currency_code' => 'TWD',
        ]);
        $googleAdsConnection = GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'google-refresh-token',
            'customer_id' => '1234567890',
            'currency_code' => 'USD',
        ]);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $metaConnection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => $metaConnection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Meta efficiency campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '100.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 40,
            'currency_code' => 'TWD',
            'synced_at' => now(),
        ]);
        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $googleAdsConnection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => $googleAdsConnection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Google efficiency campaign',
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'spend_amount' => '200.0000',
            'impressions' => 4000,
            'clicks' => 300,
            'conversions' => '15.0000',
            'conversions_value' => '600.0000',
            'currency_code' => 'USD',
            'synced_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Meta Ads CTR')
            ->assertSee('Meta Ads CPC')
            ->assertSee('Meta Ads CPM')
            ->assertSee('1.25 次／人')
            ->assertSee('Google Ads CTR')
            ->assertSee('Google Ads CPC')
            ->assertSee('Google Ads CPM')
            ->assertSee('Google Ads 每次轉換成本')
            ->assertSee('Google Ads 點擊轉換率')
            ->assertSee('4.00%')
            ->assertSee('2.5000 TWD')
            ->assertSee('100.0000 TWD')
            ->assertSee('7.50%')
            ->assertSee('0.6667 USD')
            ->assertSee('50.0000 USD')
            ->assertSee('13.3333 USD')
            ->assertSee('5.00%');

        $analysis = $response->viewData('adSpendAnalysis');
        $this->assertNull($analysis['ad_spend_amount']);
        $this->assertTrue($analysis['platform_currency_mismatch']);
        $this->assertSame(4.0, $analysis['meta_ads_ctr_percent']);
        $this->assertSame(1.25, $analysis['meta_ads_frequency']);
        $this->assertSame(2.5, $analysis['meta_ads_cost_per_click']);
        $this->assertSame(100.0, $analysis['meta_ads_cost_per_thousand_impressions']);
        $this->assertSame(7.5, $analysis['google_ads_ctr_percent']);
        $this->assertSame(0.6667, $analysis['google_ads_cost_per_click']);
        $this->assertSame(50.0, $analysis['google_ads_cost_per_thousand_impressions']);
        $this->assertSame(13.3333, $analysis['google_ads_cost_per_conversion']);
        $this->assertSame(5.0, $analysis['google_ads_conversion_rate_percent']);
    }

    public function test_ad_efficiency_metrics_are_unavailable_when_their_denominators_are_zero(): void
    {
        $metaReport = new SocialEngagementTargetMetaMarketingReport;
        $metaReport->forceFill([
            'spend_amount' => '100.0000',
            'impressions' => 0,
            'clicks' => 0,
        ]);
        $googleAdsReport = new SocialEngagementTargetGoogleAdsReport;
        $googleAdsReport->forceFill([
            'spend_amount' => '100.0000',
            'impressions' => 0,
            'clicks' => 0,
            'conversions' => '0.0000',
        ]);

        $analysis = app(CampaignMarketingEfficiencyReport::class)->build(
            SocialEngagementTarget::factory()->make(),
            null,
            null,
            $metaReport,
            $googleAdsReport,
        );

        foreach ([
            'meta_ads_ctr_percent',
            'meta_ads_frequency',
            'meta_ads_cost_per_click',
            'meta_ads_cost_per_thousand_impressions',
            'google_ads_ctr_percent',
            'google_ads_cost_per_click',
            'google_ads_cost_per_thousand_impressions',
            'google_ads_cost_per_conversion',
            'google_ads_conversion_rate_percent',
        ] as $metric) {
            $this->assertNull($analysis[$metric]);
        }
    }
}
