<?php

namespace App\Services;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\NotificationOutbox;
use App\Models\TlsObservation;
use App\Services\Probe\DomainExpiryResult;
use App\Services\Probe\ExpectedTextMatcher;
use App\Services\Probe\ProbeResult;
use App\Services\Probe\ResponseHeaderCondition;
use App\Services\Probe\TlsCertificateResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
            $hasConfiguredContentExpectation = (is_string($lockedMonitor->expected_text) && trim($lockedMonitor->expected_text) !== '')
                || $lockedMonitor->usesSocialContentBaseline();
            $accessRestricted = $normal->errorType === 'social_access_required';
            $contentCheckUnavailable = $accessRestricted
                || ($normal->errorType === 'body_too_large' && $normalStatusAccepted);
            $visibleTextBaselineUnavailable = $lockedMonitor->usesSocialContentBaseline()
                && $normalStatusAccepted
                && $run->response_visible_text_hash === null;
            $contentMatchIncomplete = $accessRestricted
                || ($hasConfiguredContentExpectation && ($contentCheckUnavailable || $visibleTextBaselineUnavailable));
            $httpFailure = ($normal->errorType !== null && ! $contentMatchIncomplete)
                || $normal->statusCode === null
                || (! $normalStatusAccepted && $normal->statusCode !== 429 && ! $accessRestricted);
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
            $expectedTextMode = in_array($lockedMonitor->expected_text_mode, [ExpectedTextMatcher::MODE_ABSENT, ExpectedTextMatcher::MODE_PRESENT_ANY], true)
                ? $lockedMonitor->expected_text_mode
                : ExpectedTextMatcher::MODE_PRESENT;

            if ($expectedText !== null && $expectedText !== '' && $normalProbeSucceeded) {
                $expectedTextFound = $normal->expectedTextFound;
                $contentFailure = $expectedTextMode === ExpectedTextMatcher::MODE_ABSENT
                    ? $expectedTextFound === true
                    : $expectedTextFound === false;
                $contentRuleKey = $expectedTextMode === ExpectedTextMatcher::MODE_ABSENT ? 'forbidden_text' : 'content';
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

            if ($lockedMonitor->expected_response_header !== null && $normalStatusAccepted && $normal->expectedResponseHeaderFound !== null) {
                $delay = $this->earlierDelay($delay, $this->recordRule(
                    $lockedMonitor,
                    $run,
                    'response_header',
                    'warning',
                    $normal->expectedResponseHeaderFound === false,
                    $normal,
                    true,
                    null,
                    [
                        'expected_response_header_name' => ResponseHeaderCondition::headerName($lockedMonitor->expected_response_header),
                        'expected_response_header_found' => $normal->expectedResponseHeaderFound,
                    ],
                ));
            }

            $expectedTrackingTags = is_array($lockedMonitor->expected_tracking_tags) ? $lockedMonitor->expected_tracking_tags : [];

            if ($expectedTrackingTags !== [] && $normalProbeSucceeded && $normal->responseTrackingTags !== null) {
                $missingTrackingTags = array_values(array_diff($expectedTrackingTags, $normal->responseTrackingTags));
                $delay = $this->earlierDelay($delay, $this->recordRule(
                    $lockedMonitor,
                    $run,
                    'tracking_tags',
                    'warning',
                    $missingTrackingTags !== [],
                    $normal,
                    true,
                    null,
                    ['missing_tracking_tags' => $missingTrackingTags],
                ));
            }

            if ($lockedMonitor->alert_on_utm_loss && $normalProbeSucceeded) {
                $expectedUtmParameters = $this->utmParameters($lockedMonitor->url);

                if ($expectedUtmParameters !== []) {
                    $finalUtmParameters = $this->utmParameters($normal->finalUrl);
                    $lostUtmParameters = array_keys(array_filter(
                        $expectedUtmParameters,
                        static fn (string $value, string $key): bool => ($finalUtmParameters[$key] ?? null) !== $value,
                        ARRAY_FILTER_USE_BOTH,
                    ));
                    $delay = $this->earlierDelay($delay, $this->recordRule(
                        $lockedMonitor,
                        $run,
                        'utm_lost',
                        'warning',
                        $lostUtmParameters !== [],
                        $normal,
                        true,
                        null,
                        ['lost_utm_parameters' => $lostUtmParameters],
                    ));
                }
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

            $minimumResponseBodyBytes = $lockedMonitor->min_response_body_bytes;
            $maximumResponseBodyBytes = $lockedMonitor->max_response_body_bytes;

            if (($minimumResponseBodyBytes !== null || $maximumResponseBodyBytes !== null) && $normalProbeSucceeded) {
                $responseBodyBytes = $normal->responseBodyBytes;
                $responseSizeIndeterminate = $responseBodyBytes === null;
                $responseSizeFailure = $responseSizeIndeterminate
                    || ($minimumResponseBodyBytes !== null && $responseBodyBytes < $minimumResponseBodyBytes)
                    || ($maximumResponseBodyBytes !== null && $responseBodyBytes > $maximumResponseBodyBytes);

                $delay = $this->earlierDelay($delay, $this->recordRule(
                    $lockedMonitor,
                    $run,
                    'response_size',
                    'warning',
                    $responseSizeFailure,
                    $normal,
                    true,
                    null,
                    [
                        'min_response_body_bytes' => $minimumResponseBodyBytes,
                        'max_response_body_bytes' => $maximumResponseBodyBytes,
                        'response_body_bytes' => $responseBodyBytes,
                        'indeterminate' => $responseSizeIndeterminate,
                    ],
                ));
            }

            $visibleTextChangeAlertEnabled = $lockedMonitor->alert_on_visible_text_change;
            $responseMetadataChangeAlertEnabled = $lockedMonitor->alert_on_response_metadata_change;

            if (($visibleTextChangeAlertEnabled || $responseMetadataChangeAlertEnabled) && $normalProbeSucceeded) {
                $previousResponseRun = $this->previousSuccessfulResponseRun($lockedMonitor, $run);
                $visibleTextChanged = null;

                if ($previousResponseRun !== null) {
                    $changedComparisons = [];
                    $monitoredComparisons = [];
                    $metadataChangedFields = [];
                    $comparisonAvailable = false;

                    if ($visibleTextChangeAlertEnabled) {
                        $monitoredComparisons[] = 'visible_text_hash';
                        $previousVisibleTextHash = $previousResponseRun->response_visible_text_hash;
                        $currentVisibleTextHash = $run->response_visible_text_hash;

                        if (is_string($previousVisibleTextHash) && is_string($currentVisibleTextHash)) {
                            $comparisonAvailable = true;
                            $visibleTextChanged = ! hash_equals($previousVisibleTextHash, $currentVisibleTextHash);

                            if ($visibleTextChanged) {
                                $changedComparisons[] = 'visible_text_hash';
                            }
                        }
                    }

                    if ($responseMetadataChangeAlertEnabled) {
                        $monitoredComparisons[] = 'response_metadata';
                        $comparisonAvailable = true;
                        $metadataChangedFields = ResponseMetadataChangeHistory::changedFields($previousResponseRun, $run);

                        if ($metadataChangedFields !== []) {
                            $changedComparisons[] = 'response_metadata';
                        }
                    }

                    if ($comparisonAvailable || $changedComparisons !== []) {
                        $delay = $this->earlierDelay($delay, $this->recordRule(
                            $lockedMonitor,
                            $run,
                            'response_content_change',
                            'warning',
                            $changedComparisons !== [],
                            $normal,
                            true,
                            false,
                            [
                                'comparison' => $changedComparisons[0] ?? ($monitoredComparisons[0] ?? null),
                                'comparisons' => $changedComparisons,
                                'monitored_comparisons' => $monitoredComparisons,
                                'response_metadata_fields' => $metadataChangedFields,
                            ],
                            true,
                        ));
                    }
                }

                if ($visibleTextChangeAlertEnabled) {
                    $run->forceFill(['response_visible_text_changed' => $visibleTextChanged])->save();
                }
            }

            if ($adsbot !== null) {
                $uaDiffers = $normalProbeSucceeded && ! $this->probeSucceededForMonitor($lockedMonitor, $adsbot);
                $delay = $this->earlierDelay($delay, $this->recordRule($lockedMonitor, $run, 'adsbot_difference', 'warning', $uaDiffers, $adsbot, false));
            }

            $delay = $this->earlierDelay($delay, $this->recordRule(
                $lockedMonitor,
                $run,
                'monitor_stale',
                'warning',
                false,
                $normal,
                false,
                true,
                ['last_checked_at' => $lockedMonitor->last_checked_at?->utc()->toIso8601String()],
            ));

            $this->recordTls($lockedMonitor, $run, $normal, $delay);

            return $delay;
        });
    }

    public function queueDueStaleIncidents(): int
    {
        $staleGraceSeconds = (int) config('monitoring.stale_grace_seconds');
        $intervals = Monitor::query()
            ->where('enabled', true)
            ->distinct()
            ->orderBy('interval_seconds')
            ->pluck('interval_seconds')
            ->map(static fn (mixed $seconds): int => (int) $seconds)
            ->all();

        if ($intervals === []) {
            return 0;
        }

        $asOf = now();
        $queued = 0;
        $staleMonitors = Monitor::query()
            ->where('enabled', true)
            ->where(function (Builder $query) use ($intervals, $staleGraceSeconds, $asOf): void {
                foreach ($intervals as $intervalSeconds) {
                    $staleAt = $asOf->copy()->subSeconds($intervalSeconds + $staleGraceSeconds);
                    $query->orWhere(function (Builder $intervalQuery) use ($intervalSeconds, $staleAt): void {
                        $intervalQuery->where('interval_seconds', $intervalSeconds)
                            ->where(function (Builder $timestampQuery) use ($staleAt): void {
                                $timestampQuery->where('last_checked_at', '<', $staleAt)
                                    ->orWhere(function (Builder $uncheckedQuery) use ($staleAt): void {
                                        $uncheckedQuery->whereNull('last_checked_at')
                                            ->where('created_at', '<', $staleAt);
                                    });
                            });
                    });
                }
            })
            ->whereDoesntHave('incidents', static function (Builder $query): void {
                $query->whereColumn('incidents.config_version', 'monitors.config_version')
                    ->where('rule_key', 'monitor_stale')
                    ->whereIn('state', [Incident::STATE_SUSPECT, Incident::STATE_OPEN]);
            })
            ->orderBy('id');

        $staleMonitors->chunkById(100, function (Collection $monitors) use (&$queued): void {
            foreach ($monitors as $monitor) {
                if ($this->openStaleIncident((int) $monitor->id)) {
                    $queued++;
                }
            }
        });

        return $queued;
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
            ->where(function (Builder $query): void {
                $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->whereNotNull('opened_at')
            ->where('opened_at', '<=', $reminderCutoff)
            ->whereDoesntHave('outboxMessages', static function (Builder $query) use ($reminderCutoff): void {
                $query->where('event_type', NotificationOutbox::EVENT_TYPE_REMINDER)
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
                    || ($incident->snoozed_until !== null && $incident->snoozed_until->isFuture())
                    || $incident->opened_at === null
                    || $incident->opened_at->greaterThan($reminderCutoff)
                    || $this->isInMaintenance($incident->monitor)
                ) {
                    return false;
                }

                $hasRecentReminder = NotificationOutbox::query()
                    ->where('incident_id', $incident->id)
                    ->where('event_type', NotificationOutbox::EVENT_TYPE_REMINDER)
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

                if ($failureRun === null && $incident->rule_key !== 'monitor_stale') {
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
                    NotificationOutbox::EVENT_TYPE_REMINDER,
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
            ->where(function (Builder $query): void {
                $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
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
                    || ($incident->snoozed_until !== null && $incident->snoozed_until->isFuture())
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
                if ($failureRun === null && $incident->rule_key !== 'monitor_stale') {
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
                        includeWebhookChannels: false,
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

    public function recordDomainExpiryObservation(Monitor $monitor, CheckRun $run, DomainExpiryResult $result): void
    {
        if (! $result->succeeded()) {
            return;
        }

        DB::transaction(function () use ($monitor, $run, $result): void {
            $lockedMonitor = Monitor::query()->lockForUpdate()->find($monitor->id);

            if ($lockedMonitor === null || $lockedMonitor->config_version !== $run->config_version) {
                return;
            }

            $probeResult = new ProbeResult(null, 0, null, [], [], null, null, null, null);
            $delay = null;

            $this->recordDomainExpiry($lockedMonitor, $run, $probeResult, $result, $delay);
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

    private function openStaleIncident(int $monitorId): bool
    {
        return DB::transaction(function () use ($monitorId): bool {
            $monitor = Monitor::query()->lockForUpdate()->find($monitorId);

            if ($monitor === null || ! $monitor->enabled || ! $monitor->staleAt()->isPast()) {
                return false;
            }

            $activeIncident = Incident::query()
                ->where('monitor_id', $monitor->id)
                ->where('config_version', $monitor->config_version)
                ->where('rule_key', 'monitor_stale')
                ->whereIn('state', [Incident::STATE_SUSPECT, Incident::STATE_OPEN])
                ->lockForUpdate()
                ->first();

            if ($activeIncident !== null) {
                return false;
            }

            $openedAt = now();
            $incident = Incident::query()->create([
                'monitor_id' => $monitor->id,
                'config_version' => $monitor->config_version,
                'rule_key' => 'monitor_stale',
                'severity' => 'warning',
                'state' => Incident::STATE_OPEN,
                'active_key' => hash('sha256', implode(':', [$monitor->id, $monitor->config_version, 'monitor_stale'])),
                'failure_count' => 1,
                'recovery_count' => 0,
                'opened_at' => $openedAt,
                'last_seen_at' => $openedAt,
            ]);
            IncidentEvent::query()->create([
                'incident_id' => $incident->id,
                'check_run_id' => null,
                'event_type' => 'opened',
                'evidence' => [
                    'stale_at' => $monitor->staleAt()->utc()->toIso8601String(),
                    'last_checked_at' => $monitor->last_checked_at?->utc()->toIso8601String(),
                ],
                'created_at' => $openedAt,
            ]);

            if (! $this->isInMaintenance($monitor)) {
                $latestRun = CheckRun::query()
                    ->where('monitor_id', $monitor->id)
                    ->where('config_version', $monitor->config_version)
                    ->where('status', 'completed')
                    ->latest('finished_at')
                    ->first();

                $this->notifications->queue($incident, $monitor, $latestRun, 'opened');
            }

            return true;
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
        bool $openImmediately = false,
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
            $openedAt = $openImmediately ? now() : null;
            $incident = Incident::query()->create([
                'monitor_id' => $monitor->id,
                'config_version' => $run->config_version,
                'rule_key' => $ruleKey,
                'severity' => $severity,
                'state' => $openImmediately ? Incident::STATE_OPEN : Incident::STATE_SUSPECT,
                'active_key' => hash('sha256', implode(':', [$monitor->id, $run->config_version, $ruleKey])),
                'failure_count' => 1,
                'recovery_count' => 0,
                'confirm_after' => $openImmediately ? null : now()->addSeconds($confirmationDelaySeconds),
                'opened_at' => $openedAt,
                'last_seen_at' => now(),
            ]);
            $this->addEvent($incident, $run, 'failure_seen', $evidence);

            if ($openImmediately) {
                $this->addEvent($incident, $run, 'opened', $evidence);

                if ($notifyOnOpen && ! $this->isInMaintenance($monitor)) {
                    $this->notifications->queue($incident, $monitor, $run, 'opened');
                }

                return null;
            }

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

    private function previousSuccessfulResponseRun(Monitor $monitor, CheckRun $run): ?CheckRun
    {
        $previousRun = $monitor->checkRuns()
            ->where('config_version', $run->config_version)
            ->where('status', 'completed')
            ->whereNotIn('source', ['tls_daily', 'domain_daily'])
            ->where(function (Builder $query) use ($run): void {
                $query->where('scheduled_at', '<', $run->scheduled_at)
                    ->orWhere(function (Builder $sameTimeQuery) use ($run): void {
                        $sameTimeQuery->where('scheduled_at', $run->scheduled_at)
                            ->where('id', '<', $run->id);
                    });
            })
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->first([
                'error_type',
                'normal_status',
                'response_visible_text_hash',
                'response_title',
                'response_description',
                'response_image_reference_hash',
                'response_open_graph_type',
                'response_canonical_reference_hash',
                'response_published_at',
                'response_modified_at',
            ]);

        if (
            $previousRun === null
            || $previousRun->error_type !== null
            || ! $monitor->acceptsHttpStatus($previousRun->normal_status)
        ) {
            return null;
        }

        return $previousRun;
    }

    private function recordDomainExpiry(
        Monitor $monitor,
        CheckRun $run,
        ProbeResult $probeResult,
        DomainExpiryResult $result,
        ?int &$delay,
    ): void {
        if ($result->expiresAt === null || $result->domainName === null) {
            return;
        }

        $secondsRemaining = $result->expiresAt->getTimestamp() - now()->timestamp;
        $expired = $secondsRemaining <= 0;
        $withinSevenDays = $secondsRemaining > 0 && $secondsRemaining <= 7 * 86400;
        $withinFourteenDays = $secondsRemaining > 7 * 86400 && $secondsRemaining <= 14 * 86400;
        $withinThirtyDays = $secondsRemaining > 14 * 86400 && $secondsRemaining <= 30 * 86400;
        $evidence = [
            'domain_name' => $result->domainName,
            'domain_expires_at' => $result->expiresAt->format(DATE_ATOM),
        ];

        $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'domain_expired', 'critical', $expired, $probeResult, true, false, $evidence));
        $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'domain_7d', 'critical', $withinSevenDays, $probeResult, true, false, $evidence));
        $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'domain_14d', 'warning', $withinFourteenDays, $probeResult, true, false, $evidence));
        $delay = $this->earlierDelay($delay, $this->recordRule($monitor, $run, 'domain_30d', 'warning', $withinThirtyDays, $probeResult, true, false, $evidence));
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

    /**
     * @return array<string, string> UTM query parameters keyed by lower-case name.
     */
    private function utmParameters(?string $url): array
    {
        $query = is_string($url) ? parse_url($url, PHP_URL_QUERY) : null;

        if (! is_string($query) || $query === '') {
            return [];
        }

        $parameters = [];

        foreach (explode('&', $query) as $segment) {
            [$rawKey, $rawValue] = array_pad(explode('=', $segment, 2), 2, '');
            $key = mb_strtolower(rawurldecode($rawKey));

            if (str_starts_with($key, 'utm_')) {
                $parameters[$key] = rawurldecode(str_replace('+', ' ', $rawValue));
            }
        }

        return $parameters;
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
