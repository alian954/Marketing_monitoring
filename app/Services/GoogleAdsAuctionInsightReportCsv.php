<?php

namespace App\Services;

class GoogleAdsAuctionInsightReportCsv
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function build(array $report): array
    {
        $headers = [
            'competitor_domain',
            'search_impression_share',
            'search_overlap_rate',
            'search_position_above_rate',
            'search_outranking_share',
            'search_top_impression_percentage',
            'search_absolute_top_impression_percentage',
            'window_start_on',
            'window_end_on',
        ];
        $rows = [];

        foreach (is_array($report['rows'] ?? null) ? $report['rows'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                $this->value($row['domain'] ?? null),
                $this->value($row['impression_share_label'] ?? null),
                $this->value($row['overlap_rate_label'] ?? null),
                $this->value($row['position_above_rate_label'] ?? null),
                $this->value($row['outranking_share_label'] ?? null),
                $this->value($row['top_impression_percentage_label'] ?? null),
                $this->value($row['absolute_top_impression_percentage_label'] ?? null),
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
