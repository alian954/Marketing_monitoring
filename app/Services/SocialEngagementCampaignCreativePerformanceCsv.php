<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class SocialEngagementCampaignCreativePerformanceCsv
{
    /**
     * @param  resource  $stream
     * @param  list<array<string, mixed>>  $groups
     */
    public function write(mixed $stream, array $groups): void
    {
        fputcsv($stream, [
            'utm_content',
            'tracked_posts',
            'complete_read_posts',
            'public_interaction_total_from_complete_reads',
            'average_public_interactions_per_complete_post',
            'latest_capture_at_asia_taipei',
            'ga4_attribution_status',
            'ga4_attribution_status_label',
            'ga4_sessions',
            'ga4_engaged_sessions',
            'ga4_engagement_rate',
            'ga4_key_events',
            'ga4_total_revenue',
            'ga4_currency_code',
            'ga4_revenue_per_public_interaction_reference',
            'revenue_per_public_interaction_currency',
            'revenue_per_public_interaction_status',
            'revenue_per_public_interaction_status_label',
        ], ',', '"', '');

        foreach ($groups as $group) {
            $attribution = is_array($group['google_analytics_attribution'] ?? null)
                ? $group['google_analytics_attribution']
                : [];
            $latestCaptureAt = $group['latest_capture_at'] ?? null;
            $row = [
                $this->value($group['utm_content'] ?? null),
                $this->value($group['post_count'] ?? null),
                $this->value($group['complete_read_count'] ?? null),
                $this->value($group['public_interaction_total'] ?? null),
                is_numeric($group['average_public_interactions'] ?? null)
                    ? number_format((float) $group['average_public_interactions'], 2, '.', '')
                    : '',
                $latestCaptureAt instanceof CarbonImmutable
                    ? $latestCaptureAt->timezone('Asia/Taipei')->format('Y-m-d H:i:s')
                    : '',
                $this->value($attribution['status'] ?? null),
                $this->value($attribution['status_label'] ?? null),
                $this->value($attribution['sessions'] ?? null),
                $this->value($attribution['engaged_sessions'] ?? null),
                $this->value($attribution['engagement_rate'] ?? null),
                $this->value($attribution['key_events'] ?? null),
                $this->value($attribution['total_revenue'] ?? null),
                $this->value($attribution['currency_code'] ?? null),
                is_numeric($group['revenue_per_public_interaction'] ?? null)
                    ? number_format((float) $group['revenue_per_public_interaction'], 4, '.', '')
                    : '',
                $this->value($group['revenue_per_public_interaction_currency'] ?? null),
                $this->value($group['revenue_per_public_interaction_status'] ?? null),
                $this->value($group['revenue_per_public_interaction_status_label'] ?? null),
            ];

            fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $row), ',', '"', '');
        }
    }

    private function value(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
