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

    public function test_campaign_report_calculates_costs_and_roas_from_ga4_and_complete_public_interactions(): void
    {
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
            'response_published_at' => '2026-11-05T08:00:00Z',
            'response_public_engagement_metrics' => [
                'likes' => 100,
                'comments' => 20,
                'shares' => 5,
                'views' => 1000,
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
            ->assertSee('0.8000 TWD')
            ->assertSee('ROAS 為同幣別 GA4 活動營收除以投放花費');

        $analysis = $response->viewData('adSpendAnalysis');
        $this->assertSame(6.5, $analysis['roas']);
        $this->assertSame(0.5, $analysis['cost_per_session']);
        $this->assertSame(10.0, $analysis['cost_per_key_event']);
        $this->assertSame(0.8, $analysis['cost_per_public_interaction']);
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
