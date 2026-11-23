<?php

namespace App\Console\Commands;

use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Services\GoogleAnalyticsApiException;
use App\Services\GoogleAnalyticsCampaignReportSync;
use App\Services\GoogleAnalyticsDataApi;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

#[Signature('google-analytics:sync-due')]
#[Description('同步目前進行中與近期結束活動的 GA4 成效')]
class SyncDueGoogleAnalyticsReports extends Command
{
    public function handle(
        GoogleAnalyticsDataApi $dataApi,
        GoogleAnalyticsCampaignReportSync $campaignReportSync,
    ): int {
        $asOf = CarbonImmutable::now();
        $this->clearExpiredLandingPageReports($asOf);
        $this->clearExpiredEventNameReports($asOf);
        $this->clearExpiredItemReports($asOf);

        if (! $this->dueTargets($asOf)->exists()) {
            $this->info('目前沒有需要更新 GA4 的活動。');

            return self::SUCCESS;
        }

        $syncedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        GoogleAnalyticsConnection::query()
            ->whereNotNull('property_id')
            ->where('property_id', '<>', '')
            ->orderBy('id')
            ->chunkById(50, function (Collection $connections) use (
                $asOf,
                $dataApi,
                $campaignReportSync,
                &$syncedCount,
                &$skippedCount,
                &$failedCount,
            ): void {
                foreach ($connections as $connection) {
                    $accessToken = null;
                    $tokenRefreshFailed = false;

                    $this->dueTargets($asOf)
                        ->orderBy('id')
                        ->chunkById(100, function (Collection $targets) use (
                            $connection,
                            &$accessToken,
                            &$tokenRefreshFailed,
                            $dataApi,
                            $asOf,
                            $campaignReportSync,
                            &$syncedCount,
                            &$skippedCount,
                            &$failedCount,
                        ): void {
                            $reports = SocialEngagementTargetGoogleAnalyticsReport::query()
                                ->where('google_analytics_connection_id', $connection->getKey())
                                ->whereIn('social_engagement_target_id', $targets->modelKeys())
                                ->get()
                                ->keyBy('social_engagement_target_id');
                            $targetsToSync = $targets->reject(fn (SocialEngagementTarget $target): bool => $campaignReportSync->isSnapshotFresh(
                                $reports->get($target->getKey()),
                                $target,
                                $asOf,
                            )
                            );

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
                                } catch (GoogleAnalyticsApiException|ConnectionException $exception) {
                                    $tokenRefreshFailed = true;
                                    $failedCount += $targetsToSync->count();
                                    Log::warning('GA4 scheduled sync could not refresh a connection token.', [
                                        'google_analytics_connection_id' => $connection->getKey(),
                                        'error' => $exception->getMessage(),
                                    ]);

                                    return;
                                }
                            }

                            foreach ($targetsToSync as $target) {
                                try {
                                    $campaignReportSync->sync($connection, $target, $accessToken);
                                    $syncedCount++;
                                } catch (GoogleAnalyticsApiException|ConnectionException $exception) {
                                    $failedCount++;
                                    Log::warning('GA4 scheduled campaign report sync failed.', [
                                        'google_analytics_connection_id' => $connection->getKey(),
                                        'social_engagement_target_id' => $target->getKey(),
                                        'error' => $exception->getMessage(),
                                    ]);
                                }
                            }
                        });
                }
            });

        $this->info('GA4 自動同步：完成 '.$syncedCount.' 個活動，略過 '.$skippedCount.' 個近期讀值，失敗 '.$failedCount.' 個。');

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return Builder<SocialEngagementTarget> */
    private function dueTargets(CarbonImmutable $asOf): Builder
    {
        return SocialEngagementTarget::query()
            ->whereNotNull('starts_on')
            ->whereNotNull('ends_on')
            ->whereDate('starts_on', '<=', $asOf->toDateString())
            ->whereDate('ends_on', '>=', $asOf->subDays(7)->toDateString());
    }

    private function clearExpiredLandingPageReports(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetGoogleAnalyticsReport::query()
            ->whereNotNull('landing_page_expires_at')
            ->where('landing_page_expires_at', '<=', $asOf)
            ->update([
                'landing_page_report' => null,
                'landing_page_expires_at' => null,
            ]);
    }

    private function clearExpiredEventNameReports(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetGoogleAnalyticsReport::query()
            ->whereNotNull('event_name_expires_at')
            ->where('event_name_expires_at', '<=', $asOf)
            ->update([
                'event_name_report' => null,
                'event_name_expires_at' => null,
            ]);
    }

    private function clearExpiredItemReports(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetGoogleAnalyticsReport::query()
            ->whereNotNull('item_report_expires_at')
            ->where('item_report_expires_at', '<=', $asOf)
            ->update([
                'item_report' => null,
                'item_report_expires_at' => null,
            ]);
    }
}
