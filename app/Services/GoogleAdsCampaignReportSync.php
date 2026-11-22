<?php

namespace App\Services;

use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use Carbon\CarbonImmutable;

class GoogleAdsCampaignReportSync
{
    public function __construct(private GoogleAdsDataApi $dataApi) {}

    public function sync(
        GoogleAdsConnection $connection,
        SocialEngagementTarget $target,
        string $accessToken,
    ): SocialEngagementTargetGoogleAdsReport {
        if ($connection->customer_id === null
            || $connection->currency_code === null
            || $target->google_ads_campaign_id === null
            || $target->starts_on === null
            || $target->ends_on === null) {
            throw new GoogleAdsApiException('請先設定 Google Ads 客戶、活動 ID 與活動日期。');
        }

        $metrics = $this->dataApi->campaignInsights(
            $accessToken,
            $connection->customer_id,
            $target->google_ads_campaign_id,
            $target->starts_on->toDateString(),
            $target->ends_on->toDateString(),
            $connection->currency_code,
            $connection->login_customer_id,
        );
        $syncedAt = CarbonImmutable::now('Asia/Taipei');

        return SocialEngagementTargetGoogleAdsReport::query()->updateOrCreate(
            [
                'google_ads_connection_id' => $connection->getKey(),
                'social_engagement_target_id' => $target->getKey(),
            ],
            [
                'customer_id' => $connection->customer_id,
                'campaign_id' => $target->google_ads_campaign_id,
                'starts_on' => $target->starts_on->toDateString(),
                'ends_on' => $target->ends_on->toDateString(),
                ...$metrics,
                'synced_at' => $syncedAt,
            ],
        );
    }

    public function isSnapshotFresh(
        ?SocialEngagementTargetGoogleAdsReport $snapshot,
        GoogleAdsConnection $connection,
        SocialEngagementTarget $target,
        CarbonImmutable $asOf,
    ): bool {
        return $snapshot !== null
            && $snapshot->customer_id === $connection->customer_id
            && $snapshot->campaign_id === $target->google_ads_campaign_id
            && $snapshot->starts_on?->toDateString() === $target->starts_on?->toDateString()
            && $snapshot->ends_on?->toDateString() === $target->ends_on?->toDateString()
            && $snapshot->synced_at?->greaterThanOrEqualTo($asOf->subDay()) === true;
    }
}
