<?php

namespace App\Http\Controllers;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Services\MonitorAvailabilityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StatusPageController extends Controller
{
    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    private const HISTORY_DAYS = 30;

    private const MAINTENANCE_LOOKAHEAD_DAYS = 30;

    public function __construct(private readonly MonitorAvailabilityCalculator $availabilityCalculator) {}

    public function edit(): View
    {
        $statusPage = StatusPage::query()->where('setting_key', 'default')->first();
        $monitors = Monitor::query()
            ->with(['latestCheckRun', 'activeIncidents'])
            ->orderBy('name')
            ->get();

        foreach ($monitors as $monitor) {
            $monitor->setAttribute('health_state', $monitor->healthState());
        }

        $publicMonitorCount = $monitors->where('public_status', true)->count();

        $publicUrl = $statusPage?->is_published
            ? route('status-pages.public', ['token' => $statusPage->public_token])
            : null;

        return view('status-pages.manage', compact('statusPage', 'monitors', 'publicMonitorCount', 'publicUrl'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'is_published' => ['required', 'boolean'],
            'rotate_public_link' => ['nullable', 'boolean'],
        ]);
        $shouldPublish = (bool) $validated['is_published'];

        if ($shouldPublish && ! Monitor::query()->where('public_status', true)->exists()) {
            throw ValidationException::withMessages([
                'is_published' => '請先在至少一個監測項目的設定中，勾選「顯示於公開狀態頁」。',
            ]);
        }

        DB::transaction(function () use ($validated, $shouldPublish): void {
            $token = Str::random(64);
            $statusPage = StatusPage::query()->firstOrCreate(
                ['setting_key' => 'default'],
                [
                    'title' => $validated['title'],
                    'public_token' => $token,
                    'public_token_hash' => hash('sha256', $token),
                    'is_published' => false,
                ],
            );
            $statusPage = StatusPage::query()->whereKey($statusPage->id)->lockForUpdate()->firstOrFail();

            $values = [
                'title' => $validated['title'],
                'is_published' => $shouldPublish,
            ];

            if (($validated['rotate_public_link'] ?? false) === true || ($validated['rotate_public_link'] ?? null) === '1') {
                $nextToken = Str::random(64);
                $values['public_token'] = $nextToken;
                $values['public_token_hash'] = hash('sha256', $nextToken);
            }

            $statusPage->forceFill($values)->save();
        });

        return redirect()->route('status-pages.edit')->with('status', '狀態頁設定已儲存。');
    }

    public function toggleMonitor(Monitor $monitor): RedirectResponse
    {
        [$isPublic, $pageWasUnpublished] = DB::transaction(function () use ($monitor): array {
            $lockedMonitor = Monitor::query()->lockForUpdate()->findOrFail($monitor->id);
            $isPublic = ! $lockedMonitor->public_status;
            $lockedMonitor->forceFill(['public_status' => $isPublic])->save();
            $pageWasUnpublished = false;

            if (! $isPublic && ! Monitor::query()->where('public_status', true)->exists()) {
                $statusPage = StatusPage::query()->where('setting_key', 'default')->lockForUpdate()->first();

                if ($statusPage?->is_published) {
                    $statusPage->forceFill(['is_published' => false])->save();
                    $pageWasUnpublished = true;
                }
            }

            return [$isPublic, $pageWasUnpublished];
        });

        $message = $isPublic
            ? '監測項目已加入公開狀態頁。'
            : ($pageWasUnpublished ? '最後一個公開項目已移除，狀態頁也已取消發布。' : '監測項目已從公開狀態頁移除。');

        return redirect()->route('status-pages.edit')->with('status', $message);
    }

    public function show(string $token): Response
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);

        $statusPage = StatusPage::query()
            ->where('public_token_hash', hash('sha256', $token))
            ->where('is_published', true)
            ->firstOrFail();
        $monitors = Monitor::query()
            ->where('public_status', true)
            ->with(['latestCheckRun', 'activeIncidents'])
            ->orderBy('name')
            ->get();

        abort_if($monitors->isEmpty(), 404);

        foreach ($monitors as $monitor) {
            $monitor->setAttribute('health_state', $monitor->healthState());
        }

        $asOf = now()->toImmutable();
        $this->attachPublicHistory($monitors, $asOf);
        [$activePublicIncidents, $recentResolvedPublicIncidents] = $this->publicIncidents($monitors);
        $publicMaintenanceWindows = $this->publicMaintenanceWindows($monitors, $asOf);

        $pageState = $monitors->contains(fn (Monitor $monitor): bool => $monitor->getAttribute('health_state') === 'issue')
            ? 'issue'
            : ($monitors->every(fn (Monitor $monitor): bool => $monitor->getAttribute('health_state') === 'healthy') ? 'healthy' : 'warning');
        $lastCheckedAt = $monitors->pluck('last_checked_at')->filter()->max();

        return response()
            ->view('status-pages.public', compact(
                'statusPage',
                'monitors',
                'pageState',
                'lastCheckedAt',
                'activePublicIncidents',
                'recentResolvedPublicIncidents',
                'publicMaintenanceWindows',
            ))
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * @param  EloquentCollection<int, Monitor>  $monitors
     * @return Collection<int, array{monitor_name: string, state: 'active'|'upcoming', starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    private function publicMaintenanceWindows(EloquentCollection $monitors, CarbonImmutable $asOf): Collection
    {
        $monitorNames = $monitors->mapWithKeys(
            static fn (Monitor $monitor): array => [(int) $monitor->id => $monitor->name],
        );
        $localDate = $asOf->timezone(self::HISTORY_TIMEZONE)->toDateString();
        $lookaheadEndsAt = $asOf->addDays(self::MAINTENANCE_LOOKAHEAD_DAYS);
        $maintenanceWindows = MaintenanceWindow::query()
            ->whereIn('monitor_id', $monitors->modelKeys())
            ->where(function (Builder $query) use ($asOf, $localDate): void {
                $query->where(function (Builder $query) use ($asOf): void {
                    $query->where('repeat_weekly', false)->where('ends_at', '>', $asOf);
                })->orWhere(function (Builder $query) use ($localDate): void {
                    $query->where('repeat_weekly', true)->whereDate('repeat_until', '>=', $localDate);
                });
            })
            ->get();
        $publicOccurrences = collect();

        foreach ($maintenanceWindows as $maintenanceWindow) {
            foreach ($maintenanceWindow->occurrenceIntervals() as $occurrence) {
                if (
                    $occurrence['ends_at']->lessThanOrEqualTo($asOf)
                    || $occurrence['starts_at']->greaterThan($lookaheadEndsAt)
                ) {
                    continue;
                }

                $publicOccurrences->push([
                    'monitor_name' => $monitorNames->get((int) $maintenanceWindow->monitor_id, '公開服務'),
                    'state' => $occurrence['starts_at']->lessThanOrEqualTo($asOf) ? 'active' : 'upcoming',
                    'starts_at' => $occurrence['starts_at'],
                    'ends_at' => $occurrence['ends_at'],
                ]);
            }
        }

        return $publicOccurrences
            ->sortBy(static fn (array $occurrence): int => $occurrence['starts_at']->getTimestamp())
            ->values();
    }

    /**
     * @param  EloquentCollection<int, Monitor>  $monitors
     * @return array{0: Collection<int, array{monitor_name: string, severity_state: string, opened_at: ?CarbonImmutable, last_seen_at: ?CarbonImmutable, resolved_at: ?CarbonImmutable}>, 1: Collection<int, array{monitor_name: string, severity_state: string, opened_at: ?CarbonImmutable, last_seen_at: ?CarbonImmutable, resolved_at: ?CarbonImmutable}>}
     */
    private function publicIncidents(EloquentCollection $monitors): array
    {
        $activeIncidents = $monitors
            ->flatMap(function (Monitor $monitor): array {
                return $monitor->activeIncidents
                    ->filter(fn (Incident $incident): bool => $incident->state === Incident::STATE_OPEN
                        && $incident->config_version === $monitor->config_version)
                    ->map(fn (Incident $incident): array => $this->publicIncidentSummary($incident, $monitor->name))
                    ->all();
            })
            ->sortByDesc(fn (array $incident): int => $incident['opened_at']?->getTimestamp() ?? 0)
            ->values();

        $recentResolvedIncidents = Incident::query()
            ->with('monitor:id,name')
            ->whereIn('monitor_id', $monitors->modelKeys())
            ->where('state', Incident::STATE_RESOLVED)
            ->whereNotNull('opened_at')
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', now()->subDays(self::HISTORY_DAYS)->utc())
            ->where('resolved_at', '<=', now()->utc())
            ->where(function (Builder $query): void {
                $query->whereNull('classification')
                    ->orWhere('classification', '!=', 'false_alarm');
            })
            ->orderByDesc('resolved_at')
            ->limit(20)
            ->get(['id', 'monitor_id', 'severity', 'opened_at', 'last_seen_at', 'resolved_at'])
            ->map(fn (Incident $incident): array => $this->publicIncidentSummary($incident, $incident->monitor->name));

        return [$activeIncidents, $recentResolvedIncidents];
    }

    /**
     * @return array{monitor_name: string, severity_state: string, opened_at: ?CarbonImmutable, last_seen_at: ?CarbonImmutable, resolved_at: ?CarbonImmutable}
     */
    private function publicIncidentSummary(Incident $incident, string $monitorName): array
    {
        return [
            'monitor_name' => $monitorName,
            'severity_state' => $incident->severity === 'warning' ? 'warning' : 'issue',
            'opened_at' => $incident->opened_at,
            'last_seen_at' => $incident->last_seen_at,
            'resolved_at' => $incident->resolved_at,
        ];
    }

    /** @param EloquentCollection<int, Monitor> $monitors */
    private function attachPublicHistory(EloquentCollection $monitors, CarbonImmutable $asOf): void
    {
        $today = $asOf->timezone(self::HISTORY_TIMEZONE)->startOfDay();
        $firstDay = $today->copy()->subDays(self::HISTORY_DAYS - 1);
        $availabilityStartsAt = $asOf->copy()->subDays(self::HISTORY_DAYS);
        $availabilityQueryStartsAt = $availabilityStartsAt->copy()->subSeconds(
            Monitor::MAX_INTERVAL_SECONDS + max(0, (int) config('monitoring.stale_grace_seconds')),
        );
        $monitorIds = $monitors->modelKeys();
        $historyDays = [];

        for ($dayOffset = 0; $dayOffset < self::HISTORY_DAYS; $dayOffset++) {
            $day = $firstDay->copy()->addDays($dayOffset);
            $historyDays[] = ['date' => $day->toDateString(), 'display_date' => $day->format('n/j')];
        }

        /** @var array<int, array<string, array{total_checks: int, successful_checks: int}>> $dailyHistory */
        $dailyHistory = [];

        foreach ($monitors as $monitor) {
            $dailyHistory[$monitor->id] = [];

            foreach ($historyDays as $day) {
                $dailyHistory[$monitor->id][$day['date']] = ['total_checks' => 0, 'successful_checks' => 0];
            }
        }

        CheckRun::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('source', 'scheduled')
            ->whereIn('status', ['completed', 'failed'])
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $firstDay->copy()->utc())
            ->where('finished_at', '<=', $asOf->utc())
            ->select(['id', 'monitor_id', 'config_snapshot', 'status', 'finished_at', 'normal_status', 'error_type', 'response_body_bytes', 'expected_text_found', 'expected_response_header_found'])
            ->chunkById(500, static function (EloquentCollection $runs) use (&$dailyHistory): void {
                foreach ($runs as $run) {
                    $day = $run->finished_at->timezone(self::HISTORY_TIMEZONE)->toDateString();

                    if (! isset($dailyHistory[$run->monitor_id][$day])) {
                        continue;
                    }

                    $dailyHistory[$run->monitor_id][$day]['total_checks']++;

                    if ($run->passesMonitorChecks()) {
                        $dailyHistory[$run->monitor_id][$day]['successful_checks']++;
                    }
                }
            });

        $maintenanceLookupDate = $availabilityStartsAt->copy()->timezone(self::HISTORY_TIMEZONE)->subDays(7)->toDateString();
        $maintenanceWindows = MaintenanceWindow::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where(function (Builder $query) use ($availabilityStartsAt, $maintenanceLookupDate): void {
                $query->where(function (Builder $query) use ($availabilityStartsAt): void {
                    $query->where('repeat_weekly', false)->where('ends_at', '>', $availabilityStartsAt);
                })->orWhere(function (Builder $query) use ($maintenanceLookupDate): void {
                    $query->where('repeat_weekly', true)->whereDate('repeat_until', '>=', $maintenanceLookupDate);
                });
            })
            ->get();
        $availabilityByMonitorId = $this->availabilityCalculator->calculateForMonitors(
            $monitors,
            $maintenanceWindows,
            CheckRun::query()
                ->whereIn('monitor_id', $monitorIds)
                ->where('source', 'scheduled')
                ->whereIn('status', ['completed', 'failed'])
                ->whereNotNull('finished_at')
                ->where('finished_at', '>=', $availabilityQueryStartsAt)
                ->where('finished_at', '<=', $asOf->utc())
                ->orderBy('monitor_id')
                ->orderBy('finished_at')
                ->orderBy('id')
                ->select(['id', 'monitor_id', 'config_snapshot', 'status', 'finished_at', 'normal_status', 'error_type', 'response_body_bytes', 'expected_text_found', 'expected_response_header_found'])
                ->lazy(500),
            (int) config('monitoring.stale_grace_seconds'),
            $asOf,
        );

        foreach ($monitors as $monitor) {
            $dailyChecks = [];
            $totalChecks = 0;
            $successfulChecks = 0;

            foreach ($historyDays as $day) {
                $counts = $dailyHistory[$monitor->id][$day['date']];
                $dayTotal = $counts['total_checks'];
                $daySuccessful = $counts['successful_checks'];
                $daySuccessRate = $dayTotal === 0 ? null : round(($daySuccessful / $dayTotal) * 100, 2);
                $state = $daySuccessRate === null
                    ? 'unknown'
                    : ($daySuccessRate >= 100 ? 'healthy' : ($daySuccessful === 0 ? 'issue' : 'warning'));
                $accessibleLabel = $daySuccessRate === null
                    ? $day['date'].' 台灣時間：沒有已結束的排程檢查。'
                    : $day['date'].' 台灣時間：成功 '.$daySuccessful.'／'.$dayTotal.' 次，成功率 '.number_format($daySuccessRate, 2).'%。';

                $dailyChecks[] = [
                    ...$day,
                    ...$counts,
                    'success_rate' => $daySuccessRate,
                    'state' => $state,
                    'accessible_label' => $accessibleLabel,
                ];
                $totalChecks += $dayTotal;
                $successfulChecks += $daySuccessful;
            }

            $monitor->setAttribute('public_history', [
                'success_rate' => $totalChecks === 0 ? null : round(($successfulChecks / $totalChecks) * 100, 2),
                'successful_checks' => $successfulChecks,
                'total_checks' => $totalChecks,
                'availability' => collect($availabilityByMonitorId[$monitor->id] ?? [])->firstWhere('label', '30 天'),
                'days' => $dailyChecks,
            ]);
        }
    }
}
