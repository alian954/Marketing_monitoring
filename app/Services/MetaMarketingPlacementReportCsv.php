<?php

namespace App\Services;

class MetaMarketingPlacementReportCsv
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
                (string) ($row['publisher_platform'] ?? ''),
                (string) ($row['platform_position'] ?? ''),
                (string) ($row['impressions'] ?? ''),
                ($row['reach'] ?? null) === null ? '' : (string) $row['reach'],
                (string) ($row['clicks'] ?? ''),
                $row['ctr_percent'] === null ? '' : (string) $row['ctr_percent'],
                (string) ($row['spend_amount'] ?? ''),
                $row['cost_per_click'] === null ? '' : (string) $row['cost_per_click'],
                $row['cost_per_thousand_impressions'] === null ? '' : (string) $row['cost_per_thousand_impressions'],
                $row['purchases'] === null ? '' : (string) $row['purchases'],
                $row['purchase_value'] === null ? '' : (string) $row['purchase_value'],
                (string) $currencyCode,
            ];
        }

        return [
            'headers' => [
                'publisher_platform',
                'platform_position',
                'impressions',
                'reach',
                'clicks',
                'ctr_percent',
                'spend_amount',
                'cost_per_click',
                'cost_per_thousand_impressions',
                'omni_purchase_actions',
                'omni_purchase_value',
                'currency_code',
            ],
            'rows' => $rows,
        ];
    }
}
