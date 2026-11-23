<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class GoogleAnalyticsLandingPagePerformanceReport
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
     *     rows: list<array<string, mixed>>
     * }
     */
    public function build(?array $snapshot, ?CarbonImmutable $expiresAt = null, string $sort = 'sessions'): array
    {
        $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : 'not_synced';

        if ($status === 'complete' && $expiresAt !== null && $expiresAt->isPast()) {
            $status = 'expired';
        }

        $sort = in_array($sort, ['sessions', 'key_events', 'total_revenue', 'engagement_rate'], true)
            ? $sort
            : 'sessions';
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            if (! is_array($row)
                || ! is_string($row['landing_page'] ?? null)
                || ! is_numeric($row['sessions'] ?? null)
                || ! is_numeric($row['engaged_sessions'] ?? null)
                || ! is_numeric($row['engagement_rate'] ?? null)
                || ! is_numeric($row['bounce_rate'] ?? null)
                || ! is_numeric($row['key_events'] ?? null)
                || ! is_numeric($row['total_revenue'] ?? null)) {
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, function (array $first, array $second) use ($sort): int {
            $sortOrder = (float) $second[$sort] <=> (float) $first[$sort];

            return $sortOrder !== 0
                ? $sortOrder
                : strcasecmp($first['landing_page'], $second['landing_page']);
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
}
