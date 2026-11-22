<?php

namespace App\Services;

use App\Models\GoogleAnalyticsCampaignSnapshot;
use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GoogleAnalyticsCampaignReportSync
{
    public function __construct(private GoogleAnalyticsDataApi $dataApi) {}

    public function sync(
        GoogleAnalyticsConnection $connection,
        SocialEngagementTarget $target,
        string $accessToken,
    ): SocialEngagementTargetGoogleAnalyticsReport {
        $campaignName = $target->googleAnalyticsCampaignName();
        $attributionMode = $target->googleAnalyticsAttributionMode();
        $usesGoogleAdsAutoTagging = $attributionMode === 'google_ads_auto_tagging';
        $googleAdsCampaignId = $usesGoogleAdsAutoTagging ? trim((string) $target->google_ads_campaign_id) : null;
        $sourceName = $usesGoogleAdsAutoTagging ? null : $target->googleAnalyticsSourceName();
        $mediumName = $usesGoogleAdsAutoTagging ? null : $target->googleAnalyticsMediumName();
        $metrics = $this->dataApi->campaignReport(
            $accessToken,
            (string) $connection->property_id,
            $campaignName,
            $target->starts_on->toDateString(),
            $target->ends_on->toDateString(),
            $sourceName,
            $mediumName,
            $googleAdsCampaignId,
        );
        $startsOn = $target->starts_on->toDateString();
        $endsOn = $target->ends_on->toDateString();
        $dailyMetrics = [
            'status' => $metrics['daily_metrics_status'] ?? 'complete',
            'requested_start_on' => $startsOn,
            'requested_end_on' => $endsOn,
            'coverage_start_on' => array_key_exists('daily_metrics_coverage_start_on', $metrics)
                ? $metrics['daily_metrics_coverage_start_on']
                : $startsOn,
            'coverage_end_on' => array_key_exists('daily_metrics_coverage_end_on', $metrics)
                ? $metrics['daily_metrics_coverage_end_on']
                : $endsOn,
            'rows' => $metrics['daily_metrics'],
        ];
        unset(
            $metrics['daily_metrics'],
            $metrics['daily_metrics_status'],
            $metrics['daily_metrics_coverage_start_on'],
            $metrics['daily_metrics_coverage_end_on'],
        );

        $syncedAt = CarbonImmutable::now();
        $snapshotDate = $syncedAt->timezone('Asia/Taipei')->toDateString();
        $reportAttributes = [
            'campaign_name' => $campaignName,
            'attribution_mode' => $attributionMode,
            'campaign_id' => $googleAdsCampaignId,
            'source_name' => $sourceName,
            'medium_name' => $mediumName,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'daily_metrics' => $dailyMetrics,
            ...$metrics,
            'synced_at' => $syncedAt,
        ];

        return DB::transaction(function () use ($connection, $target, $snapshotDate, $reportAttributes): SocialEngagementTargetGoogleAnalyticsReport {
            $report = SocialEngagementTargetGoogleAnalyticsReport::query()->updateOrCreate(
                [
                    'google_analytics_connection_id' => $connection->getKey(),
                    'social_engagement_target_id' => $target->getKey(),
                ],
                $reportAttributes,
            );

            $snapshotAttributes = $reportAttributes;
            unset($snapshotAttributes['daily_metrics']);

            GoogleAnalyticsCampaignSnapshot::query()->updateOrCreate(
                [
                    'google_analytics_connection_id' => $connection->getKey(),
                    'social_engagement_target_id' => $target->getKey(),
                    'snapshot_date' => $snapshotDate,
                ],
                $snapshotAttributes,
            );

            return $report;
        });
    }

    public function isSnapshotFresh(
        ?SocialEngagementTargetGoogleAnalyticsReport $snapshot,
        SocialEngagementTarget $target,
        CarbonImmutable $asOf,
    ): bool {
        $attributionMode = $target->googleAnalyticsAttributionMode();
        $usesGoogleAdsAutoTagging = $attributionMode === 'google_ads_auto_tagging';

        return $snapshot !== null
            && $snapshot->attribution_mode === $attributionMode
            && $snapshot->campaign_id === ($usesGoogleAdsAutoTagging ? trim((string) $target->google_ads_campaign_id) : null)
            && ($usesGoogleAdsAutoTagging || $snapshot->campaign_name === $target->googleAnalyticsCampaignName())
            && $snapshot->source_name === ($usesGoogleAdsAutoTagging ? null : $target->googleAnalyticsSourceName())
            && $snapshot->medium_name === ($usesGoogleAdsAutoTagging ? null : $target->googleAnalyticsMediumName())
            && $snapshot->starts_on?->toDateString() === $target->starts_on?->toDateString()
            && $snapshot->ends_on?->toDateString() === $target->ends_on?->toDateString()
            && $snapshot->daily_metrics !== null
            && $snapshot->synced_at?->greaterThan($asOf->subDay()) === true;
    }
}
