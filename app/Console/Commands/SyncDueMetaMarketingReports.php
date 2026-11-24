<?php

namespace App\Console\Commands;

use App\Models\AccountScopeContext;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;
use App\Models\SocialEngagementTargetMetaMarketingReport;
use App\Services\MetaMarketingApiException;
use App\Services\MetaMarketingCampaignReportSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

#[Signature('meta-marketing:sync-due')]
#[Description('同步目前進行中與近期結束活動的 Meta Ads 成效')]
class SyncDueMetaMarketingReports extends Command
{
    public function handle(MetaMarketingCampaignReportSync $campaignReportSync): int
    {
        $asOf = CarbonImmutable::now('Asia/Taipei');
        $this->clearExpiredAdInsights($asOf);
        $this->clearExpiredPlacementInsights($asOf);
        $this->clearExpiredDemographicInsights($asOf);
        $this->clearExpiredAdSetInsights($asOf);

        if (! $this->dueTargets($asOf)->exists()) {
            $this->info('目前沒有需要更新 Meta Ads 的活動。');

            return self::SUCCESS;
        }

        $syncedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        MetaMarketingConnection::query()
            ->whereNotNull('ad_account_id')
            ->whereNotNull('currency_code')
            ->where(function (Builder $query) use ($asOf): void {
                $query->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '>', $asOf);
            })
            ->orderBy('id')
            ->chunkById(50, function (Collection $connections) use (
                $asOf,
                $campaignReportSync,
                &$syncedCount,
                &$skippedCount,
                &$failedCount,
            ): void {
                foreach ($connections as $connection) {
                    $targetsForConnection = $this->dueTargets($asOf);
                    AccountScopeContext::constrainUser($targetsForConnection, (int) $connection->user_id);

                    $targetsForConnection->orderBy('id')
                        ->chunkById(100, function (Collection $targets) use (
                            $connection,
                            $asOf,
                            $campaignReportSync,
                            &$syncedCount,
                            &$skippedCount,
                            &$failedCount,
                        ): void {
                            $reports = SocialEngagementTargetMetaMarketingReport::query()
                                ->where('meta_marketing_connection_id', $connection->getKey())
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

                            foreach ($targetsToSync as $target) {
                                try {
                                    $campaignReportSync->sync($connection, $target);
                                    $syncedCount++;
                                } catch (MetaMarketingApiException|ConnectionException $exception) {
                                    $failedCount++;
                                    Log::warning('Meta Ads scheduled campaign report sync failed.', [
                                        'meta_marketing_connection_id' => $connection->getKey(),
                                        'social_engagement_target_id' => $target->getKey(),
                                        'error' => $exception->getMessage(),
                                    ]);
                                }
                            }
                        });
                }
            });

        $this->info('Meta Ads 自動同步：完成 '.$syncedCount.' 個活動，略過 '.$skippedCount.' 個近期讀值，失敗 '.$failedCount.' 個。');

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return Builder<SocialEngagementTarget> */
    private function dueTargets(CarbonImmutable $asOf): Builder
    {
        return SocialEngagementTarget::query()
            ->whereNotNull('meta_campaign_id')
            ->where('meta_campaign_id', '<>', '')
            ->whereNotNull('starts_on')
            ->whereNotNull('ends_on')
            ->whereDate('starts_on', '<=', $asOf->toDateString())
            ->whereDate('ends_on', '>=', $asOf->subDays(7)->toDateString());
    }

    private function clearExpiredAdInsights(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetMetaMarketingReport::query()
            ->whereNotNull('ad_insights_expires_at')
            ->where('ad_insights_expires_at', '<=', $asOf)
            ->update([
                'ad_insights' => null,
                'ad_insights_expires_at' => null,
            ]);
    }

    private function clearExpiredPlacementInsights(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetMetaMarketingReport::query()
            ->whereNotNull('placement_insights_expires_at')
            ->where('placement_insights_expires_at', '<=', $asOf)
            ->update([
                'placement_insights' => null,
                'placement_insights_expires_at' => null,
            ]);
    }

    private function clearExpiredDemographicInsights(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetMetaMarketingReport::query()
            ->whereNotNull('demographic_insights_expires_at')
            ->where('demographic_insights_expires_at', '<=', $asOf)
            ->update([
                'demographic_insights' => null,
                'demographic_insights_expires_at' => null,
            ]);
    }

    private function clearExpiredAdSetInsights(CarbonImmutable $asOf): void
    {
        SocialEngagementTargetMetaMarketingReport::query()
            ->whereNotNull('ad_set_insights_expires_at')
            ->where('ad_set_insights_expires_at', '<=', $asOf)
            ->update([
                'ad_set_insights' => null,
                'ad_set_insights_expires_at' => null,
            ]);
    }
}
