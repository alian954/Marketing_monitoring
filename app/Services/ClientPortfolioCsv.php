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
     *     coverage: array<string, array{configured_campaigns: int, fresh_campaigns: int}>
     * }>  $clientPortfolioRows
     * @param  resource  $stream
     */
    public function write(Collection $clientPortfolioRows, mixed $stream): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        $headers = [
            '客戶',
            '活動數',
            '報表幣別',
            'GA4 營收',
            'Meta Ads 花費',
            'Google Ads 花費',
            '平台 ROAS',
            '資料更新',
            '資料缺漏',
        ];
        fputcsv($stream, $headers, ',', '"', '');

        foreach ($clientPortfolioRows as $row) {
            $currencyLabel = $row['uses_reporting_currency']
                ? $row['client']->reporting_currency_code
                : ($row['money_groups'] === [] ? '尚未設定報表幣別' : '原始幣別');
            $fields = [
                $row['client']->name,
                $row['campaign_count'],
                $currencyLabel,
                $this->formatMoneyGroups($row['money_groups'], 'ga4_revenue', 'ga4_campaign_count'),
                $this->formatMoneyGroups($row['money_groups'], 'meta_spend', 'meta_campaign_count'),
                $this->formatMoneyGroups($row['money_groups'], 'google_ads_spend', 'google_ads_campaign_count'),
                $this->formatRoasGroups($row['roas_groups']),
                $this->formatFreshness($row['coverage']),
                $this->formatConversionGaps($row['money_groups']),
            ];

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

    /** @param list<array<string, mixed>> $moneyGroups */
    private function formatConversionGaps(array $moneyGroups): string
    {
        $sourceLabels = [
            'google_analytics' => 'GA4',
            'meta_marketing' => 'Meta',
            'google_ads' => 'Google Ads',
        ];
        $gaps = [];

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
