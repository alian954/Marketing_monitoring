<?php

namespace App\Services;

class GoogleAnalyticsLandingPageReportCsv
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function build(array $report): array
    {
        $headers = [
            'landing_page',
            'sessions',
            'engaged_sessions',
            'engagement_rate_percent',
            'bounce_rate_percent',
            'key_events',
            'key_events_per_100_sessions',
            'key_events_per_100_sessions_status_label',
            'total_revenue',
            'revenue_per_session',
            'revenue_per_session_status_label',
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
                $this->value($row['landing_page'] ?? null),
                $this->value($row['sessions'] ?? null),
                $this->value($row['engaged_sessions'] ?? null),
                $this->percentage($row['engagement_rate'] ?? null),
                $this->percentage($row['bounce_rate'] ?? null),
                $this->value($row['key_events'] ?? null),
                $this->decimal($row['key_events_per_100_sessions'] ?? null),
                $this->value($row['key_events_per_100_sessions_status_label'] ?? null),
                $this->value($row['total_revenue'] ?? null),
                $this->decimal($row['revenue_per_session'] ?? null),
                $this->value($row['revenue_per_session_status_label'] ?? null),
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

    private function percentage(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value * 100, 4, '.', '') : '';
    }

    private function decimal(mixed $value): string
    {
        return is_numeric($value) && is_finite((float) $value)
            ? number_format((float) $value, 4, '.', '')
            : '';
    }
}
