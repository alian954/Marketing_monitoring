<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class GoogleAdsDevicePerformanceReport
{
    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{
     *     status: string,
     *     window_start_on: string|null,
     *     window_end_on: string|null,
     *     currency_code: string|null,
     *     truncated: bool,
     *     sort: string,
     *     message: string|null,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function build(?array $snapshot, ?CarbonImmutable $expiresAt = null, string $sort = 'spend'): array
    {
        $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : 'not_synced';

        if ($status === 'complete' && ($expiresAt === null || $expiresAt->isPast())) {
            $status = 'expired';
        }

        $sort = in_array($sort, ['spend', 'conversions', 'roas', 'ctr'], true) ? $sort : 'spend';
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            if (! is_array($row)
                || ! is_string($row['device'] ?? null)
                || ! is_numeric($row['impressions'] ?? null)
                || ! is_numeric($row['clicks'] ?? null)
                || ! is_numeric($row['spend_amount'] ?? null)
                || ! is_numeric($row['conversions'] ?? null)
                || ! is_numeric($row['conversions_value'] ?? null)) {
                continue;
            }

            $spendAmount = (float) $row['spend_amount'];
            $impressions = (int) $row['impressions'];
            $clicks = (int) $row['clicks'];
            $conversions = (float) $row['conversions'];
            $conversionsValue = (float) $row['conversions_value'];
            $rows[] = [
                ...$row,
                'device_label' => $this->deviceLabel($row['device']),
                'ctr_percent' => $this->calculatedMetric($clicks, $impressions, 100),
                'cost_per_click' => $this->calculatedMetric($spendAmount, $clicks),
                'cost_per_conversion' => $this->calculatedMetric($spendAmount, $conversions),
                'roas' => $spendAmount > 0 ? round($conversionsValue / $spendAmount, 4) : null,
            ];
        }

        $sortField = match ($sort) {
            'conversions' => 'conversions',
            'roas' => 'roas',
            'ctr' => 'ctr_percent',
            default => 'spend_amount',
        };
        usort($rows, function (array $first, array $second) use ($sortField): int {
            $firstValue = $first[$sortField];
            $secondValue = $second[$sortField];

            if ($firstValue === null || $secondValue === null) {
                if ($firstValue !== $secondValue) {
                    return $firstValue === null ? 1 : -1;
                }
            } elseif ((float) $firstValue !== (float) $secondValue) {
                return (float) $firstValue > (float) $secondValue ? -1 : 1;
            }

            $spendOrder = (float) $second['spend_amount'] <=> (float) $first['spend_amount'];

            return $spendOrder !== 0 ? $spendOrder : strcmp($first['device'], $second['device']);
        });

        return [
            'status' => $status,
            'window_start_on' => is_string($snapshot['window_start_on'] ?? null) ? $snapshot['window_start_on'] : null,
            'window_end_on' => is_string($snapshot['window_end_on'] ?? null) ? $snapshot['window_end_on'] : null,
            'currency_code' => is_string($snapshot['currency_code'] ?? null) ? $snapshot['currency_code'] : null,
            'truncated' => ($snapshot['truncated'] ?? false) === true,
            'sort' => $sort,
            'message' => is_string($snapshot['message'] ?? null) ? $snapshot['message'] : null,
            'rows' => $status === 'complete' ? $rows : [],
        ];
    }

    private function deviceLabel(string $device): string
    {
        return match ($device) {
            'DESKTOP' => '電腦',
            'MOBILE' => '手機',
            'TABLET' => '平板',
            'CONNECTED_TV' => '連網電視',
            'OTHER' => '其他',
            'UNSPECIFIED' => '未分類',
            default => $device,
        };
    }

    private function calculatedMetric(mixed $numerator, mixed $denominator, float $multiplier = 1): ?float
    {
        if (! is_numeric($numerator) || ! is_numeric($denominator)
            || (float) $numerator < 0 || (float) $denominator <= 0) {
            return null;
        }

        return round((float) $numerator / (float) $denominator * $multiplier, 4);
    }
}
