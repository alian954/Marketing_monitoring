<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;

class CampaignMarketingEfficiencyReport
{
    /**
     * @return array{
     *     ad_spend_amount: string|null,
     *     ad_spend_currency: string|null,
     *     roas: float|null,
     *     roas_unavailable_reason: string|null,
     *     cost_per_session: float|null,
     *     cost_per_key_event: float|null,
     *     cost_per_public_interaction: float|null
     * }
     */
    public function build(
        SocialEngagementTarget $target,
        ?SocialEngagementTargetGoogleAnalyticsReport $googleAnalyticsReport,
        ?int $publicInteractionTotal,
    ): array {
        $spendAmount = $target->ad_spend_amount === null ? null : (float) $target->ad_spend_amount;
        $spendCurrency = is_string($target->ad_spend_currency) && trim($target->ad_spend_currency) !== ''
            ? strtoupper(trim($target->ad_spend_currency))
            : null;
        $googleAnalyticsCurrency = is_string($googleAnalyticsReport?->currency_code) && trim($googleAnalyticsReport->currency_code) !== ''
            ? strtoupper(trim($googleAnalyticsReport->currency_code))
            : null;
        $roasUnavailableReason = match (true) {
            $spendAmount === null => 'missing_spend',
            $spendAmount <= 0 => 'zero_spend',
            $googleAnalyticsReport === null => 'missing_ga4',
            $spendCurrency === null => 'missing_spend_currency',
            $googleAnalyticsCurrency === null => 'missing_ga4_currency',
            $spendCurrency !== $googleAnalyticsCurrency => 'currency_mismatch',
            default => null,
        };

        return [
            'ad_spend_amount' => $target->ad_spend_amount,
            'ad_spend_currency' => $spendCurrency,
            'roas' => $roasUnavailableReason === null && $googleAnalyticsReport !== null && $spendAmount !== null
                ? round((float) $googleAnalyticsReport->total_revenue / $spendAmount, 2)
                : null,
            'roas_unavailable_reason' => $roasUnavailableReason,
            'cost_per_session' => $spendAmount !== null && $googleAnalyticsReport !== null && $googleAnalyticsReport->sessions > 0
                ? round($spendAmount / $googleAnalyticsReport->sessions, 4)
                : null,
            'cost_per_key_event' => $spendAmount !== null && $googleAnalyticsReport !== null && (float) $googleAnalyticsReport->key_events > 0
                ? round($spendAmount / (float) $googleAnalyticsReport->key_events, 4)
                : null,
            'cost_per_public_interaction' => $spendAmount !== null && $publicInteractionTotal !== null && $publicInteractionTotal > 0
                ? round($spendAmount / $publicInteractionTotal, 4)
                : null,
        ];
    }
}
