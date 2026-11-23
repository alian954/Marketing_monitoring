<?php

namespace App\Services;

use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class DashboardCampaignMarketingSummary
{
    public function __construct(
        private CampaignMarketingEfficiencyReport $marketingEfficiencyReport,
        private SocialEngagementCampaignBusinessGoalReport $businessGoalReport,
    ) {}

    /**
     * @param  Collection<int, SocialEngagementTarget>  $targets
     * @param  array<int, array{interaction_total: int|null, available_posts: int, tracked_posts: int}>  $publicEngagementSummaries
     * @return array<int, array{
     *     google_analytics: array{status: string, status_label: string, sessions: int|null, revenue: string|null, currency_code: string|null, synced_at: CarbonImmutable|null},
     *     meta_marketing: array{status: string, status_label: string, spend_amount: string|null, currency_code: string|null, synced_at: CarbonImmutable|null},
     *     google_ads: array{status: string, status_label: string, spend_amount: string|null, currency_code: string|null, synced_at: CarbonImmutable|null},
     *     public_engagement: array{interaction_total: int|null, available_posts: int, tracked_posts: int, coverage_percent: float|null},
     *     ga4_business_goal: array{metric: string|null, metric_label: string|null, target_value: float|null, actual_value: float|null, goal_currency_code: string|null, report_currency_code: string|null, completion_percent: float|null, remaining_value: float|null, status: string, status_label: string, time_pacing: array{status: string, status_label: string, campaign_days: int|null, elapsed_days: int|null, elapsed_percent: float|null, expected_value: float|null, gap_value: float|null, gap_percentage_points: float|null}},
     *     efficiency: array<string, mixed>
     * }>
     */
    public function build(
        Collection $targets,
        User $user,
        CarbonImmutable $asOf,
        array $publicEngagementSummaries = [],
    ): array {
        if ($targets->isEmpty()) {
            return [];
        }

        $targetIds = $targets->modelKeys();
        $googleAnalyticsConnection = $user->googleAnalyticsConnection;
        $metaMarketingConnection = $user->metaMarketingConnection;
        $googleAdsConnection = $user->googleAdsConnection;

        $googleAnalyticsReports = $googleAnalyticsConnection === null
            ? collect()
            : SocialEngagementTargetGoogleAnalyticsReport::query()
                ->where('google_analytics_connection_id', $googleAnalyticsConnection->getKey())
                ->whereIn('social_engagement_target_id', $targetIds)
                ->get()
                ->keyBy('social_engagement_target_id');
        $metaMarketingReports = $metaMarketingConnection === null
            ? collect()
            : SocialEngagementTargetMetaMarketingReport::query()
                ->where('meta_marketing_connection_id', $metaMarketingConnection->getKey())
                ->whereIn('social_engagement_target_id', $targetIds)
                ->get()
                ->keyBy('social_engagement_target_id');
        $googleAdsReports = $googleAdsConnection === null
            ? collect()
            : SocialEngagementTargetGoogleAdsReport::query()
                ->where('google_ads_connection_id', $googleAdsConnection->getKey())
                ->whereIn('social_engagement_target_id', $targetIds)
                ->get()
                ->keyBy('social_engagement_target_id');
        $freshnessCutoff = $asOf->subDay();
        $summaries = [];

        foreach ($targets as $target) {
            $publicEngagementSummary = $publicEngagementSummaries[$target->getKey()] ?? [];
            $publicInteractionTotal = is_int($publicEngagementSummary['interaction_total'] ?? null)
                ? $publicEngagementSummary['interaction_total']
                : null;
            $availableEngagementPosts = is_int($publicEngagementSummary['available_posts'] ?? null)
                ? $publicEngagementSummary['available_posts']
                : 0;
            $trackedEngagementPosts = is_int($publicEngagementSummary['tracked_posts'] ?? null)
                ? $publicEngagementSummary['tracked_posts']
                : 0;
            $googleAnalyticsReport = $this->googleAnalyticsReportMatchesTarget(
                $googleAnalyticsReports->get($target->getKey()),
                $target,
            );
            $metaMarketingReport = $this->metaMarketingReportMatchesTarget(
                $metaMarketingReports->get($target->getKey()),
                $target,
                $metaMarketingConnection,
            );
            $googleAdsReport = $this->googleAdsReportMatchesTarget(
                $googleAdsReports->get($target->getKey()),
                $target,
                $googleAdsConnection,
            );
            $googleAnalyticsSummary = $this->googleAnalyticsSummary(
                $googleAnalyticsReport,
                $googleAnalyticsConnection,
                $target,
                $freshnessCutoff,
            );

            $summaries[$target->getKey()] = [
                'google_analytics' => $googleAnalyticsSummary,
                'meta_marketing' => $this->paidMediaSummary(
                    $metaMarketingReport,
                    $metaMarketingConnection,
                    $target->meta_campaign_id !== null && $target->starts_on !== null && $target->ends_on !== null,
                    $freshnessCutoff,
                ),
                'google_ads' => $this->paidMediaSummary(
                    $googleAdsReport,
                    $googleAdsConnection,
                    $target->google_ads_campaign_id !== null && $target->starts_on !== null && $target->ends_on !== null,
                    $freshnessCutoff,
                ),
                'public_engagement' => [
                    'interaction_total' => $publicInteractionTotal,
                    'available_posts' => $availableEngagementPosts,
                    'tracked_posts' => $trackedEngagementPosts,
                    'coverage_percent' => $trackedEngagementPosts > 0
                        ? round($availableEngagementPosts / $trackedEngagementPosts * 100, 1)
                        : null,
                ],
                'ga4_business_goal' => $this->businessGoalReport->build(
                    $target,
                    $googleAnalyticsReport,
                    $googleAnalyticsSummary['status'] === 'stale',
                ),
                'efficiency' => $this->marketingEfficiencyReport->build(
                    $target,
                    $googleAnalyticsReport,
                    $publicInteractionTotal,
                    $metaMarketingReport,
                    $googleAdsReport,
                    googleAnalyticsReportIsStale: $googleAnalyticsSummary['status'] === 'stale',
                    roasSpendSnapshotIsFresh: $this->selectedSpendSnapshotIsFreshForTarget(
                        $target,
                        $metaMarketingReport,
                        $googleAdsReport,
                        $freshnessCutoff,
                    ),
                ),
            ];
        }

        return $summaries;
    }

    private function selectedSpendSnapshotIsFreshForTarget(
        SocialEngagementTarget $target,
        ?SocialEngagementTargetMetaMarketingReport $metaMarketingReport,
        ?SocialEngagementTargetGoogleAdsReport $googleAdsReport,
        CarbonImmutable $freshnessCutoff,
    ): bool {
        if ($target->googleAnalyticsAttributionMode() === 'google_ads_auto_tagging') {
            return $googleAdsReport?->synced_at?->greaterThan($freshnessCutoff) === true;
        }

        if ($metaMarketingReport?->spend_amount !== null) {
            return $metaMarketingReport->synced_at?->greaterThan($freshnessCutoff) === true;
        }

        if ($target->ad_spend_amount !== null) {
            return is_numeric($target->ad_spend_amount);
        }

        return false;
    }

    private function googleAnalyticsReportMatchesTarget(
        ?SocialEngagementTargetGoogleAnalyticsReport $report,
        SocialEngagementTarget $target,
    ): ?SocialEngagementTargetGoogleAnalyticsReport {
        if ($report === null
            || $target->starts_on === null
            || $target->ends_on === null
            || $report->attribution_mode !== $target->googleAnalyticsAttributionMode()
            || ! $report->starts_on?->isSameDay($target->starts_on)
            || ! $report->ends_on?->isSameDay($target->ends_on)) {
            return null;
        }

        if ($target->googleAnalyticsAttributionMode() === 'google_ads_auto_tagging') {
            return (string) $report->campaign_id === trim((string) $target->google_ads_campaign_id)
                ? $report
                : null;
        }

        if ($report->campaign_id !== null
            || $report->campaign_name !== $target->googleAnalyticsCampaignName()
            || $report->source_name !== $target->googleAnalyticsSourceName()
            || $report->medium_name !== $target->googleAnalyticsMediumName()) {
            return null;
        }

        return $report;
    }

    private function metaMarketingReportMatchesTarget(
        ?SocialEngagementTargetMetaMarketingReport $report,
        SocialEngagementTarget $target,
        ?MetaMarketingConnection $connection,
    ): ?SocialEngagementTargetMetaMarketingReport {
        if ($report === null
            || $connection === null
            || $connection->ad_account_id === null
            || $target->meta_campaign_id === null
            || $target->starts_on === null
            || $target->ends_on === null
            || $report->ad_account_id !== $connection->ad_account_id
            || $report->campaign_id !== $target->meta_campaign_id
            || ! $report->starts_on?->isSameDay($target->starts_on)
            || ! $report->ends_on?->isSameDay($target->ends_on)) {
            return null;
        }

        return $report;
    }

    private function googleAdsReportMatchesTarget(
        ?SocialEngagementTargetGoogleAdsReport $report,
        SocialEngagementTarget $target,
        ?GoogleAdsConnection $connection,
    ): ?SocialEngagementTargetGoogleAdsReport {
        if ($report === null
            || $connection === null
            || $connection->customer_id === null
            || $target->google_ads_campaign_id === null
            || $target->starts_on === null
            || $target->ends_on === null
            || $report->customer_id !== $connection->customer_id
            || $report->campaign_id !== $target->google_ads_campaign_id
            || ! $report->starts_on?->isSameDay($target->starts_on)
            || ! $report->ends_on?->isSameDay($target->ends_on)) {
            return null;
        }

        return $report;
    }

    /** @return array{status: string, status_label: string, sessions: int|null, revenue: string|null, currency_code: string|null, synced_at: CarbonImmutable|null} */
    private function googleAnalyticsSummary(
        ?SocialEngagementTargetGoogleAnalyticsReport $report,
        ?GoogleAnalyticsConnection $connection,
        SocialEngagementTarget $target,
        CarbonImmutable $freshnessCutoff,
    ): array {
        $status = $this->reportStatus(
            $report?->synced_at,
            $connection !== null,
            $target->starts_on !== null && $target->ends_on !== null,
            $freshnessCutoff,
        );

        return [
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'sessions' => $report?->sessions,
            'revenue' => $report?->total_revenue,
            'currency_code' => $report?->currency_code,
            'synced_at' => $report?->synced_at,
        ];
    }

    /** @return array{status: string, status_label: string, spend_amount: string|null, currency_code: string|null, synced_at: CarbonImmutable|null} */
    private function paidMediaSummary(
        SocialEngagementTargetMetaMarketingReport|SocialEngagementTargetGoogleAdsReport|null $report,
        MetaMarketingConnection|GoogleAdsConnection|null $connection,
        bool $hasCampaign,
        CarbonImmutable $freshnessCutoff,
    ): array {
        return [
            'status' => $status = $this->reportStatus(
                $report?->synced_at,
                $connection !== null,
                $hasCampaign,
                $freshnessCutoff,
            ),
            'status_label' => $this->statusLabel($status),
            'spend_amount' => $report?->spend_amount,
            'currency_code' => $report?->currency_code,
            'synced_at' => $report?->synced_at,
        ];
    }

    private function reportStatus(
        ?CarbonImmutable $syncedAt,
        bool $connectionAvailable,
        bool $campaignConfigured,
        CarbonImmutable $freshnessCutoff,
    ): string {
        if (! $campaignConfigured) {
            return 'not_configured';
        }

        if (! $connectionAvailable) {
            return 'not_connected';
        }

        if ($syncedAt === null) {
            return 'missing';
        }

        return $syncedAt->lessThanOrEqualTo($freshnessCutoff) ? 'stale' : 'fresh';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'not_connected' => '未連結',
            'not_configured' => '未設定活動',
            'missing' => '尚無資料',
            'stale' => '資料過期',
            default => '已更新',
        };
    }
}
