<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;

class SocialEngagementCampaignBusinessGoalReport
{
    /**
     * @return array{
     *     metric: string|null,
     *     metric_label: string|null,
     *     target_value: float|null,
     *     actual_value: float|null,
     *     goal_currency_code: string|null,
     *     report_currency_code: string|null,
     *     completion_percent: float|null,
     *     remaining_value: float|null,
     *     status: string,
     *     status_label: string
     * }
     */
    public function build(
        SocialEngagementTarget $target,
        ?SocialEngagementTargetGoogleAnalyticsReport $analyticsReport,
        bool $analyticsReportIsStale,
    ): array {
        $metric = is_string($target->ga4_goal_metric) ? $target->ga4_goal_metric : null;
        $metricLabel = match ($metric) {
            'key_events' => 'GA4 重要事件',
            'total_revenue' => 'GA4 營收',
            default => null,
        };
        $targetValue = is_numeric($target->ga4_goal_value) ? (float) $target->ga4_goal_value : null;
        $goalCurrencyCode = $metric === 'total_revenue' && is_string($target->ga4_goal_currency)
            ? $target->ga4_goal_currency
            : null;
        $reportCurrencyCode = is_string($analyticsReport?->currency_code)
            && trim($analyticsReport->currency_code) !== ''
            ? strtoupper(trim($analyticsReport->currency_code))
            : null;
        $actualValue = null;

        if ($analyticsReport !== null && $metric !== null) {
            $reportedValue = $metric === 'key_events'
                ? $analyticsReport->key_events
                : ($metric === 'total_revenue' ? $analyticsReport->total_revenue : null);

            if (is_numeric($reportedValue)) {
                $actualValue = (float) $reportedValue;
            }
        }

        $status = 'available';

        if ($metricLabel === null || $targetValue === null || $targetValue <= 0) {
            $status = 'not_configured';
        } elseif ($target->starts_on === null || $target->ends_on === null) {
            $status = 'missing_period';
            $actualValue = null;
        } elseif ($analyticsReport === null || $actualValue === null) {
            $status = 'unavailable';
        } elseif ($analyticsReportIsStale) {
            $status = 'stale';
        } elseif ($metric === 'total_revenue' && $reportCurrencyCode === null) {
            $status = 'currency_unknown';
        } elseif ($metric === 'total_revenue' && $reportCurrencyCode !== $goalCurrencyCode) {
            $status = 'currency_mismatch';
        }

        $canCompare = $status === 'available';

        return [
            'metric' => $metricLabel === null ? null : $metric,
            'metric_label' => $metricLabel,
            'target_value' => $targetValue,
            'actual_value' => $actualValue,
            'goal_currency_code' => $goalCurrencyCode,
            'report_currency_code' => $reportCurrencyCode,
            'completion_percent' => $canCompare
                ? round($actualValue / $targetValue * 100, 1)
                : null,
            'remaining_value' => $canCompare
                ? round(max(0, $targetValue - $actualValue), 4)
                : null,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'available' => '進度可用',
            'missing_period' => '需設定活動期間',
            'unavailable' => '尚無可用 GA4 數據',
            'stale' => 'GA4 資料超過 24 小時，請更新後查看達成率',
            'currency_unknown' => 'GA4 未提供幣別，無法比較營收',
            'currency_mismatch' => '目標與 GA4 幣別不同，無法比較',
            default => '尚未設定 GA4 商業目標',
        };
    }
}
