<?php

namespace App\Services;

use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use Carbon\CarbonImmutable;

class CampaignDailyMarketingPerformanceReport
{
    /**
     * @return array{
     *     google_analytics: array{label: string, status: string, requested_start_on: string|null, requested_end_on: string|null, coverage_start_on: string|null, coverage_end_on: string|null, currency_code: string|null, synced_at: CarbonImmutable|null, rows: list<array<string, mixed>>},
     *     meta_marketing: array{label: string, status: string, requested_start_on: string|null, requested_end_on: string|null, coverage_start_on: string|null, coverage_end_on: string|null, currency_code: string|null, synced_at: CarbonImmutable|null, rows: list<array<string, mixed>>},
     *     google_ads: array{label: string, status: string, requested_start_on: string|null, requested_end_on: string|null, coverage_start_on: string|null, coverage_end_on: string|null, currency_code: string|null, campaign_channel_type: string|null, synced_at: CarbonImmutable|null, rows: list<array<string, mixed>>}
     * }
     */
    public function build(
        ?SocialEngagementTargetGoogleAnalyticsReport $googleAnalyticsReport,
        ?SocialEngagementTargetMetaMarketingReport $metaMarketingReport,
        ?SocialEngagementTargetGoogleAdsReport $googleAdsReport,
    ): array {
        $googleAdsSource = $this->source(
            'Google Ads',
            $googleAdsReport?->daily_metrics,
            $googleAdsReport?->currency_code,
            $googleAdsReport?->synced_at,
            function (array $row): array {
                $spendAmount = $this->decimalMetric($row['spend_amount'] ?? null);
                $impressions = $this->integerMetric($row['impressions'] ?? null);
                $clicks = $this->integerMetric($row['clicks'] ?? null);
                $conversions = $this->decimalMetric($row['conversions'] ?? null);
                $conversionsValue = $this->decimalMetric($row['conversions_value'] ?? null);

                return [
                    'date' => $row['date'],
                    'spend_amount' => $spendAmount,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'conversions' => $conversions,
                    'conversions_value' => $conversionsValue,
                    'ctr_percent' => $this->calculatedMetric($clicks, $impressions, 100),
                    'cost_per_click' => $this->calculatedMetric($spendAmount, $clicks),
                    'cost_per_thousand_impressions' => $this->calculatedMetric($spendAmount, $impressions, 1000),
                    'cost_per_action' => $this->calculatedMetric($spendAmount, $conversions),
                    'conversion_rate_percent' => $this->calculatedMetric($conversions, $clicks, 100),
                    'conversion_value_roas' => $conversionsValue !== null && $spendAmount !== null && (float) $spendAmount > 0
                        ? round((float) $conversionsValue / (float) $spendAmount, 2)
                        : null,
                    'search_impression_share' => $this->decimalMetric($row['search_impression_share'] ?? null),
                    'search_budget_lost_impression_share' => $this->decimalMetric($row['search_budget_lost_impression_share'] ?? null),
                    'search_rank_lost_impression_share' => $this->decimalMetric($row['search_rank_lost_impression_share'] ?? null),
                ];
            },
        );
        $googleAdsDailyMetrics = $googleAdsReport?->daily_metrics;
        $googleAdsSource['campaign_channel_type'] = $this->campaignChannelType(
            is_array($googleAdsDailyMetrics) ? ($googleAdsDailyMetrics['campaign_channel_type'] ?? null) : null,
        );

        return [
            'google_analytics' => $this->source(
                'GA4',
                $googleAnalyticsReport?->daily_metrics,
                $googleAnalyticsReport?->currency_code,
                $googleAnalyticsReport?->synced_at,
                fn (array $row): array => [
                    'date' => $row['date'],
                    'sessions' => $this->integerMetric($row['sessions'] ?? null),
                    'engaged_sessions' => $this->integerMetric($row['engaged_sessions'] ?? null),
                    'key_events' => $this->decimalMetric($row['key_events'] ?? null),
                    'total_revenue' => $this->decimalMetric($row['total_revenue'] ?? null),
                ],
            ),
            'meta_marketing' => $this->source(
                'Meta Ads',
                $metaMarketingReport?->daily_metrics,
                $metaMarketingReport?->currency_code,
                $metaMarketingReport?->synced_at,
                function (array $row): array {
                    $spendAmount = $this->decimalMetric($row['spend_amount'] ?? null);
                    $impressions = $this->integerMetric($row['impressions'] ?? null);
                    $reach = $this->integerMetric($row['reach'] ?? null);
                    $clicks = $this->integerMetric($row['clicks'] ?? null);
                    $purchaseCount = $this->actionMetric($row['actions'] ?? null, 'omni_purchase');
                    $purchaseValue = $this->actionMetric($row['action_values'] ?? null, 'omni_purchase');

                    return [
                        'date' => $row['date'],
                        'spend_amount' => $spendAmount,
                        'impressions' => $impressions,
                        'reach' => $reach,
                        'clicks' => $clicks,
                        'omni_purchase_count' => $purchaseCount,
                        'omni_purchase_value' => $purchaseValue,
                        'frequency' => $this->calculatedMetric($impressions, $reach),
                        'ctr_percent' => $this->calculatedMetric($clicks, $impressions, 100),
                        'cost_per_click' => $this->calculatedMetric($spendAmount, $clicks),
                        'cost_per_thousand_impressions' => $this->calculatedMetric($spendAmount, $impressions, 1000),
                        'cost_per_action' => $this->calculatedMetric($spendAmount, $purchaseCount),
                        'purchase_roas' => $purchaseValue !== null && $spendAmount !== null && (float) $spendAmount > 0
                            ? round((float) $purchaseValue / (float) $spendAmount, 2)
                            : null,
                    ];
                },
            ),
            'google_ads' => $googleAdsSource,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @param  callable(array<string, mixed>): array<string, mixed>  $mapRow
     * @return array{label: string, status: string, requested_start_on: string|null, requested_end_on: string|null, coverage_start_on: string|null, coverage_end_on: string|null, currency_code: string|null, synced_at: CarbonImmutable|null, rows: list<array<string, mixed>>}
     */
    private function source(
        string $label,
        ?array $snapshot,
        ?string $currencyCode,
        ?CarbonImmutable $syncedAt,
        callable $mapRow,
    ): array {
        $rawRows = is_array($snapshot['rows'] ?? null) && array_is_list($snapshot['rows'])
            ? $snapshot['rows']
            : [];
        $rows = [];

        foreach ($rawRows as $row) {
            if (! is_array($row) || ! $this->validDate($row['date'] ?? null)) {
                continue;
            }

            $rows[] = $mapRow($row);
        }

        usort($rows, static fn (array $first, array $second): int => strcmp($second['date'], $first['date']));

        $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : 'not_synced';

        if ($status === 'complete'
            && $syncedAt instanceof CarbonImmutable
            && $syncedAt->lessThanOrEqualTo(CarbonImmutable::now()->subDay())) {
            $status = 'stale';
        }

        return [
            'label' => $label,
            'status' => $status,
            'requested_start_on' => $this->dateValue($snapshot['requested_start_on'] ?? null),
            'requested_end_on' => $this->dateValue($snapshot['requested_end_on'] ?? null),
            'coverage_start_on' => $this->dateValue($snapshot['coverage_start_on'] ?? null),
            'coverage_end_on' => $this->dateValue($snapshot['coverage_end_on'] ?? null),
            'currency_code' => $this->currencyCode($currencyCode),
            'synced_at' => $syncedAt,
            'rows' => $rows,
        ];
    }

    private function validDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && $this->dateValue($value) === $value;
    }

    private function dateValue(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** @param array<int, mixed>|null $metrics */
    private function actionMetric(mixed $metrics, string $actionType): ?string
    {
        if (! is_array($metrics)) {
            return null;
        }

        foreach ($metrics as $metric) {
            if (is_array($metric)
                && ($metric['action_type'] ?? null) === $actionType
                && is_numeric($metric['value'] ?? null)) {
                return (string) $metric['value'];
            }
        }

        return null;
    }

    private function integerMetric(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function decimalMetric(mixed $value): ?string
    {
        return (is_string($value) || is_int($value) || is_float($value)) && is_numeric($value)
            ? (string) $value
            : null;
    }

    private function calculatedMetric(mixed $numerator, mixed $denominator, float $multiplier = 1): ?float
    {
        if (! is_numeric($numerator) || ! is_numeric($denominator)
            || (float) $numerator < 0 || (float) $denominator <= 0) {
            return null;
        }

        return round((float) $numerator / (float) $denominator * $multiplier, 4);
    }

    private function currencyCode(?string $currencyCode): ?string
    {
        return is_string($currencyCode) && preg_match('/^[A-Za-z]{3}$/', trim($currencyCode)) === 1
            ? strtoupper(trim($currencyCode))
            : null;
    }

    private function campaignChannelType(mixed $channelType): ?string
    {
        return is_string($channelType)
            && preg_match('/^[A-Z][A-Z0-9_]{0,49}$/', $channelType) === 1
            && ! in_array($channelType, ['UNKNOWN', 'UNSPECIFIED'], true)
            ? $channelType
            : null;
    }
}
