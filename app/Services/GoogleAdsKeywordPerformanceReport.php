<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class GoogleAdsKeywordPerformanceReport
{
    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{status: string, window_start_on: string|null, window_end_on: string|null, currency_code: string|null, truncated: bool, sort: string, rows: list<array<string, mixed>>}
     */
    public function build(?array $snapshot, ?CarbonImmutable $expiresAt = null, string $sort = 'spend'): array
    {
        $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : 'not_synced';

        if ($status === 'complete' && $expiresAt !== null && $expiresAt->isPast()) {
            $status = 'expired';
        }

        $sort = in_array($sort, ['spend', 'conversions', 'roas', 'ctr', 'impression_share'], true) ? $sort : 'spend';
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            if (! is_array($row)
                || ! is_string($row['keyword_text'] ?? null)
                || ! is_string($row['match_type'] ?? null)
                || ! is_string($row['criterion_status'] ?? null)
                || ! is_string($row['ad_group_name'] ?? null)
                || ! is_numeric($row['spend_amount'] ?? null)
                || ! is_numeric($row['impressions'] ?? null)
                || ! is_numeric($row['clicks'] ?? null)
                || ! is_numeric($row['conversions'] ?? null)
                || ! is_numeric($row['conversions_value'] ?? null)) {
                continue;
            }

            foreach (['search_impression_share', 'search_budget_lost_impression_share', 'search_rank_lost_impression_share'] as $shareField) {
                if (($row[$shareField] ?? null) !== null && ! is_numeric($row[$shareField])) {
                    continue 2;
                }
            }

            $spendAmount = (float) $row['spend_amount'];
            $impressions = (int) $row['impressions'];
            $clicks = (int) $row['clicks'];
            $conversions = (float) $row['conversions'];
            $conversionsValue = (float) $row['conversions_value'];
            $rows[] = [
                ...$row,
                'ctr_percent' => $this->calculatedMetric($clicks, $impressions, 100),
                'cost_per_click' => $this->calculatedMetric($spendAmount, $clicks),
                'cost_per_action' => $this->calculatedMetric($spendAmount, $conversions),
                'roas' => $spendAmount > 0 ? round($conversionsValue / $spendAmount, 4) : null,
                'search_impression_share_label' => $this->shareLabel($row['search_impression_share'] ?? null, 'lower'),
                'search_budget_lost_impression_share_label' => $this->shareLabel($row['search_budget_lost_impression_share'] ?? null, 'upper'),
                'search_rank_lost_impression_share_label' => $this->shareLabel($row['search_rank_lost_impression_share'] ?? null, 'upper'),
                'criterion_status_label' => $this->criterionStatusLabel($row['criterion_status']),
            ];
        }

        $sortField = match ($sort) {
            'conversions' => 'conversions',
            'roas' => 'roas',
            'ctr' => 'ctr_percent',
            'impression_share' => 'search_impression_share',
            default => 'spend_amount',
        };
        usort($rows, function (array $first, array $second) use ($sortField): int {
            $firstValue = $first[$sortField] ?? null;
            $secondValue = $second[$sortField] ?? null;

            if ($firstValue === null || $secondValue === null) {
                if ($firstValue !== $secondValue) {
                    return $firstValue === null ? 1 : -1;
                }
            } elseif ((float) $firstValue !== (float) $secondValue) {
                return (float) $firstValue > (float) $secondValue ? -1 : 1;
            }

            $spendOrder = (float) $second['spend_amount'] <=> (float) $first['spend_amount'];

            return $spendOrder !== 0
                ? $spendOrder
                : strcasecmp($first['keyword_text'], $second['keyword_text']);
        });

        return [
            'status' => $status,
            'window_start_on' => is_string($snapshot['window_start_on'] ?? null) ? $snapshot['window_start_on'] : null,
            'window_end_on' => is_string($snapshot['window_end_on'] ?? null) ? $snapshot['window_end_on'] : null,
            'currency_code' => is_string($snapshot['currency_code'] ?? null) ? $snapshot['currency_code'] : null,
            'truncated' => ($snapshot['truncated'] ?? false) === true,
            'sort' => $sort,
            'rows' => $status === 'complete' ? $rows : [],
        ];
    }

    private function calculatedMetric(mixed $numerator, mixed $denominator, float $multiplier = 1): ?float
    {
        if (! is_numeric($numerator) || ! is_numeric($denominator)
            || (float) $numerator < 0 || (float) $denominator <= 0) {
            return null;
        }

        return round((float) $numerator / (float) $denominator * $multiplier, 4);
    }

    private function shareLabel(mixed $share, string $bound): ?string
    {
        if (! is_numeric($share)) {
            return null;
        }

        $value = (float) $share;

        if ($bound === 'lower' && $value === 0.0999) {
            return '<10%';
        }

        if ($bound === 'upper' && $value === 0.9001) {
            return '>90%';
        }

        return number_format($value * 100, 2).'%';
    }

    private function criterionStatusLabel(string $status): string
    {
        return match ($status) {
            'ENABLED' => '啟用',
            'PAUSED' => '已暫停',
            'REMOVED' => '已移除',
            default => $status,
        };
    }
}
