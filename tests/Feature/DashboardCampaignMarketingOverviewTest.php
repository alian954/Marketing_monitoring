<?php

namespace Tests\Feature;

use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
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
        ]);
        $this->createGoogleAdsReport($user, $target, [
            'spend_amount' => '200.0000',
        ]);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('活動行銷成效')
            ->assertSee('120 工作階段')
            ->assertSee('900.00')
            ->assertSee('300.00 TWD')
            ->assertSee('3.00x')
            ->assertSee('30.0%');

        $marketing = $response->viewData('socialEngagementTargets')[0]['marketing'];

        $this->assertSame('fresh', $marketing['google_analytics']['status']);
        $this->assertSame('fresh', $marketing['meta_marketing']['status']);
        $this->assertSame('fresh', $marketing['google_ads']['status']);
        $this->assertSame(120, $marketing['google_analytics']['sessions']);
        $this->assertSame('300.0000', $marketing['efficiency']['ad_spend_amount']);
        $this->assertSame(3.0, $marketing['efficiency']['roas']);
        $this->assertSame(30.0, $marketing['efficiency']['budget_utilization_percent']);
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

    public function test_dashboard_does_not_combine_spend_or_calculate_roas_across_currencies(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createGoogleAnalyticsReport($user, $target, ['total_revenue' => '900.0000']);
        $this->createMetaMarketingReport($user, $target, ['spend_amount' => '100.0000', 'currency_code' => 'TWD']);
        $this->createGoogleAdsReport($user, $target, ['spend_amount' => '200.0000', 'currency_code' => 'USD']);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('幣別不同，未合併')
            ->assertSee('廣告幣別不同');

        $efficiency = $response->viewData('socialEngagementTargets')[0]['marketing']['efficiency'];

        $this->assertTrue($efficiency['platform_currency_mismatch']);
        $this->assertNull($efficiency['ad_spend_amount']);
        $this->assertNull($efficiency['roas']);
        $this->assertSame('ad_platform_currency_mismatch', $efficiency['roas_unavailable_reason']);
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
}
