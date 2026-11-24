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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientPortfolioTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_portfolio_requires_authentication(): void
    {
        $this->get(route('clients.performance'))
            ->assertRedirect(route('login'));

        $this->get(route('clients.performance.export'))
            ->assertRedirect(route('login'));
    }

    public function test_empty_client_portfolio_renders_without_marketing_connections(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('clients.performance'))
            ->assertOk()
            ->assertSee('行銷成效')
            ->assertSee(route('clients.performance.export'), false);

        $row = collect($response->viewData('clientPortfolioRows'))
            ->firstWhere('client.name', Client::DEFAULT_NAME);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['campaign_count']);
        $this->assertSame([], $row['money_groups']);
    }

    public function test_client_portfolio_defaults_to_the_last_30_complete_taipei_days(): void
    {
        $this->travelTo('2026-11-24 12:00:00');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('clients.performance'))
            ->assertOk()
            ->assertSee('2026/10/25–2026/11/23');

        $this->assertSame([
            'from' => '2026-10-25',
            'to' => '2026-11-23',
        ], $response->viewData('reportPeriod'));
    }

    public function test_client_portfolio_search_filters_page_and_csv_to_matching_clients(): void
    {
        $this->travelTo('2026-11-24 12:00:00');
        $user = User::factory()->create();
        $user->clients()->create(['name' => 'Northwind Studio']);
        $user->clients()->create(['name' => 'Contoso Media']);
        $this->actingAs($user);

        $response = $this->get(route('clients.performance', ['search' => 'Northwind']))
            ->assertOk()
            ->assertSee('Northwind Studio')
            ->assertDontSee('Contoso Media')
            ->assertSee('name="search"', false)
            ->assertSee('value="Northwind"', false);

        $this->assertSame(['Northwind Studio'], $response->viewData('clientPortfolioRows')
            ->map(static fn (array $row): string => $row['client']->name)
            ->all());

        $csvRows = $this->csvRows(
            $this->get(route('clients.performance.export', ['search' => 'Northwind']))
                ->assertOk()
                ->streamedContent(),
        );

        $this->assertSame(['Northwind Studio'], array_column($csvRows, '客戶'));
    }

    public function test_client_portfolio_search_rejects_terms_longer_than_80_characters(): void
    {
        $user = User::factory()->create();
        $search = str_repeat('x', 81);

        $this->actingAs($user)
            ->get(route('clients.performance', ['search' => $search]))
            ->assertRedirect(route('clients.performance'))
            ->assertSessionHasErrors(['search' => '客戶搜尋不可超過 80 個字元。']);
    }

    public function test_portfolio_and_csv_use_the_same_period_and_daily_exchange_rates(): void
    {
        $this->travelTo('2026-11-04 12:00:00');
        $user = User::factory()->create();
        $this->actingAs($user);

        $client = $user->clients()->create([
            'name' => 'Northwind',
            'reporting_currency_code' => 'TWD',
        ]);
        $campaign = $this->createCampaign($client, 'Northwind launch', '2026-11-01', '2026-11-04');
        $connections = $this->createConnections($user);
        $this->createCampaignReports($campaign, $connections, '200.0000', '20.0000', '50.0000');
        $outsidePeriodCampaign = $this->createCampaign($client, 'Completed before period', '2026-11-01', '2026-11-01');
        $this->createCampaignReports($outsidePeriodCampaign, $connections, '1000.0000', '2000.0000', '3000.0000');

        SocialEngagementTargetGoogleAnalyticsReport::query()
            ->where('social_engagement_target_id', $campaign->getKey())
            ->firstOrFail()
            ->update([
                'daily_metrics' => $this->dailyMetrics([
                    ['date' => '2026-11-02', 'total_revenue' => '20.0000'],
                    ['date' => '2026-11-03', 'total_revenue' => '30.0000'],
                ], '2026-11-01', '2026-11-04', 'partial', '2026-11-02', '2026-11-03'),
            ]);
        SocialEngagementTargetMetaMarketingReport::query()
            ->where('social_engagement_target_id', $campaign->getKey())
            ->firstOrFail()
            ->update([
                'action_values' => [['action_type' => 'omni_purchase', 'value' => '100.0000']],
                'daily_metrics' => $this->dailyMetrics([
                    ['date' => '2026-11-01', 'spend_amount' => '2.0000', 'action_values' => [['action_type' => 'omni_purchase', 'value' => '10.0000']]],
                    ['date' => '2026-11-02', 'spend_amount' => '4.0000', 'action_values' => [['action_type' => 'omni_purchase', 'value' => '20.0000']]],
                    ['date' => '2026-11-03', 'spend_amount' => '6.0000', 'action_values' => [['action_type' => 'omni_purchase', 'value' => '30.0000']]],
                    ['date' => '2026-11-04', 'spend_amount' => '8.0000', 'action_values' => [['action_type' => 'omni_purchase', 'value' => '40.0000']]],
                ], '2026-11-01', '2026-11-04'),
            ]);
        SocialEngagementTargetGoogleAdsReport::query()
            ->where('social_engagement_target_id', $campaign->getKey())
            ->firstOrFail()
            ->update([
                'conversions_value' => '100.0000',
                'daily_metrics' => $this->dailyMetrics([
                    ['date' => '2026-11-01', 'spend_amount' => '5.0000', 'conversions_value' => '10.0000'],
                    ['date' => '2026-11-02', 'spend_amount' => '10.0000', 'conversions_value' => '20.0000'],
                    ['date' => '2026-11-03', 'spend_amount' => '15.0000', 'conversions_value' => '30.0000'],
                    ['date' => '2026-11-04', 'spend_amount' => '20.0000', 'conversions_value' => '40.0000'],
                ], '2026-11-01', '2026-11-04'),
            ]);
        $client->exchangeRates()->createMany([
            [
                'source_currency_code' => 'USD',
                'reporting_currency_code' => 'TWD',
                'effective_on' => '2026-11-02',
                'rate_to_reporting_currency' => '30.00000000',
            ],
            [
                'source_currency_code' => 'USD',
                'reporting_currency_code' => 'TWD',
                'effective_on' => '2026-11-03',
                'rate_to_reporting_currency' => '32.00000000',
            ],
        ]);

        $period = ['from' => '2026-11-02', 'to' => '2026-11-03'];
        $response = $this->get(route('clients.performance', $period))
            ->assertOk()
            ->assertSee('1,560.00 TWD')
            ->assertSee('312.00 TWD')
            ->assertSee('780.00 TWD')
            ->assertSee('Meta Ads 5.00× (USD)')
            ->assertSee('Google Ads 2.00× (USD)');

        $this->assertSame($period, $response->viewData('reportPeriod'));
        $portfolioRows = collect($response->viewData('clientPortfolioRows'))->keyBy(
            static fn (array $row): string => $row['client']->name,
        );
        $this->assertSame(1, $portfolioRows->get('Northwind')['campaign_count']);

        $csvRows = collect($this->csvRows(
            $this->get(route('clients.performance.export', $period))
                ->assertOk()
                ->assertHeader('content-disposition', 'attachment; filename=client-performance-2026-11-02-to-2026-11-03.csv')
                ->streamedContent(),
        ))->keyBy('客戶');
        $row = $csvRows->get('Northwind');

        $this->assertSame('2026-11-02 至 2026-11-03', $row['報表期間']);
        $this->assertSame('1', $row['活動數']);
        $this->assertSame('1560.00 TWD', $row['GA4 營收']);
        $this->assertSame('312.00 TWD', $row['Meta Ads 花費']);
        $this->assertSame('780.00 TWD', $row['Google Ads 花費']);
        $this->assertSame('Google Ads 2.00× (USD)；Meta Ads 5.00× (USD)', $row['平台 ROAS']);
    }

    public function test_period_with_incomplete_daily_coverage_does_not_show_full_period_totals(): void
    {
        $this->travelTo('2026-11-04 12:00:00');
        $user = User::factory()->create();
        $this->actingAs($user);

        $client = $user->clients()->create([
            'name' => 'Coverage gap client',
            'reporting_currency_code' => 'TWD',
        ]);
        $campaign = $this->createCampaign($client, 'Coverage gap campaign', '2026-11-01', '2026-11-04');
        $connections = $this->createConnections($user);
        $this->createCampaignReports($campaign, $connections, '100.0000', '20.0000', '50.0000');
        SocialEngagementTargetGoogleAnalyticsReport::query()
            ->where('social_engagement_target_id', $campaign->getKey())
            ->firstOrFail()
            ->update([
                'daily_metrics' => $this->dailyMetrics([
                    ['date' => '2026-11-02', 'total_revenue' => '20.0000'],
                    ['date' => '2026-11-03', 'total_revenue' => '30.0000'],
                    ['date' => '2026-11-04', 'total_revenue' => '50.0000'],
                ], '2026-11-01', '2026-11-04', 'partial', '2026-11-02', '2026-11-04'),
            ]);
        SocialEngagementTargetMetaMarketingReport::query()
            ->where('social_engagement_target_id', $campaign->getKey())
            ->firstOrFail()
            ->update([
                'daily_metrics' => $this->dailyMetrics([
                    ['date' => '2026-11-02', 'spend_amount' => '4.0000', 'action_values' => [['action_type' => 'omni_purchase', 'value' => '20.0000']]],
                    ['date' => '2026-11-03', 'spend_amount' => '6.0000', 'action_values' => [['action_type' => 'omni_purchase', 'value' => '30.0000']]],
                    ['date' => '2026-11-04', 'spend_amount' => '8.0000', 'action_values' => [['action_type' => 'omni_purchase', 'value' => '40.0000']]],
                ], '2026-11-01', '2026-11-04', 'partial', '2026-11-02', '2026-11-04'),
            ]);
        SocialEngagementTargetGoogleAdsReport::query()
            ->where('social_engagement_target_id', $campaign->getKey())
            ->firstOrFail()
            ->update([
                'daily_metrics' => $this->dailyMetrics([
                    ['date' => '2026-11-02', 'spend_amount' => '10.0000', 'conversions_value' => '20.0000'],
                    ['date' => '2026-11-03', 'spend_amount' => '15.0000', 'conversions_value' => '30.0000'],
                    ['date' => '2026-11-04', 'spend_amount' => '20.0000', 'conversions_value' => '40.0000'],
                ], '2026-11-01', '2026-11-04', 'partial', '2026-11-02', '2026-11-04'),
            ]);

        $period = ['from' => '2026-11-01', 'to' => '2026-11-03'];
        $response = $this->get(route('clients.performance', $period))
            ->assertOk()
            ->assertSee('GA4 期間資料不足 1')
            ->assertSee('Meta 期間資料不足 1')
            ->assertSee('Google Ads 期間資料不足 1')
            ->assertDontSee('3,000.00 TWD')
            ->assertDontSee('600.00 TWD')
            ->assertDontSee('1,500.00 TWD');
        $row = collect($response->viewData('clientPortfolioRows'))->firstWhere('client.name', 'Coverage gap client');

        $this->assertTrue($row['uses_reporting_currency']);
        $this->assertSame([], $row['money_groups']);
        $this->assertSame(1, $row['coverage']['google_analytics']['missing_campaigns']);
        $this->assertSame(1, $row['coverage']['meta_marketing']['missing_campaigns']);
        $this->assertSame(1, $row['coverage']['google_ads']['missing_campaigns']);

        $csvRows = collect($this->csvRows(
            $this->get(route('clients.performance.export', $period))->assertOk()->streamedContent(),
        ))->keyBy('客戶');
        $csvRow = $csvRows->get('Coverage gap client');
        $this->assertSame('TWD', $csvRow['報表幣別']);
        $this->assertSame('', $csvRow['GA4 營收']);
        $this->assertSame('', $csvRow['Meta Ads 花費']);
        $this->assertSame('', $csvRow['Google Ads 花費']);
        $this->assertStringContainsString('GA4 期間資料不足 1', $csvRow['資料缺漏']);
        $this->assertStringContainsString('Meta 期間資料不足 1', $csvRow['資料缺漏']);
        $this->assertStringContainsString('Google Ads 期間資料不足 1', $csvRow['資料缺漏']);
    }

    public function test_complete_daily_coverage_with_no_activity_is_reported_as_zero(): void
    {
        $this->travelTo('2026-11-03 12:00:00');
        $user = User::factory()->create();
        $this->actingAs($user);

        $client = $user->clients()->create([
            'name' => 'Zero activity client',
            'reporting_currency_code' => 'TWD',
        ]);
        $campaign = $this->createCampaign($client, 'Zero activity campaign');
        $connections = $this->createConnections($user);
        $this->createCampaignReports($campaign, $connections, '25.0000', '5.0000', '10.0000');
        $dailyMetrics = $this->dailyMetrics([], '2026-11-01', '2026-11-01');

        SocialEngagementTargetGoogleAnalyticsReport::query()
            ->where('social_engagement_target_id', $campaign->getKey())
            ->firstOrFail()
            ->update(['daily_metrics' => $dailyMetrics]);
        SocialEngagementTargetMetaMarketingReport::query()
            ->where('social_engagement_target_id', $campaign->getKey())
            ->firstOrFail()
            ->update(['daily_metrics' => $dailyMetrics]);
        SocialEngagementTargetGoogleAdsReport::query()
            ->where('social_engagement_target_id', $campaign->getKey())
            ->firstOrFail()
            ->update(['daily_metrics' => $dailyMetrics]);

        $period = ['from' => '2026-11-01', 'to' => '2026-11-01'];
        $response = $this->get(route('clients.performance', $period))
            ->assertOk()
            ->assertSee('0.00 TWD')
            ->assertDontSee('期間資料不足 1');
        $row = collect($response->viewData('clientPortfolioRows'))->firstWhere('client.name', 'Zero activity client');

        $this->assertSame(1, $row['campaign_count']);
        $this->assertSame(0.0, $row['money_groups'][0]['ga4_revenue']);
        $this->assertSame(0.0, $row['money_groups'][0]['meta_spend']);
        $this->assertSame(0.0, $row['money_groups'][0]['google_ads_spend']);
        $this->assertSame(1, $row['money_groups'][0]['conversion_coverage']['google_analytics']['converted_campaigns']);
        $this->assertSame(1, $row['money_groups'][0]['conversion_coverage']['meta_marketing']['converted_campaigns']);
        $this->assertSame(1, $row['money_groups'][0]['conversion_coverage']['google_ads']['converted_campaigns']);

        $csvRow = collect($this->csvRows(
            $this->get(route('clients.performance.export', $period))->assertOk()->streamedContent(),
        ))->keyBy('客戶')->get('Zero activity client');
        $this->assertSame('0.00 TWD', $csvRow['GA4 營收']);
        $this->assertSame('0.00 TWD', $csvRow['Meta Ads 花費']);
        $this->assertSame('0.00 TWD', $csvRow['Google Ads 花費']);
        $this->assertSame('', $csvRow['資料缺漏']);
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

    public function test_client_portfolio_export_returns_currency_safe_marketing_metrics(): void
    {
        $this->travelTo('2026-11-03 12:00:00');
        $user = User::factory()->create();
        $client = $user->clients()->create([
            'name' => 'Northwind',
            'reporting_currency_code' => 'TWD',
        ]);
        $campaign = $this->createCampaign($client, 'Northwind launch');
        $connections = $this->createConnections($user);
        $this->createCampaignReports($campaign, $connections, '10.0000', '2.0000', '3.0000');
        $client->exchangeRates()->create([
            'source_currency_code' => 'USD',
            'reporting_currency_code' => 'TWD',
            'effective_on' => '2026-11-01',
            'rate_to_reporting_currency' => '30.00000000',
        ]);

        $response = $this->actingAs($user)
            ->get(route('clients.performance.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename=client-performance-2026-10-04-to-2026-11-02.csv')
            ->assertHeader('cache-control', 'no-store, private');
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $rows = collect($this->csvRows($content))->keyBy('客戶');
        $row = $rows->get('Northwind');

        $this->assertSame('TWD', $row['報表幣別']);
        $this->assertSame('2026-10-04 至 2026-11-02', $row['報表期間']);
        $this->assertSame('1', $row['活動數']);
        $this->assertSame('300.00 TWD', $row['GA4 營收']);
        $this->assertSame('60.00 TWD', $row['Meta Ads 花費']);
        $this->assertSame('90.00 TWD', $row['Google Ads 花費']);
        $this->assertSame('Google Ads 13.33× (USD)', $row['平台 ROAS']);
        $this->assertSame('GA4 最新 1/1；Meta 最新 1/1；Google Ads 最新 1/1', $row['資料更新']);
        $this->assertSame('', $row['資料缺漏']);
    }

    public function test_client_portfolio_export_sanitizes_formula_cells(): void
    {
        $user = User::factory()->create();
        $formulaClient = $user->clients()->create([
            'name' => '=HYPERLINK("https://example.invalid","開啟")',
        ]);

        $content = $this->actingAs($user)
            ->get(route('clients.performance.export'))
            ->assertOk()
            ->streamedContent();
        $rows = $this->csvRows($content);
        $row = collect($rows)->firstWhere('客戶', "'=HYPERLINK(\"https://example.invalid\",\"開啟\")");

        $this->assertNotNull($row);
        $this->assertSame($formulaClient->name, substr($row['客戶'], 1));
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
            ->assertSee('GA4 期間資料不足 1');
        $this->assertSame(3, substr_count($response->getContent(), '缺匯率 1'));

        $rows = collect($response->viewData('clientPortfolioRows'))->keyBy(
            static fn (array $row): string => $row['client']->name,
        );
        $row = $rows->get('待補匯率客戶');
        $conversionCoverage = $row['money_groups'][0]['conversion_coverage'];

        $this->assertTrue($row['uses_reporting_currency']);
        $this->assertSame(0, $row['money_groups'][0]['ga4_campaign_count']);
        $this->assertSame(1, $conversionCoverage['google_analytics']['missing_rate_campaigns']);
        $this->assertSame(0, $conversionCoverage['google_analytics']['missing_daily_data_campaigns']);
        $this->assertSame(1, $row['coverage']['google_analytics']['missing_campaigns']);

        $csvRows = collect($this->csvRows($this->get(route('clients.performance.export'))->assertOk()->streamedContent()))
            ->keyBy('客戶');
        $gaps = $csvRows->get('待補匯率客戶')['資料缺漏'];
        $this->assertStringContainsString('GA4 缺匯率 1', $gaps);
        $this->assertStringContainsString('GA4 期間資料不足 1', $gaps);
        $this->assertStringContainsString('Meta 缺匯率 1', $gaps);
        $this->assertStringContainsString('Google Ads 缺匯率 1', $gaps);
    }

    public function test_selected_workspace_member_only_sees_assigned_clients_in_the_portfolio(): void
    {
        $this->travelTo('2026-11-03 12:00:00');
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
            ->get(route('clients.performance', ['from' => '2026-11-01', 'to' => '2026-11-01']))
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

        $csvRows = $this->csvRows($this->get(route('clients.performance.export', [
            'from' => '2026-11-01',
            'to' => '2026-11-01',
        ]))->assertOk()->streamedContent());
        $this->assertSame(['可見客戶'], array_column($csvRows, '客戶'));
    }

    public static function invalidReportingPeriods(): array
    {
        return [
            'missing end date' => [
                ['from' => '2026-11-02'],
                'to',
                '請選擇報表結束日期。',
            ],
            'invalid date' => [
                ['from' => '2026-02-30', 'to' => '2026-03-01'],
                'from',
                '報表開始日期格式無效。',
            ],
            'reversed dates' => [
                ['from' => '2026-11-03', 'to' => '2026-11-02'],
                'to',
                '結束日期不可早於開始日期。',
            ],
            'future end date' => [
                ['from' => '2026-11-02', 'to' => '2026-11-04'],
                'to',
                '報表結束日期不可晚於昨天。',
            ],
            'more than 365 days' => [
                ['from' => '2025-11-03', 'to' => '2026-11-03'],
                'from',
                '報表期間最多 365 天。',
            ],
        ];
    }

    #[DataProvider('invalidReportingPeriods')]
    public function test_invalid_reporting_periods_redirect_with_the_specific_error(
        array $period,
        string $field,
        string $message,
    ): void {
        $this->travelTo('2026-11-04 12:00:00');

        $this->actingAs(User::factory()->create())
            ->get(route('clients.performance', $period))
            ->assertRedirect(route('clients.performance'))
            ->assertSessionHasErrors([$field => $message]);
    }

    public function test_reporting_period_accepts_exactly_365_calendar_days(): void
    {
        $this->travelTo('2026-11-04 12:00:00');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('clients.performance', ['from' => '2025-11-04', 'to' => '2026-11-03']))
            ->assertOk();

        $this->assertSame([
            'from' => '2025-11-04',
            'to' => '2026-11-03',
        ], $response->viewData('reportPeriod'));
    }

    public function test_invalid_csv_period_redirects_to_the_report_with_validation_errors(): void
    {
        $this->travelTo('2026-11-04 12:00:00');

        $this->actingAs(User::factory()->create())
            ->get(route('clients.performance.export', ['from' => '2026-11-02']))
            ->assertRedirect(route('clients.performance'))
            ->assertSessionHasErrors(['to' => '請選擇報表結束日期。']);
    }

    private function createCampaign(
        Client $client,
        string $tag,
        string $startsOn = '2026-11-01',
        string $endsOn = '2026-11-01',
    ): SocialEngagementTarget {
        return SocialEngagementTarget::factory()->create([
            'client_id' => $client->getKey(),
            'tag' => $tag,
            'social_platform' => 'instagram',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
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
        $startsOn = $campaign->starts_on?->toDateString() ?? '2026-11-01';
        $endsOn = $campaign->ends_on?->toDateString() ?? $startsOn;

        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connections['google_analytics']->getKey(),
            'social_engagement_target_id' => $campaign->getKey(),
            'campaign_name' => $campaign->googleAnalyticsCampaignName(),
            'attribution_mode' => 'google_ads_auto_tagging',
            'campaign_id' => $campaign->google_ads_campaign_id,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'sessions' => 10,
            'engaged_sessions' => 8,
            'key_events' => '2.0000',
            'total_revenue' => $ga4Revenue,
            'currency_code' => 'USD',
            'daily_metrics' => $this->dailyMetrics([[
                'date' => '2026-11-01',
                'total_revenue' => $ga4Revenue,
            ]], $startsOn, $endsOn),
            'synced_at' => now(),
        ]);

        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $connections['meta_marketing']->getKey(),
            'social_engagement_target_id' => $campaign->getKey(),
            'ad_account_id' => $connections['meta_marketing']->ad_account_id,
            'campaign_id' => $campaign->meta_campaign_id,
            'campaign_name' => $campaign->tag,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
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
            ]], $startsOn, $endsOn),
            'synced_at' => now(),
        ]);

        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $connections['google_ads']->getKey(),
            'social_engagement_target_id' => $campaign->getKey(),
            'customer_id' => $connections['google_ads']->customer_id,
            'campaign_id' => $campaign->google_ads_campaign_id,
            'campaign_name' => $campaign->tag,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'spend_amount' => $googleAdsSpend,
            'impressions' => 200,
            'clicks' => 20,
            'conversions' => '2.0000',
            'conversions_value' => '40.0000',
            'currency_code' => 'USD',
            'daily_metrics' => $this->dailyMetrics([[
                'date' => '2026-11-01',
                'spend_amount' => $googleAdsSpend,
                'conversions_value' => '40.0000',
            ]], $startsOn, $endsOn),
            'synced_at' => now(),
        ]);
    }

    /** @return list<array<string, string>> */
    private function csvRows(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', substr($content, 3)) ?: [];
        $headers = str_getcsv(array_shift($lines) ?? '');
        $dataLines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

        return array_map(static function (string $line) use ($headers): array {
            $values = str_getcsv($line);
            $row = array_combine($headers, $values);

            return is_array($row) ? $row : [];
        }, $dataLines);
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function dailyMetrics(
        array $rows,
        string $requestedStartOn = '2026-11-01',
        ?string $requestedEndOn = null,
        string $status = 'complete',
        ?string $coverageStartOn = null,
        ?string $coverageEndOn = null,
    ): array {
        $requestedEndOn ??= $requestedStartOn;

        return [
            'status' => $status,
            'requested_start_on' => $requestedStartOn,
            'requested_end_on' => $requestedEndOn,
            'coverage_start_on' => $coverageStartOn ?? $requestedStartOn,
            'coverage_end_on' => $coverageEndOn ?? $requestedEndOn,
            'rows' => $rows,
        ];
    }
}
