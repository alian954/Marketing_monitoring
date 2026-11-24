<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use Illuminate\Database\Eloquent\Collection;

class ClientPortfolioEstimatedProfitabilitySummary
{
    public function __construct(private CampaignEstimatedProfitabilityReport $profitabilityReport) {}

    /**
     * @param  Collection<int, SocialEngagementTarget>  $targets
     * @param  array<int, array<string, mixed>>  $marketingSummaries
     * @param  list<array{target_ids?: list<int>}>  $mappingAuditRows
     * @param  array{from: string, to: string}|null  $reportPeriod
     * @return array{
     *     groups: list<array{currency_code: string, media_contribution_amount: float, campaign_count: int}>,
     *     coverage: array{configured_campaigns: int, available_campaigns: int, unavailable_campaigns: int, unconfigured_campaigns: int, duplicate_mappings_excluded: int, eligible_campaigns: int}
     * }
     */
    public function build(
        Collection $targets,
        array $marketingSummaries,
        array $mappingAuditRows,
        ?array $reportPeriod = null,
    ): array {
        $duplicateTargetIds = [];

        foreach ($mappingAuditRows as $mappingAuditRow) {
            $targetIds = $mappingAuditRow['target_ids'] ?? [];

            if (! is_array($targetIds) || count($targetIds) < 2) {
                continue;
            }

            foreach ($targetIds as $targetId) {
                if (is_int($targetId)) {
                    $duplicateTargetIds[$targetId] = true;
                }
            }
        }

        $coverage = [
            'configured_campaigns' => 0,
            'available_campaigns' => 0,
            'unavailable_campaigns' => 0,
            'unconfigured_campaigns' => 0,
            'duplicate_mappings_excluded' => 0,
            'eligible_campaigns' => 0,
        ];
        $groups = [];

        foreach ($targets as $target) {
            $targetId = (int) $target->getKey();

            if ($target->gross_margin_percent === null) {
                $coverage['unconfigured_campaigns']++;

                continue;
            }

            $coverage['configured_campaigns']++;

            if (isset($duplicateTargetIds[$targetId])) {
                $coverage['duplicate_mappings_excluded']++;

                continue;
            }

            $coverage['eligible_campaigns']++;
            $marketingSummary = is_array($marketingSummaries[$targetId] ?? null)
                ? $marketingSummaries[$targetId]
                : [];
            $estimate = $this->profitabilityReport->buildForMarketingSummary(
                $target,
                $marketingSummary,
                $reportPeriod,
            );

            if (($estimate['status'] ?? null) !== 'available'
                || ! is_numeric($estimate['media_contribution_amount'] ?? null)
                || ! is_finite((float) $estimate['media_contribution_amount'])
                || ! is_string($estimate['currency_code'] ?? null)) {
                $coverage['unavailable_campaigns']++;

                continue;
            }

            $currencyCode = strtoupper(trim($estimate['currency_code']));
            $groups[$currencyCode] ??= [
                'currency_code' => $currencyCode,
                'media_contribution_amount' => 0.0,
                'campaign_count' => 0,
            ];
            $groups[$currencyCode]['media_contribution_amount'] += (float) $estimate['media_contribution_amount'];
            $groups[$currencyCode]['campaign_count']++;
            $coverage['available_campaigns']++;
        }

        ksort($groups);

        foreach ($groups as &$group) {
            $group['media_contribution_amount'] = round($group['media_contribution_amount'], 4);
        }
        unset($group);

        return [
            'groups' => array_values($groups),
            'coverage' => $coverage,
        ];
    }
}
