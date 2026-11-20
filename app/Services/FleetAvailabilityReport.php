<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class FleetAvailabilityReport
{
    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    private const PERIODS = [
        ['label' => '24 小時', 'duration_seconds' => 86_400],
        ['label' => '7 天', 'duration_seconds' => 604_800],
        ['label' => '30 天', 'duration_seconds' => 2_592_000],
    ];

    public function __construct(private readonly MonitorAvailabilityCalculator $calculator) {}

    /**
     * @return array{
     *     monitor_count: int,
     *     statistics: list<array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, unavailable_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, legacy_interval_fallback: bool}>
     * }
     */
    public function build(CarbonImmutable $asOf): array
    {
        $periodStart = $asOf->subSeconds(max(array_column(self::PERIODS, 'duration_seconds')));
        $staleGraceSeconds = max(0, (int) config('monitoring.stale_grace_seconds'));
        $historyQueryStart = $periodStart->subSeconds(Monitor::MAX_INTERVAL_SECONDS + $staleGraceSeconds);
        $maintenanceLookupDate = $periodStart->timezone(self::HISTORY_TIMEZONE)->subDays(7)->toDateString();
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
        $monitorCount = 0;

        Monitor::query()
            ->where('enabled', true)
            ->select(['id', 'interval_seconds'])
            ->chunkById(500, function (EloquentCollection $monitors) use (
                $asOf,
                $historyQueryStart,
                $maintenanceLookupDate,
                $staleGraceSeconds,
                &$monitorCount,
                &$totalsByPeriod,
            ): void {
                $monitorCount += $monitors->count();
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

                foreach ($statisticsByMonitor as $statistics) {
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
            });

        $statistics = [];

        foreach ($totalsByPeriod as $label => $totals) {
            $statistics[] = $this->formatStatistic($label, $totals);
        }

        return [
            'monitor_count' => $monitorCount,
            'statistics' => $statistics,
        ];
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
}
