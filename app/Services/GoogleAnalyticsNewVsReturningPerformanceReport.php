<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class GoogleAnalyticsNewVsReturningPerformanceReport
{
    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{
     *     status: string,
     *     window_start_on: ?string,
     *     window_end_on: ?string,
     *     currency_code: ?string,
     *     sort: string,
     *     rows: list<array{visitor_type: string, visitor_label: string, sessions: int, engaged_sessions: int, engagement_rate: string, key_events: string, total_revenue: string}>
     * }
     */
    public function build(?array $snapshot, ?CarbonImmutable $expiresAt = null, string $sort = 'sessions'): array
    {
        $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : 'not_synced';

        if ($status === 'complete' && $expiresAt !== null && $expiresAt->isPast()) {
            $status = 'expired';
        }

        $sort = in_array($sort, ['sessions', 'engaged_sessions', 'engagement_rate', 'key_events', 'total_revenue', 'visitor_type'], true)
            ? $sort
            : 'sessions';
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            $sessions = is_array($row) ? filter_var($row['sessions'] ?? null, FILTER_VALIDATE_INT) : false;
            $engagedSessions = is_array($row) ? filter_var($row['engaged_sessions'] ?? null, FILTER_VALIDATE_INT) : false;

            if (! is_array($row)
                || ! in_array($row['visitor_type'] ?? null, ['new', 'returning'], true)
                || $sessions === false || $sessions < 0
                || $engagedSessions === false || $engagedSessions < 0
                || ! is_numeric($row['key_events'] ?? null)
                || ! is_finite((float) $row['key_events'])
                || (float) $row['key_events'] < 0
                || ! is_numeric($row['total_revenue'] ?? null)
                || ! is_finite((float) $row['total_revenue'])) {
                continue;
            }

            $rows[] = [
                'visitor_type' => $row['visitor_type'],
                'visitor_label' => $row['visitor_type'] === 'new' ? '新客' : '回訪客',
                'sessions' => $sessions,
                'engaged_sessions' => $engagedSessions,
                'engagement_rate' => number_format($sessions > 0 ? $engagedSessions / $sessions : 0, 4, '.', ''),
                'key_events' => (string) $row['key_events'],
                'total_revenue' => (string) $row['total_revenue'],
            ];
        }

        usort($rows, static function (array $first, array $second) use ($sort): int {
            $sortOrder = $sort === 'visitor_type'
                ? strcasecmp($first['visitor_type'], $second['visitor_type'])
                : (float) $second[$sort] <=> (float) $first[$sort];

            return $sortOrder !== 0 ? $sortOrder : strcasecmp($first['visitor_type'], $second['visitor_type']);
        });

        return [
            'status' => $status,
            'window_start_on' => is_string($snapshot['window_start_on'] ?? null) ? $snapshot['window_start_on'] : null,
            'window_end_on' => is_string($snapshot['window_end_on'] ?? null) ? $snapshot['window_end_on'] : null,
            'currency_code' => is_string($snapshot['currency_code'] ?? null) ? $snapshot['currency_code'] : null,
            'sort' => $sort,
            'rows' => $status === 'complete' ? $rows : [],
        ];
    }
}
