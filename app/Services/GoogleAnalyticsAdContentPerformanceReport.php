<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class GoogleAnalyticsAdContentPerformanceReport
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
    public function build(?array $snapshot, ?CarbonImmutable $expiresAt = null, string $sort = 'total_revenue'): array
    {
        $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : 'not_synced';

        if ($status === 'complete' && $expiresAt !== null && $expiresAt->isPast()) {
            $status = 'expired';
        }

        $sort = in_array($sort, ['total_revenue', 'sessions', 'engaged_sessions', 'engagement_rate', 'key_events', 'ad_content'], true)
            ? $sort
            : 'total_revenue';
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            $sessions = is_array($row) ? filter_var($row['sessions'] ?? null, FILTER_VALIDATE_INT) : false;
            $engagedSessions = is_array($row) ? filter_var($row['engaged_sessions'] ?? null, FILTER_VALIDATE_INT) : false;

            if (! is_array($row)
                || ! is_string($row['ad_content'] ?? null)
                || $sessions === false || $sessions < 0
                || $engagedSessions === false || $engagedSessions < 0
                || ! is_numeric($row['engagement_rate'] ?? null)
                || ! is_finite((float) $row['engagement_rate'])
                || (float) $row['engagement_rate'] < 0
                || (float) $row['engagement_rate'] > 1
                || ! is_numeric($row['key_events'] ?? null)
                || ! is_finite((float) $row['key_events'])
                || (float) $row['key_events'] < 0
                || ! is_numeric($row['total_revenue'] ?? null)
                || ! is_finite((float) $row['total_revenue'])) {
                continue;
            }

            $rows[] = [
                'ad_content' => $row['ad_content'],
                'sessions' => $sessions,
                'engaged_sessions' => $engagedSessions,
                'engagement_rate' => (string) $row['engagement_rate'],
                'key_events' => (string) $row['key_events'],
                'total_revenue' => (string) $row['total_revenue'],
            ];
        }

        usort($rows, static function (array $first, array $second) use ($sort): int {
            $sortOrder = $sort === 'ad_content'
                ? strcasecmp($first['ad_content'], $second['ad_content'])
                : (float) $second[$sort] <=> (float) $first[$sort];

            return $sortOrder !== 0 ? $sortOrder : strcasecmp($first['ad_content'], $second['ad_content']);
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
