<?php

namespace Tests\Unit\Services;

use App\Services\GoogleAnalyticsEventNamePerformanceReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleAnalyticsEventNamePerformanceReportTest extends TestCase
{
    #[DataProvider('validSorts')]
    public function test_sorts_valid_events_by_the_selected_metric(string $sort, array $expectedEventNames): void
    {
        $report = (new GoogleAnalyticsEventNamePerformanceReport)->build([
            'status' => 'complete',
            'rows' => [
                ['event_name' => 'zeta', 'event_count' => 30, 'key_events' => '0.0000', 'total_revenue' => '100.0000'],
                ['event_name' => 'beta', 'event_count' => 10, 'key_events' => '5.0000', 'total_revenue' => '200.0000'],
                ['event_name' => 'alpha', 'event_count' => 20, 'key_events' => '1.0000', 'total_revenue' => '300.0000'],
            ],
        ], sort: $sort);

        $this->assertSame($expectedEventNames, array_column($report['rows'], 'event_name'));
        $this->assertSame($sort, $report['sort']);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function validSorts(): array
    {
        return [
            'event count' => ['event_count', ['zeta', 'alpha', 'beta']],
            'key events' => ['key_events', ['beta', 'alpha', 'zeta']],
            'revenue' => ['total_revenue', ['alpha', 'beta', 'zeta']],
            'event name' => ['event_name', ['alpha', 'beta', 'zeta']],
        ];
    }

    public function test_uses_default_sort_and_excludes_malformed_event_rows(): void
    {
        $report = (new GoogleAnalyticsEventNamePerformanceReport)->build([
            'status' => 'complete',
            'rows' => [
                ['event_name' => 'purchase', 'event_count' => 5, 'key_events' => '2.0000', 'total_revenue' => '100.0000'],
                ['event_name' => 'invalid-count', 'event_count' => '2.5', 'key_events' => '9.0000', 'total_revenue' => '0.0000'],
                ['event_name' => 'invalid-revenue', 'event_count' => 9, 'key_events' => '1.0000', 'total_revenue' => 'unknown'],
            ],
        ], sort: 'not-a-column');

        $this->assertSame('key_events', $report['sort']);
        $this->assertSame(['purchase'], array_column($report['rows'], 'event_name'));
    }
}
