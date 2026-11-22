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
     *     http_response_count: int,
     *     complete_metric_read_count: int,
     *     coverage_rate_percent: float,
     *     posts_with_response_count: int,
     *     posts_with_metrics_count: int,
     *     latest_complete_capture_at: string|null
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
                'check_runs.response_public_engagement_metrics',
            ])
            ->where('check_runs.status', 'completed')
            ->whereNotNull('check_runs.finished_at')
            ->whereNotNull('check_runs.normal_status')
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
                    $hasCompleteMetrics = Monitor::publicEngagementInteractionTotal($metrics, $platform) !== null;

                    foreach ($availablePeriods as $periodKey => $period) {
                        if ($run->finished_at->lessThan($asOf->subDays($period['days']))) {
                            continue;
                        }

                        $totalsByPeriodAndPlatform[$periodKey][$platform] ??= [
                            'http_response_count' => 0,
                            'complete_metric_read_count' => 0,
                            'posts_with_response' => [],
                            'posts_with_metrics' => [],
                            'latest_complete_capture_at' => null,
                        ];

                        $totalsByPeriodAndPlatform[$periodKey][$platform]['http_response_count']++;
                        $totalsByPeriodAndPlatform[$periodKey][$platform]['posts_with_response'][$monitor->id] = true;

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

                if ($totals === null || $totals['http_response_count'] === 0) {
                    continue;
                }

                $rows[] = [
                    'period_label' => $period['label'],
                    'period_key' => $periodKey,
                    'platform_key' => $platformKey,
                    'platform' => $platformLabel,
                    'interaction_scope_label' => Monitor::publicEngagementInteractionScopeLabel($platformKey),
                    'http_response_count' => $totals['http_response_count'],
                    'complete_metric_read_count' => $totals['complete_metric_read_count'],
                    'coverage_rate_percent' => round($totals['complete_metric_read_count'] / $totals['http_response_count'] * 100, 1),
                    'posts_with_response_count' => count($totals['posts_with_response']),
                    'posts_with_metrics_count' => count($totals['posts_with_metrics']),
                    'latest_complete_capture_at' => $totals['latest_complete_capture_at']?->timezone(self::HISTORY_TIMEZONE)->format('Y-m-d H:i'),
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
}
