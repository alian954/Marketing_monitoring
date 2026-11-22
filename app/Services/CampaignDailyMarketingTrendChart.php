<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class CampaignDailyMarketingTrendChart
{
    private const CHART_LEFT = 48.0;

    private const CHART_RIGHT = 576.0;

    private const CHART_TOP = 24.0;

    private const CHART_BOTTOM = 154.0;

    /**
     * @param  array<string, mixed>  $source
     * @return array{
     *     metric_label: string,
     *     format: 'integer'|'currency'|'decimal',
     *     maximum_label: string,
     *     coverage_start_on: string|null,
     *     coverage_end_on: string|null,
     *     points: list<array{date: string, value: float, value_label: string, x: float, y: float}>,
     *     segments: list<array{x1: float, y1: float, x2: float, y2: float}>
     * }
     */
    public function build(array $source, string $metricKey, string $metricLabel, string $format): array
    {
        if (! in_array($format, ['integer', 'currency', 'decimal'], true)) {
            throw new \InvalidArgumentException('The chart metric format is unsupported.');
        }

        $coverageStartOn = $this->validDate($source['coverage_start_on'] ?? null)
            ?? $this->validDate($source['requested_start_on'] ?? null);
        $coverageEndOn = $this->validDate($source['coverage_end_on'] ?? null)
            ?? $this->validDate($source['requested_end_on'] ?? null);
        $rows = is_array($source['rows'] ?? null) && array_is_list($source['rows'])
            ? $source['rows']
            : [];
        $rowsByDate = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = $this->validDate($row['date'] ?? null);

            if ($date === null
                || ($coverageStartOn !== null && $date < $coverageStartOn)
                || ($coverageEndOn !== null && $date > $coverageEndOn)
                || array_key_exists($date, $rowsByDate)) {
                continue;
            }

            $rowsByDate[$date] = $this->metricValue($row[$metricKey] ?? null);
        }

        ksort($rowsByDate);
        $validValues = array_filter($rowsByDate, static fn (?float $value): bool => $value !== null);
        $maximumValue = $validValues === [] ? 0.0 : max($validValues);
        $startDate = $coverageStartOn === null ? null : $this->asDate($coverageStartOn);
        $endDate = $coverageEndOn === null ? null : $this->asDate($coverageEndOn);
        $daySpan = $startDate !== null && $endDate !== null
            ? (int) $startDate->diff($endDate)->format('%a')
            : 0;
        $points = [];
        $segments = [];
        $previousPoint = null;
        $previousDate = null;

        foreach ($rowsByDate as $date => $value) {
            if ($value === null) {
                $previousPoint = null;
                $previousDate = $date;

                continue;
            }

            $elapsedDays = $startDate === null
                ? 0
                : (int) $startDate->diff($this->asDate($date))->format('%a');
            $x = $daySpan === 0
                ? (self::CHART_LEFT + self::CHART_RIGHT) / 2
                : self::CHART_LEFT + ($elapsedDays / $daySpan) * (self::CHART_RIGHT - self::CHART_LEFT);
            $y = $maximumValue === 0.0
                ? self::CHART_BOTTOM
                : self::CHART_BOTTOM - ($value / $maximumValue) * (self::CHART_BOTTOM - self::CHART_TOP);
            $point = [
                'date' => $date,
                'value' => $value,
                'value_label' => $this->formatValue($value, $format, $source['currency_code'] ?? null),
                'x' => round($x, 2),
                'y' => round($y, 2),
            ];

            if ($previousPoint !== null && $previousDate === $this->previousDate($date)) {
                $segments[] = [
                    'x1' => $previousPoint['x'],
                    'y1' => $previousPoint['y'],
                    'x2' => $point['x'],
                    'y2' => $point['y'],
                ];
            }

            $points[] = $point;
            $previousPoint = $point;
            $previousDate = $date;
        }

        return [
            'metric_label' => $metricLabel,
            'format' => $format,
            'maximum_label' => $this->formatValue($maximumValue, $format, $source['currency_code'] ?? null),
            'coverage_start_on' => $coverageStartOn,
            'coverage_end_on' => $coverageEndOn,
            'points' => $points,
            'segments' => $segments,
        ];
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function asDate(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
    }

    private function previousDate(string $date): string
    {
        return $this->asDate($date)->subDay()->toDateString();
    }

    private function metricValue(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $numericValue = (float) $value;

        return is_finite($numericValue) && $numericValue >= 0 ? $numericValue : null;
    }

    private function formatValue(float $value, string $format, mixed $currencyCode): string
    {
        if ($format === 'integer') {
            return number_format($value, 0);
        }

        $formattedValue = number_format($value, 2);

        return $format === 'currency' && is_string($currencyCode) && preg_match('/^[A-Z]{3}$/', $currencyCode) === 1
            ? $currencyCode.' '.$formattedValue
            : $formattedValue;
    }
}
