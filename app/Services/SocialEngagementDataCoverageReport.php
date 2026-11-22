<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class SocialEngagementDataCoverageReport
{
    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    /** @var array<string, array{label: string, days: int}> */
    private const PERIODS = [
        '7d' => ['label' => '近 7 天', 'days' => 7],
        '30d' => ['label' => '近 30 天', 'days' => 30],
    ];

    /** @var array<string, string> */
    private const PLATFORM_LABELS = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'threads' => 'Threads',
        'dcard' => 'Dcard',
    ];

    /**
     * @return list<array{
     *     period_label: string,
     *     period_key: string,
     *     platform_key: string,
     *     platform: string,
     *     interaction_scope_label: string,
     *     check_count: int,
     *     http_response_count: int,
     *     complete_metric_read_count: int,
     *     coverage_rate_percent: float|null,
     *     posts_with_response_count: int,
     *     posts_with_metrics_count: int,
     *     latest_complete_capture_at: string|null,
     *     failure_reason_counts: list<array{key: string, label: string, count: int}>,
     *     latest_failure_reason: string|null,
     *     latest_failure_at: string|null
     * }>
     */
    public function build(
        CarbonImmutable $asOf,
        ?string $selectedPlatform,
        string $selectedTag,
        ?string $selectedTagValue,
        int $retentionDays,
    ): array {
        $availablePeriods = array_filter(
            self::PERIODS,
            static fn (array $period): bool => $period['days'] <= max(1, $retentionDays),
        );

        if ($availablePeriods === []) {
            return [];
        }

        $historyStartsAt = $asOf->subDays(max(array_column($availablePeriods, 'days')));
        $totalsByPeriodAndPlatform = [];

        CheckRun::query()
            ->select([
                'check_runs.id',
                'check_runs.monitor_id',
                'check_runs.finished_at',
                'check_runs.normal_status',
                'check_runs.error_type',
                'check_runs.response_public_engagement_metrics',
            ])
            ->where('check_runs.status', 'completed')
            ->whereNotNull('check_runs.finished_at')
            ->whereBetween('check_runs.finished_at', [$historyStartsAt, $asOf])
            ->whereNotIn('check_runs.source', ['tls_daily', 'domain_daily'])
            ->whereHas('monitor', static function (Builder $query) use ($selectedPlatform): void {
                $query->whereNotNull('social_platform')
                    ->whereColumn('check_runs.config_version', 'monitors.config_version');

                if ($selectedPlatform !== null) {
                    $query->where('social_platform', $selectedPlatform);
                }
            })
            ->with('monitor:id,social_platform,config_version,tags,label')
            ->chunkById(500, function (EloquentCollection $runBatch) use (&$totalsByPeriodAndPlatform, $asOf, $availablePeriods, $selectedTag, $selectedTagValue): void {
                foreach ($runBatch as $run) {
                    $monitor = $run->monitor;
                    $platform = $monitor?->social_platform;

                    if (! is_string($platform)
                        || $run->finished_at === null
                        || ! $this->monitorMatchesTag($monitor, $selectedTag, $selectedTagValue)) {
                        continue;
                    }

                    $metrics = is_array($run->response_public_engagement_metrics)
                        ? $run->response_public_engagement_metrics
                        : [];
                    $hasHttpResponse = $run->normal_status !== null;
                    $hasCompleteMetrics = $hasHttpResponse
                        && Monitor::publicEngagementInteractionTotal($metrics, $platform) !== null;
                    $failureReason = $this->failureReason($platform, $run->normal_status, $run->error_type, $metrics);

                    foreach ($availablePeriods as $periodKey => $period) {
                        if ($run->finished_at->lessThan($asOf->subDays($period['days']))) {
                            continue;
                        }

                        $totalsByPeriodAndPlatform[$periodKey][$platform] ??= [
                            'check_count' => 0,
                            'http_response_count' => 0,
                            'complete_metric_read_count' => 0,
                            'posts_with_response' => [],
                            'posts_with_metrics' => [],
                            'latest_complete_capture_at' => null,
                            'failure_reason_counts' => [],
                            'latest_failure_reason' => null,
                        ];

                        $totalsByPeriodAndPlatform[$periodKey][$platform]['check_count']++;

                        if ($hasHttpResponse) {
                            $totalsByPeriodAndPlatform[$periodKey][$platform]['http_response_count']++;
                            $totalsByPeriodAndPlatform[$periodKey][$platform]['posts_with_response'][$monitor->id] = true;
                        }

                        if ($failureReason !== null) {
                            $totalsByPeriodAndPlatform[$periodKey][$platform]['failure_reason_counts'][$failureReason['key']] ??= [
                                'key' => $failureReason['key'],
                                'label' => $failureReason['label'],
                                'count' => 0,
                            ];
                            $totalsByPeriodAndPlatform[$periodKey][$platform]['failure_reason_counts'][$failureReason['key']]['count']++;
                            $latestFailure = $totalsByPeriodAndPlatform[$periodKey][$platform]['latest_failure_reason'];

                            if ($latestFailure === null || $run->finished_at->greaterThan($latestFailure['finished_at'])) {
                                $totalsByPeriodAndPlatform[$periodKey][$platform]['latest_failure_reason'] = [
                                    'label' => $failureReason['label'],
                                    'finished_at' => $run->finished_at->toImmutable(),
                                ];
                            }
                        }

                        if (! $hasCompleteMetrics) {
                            continue;
                        }

                        $totalsByPeriodAndPlatform[$periodKey][$platform]['complete_metric_read_count']++;
                        $totalsByPeriodAndPlatform[$periodKey][$platform]['posts_with_metrics'][$monitor->id] = true;
                        $latestCompleteCaptureAt = $totalsByPeriodAndPlatform[$periodKey][$platform]['latest_complete_capture_at'];

                        if ($latestCompleteCaptureAt === null || $run->finished_at->greaterThan($latestCompleteCaptureAt)) {
                            $totalsByPeriodAndPlatform[$periodKey][$platform]['latest_complete_capture_at'] = $run->finished_at->toImmutable();
                        }
                    }
                }
            }, 'check_runs.id', 'id');

        $rows = [];

        foreach ($availablePeriods as $periodKey => $period) {
            foreach (self::PLATFORM_LABELS as $platformKey => $platformLabel) {
                $totals = $totalsByPeriodAndPlatform[$periodKey][$platformKey] ?? null;

                if ($totals === null || $totals['check_count'] === 0) {
                    continue;
                }

                $failureReasonCounts = array_values($totals['failure_reason_counts']);
                usort($failureReasonCounts, static fn (array $left, array $right): int => $right['count'] <=> $left['count'] ?: strcmp($left['label'], $right['label']));

                $rows[] = [
                    'period_label' => $period['label'],
                    'period_key' => $periodKey,
                    'platform_key' => $platformKey,
                    'platform' => $platformLabel,
                    'interaction_scope_label' => Monitor::publicEngagementInteractionScopeLabel($platformKey),
                    'check_count' => $totals['check_count'],
                    'http_response_count' => $totals['http_response_count'],
                    'complete_metric_read_count' => $totals['complete_metric_read_count'],
                    'coverage_rate_percent' => $totals['http_response_count'] > 0
                        ? round($totals['complete_metric_read_count'] / $totals['http_response_count'] * 100, 1)
                        : null,
                    'posts_with_response_count' => count($totals['posts_with_response']),
                    'posts_with_metrics_count' => count($totals['posts_with_metrics']),
                    'latest_complete_capture_at' => $totals['latest_complete_capture_at']?->timezone(self::HISTORY_TIMEZONE)->format('Y-m-d H:i'),
                    'failure_reason_counts' => $failureReasonCounts,
                    'latest_failure_reason' => $totals['latest_failure_reason']['label'] ?? null,
                    'latest_failure_at' => ($totals['latest_failure_reason']['finished_at'] ?? null)?->timezone(self::HISTORY_TIMEZONE)->format('Y-m-d H:i'),
                ];
            }
        }

        return $rows;
    }

    private function monitorMatchesTag(Monitor $monitor, string $selectedTag, ?string $selectedTagValue): bool
    {
        $tags = $monitor->tagNames();

        return match (true) {
            $selectedTag === 'unlabeled' => $tags === [],
            $selectedTagValue !== null => in_array($selectedTagValue, $tags, true),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array{key: string, label: string}|null
     */
    private function failureReason(string $platform, ?int $normalStatus, ?string $errorType, array $metrics): ?array
    {
        if ($normalStatus !== null && Monitor::publicEngagementInteractionTotal($metrics, $platform) !== null) {
            return null;
        }

        if ($errorType === 'social_access_required') {
            return ['key' => 'platform_access', 'label' => '平台要求登入或驗證'];
        }

        if ($errorType === 'body_too_large') {
            return ['key' => 'response_too_large', 'label' => '回應超過讀取上限'];
        }

        if ($normalStatus === null) {
            return match ($errorType) {
                'dns_timeout', 'dns_no_address', 'dns_nxdomain' => ['key' => 'dns_failure', 'label' => 'DNS 解析失敗'],
                'tls_handshake', 'tls_verify' => ['key' => 'tls_failure', 'label' => 'TLS 連線失敗'],
                'timeout', 'connect_timeout' => ['key' => 'connection_timeout', 'label' => '連線逾時'],
                'connection_error' => ['key' => 'connection_failure', 'label' => '連線失敗'],
                'blocked_ssrf', 'blocked_port', 'redirect_host_not_allowed' => ['key' => 'safety_block', 'label' => '網址或重新導向遭安全規則封鎖'],
                'queue_job_failed' => ['key' => 'check_job_failure', 'label' => '檢查工作失敗'],
                default => ['key' => 'no_http_response', 'label' => '未取得 HTTP 回應'],
            };
        }

        if ($normalStatus < 200 || $normalStatus >= 400) {
            return ['key' => 'http_status_'.$normalStatus, 'label' => 'HTTP '.$normalStatus];
        }

        if ($errorType !== null) {
            return ['key' => 'capture_error', 'label' => '擷取時發生錯誤'];
        }

        $requiredMetrics = Monitor::publicEngagementInteractionMetricKeys($platform);
        $readMetricCount = count(array_filter(
            $requiredMetrics,
            static fn (string $metric): bool => isset($metrics[$metric]) && is_numeric($metrics[$metric]),
        ));

        return $readMetricCount > 0
            ? ['key' => 'partial_metrics', 'label' => '只讀到部分互動數']
            : ['key' => 'metrics_unrecognized', 'label' => '頁面未提供可辨識的互動數'];
    }
}
