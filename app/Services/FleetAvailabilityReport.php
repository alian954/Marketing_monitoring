<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class FleetAvailabilityReport
{
    private const MAX_PRIORITY_SLO_MONITORS = 10;

    private const HIGH_ERROR_BUDGET_USAGE_PERCENT = 80.0;

    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    /** @var array<string, string> */
    private const SOCIAL_PLATFORM_LABELS = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'threads' => 'Threads',
        'dcard' => 'Dcard',
    ];

    private const PERIODS = [
        ['label' => '24 小時', 'duration_seconds' => 86_400],
        ['label' => '7 天', 'duration_seconds' => 604_800],
        ['label' => '30 天', 'duration_seconds' => 2_592_000],
    ];

    public function __construct(
        private readonly MonitorAvailabilityCalculator $calculator,
        private readonly MonitorSloBudgetCalculator $sloBudgetCalculator,
    ) {}

    /**
     * @return array{
     *     monitor_count: int,
     *     statistics: list<array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, unavailable_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, legacy_interval_fallback: bool}>,
     *     social_platform_statistics: array<string, array{label: string, monitor_count: int, statistics: list<array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, unavailable_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, legacy_interval_fallback: bool}>>,
     *     slo_budget_summary: array{monitor_count: int, exceeded_count: int, high_usage_count: int, insufficient_coverage_count: int, flagged_monitor_count: int, priority_monitors: list<array{id: int, name: string, target_percent: float, allowed_downtime_minutes: float, observed_downtime_minutes: ?float, remaining_error_budget_minutes: ?float, error_budget_used_percent: ?float, coverage_rate: ?float, is_exhausted: bool, is_high_usage: bool, insufficient_coverage: bool, has_observations: bool, sort_priority: int}>}
     * }
     */
    public function build(CarbonImmutable $asOf): array
    {
        $totalsByPeriod = $this->emptyTotalsByPeriod();
        $socialPlatformTotals = [];
        $sloBudgetSummary = [
            'monitor_count' => 0,
            'exceeded_count' => 0,
            'high_usage_count' => 0,
            'insufficient_coverage_count' => 0,
            'flagged_monitor_count' => 0,
            'priority_monitors' => [],
        ];

        foreach (self::SOCIAL_PLATFORM_LABELS as $platform => $label) {
            $socialPlatformTotals[$platform] = [
                'label' => $label,
                'monitor_count' => 0,
                'totals_by_period' => $this->emptyTotalsByPeriod(),
            ];
        }
        $monitorCount = 0;

        foreach ($this->monitorStatistics($asOf) as $monitorStatistics) {
            $monitorCount++;
            $this->accumulateStatistics($totalsByPeriod, $monitorStatistics['statistics']);
            $this->accumulateSloBudgetSummary($sloBudgetSummary, $monitorStatistics['monitor'], $monitorStatistics['statistics']);

            $platform = $monitorStatistics['monitor']->social_platform;

            if (is_string($platform) && isset($socialPlatformTotals[$platform])) {
                $socialPlatformTotals[$platform]['monitor_count']++;
                $this->accumulateStatistics(
                    $socialPlatformTotals[$platform]['totals_by_period'],
                    $monitorStatistics['statistics'],
                );
            }
        }

        $socialPlatformStatistics = [];

        foreach ($socialPlatformTotals as $platform => $summary) {
            $socialPlatformStatistics[$platform] = [
                'label' => $summary['label'],
                'monitor_count' => $summary['monitor_count'],
                'statistics' => $this->formatTotalsByPeriod($summary['totals_by_period']),
            ];
        }

        return [
            'monitor_count' => $monitorCount,
            'statistics' => $this->formatTotalsByPeriod($totalsByPeriod),
            'social_platform_statistics' => $socialPlatformStatistics,
            'slo_budget_summary' => $sloBudgetSummary,
        ];
    }

    /**
     * @param  array{monitor_count: int, exceeded_count: int, high_usage_count: int, insufficient_coverage_count: int, flagged_monitor_count: int, priority_monitors: list<array{id: int, name: string, target_percent: float, allowed_downtime_minutes: float, observed_downtime_minutes: ?float, remaining_error_budget_minutes: ?float, error_budget_used_percent: ?float, coverage_rate: ?float, is_exhausted: bool, is_high_usage: bool, insufficient_coverage: bool, has_observations: bool, sort_priority: int}>}  $summary
     * @param  list<array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, unavailable_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, up_seconds: int, observed_seconds: int, eligible_seconds: int, maintenance_excluded_seconds: int, legacy_interval_fallback: bool}>  $statistics
     */
    private function accumulateSloBudgetSummary(array &$summary, Monitor $monitor, array $statistics): void
    {
        $thirtyDayStatistic = null;

        foreach ($statistics as $statistic) {
            if ($statistic['label'] === '30 天') {
                $thirtyDayStatistic = $statistic;

                break;
            }
        }

        if ($thirtyDayStatistic === null) {
            return;
        }

        $budget = $this->sloBudgetCalculator->calculate(
            $thirtyDayStatistic,
            $monitor->slo_target_basis_points,
        );
        $isExhausted = $budget['remaining_error_budget_minutes'] !== null
            && $budget['remaining_error_budget_minutes'] < 0;
        $isHighUsage = $budget['error_budget_used_percent'] !== null
            && $budget['error_budget_used_percent'] >= self::HIGH_ERROR_BUDGET_USAGE_PERCENT;
        $hasInsufficientCoverage = $budget['has_eligible_time']
            && (! $budget['has_observations'] || $budget['low_coverage']);

        $summary['monitor_count']++;
        $summary['exceeded_count'] += (int) $isExhausted;
        $summary['high_usage_count'] += (int) $isHighUsage;
        $summary['insufficient_coverage_count'] += (int) $hasInsufficientCoverage;

        if (! $isExhausted && ! $isHighUsage && ! $hasInsufficientCoverage) {
            return;
        }

        $summary['flagged_monitor_count']++;
        $summary['priority_monitors'][] = [
            'id' => (int) $monitor->id,
            'name' => $monitor->name,
            'target_percent' => $budget['target_percent'],
            'allowed_downtime_minutes' => $budget['allowed_downtime_minutes'],
            'observed_downtime_minutes' => $budget['observed_downtime_minutes'],
            'remaining_error_budget_minutes' => $budget['remaining_error_budget_minutes'],
            'error_budget_used_percent' => $budget['error_budget_used_percent'],
            'coverage_rate' => $budget['coverage_rate'],
            'is_exhausted' => $isExhausted,
            'is_high_usage' => $isHighUsage,
            'insufficient_coverage' => $hasInsufficientCoverage,
            'has_observations' => $budget['has_observations'],
            'sort_priority' => $isExhausted ? 0 : ($isHighUsage ? 1 : 2),
        ];

        usort($summary['priority_monitors'], function (array $first, array $second): int {
            return ($first['sort_priority'] <=> $second['sort_priority'])
                ?: (($second['error_budget_used_percent'] ?? -1.0) <=> ($first['error_budget_used_percent'] ?? -1.0))
                ?: (($first['coverage_rate'] ?? -1.0) <=> ($second['coverage_rate'] ?? -1.0))
                ?: strcasecmp($first['name'], $second['name'])
                ?: ($first['id'] <=> $second['id']);
        });

        if (count($summary['priority_monitors']) > self::MAX_PRIORITY_SLO_MONITORS) {
            array_pop($summary['priority_monitors']);
        }
    }

    /**
     * @return Generator<int, array{monitor: Monitor, statistics: list<array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, unavailable_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, up_seconds: int, observed_seconds: int, eligible_seconds: int, maintenance_excluded_seconds: int, legacy_interval_fallback: bool}>}>
     */
    public function monitorStatistics(CarbonImmutable $asOf): Generator
    {
        $monitorBatch = [];

        foreach (Monitor::query()
            ->where('enabled', true)
            ->select(['id', 'name', 'interval_seconds', 'social_platform', 'slo_target_basis_points'])
            ->lazyById(500) as $monitor) {
            $monitorBatch[] = $monitor;

            if (count($monitorBatch) < 500) {
                continue;
            }

            yield from $this->statisticsForMonitorBatch(new EloquentCollection($monitorBatch), $asOf);
            $monitorBatch = [];
        }

        if ($monitorBatch !== []) {
            yield from $this->statisticsForMonitorBatch(new EloquentCollection($monitorBatch), $asOf);
        }
    }

    /**
     * @param  EloquentCollection<int, Monitor>  $monitors
     * @return Generator<int, array{monitor: Monitor, statistics: list<array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, unavailable_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, up_seconds: int, observed_seconds: int, eligible_seconds: int, maintenance_excluded_seconds: int, legacy_interval_fallback: bool}>}>
     */
    private function statisticsForMonitorBatch(EloquentCollection $monitors, CarbonImmutable $asOf): Generator
    {
        $periodStart = $asOf->subSeconds(max(array_column(self::PERIODS, 'duration_seconds')));
        $staleGraceSeconds = max(0, (int) config('monitoring.stale_grace_seconds'));
        $historyQueryStart = $periodStart->subSeconds(Monitor::MAX_INTERVAL_SECONDS + $staleGraceSeconds);
        $maintenanceLookupDate = $periodStart->timezone(self::HISTORY_TIMEZONE)->subDays(7)->toDateString();
        $monitorIds = $monitors->modelKeys();
        $maintenanceWindows = MaintenanceWindow::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where(function (Builder $query) use ($historyQueryStart, $maintenanceLookupDate): void {
                $query->where(function (Builder $query) use ($historyQueryStart): void {
                    $query->where('repeat_weekly', false)->where('ends_at', '>', $historyQueryStart);
                })->orWhere(function (Builder $query) use ($maintenanceLookupDate): void {
                    $query->where('repeat_weekly', true)->whereDate('repeat_until', '>=', $maintenanceLookupDate);
                });
            })
            ->get(['id', 'monitor_id', 'starts_at', 'ends_at', 'repeat_weekly', 'repeat_until']);
        $checkRuns = CheckRun::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('source', 'scheduled')
            ->whereIn('status', ['completed', 'failed'])
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $historyQueryStart)
            ->where('finished_at', '<=', $asOf)
            ->orderBy('monitor_id')
            ->orderBy('finished_at')
            ->orderBy('id')
            ->select([
                'id',
                'monitor_id',
                'config_snapshot',
                'status',
                'finished_at',
                'normal_status',
                'error_type',
                'expected_text_found',
                'response_visible_text_changed',
                'expected_response_header_found',
            ])
            ->lazy(500);
        $statisticsByMonitor = $this->calculator->calculateForMonitors(
            $monitors,
            $maintenanceWindows,
            $checkRuns,
            $staleGraceSeconds,
            $asOf,
            self::PERIODS,
        );

        foreach ($monitors as $monitor) {
            $statistics = $statisticsByMonitor[(int) $monitor->id] ?? null;

            if ($statistics === null) {
                continue;
            }

            yield ['monitor' => $monitor, 'statistics' => $statistics];
        }
    }

    /**
     * @param  array{up_seconds: int, observed_seconds: int, eligible_seconds: int, maintenance_excluded_seconds: int, legacy_interval_fallback: bool}  $totals
     * @return array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, unavailable_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, legacy_interval_fallback: bool}
     */
    private function formatStatistic(string $label, array $totals): array
    {
        return [
            'label' => $label,
            'availability_rate' => $totals['observed_seconds'] === 0
                ? null
                : round(($totals['up_seconds'] / $totals['observed_seconds']) * 100, 2),
            'coverage_rate' => $totals['eligible_seconds'] === 0
                ? null
                : round(($totals['observed_seconds'] / $totals['eligible_seconds']) * 100, 2),
            'up_minutes' => round($totals['up_seconds'] / 60, 1),
            'unavailable_minutes' => round(max(0, $totals['observed_seconds'] - $totals['up_seconds']) / 60, 1),
            'observed_minutes' => round($totals['observed_seconds'] / 60, 1),
            'eligible_minutes' => round($totals['eligible_seconds'] / 60, 1),
            'maintenance_excluded_minutes' => round($totals['maintenance_excluded_seconds'] / 60, 1),
            'legacy_interval_fallback' => $totals['legacy_interval_fallback'],
        ];
    }

    /**
     * @return array<string, array{up_seconds: int, observed_seconds: int, eligible_seconds: int, maintenance_excluded_seconds: int, legacy_interval_fallback: bool}>
     */
    private function emptyTotalsByPeriod(): array
    {
        $totalsByPeriod = [];

        foreach (self::PERIODS as $period) {
            $totalsByPeriod[$period['label']] = [
                'up_seconds' => 0,
                'observed_seconds' => 0,
                'eligible_seconds' => 0,
                'maintenance_excluded_seconds' => 0,
                'legacy_interval_fallback' => false,
            ];
        }

        return $totalsByPeriod;
    }

    /**
     * @param  array<string, array{up_seconds: int, observed_seconds: int, eligible_seconds: int, maintenance_excluded_seconds: int, legacy_interval_fallback: bool}>  $totalsByPeriod
     * @param  list<array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, unavailable_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, up_seconds: int, observed_seconds: int, eligible_seconds: int, maintenance_excluded_seconds: int, legacy_interval_fallback: bool}>  $statistics
     */
    private function accumulateStatistics(array &$totalsByPeriod, array $statistics): void
    {
        foreach ($statistics as $statistic) {
            $label = $statistic['label'];
            $totalsByPeriod[$label]['up_seconds'] += $statistic['up_seconds'];
            $totalsByPeriod[$label]['observed_seconds'] += $statistic['observed_seconds'];
            $totalsByPeriod[$label]['eligible_seconds'] += $statistic['eligible_seconds'];
            $totalsByPeriod[$label]['maintenance_excluded_seconds'] += $statistic['maintenance_excluded_seconds'];
            $totalsByPeriod[$label]['legacy_interval_fallback'] = $totalsByPeriod[$label]['legacy_interval_fallback']
                || $statistic['legacy_interval_fallback'];
        }
    }

    /**
     * @param  array<string, array{up_seconds: int, observed_seconds: int, eligible_seconds: int, maintenance_excluded_seconds: int, legacy_interval_fallback: bool}>  $totalsByPeriod
     * @return list<array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, unavailable_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, legacy_interval_fallback: bool}>
     */
    private function formatTotalsByPeriod(array $totalsByPeriod): array
    {
        $statistics = [];

        foreach ($totalsByPeriod as $label => $totals) {
            $statistics[] = $this->formatStatistic($label, $totals);
        }

        return $statistics;
    }
}
