<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;

class CampaignDailyMarketingEfficiencyReport
{
    public function __construct(private CampaignDailyMarketingTrendChart $trendChart) {}

    /**
     * @param  array<string, array<string, mixed>>  $dailyMarketingPerformance
     * @return array{
     *     status: string,
     *     status_label: string,
     *     requested_start_on: string|null,
     *     requested_end_on: string|null,
     *     coverage_start_on: string|null,
     *     coverage_end_on: string|null,
     *     latest_synced_at: CarbonImmutable|null,
     *     rows: list<array{
     *         date: string,
     *         meta_spend_amount: string|null,
     *         meta_currency_code: string|null,
     *         google_ads_spend_amount: string|null,
     *         google_ads_currency_code: string|null,
     *         ad_spend_amount: string|null,
     *         ad_spend_currency: string|null,
     *         ga4_revenue: string|null,
     *         ga4_currency_code: string|null,
     *         roas: float|null,
     *         spend_status: string,
     *         roas_status: string,
     *         roas_status_label: string
     *     }>,
     *     trend_chart: array<string, mixed>
     * }
     */
    public function build(SocialEngagementTarget $target, array $dailyMarketingPerformance): array
    {
        $startOn = $target->starts_on?->toDateString();
        $endOn = $target->ends_on?->toDateString();
        $hasCampaignPeriod = $startOn !== null && $endOn !== null;
        $expectedSources = [
            'meta_marketing' => $hasCampaignPeriod && $target->meta_campaign_id !== null,
            'google_ads' => $hasCampaignPeriod && $target->google_ads_campaign_id !== null,
        ];
        $expectedSourceCount = count(array_filter($expectedSources));
        $metaSource = $dailyMarketingPerformance['meta_marketing'] ?? [];
        $googleAdsSource = $dailyMarketingPerformance['google_ads'] ?? [];
        $googleAnalyticsSource = $dailyMarketingPerformance['google_analytics'] ?? [];
        $metaCurrency = $this->currencyCode($metaSource['currency_code'] ?? null);
        $googleAdsCurrency = $this->currencyCode($googleAdsSource['currency_code'] ?? null);
        $googleAnalyticsCurrency = $this->currencyCode($googleAnalyticsSource['currency_code'] ?? null);
        $metaHasSnapshot = (bool) ($metaSource['snapshot_available'] ?? false);
        $googleAdsHasSnapshot = (bool) ($googleAdsSource['snapshot_available'] ?? false);
        $googleAnalyticsHasSnapshot = (bool) ($googleAnalyticsSource['snapshot_available'] ?? false);
        $paidMediaCurrencyMismatch = $expectedSources['meta_marketing']
            && $expectedSources['google_ads']
            && $metaHasSnapshot
            && $googleAdsHasSnapshot
            && $metaCurrency !== null
            && $googleAdsCurrency !== null
            && $metaCurrency !== $googleAdsCurrency;
        $metaRows = $this->rowsByDate($metaSource, 'spend_amount', $startOn, $endOn);
        $googleAdsRows = $this->rowsByDate($googleAdsSource, 'spend_amount', $startOn, $endOn);
        $googleAnalyticsRows = $this->rowsByDate($googleAnalyticsSource, 'total_revenue', $startOn, $endOn);
        $dates = array_unique([
            ...array_keys($metaRows),
            ...array_keys($googleAdsRows),
            ...array_keys($googleAnalyticsRows),
        ]);
        sort($dates);
        $rows = [];

        foreach ($dates as $date) {
            $metaSpend = $expectedSources['meta_marketing'] && $metaHasSnapshot
                ? ($metaRows[$date] ?? null)
                : null;
            $googleAdsSpend = $expectedSources['google_ads'] && $googleAdsHasSnapshot
                ? ($googleAdsRows[$date] ?? null)
                : null;
            $spendStatus = $this->spendStatus(
                $expectedSources,
                $expectedSourceCount,
                $metaHasSnapshot,
                $googleAdsHasSnapshot,
                $metaSpend,
                $googleAdsSpend,
                $metaCurrency,
                $googleAdsCurrency,
                $paidMediaCurrencyMismatch,
            );
            [$adSpendAmount, $adSpendCurrency] = $this->combinedSpend(
                $expectedSources,
                $spendStatus,
                $metaSpend,
                $googleAdsSpend,
                $metaCurrency,
                $googleAdsCurrency,
            );
            $googleAnalyticsRowAvailable = $googleAnalyticsHasSnapshot && array_key_exists($date, $googleAnalyticsRows);
            $ga4Revenue = $googleAnalyticsRowAvailable ? $googleAnalyticsRows[$date] : null;
            $roasStatus = $this->roasStatus(
                $expectedSourceCount,
                $spendStatus,
                $adSpendAmount,
                $adSpendCurrency,
                $ga4Revenue,
                $googleAnalyticsCurrency,
                $googleAnalyticsRowAvailable,
            );
            $roas = $roasStatus === 'available'
                ? round((float) $ga4Revenue / (float) $adSpendAmount, 2)
                : null;

            $rows[] = [
                'date' => $date,
                'meta_spend_amount' => $metaSpend,
                'meta_currency_code' => $metaSpend === null ? null : $metaCurrency,
                'google_ads_spend_amount' => $googleAdsSpend,
                'google_ads_currency_code' => $googleAdsSpend === null ? null : $googleAdsCurrency,
                'ad_spend_amount' => $adSpendAmount,
                'ad_spend_currency' => $adSpendCurrency,
                'ga4_revenue' => $ga4Revenue,
                'ga4_currency_code' => $ga4Revenue === null ? null : $googleAnalyticsCurrency,
                'roas' => $roas,
                'spend_status' => $spendStatus,
                'roas_status' => $roasStatus,
                'roas_status_label' => $this->roasStatusLabel($roasStatus),
            ];
        }

        $requiredSources = ['google_analytics' => $googleAnalyticsSource];

        if ($expectedSources['meta_marketing']) {
            $requiredSources['meta_marketing'] = $metaSource;
        }

        if ($expectedSources['google_ads']) {
            $requiredSources['google_ads'] = $googleAdsSource;
        }

        [$coverageStartOn, $coverageEndOn] = $this->combinedCoverage($requiredSources, $startOn, $endOn);
        $hasCompleteSourceCoverage = $this->sourcesCoverPeriod($requiredSources, $startOn, $endOn);
        $hasEveryCalendarDate = $this->hasEveryCalendarDate($dates, $startOn, $endOn);
        $summaryStatus = $this->summaryStatus(
            $expectedSourceCount,
            $rows,
            $paidMediaCurrencyMismatch,
            $hasCompleteSourceCoverage,
            $hasEveryCalendarDate,
        );
        $latestSyncedAt = $this->latestSyncedAt([
            $googleAnalyticsSource['synced_at'] ?? null,
            $metaSource['synced_at'] ?? null,
            $googleAdsSource['synced_at'] ?? null,
        ]);
        $chartRows = $rows;
        $trendChart = $this->trendChart->build([
            'requested_start_on' => $startOn,
            'requested_end_on' => $endOn,
            'rows' => $chartRows,
        ], 'roas', 'ROAS', 'decimal');

        return [
            'status' => $summaryStatus,
            'status_label' => $this->summaryStatusLabel($summaryStatus),
            'requested_start_on' => $startOn,
            'requested_end_on' => $endOn,
            'coverage_start_on' => $coverageStartOn,
            'coverage_end_on' => $coverageEndOn,
            'latest_synced_at' => $latestSyncedAt,
            'rows' => array_reverse($rows),
            'trend_chart' => $trendChart,
        ];
    }

    /** @param array<string, mixed> $source
     * @return array<string, string|null>
     */
    private function rowsByDate(array $source, string $metricKey, ?string $startOn, ?string $endOn): array
    {
        $rows = is_array($source['rows'] ?? null) ? $source['rows'] : [];
        $rowsByDate = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = $this->validDate($row['date'] ?? null);

            if ($date === null
                || ($startOn !== null && $date < $startOn)
                || ($endOn !== null && $date > $endOn)
                || array_key_exists($date, $rowsByDate)) {
                continue;
            }

            $rowsByDate[$date] = $this->decimalMetric($row[$metricKey] ?? null);
        }

        return $rowsByDate;
    }

    /** @param array{meta_marketing: bool, google_ads: bool} $expectedSources */
    private function spendStatus(
        array $expectedSources,
        int $expectedSourceCount,
        bool $metaHasSnapshot,
        bool $googleAdsHasSnapshot,
        ?string $metaSpend,
        ?string $googleAdsSpend,
        ?string $metaCurrency,
        ?string $googleAdsCurrency,
        bool $paidMediaCurrencyMismatch,
    ): string {
        if ($expectedSourceCount === 0) {
            return 'not_configured';
        }

        if ($paidMediaCurrencyMismatch) {
            return 'currency_mismatch';
        }

        if (($expectedSources['meta_marketing'] && (! $metaHasSnapshot || $metaSpend === null))
            || ($expectedSources['google_ads'] && (! $googleAdsHasSnapshot || $googleAdsSpend === null))) {
            return 'missing_data';
        }

        if (($expectedSources['meta_marketing'] && $metaCurrency === null)
            || ($expectedSources['google_ads'] && $googleAdsCurrency === null)) {
            return 'missing_currency';
        }

        return 'available';
    }

    /**
     * @param  array{meta_marketing: bool, google_ads: bool}  $expectedSources
     * @return array{string|null, string|null}
     */
    private function combinedSpend(
        array $expectedSources,
        string $spendStatus,
        ?string $metaSpend,
        ?string $googleAdsSpend,
        ?string $metaCurrency,
        ?string $googleAdsCurrency,
    ): array {
        if (! in_array($spendStatus, ['available', 'missing_currency'], true)) {
            return [null, null];
        }

        $sourceAmounts = [];
        $sourceCurrencies = [];

        if ($expectedSources['meta_marketing']) {
            $sourceAmounts[] = (float) $metaSpend;
            $sourceCurrencies[] = $metaCurrency;
        }

        if ($expectedSources['google_ads']) {
            $sourceAmounts[] = (float) $googleAdsSpend;
            $sourceCurrencies[] = $googleAdsCurrency;
        }

        $currency = $sourceCurrencies[0] ?? null;

        if (count($sourceCurrencies) > 1 && in_array(null, $sourceCurrencies, true)) {
            return [null, null];
        }

        return [
            number_format(array_sum($sourceAmounts), 4, '.', ''),
            $currency,
        ];
    }

    private function roasStatus(
        int $expectedSourceCount,
        string $spendStatus,
        ?string $adSpendAmount,
        ?string $adSpendCurrency,
        ?string $ga4Revenue,
        ?string $googleAnalyticsCurrency,
        bool $googleAnalyticsRowAvailable,
    ): string {
        return match (true) {
            $expectedSourceCount === 0 => 'not_configured',
            $spendStatus === 'currency_mismatch' => 'platform_currency_mismatch',
            $spendStatus === 'missing_data' => 'missing_spend',
            $adSpendAmount === null => 'missing_spend',
            (float) $adSpendAmount <= 0 => 'zero_spend',
            ! $googleAnalyticsRowAvailable || $ga4Revenue === null => 'missing_ga4',
            $adSpendCurrency === null => 'missing_spend_currency',
            $googleAnalyticsCurrency === null => 'missing_ga4_currency',
            $adSpendCurrency !== $googleAnalyticsCurrency => 'currency_mismatch',
            default => 'available',
        };
    }

    /** @param list<array<string, mixed>> $rows */
    private function summaryStatus(
        int $expectedSourceCount,
        array $rows,
        bool $paidMediaCurrencyMismatch,
        bool $hasCompleteSourceCoverage,
        bool $hasEveryCalendarDate,
    ): string {
        return match (true) {
            $expectedSourceCount === 0 => 'not_configured',
            $paidMediaCurrencyMismatch => 'currency_mismatch',
            $rows === [] => 'missing_data',
            ! $hasCompleteSourceCoverage || ! $hasEveryCalendarDate => 'partial',
            collect($rows)->every(static fn (array $row): bool => $row['spend_status'] === 'available'
                && ($row['roas_status'] === 'available'
                    || ($row['roas_status'] === 'zero_spend'
                        && $row['ga4_revenue'] !== null
                        && $row['ga4_currency_code'] === $row['ad_spend_currency']))) => 'complete',
            default => 'partial',
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @return array{string|null, string|null}
     */
    private function combinedCoverage(array $sources, ?string $requestedStartOn, ?string $requestedEndOn): array
    {
        if ($sources === [] || $requestedStartOn === null || $requestedEndOn === null) {
            return [null, null];
        }

        $coverageStarts = [];
        $coverageEnds = [];

        foreach ($sources as $source) {
            $coverageStart = $this->validDate($source['coverage_start_on'] ?? null);
            $coverageEnd = $this->validDate($source['coverage_end_on'] ?? null);

            if ($coverageStart === null || $coverageEnd === null || $coverageStart > $coverageEnd) {
                return [null, null];
            }

            $coverageStarts[] = max($coverageStart, $requestedStartOn);
            $coverageEnds[] = min($coverageEnd, $requestedEndOn);
        }

        $coverageStartOn = max($coverageStarts);
        $coverageEndOn = min($coverageEnds);

        return $coverageStartOn <= $coverageEndOn
            ? [$coverageStartOn, $coverageEndOn]
            : [null, null];
    }

    /** @param array<string, array<string, mixed>> $sources */
    private function sourcesCoverPeriod(array $sources, ?string $startOn, ?string $endOn): bool
    {
        if ($sources === [] || $startOn === null || $endOn === null) {
            return false;
        }

        foreach ($sources as $source) {
            $coverageStartOn = $this->validDate($source['coverage_start_on'] ?? null);
            $coverageEndOn = $this->validDate($source['coverage_end_on'] ?? null);

            if (($source['status'] ?? null) !== 'complete'
                || $coverageStartOn === null
                || $coverageEndOn === null
                || $coverageStartOn > $startOn
                || $coverageEndOn < $endOn) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $dates */
    private function hasEveryCalendarDate(array $dates, ?string $startOn, ?string $endOn): bool
    {
        if ($startOn === null || $endOn === null || $startOn > $endOn) {
            return false;
        }

        $availableDates = array_fill_keys($dates, true);
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $startOn, 'UTC');
        $lastDate = CarbonImmutable::createFromFormat('!Y-m-d', $endOn, 'UTC');

        if ($date === false || $lastDate === false) {
            return false;
        }

        while ($date <= $lastDate) {
            if (! isset($availableDates[$date->toDateString()])) {
                return false;
            }

            $date = $date->addDay();
        }

        return true;
    }

    private function summaryStatusLabel(string $status): string
    {
        return match ($status) {
            'complete' => '可比較',
            'partial' => '部分資料',
            'currency_mismatch' => '廣告平台幣別不同',
            'not_configured' => '尚未設定廣告活動',
            default => '尚無逐日資料',
        };
    }

    private function roasStatusLabel(string $status): string
    {
        return match ($status) {
            'available' => '可計算',
            'zero_spend' => '花費為 0',
            'not_configured' => '尚未設定廣告活動',
            'platform_currency_mismatch' => '廣告平台幣別不同',
            'currency_mismatch' => 'GA4 與花費幣別不同',
            'missing_ga4' => '缺少 GA4 逐日營收',
            'missing_spend_currency' => '缺少花費幣別',
            'missing_ga4_currency' => '缺少 GA4 營收幣別',
            default => '缺少完整廣告花費',
        };
    }

    /** @param list<mixed> $syncedAtValues */
    private function latestSyncedAt(array $syncedAtValues): ?CarbonImmutable
    {
        $syncedAtValues = array_values(array_filter(
            $syncedAtValues,
            static fn (mixed $syncedAt): bool => $syncedAt instanceof CarbonImmutable,
        ));

        if ($syncedAtValues === []) {
            return null;
        }

        usort($syncedAtValues, static fn (CarbonImmutable $first, CarbonImmutable $second): int => $second <=> $first);

        return $syncedAtValues[0];
    }

    private function currencyCode(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z]{3}$/', trim($value)) !== 1) {
            return null;
        }

        return strtoupper(trim($value));
    }

    private function decimalMetric(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            return null;
        }

        return (string) $value;
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
