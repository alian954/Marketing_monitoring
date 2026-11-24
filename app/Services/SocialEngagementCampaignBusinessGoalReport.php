<?php

namespace App\Services;

use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use Carbon\CarbonImmutable;

class SocialEngagementCampaignBusinessGoalReport
{
    /**
     * @return array{
     *     metric: string|null,
     *     metric_label: string|null,
     *     event_name: string|null,
     *     target_value: float|null,
     *     actual_value: float|null,
     *     goal_currency_code: string|null,
     *     report_currency_code: string|null,
     *     completion_percent: float|null,
     *     remaining_value: float|null,
     *     status: string,
     *     status_label: string,
     *     time_pacing: array{status: string, status_label: string, campaign_days: int|null, elapsed_days: int|null, elapsed_percent: float|null, expected_value: float|null, gap_value: float|null, gap_percentage_points: float|null}
     * }
     */
    public function build(
        SocialEngagementTarget $target,
        ?SocialEngagementTargetGoogleAnalyticsReport $analyticsReport,
        bool $analyticsReportIsStale,
    ): array {
        $metric = is_string($target->ga4_goal_metric) ? $target->ga4_goal_metric : null;
        $goalEventName = $metric === 'key_events'
            && is_string($target->ga4_goal_event_name)
            && trim($target->ga4_goal_event_name) !== ''
            ? trim($target->ga4_goal_event_name)
            : null;
        $metricLabel = match ($metric) {
            'key_events' => $goalEventName === null ? 'GA4 重要事件' : 'GA4 重要事件：'.$goalEventName,
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
            $reportedValue = match ($metric) {
                'key_events' => $goalEventName === null
                    ? $analyticsReport->key_events
                    : $this->keyEventsForNamedEvent($analyticsReport, $goalEventName),
                'total_revenue' => $analyticsReport->total_revenue,
                default => null,
            };

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
        } elseif ($analyticsReport === null) {
            $status = 'unavailable';
        } elseif ($analyticsReportIsStale) {
            $status = 'stale';
        } elseif ($actualValue === null) {
            $status = $goalEventName === null ? 'unavailable' : 'event_unavailable';
        } elseif ($metric === 'total_revenue' && $reportCurrencyCode === null) {
            $status = 'currency_unknown';
        } elseif ($metric === 'total_revenue' && $reportCurrencyCode !== $goalCurrencyCode) {
            $status = 'currency_mismatch';
        }

        $canCompare = $status === 'available';
        $timePacing = $this->timePacing($target, $canCompare, $targetValue, $actualValue);

        return [
            'metric' => $metricLabel === null ? null : $metric,
            'metric_label' => $metricLabel,
            'event_name' => $goalEventName,
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
            'time_pacing' => $timePacing,
        ];
    }

    private function keyEventsForNamedEvent(
        SocialEngagementTargetGoogleAnalyticsReport $analyticsReport,
        string $eventName,
    ): ?float {
        $eventNameReport = $analyticsReport->event_name_report;
        $expiresAt = $analyticsReport->event_name_expires_at;
        $windowStartOn = $analyticsReport->starts_on?->toDateString();
        $windowEndOn = $analyticsReport->ends_on?->toDateString();

        if (! is_array($eventNameReport)
            || ($eventNameReport['status'] ?? null) !== 'complete'
            || $expiresAt?->isFuture() !== true
            || $windowStartOn === null
            || $windowEndOn === null
            || ($eventNameReport['window_start_on'] ?? null) !== $windowStartOn
            || ($eventNameReport['window_end_on'] ?? null) !== $windowEndOn
            || ! is_array($eventNameReport['rows'] ?? null)
            || ! array_is_list($eventNameReport['rows'])) {
            return null;
        }

        foreach ($eventNameReport['rows'] as $row) {
            if (! is_array($row) || ($row['event_name'] ?? null) !== $eventName) {
                continue;
            }

            $keyEvents = $row['key_events'] ?? null;

            return is_numeric($keyEvents) && is_finite((float) $keyEvents) && (float) $keyEvents >= 0
                ? (float) $keyEvents
                : null;
        }

        return null;
    }

    /**
     * @return array{status: string, status_label: string, campaign_days: int|null, elapsed_days: int|null, elapsed_percent: float|null, expected_value: float|null, gap_value: float|null, gap_percentage_points: float|null}
     */
    private function timePacing(
        SocialEngagementTarget $target,
        bool $hasComparableGoal,
        ?float $targetValue,
        ?float $actualValue,
    ): array {
        $startsOn = $target->starts_on?->timezone('Asia/Taipei')->startOfDay();
        $endsOn = $target->ends_on?->timezone('Asia/Taipei')->startOfDay();
        $hasCampaignPeriod = $startsOn !== null && $endsOn !== null && ! $endsOn->lessThan($startsOn);
        $today = CarbonImmutable::now('Asia/Taipei')->startOfDay();
        $campaignDays = $hasCampaignPeriod ? (int) $startsOn->diffInDays($endsOn) + 1 : null;
        $hasStarted = $hasCampaignPeriod && ! $today->lessThan($startsOn);
        $hasEnded = $hasCampaignPeriod && $today->greaterThan($endsOn);

        $status = match (true) {
            ! $hasCampaignPeriod => 'missing_period',
            ! $hasStarted => 'not_started',
            $hasEnded => 'completed',
            ! $hasComparableGoal || $targetValue === null || $actualValue === null => 'data_unavailable',
            default => 'in_progress',
        };
        $elapsedDays = $status === 'in_progress' && $startsOn !== null && $campaignDays !== null
            ? min($campaignDays, (int) $startsOn->diffInDays($today) + 1)
            : null;
        $elapsedFraction = $status === 'in_progress' && $campaignDays !== null && $elapsedDays !== null
            ? $elapsedDays / $campaignDays
            : null;
        $expectedValue = $elapsedFraction !== null && $targetValue !== null
            ? round($targetValue * $elapsedFraction, 4)
            : null;
        $gapValue = $expectedValue !== null && $actualValue !== null
            ? round($actualValue - $expectedValue, 4)
            : null;
        $gapPercentagePoints = $elapsedFraction !== null && $targetValue !== null && $targetValue > 0 && $actualValue !== null
            ? round(($actualValue / $targetValue * 100) - ($elapsedFraction * 100), 1)
            : null;

        if ($status === 'in_progress') {
            $status = match (true) {
                $gapPercentagePoints > 0 => 'ahead',
                $gapPercentagePoints < 0 => 'behind',
                default => 'on_track',
            };
        }

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'ahead' => '高於線性進度',
                'behind' => '低於線性進度',
                'on_track' => '符合線性進度',
                'not_started' => '活動尚未開始',
                'completed' => '活動已結束',
                'missing_period' => '活動期間無效',
                default => '資料不足，暫不比較',
            },
            'campaign_days' => $campaignDays,
            'elapsed_days' => $elapsedDays,
            'elapsed_percent' => $elapsedFraction === null ? null : round($elapsedFraction * 100, 1),
            'expected_value' => $expectedValue,
            'gap_value' => $gapValue,
            'gap_percentage_points' => $gapPercentagePoints,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'available' => '進度可用',
            'missing_period' => '需設定活動期間',
            'unavailable' => '尚無可用 GA4 數據',
            'event_unavailable' => '指定 GA4 事件尚無可比較資料',
            'stale' => 'GA4 資料超過 24 小時，請更新後查看達成率',
            'currency_unknown' => 'GA4 未提供幣別，無法比較營收',
            'currency_mismatch' => '目標與 GA4 幣別不同，無法比較',
            default => '尚未設定 GA4 商業目標',
        };
    }
}
