<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class MetaMarketingPlacementPerformanceReport
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

        $sort = in_array($sort, ['spend', 'impressions', 'clicks', 'ctr', 'purchases', 'purchase_value'], true)
            ? $sort
            : 'spend';
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            if (! is_array($row)
                || ! is_string($row['publisher_platform'] ?? null)
                || ! is_string($row['platform_position'] ?? null)
                || ! is_numeric($row['spend_amount'] ?? null)
                || ! is_numeric($row['impressions'] ?? null)
                || ! is_numeric($row['clicks'] ?? null)
                || (($row['reach'] ?? null) !== null && ! is_numeric($row['reach']))) {
                continue;
            }

            $actions = is_array($row['actions'] ?? null) ? $row['actions'] : [];
            $actionValues = is_array($row['action_values'] ?? null) ? $row['action_values'] : [];
            $spendAmount = (float) $row['spend_amount'];
            $impressions = (int) $row['impressions'];
            $clicks = (int) $row['clicks'];
            $rows[] = [
                ...$row,
                'ctr_percent' => $this->calculatedMetric($clicks, $impressions, 100),
                'cost_per_click' => $this->calculatedMetric($spendAmount, $clicks),
                'cost_per_thousand_impressions' => $this->calculatedMetric($spendAmount * 1000, $impressions),
                'purchases' => $this->actionValue($actions, 'omni_purchase'),
                'purchase_value' => $this->actionValue($actionValues, 'omni_purchase'),
            ];
        }

        $sortFields = [
            'spend' => 'spend_amount',
            'impressions' => 'impressions',
            'clicks' => 'clicks',
            'ctr' => 'ctr_percent',
            'purchases' => 'purchases',
            'purchase_value' => 'purchase_value',
        ];
        $sortField = $sortFields[$sort];
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

            if ($spendOrder !== 0) {
                return $spendOrder;
            }

            $platformOrder = strcmp($first['publisher_platform'], $second['publisher_platform']);

            return $platformOrder !== 0
                ? $platformOrder
                : strcmp($first['platform_position'], $second['platform_position']);
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

    /** @param list<array{action_type?: mixed, value?: mixed}> $metrics */
    private function actionValue(array $metrics, string $actionType): ?string
    {
        foreach ($metrics as $metric) {
            if (($metric['action_type'] ?? null) === $actionType && is_numeric($metric['value'] ?? null)) {
                return (string) $metric['value'];
            }
        }

        return null;
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
