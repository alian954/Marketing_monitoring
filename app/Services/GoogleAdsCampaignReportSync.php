<?php

namespace App\Services;

use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Models\SocialEngagementTargetPaidAdSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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

        return DB::transaction(function () use ($connection, $target, $metrics, $syncedAt, $startsOn, $endsOn, $dailyMetrics): SocialEngagementTargetGoogleAdsReport {
            $report = SocialEngagementTargetGoogleAdsReport::query()->updateOrCreate(
                [
                    'google_ads_connection_id' => $connection->getKey(),
                    'social_engagement_target_id' => $target->getKey(),
                ],
                [
                    'customer_id' => $connection->customer_id,
                    'campaign_id' => $target->google_ads_campaign_id,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'daily_metrics' => $dailyMetrics,
                    ...$metrics,
                    'synced_at' => $syncedAt,
                ],
            );

            SocialEngagementTargetPaidAdSnapshot::query()->updateOrCreate(
                [
                    'google_ads_connection_id' => $connection->getKey(),
                    'social_engagement_target_id' => $target->getKey(),
                    'starts_on' => $target->starts_on->toDateString(),
                    'ends_on' => $target->ends_on->toDateString(),
                    'account_id' => $connection->customer_id,
                    'synced_at' => $syncedAt,
                ],
                [
                    'source' => SocialEngagementTargetPaidAdSnapshot::SOURCE_GOOGLE_ADS,
                    'account_id' => $connection->customer_id,
                    'campaign_id' => $metrics['campaign_id'],
                    'campaign_name' => $metrics['campaign_name'],
                    'spend_amount' => $metrics['spend_amount'],
                    'impressions' => $metrics['impressions'],
                    'reach' => null,
                    'clicks' => $metrics['clicks'],
                    'conversions' => $metrics['conversions'],
                    'conversions_value' => $metrics['conversions_value'],
                    'conversion_actions' => $metrics['conversion_actions'],
                    'currency_code' => $metrics['currency_code'],
                ],
            );

            return $report;
        });
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
            && $snapshot->daily_metrics !== null
            && $snapshot->synced_at?->greaterThan($asOf->subDay()) === true;
    }
}
