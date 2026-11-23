<?php

namespace App\Services;

class GoogleAnalyticsEventNameReportCsv
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function build(array $report): array
    {
        $headers = [
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
        ];
        $rows = [];

        foreach (is_array($report['rows'] ?? null) ? $report['rows'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                $this->value($row['event_name'] ?? null),
                $this->value($row['event_count'] ?? null),
                $this->value($row['key_events'] ?? null),
                $this->value($row['total_revenue'] ?? null),
                $this->value($report['currency_code'] ?? null),
                $this->value($report['window_start_on'] ?? null),
                $this->value($report['window_end_on'] ?? null),
                ($report['truncated'] ?? false) === true ? 'true' : 'false',
                is_numeric($row['cost_per_key_event'] ?? null)
                    ? number_format((float) $row['cost_per_key_event'], 4, '.', '')
                    : '',
                $this->value($row['cost_per_key_event_currency'] ?? null),
                $this->value($row['cost_per_key_event_source_label'] ?? null),
                $this->value($row['cost_per_key_event_status'] ?? null),
                $this->value($row['cost_per_key_event_status_label'] ?? null),
            ];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function value(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }
}
