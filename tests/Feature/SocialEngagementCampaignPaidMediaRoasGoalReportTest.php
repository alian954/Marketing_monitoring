<?php

namespace Tests\Feature;

use App\Models\SocialEngagementTarget;
use App\Services\SocialEngagementCampaignPaidMediaRoasGoalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialEngagementCampaignPaidMediaRoasGoalReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculates_roas_goal_attainment_for_ga4_meta_ads_and_google_ads(): void
    {
        $service = app(SocialEngagementCampaignPaidMediaRoasGoalReport::class);
        $cases = [
            ['ga4', '2.5000', 'roas', 2.0, 80.0, -0.5, 'Google Ads 花費'],
            ['meta_ads', '3.0000', 'meta_ads_purchase_roas', 1.5, 50.0, -1.5, null],
            ['google_ads', '3.0000', 'google_ads_conversion_value_roas', 4.5, 150.0, 1.5, null],
            ['meta_ads', '2.0000', 'meta_ads_purchase_roas', 0.0, 0.0, -2.0, null],
        ];

        foreach ($cases as [$source, $targetRoas, $metric, $actualRoas, $attainment, $difference, $spendSourceLabel]) {
            $target = $this->goalTarget($source, $targetRoas);
            $analysis = $this->efficiency([$metric => $actualRoas]);

            if ($source === 'ga4') {
                $analysis['roas_spend_source'] = 'google_ads';
                $analysis['roas_spend_source_label'] = 'Google Ads 花費';
            }

            $goal = $service->build($target, $analysis, false, false, false);

            $this->assertSame('available', $goal['status']);
            $this->assertSame($actualRoas, $goal['actual_roas']);
            $this->assertSame($attainment, $goal['attainment_percent']);
            $this->assertSame($difference, $goal['difference_roas']);
            $this->assertSame($spendSourceLabel, $goal['spend_source_label']);
        }
    }

    public function test_ga4_goal_requires_fresh_analytics_and_the_selected_spend_source(): void
    {
        $service = app(SocialEngagementCampaignPaidMediaRoasGoalReport::class);
        $target = $this->goalTarget('ga4', '2.0000');
        $analysis = $this->efficiency([
            'roas' => 2.5,
            'roas_spend_source' => 'google_ads',
            'roas_spend_source_label' => 'Google Ads 花費',
        ]);

        $staleAnalytics = $service->build($target, $analysis, true, false, false);
        $staleSpendSource = $service->build($target, $analysis, false, false, true);

        $this->assertSame('stale', $staleAnalytics['status']);
        $this->assertSame(2.5, $staleAnalytics['actual_roas']);
        $this->assertNull($staleAnalytics['attainment_percent']);
        $this->assertNull($staleAnalytics['difference_roas']);
        $this->assertSame('stale', $staleSpendSource['status']);
        $this->assertNull($staleSpendSource['attainment_percent']);
    }

    public function test_goal_comparison_uses_unrounded_platform_roas_near_the_target_threshold(): void
    {
        $goal = app(SocialEngagementCampaignPaidMediaRoasGoalReport::class)->build(
            $this->goalTarget('ga4', '2.0030'),
            $this->efficiency([
                'roas' => 2.0,
                'roas_unrounded' => 2.004,
            ]),
            false,
            false,
            false,
        );

        $this->assertSame(2.004, $goal['actual_roas']);
        $this->assertSame(100.0, $goal['attainment_percent']);
        $this->assertSame(0.001, $goal['difference_roas']);
    }

    public function test_meta_and_google_ads_goals_do_not_compare_stale_snapshots(): void
    {
        $service = app(SocialEngagementCampaignPaidMediaRoasGoalReport::class);
        $cases = [
            ['meta_ads', 'meta_ads_purchase_roas', [false, true, false]],
            ['google_ads', 'google_ads_conversion_value_roas', [false, false, true]],
        ];

        foreach ($cases as [$source, $metric, $freshness]) {
            $goal = $service->build(
                $this->goalTarget($source, '2.0000'),
                $this->efficiency([$metric => 2.5]),
                ...$freshness,
            );

            $this->assertSame('stale', $goal['status']);
            $this->assertSame(2.5, $goal['actual_roas']);
            $this->assertNull($goal['attainment_percent']);
            $this->assertNull($goal['difference_roas']);
        }
    }

    public function test_missing_period_data_and_currency_mismatch_do_not_fabricate_attainment(): void
    {
        $service = app(SocialEngagementCampaignPaidMediaRoasGoalReport::class);
        $target = $this->goalTarget('ga4', '2.0000');
        $currencyMismatch = $service->build(
            $target,
            $this->efficiency(['roas_unavailable_reason' => 'currency_mismatch']),
            false,
            false,
            false,
        );
        $zeroSpend = $service->build(
            $target,
            $this->efficiency(['roas_unavailable_reason' => 'zero_spend']),
            false,
            false,
            false,
        );
        $missingReport = $service->build(
            $this->goalTarget('meta_ads', '2.0000'),
            $this->efficiency(),
            false,
            false,
            false,
        );
        $missingPeriod = $service->build(
            $target->forceFill(['starts_on' => null, 'ends_on' => null]),
            $this->efficiency(['roas' => 2.5]),
            false,
            false,
            false,
        );
        $unconfigured = $service->build(
            SocialEngagementTarget::factory()->create(),
            $this->efficiency(['roas' => 2.5]),
            false,
            false,
            false,
        );

        $this->assertSame('currency_mismatch', $currencyMismatch['status']);
        $this->assertNull($currencyMismatch['attainment_percent']);
        $this->assertSame('zero_spend', $zeroSpend['status']);
        $this->assertNull($zeroSpend['attainment_percent']);
        $this->assertSame('unavailable', $missingReport['status']);
        $this->assertNull($missingReport['actual_roas']);
        $this->assertSame('missing_period', $missingPeriod['status']);
        $this->assertNull($missingPeriod['actual_roas']);
        $this->assertSame('not_configured', $unconfigured['status']);
        $this->assertNull($unconfigured['attainment_percent']);
    }

    private function goalTarget(string $source, string $targetRoas): SocialEngagementTarget
    {
        return SocialEngagementTarget::factory()->create([
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'paid_media_goal_source' => $source,
            'paid_media_goal_roas' => $targetRoas,
        ]);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function efficiency(array $overrides = []): array
    {
        return array_replace([
            'roas' => null,
            'roas_unrounded' => null,
            'roas_spend_source' => null,
            'roas_spend_source_label' => null,
            'roas_unavailable_reason' => null,
            'meta_ads_purchase_roas' => null,
            'google_ads_conversion_value_roas' => null,
        ], $overrides);
    }
}
