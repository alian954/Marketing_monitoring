<?php

namespace App\Services;

use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use Carbon\CarbonImmutable;

class MetaMarketingCampaignReportSync
{
    public function __construct(private MetaMarketingDataApi $dataApi) {}

    public function sync(
        MetaMarketingConnection $connection,
        SocialEngagementTarget $target,
    ): SocialEngagementTargetMetaMarketingReport {
        if ($connection->ad_account_id === null
            || $connection->currency_code === null
            || $target->meta_campaign_id === null
            || $target->starts_on === null
            || $target->ends_on === null) {
            throw new MetaMarketingApiException('請先設定 Meta 廣告帳戶、活動 ID 與活動日期。');
        }

        $metrics = $this->dataApi->campaignInsights(
            (string) $connection->access_token,
            $connection->ad_account_id,
            $target->meta_campaign_id,
            $target->starts_on->toDateString(),
            $target->ends_on->toDateString(),
            $connection->currency_code,
        );
        $syncedAt = CarbonImmutable::now('Asia/Taipei');

        return SocialEngagementTargetMetaMarketingReport::query()->updateOrCreate(
            [
                'meta_marketing_connection_id' => $connection->getKey(),
                'social_engagement_target_id' => $target->getKey(),
            ],
            [
                'ad_account_id' => $connection->ad_account_id,
                'campaign_id' => $target->meta_campaign_id,
                'starts_on' => $target->starts_on->toDateString(),
                'ends_on' => $target->ends_on->toDateString(),
                ...$metrics,
                'synced_at' => $syncedAt,
            ],
        );
    }

    public function isSnapshotFresh(
        ?SocialEngagementTargetMetaMarketingReport $snapshot,
        MetaMarketingConnection $connection,
        SocialEngagementTarget $target,
        CarbonImmutable $asOf,
    ): bool {
        return $snapshot !== null
            && $snapshot->ad_account_id === $connection->ad_account_id
            && $snapshot->campaign_id === $target->meta_campaign_id
            && $snapshot->starts_on?->toDateString() === $target->starts_on?->toDateString()
            && $snapshot->ends_on?->toDateString() === $target->ends_on?->toDateString()
            && $snapshot->synced_at?->greaterThanOrEqualTo($asOf->subDay()) === true;
    }
}
