<?php

namespace App\Services;

class GoogleAnalyticsItemReportCsv
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function build(array $report): array
    {
        $headers = [
            'item_name',
            'items_viewed',
            'items_added_to_cart',
            'items_purchased',
            'item_revenue',
            'currency_code',
            'window_start_on',
            'window_end_on',
            'truncated',
        ];
        $rows = [];

        foreach (is_array($report['rows'] ?? null) ? $report['rows'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                $this->value($row['item_name'] ?? null),
                $this->value($row['items_viewed'] ?? null),
                $this->value($row['items_added_to_cart'] ?? null),
                $this->value($row['items_purchased'] ?? null),
                $this->value($row['item_revenue'] ?? null),
                $this->value($report['currency_code'] ?? null),
                $this->value($report['window_start_on'] ?? null),
                $this->value($report['window_end_on'] ?? null),
                ($report['truncated'] ?? false) === true ? 'true' : 'false',
            ];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function value(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }
}
