<?php

namespace App\Services;

class MonitorSloBudgetCalculator
{
    /**
     * @param  array{eligible_minutes: float, observed_minutes: float, unavailable_minutes: float, coverage_rate: ?float, eligible_seconds?: int, observed_seconds?: int, up_seconds?: int}  $statistic
     * @return array{target_basis_points: int, target_percent: float, eligible_minutes: float, allowed_downtime_minutes: float, observed_downtime_minutes: ?float, remaining_error_budget_minutes: ?float, error_budget_used_percent: ?float, coverage_rate: ?float, has_eligible_time: bool, has_observations: bool, low_coverage: bool}
     */
    public function calculate(array $statistic, int $targetBasisPoints): array
    {
        $eligibleMinutes = ($statistic['eligible_seconds'] ?? ($statistic['eligible_minutes'] * 60)) / 60;
        $hasEligibleTime = $eligibleMinutes > 0;
        $allowedDowntimeMinutes = round($eligibleMinutes * (10_000 - $targetBasisPoints) / 10_000, 2);
        $observedMinutes = ($statistic['observed_seconds'] ?? ($statistic['observed_minutes'] * 60)) / 60;
        $unavailableMinutes = isset($statistic['observed_seconds'], $statistic['up_seconds'])
            ? max(0, $statistic['observed_seconds'] - $statistic['up_seconds']) / 60
            : $statistic['unavailable_minutes'];
        $hasObservations = $observedMinutes > 0 && $hasEligibleTime;
        $observedDowntimeMinutes = $hasObservations ? round($unavailableMinutes, 2) : null;
        $remainingErrorBudgetMinutes = $observedDowntimeMinutes === null
            ? null
            : round($allowedDowntimeMinutes - $observedDowntimeMinutes, 2);
        $errorBudgetUsedPercent = $observedDowntimeMinutes === null || $allowedDowntimeMinutes <= 0
            ? null
            : round(($observedDowntimeMinutes / $allowedDowntimeMinutes) * 100, 2);
        $coverageRate = $statistic['coverage_rate'];

        return [
            'target_basis_points' => $targetBasisPoints,
            'target_percent' => $targetBasisPoints / 100,
            'eligible_minutes' => $eligibleMinutes,
            'allowed_downtime_minutes' => $allowedDowntimeMinutes,
            'observed_downtime_minutes' => $observedDowntimeMinutes,
            'remaining_error_budget_minutes' => $remainingErrorBudgetMinutes,
            'error_budget_used_percent' => $errorBudgetUsedPercent,
            'coverage_rate' => $coverageRate,
            'has_eligible_time' => $hasEligibleTime,
            'has_observations' => $hasObservations,
            'low_coverage' => $coverageRate !== null && $coverageRate < 90,
        ];
    }
}
