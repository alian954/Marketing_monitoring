<?php

namespace App\Console\Commands;

use App\Jobs\CheckTlsCertificate;
use App\Models\Monitor;
use App\Models\Organization;
use App\Services\ProductEntitlementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

#[Signature('monitors:dispatch-tls')]
#[Description('將尚未執行今日憑證檢查的 HTTPS 監測項目加入佇列')]
class DispatchDailyTlsChecks extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Monitor::query()
            ->where('enabled', true)
            ->whereNull('social_platform')
            ->whereNotIn('user_id', Organization::inaccessibleOwnerIds(now('Asia/Taipei')->toDateString()))
            ->whereNotIn('user_id', app(ProductEntitlementService::class)->unentitledOwnerIds('website-monitoring', 'monitoring.targets'))
            ->whereDoesntHave('tlsObservations', static fn (Builder $query) => $query->whereDate('observed_on', today()))
            ->orderBy('id')
            ->chunkById(100, function (Collection $monitors) use (&$queued): void {
                foreach ($monitors as $monitor) {
                    if (mb_strtolower((string) parse_url($monitor->url, PHP_URL_SCHEME)) !== 'https') {
                        continue;
                    }

                    CheckTlsCertificate::dispatch($monitor->id);
                    $queued++;
                }
            });

        $this->info('已將 '.$queued.' 個 HTTPS 憑證檢查加入佇列。');

        return self::SUCCESS;
    }
}
