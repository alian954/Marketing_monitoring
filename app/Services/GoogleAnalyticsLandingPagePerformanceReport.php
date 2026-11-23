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

        if ($status === 'complete' && $expiresAt?->isFuture() !== true) {
            $status = 'expired';
        }

        $sort = in_array($sort, ['sessions', 'key_events', 'key_events_per_100_sessions', 'total_revenue', 'engagement_rate', 'revenue_per_session'], true)
            ? $sort
            : 'sessions';
        $currencyCode = $this->currencyCode($snapshot['currency_code'] ?? null);
        $rows = [];

        foreach (is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows']) ? $snapshot['rows'] : [] as $row) {
            if (! is_array($row) || ! is_string($row['landing_page'] ?? null)) {
                continue;
            }

            $sessions = filter_var($row['sessions'] ?? null, FILTER_VALIDATE_INT);
            $engagedSessions = filter_var($row['engaged_sessions'] ?? null, FILTER_VALIDATE_INT);
            $numericMetrics = [
                $row['engagement_rate'] ?? null,
                $row['bounce_rate'] ?? null,
                $row['total_revenue'] ?? null,
            ];

            if ($sessions === false
                || $sessions < 0
                || $engagedSessions === false
                || $engagedSessions < 0
                || array_any($numericMetrics, fn (mixed $metric): bool => ! is_numeric($metric) || ! is_finite((float) $metric))) {
                continue;
            }

            $keyEventsStatus = match (true) {
                ! array_key_exists('key_events', $row) || $row['key_events'] === null => 'missing_key_events',
                ! is_numeric($row['key_events'])
                    || ! is_finite((float) $row['key_events'])
                    || (float) $row['key_events'] < 0 => 'invalid_key_events',
                default => 'available',
            };
            if ($keyEventsStatus !== 'available') {
                $row['key_events'] = null;
            }

            $row['key_events_per_100_sessions'] = null;
            $row['key_events_per_100_sessions_status'] = $keyEventsStatus;
            $row['key_events_per_100_sessions_status_label'] = match ($keyEventsStatus) {
                'missing_key_events' => '缺少重要事件數',
                'invalid_key_events' => '重要事件數無效',
                default => '可計算',
            };

            if ($sessions === 0) {
                $row['key_events_per_100_sessions_status'] = 'zero_sessions';
                $row['key_events_per_100_sessions_status_label'] = '工作階段為 0';
            } elseif ($keyEventsStatus === 'available') {
                $keyEventsPer100Sessions = (float) $row['key_events'] / $sessions * 100;

                if (is_finite($keyEventsPer100Sessions)) {
                    $row['key_events_per_100_sessions'] = $keyEventsPer100Sessions;
                } else {
                    $row['key_events_per_100_sessions_status'] = 'calculation_unavailable';
                    $row['key_events_per_100_sessions_status_label'] = '無法計算';
                }
            }

            $row['revenue_per_session'] = null;
            $row['revenue_per_session_status'] = 'available';
            $row['revenue_per_session_status_label'] = '可計算';

            if ($sessions === 0) {
                $row['revenue_per_session_status'] = 'zero_sessions';
                $row['revenue_per_session_status_label'] = '工作階段為 0';
            } elseif ($currencyCode === null) {
                $row['revenue_per_session_status'] = 'currency_unknown';
                $row['revenue_per_session_status_label'] = '幣別未知';
            } else {
                $revenuePerSession = (float) $row['total_revenue'] / $sessions;

                if (is_finite($revenuePerSession)) {
                    $row['revenue_per_session'] = $revenuePerSession;
                } else {
                    $row['revenue_per_session_status'] = 'calculation_unavailable';
                    $row['revenue_per_session_status_label'] = '無法計算';
                }
            }

            $rows[] = $row;
        }

        usort($rows, function (array $first, array $second) use ($sort): int {
            if (in_array($sort, ['revenue_per_session', 'key_events_per_100_sessions'], true)
                && (($first[$sort] ?? null) === null || ($second[$sort] ?? null) === null)) {
                if (($first[$sort] ?? null) === null && ($second[$sort] ?? null) !== null) {
                    return 1;
                }

                if (($first[$sort] ?? null) !== null && ($second[$sort] ?? null) === null) {
                    return -1;
                }
            }

            $sortOrder = (float) $second[$sort] <=> (float) $first[$sort];

            return $sortOrder !== 0
                ? $sortOrder
                : strcasecmp($first['landing_page'], $second['landing_page']);
        });

        return [
            'status' => $status,
            'window_start_on' => is_string($snapshot['window_start_on'] ?? null) ? $snapshot['window_start_on'] : null,
            'window_end_on' => is_string($snapshot['window_end_on'] ?? null) ? $snapshot['window_end_on'] : null,
            'currency_code' => $currencyCode,
            'truncated' => ($snapshot['truncated'] ?? false) === true,
            'sort' => $sort,
            'rows' => $status === 'complete' ? $rows : [],
        ];
    }

    private function currencyCode(mixed $currencyCode): ?string
    {
        if (! is_string($currencyCode)) {
            return null;
        }

        $normalizedCurrencyCode = strtoupper(trim($currencyCode));

        return preg_match('/^[A-Z]{3}$/D', $normalizedCurrencyCode) === 1 ? $normalizedCurrencyCode : null;
    }
}
