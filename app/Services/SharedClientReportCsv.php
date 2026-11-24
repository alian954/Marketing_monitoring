<?php

namespace App\Services;

use App\Models\Client;
use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SharedClientReportCsv
{
    /**
     * @param  Collection<int, array{target: SocialEngagementTarget, public_engagement: array<string, mixed>, marketing: array<string, mixed>}>  $campaignRows
     * @param  list<array<string, mixed>>  $reportingCurrencyGroups
     * @param  resource  $stream
     */
    public function write(
        Client $client,
        Collection $campaignRows,
        array $reportingCurrencyGroups,
        mixed $stream,
    ): void {
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $this->headers(), ',', '"', '');

        foreach ($campaignRows as $campaignRow) {
            fputcsv(
                $stream,
                $this->safeFields($this->campaignFields($client, $campaignRow)),
                ',',
                '"',
                '',
            );
        }

        foreach ($reportingCurrencyGroups as $reportingCurrencyGroup) {
            fputcsv(
                $stream,
                $this->safeFields($this->currencySummaryFields($client, $reportingCurrencyGroup)),
                ',',
                '"',
                '',
            );
        }
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            '資料列類型',
            '客戶',
            '活動',
            '平台',
            '活動起日',
            '活動迄日',
            '報表幣別',
            '公開互動',
            '完整互動貼文數',
            '追蹤貼文數',
            '公開互動最近擷取時間（台北）',
            'GA4 工作階段',
            'GA4 營收',
            'GA4 幣別',
            'GA4 最近擷取時間（台北）',
            'Meta Ads 花費',
            'Meta Ads 幣別',
            'Meta Ads 最近擷取時間（台北）',
            'Google Ads 花費',
            'Google Ads 幣別',
            'Google Ads 最近擷取時間（台北）',
            'GA4 ROAS',
            'GA4 換算活動數',
            'Meta Ads 換算活動數',
            'Google Ads 換算活動數',
            '缺匯率活動數',
            '缺逐日資料活動數',
        ];
    }

    /**
     * @param  array{target: SocialEngagementTarget, public_engagement: array<string, mixed>, marketing: array<string, mixed>}  $campaignRow
     * @return list<string|null|int|float>
     */
    private function campaignFields(Client $client, array $campaignRow): array
    {
        $target = $campaignRow['target'];
        $publicEngagement = $campaignRow['public_engagement'];
        $marketing = $campaignRow['marketing'];
        $googleAnalytics = $marketing['google_analytics'];
        $metaMarketing = $marketing['meta_marketing'];
        $googleAds = $marketing['google_ads'];
        $efficiency = $marketing['efficiency'];

        return [
            '活動',
            $client->name,
            $target->tag,
            $this->platformLabel($target->social_platform),
            $target->starts_on?->toDateString(),
            $target->ends_on?->toDateString(),
            $target->client?->reporting_currency_code,
            $publicEngagement['interaction_total'] ?? null,
            $publicEngagement['available_posts'] ?? null,
            $publicEngagement['tracked_posts'] ?? null,
            $this->formatTimestamp($publicEngagement['latest_at'] ?? null),
            $googleAnalytics['sessions'] ?? null,
            $googleAnalytics['revenue'] ?? null,
            $googleAnalytics['currency_code'] ?? null,
            $this->formatTimestamp($googleAnalytics['synced_at'] ?? null),
            $metaMarketing['spend_amount'] ?? null,
            $metaMarketing['currency_code'] ?? null,
            $this->formatTimestamp($metaMarketing['synced_at'] ?? null),
            $googleAds['spend_amount'] ?? null,
            $googleAds['currency_code'] ?? null,
            $this->formatTimestamp($googleAds['synced_at'] ?? null),
            $efficiency['roas'] ?? null,
            null,
            null,
            null,
            null,
            null,
        ];
    }

    /**
     * @param  array<string, mixed>  $reportingCurrencyGroup
     * @return list<string|int|float|null>
     */
    private function currencySummaryFields(Client $client, array $reportingCurrencyGroup): array
    {
        $coverage = $reportingCurrencyGroup['conversion_coverage'] ?? [];

        return [
            '報表幣別彙總',
            $client->name,
            null,
            null,
            null,
            null,
            $reportingCurrencyGroup['currency_code'] ?? null,
            null,
            null,
            null,
            null,
            null,
            $reportingCurrencyGroup['ga4_revenue'] ?? null,
            $reportingCurrencyGroup['currency_code'] ?? null,
            null,
            $reportingCurrencyGroup['meta_spend'] ?? null,
            $reportingCurrencyGroup['currency_code'] ?? null,
            null,
            $reportingCurrencyGroup['google_ads_spend'] ?? null,
            $reportingCurrencyGroup['currency_code'] ?? null,
            null,
            null,
            $coverage['google_analytics']['converted_campaigns'] ?? 0,
            $coverage['meta_marketing']['converted_campaigns'] ?? 0,
            $coverage['google_ads']['converted_campaigns'] ?? 0,
            $this->coverageTotal($coverage, 'missing_rate_campaigns'),
            $this->coverageTotal($coverage, 'missing_daily_data_campaigns'),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $coverage
     */
    private function coverageTotal(array $coverage, string $countKey): int
    {
        return array_sum(array_map(
            static fn (array $sourceCoverage): int => (int) ($sourceCoverage[$countKey] ?? 0),
            $coverage,
        ));
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
