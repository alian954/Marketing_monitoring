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
use App\Services\Probe\TlsCertificateResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class IncidentManager
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function record(Monitor $monitor, CheckRun $run, ProbeResult $normal, ?ProbeResult $adsbot): ?int
    {
        return DB::transaction(function () use ($monitor, $run, $normal, $adsbot): ?int {
            $lockedMonitor = Monitor::query()->lockForUpdate()->find($monitor->id);

            if ($lockedMonitor === null || $lockedMonitor->config_version !== $run->config_version) {
                return null;
            }

            $delay = null;
            $normalStatusAccepted = $lockedMonitor->acceptsHttpStatus($normal->statusCode);
            $normalProbeSucceeded = $this->probeSucceededForMonitor($lockedMonitor, $normal);
            $contentMatchIncomplete = $normal->errorType === 'body_too_large'
                && $normalStatusAccepted
                && is_string($lockedMonitor->expected_text)
                && $lockedMonitor->expected_text !== '';
            $httpFailure = ($normal->errorType !== null && ! $contentMatchIncomplete)
                || $normal->statusCode === null
                || (! $normalStatusAccepted && $normal->statusCode !== 429);
            $delay = $this->earlierDelay($delay, $this->recordRule($lockedMonitor, $run, 'http', 'critical', $httpFailure, $normal, true));

            if ($normal->statusCode === 429 && ! $normalStatusAccepted) {
                $delay = $this->earlierDelay($delay, $this->recordRule($lockedMonitor, $run, 'probe_restricted', 'warning', true, $normal, false));
            } else {
                $delay = $this->earlierDelay($delay, $this->recordRule($lockedMonitor, $run, 'probe_restricted', 'warning', false, $normal, false));
            }

            $delay = $this->earlierDelay($delay, $this->recordRule(
                $lockedMonitor,
                $run,
                'probe_incomplete',
                'warning',
                $contentMatchIncomplete,
                $normal,
                true,
            ));

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

            if ($adsbot !== null) {
                $uaDiffers = $normalProbeSucceeded && ! $this->probeSucceededForMonitor($lockedMonitor, $adsbot);
                $delay = $this->earlierDelay($delay, $this->recordRule($lockedMonitor, $run, 'adsbot_difference', 'warning', $uaDiffers, $adsbot, false));
            }

            $this->recordTls($lockedMonitor, $run, $normal, $delay);

            return $delay;
        });
    }

    public function queueDueReminders(): int
    {
        $intervalMinutes = (int) config('monitoring.incident_reminder_interval_minutes');

        if ($intervalMinutes < 1) {
            return 0;
        }

        $reminderCutoff = now()->subMinutes($intervalMinutes);
        $incidentIds = Incident::query()
            ->where('state', Incident::STATE_OPEN)
            ->whereNotNull('opened_at')
            ->where('opened_at', '<=', $reminderCutoff)
            ->whereDoesntHave('outboxMessages', static function (Builder $query) use ($reminderCutoff): void {
                $query->where('event_type', 'reminder')
                    ->where('created_at', '>', $reminderCutoff);
            })
            ->orderBy('opened_at')
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $queuedReminders = 0;

        foreach ($incidentIds as $incidentId) {
            $wasQueued = DB::transaction(function () use ($incidentId, $reminderCutoff): bool {
                $incident = Incident::query()->lockForUpdate()->with('monitor')->find($incidentId);

                if (
                    $incident === null
                    || $incident->state !== Incident::STATE_OPEN
                    || $incident->opened_at === null
                    || $incident->opened_at->greaterThan($reminderCutoff)
                    || $this->isInMaintenance($incident->monitor)
                ) {
                    return false;
                }

                $hasRecentReminder = NotificationOutbox::query()
                    ->where('incident_id', $incident->id)
                    ->where('event_type', 'reminder')
                    ->where('created_at', '>', $reminderCutoff)
                    ->exists();

                if ($hasRecentReminder) {
                    return false;
                }

                $failureRun = IncidentEvent::query()
                    ->where('incident_id', $incident->id)
                    ->where('event_type', 'failure_seen')
                    ->whereNotNull('check_run_id')
                    ->with('checkRun')
                    ->latest('created_at')
                    ->first()?->checkRun;

                if ($failureRun === null) {
                    return false;
                }

                $reminderNumber = IncidentEvent::query()
                    ->where('incident_id', $incident->id)
                    ->where('event_type', 'reminder_queued')
                    ->count() + 1;
                $wasQueued = $this->notifications->queue(
                    $incident,
                    $incident->monitor,
                    $failureRun,
                    'reminder',
                    'reminder-'.$reminderNumber,
                );

                if ($wasQueued) {
                    IncidentEvent::query()->forceCreate([
                        'incident_id' => $incident->id,
                        'check_run_id' => null,
                        'event_type' => 'reminder_queued',
                        'evidence' => ['reminder_number' => $reminderNumber],
                        'created_at' => now(),
                    ]);
                }

                return $wasQueued;
            });

            if ($wasQueued) {
                $queuedReminders++;
            }
        }

        return $queuedReminders;
    }

    public function queueDueEscalations(): int
    {
        $incidentIds = Incident::query()
            ->where('state', Incident::STATE_OPEN)
            ->whereNull('acknowledged_at')
            ->whereNotNull('opened_at')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->whereHas('monitor', function (Builder $monitorQuery): void {
                        $monitorQuery->whereNotNull('notification_escalation_email')
                            ->whereNotNull('notification_escalation_delay_minutes');
                        $this->whereEscalationStageDue($monitorQuery, 'notification_escalation_delay_minutes');
                    })->whereDoesntHave('outboxMessages', static function (Builder $outboxQuery): void {
                        $outboxQuery->where('event_type', NotificationOutbox::EVENT_TYPE_ESCALATION);
                    });
                })->orWhere(function (Builder $query): void {
                    $query->whereHas('monitor', function (Builder $monitorQuery): void {
                        $monitorQuery->whereNotNull('notification_escalation_stage_2_email')
                            ->whereNotNull('notification_escalation_stage_2_delay_minutes');
                        $this->whereEscalationStageDue($monitorQuery, 'notification_escalation_stage_2_delay_minutes');
                    })->whereDoesntHave('outboxMessages', static function (Builder $outboxQuery): void {
                        $outboxQuery->where('event_type', NotificationOutbox::EVENT_TYPE_ESCALATION_STAGE_2);
                    });
                });
            })
            ->orderBy('opened_at')
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $queuedEscalations = 0;

        foreach ($incidentIds as $incidentId) {
            $queuedStageCount = DB::transaction(function () use ($incidentId): int {
                $incident = Incident::query()->lockForUpdate()->with('monitor')->find($incidentId);

                if (
                    $incident === null
                    || $incident->state !== Incident::STATE_OPEN
                    || $incident->acknowledged_at !== null
                    || $incident->opened_at === null
                    || $incident->monitor === null
                    || $incident->config_version !== $incident->monitor->config_version
                    || $this->isInMaintenance($incident->monitor)
                ) {
                    return 0;
                }

                $monitor = $incident->monitor;
                $failureRun = IncidentEvent::query()
                    ->where('incident_id', $incident->id)
                    ->where('event_type', 'failure_seen')
                    ->whereNotNull('check_run_id')
                    ->with('checkRun')
                    ->latest('created_at')
                    ->first()?->checkRun;
                if ($failureRun === null) {
                    return 0;
                }

                $stages = [
                    [
                        'number' => 1,
                        'email' => $monitor->notification_escalation_email,
                        'delay_minutes' => $monitor->notification_escalation_delay_minutes,
                        'event_type' => NotificationOutbox::EVENT_TYPE_ESCALATION,
                    ],
                    [
                        'number' => 2,
                        'email' => $monitor->notification_escalation_stage_2_email,
                        'delay_minutes' => $monitor->notification_escalation_stage_2_delay_minutes,
                        'event_type' => NotificationOutbox::EVENT_TYPE_ESCALATION_STAGE_2,
                    ],
                ];
                $queuedStages = 0;

                foreach ($stages as $stage) {
                    $email = $stage['email'];
                    $delayMinutes = $stage['delay_minutes'];
                    $eventType = $stage['event_type'];

                    if (
                        ! is_string($email)
                        || $email === ''
                        || ! is_int($delayMinutes)
                        || $delayMinutes < 1
                        || $incident->opened_at->addMinutes($delayMinutes)->isFuture()
                        || $incident->outboxMessages()->where('event_type', $eventType)->exists()
                    ) {
                        continue;
                    }

                    $recipients = NotificationEmailRecipientParser::parse($email);

                    if ($recipients === []) {
                        continue;
                    }

                    $wasQueued = $this->notifications->queue(
                        $incident,
                        $monitor,
                        $failureRun,
                        $eventType,
                        emailRecipientsOverride: $recipients,
                        includeSlack: false,
                    );

                    if ($wasQueued) {
                        IncidentEvent::query()->forceCreate([
                            'incident_id' => $incident->id,
                            'check_run_id' => null,
                            'event_type' => 'escalation_queued',
                            'evidence' => [
                                'stage' => $stage['number'],
                                'recipient_count' => count($recipients),
                                'delay_minutes' => $delayMinutes,
                            ],
                            'created_at' => now(),
                        ]);
                        $queuedStages++;
                    }
                }

                return $queuedStages;
            });

            $queuedEscalations += $queuedStageCount;
        }

        return $queuedEscalations;
    }

    public function recordTlsObservation(Monitor $monitor, CheckRun $run, TlsCertificateResult $result): void
    {
        DB::transaction(function () use ($monitor, $run, $result): void {
            $lockedMonitor = Monitor::query()->lockForUpdate()->find($monitor->id);

            if ($lockedMonitor === null || $lockedMonitor->config_version !== $run->config_version) {
                return;
            }

            $probeResult = new ProbeResult(
                null,
                0,
                null,
                [],
                [],
                $result->errorType,
                $result->errorDetail,
                null,
                $result->expiresAt,
            );
            $delay = null;

            $this->recordTls($lockedMonitor, $run, $probeResult, $delay);
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
        $confirmationDelaySeconds = $this->confirmationDelaySeconds($monitor);

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
                'confirm_after' => now()->addSeconds($confirmationDelaySeconds),
                'last_seen_at' => now(),
            ]);
            $this->addEvent($incident, $run, 'failure_seen', $evidence);

            return $confirmationDelaySeconds;
        }

        if ($incident === null) {
            return null;
        }

        if ($failed) {
            $isNewObservation = $this->addEvent($incident, $run, 'failure_seen', $evidence);
            $incident->last_seen_at = now();
            $incident->recovery_count = 0;
            $incident->confirm_after = $incident->state === Incident::STATE_SUSPECT
                ? ($incident->confirm_after ?? now()->addSeconds($confirmationDelaySeconds))
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
            $incident->confirm_after ??= now()->addSeconds($confirmationDelaySeconds);
            $incident->save();

            return $confirmationDelaySeconds;
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
        $withinThirtyDays = $secondsRemaining > 14 * 86400 && $secondsRemaining <= 30 * 86400;

        $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'tls_expired', 'critical', $expired, $result, true, false));
        $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'tls_7d', 'critical', $withinSevenDays, $result, true, false));
        $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'tls_14d', 'warning', $withinFourteenDays, $result, true, false));
        $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'tls_30d', 'warning', $withinThirtyDays, $result, true, false));
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

    private function confirmationDelaySeconds(Monitor $monitor): int
    {
        return max(1, (int) ($monitor->confirmation_delay_seconds ?? config('monitoring.confirmation_delay_seconds')));
    }

    private function probeSucceededForMonitor(Monitor $monitor, ProbeResult $result): bool
    {
        return $result->errorType === null && $monitor->acceptsHttpStatus($result->statusCode);
    }

    private function isInMaintenance(Monitor $monitor): bool
    {
        $currentTime = now();

        return $monitor->maintenanceWindows()
            ->get()
            ->contains(fn (MaintenanceWindow $window): bool => $window->isActiveAt($currentTime));
    }

    private function whereEscalationStageDue(Builder $monitorQuery, string $delayColumn): void
    {
        $deadlineExpression = match (DB::connection()->getDriverName()) {
            'mysql' => 'DATE_ADD(incidents.opened_at, INTERVAL monitors.'.$delayColumn.' MINUTE) <= ?',
            'pgsql' => "incidents.opened_at + (monitors.{$delayColumn} * INTERVAL '1 minute') <= ?",
            'sqlite' => "datetime(incidents.opened_at, '+' || monitors.{$delayColumn} || ' minutes') <= ?",
            default => throw new \RuntimeException('The current database driver cannot compare escalation deadlines.'),
        };

        $monitorQuery->whereRaw($deadlineExpression, [now()->toDateTimeString()]);
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
            ->get()
            ->contains(fn (MaintenanceWindow $window): bool => $window->isActiveAt($incident->opened_at));
    }
}
