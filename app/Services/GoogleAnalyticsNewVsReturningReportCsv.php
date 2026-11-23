<?php

namespace App\Services;

class GoogleAnalyticsNewVsReturningReportCsv
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function build(array $report): array
    {
        $headers = [
            'visitor_type',
            'sessions',
            'engaged_sessions',
            'engagement_rate',
            'key_events',
            'total_revenue',
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
                $this->value($row['visitor_label'] ?? null),
                $this->value($row['sessions'] ?? null),
                $this->value($row['engaged_sessions'] ?? null),
                $this->value($row['engagement_rate'] ?? null),
                $this->value($row['key_events'] ?? null),
                $this->value($row['total_revenue'] ?? null),
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
}
