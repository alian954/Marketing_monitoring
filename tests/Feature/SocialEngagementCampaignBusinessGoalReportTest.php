<?php

namespace Tests\Feature;

use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\User;
use App\Services\SocialEngagementCampaignBusinessGoalReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementCampaignBusinessGoalReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_ga4_key_event_data_reports_campaign_goal_progress(): void
    {
        $target = $this->businessGoalTarget('key_events', '20.0000');
        $analyticsReport = $this->analyticsReport([
            'key_events' => '12.5000',
            'total_revenue' => '500.2500',
            'currency_code' => 'TWD',
        ]);

        $progress = app(SocialEngagementCampaignBusinessGoalReport::class)->build(
            $target,
            $analyticsReport,
            false,
        );

        $this->assertSame('available', $progress['status']);
        $this->assertSame('GA4 重要事件', $progress['metric_label']);
        $this->assertSame(20.0, $progress['target_value']);
        $this->assertSame(12.5, $progress['actual_value']);
        $this->assertSame(62.5, $progress['completion_percent']);
        $this->assertSame(7.5, $progress['remaining_value']);
        $this->assertNull($progress['goal_currency_code']);
    }

    public function test_named_ga4_event_goal_uses_only_the_matching_key_event_count(): void
    {
        $this->travelTo('2026-11-15 12:00:00');
        $target = $this->businessGoalTarget('key_events', '20.0000');
        $target->ga4_goal_event_name = 'purchase';
        $analyticsReport = $this->analyticsReport([
            'key_events' => '12.5000',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'event_name_report' => [
                'status' => 'complete',
                'window_start_on' => '2026-11-01',
                'window_end_on' => '2026-11-30',
                'truncated' => false,
                'rows' => [
                    ['event_name' => 'generate_lead', 'key_events' => '10.0000'],
                    ['event_name' => 'purchase', 'key_events' => '2.5000'],
                ],
            ],
            'event_name_expires_at' => now()->addDays(30),
        ]);

        $progress = app(SocialEngagementCampaignBusinessGoalReport::class)->build(
            $target,
            $analyticsReport,
            false,
        );

        $this->assertSame('available', $progress['status']);
        $this->assertSame('purchase', $progress['event_name']);
        $this->assertSame('GA4 重要事件：purchase', $progress['metric_label']);
        $this->assertSame(2.5, $progress['actual_value']);
        $this->assertSame(12.5, $progress['completion_percent']);
        $this->assertSame(17.5, $progress['remaining_value']);
    }

    public function test_named_ga4_event_goal_does_not_turn_missing_event_details_into_zero(): void
    {
        $this->travelTo('2026-11-15 12:00:00');
        $target = $this->businessGoalTarget('key_events', '20.0000');
        $target->ga4_goal_event_name = 'sign_up';
        $completeWithoutEvent = $this->analyticsReport([
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'event_name_report' => [
                'status' => 'complete',
                'window_start_on' => '2026-11-01',
                'window_end_on' => '2026-11-30',
                'truncated' => false,
                'rows' => [['event_name' => 'purchase', 'key_events' => '2.5000']],
            ],
            'event_name_expires_at' => now()->addDays(30),
        ]);
        $thresholded = $this->analyticsReport([
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'event_name_report' => [
                'status' => 'thresholded',
                'window_start_on' => '2026-11-01',
                'window_end_on' => '2026-11-30',
                'truncated' => false,
                'rows' => [],
            ],
            'event_name_expires_at' => now()->addDays(30),
        ]);

        foreach ([$completeWithoutEvent, $thresholded] as $analyticsReport) {
            $progress = app(SocialEngagementCampaignBusinessGoalReport::class)->build(
                $target,
                $analyticsReport,
                false,
            );

            $this->assertSame('event_unavailable', $progress['status']);
            $this->assertNull($progress['actual_value']);
            $this->assertNull($progress['completion_percent']);
            $this->assertSame('data_unavailable', $progress['time_pacing']['status']);
        }
    }

    public function test_active_ga4_goal_pacing_compares_actual_progress_with_elapsed_campaign_time(): void
    {
        $this->travelTo('2026-11-15 12:00:00');
        $target = $this->businessGoalTarget('key_events', '20.0000');
        $service = app(SocialEngagementCampaignBusinessGoalReport::class);
        $ahead = $service->build($target, $this->analyticsReport(['key_events' => '12.5000']), false);
        $behind = $service->build($target, $this->analyticsReport(['key_events' => '6.0000']), false);

        $this->assertSame('ahead', $ahead['time_pacing']['status']);
        $this->assertSame(30, $ahead['time_pacing']['campaign_days']);
        $this->assertSame(15, $ahead['time_pacing']['elapsed_days']);
        $this->assertSame(50.0, $ahead['time_pacing']['elapsed_percent']);
        $this->assertSame(10.0, $ahead['time_pacing']['expected_value']);
        $this->assertSame(2.5, $ahead['time_pacing']['gap_value']);
        $this->assertSame(12.5, $ahead['time_pacing']['gap_percentage_points']);

        $this->assertSame('behind', $behind['time_pacing']['status']);
        $this->assertSame(10.0, $behind['time_pacing']['expected_value']);
        $this->assertSame(-4.0, $behind['time_pacing']['gap_value']);
        $this->assertSame(-20.0, $behind['time_pacing']['gap_percentage_points']);
    }

    public function test_ga4_goal_pacing_handles_campaign_date_boundaries_without_projection(): void
    {
        $this->travelTo('2026-11-15 12:00:00');
        $target = $this->businessGoalTarget('key_events', '20.0000');
        $service = app(SocialEngagementCampaignBusinessGoalReport::class);
        $analyticsReport = $this->analyticsReport(['key_events' => '12.5000']);
        $notStarted = $service->build(
            $target->forceFill(['starts_on' => '2026-11-16', 'ends_on' => '2026-11-30']),
            $analyticsReport,
            false,
        );
        $notStartedWithStaleData = $service->build(
            $target->forceFill(['starts_on' => '2026-11-16', 'ends_on' => '2026-11-30']),
            $analyticsReport,
            true,
        );
        $completed = $service->build(
            $target->forceFill(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-14']),
            $analyticsReport,
            false,
        );
        $completedWithoutReport = $service->build(
            $target->forceFill(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-14']),
            null,
            false,
        );
        $invalidPeriod = $service->build(
            $target->forceFill(['starts_on' => '2026-11-30', 'ends_on' => '2026-11-01']),
            $analyticsReport,
            false,
        );

        $this->assertSame('not_started', $notStarted['time_pacing']['status']);
        $this->assertNull($notStarted['time_pacing']['expected_value']);
        $this->assertSame('not_started', $notStartedWithStaleData['time_pacing']['status']);
        $this->assertSame('completed', $completed['time_pacing']['status']);
        $this->assertNull($completed['time_pacing']['elapsed_percent']);
        $this->assertSame('completed', $completedWithoutReport['time_pacing']['status']);
        $this->assertSame('missing_period', $invalidPeriod['time_pacing']['status']);
        $this->assertNull($invalidPeriod['time_pacing']['gap_value']);
    }

    public function test_revenue_goal_is_not_compared_across_currencies(): void
    {
        $target = $this->businessGoalTarget('total_revenue', '1000.0000', 'TWD');
        $analyticsReport = $this->analyticsReport([
            'key_events' => '12.5000',
            'total_revenue' => '800.0000',
            'currency_code' => 'USD',
        ]);

        $progress = app(SocialEngagementCampaignBusinessGoalReport::class)->build(
            $target,
            $analyticsReport,
            false,
        );

        $this->assertSame('currency_mismatch', $progress['status']);
        $this->assertSame(800.0, $progress['actual_value']);
        $this->assertSame('TWD', $progress['goal_currency_code']);
        $this->assertSame('USD', $progress['report_currency_code']);
        $this->assertNull($progress['completion_percent']);
        $this->assertNull($progress['remaining_value']);
        $this->assertSame('data_unavailable', $progress['time_pacing']['status']);
    }

    public function test_revenue_goal_without_a_report_currency_has_no_completion_percentage(): void
    {
        $target = $this->businessGoalTarget('total_revenue', '1000.0000', 'TWD');
        $analyticsReport = $this->analyticsReport([
            'total_revenue' => '800.0000',
            'currency_code' => null,
        ]);

        $progress = app(SocialEngagementCampaignBusinessGoalReport::class)->build(
            $target,
            $analyticsReport,
            false,
        );

        $this->assertSame('currency_unknown', $progress['status']);
        $this->assertSame(800.0, $progress['actual_value']);
        $this->assertNull($progress['completion_percent']);
        $this->assertSame('data_unavailable', $progress['time_pacing']['status']);
    }

    public function test_unconfigured_ga4_goal_does_not_fabricate_progress(): void
    {
        $target = SocialEngagementTarget::factory()->create([
            'tag' => 'Spring launch',
            'social_platform' => 'instagram',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);

        $progress = app(SocialEngagementCampaignBusinessGoalReport::class)->build(
            $target,
            $this->analyticsReport(['key_events' => '12.5000']),
            false,
        );

        $this->assertSame('not_configured', $progress['status']);
        $this->assertNull($progress['target_value']);
        $this->assertNull($progress['actual_value']);
        $this->assertNull($progress['completion_percent']);
        $this->assertSame('data_unavailable', $progress['time_pacing']['status']);

        $zeroTargetProgress = app(SocialEngagementCampaignBusinessGoalReport::class)->build(
            $target->forceFill(['ga4_goal_metric' => 'key_events', 'ga4_goal_value' => '0.0000']),
            $this->analyticsReport(['key_events' => '12.5000']),
            false,
        );

        $this->assertSame('not_configured', $zeroTargetProgress['status']);
        $this->assertSame('data_unavailable', $zeroTargetProgress['time_pacing']['status']);
    }

    public function test_stale_ga4_values_remain_visible_without_a_completion_percentage(): void
    {
        $target = $this->businessGoalTarget('key_events', '20.0000');
        $analyticsReport = $this->analyticsReport(['key_events' => '12.5000']);

        $progress = app(SocialEngagementCampaignBusinessGoalReport::class)->build(
            $target,
            $analyticsReport,
            true,
        );

        $this->assertSame('stale', $progress['status']);
        $this->assertSame(12.5, $progress['actual_value']);
        $this->assertNull($progress['completion_percent']);
        $this->assertNull($progress['remaining_value']);
        $this->assertSame('data_unavailable', $progress['time_pacing']['status']);
    }

    public function test_missing_ga4_data_or_campaign_period_does_not_report_progress(): void
    {
        $target = $this->businessGoalTarget('key_events', '20.0000');
        $service = app(SocialEngagementCampaignBusinessGoalReport::class);
        $withoutReport = $service->build($target, null, false);
        $withoutPeriod = $service->build(
            $target->forceFill(['starts_on' => null, 'ends_on' => null]),
            $this->analyticsReport(['key_events' => '12.5000']),
            false,
        );

        $this->assertSame('unavailable', $withoutReport['status']);
        $this->assertNull($withoutReport['completion_percent']);
        $this->assertSame('data_unavailable', $withoutReport['time_pacing']['status']);
        $this->assertSame('missing_period', $withoutPeriod['status']);
        $this->assertNull($withoutPeriod['completion_percent']);
        $this->assertSame('missing_period', $withoutPeriod['time_pacing']['status']);
    }

    public function test_campaign_report_displays_ga4_business_goal_and_progress(): void
    {
        $this->travelTo('2026-11-15 12:00:00');
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-token',
        ]);
        $target = $this->businessGoalTarget('key_events', '20.0000');
        $this->storeCampaignAnalyticsReport($connection, $target, [
            'key_events' => '12.5000',
            'total_revenue' => '500.2500',
            'currency_code' => 'TWD',
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('GA4 商業目標')
            ->assertSee('GA4 重要事件')
            ->assertSee('62.5%')
            ->assertSee('活動時間進度比較')
            ->assertSee('已用活動時間')
            ->assertSee('50.0%')
            ->assertSee('高於線性進度')
            ->assertSee('不預測最終成果')
            ->assertSee('進度可用');

        $this->assertSame('available', $response->viewData('businessGoal')['status']);
        $this->assertSame('ahead', $response->viewData('businessGoal')['time_pacing']['status']);
    }

    public function test_campaign_report_displays_paid_media_roas_goal_attainment(): void
    {
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-token',
        ]);
        $target = $this->businessGoalTarget('key_events', '20.0000');
        $target->update([
            'paid_media_goal_source' => 'ga4',
            'paid_media_goal_roas' => '2.0000',
            'ad_spend_amount' => '200.0000',
            'ad_spend_currency' => 'TWD',
        ]);
        $this->storeCampaignAnalyticsReport($connection, $target, [
            'key_events' => '12.5000',
            'total_revenue' => '500.2500',
            'currency_code' => 'TWD',
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('付費媒體 ROAS 目標')
            ->assertSee('GA4 ROAS')
            ->assertSee('2.0000x')
            ->assertSee('2.5013x')
            ->assertSee('125.1%')
            ->assertSee('超過 0.5013x')
            ->assertSee('人工記錄花費');

        $this->assertSame('available', $response->viewData('paidMediaRoasGoal')['status']);
        $this->assertSame(125.1, $response->viewData('paidMediaRoasGoal')['attainment_percent']);
    }

    public function test_dashboard_displays_paid_media_roas_goal_attainment_for_the_campaign(): void
    {
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-token',
        ]);
        $target = $this->businessGoalTarget('key_events', '20.0000');
        $target->update([
            'paid_media_goal_source' => 'ga4',
            'paid_media_goal_roas' => '2.0000',
            'ad_spend_amount' => '200.0000',
            'ad_spend_currency' => 'TWD',
        ]);
        $this->storeCampaignAnalyticsReport($connection, $target, [
            'key_events' => '12.5000',
            'total_revenue' => '500.2500',
            'currency_code' => 'TWD',
        ]);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('GA4 ROAS目標 2.0000x')
            ->assertSee('達成率 125.1%');

        $goal = $response->viewData('socialEngagementTargets')[0]['marketing']['efficiency']['paid_media_roas_goal'];
        $this->assertSame('available', $goal['status']);
        $this->assertSame(125.1, $goal['attainment_percent']);
    }

    public function test_campaign_summary_csv_exports_the_configured_ga4_goal(): void
    {
        $this->travelTo('2026-11-15 12:00:00');
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-token',
        ]);
        $target = $this->businessGoalTarget('total_revenue', '1000.0000', 'TWD');
        $target->update([
            'paid_media_goal_source' => 'ga4',
            'paid_media_goal_roas' => '2.0000',
            'ad_spend_amount' => '200.0000',
            'ad_spend_currency' => 'TWD',
        ]);
        $this->storeCampaignAnalyticsReport($connection, $target, [
            'key_events' => '12.5000',
            'total_revenue' => '800.0000',
            'currency_code' => 'TWD',
        ]);

        $response = $this->actingAs($user)
            ->get(route('social-engagement-targets.report.export', $target))
            ->assertDownload('campaign-report-'.$target->id.'.csv');
        $rows = array_map(
            static fn (string $line): array => str_getcsv($line),
            preg_split('/\r\n|\r|\n/', trim($response->streamedContent())) ?: [],
        );
        $fields = array_combine($rows[0], $rows[1]);

        $this->assertSame('total_revenue', $fields['ga4_business_goal_metric']);
        $this->assertSame('1000.0000', $fields['ga4_business_goal_target_value']);
        $this->assertSame('TWD', $fields['ga4_business_goal_currency']);
        $this->assertSame('800.0000', $fields['ga4_business_goal_actual_value']);
        $this->assertSame('80.0', $fields['ga4_business_goal_completion_percent']);
        $this->assertSame('進度可用', $fields['ga4_business_goal_status']);
        $this->assertSame('30', $fields['ga4_business_goal_campaign_days']);
        $this->assertSame('15', $fields['ga4_business_goal_elapsed_days']);
        $this->assertSame('50.0', $fields['ga4_business_goal_elapsed_percent']);
        $this->assertSame('500.0000', $fields['ga4_business_goal_expected_to_date']);
        $this->assertSame('300.0000', $fields['ga4_business_goal_pace_gap_value']);
        $this->assertSame('30.0', $fields['ga4_business_goal_pace_gap_percentage_points']);
        $this->assertSame('高於線性進度', $fields['ga4_business_goal_pace_status']);
        $this->assertSame('ga4', $fields['paid_media_roas_goal_source']);
        $this->assertSame('GA4 ROAS', $fields['paid_media_roas_goal_source_label']);
        $this->assertSame('人工記錄花費', $fields['paid_media_roas_goal_spend_source']);
        $this->assertSame('2.0000', $fields['paid_media_roas_goal_target']);
        $this->assertSame('4.0000', $fields['paid_media_roas_actual']);
        $this->assertSame('200.0', $fields['paid_media_roas_attainment_percent']);
        $this->assertSame('2.0000', $fields['paid_media_roas_difference']);
        $this->assertSame('達成度可用', $fields['paid_media_roas_goal_status']);
    }

    public function test_campaign_report_and_csv_show_the_named_ga4_event_goal(): void
    {
        $this->travelTo('2026-11-15 12:00:00');
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-token',
        ]);
        $target = $this->businessGoalTarget('key_events', '20.0000');
        $target->update(['ga4_goal_event_name' => 'purchase']);
        $this->storeCampaignAnalyticsReport($connection, $target, [
            'key_events' => '12.5000',
            'event_name_report' => [
                'status' => 'complete',
                'window_start_on' => '2026-11-01',
                'window_end_on' => '2026-11-30',
                'truncated' => false,
                'rows' => [
                    ['event_name' => 'generate_lead', 'event_count' => 9, 'key_events' => '10.0000', 'total_revenue' => '0.0000'],
                    ['event_name' => 'purchase', 'event_count' => 3, 'key_events' => '2.5000', 'total_revenue' => '500.0000'],
                ],
            ],
            'event_name_expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($user)
            ->get(route('social-engagement-targets.report', $target))
            ->assertOk()
            ->assertSee('GA4 重要事件：purchase')
            ->assertSee('2.50')
            ->assertSee('<dd>12.5%</dd>', false);

        $csvResponse = $this->get(route('social-engagement-targets.report.export', $target))
            ->assertOk();
        $rows = array_map(
            static fn (string $line): array => str_getcsv($line),
            preg_split('/\r\n|\r|\n/', trim($csvResponse->streamedContent())) ?: [],
        );
        $fields = array_combine($rows[0], $rows[1]);

        $this->assertSame('purchase', $fields['ga4_business_goal_event_name']);
        $this->assertSame('2.5000', $fields['ga4_business_goal_actual_value']);
        $this->assertSame('12.5', $fields['ga4_business_goal_completion_percent']);
    }

    private function businessGoalTarget(
        string $metric,
        string $goalValue,
        ?string $currencyCode = null,
    ): SocialEngagementTarget {
        return SocialEngagementTarget::factory()->create([
            'tag' => 'Spring launch',
            'social_platform' => 'instagram',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'ga4_campaign_name' => 'spring-launch',
            'ga4_goal_metric' => $metric,
            'ga4_goal_value' => $goalValue,
            'ga4_goal_currency' => $currencyCode,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function analyticsReport(array $attributes): SocialEngagementTargetGoogleAnalyticsReport
    {
        $report = new SocialEngagementTargetGoogleAnalyticsReport;
        $report->forceFill([
            'key_events' => '0.0000',
            'total_revenue' => '0.0000',
            'currency_code' => null,
            'synced_at' => CarbonImmutable::now(),
            ...$attributes,
        ]);

        return $report;
    }

    /** @param array<string, mixed> $attributes */
    private function storeCampaignAnalyticsReport(
        GoogleAnalyticsConnection $connection,
        SocialEngagementTarget $target,
        array $attributes,
    ): SocialEngagementTargetGoogleAnalyticsReport {
        return SocialEngagementTargetGoogleAnalyticsReport::query()->create([
            'google_analytics_connection_id' => $connection->id,
            'social_engagement_target_id' => $target->id,
            'campaign_name' => 'spring-launch',
            'attribution_mode' => 'manual_utm',
            'campaign_id' => null,
            'source_name' => null,
            'medium_name' => null,
            'starts_on' => $target->starts_on,
            'ends_on' => $target->ends_on,
            'sessions' => 100,
            'engaged_sessions' => 80,
            'key_events' => '0.0000',
            'total_revenue' => '0.0000',
            'currency_code' => 'TWD',
            'synced_at' => now(),
            ...$attributes,
        ]);
    }
}
