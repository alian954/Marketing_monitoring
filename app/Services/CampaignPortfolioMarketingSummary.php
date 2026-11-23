<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class CampaignPortfolioMarketingSummary
{
    /**
     * @param  Collection<int, SocialEngagementTarget>  $targets
     * @param  array<int, array<string, mixed>>  $marketingSummaries
     * @return array{
     *     target_count: int,
     *     coverage: array<string, array{configured_campaigns: int, available_campaigns: int, fresh_campaigns: int, stale_campaigns: int, missing_campaigns: int, duplicate_mappings_excluded: int}>,
     *     currency_groups: list<array{currency_code: string, ga4_revenue: float, meta_spend: float, google_ads_spend: float, ga4_campaign_count: int, meta_campaign_count: int, google_ads_campaign_count: int, roas: float|null, roas_campaign_count: int}>
     * }
     */
    public function build(Collection $targets, array $marketingSummaries): array
    {
        $sourceDefinitions = [
            'google_analytics' => ['value_key' => 'revenue', 'group_key' => 'ga4_revenue', 'count_key' => 'ga4_campaign_count'],
            'meta_marketing' => ['value_key' => 'spend_amount', 'group_key' => 'meta_spend', 'count_key' => 'meta_campaign_count'],
            'google_ads' => ['value_key' => 'spend_amount', 'group_key' => 'google_ads_spend', 'count_key' => 'google_ads_campaign_count'],
        ];
        $sourceCandidates = array_fill_keys(array_keys($sourceDefinitions), []);
        $roasCandidates = [];

        foreach ($targets as $target) {
            $marketing = $marketingSummaries[$target->getKey()] ?? [];

            foreach ($sourceDefinitions as $source => $definition) {
                $mappingKey = $this->campaignMappingKey($source, $target);

                if ($mappingKey === null) {
                    continue;
                }

                $summary = is_array($marketing[$source] ?? null) ? $marketing[$source] : [];
                $sourceValue = $summary[$definition['value_key']] ?? null;
                $currencyCode = $this->currencyCode($summary['currency_code'] ?? null);

                $sourceCandidates[$source][$mappingKey][] = [
                    'target_id' => (int) $target->getKey(),
                    'status' => is_string($summary['status'] ?? null) ? $summary['status'] : 'missing',
                    'value' => is_numeric($sourceValue) && is_finite((float) $sourceValue) && $currencyCode !== null
                        ? (float) $sourceValue
                        : null,
                    'currency_code' => $currencyCode,
                    'period_days' => $this->periodDays($target),
                ];
            }

            $efficiency = is_array($marketing['efficiency'] ?? null) ? $marketing['efficiency'] : [];
            $googleAnalyticsMappingKey = $this->campaignMappingKey('google_analytics', $target);
            $roasRevenue = $marketing['google_analytics']['revenue'] ?? null;
            $roasRevenueCurrency = $this->currencyCode($marketing['google_analytics']['currency_code'] ?? null);
            $roasSpend = $efficiency['roas_spend_amount'] ?? null;
            $roasSpendCurrency = $this->currencyCode($efficiency['roas_spend_currency'] ?? null);

            if ($googleAnalyticsMappingKey !== null
                && is_numeric($efficiency['roas'] ?? null)
                && is_numeric($roasRevenue)
                && is_finite((float) $roasRevenue)
                && is_numeric($roasSpend)
                && is_finite((float) $roasSpend)
                && (float) $roasSpend > 0
                && $roasRevenueCurrency !== null
                && $roasRevenueCurrency === $roasSpendCurrency) {
                $roasCandidates[$googleAnalyticsMappingKey][] = [
                    'target_id' => (int) $target->getKey(),
                    'status' => $this->roasFreshnessStatus($marketing),
                    'revenue' => (float) $roasRevenue,
                    'spend' => (float) $roasSpend,
                    'currency_code' => $roasRevenueCurrency,
                    'period_days' => $this->periodDays($target),
                ];
            }
        }

        $coverage = [];
        $currencyGroups = [];

        foreach ($sourceDefinitions as $source => $definition) {
            $coverage[$source] = [
                'configured_campaigns' => count($sourceCandidates[$source]),
                'available_campaigns' => 0,
                'fresh_campaigns' => 0,
                'stale_campaigns' => 0,
                'missing_campaigns' => 0,
                'duplicate_mappings_excluded' => 0,
            ];

            foreach ($sourceCandidates[$source] as $candidates) {
                $coverage[$source]['duplicate_mappings_excluded'] += max(0, count($candidates) - 1);
                $candidate = $this->preferredCandidate($candidates);

                if ($candidate['value'] === null || $candidate['currency_code'] === null) {
                    $coverage[$source]['missing_campaigns']++;

                    continue;
                }

                $coverage[$source]['available_campaigns']++;
                if ($candidate['status'] === 'fresh') {
                    $coverage[$source]['fresh_campaigns']++;
                } elseif ($candidate['status'] === 'stale') {
                    $coverage[$source]['stale_campaigns']++;
                }

                $this->ensureCurrencyGroup($currencyGroups, $candidate['currency_code']);
                $currencyGroups[$candidate['currency_code']][$definition['group_key']] += $candidate['value'];
                $currencyGroups[$candidate['currency_code']][$definition['count_key']]++;
            }
        }

        foreach ($roasCandidates as $candidates) {
            $candidate = $this->preferredCandidate($candidates);

            if ($candidate['revenue'] === null
                || $candidate['spend'] === null
                || $candidate['spend'] <= 0
                || $candidate['currency_code'] === null) {
                continue;
            }

            $this->ensureCurrencyGroup($currencyGroups, $candidate['currency_code']);
            $currencyGroups[$candidate['currency_code']]['roas_revenue'] += $candidate['revenue'];
            $currencyGroups[$candidate['currency_code']]['roas_spend'] += $candidate['spend'];
            $currencyGroups[$candidate['currency_code']]['roas_campaign_count']++;
        }

        foreach ($currencyGroups as &$group) {
            $group['roas'] = $group['roas_spend'] > 0
                ? round($group['roas_revenue'] / $group['roas_spend'], 4)
                : null;
            unset($group['roas_revenue'], $group['roas_spend']);

            foreach (['ga4_revenue', 'meta_spend', 'google_ads_spend'] as $amountKey) {
                $group[$amountKey] = round($group[$amountKey], 4);
            }
        }
        unset($group);

        ksort($currencyGroups);

        return [
            'target_count' => $targets->count(),
            'coverage' => $coverage,
            'currency_groups' => array_values($currencyGroups),
        ];
    }

    private function campaignMappingKey(string $source, SocialEngagementTarget $target): ?string
    {
        if ($target->starts_on === null || $target->ends_on === null) {
            return null;
        }

        $identity = match ($source) {
            'google_analytics' => $target->googleAnalyticsAttributionMode() === 'google_ads_auto_tagging'
                ? ['auto_tagging', trim((string) $target->google_ads_campaign_id)]
                : [
                    'manual_utm',
                    $target->googleAnalyticsCampaignName(),
                    $target->googleAnalyticsSourceName(),
                    $target->googleAnalyticsMediumName(),
                ],
            'meta_marketing' => is_string($target->meta_campaign_id) && trim($target->meta_campaign_id) !== ''
                ? ['meta', trim($target->meta_campaign_id)]
                : null,
            'google_ads' => is_string($target->google_ads_campaign_id) && trim($target->google_ads_campaign_id) !== ''
                ? ['google_ads', trim($target->google_ads_campaign_id)]
                : null,
            default => null,
        };

        return $identity === null ? null : hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function preferredCandidate(array $candidates): array
    {
        usort($candidates, static function (array $first, array $second): int {
            $firstAvailable = (array_key_exists('revenue', $first)
                ? $first['revenue'] !== null && $first['spend'] !== null
                : $first['value'] !== null)
                && ($first['currency_code'] ?? null) !== null;
            $secondAvailable = (array_key_exists('revenue', $second)
                ? $second['revenue'] !== null && $second['spend'] !== null
                : $second['value'] !== null)
                && ($second['currency_code'] ?? null) !== null;

            if ($firstAvailable !== $secondAvailable) {
                return $firstAvailable ? -1 : 1;
            }

            $freshnessOrder = self::freshnessPriority($second['status']) <=> self::freshnessPriority($first['status']);

            if ($freshnessOrder !== 0) {
                return $freshnessOrder;
            }

            $periodOrder = $second['period_days'] <=> $first['period_days'];

            return $periodOrder !== 0 ? $periodOrder : $first['target_id'] <=> $second['target_id'];
        });

        return $candidates[0];
    }

    /** @param array<string, mixed> $marketing */
    private function roasFreshnessStatus(array $marketing): string
    {
        $efficiency = is_array($marketing['efficiency'] ?? null) ? $marketing['efficiency'] : [];
        $spendSource = $efficiency['roas_spend_source'] ?? null;
        $spendStatus = match ($spendSource) {
            'meta_marketing' => $marketing['meta_marketing']['status'] ?? null,
            'google_ads' => $marketing['google_ads']['status'] ?? null,
            'manual' => 'fresh',
            default => null,
        };
        $analyticsStatus = $marketing['google_analytics']['status'] ?? null;

        if ($analyticsStatus === 'fresh' && $spendStatus === 'fresh') {
            return 'fresh';
        }

        return $analyticsStatus === 'stale' || $spendStatus === 'stale' ? 'stale' : 'missing';
    }

    private function periodDays(SocialEngagementTarget $target): int
    {
        if ($target->starts_on === null || $target->ends_on === null) {
            return 0;
        }

        return (int) CarbonImmutable::parse($target->starts_on)->diffInDays(CarbonImmutable::parse($target->ends_on));
    }

    private function currencyCode(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return strtoupper(trim($value));
    }

    /** @param array<string, array<string, mixed>> $groups */
    private function ensureCurrencyGroup(array &$groups, string $currencyCode): void
    {
        if (isset($groups[$currencyCode])) {
            return;
        }

        $groups[$currencyCode] = [
            'currency_code' => $currencyCode,
            'ga4_revenue' => 0.0,
            'meta_spend' => 0.0,
            'google_ads_spend' => 0.0,
            'ga4_campaign_count' => 0,
            'meta_campaign_count' => 0,
            'google_ads_campaign_count' => 0,
            'roas_revenue' => 0.0,
            'roas_spend' => 0.0,
            'roas' => null,
            'roas_campaign_count' => 0,
        ];
    }

    private static function freshnessPriority(string $status): int
    {
        return match ($status) {
            'fresh' => 2,
            'stale' => 1,
            default => 0,
        };
    }
}
