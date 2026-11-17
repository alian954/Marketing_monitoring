<?php

namespace App\Jobs;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Services\IncidentManager;
use App\Services\Probe\ProbeClient;
use App\Services\RunScheduler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunMonitorCheck implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $checkRunId) {}

    public function uniqueId(): string
    {
        return (string) $this->checkRunId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(ProbeClient $probe, IncidentManager $incidents, RunScheduler $scheduler): void
    {
        $run = CheckRun::query()->with('monitor')->findOrFail($this->checkRunId);

        if (in_array($run->status, ['completed', 'stale_config', 'paused'], true)) {
            return;
        }

        $monitor = $run->monitor;

        if ($monitor === null || ! $monitor->enabled) {
            $run->forceFill(['status' => 'paused', 'finished_at' => now()])->save();

            return;
        }

        if ($monitor->config_version !== $run->config_version) {
            $run->forceFill(['status' => 'stale_config', 'finished_at' => now()])->save();

            return;
        }

        $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();

        try {
            $normal = $probe->inspect(
                (string) $snapshot['url'],
                (array) ($snapshot['allowed_redirect_hosts'] ?? []),
                'MarketingMonitor/1.0 (+authorized landing page check)',
                isset($snapshot['expected_text']) ? (string) $snapshot['expected_text'] : null,
            );
            $adsbot = $probe->inspect(
                (string) $snapshot['url'],
                (array) ($snapshot['allowed_redirect_hosts'] ?? []),
                'AdsBot-Google (+http://www.google.com/adsbot.html)',
            );
        } catch (Throwable $exception) {
            report($exception);
            $run->forceFill([
                'status' => 'failed',
                'error_type' => 'probe_internal_error',
                'error_detail' => '檢查在記錄結果前失敗。',
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }

        DB::transaction(function () use ($run, $monitor, $normal, $adsbot, $incidents, $scheduler): void {
            $lockedRun = CheckRun::query()->lockForUpdate()->findOrFail($run->id);

            if (in_array($lockedRun->status, ['completed', 'stale_config', 'paused'], true)) {
                return;
            }

            $currentMonitor = Monitor::query()->lockForUpdate()->find($monitor->id);
            $currentVersionMatches = $currentMonitor !== null && $currentMonitor->config_version === $lockedRun->config_version;
            $resolvedIps = array_values(array_unique([...$normal->resolvedPublicIps, ...$adsbot->resolvedPublicIps]));
            $finishedAt = now();
            $lockedRun->forceFill([
                'status' => $currentVersionMatches ? 'completed' : 'stale_config',
                'finished_at' => $finishedAt,
                'normal_status' => $normal->statusCode,
                'adsbot_status' => $adsbot->statusCode,
                'error_type' => $normal->errorType,
                'error_detail' => $normal->errorDetail === null ? null : mb_substr($normal->errorDetail, 0, 300),
                'adsbot_error_type' => $adsbot->errorType,
                'elapsed_ms' => $normal->elapsedMs + $adsbot->elapsedMs,
                'final_url' => $normal->finalUrl,
                'redirects' => $normal->redirects,
                'resolved_public_ips' => $resolvedIps,
                'expected_text_found' => $normal->expectedTextFound,
                'tls_expires_at' => $normal->tlsExpiresAt,
            ])->save();

            if (! $currentVersionMatches || $currentMonitor === null || ! $currentMonitor->enabled) {
                return;
            }

            $currentMonitor->last_checked_at = $finishedAt;
            $currentMonitor->save();
            $confirmationDelay = $incidents->record($currentMonitor, $lockedRun, $normal, $adsbot);

            if ($confirmationDelay !== null && $confirmationDelay > 0) {
                $scheduler->enqueue($currentMonitor, 'confirmation', now()->addSeconds($confirmationDelay), $confirmationDelay);
            }
        });
    }
}
