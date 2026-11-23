<?php

namespace Tests\Unit\Services;

use App\Services\GoogleAnalyticsEventNameReportCsv;
use PHPUnit\Framework\TestCase;

class GoogleAnalyticsEventNameReportCsvTest extends TestCase
{
    public function test_exports_event_metrics_and_campaign_metadata(): void
    {
        $csv = (new GoogleAnalyticsEventNameReportCsv)->build([
            'currency_code' => 'TWD',
            'window_start_on' => '2026-11-01',
            'window_end_on' => '2026-11-30',
            'truncated' => true,
            'rows' => [[
                'event_name' => 'purchase',
                'event_count' => 8,
                'key_events' => '3.0000',
                'total_revenue' => '950.5000',
            ]],
        ]);

        $this->assertSame([
            'event_name',
            'event_count',
            'key_events',
            'total_revenue',
            'currency_code',
            'window_start_on',
            'window_end_on',
            'truncated',
        ], $csv['headers']);
        $this->assertSame([[
            'purchase',
            '8',
            '3.0000',
            '950.5000',
            'TWD',
            '2026-11-01',
            '2026-11-30',
            'true',
        ]], $csv['rows']);
    }
}
