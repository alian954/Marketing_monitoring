<?php

namespace App\Services;

class GoogleAdsResponsiveSearchAdAssetReportCsv
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
                (string) ($row['ad_group_id'] ?? ''),
                (string) ($row['ad_group_name'] ?? ''),
                (string) ($row['ad_id'] ?? ''),
                (string) ($row['asset_id'] ?? ''),
                (string) ($row['field_type'] ?? ''),
                (string) ($row['text'] ?? ''),
                (string) ($row['source'] ?? ''),
                (string) ($row['pinned_field'] ?? ''),
                (string) ($row['performance_label'] ?? ''),
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
                'ad_group_id',
                'ad_group_name',
                'ad_id',
                'asset_id',
                'field_type',
                'asset_text',
                'source',
                'pinned_field',
                'performance_label',
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
