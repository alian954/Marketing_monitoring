<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Models\RobotsObservation;
use App\Services\MonitorHostConcurrencyLock;
use App\Services\Probe\RobotsTxtProbe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class CheckRobotsTxt implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 12;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $monitorId) {}

    public function uniqueId(): string
    {
        return $this->monitorId.':'.today()->toDateString();
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        $monitor = Monitor::query()->find($this->monitorId);

        if ($monitor === null) {
            return [];
        }

        return [app(MonitorHostConcurrencyLock::class)->forUrl($monitor->url)];
    }

    public function handle(RobotsTxtProbe $probe): void
    {
        $monitor = Monitor::query()->find($this->monitorId);

        if ($monitor === null
            || ! $monitor->enabled
            || $monitor->socialPlatform() !== null
            || ! $monitor->ownerAllowsChecks()) {
            return;
        }

        $observedOn = today()->toDateString();
        $existingObservation = RobotsObservation::query()
            ->where('monitor_id', $monitor->id)
            ->whereDate('observed_on', $observedOn)
            ->first();

        if ($existingObservation?->config_version === $monitor->config_version) {
            return;
        }

        $configVersion = $monitor->config_version;
        $checkedAt = now();
        $result = $probe->inspect($monitor->url, $monitor->allowed_redirect_hosts ?? []);

        DB::transaction(function () use ($monitor, $configVersion, $observedOn, $checkedAt, $result): void {
            $lockedMonitor = Monitor::query()->lockForUpdate()->find($monitor->id);

            if (
                $lockedMonitor === null
                || ! $lockedMonitor->enabled
                || ! $lockedMonitor->ownerAllowsChecks()
                || $lockedMonitor->config_version !== $configVersion
                || $lockedMonitor->socialPlatform() !== null
            ) {
                return;
            }

            $previousObservation = RobotsObservation::query()
                ->where('monitor_id', $lockedMonitor->id)
                ->where('config_version', $configVersion)
                ->whereDate('observed_on', '<', $observedOn)
                ->whereNotNull('policy_hash')
                ->orderByDesc('observed_on')
                ->first();
            $policyChanged = $result->policyHash !== null && $previousObservation !== null
                ? ! hash_equals($previousObservation->policy_hash, $result->policyHash)
                : null;
            $observation = RobotsObservation::query()->firstOrNew([
                'monitor_id' => $lockedMonitor->id,
                'observed_on' => $observedOn,
            ]);

            if ($observation->exists && $observation->config_version === $configVersion) {
                return;
            }

            $observation->fill([
                'config_version' => $configVersion,
                'file_state' => $result->fileState,
                'http_status' => $result->httpStatus,
                'error_type' => $result->errorType,
                'policy_hash' => $result->policyHash,
                'crawler_decisions' => $result->crawlerDecisions,
                'rule_count' => $result->ruleCount,
                'sitemap_count' => $result->sitemapCount,
                'policy_changed' => $policyChanged,
                'checked_at' => $checkedAt,
            ])->save();
        });
    }
}
