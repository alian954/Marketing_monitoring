<?php

namespace App\Console\Commands;

use App\Models\CheckRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitors:prune')]
#[Description('刪除超過資料保留期限的已完成檢查摘要')]
class PruneMonitoringData extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('monitoring.retention_days'));
        $deleted = CheckRun::query()
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $cutoff)
            ->delete();

        $this->info('已清除 '.$deleted.' 筆檢查摘要；事件與事件記錄均予保留。');

        return self::SUCCESS;
    }
}
