<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class MonitorAvailabilityCalculator
{
    /**
     * @param  Collection<int, CheckRun>  $runs
     * @param  Collection<int, MaintenanceWindow>  $maintenanceWindows
     * @return list<array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, legacy_interval_fallback: bool}>
     */
    public function calculate(
        Collection $runs,
        Collection $maintenanceWindows,
        int $currentIntervalSeconds,
        int $staleGraceSeconds,
        DateTimeImmutable $asOf,
    ): array {
        $asOfTimestamp = $asOf->getTimestamp();
        $periods = $this->periods();
        $maintenanceIntervals = $this->maintenanceIntervals($maintenanceWindows, $asOfTimestamp - 2_592_000, $asOfTimestamp);
        $statistics = $this->newStatistics($periods, $maintenanceIntervals, $asOfTimestamp);
        $orderedRuns = $runs
            ->sortBy(static fn (CheckRun $run): int => $run->finished_at?->getTimestamp() ?? 0)
            ->values();
        $previousRun = null;

        foreach ($orderedRuns as $run) {
            if ($previousRun instanceof CheckRun) {
                $this->addRunInterval(
                    $previousRun,
                    $run->finished_at?->getTimestamp(),
                    $statistics,
                    $periods,
                    $maintenanceIntervals,
                    $currentIntervalSeconds,
                    $staleGraceSeconds,
                    $asOfTimestamp,
                );
            }

            $previousRun = $run;
        }

        if ($previousRun instanceof CheckRun) {
            $this->addRunInterval(
                $previousRun,
                null,
                $statistics,
                $periods,
                $maintenanceIntervals,
                $currentIntervalSeconds,
                $staleGraceSeconds,
                $asOfTimestamp,
            );
        }

        return $this->formatStatistics($statistics);
    }

    /**
     * @param  EloquentCollection<int, Monitor>  $monitors
     * @param  Collection<int, MaintenanceWindow>  $maintenanceWindows
     * @param  iterable<CheckRun>  $orderedRuns  Runs must be ordered by monitor ID, then finished time.
     * @return array<int, list<array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, legacy_interval_fallback: bool}>>
     */
    public function calculateForMonitors(
        EloquentCollection $monitors,
        Collection $maintenanceWindows,
        iterable $orderedRuns,
        int $staleGraceSeconds,
        DateTimeImmutable $asOf,
    ): array {
        $asOfTimestamp = $asOf->getTimestamp();
        $periods = $this->periods();
        $statisticsByMonitor = [];
        $maintenanceIntervalsByMonitor = [];
        $currentIntervalsByMonitor = [];

        foreach ($monitors as $monitor) {
            $monitorId = (int) $monitor->id;
            $monitorWindows = $maintenanceWindows->where('monitor_id', $monitorId);
            $maintenanceIntervals = $this->maintenanceIntervals($monitorWindows, $asOfTimestamp - 2_592_000, $asOfTimestamp);

            $statisticsByMonitor[$monitorId] = $this->newStatistics($periods, $maintenanceIntervals, $asOfTimestamp);
            $maintenanceIntervalsByMonitor[$monitorId] = $maintenanceIntervals;
            $currentIntervalsByMonitor[$monitorId] = $monitor->interval_seconds;
        }

        $previousRunsByMonitor = [];

        foreach ($orderedRuns as $run) {
            $monitorId = (int) $run->monitor_id;

            if (! array_key_exists($monitorId, $statisticsByMonitor)) {
                continue;
            }

            $previousRun = $previousRunsByMonitor[$monitorId] ?? null;

            if ($previousRun instanceof CheckRun) {
                $this->addRunInterval(
                    $previousRun,
                    $run->finished_at?->getTimestamp(),
                    $statisticsByMonitor[$monitorId],
                    $periods,
                    $maintenanceIntervalsByMonitor[$monitorId],
                    $currentIntervalsByMonitor[$monitorId],
                    $staleGraceSeconds,
                    $asOfTimestamp,
                );
            }

            $previousRunsByMonitor[$monitorId] = $run;
        }

        foreach ($previousRunsByMonitor as $monitorId => $previousRun) {
            $this->addRunInterval(
                $previousRun,
                null,
                $statisticsByMonitor[$monitorId],
                $periods,
                $maintenanceIntervalsByMonitor[$monitorId],
                $currentIntervalsByMonitor[$monitorId],
                $staleGraceSeconds,
                $asOfTimestamp,
            );
        }

        return array_map(fn (array $statistics): array => $this->formatStatistics($statistics), $statisticsByMonitor);
    }

    /** @return list<array{label: string, duration_seconds: int}> */
    private function periods(): array
    {
        return [
            ['label' => '24 小時', 'duration_seconds' => 86_400],
            ['label' => '7 天', 'duration_seconds' => 604_800],
            ['label' => '30 天', 'duration_seconds' => 2_592_000],
        ];
    }

    /**
     * @param  list<array{label: string, duration_seconds: int}>  $periods
     * @param  list<array{starts_at: int, ends_at: int}>  $maintenanceIntervals
     * @return array<string, array{label: string, availability_rate: ?float, coverage_rate: ?float, up_seconds: int, observed_seconds: int, eligible_seconds: int, maintenance_excluded_seconds: int, legacy_interval_fallback: bool}>
     */
    private function newStatistics(array $periods, array $maintenanceIntervals, int $asOfTimestamp): array
    {
        $statistics = [];

        foreach ($periods as $period) {
            $startsAt = $asOfTimestamp - $period['duration_seconds'];
            $maintenanceSeconds = $this->overlapSeconds($startsAt, $asOfTimestamp, $maintenanceIntervals);
            $eligibleSeconds = $period['duration_seconds'] - $maintenanceSeconds;
            $statistics[$period['label']] = [
                'label' => $period['label'],
                'availability_rate' => null,
                'coverage_rate' => $eligibleSeconds === 0 ? null : 0.0,
                'up_seconds' => 0,
                'observed_seconds' => 0,
                'eligible_seconds' => $eligibleSeconds,
                'maintenance_excluded_seconds' => $maintenanceSeconds,
                'legacy_interval_fallback' => false,
            ];
        }

        return $statistics;
    }

    /**
     * @param  array<string, array{label: string, availability_rate: ?float, coverage_rate: ?float, up_seconds: int, observed_seconds: int, eligible_seconds: int, maintenance_excluded_seconds: int, legacy_interval_fallback: bool}>  $statistics
     * @param  list<array{label: string, duration_seconds: int}>  $periods
     * @param  list<array{starts_at: int, ends_at: int}>  $maintenanceIntervals
     */
    private function addRunInterval(
        CheckRun $run,
        ?int $nextRunFinishedAt,
        array &$statistics,
        array $periods,
        array $maintenanceIntervals,
        int $currentIntervalSeconds,
        int $staleGraceSeconds,
        int $asOfTimestamp,
    ): void {
        if ($run->finished_at === null) {
            return;
        }

        if ($run->hasIndeterminateContentMatch()) {
            return;
        }

        $finishedAt = $run->finished_at->getTimestamp();
        $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $hasSnapshotInterval = isset($snapshot['interval_seconds']) && is_numeric($snapshot['interval_seconds']);
        $intervalSeconds = max(1, (int) ($hasSnapshotInterval ? $snapshot['interval_seconds'] : $currentIntervalSeconds));
        $observationEndsAt = min($asOfTimestamp, $finishedAt + $intervalSeconds + max(0, $staleGraceSeconds));

        if ($nextRunFinishedAt !== null) {
            $observationEndsAt = min($observationEndsAt, $nextRunFinishedAt);
        }

        if ($run->status !== 'completed' || $observationEndsAt <= $finishedAt) {
            return;
        }

        $isAvailable = $run->passesMonitorChecks();

        foreach ($periods as $period) {
            $periodStartsAt = $asOfTimestamp - $period['duration_seconds'];
            $observationStartsAt = max($finishedAt, $periodStartsAt);

            if ($observationEndsAt <= $observationStartsAt) {
                continue;
            }

            $observedSeconds = $this->secondsExcludingMaintenance(
                $observationStartsAt,
                $observationEndsAt,
                $maintenanceIntervals,
            );

            if ($observedSeconds === 0) {
                continue;
            }

            $statistics[$period['label']]['observed_seconds'] += $observedSeconds;
            $statistics[$period['label']]['legacy_interval_fallback'] = $statistics[$period['label']]['legacy_interval_fallback'] || ! $hasSnapshotInterval;

            if ($isAvailable) {
                $statistics[$period['label']]['up_seconds'] += $observedSeconds;
            }
        }
    }

    /**
     * @param  array<string, array{label: string, availability_rate: ?float, coverage_rate: ?float, up_seconds: int, observed_seconds: int, eligible_seconds: int, maintenance_excluded_seconds: int, legacy_interval_fallback: bool}>  $statistics
     * @return list<array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, legacy_interval_fallback: bool}>
     */
    private function formatStatistics(array $statistics): array
    {
        return array_map(static function (array $statistic): array {
            $observedSeconds = $statistic['observed_seconds'];
            $eligibleSeconds = $statistic['eligible_seconds'];

            return [
                'label' => $statistic['label'],
                'availability_rate' => $observedSeconds === 0
                    ? null
                    : round(($statistic['up_seconds'] / $observedSeconds) * 100, 2),
                'coverage_rate' => $eligibleSeconds === 0
                    ? null
                    : round(($observedSeconds / $eligibleSeconds) * 100, 2),
                'up_minutes' => round($statistic['up_seconds'] / 60, 1),
                'observed_minutes' => round($observedSeconds / 60, 1),
                'eligible_minutes' => round($eligibleSeconds / 60, 1),
                'maintenance_excluded_minutes' => round($statistic['maintenance_excluded_seconds'] / 60, 1),
                'legacy_interval_fallback' => $statistic['legacy_interval_fallback'],
            ];
        }, array_values($statistics));
    }

    /**
     * @param  Collection<int, MaintenanceWindow>  $maintenanceWindows
     * @return list<array{starts_at: int, ends_at: int}>
     */
    private function maintenanceIntervals(Collection $maintenanceWindows, int $historyStartsAt, int $asOfTimestamp): array
    {
        $intervals = [];

        foreach ($maintenanceWindows as $maintenanceWindow) {
            foreach ($maintenanceWindow->occurrenceIntervals() as $occurrence) {
                $startsAt = $occurrence['starts_at']->getTimestamp();
                $endsAt = $occurrence['ends_at']->getTimestamp();

                if ($startsAt < $asOfTimestamp && $endsAt > $historyStartsAt) {
                    $intervals[] = ['starts_at' => $startsAt, 'ends_at' => $endsAt];
                }
            }
        }

        usort($intervals, static fn (array $left, array $right): int => $left['starts_at'] <=> $right['starts_at']);

        return $intervals;
    }

    /**
     * @param  list<array{starts_at: int, ends_at: int}>  $maintenanceIntervals
     */
    private function secondsExcludingMaintenance(int $startsAt, int $endsAt, array $maintenanceIntervals): int
    {
        $eligibleSeconds = $endsAt - $startsAt;
        $maintenanceSeconds = $this->overlapSeconds($startsAt, $endsAt, $maintenanceIntervals);

        return max(0, $eligibleSeconds - $maintenanceSeconds);
    }

    /**
     * @param  list<array{starts_at: int, ends_at: int}>  $maintenanceIntervals
     */
    private function overlapSeconds(int $startsAt, int $endsAt, array $maintenanceIntervals): int
    {
        $overlapSeconds = 0;
        $cursor = $startsAt;

        foreach ($maintenanceIntervals as $interval) {
            if ($interval['ends_at'] <= $cursor) {
                continue;
            }

            if ($interval['starts_at'] >= $endsAt) {
                break;
            }

            $overlapStartsAt = max($cursor, $interval['starts_at']);
            $overlapEndsAt = min($endsAt, $interval['ends_at']);

            if ($overlapEndsAt > $overlapStartsAt) {
                $overlapSeconds += $overlapEndsAt - $overlapStartsAt;
                $cursor = $overlapEndsAt;
            }
        }

        return $overlapSeconds;
    }
}
