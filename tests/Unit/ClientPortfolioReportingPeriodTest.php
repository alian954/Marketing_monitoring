<?php

namespace Tests\Unit;

use App\Models\SocialEngagementTarget;
use App\Services\ClientPortfolioReportingPeriod;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class ClientPortfolioReportingPeriodTest extends TestCase
{
    public function test_it_recalculates_ga4_sessions_and_roas_for_the_selected_days(): void
    {
        $target = $this->target();
        $summaries = [1 => [
            'google_analytics' => ['sessions' => 321, 'revenue' => '321.0000', 'currency_code' => 'TWD'],
            'meta_marketing' => ['spend_amount' => '321.0000', 'currency_code' => 'TWD'],
            'google_ads' => ['spend_amount' => null, 'currency_code' => null],
            'efficiency' => [
                'roas' => 1.0,
                'roas_unrounded' => 1.0,
                'roas_spend_source' => 'meta_marketing',
                'roas_spend_amount' => '321.0000',
                'roas_spend_currency' => 'TWD',
            ],
            'daily_performance' => [
                'google_analytics' => ['status' => 'complete', 'coverage_start_on' => '2026-11-01', 'coverage_end_on' => '2026-11-30', 'rows' => [
                    ['date' => '2026-11-02', 'sessions' => 22, 'total_revenue' => '22.0000'],
                    ['date' => '2026-11-03', 'sessions' => 33, 'total_revenue' => '33.0000'],
                ]],
                'meta_marketing' => ['status' => 'complete', 'coverage_start_on' => '2026-11-01', 'coverage_end_on' => '2026-11-30', 'rows' => [
                    ['date' => '2026-11-02', 'spend_amount' => '20.0000'],
                    ['date' => '2026-11-03', 'spend_amount' => '30.0000'],
                ]],
                'google_ads' => ['status' => 'missing', 'coverage_start_on' => null, 'coverage_end_on' => null, 'rows' => []],
            ],
        ]];

        $result = (new ClientPortfolioReportingPeriod)->apply(
            new Collection([$target]),
            $summaries,
            '2026-11-02',
            '2026-11-03',
        );
        $marketing = $result['marketing_summaries'][1];

        $this->assertSame(55, $marketing['google_analytics']['sessions']);
        $this->assertSame('55.0000', $marketing['google_analytics']['revenue']);
        $this->assertSame('50.0000', $marketing['meta_marketing']['spend_amount']);
        $this->assertSame(1.1, $marketing['efficiency']['roas']);
        $this->assertSame('50.0000', $marketing['efficiency']['roas_spend_amount']);
    }

    public function test_it_hides_full_campaign_manual_roas_for_a_partial_reporting_period(): void
    {
        $target = $this->target(['ad_spend_amount' => '100.0000', 'ad_spend_currency' => 'TWD']);
        $summaries = [1 => [
            'google_analytics' => ['sessions' => 30, 'revenue' => '300.0000', 'currency_code' => 'TWD'],
            'meta_marketing' => ['spend_amount' => null, 'currency_code' => null],
            'google_ads' => ['spend_amount' => null, 'currency_code' => null],
            'efficiency' => [
                'roas' => 3.0,
                'roas_unrounded' => 3.0,
                'roas_spend_source' => 'manual',
                'roas_spend_amount' => '100.0000',
                'roas_spend_currency' => 'TWD',
            ],
            'daily_performance' => [
                'google_analytics' => ['status' => 'complete', 'coverage_start_on' => '2026-11-01', 'coverage_end_on' => '2026-11-30', 'rows' => [
                    ['date' => '2026-11-02', 'sessions' => 10, 'total_revenue' => '100.0000'],
                ]],
                'meta_marketing' => ['status' => 'missing', 'coverage_start_on' => null, 'coverage_end_on' => null, 'rows' => []],
                'google_ads' => ['status' => 'missing', 'coverage_start_on' => null, 'coverage_end_on' => null, 'rows' => []],
            ],
        ]];

        $result = (new ClientPortfolioReportingPeriod)->apply(
            new Collection([$target]),
            $summaries,
            '2026-11-02',
            '2026-11-03',
        );
        $efficiency = $result['marketing_summaries'][1]['efficiency'];

        $this->assertNull($efficiency['roas']);
        $this->assertSame('period_data_unavailable', $efficiency['roas_unavailable_reason']);
    }

    /** @param array<string, mixed> $overrides */
    private function target(array $overrides = []): SocialEngagementTarget
    {
        $target = new SocialEngagementTarget(array_replace([
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ], $overrides));
        $target->setAttribute('id', 1);

        return $target;
    }
}
