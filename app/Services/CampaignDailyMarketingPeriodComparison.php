<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;

class CampaignDailyMarketingPeriodComparison
{
    private const PERIOD_DAYS = 7;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     status: string,
     *     status_label: string,
     *     period_days: int,
     *     current_period: array<string, mixed>,
     *     previous_period: array<string, mixed>,
     *     changes: array{spend_amount: string|null, spend_percent: float|null, revenue_amount: string|null, revenue_percent: float|null, roas: float|null}
     * }
     */
    public function build(SocialEngagementTarget $target, array $rows): array
    {
        $campaignStartOn = $target->starts_on?->toDateString();
        $campaignEndOn = $target->ends_on?->toDateString();
        $emptyPeriod = $this->emptyPeriod(null, null, 'missing_data');

        if ($campaignStartOn === null || $campaignEndOn === null || $campaignStartOn > $campaignEndOn) {
            return $this->comparison('missing_period', $emptyPeriod, $emptyPeriod);
        }

        $metaIsConfigured = is_string($target->meta_campaign_id)
            && trim($target->meta_campaign_id) !== '';
        $googleAdsIsConfigured = is_string($target->google_ads_campaign_id)
            && trim($target->google_ads_campaign_id) !== '';

        if (! $metaIsConfigured && ! $googleAdsIsConfigured) {
            return $this->comparison('not_configured', $emptyPeriod, $emptyPeriod);
        }

        $rowsByDate = [];

        foreach ($rows as $row) {
            $date = $this->validDate($row['date'] ?? null);

            if ($date !== null && $date >= $campaignStartOn && $date <= $campaignEndOn) {
                $rowsByDate[$date] = $row;
            }
        }

        if ($rowsByDate === []) {
            return $this->comparison('missing_data', $emptyPeriod, $emptyPeriod);
        }

        $latestCompletedDate = CarbonImmutable::now('Asia/Taipei')->subDay()->toDateString();
        $comparisonEndOn = min($latestCompletedDate, $campaignEndOn);

        if ($comparisonEndOn < $campaignStartOn) {
            return $this->comparison('missing_data', $emptyPeriod, $emptyPeriod);
        }

        $currentEnd = CarbonImmutable::createFromFormat('!Y-m-d', $comparisonEndOn, 'UTC');

        if ($currentEnd === false) {
            return $this->comparison('missing_data', $emptyPeriod, $emptyPeriod);
        }

        $currentStartOn = $currentEnd->subDays(self::PERIOD_DAYS - 1)->toDateString();
        $previousEndOn = $currentEnd->subDays(self::PERIOD_DAYS)->toDateString();
        $previousStartOn = $currentEnd->subDays((self::PERIOD_DAYS * 2) - 1)->toDateString();

        if ($previousStartOn < $campaignStartOn) {
            $currentPeriod = $this->emptyPeriod($currentStartOn, $comparisonEndOn, 'insufficient_period');
            $previousPeriod = $this->emptyPeriod($previousStartOn, $previousEndOn, 'insufficient_period');

            return $this->comparison('insufficient_period', $currentPeriod, $previousPeriod);
        }

        $currentPeriod = $this->aggregatePeriod($rowsByDate, $currentStartOn, $comparisonEndOn);
        $previousPeriod = $this->aggregatePeriod($rowsByDate, $previousStartOn, $previousEndOn);

        if ($currentPeriod['status'] === 'currency_mismatch'
            || $previousPeriod['status'] === 'currency_mismatch'
            || ($currentPeriod['currency'] !== null
                && $previousPeriod['currency'] !== null
                && $currentPeriod['currency'] !== $previousPeriod['currency'])) {
            $status = 'currency_mismatch';
        } elseif ($currentPeriod['status'] !== 'complete' || $previousPeriod['status'] !== 'complete') {
            $status = 'partial';
        } else {
            $status = 'complete';
        }

        return $this->comparison($status, $currentPeriod, $previousPeriod);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByDate
     * @return array{start_on: string|null, end_on: string|null, spend_amount: string|null, revenue_amount: string|null, currency: string|null, roas: float|null, status: string}
     */
    private function aggregatePeriod(array $rowsByDate, string $startOn, string $endOn): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $startOn, 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endOn, 'UTC');

        if ($start === false || $end === false) {
            return $this->emptyPeriod($startOn, $endOn, 'partial');
        }

        $spendTotal = 0.0;
        $revenueTotal = 0.0;
        $currency = null;

        while ($start <= $end) {
            $row = $rowsByDate[$start->toDateString()] ?? null;

            if (! is_array($row)) {
                return $this->emptyPeriod($startOn, $endOn, 'partial');
            }

            if (($row['roas_status'] ?? null) === 'currency_mismatch') {
                return $this->emptyPeriod($startOn, $endOn, 'currency_mismatch');
            }

            $spend = $this->nonNegativeMetric($row['roas_spend_amount'] ?? null);
            $revenue = $this->nonNegativeMetric($row['ga4_revenue'] ?? null);
            $spendCurrency = $this->currencyCode($row['roas_spend_currency'] ?? null);
            $revenueCurrency = $this->currencyCode($row['ga4_currency_code'] ?? null);

            if (! in_array($row['roas_status'] ?? null, ['available', 'zero_spend'], true)
                || $spend === null
                || $revenue === null
                || $spendCurrency === null
                || $revenueCurrency === null) {
                return $this->emptyPeriod($startOn, $endOn, 'partial');
            }

            if ($spendCurrency !== $revenueCurrency || ($currency !== null && $currency !== $spendCurrency)) {
                return $this->emptyPeriod($startOn, $endOn, 'currency_mismatch');
            }

            $currency = $spendCurrency;
            $spendTotal += $spend;
            $revenueTotal += $revenue;
            $start = $start->addDay();
        }

        return [
            'start_on' => $startOn,
            'end_on' => $endOn,
            'spend_amount' => number_format($spendTotal, 4, '.', ''),
            'revenue_amount' => number_format($revenueTotal, 4, '.', ''),
            'currency' => $currency,
            'roas' => $spendTotal > 0 ? round($revenueTotal / $spendTotal, 2) : null,
            'status' => 'complete',
        ];
    }

    /**
     * @param  array<string, mixed>  $currentPeriod
     * @param  array<string, mixed>  $previousPeriod
     * @return array{status: string, status_label: string, period_days: int, current_period: array<string, mixed>, previous_period: array<string, mixed>, changes: array{spend_amount: string|null, spend_percent: float|null, revenue_amount: string|null, revenue_percent: float|null, roas: float|null}}
     */
    private function comparison(string $status, array $currentPeriod, array $previousPeriod): array
    {
        $canCompare = $status === 'complete';
        $currentSpend = $canCompare ? (float) $currentPeriod['spend_amount'] : null;
        $previousSpend = $canCompare ? (float) $previousPeriod['spend_amount'] : null;
        $currentRevenue = $canCompare ? (float) $currentPeriod['revenue_amount'] : null;
        $previousRevenue = $canCompare ? (float) $previousPeriod['revenue_amount'] : null;
        $currentRoas = $canCompare ? $currentPeriod['roas'] : null;
        $previousRoas = $canCompare ? $previousPeriod['roas'] : null;

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'complete' => '可比較',
                'partial' => '資料不完整',
                'currency_mismatch' => '幣別不同，無法比較',
                'insufficient_period' => '尚無完整 14 天資料',
                'missing_period' => '未設定活動期間',
                'not_configured' => '尚未設定廣告活動',
                default => '尚無逐日資料',
            },
            'period_days' => self::PERIOD_DAYS,
            'current_period' => $currentPeriod,
            'previous_period' => $previousPeriod,
            'changes' => [
                'spend_amount' => $canCompare && $currentSpend !== null && $previousSpend !== null
                    ? number_format($currentSpend - $previousSpend, 4, '.', '')
                    : null,
                'spend_percent' => $canCompare && $currentSpend !== null && $previousSpend !== null && $previousSpend > 0
                    ? round(($currentSpend - $previousSpend) / $previousSpend * 100, 2)
                    : null,
                'revenue_amount' => $canCompare && $currentRevenue !== null && $previousRevenue !== null
                    ? number_format($currentRevenue - $previousRevenue, 4, '.', '')
                    : null,
                'revenue_percent' => $canCompare && $currentRevenue !== null && $previousRevenue !== null && $previousRevenue > 0
                    ? round(($currentRevenue - $previousRevenue) / $previousRevenue * 100, 2)
                    : null,
                'roas' => $canCompare && $currentRoas !== null && $previousRoas !== null
                    ? round($currentRoas - $previousRoas, 2)
                    : null,
            ],
        ];
    }

    /** @return array{start_on: string|null, end_on: string|null, spend_amount: null, revenue_amount: null, currency: null, roas: null, status: string} */
    private function emptyPeriod(?string $startOn, ?string $endOn, string $status): array
    {
        return [
            'start_on' => $startOn,
            'end_on' => $endOn,
            'spend_amount' => null,
            'revenue_amount' => null,
            'currency' => null,
            'roas' => null,
            'status' => $status,
        ];
    }

    private function nonNegativeMetric(mixed $value): ?float
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value))
            || ! is_numeric($value)
            || ! is_finite((float) $value)
            || (float) $value < 0) {
            return null;
        }

        return (float) $value;
    }

    private function currencyCode(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z]{3}$/', trim($value)) !== 1) {
            return null;
        }

        return strtoupper(trim($value));
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
