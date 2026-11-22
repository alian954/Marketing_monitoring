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
            ->assertSee('GA4 逐日成效')
            ->assertSee('Meta Ads 逐日成效')
            ->assertSee('Google Ads 逐日成效')
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
        $this->assertSame([], $dailyReport['google_ads']['rows']);
        $this->assertTrue($dailyReport['google_ads']['snapshot_available']);
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
                'actions' => [],
                'action_values' => [],
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

        $this->get(route('social-engagement-targets.daily-marketing-performance', $target))
            ->assertRedirect(route('login'));

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.daily-marketing-performance', $target))
            ->assertDownload('campaign-daily-marketing-performance-'.$target->id.'.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csvRows = $this->csvRows($response->streamedContent());

        $this->assertCount(4, $csvRows);
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
        $this->assertSame('', $metaFields['sessions']);

        $adsFields = array_combine($csvRows[0], $csvRows[3]);
        $this->assertSame('Google Ads', $adsFields['source']);
        $this->assertSame('2026-11-06', $adsFields['date']);
        $this->assertSame('2.0000', $adsFields['conversions']);
        $this->assertSame('2026-11-01', $adsFields['requested_start_on']);
        $this->assertSame('2026-11-30', $adsFields['coverage_end_on']);
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
            ->assertOk()
            ->assertSee('尚未取得逐日資料。')
            ->assertDontSee('987654321');

        $dailyReport = $response->viewData('dailyMarketingPerformance');
        $this->assertFalse($dailyReport['google_analytics']['snapshot_available']);
        $this->assertFalse($dailyReport['meta_marketing']['snapshot_available']);
        $this->assertFalse($dailyReport['google_ads']['snapshot_available']);
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
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $analyticsConnection->id,
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
            'ad_account_id' => 'act_12345',
            'currency_code' => 'TWD',
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
