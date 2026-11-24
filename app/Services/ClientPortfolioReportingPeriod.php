<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class ClientPortfolioReportingPeriod
{
    /** @var array<string, array{summary_key: string, daily_key: string, conversion_key: string|null, conversion_summary_key: string|null}> */
    private const SOURCE_METRICS = [
        'google_analytics' => [
            'summary_key' => 'revenue',
            'daily_key' => 'total_revenue',
            'conversion_key' => null,
            'conversion_summary_key' => null,
        ],
        'meta_marketing' => [
            'summary_key' => 'spend_amount',
            'daily_key' => 'spend_amount',
            'conversion_key' => 'omni_purchase_value',
            'conversion_summary_key' => 'meta_ads_omni_purchase_value',
        ],
        'google_ads' => [
            'summary_key' => 'spend_amount',
            'daily_key' => 'spend_amount',
            'conversion_key' => 'conversions_value',
            'conversion_summary_key' => 'google_ads_conversion_value',
        ],
    ];

    /** @return array{from: string, to: string} */
    public function defaultPeriod(): array
    {
        $to = CarbonImmutable::now('Asia/Taipei')->startOfDay()->subDay();

        return [
            'from' => $to->subDays(29)->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    /**
     * @param  Collection<int, SocialEngagementTarget>  $targets
     * @param  array<int, array<string, mixed>>  $marketingSummaries
     * @return array{targets: Collection<int, SocialEngagementTarget>, marketing_summaries: array<int, array<string, mixed>>}
     */
    public function apply(
        Collection $targets,
        array $marketingSummaries,
        string $fromOn,
        string $toOn,
    ): array {
        $periodTargets = $targets
            ->filter(function (SocialEngagementTarget $target) use ($fromOn, $toOn): bool {
                $startsOn = $target->starts_on?->toDateString();
                $endsOn = $target->ends_on?->toDateString();

                return $startsOn !== null
                    && $endsOn !== null
                    && $startsOn <= $toOn
                    && $endsOn >= $fromOn;
            })
            ->values();
        $periodSummaries = [];

        foreach ($periodTargets as $target) {
            $targetId = (int) $target->getKey();
            $marketing = is_array($marketingSummaries[$targetId] ?? null)
                ? $marketingSummaries[$targetId]
                : [];
            $dailyPerformance = is_array($marketing['daily_performance'] ?? null)
                ? $marketing['daily_performance']
                : [];
            $efficiency = is_array($marketing['efficiency'] ?? null)
                ? $marketing['efficiency']
                : [];
            $periodStartOn = max($fromOn, $target->starts_on->toDateString());
            $periodEndOn = min($toOn, $target->ends_on->toDateString());

            foreach (self::SOURCE_METRICS as $source => $metrics) {
                $sourceSummary = is_array($marketing[$source] ?? null) ? $marketing[$source] : [];
                $dailySource = is_array($dailyPerformance[$source] ?? null) ? $dailyPerformance[$source] : [];
                $dailyRows = $this->rowsWithinPeriod($dailySource['rows'] ?? [], $periodStartOn, $periodEndOn);
                $hasCompleteCoverage = $this->coversPeriod($dailySource, $periodStartOn, $periodEndOn);
                $amount = $this->sumMetric($dailyRows, $metrics['daily_key'], $hasCompleteCoverage);

                $sourceSummary[$metrics['summary_key']] = $this->decimalValue($amount);

                if ($source === 'google_analytics') {
                    $sessionCount = $this->sumMetric($dailyRows, 'sessions', $hasCompleteCoverage);
                    $sourceSummary['sessions'] = $sessionCount === null ? null : (int) $sessionCount;
                }

                $dailySource['rows'] = $this->rowsForCurrencyConversion(
                    $dailyRows,
                    $metrics['daily_key'],
                    $amount,
                    $periodStartOn,
                );
                $marketing[$source] = $sourceSummary;
                $dailyPerformance[$source] = $dailySource;

                if ($metrics['conversion_key'] !== null && $metrics['conversion_summary_key'] !== null) {
                    $conversionAmount = $this->sumMetric($dailyRows, $metrics['conversion_key'], $hasCompleteCoverage);
                    $efficiency[$metrics['conversion_summary_key']] = $this->decimalValue($conversionAmount);
                }
            }

            $efficiency = $this->periodRoas($efficiency, $marketing, $target, $fromOn, $toOn);
            $marketing['daily_performance'] = $dailyPerformance;
            $marketing['efficiency'] = $efficiency;
            $periodSummaries[$targetId] = $marketing;
        }

        return [
            'targets' => $periodTargets,
            'marketing_summaries' => $periodSummaries,
        ];
    }

    /** @param array<string, mixed> $dailySource */
    private function coversPeriod(array $dailySource, string $fromOn, string $toOn): bool
    {
        $status = $dailySource['status'] ?? null;
        $coverageStartOn = $dailySource['coverage_start_on'] ?? null;
        $coverageEndOn = $dailySource['coverage_end_on'] ?? null;

        return in_array($status, ['complete', 'partial', 'stale'], true)
            && is_string($coverageStartOn)
            && is_string($coverageEndOn)
            && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $coverageStartOn) === 1
            && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $coverageEndOn) === 1
            && $coverageStartOn <= $fromOn
            && $coverageEndOn >= $toOn;
    }

    /** @return list<array<string, mixed>> */
    private function rowsWithinPeriod(mixed $rows, string $fromOn, string $toOn): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static function (mixed $row) use ($fromOn, $toOn): bool {
            $date = is_array($row) ? ($row['date'] ?? null) : null;

            return is_string($date)
                && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1
                && $date >= $fromOn
                && $date <= $toOn;
        }));
    }

    /** @param list<array<string, mixed>> $rows */
    private function sumMetric(array $rows, string $metricKey, bool $hasCompleteCoverage): ?float
    {
        if (! $hasCompleteCoverage) {
            return null;
        }

        $total = 0.0;

        foreach ($rows as $row) {
            $value = $row[$metricKey] ?? null;

            if (! is_numeric($value) || ! is_finite((float) $value)) {
                return null;
            }

            $total += (float) $value;
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function rowsForCurrencyConversion(
        array $rows,
        string $metricKey,
        ?float $amount,
        string $periodStartOn,
    ): array {
        if ($rows === [] && $amount === 0.0) {
            return [['date' => $periodStartOn, $metricKey => '0']];
        }

        return $rows;
    }

    private function decimalValue(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 4, '.', '');
    }

    /**
     * @param  array<string, mixed>  $efficiency
     * @param  array<string, mixed>  $marketing
     * @return array<string, mixed>
     */
    private function periodRoas(
        array $efficiency,
        array $marketing,
        SocialEngagementTarget $target,
        string $fromOn,
        string $toOn,
    ): array {
        $spendSource = $efficiency['roas_spend_source'] ?? null;
        $spend = match ($spendSource) {
            'meta_marketing', 'google_ads' => is_array($marketing[$spendSource] ?? null)
                ? $marketing[$spendSource]['spend_amount'] ?? null
                : null,
            'manual' => $fromOn <= $target->starts_on?->toDateString()
                && $toOn >= $target->ends_on?->toDateString()
                    ? $efficiency['roas_spend_amount'] ?? null
                    : null,
            default => null,
        };
        $spendCurrency = match ($spendSource) {
            'meta_marketing', 'google_ads' => is_array($marketing[$spendSource] ?? null)
                ? $marketing[$spendSource]['currency_code'] ?? null
                : null,
            'manual' => $efficiency['roas_spend_currency'] ?? null,
            default => null,
        };
        $analytics = is_array($marketing['google_analytics'] ?? null) ? $marketing['google_analytics'] : [];
        $revenue = $analytics['revenue'] ?? null;
        $analyticsCurrency = $analytics['currency_code'] ?? null;
        $reason = match (true) {
            $spendSource === null => 'missing_spend',
            $spend === null || ! is_numeric($spend) || ! is_finite((float) $spend) => 'period_data_unavailable',
            (float) $spend <= 0 => 'zero_spend',
            $revenue === null || ! is_numeric($revenue) || ! is_finite((float) $revenue) => 'missing_ga4',
            ! is_string($spendCurrency) || trim($spendCurrency) === '' => 'missing_spend_currency',
            ! is_string($analyticsCurrency) || trim($analyticsCurrency) === '' => 'missing_ga4_currency',
            strtoupper(trim($spendCurrency)) !== strtoupper(trim($analyticsCurrency)) => 'currency_mismatch',
            default => null,
        };
        $roas = $reason === null ? (float) $revenue / (float) $spend : null;

        $efficiency['roas'] = $roas === null || ! is_finite($roas) ? null : round($roas, 2);
        $efficiency['roas_unrounded'] = $roas === null || ! is_finite($roas) ? null : $roas;
        $efficiency['roas_spend_amount'] = $spend === null ? null : (string) $spend;
        $efficiency['roas_spend_currency'] = is_string($spendCurrency) ? strtoupper(trim($spendCurrency)) : null;
        $efficiency['roas_unavailable_reason'] = $reason;

        return $efficiency;
    }
}
