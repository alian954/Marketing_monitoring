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
            'repeat_weekly' => ['sometimes', 'boolean'],
            'repeat_until' => ['exclude_unless:repeat_weekly,1', 'required', 'date_format:Y-m-d'],
        ]);

        $startsAt = CarbonImmutable::parse($validated['starts_at'], 'Asia/Taipei')->utc();
        $endsAt = CarbonImmutable::parse($validated['ends_at'], 'Asia/Taipei')->utc();
        $currentTime = now();
        $repeatWeekly = (bool) ($validated['repeat_weekly'] ?? false);
        $repeatUntil = null;

        if ($startsAt->lessThanOrEqualTo($currentTime)) {
            throw ValidationException::withMessages(['starts_at' => '開始時間必須晚於現在。']);
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => '結束時間必須晚於開始時間。']);
        }

        if ($repeatWeekly && $endsAt->greaterThan($startsAt->addDays(7))) {
            throw ValidationException::withMessages(['ends_at' => '每週重複的單次維護不得超過 7 天。']);
        }

        if (! $repeatWeekly && $endsAt->greaterThan($startsAt->addDays(14))) {
            throw ValidationException::withMessages(['ends_at' => '單次維護時段最多可排定 14 天。']);
        }

        if ($repeatWeekly) {
            $startsAtLocal = $startsAt->timezone('Asia/Taipei');
            $repeatUntilLocal = CarbonImmutable::parse($validated['repeat_until'], 'Asia/Taipei')->startOfDay();

            if ($repeatUntilLocal->lessThan($startsAtLocal->startOfDay())) {
                throw ValidationException::withMessages(['repeat_until' => '最後重複日期不得早於維護開始日期。']);
            }

            if ($repeatUntilLocal->greaterThan($startsAtLocal->startOfDay()->addYear())) {
                throw ValidationException::withMessages(['repeat_until' => '每週重複維護最多可排定一年。']);
            }

            $repeatUntil = $repeatUntilLocal->toDateString();
        }

        DB::transaction(function () use ($monitor, $startsAt, $endsAt, $repeatWeekly, $repeatUntil, $validated): void {
            $lockedMonitor = Monitor::query()->lockForUpdate()->findOrFail($monitor->id);
            $candidate = new MaintenanceWindow;
            $candidate->forceFill([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'repeat_weekly' => $repeatWeekly,
                'repeat_until' => $repeatUntil,
            ]);
            $overlapsExistingWindow = $lockedMonitor->maintenanceWindows()
                ->get()
                ->contains(fn (MaintenanceWindow $window): bool => $candidate->overlaps($window));

            if ($overlapsExistingWindow) {
                throw ValidationException::withMessages(['starts_at' => '此監測項目已有重疊的維護時段。']);
            }

            MaintenanceWindow::query()->create([
                'monitor_id' => $lockedMonitor->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $validated['reason'],
                'repeat_weekly' => $repeatWeekly,
                'repeat_until' => $repeatUntil,
            ]);
        });

        $statusMessage = $repeatWeekly
            ? '每週重複的維護時段已排定；期間內仍會持續檢查網站。'
            : '維護時段已排定；期間內仍會持續檢查網站。';

        return redirect()->route('monitors.show', $monitor)->with('status', $statusMessage);
    }

    public function destroy(Monitor $monitor, MaintenanceWindow $maintenanceWindow): RedirectResponse
    {
        abort_unless((int) $maintenanceWindow->monitor_id === (int) $monitor->id, 404);

        $maintenanceWindow->delete();

        return redirect()->route('monitors.show', $monitor)->with('status', '維護時段已取消。');
    }
}
