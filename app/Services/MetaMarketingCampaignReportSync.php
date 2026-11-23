<?php

namespace App\Services;

use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Models\SocialEngagementTargetPaidAdSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

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
        $startsOn = $target->starts_on->toDateString();
        $endsOn = $target->ends_on->toDateString();
        $adInsights = [
            'status' => 'complete',
            'window_start_on' => $startsOn,
            'window_end_on' => $endsOn,
            'currency_code' => $connection->currency_code,
            'truncated' => false,
            'rows' => [],
        ];

        try {
            $adMetrics = $this->dataApi->campaignAdInsights(
                (string) $connection->access_token,
                $connection->ad_account_id,
                $target->meta_campaign_id,
                $startsOn,
                $endsOn,
                $connection->currency_code,
            );
            $adInsights['rows'] = $adMetrics['rows'];
            $adInsights['truncated'] = $adMetrics['truncated'];
        } catch (MetaMarketingApiException|ConnectionException $exception) {
            $adInsights['status'] = 'unavailable';
            $adInsights['message'] = $exception->getMessage();
        }

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

        return DB::transaction(function () use ($connection, $target, $metrics, $syncedAt, $startsOn, $endsOn, $dailyMetrics, $adInsights): SocialEngagementTargetMetaMarketingReport {
            $report = SocialEngagementTargetMetaMarketingReport::query()->updateOrCreate(
                [
                    'meta_marketing_connection_id' => $connection->getKey(),
                    'social_engagement_target_id' => $target->getKey(),
                ],
                [
                    'ad_account_id' => $connection->ad_account_id,
                    'campaign_id' => $target->meta_campaign_id,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'daily_metrics' => $dailyMetrics,
                    'ad_insights' => $adInsights,
                    'ad_insights_expires_at' => $syncedAt->addDays(30),
                    ...$metrics,
                    'synced_at' => $syncedAt,
                ],
            );

            SocialEngagementTargetPaidAdSnapshot::query()->updateOrCreate(
                [
                    'meta_marketing_connection_id' => $connection->getKey(),
                    'social_engagement_target_id' => $target->getKey(),
                    'starts_on' => $target->starts_on->toDateString(),
                    'ends_on' => $target->ends_on->toDateString(),
                    'account_id' => $connection->ad_account_id,
                    'synced_at' => $syncedAt,
                ],
                [
                    'source' => SocialEngagementTargetPaidAdSnapshot::SOURCE_META_MARKETING,
                    'account_id' => $connection->ad_account_id,
                    'campaign_id' => $metrics['campaign_id'],
                    'campaign_name' => $metrics['campaign_name'],
                    'spend_amount' => $metrics['spend_amount'],
                    'impressions' => $metrics['impressions'],
                    'reach' => $metrics['reach'],
                    'clicks' => $metrics['clicks'],
                    'actions' => $metrics['actions'],
                    'action_values' => $metrics['action_values'],
                    'conversions' => null,
                    'conversions_value' => null,
                    'currency_code' => $metrics['currency_code'],
                ],
            );

            return $report;
        });
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
            && $snapshot->daily_metrics !== null
            && $snapshot->synced_at?->greaterThan($asOf->subDay()) === true;
    }
}
