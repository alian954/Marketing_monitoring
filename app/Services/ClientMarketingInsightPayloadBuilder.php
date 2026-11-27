<?php

namespace App\Services;

use App\Models\Client;

/**
 * Reduces a client's cross-platform report to the compact facts an AI summary needs.
 *
 * Only aggregated metrics are included: no campaign URLs, account IDs, recipients or credentials.
 */
class ClientMarketingInsightPayloadBuilder
{
    private const MAX_TIMELINE_ENTRIES = 60;

    private const MAX_DAILY_ROWS = 92;

    /**
     * @param  array<string, mixed>  $overview
     * @param  array<string, mixed>  $comparison
     * @param  array{from: string, to: string}  $previousPeriod
     * @param  list<array{date: string, source: string, category_label: string, title: string, note: string|null}>  $timeline
     * @param  array<string, mixed>|null  $budgetPacing
     * @param  array<string, mixed>|null  $storeRevenue
     * @return array<string, mixed>
     */
    public function build(
        Client $client,
        array $overview,
        array $comparison,
        array $previousPeriod,
        array $timeline,
        ?array $budgetPacing,
        ?array $storeRevenue = null,
    ): array {
        $platformLabels = array_map(
            static fn (array $definition): string => $definition['label'],
            ClientCrossPlatformOverview::PAID_SOURCES,
        );
        $currencyGroups = [];

        foreach ($overview['currency_groups'] ?? [] as $group) {
            $platforms = [];

            foreach ($group['platforms'] as $source => $metrics) {
                $platforms[$platformLabels[$source] ?? $source] = $this->metrics($metrics);
            }

            $currencyGroups[] = [
                'currency' => $group['currency_code'],
                'total' => $this->metrics($group['total']),
                'change_vs_previous_period' => $comparison['currency_groups'][$group['currency_code']] ?? null,
                'platforms' => $platforms,
            ];
        }

        $coverage = [];

        foreach ($overview['coverage'] ?? [] as $source) {
            $coverage[$source['label']] = [
                'configured_campaigns' => $source['configured_campaigns'],
                'campaigns_with_data' => $source['campaigns_with_data'],
                'stale_campaigns' => $source['stale_campaigns'],
                'unavailable_campaigns' => $source['unavailable_campaigns'],
            ];
        }

        return [
            'client_name' => $client->name,
            'period' => ['from' => $overview['from'] ?? null, 'to' => $overview['to'] ?? null],
            'previous_period' => $previousPeriod,
            'paid_media_by_currency' => $currencyGroups,
            'daily_spend_primary_currency' => array_slice($overview['daily'] ?? [], -self::MAX_DAILY_ROWS),
            'ga4' => [
                'has_data' => $overview['ga4']['has_data'] ?? false,
                'sessions' => $overview['ga4']['sessions'] ?? null,
                'engaged_sessions' => $overview['ga4']['engaged_sessions'] ?? null,
                'key_events' => $overview['ga4']['key_events'] ?? null,
                'revenue_by_currency' => $overview['ga4']['revenue_groups'] ?? [],
                'change_vs_previous_period' => $comparison['ga4'] ?? null,
            ],
            'attribution_check' => $overview['attribution'] ?? null,
            'data_coverage' => $coverage,
            'monthly_budget_pacing' => $budgetPacing,
            'store_revenue' => $storeRevenue === null || ! $storeRevenue['has_data'] ? null : array_diff_key($storeRevenue, ['daily_revenue' => true, 'has_data' => true]),
            'events' => array_map(
                static fn (array $entry): array => [
                    'date' => $entry['date'],
                    'source' => ClientMarketingTimeline::SOURCE_LABELS[$entry['source']] ?? $entry['source'],
                    'type' => $entry['category_label'],
                    'title' => $entry['title'],
                    'note' => $entry['note'],
                ],
                array_slice($timeline, 0, self::MAX_TIMELINE_ENTRIES),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function metrics(array $metrics): array
    {
        return array_intersect_key($metrics, array_flip([
            'spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'campaign_count', 'active_days',
            'spend_share_percent', 'ctr_percent', 'cost_per_click', 'cost_per_conversion', 'roas',
        ]));
    }
}
