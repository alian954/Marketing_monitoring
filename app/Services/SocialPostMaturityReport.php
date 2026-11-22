<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SocialPostMaturityReport
{
    private const OBSERVATION_TOLERANCE_SECONDS = 21_600;

    /** @var array<string, array{label: string, seconds: int}> */
    private const MATURITY_AGES = [
        '24h' => ['label' => '發文後 24 小時', 'seconds' => 86_400],
        '7d' => ['label' => '發文後 7 天', 'seconds' => 604_800],
        '30d' => ['label' => '發文後 30 天', 'seconds' => 2_592_000],
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
     *     maturity_label: string,
     *     platform_key: string,
     *     platform: string,
     *     interaction_scope_label: string,
     *     average_interactions_per_post: float,
     *     median_interactions_per_post: float,
     *     average_views_per_post: float|null,
     *     view_sample_count: int,
     *     engagement_rate_percent: float|null,
     *     post_count: int
     * }>
     */
    public function build(
        CarbonImmutable $asOf,
        ?string $selectedPlatform,
        string $selectedTag,
        ?string $selectedTagValue,
        int $retentionDays,
    ): array {
        $databaseDriver = DB::connection()->getDriverName();
        $elapsedSecondsExpression = $this->elapsedSecondsExpression($databaseDriver);
        $historyStartsAt = $asOf->subDays(max(1, $retentionDays));
        $nearestObservationsByAge = [];

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
            ->whereNotNull('check_runs.response_published_at')
            ->whereBetween('check_runs.finished_at', [$historyStartsAt, $asOf])
            ->whereNotNull('check_runs.response_public_engagement_metrics')
            ->whereNotIn('check_runs.source', ['tls_daily', 'domain_daily'])
            ->whereHas('monitor', static function (Builder $query) use ($selectedPlatform): void {
                $query->whereNotNull('social_platform')
                    ->whereColumn('check_runs.config_version', 'monitors.config_version');

                if ($selectedPlatform !== null) {
                    $query->where('social_platform', $selectedPlatform);
                }
            })
            ->where(function (Builder $query) use ($elapsedSecondsExpression): void {
                foreach (self::MATURITY_AGES as $maturityAge) {
                    $query->orWhereRaw(
                        'ABS('.$elapsedSecondsExpression.' - ?) <= ?',
                        [$maturityAge['seconds'], self::OBSERVATION_TOLERANCE_SECONDS],
                    );
                }
            })
            ->with('monitor:id,social_platform,config_version,tags,label')
            ->chunkById(500, function (EloquentCollection $runBatch) use (&$nearestObservationsByAge, $selectedTag, $selectedTagValue): void {
                foreach ($runBatch as $run) {
                    $monitor = $run->monitor;
                    $platform = $monitor?->social_platform;

                    if (! is_string($platform)
                        || $run->finished_at === null
                        || $run->response_published_at === null
                        || ! $this->monitorMatchesTag($monitor, $selectedTag, $selectedTagValue)) {
                        continue;
                    }

                    $metrics = is_array($run->response_public_engagement_metrics)
                        ? $run->response_public_engagement_metrics
                        : [];
                    $interactionTotal = Monitor::publicEngagementInteractionTotal($metrics, $platform);

                    if ($interactionTotal === null) {
                        continue;
                    }

                    $elapsedSeconds = $run->finished_at->getTimestamp() - $run->response_published_at->getTimestamp();

                    foreach (self::MATURITY_AGES as $ageKey => $maturityAge) {
                        $distanceFromMaturity = abs($elapsedSeconds - $maturityAge['seconds']);

                        if ($distanceFromMaturity > self::OBSERVATION_TOLERANCE_SECONDS) {
                            continue;
                        }

                        $candidate = [
                            'distance_seconds' => $distanceFromMaturity,
                            'is_before_maturity' => $elapsedSeconds <= $maturityAge['seconds'],
                            'finished_at_timestamp' => $run->finished_at->getTimestamp(),
                            'platform_key' => $platform,
                            'interaction_total' => $interactionTotal,
                            'views' => is_numeric($metrics['views'] ?? null) ? (int) $metrics['views'] : null,
                        ];
                        $existing = $nearestObservationsByAge[$ageKey][$monitor->id] ?? null;
                        $candidateIsBetter = $existing === null
                            || $candidate['distance_seconds'] < $existing['distance_seconds']
                            || ($candidate['distance_seconds'] === $existing['distance_seconds']
                                && $candidate['is_before_maturity']
                                && ! $existing['is_before_maturity'])
                            || ($candidate['distance_seconds'] === $existing['distance_seconds']
                                && $candidate['is_before_maturity'] === $existing['is_before_maturity']
                                && $candidate['finished_at_timestamp'] > $existing['finished_at_timestamp']);

                        if ($candidateIsBetter) {
                            $nearestObservationsByAge[$ageKey][$monitor->id] = $candidate;
                        }
                    }
                }
            }, 'check_runs.id', 'id');

        $rows = [];

        foreach (self::MATURITY_AGES as $ageKey => $maturityAge) {
            $totalsByPlatform = [];

            foreach ($nearestObservationsByAge[$ageKey] ?? [] as $observation) {
                $platform = $observation['platform_key'];
                $totalsByPlatform[$platform] ??= [
                    'interaction_total' => 0,
                    'post_count' => 0,
                    'interaction_values' => [],
                    'view_total' => 0,
                    'view_sample_count' => 0,
                    'interactions_with_views' => 0,
                    'views_for_rate' => 0,
                ];
                $totalsByPlatform[$platform]['interaction_total'] += $observation['interaction_total'];
                $totalsByPlatform[$platform]['post_count']++;
                $totalsByPlatform[$platform]['interaction_values'][] = $observation['interaction_total'];

                if ($observation['views'] !== null) {
                    $totalsByPlatform[$platform]['view_total'] += $observation['views'];
                    $totalsByPlatform[$platform]['view_sample_count']++;

                    if ($observation['views'] > 0) {
                        $totalsByPlatform[$platform]['interactions_with_views'] += $observation['interaction_total'];
                        $totalsByPlatform[$platform]['views_for_rate'] += $observation['views'];
                    }
                }
            }

            foreach (self::PLATFORM_LABELS as $platformKey => $platformLabel) {
                $totals = $totalsByPlatform[$platformKey] ?? null;

                if ($totals === null || $totals['post_count'] === 0) {
                    continue;
                }

                $sortedInteractionValues = $totals['interaction_values'];
                sort($sortedInteractionValues, SORT_NUMERIC);
                $middleIndex = intdiv(count($sortedInteractionValues), 2);
                $medianInteractionTotal = count($sortedInteractionValues) % 2 === 1
                    ? $sortedInteractionValues[$middleIndex]
                    : ($sortedInteractionValues[$middleIndex - 1] + $sortedInteractionValues[$middleIndex]) / 2;

                $rows[] = [
                    'maturity_label' => $maturityAge['label'],
                    'platform_key' => $platformKey,
                    'platform' => $platformLabel,
                    'interaction_scope_label' => Monitor::publicEngagementInteractionScopeLabel($platformKey),
                    'average_interactions_per_post' => round($totals['interaction_total'] / $totals['post_count'], 1),
                    'median_interactions_per_post' => round($medianInteractionTotal, 1),
                    'average_views_per_post' => $totals['view_sample_count'] > 0
                        ? round($totals['view_total'] / $totals['view_sample_count'], 1)
                        : null,
                    'view_sample_count' => $totals['view_sample_count'],
                    'engagement_rate_percent' => $totals['views_for_rate'] > 0
                        ? round($totals['interactions_with_views'] / $totals['views_for_rate'] * 100, 2)
                        : null,
                    'post_count' => $totals['post_count'],
                ];
            }
        }

        return $rows;
    }

    private function elapsedSecondsExpression(string $databaseDriver): string
    {
        return match ($databaseDriver) {
            'sqlite' => '((julianday(check_runs.finished_at) - julianday(check_runs.response_published_at)) * 86400)',
            'mysql', 'mariadb' => 'TIMESTAMPDIFF(SECOND, check_runs.response_published_at, check_runs.finished_at)',
            'pgsql' => 'EXTRACT(EPOCH FROM (check_runs.finished_at - check_runs.response_published_at))',
            'sqlsrv' => 'DATEDIFF(SECOND, check_runs.response_published_at, check_runs.finished_at)',
            default => throw new InvalidArgumentException('Unsupported database driver for social post maturity reporting.'),
        };
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
