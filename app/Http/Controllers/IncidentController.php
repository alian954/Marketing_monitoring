<?php

namespace App\Http\Controllers;

use App\Jobs\SendNotificationOutbox;
use App\Models\Incident;
use App\Models\NotificationOutbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IncidentController extends Controller
{
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
}
