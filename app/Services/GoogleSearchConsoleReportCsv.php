<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class GoogleSearchConsoleReportCsv
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function build(array $report): array
    {
        $headers = [
            'client_name',
            'site_url',
            'start_date',
            'end_date',
            'dimension',
            'search_type',
            'dimension_value',
            'clicks',
            'impressions',
            'ctr_percent',
            'average_position',
        ];
        $rows = [];

        foreach (is_array($report['current']['rows'] ?? null) ? $report['current']['rows'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                $this->text($report['client_name'] ?? null),
                $this->text($report['site_url'] ?? null),
                $this->text($report['current']['starts_on'] ?? null),
                $this->text($report['current']['ends_on'] ?? null),
                $this->text($report['dimension'] ?? null),
                $this->text($report['search_type'] ?? null),
                $this->text($row['label'] ?? null),
                $this->decimal($row['clicks'] ?? null),
                $this->decimal($row['impressions'] ?? null),
                $this->percentage($row['ctr'] ?? null),
                $this->decimal($row['position'] ?? null),
            ];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** @param array<string, mixed> $report */
    public function download(array $report): StreamedResponse
    {
        $csv = $this->build($report);
        $startsOn = $this->text($report['current']['starts_on'] ?? null);
        $endsOn = $this->text($report['current']['ends_on'] ?? null);

        return response()->streamDownload(function () use ($csv): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $csv['headers'], ',', '"', '');

            foreach ($csv['rows'] as $row) {
                fputcsv($output, $row, ',', '"', '');
            }

            fclose($output);
        }, 'search-console-'.$startsOn.'-'.$endsOn.'.csv', [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return '';
        }

        return CsvFormulaSanitizer::escape((string) $value);
    }

    private function decimal(mixed $value): string
    {
        return is_numeric($value) && is_finite((float) $value)
            ? $this->trimDecimal(number_format((float) $value, 6, '.', ''))
            : '';
    }

    private function percentage(mixed $value): string
    {
        return is_numeric($value) && is_finite((float) $value)
            ? $this->trimDecimal(number_format((float) $value * 100, 6, '.', ''))
            : '';
    }

    private function trimDecimal(string $value): string
    {
        return str_contains($value, '.')
            ? rtrim(rtrim($value, '0'), '.')
            : $value;
    }
}
