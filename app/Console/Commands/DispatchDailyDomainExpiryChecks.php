<?php

namespace App\Console\Commands;

use App\Jobs\CheckDomainRegistrationExpiry;
use App\Models\Monitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

#[Signature('monitors:dispatch-domain-expiry')]
#[Description('將尚未執行今日網域註冊期限檢查的網站監測項目加入佇列')]
class DispatchDailyDomainExpiryChecks extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Monitor::query()
            ->where('enabled', true)
            ->whereNull('social_platform')
            ->whereDoesntHave('domainRegistrationObservations', static fn (Builder $query) => $query->whereDate('observed_on', today()))
            ->orderBy('id')
            ->chunkById(100, function (Collection $monitors) use (&$queued): void {
                foreach ($monitors as $monitor) {
                    $host = parse_url($monitor->url, PHP_URL_HOST);

                    if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) !== false) {
                        continue;
                    }

                    CheckDomainRegistrationExpiry::dispatch($monitor->id);
                    $queued++;
                }
            });

        $this->info('已將 '.$queued.' 個網域註冊期限檢查加入佇列。');

        return self::SUCCESS;
    }
}
