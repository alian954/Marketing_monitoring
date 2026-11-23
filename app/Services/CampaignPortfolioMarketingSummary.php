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
     *     native_roas_groups: list<array{source: string, source_label: string, currency_code: string, conversion_value_amount: float, matched_spend_amount: float, roas: float|null, campaign_count: int, comparable_campaign_count: int, fresh_campaign_count: int, stale_campaign_count: int, missing_campaign_count: int, duplicate_mappings_excluded: int}>,
     *     native_roas_coverage: array<string, array{configured_campaigns: int, comparable_campaigns: int, fresh_campaigns: int, stale_campaigns: int, missing_campaigns: int, duplicate_mappings_excluded: int}>,
     *     ga4_business_goal_coverage: array{configured_campaigns: int, available_campaigns: int, unavailable_campaigns: int, ahead_campaigns: int, behind_campaigns: int, on_track_campaigns: int, not_started_campaigns: int, completed_campaigns: int, data_unavailable_campaigns: int},
     *     budget_coverage: array{configured_campaigns: int, spend_campaigns: int, fresh_spend_campaigns: int, stale_spend_campaigns: int, duplicate_mappings_excluded: int, pacing_campaigns: int, projected_campaigns: int, projected_over_budget_campaigns: int, projected_under_budget_campaigns: int},
     *     budget_currency_groups: list<array{currency_code: string, budget_amount: float, budget_campaign_count: int, current_spend_amount: float, current_spend_campaign_count: int, pacing_difference_amount: float, pacing_campaign_count: int, projected_spend_amount: float, projected_campaign_count: int, projected_over_budget_amount: float, projected_under_budget_amount: float, projected_over_budget_campaign_count: int, projected_under_budget_campaign_count: int}>,
     *     budget_risk_rows: list<array{target_id: int, tag: string, currency_code: string, spend_source_label: string, freshness_status: string, freshness_label: string, spend_amount: float, budget_amount: float, projected_spend_amount: float, projected_overrun_amount: float, projected_overrun_percent: float, status: string, status_label: string, starts_on: string, ends_on: string}>,
     *     mapping_audit_rows: list<array{type: string, type_label: string, target_ids: list<int>, target_tags: list<string>, merged_count: int, mappings: list<array{source_label: string, campaign_id: string|null, attribution_mode: string|null, campaign_name: string|null, source_name: string|null, medium_name: string|null, preferred_tag: string, freshness_status: string, freshness_label: string}>}>,
     *     interaction_cost_groups: list<array{source: string, source_label: string, currency_code: string, comparable_spend_amount: float, public_interactions: int, cost_per_public_interaction: float|null, campaign_count: int, fresh_campaign_count: int, stale_campaign_count: int, comparable_campaign_count: int, available_post_count: int, tracked_post_count: int, coverage_percent: float|null}>,
     *     campaign_ranks: array<int, array{rank: int, currency_code: string, roas: float}>,
     *     campaign_interaction_cost_ranks: array<int, array<string, array{rank: int, source: string, source_label: string, currency_code: string, cost_per_public_interaction: float, status: string}>>
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
        $interactionCostCandidates = ['meta_marketing' => [], 'google_ads' => [], 'manual' => []];
        $roasCandidates = [];
        $nativeRoasCandidates = ['meta_marketing' => [], 'google_ads' => []];
        $ga4BusinessGoalCoverage = [
            'configured_campaigns' => 0,
            'available_campaigns' => 0,
            'unavailable_campaigns' => 0,
            'ahead_campaigns' => 0,
            'behind_campaigns' => 0,
            'on_track_campaigns' => 0,
            'not_started_campaigns' => 0,
            'completed_campaigns' => 0,
            'data_unavailable_campaigns' => 0,
        ];
        $budgetParents = [];
        $budgetMappingMembers = [];
        $budgetCandidatesByTarget = [];

        foreach ($targets as $target) {
            $marketing = $marketingSummaries[$target->getKey()] ?? [];
            $ga4BusinessGoal = is_array($marketing['ga4_business_goal'] ?? null)
                ? $marketing['ga4_business_goal']
                : [];

            if (($ga4BusinessGoal['status'] ?? 'not_configured') !== 'not_configured') {
                $ga4BusinessGoalCoverage['configured_campaigns']++;

                if (($ga4BusinessGoal['status'] ?? null) === 'available') {
                    $ga4BusinessGoalCoverage['available_campaigns']++;
                } else {
                    $ga4BusinessGoalCoverage['unavailable_campaigns']++;
                }

                $pacingStatus = is_array($ga4BusinessGoal['time_pacing'] ?? null)
                    ? ($ga4BusinessGoal['time_pacing']['status'] ?? null)
                    : null;
                $pacingCoverageKey = match ($pacingStatus) {
                    'ahead' => 'ahead_campaigns',
                    'behind' => 'behind_campaigns',
                    'on_track' => 'on_track_campaigns',
                    'not_started' => 'not_started_campaigns',
                    'completed' => 'completed_campaigns',
                    'data_unavailable' => 'data_unavailable_campaigns',
                    default => null,
                };

                if ($pacingCoverageKey !== null) {
                    $ga4BusinessGoalCoverage[$pacingCoverageKey]++;
                }
            }

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
                    'tag' => $target->tag,
                    'starts_on' => $target->starts_on?->toDateString(),
                    'ends_on' => $target->ends_on?->toDateString(),
                    'budget_amount' => (float) $budgetAmount,
                    'currency_code' => $budgetCurrency,
                    'spend_amount' => $hasComparableSpend ? (float) $spendAmount : null,
                    'spend_status' => $hasComparableSpend && is_string($spendStatus) ? $spendStatus : 'missing',
                    'spend_source' => is_string($spendSource) ? $spendSource : null,
                    'spend_source_label' => is_string($efficiency['ad_spend_source_label'] ?? null)
                        ? $efficiency['ad_spend_source_label']
                        : null,
                    'budget_status' => is_string($efficiency['budget_status'] ?? null)
                        ? $efficiency['budget_status']
                        : 'missing_spend',
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
                    'tag' => $target->tag,
                    'status' => is_string($summary['status'] ?? null) ? $summary['status'] : 'missing',
                    'value' => is_numeric($sourceValue) && is_finite((float) $sourceValue) && $currencyCode !== null
                        ? (float) $sourceValue
                        : null,
                    'currency_code' => $currencyCode,
                    'period_days' => $this->periodDays($target),
                    'mapping_details' => $this->campaignMappingDetails($source, $this->campaignMappingIdentity($source, $target)),
                ];

                if (in_array($source, ['meta_marketing', 'google_ads'], true)) {
                    $publicEngagement = is_array($marketing['public_engagement'] ?? null)
                        ? $marketing['public_engagement']
                        : [];
                    $interactionCostCandidates[$source][$mappingKey][] = [
                        'target_id' => (int) $target->getKey(),
                        'tag' => $target->tag,
                        'status' => is_string($summary['status'] ?? null) ? $summary['status'] : 'missing',
                        'value' => is_numeric($sourceValue) && is_finite((float) $sourceValue) && $currencyCode !== null
                            ? (float) $sourceValue
                            : null,
                        'currency_code' => $currencyCode,
                        'public_interactions' => is_int($publicEngagement['interaction_total'] ?? null)
                            ? $publicEngagement['interaction_total']
                            : null,
                        'available_post_count' => is_int($publicEngagement['available_posts'] ?? null)
                            ? $publicEngagement['available_posts']
                            : 0,
                        'tracked_post_count' => is_int($publicEngagement['tracked_posts'] ?? null)
                            ? $publicEngagement['tracked_posts']
                            : 0,
                        'period_days' => $this->periodDays($target),
                    ];
                }
            }

            $efficiency = is_array($marketing['efficiency'] ?? null) ? $marketing['efficiency'] : [];
            foreach ([
                'meta_marketing' => 'meta_ads_omni_purchase_value',
                'google_ads' => 'google_ads_conversion_value',
            ] as $source => $conversionValueKey) {
                $mappingKey = $this->campaignMappingKey($source, $target);

                if ($mappingKey === null) {
                    continue;
                }

                $summary = is_array($marketing[$source] ?? null) ? $marketing[$source] : [];
                $conversionValue = $efficiency[$conversionValueKey] ?? null;
                $spendAmount = $summary['spend_amount'] ?? null;
                $currencyCode = $this->currencyCode($summary['currency_code'] ?? null);
                $hasConversionValue = is_numeric($conversionValue) && is_finite((float) $conversionValue);
                $hasPositiveSpend = is_numeric($spendAmount)
                    && is_finite((float) $spendAmount)
                    && (float) $spendAmount > 0;

                $nativeRoasCandidates[$source][$mappingKey][] = [
                    'target_id' => (int) $target->getKey(),
                    'status' => is_string($summary['status'] ?? null) ? $summary['status'] : 'missing',
                    'revenue' => $hasConversionValue ? (float) $conversionValue : null,
                    'spend' => $hasPositiveSpend ? (float) $spendAmount : null,
                    'currency_code' => $currencyCode,
                    'period_days' => $this->periodDays($target),
                ];
            }

            if (($efficiency['ad_spend_source'] ?? null) === 'manual') {
                $publicEngagement = is_array($marketing['public_engagement'] ?? null)
                    ? $marketing['public_engagement']
                    : [];
                $interactionCostCandidates['manual']['manual:'.$target->getKey()][] = [
                    'target_id' => (int) $target->getKey(),
                    'tag' => $target->tag,
                    'status' => 'fresh',
                    'value' => is_numeric($efficiency['ad_spend_amount'] ?? null)
                        && is_finite((float) $efficiency['ad_spend_amount'])
                        ? (float) $efficiency['ad_spend_amount']
                        : null,
                    'currency_code' => $this->currencyCode($efficiency['ad_spend_currency'] ?? null),
                    'public_interactions' => is_int($publicEngagement['interaction_total'] ?? null)
                        ? $publicEngagement['interaction_total']
                        : null,
                    'available_post_count' => is_int($publicEngagement['available_posts'] ?? null)
                        ? $publicEngagement['available_posts']
                        : 0,
                    'tracked_post_count' => is_int($publicEngagement['tracked_posts'] ?? null)
                        ? $publicEngagement['tracked_posts']
                        : 0,
                    'period_days' => $this->periodDays($target),
                ];
            }

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
        $nativeRoasGroups = [];
        $nativeRoasCoverage = [];
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
        $budgetRiskRows = [];
        $interactionCostGroups = [];
        $interactionRankingCandidates = [];
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

            $forecastCandidates = array_values(array_filter(
                $candidates,
                fn (array $budgetCandidate): bool => $this->hasAvailableBudgetForecast($budgetCandidate),
            ));

            if ($forecastCandidates !== []) {
                $forecastCandidate = $this->preferredBudgetCandidate($forecastCandidates);
                $budgetRiskRows[] = $this->budgetRiskRow($forecastCandidate);
            }

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

        foreach ($nativeRoasCandidates as $source => $campaignCandidates) {
            $nativeRoasCoverage[$source] = [
                'configured_campaigns' => count($campaignCandidates),
                'comparable_campaigns' => 0,
                'fresh_campaigns' => 0,
                'stale_campaigns' => 0,
                'missing_campaigns' => 0,
                'duplicate_mappings_excluded' => 0,
            ];

            foreach ($campaignCandidates as $candidates) {
                $nativeRoasCoverage[$source]['duplicate_mappings_excluded'] += max(0, count($candidates) - 1);
                $candidate = $this->preferredCandidate($candidates);
                $currencyCode = $candidate['currency_code'];
                $isComparable = $candidate['revenue'] !== null
                    && $candidate['spend'] !== null
                    && $candidate['currency_code'] !== null;

                if ($isComparable) {
                    $nativeRoasCoverage[$source]['comparable_campaigns']++;
                    if ($candidate['status'] === 'fresh') {
                        $nativeRoasCoverage[$source]['fresh_campaigns']++;
                    } elseif ($candidate['status'] === 'stale') {
                        $nativeRoasCoverage[$source]['stale_campaigns']++;
                    }
                } else {
                    $nativeRoasCoverage[$source]['missing_campaigns']++;
                }

                if ($currencyCode === null) {
                    continue;
                }

                $groupKey = $source.'|'.$currencyCode;
                $nativeRoasGroups[$groupKey] ??= [
                    'source' => $source,
                    'source_label' => $source === 'meta_marketing' ? 'Meta Ads' : 'Google Ads',
                    'currency_code' => $currencyCode,
                    'conversion_value_amount' => 0.0,
                    'matched_spend_amount' => 0.0,
                    'roas' => null,
                    'campaign_count' => 0,
                    'comparable_campaign_count' => 0,
                    'fresh_campaign_count' => 0,
                    'stale_campaign_count' => 0,
                    'missing_campaign_count' => 0,
                    'duplicate_mappings_excluded' => 0,
                ];

                $nativeRoasGroups[$groupKey]['campaign_count']++;
                $nativeRoasGroups[$groupKey]['duplicate_mappings_excluded'] += max(0, count($candidates) - 1);
                $nativeRoasGroups[$groupKey]['fresh_campaign_count'] += $candidate['status'] === 'fresh' ? 1 : 0;
                $nativeRoasGroups[$groupKey]['stale_campaign_count'] += $candidate['status'] === 'stale' ? 1 : 0;

                if (! $isComparable) {
                    $nativeRoasGroups[$groupKey]['missing_campaign_count']++;

                    continue;
                }

                $nativeRoasGroups[$groupKey]['conversion_value_amount'] += $candidate['revenue'];
                $nativeRoasGroups[$groupKey]['matched_spend_amount'] += $candidate['spend'];
                $nativeRoasGroups[$groupKey]['comparable_campaign_count']++;
            }
        }

        foreach ($nativeRoasGroups as &$group) {
            $group['conversion_value_amount'] = round($group['conversion_value_amount'], 4);
            $group['matched_spend_amount'] = round($group['matched_spend_amount'], 4);
            $group['roas'] = $group['matched_spend_amount'] > 0
                ? round($group['conversion_value_amount'] / $group['matched_spend_amount'], 4)
                : null;
        }
        unset($group);

        foreach ($interactionCostCandidates as $source => $campaignCandidates) {
            foreach ($campaignCandidates as $candidates) {
                $candidate = $this->preferredInteractionCostCandidate($candidates);

                if ($candidate['value'] === null || $candidate['currency_code'] === null) {
                    continue;
                }

                $groupKey = $source.'|'.$candidate['currency_code'];
                $interactionCostGroups[$groupKey] ??= [
                    'source' => $source,
                    'source_label' => match ($source) {
                        'meta_marketing' => 'Meta Ads',
                        'google_ads' => 'Google Ads',
                        default => '人工記錄',
                    },
                    'currency_code' => $candidate['currency_code'],
                    'comparable_spend_amount' => 0.0,
                    'public_interactions' => 0,
                    'cost_per_public_interaction' => null,
                    'campaign_count' => 0,
                    'fresh_campaign_count' => 0,
                    'stale_campaign_count' => 0,
                    'comparable_campaign_count' => 0,
                    'available_post_count' => 0,
                    'tracked_post_count' => 0,
                    'coverage_percent' => null,
                ];

                $interactionCostGroups[$groupKey]['campaign_count']++;
                $interactionCostGroups[$groupKey]['fresh_campaign_count'] += $candidate['status'] === 'fresh' ? 1 : 0;
                $interactionCostGroups[$groupKey]['stale_campaign_count'] += $candidate['status'] === 'stale' ? 1 : 0;
                $interactionCostGroups[$groupKey]['available_post_count'] += $candidate['available_post_count'];
                $interactionCostGroups[$groupKey]['tracked_post_count'] += $candidate['tracked_post_count'];

                if ($candidate['public_interactions'] !== null) {
                    $interactionCostGroups[$groupKey]['comparable_spend_amount'] += $candidate['value'];
                    $interactionCostGroups[$groupKey]['public_interactions'] += $candidate['public_interactions'];
                    $interactionCostGroups[$groupKey]['comparable_campaign_count']++;
                }

                if ($candidate['value'] < 0
                    || $candidate['public_interactions'] === null
                    || $candidate['public_interactions'] <= 0
                    || $candidate['tracked_post_count'] <= 0
                    || $candidate['available_post_count'] < $candidate['tracked_post_count']) {
                    continue;
                }

                $interactionRankingCandidates[$groupKey][] = [
                    'target_id' => $candidate['target_id'],
                    'tag' => $candidate['tag'],
                    'source' => $source,
                    'source_label' => match ($source) {
                        'meta_marketing' => 'Meta Ads',
                        'google_ads' => 'Google Ads',
                        default => '人工記錄',
                    },
                    'currency_code' => $candidate['currency_code'],
                    'cost_per_public_interaction' => round($candidate['value'] / $candidate['public_interactions'], 4),
                    'status' => $candidate['status'],
                ];
            }
        }

        foreach ($interactionCostGroups as &$group) {
            $group['comparable_spend_amount'] = round($group['comparable_spend_amount'], 4);
            $group['cost_per_public_interaction'] = $group['public_interactions'] > 0
                ? round($group['comparable_spend_amount'] / $group['public_interactions'], 4)
                : null;
            $group['coverage_percent'] = $group['tracked_post_count'] > 0
                ? round($group['available_post_count'] / $group['tracked_post_count'] * 100, 1)
                : null;
        }
        unset($group);

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

        $mappingAuditRows = $this->mappingAuditRows($sourceCandidates);

        ksort($currencyGroups);
        ksort($nativeRoasGroups);
        ksort($budgetCurrencyGroups);
        ksort($interactionCostGroups);
        usort($budgetRiskRows, static function (array $first, array $second): int {
            $riskOrder = $second['projected_overrun_percent'] <=> $first['projected_overrun_percent'];

            return $riskOrder !== 0
                ? $riskOrder
                : (strcasecmp($first['tag'], $second['tag']) ?: ($first['target_id'] <=> $second['target_id']));
        });
        $campaignInteractionCostRanks = [];

        foreach ($interactionRankingCandidates as $candidates) {
            usort($candidates, static function (array $first, array $second): int {
                $costOrder = $first['cost_per_public_interaction'] <=> $second['cost_per_public_interaction'];

                return $costOrder !== 0
                    ? $costOrder
                    : (strcasecmp($first['tag'], $second['tag']) ?: ($first['target_id'] <=> $second['target_id']));
            });
            $rank = 0;
            $position = 0;
            $previousCost = null;

            foreach ($candidates as $candidate) {
                $position++;

                if ($previousCost === null || $candidate['cost_per_public_interaction'] !== $previousCost) {
                    $rank = $position;
                }

                $campaignInteractionCostRanks[$candidate['target_id']][$candidate['source']] = [
                    'rank' => $rank,
                    'source' => $candidate['source'],
                    'source_label' => $candidate['source_label'],
                    'currency_code' => $candidate['currency_code'],
                    'cost_per_public_interaction' => $candidate['cost_per_public_interaction'],
                    'status' => $candidate['status'],
                ];
                $previousCost = $candidate['cost_per_public_interaction'];
            }
        }

        ksort($campaignInteractionCostRanks);

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
            'native_roas_groups' => array_values($nativeRoasGroups),
            'native_roas_coverage' => $nativeRoasCoverage,
            'ga4_business_goal_coverage' => $ga4BusinessGoalCoverage,
            'budget_coverage' => $budgetCoverage,
            'budget_currency_groups' => array_values($budgetCurrencyGroups),
            'budget_risk_rows' => $budgetRiskRows,
            'mapping_audit_rows' => $mappingAuditRows,
            'interaction_cost_groups' => array_values($interactionCostGroups),
            'campaign_interaction_cost_ranks' => $campaignInteractionCostRanks,
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

    /** @param array<string, mixed> $candidate */
    private function hasAvailableBudgetForecast(array $candidate): bool
    {
        return ($candidate['budget_status'] ?? null) === 'in_progress'
            && ($candidate['spend_status'] ?? null) === 'fresh'
            && in_array($candidate['spend_source'] ?? null, ['meta_marketing', 'google_ads', 'combined_paid_ads', 'manual'], true)
            && is_numeric($candidate['budget_amount'] ?? null)
            && is_finite((float) $candidate['budget_amount'])
            && (float) $candidate['budget_amount'] > 0
            && is_numeric($candidate['spend_amount'] ?? null)
            && is_finite((float) $candidate['spend_amount'])
            && (float) $candidate['spend_amount'] >= 0
            && is_string($candidate['currency_code'] ?? null)
            && $this->currencyCode($candidate['currency_code']) !== null
            && is_numeric($candidate['projected_spend_amount'] ?? null)
            && is_finite((float) $candidate['projected_spend_amount'])
            && is_numeric($candidate['projected_overrun_amount'] ?? null)
            && is_finite((float) $candidate['projected_overrun_amount'])
            && is_string($candidate['starts_on'] ?? null)
            && is_string($candidate['ends_on'] ?? null);
    }

    /** @param array<string, mixed> $candidate
     * @return array{target_id: int, tag: string, currency_code: string, spend_source_label: string, freshness_status: string, freshness_label: string, spend_amount: float, budget_amount: float, projected_spend_amount: float, projected_overrun_amount: float, projected_overrun_percent: float, status: string, status_label: string, starts_on: string, ends_on: string}
     */
    private function budgetRiskRow(array $candidate): array
    {
        $projectedOverrunAmount = (float) $candidate['projected_overrun_amount'];
        $projectedOverrunPercent = round($projectedOverrunAmount / (float) $candidate['budget_amount'] * 100, 2);
        $status = match (true) {
            $projectedOverrunAmount > 0 => 'projected_over_budget',
            $projectedOverrunAmount < 0 => 'projected_under_budget',
            default => 'projected_on_budget',
        };

        return [
            'target_id' => (int) $candidate['target_id'],
            'tag' => (string) $candidate['tag'],
            'currency_code' => (string) $candidate['currency_code'],
            'spend_source_label' => is_string($candidate['spend_source_label'] ?? null)
                ? $candidate['spend_source_label']
                : match ($candidate['spend_source'] ?? null) {
                    'meta_marketing' => 'Meta Ads 花費',
                    'google_ads' => 'Google Ads 花費',
                    'combined_paid_ads' => 'Meta Ads + Google Ads 花費',
                    default => '人工記錄花費',
                },
            'freshness_status' => 'fresh',
            'freshness_label' => ($candidate['spend_source'] ?? null) === 'manual' ? '人工記錄' : '24 小時內更新',
            'spend_amount' => round((float) $candidate['spend_amount'], 4),
            'budget_amount' => round((float) $candidate['budget_amount'], 4),
            'projected_spend_amount' => round((float) $candidate['projected_spend_amount'], 4),
            'projected_overrun_amount' => round($projectedOverrunAmount, 4),
            'projected_overrun_percent' => $projectedOverrunPercent,
            'status' => $status,
            'status_label' => match ($status) {
                'projected_over_budget' => '可能超支',
                'projected_under_budget' => '預估低於預算',
                default => '預估符合預算',
            },
            'starts_on' => (string) $candidate['starts_on'],
            'ends_on' => (string) $candidate['ends_on'],
        ];
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
        $identity = $this->campaignMappingIdentity($source, $target);

        return $identity === null
            ? null
            : hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    /** @return list<string|null>|null */
    private function campaignMappingIdentity(string $source, SocialEngagementTarget $target): ?array
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

        return $identity;
    }

    /**
     * @param  list<string|null>|null  $identity
     * @return array{source_label: string, campaign_id: string|null, attribution_mode: string|null, campaign_name: string|null, source_name: string|null, medium_name: string|null}
     */
    private function campaignMappingDetails(string $source, ?array $identity): array
    {
        $sourceLabel = match ($source) {
            'google_analytics' => 'GA4',
            'meta_marketing' => 'Meta Ads',
            'google_ads' => 'Google Ads',
            default => $source,
        };

        if ($source === 'google_analytics' && ($identity[0] ?? null) === 'manual_utm') {
            return [
                'source_label' => $sourceLabel,
                'campaign_id' => null,
                'attribution_mode' => 'manual_utm',
                'campaign_name' => $identity[1] ?? null,
                'source_name' => $identity[2] ?? null,
                'medium_name' => $identity[3] ?? null,
            ];
        }

        return [
            'source_label' => $sourceLabel,
            'campaign_id' => $identity[1] ?? null,
            'attribution_mode' => $source === 'google_analytics' ? 'google_ads_auto_tagging' : null,
            'campaign_name' => null,
            'source_name' => null,
            'medium_name' => null,
        ];
    }

    /**
     * @param  array<string, array<string, list<array<string, mixed>>>>  $sourceCandidates
     * @return list<array{type: string, type_label: string, target_ids: list<int>, target_tags: list<string>, merged_count: int, mappings: list<array{source_label: string, campaign_id: string|null, attribution_mode: string|null, campaign_name: string|null, source_name: string|null, medium_name: string|null, preferred_tag: string, freshness_status: string, freshness_label: string}>}
     */
    private function mappingAuditRows(array $sourceCandidates): array
    {
        $rows = [];
        $paidMappingGroups = [];
        $paidMappingParents = [];

        foreach (['meta_marketing', 'google_ads'] as $source) {
            foreach ($sourceCandidates[$source] ?? [] as $candidates) {
                if (count($candidates) < 2) {
                    continue;
                }

                $targetIds = array_values(array_unique(array_map(
                    static fn (array $candidate): int => (int) $candidate['target_id'],
                    $candidates,
                )));
                foreach ($targetIds as $targetId) {
                    $paidMappingParents[$targetId] ??= $targetId;
                }

                $this->unionBudgetTargets($paidMappingParents, $targetIds);
                $paidMappingGroups[] = [
                    'candidates' => $candidates,
                    'target_ids' => $targetIds,
                ];
            }
        }

        $paidGroupsByRoot = [];
        foreach ($paidMappingGroups as $group) {
            $root = $this->budgetRoot($paidMappingParents, $group['target_ids'][0]);
            $paidGroupsByRoot[$root][] = $group;
        }

        foreach ($paidGroupsByRoot as $groups) {
            $targetTagsById = [];
            $mappings = [];

            foreach ($groups as $group) {
                foreach ($group['candidates'] as $candidate) {
                    $targetTagsById[(int) $candidate['target_id']] = (string) $candidate['tag'];
                }

                $preferredCandidate = $this->preferredCandidate($group['candidates']);
                $mappings[] = [
                    ...$preferredCandidate['mapping_details'],
                    'preferred_tag' => (string) $preferredCandidate['tag'],
                    'freshness_status' => (string) $preferredCandidate['status'],
                    'freshness_label' => $this->freshnessLabel((string) $preferredCandidate['status']),
                ];
            }

            ksort($targetTagsById);
            usort($mappings, static fn (array $first, array $second): int => [$first['source_label'], $first['campaign_id']] <=> [$second['source_label'], $second['campaign_id']]);
            $targetIds = array_map('intval', array_keys($targetTagsById));

            $rows[] = [
                'type' => 'paid_media',
                'type_label' => '廣告活動',
                'target_ids' => $targetIds,
                'target_tags' => array_values($targetTagsById),
                'merged_count' => max(0, count($targetIds) - 1),
                'mappings' => $mappings,
            ];
        }

        foreach ($sourceCandidates['google_analytics'] ?? [] as $candidates) {
            if (count($candidates) < 2) {
                continue;
            }

            $preferredCandidate = $this->preferredCandidate($candidates);
            $targetTagsById = [];
            foreach ($candidates as $candidate) {
                $targetTagsById[(int) $candidate['target_id']] = (string) $candidate['tag'];
            }

            ksort($targetTagsById);
            $targetIds = array_map('intval', array_keys($targetTagsById));
            $rows[] = [
                'type' => 'google_analytics',
                'type_label' => 'GA4 歸因',
                'target_ids' => $targetIds,
                'target_tags' => array_values($targetTagsById),
                'merged_count' => max(0, count($targetIds) - 1),
                'mappings' => [[
                    ...$preferredCandidate['mapping_details'],
                    'preferred_tag' => (string) $preferredCandidate['tag'],
                    'freshness_status' => (string) $preferredCandidate['status'],
                    'freshness_label' => $this->freshnessLabel((string) $preferredCandidate['status']),
                ]],
            ];
        }

        usort($rows, static fn (array $first, array $second): int => [$first['type'], $first['target_tags'][0] ?? ''] <=> [$second['type'], $second['target_tags'][0] ?? '']);

        return $rows;
    }

    private function freshnessLabel(string $status): string
    {
        return match ($status) {
            'fresh' => '資料新鮮',
            'stale' => '資料過期',
            default => '資料不足',
        };
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

    /**
     * @param  list<array{
     *     target_id: int,
     *     tag: string,
     *     status: string,
     *     value: float|null,
     *     currency_code: string|null,
     *     public_interactions: int|null,
     *     available_post_count: int,
     *     tracked_post_count: int,
     *     period_days: int
     * }>  $candidates
     * @return array{
     *     target_id: int,
     *     tag: string,
     *     status: string,
     *     value: float|null,
     *     currency_code: string|null,
     *     public_interactions: int|null,
     *     available_post_count: int,
     *     tracked_post_count: int,
     *     period_days: int
     * }
     */
    private function preferredInteractionCostCandidate(array $candidates): array
    {
        usort($candidates, static function (array $first, array $second): int {
            $firstHasComparableData = $first['value'] !== null
                && $first['currency_code'] !== null
                && $first['public_interactions'] !== null;
            $secondHasComparableData = $second['value'] !== null
                && $second['currency_code'] !== null
                && $second['public_interactions'] !== null;

            if ($firstHasComparableData !== $secondHasComparableData) {
                return $firstHasComparableData ? -1 : 1;
            }

            $firstHasSpend = $first['value'] !== null && $first['currency_code'] !== null;
            $secondHasSpend = $second['value'] !== null && $second['currency_code'] !== null;

            if ($firstHasSpend !== $secondHasSpend) {
                return $firstHasSpend ? -1 : 1;
            }

            $freshnessOrder = self::freshnessPriority($second['status']) <=> self::freshnessPriority($first['status']);

            if ($freshnessOrder !== 0) {
                return $freshnessOrder;
            }

            $coverageOrder = $second['available_post_count'] <=> $first['available_post_count'];

            if ($coverageOrder !== 0) {
                return $coverageOrder;
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
