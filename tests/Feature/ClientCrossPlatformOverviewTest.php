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

class ClientCrossPlatformOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 12:00:00');
    }

    public function test_overview_combines_platforms_once_per_ad_campaign_and_compares_with_previous_period(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $client = $user->clients()->create(['name' => '跨平台客戶']);
        $connections = $this->createConnections($user, $client);
        $mainCampaign = $this->createCampaign($client, '雙十一主檔', 'ads-main', 'meta-shared');
        $sideCampaign = $this->createCampaign($client, '雙十一加碼', 'ads-side', 'meta-shared');

        $this->createGoogleAdsReport($mainCampaign, $connections['google_ads'], [
            ['date' => '2026-11-03', 'spend_amount' => '50', 'impressions' => 500, 'clicks' => 10, 'conversions' => '1', 'conversions_value' => '100'],
            ['date' => '2026-11-05', 'spend_amount' => '100', 'impressions' => 1000, 'clicks' => 40, 'conversions' => '2', 'conversions_value' => '400'],
            ['date' => '2026-11-06', 'spend_amount' => '100', 'impressions' => 1000, 'clicks' => 40, 'conversions' => '2', 'conversions_value' => '400'],
        ]);
        $this->createGoogleAdsReport($sideCampaign, $connections['google_ads'], [
            ['date' => '2026-11-05', 'spend_amount' => '50', 'impressions' => 500, 'clicks' => 20, 'conversions' => '0', 'conversions_value' => '0'],
        ]);
        $sharedMetaRows = [
            $this->metaRow('2026-11-05', '60', 2000, 20, '1', '300'),
            $this->metaRow('2026-11-06', '40', 1500, 20, '1', '200'),
        ];
        $this->createMetaReport($mainCampaign, $connections['meta_marketing'], $sharedMetaRows);
        $this->createMetaReport($sideCampaign, $connections['meta_marketing'], $sharedMetaRows);
        $this->createGoogleAnalyticsReport($mainCampaign, $connections['google_analytics'], [
            ['date' => '2026-11-05', 'sessions' => 80, 'engaged_sessions' => 60, 'key_events' => '2', 'total_revenue' => '500'],
            ['date' => '2026-11-06', 'sessions' => 70, 'engaged_sessions' => 50, 'key_events' => '1', 'total_revenue' => '300'],
        ]);

        $response = $this->get(route('clients.cross-platform', ['client' => $client, 'from' => '2026-11-05', 'to' => '2026-11-06']))
            ->assertOk()
            ->assertSee('跨平台客戶｜跨平台總覽')
            ->assertSee('平台比較（TWD）')
            ->assertSee('重複歸因');

        $overview = $response->viewData('overview');
        $group = $overview['currency_groups'][0];
        $this->assertSame('TWD', $group['currency_code']);
        $this->assertEqualsWithDelta(250.0, $group['platforms']['google_ads']['spend'], 0.001);
        $this->assertSame(2, $group['platforms']['google_ads']['campaign_count']);
        $this->assertEqualsWithDelta(100.0, $group['platforms']['meta_marketing']['spend'], 0.001, 'The shared Meta campaign is counted once.');
        $this->assertEqualsWithDelta(2.0, $group['platforms']['meta_marketing']['conversions'], 0.001);
        $this->assertSame(1, $group['platforms']['meta_marketing']['campaign_count']);
        $this->assertSame(28.6, $group['platforms']['meta_marketing']['spend_share_percent']);
        $this->assertEqualsWithDelta(350.0, $group['total']['spend'], 0.001);
        $this->assertEqualsWithDelta(6.0, $group['total']['conversions'], 0.001);
        $this->assertSame(3.71, $group['total']['roas']);
        $this->assertSame(58.33, $group['total']['cost_per_conversion']);
        $this->assertSame(6000, $group['total']['impressions']);
        $this->assertSame(1, $overview['coverage']['meta_marketing']['duplicate_mappings_excluded']);

        $this->assertSame(150, $overview['ga4']['sessions']);
        $this->assertSame(['TWD' => 800.0], $overview['ga4']['revenue_groups']);
        $this->assertSame(2.0, $overview['attribution']['ratio']);
        $this->assertSame('platform_higher', $overview['attribution']['status']);

        $this->assertSame(['date' => '2026-11-05', 'google_ads_spend' => 150.0, 'meta_marketing_spend' => 60.0, 'conversions' => 3.0], $overview['daily'][0]);
        $this->assertCount(2, $response->viewData('chart')['bars']);

        $comparison = $response->viewData('comparison');
        $this->assertSame(['from' => '2026-11-03', 'to' => '2026-11-04'], $response->viewData('previousPeriod'));
        $this->assertSame(['delta' => 300.0, 'percent_change' => 600.0], $comparison['currency_groups']['TWD']['spend']);
        $this->assertNull($comparison['ga4']['sessions']);
    }

    public function test_overview_defaults_to_last_30_days_and_shows_empty_state_without_ad_data(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => '尚未投放客戶']);

        $response = $this->actingAs($user)
            ->get(route('clients.cross-platform', $client))
            ->assertOk()
            ->assertSee('此期間沒有廣告數據');

        $this->assertSame(['from' => '2026-10-11', 'to' => '2026-11-09'], $response->viewData('period'));
        $this->assertNull($response->viewData('chart'));
    }

    public function test_invalid_periods_are_rejected(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => '期間驗證客戶']);
        $this->actingAs($user);

        $this->get(route('clients.cross-platform', ['client' => $client, 'from' => '2026-07-01', 'to' => '2026-11-09']))
            ->assertRedirect(route('clients.cross-platform', $client))
            ->assertSessionHasErrors('from');
        $this->get(route('clients.cross-platform', ['client' => $client, 'from' => '2026-11-01', 'to' => '2026-11-10']))
            ->assertSessionHasErrors('to');
    }

    public function test_other_workspaces_cannot_open_the_overview(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '他人客戶']);

        $this->actingAs(User::factory()->create())
            ->get(route('clients.cross-platform', $client))
            ->assertNotFound();
        auth()->logout();
        $this->get(route('clients.cross-platform', $client))->assertRedirect(route('login'));
    }

    private function createCampaign(Client $client, string $tag, string $googleAdsCampaignId, string $metaCampaignId): SocialEngagementTarget
    {
        $campaign = SocialEngagementTarget::factory()->create([
            'client_id' => $client->getKey(),
            'tag' => $tag,
            'social_platform' => 'instagram',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_campaign_name' => $tag,
            'google_ads_campaign_id' => $googleAdsCampaignId,
            'meta_campaign_id' => $metaCampaignId,
        ]);
        $campaign->forceFill(['user_id' => $client->user_id])->save();

        return $campaign;
    }

    /** @return array{google_analytics: GoogleAnalyticsConnection, meta_marketing: MetaMarketingConnection, google_ads: GoogleAdsConnection} */
    private function createConnections(User $user, Client $client): array
    {
        $googleAnalytics = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->getKey(),
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-refresh-token',
        ]);
        GoogleAnalyticsClientProperty::query()->create([
            'google_analytics_connection_id' => $googleAnalytics->getKey(),
            'client_id' => $client->getKey(),
            'property_id' => '123456789',
        ]);
        $metaMarketing = MetaMarketingConnection::factory()->create([
            'user_id' => $user->getKey(),
            'ad_account_id' => '12345',
            'ad_account_name' => 'Cross platform Meta account',
            'currency_code' => 'TWD',
        ]);
        MetaMarketingClientAccount::withoutGlobalScopes()->create([
            'meta_marketing_connection_id' => $metaMarketing->getKey(),
            'client_id' => $client->getKey(),
            'ad_account_id' => '12345',
            'ad_account_name' => 'Cross platform Meta account',
            'currency_code' => 'TWD',
        ]);

        return [
            'google_analytics' => $googleAnalytics,
            'meta_marketing' => $metaMarketing,
            'google_ads' => GoogleAdsConnection::factory()->create([
                'user_id' => $user->getKey(),
                'customer_id' => '1234567890',
                'currency_code' => 'TWD',
            ]),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function createGoogleAdsReport(SocialEngagementTarget $campaign, GoogleAdsConnection $connection, array $rows): void
    {
        $this->mapGoogleAdsConnectionToTargetClient($connection, $campaign);

        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $campaign->getKey(),
            'customer_id' => $connection->customer_id,
            'campaign_id' => $campaign->google_ads_campaign_id,
            'campaign_name' => $campaign->tag,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'spend_amount' => '0',
            'impressions' => 0,
            'clicks' => 0,
            'conversions' => '0',
            'conversions_value' => '0',
            'currency_code' => 'TWD',
            'daily_metrics' => $this->dailyMetrics($rows),
            'synced_at' => now(),
        ]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function createMetaReport(SocialEngagementTarget $campaign, MetaMarketingConnection $connection, array $rows): void
    {
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $campaign->getKey(),
            'ad_account_id' => $connection->ad_account_id,
            'campaign_id' => $campaign->meta_campaign_id,
            'campaign_name' => $campaign->tag,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'spend_amount' => '0',
            'impressions' => 0,
            'reach' => 0,
            'clicks' => 0,
            'actions' => [],
            'action_values' => [],
            'currency_code' => 'TWD',
            'daily_metrics' => $this->dailyMetrics($rows),
            'synced_at' => now(),
        ]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function createGoogleAnalyticsReport(SocialEngagementTarget $campaign, GoogleAnalyticsConnection $connection, array $rows): void
    {
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->getKey(),
            'property_id' => $connection->property_id,
            'social_engagement_target_id' => $campaign->getKey(),
            'campaign_name' => $campaign->googleAnalyticsCampaignName(),
            'attribution_mode' => 'google_ads_auto_tagging',
            'campaign_id' => $campaign->google_ads_campaign_id,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'sessions' => 0,
            'engaged_sessions' => 0,
            'key_events' => '0',
            'total_revenue' => '0',
            'currency_code' => 'TWD',
            'daily_metrics' => $this->dailyMetrics($rows),
            'synced_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function metaRow(string $date, string $spend, int $impressions, int $clicks, string $purchases, string $purchaseValue): array
    {
        return [
            'date' => $date,
            'spend_amount' => $spend,
            'impressions' => $impressions,
            'reach' => $impressions,
            'clicks' => $clicks,
            'actions' => [['action_type' => 'omni_purchase', 'value' => $purchases]],
            'action_values' => [['action_type' => 'omni_purchase', 'value' => $purchaseValue]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function dailyMetrics(array $rows): array
    {
        return [
            'status' => 'complete',
            'requested_start_on' => '2026-11-01',
            'requested_end_on' => '2026-11-30',
            'coverage_start_on' => '2026-11-01',
            'coverage_end_on' => '2026-11-09',
            'rows' => $rows,
        ];
    }
}
