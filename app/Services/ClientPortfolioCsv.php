<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Collection;

class ClientPortfolioCsv
{
    /**
     * @param  Collection<int, array{
     *     client: Client,
     *     campaign_count: int,
     *     money_groups: list<array<string, mixed>>,
     *     roas_groups: list<array<string, mixed>>,
     *     uses_reporting_currency: bool,
     *     coverage: array<string, array{configured_campaigns: int, fresh_campaigns: int, missing_campaigns: int}>,
     *     comparison: array{money: array<string, array<string, array{delta: float|null, percent_change: float|null, status: string}>>, roas: array<string, array{source_label: string, currency_code: string, delta: float|null, percent_change: float|null, status: string}>}|null
     * }>  $clientPortfolioRows
     * @param  resource  $stream
     * @param  array{from: string, to: string}|null  $comparisonPeriod
     */
    public function write(
        Collection $clientPortfolioRows,
        mixed $stream,
        string $fromOn,
        string $toOn,
        ?array $comparisonPeriod = null,
    ): void {
        fwrite($stream, "\xEF\xBB\xBF");
        $headers = [
            '客戶',
            '報表期間',
            '活動數',
            '報表幣別',
            'GA4 營收',
            'Meta Ads 花費',
            'Google Ads 花費',
            '平台 ROAS',
            '資料更新',
            '資料缺漏',
        ];

        if ($comparisonPeriod !== null) {
            $headers = [
                ...$headers,
                '比較期間',
                'GA4 營收變化',
                'Meta Ads 花費變化',
                'Google Ads 花費變化',
                '平台 ROAS 變化',
            ];
        }

        fputcsv($stream, $headers, ',', '"', '');

        foreach ($clientPortfolioRows as $row) {
            $currencyLabel = $row['uses_reporting_currency']
                ? $row['client']->reporting_currency_code
                : ($row['money_groups'] === [] ? '尚未設定報表幣別' : '原始幣別');
            $fields = [
                $row['client']->name,
                $fromOn.' 至 '.$toOn,
                $row['campaign_count'],
                $currencyLabel,
                $this->formatMoneyGroups($row['money_groups'], 'ga4_revenue', 'ga4_campaign_count'),
                $this->formatMoneyGroups($row['money_groups'], 'meta_spend', 'meta_campaign_count'),
                $this->formatMoneyGroups($row['money_groups'], 'google_ads_spend', 'google_ads_campaign_count'),
                $this->formatRoasGroups($row['roas_groups']),
                $this->formatFreshness($row['coverage']),
                $this->formatConversionGaps($row['money_groups'], $row['coverage']),
            ];

            if ($comparisonPeriod !== null) {
                $comparison = is_array($row['comparison'] ?? null) ? $row['comparison'] : ['money' => [], 'roas' => []];
                $fields = [
                    ...$fields,
                    $comparisonPeriod['from'].' 至 '.$comparisonPeriod['to'],
                    $this->formatMoneyChanges($row['money_groups'], 'ga4_campaign_count', $comparison['money']['ga4_revenue'] ?? []),
                    $this->formatMoneyChanges($row['money_groups'], 'meta_campaign_count', $comparison['money']['meta_spend'] ?? []),
                    $this->formatMoneyChanges($row['money_groups'], 'google_ads_campaign_count', $comparison['money']['google_ads_spend'] ?? []),
                    $this->formatRoasChanges($row['roas_groups'], $comparison['roas'] ?? []),
                ];
            }

            fputcsv(
                $stream,
                array_map(
                    static fn (string|int|float|null $value): string => CsvFormulaSanitizer::escape((string) ($value ?? '')),
                    $fields,
                ),
                ',',
                '"',
                '',
            );
        }
    }

    /** @param list<array<string, mixed>> $moneyGroups
     * @param  array<string, array{delta: float|null, percent_change: float|null, status: string}>  $changes
     */
    private function formatMoneyChanges(array $moneyGroups, string $countKey, array $changes): string
    {
        $metricGroups = collect($moneyGroups)
            ->filter(static fn (array $group): bool => ($group[$countKey] ?? 0) > 0);

        if ($metricGroups->isEmpty()) {
            return '比較資料不足';
        }

        return $metricGroups
            ->map(function (array $group) use ($changes): string {
                $currencyCode = $group['currency_code'];
                $change = $changes[$currencyCode] ?? null;

                if (! is_array($change) || $change['status'] === 'insufficient') {
                    return '比較資料不足（'.$currencyCode.'）';
                }

                $delta = (float) $change['delta'];
                $formattedDelta = ($delta > 0 ? '+' : '').number_format($delta, 2, '.', '').' '.$currencyCode;

                if ($change['status'] === 'zero_baseline') {
                    return $formattedDelta.'（前期為 0）';
                }

                $percentChange = (float) $change['percent_change'];

                return $formattedDelta.'（'.($percentChange > 0 ? '+' : '').number_format($percentChange, 2, '.', '').'%）';
            })
            ->implode('；');
    }

    /**
     * @param  list<array<string, mixed>>  $roasGroups
     * @param  array<string, array{source_label: string, currency_code: string, delta: float|null, percent_change: float|null, status: string}>  $changes
     */
    private function formatRoasChanges(array $roasGroups, array $changes): string
    {
        $availableGroups = collect($roasGroups)->filter(static fn (array $group): bool => $group['roas'] !== null);

        if ($availableGroups->isEmpty()) {
            return '比較資料不足';
        }

        return $availableGroups
            ->map(function (array $group) use ($changes): string {
                $key = $group['source'].'|'.$group['currency_code'];
                $change = $changes[$key] ?? null;

                if (! is_array($change) || $change['status'] === 'insufficient') {
                    return $group['source_label'].' 比較資料不足（'.$group['currency_code'].'）';
                }

                $delta = (float) $change['delta'];
                $formattedDelta = ($delta > 0 ? '+' : '').number_format($delta, 2, '.', '').'×';

                if ($change['status'] === 'zero_baseline') {
                    return $group['source_label'].' '.$formattedDelta.'（前期為 0，'.$group['currency_code'].'）';
                }

                $percentChange = (float) $change['percent_change'];

                return $group['source_label'].' '.$formattedDelta.'（'.($percentChange > 0 ? '+' : '').number_format($percentChange, 2, '.', '').'%，'.$group['currency_code'].'）';
            })
            ->implode('；');
    }

    /** @param list<array<string, mixed>> $moneyGroups */
    private function formatMoneyGroups(array $moneyGroups, string $amountKey, string $countKey): string
    {
        return collect($moneyGroups)
            ->filter(static fn (array $group): bool => ($group[$countKey] ?? 0) > 0)
            ->map(static fn (array $group): string => number_format((float) $group[$amountKey], 2, '.', '').' '.$group['currency_code'])
            ->implode('；');
    }

    /** @param list<array<string, mixed>> $roasGroups */
    private function formatRoasGroups(array $roasGroups): string
    {
        return collect($roasGroups)
            ->filter(static fn (array $group): bool => $group['roas'] !== null)
            ->map(static fn (array $group): string => $group['source_label'].' '.number_format((float) $group['roas'], 2, '.', '').'× ('.$group['currency_code'].')')
            ->implode('；');
    }

    /** @param array<string, array{configured_campaigns: int, fresh_campaigns: int}> $coverage */
    private function formatFreshness(array $coverage): string
    {
        $sourceLabels = [
            'google_analytics' => 'GA4',
            'meta_marketing' => 'Meta',
            'google_ads' => 'Google Ads',
        ];

        return collect($coverage)
            ->filter(static fn (array $sourceCoverage): bool => $sourceCoverage['configured_campaigns'] > 0)
            ->map(static fn (array $sourceCoverage, string $source): string => ($sourceLabels[$source] ?? $source).' 最新 '.$sourceCoverage['fresh_campaigns'].'/'.$sourceCoverage['configured_campaigns'])
            ->implode('；');
    }

    /**
     * @param  list<array<string, mixed>>  $moneyGroups
     * @param  array<string, array{configured_campaigns: int, missing_campaigns: int}>  $coverage
     */
    private function formatConversionGaps(array $moneyGroups, array $coverage): string
    {
        $sourceLabels = [
            'google_analytics' => 'GA4',
            'meta_marketing' => 'Meta',
            'google_ads' => 'Google Ads',
        ];
        $gaps = [];

        foreach ($coverage as $source => $sourceCoverage) {
            if ($sourceCoverage['configured_campaigns'] > 0 && $sourceCoverage['missing_campaigns'] > 0) {
                $sourceLabel = $sourceLabels[$source] ?? $source;
                $gaps[] = $sourceLabel.' 期間資料不足 '.$sourceCoverage['missing_campaigns'];
            }
        }

        foreach ($moneyGroups as $group) {
            foreach ($group['conversion_coverage'] ?? [] as $source => $conversionCoverage) {
                $description = $sourceLabels[$source] ?? $source;

                if ($conversionCoverage['missing_rate_campaigns'] > 0) {
                    $gaps[] = $description.' 缺匯率 '.$conversionCoverage['missing_rate_campaigns'];
                }

                if ($conversionCoverage['missing_daily_data_campaigns'] > 0) {
                    $gaps[] = $description.' 缺逐日資料 '.$conversionCoverage['missing_daily_data_campaigns'];
                }
            }
        }

        return implode('；', array_unique($gaps));
    }
}
