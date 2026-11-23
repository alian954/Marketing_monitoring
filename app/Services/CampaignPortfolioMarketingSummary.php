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
     *     currency_groups: list<array{currency_code: string, ga4_revenue: float, meta_spend: float, google_ads_spend: float, ga4_campaign_count: int, meta_campaign_count: int, google_ads_campaign_count: int, roas: float|null, roas_campaign_count: int}>,
     *     budget_coverage: array{configured_campaigns: int, spend_campaigns: int, fresh_spend_campaigns: int, stale_spend_campaigns: int, duplicate_mappings_excluded: int, pacing_campaigns: int, projected_campaigns: int, projected_over_budget_campaigns: int, projected_under_budget_campaigns: int},
     *     budget_currency_groups: list<array{currency_code: string, budget_amount: float, budget_campaign_count: int, current_spend_amount: float, current_spend_campaign_count: int, pacing_difference_amount: float, pacing_campaign_count: int, projected_spend_amount: float, projected_campaign_count: int, projected_over_budget_amount: float, projected_under_budget_amount: float, projected_over_budget_campaign_count: int, projected_under_budget_campaign_count: int}>,
     *     campaign_ranks: array<int, array{rank: int, currency_code: string, roas: float}>
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
        $budgetParents = [];
        $budgetMappingMembers = [];
        $budgetCandidatesByTarget = [];

        foreach ($targets as $target) {
            $marketing = $marketingSummaries[$target->getKey()] ?? [];

            $budgetAmount = $target->campaign_budget_amount;
            $budgetCurrency = $this->currencyCode($target->campaign_budget_currency);

            if (is_numeric($budgetAmount) && is_finite((float) $budgetAmount) && $budgetCurrency !== null) {
                $targetId = (int) $target->getKey();
                $efficiency = is_array($marketing['efficiency'] ?? null) ? $marketing['efficiency'] : [];
                $spendSource = $efficiency['ad_spend_source'] ?? null;
                $spendStatus = $this->budgetSpendFreshnessStatus($marketing, is_string($spendSource) ? $spendSource : null);
                $spendAmount = $efficiency['ad_spend_amount'] ?? null;
                $spendCurrency = $this->currencyCode($efficiency['ad_spend_currency'] ?? null);
                $hasComparableSpend = is_numeric($spendAmount)
                    && is_finite((float) $spendAmount)
                    && $spendCurrency === $budgetCurrency;

                $budgetParents[$targetId] = $targetId;
                $budgetCandidatesByTarget[$targetId] = [
                    'target_id' => $targetId,
                    'budget_amount' => (float) $budgetAmount,
                    'currency_code' => $budgetCurrency,
                    'spend_amount' => $hasComparableSpend ? (float) $spendAmount : null,
                    'spend_status' => $hasComparableSpend && is_string($spendStatus) ? $spendStatus : 'missing',
                    'pacing_difference_amount' => $hasComparableSpend && is_numeric($efficiency['budget_pacing_difference_amount'] ?? null)
                        ? (float) $efficiency['budget_pacing_difference_amount']
                        : null,
                    'projected_spend_amount' => $hasComparableSpend && is_numeric($efficiency['budget_projected_spend_amount'] ?? null)
                        ? (float) $efficiency['budget_projected_spend_amount']
                        : null,
                    'projected_overrun_amount' => $hasComparableSpend && is_numeric($efficiency['budget_projected_overrun_amount'] ?? null)
                        ? (float) $efficiency['budget_projected_overrun_amount']
                        : null,
                    'period_days' => $this->periodDays($target),
                ];

                foreach ($this->budgetMappingKeys($target) as $mappingKey) {
                    $budgetMappingMembers[$mappingKey][] = $targetId;
                }
            }

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
                    'tag' => $target->tag,
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
        $budgetCoverage = [
            'configured_campaigns' => 0,
            'spend_campaigns' => 0,
            'fresh_spend_campaigns' => 0,
            'stale_spend_campaigns' => 0,
            'duplicate_mappings_excluded' => 0,
            'pacing_campaigns' => 0,
            'projected_campaigns' => 0,
            'projected_over_budget_campaigns' => 0,
            'projected_under_budget_campaigns' => 0,
        ];
        $budgetCurrencyGroups = [];
        $rankingCandidates = [];

        foreach ($budgetMappingMembers as $targetIds) {
            $this->unionBudgetTargets($budgetParents, $targetIds);
        }

        $budgetCandidatesByGroup = [];

        foreach ($budgetCandidatesByTarget as $targetId => $candidate) {
            $root = $this->budgetRoot($budgetParents, (int) $targetId);
            $budgetCandidatesByGroup[$root][] = $candidate;
        }

        foreach ($budgetCandidatesByGroup as $candidates) {
            $budgetCoverage['duplicate_mappings_excluded'] += max(0, count($candidates) - 1);
            $candidate = $this->preferredBudgetCandidate($candidates);
            $currencyCode = $candidate['currency_code'];
            $budgetCoverage['configured_campaigns']++;

            if (! isset($budgetCurrencyGroups[$currencyCode])) {
                $budgetCurrencyGroups[$currencyCode] = [
                    'currency_code' => $currencyCode,
                    'budget_amount' => 0.0,
                    'budget_campaign_count' => 0,
                    'current_spend_amount' => 0.0,
                    'current_spend_campaign_count' => 0,
                    'pacing_difference_amount' => 0.0,
                    'pacing_campaign_count' => 0,
                    'projected_spend_amount' => 0.0,
                    'projected_campaign_count' => 0,
                    'projected_over_budget_amount' => 0.0,
                    'projected_under_budget_amount' => 0.0,
                    'projected_over_budget_campaign_count' => 0,
                    'projected_under_budget_campaign_count' => 0,
                ];
            }

            $budgetCurrencyGroups[$currencyCode]['budget_amount'] += $candidate['budget_amount'];
            $budgetCurrencyGroups[$currencyCode]['budget_campaign_count']++;

            if ($candidate['spend_amount'] !== null) {
                $budgetCoverage['spend_campaigns']++;
                if ($candidate['spend_status'] === 'fresh') {
                    $budgetCoverage['fresh_spend_campaigns']++;
                } elseif ($candidate['spend_status'] === 'stale') {
                    $budgetCoverage['stale_spend_campaigns']++;
                }
                $budgetCurrencyGroups[$currencyCode]['current_spend_amount'] += $candidate['spend_amount'];
                $budgetCurrencyGroups[$currencyCode]['current_spend_campaign_count']++;
            }

            if ($candidate['pacing_difference_amount'] !== null) {
                $budgetCoverage['pacing_campaigns']++;
                $budgetCurrencyGroups[$currencyCode]['pacing_difference_amount'] += $candidate['pacing_difference_amount'];
                $budgetCurrencyGroups[$currencyCode]['pacing_campaign_count']++;
            }

            if ($candidate['projected_spend_amount'] !== null) {
                $budgetCoverage['projected_campaigns']++;
                $budgetCurrencyGroups[$currencyCode]['projected_spend_amount'] += $candidate['projected_spend_amount'];
                $budgetCurrencyGroups[$currencyCode]['projected_campaign_count']++;
            }

            if ($candidate['projected_overrun_amount'] !== null && $candidate['projected_overrun_amount'] > 0) {
                $budgetCoverage['projected_over_budget_campaigns']++;
                $budgetCurrencyGroups[$currencyCode]['projected_over_budget_amount'] += $candidate['projected_overrun_amount'];
                $budgetCurrencyGroups[$currencyCode]['projected_over_budget_campaign_count']++;
            } elseif ($candidate['projected_overrun_amount'] !== null && $candidate['projected_overrun_amount'] < 0) {
                $budgetCoverage['projected_under_budget_campaigns']++;
                $budgetCurrencyGroups[$currencyCode]['projected_under_budget_amount'] += abs($candidate['projected_overrun_amount']);
                $budgetCurrencyGroups[$currencyCode]['projected_under_budget_campaign_count']++;
            }
        }

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
            $rankingCandidates[$candidate['currency_code']][] = [
                'target_id' => $candidate['target_id'],
                'tag' => $candidate['tag'],
                'roas' => round($candidate['revenue'] / $candidate['spend'], 4),
            ];
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

        foreach ($budgetCurrencyGroups as &$group) {
            foreach ([
                'budget_amount',
                'current_spend_amount',
                'pacing_difference_amount',
                'projected_spend_amount',
                'projected_over_budget_amount',
                'projected_under_budget_amount',
            ] as $amountKey) {
                $group[$amountKey] = round($group[$amountKey], 4);
            }
        }
        unset($group);

        ksort($currencyGroups);
        ksort($budgetCurrencyGroups);
        $campaignRanks = [];

        foreach ($rankingCandidates as $currencyCode => $candidates) {
            usort($candidates, static function (array $first, array $second): int {
                $roasOrder = $second['roas'] <=> $first['roas'];

                return $roasOrder !== 0
                    ? $roasOrder
                    : (strcasecmp($first['tag'], $second['tag']) ?: ($first['target_id'] <=> $second['target_id']));
            });
            $rank = 0;
            $position = 0;
            $previousRoas = null;

            foreach ($candidates as $candidate) {
                $position++;

                if ($previousRoas === null || $candidate['roas'] !== $previousRoas) {
                    $rank = $position;
                }

                $campaignRanks[$candidate['target_id']] = [
                    'rank' => $rank,
                    'currency_code' => $currencyCode,
                    'roas' => $candidate['roas'],
                ];
                $previousRoas = $candidate['roas'];
            }
        }

        ksort($campaignRanks);

        return [
            'target_count' => $targets->count(),
            'coverage' => $coverage,
            'currency_groups' => array_values($currencyGroups),
            'budget_coverage' => $budgetCoverage,
            'budget_currency_groups' => array_values($budgetCurrencyGroups),
            'campaign_ranks' => $campaignRanks,
        ];
    }

    /** @return list<string> */
    private function budgetMappingKeys(SocialEngagementTarget $target): array
    {
        $keys = [];

        foreach ([
            'meta' => $target->meta_campaign_id,
            'google_ads' => $target->google_ads_campaign_id,
        ] as $source => $campaignId) {
            if (is_string($campaignId) && trim($campaignId) !== '') {
                $keys[] = $source.':'.trim($campaignId).':'.strtoupper(trim((string) $target->campaign_budget_currency));
            }
        }

        return $keys;
    }

    /** @param array<int, int> $parents
     * @param  list<int>  $targetIds
     */
    private function unionBudgetTargets(array &$parents, array $targetIds): void
    {
        if (count($targetIds) < 2) {
            return;
        }

        $firstRoot = $this->budgetRoot($parents, $targetIds[0]);

        foreach (array_slice($targetIds, 1) as $targetId) {
            $targetRoot = $this->budgetRoot($parents, $targetId);

            if ($targetRoot !== $firstRoot) {
                $parents[$targetRoot] = $firstRoot;
            }
        }
    }

    /** @param array<int, int> $parents */
    private function budgetRoot(array &$parents, int $targetId): int
    {
        $root = $targetId;

        while ($parents[$root] !== $root) {
            $root = $parents[$root];
        }

        while ($parents[$targetId] !== $targetId) {
            $nextTargetId = $parents[$targetId];
            $parents[$targetId] = $root;
            $targetId = $nextTargetId;
        }

        return $root;
    }

    /** @param list<array<string, mixed>> $candidates
     * @return array{target_id: int, budget_amount: float, currency_code: string, spend_amount: float|null, spend_status: string, pacing_difference_amount: float|null, projected_spend_amount: float|null, projected_overrun_amount: float|null, period_days: int}
     */
    private function preferredBudgetCandidate(array $candidates): array
    {
        usort($candidates, static function (array $first, array $second): int {
            $spendOrder = ($second['spend_amount'] !== null) <=> ($first['spend_amount'] !== null);

            if ($spendOrder !== 0) {
                return $spendOrder;
            }

            $freshnessOrder = self::freshnessPriority($second['spend_status']) <=> self::freshnessPriority($first['spend_status']);

            if ($freshnessOrder !== 0) {
                return $freshnessOrder;
            }

            $periodOrder = $second['period_days'] <=> $first['period_days'];

            return $periodOrder !== 0 ? $periodOrder : $first['target_id'] <=> $second['target_id'];
        });

        return $candidates[0];
    }

    /** @param array<string, mixed> $marketing */
    private function budgetSpendFreshnessStatus(array $marketing, ?string $spendSource): string
    {
        if ($spendSource === 'manual') {
            return 'fresh';
        }

        $sources = match ($spendSource) {
            'meta_marketing' => ['meta_marketing'],
            'google_ads' => ['google_ads'],
            'combined_paid_ads' => ['meta_marketing', 'google_ads'],
            default => [],
        };
        $statuses = array_values(array_filter(array_map(
            static fn (string $source): mixed => $marketing[$source]['status'] ?? null,
            $sources,
        ), 'is_string'));

        if (count($statuses) === count($sources) && $statuses !== [] && count(array_filter($statuses, static fn (string $status): bool => $status === 'fresh')) === count($statuses)) {
            return 'fresh';
        }

        return in_array('stale', $statuses, true) ? 'stale' : 'missing';
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
