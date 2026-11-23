<?php

namespace Tests\Unit\Services;

use App\Services\GoogleAnalyticsEventNamePerformanceReport;
use Carbon\CarbonImmutable;
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
        ], CarbonImmutable::parse('2099-01-01 00:00:00', 'UTC'), sort: $sort);

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
        ], CarbonImmutable::parse('2099-01-01 00:00:00', 'UTC'), sort: 'not-a-column');

        $this->assertSame('key_events', $report['sort']);
        $this->assertSame(['purchase'], array_column($report['rows'], 'event_name'));
    }

    public function test_hides_complete_event_rows_when_retention_expiration_is_missing(): void
    {
        $report = (new GoogleAnalyticsEventNamePerformanceReport)->build([
            'status' => 'complete',
            'rows' => [['event_name' => 'purchase', 'event_count' => 1, 'key_events' => '1.0000', 'total_revenue' => '10.0000']],
        ]);

        $this->assertSame('expired', $report['status']);
        $this->assertSame([], $report['rows']);
    }

    public function test_excludes_non_finite_key_events_and_revenue(): void
    {
        $report = (new GoogleAnalyticsEventNamePerformanceReport)->build([
            'status' => 'complete',
            'rows' => [
                ['event_name' => 'valid', 'event_count' => 1, 'key_events' => '1.0000', 'total_revenue' => '10.0000'],
                ['event_name' => 'infinite-key-events', 'event_count' => 1, 'key_events' => INF, 'total_revenue' => '10.0000'],
                ['event_name' => 'infinite-revenue', 'event_count' => 1, 'key_events' => '1.0000', 'total_revenue' => INF],
            ],
        ], CarbonImmutable::parse('2099-01-01 00:00:00', 'UTC'));

        $this->assertSame(['valid'], array_column($report['rows'], 'event_name'));
    }
}
