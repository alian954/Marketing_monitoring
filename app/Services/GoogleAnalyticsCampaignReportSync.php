<?php

namespace App\Services;

use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use Carbon\CarbonImmutable;

class GoogleAnalyticsCampaignReportSync
{
    public function __construct(private GoogleAnalyticsDataApi $dataApi) {}

    public function sync(
        GoogleAnalyticsConnection $connection,
        SocialEngagementTarget $target,
        string $accessToken,
    ): SocialEngagementTargetGoogleAnalyticsReport {
        $campaignName = $this->campaignNameFor($target);
        $sourceName = $this->sourceNameFor($target);
        $metrics = $this->dataApi->campaignReport(
            $accessToken,
            (string) $connection->property_id,
            $campaignName,
            $target->starts_on->toDateString(),
            $target->ends_on->toDateString(),
            $sourceName,
        );

        return SocialEngagementTargetGoogleAnalyticsReport::query()->updateOrCreate(
            [
                'google_analytics_connection_id' => $connection->getKey(),
                'social_engagement_target_id' => $target->getKey(),
            ],
            [
                'campaign_name' => $campaignName,
                'source_name' => $sourceName,
                'starts_on' => $target->starts_on->toDateString(),
                'ends_on' => $target->ends_on->toDateString(),
                ...$metrics,
                'synced_at' => now(),
            ],
        );
    }

    public function isSnapshotFresh(
        ?SocialEngagementTargetGoogleAnalyticsReport $snapshot,
        SocialEngagementTarget $target,
        CarbonImmutable $asOf,
    ): bool {
        return $snapshot !== null
            && $snapshot->campaign_name === $this->campaignNameFor($target)
            && $snapshot->source_name === $this->sourceNameFor($target)
            && $snapshot->starts_on?->toDateString() === $target->starts_on?->toDateString()
            && $snapshot->ends_on?->toDateString() === $target->ends_on?->toDateString()
            && $snapshot->synced_at?->greaterThanOrEqualTo($asOf->subDay()) === true;
    }

    public function campaignNameFor(SocialEngagementTarget $target): string
    {
        return is_string($target->ga4_campaign_name) && trim($target->ga4_campaign_name) !== ''
            ? trim($target->ga4_campaign_name)
            : $target->tag;
    }

    public function sourceNameFor(SocialEngagementTarget $target): ?string
    {
        return is_string($target->ga4_source_name) && trim($target->ga4_source_name) !== ''
            ? trim($target->ga4_source_name)
            : null;
    }
}
