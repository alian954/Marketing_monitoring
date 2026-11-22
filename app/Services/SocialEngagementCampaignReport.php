<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class SocialEngagementCampaignReport
{
    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    private const PLATFORM_LABELS = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'threads' => 'Threads',
        'dcard' => 'Dcard',
    ];

    /**
     * @return array{
     *     target: SocialEngagementTarget,
     *     report: array{
     *         platform_label: string,
     *         interaction_scope_label: string,
     *         generated_at: CarbonImmutable,
     *         tracked_post_count: int,
     *         complete_read_count: int,
     *         capture_coverage_percent: float|null,
     *         unknown_publication_date_count: int,
     *         interaction_total: int|null,
     *         target_completion_percent: float|null,
     *         average_interactions_per_post: float|null,
     *         engagement_rate_percent: float|null,
     *         metrics: array<string, array{total: int|null, available: int, tracked: int}>,
     *         weekly_interaction_changes: list<array{
     *             week_start: string,
     *             week_end: string,
     *             label: string,
     *             interaction_change: int,
     *             compared_post_count: int
     *         }>,
     *         top_posts: list<array{
     *             id: int,
     *             name: string,
     *             published_at: CarbonImmutable|null,
     *             views: int|null,
     *             likes: int|null,
     *             comments: int|null,
     *             shares: int|null,
     *             interaction_total: int|null,
     *             metrics_freshness: string,
     *             finished_at: CarbonImmutable|null
     *         }>
     *     }
     * }
     */
    public function build(SocialEngagementTarget $target, bool $includeHistoricalDetails = true): array
    {
        $asOf = CarbonImmutable::now(self::HISTORY_TIMEZONE);
        $platform = $target->social_platform;
        $campaignStart = $target->starts_on?->toDateString();
        $campaignEnd = $target->ends_on?->toDateString();
        $hasCampaignPeriod = $campaignStart !== null && $campaignEnd !== null;
        $trackedPostCount = 0;
        $completeReadCount = 0;
        $unknownPublicationDateCount = 0;
        $interactionTotal = 0;
        $engagementRateInteractions = 0;
        $engagementRateViews = 0;
        $engagementRatePostCount = 0;
        $metricTotals = array_fill_keys(['views', 'likes', 'comments', 'shares'], ['total' => 0, 'available' => 0]);
        $posts = [];
        $sortPosts = static function (array $first, array $second): int {
            if ($first['interaction_total'] === null && $second['interaction_total'] !== null) {
                return 1;
            }

            if ($first['interaction_total'] !== null && $second['interaction_total'] === null) {
                return -1;
            }

            $interactionComparison = ($second['interaction_total'] ?? 0) <=> ($first['interaction_total'] ?? 0);

            return $interactionComparison !== 0
                ? $interactionComparison
                : (strnatcasecmp($first['name'], $second['name']) ?: ($first['id'] <=> $second['id']));
        };

        $monitors = Monitor::query()
            ->where('social_platform', $platform)
            ->where(function (Builder $query) use ($target): void {
                $query->whereJsonContains('tags', $target->tag)
                    ->orWhere('label', $target->tag);
            })
            ->select(['id', 'name', 'social_platform', 'config_version'])
            ->with('latestPublicEngagementCheckRun');

        if ($includeHistoricalDetails) {
            $monitors->with('latestCheckRun');
        }

        $monitors->chunkById(250, function (EloquentCollection $monitorBatch) use (
            &$trackedPostCount,
            &$completeReadCount,
            &$unknownPublicationDateCount,
            &$interactionTotal,
            &$engagementRateInteractions,
            &$engagementRateViews,
            &$engagementRatePostCount,
            &$metricTotals,
            &$posts,
            $platform,
            $campaignStart,
            $campaignEnd,
            $hasCampaignPeriod,
            $includeHistoricalDetails,
            $sortPosts,
        ): void {
            foreach ($monitorBatch as $monitor) {
                $engagementRun = $monitor->latestPublicEngagementCheckRun;

                if ($engagementRun?->config_version !== $monitor->config_version) {
                    $engagementRun = null;
                }

                $publishedAt = $engagementRun?->response_published_at?->timezone(self::HISTORY_TIMEZONE);
                $publishedDate = $publishedAt?->toDateString();

                if ($hasCampaignPeriod && $publishedDate === null) {
                    $unknownPublicationDateCount++;

                    continue;
                }

                if ($hasCampaignPeriod
                    && (($campaignStart !== null && $publishedDate < $campaignStart)
                        || ($campaignEnd !== null && $publishedDate > $campaignEnd))) {
                    continue;
                }

                $trackedPostCount++;
                $metrics = $engagementRun?->response_public_engagement_metrics ?? [];
                $postInteractionTotal = Monitor::publicEngagementInteractionTotal($metrics, $platform);
                $views = is_numeric($metrics['views'] ?? null) ? (int) $metrics['views'] : null;

                foreach (array_keys($metricTotals) as $metric) {
                    if (is_numeric($metrics[$metric] ?? null)) {
                        $metricTotals[$metric]['total'] += (int) $metrics[$metric];
                        $metricTotals[$metric]['available']++;
                    }
                }

                if ($postInteractionTotal !== null) {
                    $completeReadCount++;
                    $interactionTotal += $postInteractionTotal;

                    if ($views !== null && $views > 0) {
                        $engagementRateInteractions += $postInteractionTotal;
                        $engagementRateViews += $views;
                        $engagementRatePostCount++;
                    }
                }

                if (! $includeHistoricalDetails) {
                    continue;
                }

                $latestCheckRun = $monitor->latestCheckRun;
                $captureIsCurrent = $engagementRun !== null && $latestCheckRun?->id === $engagementRun->id;

                $posts[] = [
                    'id' => (int) $monitor->id,
                    'name' => $monitor->name,
                    'published_at' => $publishedAt?->toImmutable(),
                    'views' => $views,
                    'likes' => is_numeric($metrics['likes'] ?? null) ? (int) $metrics['likes'] : null,
                    'comments' => is_numeric($metrics['comments'] ?? null) ? (int) $metrics['comments'] : null,
                    'shares' => is_numeric($metrics['shares'] ?? null) ? (int) $metrics['shares'] : null,
                    'interaction_total' => $postInteractionTotal,
                    'metrics_freshness' => $postInteractionTotal === null
                        ? '尚無完整讀值'
                        : ($captureIsCurrent ? '最新檢查讀取' : '沿用前次讀值'),
                    'finished_at' => $engagementRun?->finished_at?->toImmutable(),
                ];

                if (count($posts) > 10) {
                    usort($posts, $sortPosts);
                    array_pop($posts);
                }
            }
        });

        if ($includeHistoricalDetails) {
            usort($posts, $sortPosts);
        }

        foreach ($metricTotals as $metric => $summary) {
            $metricTotals[$metric] = [
                'total' => $summary['available'] > 0 ? $summary['total'] : null,
                'available' => $summary['available'],
                'tracked' => $trackedPostCount,
            ];
        }

        return [
            'target' => $target,
            'report' => [
                'platform_label' => self::PLATFORM_LABELS[$platform],
                'interaction_scope_label' => Monitor::publicEngagementInteractionScopeLabel($platform),
                'generated_at' => $asOf,
                'tracked_post_count' => $trackedPostCount,
                'complete_read_count' => $completeReadCount,
                'capture_coverage_percent' => $trackedPostCount > 0
                    ? round($completeReadCount / $trackedPostCount * 100, 1)
                    : null,
                'unknown_publication_date_count' => $unknownPublicationDateCount,
                'interaction_total' => $completeReadCount > 0 ? $interactionTotal : null,
                'target_completion_percent' => $completeReadCount > 0
                    ? round($interactionTotal / (int) $target->target_interactions * 100, 1)
                    : null,
                'average_interactions_per_post' => $completeReadCount > 0
                    ? round($interactionTotal / $completeReadCount, 1)
                    : null,
                'engagement_rate_percent' => $engagementRatePostCount > 0
                    ? round($engagementRateInteractions / $engagementRateViews * 100, 2)
                    : null,
                'metrics' => $metricTotals,
                'weekly_interaction_changes' => $includeHistoricalDetails
                    ? $this->weeklyInteractionChanges(
                        $target,
                        $campaignStart,
                        $campaignEnd,
                        $asOf,
                    )
                    : [],
                'top_posts' => $posts,
            ],
        ];
    }

    /**
     * @return list<array{
     *     week_start: string,
     *     week_end: string,
     *     label: string,
     *     interaction_change: int,
     *     compared_post_count: int
     * }>
     */
    private function weeklyInteractionChanges(
        SocialEngagementTarget $target,
        ?string $campaignStart,
        ?string $campaignEnd,
        CarbonImmutable $asOf,
    ): array {
        if ($campaignStart === null || $campaignEnd === null) {
            return [];
        }

        $campaignStartsAt = CarbonImmutable::parse($campaignStart, self::HISTORY_TIMEZONE)->startOfDay()->utc();
        $campaignEndsAt = CarbonImmutable::parse($campaignEnd, self::HISTORY_TIMEZONE)->endOfDay()->utc();
        $asOfUtc = $asOf->utc();
        $observationsEndAt = $campaignEndsAt->lessThan($asOfUtc) ? $campaignEndsAt : $asOfUtc;

        if ($campaignStartsAt->greaterThan($observationsEndAt)) {
            return [];
        }

        $retentionStartsAt = $asOfUtc->subDays(max(1, (int) config('monitoring.retention_days')));
        $observationsStartAt = $campaignStartsAt->greaterThan($retentionStartsAt)
            ? $campaignStartsAt
            : $retentionStartsAt;

        if ($observationsStartAt->greaterThan($observationsEndAt)) {
            return [];
        }

        $observationsByMonitor = [];

        CheckRun::query()
            ->select([
                'check_runs.id',
                'check_runs.monitor_id',
                'check_runs.finished_at',
                'check_runs.response_published_at',
                'check_runs.response_public_engagement_metrics',
            ])
            ->where('check_runs.status', 'completed')
            ->whereNotNull('check_runs.finished_at')
            ->whereBetween('check_runs.finished_at', [$observationsStartAt, $observationsEndAt])
            ->whereBetween('check_runs.response_published_at', [$campaignStartsAt, $observationsEndAt])
            ->whereNotNull('check_runs.response_public_engagement_metrics')
            ->whereNotIn('check_runs.source', ['tls_daily', 'domain_daily'])
            ->whereHas('monitor', static function (Builder $query) use ($target): void {
                $query->where('social_platform', $target->social_platform)
                    ->whereColumn('check_runs.config_version', 'monitors.config_version')
                    ->where(function (Builder $tagQuery) use ($target): void {
                        $tagQuery->whereJsonContains('tags', $target->tag)
                            ->orWhere('label', $target->tag);
                    });
            })
            ->lazyById(500)
            ->each(function (CheckRun $run) use (&$observationsByMonitor, $target, $campaignStart, $campaignEnd): void {
                $publishedDate = $run->response_published_at?->timezone(self::HISTORY_TIMEZONE)->toDateString();

                if ($publishedDate === null || $publishedDate < $campaignStart || $publishedDate > $campaignEnd) {
                    return;
                }

                $interactionTotal = Monitor::publicEngagementInteractionTotal(
                    $run->response_public_engagement_metrics ?? [],
                    $target->social_platform,
                );

                if ($interactionTotal === null || $run->finished_at === null) {
                    return;
                }

                $date = $run->finished_at->timezone(self::HISTORY_TIMEZONE)->toDateString();
                $monitorId = (int) $run->monitor_id;
                $sample = [
                    'id' => (int) $run->id,
                    'finished_at' => $run->finished_at,
                    'interaction_total' => $interactionTotal,
                ];
                $existing = $observationsByMonitor[$monitorId][$date] ?? null;

                if ($existing === null
                    || $sample['finished_at']->greaterThan($existing['finished_at'])
                    || ($sample['finished_at']->equalTo($existing['finished_at']) && $sample['id'] > $existing['id'])) {
                    $observationsByMonitor[$monitorId][$date] = $sample;
                }
            });

        $weeklyChanges = [];

        foreach ($observationsByMonitor as $monitorId => $dailyObservations) {
            ksort($dailyObservations);
            $previousInteractionTotal = null;

            foreach ($dailyObservations as $date => $observation) {
                if ($previousInteractionTotal !== null) {
                    $day = CarbonImmutable::parse($date, self::HISTORY_TIMEZONE);
                    $weekStart = $day->subDays((int) $day->format('N') - 1)->toDateString();
                    $weeklyChanges[$weekStart] ??= [
                        'week_start' => $weekStart,
                        'week_end' => CarbonImmutable::parse($weekStart, self::HISTORY_TIMEZONE)->addDays(6)->toDateString(),
                        'interaction_change' => 0,
                        'post_ids' => [],
                    ];
                    $weeklyChanges[$weekStart]['interaction_change'] += $observation['interaction_total'] - $previousInteractionTotal;
                    $weeklyChanges[$weekStart]['post_ids'][$monitorId] = true;
                }

                $previousInteractionTotal = $observation['interaction_total'];
            }
        }

        ksort($weeklyChanges);

        return array_values(array_map(static function (array $summary): array {
            $weekStart = CarbonImmutable::parse($summary['week_start'], self::HISTORY_TIMEZONE);
            $weekEnd = CarbonImmutable::parse($summary['week_end'], self::HISTORY_TIMEZONE);

            return [
                'week_start' => $summary['week_start'],
                'week_end' => $summary['week_end'],
                'label' => $weekStart->format('n/j').'–'.$weekEnd->format('n/j'),
                'interaction_change' => $summary['interaction_change'],
                'compared_post_count' => count($summary['post_ids']),
            ];
        }, $weeklyChanges));
    }
}
