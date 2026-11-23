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
            ->assertSee('6.00x')
            ->assertSee('資料不完整')
            ->assertSee('role="img"', false);

        $efficiency = $response->viewData('dailyMarketingPerformance')['combined_efficiency'];
        $rowsByDate = collect($efficiency['rows'])->keyBy('date');

        $this->assertSame('partial', $efficiency['status']);
        $this->assertFalse($rowsByDate->has('2026-10-31'));
        $this->assertSame('30.0000', $rowsByDate['2026-11-02']['ad_spend_amount']);
        $this->assertSame('20.0000', $rowsByDate['2026-11-02']['roas_spend_amount']);
        $this->assertSame('TWD', $rowsByDate['2026-11-02']['roas_spend_currency']);
        $this->assertSame('google_ads', $rowsByDate['2026-11-02']['roas_spend_source']);
        $this->assertSame(6.0, $rowsByDate['2026-11-02']['roas']);
        $this->assertSame('5.0000', $rowsByDate['2026-11-03']['ad_spend_amount']);
        $this->assertSame('5.0000', $rowsByDate['2026-11-03']['roas_spend_amount']);
        $this->assertSame(0.0, $rowsByDate['2026-11-03']['roas']);
        $this->assertNull($rowsByDate['2026-11-04']['ad_spend_amount']);
        $this->assertSame('50.0000', $rowsByDate['2026-11-04']['ga4_revenue']);
        $this->assertSame('missing_spend', $rowsByDate['2026-11-04']['roas_status']);
        $this->assertSame(['2026-11-02', '2026-11-03'], array_column($efficiency['trend_chart']['points'], 'date'));

        $csvRows = $this->csvRows(
            $this->get(route('social-engagement-targets.daily-marketing-performance', $target))
                ->assertDownload('campaign-daily-marketing-performance-'.$target->id.'.csv')
                ->streamedContent(),
        );
        $combinedRow = collect(array_slice($csvRows, 1))->first(
            static fn (array $row): bool => ($row[0] ?? null) === '每日 GA4 ROAS' && ($row[1] ?? null) === '2026-11-02',
        );
        $this->assertIsArray($combinedRow);
        $combinedFields = array_combine($csvRows[0], $combinedRow);
        $this->assertSame('20.0000', $combinedFields['roas_spend_amount']);
        $this->assertSame('TWD', $combinedFields['roas_spend_currency']);
        $this->assertSame('google_ads', $combinedFields['roas_spend_source']);
    }

    public function test_report_uses_meta_spend_for_ga4_roas_without_a_google_ads_campaign(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget(['google_ads_campaign_id' => null]);
        $this->createDailyReports($user, $target, [
            'ga_rows' => [$this->gaRow('2026-11-02', '120.0000')],
            'meta_rows' => [$this->metaRow('2026-11-02', '10.0000')],
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('12.00x');
        $dailyRow = $response->viewData('dailyMarketingPerformance')['combined_efficiency']['rows'][0];

        $this->assertSame('10.0000', $dailyRow['ad_spend_amount']);
        $this->assertSame('10.0000', $dailyRow['roas_spend_amount']);
        $this->assertSame('TWD', $dailyRow['roas_spend_currency']);
        $this->assertSame('meta_marketing', $dailyRow['roas_spend_source']);
        $this->assertSame(12.0, $dailyRow['roas']);
    }

    public function test_report_can_calculate_google_ads_roas_when_meta_currency_differs(): void
    {
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'ga_rows' => [$this->gaRow('2026-11-02', '120.0000')],
            'meta_rows' => [$this->metaRow('2026-11-02', '10.0000')],
            'ads_rows' => [$this->adsRow('2026-11-02', '20.0000')],
            'ga_currency' => 'USD',
            'ads_currency' => 'USD',
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('廣告平台幣別不同')
            ->assertSee('10.00 TWD')
            ->assertSee('20.00 USD')
            ->assertSee('6.00x');

        $efficiency = $response->viewData('dailyMarketingPerformance')['combined_efficiency'];
        $dailyRow = $efficiency['rows'][0];

        $this->assertSame('currency_mismatch', $efficiency['status']);
        $this->assertNull($dailyRow['ad_spend_amount']);
        $this->assertSame('20.0000', $dailyRow['roas_spend_amount']);
        $this->assertSame('google_ads', $dailyRow['roas_spend_source']);
        $this->assertSame(6.0, $dailyRow['roas']);
        $this->assertSame('available', $dailyRow['roas_status']);
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

    public function test_meta_fatigue_observation_flags_complete_frequency_increase_and_ctr_decline(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'meta_rows' => $this->fatigueMetaRows(1500, 1000, 120),
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('Meta Ads 頻率與 CTR 觀察')
            ->assertSee('建議檢視')
            ->assertSee('頻率增加與 CTR 下滑同時達到提示門檻，建議檢視素材與受眾。')
            ->assertSee('10,500 次曝光')
            ->assertSee('7 個投放日');
        $observation = $response->viewData('metaAdsFatigueObservation');

        $this->assertSame('signal', $observation['status']);
        $this->assertSame('2026-11-15', $observation['current_period']['start_on']);
        $this->assertSame('2026-11-21', $observation['current_period']['end_on']);
        $this->assertSame('2026-11-08', $observation['previous_period']['start_on']);
        $this->assertSame('2026-11-14', $observation['previous_period']['end_on']);
        $this->assertSame(1.5, $observation['current_period']['average_frequency']);
        $this->assertSame(8.0, $observation['current_period']['ctr_percent']);
        $this->assertSame(1.0, $observation['previous_period']['average_frequency']);
        $this->assertSame(10.0, $observation['previous_period']['ctr_percent']);
        $this->assertSame(50.0, $observation['frequency_change_percent']);
        $this->assertSame(-20.0, $observation['ctr_change_percent']);
    }

    public function test_meta_fatigue_observation_requires_both_frequency_and_ctr_conditions(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget();
        $this->createDailyReports($user, $target, [
            'meta_rows' => $this->fatigueMetaRows(1500, 1000, 150),
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('未達提示條件')
            ->assertSee('目前未同時達到兩項提示門檻。');
        $observation = $response->viewData('metaAdsFatigueObservation');

        $this->assertSame('no_signal', $observation['status']);
        $this->assertSame(50.0, $observation['frequency_change_percent']);
        $this->assertSame(0.0, $observation['ctr_change_percent']);
    }

    public function test_meta_fatigue_observation_ignores_zero_delivery_days_and_exposes_period_sample_volume(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $user = User::factory()->create();
        $target = $this->createTarget();
        $rows = $this->fatigueMetaRows(1500, 1000, 120);

        foreach ($rows as &$row) {
            if ($row['date'] === '2026-11-10') {
                $row['impressions'] = 0;
                $row['reach'] = 0;
                $row['clicks'] = 0;
            }
        }
        unset($row);
        $this->createDailyReports($user, $target, ['meta_rows' => $rows]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('曝光量偏低時波動較大')
            ->assertSee('6 個投放日');
        $observation = $response->viewData('metaAdsFatigueObservation');

        $this->assertSame('signal', $observation['status']);
        $this->assertSame(6, $observation['previous_period']['delivery_day_count']);
        $this->assertSame(6000, $observation['previous_period']['impressions']);
        $this->assertSame(7, $observation['current_period']['delivery_day_count']);
        $this->assertSame(10500, $observation['current_period']['impressions']);
    }

    public function test_meta_fatigue_observation_suppresses_incomplete_stale_and_invalid_data(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $incompleteUser = User::factory()->create();
        $incompleteTarget = $this->createTarget();
        $incompleteRows = $this->fatigueMetaRows(1500, 1000, 120);
        $incompleteRows = array_values(array_filter(
            $incompleteRows,
            static fn (array $row): bool => $row['date'] !== '2026-11-18',
        ));
        $this->createDailyReports($incompleteUser, $incompleteTarget, ['meta_rows' => $incompleteRows]);

        $incompleteObservation = $this->actingAs($incompleteUser)
            ->get(route('social-engagement-targets.report', $incompleteTarget))
            ->assertOk()
            ->assertSee('逐日資料不完整')
            ->viewData('metaAdsFatigueObservation');

        $this->assertSame('partial', $incompleteObservation['status']);
        $this->assertNull($incompleteObservation['frequency_change_percent']);

        $staleUser = User::factory()->create();
        $staleTarget = $this->createTarget([
            'tag' => 'Stale Meta fatigue',
            'meta_campaign_id' => 'meta-stale-fatigue',
        ]);
        $this->createDailyReports($staleUser, $staleTarget, [
            'meta_rows' => $this->fatigueMetaRows(1500, 1000, 120),
        ]);
        SocialEngagementTargetMetaMarketingReport::query()
            ->where('social_engagement_target_id', $staleTarget->id)
            ->firstOrFail()
            ->update(['synced_at' => now()->subDay()]);

        $staleObservation = $this->actingAs($staleUser)
            ->get(route('social-engagement-targets.report', $staleTarget))
            ->assertOk()
            ->assertSee('Meta 資料已過期')
            ->viewData('metaAdsFatigueObservation');

        $this->assertSame('stale', $staleObservation['status']);
        $this->assertNull($staleObservation['ctr_change_percent']);

        $invalidUser = User::factory()->create();
        $invalidTarget = $this->createTarget([
            'tag' => 'Invalid Meta fatigue',
            'meta_campaign_id' => 'meta-invalid-fatigue',
        ]);
        $invalidRows = $this->fatigueMetaRows(1500, 1000, 120);
        foreach ($invalidRows as &$row) {
            if ($row['date'] === '2026-11-18') {
                $row['reach'] = 0;
            }
        }
        unset($row);
        $this->createDailyReports($invalidUser, $invalidTarget, ['meta_rows' => $invalidRows]);

        $invalidObservation = $this->actingAs($invalidUser)
            ->get(route('social-engagement-targets.report', $invalidTarget))
            ->assertOk()
            ->assertSee('頻率或點擊率指標不足')
            ->viewData('metaAdsFatigueObservation');

        $this->assertSame('invalid_metrics', $invalidObservation['status']);
        $this->assertNull($invalidObservation['frequency_change_percent']);
    }

    public function test_meta_fatigue_observation_requires_two_full_campaign_weeks_and_complete_source_status(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $shortPeriodUser = User::factory()->create();
        $shortPeriodTarget = $this->createTarget([
            'tag' => 'Short Meta fatigue period',
            'starts_on' => '2026-11-10',
            'ends_on' => '2026-11-30',
        ]);
        $this->createDailyReports($shortPeriodUser, $shortPeriodTarget, [
            'meta_rows' => $this->fatigueMetaRows(1500, 1000, 120),
        ]);

        $shortPeriodObservation = $this->actingAs($shortPeriodUser)
            ->get(route('social-engagement-targets.report', $shortPeriodTarget))
            ->assertOk()
            ->assertSee('不足 14 天資料')
            ->assertSee('活動日期不足兩個完整七日區間，暫不判讀。')
            ->viewData('metaAdsFatigueObservation');

        $this->assertSame('insufficient_period', $shortPeriodObservation['status']);

        $partialUser = User::factory()->create();
        $partialTarget = $this->createTarget([
            'tag' => 'Partial Meta fatigue',
            'meta_campaign_id' => 'meta-partial-fatigue',
        ]);
        $this->createDailyReports($partialUser, $partialTarget, [
            'meta_status' => 'partial',
            'meta_rows' => $this->fatigueMetaRows(1500, 1000, 120),
        ]);

        $partialObservation = $this->actingAs($partialUser)
            ->get(route('social-engagement-targets.report', $partialTarget))
            ->assertOk()
            ->assertSee('逐日資料不完整')
            ->viewData('metaAdsFatigueObservation');

        $this->assertSame('partial', $partialObservation['status']);
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
        $attributionMode = $target->googleAnalyticsAttributionMode();
        SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $analyticsConnection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => $target->googleAnalyticsCampaignName(),
            'attribution_mode' => $attributionMode,
            'campaign_id' => $attributionMode === 'google_ads_auto_tagging' ? $target->google_ads_campaign_id : null,
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

        if ($target->google_ads_campaign_id !== null) {
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

    /** @return list<array<string, mixed>> */
    private function fatigueMetaRows(int $recentImpressions, int $recentReach, int $recentClicks): array
    {
        $rows = [];

        for ($date = CarbonImmutable::parse('2026-11-08'); $date->toDateString() <= '2026-11-21'; $date = $date->addDay()) {
            $isRecentPeriod = $date->toDateString() >= '2026-11-15';
            $rows[] = [
                'date' => $date->toDateString(),
                'spend_amount' => '10.0000',
                'impressions' => $isRecentPeriod ? $recentImpressions : 1000,
                'reach' => $isRecentPeriod ? $recentReach : 1000,
                'clicks' => $isRecentPeriod ? $recentClicks : 100,
                'actions' => [],
                'action_values' => [],
            ];
        }

        return $rows;
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

            $metaRows[] = $this->metaRow($date, number_format((float) $metrics['spend'] * 2, 4, '.', ''));
            $adsRows[] = $this->adsRow($date, $metrics['spend']);
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
