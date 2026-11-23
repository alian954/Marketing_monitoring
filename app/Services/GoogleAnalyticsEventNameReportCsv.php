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
            ];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function value(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }
}
