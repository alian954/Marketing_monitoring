<?php

namespace Tests\Unit;

use App\Services\MonitorSloBudgetCalculator;
use PHPUnit\Framework\TestCase;

class MonitorSloBudgetCalculatorTest extends TestCase
{
    public function test_calculates_allowed_used_and_remaining_error_budget_with_low_coverage(): void
    {
        $report = (new MonitorSloBudgetCalculator)->calculate([
            'eligible_minutes' => 43_200.0,
            'observed_minutes' => 3.0,
            'unavailable_minutes' => 1.5,
            'coverage_rate' => 0.01,
            'eligible_seconds' => 2_592_000,
            'observed_seconds' => 180,
            'up_seconds' => 90,
        ], 9990);

        $this->assertSame(99.9, $report['target_percent']);
        $this->assertSame(43.2, $report['allowed_downtime_minutes']);
        $this->assertSame(1.5, $report['observed_downtime_minutes']);
        $this->assertSame(41.7, $report['remaining_error_budget_minutes']);
        $this->assertSame(3.47, $report['error_budget_used_percent']);
        $this->assertTrue($report['low_coverage']);
    }

    public function test_does_not_report_unused_budget_when_there_are_no_observations(): void
    {
        $report = (new MonitorSloBudgetCalculator)->calculate([
            'eligible_minutes' => 43_200.0,
            'observed_minutes' => 0.0,
            'unavailable_minutes' => 0.0,
            'coverage_rate' => 0.0,
            'eligible_seconds' => 2_592_000,
            'observed_seconds' => 0,
            'up_seconds' => 0,
        ], 9990);

        $this->assertSame(43.2, $report['allowed_downtime_minutes']);
        $this->assertNull($report['observed_downtime_minutes']);
        $this->assertNull($report['remaining_error_budget_minutes']);
        $this->assertNull($report['error_budget_used_percent']);
        $this->assertFalse($report['has_observations']);
    }

    public function test_reports_no_eligible_time_when_the_entire_window_is_maintenance(): void
    {
        $report = (new MonitorSloBudgetCalculator)->calculate([
            'eligible_minutes' => 0.0,
            'observed_minutes' => 0.0,
            'unavailable_minutes' => 0.0,
            'coverage_rate' => null,
            'eligible_seconds' => 0,
            'observed_seconds' => 0,
            'up_seconds' => 0,
        ], 9990);

        $this->assertSame(0.0, $report['allowed_downtime_minutes']);
        $this->assertFalse($report['has_eligible_time']);
        $this->assertFalse($report['has_observations']);
    }
}
