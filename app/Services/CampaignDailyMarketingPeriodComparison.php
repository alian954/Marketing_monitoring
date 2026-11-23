<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;

class CampaignDailyMarketingPeriodComparison
{
    private const PERIOD_DAYS = 7;

    /**
     * @param  array<string, mixed>  $source
     * @param  'meta_marketing'|'google_ads'  $sourceKey
     * @return array<string, mixed>
     */
    public function buildPaidMedia(
        SocialEngagementTarget $target,
        array $source,
        string $sourceKey,
        CarbonImmutable $asOf,
    ): array {
        $isMeta = $sourceKey === 'meta_marketing';
        $sourceLabel = $isMeta ? 'Meta Ads' : 'Google Ads';
        $resultLabel = $isMeta ? '購買' : '轉換';
        $campaignId = $isMeta ? $target->meta_campaign_id : $target->google_ads_campaign_id;
        $currencyCode = $this->currencyCode($source['currency_code'] ?? null);
        $window = $this->periodWindow($target, $asOf);
        $emptyCurrentPeriod = $this->emptyPaidMediaPeriod(
            $window['current_start_on'],
            $window['current_end_on'],
            'missing_data',
            $currencyCode,
        );
        $emptyPreviousPeriod = $this->emptyPaidMediaPeriod(
            $window['previous_start_on'],
            $window['previous_end_on'],
            'missing_data',
            $currencyCode,
        );

        if (! is_string($campaignId) || trim($campaignId) === '') {
            return $this->paidMediaComparison($sourceKey, $sourceLabel, $resultLabel, 'not_configured', $emptyCurrentPeriod, $emptyPreviousPeriod);
        }

        if ($window['status'] !== null) {
            return $this->paidMediaComparison(
                $sourceKey,
                $sourceLabel,
                $resultLabel,
                $window['status'],
                $this->emptyPaidMediaPeriod($window['current_start_on'], $window['current_end_on'], $window['status'], $currencyCode),
                $this->emptyPaidMediaPeriod($window['previous_start_on'], $window['previous_end_on'], $window['status'], $currencyCode),
            );
        }

        if (($source['status'] ?? null) !== 'complete') {
            $status = match ($source['status'] ?? null) {
                'partial' => 'partial',
                'stale' => 'stale',
                default => 'source_unavailable',
            };

            return $this->paidMediaComparison(
                $sourceKey,
                $sourceLabel,
                $resultLabel,
                $status,
                $this->emptyPaidMediaPeriod($window['current_start_on'], $window['current_end_on'], $status, $currencyCode),
                $this->emptyPaidMediaPeriod($window['previous_start_on'], $window['previous_end_on'], $status, $currencyCode),
            );
        }

        $syncedAt = $source['synced_at'] ?? null;

        if (! $syncedAt instanceof CarbonImmutable || $syncedAt->lessThanOrEqualTo($asOf->subDay())) {
            $status = $syncedAt instanceof CarbonImmutable ? 'stale' : 'missing_data';

            return $this->paidMediaComparison(
                $sourceKey,
                $sourceLabel,
                $resultLabel,
                $status,
                $this->emptyPaidMediaPeriod($window['current_start_on'], $window['current_end_on'], $status, $currencyCode),
                $this->emptyPaidMediaPeriod($window['previous_start_on'], $window['previous_end_on'], $status, $currencyCode),
            );
        }

        if ($currencyCode === null) {
            return $this->paidMediaComparison(
                $sourceKey,
                $sourceLabel,
                $resultLabel,
                'currency_unknown',
                $this->emptyPaidMediaPeriod($window['current_start_on'], $window['current_end_on'], 'currency_unknown', null),
                $this->emptyPaidMediaPeriod($window['previous_start_on'], $window['previous_end_on'], 'currency_unknown', null),
            );
        }

        $rowsByDate = [];
        $hasDuplicateComparisonDate = false;

        foreach (is_array($source['rows'] ?? null) ? $source['rows'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = $this->validDate($row['date'] ?? null);

            if ($date === null) {
                continue;
            }

            if (array_key_exists($date, $rowsByDate)) {
                $hasDuplicateComparisonDate = $hasDuplicateComparisonDate
                    || ($date >= $window['previous_start_on'] && $date <= $window['current_end_on']);

                continue;
            }

            $rowsByDate[$date] = $row;
        }

        if ($hasDuplicateComparisonDate) {
            return $this->paidMediaComparison(
                $sourceKey,
                $sourceLabel,
                $resultLabel,
                'partial',
                $this->emptyPaidMediaPeriod($window['current_start_on'], $window['current_end_on'], 'partial', $currencyCode),
                $this->emptyPaidMediaPeriod($window['previous_start_on'], $window['previous_end_on'], 'partial', $currencyCode),
            );
        }

        $currentPeriod = $this->aggregatePaidMediaPeriod(
            $rowsByDate,
            $window['current_start_on'],
            $window['current_end_on'],
            $sourceKey,
            $currencyCode,
        );
        $previousPeriod = $this->aggregatePaidMediaPeriod(
            $rowsByDate,
            $window['previous_start_on'],
            $window['previous_end_on'],
            $sourceKey,
            $currencyCode,
        );
        $status = $currentPeriod['status'] === 'complete' && $previousPeriod['status'] === 'complete'
            ? 'complete'
            : 'partial';

        return $this->paidMediaComparison($sourceKey, $sourceLabel, $resultLabel, $status, $currentPeriod, $previousPeriod);
    }

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
        } elseif ($currentPeriod['status'] === 'stale' || $previousPeriod['status'] === 'stale') {
            $status = 'stale';
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

            if (($row['roas_status'] ?? null) === 'stale') {
                return $this->emptyPeriod($startOn, $endOn, 'stale');
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
                'stale' => '資料超過 24 小時',
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

    /**
     * @return array{status: string|null, current_start_on: string|null, current_end_on: string|null, previous_start_on: string|null, previous_end_on: string|null}
     */
    private function periodWindow(SocialEngagementTarget $target, CarbonImmutable $asOf): array
    {
        $campaignStartOn = $target->starts_on?->toDateString();
        $campaignEndOn = $target->ends_on?->toDateString();

        if ($campaignStartOn === null || $campaignEndOn === null || $campaignStartOn > $campaignEndOn) {
            return ['status' => 'missing_period', 'current_start_on' => null, 'current_end_on' => null, 'previous_start_on' => null, 'previous_end_on' => null];
        }

        $comparisonEndOn = min($asOf->timezone('Asia/Taipei')->subDay()->toDateString(), $campaignEndOn);

        if ($comparisonEndOn < $campaignStartOn) {
            return ['status' => 'missing_data', 'current_start_on' => null, 'current_end_on' => null, 'previous_start_on' => null, 'previous_end_on' => null];
        }

        $currentEnd = CarbonImmutable::createFromFormat('!Y-m-d', $comparisonEndOn, 'UTC');

        if ($currentEnd === false) {
            return ['status' => 'missing_data', 'current_start_on' => null, 'current_end_on' => null, 'previous_start_on' => null, 'previous_end_on' => null];
        }

        $currentStartOn = $currentEnd->subDays(self::PERIOD_DAYS - 1)->toDateString();
        $previousEndOn = $currentEnd->subDays(self::PERIOD_DAYS)->toDateString();
        $previousStartOn = $currentEnd->subDays((self::PERIOD_DAYS * 2) - 1)->toDateString();

        return [
            'status' => $previousStartOn < $campaignStartOn ? 'insufficient_period' : null,
            'current_start_on' => $currentStartOn,
            'current_end_on' => $comparisonEndOn,
            'previous_start_on' => $previousStartOn,
            'previous_end_on' => $previousEndOn,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByDate
     * @return array<string, mixed>
     */
    private function aggregatePaidMediaPeriod(
        array $rowsByDate,
        ?string $startOn,
        ?string $endOn,
        string $sourceKey,
        string $currencyCode,
    ): array {
        if ($startOn === null || $endOn === null) {
            return $this->emptyPaidMediaPeriod($startOn, $endOn, 'partial', $currencyCode);
        }

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $startOn, 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endOn, 'UTC');

        if ($start === false || $end === false) {
            return $this->emptyPaidMediaPeriod($startOn, $endOn, 'partial', $currencyCode);
        }

        $spendTotal = 0.0;
        $impressionTotal = 0;
        $clickTotal = 0;
        $resultCountTotal = 0.0;
        $resultValueTotal = 0.0;
        $resultCountComplete = true;
        $resultValueComplete = true;

        while ($start <= $end) {
            $row = $rowsByDate[$start->toDateString()] ?? null;
            $spend = is_array($row) ? $this->nonNegativeMetric($row['spend_amount'] ?? null) : null;
            $impressions = is_array($row) ? $this->nonNegativeMetric($row['impressions'] ?? null) : null;
            $clicks = is_array($row) ? $this->nonNegativeMetric($row['clicks'] ?? null) : null;

            if (! is_array($row) || $spend === null || $impressions === null || $clicks === null || ! is_finite($spend + $impressions + $clicks)) {
                return $this->emptyPaidMediaPeriod($startOn, $endOn, 'partial', $currencyCode);
            }

            $resultCountKey = $sourceKey === 'meta_marketing' ? 'omni_purchase_count' : 'conversions';
            $resultValueKey = $sourceKey === 'meta_marketing' ? 'omni_purchase_value' : 'conversions_value';
            $resultCount = $this->nonNegativeMetric($row[$resultCountKey] ?? null);
            $resultValue = $this->nonNegativeMetric($row[$resultValueKey] ?? null);

            if ($resultCount === null || ! is_finite($resultCountTotal + $resultCount)) {
                $resultCountComplete = false;
            } else {
                $resultCountTotal += $resultCount;
            }

            if ($resultValue === null || ! is_finite($resultValueTotal + $resultValue)) {
                $resultValueComplete = false;
            } else {
                $resultValueTotal += $resultValue;
            }

            $spendTotal += $spend;
            $impressionTotal += (int) $impressions;
            $clickTotal += (int) $clicks;
            $start = $start->addDay();
        }

        if (! is_finite($spendTotal)) {
            return $this->emptyPaidMediaPeriod($startOn, $endOn, 'partial', $currencyCode);
        }

        $resultCount = $resultCountComplete ? $resultCountTotal : null;
        $resultValue = $resultValueComplete ? $resultValueTotal : null;

        return [
            'start_on' => $startOn,
            'end_on' => $endOn,
            'spend_amount' => number_format($spendTotal, 4, '.', ''),
            'impressions' => $impressionTotal,
            'clicks' => $clickTotal,
            'result_count' => $resultCount,
            'result_value' => $resultValue === null ? null : number_format($resultValue, 4, '.', ''),
            'currency_code' => $currencyCode,
            'ctr_percent' => $impressionTotal > 0 ? round($clickTotal / $impressionTotal * 100, 4) : null,
            'cost_per_click' => $clickTotal > 0 ? round($spendTotal / $clickTotal, 4) : null,
            'cost_per_result' => $resultCount !== null && $resultCount > 0 ? round($spendTotal / $resultCount, 4) : null,
            'roas' => $resultValue !== null && $spendTotal > 0 ? round($resultValue / $spendTotal, 4) : null,
            'status' => 'complete',
        ];
    }

    /**
     * @param  array<string, mixed>  $currentPeriod
     * @param  array<string, mixed>  $previousPeriod
     * @return array<string, mixed>
     */
    private function paidMediaComparison(
        string $sourceKey,
        string $sourceLabel,
        string $resultLabel,
        string $status,
        array $currentPeriod,
        array $previousPeriod,
    ): array {
        $canCompare = $status === 'complete';
        $currentSpend = $canCompare ? (float) $currentPeriod['spend_amount'] : null;
        $previousSpend = $canCompare ? (float) $previousPeriod['spend_amount'] : null;

        return [
            'source' => $sourceKey,
            'source_label' => $sourceLabel,
            'result_label' => $resultLabel,
            'status' => $status,
            'status_label' => match ($status) {
                'complete' => '可比較',
                'partial' => '逐日資料不完整',
                'stale' => '資料超過 24 小時',
                'currency_unknown' => '幣別未知',
                'insufficient_period' => '尚無完整 14 天資料',
                'missing_period' => '未設定活動期間',
                'not_configured' => '尚未設定廣告活動',
                'source_unavailable' => '尚無完整逐日資料',
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
                'ctr_percentage_points' => $canCompare && $currentPeriod['ctr_percent'] !== null && $previousPeriod['ctr_percent'] !== null
                    ? round($currentPeriod['ctr_percent'] - $previousPeriod['ctr_percent'], 4)
                    : null,
                'cost_per_click_amount' => $canCompare && $currentPeriod['cost_per_click'] !== null && $previousPeriod['cost_per_click'] !== null
                    ? number_format($currentPeriod['cost_per_click'] - $previousPeriod['cost_per_click'], 4, '.', '')
                    : null,
                'cost_per_click_percent' => $canCompare && $currentPeriod['cost_per_click'] !== null && $previousPeriod['cost_per_click'] !== null && $previousPeriod['cost_per_click'] > 0
                    ? round(($currentPeriod['cost_per_click'] - $previousPeriod['cost_per_click']) / $previousPeriod['cost_per_click'] * 100, 2)
                    : null,
                'result_count' => $canCompare && $currentPeriod['result_count'] !== null && $previousPeriod['result_count'] !== null
                    ? round($currentPeriod['result_count'] - $previousPeriod['result_count'], 4)
                    : null,
                'cost_per_result_amount' => $canCompare && $currentPeriod['cost_per_result'] !== null && $previousPeriod['cost_per_result'] !== null
                    ? number_format($currentPeriod['cost_per_result'] - $previousPeriod['cost_per_result'], 4, '.', '')
                    : null,
                'cost_per_result_percent' => $canCompare && $currentPeriod['cost_per_result'] !== null && $previousPeriod['cost_per_result'] !== null && $previousPeriod['cost_per_result'] > 0
                    ? round(($currentPeriod['cost_per_result'] - $previousPeriod['cost_per_result']) / $previousPeriod['cost_per_result'] * 100, 2)
                    : null,
                'roas' => $canCompare && $currentPeriod['roas'] !== null && $previousPeriod['roas'] !== null
                    ? round($currentPeriod['roas'] - $previousPeriod['roas'], 4)
                    : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyPaidMediaPeriod(?string $startOn, ?string $endOn, string $status, ?string $currencyCode): array
    {
        return [
            'start_on' => $startOn,
            'end_on' => $endOn,
            'spend_amount' => null,
            'impressions' => null,
            'clicks' => null,
            'result_count' => null,
            'result_value' => null,
            'currency_code' => $currencyCode,
            'ctr_percent' => null,
            'cost_per_click' => null,
            'cost_per_result' => null,
            'roas' => null,
            'status' => $status,
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
