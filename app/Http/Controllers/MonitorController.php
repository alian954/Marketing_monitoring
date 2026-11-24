<?php

namespace App\Http\Controllers;

use App\Models\CheckRun;
use App\Models\Client;
use App\Models\ClientSelectionContext;
use App\Models\DnsChangeEvent;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\AcceptedStatusCodeList;
use App\Services\CsvFormulaSanitizer;
use App\Services\DiscordWebhookUrlPolicy;
use App\Services\FleetAvailabilityReport;
use App\Services\IncidentManager;
use App\Services\MonitorAvailabilityReport;
use App\Services\MonitorSloBudgetCalculator;
use App\Services\NotificationEmailRecipientParser;
use App\Services\Probe\ExpectedTextMatcher;
use App\Services\Probe\ProbeClient;
use App\Services\Probe\ProbeException;
use App\Services\Probe\RequestHeaderParser;
use App\Services\Probe\ResponseHeaderCondition;
use App\Services\Probe\UrlSafetyPolicy;
use App\Services\RedirectChangeHistory;
use App\Services\ResponseContentChangeHistory;
use App\Services\ResponseMetadataChangeHistory;
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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonitorController extends Controller
{
    private const SOCIAL_ENGAGEMENT_PLATFORMS = ['facebook', 'instagram', 'threads', 'dcard'];

    /** @var array<int, string> */
    private const SOCIAL_ENGAGEMENT_HISTORY_PERIODS = [
        7 => '近 7 天',
        30 => '近 30 天',
        90 => '近 90 天',
    ];

    private const HISTORY_TIMEZONE = 'Asia/Taipei';

    private const DAILY_HISTORY_DAYS = 30;

    private const MAX_PUBLIC_ENGAGEMENT_TREND_POINTS = 60;

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
        'platform' => ['nullable', 'in:all,website,facebook,instagram,threads,dcard'],
        'state' => ['nullable', 'in:all,enabled,paused,incidents,stale'],
        'content_match' => ['nullable', 'in:all,found,missing,not_checked,visible_text_changed,visible_text_stable,visible_text_pending'],
        'http_status' => ['nullable', 'integer', 'between:100,599'],
        'signal' => ['nullable', 'string', 'max:40', 'regex:/\A[a-z0-9_]+\z/'],
        'client_id' => ['nullable', 'integer', 'min:1'],
        'sort' => ['nullable', 'in:name,last_checked_desc,last_checked_asc'],
    ];

    /** @var array<string, string> */
    private const MONITOR_LIST_FILTER_MESSAGES = [
        'search.max' => '搜尋條件不得超過 2048 個字元。',
        'label.max' => '標籤篩選不得超過 100 個字元。',
        'platform.in' => '請選擇有效的平台篩選條件。',
        'state.in' => '請選擇有效的監測狀態篩選條件。',
        'content_match.in' => '請選擇有效的文字比對篩選條件。',
        'http_status.integer' => 'HTTP 回應碼必須是整數。',
        'http_status.between' => 'HTTP 回應碼必須介於 100–599。',
        'signal.max' => '訊號代碼不得超過 40 個字元。',
        'signal.regex' => '訊號代碼只能使用小寫英文字母、數字與底線。',
        'client_id.integer' => '請選擇有效的客戶。',
        'client_id.min' => '請選擇有效的客戶。',
        'client_id.exists' => '所選客戶不存在或無權限。',
        'sort.in' => '請選擇有效的監測排序方式。',
    ];

    public function __construct(
        private readonly UrlSafetyPolicy $urlPolicy,
        private readonly UrlMasker $urlMasker,
        private readonly MonitorAvailabilityReport $availabilityReport,
        private readonly MonitorSloBudgetCalculator $sloBudgetCalculator,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $validator = Validator::make($request->query(), $this->monitorListFilterRules($request), self::MONITOR_LIST_FILTER_MESSAGES);
        if ($validator->fails()) {
            return redirect()->route('monitors.index')->withErrors($validator);
        }

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Client::ensureDefaultFor($user);

        $validated = $validator->validated();
        $search = trim((string) ($validated['search'] ?? ''));
        $label = (string) ($validated['label'] ?? '');
        $platform = $validated['platform'] ?? 'all';
        $state = $validated['state'] ?? 'all';
        $contentMatch = $validated['content_match'] ?? 'all';
        $httpStatus = (string) ($validated['http_status'] ?? '');
        $signal = (string) ($validated['signal'] ?? '');
        $sort = $validated['sort'] ?? 'name';
        $filters = [
            'search' => $search,
            'label' => $label,
            'platform' => $platform,
            'state' => $state,
            'content_match' => $contentMatch,
            'http_status' => $httpStatus,
            'signal' => $signal,
            'client_id' => isset($validated['client_id']) ? (string) $validated['client_id'] : '',
            'sort' => $sort,
        ];

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
            $activeIncidentReasonLabels = $monitor->activeIncidents
                ->where('config_version', $monitor->config_version)
                ->map(static fn (Incident $incident): string => $incident->ruleLabel())
                ->unique()
                ->values();
            $monitor->setAttribute('active_incident_reason_labels', $activeIncidentReasonLabels->all());
        });

        $labels = Monitor::query()
            ->when($filters['client_id'] !== '', static fn (Builder $query): Builder => $query->where('client_id', (int) $filters['client_id']))
            ->select(['id', 'label', 'tags'])
            ->lazyById(500)
            ->flatMap(static fn (Monitor $monitor): array => $monitor->tagNames())
            ->unique()
            ->sort()
            ->values();

        return view('monitors.index', compact('monitors', 'filters', 'labels') + [
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'intervalOptions' => self::INTERVAL_OPTIONS,
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function monitorListFilterRules(Request $request): array
    {
        $rules = self::MONITOR_LIST_FILTER_RULES;
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $clientExistsRule = Rule::exists('clients', 'id')->where('user_id', $user->workspaceOwnerId());
        $clientIds = $user->workspaceClientIds();

        if ($clientIds !== null) {
            $clientExistsRule->whereIn('id', $clientIds);
        }

        $rules['client_id'][] = $clientExistsRule;

        return $rules;
    }

    /** @param array{search: string, label: string, platform: string, state: string, content_match: string, http_status?: string, signal?: string, client_id?: string, sort?: string} $filters */
    private function filterMonitorList(Builder $query, array $filters): Builder
    {
        return $query
            ->when(($filters['client_id'] ?? '') !== '', static function (Builder $query) use ($filters): void {
                $query->where('client_id', (int) $filters['client_id']);
            })
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $searchTerm = '%'.$filters['search'].'%';
                $encodedSearchValue = json_encode($filters['search'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '""';
                $encodedSearchTerm = '%'.substr($encodedSearchValue, 1, -1).'%';
                $urlFingerprint = Monitor::fingerprintUrl($filters['search']);
                $query->where(function (Builder $query) use ($searchTerm, $encodedSearchTerm, $urlFingerprint): void {
                    $query->where('name', 'like', $searchTerm)
                        ->orWhere('label', 'like', $searchTerm)
                        ->orWhere('tags', 'like', $searchTerm)
                        ->orWhere('url_hash', $urlFingerprint)
                        ->orWhereHas('latestCheckRun', static function (Builder $checkRunQuery) use ($searchTerm): void {
                            $checkRunQuery->where(function (Builder $metadataQuery) use ($searchTerm): void {
                                $metadataQuery->where('response_title', 'like', $searchTerm)
                                    ->orWhere('response_description', 'like', $searchTerm);
                            });
                        });

                    if ($encodedSearchTerm !== $searchTerm) {
                        $query->orWhere('tags', 'like', $encodedSearchTerm);
                    }
                });
            })
            ->when($filters['label'] !== '', static function (Builder $query) use ($filters): void {
                $query->where(function (Builder $tagQuery) use ($filters): void {
                    $tagQuery->whereJsonContains('tags', $filters['label'])
                        ->orWhere('label', $filters['label']);
                });
            })
            ->when($filters['platform'] !== 'all', static function (Builder $query) use ($filters): void {
                if ($filters['platform'] === 'website') {
                    $query->whereNull('social_platform');

                    return;
                }

                $query->where('social_platform', $filters['platform']);
            })
            ->when($filters['content_match'] !== 'all', static function (Builder $query) use ($filters): void {
                if (in_array($filters['content_match'], ['visible_text_changed', 'visible_text_stable', 'visible_text_pending'], true)) {
                    $query->where('alert_on_visible_text_change', true);

                    if ($filters['content_match'] === 'visible_text_pending') {
                        $query->where(function (Builder $baselineQuery): void {
                            $baselineQuery->whereDoesntHave('latestCheckRun')
                                ->orWhereHas('latestCheckRun', static function (Builder $checkRunQuery): void {
                                    $checkRunQuery->where(function (Builder $pendingQuery): void {
                                        $pendingQuery->whereColumn('check_runs.config_version', '!=', 'monitors.config_version')
                                            ->orWhereNull('check_runs.response_visible_text_changed');
                                    });
                                });
                        });

                        return;
                    }

                    $visibleTextChanged = $filters['content_match'] === 'visible_text_changed';
                    $query->whereHas('latestCheckRun', static function (Builder $checkRunQuery) use ($visibleTextChanged): void {
                        $checkRunQuery->where('response_visible_text_changed', $visibleTextChanged)
                            ->whereColumn('check_runs.config_version', 'monitors.config_version');
                    });

                    return;
                }

                $query->whereNotNull('social_platform');

                if ($filters['content_match'] === 'not_checked') {
                    $query->where(function (Builder $matchQuery): void {
                        $matchQuery->whereDoesntHave('latestCheckRun')
                            ->orWhereHas('latestCheckRun', static function (Builder $checkRunQuery): void {
                                $checkRunQuery->where(function (Builder $uncheckedQuery): void {
                                    $uncheckedQuery->whereNull('expected_text_found')
                                        ->orWhereColumn('check_runs.config_version', '!=', 'monitors.config_version');
                                });
                            });
                    });

                    return;
                }

                $expectedTextFound = $filters['content_match'] === 'found';
                $query->whereHas('latestCheckRun', static function (Builder $checkRunQuery) use ($expectedTextFound): void {
                    $checkRunQuery->where('expected_text_found', $expectedTextFound)
                        ->whereColumn('check_runs.config_version', 'monitors.config_version');
                });
            })
            ->when(
                ($filters['http_status'] ?? '') !== '' || ($filters['signal'] ?? '') !== '',
                static function (Builder $query) use ($filters): void {
                    $query->whereHas('latestCheckRun', static function (Builder $checkRunQuery) use ($filters): void {
                        if (($filters['http_status'] ?? '') !== '') {
                            $checkRunQuery->where('normal_status', (int) $filters['http_status']);
                        }

                        if (($filters['signal'] ?? '') !== '') {
                            $checkRunQuery->where(function (Builder $signalQuery) use ($filters): void {
                                $signalQuery->where('error_type', $filters['signal'])
                                    ->orWhere('adsbot_error_type', $filters['signal']);
                            });
                        }
                    });
                },
            )
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

    public function create(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $defaultClient = Client::ensureDefaultFor($user);
        $requestedClientId = $request->query('client_id');
        $selectedClient = is_string($requestedClientId) && ctype_digit($requestedClientId)
            ? Client::query()->find((int) $requestedClientId)
            : null;

        return view('monitors.form', [
            'monitor' => new Monitor,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'selectedClientId' => $selectedClient?->id ?? $defaultClient->id,
            'formAction' => route('monitors.store'),
            'formMethod' => 'POST',
            'previewAction' => route('monitors.probe-preview'),
            'intervalOptions' => self::INTERVAL_OPTIONS,
            'sloTargetOptions' => Monitor::SLO_TARGET_OPTIONS,
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

        $monitorAttributes = [
            'name' => $validated['name'],
            'url' => $normalizedUrl,
            'url_hash' => $fingerprint,
            'label' => $validated['label'] ?: null,
            'client_id' => $validated['client_id'],
            'utm_content' => Monitor::socialPlatformForUrl($normalizedUrl) !== null ? $validated['utm_content'] : null,
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
            'alert_on_visible_text_change' => $validated['alert_on_visible_text_change'],
            'alert_on_response_metadata_change' => $validated['alert_on_response_metadata_change'],
            'response_time_threshold_ms' => $validated['response_time_threshold_ms'],
            'min_response_body_bytes' => $validated['min_response_body_bytes'],
            'max_response_body_bytes' => $validated['max_response_body_bytes'],
            'timeout_seconds' => $validated['timeout_seconds'],
            'confirmation_delay_seconds' => $validated['confirmation_delay_seconds'],
            'interval_seconds' => $validated['interval_seconds'],
            'slo_target_basis_points' => $validated['slo_target_basis_points'],
            'next_check_at' => now(),
        ];
        $monitor = DB::transaction(function () use ($request, $fingerprint, $monitorAttributes): ?Monitor {
            $organization = Organization::query()
                ->where('owner_user_id', $request->user()->workspaceOwnerId())
                ->lockForUpdate()
                ->first();

            if (Monitor::query()->where('url_hash', $fingerprint)->exists()) {
                return null;
            }

            if ($organization !== null && ! $organization->hasAvailableMonitor()) {
                throw ValidationException::withMessages([
                    'name' => '此組織已達監測項目上限，請聯絡平台管理員調整方案。',
                ]);
            }

            return Monitor::query()->create($monitorAttributes);
        });

        if ($monitor === null) {
            $existingMonitor = Monitor::query()->where('url_hash', $fingerprint)->firstOrFail();

            return back()
                ->withErrors(['url' => '此網址已在監測中。'])
                ->with('duplicateMonitorId', $existingMonitor->getKey())
                ->withInput($request->except(['notification_slack_webhook', 'notification_discord_webhook', 'notification_teams_webhook']));
        }

        return redirect()->route('monitors.show', $monitor)->with('status', '已新增監測項目，排程器下次執行時會進行檢查。');
    }

    public function show(
        Request $request,
        Monitor $monitor,
        ResponseMetadataChangeHistory $metadataChangeHistory,
        RedirectChangeHistory $redirectChangeHistory,
        ResponseContentChangeHistory $responseContentChangeHistory,
    ): View {
        $monitor->setAttribute('masked_url', $this->urlMasker->mask($monitor->url));
        $responseMetadataChanges = $metadataChangeHistory->build($monitor);
        $redirectChanges = $redirectChangeHistory->build($monitor);
        $responseContentChanges = $responseContentChangeHistory->build($monitor);
        $latestResponseMetadataRun = $monitor->latestCheckRun;
        $previousResponseMetadataRun = $this->previousResponseMetadataRun($monitor, $latestResponseMetadataRun);
        $responseMetadataComparisonAvailable = $previousResponseMetadataRun !== null && (
            $latestResponseMetadataRun?->response_title !== null
            || $latestResponseMetadataRun?->response_description !== null
            || $latestResponseMetadataRun?->response_image_reference_hash !== null
            || $latestResponseMetadataRun?->response_open_graph_type !== null
            || $latestResponseMetadataRun?->response_canonical_reference_hash !== null
            || $latestResponseMetadataRun?->response_published_at !== null
            || $latestResponseMetadataRun?->response_modified_at !== null
            || $previousResponseMetadataRun->response_title !== null
            || $previousResponseMetadataRun->response_description !== null
            || $previousResponseMetadataRun->response_image_reference_hash !== null
            || $previousResponseMetadataRun->response_open_graph_type !== null
            || $previousResponseMetadataRun->response_canonical_reference_hash !== null
            || $previousResponseMetadataRun->response_published_at !== null
            || $previousResponseMetadataRun->response_modified_at !== null
        );
        $responseMetadataChanged = $responseMetadataComparisonAvailable && (
            $latestResponseMetadataRun?->response_title !== $previousResponseMetadataRun->response_title
            || $latestResponseMetadataRun?->response_description !== $previousResponseMetadataRun->response_description
            || $latestResponseMetadataRun?->response_image_reference_hash !== $previousResponseMetadataRun->response_image_reference_hash
            || $latestResponseMetadataRun?->response_open_graph_type !== $previousResponseMetadataRun->response_open_graph_type
            || $latestResponseMetadataRun?->response_canonical_reference_hash !== $previousResponseMetadataRun->response_canonical_reference_hash
            || $latestResponseMetadataRun?->response_published_at?->format('U.u') !== $previousResponseMetadataRun->response_published_at?->format('U.u')
            || $latestResponseMetadataRun?->response_modified_at?->format('U.u') !== $previousResponseMetadataRun->response_modified_at?->format('U.u')
        );
        $checkRunFilters = $this->validateCheckRunFilters($request);
        $acceptedStatusCodesByVersion = $checkRunFilters['content_match'] === ''
            ? []
            : $this->acceptedStatusCodesByVersion($monitor);
        $runs = $this->filterCheckRuns($monitor->checkRuns()->getQuery(), $checkRunFilters, $acceptedStatusCodesByVersion)
            ->latest('scheduled_at')
            ->paginate(50)
            ->withQueryString();
        $runs->getCollection()->each(function (CheckRun $run): void {
            $run->setAttribute('masked_final_url', $this->maskProbeUrl($run->final_url));
            $run->setAttribute('masked_redirects', $this->maskedRedirectUrls($run->redirects));
        });
        $checkRunExportUrl = route('monitors.checks.export', [
            'monitor' => $monitor,
            ...array_filter($checkRunFilters, static fn (string $value): bool => $value !== ''),
        ]);
        $dnsChanges = $monitor->dnsChanges()
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'monitor_id', 'previous_ips', 'current_ips', 'previous_cnames', 'current_cnames', 'previous_records', 'current_records', 'monitor_host_only', 'detected_at']);
        $latestTlsObservation = $monitor->tlsObservations()->latest('observed_on')->first();
        $latestDomainRegistrationObservation = $monitor->domainRegistrationObservations()->latest('observed_on')->first();
        $incidents = $monitor->incidents()->with(['events.checkRun', 'outboxMessages', 'acknowledgedBy:id,name'])->latest('last_seen_at')->limit(50)->get();
        $asOf = now();
        $publicEngagementHistoryPeriodOptions = $this->socialEngagementHistoryPeriodOptions();
        $publicEngagementHistoryPeriodDays = $this->selectedSocialEngagementHistoryPeriodDays($request);
        $publicEngagementRows = collect();
        $publicEngagementPeriodChanges = [];
        $publicEngagementTrendSeries = [];

        if ($monitor->socialPlatform() !== null) {
            $publicEngagementRuns = $monitor->checkRuns()
                ->where('config_version', $monitor->config_version)
                ->where('status', 'completed')
                ->whereNotIn('source', ['tls_daily', 'domain_daily'])
                ->whereNotNull('response_public_engagement_metrics')
                ->whereBetween('finished_at', [
                    $asOf->copy()->subDays($publicEngagementHistoryPeriodDays),
                    $asOf,
                ])
                ->orderBy('finished_at')
                ->orderBy('id')
                ->get(['id', 'config_version', 'finished_at', 'response_public_engagement_metrics']);
            $previousMetricValues = [];
            $periodFirstValues = [];
            $periodLatestValues = [];
            $periodObservationCounts = array_fill_keys(['views', 'likes', 'comments', 'shares'], 0);
            $publicEngagementRows = $publicEngagementRuns->map(function (CheckRun $run) use (
                &$previousMetricValues,
                &$periodFirstValues,
                &$periodLatestValues,
                &$periodObservationCounts,
            ): array {
                $metrics = is_array($run->response_public_engagement_metrics) ? $run->response_public_engagement_metrics : [];
                $changes = [];
                $changePercentages = [];
                $previousValuesWereZero = [];

                foreach (['views', 'likes', 'comments', 'shares'] as $metric) {
                    $currentValue = isset($metrics[$metric]) ? (int) $metrics[$metric] : null;
                    $previousValue = $previousMetricValues[$metric] ?? null;
                    $changes[$metric] = $currentValue !== null && $previousValue !== null
                        ? $currentValue - $previousValue
                        : null;
                    $changePercentages[$metric] = $currentValue !== null && $previousValue !== null && $previousValue > 0
                        ? round((($currentValue - $previousValue) / $previousValue) * 100, 2)
                        : null;
                    $previousValuesWereZero[$metric] = $currentValue !== null && $previousValue === 0 && $currentValue > 0;

                    if ($currentValue !== null) {
                        $previousMetricValues[$metric] = $currentValue;
                        $periodFirstValues[$metric] ??= $currentValue;
                        $periodLatestValues[$metric] = $currentValue;
                        $periodObservationCounts[$metric]++;
                    }
                }

                return [
                    'run' => $run,
                    'metrics' => $metrics,
                    'changes' => $changes,
                    'change_percentages' => $changePercentages,
                    'previous_values_were_zero' => $previousValuesWereZero,
                ];
            })->values();

            foreach (['views', 'likes', 'comments', 'shares'] as $metric) {
                $startValue = $periodFirstValues[$metric] ?? null;
                $endValue = $periodLatestValues[$metric] ?? null;
                $change = $periodObservationCounts[$metric] >= 2 && $startValue !== null && $endValue !== null
                    ? $endValue - $startValue
                    : null;

                $publicEngagementPeriodChanges[$metric] = [
                    'start_value' => $startValue,
                    'end_value' => $endValue,
                    'observations' => $periodObservationCounts[$metric],
                    'change' => $change,
                    'change_percentage' => $change !== null && $startValue > 0
                        ? round($change / $startValue * 100, 2)
                        : null,
                    'start_value_was_zero' => $startValue === 0 && $endValue !== null && $endValue > 0,
                ];
            }

            $publicEngagementTrendSeries = $this->buildPublicEngagementTrendSeries($publicEngagementRows->all());
        }

        $historyStart = $asOf->copy()->subDays(30);
        $dailyHistoryToday = $asOf->copy()->timezone(self::HISTORY_TIMEZONE)->startOfDay();
        $dailyHistoryFirstDay = $dailyHistoryToday->copy()->subDays(self::DAILY_HISTORY_DAYS - 1);
        $dailyAvailabilityPeriods = [];

        for ($dayOffset = 0; $dayOffset < self::DAILY_HISTORY_DAYS; $dayOffset++) {
            $day = $dailyHistoryFirstDay->copy()->addDays($dayOffset);
            $dailyAvailabilityPeriods[] = [
                'label' => $day->toDateString(),
                'starts_at' => $day->copy()->utc()->getTimestamp(),
                'ends_at' => min($day->copy()->addDay()->utc()->getTimestamp(), $asOf->getTimestamp()),
            ];
        }

        $availabilityData = $this->availabilityReport->build($monitor, $asOf->toImmutable(), $dailyAvailabilityPeriods);
        $historyRuns = $availabilityData['history_runs'];
        $maintenanceWindows = $availabilityData['maintenance_windows'];
        $availabilityStats = $availabilityData['statistics'];
        $dailyAvailabilityStats = collect($availabilityData['daily_statistics'])->keyBy('label');
        $sloBudget = $this->sloBudgetCalculator->calculate(
            collect($availabilityStats)->firstWhere('label', '30 天'),
            $monitor->slo_target_basis_points,
        );

        $checkSuccessStats = collect([
            ['label' => '24 小時', 'starts_at' => $asOf->copy()->subDay()],
            ['label' => '7 天', 'starts_at' => $asOf->copy()->subDays(7)],
            ['label' => '30 天', 'starts_at' => $historyStart],
        ])->map(function (array $period) use ($historyRuns): array {
            $periodRuns = $historyRuns->filter(
                fn (CheckRun $run): bool => $run->finished_at->greaterThanOrEqualTo($period['starts_at']),
            )->reject(static fn (CheckRun $run): bool => $run->hasIndeterminateContentMatch());
            $endedChecks = $periodRuns->count();
            $successfulChecks = $periodRuns->filter(static fn (CheckRun $run): bool => $run->passesMonitorChecks())->count();

            return [
                'label' => $period['label'],
                'success_rate' => $endedChecks === 0 ? null : round(($successfulChecks / $endedChecks) * 100, 2),
                'successful_checks' => $successfulChecks,
                'ended_checks' => $endedChecks,
            ];
        })->all();

        $dailyHistoryStartsAt = $dailyHistoryFirstDay->copy()->utc();
        $dailyHistoryCounts = [];

        foreach ($historyRuns as $run) {
            if ($run->finished_at->lessThan($dailyHistoryStartsAt)
                || $run->finished_at->greaterThan($asOf)
                || $run->hasIndeterminateContentMatch()) {
                continue;
            }

            $finishedDate = $run->finished_at->timezone(self::HISTORY_TIMEZONE)->toDateString();
            $dailyHistoryCounts[$finishedDate] ??= ['total_checks' => 0, 'successful_checks' => 0];
            $dailyHistoryCounts[$finishedDate]['total_checks']++;

            if ($run->passesMonitorChecks()) {
                $dailyHistoryCounts[$finishedDate]['successful_checks']++;
            }
        }

        $dailyCheckHistory = [];
        $dailyAvailabilityHistory = [];

        for ($dayOffset = 0; $dayOffset < self::DAILY_HISTORY_DAYS; $dayOffset++) {
            $day = $dailyHistoryFirstDay->copy()->addDays($dayOffset);
            $date = $day->toDateString();
            $counts = $dailyHistoryCounts[$date] ?? ['total_checks' => 0, 'successful_checks' => 0];
            $successRate = $counts['total_checks'] === 0
                ? null
                : round(($counts['successful_checks'] / $counts['total_checks']) * 100, 2);
            $state = $successRate === null
                ? 'unknown'
                : ($successRate >= 100 ? 'healthy' : ($counts['successful_checks'] === 0 ? 'issue' : 'warning'));
            $accessibleLabel = $successRate === null
                ? $day->toDateString().' 台灣時間：沒有可判定的排程檢查。'
                : $day->toDateString().' 台灣時間：成功 '.$counts['successful_checks'].'／'.$counts['total_checks'].' 次，成功率 '.number_format($successRate, 2).'%。';

            $dailyCheckHistory[] = [
                'date' => $date,
                'display_date' => $day->format('n/j'),
                ...$counts,
                'success_rate' => $successRate,
                'state' => $state,
                'accessible_label' => $accessibleLabel,
            ];

            $availability = $dailyAvailabilityStats->get($date);
            $availabilityRate = $availability['availability_rate'] ?? null;
            $coverageRate = $availability['coverage_rate'] ?? null;
            $availabilityState = match (true) {
                $availabilityRate === null => 'unknown',
                $availabilityRate <= 0.0 => 'issue',
                $availabilityRate < 100.0 => 'warning',
                $coverageRate === null || $coverageRate < 90.0 => 'unknown',
                default => 'healthy',
            };
            $availabilityAccessibleLabel = $availabilityRate === null
                ? $date.' 台灣時間：沒有可用的時間觀測。'
                : $date.' 台灣時間：估算可用率 '.number_format($availabilityRate, 2).'%，資料覆蓋率 '
                    .($coverageRate === null ? '無' : number_format($coverageRate, 2).'%')
                    .'；觀測 '.number_format($availability['observed_minutes'], 1)
                    .' 分鐘，可用 '.number_format($availability['up_minutes'], 1)
                    .' 分鐘，不可用 '.number_format($availability['unavailable_minutes'], 1)
                    .' 分鐘，維護排除 '.number_format($availability['maintenance_excluded_minutes'], 1).' 分鐘。';

            $dailyAvailabilityHistory[] = [
                'date' => $date,
                'display_date' => $day->format('n/j'),
                'availability_rate' => $availabilityRate,
                'coverage_rate' => $coverageRate,
                'up_minutes' => $availability['up_minutes'] ?? 0.0,
                'unavailable_minutes' => $availability['unavailable_minutes'] ?? 0.0,
                'observed_minutes' => $availability['observed_minutes'] ?? 0.0,
                'eligible_minutes' => $availability['eligible_minutes'] ?? 0.0,
                'maintenance_excluded_minutes' => $availability['maintenance_excluded_minutes'] ?? 0.0,
                'state' => $availabilityState,
                'accessible_label' => $availabilityAccessibleLabel,
            ];
        }

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

        $responseBodySizeRuns = $historyRuns->filter(
            fn (CheckRun $run): bool => $run->finished_at->greaterThanOrEqualTo($responseTimeStart)
                && $run->response_body_bytes !== null,
        )->values();
        $responseBodySizeValues = $responseBodySizeRuns->pluck('response_body_bytes')->sort()->values();
        $responseBodySizeP95Index = (int) ceil($responseBodySizeValues->count() * 0.95) - 1;
        $maximumResponseBodyBytes = (int) ($responseBodySizeRuns->max('response_body_bytes') ?? 0);
        $responseBodySizeScaleMaxBytes = max(1024, (int) (ceil($maximumResponseBodyBytes / 1024) * 1024));
        $responseBodySizePoints = $responseBodySizeRuns->map(function (CheckRun $run) use ($responseTimeStart, $responseBodySizeScaleMaxBytes): array {
            $elapsedSeconds = max(0, $run->finished_at->getTimestamp() - $responseTimeStart->getTimestamp());
            $bodyBytes = (int) $run->response_body_bytes;
            $x = 48 + (min(1, $elapsedSeconds / 86400) * 522);
            $y = 144 - (($bodyBytes / $responseBodySizeScaleMaxBytes) * 116);

            return [
                'x' => number_format($x, 2, '.', ''),
                'y' => number_format($y, 2, '.', ''),
                'coordinate' => number_format($x, 2, '.', '').','.number_format($y, 2, '.', ''),
                'finished_at' => $run->finished_at,
                'normal_status' => $run->normal_status,
                'body_bytes' => $bodyBytes,
                'error_type' => $run->error_type,
            ];
        });
        $responseBodySizeSegments = [];
        $currentResponseBodySizeSegment = [];
        $previousResponseBodySizeFinishedAt = null;

        foreach ($responseBodySizePoints as $point) {
            $finishedAtTimestamp = $point['finished_at']->getTimestamp();

            if ($previousResponseBodySizeFinishedAt !== null && ($finishedAtTimestamp - $previousResponseBodySizeFinishedAt) > $maximumGapSeconds) {
                if (count($currentResponseBodySizeSegment) > 1) {
                    $responseBodySizeSegments[] = implode(' ', $currentResponseBodySizeSegment);
                }

                $currentResponseBodySizeSegment = [];
            }

            $currentResponseBodySizeSegment[] = $point['coordinate'];
            $previousResponseBodySizeFinishedAt = $finishedAtTimestamp;
        }

        if (count($currentResponseBodySizeSegment) > 1) {
            $responseBodySizeSegments[] = implode(' ', $currentResponseBodySizeSegment);
        }

        $responseBodySize = [
            'count' => $responseBodySizeRuns->count(),
            'average_bytes' => $responseBodySizeRuns->avg('response_body_bytes'),
            'minimum_bytes' => $responseBodySizeRuns->min('response_body_bytes'),
            'maximum_bytes' => $responseBodySizeRuns->max('response_body_bytes'),
            'p95_bytes' => $responseBodySizeP95Index < 0 ? null : $responseBodySizeValues->get($responseBodySizeP95Index),
            'scale_max_bytes' => $responseBodySizeScaleMaxBytes,
            'points' => $responseBodySizePoints->all(),
            'segments' => $responseBodySizeSegments,
        ];

        return view('monitors.show', compact(
            'monitor',
            'previousResponseMetadataRun',
            'responseMetadataComparisonAvailable',
            'responseMetadataChanged',
            'responseMetadataChanges',
            'publicEngagementRows',
            'publicEngagementPeriodChanges',
            'publicEngagementTrendSeries',
            'publicEngagementHistoryPeriodDays',
            'publicEngagementHistoryPeriodOptions',
            'redirectChanges',
            'responseContentChanges',
            'runs',
            'dnsChanges',
            'latestTlsObservation',
            'latestDomainRegistrationObservation',
            'incidents',
            'checkSuccessStats',
            'dailyCheckHistory',
            'dailyAvailabilityHistory',
            'availabilityStats',
            'sloBudget',
            'httpRecoveryStats',
            'responseTime',
            'responseBodySize',
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

    private function previousResponseMetadataRun(Monitor $monitor, ?CheckRun $latestRun): ?CheckRun
    {
        if ($latestRun === null || $latestRun->status !== 'completed') {
            return null;
        }

        return $monitor->checkRuns()
            ->where('config_version', $latestRun->config_version)
            ->where('status', 'completed')
            ->whereNotIn('source', ['tls_daily', 'domain_daily'])
            ->where(function (Builder $query) use ($latestRun): void {
                $query->where('scheduled_at', '<', $latestRun->scheduled_at)
                    ->orWhere(function (Builder $sameTimeQuery) use ($latestRun): void {
                        $sameTimeQuery->where('scheduled_at', $latestRun->scheduled_at)
                            ->where('id', '<', $latestRun->id);
                    });
            })
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->first(['id', 'scheduled_at', 'response_title', 'response_description', 'response_image_reference_hash', 'response_open_graph_type', 'response_canonical_reference_hash', 'response_published_at', 'response_modified_at']);
    }

    public function edit(Request $request, Monitor $monitor): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $defaultClient = Client::ensureDefaultFor($user);
        $monitor->setAttribute('masked_url', $this->urlMasker->mask($monitor->url));

        return view('monitors.form', [
            'monitor' => $monitor,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'selectedClientId' => $monitor->client_id ?? $defaultClient->id,
            'formAction' => route('monitors.update', $monitor),
            'formMethod' => 'PUT',
            'previewAction' => route('monitors.probe-preview.update', $monitor),
            'intervalOptions' => self::INTERVAL_OPTIONS,
            'sloTargetOptions' => Monitor::SLO_TARGET_OPTIONS,
        ]);
    }

    public function update(Request $request, Monitor $monitor): RedirectResponse
    {
        $validated = $this->validateMonitor($request, $monitor);
        $inputUrl = trim((string) ($validated['url'] ?? ''));
        $normalizedUrl = $inputUrl === '' ? $monitor->url : $this->validateProbeUrl($inputUrl, $validated['allowed_redirect_hosts']);
        $this->validateSocialPostExpectation($normalizedUrl, $validated);
        $fingerprint = Monitor::fingerprintUrl($normalizedUrl);

        $existingMonitor = Monitor::query()
            ->where('url_hash', $fingerprint)
            ->whereKeyNot($monitor->id)
            ->first();

        if ($existingMonitor !== null) {
            return back()
                ->withErrors(['url' => '此網址已在監測中。'])
                ->with('duplicateMonitorId', $existingMonitor->getKey())
                ->withInput($request->except(['notification_slack_webhook', 'notification_discord_webhook', 'notification_teams_webhook']));
        }

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $clientId = $validated['client_id'] ?? $monitor->client_id ?? Client::ensureDefaultFor($user)->id;

        $monitor = DB::transaction(function () use ($monitor, $validated, $normalizedUrl, $fingerprint, $clientId): Monitor {
            $lockedMonitor = Monitor::query()->lockForUpdate()->findOrFail($monitor->id);
            $this->persistMonitorConfigurationVersion($lockedMonitor, [
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
                'alert_on_visible_text_change' => $validated['alert_on_visible_text_change'],
                'alert_on_response_metadata_change' => $validated['alert_on_response_metadata_change'],
                'response_time_threshold_ms' => $validated['response_time_threshold_ms'],
                'min_response_body_bytes' => $validated['min_response_body_bytes'],
                'max_response_body_bytes' => $validated['max_response_body_bytes'],
                'timeout_seconds' => $validated['timeout_seconds'],
                'confirmation_delay_seconds' => $validated['confirmation_delay_seconds'],
                'interval_seconds' => $validated['interval_seconds'],
                'slo_target_basis_points' => $validated['slo_target_basis_points'],
                'allowed_redirect_hosts' => $validated['allowed_redirect_hosts'],
            ]);

            $lockedMonitor->forceFill([
                'client_id' => $clientId,
                'utm_content' => Monitor::socialPlatformForUrl($normalizedUrl) !== null ? $validated['utm_content'] : null,
            ])->save();

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

    public function bulkStatus(Request $request, IncidentManager $incidents, RunScheduler $scheduler): RedirectResponse|StreamedResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:pause,resume,label,interval,check,delete,export'],
            'label' => ['nullable', 'string', 'max:1200'],
            'interval_seconds' => ['exclude_unless:action,interval', 'required', 'integer', 'in:'.implode(',', Monitor::SUPPORTED_INTERVAL_SECONDS)],
            'confirm_delete' => ['exclude_unless:action,delete', 'accepted'],
            'monitor_ids' => ['required', 'array', 'min:1', 'max:50'],
            'monitor_ids.*' => ['required', 'integer', 'distinct', 'exists:monitors,id'],
        ], [
            'action.required' => '請選擇要執行的批次操作。',
            'action.in' => '請選擇有效的批次操作。',
            'label.max' => '標籤欄位不得超過 1200 個字元。',
            'interval_seconds.required' => '選擇此批次操作時，請指定檢查頻率。',
            'interval_seconds.integer' => '檢查頻率必須是秒數。',
            'interval_seconds.in' => '請選擇支援的檢查頻率。',
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
        $label = $action === 'label'
            ? $this->normalizeTagInput($validated['label'] ?? null)
            : null;
        $intervalSeconds = array_key_exists('interval_seconds', $validated)
            ? (int) $validated['interval_seconds']
            : null;

        if ($action === 'export') {
            $query = Monitor::query()->whereIn('id', $monitorIds);

            if ((clone $query)->count() !== count($monitorIds)) {
                throw ValidationException::withMessages([
                    'monitor_ids' => '部分監測項目已不存在，請重新載入清單後再試。',
                ]);
            }

            return $this->streamMonitorCsv($query);
        }

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

        $changedCount = DB::transaction(function () use ($monitorIds, $action, $label, $intervalSeconds, $incidents): int {
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
                    if ($monitor->tagNames() === Monitor::parseTags($label)) {
                        continue;
                    }

                    $monitor->forceFill(['label' => $label])->save();
                    $changedCount++;
                }

                return $changedCount;
            }

            if ($action === 'interval' && $intervalSeconds !== null) {
                foreach ($monitors as $monitor) {
                    if ($monitor->interval_seconds === $intervalSeconds) {
                        continue;
                    }

                    $this->persistMonitorConfigurationVersion($monitor, ['interval_seconds' => $intervalSeconds]);
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

        if ($action === 'interval' && $intervalSeconds !== null) {
            $intervalLabel = self::INTERVAL_OPTIONS[$intervalSeconds];

            if ($changedCount === 0) {
                return back()->with('status', '所選監測項目皆已是「'.$intervalLabel.'」，未變更。');
            }

            $statusMessage = '已調整 '.$changedCount.' 個監測項目的檢查頻率為「'.$intervalLabel.'」。';

            if ($unchangedCount > 0) {
                $statusMessage .= ' '.$unchangedCount.' 個項目已是此頻率，未變更。';
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
                'response_content_type',
                'response_body_bytes',
                'min_response_body_bytes',
                'max_response_body_bytes',
                'adsbot_status',
                'http_elapsed_ms',
                'total_elapsed_ms',
                'error_type',
                'adsbot_error_type',
                'expected_text_found',
                'response_visible_text_changed',
                'expected_response_header_found',
                'response_title',
                'response_description',
                'public_views',
                'public_likes',
                'public_comments',
                'public_shares',
                'public_views_source',
                'public_likes_source',
                'public_comments_source',
                'public_shares_source',
                'public_metrics_source',
                'response_image_reference_present',
                'response_og_type',
                'response_canonical_reference_present',
                'response_published_at_utc',
                'response_modified_at_utc',
                'response_robots_directives_json',
                'expected_text_mode',
                'tls_expires_at_utc',
                'final_url',
                'redirect_chain',
            ], ',', '"', '');

            $acceptedStatusCodesByVersion = $checkRunFilters['content_match'] === ''
                ? []
                : $this->acceptedStatusCodesByVersion($monitor);

            $this->filterCheckRuns($monitor->checkRuns()->getQuery(), $checkRunFilters, $acceptedStatusCodesByVersion)
                ->orderBy('scheduled_at')
                ->orderBy('id')
                ->lazy(500)
                ->each(function (CheckRun $run) use ($stream): void {
                    $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
                    $expectedTextMatchResult = $run->expectedTextMatchResult();
                    $expectedResponseHeaderMatchResult = $run->expectedResponseHeaderMatchResult();
                    $redirectUrls = $this->maskedRedirectUrls($run->redirects);
                    $publicMetrics = is_array($run->response_public_engagement_metrics)
                        ? $run->response_public_engagement_metrics
                        : [];
                    $fields = [
                        (string) $run->id,
                        $run->scheduled_at->utc()->toIso8601String(),
                        $run->started_at?->utc()->toIso8601String() ?? '',
                        $run->finished_at?->utc()->toIso8601String() ?? '',
                        $run->source,
                        $run->status,
                        $run->normal_status === null ? '' : (string) $run->normal_status,
                        (string) $run->response_content_type,
                        $run->response_body_bytes === null ? '' : (string) $run->response_body_bytes,
                        isset($snapshot['min_response_body_bytes']) ? (string) $snapshot['min_response_body_bytes'] : '',
                        isset($snapshot['max_response_body_bytes']) ? (string) $snapshot['max_response_body_bytes'] : '',
                        $run->adsbot_status === null ? '' : (string) $run->adsbot_status,
                        $run->normal_elapsed_ms === null ? '' : (string) $run->normal_elapsed_ms,
                        $run->elapsed_ms === null ? '' : (string) $run->elapsed_ms,
                        (string) $run->error_type,
                        (string) $run->adsbot_error_type,
                        $expectedTextMatchResult === null ? '' : ($expectedTextMatchResult ? '1' : '0'),
                        $run->response_visible_text_changed === null ? '' : ($run->response_visible_text_changed ? '1' : '0'),
                        $expectedResponseHeaderMatchResult === null ? '' : ($expectedResponseHeaderMatchResult ? '1' : '0'),
                        (string) $run->response_title,
                        (string) $run->response_description,
                        ...array_map(
                            static fn (string $metric): string => isset($publicMetrics[$metric]) ? (string) $publicMetrics[$metric] : '',
                            ['views', 'likes', 'comments', 'shares'],
                        ),
                        ...$this->publicMetricSourceValues($publicMetrics),
                        (string) ($publicMetrics['source'] ?? ''),
                        $run->response_image_reference_hash === null ? '0' : '1',
                        (string) $run->response_open_graph_type,
                        $run->response_canonical_reference_hash === null ? '0' : '1',
                        $run->response_published_at?->utc()->toIso8601String() ?? '',
                        $run->response_modified_at?->utc()->toIso8601String() ?? '',
                        json_encode($run->response_robots_directives, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
                        $run->expectedTextMode(),
                        $run->tls_expires_at?->utc()->toIso8601String() ?? '',
                        $this->maskProbeUrl($run->final_url) ?? '',
                        implode(' -> ', $redirectUrls),
                    ];

                    fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
                });

            fclose($stream);
        }, 'check-history-'.$monitor->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportSocialEngagement(Request $request): StreamedResponse
    {
        $this->selectClientContext($request);
        $platform = $this->socialEngagementPlatformFilter($request);
        $tagFilter = $this->socialEngagementTagFilter($request);

        return response()->streamDownload(function () use ($platform, $tagFilter): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'monitor_name',
                'platform',
                'url',
                'tags',
                'public_views',
                'public_likes',
                'public_comments',
                'public_shares',
                'public_views_source',
                'public_likes_source',
                'public_comments_source',
                'public_shares_source',
                'public_interaction_total',
                'interaction_metric_scope',
                'public_engagement_rate_percent',
                'metrics_source',
                'collected_at_utc',
                'metrics_capture_status',
                'latest_check_http_status',
                'latest_check_error_type',
                'latest_check_finished_at_utc',
            ], ',', '"', '');

            $monitorsQuery = Monitor::query()->whereNotNull('social_platform');

            if ($platform !== null) {
                $monitorsQuery->where('social_platform', $platform);
            }

            $this->applySocialEngagementTagFilter($monitorsQuery, $tagFilter);

            $monitorsQuery
                ->with(['latestCheckRun', 'latestPublicEngagementCheckRun'])
                ->orderBy('id')
                ->lazyById(250)
                ->each(function (Monitor $monitor) use ($stream): void {
                    $engagementRun = $monitor->latestPublicEngagementCheckRun;

                    if ($engagementRun?->config_version !== $monitor->config_version) {
                        $engagementRun = null;
                    }

                    $latestCheckRun = $monitor->latestCheckRun;
                    $metrics = $engagementRun?->response_public_engagement_metrics ?? [];
                    $interactionTotal = Monitor::publicEngagementInteractionTotal($metrics, $monitor->social_platform);
                    $metricsCaptureStatus = match (true) {
                        $engagementRun === null => 'unavailable',
                        $latestCheckRun?->id === $engagementRun->id => 'current',
                        default => 'previous',
                    };
                    $engagementRate = null;

                    if ($interactionTotal !== null
                        && is_numeric($metrics['views'] ?? null)
                        && (int) $metrics['views'] > 0) {
                        $engagementRate = $interactionTotal / (int) $metrics['views'] * 100;
                    }

                    $fields = [
                        $monitor->name,
                        $monitor->socialPlatform() ?? '',
                        $this->urlMasker->mask($monitor->url),
                        $monitor->tagInputValue(),
                        ...array_map(
                            static fn (string $metric): string => isset($metrics[$metric]) ? (string) $metrics[$metric] : '',
                            ['views', 'likes', 'comments', 'shares'],
                        ),
                        ...$this->publicMetricSourceValues($metrics),
                        $interactionTotal === null ? '' : (string) $interactionTotal,
                        implode('+', Monitor::publicEngagementInteractionMetricKeys($monitor->social_platform)),
                        $engagementRate === null ? '' : number_format($engagementRate, 2, '.', ''),
                        (string) ($metrics['source'] ?? ''),
                        $engagementRun?->finished_at?->utc()->toIso8601String() ?? '',
                        $metricsCaptureStatus,
                        $latestCheckRun?->normal_status === null ? '' : (string) $latestCheckRun->normal_status,
                        (string) ($latestCheckRun?->error_type ?? ''),
                        $latestCheckRun?->finished_at?->utc()->toIso8601String() ?? '',
                    ];

                    fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
                });

            fclose($stream);
        }, 'social-engagement-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportSocialEngagementHistory(Request $request): StreamedResponse
    {
        return $this->streamSocialEngagementHistory($request);
    }

    public function exportMonitorSocialEngagementHistory(Request $request, Monitor $monitor): StreamedResponse
    {
        abort_if($monitor->socialPlatform() === null, 404);

        return $this->streamSocialEngagementHistory($request, $monitor);
    }

    private function streamSocialEngagementHistory(Request $request, ?Monitor $monitor = null): StreamedResponse
    {
        $this->selectClientContext($request);
        $platform = $monitor?->social_platform ?? $this->socialEngagementPlatformFilter($request);
        $tagFilter = $this->socialEngagementTagFilter($request);
        $periodDays = $this->selectedSocialEngagementHistoryPeriodDays($request);
        $asOf = now()->toImmutable();
        $startsAt = $asOf->subDays($periodDays);
        $filename = $monitor === null
            ? 'social-engagement-history-'.$periodDays.'-days-'.now()->format('Y-m-d').'.csv'
            : 'social-engagement-history-monitor-'.$monitor->id.'-'.$periodDays.'-days-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($asOf, $startsAt, $platform, $monitor, $tagFilter): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'check_run_id',
                'monitor_name',
                'platform',
                'url',
                'tags',
                'collected_at_utc',
                'public_views',
                'public_likes',
                'public_comments',
                'public_shares',
                'public_views_source',
                'public_likes_source',
                'public_comments_source',
                'public_shares_source',
                'public_interaction_total',
                'interaction_metric_scope',
                'public_engagement_rate_percent',
                'metrics_source',
            ], ',', '"', '');

            CheckRun::query()
                ->select(['id', 'monitor_id', 'config_version', 'finished_at', 'response_public_engagement_metrics'])
                ->where('status', 'completed')
                ->whereNotNull('finished_at')
                ->whereBetween('finished_at', [$startsAt, $asOf])
                ->whereNotNull('response_public_engagement_metrics')
                ->whereNotIn('source', ['tls_daily', 'domain_daily'])
                ->whereHas('monitor', function (Builder $query) use ($platform, $monitor, $tagFilter): void {
                    $query->whereNotNull('social_platform')
                        ->whereColumn('check_runs.config_version', 'monitors.config_version');

                    if ($platform !== null) {
                        $query->where('social_platform', $platform);
                    }

                    if ($monitor !== null) {
                        $query->whereKey($monitor->getKey());
                    }

                    $this->applySocialEngagementTagFilter($query, $tagFilter);
                })
                ->with('monitor')
                ->orderBy('id')
                ->lazyById(500)
                ->each(function (CheckRun $run) use ($stream): void {
                    $monitor = $run->monitor;
                    $metrics = $run->response_public_engagement_metrics ?? [];
                    $interactionTotal = Monitor::publicEngagementInteractionTotal($metrics, $monitor->social_platform);
                    $engagementRate = null;

                    if ($interactionTotal !== null
                        && is_numeric($metrics['views'] ?? null)
                        && (int) $metrics['views'] > 0) {
                        $engagementRate = $interactionTotal / (int) $metrics['views'] * 100;
                    }

                    $fields = [
                        (string) $run->id,
                        $monitor->name,
                        $monitor->socialPlatform() ?? '',
                        $this->urlMasker->mask($monitor->url),
                        $monitor->tagInputValue(),
                        $run->finished_at?->utc()->toIso8601String() ?? '',
                        ...array_map(
                            static fn (string $metric): string => isset($metrics[$metric]) ? (string) $metrics[$metric] : '',
                            ['views', 'likes', 'comments', 'shares'],
                        ),
                        ...$this->publicMetricSourceValues($metrics),
                        $interactionTotal === null ? '' : (string) $interactionTotal,
                        implode('+', Monitor::publicEngagementInteractionMetricKeys($monitor->social_platform)),
                        $engagementRate === null ? '' : number_format($engagementRate, 2, '.', ''),
                        (string) ($metrics['source'] ?? ''),
                    ];

                    fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
                });

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<int, string> */
    private function socialEngagementHistoryPeriodOptions(): array
    {
        $retentionDays = max(1, (int) config('monitoring.retention_days'));
        $availablePeriods = array_filter(
            self::SOCIAL_ENGAGEMENT_HISTORY_PERIODS,
            static fn (string $label, int $days): bool => $days <= $retentionDays,
            ARRAY_FILTER_USE_BOTH,
        );

        return $availablePeriods !== []
            ? $availablePeriods
            : [$retentionDays => '近 '.$retentionDays.' 天'];
    }

    private function selectedSocialEngagementHistoryPeriodDays(Request $request): int
    {
        $periodOptions = $this->socialEngagementHistoryPeriodOptions();
        $requestedPeriod = $request->query('engagement_period');

        if (is_string($requestedPeriod)
            && ctype_digit($requestedPeriod)
            && array_key_exists((int) $requestedPeriod, $periodOptions)) {
            return (int) $requestedPeriod;
        }

        return array_key_exists(30, $periodOptions)
            ? 30
            : (int) array_key_first($periodOptions);
    }

    /**
     * @param  list<array{run: CheckRun, metrics: array<string, mixed>, changes: array<string, int|null>, change_percentages: array<string, float|null>, previous_values_were_zero: array<string, bool>}>  $engagementRows
     * @return array<string, array{points: list<array{x: float, y: float, value: int, timestamp: int}>, polyline_points: string, observation_count: int, minimum: int|null, maximum: int|null, first_value: int|null, last_value: int|null, first_at: string|null, last_at: string|null, first_label: string|null, last_label: string|null}>
     */
    private function buildPublicEngagementTrendSeries(array $engagementRows): array
    {
        $seriesByMetric = [];

        foreach (['views', 'likes', 'comments', 'shares'] as $metric) {
            $observations = [];

            foreach ($engagementRows as $engagementRow) {
                $value = $engagementRow['metrics'][$metric] ?? null;

                if ($value === null) {
                    continue;
                }

                $observations[] = [
                    'value' => (int) $value,
                    'timestamp' => $engagementRow['run']->finished_at->getTimestamp(),
                    'finished_at' => $engagementRow['run']->finished_at,
                ];
            }

            $observationCount = count($observations);
            $values = array_column($observations, 'value');
            $minimum = $values === [] ? null : min($values);
            $maximum = $values === [] ? null : max($values);
            $sampledObservations = $observations;

            if ($observationCount > self::MAX_PUBLIC_ENGAGEMENT_TREND_POINTS) {
                $sampledIndexes = [];
                $regularPointCount = self::MAX_PUBLIC_ENGAGEMENT_TREND_POINTS - 2;

                for ($sampleIndex = 0; $sampleIndex < $regularPointCount; $sampleIndex++) {
                    $observationIndex = (int) round($sampleIndex * ($observationCount - 1) / ($regularPointCount - 1));
                    $sampledIndexes[$observationIndex] = true;
                }

                $sampledIndexes[array_search($minimum, $values, true)] = true;
                $sampledIndexes[array_search($maximum, $values, true)] = true;
                ksort($sampledIndexes);
                $sampledObservations = [];

                foreach (array_keys($sampledIndexes) as $observationIndex) {
                    $sampledObservations[] = $observations[$observationIndex];
                }
            }

            $sampledPointCount = count($sampledObservations);
            $firstTimestamp = $sampledObservations[0]['timestamp'] ?? null;
            $lastTimestamp = $sampledObservations[$sampledPointCount - 1]['timestamp'] ?? null;
            $timeSpan = $firstTimestamp !== null && $lastTimestamp !== null
                ? $lastTimestamp - $firstTimestamp
                : 0;
            $valueSpan = $minimum !== null && $maximum !== null ? $maximum - $minimum : 0;
            $points = [];

            foreach ($sampledObservations as $pointIndex => $observation) {
                $xRatio = $timeSpan > 0
                    ? ($observation['timestamp'] - $firstTimestamp) / $timeSpan
                    : ($sampledPointCount > 1 ? $pointIndex / ($sampledPointCount - 1) : 0.5);
                $yRatio = $valueSpan > 0
                    ? ($observation['value'] - $minimum) / $valueSpan
                    : 0.5;

                $points[] = [
                    'x' => round(4 + ($xRatio * 172), 2),
                    'y' => round(46 - ($yRatio * 40), 2),
                    'value' => $observation['value'],
                    'timestamp' => $observation['timestamp'],
                ];
            }

            $firstObservation = $observations[0] ?? null;
            $lastObservation = $observations[$observationCount - 1] ?? null;
            $polylinePoints = implode(' ', array_map(
                static fn (array $point): string => number_format($point['x'], 2, '.', '').','.number_format($point['y'], 2, '.', ''),
                $points,
            ));

            $seriesByMetric[$metric] = [
                'points' => $points,
                'polyline_points' => $polylinePoints,
                'observation_count' => $observationCount,
                'minimum' => $minimum,
                'maximum' => $maximum,
                'first_value' => $firstObservation['value'] ?? null,
                'last_value' => $lastObservation['value'] ?? null,
                'first_at' => ($firstObservation['finished_at'] ?? null)?->toIso8601String(),
                'last_at' => ($lastObservation['finished_at'] ?? null)?->toIso8601String(),
                'first_label' => ($firstObservation['finished_at'] ?? null)?->copy()->timezone(self::HISTORY_TIMEZONE)->format('n/j H:i'),
                'last_label' => ($lastObservation['finished_at'] ?? null)?->copy()->timezone(self::HISTORY_TIMEZONE)->format('n/j H:i'),
            ];
        }

        return $seriesByMetric;
    }

    private function socialEngagementPlatformFilter(Request $request): ?string
    {
        $platform = $request->query('engagement_platform');

        return is_string($platform) && in_array($platform, self::SOCIAL_ENGAGEMENT_PLATFORMS, true)
            ? $platform
            : null;
    }

    private function selectClientContext(Request $request): void
    {
        $rawClientId = $request->query('client_id');

        if ($rawClientId === null || $rawClientId === '') {
            ClientSelectionContext::select($request, null);

            return;
        }

        abort_unless(is_string($rawClientId) && ctype_digit($rawClientId) && (int) $rawClientId > 0, 404);
        $clientId = (int) $rawClientId;
        abort_unless(Client::query()->whereKey($clientId)->exists(), 404);

        ClientSelectionContext::select($request, $clientId);
    }

    /** @return array{tag: string|null, untagged: bool} */
    private function socialEngagementTagFilter(Request $request): array
    {
        $tagToken = $request->query('engagement_tag');

        if ($tagToken === 'unlabeled') {
            return ['tag' => null, 'untagged' => true];
        }

        if (is_string($tagToken) && str_starts_with($tagToken, 'tag:')) {
            $tag = substr($tagToken, 4);

            if ($tag !== '' && mb_strlen($tag) <= 100) {
                return ['tag' => $tag, 'untagged' => false];
            }
        }

        return ['tag' => null, 'untagged' => false];
    }

    /** @param array{tag: string|null, untagged: bool} $tagFilter */
    private function applySocialEngagementTagFilter(Builder $query, array $tagFilter): void
    {
        if ($tagFilter['tag'] !== null) {
            $query->where(function (Builder $tagQuery) use ($tagFilter): void {
                $tagQuery->whereJsonContains('tags', $tagFilter['tag'])
                    ->orWhere('label', $tagFilter['tag']);
            });

            return;
        }

        if ($tagFilter['untagged']) {
            $query->whereNull('label');
        }
    }

    public function exportAvailability(Monitor $monitor): StreamedResponse
    {
        $asOf = now()->toImmutable();
        $availabilityStats = $this->availabilityReport->build($monitor, $asOf)['statistics'];

        return response()->streamDownload(function () use ($monitor, $asOf, $availabilityStats): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'monitor_name',
                'period',
                'as_of_utc',
                'availability_percent',
                'slo_target_percent',
                'slo_allowed_downtime_minutes',
                'slo_observed_downtime_minutes',
                'slo_remaining_error_budget_minutes',
                'slo_error_budget_used_percent',
                'coverage_percent',
                'estimated_up_minutes',
                'estimated_down_minutes',
                'observed_minutes',
                'eligible_minutes',
                'maintenance_excluded_minutes',
                'legacy_interval_fallback',
            ], ',', '"', '');

            foreach ($availabilityStats as $statistic) {
                $unavailableSeconds = max(0, $statistic['observed_seconds'] - $statistic['up_seconds']);
                $fields = [
                    $monitor->name,
                    $statistic['label'],
                    $asOf->utc()->toIso8601String(),
                    $statistic['availability_rate'] === null ? '' : number_format($statistic['availability_rate'], 2, '.', ''),
                    ...$this->sloBudgetCsvFields($statistic, $monitor),
                    $statistic['coverage_rate'] === null ? '' : number_format($statistic['coverage_rate'], 2, '.', ''),
                    number_format($statistic['up_minutes'], 2, '.', ''),
                    number_format($unavailableSeconds / 60, 2, '.', ''),
                    number_format($statistic['observed_minutes'], 2, '.', ''),
                    number_format($statistic['eligible_minutes'], 2, '.', ''),
                    number_format($statistic['maintenance_excluded_minutes'], 2, '.', ''),
                    $statistic['legacy_interval_fallback'] ? '1' : '0',
                ];

                fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
            }

            fclose($stream);
        }, 'availability-report-'.$monitor->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array{eligible_minutes: float, observed_minutes: float, unavailable_minutes: float, coverage_rate: ?float, eligible_seconds?: int, observed_seconds?: int, up_seconds?: int}  $statistic
     * @return list<string>
     */
    private function sloBudgetCsvFields(array $statistic, Monitor $monitor): array
    {
        $budget = $this->sloBudgetCalculator->calculate($statistic, $monitor->slo_target_basis_points);

        return [
            number_format($budget['target_percent'], 2, '.', ''),
            number_format($budget['allowed_downtime_minutes'], 2, '.', ''),
            $budget['observed_downtime_minutes'] === null ? '' : number_format($budget['observed_downtime_minutes'], 2, '.', ''),
            $budget['remaining_error_budget_minutes'] === null ? '' : number_format($budget['remaining_error_budget_minutes'], 2, '.', ''),
            $budget['error_budget_used_percent'] === null ? '' : number_format($budget['error_budget_used_percent'], 2, '.', ''),
        ];
    }

    public function exportFleetAvailability(FleetAvailabilityReport $fleetAvailabilityReport): StreamedResponse
    {
        $asOf = now()->toImmutable();

        return response()->streamDownload(function () use ($asOf, $fleetAvailabilityReport): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'monitor_id',
                'monitor_name',
                'period',
                'as_of_utc',
                'availability_percent',
                'slo_target_percent',
                'slo_allowed_downtime_minutes',
                'slo_observed_downtime_minutes',
                'slo_remaining_error_budget_minutes',
                'slo_error_budget_used_percent',
                'coverage_percent',
                'estimated_up_minutes',
                'estimated_down_minutes',
                'observed_minutes',
                'eligible_minutes',
                'maintenance_excluded_minutes',
                'legacy_interval_fallback',
            ], ',', '"', '');

            foreach ($fleetAvailabilityReport->monitorStatistics($asOf) as $monitorStatistics) {
                foreach ($monitorStatistics['statistics'] as $statistic) {
                    $unavailableSeconds = max(0, $statistic['observed_seconds'] - $statistic['up_seconds']);
                    $fields = [
                        (string) $monitorStatistics['monitor']->id,
                        $monitorStatistics['monitor']->name,
                        $statistic['label'],
                        $asOf->utc()->toIso8601String(),
                        $statistic['availability_rate'] === null ? '' : number_format($statistic['availability_rate'], 2, '.', ''),
                        ...$this->sloBudgetCsvFields($statistic, $monitorStatistics['monitor']),
                        $statistic['coverage_rate'] === null ? '' : number_format($statistic['coverage_rate'], 2, '.', ''),
                        number_format($statistic['up_seconds'] / 60, 2, '.', ''),
                        number_format($unavailableSeconds / 60, 2, '.', ''),
                        number_format($statistic['observed_seconds'] / 60, 2, '.', ''),
                        number_format($statistic['eligible_seconds'] / 60, 2, '.', ''),
                        number_format($statistic['maintenance_excluded_seconds'] / 60, 2, '.', ''),
                        $statistic['legacy_interval_fallback'] ? '1' : '0',
                    ];

                    fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
                }
            }

            fclose($stream);
        }, 'fleet-availability-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
                'previous_cnames',
                'current_cnames',
                'previous_records',
                'current_records',
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
                        $event->previousCnamesLabel(),
                        $event->currentCnamesLabel(),
                        $event->previousRecordsLabel(),
                        $event->currentRecordsLabel(),
                        $event->monitor_host_only ? '1' : '0',
                    ];

                    fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
                });

            fclose($stream);
        }, 'dns-changes-'.$monitor->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{status: string, source: string, http_status: string, signal: string, content_match: string, date_from: string, date_to: string} */
    private function validateCheckRunFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::CHECK_RUN_STATUS_OPTIONS))],
            'source' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::CHECK_RUN_SOURCE_OPTIONS))],
            'http_status' => ['nullable', 'integer', 'between:100,599'],
            'signal' => ['nullable', 'string', 'max:40', 'regex:/\\A[a-z0-9_]+\\z/'],
            'content_match' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::CHECK_RUN_CONTENT_MATCH_OPTIONS))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], [
            'status.in' => '請選擇有效的檢查執行狀態。',
            'source.in' => '請選擇有效的檢查來源。',
            'http_status.integer' => 'HTTP 回應碼必須是整數。',
            'http_status.between' => 'HTTP 回應碼必須介於 100 到 599。',
            'signal.regex' => '訊號代碼只能使用小寫英文字母、數字與底線。',
            'signal.max' => '訊號代碼不得超過 40 個字元。',
            'content_match.in' => '請選擇有效的文字比對篩選條件。',
            'date_from.date_format' => '開始日期格式無效。',
            'date_to.date_format' => '結束日期格式無效。',
            'date_to.after_or_equal' => '結束日期不可早於開始日期。',
        ]);

        return [
            'status' => (string) ($validated['status'] ?? ''),
            'source' => (string) ($validated['source'] ?? ''),
            'http_status' => (string) ($validated['http_status'] ?? ''),
            'signal' => (string) ($validated['signal'] ?? ''),
            'content_match' => (string) ($validated['content_match'] ?? ''),
            'date_from' => (string) ($validated['date_from'] ?? ''),
            'date_to' => (string) ($validated['date_to'] ?? ''),
        ];
    }

    /**
     * @param  array{status: string, source: string, http_status: string, signal: string, content_match: string, date_from: string, date_to: string}  $filters
     * @param  array<int, list<int>>  $acceptedStatusCodesByVersion
     */
    private function filterCheckRuns(Builder $query, array $filters, array $acceptedStatusCodesByVersion = []): Builder
    {
        return $query
            ->when($filters['status'] !== '', static fn (Builder $query): Builder => $query->where('status', $filters['status']))
            ->when($filters['source'] !== '', static fn (Builder $query): Builder => $query->where('source', $filters['source']))
            ->when($filters['http_status'] !== '', static fn (Builder $query): Builder => $query->where('normal_status', (int) $filters['http_status']))
            ->when($filters['signal'] !== '', static function (Builder $query) use ($filters): Builder {
                return $query->where(function (Builder $query) use ($filters): void {
                    $query->where('error_type', $filters['signal'])
                        ->orWhere('adsbot_error_type', $filters['signal']);
                });
            })
            ->when($filters['content_match'] === 'found', function (Builder $query) use ($acceptedStatusCodesByVersion): Builder {
                $query->where('expected_text_found', true);
                $this->whereAcceptedHttpStatuses($query, $acceptedStatusCodesByVersion);

                return $query;
            })
            ->when($filters['content_match'] === 'missing', function (Builder $query) use ($acceptedStatusCodesByVersion): Builder {
                $query->where('expected_text_found', false);
                $this->whereAcceptedHttpStatuses($query, $acceptedStatusCodesByVersion);

                return $query;
            })
            ->when($filters['content_match'] === 'not_checked', function (Builder $query) use ($acceptedStatusCodesByVersion): Builder {
                $query->where(function (Builder $query) use ($acceptedStatusCodesByVersion): void {
                    $query->whereNull('expected_text_found')
                        ->orWhere(function (Builder $query) use ($acceptedStatusCodesByVersion): void {
                            $query->whereNotNull('expected_text_found');
                            $this->whereUnacceptedHttpStatuses($query, $acceptedStatusCodesByVersion);
                        });
                });

                return $query;
            })
            ->when($filters['date_from'] !== '', static function (Builder $query) use ($filters): Builder {
                $start = CarbonImmutable::parse($filters['date_from'], 'Asia/Taipei')->startOfDay()->utc();

                return $query->where('scheduled_at', '>=', $start);
            })
            ->when($filters['date_to'] !== '', static function (Builder $query) use ($filters): Builder {
                $endExclusive = CarbonImmutable::parse($filters['date_to'], 'Asia/Taipei')->addDay()->startOfDay()->utc();

                return $query->where('scheduled_at', '<', $endExclusive);
            });
    }

    /** @return array<int, list<int>> */
    private function acceptedStatusCodesByVersion(Monitor $monitor): array
    {
        $representativeRunIds = $monitor->checkRuns()
            ->selectRaw('MIN(id) AS representative_id')
            ->groupBy('config_version')
            ->pluck('representative_id');

        if ($representativeRunIds->isEmpty()) {
            return [];
        }

        return CheckRun::query()
            ->whereIn('id', $representativeRunIds)
            ->get(['config_version', 'config_snapshot'])
            ->mapWithKeys(static function (CheckRun $run): array {
                $snapshot = json_decode($run->config_snapshot, true, flags: JSON_THROW_ON_ERROR);
                $acceptedStatusCodes = $snapshot['accepted_status_codes'] ?? [];

                return [(int) $run->config_version => is_array($acceptedStatusCodes)
                    ? array_values(array_unique(array_map('intval', $acceptedStatusCodes)))
                    : []];
            })
            ->all();
    }

    private function maskProbeUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        return $this->urlMasker->mask($url);
    }

    /** @param array<mixed>|null $redirects
     * @return list<string>
     */
    private function maskedRedirectUrls(?array $redirects): array
    {
        $urls = [];

        foreach ($redirects ?? [] as $redirect) {
            $url = is_string($redirect)
                ? $redirect
                : (is_array($redirect) && is_string($redirect['url'] ?? null) ? $redirect['url'] : null);

            if ($url === null || trim($url) === '') {
                continue;
            }

            $urls[] = $this->maskProbeUrl($url) ?? '';
        }

        return array_values(array_filter($urls, static fn (string $url): bool => $url !== ''));
    }

    /** @param array<int, list<int>> $acceptedStatusCodesByVersion */
    private function whereAcceptedHttpStatuses(Builder $query, array $acceptedStatusCodesByVersion): void
    {
        $query->where(function (Builder $statusQuery) use ($acceptedStatusCodesByVersion): void {
            if ($acceptedStatusCodesByVersion === []) {
                $statusQuery->whereRaw('1 = 0');

                return;
            }

            foreach ($acceptedStatusCodesByVersion as $configVersion => $acceptedStatusCodes) {
                $statusQuery->orWhere(function (Builder $versionQuery) use ($configVersion, $acceptedStatusCodes): void {
                    $versionQuery->where('config_version', $configVersion)
                        ->where(function (Builder $httpStatusQuery) use ($acceptedStatusCodes): void {
                            $httpStatusQuery->whereBetween('normal_status', [200, 399]);

                            if ($acceptedStatusCodes !== []) {
                                $httpStatusQuery->orWhereIn('normal_status', $acceptedStatusCodes);
                            }
                        });
                });
            }
        });
    }

    /** @param array<int, list<int>> $acceptedStatusCodesByVersion */
    private function whereUnacceptedHttpStatuses(Builder $query, array $acceptedStatusCodesByVersion): void
    {
        $query->where(function (Builder $statusQuery) use ($acceptedStatusCodesByVersion): void {
            if ($acceptedStatusCodesByVersion === []) {
                $statusQuery->whereRaw('1 = 0');

                return;
            }

            foreach ($acceptedStatusCodesByVersion as $configVersion => $acceptedStatusCodes) {
                $statusQuery->orWhere(function (Builder $versionQuery) use ($configVersion, $acceptedStatusCodes): void {
                    $versionQuery->where('config_version', $configVersion)
                        ->where(function (Builder $httpStatusQuery) use ($acceptedStatusCodes): void {
                            $httpStatusQuery->whereNull('normal_status')
                                ->orWhere(function (Builder $unacceptedStatusQuery) use ($acceptedStatusCodes): void {
                                    $unacceptedStatusQuery->where(function (Builder $statusRangeQuery): void {
                                        $statusRangeQuery->where('normal_status', '<', 200)
                                            ->orWhere('normal_status', '>=', 400);
                                    });

                                    if ($acceptedStatusCodes !== []) {
                                        $unacceptedStatusQuery->whereNotIn('normal_status', $acceptedStatusCodes);
                                    }
                                });
                        });
                });
            }
        });
    }

    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        $exportRules = array_diff_key($this->monitorListFilterRules($request), ['sort' => true]);
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
            'content_match' => $validated['content_match'] ?? 'all',
            'http_status' => (string) ($validated['http_status'] ?? ''),
            'signal' => (string) ($validated['signal'] ?? ''),
            'client_id' => isset($validated['client_id']) ? (string) $validated['client_id'] : '',
        ];

        return $this->streamMonitorCsv($this->filterMonitorList(Monitor::query(), $filters));
    }

    private function streamMonitorCsv(Builder $query): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['name', 'url', 'label', 'enabled', 'slo_target_basis_points', 'last_checked_at_utc', 'http_status', 'error_type', 'adsbot_error_type']);

            $query
                ->with('latestCheckRun')
                ->orderBy('id')
                ->lazyById()
                ->each(function (Monitor $monitor) use ($stream): void {
                    $run = $monitor->latestCheckRun;
                    $fields = [
                        $monitor->name,
                        $this->urlMasker->mask($monitor->url),
                        $monitor->tagInputValue(),
                        $monitor->enabled ? '1' : '0',
                        (string) $monitor->slo_target_basis_points,
                        $monitor->last_checked_at?->utc()->toIso8601String() ?? '',
                        $run?->normal_status === null ? '' : (string) $run->normal_status,
                        (string) $run?->error_type,
                        (string) $run?->adsbot_error_type,
                    ];

                    fputcsv($stream, array_map(CsvFormulaSanitizer::escape(...), $fields), ',', '"', '');
                });

            fclose($stream);
        }, 'monitors.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{name: string, url: string, label: ?string, client_id: int|null, notification_email: ?string, notification_escalation_email: ?string, notification_escalation_delay_minutes: ?int, notification_escalation_stage_2_email: ?string, notification_escalation_stage_2_delay_minutes: ?int, notification_slack_webhook: ?string, notification_discord_webhook: ?string, notification_teams_webhook: ?string, request_headers: array<string, string>, expected_response_header: ?string, accepted_status_codes: list<int>, expected_text: ?string, expected_text_mode: string, alert_on_visible_text_change: bool, alert_on_response_metadata_change: bool, response_time_threshold_ms: ?int, min_response_body_bytes: ?int, max_response_body_bytes: ?int, timeout_seconds: int, confirmation_delay_seconds: int, interval_seconds: int, slo_target_basis_points: int, allowed_redirect_hosts: list<string>} */
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

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $clientExistsRule = Rule::exists('clients', 'id')->where('user_id', $user->workspaceOwnerId());
        $clientIds = $user->workspaceClientIds();

        if ($clientIds !== null) {
            $clientExistsRule->whereIn('id', $clientIds);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'client_id' => [
                'nullable',
                'integer',
                'min:1',
                $clientExistsRule,
            ],
            'url' => [$monitor === null ? 'required' : 'nullable', 'string', 'max:2048'],
            'label' => ['nullable', 'string', 'max:1200'],
            'utm_content' => ['nullable', 'string', 'max:4096'],
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
            'alert_on_visible_text_change' => ['sometimes', 'boolean'],
            'alert_on_response_metadata_change' => ['sometimes', 'boolean'],
            'expected_text_mode' => ['sometimes', 'in:'.implode(',', [
                ExpectedTextMatcher::MODE_PRESENT,
                ExpectedTextMatcher::MODE_PRESENT_ANY,
                ExpectedTextMatcher::MODE_ABSENT,
            ])],
            'response_time_threshold_ms' => ['nullable', 'integer', 'min:100', 'max:14000'],
            'min_response_body_bytes' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('monitoring.max_body_bytes', 1_048_576)],
            'max_response_body_bytes' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('monitoring.max_body_bytes', 1_048_576)],
            'timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:15'],
            'confirmation_delay_seconds' => ['sometimes', 'integer', 'min:30', 'max:300'],
            'interval_seconds' => ['sometimes', 'integer', 'in:'.implode(',', Monitor::SUPPORTED_INTERVAL_SECONDS)],
            'slo_target_basis_points' => ['sometimes', 'integer', 'in:'.implode(',', array_keys(Monitor::SLO_TARGET_OPTIONS))],
            'redirect_hosts' => ['nullable', 'string', 'max:2000'],
        ], [
            'client_id.integer' => '請選擇有效的客戶。',
            'client_id.min' => '請選擇有效的客戶。',
            'client_id.exists' => '所選客戶不存在或無權限。',
            'response_time_threshold_ms.integer' => '回應時間門檻必須是整數。',
            'response_time_threshold_ms.min' => '回應時間門檻不得低於 100 毫秒。',
            'response_time_threshold_ms.max' => '回應時間門檻不得高於 14000 毫秒。',
            'min_response_body_bytes.integer' => '最小回應本文大小必須是整數 bytes。',
            'min_response_body_bytes.min' => '最小回應本文大小不得低於 1 byte。',
            'min_response_body_bytes.max' => '回應本文大小門檻不得高於讀取上限（'.number_format((int) config('monitoring.max_body_bytes', 1_048_576)).' bytes）。',
            'max_response_body_bytes.integer' => '最大回應本文大小必須是整數 bytes。',
            'max_response_body_bytes.min' => '最大回應本文大小不得低於 1 byte。',
            'max_response_body_bytes.max' => '回應本文大小門檻不得高於讀取上限（'.number_format((int) config('monitoring.max_body_bytes', 1_048_576)).' bytes）。',
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
            'slo_target_basis_points.in' => '請選擇支援的可用率目標。',
        ]);

        $validated['label'] = $this->normalizeTagInput($validated['label'] ?? null);

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
        $minimumResponseBodyBytes = array_key_exists('min_response_body_bytes', $validated)
            ? ($validated['min_response_body_bytes'] === null ? null : (int) $validated['min_response_body_bytes'])
            : $monitor?->min_response_body_bytes;
        $maximumResponseBodyBytes = array_key_exists('max_response_body_bytes', $validated)
            ? ($validated['max_response_body_bytes'] === null ? null : (int) $validated['max_response_body_bytes'])
            : $monitor?->max_response_body_bytes;

        if ($minimumResponseBodyBytes !== null
            && $maximumResponseBodyBytes !== null
            && $minimumResponseBodyBytes > $maximumResponseBodyBytes) {
            throw ValidationException::withMessages([
                'max_response_body_bytes' => '最大回應本文大小必須大於或等於最小回應本文大小。',
            ]);
        }

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
            'client_id' => isset($validated['client_id']) ? (int) $validated['client_id'] : null,
            'utm_content' => array_key_exists('utm_content', $validated)
                ? (trim((string) $validated['utm_content']) === '' ? null : trim((string) $validated['utm_content']))
                : $monitor?->utm_content,
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
            'alert_on_visible_text_change' => array_key_exists('alert_on_visible_text_change', $validated)
                ? (bool) $validated['alert_on_visible_text_change']
                : ($monitor?->alert_on_visible_text_change ?? false),
            'alert_on_response_metadata_change' => array_key_exists('alert_on_response_metadata_change', $validated)
                ? (bool) $validated['alert_on_response_metadata_change']
                : ($monitor?->alert_on_response_metadata_change ?? false),
            'response_time_threshold_ms' => array_key_exists('response_time_threshold_ms', $validated)
                ? ($validated['response_time_threshold_ms'] === null ? null : (int) $validated['response_time_threshold_ms'])
                : ($monitor?->response_time_threshold_ms),
            'min_response_body_bytes' => $minimumResponseBodyBytes,
            'max_response_body_bytes' => $maximumResponseBodyBytes,
            'timeout_seconds' => array_key_exists('timeout_seconds', $validated)
                ? (int) $validated['timeout_seconds']
                : ($monitor?->timeout_seconds ?? (int) config('monitoring.request_timeout_seconds')),
            'confirmation_delay_seconds' => array_key_exists('confirmation_delay_seconds', $validated)
                ? (int) $validated['confirmation_delay_seconds']
                : ($monitor?->confirmation_delay_seconds ?? (int) config('monitoring.confirmation_delay_seconds')),
            'interval_seconds' => array_key_exists('interval_seconds', $validated)
                ? (int) $validated['interval_seconds']
                : ($monitor?->interval_seconds ?? (int) config('monitoring.interval_seconds')),
            'slo_target_basis_points' => array_key_exists('slo_target_basis_points', $validated)
                ? (int) $validated['slo_target_basis_points']
                : ($monitor?->slo_target_basis_points ?? Monitor::DEFAULT_SLO_TARGET_BASIS_POINTS),
            'allowed_redirect_hosts' => array_values(array_unique($allowedHosts)),
        ];
    }

    private function normalizeTagInput(?string $input): ?string
    {
        $tags = Monitor::parseTags($input);

        if (count($tags) > 10) {
            throw ValidationException::withMessages(['label' => '每個監測項目最多可設定 10 個標籤。']);
        }

        foreach ($tags as $tag) {
            if (mb_strlen($tag) > 100) {
                throw ValidationException::withMessages(['label' => '每個標籤不得超過 100 個字元。']);
            }
        }

        return $tags === [] ? null : implode(', ', $tags);
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
            if (in_array($exception->type, ['dns_no_address', 'dns_timeout'], true)) {
                try {
                    return $this->urlPolicy->normalizeUrl($url, $allowedHosts);
                } catch (ProbeException $normalizationException) {
                    $exception = $normalizationException;
                }
            }

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

        $socialPlatform = Monitor::socialPlatformKeyForUrl($url);

        if ($socialPlatform !== null) {
            $probeArguments[6] ??= $validated['request_headers'];
            $probeArguments[7] ??= $validated['expected_response_header'];
            $probeArguments[8] = $socialPlatform;
        }

        $result = $probe->inspect(...$probeArguments);
        $statusAccepted = $result->statusCode !== null
            && (($result->statusCode >= 200 && $result->statusCode < 400)
                || in_array($result->statusCode, $validated['accepted_status_codes'], true));
        $expectedTextChecked = $expectedText !== null && $statusAccepted;
        $contentConditionPassed = ! $expectedTextChecked
            || ($validated['expected_text_mode'] === ExpectedTextMatcher::MODE_ABSENT
                ? $result->expectedTextFound === false
                : $result->expectedTextFound === true);
        $expectedResponseHeaderChecked = $validated['expected_response_header'] !== null && $statusAccepted;
        $responseHeaderConditionPassed = ! $expectedResponseHeaderChecked
            || $result->expectedResponseHeaderFound === true;
        $isIncomplete = in_array($result->errorType, ['body_too_large', 'social_access_required'], true)
            && $statusAccepted
            && $expectedText !== null
            && $result->expectedTextFound === null
            && $responseHeaderConditionPassed;
        $isPassed = $result->errorType === null && $statusAccepted && $contentConditionPassed && $responseHeaderConditionPassed;
        $state = $isPassed ? 'passed' : ($isIncomplete ? 'incomplete' : 'failed');
        $message = $this->probePreviewMessage($result->errorType, $result->statusCode, $statusAccepted, $contentConditionPassed, $responseHeaderConditionPassed, $isIncomplete, $validated['expected_text_mode']);
        $expectedTextMatches = [];
        $expectedTextTerms = ExpectedTextMatcher::terms($expectedText);

        if ($expectedTextChecked && $result->expectedTextTermMatches !== null && count($expectedTextTerms) === count($result->expectedTextTermMatches)) {
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
                'response_metadata' => [
                    'title' => $result->responseTitle,
                    'description' => $result->responseDescription,
                    'image_reference_present' => $result->responseImageReferenceHash !== null,
                    'open_graph_type' => $result->responseOpenGraphType,
                    'canonical_reference_present' => $result->responseCanonicalReferenceHash !== null,
                    'published_at_utc' => $result->responsePublishedAt?->format(DATE_ATOM),
                    'modified_at_utc' => $result->responseModifiedAt?->format(DATE_ATOM),
                ],
                'expected_text_checked' => $expectedTextChecked,
                'expected_text_mode' => $validated['expected_text_mode'],
                'expected_text_state' => ! $expectedTextChecked || $result->expectedTextFound === null
                    ? null
                    : ($result->expectedTextFound ? 'found' : 'missing'),
                'expected_text_matches' => $expectedTextMatches,
                'expected_response_header_checked' => $expectedResponseHeaderChecked,
                'expected_response_header_found' => $expectedResponseHeaderChecked ? $result->expectedResponseHeaderFound : null,
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
            if ($errorType === 'social_access_required') {
                return '平台公開回應包含登入、驗證或暫時限制提示，這次無法判定預期貼文文字；請手動開啟網址核對。';
            }

            return '回應超過讀取上限，指定文字比對尚未完成。';
        }

        if ($errorType !== null) {
            return match ($errorType) {
                'blocked_ssrf' => '網址主機解析到非公開 IP，安全檢查已封鎖此次請求。',
                'blocked_port' => '只允許連接埠 80 或 443。',
                'dns_no_address' => 'DNS 回應沒有可用的 A/AAAA 位址，可能是名稱不存在、沒有地址記錄，或解析器未提供回答。',
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

        $hasExpectedText = trim((string) ($validated['expected_text'] ?? '')) !== '';
        $usesVisibleTextBaseline = (bool) ($validated['alert_on_visible_text_change'] ?? false);

        if (! $hasExpectedText && ! $usesVisibleTextBaseline) {
            throw ValidationException::withMessages([
                'expected_text' => '監測 '.$platform.' 公開貼文時，請填入預期文字，或啟用可見文字變更警示以建立內容基準。',
            ]);
        }

        if ($hasExpectedText && ! in_array($validated['expected_text_mode'] ?? ExpectedTextMatcher::MODE_PRESENT, [
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

    /**
     * @param  array<string, mixed>  $metrics
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function publicMetricSourceValues(array $metrics): array
    {
        $sources = is_array($metrics['metric_sources'] ?? null) ? $metrics['metric_sources'] : [];

        return array_map(
            static fn (string $metric): string => is_string($sources[$metric] ?? null) ? $sources[$metric] : '',
            ['views', 'likes', 'comments', 'shares'],
        );
    }

    private function resumeMonitorChecks(Monitor $monitor): void
    {
        $monitor->forceFill(['enabled' => true, 'next_check_at' => now()])->save();
    }

    /** @param array<string, mixed> $configuration */
    private function persistMonitorConfigurationVersion(Monitor $monitor, array $configuration): void
    {
        $configurationChanged = false;

        foreach ($configuration as $key => $value) {
            $currentValue = $monitor->getAttribute($key);

            if ($currentValue != $value) {
                $configurationChanged = true;

                break;
            }
        }

        if (! $configurationChanged) {
            return;
        }

        $previousVersion = $monitor->config_version;
        $nextVersion = $previousVersion + 1;

        $monitor->forceFill([
            ...$configuration,
            'config_version' => $nextVersion,
            'next_check_at' => $monitor->enabled ? now() : null,
        ])->save();

        CheckRun::query()
            ->where('monitor_id', $monitor->id)
            ->where('config_version', $previousVersion)
            ->where('status', 'queued')
            ->update(['status' => 'stale_config', 'finished_at' => now(), 'updated_at' => now()]);

        $activeIncidents = Incident::query()
            ->where('monitor_id', $monitor->id)
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
    }
}
