<?php

namespace App\Services;

use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ScheduledSyncHealthRecorder
{
    public function start(
        GoogleAnalyticsConnection|GoogleAdsConnection|MetaMarketingConnection $connection,
    ): CarbonImmutable {
        $startedAt = CarbonImmutable::now();
        $connection->forceFill([
            'scheduled_sync_status' => 'running',
            'scheduled_sync_started_at' => $startedAt,
            'scheduled_sync_finished_at' => null,
            'scheduled_sync_synced_count' => 0,
            'scheduled_sync_skipped_count' => 0,
            'scheduled_sync_failed_count' => 0,
            'scheduled_sync_error' => null,
        ])->save();

        return $startedAt;
    }

    public function finish(
        GoogleAnalyticsConnection|GoogleAdsConnection|MetaMarketingConnection $connection,
        CarbonImmutable $startedAt,
        int $syncedCount,
        int $skippedCount,
        int $failedCount,
        ?string $error,
    ): void {
        $status = match (true) {
            $failedCount === 0 => 'succeeded',
            $syncedCount > 0 || $skippedCount > 0 => 'partial',
            default => 'failed',
        };

        $connection->forceFill([
            'scheduled_sync_status' => $status,
            'scheduled_sync_started_at' => $startedAt,
            'scheduled_sync_finished_at' => CarbonImmutable::now(),
            'scheduled_sync_synced_count' => $syncedCount,
            'scheduled_sync_skipped_count' => $skippedCount,
            'scheduled_sync_failed_count' => $failedCount,
            'scheduled_sync_error' => $failedCount > 0 && is_string($error) && trim($error) !== ''
                ? Str::limit(Str::squish($error), 500)
                : null,
        ])->save();
    }
}
