<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class MetaMarketingAdPerformanceReport
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

        $sort = in_array($sort, ['spend', 'impressions', 'clicks', 'ctr', 'purchases', 'purchase_value', 'purchase_roas', 'cost_per_purchase', 'thruplays', 'cost_per_thruplay', 'video_completion_rate'], true)
            ? $sort
            : 'spend';
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            if (! is_array($row)
                || ! is_string($row['ad_id'] ?? null)
                || ! is_string($row['ad_name'] ?? null)
                || ! is_string($row['adset_name'] ?? null)
                || ! is_numeric($row['spend_amount'] ?? null)
                || ! is_numeric($row['impressions'] ?? null)
                || ! is_numeric($row['reach'] ?? null)
                || ! is_numeric($row['clicks'] ?? null)) {
                continue;
            }

            $actions = is_array($row['actions'] ?? null) ? $row['actions'] : [];
            $actionValues = is_array($row['action_values'] ?? null) ? $row['action_values'] : [];
            $spendAmount = (float) $row['spend_amount'];
            $impressions = (int) $row['impressions'];
            $clicks = (int) $row['clicks'];
            $purchases = $this->actionValue($actions, 'omni_purchase');
            $purchaseValue = $this->actionValue($actionValues, 'omni_purchase');
            $videoPlays = $this->actionValue($this->actionMetrics($row, 'video_play_actions'), 'video_view');
            $thruplays = $this->actionValue($this->actionMetrics($row, 'video_thruplay_watched_actions'), 'video_view');
            $videoP25Views = $this->actionValue($this->actionMetrics($row, 'video_p25_watched_actions'), 'video_view');
            $videoP50Views = $this->actionValue($this->actionMetrics($row, 'video_p50_watched_actions'), 'video_view');
            $videoP75Views = $this->actionValue($this->actionMetrics($row, 'video_p75_watched_actions'), 'video_view');
            $videoP95Views = $this->actionValue($this->actionMetrics($row, 'video_p95_watched_actions'), 'video_view');
            $videoP100Views = $this->actionValue($this->actionMetrics($row, 'video_p100_watched_actions'), 'video_view');

            $rows[] = [
                ...$row,
                'ctr_percent' => $this->calculatedMetric($clicks, $impressions, 100),
                'cost_per_click' => $this->calculatedMetric($spendAmount, $clicks),
                'cost_per_thousand_impressions' => $this->calculatedMetric($spendAmount * 1000, $impressions),
                'purchases' => $purchases,
                'purchase_value' => $purchaseValue,
                'purchase_roas' => $this->calculatedMetric($purchaseValue, $spendAmount),
                'cost_per_purchase' => $this->calculatedMetric($spendAmount, $purchases),
                'video_plays' => $videoPlays,
                'thruplays' => $thruplays,
                'cost_per_thruplay' => $this->calculatedMetric($spendAmount, $thruplays),
                'video_p25_views' => $videoP25Views,
                'video_p25_rate_percent' => $this->calculatedMetric($videoP25Views, $videoPlays, 100),
                'video_p50_views' => $videoP50Views,
                'video_p50_rate_percent' => $this->calculatedMetric($videoP50Views, $videoPlays, 100),
                'video_p75_views' => $videoP75Views,
                'video_p75_rate_percent' => $this->calculatedMetric($videoP75Views, $videoPlays, 100),
                'video_p95_views' => $videoP95Views,
                'video_p95_rate_percent' => $this->calculatedMetric($videoP95Views, $videoPlays, 100),
                'video_p100_views' => $videoP100Views,
                'video_p100_rate_percent' => $this->calculatedMetric($videoP100Views, $videoPlays, 100),
            ];
        }

        $sortFields = [
            'spend' => 'spend_amount',
            'impressions' => 'impressions',
            'clicks' => 'clicks',
            'ctr' => 'ctr_percent',
            'purchases' => 'purchases',
            'purchase_value' => 'purchase_value',
            'purchase_roas' => 'purchase_roas',
            'cost_per_purchase' => 'cost_per_purchase',
            'thruplays' => 'thruplays',
            'cost_per_thruplay' => 'cost_per_thruplay',
            'video_completion_rate' => 'video_p100_rate_percent',
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
                if (in_array($sortField, ['cost_per_purchase', 'cost_per_thruplay'], true)) {
                    return (float) $firstValue < (float) $secondValue ? -1 : 1;
                }

                return (float) $firstValue > (float) $secondValue ? -1 : 1;
            }

            $spendOrder = (float) $second['spend_amount'] <=> (float) $first['spend_amount'];

            return $spendOrder !== 0 ? $spendOrder : strcmp($first['ad_id'], $second['ad_id']);
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

    /** @param list<mixed> $metrics */
    private function actionValue(array $metrics, string $actionType): ?string
    {
        foreach ($metrics as $metric) {
            if (is_array($metric)
                && ($metric['action_type'] ?? null) === $actionType
                && is_numeric($metric['value'] ?? null)) {
                return (string) $metric['value'];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row
     * @return list<array{action_type?: mixed, value?: mixed}>
     */
    private function actionMetrics(array $row, string $field): array
    {
        return is_array($row[$field] ?? null) && array_is_list($row[$field]) ? $row[$field] : [];
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
