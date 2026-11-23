<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class GoogleAdsResponsiveSearchAdAssetPerformanceReport
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
    public function build(?array $snapshot, ?CarbonImmutable $expiresAt = null, string $sort = 'impressions'): array
    {
        $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : 'not_synced';

        if ($status === 'complete' && ($expiresAt === null || $expiresAt->isPast())) {
            $status = 'expired';
        }

        $sort = in_array($sort, ['impressions', 'spend', 'conversions', 'roas', 'ctr'], true)
            ? $sort
            : 'impressions';
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            if (! is_array($row)
                || ! is_string($row['ad_group_id'] ?? null)
                || ! is_string($row['ad_group_name'] ?? null)
                || ! is_string($row['ad_id'] ?? null)
                || ! is_string($row['asset_id'] ?? null)
                || ! is_string($row['text'] ?? null)
                || ! is_string($row['field_type'] ?? null)
                || ! is_string($row['source'] ?? null)
                || ! is_string($row['pinned_field'] ?? null)
                || ! is_string($row['performance_label'] ?? null)
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
                'field_type_label' => $this->fieldTypeLabel($row['field_type']),
                'source_label' => $this->sourceLabel($row['source']),
                'performance_label_label' => $this->performanceLabel($row['performance_label']),
                'ctr_percent' => $this->calculatedMetric($clicks, $impressions, 100),
                'cost_per_click' => $this->calculatedMetric($spendAmount, $clicks),
                'cost_per_conversion' => $this->calculatedMetric($spendAmount, $conversions),
                'roas' => $spendAmount > 0 ? round($conversionsValue / $spendAmount, 4) : null,
            ];
        }

        $sortField = match ($sort) {
            'spend' => 'spend_amount',
            'conversions' => 'conversions',
            'roas' => 'roas',
            'ctr' => 'ctr_percent',
            default => 'impressions',
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

            $impressionOrder = (int) $second['impressions'] <=> (int) $first['impressions'];

            if ($impressionOrder !== 0) {
                return $impressionOrder;
            }

            $adOrder = $this->compareIdentifiers($first['ad_id'], $second['ad_id']);

            return $adOrder !== 0
                ? $adOrder
                : $this->compareIdentifiers($first['asset_id'], $second['asset_id']);
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

    private function fieldTypeLabel(string $fieldType): string
    {
        return match ($fieldType) {
            'HEADLINE' => '標題',
            'DESCRIPTION' => '說明',
            default => $fieldType,
        };
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'ADVERTISER' => '廣告主提供',
            'AUTOMATICALLY_CREATED' => 'Google 自動建立',
            default => $source,
        };
    }

    private function performanceLabel(string $performanceLabel): string
    {
        return match ($performanceLabel) {
            'BEST' => '最佳',
            'GOOD' => '良好',
            'LOW' => '偏低',
            'LEARNING' => '學習中',
            'PENDING' => '資料不足',
            'UNKNOWN' => '未知',
            'UNSPECIFIED' => '未指定',
            default => $performanceLabel,
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

    private function compareIdentifiers(string $first, string $second): int
    {
        $normalizedFirst = ltrim($first, '0');
        $normalizedSecond = ltrim($second, '0');
        $normalizedFirst = $normalizedFirst === '' ? '0' : $normalizedFirst;
        $normalizedSecond = $normalizedSecond === '' ? '0' : $normalizedSecond;
        $lengthOrder = strlen($normalizedFirst) <=> strlen($normalizedSecond);

        return $lengthOrder !== 0
            ? $lengthOrder
            : strcmp($normalizedFirst, $normalizedSecond);
    }
}
