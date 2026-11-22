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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementDailyMarketingEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_aligns_daily_spend_and_ga4_revenue_without_filling_missing_dates_with_zero(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'ga_rows' => [
                $this->gaRow('2026-10-31', '999.0000'),
                $this->gaRow('2026-11-02', '120.0000'),
                $this->gaRow('2026-11-03', '0.0000'),
                $this->gaRow('2026-11-04', '50.0000'),
            ],
            'meta_rows' => [
                $this->metaRow('2026-11-02', '10.0000'),
                $this->metaRow('2026-11-03', '0.0000'),
            ],
            'ads_rows' => [
                $this->adsRow('2026-11-02', '20.0000'),
                $this->adsRow('2026-11-03', '5.0000'),
            ],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('每日廣告花費與同日 ROAS')
            ->assertSee('120.00 TWD')
            ->assertSee('30.00 TWD')
            ->assertSee('4.00x')
            ->assertSee('資料不完整')
            ->assertSee('role="img"', false);

        $efficiency = $response->viewData('dailyMarketingPerformance')['combined_efficiency'];
        $rowsByDate = collect($efficiency['rows'])->keyBy('date');

        $this->assertSame('partial', $efficiency['status']);
        $this->assertFalse($rowsByDate->has('2026-10-31'));
        $this->assertSame('30.0000', $rowsByDate['2026-11-02']['ad_spend_amount']);
        $this->assertSame(4.0, $rowsByDate['2026-11-02']['roas']);
        $this->assertSame('5.0000', $rowsByDate['2026-11-03']['ad_spend_amount']);
        $this->assertSame(0.0, $rowsByDate['2026-11-03']['roas']);
        $this->assertNull($rowsByDate['2026-11-04']['ad_spend_amount']);
        $this->assertSame('50.0000', $rowsByDate['2026-11-04']['ga4_revenue']);
        $this->assertSame('missing_spend', $rowsByDate['2026-11-04']['roas_status']);
        $this->assertSame(['2026-11-02', '2026-11-03'], array_column($efficiency['trend_chart']['points'], 'date'));
    }

    public function test_report_does_not_merge_daily_ad_spend_when_platform_currencies_differ(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'ga_rows' => [$this->gaRow('2026-11-02', '120.0000')],
            'meta_rows' => [$this->metaRow('2026-11-02', '10.0000')],
            'ads_rows' => [$this->adsRow('2026-11-02', '20.0000')],
            'ads_currency' => 'USD',
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('廣告平台幣別不同')
            ->assertSee('10.00 TWD')
            ->assertSee('20.00 USD');

        $efficiency = $response->viewData('dailyMarketingPerformance')['combined_efficiency'];
        $dailyRow = $efficiency['rows'][0];

        $this->assertSame('currency_mismatch', $efficiency['status']);
        $this->assertNull($dailyRow['ad_spend_amount']);
        $this->assertNull($dailyRow['roas']);
        $this->assertSame('platform_currency_mismatch', $dailyRow['roas_status']);
    }

    public function test_report_keeps_ad_spend_when_ga4_revenue_currency_does_not_match(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'ga_rows' => [$this->gaRow('2026-11-02', '120.0000')],
            'ga_currency' => 'USD',
            'meta_rows' => [$this->metaRow('2026-11-02', '10.0000')],
            'ads_rows' => [$this->adsRow('2026-11-02', '20.0000')],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('30.00 TWD')
            ->assertSee('GA4 與花費幣別不同');

        $dailyRow = $response->viewData('dailyMarketingPerformance')['combined_efficiency']['rows'][0];

        $this->assertSame('30.0000', $dailyRow['ad_spend_amount']);
        $this->assertNull($dailyRow['roas']);
        $this->assertSame('currency_mismatch', $dailyRow['roas_status']);
    }

    public function test_report_marks_partial_source_coverage_and_exports_the_shared_coverage_range(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'ga_rows' => [$this->gaRow('2026-11-15', '120.0000')],
            'meta_rows' => [$this->metaRow('2026-11-15', '10.0000')],
            'ads_rows' => [$this->adsRow('2026-11-15', '20.0000')],
            'ads_status' => 'partial',
            'ads_coverage_start_on' => '2026-11-15',
        ]);

        $reportResponse = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();
        $efficiency = $reportResponse->viewData('dailyMarketingPerformance')['combined_efficiency'];

        $this->assertSame('partial', $efficiency['status']);
        $this->assertSame('2026-11-15', $efficiency['coverage_start_on']);
        $this->assertSame('2026-11-30', $efficiency['coverage_end_on']);

        $csvResponse = $this->get(route('social-engagement-targets.daily-marketing-performance', $target))
            ->assertDownload('campaign-daily-marketing-performance-'.$target->id.'.csv');
        $csvRows = $this->csvRows($csvResponse->streamedContent());
        $fields = array_combine($csvRows[0], $csvRows[4]);

        $this->assertSame('2026-11-01', $fields['requested_start_on']);
        $this->assertSame('2026-11-15', $fields['coverage_start_on']);
        $this->assertSame('2026-11-30', $fields['coverage_end_on']);
    }

    public function test_report_marks_calendar_dates_missing_from_every_source_as_partial(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget([
            'starts_on' => '2026-11-02',
            'ends_on' => '2026-11-03',
        ]);
        $this->createDailyReports($user, $target, [
            'ga_rows' => [$this->gaRow('2026-11-02', '120.0000')],
            'meta_rows' => [$this->metaRow('2026-11-02', '10.0000')],
            'ads_rows' => [$this->adsRow('2026-11-02', '20.0000')],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();
        $efficiency = $response->viewData('dailyMarketingPerformance')['combined_efficiency'];

        $this->assertSame('partial', $efficiency['status']);
        $this->assertCount(1, $efficiency['rows']);
        $this->assertSame('2026-11-02', $efficiency['rows'][0]['date']);
    }

    public function test_report_compares_equal_seven_day_periods_using_period_totals_for_roas(): void
    {
        $this->travelTo('2026-12-01 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget([
            'starts_on' => '2026-11-17',
            'ends_on' => '2026-11-30',
        ]);
        $dailyMetrics = [];

        for ($date = CarbonImmutable::parse('2026-11-17'); $date->toDateString() <= '2026-11-30'; $date = $date->addDay()) {
            $dateString = $date->toDateString();
            $isCurrentPeriod = $dateString >= '2026-11-24';
            $dailyMetrics[$dateString] = [
                'spend' => $isCurrentPeriod
                    ? ($dateString === '2026-11-30' ? '100.0000' : '1.0000')
                    : '10.0000',
                'revenue' => $isCurrentPeriod
                    ? ($dateString === '2026-11-30' ? '100.0000' : '0.0000')
                    : '5.0000',
            ];
        }

        $this->createDailyReports($user, $target, $this->periodRows($dailyMetrics));

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('近 7 天與前 7 天')
            ->assertSee('0.94x');
        $comparison = $response->viewData('dailyMarketingPerformance')['combined_efficiency']['period_comparison'];

        $this->assertSame('complete', $comparison['status']);
        $this->assertSame('2026-11-24', $comparison['current_period']['start_on']);
        $this->assertSame('2026-11-30', $comparison['current_period']['end_on']);
        $this->assertSame('106.0000', $comparison['current_period']['spend_amount']);
        $this->assertSame('100.0000', $comparison['current_period']['revenue_amount']);
        $this->assertSame(0.94, $comparison['current_period']['roas']);
        $this->assertSame('2026-11-17', $comparison['previous_period']['start_on']);
        $this->assertSame('70.0000', $comparison['previous_period']['spend_amount']);
        $this->assertSame(0.5, $comparison['previous_period']['roas']);
        $this->assertSame('36.0000', $comparison['changes']['spend_amount']);
        $this->assertSame(51.43, $comparison['changes']['spend_percent']);
        $this->assertSame('65.0000', $comparison['changes']['revenue_amount']);
        $this->assertSame(0.44, $comparison['changes']['roas']);
    }

    public function test_period_comparison_does_not_calculate_change_when_a_calendar_date_is_missing(): void
    {
        $this->travelTo('2026-12-01 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget([
            'starts_on' => '2026-11-17',
            'ends_on' => '2026-11-30',
        ]);
        $dailyMetrics = [];

        for ($date = CarbonImmutable::parse('2026-11-17'); $date->toDateString() <= '2026-11-30'; $date = $date->addDay()) {
            $dailyMetrics[$date->toDateString()] = ['spend' => '10.0000', 'revenue' => '20.0000'];
        }

        $this->createDailyReports($user, $target, $this->periodRows($dailyMetrics, ['2026-11-25']));

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();
        $comparison = $response->viewData('dailyMarketingPerformance')['combined_efficiency']['period_comparison'];

        $this->assertSame('partial', $comparison['status']);
        $this->assertSame('complete', $comparison['previous_period']['status']);
        $this->assertSame('partial', $comparison['current_period']['status']);
        $this->assertNull($comparison['current_period']['spend_amount']);
        $this->assertNull($comparison['changes']['spend_amount']);
        $this->assertNull($comparison['changes']['revenue_amount']);
    }

    public function test_period_comparison_handles_zero_previous_spend_without_dividing_by_zero(): void
    {
        $this->travelTo('2026-12-01 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget([
            'starts_on' => '2026-11-17',
            'ends_on' => '2026-11-30',
        ]);
        $dailyMetrics = [];

        for ($date = CarbonImmutable::parse('2026-11-17'); $date->toDateString() <= '2026-11-30'; $date = $date->addDay()) {
            $isCurrentPeriod = $date->toDateString() >= '2026-11-24';
            $dailyMetrics[$date->toDateString()] = [
                'spend' => $isCurrentPeriod ? '10.0000' : '0.0000',
                'revenue' => $isCurrentPeriod ? '20.0000' : '0.0000',
            ];
        }

        $this->createDailyReports($user, $target, $this->periodRows($dailyMetrics));

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('前期為 0，無法計算百分比');
        $comparison = $response->viewData('dailyMarketingPerformance')['combined_efficiency']['period_comparison'];

        $this->assertSame('complete', $comparison['status']);
        $this->assertSame('0.0000', $comparison['previous_period']['spend_amount']);
        $this->assertNull($comparison['previous_period']['roas']);
        $this->assertNull($comparison['changes']['spend_percent']);
        $this->assertNull($comparison['changes']['roas']);
    }

    public function test_period_comparison_does_not_combine_different_paid_media_currencies(): void
    {
        $this->travelTo('2026-12-01 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget([
            'starts_on' => '2026-11-17',
            'ends_on' => '2026-11-30',
        ]);
        $dailyMetrics = [];

        for ($date = CarbonImmutable::parse('2026-11-17'); $date->toDateString() <= '2026-11-30'; $date = $date->addDay()) {
            $dailyMetrics[$date->toDateString()] = ['spend' => '10.0000', 'revenue' => '20.0000'];
        }

        $this->createDailyReports($user, $target, $this->periodRows($dailyMetrics) + ['ads_currency' => 'USD']);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();
        $comparison = $response->viewData('dailyMarketingPerformance')['combined_efficiency']['period_comparison'];

        $this->assertSame('currency_mismatch', $comparison['status']);
        $this->assertSame('currency_mismatch', $comparison['current_period']['status']);
        $this->assertSame('currency_mismatch', $comparison['previous_period']['status']);
        $this->assertNull($comparison['changes']['spend_amount']);
        $this->assertNull($comparison['changes']['revenue_amount']);
        $this->assertNull($comparison['changes']['roas']);
    }

    public function test_period_comparison_does_not_shift_back_when_recent_days_are_missing(): void
    {
        $this->travelTo('2026-12-01 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget([
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);
        $dailyMetrics = [];

        for ($date = CarbonImmutable::parse('2026-11-01'); $date->toDateString() <= '2026-11-16'; $date = $date->addDay()) {
            $dailyMetrics[$date->toDateString()] = ['spend' => '10.0000', 'revenue' => '20.0000'];
        }

        $this->createDailyReports($user, $target, $this->periodRows($dailyMetrics));

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk();
        $comparison = $response->viewData('dailyMarketingPerformance')['combined_efficiency']['period_comparison'];

        $this->assertSame('partial', $comparison['status']);
        $this->assertSame('2026-11-24', $comparison['current_period']['start_on']);
        $this->assertSame('2026-11-30', $comparison['current_period']['end_on']);
        $this->assertSame('partial', $comparison['current_period']['status']);
        $this->assertNull($comparison['changes']['spend_amount']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTarget(array $overrides = []): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create($overrides + [
            'tag' => 'Daily efficiency campaign',
            'social_platform' => 'facebook',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_campaign_name' => 'Daily efficiency campaign',
            'google_ads_campaign_id' => 'ads-123',
            'meta_campaign_id' => 'meta-123',
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function createDailyReports(User $user, SocialEngagementTarget $target, array $options): void
    {
        $startOn = $target->starts_on->toDateString();
        $endOn = $target->ends_on->toDateString();
        $requestedDates = [
            'requested_start_on' => $startOn,
            'requested_end_on' => $endOn,
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
            'source_name' => null,
            'medium_name' => null,
            'starts_on' => $startOn,
            'ends_on' => $endOn,
            'sessions' => 100,
            'engaged_sessions' => 80,
            'key_events' => '12.0000',
            'total_revenue' => '120.0000',
            'currency_code' => $options['ga_currency'] ?? 'TWD',
            'daily_metrics' => [
                'status' => $options['ga_status'] ?? 'complete',
                ...$requestedDates,
                'coverage_start_on' => $options['ga_coverage_start_on'] ?? $startOn,
                'coverage_end_on' => $options['ga_coverage_end_on'] ?? $endOn,
                'rows' => $options['ga_rows'] ?? [],
            ],
            'synced_at' => now(),
        ]);

        $metaConnection = MetaMarketingConnection::factory()->create([
            'user_id' => $user->id,
            'ad_account_id' => 'act_12345',
            'currency_code' => $options['meta_currency'] ?? 'TWD',
        ]);
        SocialEngagementTargetMetaMarketingReport::query()->create([
            'meta_marketing_connection_id' => $metaConnection->id,
            'social_engagement_target_id' => $target->id,
            'ad_account_id' => $metaConnection->ad_account_id,
            'campaign_id' => $target->meta_campaign_id,
            'campaign_name' => 'Daily efficiency Meta campaign',
            'starts_on' => $startOn,
            'ends_on' => $endOn,
            'spend_amount' => '10.0000',
            'impressions' => 1000,
            'reach' => 800,
            'clicks' => 50,
            'actions' => [],
            'action_values' => [],
            'currency_code' => $options['meta_currency'] ?? 'TWD',
            'daily_metrics' => [
                'status' => $options['meta_status'] ?? 'complete',
                ...$requestedDates,
                'coverage_start_on' => $options['meta_coverage_start_on'] ?? $startOn,
                'coverage_end_on' => $options['meta_coverage_end_on'] ?? $endOn,
                'rows' => $options['meta_rows'] ?? [],
            ],
            'synced_at' => now(),
        ]);

        $adsConnection = GoogleAdsConnection::factory()->create([
            'user_id' => $user->id,
            'customer_id' => '1234567890',
            'currency_code' => $options['ads_currency'] ?? 'TWD',
        ]);
        SocialEngagementTargetGoogleAdsReport::query()->create([
            'google_ads_connection_id' => $adsConnection->id,
            'social_engagement_target_id' => $target->id,
            'customer_id' => $adsConnection->customer_id,
            'campaign_id' => $target->google_ads_campaign_id,
            'campaign_name' => 'Daily efficiency Google Ads campaign',
            'starts_on' => $startOn,
            'ends_on' => $endOn,
            'spend_amount' => '20.0000',
            'impressions' => 2000,
            'clicks' => 100,
            'conversions' => '10.0000',
            'conversions_value' => '300.0000',
            'currency_code' => $options['ads_currency'] ?? 'TWD',
            'daily_metrics' => [
                'status' => $options['ads_status'] ?? 'complete',
                ...$requestedDates,
                'coverage_start_on' => $options['ads_coverage_start_on'] ?? $startOn,
                'coverage_end_on' => $options['ads_coverage_end_on'] ?? $endOn,
                'rows' => $options['ads_rows'] ?? [],
            ],
            'synced_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function gaRow(string $date, string $revenue): array
    {
        return [
            'date' => $date,
            'sessions' => 10,
            'engaged_sessions' => 8,
            'key_events' => '1.0000',
            'total_revenue' => $revenue,
        ];
    }

    /** @return array<string, mixed> */
    private function metaRow(string $date, string $spend): array
    {
        return [
            'date' => $date,
            'spend_amount' => $spend,
            'impressions' => 100,
            'reach' => 80,
            'clicks' => 5,
            'actions' => [],
            'action_values' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function adsRow(string $date, string $spend): array
    {
        return [
            'date' => $date,
            'spend_amount' => $spend,
            'impressions' => 200,
            'clicks' => 10,
            'conversions' => '1.0000',
            'conversions_value' => '50.0000',
        ];
    }

    /**
     * @param  array<string, array{spend: string, revenue: string}>  $dailyMetrics
     * @param  list<string>  $datesWithoutGa4
     * @return array{ga_rows: list<array<string, mixed>>, meta_rows: list<array<string, mixed>>, ads_rows: list<array<string, mixed>>}
     */
    private function periodRows(array $dailyMetrics, array $datesWithoutGa4 = []): array
    {
        $gaRows = [];
        $metaRows = [];
        $adsRows = [];

        foreach ($dailyMetrics as $date => $metrics) {
            if (! in_array($date, $datesWithoutGa4, true)) {
                $gaRows[] = $this->gaRow($date, $metrics['revenue']);
            }

            $metaRows[] = $this->metaRow($date, $metrics['spend']);
            $adsRows[] = $this->adsRow($date, '0.0000');
        }

        return [
            'ga_rows' => $gaRows,
            'meta_rows' => $metaRows,
            'ads_rows' => $adsRows,
        ];
    }

    /** @return list<list<string|null>> */
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
