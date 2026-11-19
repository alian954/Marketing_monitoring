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

    public function update(Request $request, Incident $incident): RedirectResponse
    {
        $validated = $request->validate([
            'classification' => ['nullable', 'in:true_issue,false_alarm,unknown'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $incident->forceFill([
            'classification' => $validated['classification'] ?? null,
            'note' => $validated['note'] ?? null,
        ])->save();

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
}
