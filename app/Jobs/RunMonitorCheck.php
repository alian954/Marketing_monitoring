<?php

namespace App\Jobs;

use App\Models\CheckRun;
use App\Models\Monitor;
use App\Services\DnsChangeRecorder;
use App\Services\IncidentManager;
use App\Services\MonitorHostConcurrencyLock;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\RetryAfterParser;
use App\Services\RunScheduler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunMonitorCheck implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $maxExceptions = 3;

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

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        $monitor = CheckRun::query()->with('monitor')->find($this->checkRunId)?->monitor;

        if ($monitor === null) {
            return [];
        }

        return [app(MonitorHostConcurrencyLock::class)->forUrl($monitor->url)];
    }

    public function handle(
        ProbeClient $probe,
        IncidentManager $incidents,
        RunScheduler $scheduler,
        ?DnsChangeRecorder $dnsChanges = null,
    ): void {
        $dnsChanges ??= app(DnsChangeRecorder::class);
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
        $timeoutSeconds = (int) ($snapshot['timeout_seconds'] ?? config('monitoring.request_timeout_seconds'));
        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();
        $adsbot = null;

        try {
            $probeArguments = [
                (string) $snapshot['url'],
                (array) ($snapshot['allowed_redirect_hosts'] ?? []),
                'MarketingMonitor/1.0 (+authorized landing page check)',
                isset($snapshot['expected_text']) ? (string) $snapshot['expected_text'] : null,
                $timeoutSeconds,
                (string) ($snapshot['expected_text_mode'] ?? 'present'),
            ];

            $requestHeaders = (array) ($snapshot['request_headers'] ?? []);
            $expectedResponseHeader = isset($snapshot['expected_response_header']) ? (string) $snapshot['expected_response_header'] : null;

            if ($requestHeaders !== [] || $expectedResponseHeader !== null) {
                $probeArguments[] = $requestHeaders;
            }

            if ($expectedResponseHeader !== null) {
                $probeArguments[] = $expectedResponseHeader;
            }

            $socialPlatform = Monitor::socialPlatformKeyForUrl((string) $snapshot['url']);

            if ($socialPlatform !== null) {
                $probeArguments[6] ??= $requestHeaders;
                $probeArguments[7] ??= $expectedResponseHeader;
                $probeArguments[8] = $socialPlatform;
            }

            $normal = $probe->inspect(...$probeArguments);

            if ($monitor->socialPlatform() === null && ! in_array($normal->errorType, ['dns_no_address', 'dns_timeout'], true)) {
                $adsbot = $probe->inspect(
                    (string) $snapshot['url'],
                    (array) ($snapshot['allowed_redirect_hosts'] ?? []),
                    'AdsBot-Google (+http://www.google.com/adsbot.html)',
                    null,
                    $timeoutSeconds,
                );
            }
        } catch (Throwable $exception) {
            report($exception);
            throw $exception;
        }

        DB::transaction(function () use ($run, $monitor, $normal, $adsbot, $incidents, $scheduler, $dnsChanges): void {
            $lockedRun = CheckRun::query()->lockForUpdate()->findOrFail($run->id);

            if (in_array($lockedRun->status, ['completed', 'stale_config', 'paused'], true)) {
                return;
            }

            $currentMonitor = Monitor::query()->lockForUpdate()->find($monitor->id);
            $currentVersionMatches = $currentMonitor !== null && $currentMonitor->config_version === $lockedRun->config_version;
            $resolvedIps = $normal->resolvedPublicIps;

            if ($adsbot !== null) {
                $resolvedIps = [...$resolvedIps, ...$adsbot->resolvedPublicIps];
            }

            $resolvedIps = array_values(array_unique($resolvedIps));
            $finishedAt = now();
            $responseConditionStatusAccepted = $monitor->acceptsHttpStatus($normal->statusCode);
            $lockedRun->forceFill([
                'status' => $currentVersionMatches ? 'completed' : 'stale_config',
                'finished_at' => $finishedAt,
                'normal_status' => $normal->statusCode,
                'adsbot_status' => $adsbot?->statusCode,
                'error_type' => $normal->errorType,
                'error_detail' => $normal->errorDetail === null ? null : mb_substr($normal->errorDetail, 0, 300),
                'adsbot_error_type' => $adsbot?->errorType,
                'elapsed_ms' => $normal->elapsedMs + ($adsbot?->elapsedMs ?? 0),
                'normal_elapsed_ms' => $normal->elapsedMs,
                'final_url' => $normal->finalUrl,
                'redirects' => $normal->redirects,
                'resolved_public_ips' => $resolvedIps,
                'monitor_host_ips' => $normal->monitorHostIps,
                'monitor_host_cnames' => $normal->monitorHostCnames,
                'monitor_host_dns_records' => $normal->monitorHostDnsRecords,
                'expected_text_found' => $responseConditionStatusAccepted ? $normal->expectedTextFound : null,
                'expected_text_term_matches' => $responseConditionStatusAccepted ? $normal->expectedTextTermMatches : null,
                'expected_response_header_found' => $responseConditionStatusAccepted ? $normal->expectedResponseHeaderFound : null,
                'response_title' => $normal->responseTitle,
                'response_description' => $normal->responseDescription,
                'response_public_engagement_metrics' => $normal->publicEngagementMetrics,
                'response_image_reference_hash' => $normal->responseImageReferenceHash,
                'response_open_graph_type' => $normal->responseOpenGraphType,
                'response_canonical_reference_hash' => $normal->responseCanonicalReferenceHash,
                'response_published_at' => $normal->responsePublishedAt,
                'response_modified_at' => $normal->responseModifiedAt,
                'response_visible_text_hash' => $responseConditionStatusAccepted ? $normal->responseVisibleTextHash : null,
                'response_robots_directives' => $normal->responseRobotsDirectives,
                'response_content_type' => $normal->responseContentType,
                'response_body_bytes' => $normal->responseBodyBytes,
                'tls_expires_at' => $normal->tlsExpiresAt,
            ])->save();

            if (! $currentVersionMatches || $currentMonitor === null || ! $currentMonitor->enabled) {
                return;
            }

            $currentMonitor->last_checked_at = $finishedAt;
            $currentMonitor->save();

            if ($lockedRun->source === 'scheduled') {
                $dnsChanges->recordFor($lockedRun);
            }

            $confirmationDelay = $incidents->record($currentMonitor, $lockedRun, $normal, $adsbot);
            $retryAfterSeconds = in_array($normal->statusCode, [429, 503], true)
                ? min(max(0, $normal->retryAfterSeconds ?? (int) config('monitoring.retry_after_fallback_seconds')), RetryAfterParser::MAX_DELAY_SECONDS)
                : 0;
            $followUpDelaySeconds = $confirmationDelay === null
                ? null
                : max($confirmationDelay, $retryAfterSeconds);
            $nextCheckDelaySeconds = $retryAfterSeconds > 0
                ? ($followUpDelaySeconds === null
                    ? $retryAfterSeconds
                    : $followUpDelaySeconds + $currentMonitor->interval_seconds)
                : 0;

            if ($normal->errorType === 'social_access_required' && $currentMonitor->socialPlatform() === 'Dcard') {
                $nextCheckDelaySeconds = max(
                    $nextCheckDelaySeconds,
                    max(0, (int) config('monitoring.dcard_access_gate_cooldown_seconds')),
                );
            }

            if ($nextCheckDelaySeconds > 0) {
                $nextScheduledCheckAt = $finishedAt->copy()->addSeconds($nextCheckDelaySeconds);

                if ($currentMonitor->next_check_at === null || $currentMonitor->next_check_at->lessThan($nextScheduledCheckAt)) {
                    $currentMonitor->next_check_at = $nextScheduledCheckAt;
                    $currentMonitor->save();
                }
            }

            if ($followUpDelaySeconds !== null && $followUpDelaySeconds > 0) {
                $scheduler->enqueue(
                    $currentMonitor,
                    'confirmation',
                    $finishedAt->copy()->addSeconds($followUpDelaySeconds),
                    $followUpDelaySeconds,
                );
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $run = CheckRun::query()->lockForUpdate()->find($this->checkRunId);

            if ($run === null || ! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }

            $run->forceFill([
                'status' => 'failed',
                'error_type' => 'queue_job_failed',
                'error_detail' => '檢查佇列工作重試耗盡，未能完成探測。',
                'finished_at' => now(),
            ])->save();
        });
    }
}
