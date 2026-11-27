<?php

namespace App\Services;

use App\Models\ClientMonthlyBudget;
use Carbon\CarbonImmutable;

/**
 * Compares a client's monthly budget with the platform spend synced so far and projects month-end spend.
 *
 * Spend data is complete only up to yesterday (Asia/Taipei), so elapsed days count up to yesterday and
 * the remaining days include today.
 */
class ClientBudgetPacing
{
    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_ON_TRACK = 'on_track';

    public const STATUS_OVER_PACE = 'over_pace';

    public const STATUS_UNDER_PACE = 'under_pace';

    public const STATUS_EXHAUSTED = 'exhausted';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_NOT_STARTED => '尚未開始',
        self::STATUS_ON_TRACK => '進度正常',
        self::STATUS_OVER_PACE => '消耗過快',
        self::STATUS_UNDER_PACE => '消耗過慢',
        self::STATUS_EXHAUSTED => '預算已用完',
    ];

    private const TIMEZONE = 'Asia/Taipei';

    private const OVER_PACE_PERCENT = 110.0;

    private const UNDER_PACE_PERCENT = 90.0;

    /**
     * @return array{from: string, to: string, has_elapsed_days: bool}
     */
    public function dataPeriod(string $month, CarbonImmutable $now): array
    {
        $monthStart = $this->monthStart($month);
        $monthEnd = $monthStart->endOfMonth()->startOfDay();
        $yesterday = $now->setTimezone(self::TIMEZONE)->startOfDay()->subDay();
        $dataThrough = $yesterday->lessThan($monthEnd) ? $yesterday : $monthEnd;

        return [
            'from' => $monthStart->toDateString(),
            'to' => $dataThrough->greaterThanOrEqualTo($monthStart) ? $dataThrough->toDateString() : $monthStart->toDateString(),
            'has_elapsed_days' => $dataThrough->greaterThanOrEqualTo($monthStart),
        ];
    }

    /**
     * @param  array{currency_groups: list<array{currency_code: string, platforms: array<string, array<string, mixed>>, total: array<string, mixed>}>}|null  $overview
     * @return array{
     *     month: string,
     *     currency_code: string,
     *     days_in_month: int,
     *     elapsed_days: int,
     *     remaining_days: int,
     *     data_through: string|null,
     *     total: array<string, mixed>,
     *     platforms: array<string, array<string, mixed>>,
     *     unmatched_currency_spend: array<string, float>
     * }
     */
    public function pace(ClientMonthlyBudget $budget, ?array $overview, CarbonImmutable $now): array
    {
        $monthStart = $this->monthStart($budget->month);
        $daysInMonth = $monthStart->daysInMonth;
        $period = $this->dataPeriod($budget->month, $now);
        $elapsedDays = $period['has_elapsed_days']
            ? (int) $monthStart->diff(CarbonImmutable::createFromFormat('!Y-m-d', $period['to'], self::TIMEZONE))->days + 1
            : 0;
        $platformSpend = ['google_ads' => 0.0, 'meta_marketing' => 0.0];
        $unmatched = [];

        foreach ($overview['currency_groups'] ?? [] as $group) {
            if ($group['currency_code'] === $budget->currency_code) {
                foreach (array_keys($platformSpend) as $source) {
                    $platformSpend[$source] = (float) ($group['platforms'][$source]['spend'] ?? 0.0);
                }

                continue;
            }

            if ((float) $group['total']['spend'] > 0) {
                $unmatched[$group['currency_code']] = (float) $group['total']['spend'];
            }
        }

        $platforms = [];

        foreach ($budget->platformBudgets() as $source => $platformBudget) {
            $platforms[$source] = $this->line($platformBudget, $platformSpend[$source], $daysInMonth, $elapsedDays);
        }

        return [
            'month' => $budget->month,
            'currency_code' => $budget->currency_code,
            'days_in_month' => $daysInMonth,
            'elapsed_days' => $elapsedDays,
            'remaining_days' => $daysInMonth - $elapsedDays,
            'data_through' => $period['has_elapsed_days'] ? $period['to'] : null,
            'total' => $this->line((float) $budget->total_budget, array_sum($platformSpend), $daysInMonth, $elapsedDays),
            'platforms' => $platforms,
            'unmatched_currency_spend' => $unmatched,
        ];
    }

    /**
     * @return array{budget: float|null, spend: float, spent_percent: float|null, expected_spend: float|null, pace_percent: float|null, projected_spend: float|null, projected_percent: float|null, remaining_budget: float|null, suggested_daily_spend: float|null, status: string|null}
     */
    private function line(?float $budget, float $spend, int $daysInMonth, int $elapsedDays): array
    {
        $spend = round($spend, 2);

        if ($budget === null || $budget <= 0) {
            return [
                'budget' => null,
                'spend' => $spend,
                'spent_percent' => null,
                'expected_spend' => null,
                'pace_percent' => null,
                'projected_spend' => $elapsedDays > 0 ? round($spend / $elapsedDays * $daysInMonth, 2) : null,
                'projected_percent' => null,
                'remaining_budget' => null,
                'suggested_daily_spend' => null,
                'status' => null,
            ];
        }

        $remainingDays = $daysInMonth - $elapsedDays;
        $expectedSpend = $budget * $elapsedDays / $daysInMonth;
        $pacePercent = $expectedSpend > 0 ? round($spend / $expectedSpend * 100, 1) : null;
        $projectedSpend = $elapsedDays > 0 ? $spend / $elapsedDays * $daysInMonth : null;
        $remainingBudget = $budget - $spend;

        return [
            'budget' => round($budget, 2),
            'spend' => $spend,
            'spent_percent' => round($spend / $budget * 100, 1),
            'expected_spend' => round($expectedSpend, 2),
            'pace_percent' => $pacePercent,
            'projected_spend' => $projectedSpend === null ? null : round($projectedSpend, 2),
            'projected_percent' => $projectedSpend === null ? null : round($projectedSpend / $budget * 100, 1),
            'remaining_budget' => round($remainingBudget, 2),
            'suggested_daily_spend' => $remainingDays > 0 ? round(max(0.0, $remainingBudget) / $remainingDays, 2) : null,
            'status' => match (true) {
                $spend >= $budget => self::STATUS_EXHAUSTED,
                $pacePercent === null => self::STATUS_NOT_STARTED,
                $pacePercent > self::OVER_PACE_PERCENT => self::STATUS_OVER_PACE,
                $pacePercent < self::UNDER_PACE_PERCENT => self::STATUS_UNDER_PACE,
                default => self::STATUS_ON_TRACK,
            },
        ];
    }

    private function monthStart(string $month): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month, self::TIMEZONE)->startOfMonth();
    }
}
