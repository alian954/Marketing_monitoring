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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientReportingCurrencySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_shared_report_convert_daily_campaign_metrics_to_the_client_currency(): void
    {
        $this->travelTo('2026-11-03 12:00:00');
        $user = User::factory()->create();
        $this->actingAs($user);
        $client = $user->clients()->create([
            'name' => 'Northwind',
            'reporting_currency_code' => 'TWD',
        ]);
        $target = $this->createCampaign($client, 'Winter launch');
        $this->createAllSourceReports($user, $target);

        $client->exchangeRates()->createMany([
            [
                'source_currency_code' => 'USD',
                'reporting_currency_code' => 'TWD',
                'effective_on' => '2026-10-01',
                'rate_to_reporting_currency' => '29.00000000',
            ],
            [
                'source_currency_code' => 'USD',
                'reporting_currency_code' => 'TWD',
                'effective_on' => '2026-11-01',
                'rate_to_reporting_currency' => '30.00000000',
            ],
            [
                'source_currency_code' => 'USD',
                'reporting_currency_code' => 'TWD',
                'effective_on' => '2026-11-02',
                'rate_to_reporting_currency' => '31.00000000',
            ],
        ]);

        $dashboard = $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('報表幣別彙總');
        $dashboardGroup = collect($dashboard->viewData('campaignPortfolioMarketingSummary')['reporting_currency_groups'])->sole();

        $this->assertSame('TWD', $dashboardGroup['currency_code']);
        $this->assertSame(920.0, $dashboardGroup['ga4_revenue']);
        $this->assertSame(214.0, $dashboardGroup['meta_spend']);
        $this->assertSame(336.0, $dashboardGroup['google_ads_spend']);
        $this->assertSame(1, $dashboardGroup['conversion_coverage']['google_analytics']['converted_campaigns']);
        $this->assertSame('USD', $dashboard->viewData('campaignPortfolioMarketingSummary')['currency_groups'][0]['currency_code']);
        $this->assertSame(30.0, $dashboard->viewData('campaignPortfolioMarketingSummary')['currency_groups'][0]['ga4_revenue']);

        $token = str_repeat('d', 64);
        $client->reportShares()->create([
            'name' => 'Northwind monthly report',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        $sharedReport = $this->get(route('client-report-shares.public', $token))
            ->assertOk()
            ->assertSee('報表幣別彙總')
            ->assertSee('920.00 TWD')
            ->assertSee('214.00 TWD')
            ->assertSee('336.00 TWD');

        $this->assertSame(
            $dashboardGroup,
            collect($sharedReport->viewData('reportingCurrencyGroups'))->sole(),
        );
    }

    public function test_campaigns_with_missing_daily_rates_or_daily_rows_are_not_mixed_into_currency_totals(): void
    {
        $this->travelTo('2026-11-03 12:00:00');
        $user = User::factory()->create();
        $this->actingAs($user);
        $client = $user->clients()->create([
            'name' => 'Northwind',
            'reporting_currency_code' => 'TWD',
        ]);
        $dailyCampaign = $this->createCampaign($client, 'Daily campaign', [
            'meta_campaign_id' => null,
            'google_ads_campaign_id' => null,
        ]);
        $missingDailyCampaign = $this->createCampaign($client, 'Missing daily campaign', [
            'meta_campaign_id' => null,
            'google_ads_campaign_id' => null,
            'ga4_campaign_name' => 'missing-daily-campaign',
        ]);
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->getKey(),
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-refresh-token',
        ]);
        $this->createGoogleAnalyticsReport($connection, $dailyCampaign, [
            'daily_metrics' => $this->dailyMetrics([
                ['date' => '2026-11-01', 'total_revenue' => '10.0000'],
                ['date' => '2026-11-02', 'total_revenue' => '20.0000'],
            ]),
        ]);
        $this->createGoogleAnalyticsReport($connection, $missingDailyCampaign, [
            'campaign_name' => 'missing-daily-campaign',
            'total_revenue' => '100.0000',
            'daily_metrics' => null,
        ]);
        $client->exchangeRates()->create([
            'source_currency_code' => 'USD',
            'reporting_currency_code' => 'TWD',
            'effective_on' => '2026-11-02',
            'rate_to_reporting_currency' => '30.00000000',
        ]);

        $response = $this->get(route('dashboard'))->assertOk();
        $group = collect($response->viewData('campaignPortfolioMarketingSummary')['reporting_currency_groups'])->sole();
        $coverage = $group['conversion_coverage']['google_analytics'];

        $response
            ->assertSee('缺匯率 1 個活動／1 個日期')
            ->assertSee('缺逐日資料 1 個');

        $this->assertSame(0.0, $group['ga4_revenue']);
        $this->assertSame(0, $group['ga4_campaign_count']);
        $this->assertSame(0, $coverage['converted_campaigns']);
        $this->assertSame(1, $coverage['missing_rate_campaigns']);
        $this->assertSame(1, $coverage['missing_rate_observations']);
        $this->assertSame(1, $coverage['missing_daily_data_campaigns']);
    }

    /** @param array<string, mixed> $overrides */
    private function createCampaign(Client $client, string $tag, array $overrides = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create(array_replace([
            'client_id' => $client->getKey(),
            'tag' => $tag,
            'social_platform' => 'instagram',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-02',
            'ga4_campaign_name' => $tag,
            'google_ads_campaign_id' => 'ads-'.$tag,
            'meta_campaign_id' => 'meta-'.$tag,
        ], $overrides));
    }

    private function createAllSourceReports(User $user, SocialEngagementTarget $target): void
    {
        $googleAnalyticsConnection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->getKey(),
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-refresh-token',
        ]);
        $metaConnection = MetaMarketingConnection::factory()->create([
            'user_id' => $user->getKey(),
            'ad_account_id' => 'act_12345',
            'currency_code' => 'USD',
        ]);
        $googleAdsConnection = GoogleAdsConnection::factory()->create([
            'user_id' => $user->getKey(),
            'customer_id' => '1234567890',
            'currency_code' => 'USD',
        ]);

        $this->createGoogleAnalyticsReport($googleAnalyticsConnection, $target, [
            'campaign_id' => $target->google_ads_campaign_id,
            'attribution_mode' => 'google_ads_auto_tagging',
            'total_revenue' => '30.0000',
            'daily_metrics' => $this->dailyMetrics([
                ['date' => '2026-11-01', 'total_revenue' => '10.0000'],
                ['date' => '2026-11-02', 'total_revenue' => '20.0000'],
            ]),
        ]);

        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $metaConnection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'ad_account_id' => $metaConnection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => $target->tag,
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
            'spend_amount' => '7.0000',
            'impressions' => 100,
            'reach' => 90,
            'clicks' => 10,
            'actions' => [],
            'action_values' => [],
            'currency_code' => 'USD',
            'daily_metrics' => $this->dailyMetrics([
                ['date' => '2026-11-01', 'spend_amount' => '3.0000'],
                ['date' => '2026-11-02', 'spend_amount' => '4.0000'],
            ]),
            'synced_at' => now(),
        ]);

        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $googleAdsConnection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'customer_id' => $googleAdsConnection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => $target->tag,
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
            'spend_amount' => '11.0000',
            'impressions' => 200,
            'clicks' => 20,
            'conversions' => '2.0000',
            'conversions_value' => '40.0000',
            'currency_code' => 'USD',
            'daily_metrics' => $this->dailyMetrics([
                ['date' => '2026-11-01', 'spend_amount' => '5.0000'],
                ['date' => '2026-11-02', 'spend_amount' => '6.0000'],
            ]),
            'synced_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createGoogleAnalyticsReport(
        GoogleAnalyticsConnection $connection,
        SocialEngagementTarget $target,
        array $overrides = [],
    ): SocialEngagementTargetGoogleAnalyticsReport {
        return SocialEngagementTargetGoogleAnalyticsReport::query()->create(array_replace([
            'google_analytics_connection_id' => $connection->getKey(),
            'social_engagement_target_id' => $target->getKey(),
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'attribution_mode' => $target->googleAnalyticsAttributionMode(),
            'campaign_id' => null,
            'source_name' => $target->googleAnalyticsSourceName(),
            'medium_name' => $target->googleAnalyticsMediumName(),
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
            'sessions' => 10,
            'engaged_sessions' => 8,
            'key_events' => '2.0000',
            'total_revenue' => '30.0000',
            'currency_code' => 'USD',
            'daily_metrics' => $this->dailyMetrics([
                ['date' => '2026-11-01', 'total_revenue' => '10.0000'],
                ['date' => '2026-11-02', 'total_revenue' => '20.0000'],
            ]),
            'synced_at' => now(),
        ], $overrides));
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function dailyMetrics(array $rows): array
    {
        return [
            'status' => 'complete',
            'requested_start_on' => '2026-11-01',
            'requested_end_on' => '2026-11-02',
            'coverage_start_on' => '2026-11-01',
            'coverage_end_on' => '2026-11-02',
            'rows' => $rows,
        ];
    }
}
