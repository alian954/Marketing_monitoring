<?php

namespace Tests\Unit\Services;

use App\Services\GoogleAnalyticsEventNameCostPerKeyEventReport;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleAnalyticsEventNameCostPerKeyEventReportTest extends TestCase
{
    public function test_calculates_each_truncated_event_from_fractional_key_events(): void
    {
        $asOf = CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC');
        $eventReport = [
            'status' => 'complete',
            'currency_code' => 'TWD',
            'truncated' => true,
            'rows' => [
                ['event_name' => 'purchase', 'event_count' => 400, 'key_events' => '2.5000', 'total_revenue' => '800.2500'],
                ['event_name' => 'generate_lead', 'event_count' => 20, 'key_events' => '4.0000', 'total_revenue' => '0.0000'],
            ],
        ];
        $spendAnalysis = [
            'roas_spend_amount' => '100.0000',
            'roas_spend_currency' => 'TWD',
            'roas_spend_source' => 'meta_marketing',
            'roas_spend_source_label' => 'Meta Ads',
        ];

        $report = (new GoogleAnalyticsEventNameCostPerKeyEventReport)->attach(
            $eventReport,
            $asOf->addDay(),
            $asOf,
            $spendAnalysis,
            true,
            true,
        );

        $this->assertTrue($report['truncated']);
        $this->assertSame(40.0, $report['rows'][0]['cost_per_key_event']);
        $this->assertSame(25.0, $report['rows'][1]['cost_per_key_event']);
        $this->assertSame('TWD', $report['rows'][0]['cost_per_key_event_currency']);
        $this->assertSame('Meta Ads', $report['rows'][0]['cost_per_key_event_source_label']);
        $this->assertSame('available', $report['rows'][0]['cost_per_key_event_status']);
    }

    #[DataProvider('unavailableInputs')]
    public function test_leaves_event_cost_blank_when_any_required_input_is_unusable(
        array $eventReportOverrides,
        ?int $expiryOffsetSeconds,
        array $spendAnalysis,
        bool $googleAnalyticsSnapshotIsFresh,
        bool $spendSnapshotIsFresh,
        string $expectedStatus,
    ): void {
        $asOf = CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC');
        $eventReport = array_replace([
            'status' => 'complete',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [['event_name' => 'purchase', 'event_count' => 1, 'key_events' => '2.0000', 'total_revenue' => '20.0000']],
        ], $eventReportOverrides);
        $expiresAt = $expiryOffsetSeconds === null ? null : $asOf->addSeconds($expiryOffsetSeconds);

        $report = (new GoogleAnalyticsEventNameCostPerKeyEventReport)->attach(
            $eventReport,
            $expiresAt,
            $asOf,
            $spendAnalysis,
            $googleAnalyticsSnapshotIsFresh,
            $spendSnapshotIsFresh,
        );

        $this->assertSame($expectedStatus, $report['rows'][0]['cost_per_key_event_status']);
        $this->assertNull($report['rows'][0]['cost_per_key_event']);
    }

    /** @return array<string, array{array<string, mixed>, int|null, array<string, mixed>, bool, bool, string}> */
    public static function unavailableInputs(): array
    {
        $validSpend = [
            'roas_spend_amount' => '100.0000',
            'roas_spend_currency' => 'TWD',
            'roas_spend_source' => 'meta_marketing',
            'roas_spend_source_label' => 'Meta Ads',
        ];

        return [
            'event data not complete' => [['status' => 'thresholded'], 3600, $validSpend, true, true, 'event_report_unavailable'],
            'event expiry missing' => [[], null, $validSpend, true, true, 'event_report_expired'],
            'event expiry passed' => [[], -1, $validSpend, true, true, 'event_report_expired'],
            'GA4 snapshot stale' => [[], 3600, $validSpend, false, true, 'ga4_stale'],
            'selected paid snapshot stale' => [[], 3600, $validSpend, true, false, 'spend_stale'],
            'spend source unknown' => [[], 3600, [...$validSpend, 'roas_spend_source' => 'unknown'], true, true, 'spend_unavailable'],
            'spend missing' => [[], 3600, [...$validSpend, 'roas_spend_amount' => null], true, true, 'spend_unavailable'],
            'spend non finite' => [[], 3600, [...$validSpend, 'roas_spend_amount' => INF], true, true, 'spend_unavailable'],
            'spend is zero' => [[], 3600, [...$validSpend, 'roas_spend_amount' => '0.0000'], true, true, 'zero_spend'],
            'spend currency unknown' => [[], 3600, [...$validSpend, 'roas_spend_currency' => ''], true, true, 'currency_unknown'],
            'event currency unknown' => [['currency_code' => null], 3600, $validSpend, true, true, 'currency_unknown'],
            'event and spend currencies differ' => [['currency_code' => 'USD'], 3600, $validSpend, true, true, 'currency_mismatch'],
        ];
    }

    public function test_manual_spend_does_not_require_a_paid_platform_snapshot(): void
    {
        $asOf = CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC');
        $eventReport = [
            'status' => 'complete',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [['event_name' => 'purchase', 'event_count' => 10, 'key_events' => '2.0000', 'total_revenue' => '20.0000']],
        ];
        $manualSpend = [
            'roas_spend_amount' => '80.0000',
            'roas_spend_currency' => 'TWD',
            'roas_spend_source' => 'manual',
            'roas_spend_source_label' => '人工記錄花費',
        ];

        $report = (new GoogleAnalyticsEventNameCostPerKeyEventReport)->attach(
            $eventReport,
            $asOf->addDay(),
            $asOf,
            $manualSpend,
            true,
            false,
        );

        $this->assertSame(40.0, $report['rows'][0]['cost_per_key_event']);
    }

    public function test_displays_no_cost_for_an_event_without_key_events(): void
    {
        $asOf = CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC');
        $eventReport = [
            'status' => 'complete',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [['event_name' => 'page_view', 'event_count' => 100, 'key_events' => '0.0000', 'total_revenue' => '0.0000']],
        ];
        $spendAnalysis = [
            'roas_spend_amount' => '80.0000',
            'roas_spend_currency' => 'TWD',
            'roas_spend_source' => 'google_ads',
            'roas_spend_source_label' => 'Google Ads',
        ];

        $report = (new GoogleAnalyticsEventNameCostPerKeyEventReport)->attach(
            $eventReport,
            $asOf->addDay(),
            $asOf,
            $spendAnalysis,
            true,
            true,
        );

        $this->assertSame('no_key_events', $report['rows'][0]['cost_per_key_event_status']);
        $this->assertNull($report['rows'][0]['cost_per_key_event']);
    }

    public function test_hides_a_cost_that_overflows_to_a_non_finite_value(): void
    {
        $asOf = CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC');
        $eventReport = [
            'status' => 'complete',
            'currency_code' => 'TWD',
            'truncated' => false,
            'rows' => [['event_name' => 'purchase', 'event_count' => 1, 'key_events' => '0.00000001', 'total_revenue' => '0.0000']],
        ];
        $spendAnalysis = [
            'roas_spend_amount' => '1e308',
            'roas_spend_currency' => 'TWD',
            'roas_spend_source' => 'google_ads',
            'roas_spend_source_label' => 'Google Ads 花費',
        ];

        $report = (new GoogleAnalyticsEventNameCostPerKeyEventReport)->attach(
            $eventReport,
            $asOf->addDay(),
            $asOf,
            $spendAnalysis,
            true,
            true,
        );

        $this->assertSame('cost_unavailable', $report['rows'][0]['cost_per_key_event_status']);
        $this->assertNull($report['rows'][0]['cost_per_key_event']);
    }
}
