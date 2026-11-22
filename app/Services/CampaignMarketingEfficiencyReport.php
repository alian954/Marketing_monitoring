<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;

class CampaignMarketingEfficiencyReport
{
    /**
     * @return array{
     *     ad_spend_amount: string|null,
     *     ad_spend_currency: string|null,
     *     ad_spend_source: string|null,
     *     ad_spend_source_label: string|null,
     *     platform_currency_mismatch: bool,
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
        ?SocialEngagementTargetMetaMarketingReport $metaMarketingReport = null,
        ?SocialEngagementTargetGoogleAdsReport $googleAdsReport = null,
    ): array {
        $metaSpendAmount = $metaMarketingReport?->spend_amount;
        $metaSpendCurrency = $this->currencyCode($metaMarketingReport?->currency_code);
        $googleAdsSpendAmount = $googleAdsReport?->spend_amount;
        $googleAdsSpendCurrency = $this->currencyCode($googleAdsReport?->currency_code);
        $hasDifferentPlatformCurrencies = $metaSpendAmount !== null
            && $googleAdsSpendAmount !== null
            && $metaSpendCurrency !== $googleAdsSpendCurrency;

        if ($hasDifferentPlatformCurrencies) {
            $spendAmountValue = null;
            $spendCurrencyValue = null;
            $spendSource = 'currency_mismatch';
        } elseif ($metaSpendAmount !== null && $googleAdsSpendAmount !== null) {
            $spendAmountValue = number_format((float) $metaSpendAmount + (float) $googleAdsSpendAmount, 4, '.', '');
            $spendCurrencyValue = $metaSpendCurrency;
            $spendSource = 'combined_paid_ads';
        } elseif ($metaSpendAmount !== null) {
            $spendAmountValue = $metaSpendAmount;
            $spendCurrencyValue = $metaSpendCurrency;
            $spendSource = 'meta_marketing';
        } elseif ($googleAdsSpendAmount !== null) {
            $spendAmountValue = $googleAdsSpendAmount;
            $spendCurrencyValue = $googleAdsSpendCurrency;
            $spendSource = 'google_ads';
        } else {
            $spendAmountValue = $target->ad_spend_amount;
            $spendCurrencyValue = $target->ad_spend_currency;
            $spendSource = $target->ad_spend_amount !== null ? 'manual' : null;
        }

        $spendAmount = $spendAmountValue === null ? null : (float) $spendAmountValue;
        $spendCurrency = $this->currencyCode(is_string($spendCurrencyValue) ? $spendCurrencyValue : null);
        $googleAnalyticsCurrency = is_string($googleAnalyticsReport?->currency_code) && trim($googleAnalyticsReport->currency_code) !== ''
            ? strtoupper(trim($googleAnalyticsReport->currency_code))
            : null;
        $roasUnavailableReason = match (true) {
            $hasDifferentPlatformCurrencies => 'ad_platform_currency_mismatch',
            $spendAmount === null => 'missing_spend',
            $spendAmount <= 0 => 'zero_spend',
            $googleAnalyticsReport === null => 'missing_ga4',
            $spendCurrency === null => 'missing_spend_currency',
            $googleAnalyticsCurrency === null => 'missing_ga4_currency',
            $spendCurrency !== $googleAnalyticsCurrency => 'currency_mismatch',
            default => null,
        };

        return [
            'ad_spend_amount' => $spendAmountValue,
            'ad_spend_currency' => $spendCurrency,
            'ad_spend_source' => $spendSource,
            'ad_spend_source_label' => match ($spendSource) {
                'meta_marketing' => 'Meta Ads',
                'google_ads' => 'Google Ads',
                'combined_paid_ads' => 'Meta Ads + Google Ads',
                'currency_mismatch' => '多平台幣別不同',
                'manual' => '人工記錄',
                default => null,
            },
            'platform_currency_mismatch' => $hasDifferentPlatformCurrencies,
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

    private function currencyCode(?string $currencyCode): ?string
    {
        return is_string($currencyCode) && preg_match('/^[A-Za-z]{3}$/', trim($currencyCode)) === 1
            ? strtoupper(trim($currencyCode))
            : null;
    }
}
