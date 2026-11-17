<?php

namespace App\Http\Controllers;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Services\IncidentManager;
use App\Services\Probe\ProbeException;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\RunScheduler;
use App\Services\UrlMasker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonitorController extends Controller
{
    /** @var array<int, string> */
    private const INTERVAL_OPTIONS = [
        60 => '每 1 分鐘',
        300 => '每 5 分鐘',
        900 => '每 15 分鐘',
        1800 => '每 30 分鐘',
        3600 => '每 1 小時',
    ];

    public function __construct(
        private readonly UrlSafetyPolicy $urlPolicy,
        private readonly UrlMasker $urlMasker,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $validator = Validator::make($request->query(), [
            'search' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'in:all,enabled,paused'],
        ], [
            'search.max' => '搜尋文字不得超過 120 個字元。',
            'state.in' => '請選擇有效的監測狀態篩選條件。',
        ]);
        if ($validator->fails()) {
            return redirect()->route('monitors.index')->withErrors($validator);
        }

        $validated = $validator->validated();
        $search = trim((string) ($validated['search'] ?? ''));
        $state = $validated['state'] ?? 'all';

        $query = Monitor::query()
            ->with(['latestCheckRun', 'activeIncidents'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $searchTerm = '%'.$search.'%';
                $query->where(function (Builder $query) use ($searchTerm): void {
                    $query->where('name', 'like', $searchTerm)
                        ->orWhere('label', 'like', $searchTerm);
                });
            })
            ->when($state === 'enabled', static fn (Builder $query) => $query->where('enabled', true))
            ->when($state === 'paused', static fn (Builder $query) => $query->where('enabled', false))
            ->orderBy('name');
        $monitors = $query->paginate(50)->withQueryString();

        $monitors->getCollection()->each(function (Monitor $monitor): void {
            $monitor->setAttribute('masked_url', $this->urlMasker->mask($monitor->url));
            $monitor->setAttribute('health_state', $monitor->healthState());
        });

        $filters = ['search' => $search, 'state' => $state];

        return view('monitors.index', compact('monitors', 'filters'));
    }

    public function create(): View
    {
        return view('monitors.form', [
            'monitor' => new Monitor,
            'formAction' => route('monitors.store'),
            'formMethod' => 'POST',
            'intervalOptions' => self::INTERVAL_OPTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateMonitor($request);
        $normalizedUrl = $this->validateProbeUrl($validated['url'], $validated['allowed_redirect_hosts']);
        $fingerprint = Monitor::fingerprintUrl($normalizedUrl);

        if (Monitor::query()->where('url_hash', $fingerprint)->exists()) {
            return back()->withErrors(['url' => '此網址已在監測中。'])->withInput();
        }

        $monitor = Monitor::query()->create([
            'name' => $validated['name'],
            'url' => $normalizedUrl,
            'url_hash' => $fingerprint,
            'label' => $validated['label'] ?: null,
            'enabled' => true,
            'config_version' => 1,
            'allowed_redirect_hosts' => $validated['allowed_redirect_hosts'],
            'accepted_status_codes' => $validated['accepted_status_codes'],
            'expected_text' => $validated['expected_text'] ?: null,
            'expected_text_mode' => $validated['expected_text_mode'],
            'response_time_threshold_ms' => $validated['response_time_threshold_ms'],
            'interval_seconds' => $validated['interval_seconds'],
            'next_check_at' => now(),
        ]);

        return redirect()->route('monitors.show', $monitor)->with('status', '已新增監測項目，排程器下次執行時會進行檢查。');
    }

    public function show(Monitor $monitor): View
    {
        $monitor->setAttribute('masked_url', $this->urlMasker->mask($monitor->url));
        $runs = $monitor->checkRuns()->latest('scheduled_at')->limit(100)->get();
        $incidents = $monitor->incidents()->with(['events', 'outboxMessages'])->latest('last_seen_at')->limit(50)->get();
        $asOf = now();
        $historyStart = $asOf->copy()->subDays(30);
        $historyRuns = $monitor->checkRuns()
            ->where('source', 'scheduled')
            ->where('status', 'completed')
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $historyStart)
            ->orderBy('finished_at')
            ->get(['id', 'config_snapshot', 'finished_at', 'normal_status', 'error_type', 'normal_elapsed_ms']);

        $availabilityStats = collect([
            ['label' => '24 小時', 'starts_at' => $asOf->copy()->subDay()],
            ['label' => '7 天', 'starts_at' => $asOf->copy()->subDays(7)],
            ['label' => '30 天', 'starts_at' => $historyStart],
        ])->map(function (array $period) use ($historyRuns, $monitor): array {
            $periodRuns = $historyRuns->filter(
                fn (CheckRun $run): bool => $run->finished_at->greaterThanOrEqualTo($period['starts_at']),
            );
            $completedChecks = $periodRuns->count();
            $successfulChecks = $periodRuns->filter(static function (CheckRun $run): bool {
                $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
                $acceptedStatusCodes = array_map('intval', $snapshot['accepted_status_codes'] ?? []);
                $statusCode = $run->normal_status;
                $isSuccessfulStatus = $statusCode === 429
                    || ($statusCode !== null && (($statusCode >= 200 && $statusCode < 400) || in_array($statusCode, $acceptedStatusCodes, true)));

                return $run->error_type === null && $isSuccessfulStatus;
            })->count();

            return [
                'label' => $period['label'],
                'availability' => $completedChecks === 0 ? null : round(($successfulChecks / $completedChecks) * 100, 2),
                'successful_checks' => $successfulChecks,
                'completed_checks' => $completedChecks,
            ];
        })->all();

        $responseTimeStart = $asOf->copy()->subDay();
        $responseTimeRuns = $historyRuns->filter(
            fn (CheckRun $run): bool => $run->finished_at->greaterThanOrEqualTo($responseTimeStart)
                && $run->normal_elapsed_ms !== null,
        )->values();
        $maximumResponseTimeMs = (int) ($responseTimeRuns->max('normal_elapsed_ms') ?? 0);
        $responseTimeScaleMaxMs = max(100, (int) (ceil($maximumResponseTimeMs / 100) * 100));
        $responseTimePoints = $responseTimeRuns->map(function (CheckRun $run) use ($responseTimeStart, $responseTimeScaleMaxMs): array {
            $elapsedSeconds = max(0, $run->finished_at->getTimestamp() - $responseTimeStart->getTimestamp());
            $x = 48 + (min(1, $elapsedSeconds / 86400) * 522);
            $y = 144 - (($run->normal_elapsed_ms / $responseTimeScaleMaxMs) * 116);

            return [
                'x' => number_format($x, 2, '.', ''),
                'y' => number_format($y, 2, '.', ''),
                'coordinate' => number_format($x, 2, '.', '').','.number_format($y, 2, '.', ''),
                'finished_at' => $run->finished_at,
                'normal_status' => $run->normal_status,
                'elapsed_ms' => $run->normal_elapsed_ms,
                'error_type' => $run->error_type,
            ];
        });
        $responseTimeSegments = [];
        $currentSegment = [];
        $previousFinishedAt = null;
        $maximumGapSeconds = max(60, $monitor->interval_seconds * 2);

        foreach ($responseTimePoints as $point) {
            $finishedAtTimestamp = $point['finished_at']->getTimestamp();

            if ($previousFinishedAt !== null && ($finishedAtTimestamp - $previousFinishedAt) > $maximumGapSeconds) {
                if (count($currentSegment) > 1) {
                    $responseTimeSegments[] = implode(' ', $currentSegment);
                }

                $currentSegment = [];
            }

            $currentSegment[] = $point['coordinate'];
            $previousFinishedAt = $finishedAtTimestamp;
        }

        if (count($currentSegment) > 1) {
            $responseTimeSegments[] = implode(' ', $currentSegment);
        }

        $responseTime = [
            'count' => $responseTimeRuns->count(),
            'average_ms' => $responseTimeRuns->avg('normal_elapsed_ms'),
            'minimum_ms' => $responseTimeRuns->min('normal_elapsed_ms'),
            'maximum_ms' => $responseTimeRuns->max('normal_elapsed_ms'),
            'scale_max_ms' => $responseTimeScaleMaxMs,
            'points' => $responseTimePoints->all(),
            'segments' => $responseTimeSegments,
        ];

        return view('monitors.show', compact('monitor', 'runs', 'incidents', 'availabilityStats', 'responseTime'));
    }

    public function edit(Monitor $monitor): View
    {
        $monitor->setAttribute('masked_url', $this->urlMasker->mask($monitor->url));

        return view('monitors.form', [
            'monitor' => $monitor,
            'formAction' => route('monitors.update', $monitor),
            'formMethod' => 'PUT',
            'intervalOptions' => self::INTERVAL_OPTIONS,
        ]);
    }

    public function update(Request $request, Monitor $monitor): RedirectResponse
    {
        $validated = $this->validateMonitor($request, $monitor);
        $inputUrl = trim((string) ($validated['url'] ?? ''));
        $normalizedUrl = $inputUrl === '' ? $monitor->url : $this->validateProbeUrl($inputUrl, $validated['allowed_redirect_hosts']);
        $fingerprint = Monitor::fingerprintUrl($normalizedUrl);

        if (Monitor::query()->where('url_hash', $fingerprint)->whereKeyNot($monitor->id)->exists()) {
            return back()->withErrors(['url' => '此網址已在監測中。'])->withInput();
        }

        $monitor = DB::transaction(function () use ($monitor, $validated, $normalizedUrl, $fingerprint): Monitor {
            $lockedMonitor = Monitor::query()->lockForUpdate()->findOrFail($monitor->id);
            $previousVersion = $lockedMonitor->config_version;
            $nextVersion = $previousVersion + 1;

            $lockedMonitor->forceFill([
                'name' => $validated['name'],
                'url' => $normalizedUrl,
                'url_hash' => $fingerprint,
                'label' => $validated['label'] ?: null,
                'accepted_status_codes' => $validated['accepted_status_codes'],
                'expected_text' => $validated['expected_text'] ?: null,
                'expected_text_mode' => $validated['expected_text_mode'],
                'response_time_threshold_ms' => $validated['response_time_threshold_ms'],
                'interval_seconds' => $validated['interval_seconds'],
                'allowed_redirect_hosts' => $validated['allowed_redirect_hosts'],
                'config_version' => $nextVersion,
                'next_check_at' => $lockedMonitor->enabled ? now() : null,
            ])->save();

            CheckRun::query()
                ->where('monitor_id', $lockedMonitor->id)
                ->where('config_version', $previousVersion)
                ->where('status', 'queued')
                ->update(['status' => 'stale_config', 'finished_at' => now(), 'updated_at' => now()]);

            $activeIncidents = Incident::query()
                ->where('monitor_id', $lockedMonitor->id)
                ->where('config_version', $previousVersion)
                ->whereIn('state', [Incident::STATE_SUSPECT, Incident::STATE_OPEN])
                ->lockForUpdate()
                ->get();

            foreach ($activeIncidents as $incident) {
                $incident->forceFill([
                    'state' => Incident::STATE_SUPERSEDED,
                    'active_key' => null,
                    'confirm_after' => null,
                ])->save();

                IncidentEvent::query()->forceCreate([
                    'incident_id' => $incident->id,
                    'check_run_id' => null,
                    'event_type' => 'superseded',
                    'evidence' => ['previous_config_version' => $previousVersion, 'new_config_version' => $nextVersion],
                    'created_at' => now(),
                ]);
            }

            return $lockedMonitor;
        });

        return redirect()->route('monitors.show', $monitor)->with('status', '監測設定已儲存，設定版本：'.$monitor->config_version.'。');
    }

    public function toggle(Monitor $monitor, IncidentManager $incidents): RedirectResponse
    {
        if ($monitor->enabled) {
            $monitor->forceFill(['enabled' => false, 'next_check_at' => null])->save();
            CheckRun::query()->where('monitor_id', $monitor->id)->where('status', 'queued')->update(['status' => 'paused', 'finished_at' => now(), 'updated_at' => now()]);
            $incidents->pauseMonitor($monitor);

            return back()->with('status', '監測已暫停；進行中的事件已標示為暫停，並未標示為恢復。');
        }

        $monitor->forceFill(['enabled' => true, 'next_check_at' => now()])->save();

        return back()->with('status', '監測已恢復，排程器下次執行時會進行檢查。');
    }

    public function checkNow(Monitor $monitor, RunScheduler $scheduler): RedirectResponse
    {
        $cacheKey = 'manual-check:'.$monitor->id;

        if (! Cache::add($cacheKey, true, now()->addMinutes(5))) {
            return back()->withErrors(['check' => '剛才已提出手動檢查，請等待五分鐘後再試。']);
        }

        if (! $monitor->enabled) {
            Cache::forget($cacheKey);

            return back()->withErrors(['check' => '請先恢復監測，再提出手動檢查。']);
        }

        $run = $scheduler->enqueue($monitor, 'manual');

        if ($run === null) {
            Cache::forget($cacheKey);

            return back()->withErrors(['check' => '此監測項目已有檢查排入佇列或正在執行。']);
        }

        return back()->with('status', '手動檢查已排入佇列，並會套用與排程檢查相同的安全及確認規則。');
    }

    public function destroy(Monitor $monitor): RedirectResponse
    {
        $pageWasUnpublished = DB::transaction(function () use ($monitor): bool {
            $lockedMonitor = Monitor::query()->lockForUpdate()->findOrFail($monitor->id);
            $wasPublic = $lockedMonitor->public_status;
            $lockedMonitor->delete();

            if (! $wasPublic || Monitor::query()->where('public_status', true)->exists()) {
                return false;
            }

            $statusPage = StatusPage::query()
                ->where('setting_key', 'default')
                ->lockForUpdate()
                ->first();

            if (! $statusPage?->is_published) {
                return false;
            }

            $statusPage->forceFill(['is_published' => false])->save();

            return true;
        });

        $statusMessage = $pageWasUnpublished
            ? '監測項目及其相關記錄已刪除；最後一個公開項目已移除，狀態頁也已取消發布。'
            : '監測項目及其相關記錄已刪除。';

        return redirect()->route('monitors.index')->with('status', $statusMessage);
    }

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['name', 'url', 'label', 'enabled', 'last_checked_at_utc', 'http_status', 'error_type']);

            Monitor::query()->with('latestCheckRun')->orderBy('id')->lazyById()->each(function (Monitor $monitor) use ($stream): void {
                $run = $monitor->latestCheckRun;
                $fields = [
                    $monitor->name,
                    $this->urlMasker->mask($monitor->url),
                    (string) $monitor->label,
                    $monitor->enabled ? '1' : '0',
                    $monitor->last_checked_at?->utc()->toIso8601String() ?? '',
                    $run?->normal_status === null ? '' : (string) $run->normal_status,
                    (string) $run?->error_type,
                ];

                fputcsv($stream, array_map($this->escapeCsvFormula(...), $fields), ',', '"', '');
            });

            fclose($stream);
        }, 'monitors.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{name: string, url: string, label: ?string, accepted_status_codes: list<int>, expected_text: ?string, expected_text_mode: string, response_time_threshold_ms: ?int, interval_seconds: int, allowed_redirect_hosts: list<string>} */
    private function validateMonitor(Request $request, ?Monitor $monitor = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => [$monitor === null ? 'required' : 'nullable', 'string', 'max:2048'],
            'label' => ['nullable', 'string', 'max:100'],
            'accepted_status_codes' => ['nullable', 'string', 'max:1000'],
            'expected_text' => ['nullable', 'string', 'max:500'],
            'expected_text_mode' => ['sometimes', 'in:present,absent'],
            'response_time_threshold_ms' => ['nullable', 'integer', 'min:100', 'max:9000'],
            'interval_seconds' => ['sometimes', 'integer', 'in:'.implode(',', array_keys(self::INTERVAL_OPTIONS))],
            'redirect_hosts' => ['nullable', 'string', 'max:2000'],
        ], [
            'response_time_threshold_ms.integer' => '回應時間門檻必須是整數。',
            'response_time_threshold_ms.min' => '回應時間門檻不得低於 100 毫秒。',
            'response_time_threshold_ms.max' => '回應時間門檻不得高於 9000 毫秒。',
            'expected_text_mode.in' => '文字條件設定無效，請重新選擇。',
            'accepted_status_codes.string' => '額外接受狀態碼格式不正確。',
            'accepted_status_codes.max' => '額外接受狀態碼欄位過長。',
            'interval_seconds.integer' => '檢查頻率格式不正確。',
            'interval_seconds.in' => '請選擇支援的檢查頻率。',
        ]);
        $allowedHosts = [];

        foreach (preg_split('/[\r\n,]+/', (string) ($validated['redirect_hosts'] ?? ''), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $host) {
            try {
                $allowedHosts[] = $this->urlPolicy->normalizeHost($host);
            } catch (ProbeException $exception) {
                throw ValidationException::withMessages(['redirect_hosts' => $exception->getMessage()]);
            }
        }

        if (array_key_exists('accepted_status_codes', $validated)) {
            $acceptedStatusCodes = $this->normalizeAcceptedStatusCodes($validated['accepted_status_codes']);
        } else {
            $acceptedStatusCodes = $monitor?->accepted_status_codes ?? [];
        }

        return [
            'name' => $validated['name'],
            'url' => isset($validated['url']) ? trim($validated['url']) : '',
            'label' => $validated['label'] ?? null,
            'accepted_status_codes' => $acceptedStatusCodes,
            'expected_text' => $validated['expected_text'] ?? null,
            'expected_text_mode' => $validated['expected_text_mode'] ?? $monitor?->expected_text_mode ?? 'present',
            'response_time_threshold_ms' => array_key_exists('response_time_threshold_ms', $validated)
                ? ($validated['response_time_threshold_ms'] === null ? null : (int) $validated['response_time_threshold_ms'])
                : ($monitor?->response_time_threshold_ms),
            'interval_seconds' => array_key_exists('interval_seconds', $validated)
                ? (int) $validated['interval_seconds']
                : ($monitor?->interval_seconds ?? (int) config('monitoring.interval_seconds')),
            'allowed_redirect_hosts' => array_values(array_unique($allowedHosts)),
        ];
    }

    /** @return list<int> */
    private function normalizeAcceptedStatusCodes(?string $input): array
    {
        $codes = preg_split('/[\s,]+/', trim((string) $input), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $normalizedCodes = [];

        foreach ($codes as $code) {
            if (preg_match('/\A\d{3}\z/D', $code) !== 1 || (int) $code < 400 || (int) $code > 599) {
                throw ValidationException::withMessages([
                    'accepted_status_codes' => '額外接受狀態碼只能是 400 至 599 的三位數 HTTP 狀態碼。',
                ]);
            }

            $normalizedCodes[] = (int) $code;
        }

        $normalizedCodes = array_values(array_unique($normalizedCodes));
        sort($normalizedCodes, SORT_NUMERIC);

        return $normalizedCodes;
    }

    private function validateProbeUrl(string $url, array $allowedHosts): string
    {
        if (config('monitoring.transport') === 'fixture') {
            $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

            if (! in_array($host, ['fixture.test', 'healthy.fixture.test'], true) || ! in_array(mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw ValidationException::withMessages(['url' => '離線測試模式只接受 fixture.test 網址，不會連接網路。']);
            }

            return $url;
        }

        try {
            return $this->urlPolicy->validateAndResolve($url, $allowedHosts)['url'];
        } catch (ProbeException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }
    }

    private function escapeCsvFormula(string $value): string
    {
        return preg_match('/\A[\t\r ]*[=+@-]/u', $value) === 1 ? "'".$value : $value;
    }
}
