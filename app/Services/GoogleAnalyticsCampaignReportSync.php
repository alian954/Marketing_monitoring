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

        $syncedAt = CarbonImmutable::now();
        $snapshotDate = $syncedAt->timezone('Asia/Taipei')->toDateString();
        $reportAttributes = [
            'campaign_name' => $campaignName,
            'attribution_mode' => $attributionMode,
            'campaign_id' => $googleAdsCampaignId,
            'source_name' => $sourceName,
            'medium_name' => $mediumName,
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
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

            GoogleAnalyticsCampaignSnapshot::query()->updateOrCreate(
                [
                    'google_analytics_connection_id' => $connection->getKey(),
                    'social_engagement_target_id' => $target->getKey(),
                    'snapshot_date' => $snapshotDate,
                ],
                $reportAttributes,
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
            && $snapshot->synced_at?->greaterThan($asOf->subDay()) === true;
    }
}
