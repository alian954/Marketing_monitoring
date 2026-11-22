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

                foreach (array_slice(self::HEADERS, 9) as $metric) {
                    $fields[] = in_array($metric, $sourceDefinition['metrics'], true)
                        ? $this->value($dailyRow[$metric] ?? null)
                        : '';
                }

                $rows[] = array_map($this->value(...), $fields);
            }
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
