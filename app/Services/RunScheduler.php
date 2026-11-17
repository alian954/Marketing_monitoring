<?php

namespace App\Services;

use App\Jobs\RunMonitorCheck;
use App\Models\CheckRun;
use App\Models\Monitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RunScheduler
{
    public function enqueue(Monitor $monitor, string $source = 'scheduled', ?Carbon $scheduledAt = null, int $delaySeconds = 0): ?CheckRun
    {
        return DB::transaction(function () use ($monitor, $source, $scheduledAt, $delaySeconds): ?CheckRun {
            $lockedMonitor = Monitor::query()->lockForUpdate()->find($monitor->id);

            if ($lockedMonitor === null || ! $lockedMonitor->enabled) {
                return null;
            }

            $inFlightRun = CheckRun::query()
                ->where('monitor_id', $lockedMonitor->id)
                ->whereIn('status', ['queued', 'running'])
                ->lockForUpdate()
                ->first();

            if ($inFlightRun !== null) {
                return null;
            }

            $scheduledAt ??= $source === 'scheduled' ? ($lockedMonitor->next_check_at ?? now()) : now();
            $snapshot = json_encode([
                'name' => $lockedMonitor->name,
                'url' => $lockedMonitor->url,
                'accepted_status_codes' => $lockedMonitor->accepted_status_codes ?? [],
                'expected_text' => $lockedMonitor->expected_text,
                'expected_text_mode' => $lockedMonitor->expected_text_mode ?? 'present',
                'response_time_threshold_ms' => $lockedMonitor->response_time_threshold_ms,
                'allowed_redirect_hosts' => $lockedMonitor->allowed_redirect_hosts ?? [],
            ], JSON_THROW_ON_ERROR);
            $runKey = hash('sha256', implode(':', [
                $lockedMonitor->id,
                $lockedMonitor->config_version,
                $source,
                $scheduledAt->utc()->format('Y-m-d\TH:i:s.uP'),
            ]));
            $run = CheckRun::query()->firstOrCreate(['run_key' => $runKey], [
                'monitor_id' => $lockedMonitor->id,
                'config_version' => $lockedMonitor->config_version,
                'config_snapshot' => $snapshot,
                'source' => $source,
                'status' => 'queued',
                'scheduled_at' => $scheduledAt,
            ]);

            if ($source === 'scheduled') {
                $lockedMonitor->next_check_at = now()->addSeconds($lockedMonitor->interval_seconds);
                $lockedMonitor->save();
            }

            if ($run->wasRecentlyCreated) {
                $dispatch = RunMonitorCheck::dispatch($run->id)->afterCommit();

                if ($delaySeconds > 0) {
                    $dispatch->delay(now()->addSeconds($delaySeconds));
                }
            }

            return $run;
        });
    }
}
