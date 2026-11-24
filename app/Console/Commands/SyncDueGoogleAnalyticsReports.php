<?php

namespace App\Console\Commands;

use App\Models\AccountScopeContext;
use App\Models\GoogleAnalyticsConnection;
use App\Models\Organization;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetGoogleAnalyticsReport;
use App\Services\GoogleAnalyticsApiException;
use App\Services\GoogleAnalyticsCampaignReportSync;
use App\Services\GoogleAnalyticsClientPropertyResolver;
use App\Services\GoogleAnalyticsDataApi;
use App\Services\ScheduledSyncHealthRecorder;
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
        GoogleAnalyticsClientPropertyResolver $propertyResolver,
        ScheduledSyncHealthRecorder $syncHealthRecorder,
    ): int {
        $asOf = CarbonImmutable::now();
        $this->clearExpiredLandingPageReports($asOf);
        $this->clearExpiredEventNameReports($asOf);
        $this->clearExpiredItemReports($asOf);
        $this->clearExpiredAdContentReports($asOf);
        $this->clearExpiredNewVsReturningReports($asOf);

        if (! $this->dueTargets($asOf)->exists()) {
            $this->info('目前沒有需要更新 GA4 的活動。');

            return self::SUCCESS;
        }

        $syncedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        GoogleAnalyticsConnection::query()
            ->orderBy('id')
            ->chunkById(50, function (Collection $connections) use (
                $asOf,
                $dataApi,
                $campaignReportSync,
                $propertyResolver,
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
                            $propertyResolver,
                            &$syncedCount,
                            &$skippedCount,
                            &$failedCount,
                        ): void {
                            $propertiesByTarget = $targets->mapWithKeys(function (SocialEngagementTarget $target) use ($connection, $propertyResolver): array {
                                $property = $propertyResolver->forTarget($connection, $target);

                                return $property === null ? [] : [$target->getKey() => $property];
                            });
                            $reports = SocialEngagementTargetGoogleAnalyticsReport::query()
                                ->where('google_analytics_connection_id', $connection->getKey())
                                ->whereIn('social_engagement_target_id', $targets->modelKeys())
                                ->get()
                                ->keyBy('social_engagement_target_id');
                            $targetsToSync = $targets->reject(function (SocialEngagementTarget $target) use ($propertiesByTarget, $reports, $campaignReportSync, $asOf): bool {
                                $property = $propertiesByTarget->get($target->getKey());

                                return $property === null || $campaignReportSync->isSnapshotFresh(
                                    $reports->get($target->getKey()),
                                    $target,
                                    $asOf,
                                    $property->property_id,
                                    ! $property->exists,
                                );
                            });

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
                                    $connectionSyncError ??= $exception->getMessage();

                                    return;
                                }
                            }

                            foreach ($targetsToSync as $target) {
                                try {
                                    $campaignReportSync->sync(
                                        $connection,
                                        $target,
                                        $accessToken,
                                        $propertiesByTarget->get($target->getKey())->property_id,
                                    );
                                    $syncedCount++;
                                } catch (GoogleAnalyticsApiException|ConnectionException $exception) {
                                    $failedCount++;
                                    Log::warning('GA4 scheduled campaign report sync failed.', [
                                        'google_analytics_connection_id' => $connection->getKey(),
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
            ->whereDate('ends_on', '>=', $asOf->subDays(7)->toDateString())
            ->whereNotIn('user_id', Organization::inaccessibleOwnerIds(
                $asOf->setTimezone('Asia/Taipei')->toDateString(),
            ));
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

    private function clearExpiredAdContentReports(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetGoogleAnalyticsReport::query()
            ->whereNotNull('ad_content_report_expires_at')
            ->where('ad_content_report_expires_at', '<=', $asOf)
            ->update([
                'ad_content_report' => null,
                'ad_content_report_expires_at' => null,
            ]);
    }

    private function clearExpiredNewVsReturningReports(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetGoogleAnalyticsReport::query()
            ->whereNotNull('new_vs_returning_report_expires_at')
            ->where('new_vs_returning_report_expires_at', '<=', $asOf)
            ->update([
                'new_vs_returning_report' => null,
                'new_vs_returning_report_expires_at' => null,
            ]);
    }
}
