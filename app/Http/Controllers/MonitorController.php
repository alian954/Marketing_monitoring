<?php

namespace App\Http\Controllers;

use App\Models\CheckRun;
use App\Models\DnsChangeEvent;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Services\AcceptedStatusCodeList;
use App\Services\CsvFormulaSanitizer;
use App\Services\DiscordWebhookUrlPolicy;
use App\Services\IncidentManager;
use App\Services\MonitorAvailabilityCalculator;
use App\Services\NotificationEmailRecipientParser;
use App\Services\Probe\ExpectedTextMatcher;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeException;
use App\Services\Probe\RequestHeaderParser;
use App\Services\Probe\ResponseHeaderCondition;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\RunScheduler;
use App\Services\SlackWebhookUrlPolicy;
use App\Services\TeamsWebhookUrlPolicy;
use App\Services\UrlMasker;
use Carbon\CarbonImmutable;
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
        Monitor::MAX_INTERVAL_SECONDS => '每 1 小時',
    ];

    /** @var array<string, string> */
    private const CHECK_RUN_STATUS_OPTIONS = [
        'queued' => '排隊中',
        'running' => '執行中',
        'completed' => '已完成',
        'failed' => '失敗',
        'paused' => '已暫停',
        'stale_config' => '舊版設定',
    ];

    /** @var array<string, string> */
    private const CHECK_RUN_SOURCE_OPTIONS = [
        'scheduled' => '排程檢查',
        'manual' => '手動檢查',
        'confirmation' => '確認延遲檢查',
        'tls_daily' => '每日 TLS 檢查',
        'domain_daily' => '每日網域期限檢查',
    ];

    /** @var array<string, string> */
    private const CHECK_RUN_CONTENT_MATCH_OPTIONS = [
        'found' => '比對結果為「找到」',
        'missing' => '比對結果為「未找到」',
        'not_checked' => '未進行比對',
    ];

    /** @var array<string, array<int, string>> */
    private const MONITOR_LIST_FILTER_RULES = [
        'search' => ['nullable', 'string', 'max:2048'],
        'label' => ['nullable', 'string', 'max:100'],
        'platform' => ['nullable', 'in:all,website,facebook,instagram,threads'],
        'state' => ['nullable', 'in:all,enabled,paused,incidents,stale'],
        'sort' => ['nullable', 'in:name,last_checked_desc,last_checked_asc'],
    ];

    /** @var array<string, string> */
    private const MONITOR_LIST_FILTER_MESSAGES = [
        'search.max' => '搜尋條件不得超過 2048 個字元。',
        'label.max' => '標籤篩選不得超過 100 個字元。',
        'platform.in' => '請選擇有效的平台篩選條件。',
        'state.in' => '請選擇有效的監測狀態篩選條件。',
        'sort.in' => '請選擇有效的監測排序方式。',
    ];

    public function __construct(
        private readonly UrlSafetyPolicy $urlPolicy,
        private readonly UrlMasker $urlMasker,
        private readonly MonitorAvailabilityCalculator $availabilityCalculator,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $validator = Validator::make($request->query(), self::MONITOR_LIST_FILTER_RULES, self::MONITOR_LIST_FILTER_MESSAGES);
        if ($validator->fails()) {
            return redirect()->route('monitors.index')->withErrors($validator);
        }

        $validated = $validator->validated();
        $search = trim((string) ($validated['search'] ?? ''));
        $label = (string) ($validated['label'] ?? '');
        $platform = $validated['platform'] ?? 'all';
        $state = $validated['state'] ?? 'all';
        $sort = $validated['sort'] ?? 'name';
        $filters = ['search' => $search, 'label' => $label, 'platform' => $platform, 'state' => $state, 'sort' => $sort];

        $query = $this->filterMonitorList(Monitor::query(), $filters)
            ->with(['latestCheckRun', 'activeIncidents']);
        $query = match ($sort) {
            'last_checked_desc' => $query->orderByDesc('last_checked_at')->orderBy('name')->orderBy('id'),
            'last_checked_asc' => $query->orderBy('last_checked_at')->orderBy('name')->orderBy('id'),
            default => $query->orderBy('name')->orderBy('id'),
        };
        $monitors = $query->paginate(50)->withQueryString();

        $monitors->getCollection()->each(function (Monitor $monitor): void {
            $monitor->setAttribute('masked_url', $this->urlMasker->mask($monitor->url));
            $monitor->setAttribute('health_state', $monitor->healthState());
        });

        $labels = Monitor::query()
            ->whereNotNull('label')
            ->where('label', '!=', '')
            ->distinct()
            ->orderBy('label')
            ->pluck('label');

        return view('monitors.index', compact('monitors', 'filters', 'labels'));
    }

    /** @param array{search: string, label: string, platform: string, state: string, sort?: string} $filters */
    private function filterMonitorList(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $searchTerm = '%'.$filters['search'].'%';
                $urlFingerprint = Monitor::fingerprintUrl($filters['search']);
                $query->where(function (Builder $query) use ($searchTerm, $urlFingerprint): void {
                    $query->where('name', 'like', $searchTerm)
                        ->orWhere('label', 'like', $searchTerm)
                        ->orWhere('url_hash', $urlFingerprint);
                });
            })
            ->when($filters['label'] !== '', static fn (Builder $query) => $query->where('label', $filters['label']))
            ->when($filters['platform'] !== 'all', static function (Builder $query) use ($filters): void {
                if ($filters['platform'] === 'website') {
                    $query->whereNull('social_platform');

                    return;
                }

                $query->where('social_platform', $filters['platform']);
            })
            ->when($filters['state'] === 'enabled', static fn (Builder $query) => $query->where('enabled', true))
            ->when($filters['state'] === 'paused', static fn (Builder $query) => $query->where('enabled', false))
            ->when($filters['state'] === 'incidents', static function (Builder $query): void {
                $query->whereHas('activeIncidents', static function (Builder $incidentQuery): void {
                    $incidentQuery->whereColumn('incidents.config_version', 'monitors.config_version');
                });
            })
            ->when($filters['state'] === 'stale', static function (Builder $query): void {
                $query->where('enabled', true)
                    ->where(function (Builder $staleQuery): void {
                        $asOf = now();
                        $staleGraceSeconds = max(0, (int) config('monitoring.stale_grace_seconds'));

                        foreach (Monitor::SUPPORTED_INTERVAL_SECONDS as $intervalSeconds) {
                            $staleBefore = $asOf->copy()->subSeconds($intervalSeconds + $staleGraceSeconds);

                            $staleQuery->orWhere(function (Builder $intervalQuery) use ($intervalSeconds, $staleBefore): void {
                                $intervalQuery->where('interval_seconds', $intervalSeconds)
                                    ->where(function (Builder $checkQuery) use ($staleBefore): void {
                                        $checkQuery->where('last_checked_at', '<', $staleBefore)
                                            ->orWhere(function (Builder $firstCheckQuery) use ($staleBefore): void {
                                                $firstCheckQuery->whereNull('last_checked_at')
                                                    ->where('created_at', '<', $staleBefore);
                                            });
                                    });
                            });
                        }
                    });
            });
    }

    public function create(): View
    {
        return view('monitors.form', [
            'monitor' => new Monitor,
            'formAction' => route('monitors.store'),
            'formMethod' => 'POST',
            'previewAction' => route('monitors.probe-preview'),
            'intervalOptions' => self::INTERVAL_OPTIONS,
        ]);
    }

    public function previewProbe(Request $request, ProbeClient $probe): RedirectResponse
    {
        return $this->runProbePreview($request, $probe);
    }

    public function previewExistingProbe(Request $request, Monitor $monitor, ProbeClient $probe): RedirectResponse
    {
        return $this->runProbePreview($request, $probe, $monitor);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateMonitor($request);
        $normalizedUrl = $this->validateProbeUrl($validated['url'], $validated['allowed_redirect_hosts']);
        $this->validateSocialPostExpectation($normalizedUrl, $validated);
        $fingerprint = Monitor::fingerprintUrl($normalizedUrl);

        if (Monitor::query()->where('url_hash', $fingerprint)->exists()) {
            return back()->withErrors(['url' => '此網址已在監測中。'])->withInput($request->except(['notification_slack_webhook', 'notification_discord_webhook', 'notification_teams_webhook']));
        }

        $monitor = Monitor::query()->create([
            'name' => $validated['name'],
            'url' => $normalizedUrl,
            'url_hash' => $fingerprint,
            'label' => $validated['label'] ?: null,
            'notification_email' => $validated['notification_email'],
            'notification_escalation_email' => $validated['notification_escalation_email'],
            'notification_escalation_delay_minutes' => $validated['notification_escalation_delay_minutes'],
            'notification_escalation_stage_2_email' => $validated['notification_escalation_stage_2_email'],
            'notification_escalation_stage_2_delay_minutes' => $validated['notification_escalation_stage_2_delay_minutes'],
            'notification_slack_webhook' => $validated['notification_slack_webhook'],
            'notification_discord_webhook' => $validated['notification_discord_webhook'],
            'notification_teams_webhook' => $validated['notification_teams_webhook'],
            'request_headers' => $validated['request_headers'],
            'expected_response_header' => $validated['expected_response_header'],
            'enabled' => true,
            'config_version' => 1,
            'allowed_redirect_hosts' => $validated['allowed_redirect_hosts'],
            'accepted_status_codes' => $validated['accepted_status_codes'],
            'expected_text' => $validated['expected_text'] ?: null,
            'expected_text_mode' => $validated['expected_text_mode'],
            'response_time_threshold_ms' => $validated['response_time_threshold_ms'],
            'timeout_seconds' => $validated['timeout_seconds'],
            'confirmation_delay_seconds' => $validated['confirmation_delay_seconds'],
            'interval_seconds' => $validated['interval_seconds'],
            'next_check_at' => now(),
        ]);

        return redirect()->route('monitors.show', $monitor)->with('status', '已新增監測項目，排程器下次執行時會進行檢查。');
    }

    public function show(Request $request, Monitor $monitor): View
    {
        $monitor->setAttribute('masked_url', $this->urlMasker->mask($monitor->url));
        $checkRunFilters = $this->validateCheckRunFilters($request);
        $runs = $this->filterCheckRuns($monitor->checkRuns()->getQuery(), $checkRunFilters)
            ->latest('scheduled_at')
            ->paginate(50)
            ->withQueryString();
        $checkRunExportUrl = route('monitors.checks.export', [
            'monitor' => $monitor,
            ...array_filter($checkRunFilters, static fn (string $value): bool => $value !== ''),
        ]);
        $dnsChanges = $monitor->dnsChanges()
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'monitor_id', 'previous_ips', 'current_ips', 'monitor_host_only', 'detected_at']);
        $latestTlsObservation = $monitor->tlsObservations()->latest('observed_on')->first();
        $latestDomainRegistrationObservation = $monitor->domainRegistrationObservations()->latest('observed_on')->first();
        $incidents = $monitor->incidents()->with(['events', 'outboxMessages', 'acknowledgedBy:id,name'])->latest('last_seen_at')->limit(50)->get();
        $asOf = now();
        $historyStart = $asOf->copy()->subDays(30);
        $historyQueryStart = $historyStart->copy()->subSeconds(
            Monitor::MAX_INTERVAL_SECONDS + max(0, (int) config('monitoring.stale_grace_seconds')),
        );
        $historyRuns = $monitor->checkRuns()
            ->where('source', 'scheduled')
            ->whereIn('status', ['completed', 'failed'])
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $historyQueryStart)
            ->orderBy('finished_at')
            ->get(['id', 'config_snapshot', 'status', 'finished_at', 'normal_status', 'error_type', 'normal_elapsed_ms', 'expected_text_found', 'expected_response_header_found']);
        $maintenanceLookupDate = $historyStart->copy()->timezone('Asia/Taipei')->subDays(7)->toDateString();
        $maintenanceWindows = $monitor->maintenanceWindows()
            ->where(function (Builder $query) use ($historyStart, $maintenanceLookupDate): void {
                $query->where(function (Builder $query) use ($historyStart): void {
                    $query->where('repeat_weekly', false)->where('ends_at', '>', $historyStart);
                })->orWhere(function (Builder $query) use ($maintenanceLookupDate): void {
                    $query->where('repeat_weekly', true)->whereDate('repeat_until', '>=', $maintenanceLookupDate);
                });
            })
            ->get();
        $availabilityStats = $this->availabilityCalculator->calculate(
            $historyRuns,
            $maintenanceWindows,
            $monitor->interval_seconds,
            (int) config('monitoring.stale_grace_seconds'),
            $asOf->toImmutable(),
        );

        $checkSuccessStats = collect([
            ['label' => '24 小時', 'starts_at' => $asOf->copy()->subDay()],
            ['label' => '7 天', 'starts_at' => $asOf->copy()->subDays(7)],
            ['label' => '30 天', 'starts_at' => $historyStart],
        ])->map(function (array $period) use ($historyRuns): array {
            $periodRuns = $historyRuns->filter(
                fn (CheckRun $run): bool => $run->finished_at->greaterThanOrEqualTo($period['starts_at']),
            );
            $endedChecks = $periodRuns->count();
            $successfulChecks = $periodRuns->filter(static fn (CheckRun $run): bool => $run->passesMonitorChecks())->count();

            return [
                'label' => $period['label'],
                'success_rate' => $endedChecks === 0 ? null : round(($successfulChecks / $endedChecks) * 100, 2),
                'successful_checks' => $successfulChecks,
                'ended_checks' => $endedChecks,
            ];
        })->all();

        $httpRecoveryIncidents = $monitor->incidents()
            ->where('rule_key', 'http')
            ->where('state', Incident::STATE_RESOLVED)
            ->whereNotNull('opened_at')
            ->whereNotNull('resolved_at')
            ->whereColumn('resolved_at', '>=', 'opened_at')
            ->where('resolved_at', '>=', $historyStart)
            ->where('opened_at', '<=', $asOf)
            ->where('resolved_at', '<=', $asOf)
            ->where(function (Builder $query): void {
                $query->whereNull('classification')->orWhere('classification', '!=', 'false_alarm');
            })
            ->get(['opened_at', 'resolved_at']);
        $httpRecoveryMinutes = $httpRecoveryIncidents->map(
            static fn (Incident $incident): float => ($incident->resolved_at->getTimestamp() - $incident->opened_at->getTimestamp()) / 60,
        );
        $httpRecoveryStats = [
            'count' => $httpRecoveryMinutes->count(),
            'average_minutes' => $httpRecoveryMinutes->isEmpty() ? null : round((float) $httpRecoveryMinutes->avg(), 1),
            'maximum_minutes' => $httpRecoveryMinutes->isEmpty() ? null : round((float) $httpRecoveryMinutes->max(), 1),
        ];

        $responseTimeStart = $asOf->copy()->subDay();
        $responseTimeRuns = $historyRuns->filter(
            fn (CheckRun $run): bool => $run->finished_at->greaterThanOrEqualTo($responseTimeStart)
                && $run->normal_elapsed_ms !== null,
        )->values();
        $responseTimeValuesMs = $responseTimeRuns->pluck('normal_elapsed_ms')->sort()->values();
        $p95Index = (int) ceil($responseTimeValuesMs->count() * 0.95) - 1;
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
            'p95_ms' => $p95Index < 0 ? null : $responseTimeValuesMs->get($p95Index),
            'scale_max_ms' => $responseTimeScaleMaxMs,
            'points' => $responseTimePoints->all(),
            'segments' => $responseTimeSegments,
        ];

        return view('monitors.show', compact(
            'monitor',
            'runs',
            'dnsChanges',
            'latestTlsObservation',
            'latestDomainRegistrationObservation',
            'incidents',
            'checkSuccessStats',
            'availabilityStats',
            'httpRecoveryStats',
            'responseTime',
            'checkRunFilters',
            'checkRunExportUrl',
            'maintenanceWindows',
            'asOf',
        ) + [
            'checkRunStatusOptions' => self::CHECK_RUN_STATUS_OPTIONS,
            'checkRunSourceOptions' => self::CHECK_RUN_SOURCE_OPTIONS,
            'checkRunContentMatchOptions' => self::CHECK_RUN_CONTENT_MATCH_OPTIONS,
        ]);
    }

    public function edit(Monitor $monitor): View
    {
        $monitor->setAttribute('masked_url', $this->urlMasker->mask($monitor->url));

        return view('monitors.form', [
            'monitor' => $monitor,
            'formAction' => route('monitors.update', $monitor),
            'formMethod' => 'PUT',
            'previewAction' => route('monitors.probe-preview.update', $monitor),
            'intervalOptions' => self::INTERVAL_OPTIONS,
        ]);
    }

    public function update(Request $request, Monitor $monitor): RedirectResponse
    {
        $validated = $this->validateMonitor($request, $monitor);
        $inputUrl = trim((string) ($validated['url'] ?? ''));
        $normalizedUrl = $inputUrl === '' ? $monitor->url : $this->validateProbeUrl($inputUrl, $validated['allowed_redirect_hosts']);
        $this->validateSocialPostExpectation($normalizedUrl, $validated);
        $fingerprint = Monitor::fingerprintUrl($normalizedUrl);

        if (Monitor::query()->where('url_hash', $fingerprint)->whereKeyNot($monitor->id)->exists()) {
            return back()->withErrors(['url' => '此網址已在監測中。'])->withInput($request->except(['notification_slack_webhook', 'notification_discord_webhook', 'notification_teams_webhook']));
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
                'notification_email' => $validated['notification_email'],
                'notification_escalation_email' => $validated['notification_escalation_email'],
                'notification_escalation_delay_minutes' => $validated['notification_escalation_delay_minutes'],
                'notification_escalation_stage_2_email' => $validated['notification_escalation_stage_2_email'],
                'notification_escalation_stage_2_delay_minutes' => $validated['notification_escalation_stage_2_delay_minutes'],
                'notification_slack_webhook' => $validated['notification_slack_webhook'],
                'notification_discord_webhook' => $validated['notification_discord_webhook'],
                'notification_teams_webhook' => $validated['notification_teams_webhook'],
                'request_headers' => $validated['request_headers'],
                'expected_response_header' => $validated['expected_response_header'],
                'accepted_status_codes' => $validated['accepted_status_codes'],
                'expected_text' => $validated['expected_text'] ?: null,
                'expected_text_mode' => $validated['expected_text_mode'],
                'response_time_threshold_ms' => $validated['response_time_threshold_ms'],
                'timeout_seconds' => $validated['timeout_seconds'],
                'confirmation_delay_seconds' => $validated['confirmation_delay_seconds'],
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
            $this->pauseMonitorChecks($monitor, $incidents);

            return back()->with('status', '監測已暫停；進行中的事件已標示為暫停，並未標示為恢復。');
        }

        $this->resumeMonitorChecks($monitor);

        return back()->with('status', '監測已恢復，排程器下次執行時會進行檢查。');
    }

    public function bulkStatus(Request $request, IncidentManager $incidents, RunScheduler $scheduler): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:pause,resume,label,check,delete'],
            'label' => ['nullable', 'string', 'max:100'],
            'confirm_delete' => ['exclude_unless:action,delete', 'accepted'],
            'monitor_ids' => ['required', 'array', 'min:1', 'max:50'],
            'monitor_ids.*' => ['required', 'integer', 'distinct', 'exists:monitors,id'],
        ], [
            'action.required' => '請選擇要執行的批次操作。',
            'action.in' => '請選擇有效的批次操作。',
            'label.max' => '監測項目標籤不得超過 100 個字元。',
            'confirm_delete.accepted' => '刪除前請勾選確認方塊。',
            'monitor_ids.required' => '請至少選取一個監測項目。',
            'monitor_ids.array' => '監測項目選取資料格式不正確。',
            'monitor_ids.min' => '請至少選取一個監測項目。',
            'monitor_ids.max' => '每次最多操作 50 個監測項目，請分批處理。',
            'monitor_ids.*.integer' => '監測項目選取資料格式不正確。',
            'monitor_ids.*.distinct' => '監測項目不可重複選取。',
            'monitor_ids.*.exists' => '部分監測項目已不存在，請重新載入清單後再試。',
        ]);

        $monitorIds = array_map('intval', $validated['monitor_ids']);
        $action = $validated['action'];
        $label = trim((string) ($validated['label'] ?? ''));
        $label = $label === '' ? null : $label;

        if ($action === 'check') {
            $monitors = Monitor::query()->whereIn('id', $monitorIds)->orderBy('id')->get();

            if ($monitors->count() !== count($monitorIds)) {
                throw ValidationException::withMessages([
                    'monitor_ids' => '部分監測項目已不存在，請重新載入清單後再試。',
                ]);
            }

            $counts = ['queued' => 0, 'paused' => 0, 'cooldown' => 0, 'in_flight' => 0, 'missing' => 0];

            foreach ($monitors as $monitor) {
                $counts[$this->requestManualCheck($monitor, $scheduler)]++;
            }

            return back()->with('status', $this->bulkManualCheckStatus($counts));
        }

        if ($action === 'delete') {
            $pageWasUnpublished = $this->deleteMonitors($monitorIds);
            $statusMessage = $pageWasUnpublished
                ? '已刪除 '.count($monitorIds).' 個監測項目及其相關紀錄；最後一個公開項目已移除，狀態頁也已取消發布。'
                : '已刪除 '.count($monitorIds).' 個監測項目及其相關紀錄。';

            return back()->with('status', $statusMessage);
        }

        $changedCount = DB::transaction(function () use ($monitorIds, $action, $label, $incidents): int {
            $monitors = Monitor::query()
                ->whereIn('id', $monitorIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($monitors->count() !== count($monitorIds)) {
                throw ValidationException::withMessages([
                    'monitor_ids' => '部分監測項目已不存在，請重新載入清單後再試。',
                ]);
            }

            $changedCount = 0;

            if ($action === 'label') {
                foreach ($monitors as $monitor) {
                    if ($monitor->label === $label) {
                        continue;
                    }

                    $monitor->forceFill(['label' => $label])->save();
                    $changedCount++;
                }

                return $changedCount;
            }

            $shouldEnable = $action === 'resume';

            foreach ($monitors as $monitor) {
                if ($monitor->enabled === $shouldEnable) {
                    continue;
                }

                if ($shouldEnable) {
                    $this->resumeMonitorChecks($monitor);
                } else {
                    $this->pauseMonitorChecks($monitor, $incidents);
                }

                $changedCount++;
            }

            return $changedCount;
        });

        $unchangedCount = count($monitorIds) - $changedCount;
        if ($action === 'label') {
            $labelDescription = $label === null ? '（已清除）' : '為「'.$label.'」';
            $statusMessage = "已更新 {$changedCount} 個監測項目標籤{$labelDescription}。";

            if ($unchangedCount > 0) {
                $statusMessage .= " {$unchangedCount} 個標籤已相同，未變更。";
            }

            return back()->with('status', $statusMessage);
        }

        $shouldEnable = $action === 'resume';
        $actionLabel = $shouldEnable ? '恢復' : '暫停';
        $unchangedLabel = $shouldEnable ? '已啟用' : '已暫停';
        $statusMessage = "已{$actionLabel} {$changedCount} 個監測項目。";

        if ($unchangedCount > 0) {
            $statusMessage .= " {$unchangedCount} 個原本{$unchangedLabel}，未變更。";
        }

        return back()->with('status', $statusMessage);
    }

    public function checkNow(Monitor $monitor, RunScheduler $scheduler): RedirectResponse
    {
        return match ($this->requestManualCheck($monitor, $scheduler)) {
            'queued' => back()->with('status', '手動檢查已排入佇列，並會套用與排程檢查相同的安全及確認規則。'),
            'paused' => back()->withErrors(['check' => '請先恢復監測，再提出手動檢查。']),
            'cooldown' => back()->withErrors(['check' => '剛才已提出手動檢查，請等待五分鐘後再試。']),
            'in_flight' => back()->withErrors(['check' => '此監測項目已有檢查排入佇列或正在執行。']),
            default => back()->withErrors(['check' => '此監測項目已不存在，請重新載入清單。']),
        };
    }

    /** @return 'queued'|'paused'|'cooldown'|'in_flight'|'missing' */
    private function requestManualCheck(Monitor $monitor, RunScheduler $scheduler): string
    {
        $monitor = $monitor->fresh();

        if ($monitor === null) {
            return 'missing';
        }

        if (! $monitor->enabled) {
            return 'paused';
        }

        $cacheKey = 'manual-check:'.$monitor->id;

        if (! Cache::add($cacheKey, true, now()->addMinutes(5))) {
            return 'cooldown';
        }

        if ($scheduler->enqueue($monitor, 'manual') !== null) {
            return 'queued';
        }

        Cache::forget($cacheKey);
        $currentMonitor = $monitor->fresh();

        if ($currentMonitor === null) {
            return 'missing';
        }

        return $currentMonitor->enabled ? 'in_flight' : 'paused';
    }

    /** @param array{queued: int, paused: int, cooldown: int, in_flight: int, missing: int} $counts */
    private function bulkManualCheckStatus(array $counts): string
    {
        $messages = [$counts['queued'] > 0 ? "已排入 {$counts['queued']} 個手動檢查。" : '沒有項目排入手動檢查。'];

        foreach ([
            'paused' => '個已暫停，略過。',
            'cooldown' => '個仍在五分鐘冷卻期，略過。',
            'in_flight' => '個已有檢查排入佇列或正在執行，略過。',
            'missing' => '個已不存在，略過。',
        ] as $result => $suffix) {
            if ($counts[$result] > 0) {
                $messages[] = $counts[$result].' '.$suffix;
            }
        }

        return implode(' ', $messages);
    }

    public function destroy(Monitor $monitor): RedirectResponse
    {
        $pageWasUnpublished = $this->deleteMonitors([(int) $monitor->id]);

        $statusMessage = $pageWasUnpublished
            ? '監測項目及其相關記錄已刪除；最後一個公開項目已移除，狀態頁也已取消發布。'
            : '監測項目及其相關記錄已刪除。';

        return redirect()->route('monitors.index')->with('status', $statusMessage);
    }

    /** @param list<int> $monitorIds */
    private function deleteMonitors(array $monitorIds): bool
    {
        return DB::transaction(function () use ($monitorIds): bool {
            $monitors = Monitor::query()
                ->whereIn('id', $monitorIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($monitors->count() !== count($monitorIds)) {
                throw ValidationException::withMessages([
                    'monitor_ids' => '部分監測項目已不存在，請重新載入清單後再試。',
                ]);
            }

            $removedPublicMonitor = $monitors->contains(static fn (Monitor $monitor): bool => $monitor->public_status);

            foreach ($monitors as $monitor) {
                $monitor->delete();
            }

            if (! $removedPublicMonitor || Monitor::query()->where('public_status', true)->exists()) {
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
    }

    public function exportCheckRuns(Request $request, Monitor $monitor): StreamedResponse
    {
        $checkRunFilters = $this->validateCheckRunFilters($request);

        return response()->streamDownload(function () use ($monitor, $checkRunFilters): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'check_run_id',
                'scheduled_at_utc',
                'started_at_utc',
                'finished_at_utc',
                'source',
                'status',
                'http_status',
                'adsbot_status',
                'http_elapsed_ms',
                'total_elapsed_ms',
                'error_type',
                'adsbot_error_type',
                'expected_text_found',
                'expected_response_header_found',
                'expected_text_mode',
                'tls_expires_at_utc',
            ], ',', '"', '');

            $this->filterCheckRuns($monitor->checkRuns()->getQuery(), $checkRunFilters)
                ->orderBy('scheduled_at')
                ->orderBy('id')
                ->lazy(500)
                ->each(function (CheckRun $run) use ($stream): void {
                    $fields = [
                        (string) $run->id,
                        $run->scheduled_at->utc()->toIso8601String(),
                        $run->started_at?->utc()->toIso8601String() ?? '',
                        $run->finished_at?->utc()->toIso8601String() ?? '',
                        $run->source,
                        $run->status,
                        $run->normal_status === null ? '' : (string) $run->normal_status,
                        $run->adsbot_status === null ? '' : (string) $run->adsbot_status,
                        $run->normal_elapsed_ms === null ? '' : (string) $run->normal_elapsed_ms,
                        $run->elapsed_ms === null ? '' : (string) $run->elapsed_ms,
                        (string) $run->error_type,
                        (string) $run->adsbot_error_type,
                        $run->expected_text_found === null ? '' : ($run->expected_text_found ? '1' : '0'),
                        $run->expected_response_header_found === null ? '' : ($run->expected_response_header_found ? '1' : '0'),
                        $run->expectedTextMode(),
                        $run->tls_expires_at?->utc()->toIso8601String() ?? '',
                    ];

                    fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
                });

            fclose($stream);
        }, 'check-history-'.$monitor->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportDnsChanges(Monitor $monitor): StreamedResponse
    {
        return response()->streamDownload(function () use ($monitor): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'dns_change_id',
                'detected_at_utc',
                'previous_ips',
                'current_ips',
                'monitor_host_only',
            ], ',', '"', '');

            $monitor->dnsChanges()
                ->orderBy('detected_at')
                ->orderBy('id')
                ->lazy(500)
                ->each(function (DnsChangeEvent $event) use ($stream): void {
                    $fields = [
                        (string) $event->id,
                        $event->detected_at->utc()->toIso8601String(),
                        $event->previousIpsLabel(),
                        $event->currentIpsLabel(),
                        $event->monitor_host_only ? '1' : '0',
                    ];

                    fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
                });

            fclose($stream);
        }, 'dns-changes-'.$monitor->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{status: string, source: string, content_match: string, date_from: string, date_to: string} */
    private function validateCheckRunFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::CHECK_RUN_STATUS_OPTIONS))],
            'source' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::CHECK_RUN_SOURCE_OPTIONS))],
            'content_match' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::CHECK_RUN_CONTENT_MATCH_OPTIONS))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], [
            'status.in' => '請選擇有效的檢查執行狀態。',
            'source.in' => '請選擇有效的檢查來源。',
            'content_match.in' => '請選擇有效的文字比對篩選條件。',
            'date_from.date_format' => '開始日期格式無效。',
            'date_to.date_format' => '結束日期格式無效。',
            'date_to.after_or_equal' => '結束日期不可早於開始日期。',
        ]);

        return [
            'status' => (string) ($validated['status'] ?? ''),
            'source' => (string) ($validated['source'] ?? ''),
            'content_match' => (string) ($validated['content_match'] ?? ''),
            'date_from' => (string) ($validated['date_from'] ?? ''),
            'date_to' => (string) ($validated['date_to'] ?? ''),
        ];
    }

    /** @param array{status: string, source: string, content_match: string, date_from: string, date_to: string} $filters */
    private function filterCheckRuns(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] !== '', static fn (Builder $query): Builder => $query->where('status', $filters['status']))
            ->when($filters['source'] !== '', static fn (Builder $query): Builder => $query->where('source', $filters['source']))
            ->when($filters['content_match'] === 'found', static fn (Builder $query): Builder => $query->where('expected_text_found', true))
            ->when($filters['content_match'] === 'missing', static fn (Builder $query): Builder => $query->where('expected_text_found', false))
            ->when($filters['content_match'] === 'not_checked', static fn (Builder $query): Builder => $query->whereNull('expected_text_found'))
            ->when($filters['date_from'] !== '', static function (Builder $query) use ($filters): Builder {
                $start = CarbonImmutable::parse($filters['date_from'], 'Asia/Taipei')->startOfDay()->utc();

                return $query->where('scheduled_at', '>=', $start);
            })
            ->when($filters['date_to'] !== '', static function (Builder $query) use ($filters): Builder {
                $endExclusive = CarbonImmutable::parse($filters['date_to'], 'Asia/Taipei')->addDay()->startOfDay()->utc();

                return $query->where('scheduled_at', '<', $endExclusive);
            });
    }

    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        $exportRules = array_diff_key(self::MONITOR_LIST_FILTER_RULES, ['sort' => true]);
        $validator = Validator::make($request->query(), $exportRules, self::MONITOR_LIST_FILTER_MESSAGES);
        if ($validator->fails()) {
            return redirect()->route('monitors.index')->withErrors($validator);
        }

        $validated = $validator->validated();
        $filters = [
            'search' => trim((string) ($validated['search'] ?? '')),
            'label' => (string) ($validated['label'] ?? ''),
            'platform' => $validated['platform'] ?? 'all',
            'state' => $validated['state'] ?? 'all',
        ];

        return response()->streamDownload(function () use ($filters): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['name', 'url', 'label', 'enabled', 'last_checked_at_utc', 'http_status', 'error_type']);

            $this->filterMonitorList(Monitor::query(), $filters)
                ->with('latestCheckRun')
                ->orderBy('id')
                ->lazyById()
                ->each(function (Monitor $monitor) use ($stream): void {
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

                    fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
                });

            fclose($stream);
        }, 'monitors.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{name: string, url: string, label: ?string, notification_email: ?string, notification_escalation_email: ?string, notification_escalation_delay_minutes: ?int, notification_escalation_stage_2_email: ?string, notification_escalation_stage_2_delay_minutes: ?int, notification_slack_webhook: ?string, notification_discord_webhook: ?string, notification_teams_webhook: ?string, request_headers: array<string, string>, expected_response_header: ?string, accepted_status_codes: list<int>, expected_text: ?string, expected_text_mode: string, response_time_threshold_ms: ?int, timeout_seconds: int, confirmation_delay_seconds: int, interval_seconds: int, allowed_redirect_hosts: list<string>} */
    private function validateMonitor(Request $request, ?Monitor $monitor = null): array
    {
        $requestHeadersInput = $request->input('request_headers', '');
        $expectedResponseHeaderInput = $request->input('expected_response_header', '');
        $request->request->remove('request_headers');
        $request->request->remove('expected_response_header');
        $request->query->remove('request_headers');
        $request->query->remove('expected_response_header');

        if ($request->isJson()) {
            $request->json()->remove('request_headers');
            $request->json()->remove('expected_response_header');
        }

        if ($requestHeadersInput !== null && ! is_string($requestHeadersInput)) {
            throw ValidationException::withMessages(['request_headers' => '自訂 HTTP 標頭格式不正確。']);
        }

        if ($expectedResponseHeaderInput !== null && ! is_string($expectedResponseHeaderInput)) {
            throw ValidationException::withMessages(['expected_response_header' => '回應標頭條件格式不正確。']);
        }

        if ($request->exists('expected_text') && is_string($request->input('expected_text'))) {
            $request->merge(['expected_text' => ExpectedTextMatcher::normalize($request->input('expected_text'))]);
        }

        if ($request->exists('notification_email') && is_string($request->input('notification_email'))) {
            $request->merge([
                'notification_email' => NotificationEmailRecipientParser::normalize($request->input('notification_email')),
            ]);
        }

        if ($request->exists('notification_slack_webhook') && is_string($request->input('notification_slack_webhook'))) {
            $webhookUrl = trim($request->input('notification_slack_webhook'));
            $request->merge(['notification_slack_webhook' => $webhookUrl === '' ? null : $webhookUrl]);
        }

        if ($request->exists('notification_discord_webhook') && is_string($request->input('notification_discord_webhook'))) {
            $webhookUrl = trim($request->input('notification_discord_webhook'));
            $request->merge(['notification_discord_webhook' => $webhookUrl === '' ? null : $webhookUrl]);
        }

        if ($request->exists('notification_teams_webhook') && is_string($request->input('notification_teams_webhook'))) {
            $webhookUrl = trim($request->input('notification_teams_webhook'));
            $request->merge(['notification_teams_webhook' => $webhookUrl === '' ? null : $webhookUrl]);
        }

        if ($request->exists('notification_escalation_email') && is_string($request->input('notification_escalation_email'))) {
            $request->merge([
                'notification_escalation_email' => NotificationEmailRecipientParser::normalize($request->input('notification_escalation_email')),
            ]);
        }

        if ($request->exists('notification_escalation_stage_2_email') && is_string($request->input('notification_escalation_stage_2_email'))) {
            $request->merge([
                'notification_escalation_stage_2_email' => NotificationEmailRecipientParser::normalize($request->input('notification_escalation_stage_2_email')),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => [$monitor === null ? 'required' : 'nullable', 'string', 'max:2048'],
            'label' => ['nullable', 'string', 'max:100'],
            'notification_email' => ['sometimes', 'nullable', 'string', 'max:2549'],
            'notification_escalation_email' => ['sometimes', 'nullable', 'string', 'max:2549'],
            'notification_escalation_delay_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
            'notification_escalation_stage_2_email' => ['sometimes', 'nullable', 'string', 'max:2549'],
            'notification_escalation_stage_2_delay_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
            'notification_slack_webhook' => ['sometimes', 'nullable', 'string', 'max:2048', 'url'],
            'clear_notification_slack_webhook' => ['sometimes', 'boolean'],
            'notification_discord_webhook' => ['sometimes', 'nullable', 'string', 'max:2048', 'url'],
            'clear_notification_discord_webhook' => ['sometimes', 'boolean'],
            'notification_teams_webhook' => ['sometimes', 'nullable', 'string', 'max:2048', 'url'],
            'clear_notification_teams_webhook' => ['sometimes', 'boolean'],
            'clear_request_headers' => ['sometimes', 'boolean'],
            'clear_expected_response_header' => ['sometimes', 'boolean'],
            'accepted_status_codes' => ['nullable', 'string', 'max:1000'],
            'expected_text' => ['nullable', 'string', 'max:500'],
            'expected_text_mode' => ['sometimes', 'in:'.implode(',', [
                ExpectedTextMatcher::MODE_PRESENT,
                ExpectedTextMatcher::MODE_PRESENT_ANY,
                ExpectedTextMatcher::MODE_ABSENT,
            ])],
            'response_time_threshold_ms' => ['nullable', 'integer', 'min:100', 'max:14000'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:15'],
            'confirmation_delay_seconds' => ['sometimes', 'integer', 'min:30', 'max:300'],
            'interval_seconds' => ['sometimes', 'integer', 'in:'.implode(',', Monitor::SUPPORTED_INTERVAL_SECONDS)],
            'redirect_hosts' => ['nullable', 'string', 'max:2000'],
        ], [
            'response_time_threshold_ms.integer' => '回應時間門檻必須是整數。',
            'response_time_threshold_ms.min' => '回應時間門檻不得低於 100 毫秒。',
            'response_time_threshold_ms.max' => '回應時間門檻不得高於 14000 毫秒。',
            'timeout_seconds.integer' => '請求逾時秒數必須是整數。',
            'timeout_seconds.min' => '請求逾時秒數不得低於 1 秒。',
            'timeout_seconds.max' => '請求逾時秒數不得高於 15 秒。',
            'confirmation_delay_seconds.integer' => '異常確認延遲必須是整數秒。',
            'confirmation_delay_seconds.min' => '異常確認延遲不得低於 30 秒。',
            'confirmation_delay_seconds.max' => '異常確認延遲不得高於 300 秒。',
            'expected_text_mode.in' => '文字條件設定無效，請重新選擇。',
            'accepted_status_codes.string' => '額外接受狀態碼格式不正確。',
            'accepted_status_codes.max' => '額外接受狀態碼欄位過長。',
            'notification_email.string' => '告警收件信箱格式不正確。',
            'notification_email.max' => '告警收件信箱清單過長。',
            'notification_escalation_email.string' => '升級告警收件信箱格式不正確。',
            'notification_escalation_email.max' => '升級告警收件信箱清單過長。',
            'notification_escalation_delay_minutes.integer' => '升級等待時間必須是整數分鐘。',
            'notification_escalation_delay_minutes.min' => '升級等待時間不得短於 5 分鐘。',
            'notification_escalation_delay_minutes.max' => '升級等待時間不得超過 1440 分鐘。',
            'notification_escalation_stage_2_email.string' => '第二階段升級收件信箱格式不正確。',
            'notification_escalation_stage_2_email.max' => '第二階段升級收件信箱清單過長。',
            'notification_escalation_stage_2_delay_minutes.integer' => '第二階段等待時間必須是整數分鐘。',
            'notification_escalation_stage_2_delay_minutes.min' => '第二階段等待時間不得短於 5 分鐘。',
            'notification_escalation_stage_2_delay_minutes.max' => '第二階段等待時間不得超過 1440 分鐘。',
            'notification_slack_webhook.url' => '請輸入有效的 Slack webhook 網址。',
            'notification_slack_webhook.max' => 'Slack webhook 網址不得超過 2048 個字元。',
            'notification_discord_webhook.url' => '請輸入有效的 Discord webhook 網址。',
            'notification_discord_webhook.max' => 'Discord webhook 網址不得超過 2048 個字元。',
            'notification_teams_webhook.url' => '請輸入有效的 Microsoft Teams webhook 網址。',
            'notification_teams_webhook.max' => 'Microsoft Teams webhook 網址不得超過 2048 個字元。',
            'interval_seconds.integer' => '檢查頻率格式不正確。',
            'interval_seconds.in' => '請選擇支援的檢查頻率。',
        ]);

        try {
            $requestHeaders = RequestHeaderParser::parse($requestHeadersInput);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['request_headers' => $exception->getMessage()]);
        }

        try {
            $expectedResponseHeader = ResponseHeaderCondition::normalizedCondition($expectedResponseHeaderInput);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['expected_response_header' => $exception->getMessage()]);
        }

        $clearRequestHeaders = (bool) ($validated['clear_request_headers'] ?? false);

        if ($clearRequestHeaders && $requestHeaders !== []) {
            throw ValidationException::withMessages(['clear_request_headers' => '清除自訂標頭時，請先清空標頭輸入欄。']);
        }

        if ($clearRequestHeaders) {
            $requestHeaders = [];
        } elseif ($requestHeaders === []) {
            $requestHeaders = $monitor?->request_headers ?? [];
        }

        $clearExpectedResponseHeader = (bool) ($validated['clear_expected_response_header'] ?? false);

        if ($clearExpectedResponseHeader && $expectedResponseHeader !== null) {
            throw ValidationException::withMessages(['clear_expected_response_header' => '清除回應標頭條件時，請先清空條件輸入欄。']);
        }

        if ($clearExpectedResponseHeader) {
            $expectedResponseHeader = null;
        } elseif ($expectedResponseHeader === null) {
            $expectedResponseHeader = $monitor?->expected_response_header;
        }

        $requestUrl = trim((string) ($validated['url'] ?? '')) !== ''
            ? trim((string) $validated['url'])
            : (string) ($monitor?->url ?? '');

        if ($requestHeaders !== [] && mb_strtolower((string) parse_url($requestUrl, PHP_URL_SCHEME)) !== 'https') {
            throw ValidationException::withMessages([
                'request_headers' => '自訂 HTTP 標頭可能含敏感值，監測網址必須使用 HTTPS。',
            ]);
        }

        $timeoutSeconds = array_key_exists('timeout_seconds', $validated)
            ? (int) $validated['timeout_seconds']
            : ($monitor?->timeout_seconds ?? (int) config('monitoring.request_timeout_seconds'));
        $responseTimeThresholdMs = array_key_exists('response_time_threshold_ms', $validated)
            ? $validated['response_time_threshold_ms']
            : $monitor?->response_time_threshold_ms;

        if ($responseTimeThresholdMs !== null && (int) $responseTimeThresholdMs >= $timeoutSeconds * 1000) {
            throw ValidationException::withMessages([
                'response_time_threshold_ms' => '回應時間門檻必須低於請求逾時上限（'.($timeoutSeconds * 1000).' 毫秒）。',
            ]);
        }

        $this->validateNotificationEmailRecipients($validated['notification_email'] ?? null);
        $escalationEmail = array_key_exists('notification_escalation_email', $validated)
            ? $validated['notification_escalation_email']
            : $monitor?->notification_escalation_email;
        $escalationDelayMinutes = array_key_exists('notification_escalation_delay_minutes', $validated)
            ? ($validated['notification_escalation_delay_minutes'] === null ? null : (int) $validated['notification_escalation_delay_minutes'])
            : $monitor?->notification_escalation_delay_minutes;
        $this->validateNotificationEmailRecipients($escalationEmail, 'notification_escalation_email');

        if ($escalationEmail !== null && $escalationDelayMinutes === null) {
            throw ValidationException::withMessages([
                'notification_escalation_delay_minutes' => '設定升級告警收件信箱時，請同時設定等待時間。',
            ]);
        }

        if ($escalationEmail === null) {
            $escalationDelayMinutes = null;
        }

        $escalationStageTwoEmail = array_key_exists('notification_escalation_stage_2_email', $validated)
            ? $validated['notification_escalation_stage_2_email']
            : $monitor?->notification_escalation_stage_2_email;
        $escalationStageTwoDelayMinutes = array_key_exists('notification_escalation_stage_2_delay_minutes', $validated)
            ? ($validated['notification_escalation_stage_2_delay_minutes'] === null ? null : (int) $validated['notification_escalation_stage_2_delay_minutes'])
            : $monitor?->notification_escalation_stage_2_delay_minutes;
        $this->validateNotificationEmailRecipients($escalationStageTwoEmail, 'notification_escalation_stage_2_email');

        if ($escalationStageTwoEmail !== null && $escalationEmail === null) {
            throw ValidationException::withMessages([
                'notification_escalation_stage_2_email' => '設定第二階段時，請先設定第一階段升級收件人。',
            ]);
        }

        if ($escalationStageTwoEmail !== null && $escalationStageTwoDelayMinutes === null) {
            throw ValidationException::withMessages([
                'notification_escalation_stage_2_delay_minutes' => '設定第二階段升級收件人時，請同時設定等待時間。',
            ]);
        }

        if (
            $escalationStageTwoEmail !== null
            && $escalationDelayMinutes !== null
            && $escalationStageTwoDelayMinutes !== null
            && $escalationStageTwoDelayMinutes <= $escalationDelayMinutes
        ) {
            throw ValidationException::withMessages([
                'notification_escalation_stage_2_delay_minutes' => '第二階段等待時間必須晚於第一階段。',
            ]);
        }

        if ($escalationStageTwoEmail === null) {
            $escalationStageTwoDelayMinutes = null;
        }

        $slackWebhookUrl = $validated['notification_slack_webhook'] ?? null;

        if ($slackWebhookUrl !== null && ! SlackWebhookUrlPolicy::isAllowed($slackWebhookUrl)) {
            throw ValidationException::withMessages([
                'notification_slack_webhook' => '請使用 hooks.slack.com 或 hooks.slack-gov.com 的 HTTPS Incoming Webhook 網址。',
            ]);
        }

        if ($slackWebhookUrl === null && ! (bool) ($validated['clear_notification_slack_webhook'] ?? false)) {
            $slackWebhookUrl = $monitor?->notification_slack_webhook;
        }

        $discordWebhookUrl = $validated['notification_discord_webhook'] ?? null;

        if ($discordWebhookUrl !== null && ! DiscordWebhookUrlPolicy::isAllowed($discordWebhookUrl)) {
            throw ValidationException::withMessages([
                'notification_discord_webhook' => '請使用 discord.com 的 Discord Incoming Webhook HTTPS 網址。',
            ]);
        }

        if ($discordWebhookUrl === null && ! (bool) ($validated['clear_notification_discord_webhook'] ?? false)) {
            $discordWebhookUrl = $monitor?->notification_discord_webhook;
        }

        $teamsWebhookUrl = $validated['notification_teams_webhook'] ?? null;

        if ($teamsWebhookUrl !== null && ! TeamsWebhookUrlPolicy::isAllowed($teamsWebhookUrl)) {
            throw ValidationException::withMessages([
                'notification_teams_webhook' => '請使用 Microsoft Power Automate Teams Workflow HTTPS webhook 網址。',
            ]);
        }

        if ($teamsWebhookUrl === null && ! (bool) ($validated['clear_notification_teams_webhook'] ?? false)) {
            $teamsWebhookUrl = $monitor?->notification_teams_webhook;
        }

        try {
            $allowedHosts = $this->urlPolicy->normalizeRedirectHosts($validated['redirect_hosts'] ?? null);
        } catch (ProbeException $exception) {
            throw ValidationException::withMessages(['redirect_hosts' => $exception->getMessage()]);
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
            'notification_email' => array_key_exists('notification_email', $validated)
                ? $validated['notification_email']
                : $monitor?->notification_email,
            'notification_escalation_email' => $escalationEmail,
            'notification_escalation_delay_minutes' => $escalationDelayMinutes,
            'notification_escalation_stage_2_email' => $escalationStageTwoEmail,
            'notification_escalation_stage_2_delay_minutes' => $escalationStageTwoDelayMinutes,
            'notification_slack_webhook' => $slackWebhookUrl,
            'notification_discord_webhook' => $discordWebhookUrl,
            'notification_teams_webhook' => $teamsWebhookUrl,
            'request_headers' => $requestHeaders,
            'expected_response_header' => $expectedResponseHeader,
            'accepted_status_codes' => $acceptedStatusCodes,
            'expected_text' => isset($validated['expected_text']) ? trim($validated['expected_text']) : null,
            'expected_text_mode' => $validated['expected_text_mode'] ?? $monitor?->expected_text_mode ?? 'present',
            'response_time_threshold_ms' => array_key_exists('response_time_threshold_ms', $validated)
                ? ($validated['response_time_threshold_ms'] === null ? null : (int) $validated['response_time_threshold_ms'])
                : ($monitor?->response_time_threshold_ms),
            'timeout_seconds' => array_key_exists('timeout_seconds', $validated)
                ? (int) $validated['timeout_seconds']
                : ($monitor?->timeout_seconds ?? (int) config('monitoring.request_timeout_seconds')),
            'confirmation_delay_seconds' => array_key_exists('confirmation_delay_seconds', $validated)
                ? (int) $validated['confirmation_delay_seconds']
                : ($monitor?->confirmation_delay_seconds ?? (int) config('monitoring.confirmation_delay_seconds')),
            'interval_seconds' => array_key_exists('interval_seconds', $validated)
                ? (int) $validated['interval_seconds']
                : ($monitor?->interval_seconds ?? (int) config('monitoring.interval_seconds')),
            'allowed_redirect_hosts' => array_values(array_unique($allowedHosts)),
        ];
    }

    private function validateNotificationEmailRecipients(?string $notificationEmail, string $attribute = 'notification_email'): void
    {
        $message = NotificationEmailRecipientParser::validationError($notificationEmail);

        if ($message !== null) {
            throw ValidationException::withMessages([$attribute => $message]);
        }
    }

    /** @return list<int> */
    private function normalizeAcceptedStatusCodes(?string $input): array
    {
        try {
            return AcceptedStatusCodeList::normalize($input);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'accepted_status_codes' => $exception->getMessage(),
            ]);
        }
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

    private function runProbePreview(Request $request, ProbeClient $probe, ?Monitor $monitor = null): RedirectResponse
    {
        $validated = $this->validateMonitor($request, $monitor);
        $inputUrl = trim((string) ($validated['url'] ?? ''));
        $url = $inputUrl !== ''
            ? $this->validateProbeUrl($inputUrl, $validated['allowed_redirect_hosts'])
            : ($monitor?->url ?? '');

        if ($url === '') {
            throw ValidationException::withMessages(['url' => '請輸入要預先檢查的網址。']);
        }

        $this->validateSocialPostExpectation($url, $validated);

        $expectedText = $validated['expected_text'] ?: null;
        $probeArguments = [
            $url,
            $validated['allowed_redirect_hosts'],
            'MarketingMonitor/1.0 (+authorized landing page check)',
            $expectedText,
            $validated['timeout_seconds'],
            $validated['expected_text_mode'],
        ];

        if ($validated['request_headers'] !== []) {
            $probeArguments[] = $validated['request_headers'];
        } elseif ($validated['expected_response_header'] !== null) {
            $probeArguments[] = [];
        }

        if ($validated['expected_response_header'] !== null) {
            $probeArguments[] = $validated['expected_response_header'];
        }

        $result = $probe->inspect(...$probeArguments);
        $statusAccepted = $result->statusCode !== null
            && (($result->statusCode >= 200 && $result->statusCode < 400)
                || in_array($result->statusCode, $validated['accepted_status_codes'], true));
        $contentConditionPassed = $expectedText === null
            || ($validated['expected_text_mode'] === ExpectedTextMatcher::MODE_ABSENT
                ? $result->expectedTextFound === false
                : $result->expectedTextFound === true);
        $responseHeaderConditionPassed = $validated['expected_response_header'] === null
            || $result->expectedResponseHeaderFound === true;
        $isIncomplete = $result->errorType === 'body_too_large'
            && $statusAccepted
            && $expectedText !== null
            && $result->expectedTextFound === null
            && $responseHeaderConditionPassed;
        $isPassed = $result->errorType === null && $statusAccepted && $contentConditionPassed && $responseHeaderConditionPassed;
        $state = $isPassed ? 'passed' : ($isIncomplete ? 'incomplete' : 'failed');
        $message = $this->probePreviewMessage($result->errorType, $result->statusCode, $statusAccepted, $contentConditionPassed, $responseHeaderConditionPassed, $isIncomplete, $validated['expected_text_mode']);
        $expectedTextMatches = [];
        $expectedTextTerms = ExpectedTextMatcher::terms($expectedText);

        if ($result->expectedTextTermMatches !== null && count($expectedTextTerms) === count($result->expectedTextTermMatches)) {
            foreach ($expectedTextTerms as $index => $term) {
                $expectedTextMatches[] = [
                    'term' => $term,
                    'matched' => $result->expectedTextTermMatches[$index],
                ];
            }
        }

        return back()
            ->withInput($request->except(['notification_slack_webhook', 'notification_discord_webhook', 'notification_teams_webhook', 'request_headers', 'expected_response_header', '_token', '_method']))
            ->with('probe_preview', [
                'state' => $state,
                'label' => match ($state) {
                    'passed' => '預檢通過',
                    'incomplete' => '無法完整判定',
                    default => '預檢未通過',
                },
                'message' => $message,
                'status_code' => $result->statusCode,
                'elapsed_ms' => $result->elapsedMs,
                'final_url' => $result->finalUrl,
                'expected_text_checked' => $expectedText !== null,
                'expected_text_mode' => $validated['expected_text_mode'],
                'expected_text_state' => $result->expectedTextFound === null
                    ? null
                    : ($result->expectedTextFound ? 'found' : 'missing'),
                'expected_text_matches' => $expectedTextMatches,
                'expected_response_header_checked' => $validated['expected_response_header'] !== null,
                'expected_response_header_found' => $result->expectedResponseHeaderFound,
            ]);
    }

    private function probePreviewMessage(
        ?string $errorType,
        ?int $statusCode,
        bool $statusAccepted,
        bool $contentConditionPassed,
        bool $responseHeaderConditionPassed,
        bool $isIncomplete,
        string $expectedTextMode,
    ): string {
        if ($isIncomplete) {
            return '回應超過讀取上限，指定文字比對尚未完成。';
        }

        if ($errorType !== null) {
            return match ($errorType) {
                'blocked_ssrf' => '網址主機解析到非公開 IP，安全檢查已封鎖此次請求。',
                'blocked_port' => '只允許連接埠 80 或 443。',
                'dns_nxdomain' => '網址主機無法解析，請確認網址與 DNS 設定。',
                'dns_timeout', 'timeout' => '請求逾時，請稍後再試。',
                'tls_handshake', 'tls_verify' => '無法完成安全 TLS 連線。',
                'redirect_host_not_allowed' => '回應重新導向至未允許的主機。',
                'redirect_invalid', 'redirect_limit', 'redirect_loop' => '網址重新導向無效或超過允許次數。',
                'body_too_large' => '回應內容超過讀取上限，無法完成檢查。',
                default => '無法完成安全 HTTP 檢查（'.$errorType.'）。',
            };
        }

        if (! $statusAccepted) {
            return $statusCode === null
                ? '沒有收到 HTTP 狀態碼。'
                : 'HTTP '.$statusCode.' 不在此監測設定接受的狀態碼範圍內。';
        }

        if (! $contentConditionPassed) {
            return match ($expectedTextMode) {
                'absent' => '原始 HTTP 回應包含至少一個不得出現的片段。',
                'present_any' => '原始 HTTP 回應未找到任何一個指定片段。',
                default => '原始 HTTP 回應至少缺少一個指定片段。',
            };
        }

        if (! $responseHeaderConditionPassed) {
            return 'HTTP 回應未包含符合設定的標頭名稱或預期值。';
        }

        return 'HTTP 狀態與文字條件均符合目前設定。';
    }

    /** @param array{expected_text?: ?string, expected_text_mode?: string} $validated */
    private function validateSocialPostExpectation(string $url, array $validated): void
    {
        $platform = Monitor::socialPlatformForUrl($url);

        if ($platform === null) {
            return;
        }

        if (trim((string) ($validated['expected_text'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'expected_text' => '監測 '.$platform.' 公開貼文時，請填入一段應出現在貼文中的文字。',
            ]);
        }

        if (! in_array($validated['expected_text_mode'] ?? ExpectedTextMatcher::MODE_PRESENT, [
            ExpectedTextMatcher::MODE_PRESENT,
            ExpectedTextMatcher::MODE_PRESENT_ANY,
        ], true)) {
            throw ValidationException::withMessages([
                'expected_text_mode' => '社群貼文監測需確認指定文字存在，請選擇「全部片段都需包含」或「至少包含一個片段」。',
            ]);
        }
    }

    private function pauseMonitorChecks(Monitor $monitor, IncidentManager $incidents): void
    {
        $monitor->forceFill(['enabled' => false, 'next_check_at' => null])->save();
        CheckRun::query()
            ->where('monitor_id', $monitor->id)
            ->where('status', 'queued')
            ->update(['status' => 'paused', 'finished_at' => now(), 'updated_at' => now()]);
        $incidents->pauseMonitor($monitor);
    }

    private function resumeMonitorChecks(Monitor $monitor): void
    {
        $monitor->forceFill(['enabled' => true, 'next_check_at' => now()])->save();
    }
}
