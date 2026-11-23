<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use Carbon\CarbonImmutable;

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
     *     roas_spend_amount: string|null,
     *     roas_spend_currency: string|null,
     *     roas_spend_source: string|null,
     *     roas_spend_source_label: string|null,
     *     roas_unavailable_reason: string|null,
     *     roas_unrounded: float|null,
     *     paid_ad_clicks: int|null,
     *     paid_click_source: string|null,
     *     paid_click_source_label: string|null,
     *     ga4_sessions_per_paid_click: float|null,
     *     paid_click_session_status: string,
     *     paid_click_session_status_label: string,
     *     meta_ads_omni_purchase_value: string|null,
     *     meta_ads_purchase_roas: float|null,
     *     meta_ads_purchase_roas_unrounded: float|null,
     *     google_ads_conversion_value_roas: float|null,
     *     google_ads_conversion_value_roas_unrounded: float|null,
     *     cost_per_session: float|null,
     *     cost_per_key_event: float|null,
     *     cost_per_public_interaction: float|null,
     *     observed_campaign_interaction_change: int|null,
     *     comparable_interaction_post_count: int,
     *     cost_per_campaign_interaction_change: float|null,
     *     meta_ads_frequency: float|null,
     *     meta_ads_ctr_percent: float|null,
     *     meta_ads_cost_per_click: float|null,
     *     meta_ads_cost_per_thousand_impressions: float|null,
     *     google_ads_ctr_percent: float|null,
     *     google_ads_cost_per_click: float|null,
     *     google_ads_cost_per_thousand_impressions: float|null,
     *     google_ads_cost_per_conversion: float|null,
     *     google_ads_conversion_rate_percent: float|null,
     *     campaign_budget_amount: string|null,
     *     campaign_budget_currency: string|null,
     *     budget_utilization_percent: float|null,
     *     budget_elapsed_percent: float|null,
     *     budget_expected_spend_amount: float|null,
     *     budget_pacing_difference_amount: float|null,
     *     budget_projected_spend_amount: float|null,
     *     budget_projected_overrun_amount: float|null,
     *     budget_status: string
     * }
     */
    public function build(
        SocialEngagementTarget $target,
        ?SocialEngagementTargetGoogleAnalyticsReport $googleAnalyticsReport,
        ?int $publicInteractionTotal,
        ?SocialEngagementTargetMetaMarketingReport $metaMarketingReport = null,
        ?SocialEngagementTargetGoogleAdsReport $googleAdsReport = null,
        ?int $observedCampaignInteractionChange = null,
        int $comparableInteractionPostCount = 0,
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
        [$roasSpendAmount, $roasSpendCurrencyValue, $roasSpendSource] = $this->roasSpendSource(
            $target,
            $metaSpendAmount,
            $metaSpendCurrency,
            $googleAdsSpendAmount,
            $googleAdsSpendCurrency,
        );
        $roasSpendCurrency = $this->currencyCode($roasSpendCurrencyValue);
        $metaImpressions = $metaMarketingReport?->impressions ?? 0;
        $metaReach = $metaMarketingReport?->reach ?? 0;
        $metaClicks = $metaMarketingReport?->clicks ?? 0;
        $googleAdsImpressions = $googleAdsReport?->impressions ?? 0;
        $googleAdsClicks = $googleAdsReport?->clicks ?? 0;
        $paidClickSessionDiagnostic = $this->paidClickSessionDiagnostic(
            $target,
            $googleAnalyticsReport,
            $metaMarketingReport,
            $googleAdsReport,
        );
        $googleAdsConversions = $googleAdsReport === null ? null : (float) $googleAdsReport->conversions;
        $metaPurchaseValue = $this->metaOmniPurchaseValue($metaMarketingReport?->action_values);
        $metaCurrency = $this->currencyCode($metaMarketingReport?->currency_code);
        $metaRoasUnrounded = $metaPurchaseValue !== null
            && $metaSpendAmount !== null
            && (float) $metaSpendAmount > 0
            && $metaCurrency !== null
            ? (float) $metaPurchaseValue / (float) $metaSpendAmount
            : null;
        $metaRoas = $metaRoasUnrounded === null ? null : round($metaRoasUnrounded, 2);
        $googleAdsConversionValue = $googleAdsReport?->conversions_value;
        $googleAdsCurrency = $this->currencyCode($googleAdsReport?->currency_code);
        $googleAdsRoasUnrounded = $googleAdsConversionValue !== null
            && $googleAdsSpendAmount !== null
            && (float) $googleAdsSpendAmount > 0
            && $googleAdsCurrency !== null
            ? (float) $googleAdsConversionValue / (float) $googleAdsSpendAmount
            : null;
        $googleAdsRoas = $googleAdsRoasUnrounded === null ? null : round($googleAdsRoasUnrounded, 2);
        $googleAnalyticsCurrency = is_string($googleAnalyticsReport?->currency_code) && trim($googleAnalyticsReport->currency_code) !== ''
            ? strtoupper(trim($googleAnalyticsReport->currency_code))
            : null;
        $roasUnavailableReason = match (true) {
            $roasSpendAmount === null => 'missing_spend',
            (float) $roasSpendAmount <= 0 => 'zero_spend',
            $googleAnalyticsReport === null => 'missing_ga4',
            $roasSpendCurrency === null => 'missing_spend_currency',
            $googleAnalyticsCurrency === null => 'missing_ga4_currency',
            $roasSpendCurrency !== $googleAnalyticsCurrency => 'currency_mismatch',
            default => null,
        };
        $roasUnrounded = $roasUnavailableReason === null && $googleAnalyticsReport !== null && $roasSpendAmount !== null
            ? (float) $googleAnalyticsReport->total_revenue / (float) $roasSpendAmount
            : null;
        $budgetPacing = $this->budgetPacing($target, $spendAmount, $spendCurrency, $spendSource);

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
            'roas' => $roasUnavailableReason === null && $googleAnalyticsReport !== null && $roasSpendAmount !== null
                ? round((float) $googleAnalyticsReport->total_revenue / (float) $roasSpendAmount, 2)
                : null,
            'roas_spend_amount' => $roasSpendAmount,
            'roas_spend_currency' => $roasSpendCurrency,
            'roas_spend_source' => $roasSpendSource,
            'roas_spend_source_label' => match ($roasSpendSource) {
                'google_ads' => 'Google Ads 花費',
                'meta_marketing' => 'Meta Ads 花費',
                'manual' => '人工記錄花費',
                default => null,
            },
            'roas_unavailable_reason' => $roasUnavailableReason,
            'roas_unrounded' => $roasUnrounded,
            ...$paidClickSessionDiagnostic,
            'meta_ads_omni_purchase_value' => $metaPurchaseValue,
            'meta_ads_purchase_roas' => $metaRoas,
            'meta_ads_purchase_roas_unrounded' => $metaRoasUnrounded,
            'google_ads_conversion_value_roas' => $googleAdsRoas,
            'google_ads_conversion_value_roas_unrounded' => $googleAdsRoasUnrounded,
            'cost_per_session' => $roasSpendAmount !== null && $googleAnalyticsReport !== null && $googleAnalyticsReport->sessions > 0
                ? round((float) $roasSpendAmount / $googleAnalyticsReport->sessions, 4)
                : null,
            'cost_per_key_event' => $roasSpendAmount !== null && $googleAnalyticsReport !== null && (float) $googleAnalyticsReport->key_events > 0
                ? round((float) $roasSpendAmount / (float) $googleAnalyticsReport->key_events, 4)
                : null,
            'cost_per_public_interaction' => $spendAmount !== null && $publicInteractionTotal !== null && $publicInteractionTotal > 0
                ? round($spendAmount / $publicInteractionTotal, 4)
                : null,
            'observed_campaign_interaction_change' => $observedCampaignInteractionChange,
            'comparable_interaction_post_count' => $comparableInteractionPostCount,
            'cost_per_campaign_interaction_change' => $spendAmount !== null
                && $observedCampaignInteractionChange !== null
                && $observedCampaignInteractionChange > 0
                    ? round($spendAmount / $observedCampaignInteractionChange, 4)
                    : null,
            'meta_ads_frequency' => $metaReach > 0
                ? round($metaImpressions / $metaReach, 4)
                : null,
            'meta_ads_ctr_percent' => $metaImpressions > 0
                ? round($metaClicks / $metaImpressions * 100, 2)
                : null,
            'meta_ads_cost_per_click' => $metaSpendAmount !== null && $metaClicks > 0
                ? round((float) $metaSpendAmount / $metaClicks, 4)
                : null,
            'meta_ads_cost_per_thousand_impressions' => $metaSpendAmount !== null && $metaImpressions > 0
                ? round((float) $metaSpendAmount / $metaImpressions * 1000, 4)
                : null,
            'google_ads_ctr_percent' => $googleAdsImpressions > 0
                ? round($googleAdsClicks / $googleAdsImpressions * 100, 2)
                : null,
            'google_ads_cost_per_click' => $googleAdsSpendAmount !== null && $googleAdsClicks > 0
                ? round((float) $googleAdsSpendAmount / $googleAdsClicks, 4)
                : null,
            'google_ads_cost_per_thousand_impressions' => $googleAdsSpendAmount !== null && $googleAdsImpressions > 0
                ? round((float) $googleAdsSpendAmount / $googleAdsImpressions * 1000, 4)
                : null,
            'google_ads_cost_per_conversion' => $googleAdsSpendAmount !== null && $googleAdsConversions !== null && $googleAdsConversions > 0
                ? round((float) $googleAdsSpendAmount / $googleAdsConversions, 4)
                : null,
            'google_ads_conversion_rate_percent' => $googleAdsConversions !== null && $googleAdsClicks > 0
                ? round($googleAdsConversions / $googleAdsClicks * 100, 2)
                : null,
            ...$budgetPacing,
        ];
    }

    /** @return array{string|null, string|null, string|null} */
    private function roasSpendSource(
        SocialEngagementTarget $target,
        mixed $metaSpendAmount,
        ?string $metaSpendCurrency,
        mixed $googleAdsSpendAmount,
        ?string $googleAdsSpendCurrency,
    ): array {
        if ($target->googleAnalyticsAttributionMode() === 'google_ads_auto_tagging') {
            return [$googleAdsSpendAmount, $googleAdsSpendCurrency, 'google_ads'];
        }

        if ($metaSpendAmount !== null) {
            return [$metaSpendAmount, $metaSpendCurrency, 'meta_marketing'];
        }

        if ($target->ad_spend_amount !== null) {
            return [$target->ad_spend_amount, $target->ad_spend_currency, 'manual'];
        }

        return [null, null, null];
    }

    /**
     * @return array{paid_ad_clicks: int|null, paid_click_source: string|null, paid_click_source_label: string|null, ga4_sessions_per_paid_click: float|null, paid_click_session_status: string, paid_click_session_status_label: string}
     */
    private function paidClickSessionDiagnostic(
        SocialEngagementTarget $target,
        ?SocialEngagementTargetGoogleAnalyticsReport $googleAnalyticsReport,
        ?SocialEngagementTargetMetaMarketingReport $metaMarketingReport,
        ?SocialEngagementTargetGoogleAdsReport $googleAdsReport,
    ): array {
        $googleAdsIsConfigured = is_string($target->google_ads_campaign_id)
            && trim($target->google_ads_campaign_id) !== '';
        $metaIsConfigured = is_string($target->meta_campaign_id)
            && trim($target->meta_campaign_id) !== '';

        if (! $metaIsConfigured && ! $googleAdsIsConfigured) {
            return [
                'paid_ad_clicks' => null,
                'paid_click_source' => null,
                'paid_click_source_label' => null,
                'ga4_sessions_per_paid_click' => null,
                'paid_click_session_status' => 'not_configured',
                'paid_click_session_status_label' => '尚未設定廣告活動',
            ];
        }

        $clickSource = $googleAdsIsConfigured ? 'google_ads' : 'meta_marketing';
        $clickSourceLabel = $googleAdsIsConfigured ? 'Google Ads' : 'Meta Ads';
        $clickCount = $googleAdsIsConfigured
            ? $googleAdsReport?->clicks
            : $metaMarketingReport?->clicks;

        if (! is_int($clickCount) || $clickCount < 0) {
            return [
                'paid_ad_clicks' => null,
                'paid_click_source' => $clickSource,
                'paid_click_source_label' => $clickSourceLabel,
                'ga4_sessions_per_paid_click' => null,
                'paid_click_session_status' => 'missing_paid_clicks',
                'paid_click_session_status_label' => '缺少已設定廣告來源的點擊資料',
            ];
        }

        $paidAdClicks = $clickCount;
        $ga4Sessions = $googleAnalyticsReport?->sessions;

        if (! is_int($ga4Sessions) || $ga4Sessions < 0) {
            return [
                'paid_ad_clicks' => $paidAdClicks,
                'paid_click_source' => $clickSource,
                'paid_click_source_label' => $clickSourceLabel,
                'ga4_sessions_per_paid_click' => null,
                'paid_click_session_status' => 'missing_ga4',
                'paid_click_session_status_label' => '缺少 GA4 工作階段資料',
            ];
        }

        if ($paidAdClicks === 0) {
            return [
                'paid_ad_clicks' => $paidAdClicks,
                'paid_click_source' => $clickSource,
                'paid_click_source_label' => $clickSourceLabel,
                'ga4_sessions_per_paid_click' => null,
                'paid_click_session_status' => 'zero_clicks',
                'paid_click_session_status_label' => '零廣告點擊，無法計算',
            ];
        }

        return [
            'paid_ad_clicks' => $paidAdClicks,
            'paid_click_source' => $clickSource,
            'paid_click_source_label' => $clickSourceLabel,
            'ga4_sessions_per_paid_click' => round($ga4Sessions / $paidAdClicks, 4),
            'paid_click_session_status' => 'complete',
            'paid_click_session_status_label' => '資料完整',
        ];
    }

    /** @return array{campaign_budget_amount: string|null, campaign_budget_currency: string|null, budget_utilization_percent: float|null, budget_elapsed_percent: float|null, budget_expected_spend_amount: float|null, budget_pacing_difference_amount: float|null, budget_projected_spend_amount: float|null, budget_projected_overrun_amount: float|null, budget_status: string} */
    private function budgetPacing(
        SocialEngagementTarget $target,
        ?float $spendAmount,
        ?string $spendCurrency,
        ?string $spendSource,
    ): array {
        $budgetAmount = $target->campaign_budget_amount;
        $budgetCurrency = $this->currencyCode($target->campaign_budget_currency);
        $budgetAmountValue = is_string($budgetAmount) ? $budgetAmount : null;
        $budgetAmountNumber = $budgetAmountValue === null ? null : (float) $budgetAmountValue;
        $currencyMatches = $spendCurrency !== null && $spendCurrency === $budgetCurrency;
        $hasComparableSpend = $spendAmount !== null && $currencyMatches;
        $startsOn = $target->starts_on?->timezone('Asia/Taipei')->startOfDay();
        $endsOn = $target->ends_on?->timezone('Asia/Taipei')->startOfDay();
        $today = CarbonImmutable::now('Asia/Taipei')->startOfDay();
        $hasCampaignPeriod = $startsOn !== null && $endsOn !== null && ! $endsOn->lessThan($startsOn);
        $campaignDays = $hasCampaignPeriod ? (int) $startsOn->diffInDays($endsOn) + 1 : null;
        $campaignHasStarted = $hasCampaignPeriod && ! $today->lessThan($startsOn);
        $campaignHasEnded = $hasCampaignPeriod && $today->greaterThan($endsOn);
        $campaignIsInProgress = $campaignHasStarted && ! $campaignHasEnded;
        $elapsedDays = $campaignIsInProgress
            ? min($campaignDays, (int) $startsOn->diffInDays($today) + 1)
            : null;
        $elapsedFraction = $campaignIsInProgress && $campaignDays !== null && $elapsedDays !== null
            ? $elapsedDays / $campaignDays
            : null;
        $expectedSpend = $campaignIsInProgress && $budgetAmountNumber !== null && $elapsedFraction !== null
            ? round($budgetAmountNumber * $elapsedFraction, 4)
            : null;
        $projectedSpend = $campaignIsInProgress && $hasComparableSpend && $spendAmount !== null && $elapsedFraction !== null && $elapsedFraction > 0
            ? round($spendAmount / $elapsedFraction, 4)
            : null;
        $status = match (true) {
            $budgetAmountValue === null => 'not_set',
            $budgetAmountNumber === 0.0 => 'zero_budget',
            $spendSource === 'currency_mismatch' => 'platform_currency_mismatch',
            $spendAmount === null => 'missing_spend',
            $budgetCurrency === null => 'missing_budget_currency',
            $spendCurrency === null => 'missing_spend_currency',
            ! $currencyMatches => 'currency_mismatch',
            ! $hasCampaignPeriod => 'missing_campaign_period',
            ! $campaignHasStarted => 'not_started',
            $campaignHasEnded => 'completed',
            default => 'in_progress',
        };

        return [
            'campaign_budget_amount' => $budgetAmountValue,
            'campaign_budget_currency' => $budgetCurrency,
            'budget_utilization_percent' => $hasComparableSpend && $budgetAmountNumber !== null && $budgetAmountNumber > 0
                ? round($spendAmount / $budgetAmountNumber * 100, 2)
                : null,
            'budget_elapsed_percent' => $hasCampaignPeriod
                ? ($campaignHasEnded ? 100.0 : ($campaignHasStarted && $campaignDays !== null && $elapsedDays !== null
                    ? round($elapsedDays / $campaignDays * 100, 2)
                    : 0.0))
                : null,
            'budget_expected_spend_amount' => $expectedSpend,
            'budget_pacing_difference_amount' => $expectedSpend !== null && $hasComparableSpend && $spendAmount !== null
                ? round($spendAmount - $expectedSpend, 4)
                : null,
            'budget_projected_spend_amount' => $projectedSpend,
            'budget_projected_overrun_amount' => $projectedSpend !== null && $budgetAmountNumber !== null
                ? round($projectedSpend - $budgetAmountNumber, 4)
                : null,
            'budget_status' => $status,
        ];
    }

    private function currencyCode(?string $currencyCode): ?string
    {
        return is_string($currencyCode) && preg_match('/^[A-Za-z]{3}$/', trim($currencyCode)) === 1
            ? strtoupper(trim($currencyCode))
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
}
