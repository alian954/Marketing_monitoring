<?php

namespace App\Services;

class MetaMarketingAdReportCsv
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
                (string) ($row['ad_id'] ?? ''),
                (string) ($row['ad_name'] ?? ''),
                (string) ($row['adset_id'] ?? ''),
                (string) ($row['adset_name'] ?? ''),
                (string) ($row['impressions'] ?? ''),
                (string) ($row['reach'] ?? ''),
                (string) ($row['clicks'] ?? ''),
                $row['ctr_percent'] === null ? '' : (string) $row['ctr_percent'],
                (string) ($row['spend_amount'] ?? ''),
                $row['cost_per_click'] === null ? '' : (string) $row['cost_per_click'],
                $row['cost_per_thousand_impressions'] === null ? '' : (string) $row['cost_per_thousand_impressions'],
                $row['purchases'] === null ? '' : (string) $row['purchases'],
                $row['purchase_value'] === null ? '' : (string) $row['purchase_value'],
                $row['purchase_roas'] === null ? '' : (string) $row['purchase_roas'],
                $row['cost_per_purchase'] === null ? '' : (string) $row['cost_per_purchase'],
                (string) $currencyCode,
            ];
        }

        return [
            'headers' => [
                'ad_id',
                'ad_name',
                'adset_id',
                'adset_name',
                'impressions',
                'reach',
                'clicks',
                'ctr_percent',
                'spend_amount',
                'cost_per_click',
                'cost_per_thousand_impressions',
                'omni_purchase_actions',
                'omni_purchase_value',
                'purchase_roas',
                'cost_per_purchase',
                'currency_code',
            ],
            'rows' => $rows,
        ];
    }
}
