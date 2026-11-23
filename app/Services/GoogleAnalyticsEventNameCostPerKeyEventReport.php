<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class GoogleAnalyticsEventNameCostPerKeyEventReport
{
    /**
     * @param  array<string, mixed>  $eventNameReport
     * @param  array<string, mixed>  $spendAnalysis
     * @return array<string, mixed>
     */
    public function attach(
        array $eventNameReport,
        ?CarbonImmutable $eventNameExpiresAt,
        CarbonImmutable $asOf,
        array $spendAnalysis,
        bool $googleAnalyticsSnapshotIsFresh,
        bool $spendSnapshotIsFresh,
    ): array {
        $spendAmount = $this->finiteNumber($spendAnalysis['roas_spend_amount'] ?? null);
        $spendCurrency = $this->currencyCode($spendAnalysis['roas_spend_currency'] ?? null);
        $eventCurrency = $this->currencyCode($eventNameReport['currency_code'] ?? null);
        $spendSource = $spendAnalysis['roas_spend_source'] ?? null;
        $spendSourceLabel = is_string($spendAnalysis['roas_spend_source_label'] ?? null)
            ? $spendAnalysis['roas_spend_source_label']
            : null;
        $baseStatus = $this->baseStatus(
            $eventNameReport,
            $eventNameExpiresAt,
            $asOf,
            $spendAmount,
            $spendCurrency,
            $eventCurrency,
            $spendSource,
            $googleAnalyticsSnapshotIsFresh,
            $spendSnapshotIsFresh,
        );
        $rows = is_array($eventNameReport['rows'] ?? null) ? $eventNameReport['rows'] : [];

        $eventNameReport['rows'] = array_map(function (array $row) use (
            $baseStatus,
            $spendAmount,
            $spendCurrency,
            $spendSourceLabel,
        ): array {
            $status = $baseStatus;
            $cost = null;

            if ($status === 'available') {
                $keyEvents = $this->finiteNumber($row['key_events'] ?? null);

                if ($keyEvents === null) {
                    $status = 'invalid_key_events';
                } elseif ($keyEvents <= 0) {
                    $status = 'no_key_events';
                } else {
                    $calculatedCost = $spendAmount / $keyEvents;

                    if (is_finite($calculatedCost)) {
                        $cost = $calculatedCost;
                    } else {
                        $status = 'cost_unavailable';
                    }
                }
            }

            $row['cost_per_key_event'] = $cost;
            $row['cost_per_key_event_currency'] = $status === 'available' ? $spendCurrency : null;
            $row['cost_per_key_event_source_label'] = $spendSourceLabel;
            $row['cost_per_key_event_status'] = $status;
            $row['cost_per_key_event_status_label'] = $this->statusLabel($status);

            return $row;
        }, array_values(array_filter($rows, 'is_array')));

        return $eventNameReport;
    }

    /** @param array<string, mixed> $eventNameReport */
    private function baseStatus(
        array $eventNameReport,
        ?CarbonImmutable $eventNameExpiresAt,
        CarbonImmutable $asOf,
        ?float $spendAmount,
        ?string $spendCurrency,
        ?string $eventCurrency,
        mixed $spendSource,
        bool $googleAnalyticsSnapshotIsFresh,
        bool $spendSnapshotIsFresh,
    ): string {
        if (($eventNameReport['status'] ?? null) !== 'complete') {
            return 'event_report_unavailable';
        }

        if ($eventNameExpiresAt === null || $eventNameExpiresAt->lessThanOrEqualTo($asOf)) {
            return 'event_report_expired';
        }

        if (! $googleAnalyticsSnapshotIsFresh) {
            return 'ga4_stale';
        }

        if (! in_array($spendSource, ['meta_marketing', 'google_ads', 'manual'], true) || $spendAmount === null) {
            return 'spend_unavailable';
        }

        if ($spendSource !== 'manual' && ! $spendSnapshotIsFresh) {
            return 'spend_stale';
        }

        if ($spendAmount <= 0) {
            return 'zero_spend';
        }

        if ($spendCurrency === null || $eventCurrency === null) {
            return 'currency_unknown';
        }

        if ($spendCurrency !== $eventCurrency) {
            return 'currency_mismatch';
        }

        return 'available';
    }

    private function finiteNumber(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function currencyCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $currencyCode = strtoupper(trim($value));

        return preg_match('/^[A-Z]{3}$/D', $currencyCode) === 1 ? $currencyCode : null;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'available' => '參考值',
            'event_report_expired' => 'GA4 事件資料已過期',
            'event_report_unavailable' => 'GA4 事件資料不可用',
            'ga4_stale' => 'GA4 讀值已超過 24 小時',
            'spend_stale' => '花費讀值已超過 24 小時',
            'zero_spend' => '花費為零',
            'currency_unknown' => '幣別未知',
            'currency_mismatch' => '幣別不同',
            'no_key_events' => '尚無重要事件',
            'invalid_key_events' => '事件數值無效',
            'cost_unavailable' => '成本數值無法計算',
            default => '缺少可用花費',
        };
    }
}
