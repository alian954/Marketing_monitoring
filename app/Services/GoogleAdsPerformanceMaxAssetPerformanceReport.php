<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class GoogleAdsPerformanceMaxAssetPerformanceReport
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

        $sort = in_array($sort, ['impressions', 'spend', 'conversions', 'conversions_value', 'roas', 'ctr', 'cost_per_conversion'], true)
            ? $sort
            : 'impressions';
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            if (! is_array($row)
                || ! is_string($row['asset_group_id'] ?? null)
                || ! is_string($row['asset_group_name'] ?? null)
                || ! is_string($row['asset_id'] ?? null)
                || ! is_string($row['asset_name'] ?? null)
                || ! is_string($row['field_type'] ?? null)
                || ! is_string($row['asset_type'] ?? null)
                || ! is_string($row['content'] ?? null)
                || ! is_string($row['status'] ?? null)
                || ! is_string($row['primary_status'] ?? null)
                || ! is_array($row['primary_status_reasons'] ?? null)
                || ! array_is_list($row['primary_status_reasons'])
                || ! is_string($row['source'] ?? null)
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
            $reasons = array_values(array_filter(
                $row['primary_status_reasons'],
                static fn (mixed $reason): bool => is_string($reason),
            ));

            $rows[] = [
                ...$row,
                'field_type_label' => $this->fieldTypeLabel($row['field_type']),
                'asset_type_label' => $this->assetTypeLabel($row['asset_type']),
                'status_label' => $this->statusLabel($row['status']),
                'primary_status_label' => $this->primaryStatusLabel($row['primary_status']),
                'primary_status_reasons' => $reasons,
                'source_label' => $this->sourceLabel($row['source']),
                'ctr_percent' => $this->calculatedMetric($clicks, $impressions, 100),
                'cost_per_click' => $this->calculatedMetric($spendAmount, $clicks),
                'cost_per_conversion' => $this->calculatedMetric($spendAmount, $conversions),
                'roas' => $spendAmount > 0 ? round($conversionsValue / $spendAmount, 4) : null,
            ];
        }

        $sortField = match ($sort) {
            'spend' => 'spend_amount',
            'conversions' => 'conversions',
            'conversions_value' => 'conversions_value',
            'roas' => 'roas',
            'ctr' => 'ctr_percent',
            'cost_per_conversion' => 'cost_per_conversion',
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
                if ($sortField === 'cost_per_conversion') {
                    return (float) $firstValue < (float) $secondValue ? -1 : 1;
                }

                return (float) $firstValue > (float) $secondValue ? -1 : 1;
            }

            $impressionOrder = (int) $second['impressions'] <=> (int) $first['impressions'];

            if ($impressionOrder !== 0) {
                return $impressionOrder;
            }

            $groupOrder = $this->compareIdentifiers($first['asset_group_id'], $second['asset_group_id']);

            return $groupOrder !== 0
                ? $groupOrder
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
            'LONG_HEADLINE' => '長標題',
            'DESCRIPTION', 'LONG_DESCRIPTION' => '說明',
            'MARKETING_IMAGE', 'SQUARE_MARKETING_IMAGE', 'PORTRAIT_MARKETING_IMAGE', 'LANDSCAPE_LOGO', 'LOGO', 'BUSINESS_LOGO' => '圖片',
            'YOUTUBE_VIDEO', 'VIDEO', 'RELATED_YOUTUBE_VIDEOS' => '影片',
            'BUSINESS_NAME' => '商家名稱',
            default => $fieldType,
        };
    }

    private function assetTypeLabel(string $assetType): string
    {
        return match ($assetType) {
            'TEXT' => '文字',
            'IMAGE' => '圖片',
            'YOUTUBE_VIDEO', 'YOUTUBE_VIDEO_LIST' => 'YouTube 影片',
            'MEDIA_BUNDLE' => '媒體套件',
            default => $assetType,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'ENABLED' => '啟用',
            'PAUSED' => '暫停',
            'REMOVED' => '已移除',
            default => $status,
        };
    }

    private function primaryStatusLabel(string $status): string
    {
        return match ($status) {
            'ELIGIBLE' => '可放送',
            'LIMITED' => '有限放送',
            'NOT_ELIGIBLE' => '不可放送',
            'PAUSED' => '已暫停',
            'PENDING' => '審查中',
            'REMOVED' => '已移除',
            'UNKNOWN', 'UNSPECIFIED' => '未知',
            default => $status,
        };
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'ADVERTISER' => '廣告主提供',
            'AUTOMATICALLY_CREATED' => 'Google 自動建立',
            'UNKNOWN', 'UNSPECIFIED' => '未知',
            default => $source,
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

        return $lengthOrder !== 0 ? $lengthOrder : strcmp($normalizedFirst, $normalizedSecond);
    }
}
