<?php

namespace App\Services;

use App\Models\GoogleAdsClientAccount;
use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsClientProperty;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingClientAccount;
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
        private CampaignDailyMarketingPerformanceReport $dailyPerformanceReport,
        private GoogleAnalyticsClientPropertyResolver $googleAnalyticsClientPropertyResolver,
        private MetaMarketingClientAccountResolver $metaMarketingClientAccountResolver,
        private GoogleAdsClientAccountResolver $googleAdsClientAccountResolver,
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
     *     daily_performance: array<string, array<string, mixed>>,
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
        $googleAnalyticsPropertiesByClient = collect();
        $metaMarketingAccountsByClient = collect();
        $googleAdsAccountsByClient = collect();

        if ($googleAnalyticsConnection !== null) {
            foreach ($targets->unique('client_id') as $target) {
                $property = $this->googleAnalyticsClientPropertyResolver->forTarget($googleAnalyticsConnection, $target);

                if ($property !== null) {
                    $googleAnalyticsPropertiesByClient->put($target->client_id, $property);
                }
            }
        }

        if ($metaMarketingConnection !== null) {
            foreach ($targets->unique('client_id') as $target) {
                $account = $this->metaMarketingClientAccountResolver->forTarget($metaMarketingConnection, $target);

                if ($account !== null) {
                    $metaMarketingAccountsByClient->put($target->client_id, $account);
                }
            }
        }

        if ($googleAdsConnection !== null) {
            foreach ($targets->unique('client_id') as $target) {
                $account = $this->googleAdsClientAccountResolver->forTarget($googleAdsConnection, $target);

                if ($account !== null) {
                    $googleAdsAccountsByClient->put($target->client_id, $account);
                }
            }
        }

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
            $googleAnalyticsProperty = $googleAnalyticsPropertiesByClient->get($target->client_id);
            $metaMarketingClientAccount = $metaMarketingAccountsByClient->get($target->client_id);
            $googleAdsClientAccount = $googleAdsAccountsByClient->get($target->client_id);
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
                $googleAnalyticsProperty,
            );
            $metaMarketingReport = $this->metaMarketingReportMatchesTarget(
                $metaMarketingReports->get($target->getKey()),
                $target,
                $metaMarketingClientAccount,
            );
            $googleAdsReport = $this->googleAdsReportMatchesTarget(
                $googleAdsReports->get($target->getKey()),
                $target,
                $googleAdsClientAccount,
            );
            $googleAnalyticsSummary = $this->googleAnalyticsSummary(
                $googleAnalyticsReport,
                $googleAnalyticsConnection,
                $target,
                $freshnessCutoff,
                $googleAnalyticsProperty?->property_id,
            );
            $dailyPerformance = $this->dailyPerformanceReport->build(
                $googleAnalyticsReport,
                $metaMarketingReport,
                $googleAdsReport,
            );

            $summaries[$target->getKey()] = [
                'google_analytics' => $googleAnalyticsSummary,
                'meta_marketing' => $this->paidMediaSummary(
                    $metaMarketingReport,
                    $metaMarketingConnection,
                    $target->meta_campaign_id !== null && $target->starts_on !== null && $target->ends_on !== null,
                    $freshnessCutoff,
                    $metaMarketingClientAccount !== null,
                ),
                'google_ads' => $this->paidMediaSummary(
                    $googleAdsReport,
                    $googleAdsConnection,
                    $target->google_ads_campaign_id !== null && $target->starts_on !== null && $target->ends_on !== null,
                    $freshnessCutoff,
                    $googleAdsClientAccount !== null,
                    'not_configured_google_ads_source',
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
                'daily_performance' => $dailyPerformance,
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
        ?GoogleAnalyticsClientProperty $clientProperty,
    ): ?SocialEngagementTargetGoogleAnalyticsReport {
        if ($report === null
            || $clientProperty === null
            || ($report->property_id !== $clientProperty->property_id
                && ! (! $clientProperty->exists && $report->property_id === null))
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
        ?MetaMarketingClientAccount $clientAccount,
    ): ?SocialEngagementTargetMetaMarketingReport {
        if ($report === null
            || $clientAccount === null
            || $target->meta_campaign_id === null
            || $target->starts_on === null
            || $target->ends_on === null
            || $report->ad_account_id !== $clientAccount->ad_account_id
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
        ?GoogleAdsClientAccount $clientAccount,
    ): ?SocialEngagementTargetGoogleAdsReport {
        if ($report === null
            || $clientAccount === null
            || $target->google_ads_campaign_id === null
            || $target->starts_on === null
            || $target->ends_on === null
            || $report->customer_id !== $clientAccount->customer_id
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
        ?string $propertyId,
    ): array {
        $status = match (true) {
            $connection === null => 'not_connected',
            $propertyId === null => 'not_configured_source',
            default => $this->reportStatus(
                $report?->synced_at,
                true,
                $target->starts_on !== null && $target->ends_on !== null,
                $freshnessCutoff,
            ),
        };

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
        bool $sourceConfigured = true,
        string $unconfiguredStatus = 'not_configured_meta_source',
    ): array {
        $status = match (true) {
            ! $hasCampaign => 'not_configured',
            $connection === null => 'not_connected',
            ! $sourceConfigured => $unconfiguredStatus,
            $report?->synced_at === null => 'missing',
            $report->synced_at->lessThanOrEqualTo($freshnessCutoff) => 'stale',
            default => 'fresh',
        };

        return [
            'status' => $status,
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
            'not_configured_source' => '未設定 GA4 資源',
            'not_configured_meta_source' => '未設定 Meta 廣告帳戶',
            'not_configured_google_ads_source' => '未設定 Google Ads 客戶帳戶',
            'missing' => '尚無資料',
            'stale' => '資料過期',
            default => '已更新',
        };
    }
}
