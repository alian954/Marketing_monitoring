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
                'cost_per_key_event' => 25.25,
                'cost_per_key_event_currency' => 'TWD',
                'cost_per_key_event_source_label' => 'Meta Ads',
                'cost_per_key_event_status' => 'available',
                'cost_per_key_event_status_label' => '參考值',
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
            'cost_per_key_event_reference',
            'cost_per_key_event_currency_code',
            'cost_per_key_event_source',
            'cost_per_key_event_status',
            'cost_per_key_event_status_label',
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
            '25.2500',
            'TWD',
            'Meta Ads',
            'available',
            '參考值',
        ]], $csv['rows']);
    }
}
