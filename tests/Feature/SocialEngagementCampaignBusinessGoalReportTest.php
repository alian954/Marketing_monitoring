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
        $this->assertSame('missing_period', $withoutPeriod['status']);
        $this->assertNull($withoutPeriod['completion_percent']);
    }

    public function test_campaign_report_displays_ga4_business_goal_and_progress(): void
    {
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
            ->assertSee('進度可用');

        $this->assertSame('available', $response->viewData('businessGoal')['status']);
    }

    public function test_campaign_summary_csv_exports_the_configured_ga4_goal(): void
    {
        $user = User::factory()->create();
        $connection = GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'property_id' => '123456789',
            'refresh_token' => 'encrypted-token',
        ]);
        $target = $this->businessGoalTarget('total_revenue', '1000.0000', 'TWD');
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
