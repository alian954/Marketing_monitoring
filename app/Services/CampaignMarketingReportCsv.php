<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use Carbon\CarbonImmutable;

class CampaignMarketingReportCsv
{
    /**
     * @param  array{
     *     target: SocialEngagementTarget,
     *     report: array<string, mixed>,
     *     googleAnalyticsReport: SocialEngagementTargetGoogleAnalyticsReport|null,
     *     googleAnalyticsReportIsStale: bool,
     *     metaMarketingReport: SocialEngagementTargetMetaMarketingReport|null,
     *     metaMarketingReportIsStale: bool,
     *     googleAdsReport: SocialEngagementTargetGoogleAdsReport|null,
     *     googleAdsReportIsStale: bool,
     *     googleAnalyticsCampaignName: string,
     *     googleAnalyticsSourceName: string|null,
     *     googleAnalyticsMediumName: string|null,
     *     adSpendAnalysis: array<string, mixed>
     * }  $data
     * @return array{headers: list<string>, fields: list<string>}
     */
    public function build(array $data): array
    {
        $target = $data['target'];
        $report = $data['report'];
        $metrics = $report['metrics'];
        $goalForecast = $report['goal_forecast'];
        $analyticsReport = $data['googleAnalyticsReport'];
        $metaMarketingReport = $data['metaMarketingReport'];
        $googleAdsReport = $data['googleAdsReport'];
        $adSpendAnalysis = $data['adSpendAnalysis'];
        $roasStatus = match ($adSpendAnalysis['roas_unavailable_reason']) {
            null => '可計算',
            'ad_platform_currency_mismatch' => 'Meta Ads 與 Google Ads 幣別不一致',
            'missing_spend' => '缺少投放花費',
            'zero_spend' => '投放花費為零',
            'missing_ga4' => '缺少 GA4 數據',
            'missing_spend_currency' => '缺少投放幣別',
            'missing_ga4_currency' => '缺少 GA4 幣別',
            'currency_mismatch' => 'GA4 營收與投放幣別不一致',
            default => '無法計算',
        };
        $headers = [
            'campaign_tag',
            'report_generated_at_asia_taipei',
            'social_platform',
            'social_platform_label',
            'campaign_start',
            'campaign_end',
            'target_interactions',
            'public_interactions',
            'observed_campaign_interaction_change',
            'comparable_interaction_posts',
            'target_completion_percent',
            'interaction_goal_forecast_status',
            'interaction_goal_forecast_confidence',
            'interaction_goal_forecast_comparable_posts',
            'interaction_goal_forecast_sample_coverage_percent',
            'interaction_goal_forecast_fresh_read_coverage_percent',
            'interaction_goal_forecast_average_daily_change',
            'interaction_goal_forecast_projected_interactions',
            'interaction_goal_forecast_projected_completion_percent',
            'interaction_goal_forecast_remaining_days',
            'interaction_goal_forecast_latest_read_at',
            'tracked_posts',
            'complete_read_posts',
            'posts_without_publication_date',
            'capture_coverage_percent',
            'views_total',
            'views_available_posts',
            'likes_total',
            'likes_available_posts',
            'comments_total',
            'comments_available_posts',
            'shares_total',
            'shares_available_posts',
            'average_interactions_per_post',
            'engagement_rate_percent',
            'ga4_campaign_name',
            'ga4_attribution_mode',
            'ga4_campaign_id',
            'ga4_source_name',
            'ga4_medium_name',
            'ga4_sessions',
            'ga4_engaged_sessions',
            'ga4_key_events',
            'ga4_total_revenue',
            'ga4_source_medium_breakdown_json',
            'ga4_source_medium_breakdown_truncated',
            'ga4_currency_code',
            'ga4_synced_at',
            'ga4_is_stale',
            'meta_campaign_id',
            'meta_campaign_name',
            'meta_spend_amount',
            'meta_currency_code',
            'meta_impressions',
            'meta_reach',
            'meta_clicks',
            'meta_synced_at',
            'meta_ads_is_stale',
            'meta_actions_json',
            'meta_action_values_json',
            'meta_ads_omni_purchase_value',
            'meta_ads_purchase_roas',
            'google_ads_campaign_id',
            'google_ads_campaign_name',
            'google_ads_spend_amount',
            'google_ads_currency_code',
            'google_ads_impressions',
            'google_ads_clicks',
            'google_ads_conversions',
            'google_ads_conversions_value',
            'google_ads_conversion_value_roas',
            'google_ads_synced_at',
            'google_ads_is_stale',
            'google_ads_conversion_actions_json',
            'meta_ads_frequency',
            'meta_ads_ctr_percent',
            'meta_ads_cost_per_click',
            'meta_ads_cost_per_thousand_impressions',
            'google_ads_ctr_percent',
            'google_ads_cost_per_click',
            'google_ads_cost_per_thousand_impressions',
            'google_ads_cost_per_conversion',
            'google_ads_conversion_rate_percent',
            'total_ad_spend_amount',
            'total_ad_spend_currency',
            'ad_spend_source',
            'campaign_budget_amount',
            'campaign_budget_currency',
            'budget_utilization_percent',
            'budget_elapsed_percent',
            'budget_expected_spend_amount',
            'budget_pacing_difference_amount',
            'budget_projected_spend_amount',
            'budget_projected_overrun_amount',
            'budget_status',
            'roas',
            'roas_status',
            'cost_per_session',
            'cost_per_key_event',
            'cost_per_public_interaction',
            'cost_per_campaign_interaction_change',
            'paid_ad_clicks',
            'paid_click_source',
            'ga4_sessions_per_paid_click',
            'paid_click_session_status',
        ];
        $fields = [
            $target->tag,
            $this->formatDateTime($report['generated_at']),
            $target->social_platform,
            $report['platform_label'],
            $target->starts_on?->toDateString() ?? '',
            $target->ends_on?->toDateString() ?? '',
            (string) $target->target_interactions,
            $report['interaction_total'] === null ? '' : (string) $report['interaction_total'],
            $report['observed_interaction_change'] === null ? '' : (string) $report['observed_interaction_change'],
            (string) $report['comparable_interaction_post_count'],
            $this->formatDecimal($report['target_completion_percent'], 1),
            $goalForecast['status_label'],
            $goalForecast['confidence_label'] ?? '',
            (string) $goalForecast['comparable_post_count'],
            $this->formatDecimal($goalForecast['sample_coverage_percent'], 1),
            $this->formatDecimal($goalForecast['fresh_read_coverage_percent'], 1),
            $this->formatDecimal($goalForecast['average_daily_interaction_change'], 1),
            $goalForecast['projected_interaction_total'] === null ? '' : (string) $goalForecast['projected_interaction_total'],
            $this->formatDecimal($goalForecast['projected_completion_percent'], 1),
            $this->formatDecimal($goalForecast['remaining_days'], 1),
            $this->formatDateTime($goalForecast['latest_observed_at']),
            (string) $report['tracked_post_count'],
            (string) $report['complete_read_count'],
            (string) $report['unknown_publication_date_count'],
            $this->formatDecimal($report['capture_coverage_percent'], 1),
            $metrics['views']['total'] === null ? '' : (string) $metrics['views']['total'],
            (string) $metrics['views']['available'],
            $metrics['likes']['total'] === null ? '' : (string) $metrics['likes']['total'],
            (string) $metrics['likes']['available'],
            $metrics['comments']['total'] === null ? '' : (string) $metrics['comments']['total'],
            (string) $metrics['comments']['available'],
            $metrics['shares']['total'] === null ? '' : (string) $metrics['shares']['total'],
            (string) $metrics['shares']['available'],
            $this->formatDecimal($report['average_interactions_per_post'], 1),
            $this->formatDecimal($report['engagement_rate_percent'], 2),
            $data['googleAnalyticsCampaignName'],
            $analyticsReport?->attribution_mode ?? '',
            $analyticsReport?->campaign_id ?? '',
            $data['googleAnalyticsSourceName'] ?? '',
            $data['googleAnalyticsMediumName'] ?? '',
            $analyticsReport === null ? '' : (string) $analyticsReport->sessions,
            $analyticsReport === null ? '' : (string) $analyticsReport->engaged_sessions,
            $analyticsReport?->key_events ?? '',
            $analyticsReport?->total_revenue ?? '',
            $analyticsReport?->source_medium_breakdown === null
                ? ''
                : $this->encodeJson($analyticsReport->source_medium_breakdown),
            $analyticsReport === null ? '' : ($analyticsReport->source_medium_breakdown_truncated ? 'true' : 'false'),
            $analyticsReport?->currency_code ?? '',
            $this->formatDateTime($analyticsReport?->synced_at),
            $analyticsReport === null ? '' : ($data['googleAnalyticsReportIsStale'] ? 'true' : 'false'),
            $metaMarketingReport?->campaign_id ?? '',
            $metaMarketingReport?->campaign_name ?? '',
            $metaMarketingReport?->spend_amount ?? '',
            $metaMarketingReport?->currency_code ?? '',
            $metaMarketingReport === null ? '' : (string) $metaMarketingReport->impressions,
            $metaMarketingReport === null ? '' : (string) $metaMarketingReport->reach,
            $metaMarketingReport === null ? '' : (string) $metaMarketingReport->clicks,
            $this->formatDateTime($metaMarketingReport?->synced_at),
            $metaMarketingReport === null ? '' : ($data['metaMarketingReportIsStale'] ? 'true' : 'false'),
            $this->encodeJson($metaMarketingReport?->actions),
            $this->encodeJson($metaMarketingReport?->action_values),
            $adSpendAnalysis['meta_ads_omni_purchase_value'] ?? '',
            $this->formatDecimal($adSpendAnalysis['meta_ads_purchase_roas'], 2),
            $googleAdsReport?->campaign_id ?? '',
            $googleAdsReport?->campaign_name ?? '',
            $googleAdsReport?->spend_amount ?? '',
            $googleAdsReport?->currency_code ?? '',
            $googleAdsReport === null ? '' : (string) $googleAdsReport->impressions,
            $googleAdsReport === null ? '' : (string) $googleAdsReport->clicks,
            $googleAdsReport?->conversions ?? '',
            $googleAdsReport?->conversions_value ?? '',
            $this->formatDecimal($adSpendAnalysis['google_ads_conversion_value_roas'], 2),
            $this->formatDateTime($googleAdsReport?->synced_at),
            $googleAdsReport === null ? '' : ($data['googleAdsReportIsStale'] ? 'true' : 'false'),
            $this->encodeJson($googleAdsReport?->conversion_actions),
            $this->formatDecimal($adSpendAnalysis['meta_ads_frequency'], 4),
            $this->formatDecimal($adSpendAnalysis['meta_ads_ctr_percent'], 2),
            $this->formatDecimal($adSpendAnalysis['meta_ads_cost_per_click'], 4),
            $this->formatDecimal($adSpendAnalysis['meta_ads_cost_per_thousand_impressions'], 4),
            $this->formatDecimal($adSpendAnalysis['google_ads_ctr_percent'], 2),
            $this->formatDecimal($adSpendAnalysis['google_ads_cost_per_click'], 4),
            $this->formatDecimal($adSpendAnalysis['google_ads_cost_per_thousand_impressions'], 4),
            $this->formatDecimal($adSpendAnalysis['google_ads_cost_per_conversion'], 4),
            $this->formatDecimal($adSpendAnalysis['google_ads_conversion_rate_percent'], 2),
            $adSpendAnalysis['ad_spend_amount'] ?? '',
            $adSpendAnalysis['ad_spend_currency'] ?? '',
            $adSpendAnalysis['ad_spend_source_label'] ?? '',
            $adSpendAnalysis['campaign_budget_amount'] ?? '',
            $adSpendAnalysis['campaign_budget_currency'] ?? '',
            $this->formatDecimal($adSpendAnalysis['budget_utilization_percent'], 2),
            $this->formatDecimal($adSpendAnalysis['budget_elapsed_percent'], 2),
            $this->formatDecimal($adSpendAnalysis['budget_expected_spend_amount'], 4),
            $this->formatDecimal($adSpendAnalysis['budget_pacing_difference_amount'], 4),
            $this->formatDecimal($adSpendAnalysis['budget_projected_spend_amount'], 4),
            $this->formatDecimal($adSpendAnalysis['budget_projected_overrun_amount'], 4),
            $adSpendAnalysis['budget_status'],
            $this->formatDecimal($adSpendAnalysis['roas'], 2),
            $roasStatus,
            $this->formatDecimal($adSpendAnalysis['cost_per_session'], 4),
            $this->formatDecimal($adSpendAnalysis['cost_per_key_event'], 4),
            $this->formatDecimal($adSpendAnalysis['cost_per_public_interaction'], 4),
            $this->formatDecimal($adSpendAnalysis['cost_per_campaign_interaction_change'], 4),
            $adSpendAnalysis['paid_ad_clicks'] === null ? '' : (string) $adSpendAnalysis['paid_ad_clicks'],
            $adSpendAnalysis['paid_click_source'] ?? '',
            $this->formatDecimal($adSpendAnalysis['ga4_sessions_per_paid_click'], 4),
            $adSpendAnalysis['paid_click_session_status'],
        ];

        return [
            'headers' => $headers,
            'fields' => $fields,
        ];
    }

    private function formatDecimal(?float $value, int $precision): string
    {
        return $value === null
            ? ''
            : number_format($value, $precision, '.', '');
    }

    private function formatDateTime(?CarbonImmutable $dateTime): string
    {
        return $dateTime === null
            ? ''
            : $dateTime->timezone('Asia/Taipei')->format('Y-m-d H:i:s');
    }

    private function encodeJson(?array $value): string
    {
        return json_encode(
            $value ?? [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
