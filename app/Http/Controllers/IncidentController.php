<?php

namespace App\Http\Controllers;

use App\Jobs\SendNotificationOutbox;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\NotificationOutbox;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncidentController extends Controller
{
    public function bulkAcknowledge(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'acknowledgement_action' => ['required', 'in:acknowledge,unacknowledge,snooze_notifications,resume_notifications'],
            'duration_minutes' => ['required_if:acknowledgement_action,snooze_notifications', 'nullable', 'integer', 'in:30,60,240,1440'],
            'incident_ids' => ['required', 'array', 'min:1', 'max:25'],
            'incident_ids.*' => ['required', 'integer', 'distinct', 'exists:incidents,id'],
        ], [
            'acknowledgement_action.required' => '請先選擇認領操作。',
            'acknowledgement_action.in' => '認領操作無效，請重新選擇。',
            'duration_minutes.required_if' => '請先選擇通知暫停時間。',
            'duration_minutes.in' => '通知暫停時間無效，請重新選擇。',
            'incident_ids.required' => '請先選取要認領的事件。',
            'incident_ids.array' => '所選事件格式無效，請重新選取。',
            'incident_ids.min' => '請先選取要認領的事件。',
            'incident_ids.max' => '每次最多只能認領 25 件事件。',
            'incident_ids.*.distinct' => '事件清單有重複項目，請重新選取。',
            'incident_ids.*.exists' => '部分事件已不存在，請重新整理後再試。',
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $selectedIds = array_map('intval', $validated['incident_ids']);
        $action = $validated['acknowledgement_action'];
        $durationMinutes = isset($validated['duration_minutes']) ? (int) $validated['duration_minutes'] : null;
        $snoozedUntil = $durationMinutes === null ? null : now()->addMinutes($durationMinutes);
        $acknowledge = $action === 'acknowledge';
        $eventType = $acknowledge ? 'acknowledged' : 'unacknowledged';
        $acknowledgedAt = now();

        /** @var array{changed: int, skipped: int} $result */
        $result = DB::transaction(function () use ($selectedIds, $actor, $acknowledgedAt, $acknowledge, $eventType, $action, $durationMinutes, $snoozedUntil): array {
            $lockedIncidents = Incident::query()
                ->whereKey($selectedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $changedCount = 0;
            $skippedCount = count($selectedIds) - $lockedIncidents->count();

            foreach ($lockedIncidents as $lockedIncident) {
                if ($action === 'snooze_notifications') {
                    if (
                        $lockedIncident->state !== Incident::STATE_OPEN
                        || ($lockedIncident->snoozed_until !== null && $lockedIncident->snoozed_until->isFuture())
                    ) {
                        $skippedCount++;

                        continue;
                    }

                    $lockedIncident->forceFill(['snoozed_until' => $snoozedUntil])->save();
                    $this->recordActivity($lockedIncident, $actor, 'notifications_snoozed', [
                        'duration_minutes' => $durationMinutes,
                        'snoozed_until' => $snoozedUntil?->toIso8601String(),
                    ]);
                    $changedCount++;

                    continue;
                }

                if ($action === 'resume_notifications') {
                    if ($lockedIncident->state !== Incident::STATE_OPEN || $lockedIncident->snoozed_until === null) {
                        $skippedCount++;

                        continue;
                    }

                    $lockedIncident->forceFill(['snoozed_until' => null])->save();
                    $this->recordActivity($lockedIncident, $actor, 'notifications_resumed', []);
                    $changedCount++;

                    continue;
                }

                if (
                    ! in_array($lockedIncident->state, [Incident::STATE_SUSPECT, Incident::STATE_OPEN], true)
                    || ($lockedIncident->acknowledged_at !== null) === $acknowledge
                ) {
                    $skippedCount++;

                    continue;
                }

                $lockedIncident->forceFill([
                    'acknowledged_at' => $acknowledge ? $acknowledgedAt : null,
                    'acknowledged_by_id' => $acknowledge ? $actor->getKey() : null,
                ])->save();
                $this->recordActivity($lockedIncident, $actor, $eventType, []);
                $changedCount++;
            }

            return ['changed' => $changedCount, 'skipped' => $skippedCount];
        });

        if ($action === 'snooze_notifications') {
            $snoozeTime = $snoozedUntil?->timezone('Asia/Taipei')->format('Y/n/j H:i');
            $message = $result['changed'] > 0
                ? '已暫停 '.$result['changed'].' 件事件的提醒與升級通知至 '.$snoozeTime.'；略過 '.$result['skipped'].' 件尚未成立、已有有效暫停或已恢復／結束的事件。'
                : '所選事件均尚未成立、已有有效暫停或已恢復／結束，沒有變更通知狀態。';
        } elseif ($action === 'resume_notifications') {
            $message = $result['changed'] > 0
                ? '已恢復 '.$result['changed'].' 件事件的提醒與升級通知；略過 '.$result['skipped'].' 件未暫停、尚未成立或已恢復／結束的事件。'
                : '所選事件均未暫停、尚未成立或已恢復／結束，沒有變更通知狀態。';
        } else {
            $actionLabel = $acknowledge ? '認領' : '解除認領';
            $skipReason = $acknowledge ? '已認領、已恢復或已結束' : '尚未認領、已恢復或已結束';
            $message = $result['changed'] > 0
                ? '已'.$actionLabel.' '.$result['changed'].' 件事件；略過 '.$result['skipped'].' 件'.$skipReason.'的事件。'
                : '所選事件均'.$skipReason.'，沒有變更認領狀態。';
        }

        return redirect()->route('incidents.index', $request->query())->with('status', $message);
    }

    public function acknowledge(Request $request, Incident $incident): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $wasAcknowledged = $this->setAcknowledgement($incident, $actor, true);

        return back()->with('status', $wasAcknowledged ? '事件已由你認領。' : '事件目前已有人認領，認領者未變更。');
    }

    public function unacknowledge(Request $request, Incident $incident): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $wasAcknowledged = $this->setAcknowledgement($incident, $actor, false);

        return back()->with('status', $wasAcknowledged ? '事件認領已解除。' : '事件目前沒有待解除的認領。');
    }

    public function snoozeNotifications(Request $request, Incident $incident): RedirectResponse
    {
        $field = 'duration_minutes.'.$incident->id;
        $validated = $request->validate([
            $field => ['required', 'integer', 'in:30,60,240,1440'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $durationMinutes = (int) data_get($validated, $field);
        $snoozedUntil = now()->addMinutes($durationMinutes);

        DB::transaction(function () use ($incident, $actor, $snoozedUntil, $durationMinutes): void {
            $lockedIncident = Incident::query()->lockForUpdate()->findOrFail($incident->id);

            if ($lockedIncident->state !== Incident::STATE_OPEN) {
                throw ValidationException::withMessages(['incident' => '只有已成立且仍進行中的事件可以暫停通知。']);
            }

            $lockedIncident->forceFill(['snoozed_until' => $snoozedUntil])->save();

            IncidentEvent::query()->forceCreate([
                'incident_id' => $lockedIncident->id,
                'check_run_id' => null,
                'event_type' => 'notifications_snoozed',
                'evidence' => [
                    'actor_id' => $actor->getKey(),
                    'actor_name' => $actor->name,
                    'duration_minutes' => $durationMinutes,
                    'snoozed_until' => $snoozedUntil->toIso8601String(),
                ],
                'created_at' => now(),
            ]);
        });

        return back()->with('status', '提醒與升級通知已暫停至 '.$snoozedUntil->timezone('Asia/Taipei')->format('Y/n/j H:i').'。');
    }

    public function resumeNotifications(Request $request, Incident $incident): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $wasSnoozed = DB::transaction(function () use ($incident, $actor): bool {
            $lockedIncident = Incident::query()->lockForUpdate()->findOrFail($incident->id);

            if ($lockedIncident->state !== Incident::STATE_OPEN) {
                throw ValidationException::withMessages(['incident' => '只有已成立且仍進行中的事件可以恢復通知。']);
            }

            if ($lockedIncident->snoozed_until === null) {
                return false;
            }

            $lockedIncident->forceFill(['snoozed_until' => null])->save();

            IncidentEvent::query()->forceCreate([
                'incident_id' => $lockedIncident->id,
                'check_run_id' => null,
                'event_type' => 'notifications_resumed',
                'evidence' => [
                    'actor_id' => $actor->getKey(),
                    'actor_name' => $actor->name,
                ],
                'created_at' => now(),
            ]);

            return true;
        });

        return back()->with('status', $wasSnoozed ? '事件提醒與升級通知已恢復。' : '此事件目前沒有暫停通知。');
    }

    public function update(Request $request, Incident $incident): RedirectResponse
    {
        $validated = $request->validate([
            'classification' => ['nullable', 'in:true_issue,false_alarm,unknown'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $classification = $validated['classification'] ?? null;
        $note = $validated['note'] ?? null;

        DB::transaction(function () use ($incident, $actor, $classification, $note): void {
            $lockedIncident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            $previousClassification = $lockedIncident->classification;
            $previousNote = $lockedIncident->note;

            $lockedIncident->forceFill([
                'classification' => $classification,
                'note' => $note,
            ])->save();

            if ($previousClassification !== $classification) {
                $this->recordActivity($lockedIncident, $actor, 'classification_changed', [
                    'from' => $previousClassification,
                    'to' => $classification,
                ]);
            }

            if ($previousNote !== $note) {
                $this->recordActivity(
                    $lockedIncident,
                    $actor,
                    $note === null || $note === '' ? 'incident_note_cleared' : 'incident_note_added',
                    $note === null || $note === '' ? [] : ['note' => $note],
                );
            }
        });

        return back()->with('status', '事件分類與備註已儲存。');
    }

    public function retryOutbox(Request $request, NotificationOutbox $outbox): RedirectResponse
    {
        $validated = $request->validate(['acknowledge_duplicate_risk' => ['accepted']]);

        if (! in_array($outbox->status, ['failed', 'unknown'], true)) {
            throw ValidationException::withMessages(['notification' => '只有寄送失敗或結果不明的通知才能重試。']);
        }

        $outbox->forceFill(['status' => 'pending', 'attempt_started_at' => null, 'last_error' => null])->save();
        SendNotificationOutbox::dispatch($outbox->id);

        return back()->with('status', '重試已排入佇列。若先前的寄送其實成功，收件者可能會收到重複通知。');
    }

    private function setAcknowledgement(Incident $incident, User $actor, bool $acknowledge): bool
    {
        return DB::transaction(function () use ($incident, $actor, $acknowledge): bool {
            $lockedIncident = Incident::query()->lockForUpdate()->findOrFail($incident->id);

            if (! in_array($lockedIncident->state, [Incident::STATE_SUSPECT, Incident::STATE_OPEN], true)) {
                throw ValidationException::withMessages(['incident' => '只有進行中的事件可以認領或解除認領。']);
            }

            $isAcknowledged = $lockedIncident->acknowledged_at !== null;

            if ($isAcknowledged === $acknowledge) {
                return false;
            }

            $lockedIncident->forceFill([
                'acknowledged_at' => $acknowledge ? now() : null,
                'acknowledged_by_id' => $acknowledge ? $actor->getKey() : null,
            ])->save();

            IncidentEvent::query()->forceCreate([
                'incident_id' => $lockedIncident->id,
                'check_run_id' => null,
                'event_type' => $acknowledge ? 'acknowledged' : 'unacknowledged',
                'evidence' => [
                    'actor_id' => $actor->getKey(),
                    'actor_name' => $actor->name,
                ],
                'created_at' => now(),
            ]);

            return true;
        });
    }

    /** @param array<string, mixed> $evidence */
    private function recordActivity(Incident $incident, User $actor, string $eventType, array $evidence): void
    {
        IncidentEvent::query()->forceCreate([
            'incident_id' => $incident->id,
            'check_run_id' => null,
            'event_type' => $eventType,
            'evidence' => [
                'actor_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                ...$evidence,
            ],
            'created_at' => now(),
        ]);
    }
}
