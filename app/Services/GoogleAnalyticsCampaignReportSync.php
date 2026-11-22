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
        $sourceName = $target->googleAnalyticsSourceName();
        $metrics = $this->dataApi->campaignReport(
            $accessToken,
            (string) $connection->property_id,
            $campaignName,
            $target->starts_on->toDateString(),
            $target->ends_on->toDateString(),
            $sourceName,
        );

        $syncedAt = CarbonImmutable::now('Asia/Taipei');
        $reportAttributes = [
            'campaign_name' => $campaignName,
            'source_name' => $sourceName,
            'starts_on' => $target->starts_on->toDateString(),
            'ends_on' => $target->ends_on->toDateString(),
            ...$metrics,
            'synced_at' => $syncedAt,
        ];

        return DB::transaction(function () use ($connection, $target, $syncedAt, $reportAttributes): SocialEngagementTargetGoogleAnalyticsReport {
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
                    'snapshot_date' => $syncedAt->toDateString(),
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
        return $snapshot !== null
            && $snapshot->campaign_name === $target->googleAnalyticsCampaignName()
            && $snapshot->source_name === $target->googleAnalyticsSourceName()
            && $snapshot->starts_on?->toDateString() === $target->starts_on?->toDateString()
            && $snapshot->ends_on?->toDateString() === $target->ends_on?->toDateString()
            && $snapshot->synced_at?->greaterThanOrEqualTo($asOf->subDay()) === true;
    }
}
