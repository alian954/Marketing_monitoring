<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;

class CampaignDailyMarketingReportCsv
{
    private const SOURCES = [
        'google_analytics' => [
            'label' => 'GA4',
            'metrics' => ['sessions', 'engaged_sessions', 'key_events', 'total_revenue'],
        ],
        'meta_marketing' => [
            'label' => 'Meta Ads',
            'metrics' => ['spend_amount', 'impressions', 'reach', 'clicks', 'omni_purchase_count', 'omni_purchase_value', 'purchase_roas'],
        ],
        'google_ads' => [
            'label' => 'Google Ads',
            'metrics' => ['spend_amount', 'impressions', 'clicks', 'conversions', 'conversions_value', 'conversion_value_roas'],
        ],
    ];

    private const HEADERS = [
        'source',
        'date',
        'status',
        'requested_start_on',
        'requested_end_on',
        'coverage_start_on',
        'coverage_end_on',
        'currency_code',
        'synced_at_asia_taipei',
        'sessions',
        'engaged_sessions',
        'key_events',
        'total_revenue',
        'spend_amount',
        'impressions',
        'reach',
        'clicks',
        'omni_purchase_count',
        'omni_purchase_value',
        'purchase_roas',
        'conversions',
        'conversions_value',
        'conversion_value_roas',
        'meta_spend_amount',
        'meta_currency_code',
        'google_ads_spend_amount',
        'google_ads_currency_code',
        'combined_spend_amount',
        'combined_spend_currency',
        'ga4_revenue',
        'ga4_currency_code',
        'daily_roas',
        'roas_status',
        'roas_spend_amount',
        'roas_spend_currency',
        'roas_spend_source',
    ];

    /**
     * @param  array<string, array<string, mixed>>  $dailyMarketingPerformance
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function build(array $dailyMarketingPerformance): array
    {
        $rows = [];

        foreach (self::SOURCES as $sourceKey => $sourceDefinition) {
            $source = $dailyMarketingPerformance[$sourceKey] ?? [];
            $dailyRows = is_array($source['rows'] ?? null) ? $source['rows'] : [];

            foreach ($dailyRows as $dailyRow) {
                if (! is_array($dailyRow) || ! is_string($dailyRow['date'] ?? null)) {
                    continue;
                }

                $fields = [
                    $sourceDefinition['label'],
                    $dailyRow['date'],
                    $source['status'] ?? '',
                    $source['requested_start_on'] ?? '',
                    $source['requested_end_on'] ?? '',
                    $source['coverage_start_on'] ?? '',
                    $source['coverage_end_on'] ?? '',
                    $source['currency_code'] ?? '',
                    $this->syncedAt($source['synced_at'] ?? null),
                ];

                foreach (array_slice(self::HEADERS, 9, 14) as $metric) {
                    $fields[] = in_array($metric, $sourceDefinition['metrics'], true)
                        ? $this->value($dailyRow[$metric] ?? null)
                        : '';
                }

                $fields = [...$fields, ...array_fill(0, 13, '')];

                $rows[] = array_map($this->value(...), $fields);
            }
        }

        $combinedEfficiency = $dailyMarketingPerformance['combined_efficiency'] ?? [];
        $combinedRows = is_array($combinedEfficiency['rows'] ?? null) ? $combinedEfficiency['rows'] : [];

        foreach ($combinedRows as $combinedRow) {
            if (! is_array($combinedRow) || ! is_string($combinedRow['date'] ?? null)) {
                continue;
            }

            $fields = [
                '每日 GA4 ROAS',
                $combinedRow['date'],
                $combinedRow['roas_status'] ?? '',
                $combinedEfficiency['requested_start_on'] ?? '',
                $combinedEfficiency['requested_end_on'] ?? '',
                $combinedEfficiency['coverage_start_on'] ?? '',
                $combinedEfficiency['coverage_end_on'] ?? '',
                $combinedRow['ad_spend_currency'] ?? '',
                $this->syncedAt($combinedEfficiency['latest_synced_at'] ?? null),
            ];

            $fields = [...$fields, ...array_fill(0, 14, '')];

            foreach ([
                'meta_spend_amount',
                'meta_currency_code',
                'google_ads_spend_amount',
                'google_ads_currency_code',
                'ad_spend_amount',
                'ad_spend_currency',
                'ga4_revenue',
                'ga4_currency_code',
                'roas',
                'roas_status',
                'roas_spend_amount',
                'roas_spend_currency',
                'roas_spend_source',
            ] as $metric) {
                $fields[] = $this->value(match ($metric) {
                    'meta_currency_code' => $combinedRow['meta_currency_code'] ?? null,
                    'google_ads_currency_code' => $combinedRow['google_ads_currency_code'] ?? null,
                    'ad_spend_amount' => $combinedRow['ad_spend_amount'] ?? null,
                    'ad_spend_currency' => $combinedRow['ad_spend_currency'] ?? null,
                    'roas' => $combinedRow['roas'] ?? null,
                    default => $combinedRow[$metric] ?? null,
                });
            }

            $rows[] = array_map($this->value(...), $fields);
        }

        return ['headers' => self::HEADERS, 'rows' => $rows];
    }

    private function syncedAt(mixed $syncedAt): string
    {
        return $syncedAt instanceof DateTimeInterface
            ? Carbon::instance($syncedAt)->timezone('Asia/Taipei')->format('Y-m-d H:i:s')
            : '';
    }

    private function value(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value)
            ? (string) $value
            : '';
    }
}
