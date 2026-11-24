<?php

namespace App\Console\Commands;

use App\Models\AccountScopeContext;
use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAdsReport;
use App\Services\GoogleAdsApiException;
use App\Services\GoogleAdsCampaignReportSync;
use App\Services\GoogleAdsDataApi;
use App\Services\ScheduledSyncHealthRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

#[Signature('google-ads:sync-due')]
#[Description('同步目前進行中與近期結束活動的 Google Ads 成效')]
class SyncDueGoogleAdsReports extends Command
{
    public function handle(
        GoogleAdsDataApi $dataApi,
        GoogleAdsCampaignReportSync $campaignReportSync,
        ScheduledSyncHealthRecorder $syncHealthRecorder,
    ): int {
        $asOf = CarbonImmutable::now('Asia/Taipei');
        $this->clearExpiredSearchTermReports($asOf);
        $this->clearExpiredKeywordPerformanceReports($asOf);
        $this->clearExpiredDeliveryUnitReports($asOf);
        $this->clearExpiredDeviceReports($asOf);
        $this->clearExpiredAuctionInsights($asOf);
        $this->clearExpiredResponsiveSearchAdAssetPerformance($asOf);

        if (! $this->dueTargets($asOf)->exists()) {
            $this->info('目前沒有需要更新 Google Ads 的活動。');

            return self::SUCCESS;
        }

        $syncedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        GoogleAdsConnection::query()
            ->whereNotNull('customer_id')
            ->whereNotNull('currency_code')
            ->whereNotNull('refresh_token')
            ->orderBy('id')
            ->chunkById(50, function (Collection $connections) use (
                $asOf,
                $dataApi,
                $campaignReportSync,
                $syncHealthRecorder,
                &$syncedCount,
                &$skippedCount,
                &$failedCount,
            ): void {
                foreach ($connections as $connection) {
                    $accessToken = null;
                    $tokenRefreshFailed = false;

                    $targetsForConnection = $this->dueTargets($asOf);
                    AccountScopeContext::constrainUser($targetsForConnection, (int) $connection->user_id);

                    if (! $targetsForConnection->exists()) {
                        continue;
                    }

                    $syncStartedAt = $syncHealthRecorder->start($connection);
                    $syncedCountBeforeConnection = $syncedCount;
                    $skippedCountBeforeConnection = $skippedCount;
                    $failedCountBeforeConnection = $failedCount;
                    $connectionSyncError = null;

                    $targetsForConnection->orderBy('id')
                        ->chunkById(100, function (Collection $targets) use (
                            $connection,
                            &$connectionSyncError,
                            &$accessToken,
                            &$tokenRefreshFailed,
                            $dataApi,
                            $asOf,
                            $campaignReportSync,
                            &$syncedCount,
                            &$skippedCount,
                            &$failedCount,
                        ): void {
                            $reports = SocialEngagementTargetGoogleAdsReport::query()
                                ->where('google_ads_connection_id', $connection->getKey())
                                ->whereIn('social_engagement_target_id', $targets->modelKeys())
                                ->get()
                                ->keyBy('social_engagement_target_id');
                            $targetsToSync = $targets->reject(fn (SocialEngagementTarget $target): bool => $campaignReportSync->isSnapshotFresh(
                                $reports->get($target->getKey()),
                                $connection,
                                $target,
                                $asOf,
                            ));

                            $skippedCount += $targets->count() - $targetsToSync->count();

                            if ($targetsToSync->isEmpty()) {
                                return;
                            }

                            if ($tokenRefreshFailed) {
                                $failedCount += $targetsToSync->count();

                                return;
                            }

                            if ($accessToken === null) {
                                try {
                                    $accessToken = $dataApi->refreshAccessToken($connection->refresh_token);
                                } catch (GoogleAdsApiException|ConnectionException $exception) {
                                    $tokenRefreshFailed = true;
                                    $failedCount += $targetsToSync->count();
                                    Log::warning('Google Ads scheduled sync could not refresh a connection token.', [
                                        'google_ads_connection_id' => $connection->getKey(),
                                        'error' => $exception->getMessage(),
                                    ]);
                                    $connectionSyncError ??= $exception->getMessage();

                                    return;
                                }
                            }

                            foreach ($targetsToSync as $target) {
                                try {
                                    $campaignReportSync->sync($connection, $target, $accessToken);
                                    $syncedCount++;
                                } catch (GoogleAdsApiException|ConnectionException $exception) {
                                    $failedCount++;
                                    Log::warning('Google Ads scheduled campaign report sync failed.', [
                                        'google_ads_connection_id' => $connection->getKey(),
                                        'social_engagement_target_id' => $target->getKey(),
                                        'error' => $exception->getMessage(),
                                    ]);
                                    $connectionSyncError ??= $exception->getMessage();
                                }
                            }
                        });

                    $syncHealthRecorder->finish(
                        $connection,
                        $syncStartedAt,
                        $syncedCount - $syncedCountBeforeConnection,
                        $skippedCount - $skippedCountBeforeConnection,
                        $failedCount - $failedCountBeforeConnection,
                        $connectionSyncError,
                    );
                }
            });

        $this->info('Google Ads 自動同步：完成 '.$syncedCount.' 個活動，略過 '.$skippedCount.' 個近期讀值，失敗 '.$failedCount.' 個。');

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return Builder<SocialEngagementTarget> */
    private function dueTargets(CarbonImmutable $asOf): Builder
    {
        return SocialEngagementTarget::query()
            ->whereNotNull('google_ads_campaign_id')
            ->where('google_ads_campaign_id', '<>', '')
            ->whereNotNull('starts_on')
            ->whereNotNull('ends_on')
            ->whereDate('starts_on', '<=', $asOf->toDateString())
            ->whereDate('ends_on', '>=', $asOf->subDays(7)->toDateString());
    }

    private function clearExpiredSearchTermReports(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetGoogleAdsReport::query()
            ->whereNotNull('search_terms_expires_at')
            ->where('search_terms_expires_at', '<=', $asOf)
            ->update([
                'search_terms_data' => null,
                'search_terms_expires_at' => null,
            ]);
    }

    private function clearExpiredKeywordPerformanceReports(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetGoogleAdsReport::query()
            ->whereNotNull('keyword_performance_expires_at')
            ->where('keyword_performance_expires_at', '<=', $asOf)
            ->update([
                'keyword_performance' => null,
                'keyword_performance_expires_at' => null,
            ]);
    }

    private function clearExpiredDeliveryUnitReports(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetGoogleAdsReport::query()
            ->whereNotNull('delivery_unit_performance_expires_at')
            ->where('delivery_unit_performance_expires_at', '<=', $asOf)
            ->update([
                'delivery_unit_performance' => null,
                'delivery_unit_performance_expires_at' => null,
            ]);
    }

    private function clearExpiredDeviceReports(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetGoogleAdsReport::query()
            ->whereNotNull('device_performance_expires_at')
            ->where('device_performance_expires_at', '<=', $asOf)
            ->update([
                'device_performance' => null,
                'device_performance_expires_at' => null,
            ]);
    }

    private function clearExpiredAuctionInsights(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetGoogleAdsReport::query()
            ->whereNotNull('auction_insights_expires_at')
            ->where('auction_insights_expires_at', '<=', $asOf)
            ->update([
                'auction_insights' => null,
                'auction_insights_expires_at' => null,
            ]);
    }

    private function clearExpiredResponsiveSearchAdAssetPerformance(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetGoogleAdsReport::query()
            ->whereNotNull('responsive_search_ad_asset_performance_expires_at')
            ->where('responsive_search_ad_asset_performance_expires_at', '<=', $asOf)
            ->update([
                'responsive_search_ad_asset_performance' => null,
                'responsive_search_ad_asset_performance_expires_at' => null,
            ]);
        SocialEngagementTargetGoogleAdsReport::query()
            ->whereNotNull('pmax_asset_performance_expires_at')
            ->where('pmax_asset_performance_expires_at', '<=', $asOf)
            ->update([
                'pmax_asset_performance' => null,
                'pmax_asset_performance_expires_at' => null,
            ]);
    }
}
