<?php

namespace App\Services;

class GoogleAdsDeviceReportCsv
{
    /**
     * @param  array{currency_code: string|null, rows: list<array<string, mixed>>}  $report
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function build(array $report): array
    {
        $rows = [];
        $currencyCode = $report['currency_code'] ?? '';

        foreach ($report['rows'] as $row) {
            $rows[] = [
                (string) ($row['device'] ?? ''),
                (string) ($row['device_label'] ?? ''),
                (string) ($row['impressions'] ?? ''),
                (string) ($row['clicks'] ?? ''),
                $row['ctr_percent'] === null ? '' : (string) $row['ctr_percent'],
                (string) ($row['spend_amount'] ?? ''),
                $row['cost_per_click'] === null ? '' : (string) $row['cost_per_click'],
                (string) ($row['conversions'] ?? ''),
                $row['cost_per_conversion'] === null ? '' : (string) $row['cost_per_conversion'],
                (string) ($row['conversions_value'] ?? ''),
                $row['roas'] === null ? '' : (string) $row['roas'],
                (string) $currencyCode,
            ];
        }

        return [
            'headers' => [
                'device',
                'device_label',
                'impressions',
                'clicks',
                'ctr_percent',
                'spend_amount',
                'cost_per_click',
                'conversions',
                'cost_per_conversion',
                'conversions_value',
                'roas',
                'currency_code',
            ],
            'rows' => $rows,
        ];
    }
}
