<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPortfolioTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_portfolio_requires_authentication(): void
    {
        $this->get(route('clients.performance'))
            ->assertRedirect(route('login'));
    }

    public function test_empty_client_portfolio_renders_without_marketing_connections(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('clients.performance'))
            ->assertOk()
            ->assertSee('行銷成效');

        $row = collect($response->viewData('clientPortfolioRows'))
            ->firstWhere('client.name', Client::DEFAULT_NAME);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['campaign_count']);
        $this->assertSame([], $row['money_groups']);
    }

    public function test_client_portfolio_converts_each_clients_marketing_metrics_to_its_reporting_currency(): void
    {
        $this->travelTo('2026-11-03 12:00:00');
        $user = User::factory()->create();
        $this->actingAs($user);

        $northwind = $user->clients()->create([
            'name' => 'Northwind',
            'reporting_currency_code' => 'TWD',
        ]);
        $contoso = $user->clients()->create([
            'name' => 'Contoso',
            'reporting_currency_code' => 'JPY',
        ]);
        $northwindCampaign = $this->createCampaign($northwind, 'Northwind launch');
        $contosoCampaign = $this->createCampaign($contoso, 'Contoso launch');
        $connections = $this->createConnections($user);
        $this->createCampaignReports($northwindCampaign, $connections, '10.0000', '2.0000', '3.0000');
        $this->createCampaignReports($contosoCampaign, $connections, '40.0000', '6.0000', '8.0000');

        $northwind->exchangeRates()->create([
            'source_currency_code' => 'USD',
            'reporting_currency_code' => 'TWD',
            'effective_on' => '2026-11-01',
            'rate_to_reporting_currency' => '30.00000000',
        ]);
        $contoso->exchangeRates()->create([
            'source_currency_code' => 'USD',
            'reporting_currency_code' => 'JPY',
            'effective_on' => '2026-11-01',
            'rate_to_reporting_currency' => '150.00000000',
        ]);

        $response = $this->get(route('clients.performance'))
            ->assertOk()
            ->assertSee('Northwind')
            ->assertSee('Contoso')
            ->assertSee('300.00 TWD')
            ->assertSee('60.00 TWD')
            ->assertSee('90.00 TWD')
            ->assertSee('Google Ads 13.33× (USD)')
            ->assertSee('6,000.00 JPY')
            ->assertSee('900.00 JPY')
            ->assertSee('1,200.00 JPY')
            ->assertSee('Google Ads 5.00× (USD)');

        $rows = collect($response->viewData('clientPortfolioRows'))->keyBy(
            static fn (array $row): string => $row['client']->name,
        );
        $northwindMetrics = $rows->get('Northwind')['money_groups'][0];
        $contosoMetrics = $rows->get('Contoso')['money_groups'][0];

        $this->assertSame(true, $rows->get('Northwind')['uses_reporting_currency']);
        $this->assertSame('TWD', $northwindMetrics['currency_code']);
        $this->assertSame(300.0, $northwindMetrics['ga4_revenue']);
        $this->assertSame(60.0, $northwindMetrics['meta_spend']);
        $this->assertSame(90.0, $northwindMetrics['google_ads_spend']);
        $this->assertSame('USD', $rows->get('Northwind')['roas_groups'][0]['currency_code']);
        $this->assertSame(13.3333, $rows->get('Northwind')['roas_groups'][0]['roas']);
        $this->assertSame(1, $rows->get('Northwind')['coverage']['google_analytics']['fresh_campaigns']);
        $this->assertSame('JPY', $contosoMetrics['currency_code']);
        $this->assertSame(6000.0, $contosoMetrics['ga4_revenue']);
        $this->assertSame(900.0, $contosoMetrics['meta_spend']);
        $this->assertSame(1200.0, $contosoMetrics['google_ads_spend']);
    }

    public function test_client_without_a_reporting_currency_keeps_source_currency_groups_separate(): void
    {
        $this->travelTo('2026-11-03 12:00:00');
        $user = User::factory()->create();
        $this->actingAs($user);

        $client = $user->clients()->create(['name' => '原幣別客戶']);
        $campaign = $this->createCampaign($client, 'USD campaign');
        $connections = $this->createConnections($user);
        $this->createCampaignReports($campaign, $connections, '25.0000', '0.0000', '0.0000');

        $response = $this->get(route('clients.performance'))
            ->assertOk()
            ->assertSee('原幣別客戶')
            ->assertSee('原始幣別')
            ->assertSee('25.00 USD');

        $rows = collect($response->viewData('clientPortfolioRows'))->keyBy(
            static fn (array $row): string => $row['client']->name,
        );
        $row = $rows->get('原幣別客戶');

        $this->assertFalse($row['uses_reporting_currency']);
        $this->assertSame('USD', $row['money_groups'][0]['currency_code']);
        $this->assertSame(25.0, $row['money_groups'][0]['ga4_revenue']);
    }

    public function test_missing_daily_exchange_rates_are_reported_without_showing_unconverted_totals(): void
    {
        $this->travelTo('2026-11-03 12:00:00');
        $user = User::factory()->create();
        $this->actingAs($user);

        $client = $user->clients()->create([
            'name' => '待補匯率客戶',
            'reporting_currency_code' => 'TWD',
        ]);
        $campaign = $this->createCampaign($client, 'USD campaign');
        $connections = $this->createConnections($user);
        $this->createCampaignReports($campaign, $connections, '25.0000', '5.0000', '10.0000');
        $dailyMissingCampaign = $this->createCampaign($client, 'Daily data missing');
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connections['google_analytics']->getKey(),
            'social_engagement_target_id' => $dailyMissingCampaign->getKey(),
            'campaign_name' => $dailyMissingCampaign->googleAnalyticsCampaignName(),
            'attribution_mode' => 'google_ads_auto_tagging',
            'campaign_id' => $dailyMissingCampaign->google_ads_campaign_id,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-01',
            'sessions' => 10,
            'engaged_sessions' => 8,
            'key_events' => '2.0000',
            'total_revenue' => '7.0000',
            'currency_code' => 'USD',
            'daily_metrics' => null,
            'synced_at' => now(),
        ]);

        $response = $this->get(route('clients.performance'))
            ->assertOk()
            ->assertDontSee('25.00 USD')
            ->assertDontSee('5.00 USD')
            ->assertDontSee('10.00 USD')
            ->assertDontSee('7.00 USD')
            ->assertSee('缺逐日資料 1');
        $this->assertSame(3, substr_count($response->getContent(), '缺匯率 1'));

        $rows = collect($response->viewData('clientPortfolioRows'))->keyBy(
            static fn (array $row): string => $row['client']->name,
        );
        $row = $rows->get('待補匯率客戶');
        $conversionCoverage = $row['money_groups'][0]['conversion_coverage'];

        $this->assertTrue($row['uses_reporting_currency']);
        $this->assertSame(0, $row['money_groups'][0]['ga4_campaign_count']);
        $this->assertSame(1, $conversionCoverage['google_analytics']['missing_rate_campaigns']);
        $this->assertSame(1, $conversionCoverage['google_analytics']['missing_daily_data_campaigns']);
    }

    public function test_selected_workspace_member_only_sees_assigned_clients_in_the_portfolio(): void
    {
        $owner = User::factory()->create();
        $selectedClient = $owner->clients()->create([
            'name' => '可見客戶',
            'reporting_currency_code' => 'TWD',
        ]);
        $hiddenClient = $owner->clients()->create([
            'name' => '隱藏客戶',
            'reporting_currency_code' => 'TWD',
        ]);
        $this->actingAs($owner);

        $selectedCampaign = $this->createCampaign($selectedClient, '可見活動');
        $hiddenCampaign = $this->createCampaign($hiddenClient, '隱藏活動');
        $connections = $this->createConnections($owner);
        $this->createCampaignReports($selectedCampaign, $connections, '10.0000', '2.0000', '3.0000');
        $this->createCampaignReports($hiddenCampaign, $connections, '1000.0000', '200.0000', '300.0000');
        $selectedClient->exchangeRates()->create([
            'source_currency_code' => 'USD',
            'reporting_currency_code' => 'TWD',
            'effective_on' => '2026-11-01',
            'rate_to_reporting_currency' => '30.00000000',
        ]);
        $hiddenClient->exchangeRates()->create([
            'source_currency_code' => 'USD',
            'reporting_currency_code' => 'TWD',
            'effective_on' => '2026-11-01',
            'rate_to_reporting_currency' => '30.00000000',
        ]);

        $member = User::factory()->create();
        $membership = WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_SELECTED,
        ]);
        $membership->clients()->sync([$selectedClient->id]);

        $response = $this->actingAs($member)
            ->get(route('clients.performance'))
            ->assertOk()
            ->assertSee('可見客戶')
            ->assertDontSee('隱藏客戶')
            ->assertDontSee('隱藏活動')
            ->assertSee('300.00 TWD')
            ->assertDontSee('30,000.00 TWD');

        $rows = $response->viewData('clientPortfolioRows');

        $this->assertCount(1, $rows);
        $this->assertSame($selectedClient->id, $rows->sole()['client']->id);
        $this->assertSame(300.0, $rows->sole()['money_groups'][0]['ga4_revenue']);
    }

    private function createCampaign(Client $client, string $tag): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create([
            'client_id' => $client->getKey(),
            'tag' => $tag,
            'social_platform' => 'instagram',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-01',
            'ga4_campaign_name' => $tag,
            'google_ads_campaign_id' => 'ads-'.$tag,
            'meta_campaign_id' => 'meta-'.$tag,
        ]);
    }

    /** @return array{google_analytics: GoogleAnalyticsConnection, meta_marketing: MetaMarketingConnection, google_ads: GoogleAdsConnection} */
    private function createConnections(User $user): array
    {
        return [
            'google_analytics' => GoogleAnalyticsConnection::query()->create([
                'user_id' => $user->getKey(),
                'property_id' => '123456789',
                'refresh_token' => 'encrypted-refresh-token',
            ]),
            'meta_marketing' => MetaMarketingConnection::factory()->create([
                'user_id' => $user->getKey(),
                'ad_account_id' => 'act_12345',
                'currency_code' => 'USD',
            ]),
            'google_ads' => GoogleAdsConnection::factory()->create([
                'user_id' => $user->getKey(),
                'customer_id' => '1234567890',
                'currency_code' => 'USD',
            ]),
        ];
    }

    /**
     * @param  array{google_analytics: GoogleAnalyticsConnection, meta_marketing: MetaMarketingConnection, google_ads: GoogleAdsConnection}  $connections
     */
    private function createCampaignReports(
        SocialEngagementTarget $campaign,
        array $connections,
        string $ga4Revenue,
        string $metaSpend,
        string $googleAdsSpend,
    ): void {
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connections['google_analytics']->getKey(),
            'social_engagement_target_id' => $campaign->getKey(),
            'campaign_name' => $campaign->googleAnalyticsCampaignName(),
            'attribution_mode' => 'google_ads_auto_tagging',
            'campaign_id' => $campaign->google_ads_campaign_id,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-01',
            'sessions' => 10,
            'engaged_sessions' => 8,
            'key_events' => '2.0000',
            'total_revenue' => $ga4Revenue,
            'currency_code' => 'USD',
            'daily_metrics' => $this->dailyMetrics([[
                'date' => '2026-11-01',
                'total_revenue' => $ga4Revenue,
            ]]),
            'synced_at' => now(),
        ]);

        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connections['meta_marketing']->getKey(),
            'social_engagement_target_id' => $campaign->getKey(),
            'ad_account_id' => $connections['meta_marketing']->ad_account_id,
            'campaign_id' => $campaign->meta_campaign_id,
            'campaign_name' => $campaign->tag,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-01',
            'spend_amount' => $metaSpend,
            'impressions' => 100,
            'reach' => 90,
            'clicks' => 10,
            'actions' => [],
            'action_values' => [],
            'currency_code' => 'USD',
            'daily_metrics' => $this->dailyMetrics([[
                'date' => '2026-11-01',
                'spend_amount' => $metaSpend,
            ]]),
            'synced_at' => now(),
        ]);

        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $connections['google_ads']->getKey(),
            'social_engagement_target_id' => $campaign->getKey(),
            'customer_id' => $connections['google_ads']->customer_id,
            'campaign_id' => $campaign->google_ads_campaign_id,
            'campaign_name' => $campaign->tag,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-01',
            'spend_amount' => $googleAdsSpend,
            'impressions' => 200,
            'clicks' => 20,
            'conversions' => '2.0000',
            'conversions_value' => '40.0000',
            'currency_code' => 'USD',
            'daily_metrics' => $this->dailyMetrics([[
                'date' => '2026-11-01',
                'spend_amount' => $googleAdsSpend,
            ]]),
            'synced_at' => now(),
        ]);
    }

    /** @param list<array<string, string>> $rows
     * @return array<string, mixed>
     */
    private function dailyMetrics(array $rows): array
    {
        return [
            'status' => 'complete',
            'requested_start_on' => '2026-11-01',
            'requested_end_on' => '2026-11-01',
            'coverage_start_on' => '2026-11-01',
            'coverage_end_on' => '2026-11-01',
            'rows' => $rows,
        ];
    }
}
