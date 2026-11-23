<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class GoogleAnalyticsEventNamePerformanceReport
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
    public function build(?array $snapshot, ?CarbonImmutable $expiresAt = null, string $sort = 'key_events'): array
    {
        $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : 'not_synced';

        if ($status === 'complete' && $expiresAt?->isFuture() !== true) {
            $status = 'expired';
        }

        $sort = in_array($sort, ['event_count', 'key_events', 'total_revenue', 'event_name'], true)
            ? $sort
            : 'key_events';
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            $eventCount = is_array($row) ? filter_var($row['event_count'] ?? null, FILTER_VALIDATE_INT) : false;

            if (! is_array($row)
                || ! is_string($row['event_name'] ?? null)
                || $eventCount === false
                || $eventCount < 0
                || ! is_numeric($row['key_events'] ?? null)
                || ! is_finite((float) $row['key_events'])
                || (float) $row['key_events'] < 0
                || ! is_numeric($row['total_revenue'] ?? null)
                || ! is_finite((float) $row['total_revenue'])) {
                continue;
            }

            $rows[] = [
                'event_name' => $row['event_name'],
                'event_count' => $eventCount,
                'key_events' => (string) $row['key_events'],
                'total_revenue' => (string) $row['total_revenue'],
            ];
        }

        usort($rows, function (array $first, array $second) use ($sort): int {
            $sortOrder = $sort === 'event_name'
                ? strcasecmp($first['event_name'], $second['event_name'])
                : (float) $second[$sort] <=> (float) $first[$sort];

            return $sortOrder !== 0
                ? $sortOrder
                : strcasecmp($first['event_name'], $second['event_name']);
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
