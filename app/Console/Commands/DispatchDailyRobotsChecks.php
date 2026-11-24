<?php

namespace App\Console\Commands;

use App\Jobs\CheckRobotsTxt;
use App\Models\Monitor;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

#[Signature('monitors:dispatch-robots')]
#[Description('將尚未執行今日 robots.txt 爬蟲規則檢查的網站監測項目加入佇列')]
class DispatchDailyRobotsChecks extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Monitor::query()
            ->where('enabled', true)
            ->whereNull('social_platform')
            ->whereNotIn('user_id', Organization::inaccessibleOwnerIds(now('Asia/Taipei')->toDateString()))
            ->whereDoesntHave('robotsObservations', static fn (Builder $query) => $query->whereDate('observed_on', today()))
            ->orderBy('id')
            ->chunkById(100, function (Collection $monitors) use (&$queued): void {
                foreach ($monitors as $monitor) {
                    CheckRobotsTxt::dispatch($monitor->id);
                    $queued++;
                }
            });

        $this->info('已將 '.$queued.' 個網站 robots.txt 檢查加入佇列。');

        return self::SUCCESS;
    }
}
