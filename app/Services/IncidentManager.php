<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\TlsObservation;
use App\Services\Probe\ProbeResult;
use Illuminate\Support\Facades\DB;

class IncidentManager
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function record(Monitor $monitor, CheckRun $run, ProbeResult $normal, ProbeResult $adsbot): ?int
    {
        return DB::transaction(function () use ($monitor, $run, $normal, $adsbot): ?int {
            $lockedMonitor = Monitor::query()->lockForUpdate()->find($monitor->id);

            if ($lockedMonitor === null || $lockedMonitor->config_version !== $run->config_version) {
                return null;
            }

            $delay = null;
            $normalStatusAccepted = $lockedMonitor->acceptsHttpStatus($normal->statusCode);
            $normalProbeSucceeded = $this->probeSucceededForMonitor($lockedMonitor, $normal);
            $httpFailure = $normal->errorType !== null
                || $normal->statusCode === null
                || (! $normalStatusAccepted && $normal->statusCode !== 429);
            $delay = $this->earlierDelay($delay, $this->recordRule($lockedMonitor, $run, 'http', 'critical', $httpFailure, $normal, true));

            if ($normal->statusCode === 429 && ! $normalStatusAccepted) {
                $delay = $this->earlierDelay($delay, $this->recordRule($lockedMonitor, $run, 'probe_restricted', 'warning', true, $normal, false));
            } else {
                $delay = $this->earlierDelay($delay, $this->recordRule($lockedMonitor, $run, 'probe_restricted', 'warning', false, $normal, false));
            }

            $expectedText = $lockedMonitor->expected_text;
            $expectedTextMode = $lockedMonitor->expected_text_mode === 'absent' ? 'absent' : 'present';

            if ($expectedText !== null && $expectedText !== '' && $normalProbeSucceeded) {
                $expectedTextFound = $normal->expectedTextFound;
                $contentFailure = $expectedTextMode === 'absent'
                    ? $expectedTextFound === true
                    : $expectedTextFound === false;
                $contentRuleKey = $expectedTextMode === 'absent' ? 'forbidden_text' : 'content';
                $delay = $this->earlierDelay($delay, $this->recordRule(
                    $lockedMonitor,
                    $run,
                    $contentRuleKey,
                    'warning',
                    $contentFailure,
                    $normal,
                    true,
                    null,
                    [
                        'expected_text_mode' => $expectedTextMode,
                        'expected_text_found' => $expectedTextFound,
                    ],
                ));
            }

            $responseTimeThreshold = $lockedMonitor->response_time_threshold_ms;
            $normalElapsedMs = $run->normal_elapsed_ms ?? $normal->elapsedMs;

            if ($responseTimeThreshold !== null && $normalProbeSucceeded) {
                $delay = $this->earlierDelay($delay, $this->recordRule(
                    $lockedMonitor,
                    $run,
                    'response_time',
                    'warning',
                    $normalElapsedMs > $responseTimeThreshold,
                    $normal,
                    true,
                    null,
                    ['threshold_ms' => $responseTimeThreshold],
                ));
            }

            $uaDiffers = $normalProbeSucceeded && ! $this->probeSucceededForMonitor($lockedMonitor, $adsbot);
            $delay = $this->earlierDelay($delay, $this->recordRule($lockedMonitor, $run, 'adsbot_difference', 'warning', $uaDiffers, $adsbot, false));

            $this->recordTls($lockedMonitor, $run, $normal, $delay);

            return $delay;
        });
    }

    public function pauseMonitor(Monitor $monitor): void
    {
        DB::transaction(function () use ($monitor): void {
            $lockedMonitor = Monitor::query()->lockForUpdate()->findOrFail($monitor->id);
            $incidents = Incident::query()
                ->where('monitor_id', $lockedMonitor->id)
                ->whereIn('state', [Incident::STATE_SUSPECT, Incident::STATE_OPEN])
                ->lockForUpdate()
                ->get();

            foreach ($incidents as $incident) {
                $incident->forceFill(['state' => Incident::STATE_PAUSED, 'active_key' => null, 'resolved_at' => null])->save();
                IncidentEvent::query()->firstOrCreate([
                    'incident_id' => $incident->id,
                    'event_type' => 'paused',
                    'check_run_id' => null,
                ], ['evidence' => ['reason' => 'monitor_paused'], 'created_at' => now()]);
            }
        });
    }

    private function recordRule(
        Monitor $monitor,
        CheckRun $run,
        string $ruleKey,
        string $severity,
        bool $failed,
        ProbeResult $result,
        bool $notifyOnOpen,
        ?bool $notifyOnRecovery = null,
        array $additionalEvidence = [],
    ): ?int {
        $incident = Incident::query()
            ->where('monitor_id', $monitor->id)
            ->where('config_version', $run->config_version)
            ->where('rule_key', $ruleKey)
            ->whereIn('state', [Incident::STATE_SUSPECT, Incident::STATE_OPEN])
            ->lockForUpdate()
            ->first();

        $evidence = [
            'status' => $result->statusCode,
            'error_type' => $result->errorType,
            'elapsed_ms' => $result->elapsedMs,
        ] + $additionalEvidence;

        if ($failed && $incident === null) {
            $incident = Incident::query()->create([
                'monitor_id' => $monitor->id,
                'config_version' => $run->config_version,
                'rule_key' => $ruleKey,
                'severity' => $severity,
                'state' => Incident::STATE_SUSPECT,
                'active_key' => hash('sha256', implode(':', [$monitor->id, $run->config_version, $ruleKey])),
                'failure_count' => 1,
                'recovery_count' => 0,
                'confirm_after' => now()->addSeconds((int) config('monitoring.confirmation_delay_seconds')),
                'last_seen_at' => now(),
            ]);
            $this->addEvent($incident, $run, 'failure_seen', $evidence);

            return (int) config('monitoring.confirmation_delay_seconds');
        }

        if ($incident === null) {
            return null;
        }

        if ($failed) {
            $isNewObservation = $this->addEvent($incident, $run, 'failure_seen', $evidence);
            $incident->last_seen_at = now();
            $incident->recovery_count = 0;
            $incident->confirm_after = $incident->state === Incident::STATE_SUSPECT
                ? ($incident->confirm_after ?? now()->addSeconds((int) config('monitoring.confirmation_delay_seconds')))
                : null;

            if ($isNewObservation) {
                $incident->failure_count++;
            }

            if ($incident->state === Incident::STATE_SUSPECT && $incident->confirm_after->getTimestamp() <= now()->timestamp) {
                $incident->state = Incident::STATE_OPEN;
                $incident->opened_at = now();
                $incident->confirm_after = null;
                $incident->save();
                $this->addEvent($incident, $run, 'opened', $evidence);

                if ($notifyOnOpen && ! $this->isInMaintenance($monitor)) {
                    $this->notifications->queue($incident, $monitor, $run, 'opened');
                }

                return null;
            }

            $incident->save();

            if (
                $incident->state === Incident::STATE_OPEN
                && $notifyOnOpen
                && ! $this->hasOpenedNotification($incident)
                && ! $this->isInMaintenance($monitor)
            ) {
                $this->notifications->queue($incident, $monitor, $run, 'opened');
            }

            if ($incident->state === Incident::STATE_SUSPECT) {
                return max(1, $incident->confirm_after->getTimestamp() - now()->timestamp);
            }

            return null;
        }

        if ($incident->state === Incident::STATE_SUSPECT) {
            $this->addEvent($incident, $run, 'false_alarm', $evidence);
            $incident->state = Incident::STATE_RESOLVED;
            $incident->classification = 'false_alarm';
            $incident->active_key = null;
            $incident->resolved_at = now();
            $incident->confirm_after = null;
            $incident->save();

            return null;
        }

        $isNewRecovery = $this->addEvent($incident, $run, 'recovery_seen', $evidence);

        if ($isNewRecovery) {
            $incident->recovery_count++;
        }

        if ($incident->recovery_count === 1) {
            $incident->confirm_after ??= now()->addSeconds((int) config('monitoring.confirmation_delay_seconds'));
            $incident->save();

            return (int) config('monitoring.confirmation_delay_seconds');
        }

        if ($incident->recovery_count >= 2 && $incident->confirm_after !== null && $incident->confirm_after->getTimestamp() <= now()->timestamp) {
            $incident->state = Incident::STATE_RESOLVED;
            $incident->active_key = null;
            $incident->resolved_at = now();
            $incident->confirm_after = null;
            $incident->save();
            $this->addEvent($incident, $run, 'recovered', $evidence);

            if (
                ($notifyOnRecovery ?? $notifyOnOpen)
                && ! $this->isInMaintenance($monitor)
                && ($this->hasOpenedNotification($incident) || ! $this->wasOpenedDuringMaintenance($incident))
            ) {
                $this->notifications->queue($incident, $monitor, $run, 'recovered');
            }

            return null;
        }

        $incident->save();

        return $incident->confirm_after === null ? null : max(1, $incident->confirm_after->getTimestamp() - now()->timestamp);
    }

    private function recordTls(Monitor $monitor, CheckRun $run, ProbeResult $result, ?int &$delay): void
    {
        if ($result->tlsExpiresAt !== null || str_starts_with((string) $result->errorType, 'tls_')) {
            TlsObservation::query()->updateOrCreate(
                ['monitor_id' => $monitor->id, 'observed_on' => today()],
                ['expires_at' => $result->tlsExpiresAt, 'error_type' => $result->errorType],
            );
        }

        if ($result->errorType === 'tls_handshake' || $result->errorType === 'tls_verify') {
            if ($result->tlsExpiresAt !== null && $result->tlsExpiresAt->getTimestamp() <= now()->timestamp) {
                $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'tls_expired', 'critical', true, $result, true, false));
            } else {
                $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'tls_handshake', 'critical', true, $result, true, false));
            }

            return;
        }

        if ($result->tlsExpiresAt === null) {
            return;
        }

        $secondsRemaining = $result->tlsExpiresAt->getTimestamp() - now()->timestamp;
        $expired = $secondsRemaining <= 0;
        $withinSevenDays = $secondsRemaining > 0 && $secondsRemaining <= 7 * 86400;
        $withinFourteenDays = $secondsRemaining > 7 * 86400 && $secondsRemaining <= 14 * 86400;

        $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'tls_expired', 'critical', $expired, $result, true, false));
        $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'tls_7d', 'critical', $withinSevenDays, $result, true, false));
        $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'tls_14d', 'warning', $withinFourteenDays, $result, true, false));
        $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'tls_handshake', 'critical', false, $result, true, false));
    }

    /** @param array<string, mixed> $evidence */
    private function addEvent(Incident $incident, CheckRun $run, string $eventType, array $evidence): bool
    {
        return IncidentEvent::query()->firstOrCreate([
            'incident_id' => $incident->id,
            'check_run_id' => $run->id,
            'event_type' => $eventType,
        ], ['evidence' => $evidence, 'created_at' => now()])->wasRecentlyCreated;
    }

    private function earlierDelay(?int $current, ?int $candidate): ?int
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null ? $candidate : min($current, $candidate);
    }

    private function probeSucceededForMonitor(Monitor $monitor, ProbeResult $result): bool
    {
        return $result->errorType === null && $monitor->acceptsHttpStatus($result->statusCode);
    }

    private function isInMaintenance(Monitor $monitor): bool
    {
        $currentTime = now();

        return $monitor->maintenanceWindows()
            ->where('starts_at', '<=', $currentTime)
            ->where('ends_at', '>', $currentTime)
            ->exists();
    }

    private function hasOpenedNotification(Incident $incident): bool
    {
        return NotificationOutbox::query()
            ->where('incident_id', $incident->id)
            ->where('event_type', 'opened')
            ->exists();
    }

    private function wasOpenedDuringMaintenance(Incident $incident): bool
    {
        if ($incident->opened_at === null) {
            return false;
        }

        return MaintenanceWindow::query()
            ->where('monitor_id', $incident->monitor_id)
            ->where('starts_at', '<=', $incident->opened_at)
            ->where('ends_at', '>', $incident->opened_at)
            ->exists();
    }
}
