<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Combines one client's Google Ads, Meta Ads and GA4 daily data for a reporting period.
 *
 * Several campaigns in this system can point at the same ad-platform campaign, so daily rows are
 * merged by the platform campaign identity and date before summing to avoid counting spend twice.
 */
class ClientCrossPlatformOverview
{
    public const PAID_SOURCES = [
        'google_ads' => [
            'label' => 'Google Ads',
            'conversions_key' => 'conversions',
            'conversion_value_key' => 'conversions_value',
            'conversion_label' => '轉換',
        ],
        'meta_marketing' => [
            'label' => 'Meta Ads',
            'conversions_key' => 'omni_purchase_count',
            'conversion_value_key' => 'omni_purchase_value',
            'conversion_label' => '購買',
        ],
    ];

    private const CHART_LEFT = 48.0;

    private const CHART_RIGHT = 576.0;

    private const CHART_TOP = 24.0;

    private const CHART_BOTTOM = 154.0;

    public function __construct(private readonly CampaignPortfolioMarketingSummary $portfolioSummary) {}

    /**
     * @param  Collection<int, SocialEngagementTarget>  $targets
     * @param  array<int, array<string, mixed>>  $marketingSummaries
     * @return array{
     *     from: string,
     *     to: string,
     *     currency_groups: list<array{currency_code: string, platforms: array<string, array<string, mixed>>, total: array<string, mixed>}>,
     *     primary_currency_code: string|null,
     *     ga4: array{sessions: int, engaged_sessions: int, key_events: float, revenue_groups: array<string, float>, campaign_count: int, has_data: bool},
     *     attribution: array{platform_conversions: float, ga4_key_events: float|null, ratio: float|null, status: string},
     *     coverage: array<string, array{label: string, configured_campaigns: int, campaigns_with_data: int, stale_campaigns: int, unavailable_campaigns: int, duplicate_mappings_excluded: int}>,
     *     daily: list<array{date: string, google_ads_spend: float, meta_marketing_spend: float, conversions: float}>
     * }
     */
    public function build(Collection $targets, array $marketingSummaries, string $fromOn, string $toOn): array
    {
        $sources = [...array_keys(self::PAID_SOURCES), 'google_analytics'];
        $coverage = [];
        $rowsByIdentity = array_fill_keys($sources, []);

        foreach ($sources as $source) {
            $coverage[$source] = [
                'label' => self::PAID_SOURCES[$source]['label'] ?? 'GA4',
                'configured_campaigns' => 0,
                'campaigns_with_data' => 0,
                'stale_campaigns' => 0,
                'unavailable_campaigns' => 0,
                'duplicate_mappings_excluded' => 0,
            ];
        }

        foreach ($targets->sortBy(static fn (SocialEngagementTarget $target): int => (int) $target->getKey()) as $target) {
            if ($target->starts_on === null || $target->ends_on === null) {
                continue;
            }

            $periodStartOn = max($fromOn, $target->starts_on->toDateString());
            $periodEndOn = min($toOn, $target->ends_on->toDateString());

            if ($periodStartOn > $periodEndOn) {
                continue;
            }

            $marketing = is_array($marketingSummaries[$target->getKey()] ?? null) ? $marketingSummaries[$target->getKey()] : [];
            $dailyPerformance = is_array($marketing['daily_performance'] ?? null) ? $marketing['daily_performance'] : [];

            foreach ($sources as $source) {
                $mappingKey = $this->portfolioSummary->campaignMappingKey($source, $target);
                $status = is_array($marketing[$source] ?? null) ? (string) ($marketing[$source]['status'] ?? '') : '';

                if ($mappingKey === null || $status === 'not_configured') {
                    continue;
                }

                $coverage[$source]['configured_campaigns']++;

                if (array_key_exists($mappingKey, $rowsByIdentity[$source])) {
                    $coverage[$source]['duplicate_mappings_excluded']++;
                    $rowsByIdentity[$source][$mappingKey]['start_on'] = min($rowsByIdentity[$source][$mappingKey]['start_on'], $periodStartOn);
                    $rowsByIdentity[$source][$mappingKey]['end_on'] = max($rowsByIdentity[$source][$mappingKey]['end_on'], $periodEndOn);

                    continue;
                }

                if ($status === 'stale') {
                    $coverage[$source]['stale_campaigns']++;
                } elseif (! in_array($status, ['fresh', 'stale'], true)) {
                    $coverage[$source]['unavailable_campaigns']++;
                }

                $dailySource = is_array($dailyPerformance[$source] ?? null) ? $dailyPerformance[$source] : [];
                $rowsByIdentity[$source][$mappingKey] = [
                    'currency_code' => $this->currencyCode($dailySource['currency_code'] ?? null),
                    'rows' => is_array($dailySource['rows'] ?? null) ? $dailySource['rows'] : [],
                    'start_on' => $periodStartOn,
                    'end_on' => $periodEndOn,
                ];
            }
        }

        $groups = [];
        $dailyByCurrency = [];
        $platformConversions = 0.0;

        foreach (self::PAID_SOURCES as $source => $definition) {
            foreach ($rowsByIdentity[$source] as $mapping) {
                $rows = $this->rowsWithinPeriod($mapping['rows'], $mapping['start_on'], $mapping['end_on']);

                if ($rows === [] || $mapping['currency_code'] === null) {
                    continue;
                }

                $coverage[$source]['campaigns_with_data']++;
                $currencyCode = $mapping['currency_code'];
                $groups[$currencyCode] ??= array_fill_keys(array_keys(self::PAID_SOURCES), $this->emptyPaidMetrics());
                $groups[$currencyCode][$source]['campaign_count']++;

                foreach ($rows as $row) {
                    $spend = $this->number($row['spend_amount'] ?? null);
                    $conversions = $this->number($row[$definition['conversions_key']] ?? null);
                    $groups[$currencyCode][$source]['spend'] += $spend;
                    $groups[$currencyCode][$source]['impressions'] += (int) $this->number($row['impressions'] ?? null);
                    $groups[$currencyCode][$source]['clicks'] += (int) $this->number($row['clicks'] ?? null);
                    $groups[$currencyCode][$source]['conversions'] += $conversions;
                    $groups[$currencyCode][$source]['conversion_value'] += $this->number($row[$definition['conversion_value_key']] ?? null);
                    $groups[$currencyCode][$source]['active_dates'][$row['date']] = true;
                    $platformConversions += $conversions;

                    $dailyByCurrency[$currencyCode][$row['date']] ??= ['google_ads_spend' => 0.0, 'meta_marketing_spend' => 0.0, 'conversions' => 0.0];
                    $dailyByCurrency[$currencyCode][$row['date']][$source.'_spend'] += $spend;
                    $dailyByCurrency[$currencyCode][$row['date']]['conversions'] += $conversions;
                }
            }
        }

        $currencyGroups = [];

        foreach ($groups as $currencyCode => $platforms) {
            $total = $this->emptyPaidMetrics();

            foreach ($platforms as $metrics) {
                foreach (['spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'campaign_count'] as $metric) {
                    $total[$metric] += $metrics[$metric];
                }

                $total['active_dates'] += $metrics['active_dates'];
            }

            $finalizedPlatforms = [];

            foreach ($platforms as $source => $metrics) {
                $finalizedPlatforms[$source] = $this->finalizePaidMetrics($metrics, $total['spend']);
            }

            $currencyGroups[] = [
                'currency_code' => (string) $currencyCode,
                'platforms' => $finalizedPlatforms,
                'total' => $this->finalizePaidMetrics($total, $total['spend']),
            ];
        }

        usort($currencyGroups, static fn (array $first, array $second): int => $second['total']['spend'] <=> $first['total']['spend']
            ?: strcmp($first['currency_code'], $second['currency_code']));
        $primaryCurrencyCode = $currencyGroups[0]['currency_code'] ?? null;
        $ga4 = $this->ga4Summary($rowsByIdentity['google_analytics'], $coverage['google_analytics']);
        $daily = [];

        if ($primaryCurrencyCode !== null) {
            foreach ($this->datesBetween($fromOn, $toOn) as $date) {
                $daily[] = ['date' => $date] + ($dailyByCurrency[$primaryCurrencyCode][$date] ?? [
                    'google_ads_spend' => 0.0,
                    'meta_marketing_spend' => 0.0,
                    'conversions' => 0.0,
                ]);
            }
        }

        return [
            'from' => $fromOn,
            'to' => $toOn,
            'currency_groups' => $currencyGroups,
            'primary_currency_code' => $primaryCurrencyCode,
            'ga4' => $ga4,
            'attribution' => $this->attribution($platformConversions, $ga4),
            'coverage' => $coverage,
            'daily' => $daily,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array{currency_groups: array<string, array<string, array{delta: float, percent_change: float|null}|null>>, ga4: array<string, array{delta: float, percent_change: float|null}|null>}
     */
    public function compare(array $current, array $previous): array
    {
        $previousGroups = collect($previous['currency_groups'])->keyBy('currency_code');
        $currencyGroups = [];

        foreach ($current['currency_groups'] as $group) {
            $previousTotal = $previousGroups->get($group['currency_code'])['total'] ?? null;

            foreach (['spend', 'conversions', 'conversion_value', 'roas', 'cost_per_conversion', 'clicks'] as $metric) {
                $currencyGroups[$group['currency_code']][$metric] = $this->change(
                    $group['total'][$metric],
                    is_array($previousTotal) ? $previousTotal[$metric] : null,
                );
            }
        }

        $ga4 = [];

        foreach (['sessions', 'key_events'] as $metric) {
            $ga4[$metric] = $current['ga4']['has_data'] && $previous['ga4']['has_data']
                ? $this->change((float) $current['ga4'][$metric], (float) $previous['ga4'][$metric])
                : null;
        }

        return ['currency_groups' => $currencyGroups, 'ga4' => $ga4];
    }

    /**
     * Builds a stacked daily spend bar chart for the primary currency.
     *
     * @param  list<array{date: string, google_ads_spend: float, meta_marketing_spend: float, conversions: float}>  $daily
     * @return array{maximum_label: string, bars: list<array{date: string, label: string, x: float, width: float, google_ads: array{y: float, height: float}, meta_marketing: array{y: float, height: float}}>, axis_labels: list<array{x: float, label: string}>}|null
     */
    public function chart(array $daily): ?array
    {
        $maximum = 0.0;

        foreach ($daily as $day) {
            $maximum = max($maximum, $day['google_ads_spend'] + $day['meta_marketing_spend']);
        }

        if ($daily === [] || $maximum <= 0) {
            return null;
        }

        $slotWidth = (self::CHART_RIGHT - self::CHART_LEFT) / count($daily);
        $barWidth = max(1.0, min(18.0, $slotWidth * 0.7));
        $plotHeight = self::CHART_BOTTOM - self::CHART_TOP;
        $bars = [];
        $axisLabels = [];
        $labelEvery = max(1, (int) ceil(count($daily) / 6));

        foreach ($daily as $index => $day) {
            $x = self::CHART_LEFT + $slotWidth * $index + ($slotWidth - $barWidth) / 2;
            $googleHeight = $day['google_ads_spend'] / $maximum * $plotHeight;
            $metaHeight = $day['meta_marketing_spend'] / $maximum * $plotHeight;
            $bars[] = [
                'date' => $day['date'],
                'label' => $day['date'].' Google Ads '.number_format($day['google_ads_spend'], 2).'、Meta Ads '.number_format($day['meta_marketing_spend'], 2),
                'x' => round($x, 2),
                'width' => round($barWidth, 2),
                'google_ads' => ['y' => round(self::CHART_BOTTOM - $googleHeight, 2), 'height' => round($googleHeight, 2)],
                'meta_marketing' => ['y' => round(self::CHART_BOTTOM - $googleHeight - $metaHeight, 2), 'height' => round($metaHeight, 2)],
            ];

            if ($index % $labelEvery === 0) {
                $axisLabels[] = [
                    'x' => round($x + $barWidth / 2, 2),
                    'label' => CarbonImmutable::createFromFormat('!Y-m-d', $day['date'])->format('n/j'),
                ];
            }
        }

        return [
            'maximum_label' => number_format($maximum, 0),
            'bars' => $bars,
            'axis_labels' => $axisLabels,
        ];
    }

    /**
     * @param  array<string, array{currency_code: string|null, rows: array<int, mixed>, start_on: string, end_on: string}>  $mappings
     * @param  array{label: string, configured_campaigns: int, campaigns_with_data: int, stale_campaigns: int, unavailable_campaigns: int, duplicate_mappings_excluded: int}  $coverage
     * @return array{sessions: int, engaged_sessions: int, key_events: float, revenue_groups: array<string, float>, campaign_count: int, has_data: bool}
     */
    private function ga4Summary(array $mappings, array &$coverage): array
    {
        $summary = ['sessions' => 0, 'engaged_sessions' => 0, 'key_events' => 0.0, 'revenue_groups' => [], 'campaign_count' => 0, 'has_data' => false];

        foreach ($mappings as $mapping) {
            $rows = $this->rowsWithinPeriod($mapping['rows'], $mapping['start_on'], $mapping['end_on']);

            if ($rows === []) {
                continue;
            }

            $coverage['campaigns_with_data']++;
            $summary['campaign_count']++;
            $summary['has_data'] = true;

            foreach ($rows as $row) {
                $summary['sessions'] += (int) $this->number($row['sessions'] ?? null);
                $summary['engaged_sessions'] += (int) $this->number($row['engaged_sessions'] ?? null);
                $summary['key_events'] += $this->number($row['key_events'] ?? null);

                if ($mapping['currency_code'] !== null && is_numeric($row['total_revenue'] ?? null)) {
                    $summary['revenue_groups'][$mapping['currency_code']] = ($summary['revenue_groups'][$mapping['currency_code']] ?? 0.0)
                        + (float) $row['total_revenue'];
                }
            }
        }

        ksort($summary['revenue_groups']);

        return $summary;
    }

    /**
     * @param  array{key_events: float, has_data: bool}  $ga4
     * @return array{platform_conversions: float, ga4_key_events: float|null, ratio: float|null, status: string}
     */
    private function attribution(float $platformConversions, array $ga4): array
    {
        if (! $ga4['has_data']) {
            return ['platform_conversions' => $platformConversions, 'ga4_key_events' => null, 'ratio' => null, 'status' => 'ga4_unavailable'];
        }

        if ($ga4['key_events'] <= 0) {
            return ['platform_conversions' => $platformConversions, 'ga4_key_events' => $ga4['key_events'], 'ratio' => null, 'status' => 'ga4_zero'];
        }

        $ratio = round($platformConversions / $ga4['key_events'], 2);

        return [
            'platform_conversions' => $platformConversions,
            'ga4_key_events' => $ga4['key_events'],
            'ratio' => $ratio,
            'status' => match (true) {
                $ratio > 1.2 => 'platform_higher',
                $ratio < 0.8 => 'platform_lower',
                default => 'aligned',
            },
        ];
    }

    /** @return array{spend: float, impressions: int, clicks: int, conversions: float, conversion_value: float, campaign_count: int, active_dates: array<string, true>} */
    private function emptyPaidMetrics(): array
    {
        return ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'conversions' => 0.0, 'conversion_value' => 0.0, 'campaign_count' => 0, 'active_dates' => []];
    }

    /**
     * @param  array{spend: float, impressions: int, clicks: int, conversions: float, conversion_value: float, campaign_count: int, active_dates: array<string, true>}  $metrics
     * @return array<string, mixed>
     */
    private function finalizePaidMetrics(array $metrics, float $totalSpend): array
    {
        $activeDays = count($metrics['active_dates']);
        unset($metrics['active_dates']);

        return $metrics + [
            'active_days' => $activeDays,
            'spend_share_percent' => $totalSpend > 0 ? round($metrics['spend'] / $totalSpend * 100, 1) : null,
            'ctr_percent' => $metrics['impressions'] > 0 ? round($metrics['clicks'] / $metrics['impressions'] * 100, 2) : null,
            'cost_per_click' => $metrics['clicks'] > 0 ? round($metrics['spend'] / $metrics['clicks'], 2) : null,
            'cost_per_conversion' => $metrics['conversions'] > 0 ? round($metrics['spend'] / $metrics['conversions'], 2) : null,
            'roas' => $metrics['spend'] > 0 ? round($metrics['conversion_value'] / $metrics['spend'], 2) : null,
        ];
    }

    /** @return array{delta: float, percent_change: float|null}|null */
    private function change(?float $current, ?float $previous): ?array
    {
        if ($current === null || $previous === null) {
            return null;
        }

        return [
            'delta' => round($current - $previous, 2),
            'percent_change' => abs($previous) > 0.0 ? round(($current - $previous) / abs($previous) * 100, 1) : null,
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function rowsWithinPeriod(array $rows, string $startOn, string $endOn): array
    {
        $rowsByDate = [];

        foreach ($rows as $row) {
            $date = is_array($row) ? ($row['date'] ?? null) : null;

            if (is_string($date) && $date >= $startOn && $date <= $endOn && ! array_key_exists($date, $rowsByDate)) {
                $rowsByDate[$date] = $row;
            }
        }

        ksort($rowsByDate);

        return array_values($rowsByDate);
    }

    /** @return list<string> */
    private function datesBetween(string $fromOn, string $toOn): array
    {
        $dates = [];
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $fromOn, 'Asia/Taipei');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $toOn, 'Asia/Taipei');

        while ($date->lessThanOrEqualTo($end)) {
            $dates[] = $date->toDateString();
            $date = $date->addDay();
        }

        return $dates;
    }

    private function number(mixed $value): float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : 0.0;
    }

    private function currencyCode(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : null;
    }
}
