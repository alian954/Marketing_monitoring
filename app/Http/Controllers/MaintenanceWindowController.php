<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceWindowController extends Controller
{
    public function store(Request $request, Monitor $monitor): RedirectResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $startsAt = CarbonImmutable::parse($validated['starts_at'], 'Asia/Taipei')->utc();
        $endsAt = CarbonImmutable::parse($validated['ends_at'], 'Asia/Taipei')->utc();
        $currentTime = now();

        if ($startsAt->lessThanOrEqualTo($currentTime)) {
            throw ValidationException::withMessages(['starts_at' => '開始時間必須晚於現在。']);
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => '結束時間必須晚於開始時間。']);
        }

        if ($endsAt->greaterThan($startsAt->addDays(14))) {
            throw ValidationException::withMessages(['ends_at' => '單次維護時段最多可排定 14 天。']);
        }

        DB::transaction(function () use ($monitor, $startsAt, $endsAt, $validated): void {
            $lockedMonitor = Monitor::query()->lockForUpdate()->findOrFail($monitor->id);
            $overlapsExistingWindow = MaintenanceWindow::query()
                ->where('monitor_id', $lockedMonitor->id)
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($overlapsExistingWindow) {
                throw ValidationException::withMessages(['starts_at' => '此監測項目已有重疊的維護時段。']);
            }

            MaintenanceWindow::query()->create([
                'monitor_id' => $lockedMonitor->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $validated['reason'],
            ]);
        });

        return redirect()->route('monitors.show', $monitor)->with('status', '維護時段已排定；期間內仍會持續檢查網站。');
    }

    public function destroy(Monitor $monitor, MaintenanceWindow $maintenanceWindow): RedirectResponse
    {
        abort_unless((int) $maintenanceWindow->monitor_id === (int) $monitor->id, 404);

        $maintenanceWindow->delete();

        return redirect()->route('monitors.show', $monitor)->with('status', '維護時段已取消。');
    }
}
