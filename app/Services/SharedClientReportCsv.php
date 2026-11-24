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
     * @param  Collection<int, array{target: SocialEngagementTarget, public_engagement: array<string, mixed>, marketing: array<string, mixed>}>  $campaignRows
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
    ): void {
        fwrite($stream, "\xEF\xBB\xBF");
        $includesRoas = $campaignRows->contains(fn (array $campaignRow): bool => $this->campaignEfficiencyVisible(
            $reportSections,
            $campaignRow['marketing']['efficiency'] ?? [],
        ));
        $headers = $this->headers($reportSections, $includesRoas);
        fputcsv($stream, $headers, ',', '"', '');

        foreach ($campaignRows as $campaignRow) {
            fputcsv(
                $stream,
                $this->safeFields($this->orderedFields($headers, $this->campaignValues($client, $campaignRow, $reportSections, $reportPeriodLabel))),
                ',',
                '"',
                '',
            );
        }

        if ($this->hasFinancialSection($reportSections)) {
            foreach ($reportingCurrencyGroups as $reportingCurrencyGroup) {
                fputcsv(
                    $stream,
                    $this->safeFields($this->orderedFields($headers, $this->currencySummaryValues($client, $reportingCurrencyGroup, $reportSections, $reportPeriodLabel))),
                    ',',
                    '"',
                    '',
                );
            }
        }
    }

    /** @return list<string> */
    private function headers(array $reportSections, bool $includesRoas): array
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

        return $headers;
    }

    /**
     * @param  array{target: SocialEngagementTarget, public_engagement: array<string, mixed>, marketing: array<string, mixed>}  $campaignRow
     * @return list<string|null|int|float>
     */
    private function campaignValues(Client $client, array $campaignRow, array $reportSections, ?string $reportPeriodLabel): array
    {
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
        $showFinancialSections = $this->hasFinancialSection($reportSections);
        $showEfficiency = $this->campaignEfficiencyVisible($reportSections, $efficiency);

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
            'GA4 ROAS' => $showEfficiency ? ($efficiency['roas'] ?? null) : null,
            'GA4 換算活動數' => null,
            'Meta Ads 換算活動數' => null,
            'Google Ads 換算活動數' => null,
            '缺匯率活動數' => null,
            '缺逐日資料活動數' => null,
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
    ): array {
        $coverage = $reportingCurrencyGroup['conversion_coverage'] ?? [];
        $showGoogleAnalytics = in_array(ClientReportShare::SECTION_GOOGLE_ANALYTICS, $reportSections, true);
        $showMetaMarketing = in_array(ClientReportShare::SECTION_META_MARKETING, $reportSections, true);
        $showGoogleAds = in_array(ClientReportShare::SECTION_GOOGLE_ADS, $reportSections, true);

        return [
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
