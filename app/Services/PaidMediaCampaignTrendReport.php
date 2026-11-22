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
     *     clicks: int,
     *     clicks_change: int|null,
     *     conversions: string|null,
     *     conversions_change: float|null,
     *     conversions_value: string|null,
     *     conversions_value_change: float|null,
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

        return $this->recentChanges(
            $target,
            SocialEngagementTargetPaidAdSnapshot::SOURCE_META_MARKETING,
            'meta_marketing_connection_id',
            (int) $connection->getKey(),
            (string) $connection->ad_account_id,
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
     *     clicks: int,
     *     clicks_change: int|null,
     *     conversions: string|null,
     *     conversions_change: float|null,
     *     conversions_value: string|null,
     *     conversions_value_change: float|null,
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

        return $this->recentChanges(
            $target,
            SocialEngagementTargetPaidAdSnapshot::SOURCE_GOOGLE_ADS,
            'google_ads_connection_id',
            (int) $connection->getKey(),
            (string) $connection->customer_id,
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
     *     clicks: int,
     *     clicks_change: int|null,
     *     conversions: string|null,
     *     conversions_change: float|null,
     *     conversions_value: string|null,
     *     conversions_value_change: float|null,
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
            $currenciesMatch = $hasPreviousSnapshot
                && $previousSnapshot->currency_code === $snapshot->currency_code;
            $moneyIsComparable = $currenciesMatch;

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
}
