<?php

namespace App\Services;

use App\Models\GoogleAdsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetPaidAdSnapshot;
use Carbon\CarbonImmutable;

class PaidMediaCampaignTrendReport
{
    private const HISTORY_LIMIT = 30;

    public function __construct(
        private MetaMarketingClientAccountResolver $metaMarketingClientAccountResolver,
        private GoogleAdsClientAccountResolver $googleAdsClientAccountResolver,
    ) {}

    /**
     * @return list<array{
     *     synced_at: CarbonImmutable,
     *     campaign_name: string,
     *     spend_amount: string,
     *     spend_change: float|null,
     *     currency_code: string,
     *     currency_comparable: bool,
     *     impressions: int,
     *     impressions_change: int|null,
     *     reach: int|null,
     *     reach_change: int|null,
     *     frequency: float|null,
     *     frequency_change: float|null,
     *     ctr_percent: float|null,
     *     ctr_change_percentage_points: float|null,
     *     cost_per_click: float|null,
     *     cost_per_click_change: float|null,
     *     cost_per_thousand_impressions: float|null,
     *     cost_per_thousand_impressions_change: float|null,
     *     clicks: int,
     *     clicks_change: int|null,
     *     conversions: string|null,
     *     conversions_change: float|null,
     *     conversions_value: string|null,
     *     conversions_value_change: float|null,
     *     roas_value: string|null,
     *     roas: float|null,
     *     roas_change: float|null,
     *     actions: list<array{action_type: string, value: string}>,
     *     action_values: list<array{action_type: string, value: string}>,
     *     conversion_actions: list<array{conversion_action_id: string, conversion_action_name: string, conversions: string, conversion_value: string}>,
     *     cost_per_conversion: float|null,
     *     cost_per_conversion_change: float|null,
     *     conversion_rate_percent: float|null,
     *     conversion_rate_change_percentage_points: float|null,
     *     has_previous_snapshot: bool
     * }>
     */
    public function metaMarketingChanges(
        ?MetaMarketingConnection $connection,
        SocialEngagementTarget $target,
    ): array {
        if ($connection === null || $target->meta_campaign_id === null) {
            return [];
        }

        $clientAccount = $this->metaMarketingClientAccountResolver->forTarget($connection, $target);

        if ($clientAccount === null) {
            return [];
        }

        return $this->recentChanges(
            $target,
            SocialEngagementTargetPaidAdSnapshot::SOURCE_META_MARKETING,
            'meta_marketing_connection_id',
            (int) $connection->getKey(),
            $clientAccount->ad_account_id,
            $target->meta_campaign_id,
        );
    }

    /**
     * @return list<array{
     *     synced_at: CarbonImmutable,
     *     campaign_name: string,
     *     spend_amount: string,
     *     spend_change: float|null,
     *     currency_code: string,
     *     currency_comparable: bool,
     *     impressions: int,
     *     impressions_change: int|null,
     *     reach: int|null,
     *     reach_change: int|null,
     *     frequency: float|null,
     *     frequency_change: float|null,
     *     ctr_percent: float|null,
     *     ctr_change_percentage_points: float|null,
     *     cost_per_click: float|null,
     *     cost_per_click_change: float|null,
     *     cost_per_thousand_impressions: float|null,
     *     cost_per_thousand_impressions_change: float|null,
     *     clicks: int,
     *     clicks_change: int|null,
     *     conversions: string|null,
     *     conversions_change: float|null,
     *     conversions_value: string|null,
     *     conversions_value_change: float|null,
     *     roas_value: string|null,
     *     roas: float|null,
     *     roas_change: float|null,
     *     actions: list<array{action_type: string, value: string}>,
     *     action_values: list<array{action_type: string, value: string}>,
     *     conversion_actions: list<array{conversion_action_id: string, conversion_action_name: string, conversions: string, conversion_value: string}>,
     *     cost_per_conversion: float|null,
     *     cost_per_conversion_change: float|null,
     *     conversion_rate_percent: float|null,
     *     conversion_rate_change_percentage_points: float|null,
     *     has_previous_snapshot: bool
     * }>
     */
    public function googleAdsChanges(
        ?GoogleAdsConnection $connection,
        SocialEngagementTarget $target,
    ): array {
        if ($connection === null || $target->google_ads_campaign_id === null) {
            return [];
        }

        $clientAccount = $this->googleAdsClientAccountResolver->forTarget($connection, $target);

        if ($clientAccount === null) {
            return [];
        }

        return $this->recentChanges(
            $target,
            SocialEngagementTargetPaidAdSnapshot::SOURCE_GOOGLE_ADS,
            'google_ads_connection_id',
            (int) $connection->getKey(),
            $clientAccount->customer_id,
            $target->google_ads_campaign_id,
        );
    }

    /**
     * @return list<array{
     *     synced_at: CarbonImmutable,
     *     campaign_name: string,
     *     spend_amount: string,
     *     spend_change: float|null,
     *     currency_code: string,
     *     currency_comparable: bool,
     *     impressions: int,
     *     impressions_change: int|null,
     *     reach: int|null,
     *     reach_change: int|null,
     *     frequency: float|null,
     *     frequency_change: float|null,
     *     ctr_percent: float|null,
     *     ctr_change_percentage_points: float|null,
     *     cost_per_click: float|null,
     *     cost_per_click_change: float|null,
     *     cost_per_thousand_impressions: float|null,
     *     cost_per_thousand_impressions_change: float|null,
     *     clicks: int,
     *     clicks_change: int|null,
     *     conversions: string|null,
     *     conversions_change: float|null,
     *     conversions_value: string|null,
     *     conversions_value_change: float|null,
     *     roas_value: string|null,
     *     roas: float|null,
     *     roas_change: float|null,
     *     actions: list<array{action_type: string, value: string}>,
     *     action_values: list<array{action_type: string, value: string}>,
     *     conversion_actions: list<array{conversion_action_id: string, conversion_action_name: string, conversions: string, conversion_value: string}>,
     *     cost_per_conversion: float|null,
     *     cost_per_conversion_change: float|null,
     *     conversion_rate_percent: float|null,
     *     conversion_rate_change_percentage_points: float|null,
     *     has_previous_snapshot: bool
     * }>
     */
    private function recentChanges(
        SocialEngagementTarget $target,
        string $source,
        string $connectionColumn,
        int $connectionId,
        string $accountId,
        string $campaignId,
    ): array {
        if ($target->starts_on === null || $target->ends_on === null) {
            return [];
        }

        $snapshots = SocialEngagementTargetPaidAdSnapshot::query()
            ->where('social_engagement_target_id', $target->getKey())
            ->where('source', $source)
            ->where($connectionColumn, $connectionId)
            ->where('account_id', $accountId)
            ->where('campaign_id', $campaignId)
            ->whereDate('starts_on', $target->starts_on)
            ->whereDate('ends_on', $target->ends_on)
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        $changes = [];
        $previousSnapshot = null;

        foreach ($snapshots as $snapshot) {
            $hasPreviousSnapshot = $previousSnapshot !== null;
            $frequency = $this->frequency($snapshot->impressions, $snapshot->reach);
            $previousFrequency = $previousSnapshot === null
                ? null
                : $this->frequency($previousSnapshot->impressions, $previousSnapshot->reach);
            $ctrPercent = $snapshot->impressions > 0
                ? round($snapshot->clicks / $snapshot->impressions * 100, 2)
                : null;
            $previousCtrPercent = $previousSnapshot !== null && $previousSnapshot->impressions > 0
                ? round($previousSnapshot->clicks / $previousSnapshot->impressions * 100, 2)
                : null;
            $costPerClick = $snapshot->clicks > 0
                ? round((float) $snapshot->spend_amount / $snapshot->clicks, 4)
                : null;
            $previousCostPerClick = $previousSnapshot !== null && $previousSnapshot->clicks > 0
                ? round((float) $previousSnapshot->spend_amount / $previousSnapshot->clicks, 4)
                : null;
            $costPerThousandImpressions = $snapshot->impressions > 0
                ? round((float) $snapshot->spend_amount / $snapshot->impressions * 1000, 4)
                : null;
            $previousCostPerThousandImpressions = $previousSnapshot !== null && $previousSnapshot->impressions > 0
                ? round((float) $previousSnapshot->spend_amount / $previousSnapshot->impressions * 1000, 4)
                : null;
            $costPerConversion = $snapshot->conversions !== null && (float) $snapshot->conversions > 0
                ? round((float) $snapshot->spend_amount / (float) $snapshot->conversions, 4)
                : null;
            $previousCostPerConversion = $previousSnapshot?->conversions !== null && (float) $previousSnapshot->conversions > 0
                ? round((float) $previousSnapshot->spend_amount / (float) $previousSnapshot->conversions, 4)
                : null;
            $conversionRatePercent = $snapshot->conversions !== null && $snapshot->clicks > 0
                ? round((float) $snapshot->conversions / $snapshot->clicks * 100, 2)
                : null;
            $previousConversionRatePercent = $previousSnapshot?->conversions !== null && $previousSnapshot->clicks > 0
                ? round((float) $previousSnapshot->conversions / $previousSnapshot->clicks * 100, 2)
                : null;
            $currenciesMatch = $hasPreviousSnapshot
                && $previousSnapshot->currency_code === $snapshot->currency_code;
            $moneyIsComparable = $currenciesMatch;
            $roasValue = $source === SocialEngagementTargetPaidAdSnapshot::SOURCE_META_MARKETING
                ? $this->metaOmniPurchaseValue($snapshot->action_values)
                : $snapshot->conversions_value;
            $previousRoasValue = $previousSnapshot === null
                ? null
                : ($source === SocialEngagementTargetPaidAdSnapshot::SOURCE_META_MARKETING
                    ? $this->metaOmniPurchaseValue($previousSnapshot->action_values)
                    : $previousSnapshot->conversions_value);
            $roas = $this->roas($roasValue, $snapshot->spend_amount);
            $previousRoas = $previousSnapshot === null
                ? null
                : $this->roas($previousRoasValue, $previousSnapshot->spend_amount);

            $changes[] = [
                'synced_at' => $snapshot->synced_at,
                'campaign_name' => $snapshot->campaign_name,
                'spend_amount' => $snapshot->spend_amount,
                'spend_change' => $moneyIsComparable
                    ? $this->decimalChange($snapshot->spend_amount, $previousSnapshot->spend_amount)
                    : null,
                'currency_code' => $snapshot->currency_code,
                'currency_comparable' => $moneyIsComparable,
                'impressions' => $snapshot->impressions,
                'impressions_change' => $hasPreviousSnapshot
                    ? $snapshot->impressions - $previousSnapshot->impressions
                    : null,
                'reach' => $snapshot->reach,
                'reach_change' => $hasPreviousSnapshot && $snapshot->reach !== null && $previousSnapshot->reach !== null
                    ? $snapshot->reach - $previousSnapshot->reach
                    : null,
                'frequency' => $frequency,
                'frequency_change' => $frequency !== null && $previousFrequency !== null
                    ? round($frequency - $previousFrequency, 4)
                    : null,
                'ctr_percent' => $ctrPercent,
                'ctr_change_percentage_points' => $ctrPercent !== null && $previousCtrPercent !== null
                    ? round($ctrPercent - $previousCtrPercent, 2)
                    : null,
                'cost_per_click' => $costPerClick,
                'cost_per_click_change' => $moneyIsComparable && $costPerClick !== null && $previousCostPerClick !== null
                    ? round($costPerClick - $previousCostPerClick, 4)
                    : null,
                'cost_per_thousand_impressions' => $costPerThousandImpressions,
                'cost_per_thousand_impressions_change' => $moneyIsComparable && $costPerThousandImpressions !== null && $previousCostPerThousandImpressions !== null
                    ? round($costPerThousandImpressions - $previousCostPerThousandImpressions, 4)
                    : null,
                'clicks' => $snapshot->clicks,
                'clicks_change' => $hasPreviousSnapshot
                    ? $snapshot->clicks - $previousSnapshot->clicks
                    : null,
                'conversions' => $snapshot->conversions,
                'conversions_change' => $hasPreviousSnapshot
                    ? $this->optionalDecimalChange($snapshot->conversions, $previousSnapshot->conversions)
                    : null,
                'conversions_value' => $snapshot->conversions_value,
                'conversions_value_change' => $moneyIsComparable
                    ? $this->optionalDecimalChange($snapshot->conversions_value, $previousSnapshot->conversions_value)
                    : null,
                'roas_value' => $roasValue,
                'roas' => $roas,
                'roas_change' => $moneyIsComparable && $roas !== null && $previousRoas !== null
                    ? round($roas - $previousRoas, 2)
                    : null,
                'actions' => $snapshot->actions ?? [],
                'action_values' => $snapshot->action_values ?? [],
                'conversion_actions' => $snapshot->conversion_actions ?? [],
                'cost_per_conversion' => $costPerConversion,
                'cost_per_conversion_change' => $moneyIsComparable && $costPerConversion !== null && $previousCostPerConversion !== null
                    ? round($costPerConversion - $previousCostPerConversion, 4)
                    : null,
                'conversion_rate_percent' => $conversionRatePercent,
                'conversion_rate_change_percentage_points' => $conversionRatePercent !== null && $previousConversionRatePercent !== null
                    ? round($conversionRatePercent - $previousConversionRatePercent, 2)
                    : null,
                'has_previous_snapshot' => $hasPreviousSnapshot,
            ];

            $previousSnapshot = $snapshot;
        }

        return $changes;
    }

    private function decimalChange(string $current, string $previous): float
    {
        return round((float) $current - (float) $previous, 4);
    }

    private function optionalDecimalChange(?string $current, ?string $previous): ?float
    {
        return $current !== null && $previous !== null
            ? $this->decimalChange($current, $previous)
            : null;
    }

    private function frequency(int $impressions, ?int $reach): ?float
    {
        return $reach !== null && $reach > 0
            ? round($impressions / $reach, 4)
            : null;
    }

    private function metaOmniPurchaseValue(?array $actionValues): ?string
    {
        foreach ($actionValues ?? [] as $actionValue) {
            if (! is_array($actionValue) || ($actionValue['action_type'] ?? null) !== 'omni_purchase') {
                continue;
            }

            $value = $actionValue['value'] ?? null;

            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function roas(?string $conversionValue, ?string $spendAmount): ?float
    {
        return $conversionValue !== null
            && $spendAmount !== null
            && (float) $spendAmount > 0
            ? round((float) $conversionValue / (float) $spendAmount, 2)
            : null;
    }
}
