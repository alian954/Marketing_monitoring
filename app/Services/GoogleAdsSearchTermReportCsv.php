<?php

namespace App\Services;

class GoogleAdsSearchTermReportCsv
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function build(array $report): array
    {
        $headers = [
            'search_term',
            'ad_group',
            'impressions',
            'clicks',
            'ctr_percent',
            'spend_amount',
            'cost_per_click',
            'conversions',
            'cost_per_action',
            'conversions_value',
            'roas',
            'diagnostic_status',
            'currency_code',
            'window_start_on',
            'window_end_on',
        ];
        $rows = [];

        foreach (is_array($report['rows'] ?? null) ? $report['rows'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                $this->value($row['search_term'] ?? null),
                $this->value($row['ad_group_name'] ?? null),
                $this->value($row['impressions'] ?? null),
                $this->value($row['clicks'] ?? null),
                $this->decimalValue($row['ctr_percent'] ?? null),
                $this->value($row['spend_amount'] ?? null),
                $this->decimalValue($row['cost_per_click'] ?? null),
                $this->value($row['conversions'] ?? null),
                $this->decimalValue($row['cost_per_action'] ?? null),
                $this->value($row['conversions_value'] ?? null),
                $this->decimalValue($row['roas'] ?? null),
                $this->statusLabel($row['diagnostic_status'] ?? null),
                $this->value($report['currency_code'] ?? null),
                $this->value($report['window_start_on'] ?? null),
                $this->value($report['window_end_on'] ?? null),
            ];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function value(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    private function decimalValue(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 4, '.', '') : '';
    }

    private function statusLabel(mixed $status): string
    {
        return match ($status) {
            'spend_without_conversions' => '有花費無轉換',
            'has_conversions' => '已產生轉換',
            default => '尚無花費',
        };
    }
}
