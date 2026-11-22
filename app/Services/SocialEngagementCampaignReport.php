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

    private const MIN_FORECAST_COMPARABLE_POSTS = 3;

    private const MIN_FORECAST_SAMPLE_COVERAGE_PERCENT = 50.0;

    private const MIN_FORECAST_FRESH_READ_COVERAGE_PERCENT = 80.0;

    private const HIGH_FORECAST_COMPARABLE_POSTS = 5;

    private const HIGH_FORECAST_SAMPLE_COVERAGE_PERCENT = 80.0;

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
     *         observed_interaction_change: int|null,
     *         comparable_interaction_post_count: int,
     *         target_completion_percent: float|null,
     *         goal_forecast: array{
     *             status: string,
     *             status_label: string,
     *             confidence_label: string|null,
     *             comparable_post_count: int,
     *             sample_coverage_percent: float|null,
     *             fresh_read_coverage_percent: float|null,
     *             average_daily_interaction_change: float|null,
     *             projected_interaction_total: int|null,
     *             projected_completion_percent: float|null,
     *             remaining_days: float|null,
     *             latest_observed_at: CarbonImmutable|null
     *         },
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
     *             metric_sources: array{views: string|null, likes: string|null, comments: string|null, shares: string|null},
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
        $freshCompleteReadCount = 0;
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
            &$freshCompleteReadCount,
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
            $asOf,
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
                $metricSources = is_array($metrics['metric_sources'] ?? null) ? $metrics['metric_sources'] : [];
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

                    if ($engagementRun?->finished_at?->greaterThanOrEqualTo($asOf->utc()->subDay())) {
                        $freshCompleteReadCount++;
                    }

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
                    'metric_sources' => [
                        'views' => is_string($metricSources['views'] ?? null) ? $metricSources['views'] : null,
                        'likes' => is_string($metricSources['likes'] ?? null) ? $metricSources['likes'] : null,
                        'comments' => is_string($metricSources['comments'] ?? null) ? $metricSources['comments'] : null,
                        'shares' => is_string($metricSources['shares'] ?? null) ? $metricSources['shares'] : null,
                    ],
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

        $observedInteractionSummary = $this->observedCampaignInteractionChange(
            $target,
            $campaignStart,
            $campaignEnd,
            $asOf,
        );
        $currentInteractionTotal = $completeReadCount > 0 ? $interactionTotal : null;
        $goalForecast = $this->goalForecast(
            $target,
            $currentInteractionTotal,
            $trackedPostCount,
            $freshCompleteReadCount,
            $observedInteractionSummary,
            $asOf,
        );

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
                'interaction_total' => $currentInteractionTotal,
                'observed_interaction_change' => $observedInteractionSummary['interaction_change'],
                'comparable_interaction_post_count' => $observedInteractionSummary['comparable_post_count'],
                'goal_forecast' => $goalForecast,
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
     * @return array{
     *     interaction_change: int|null,
     *     comparable_post_count: int,
     *     average_daily_interaction_change: float|null,
     *     forecast_comparable_post_count: int,
     *     latest_observed_at: CarbonImmutable|null
     * }
     */
    private function observedCampaignInteractionChange(
        SocialEngagementTarget $target,
        ?string $campaignStart,
        ?string $campaignEnd,
        CarbonImmutable $asOf,
    ): array {
        if ($campaignStart === null || $campaignEnd === null) {
            return [
                'interaction_change' => null,
                'comparable_post_count' => 0,
                'average_daily_interaction_change' => null,
                'forecast_comparable_post_count' => 0,
                'latest_observed_at' => null,
            ];
        }

        $campaignStartsAt = CarbonImmutable::parse($campaignStart, self::HISTORY_TIMEZONE)->startOfDay()->utc();
        $campaignEndsAt = CarbonImmutable::parse($campaignEnd, self::HISTORY_TIMEZONE)->endOfDay()->utc();
        $asOfUtc = $asOf->utc();
        $observationsEndAt = $campaignEndsAt->lessThan($asOfUtc) ? $campaignEndsAt : $asOfUtc;

        if ($campaignStartsAt->greaterThanOrEqualTo($observationsEndAt)) {
            return [
                'interaction_change' => null,
                'comparable_post_count' => 0,
                'average_daily_interaction_change' => null,
                'forecast_comparable_post_count' => 0,
                'latest_observed_at' => null,
            ];
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
            ->whereBetween('check_runs.finished_at', [$campaignStartsAt, $observationsEndAt])
            ->whereBetween('check_runs.response_published_at', [$campaignStartsAt, $campaignEndsAt])
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
            ->orderBy('check_runs.finished_at')
            ->orderBy('check_runs.id')
            ->cursor()
            ->each(function (CheckRun $run) use ($target, &$observationsByMonitor): void {
                $interactionTotal = Monitor::publicEngagementInteractionTotal(
                    $run->response_public_engagement_metrics ?? [],
                    $target->social_platform,
                );

                if ($interactionTotal === null || $run->finished_at === null) {
                    return;
                }

                $monitorId = (int) $run->monitor_id;
                $observation = [
                    'id' => (int) $run->id,
                    'finished_at' => $run->finished_at->toImmutable(),
                    'interaction_total' => $interactionTotal,
                ];
                $observationsByMonitor[$monitorId]['first'] ??= $observation;
                $observationsByMonitor[$monitorId]['last'] = $observation;
            });

        $interactionChange = 0;
        $comparablePostCount = 0;
        $dailyInteractionChange = 0.0;
        $forecastComparablePostCount = 0;
        $latestObservedAt = null;

        foreach ($observationsByMonitor as $observations) {
            $latestObservedAt = $latestObservedAt === null
                || $observations['last']['finished_at']->greaterThan($latestObservedAt)
                ? $observations['last']['finished_at']
                : $latestObservedAt;

            if ($observations['first']['id'] === $observations['last']['id']) {
                continue;
            }

            $postInteractionChange = $observations['last']['interaction_total'] - $observations['first']['interaction_total'];
            $interactionChange += $postInteractionChange;
            $comparablePostCount++;
            $observedSeconds = $observations['last']['finished_at']->getTimestamp()
                - $observations['first']['finished_at']->getTimestamp();

            if ($observedSeconds >= 86400
                && $asOf->utc()->getTimestamp() - $observations['last']['finished_at']->utc()->getTimestamp() <= 86400) {
                $dailyInteractionChange += $postInteractionChange / ($observedSeconds / 86400);
                $forecastComparablePostCount++;
            }
        }

        return [
            'interaction_change' => $comparablePostCount > 0 ? $interactionChange : null,
            'comparable_post_count' => $comparablePostCount,
            'average_daily_interaction_change' => $forecastComparablePostCount > 0
                ? round($dailyInteractionChange, 1)
                : null,
            'forecast_comparable_post_count' => $forecastComparablePostCount,
            'latest_observed_at' => $latestObservedAt,
        ];
    }

    /**
     * @param array{
     *     interaction_change: int|null,
     *     comparable_post_count: int,
     *     average_daily_interaction_change: float|null,
     *     forecast_comparable_post_count: int,
     *     latest_observed_at: CarbonImmutable|null
     * } $observations
     * @return array{
     *     status: string,
     *     status_label: string,
     *     confidence_label: string|null,
     *     comparable_post_count: int,
     *     sample_coverage_percent: float|null,
     *     fresh_read_coverage_percent: float|null,
     *     average_daily_interaction_change: float|null,
     *     projected_interaction_total: int|null,
     *     projected_completion_percent: float|null,
     *     remaining_days: float|null,
     *     latest_observed_at: CarbonImmutable|null
     * }
     */
    private function goalForecast(
        SocialEngagementTarget $target,
        ?int $currentInteractionTotal,
        int $trackedPostCount,
        int $freshCompleteReadCount,
        array $observations,
        CarbonImmutable $asOf,
    ): array {
        $comparablePostCount = $observations['forecast_comparable_post_count'];
        $sampleCoveragePercent = $trackedPostCount > 0
            ? round($comparablePostCount / $trackedPostCount * 100, 1)
            : null;
        $freshReadCoveragePercent = $trackedPostCount > 0
            ? round($freshCompleteReadCount / $trackedPostCount * 100, 1)
            : null;
        $base = [
            'confidence_label' => null,
            'comparable_post_count' => $comparablePostCount,
            'sample_coverage_percent' => $sampleCoveragePercent,
            'fresh_read_coverage_percent' => $freshReadCoveragePercent,
            'average_daily_interaction_change' => $observations['average_daily_interaction_change'],
            'projected_interaction_total' => null,
            'projected_completion_percent' => null,
            'remaining_days' => null,
            'latest_observed_at' => $observations['latest_observed_at'],
        ];
        $startsOn = $target->starts_on?->toDateString();
        $endsOn = $target->ends_on?->toDateString();

        if ($startsOn === null || $endsOn === null || $endsOn < $startsOn) {
            return ['status' => 'missing_period', 'status_label' => '請先設定有效的活動日期'] + $base;
        }

        $campaignStartsAt = CarbonImmutable::parse($startsOn, self::HISTORY_TIMEZONE)->startOfDay();
        $campaignEndsAt = CarbonImmutable::parse($endsOn, self::HISTORY_TIMEZONE)->endOfDay();

        if ($asOf->lessThan($campaignStartsAt)) {
            return ['status' => 'not_started', 'status_label' => '活動尚未開始'] + $base;
        }

        if ($asOf->greaterThan($campaignEndsAt)) {
            return ['status' => 'ended', 'status_label' => '活動已結束'] + $base;
        }

        $latestObservedAt = $observations['latest_observed_at'];
        $latestObservationIsFresh = $latestObservedAt !== null
            && $asOf->utc()->getTimestamp() - $latestObservedAt->utc()->getTimestamp() <= 86400;
        $currentGoalIsReached = $currentInteractionTotal !== null
            && $currentInteractionTotal >= $target->target_interactions;
        $currentDataIsFresh = $freshReadCoveragePercent !== null
            && $freshReadCoveragePercent >= self::MIN_FORECAST_FRESH_READ_COVERAGE_PERCENT;

        if ($currentGoalIsReached && $currentDataIsFresh) {
            return ['status' => 'achieved', 'status_label' => '目前已達成互動目標'] + $base;
        }

        if ($currentGoalIsReached && ! $currentDataIsFresh) {
            return ['status' => 'insufficient_data', 'status_label' => '資料不足，暫不推估'] + $base;
        }

        $sampleIsSufficient = $comparablePostCount >= self::MIN_FORECAST_COMPARABLE_POSTS
            && $sampleCoveragePercent !== null
            && $sampleCoveragePercent >= self::MIN_FORECAST_SAMPLE_COVERAGE_PERCENT
            && $freshReadCoveragePercent !== null
            && $freshReadCoveragePercent >= self::MIN_FORECAST_FRESH_READ_COVERAGE_PERCENT;

        if ($currentInteractionTotal === null
            || $observations['average_daily_interaction_change'] === null
            || ! $sampleIsSufficient
            || ! $latestObservationIsFresh) {
            return ['status' => 'insufficient_data', 'status_label' => '資料不足，暫不推估'] + $base;
        }

        $asOfUtc = $asOf->utc();
        $remainingDays = max(0, ($campaignEndsAt->utc()->getTimestamp() - $asOfUtc->getTimestamp()) / 86400);
        $projectedInteractionTotal = max(0, (int) round(
            $currentInteractionTotal + $observations['average_daily_interaction_change'] * $remainingDays,
        ));
        $confidenceLabel = $comparablePostCount >= self::HIGH_FORECAST_COMPARABLE_POSTS
            && $sampleCoveragePercent >= self::HIGH_FORECAST_SAMPLE_COVERAGE_PERCENT
            ? '較穩定'
            : '有限樣本';
        $willReachGoal = $projectedInteractionTotal >= $target->target_interactions;

        return [
            'status' => $willReachGoal ? 'on_track' : 'at_risk',
            'status_label' => $willReachGoal ? '依目前速度推估可達標' : '依目前速度推估未達標',
            'confidence_label' => $confidenceLabel,
            'comparable_post_count' => $comparablePostCount,
            'sample_coverage_percent' => $sampleCoveragePercent,
            'fresh_read_coverage_percent' => $freshReadCoveragePercent,
            'average_daily_interaction_change' => $observations['average_daily_interaction_change'],
            'projected_interaction_total' => $projectedInteractionTotal,
            'projected_completion_percent' => round(
                $projectedInteractionTotal / $target->target_interactions * 100,
                1,
            ),
            'remaining_days' => round($remainingDays, 1),
            'latest_observed_at' => $latestObservedAt,
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
