<?php

namespace App\Console\Commands;

use App\Models\CheckRun;
use App\Models\RobotsObservation;
use App\Models\StatusPageSubscription;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitors:prune')]
#[Description('依保留期限清除檢查摘要、robots.txt 觀測與過期未確認訂閱')]
class PruneMonitoringData extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('monitoring.retention_days'));
        $deleted = CheckRun::query()
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $cutoff)
            ->delete();
        $observationCutoff = $cutoff->toDateString();
        $deletedRobotsObservations = RobotsObservation::query()
            ->whereDate('observed_on', '<', $observationCutoff)
            ->delete();
        $deletedPendingSubscriptions = StatusPageSubscription::query()
            ->whereNull('confirmed_at')
            ->whereNull('unsubscribed_at')
            ->whereNotNull('confirmation_expires_at')
            ->where('confirmation_expires_at', '<', now())
            ->delete();

        $this->info('已清除 '.$deleted.' 筆檢查摘要、'.$deletedRobotsObservations.' 筆過期 robots.txt 觀測與 '.$deletedPendingSubscriptions.' 筆過期未確認訂閱；事件與事件記錄均予保留。');

        return self::SUCCESS;
    }
}
