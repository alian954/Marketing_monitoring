<?php

namespace Tests\Unit;

use App\Services\CampaignDailyMarketingTrendChart;
use PHPUnit\Framework\TestCase;

class CampaignDailyMarketingTrendChartTest extends TestCase
{
    public function test_trend_orders_points_and_leaves_gaps_for_missing_or_unavailable_days(): void
    {
        $chart = (new CampaignDailyMarketingTrendChart)->build([
            'requested_start_on' => '2026-11-01',
            'requested_end_on' => '2026-11-07',
            'coverage_start_on' => '2026-11-01',
            'coverage_end_on' => '2026-11-07',
            'currency_code' => 'TWD',
            'rows' => [
                ['date' => '2026-11-07', 'sessions' => 4],
                ['date' => '2026-11-05', 'sessions' => 5],
                ['date' => '2026-11-03', 'sessions' => null],
                ['date' => '2026-11-02', 'sessions' => 10],
                ['date' => '2026-11-01', 'sessions' => 0],
            ],
        ], 'sessions', '工作階段', 'integer');

        $this->assertSame([
            '2026-11-01',
            '2026-11-02',
            '2026-11-05',
            '2026-11-07',
        ], array_column($chart['points'], 'date'));
        $this->assertSame([0.0, 10.0, 5.0, 4.0], array_column($chart['points'], 'value'));
        $this->assertSame('10', $chart['maximum_label']);
        $this->assertCount(1, $chart['segments']);
        $this->assertSame(48.0, $chart['points'][0]['x']);
        $this->assertSame(154.0, $chart['points'][0]['y']);
        $this->assertSame(400.0, $chart['points'][2]['x']);
        $this->assertSame(24.0, $chart['points'][1]['y']);
    }

    public function test_single_day_zero_value_remains_a_visible_chart_point(): void
    {
        $chart = (new CampaignDailyMarketingTrendChart)->build([
            'requested_start_on' => '2026-11-02',
            'requested_end_on' => '2026-11-02',
            'coverage_start_on' => '2026-11-02',
            'coverage_end_on' => '2026-11-02',
            'currency_code' => 'TWD',
            'rows' => [
                ['date' => '2026-11-02', 'spend_amount' => '0.0000'],
            ],
        ], 'spend_amount', '花費', 'currency');

        $this->assertCount(1, $chart['points']);
        $this->assertSame('TWD 0.00', $chart['points'][0]['value_label']);
        $this->assertSame('TWD 0.00', $chart['maximum_label']);
        $this->assertSame(312.0, $chart['points'][0]['x']);
        $this->assertSame(154.0, $chart['points'][0]['y']);
        $this->assertSame([], $chart['segments']);
    }

    public function test_chart_excludes_invalid_negative_and_out_of_coverage_values(): void
    {
        $chart = (new CampaignDailyMarketingTrendChart)->build([
            'requested_start_on' => '2026-11-01',
            'requested_end_on' => '2026-11-30',
            'coverage_start_on' => '2026-11-05',
            'coverage_end_on' => '2026-11-28',
            'currency_code' => 'TWD',
            'rows' => [
                ['date' => '2026-11-04', 'spend_amount' => '100.00'],
                ['date' => '2026-11-05', 'spend_amount' => 'invalid'],
                ['date' => '2026-11-06', 'spend_amount' => '-1.00'],
                ['date' => '2026-11-07', 'spend_amount' => '25.50'],
            ],
        ], 'spend_amount', '花費', 'currency');

        $this->assertSame(['2026-11-07'], array_column($chart['points'], 'date'));
        $this->assertSame('TWD 25.50', $chart['maximum_label']);
        $this->assertSame('2026-11-05', $chart['coverage_start_on']);
        $this->assertSame('2026-11-28', $chart['coverage_end_on']);
    }
}
