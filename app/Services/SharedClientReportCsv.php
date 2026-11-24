<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SharedClientReportCsv
{
    /**
     * @param  Collection<int, array{target: SocialEngagementTarget, public_engagement: array<string, mixed>, marketing: array<string, mixed>, estimated_profitability?: array<string, mixed>}>  $campaignRows
     * @param  list<array<string, mixed>>  $reportingCurrencyGroups
     * @param  list<string>  $reportSections
     */
    public function build(
        Client $client,
        Collection $campaignRows,
        array $reportingCurrencyGroups,
        array $reportSections = ClientReportShare::DEFAULT_REPORT_SECTIONS,
        ?string $reportPeriodLabel = null,
        ?string $comparisonPeriodLabel = null,
    ): string {
        $stream = fopen('php://temp/maxmemory:5242880', 'w+b');
        $this->write(
            $client,
            $campaignRows,
            $reportingCurrencyGroups,
            $stream,
            $reportSections,
            $reportPeriodLabel,
            $comparisonPeriodLabel,
        );
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        if (! is_string($content)) {
            throw new \RuntimeException('Unable to read generated client report CSV.');
        }

        return $content;
    }

    /**
     * @param  Collection<int, array{target: SocialEngagementTarget, public_engagement: array<string, mixed>, marketing: array<string, mixed>, estimated_profitability?: array<string, mixed>}>  $campaignRows
     * @param  list<array<string, mixed>>  $reportingCurrencyGroups
     * @param  list<string>  $reportSections
     * @param  resource  $stream
     */
    public function write(
        Client $client,
        Collection $campaignRows,
        array $reportingCurrencyGroups,
        mixed $stream,
        array $reportSections = ClientReportShare::DEFAULT_REPORT_SECTIONS,
        ?string $reportPeriodLabel = null,
        ?string $comparisonPeriodLabel = null,
    ): void {
        fwrite($stream, "\xEF\xBB\xBF");
        $includesRoas = $campaignRows->contains(fn (array $campaignRow): bool => $this->campaignEfficiencyVisible(
            $reportSections,
            $campaignRow['marketing']['efficiency'] ?? [],
        ));
        $headers = $this->headers($reportSections, $includesRoas, $comparisonPeriodLabel !== null);
        fputcsv($stream, $headers, ',', '"', '');

        foreach ($campaignRows as $campaignRow) {
            fputcsv(
                $stream,
                $this->safeFields($this->orderedFields($headers, $this->campaignValues(
                    $client,
                    $campaignRow,
                    $reportSections,
                    $reportPeriodLabel,
                    $comparisonPeriodLabel,
                ))),
                ',',
                '"',
                '',
            );
        }

        if ($this->hasFinancialSection($reportSections)) {
            foreach ($reportingCurrencyGroups as $reportingCurrencyGroup) {
                fputcsv(
                    $stream,
                    $this->safeFields($this->orderedFields($headers, $this->currencySummaryValues(
                        $client,
                        $reportingCurrencyGroup,
                        $reportSections,
                        $reportPeriodLabel,
                        $comparisonPeriodLabel,
                    ))),
                    ',',
                    '"',
                    '',
                );
            }
        }
    }

    /** @return list<string> */
    private function headers(array $reportSections, bool $includesRoas, bool $includesComparison): array
    {
        $headers = [
            '資料列類型',
            '客戶',
            '活動',
            '平台',
            '活動起日',
            '活動迄日',
            '報表期間',
        ];

        if ($this->hasFinancialSection($reportSections)) {
            $headers[] = '報表幣別';
        }

        if (in_array(ClientReportShare::SECTION_PUBLIC_ENGAGEMENT, $reportSections, true)) {
            array_push($headers, '公開互動', '完整互動貼文數', '追蹤貼文數', '公開互動最近擷取時間（台北）');
        }

        if (in_array(ClientReportShare::SECTION_GOOGLE_ANALYTICS, $reportSections, true)) {
            array_push($headers, 'GA4 工作階段', 'GA4 營收', 'GA4 幣別', 'GA4 最近擷取時間（台北）');
        }

        if (in_array(ClientReportShare::SECTION_META_MARKETING, $reportSections, true)) {
            array_push($headers, 'Meta Ads 花費', 'Meta Ads 幣別', 'Meta Ads 最近擷取時間（台北）');
        }

        if (in_array(ClientReportShare::SECTION_GOOGLE_ADS, $reportSections, true)) {
            array_push($headers, 'Google Ads 花費', 'Google Ads 幣別', 'Google Ads 最近擷取時間（台北）');
        }

        if (in_array(ClientReportShare::SECTION_ESTIMATED_PROFITABILITY, $reportSections, true)) {
            array_push($headers, '估算毛利', '估算媒體貢獻', '獲利估算幣別', '損益兩平 ROAS', '獲利估算狀態');
        }

        if ($includesRoas) {
            $headers[] = 'GA4 ROAS';
        }

        if (in_array(ClientReportShare::SECTION_GOOGLE_ANALYTICS, $reportSections, true)) {
            $headers[] = 'GA4 換算活動數';
        }

        if (in_array(ClientReportShare::SECTION_META_MARKETING, $reportSections, true)) {
            $headers[] = 'Meta Ads 換算活動數';
        }

        if (in_array(ClientReportShare::SECTION_GOOGLE_ADS, $reportSections, true)) {
            $headers[] = 'Google Ads 換算活動數';
        }

        if ($this->hasFinancialSection($reportSections)) {
            array_push($headers, '缺匯率活動數', '缺逐日資料活動數');
        }

        if ($includesComparison) {
            $headers[] = '比較期間';

            if (in_array(ClientReportShare::SECTION_GOOGLE_ANALYTICS, $reportSections, true)) {
                $headers[] = 'GA4 營收變化';
            }

            if (in_array(ClientReportShare::SECTION_META_MARKETING, $reportSections, true)) {
                $headers[] = 'Meta Ads 花費變化';
            }

            if (in_array(ClientReportShare::SECTION_GOOGLE_ADS, $reportSections, true)) {
                $headers[] = 'Google Ads 花費變化';
            }

            if (in_array(ClientReportShare::SECTION_GOOGLE_ANALYTICS, $reportSections, true)
                && (in_array(ClientReportShare::SECTION_META_MARKETING, $reportSections, true)
                    || in_array(ClientReportShare::SECTION_GOOGLE_ADS, $reportSections, true))) {
                $headers[] = 'GA4 ROAS 變化';
            }
        }

        return $headers;
    }

    /**
     * @param  array{target: SocialEngagementTarget, public_engagement: array<string, mixed>, marketing: array<string, mixed>, estimated_profitability?: array<string, mixed>}  $campaignRow
     * @return list<string|null|int|float>
     */
    private function campaignValues(
        Client $client,
        array $campaignRow,
        array $reportSections,
        ?string $reportPeriodLabel,
        ?string $comparisonPeriodLabel,
    ): array {
        $target = $campaignRow['target'];
        $publicEngagement = $campaignRow['public_engagement'];
        $marketing = $campaignRow['marketing'];
        $googleAnalytics = $marketing['google_analytics'];
        $metaMarketing = $marketing['meta_marketing'];
        $googleAds = $marketing['google_ads'];
        $efficiency = $marketing['efficiency'];
        $showPublicEngagement = in_array(ClientReportShare::SECTION_PUBLIC_ENGAGEMENT, $reportSections, true);
        $showGoogleAnalytics = in_array(ClientReportShare::SECTION_GOOGLE_ANALYTICS, $reportSections, true);
        $showMetaMarketing = in_array(ClientReportShare::SECTION_META_MARKETING, $reportSections, true);
        $showGoogleAds = in_array(ClientReportShare::SECTION_GOOGLE_ADS, $reportSections, true);
        $showEstimatedProfitability = in_array(ClientReportShare::SECTION_ESTIMATED_PROFITABILITY, $reportSections, true);
        $showFinancialSections = $this->hasFinancialSection($reportSections);
        $showEfficiency = $this->campaignEfficiencyVisible($reportSections, $efficiency);
        $estimatedProfitability = is_array($campaignRow['estimated_profitability'] ?? null)
            ? $campaignRow['estimated_profitability']
            : [];

        return [
            '資料列類型' => '活動',
            '客戶' => $client->name,
            '活動' => $target->tag,
            '平台' => $this->platformLabel($target->social_platform),
            '活動起日' => $target->starts_on?->toDateString(),
            '活動迄日' => $target->ends_on?->toDateString(),
            '報表期間' => $reportPeriodLabel,
            '報表幣別' => $showFinancialSections ? $target->client?->reporting_currency_code : null,
            '公開互動' => $showPublicEngagement ? ($publicEngagement['interaction_total'] ?? null) : null,
            '完整互動貼文數' => $showPublicEngagement ? ($publicEngagement['available_posts'] ?? null) : null,
            '追蹤貼文數' => $showPublicEngagement ? ($publicEngagement['tracked_posts'] ?? null) : null,
            '公開互動最近擷取時間（台北）' => $showPublicEngagement ? $this->formatTimestamp($publicEngagement['latest_at'] ?? null) : null,
            'GA4 工作階段' => $showGoogleAnalytics ? ($googleAnalytics['sessions'] ?? null) : null,
            'GA4 營收' => $showGoogleAnalytics ? ($googleAnalytics['revenue'] ?? null) : null,
            'GA4 幣別' => $showGoogleAnalytics ? ($googleAnalytics['currency_code'] ?? null) : null,
            'GA4 最近擷取時間（台北）' => $showGoogleAnalytics ? $this->formatTimestamp($googleAnalytics['synced_at'] ?? null) : null,
            'Meta Ads 花費' => $showMetaMarketing ? ($metaMarketing['spend_amount'] ?? null) : null,
            'Meta Ads 幣別' => $showMetaMarketing ? ($metaMarketing['currency_code'] ?? null) : null,
            'Meta Ads 最近擷取時間（台北）' => $showMetaMarketing ? $this->formatTimestamp($metaMarketing['synced_at'] ?? null) : null,
            'Google Ads 花費' => $showGoogleAds ? ($googleAds['spend_amount'] ?? null) : null,
            'Google Ads 幣別' => $showGoogleAds ? ($googleAds['currency_code'] ?? null) : null,
            'Google Ads 最近擷取時間（台北）' => $showGoogleAds ? $this->formatTimestamp($googleAds['synced_at'] ?? null) : null,
            '估算毛利' => $showEstimatedProfitability
                ? $this->formatDecimal($estimatedProfitability['gross_profit_amount'] ?? null)
                : null,
            '估算媒體貢獻' => $showEstimatedProfitability
                ? $this->formatDecimal($estimatedProfitability['media_contribution_amount'] ?? null)
                : null,
            '獲利估算幣別' => $showEstimatedProfitability ? ($estimatedProfitability['currency_code'] ?? null) : null,
            '損益兩平 ROAS' => $showEstimatedProfitability && is_numeric($estimatedProfitability['break_even_roas'] ?? null)
                ? number_format((float) $estimatedProfitability['break_even_roas'], 4, '.', '')
                : null,
            '獲利估算狀態' => $showEstimatedProfitability ? ($estimatedProfitability['status_label'] ?? null) : null,
            'GA4 ROAS' => $showEfficiency ? ($efficiency['roas'] ?? null) : null,
            'GA4 換算活動數' => null,
            'Meta Ads 換算活動數' => null,
            'Google Ads 換算活動數' => null,
            '缺匯率活動數' => null,
            '缺逐日資料活動數' => null,
            '比較期間' => $comparisonPeriodLabel,
            'GA4 營收變化' => $this->formatMetricChanges($campaignRow['comparison']['money']['ga4_revenue'] ?? []),
            'Meta Ads 花費變化' => $this->formatMetricChanges($campaignRow['comparison']['money']['meta_spend'] ?? []),
            'Google Ads 花費變化' => $this->formatMetricChanges($campaignRow['comparison']['money']['google_ads_spend'] ?? []),
            'GA4 ROAS 變化' => $this->formatRoasChange($campaignRow['comparison']['roas']['ga4_roas|'.($this->ga4RoasCurrency($marketing) ?? '')] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $reportingCurrencyGroup
     * @return list<string|int|float|null>
     */
    private function currencySummaryValues(
        Client $client,
        array $reportingCurrencyGroup,
        array $reportSections,
        ?string $reportPeriodLabel,
        ?string $comparisonPeriodLabel,
    ): array {
        $coverage = $reportingCurrencyGroup['conversion_coverage'] ?? [];
        $showGoogleAnalytics = in_array(ClientReportShare::SECTION_GOOGLE_ANALYTICS, $reportSections, true);
        $showMetaMarketing = in_array(ClientReportShare::SECTION_META_MARKETING, $reportSections, true);
        $showGoogleAds = in_array(ClientReportShare::SECTION_GOOGLE_ADS, $reportSections, true);

        $values = [
            '資料列類型' => '報表幣別彙總',
            '客戶' => $client->name,
            '報表期間' => $reportPeriodLabel,
            '報表幣別' => $reportingCurrencyGroup['currency_code'] ?? null,
            'GA4 營收' => $showGoogleAnalytics ? ($reportingCurrencyGroup['ga4_revenue'] ?? null) : null,
            'GA4 幣別' => $showGoogleAnalytics ? ($reportingCurrencyGroup['currency_code'] ?? null) : null,
            'Meta Ads 花費' => $showMetaMarketing ? ($reportingCurrencyGroup['meta_spend'] ?? null) : null,
            'Meta Ads 幣別' => $showMetaMarketing ? ($reportingCurrencyGroup['currency_code'] ?? null) : null,
            'Google Ads 花費' => $showGoogleAds ? ($reportingCurrencyGroup['google_ads_spend'] ?? null) : null,
            'Google Ads 幣別' => $showGoogleAds ? ($reportingCurrencyGroup['currency_code'] ?? null) : null,
            'GA4 換算活動數' => $showGoogleAnalytics ? ($coverage['google_analytics']['converted_campaigns'] ?? 0) : null,
            'Meta Ads 換算活動數' => $showMetaMarketing ? ($coverage['meta_marketing']['converted_campaigns'] ?? 0) : null,
            'Google Ads 換算活動數' => $showGoogleAds ? ($coverage['google_ads']['converted_campaigns'] ?? 0) : null,
            '缺匯率活動數' => $this->coverageTotal($coverage, 'missing_rate_campaigns', $reportSections),
            '缺逐日資料活動數' => $this->coverageTotal($coverage, 'missing_daily_data_campaigns', $reportSections),
        ];

        if ($comparisonPeriodLabel !== null) {
            $comparison = is_array($reportingCurrencyGroup['comparison'] ?? null)
                ? $reportingCurrencyGroup['comparison']
                : [];
            $currencyCode = is_string($reportingCurrencyGroup['currency_code'] ?? null)
                ? $reportingCurrencyGroup['currency_code']
                : '';
            $values += [
                '比較期間' => $comparisonPeriodLabel,
                'GA4 營收變化' => $this->formatMetricChange($comparison, 'ga4_revenue', $currencyCode),
                'Meta Ads 花費變化' => $this->formatMetricChange($comparison, 'meta_spend', $currencyCode),
                'Google Ads 花費變化' => $this->formatMetricChange($comparison, 'google_ads_spend', $currencyCode),
                'GA4 ROAS 變化' => null,
            ];
        }

        return $values;
    }

    /** @param array<string, array<string, array{delta: float|null, percent_change: float|null, status: string}>> $comparison */
    private function formatMetricChange(array $comparison, string $metric, string $currencyCode): string
    {
        $change = $comparison[$metric][$currencyCode] ?? null;

        if (! is_array($change) || $change['status'] === 'insufficient') {
            return '比較資料不足'.($currencyCode === '' ? '' : '（'.$currencyCode.'）');
        }

        $delta = is_numeric($change['delta'] ?? null) ? (float) $change['delta'] : 0.0;
        $formattedDelta = ($delta > 0 ? '+' : '').number_format($delta, 2, '.', '').' '.$currencyCode;

        if ($change['status'] === 'zero_baseline') {
            return $formattedDelta.'（前期為 0）';
        }

        $percentChange = is_numeric($change['percent_change'] ?? null) ? (float) $change['percent_change'] : null;

        if ($percentChange === null) {
            return '比較資料不足'.($currencyCode === '' ? '' : '（'.$currencyCode.'）');
        }

        return $formattedDelta.'（'.($percentChange > 0 ? '+' : '').number_format($percentChange, 2, '.', '').'%）';
    }

    /** @param array<string, array{delta: float|null, percent_change: float|null, status: string}> $changes */
    private function formatMetricChanges(array $changes): ?string
    {
        if ($changes === []) {
            return null;
        }

        return collect($changes)
            ->map(fn (array $change, string $currencyCode): string => $this->formatMetricChange(
                ['metric' => [$currencyCode => $change]],
                'metric',
                $currencyCode,
            ))
            ->implode('；');
    }

    private function formatDecimal(mixed $value): ?string
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    /** @param array{source_label: string, currency_code: string, delta: float|null, percent_change: float|null, status: string}|null $change */
    private function formatRoasChange(?array $change): ?string
    {
        if ($change === null || $change['status'] === 'insufficient') {
            return null;
        }

        $delta = is_numeric($change['delta'] ?? null) ? (float) $change['delta'] : 0.0;
        $formattedDelta = ($delta > 0 ? '+' : '').number_format($delta, 2, '.', '').'×';

        if ($change['status'] === 'zero_baseline') {
            return $formattedDelta.'（前期為 0，'.$change['currency_code'].'）';
        }

        $percentChange = is_numeric($change['percent_change'] ?? null) ? (float) $change['percent_change'] : null;

        if ($percentChange === null) {
            return null;
        }

        return $formattedDelta.'（'.($percentChange > 0 ? '+' : '').number_format($percentChange, 2, '.', '').'%，'.$change['currency_code'].'）';
    }

    /** @param array<string, mixed> $marketing */
    private function ga4RoasCurrency(array $marketing): ?string
    {
        $efficiency = is_array($marketing['efficiency'] ?? null) ? $marketing['efficiency'] : [];
        $currencyCode = $efficiency['roas_spend_currency'] ?? null;

        return is_string($currencyCode) && trim($currencyCode) !== '' ? strtoupper(trim($currencyCode)) : null;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, string|null|int|float>  $values
     * @return list<string|null|int|float>
     */
    private function orderedFields(array $headers, array $values): array
    {
        return array_map(static fn (string $header): string|int|float|null => $values[$header] ?? null, $headers);
    }

    /**
     * @param  array<string, array<string, mixed>>  $coverage
     */
    private function coverageTotal(array $coverage, string $countKey, array $reportSections): int
    {
        $sectionBySource = [
            'google_analytics' => ClientReportShare::SECTION_GOOGLE_ANALYTICS,
            'meta_marketing' => ClientReportShare::SECTION_META_MARKETING,
            'google_ads' => ClientReportShare::SECTION_GOOGLE_ADS,
        ];

        return array_sum(array_map(
            static fn (string $source, string $section): int => in_array($section, $reportSections, true)
                ? (int) ($coverage[$source][$countKey] ?? 0)
                : 0,
            array_keys($sectionBySource),
            array_values($sectionBySource),
        ));
    }

    /** @param  list<string>  $reportSections */
    private function hasFinancialSection(array $reportSections): bool
    {
        return in_array(ClientReportShare::SECTION_GOOGLE_ANALYTICS, $reportSections, true)
            || in_array(ClientReportShare::SECTION_META_MARKETING, $reportSections, true)
            || in_array(ClientReportShare::SECTION_GOOGLE_ADS, $reportSections, true);
    }

    /** @param  list<string>  $reportSections */
    private function campaignEfficiencyVisible(array $reportSections, array $efficiency): bool
    {
        if (! in_array(ClientReportShare::SECTION_GOOGLE_ANALYTICS, $reportSections, true)) {
            return false;
        }

        return match ($efficiency['roas_spend_source'] ?? null) {
            'google_ads' => in_array(ClientReportShare::SECTION_GOOGLE_ADS, $reportSections, true),
            'meta_marketing' => in_array(ClientReportShare::SECTION_META_MARKETING, $reportSections, true),
            'manual' => in_array(ClientReportShare::SECTION_META_MARKETING, $reportSections, true)
                || in_array(ClientReportShare::SECTION_GOOGLE_ADS, $reportSections, true),
            default => false,
        };
    }

    /** @param  list<string|null|int|float>  $fields
     * @return list<string>
     */
    private function safeFields(array $fields): array
    {
        return array_map(
            static fn (string|int|float|null $field): string => CsvFormulaSanitizer::escape((string) ($field ?? '')),
            $fields,
        );
    }

    private function formatTimestamp(mixed $capturedAt): ?string
    {
        if (! $capturedAt instanceof CarbonImmutable) {
            return null;
        }

        return $capturedAt->timezone('Asia/Taipei')->format('Y-m-d H:i:s');
    }

    private function platformLabel(string $platform): string
    {
        return [
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'threads' => 'Threads',
            'dcard' => 'Dcard',
        ][$platform] ?? $platform;
    }
}
