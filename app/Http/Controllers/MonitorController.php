<?php

namespace App\Http\Controllers;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Services\IncidentManager;
use App\Services\Probe\ProbeException;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\RunScheduler;
use App\Services\UrlMasker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonitorController extends Controller
{
    public function __construct(
        private readonly UrlSafetyPolicy $urlPolicy,
        private readonly UrlMasker $urlMasker,
    ) {}

    public function index(): View
    {
        $monitors = Monitor::query()
            ->with(['latestCheckRun', 'activeIncidents'])
            ->orderBy('name')
            ->paginate(50);

        $monitors->getCollection()->each(function (Monitor $monitor): void {
            $monitor->setAttribute('masked_url', $this->urlMasker->mask($monitor->url));
            $monitor->setAttribute('health_state', $monitor->healthState());
        });

        return view('monitors.index', compact('monitors'));
    }

    public function create(): View
    {
        return view('monitors.form', ['monitor' => new Monitor, 'formAction' => route('monitors.store'), 'formMethod' => 'POST']);
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
            'expected_text' => $validated['expected_text'] ?: null,
            'interval_seconds' => (int) config('monitoring.interval_seconds'),
            'next_check_at' => now(),
        ]);

        return redirect()->route('monitors.show', $monitor)->with('status', '已新增監測項目，排程器下次執行時會進行檢查。');
    }

    public function show(Monitor $monitor): View
    {
        $monitor->setAttribute('masked_url', $this->urlMasker->mask($monitor->url));
        $runs = $monitor->checkRuns()->latest('scheduled_at')->limit(100)->get();
        $incidents = $monitor->incidents()->with(['events', 'outboxMessages'])->latest('last_seen_at')->limit(50)->get();

        return view('monitors.show', compact('monitor', 'runs', 'incidents'));
    }

    public function edit(Monitor $monitor): View
    {
        $monitor->setAttribute('masked_url', $this->urlMasker->mask($monitor->url));

        return view('monitors.form', [
            'monitor' => $monitor,
            'formAction' => route('monitors.update', $monitor),
            'formMethod' => 'PUT',
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
                'expected_text' => $validated['expected_text'] ?: null,
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
        $monitor->delete();

        return redirect()->route('monitors.index')->with('status', '監測項目及其相關記錄已刪除。');
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

    /** @return array{name: string, url: string, label: ?string, expected_text: ?string, allowed_redirect_hosts: list<string>} */
    private function validateMonitor(Request $request, ?Monitor $monitor = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => [$monitor === null ? 'required' : 'nullable', 'string', 'max:2048'],
            'label' => ['nullable', 'string', 'max:100'],
            'expected_text' => ['nullable', 'string', 'max:500'],
            'redirect_hosts' => ['nullable', 'string', 'max:2000'],
        ]);
        $allowedHosts = [];

        foreach (preg_split('/[\r\n,]+/', (string) ($validated['redirect_hosts'] ?? ''), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $host) {
            try {
                $allowedHosts[] = $this->urlPolicy->normalizeHost($host);
            } catch (ProbeException $exception) {
                throw ValidationException::withMessages(['redirect_hosts' => $exception->getMessage()]);
            }
        }

        return [
            'name' => $validated['name'],
            'url' => isset($validated['url']) ? trim($validated['url']) : '',
            'label' => $validated['label'] ?? null,
            'expected_text' => $validated['expected_text'] ?? null,
            'allowed_redirect_hosts' => array_values(array_unique($allowedHosts)),
        ];
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
