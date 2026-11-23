<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;

class SocialEngagementCampaignPaidMediaRoasGoalReport
{
    /**
     * @param  array<string, mixed>  $efficiency
     * @return array{
     *     source: string|null,
     *     source_label: string|null,
     *     target_roas: float|null,
     *     actual_roas: float|null,
     *     attainment_percent: float|null,
     *     difference_roas: float|null,
     *     spend_source_label: string|null,
     *     status: string,
     *     status_label: string
     * }
     */
    public function build(
        SocialEngagementTarget $target,
        array $efficiency,
        bool $googleAnalyticsReportIsStale,
        bool $metaMarketingReportIsStale,
        bool $googleAdsReportIsStale,
    ): array {
        $source = is_string($target->paid_media_goal_source)
            && in_array($target->paid_media_goal_source, ['ga4', 'meta_ads', 'google_ads'], true)
            ? $target->paid_media_goal_source
            : null;
        $sourceLabel = match ($source) {
            'ga4' => 'GA4 ROAS',
            'meta_ads' => 'Meta Ads 購買 ROAS',
            'google_ads' => 'Google Ads 轉換價值 ROAS',
            default => null,
        };
        $targetRoas = is_numeric($target->paid_media_goal_roas) && (float) $target->paid_media_goal_roas > 0
            ? (float) $target->paid_media_goal_roas
            : null;
        $metric = match ($source) {
            'ga4' => 'roas',
            'meta_ads' => 'meta_ads_purchase_roas',
            'google_ads' => 'google_ads_conversion_value_roas',
            default => null,
        };
        $unroundedMetric = match ($source) {
            'ga4' => 'roas_unrounded',
            'meta_ads' => 'meta_ads_purchase_roas_unrounded',
            'google_ads' => 'google_ads_conversion_value_roas_unrounded',
            default => null,
        };
        $actualRoas = $unroundedMetric !== null && is_numeric($efficiency[$unroundedMetric] ?? null)
            ? (float) $efficiency[$unroundedMetric]
            : ($metric !== null && is_numeric($efficiency[$metric] ?? null) ? (float) $efficiency[$metric] : null);
        $status = 'available';
        $unavailableReason = is_string($efficiency['roas_unavailable_reason'] ?? null)
            ? $efficiency['roas_unavailable_reason']
            : null;

        if ($source === null || $targetRoas === null) {
            $status = 'not_configured';
            $actualRoas = null;
        } elseif ($target->starts_on === null || $target->ends_on === null) {
            $status = 'missing_period';
            $actualRoas = null;
        } elseif ($actualRoas === null) {
            $status = $source === 'ga4' ? $this->ga4UnavailableStatus($unavailableReason) : 'unavailable';
        } elseif ($this->sourceIsStale(
            $source,
            $efficiency,
            $googleAnalyticsReportIsStale,
            $metaMarketingReportIsStale,
            $googleAdsReportIsStale,
        )) {
            $status = 'stale';
        }

        $canCompare = $status === 'available';

        return [
            'source' => $source,
            'source_label' => $sourceLabel,
            'target_roas' => $targetRoas,
            'actual_roas' => $actualRoas,
            'attainment_percent' => $canCompare ? round($actualRoas / $targetRoas * 100, 1) : null,
            'difference_roas' => $canCompare ? round($actualRoas - $targetRoas, 4) : null,
            'spend_source_label' => $source === 'ga4' && is_string($efficiency['roas_spend_source_label'] ?? null)
                ? $efficiency['roas_spend_source_label']
                : null,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
        ];
    }

    /** @param array<string, mixed> $efficiency */
    private function sourceIsStale(
        string $source,
        array $efficiency,
        bool $googleAnalyticsReportIsStale,
        bool $metaMarketingReportIsStale,
        bool $googleAdsReportIsStale,
    ): bool {
        return match ($source) {
            'meta_ads' => $metaMarketingReportIsStale,
            'google_ads' => $googleAdsReportIsStale,
            'ga4' => $googleAnalyticsReportIsStale || match ($efficiency['roas_spend_source'] ?? null) {
                'meta_marketing' => $metaMarketingReportIsStale,
                'google_ads' => $googleAdsReportIsStale,
                default => false,
            },
            default => false,
        };
    }

    private function ga4UnavailableStatus(?string $reason): string
    {
        return match ($reason) {
            'currency_mismatch', 'ad_platform_currency_mismatch' => 'currency_mismatch',
            'missing_spend', 'missing_spend_currency', 'zero_spend' => $reason,
            'missing_ga4', 'missing_ga4_currency' => $reason,
            default => 'unavailable',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'available' => '達成度可用',
            'missing_period' => '需設定活動期間',
            'stale' => '來源資料超過 24 小時，更新後查看達成度',
            'currency_mismatch' => 'GA4 營收與花費幣別不同',
            'missing_spend' => '尚無可配對花費',
            'zero_spend' => '花費為 0，無法計算 ROAS',
            'missing_spend_currency', 'missing_ga4_currency' => '幣別資訊不足，無法計算 GA4 ROAS',
            'missing_ga4' => '尚無 GA4 數據',
            'unavailable' => '尚無可用 ROAS 數據',
            default => '尚未設定付費媒體 ROAS 目標',
        };
    }
}
