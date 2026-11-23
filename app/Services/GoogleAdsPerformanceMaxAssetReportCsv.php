<?php

namespace App\Services;

class GoogleAdsPerformanceMaxAssetReportCsv
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
                (string) ($row['asset_group_id'] ?? ''),
                (string) ($row['asset_group_name'] ?? ''),
                (string) ($row['asset_id'] ?? ''),
                (string) ($row['asset_name'] ?? ''),
                (string) ($row['field_type'] ?? ''),
                (string) ($row['asset_type'] ?? ''),
                (string) ($row['content'] ?? ''),
                (string) ($row['youtube_video_id'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['primary_status'] ?? ''),
                implode('|', $row['primary_status_reasons'] ?? []),
                (string) ($row['source'] ?? ''),
                (string) ($row['impressions'] ?? ''),
                (string) ($row['clicks'] ?? ''),
                $row['ctr_percent'] === null ? '' : (string) $row['ctr_percent'],
                (string) ($row['spend_amount'] ?? ''),
                $row['cost_per_click'] === null ? '' : (string) $row['cost_per_click'],
                $row['conversions'] === null ? '' : (string) $row['conversions'],
                $row['cost_per_conversion'] === null ? '' : (string) $row['cost_per_conversion'],
                (string) ($row['conversions_value'] ?? ''),
                $row['roas'] === null ? '' : (string) $row['roas'],
                (string) $currencyCode,
            ];
        }

        return [
            'headers' => [
                'asset_group_id',
                'asset_group_name',
                'asset_id',
                'asset_name',
                'field_type',
                'asset_type',
                'content',
                'youtube_video_id',
                'status',
                'primary_status',
                'primary_status_reasons',
                'source',
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
