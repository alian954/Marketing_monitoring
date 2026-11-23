<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class GoogleAdsAuctionInsightPerformanceReport
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
    public function build(?array $snapshot, ?CarbonImmutable $expiresAt = null, string $sort = 'overlap_rate'): array
    {
        $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : 'not_synced';

        if ($status === 'complete' && ($expiresAt === null || $expiresAt->isPast())) {
            $status = 'expired';
        }

        $sortFields = [
            'impression_share' => 'impression_share',
            'overlap_rate' => 'overlap_rate',
            'position_above_rate' => 'position_above_rate',
            'outranking_share' => 'outranking_share',
            'top_impression_percentage' => 'top_impression_percentage',
            'absolute_top_impression_percentage' => 'absolute_top_impression_percentage',
            'domain' => 'domain',
        ];
        $sort = array_key_exists($sort, $sortFields) ? $sort : 'overlap_rate';
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            if (! is_array($row)
                || ! is_string($row['domain'] ?? null)
                || trim($row['domain']) === '') {
                continue;
            }

            $hasInvalidMetric = false;

            foreach ([
                'impression_share',
                'overlap_rate',
                'position_above_rate',
                'outranking_share',
                'top_impression_percentage',
                'absolute_top_impression_percentage',
            ] as $metric) {
                $value = $row[$metric] ?? null;

                if ($value !== null && (! is_numeric($value) || (float) $value < 0 || (float) $value > 1)) {
                    $hasInvalidMetric = true;
                    break;
                }
            }

            if ($hasInvalidMetric) {
                continue;
            }

            $impressionShare = $this->metric($row, 'impression_share');
            $outrankingShare = $this->metric($row, 'outranking_share');
            $impressionShareIsBelowTenPercent = ($row['impression_share_is_below_10_percent'] ?? false) === true
                || $impressionShare === 0.0999;
            $outrankingShareIsBelowTenPercent = ($row['outranking_share_is_below_10_percent'] ?? false) === true
                || $outrankingShare === 0.0999;

            $rows[] = [
                ...$row,
                'domain' => trim($row['domain']),
                'impression_share' => $impressionShare,
                'impression_share_is_below_10_percent' => $impressionShareIsBelowTenPercent,
                'impression_share_label' => $this->shareLabel($impressionShare, $impressionShareIsBelowTenPercent),
                'overlap_rate' => $this->metric($row, 'overlap_rate'),
                'overlap_rate_label' => $this->shareLabel($this->metric($row, 'overlap_rate')),
                'position_above_rate' => $this->metric($row, 'position_above_rate'),
                'position_above_rate_label' => $this->shareLabel($this->metric($row, 'position_above_rate')),
                'outranking_share' => $outrankingShare,
                'outranking_share_is_below_10_percent' => $outrankingShareIsBelowTenPercent,
                'outranking_share_label' => $this->shareLabel($outrankingShare, $outrankingShareIsBelowTenPercent),
                'top_impression_percentage' => $this->metric($row, 'top_impression_percentage'),
                'top_impression_percentage_label' => $this->shareLabel($this->metric($row, 'top_impression_percentage')),
                'absolute_top_impression_percentage' => $this->metric($row, 'absolute_top_impression_percentage'),
                'absolute_top_impression_percentage_label' => $this->shareLabel($this->metric($row, 'absolute_top_impression_percentage')),
            ];
        }

        $sortField = $sortFields[$sort];
        usort($rows, static function (array $first, array $second) use ($sortField): int {
            $sortOrder = $sortField === 'domain'
                ? strcasecmp($first[$sortField], $second[$sortField])
                : self::compareDescending($first[$sortField] ?? null, $second[$sortField] ?? null);

            if ($sortOrder !== 0) {
                return $sortOrder;
            }

            $overlapOrder = self::compareDescending($first['overlap_rate'] ?? null, $second['overlap_rate'] ?? null);

            return $overlapOrder !== 0 ? $overlapOrder : strcasecmp($first['domain'], $second['domain']);
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

    /** @param array<string, mixed> $row */
    private function metric(array $row, string $key): ?float
    {
        return is_numeric($row[$key] ?? null) ? (float) $row[$key] : null;
    }

    private function shareLabel(?float $share, bool $isBelowTenPercent = false): ?string
    {
        if ($share === null) {
            return null;
        }

        return $isBelowTenPercent ? '<10%' : number_format($share * 100, 2).'%';
    }

    private static function compareDescending(mixed $first, mixed $second): int
    {
        if ($first === null || $second === null) {
            if ($first !== $second) {
                return $first === null ? 1 : -1;
            }
        } elseif ((float) $first !== (float) $second) {
            return (float) $second <=> (float) $first;
        }

        return 0;
    }
}
