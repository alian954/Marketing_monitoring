<?php

namespace App\Jobs;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\TlsObservation;
use App\Services\IncidentManager;
use App\Services\MonitorHostConcurrencyLock;
use App\Services\Probe\TlsCertificateProbe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class CheckTlsCertificate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 20;

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

    public function handle(TlsCertificateProbe $probe, IncidentManager $incidents): void
    {
        $monitor = Monitor::query()->find($this->monitorId);

        if ($monitor === null
            || ! $monitor->enabled
            || $monitor->socialPlatform() !== null
            || parse_url($monitor->url, PHP_URL_SCHEME) !== 'https'
            || ! Organization::isAccessibleForOwner(
                $monitor->user_id === null ? null : (int) $monitor->user_id,
            )) {
            return;
        }

        $observedOn = today()->toDateString();

        if (TlsObservation::query()->where('monitor_id', $monitor->id)->whereDate('observed_on', $observedOn)->exists()) {
            return;
        }

        $configVersion = $monitor->config_version;
        $startedAt = now();
        $result = $probe->inspect($monitor->url);
        $finishedAt = now();

        DB::transaction(function () use ($monitor, $configVersion, $observedOn, $startedAt, $finishedAt, $result, $incidents): void {
            $lockedMonitor = Monitor::query()->lockForUpdate()->find($monitor->id);

            if ($lockedMonitor === null
                || ! $lockedMonitor->enabled
                || $lockedMonitor->socialPlatform() !== null
                || ! Organization::isAccessibleForOwner(
                    $lockedMonitor->user_id === null ? null : (int) $lockedMonitor->user_id,
                )
                || $lockedMonitor->config_version !== $configVersion) {
                return;
            }

            $snapshot = json_encode([
                'name' => $lockedMonitor->name,
                'url' => $lockedMonitor->url,
                'accepted_status_codes' => $lockedMonitor->accepted_status_codes ?? [],
                'expected_text' => $lockedMonitor->expected_text,
                'expected_text_mode' => $lockedMonitor->expected_text_mode ?? 'present',
                'response_time_threshold_ms' => $lockedMonitor->response_time_threshold_ms,
                'allowed_redirect_hosts' => $lockedMonitor->allowed_redirect_hosts ?? [],
            ], JSON_THROW_ON_ERROR);
            $runKey = hash('sha256', implode(':', [$lockedMonitor->id, $configVersion, 'tls_daily', $observedOn]));
            $run = CheckRun::query()->firstOrCreate(['run_key' => $runKey], [
                'monitor_id' => $lockedMonitor->id,
                'config_version' => $configVersion,
                'config_snapshot' => $snapshot,
                'source' => 'tls_daily',
                'status' => 'completed',
                'scheduled_at' => $startedAt,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'error_type' => $result->errorType,
                'error_detail' => $result->errorDetail === null ? null : mb_substr($result->errorDetail, 0, 300),
                'tls_expires_at' => $result->expiresAt,
            ]);

            $observation = TlsObservation::query()->firstOrCreate([
                'monitor_id' => $lockedMonitor->id,
                'observed_on' => $observedOn,
            ], [
                'expires_at' => $result->expiresAt,
                'error_type' => $result->errorType,
            ]);

            if (! $observation->wasRecentlyCreated) {
                return;
            }

            $incidents->recordTlsObservation($lockedMonitor, $run, $result);
        });
    }
}
