<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class MonitorAvailabilityReport
{
    public function __construct(private readonly MonitorAvailabilityCalculator $calculator) {}

    /**
     * @return array{
     *     history_runs: Collection<int, CheckRun>,
     *     maintenance_windows: Collection<int, MaintenanceWindow>,
     *     statistics: list<array{label: string, availability_rate: ?float, coverage_rate: ?float, up_minutes: float, observed_minutes: float, eligible_minutes: float, maintenance_excluded_minutes: float, up_seconds: int, observed_seconds: int, eligible_seconds: int, maintenance_excluded_seconds: int, legacy_interval_fallback: bool}>
     * }
     */
    public function build(Monitor $monitor, CarbonImmutable $asOf): array
    {
        $historyStart = $asOf->copy()->subDays(30);
        $historyQueryStart = $historyStart->copy()->subSeconds(
            Monitor::MAX_INTERVAL_SECONDS + max(0, (int) config('monitoring.stale_grace_seconds')),
        );
        $historyRuns = $monitor->checkRuns()
            ->where('source', 'scheduled')
            ->whereIn('status', ['completed', 'failed'])
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $historyQueryStart)
            ->orderBy('finished_at')
            ->get(['id', 'config_snapshot', 'status', 'finished_at', 'normal_status', 'error_type', 'normal_elapsed_ms', 'expected_text_found', 'expected_response_header_found']);

        $maintenanceLookupDate = $historyStart->copy()->timezone('Asia/Taipei')->subDays(7)->toDateString();
        $maintenanceWindows = $monitor->maintenanceWindows()
            ->where(function (Builder $query) use ($historyStart, $maintenanceLookupDate): void {
                $query->where(function (Builder $query) use ($historyStart): void {
                    $query->where('repeat_weekly', false)->where('ends_at', '>', $historyStart);
                })->orWhere(function (Builder $query) use ($maintenanceLookupDate): void {
                    $query->where('repeat_weekly', true)->whereDate('repeat_until', '>=', $maintenanceLookupDate);
                });
            })
            ->get();

        return [
            'history_runs' => $historyRuns,
            'maintenance_windows' => $maintenanceWindows,
            'statistics' => $this->calculator->calculate(
                $historyRuns,
                $maintenanceWindows,
                $monitor->interval_seconds,
                (int) config('monitoring.stale_grace_seconds'),
                $asOf,
            ),
        ];
    }
}
