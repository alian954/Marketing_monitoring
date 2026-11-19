@extends('layouts.app')

@php
    $healthState = $monitor->getAttribute('health_state') ?? $monitor->healthState();
    $socialPlatform = $monitor->socialPlatform();
    $allowedRedirectHosts = is_array($monitor->allowed_redirect_hosts) ? $monitor->allowed_redirect_hosts : [];
    $hasCheckRunFilters = count(array_filter($checkRunFilters, static fn (string $filter): bool => $filter !== '')) > 0;
    $intervalLabel = $monitor->interval_seconds >= 3600 && $monitor->interval_seconds % 3600 === 0
        ? intdiv($monitor->interval_seconds, 3600).' 小時'
        : ($monitor->interval_seconds >= 60
            ? intdiv($monitor->interval_seconds, 60).' 分鐘'
            : $monitor->interval_seconds.' 秒');
    $ruleLabels = [
        'http' => 'HTTP 回應異常',
        'probe_restricted' => '檢查受到限制',
        'probe_incomplete' => '回應過大，驗證未完成',
        'content' => '原始回應未包含預期文字',
        'forbidden_text' => '回應包含不應出現的文字',
        'response_header' => 'HTTP 回應標頭不符合設定',
        'response_time' => 'HTTP 回應時間超過設定門檻',
        'monitor_stale' => '監測資料逾時',
        'adsbot_difference' => 'AdsBot 模擬結果不同',
        'tls_expired' => 'TLS 憑證已過期',
        'tls_handshake' => 'TLS 交握失敗',
        'tls_7d' => 'TLS 憑證將於 7 天內到期',
        'tls_14d' => 'TLS 憑證將於 14 天內到期',
        'tls_30d' => 'TLS 憑證將於 30 天內到期',
        'domain_expired' => '網域已到期',
        'domain_7d' => '網域將於 7 天內到期',
        'domain_14d' => '網域將於 14 天內到期',
        'domain_30d' => '網域將於 30 天內到期',
    ];
    $domainExpiryErrorLabels = [
        'rdap_invalid_host' => '無法辨識此網址的網域名稱',
        'rdap_unsupported' => 'IANA 清單未提供此頂級網域的 RDAP 服務',
        'rdap_bootstrap_unavailable' => '無法取得 IANA RDAP 服務清單',
        'rdap_unavailable' => 'RDAP 註冊服務暫時無法回應',
        'rdap_not_found' => 'RDAP 未找到網域註冊資料',
        'rdap_invalid_response' => 'RDAP 回應格式無法辨識',
        'rdap_domain_mismatch' => 'RDAP 回傳的網域與查詢不一致',
        'rdap_expiration_unavailable' => '註冊機構未提供網域到期日',
        'rdap_insecure_endpoint' => 'RDAP 服務未使用 HTTPS',
        'blocked_ssrf' => 'RDAP 服務位址未通過安全檢查',
        'dns_nxdomain' => 'RDAP 主機名稱無法解析',
    ];
    $eventLabels = [
        'failure_seen' => '發現異常',
        'opened' => '事件已確認',
        'false_alarm' => '確認為誤報',
        'recovery_seen' => '發現恢復跡象',
        'recovered' => '網站已恢復',
        'reminder_queued' => '事件仍未恢復，已排入提醒',
        'escalation_queued' => '升級告警已排入通知',
        'escalation' => '升級告警',
        'escalation_stage_2' => '第二階段升級告警',
        'paused' => '監測已暫停',
        'superseded' => '舊事件已因設定變更而結束',
        'acknowledged' => '事件已認領',
        'unacknowledged' => '事件認領已解除',
        'notifications_snoozed' => '提醒與升級通知已暫停',
        'notifications_resumed' => '提醒與升級通知已恢復',
        'classification_changed' => '事件分類已變更',
        'incident_note_added' => '新增檢視備註',
        'incident_note_cleared' => '已清除檢視備註',
    ];
    $classificationLabels = [
        'true_issue' => '確認為真實異常',
        'false_alarm' => '確認為誤報',
        'unknown' => '尚不確定',
    ];
    $responseTimeAxis = [
        ['y' => 28, 'value' => $responseTime['scale_max_ms']],
        ['y' => 86, 'value' => (int) round($responseTime['scale_max_ms'] / 2)],
        ['y' => 144, 'value' => 0],
    ];
    $maintenanceAsOf = $asOf;
    $maintenanceWindows = $maintenanceWindows
        ->filter(fn ($window): bool => $window->hasUpcomingOrActiveOccurrence($maintenanceAsOf))
        ->sortBy(fn ($window): int => $window->nextOccurrence($maintenanceAsOf)['starts_at']->getTimestamp())
        ->values();
    $activeMaintenanceWindow = $maintenanceWindows->first(fn ($window): bool => $window->isActiveAt($maintenanceAsOf));
    $activeMaintenanceOccurrence = $activeMaintenanceWindow?->nextOccurrence($maintenanceAsOf);
@endphp

@section('title', $monitor->name)

@section('content')
    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('monitors.index') }}">監測項目</a><span aria-hidden="true">/</span>
        <span aria-current="page">{{ $monitor->name }}</span>
    </div>

    <div class="page-heading detail-heading">
        <div class="heading-copy">
            <p class="eyebrow">監測項目詳情</p>
            <div class="detail-title-line">
                <h1 class="page-title">{{ $monitor->name }}</h1>
                <x-state-badge :state="$healthState" />
            </div>
            <p class="detail-url">{{ $monitor->masked_url }}</p>
        </div>
        <div class="heading-actions">
            <form class="inline-form" action="{{ route('monitors.notifications.test', $monitor) }}" method="POST" aria-label="傳送此監測項目的測試通知">
                @csrf
                <button class="button button-secondary" type="submit">測試通知</button>
            </form>
            <form class="inline-form" action="{{ route('monitors.check', $monitor) }}" method="POST">
                @csrf
                <button class="button button-primary" type="submit" @disabled(! $monitor->enabled)>
                    <span aria-hidden="true">↻</span> 立即檢查
                </button>
            </form>
            <form class="inline-form" action="{{ route('monitors.toggle', $monitor) }}" method="POST">
                @csrf
                <button class="button button-secondary" type="submit">{{ $monitor->enabled ? '暫停監測' : '恢復監測' }}</button>
            </form>
            <a class="button button-secondary" href="{{ route('monitors.robots', $monitor) }}">檢視 robots.txt</a>
            <a class="button button-secondary" href="{{ route('monitors.edit', $monitor) }}">編輯</a>
        </div>
    </div>

    @if (! $monitor->enabled)
        <div class="form-note spaced-bottom">監測目前已暫停。請先恢復監測，再要求立即檢查。</div>
    @endif
    @if ($socialPlatform)
        <div class="form-note spaced-bottom">
            {{ $socialPlatform }} 貼文以每行一個文字片段比對公開頁面的原始 HTTP 回應，不使用平台 API，也不執行 JavaScript；可選擇要求全部片段出現，或只要求任一片段出現。每次只送出一般 HTTP 請求，不額外模擬 AdsBot，以減少對平台的請求。若未找到文字，可能是平台未在初始回應提供貼文內容；請開啟貼文網址確認，這項結果本身不代表貼文未發布或已刪除。
        </div>
    @endif
    @error('check')
        <div class="alert alert-error" role="alert">{{ $message }}</div>
    @enderror
    @if ($activeMaintenanceWindow)
        <div class="form-note spaced-bottom" role="status">
            維護時段進行中：{{ $activeMaintenanceWindow->reason }}。檢查會持續執行；期間內成立的事件不會建立通知，已排入佇列的通知仍照原排程處理。時段結束於
            <time datetime="{{ $activeMaintenanceOccurrence['ends_at']->toIso8601String() }}">{{ $activeMaintenanceOccurrence['ends_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>。
        </div>
    @endif

    <div class="detail-grid section-stack">
        <section class="panel" aria-labelledby="configuration-title">
            <div class="panel-header">
                <div>
                    <h2 id="configuration-title" class="panel-title">監測設定</h2>
                    <p class="panel-description">目前的監測設定與檢查頻率。</p>
                </div>
                <span class="secondary-text">設定版本 {{ $monitor->config_version }}</span>
            </div>
            <div class="panel-body">
                <dl class="metadata-list">
                    @if ($socialPlatform)
                        <div class="metadata-item">
                            <dt>監測平台</dt>
                            <dd>{{ $socialPlatform }} 公開貼文</dd>
                        </div>
                    @endif
                    <div class="metadata-item">
                        <dt>標籤</dt>
                        <dd>{{ $monitor->label ?: '—' }}</dd>
                    </div>
                    <div class="metadata-item">
                        <dt>檢查間隔</dt>
                        <dd>每 {{ $intervalLabel }}</dd>
                    </div>
                    <div class="metadata-item">
                        <dt>額外接受的 HTTP 狀態碼</dt>
                        <dd>{{ $monitor->accepted_status_codes ? implode(', ', $monitor->accepted_status_codes) : '無（預設接受 200–399）' }}</dd>
                    </div>
                    <div class="metadata-item">
                        <dt>自訂 HTTP 請求標頭</dt>
                        <dd>
                            @if ($monitor->request_headers)
                                {{ implode('、', array_keys($monitor->request_headers)) }}（值已加密保存）
                            @else
                                未設定
                            @endif
                        </dd>
                    </div>
                    <div class="metadata-item">
                        <dt>HTTP 回應標頭條件</dt>
                        <dd>
                            @if ($monitor->expectedResponseHeaderName() !== null)
                                {{ $monitor->expectedResponseHeaderName() }}（{{ $monitor->expectedResponseHeaderChecksPresenceOnly() ? '只檢查是否存在' : '預期值已加密保存' }}）
                            @else
                                未設定
                            @endif
                        </dd>
                    </div>
                    <div class="metadata-item">
                        <dt>預期內容文字片段</dt>
                        <dd>
                            @if ($monitor->expected_text)
                                {{ match ($monitor->expected_text_mode) { 'absent' => '不得出現任一片段：', 'present_any' => '至少包含一個片段：', default => '必須包含全部片段：' } }}{{ str_replace("\n", '、', $monitor->expected_text) }}
                            @else
                                未設定文字條件
                            @endif
                        </dd>
                    </div>
                    <div class="metadata-item">
                        <dt>回應時間警示門檻</dt>
                        <dd>{{ $monitor->response_time_threshold_ms !== null ? number_format($monitor->response_time_threshold_ms).' 毫秒' : '未設定' }}</dd>
                    </div>
                    <div class="metadata-item">
                        <dt>請求逾時上限</dt>
                        <dd>{{ $monitor->timeout_seconds }} 秒</dd>
                    </div>
                    <div class="metadata-item">
                        <dt>異常與恢復確認延遲</dt>
                        <dd>{{ $monitor->confirmation_delay_seconds }} 秒</dd>
                    </div>
                    <div class="metadata-item">
                        <dt>最近檢查</dt>
                        <dd>
                            @if ($monitor->last_checked_at)
                                <time datetime="{{ $monitor->last_checked_at->toIso8601String() }}">{{ $monitor->last_checked_at->timezone('Asia/Taipei')->format('Y/n/j · H:i:s') }}</time>
                            @else
                                尚未檢查
                            @endif
                        </dd>
                    </div>
                    <div class="metadata-item">
                        <dt>下次檢查</dt>
                        <dd>
                            @if ($monitor->enabled && $monitor->next_check_at)
                                <time datetime="{{ $monitor->next_check_at->toIso8601String() }}">{{ $monitor->next_check_at->timezone('Asia/Taipei')->format('Y/n/j · H:i:s') }}</time>
                            @elseif (! $monitor->enabled)
                                已暫停
                            @else
                                等待排程器執行
                            @endif
                        </dd>
                    </div>
                    <div class="metadata-item">
                        <dt>允許的重新導向主機</dt>
                        <dd>{{ $allowedRedirectHosts ? implode(', ', $allowedRedirectHosts) : '無' }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="panel" aria-labelledby="recent-run-title">
            <div class="panel-header">
                <div>
                    <h2 id="recent-run-title" class="panel-title">最近一次檢查</h2>
                    <p class="panel-description">顯示 HTTP 回應的分享標題與摘要，並與同設定版本的前次完成檢查比較。</p>
                </div>
            </div>
            @if ($monitor->latestCheckRun)
                <div class="panel-body">
                    <dl class="metadata-list">
                        <div class="metadata-item">
                            <dt>執行狀態</dt>
                            <dd><x-state-badge :state="$monitor->latestCheckRun->status" /></dd>
                        </div>
                        <div class="metadata-item">
                            <dt>HTTP 回應</dt>
                            <dd>{{ $monitor->latestCheckRun->normal_status ?? '沒有回應' }}</dd>
                        </div>
                        <div class="metadata-item">
                            <dt>檢查總耗時</dt>
                            <dd>{{ $monitor->latestCheckRun->elapsed_ms !== null ? number_format($monitor->latestCheckRun->elapsed_ms).' 毫秒' : '—' }}</dd>
                        </div>
                        <div class="metadata-item">
                            <dt>HTTP 探測耗時</dt>
                            <dd>{{ $monitor->latestCheckRun->normal_elapsed_ms !== null ? number_format($monitor->latestCheckRun->normal_elapsed_ms).' 毫秒' : '尚未記錄' }}</dd>
                        </div>
                        <div class="metadata-item">
                            <dt>檢查來源</dt>
                            <dd>{{ $monitor->latestCheckRun->source === 'manual' ? '手動檢查' : '排程檢查' }}</dd>
                        </div>
                        <div class="metadata-item">
                            <dt>AdsBot 回應</dt>
                            <dd>{{ $monitor->latestCheckRun->adsbot_status ?? ($socialPlatform ? '略過（社群監測）' : '未記錄') }}</dd>
                        </div>
                        <div class="metadata-item">
                                <dt>文字比對結果</dt>
                            <dd>
                                @if ($monitor->latestCheckRun->expectedTextMatchResult() === null)
                                    未檢查或無法判定
                                @elseif ($monitor->latestCheckRun->expectedTextMatchResult())
                                    {{ match ($monitor->latestCheckRun->expectedTextMode()) { 'absent' => '原始回應找到禁止文字', 'present_any' => '原始回應找到至少一個指定片段', default => '原始回應找到全部指定片段' } }}
                                @else
                                    {{ match ($monitor->latestCheckRun->expectedTextMode()) { 'absent' => '原始回應未找到禁止文字', 'present_any' => '原始回應未找到任何指定片段', default => '原始回應缺少至少一個指定片段' } }}
                                @endif
                                @php
                                    $latestTextMatchDetails = $monitor->latestCheckRun->expectedTextMatchDetails();
                                @endphp
                                @if ($latestTextMatchDetails !== null)
                                    <ul aria-label="逐項文字片段結果">
                                        @foreach ($latestTextMatchDetails as $matchDetail)
                                            <li>{{ $matchDetail['term'] }}：{{ $matchDetail['matched'] === null ? '回應截斷，無法判定' : ($matchDetail['matched'] ? '原始回應有此片段' : '原始回應未找到此片段') }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </dd>
                        </div>
                        @if ($monitor->expectedResponseHeaderName() !== null)
                            <div class="metadata-item">
                                <dt>回應標頭比對結果</dt>
                                <dd>{{ $monitor->latestCheckRun->expectedResponseHeaderMatchResult() === null ? '未檢查或無法判定' : ($monitor->latestCheckRun->expectedResponseHeaderMatchResult() ? '符合' : '不符合') }}</dd>
                            </div>
                        @endif
                        <div class="metadata-item field-group-full">
                            <dt>頁面回應摘要</dt>
                            <dd>
                                @if ($monitor->latestCheckRun->response_title || $monitor->latestCheckRun->response_description)
                                    @if ($monitor->latestCheckRun->response_title)
                                        <p>分享標題：{{ $monitor->latestCheckRun->response_title }}</p>
                                    @endif
                                    @if ($monitor->latestCheckRun->response_description)
                                        <p>分享摘要：{{ $monitor->latestCheckRun->response_description }}</p>
                                    @endif
                                @else
                                    <span class="secondary-text">回應沒有提供頁面標題或摘要。</span>
                                @endif
                            </dd>
                        </div>
                        @if ($responseMetadataComparisonAvailable)
                            <div class="metadata-item field-group-full">
                                <dt>摘要變化</dt>
                                <dd>
                                    @if ($responseMetadataChanged)
                                        <p>與上次完成檢查相比已變更。</p>
                                        <p>
                                            比較基準：<time datetime="{{ $previousResponseMetadataRun->scheduled_at->toIso8601String() }}">{{ $previousResponseMetadataRun->scheduled_at->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time>
                                        </p>
                                        <p>上次分享標題：{{ $previousResponseMetadataRun->response_title ?? '未提供' }}</p>
                                        <p>上次分享摘要：{{ $previousResponseMetadataRun->response_description ?? '未提供' }}</p>
                                    @else
                                        <p>與上次完成檢查一致。</p>
                                    @endif
                                </dd>
                            </div>
                        @endif
                        @if ($monitor->latestCheckRun->error_type || $monitor->latestCheckRun->adsbot_error_type)
                            <div class="metadata-item field-group-full">
                                <dt>檢查訊號</dt>
                                <dd>
                                    @if ($monitor->latestCheckRun->error_type)
                                        <p>一般探測：{{ $monitor->latestCheckRun->error_type }}</p>
                                    @endif
                                    @if ($monitor->latestCheckRun->adsbot_error_type)
                                        <p>AdsBot 模擬：{{ $monitor->latestCheckRun->adsbot_error_type }}</p>
                                    @endif
                                </dd>
                            </div>
                        @endif
                        @if ($monitor->latestCheckRun->tls_expires_at)
                            <div class="metadata-item field-group-full">
                                <dt>TLS 憑證到期日</dt>
                                <dd><time datetime="{{ $monitor->latestCheckRun->tls_expires_at->toIso8601String() }}">{{ $monitor->latestCheckRun->tls_expires_at->timezone('Asia/Taipei')->format('Y/n/j') }}</time></dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @else
                <div class="empty-state">
                    <h3 class="empty-title">尚無檢查結果</h3>
                    <p class="empty-copy">排程器執行此監測項目後，檢查結果會顯示於此。</p>
                </div>
            @endif
        </section>
    </div>

    @if ($latestTlsObservation)
        <section class="panel spaced-top" aria-labelledby="latest-tls-observation-title">
            <div class="panel-header">
                <div>
                    <h2 id="latest-tls-observation-title" class="panel-title">每日 TLS 獨立檢查</h2>
                    <p class="panel-description">每日讀取 HTTPS 憑證到期資訊，不發送 HTTP 請求。</p>
                </div>
            </div>
            <div class="panel-body">
                <dl class="metadata-list">
                    <div class="metadata-item">
                        <dt>最近檢查</dt>
                        <dd><time datetime="{{ $latestTlsObservation->observed_on->toDateString() }}">{{ $latestTlsObservation->observed_on->format('Y/n/j') }}</time></dd>
                    </div>
                    <div class="metadata-item">
                        <dt>憑證到期</dt>
                        <dd>
                            @if ($latestTlsObservation->expires_at)
                                <time datetime="{{ $latestTlsObservation->expires_at->toIso8601String() }}">{{ $latestTlsObservation->expires_at->timezone('Asia/Taipei')->format('Y/n/j') }}</time>
                            @elseif ($latestTlsObservation->error_type)
                                {{ $latestTlsObservation->error_type }}
                            @else
                                未取得憑證到期時間
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>
        </section>
    @endif

    @if ($latestDomainRegistrationObservation)
        <section class="panel spaced-top" aria-labelledby="latest-domain-observation-title">
            <div class="panel-header">
                <div>
                    <h2 id="latest-domain-observation-title" class="panel-title">每日網域註冊期限檢查</h2>
                    <p class="panel-description">透過 IANA RDAP 服務查詢網站註冊網域，不使用社群平台 API。註冊機構未提供資料時會顯示為無法判定。</p>
                </div>
            </div>
            <div class="panel-body">
                <dl class="metadata-list">
                    <div class="metadata-item">
                        <dt>最近檢查</dt>
                        <dd><time datetime="{{ $latestDomainRegistrationObservation->observed_on->toDateString() }}">{{ $latestDomainRegistrationObservation->observed_on->format('Y/n/j') }}</time></dd>
                    </div>
                    <div class="metadata-item">
                        <dt>註冊網域</dt>
                        <dd>{{ $latestDomainRegistrationObservation->domain_name ?? '尚未判定' }}</dd>
                    </div>
                    <div class="metadata-item">
                        <dt>註冊到期日</dt>
                        <dd>
                            @if ($latestDomainRegistrationObservation->expires_at)
                                <time datetime="{{ $latestDomainRegistrationObservation->expires_at->toIso8601String() }}">{{ $latestDomainRegistrationObservation->expires_at->timezone('Asia/Taipei')->format('Y/n/j') }}</time>
                            @elseif ($latestDomainRegistrationObservation->error_type)
                                <span>{{ $domainExpiryErrorLabels[$latestDomainRegistrationObservation->error_type] ?? $latestDomainRegistrationObservation->error_type }}</span>
                            @else
                                未取得註冊期限
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>
        </section>
    @endif

    <section class="panel spaced-top" aria-labelledby="maintenance-window-title">
        <div class="panel-header">
            <div>
                <h2 id="maintenance-window-title" class="panel-title">維護時段</h2>
            <p class="panel-description">排定期間照常檢查；期間內成立或恢復的事件仍會記錄，但不會建立通知。已排入佇列的通知仍照原排程處理。</p>
            </div>
        </div>

        @if ($maintenanceWindows->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">沒有進行中或已排定的維護時段</h3>
                <p class="empty-copy">單次維護最長 14 天；也可設定最多一年的每週重複時段。時段不可彼此重疊。</p>
            </div>
        @else
            <div class="incident-list">
                @foreach ($maintenanceWindows as $maintenanceWindow)
                    @php
                        $maintenanceOccurrence = $maintenanceWindow->nextOccurrence($maintenanceAsOf);
                        $maintenanceWindowIsActive = $maintenanceWindow->isActiveAt($maintenanceAsOf);
                    @endphp
                    <article class="incident-card">
                        <div class="incident-card-header">
                            <div>
                                <h3 class="incident-card-title">{{ $maintenanceWindowIsActive ? '進行中' : '已排定' }}</h3>
                                <p>{{ $maintenanceWindow->reason }}</p>
                                <div class="incident-meta">
                                    <span>
                                        @if ($maintenanceWindow->repeat_weekly)
                                            每週重複，截止 {{ $maintenanceWindow->repeat_until->format('Y/n/j') }}（台灣時間）<br>
                                        @endif
                                        <time datetime="{{ $maintenanceOccurrence['starts_at']->toIso8601String() }}">{{ $maintenanceOccurrence['starts_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                        至
                                        <time datetime="{{ $maintenanceOccurrence['ends_at']->toIso8601String() }}">{{ $maintenanceOccurrence['ends_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                        （台灣時間）
                                    </span>
                                </div>
                            </div>
                            <form class="inline-form" action="{{ route('monitors.maintenance-windows.destroy', [$monitor, $maintenanceWindow]) }}" method="POST" data-confirm="{{ $maintenanceWindowIsActive ? '取消維護時段會立即恢復事件通知。確定繼續？' : '確定取消這個維護時段？' }}">
                                @csrf
                                @method('DELETE')
                                <button class="button button-secondary button-small" type="submit">取消時段</button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <div class="panel-body">
            <form action="{{ route('monitors.maintenance-windows.store', $monitor) }}" method="POST" aria-labelledby="maintenance-form-title">
                @csrf
                <div class="form-section">
                    <h3 id="maintenance-form-title" class="form-section-title">排定維護時段</h3>
                    <p class="form-section-copy">時間以台灣時間輸入。期間仍會更新檢查狀態與事件紀錄，但不會建立新的事件通知。可排單次時段，或選擇每週重複。</p>
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="starts_at">開始時間（台灣時間）<span class="required-mark" aria-hidden="true">*</span></label>
                            <input class="field-input @error('starts_at') is-invalid @enderror" id="starts_at" name="starts_at" type="datetime-local" step="60" min="{{ now()->timezone('Asia/Taipei')->addMinute()->format('Y-m-d\\TH:i') }}" value="{{ old('starts_at') }}" required aria-describedby="starts-at-help{{ $errors->has('starts_at') ? ' starts-at-error' : '' }}" @if ($errors->has('starts_at')) aria-invalid="true" autofocus @endif>
                            <p id="starts-at-help" class="field-help">必須晚於現在，並且不得與其他維護時段重疊。</p>
                            @error('starts_at')<p id="starts-at-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="ends_at">結束時間（台灣時間）<span class="required-mark" aria-hidden="true">*</span></label>
                            <input class="field-input @error('ends_at') is-invalid @enderror" id="ends_at" name="ends_at" type="datetime-local" step="60" value="{{ old('ends_at') }}" required aria-describedby="ends-at-help{{ $errors->has('ends_at') ? ' ends-at-error' : '' }}" @if ($errors->has('ends_at')) aria-invalid="true" @unless ($errors->has('starts_at')) autofocus @endunless @endif>
                            <p id="ends-at-help" class="field-help">必須晚於開始時間；單次時段最多 14 天，每週重複時每次最多 7 天。</p>
                            @error('ends_at')<p id="ends-at-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <fieldset class="field-group field-group-full maintenance-repeat-settings">
                            <legend class="field-label">重複方式</legend>
                            <input type="hidden" name="repeat_weekly" value="0">
                            <label class="field-check" for="repeat_weekly">
                                <input id="repeat_weekly" name="repeat_weekly" type="checkbox" value="1" @checked(old('repeat_weekly', false))>
                                <span>每週重複</span>
                            </label>
                            <div class="field-group spaced-top">
                                <label class="field-label" for="repeat_until">重複截止日</label>
                                <input class="field-input @error('repeat_until') is-invalid @enderror" id="repeat_until" name="repeat_until" type="date" min="{{ now()->timezone('Asia/Taipei')->toDateString() }}" value="{{ old('repeat_until') }}" aria-describedby="repeat-until-help{{ $errors->has('repeat_until') ? ' repeat-until-error' : '' }}" @if ($errors->has('repeat_until')) aria-invalid="true" @unless ($errors->has('starts_at') || $errors->has('ends_at') || $errors->has('reason')) autofocus @endunless @endif>
                                <p id="repeat-until-help" class="field-help">勾選每週重複時必填；依開始日期的星期與台灣時間執行，僅排定截止日當天或之前的時段，最多一年。</p>
                                @error('repeat_until')<p id="repeat-until-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                        </fieldset>
                        <div class="field-group field-group-full">
                            <label class="field-label" for="reason">維護原因<span class="required-mark" aria-hidden="true">*</span></label>
                            <input class="field-input @error('reason') is-invalid @enderror" id="reason" name="reason" type="text" value="{{ old('reason') }}" maxlength="200" required aria-describedby="reason-help{{ $errors->has('reason') ? ' reason-error' : '' }}" @if ($errors->has('reason')) aria-invalid="true" @unless ($errors->has('starts_at') || $errors->has('ends_at')) autofocus @endunless @endif>
                            <p id="reason-help" class="field-help">簡短說明這段預期維護，例如「網站版本部署」。</p>
                            @error('reason')<p id="reason-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="button button-primary" type="submit">排定維護時段</button>
                </div>
            </form>
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="check-success-rate-title">
        <div class="panel-header">
            <div>
                <h2 id="check-success-rate-title" class="panel-title">歷史檢查成功率</h2>
                <p class="panel-description">以已完成或重試耗盡的排程檢查計算成功率，不估算檢查間隔內的時間；只有完成、沒有連線錯誤，且符合已設定 HTTP 狀態碼、文字與回應標頭條件的檢查才列為成功。</p>
            </div>
        </div>
        <div class="stat-grid monitor-availability-grid" aria-label="不同期間的檢查成功率">
            @foreach ($checkSuccessStats as $stat)
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">最近 {{ $stat['label'] }}</span></div>
                    <strong class="stat-value">{{ $stat['success_rate'] === null ? '—' : number_format($stat['success_rate'], 2).'%' }}</strong>
                    <p class="stat-hint">成功 {{ $stat['successful_checks'] }} / 已結束 {{ $stat['ended_checks'] }} 次</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="availability-title">
        <div class="panel-header">
            <div>
                <h2 id="availability-title" class="panel-title">估算時間可用率</h2>
                <p class="panel-description">依已完成的排程 HTTP 檢查估算：通過目前狀態碼與文字條件的結果算可用，其他完成結果算不可用。每次結果最多延伸至檢查頻率加寬限時間；佇列工作失敗及超過延伸上限的資料空窗不算可用或停機。維護時段不計入觀測。可用率只計入有觀測的時間，覆蓋率顯示有觀測時間占非維護時間的比例。</p>
            </div>
        </div>
        <div class="stat-grid monitor-availability-grid" aria-label="不同期間的估算時間可用率">
            @foreach ($availabilityStats as $stat)
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">最近 {{ $stat['label'] }}</span></div>
                    <strong class="stat-value">{{ $stat['availability_rate'] === null ? '—' : number_format($stat['availability_rate'], 2).'%' }}</strong>
                    <p class="stat-hint">可用 {{ number_format($stat['up_minutes'], 1) }} / 已觀測 {{ number_format($stat['observed_minutes'], 1) }} 分鐘</p>
                    <p class="stat-hint">資料覆蓋 {{ $stat['coverage_rate'] === null ? '—' : number_format($stat['coverage_rate'], 2).'%' }}；非維護時間 {{ number_format($stat['eligible_minutes'], 1) }} 分鐘</p>
                    @if ($stat['legacy_interval_fallback'])
                        <p class="stat-hint">部分舊檢查紀錄未保存當時頻率，使用目前設定估算。</p>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="http-recovery-title">
        <div class="panel-header">
            <div>
                <h2 id="http-recovery-title" class="panel-title">HTTP 故障確認後恢復時間</h2>
                <p class="panel-description">以最近 30 天內已恢復的 HTTP 事件計算，從事件確認成立到確認恢復；排除誤報、未確認及尚未恢復的事件。</p>
            </div>
            @if ($httpRecoveryStats['count'] > 0)
                <span class="secondary-text">{{ $httpRecoveryStats['count'] }} 件</span>
            @endif
        </div>
        @if ($httpRecoveryStats['count'] === 0)
            <div class="empty-state">
                <h3 class="empty-title">尚無已恢復的 HTTP 故障事件</h3>
                <p class="empty-copy">近 30 天內完成確認並恢復的 HTTP 異常會列入統計。</p>
            </div>
        @else
            <div class="stat-grid">
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">平均確認後恢復時間</span></div>
                    <strong class="stat-value">{{ number_format($httpRecoveryStats['average_minutes'], 1) }} 分鐘</strong>
                    <p class="stat-hint">從事件確認成立至確認恢復的平均時間</p>
                </article>
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">最長確認後恢復時間</span></div>
                    <strong class="stat-value">{{ number_format($httpRecoveryStats['maximum_minutes'], 1) }} 分鐘</strong>
                    <p class="stat-hint">近 30 天已恢復 HTTP 事件中的最長時間</p>
                </article>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="response-time-title">
        <div class="panel-header">
            <div>
                <h2 id="response-time-title" class="panel-title">HTTP 探測耗時</h2>
                <p class="panel-description">最近 24 小時的一般使用者代理檢查，包含連線、TLS 與重新導向耗時。</p>
            </div>
            @if ($responseTime['count'] > 0)
                <span class="secondary-text">{{ $responseTime['count'] }} 筆</span>
            @endif
        </div>
        @if ($responseTime['count'] === 0)
            <div class="empty-state">
                <h3 class="empty-title">尚無 HTTP 探測耗時</h3>
                <p class="empty-copy">新檢查會開始記錄一般使用者代理的耗時。舊紀錄只有一般檢查與 AdsBot 模擬的合計時間。</p>
            </div>
        @else
            <div class="response-time-chart">
                <figure class="response-time-figure">
                    <svg class="response-time-svg" viewBox="0 0 600 190" role="img" aria-labelledby="response-time-chart-title response-time-chart-description">
                        <title id="response-time-chart-title">最近 24 小時 HTTP 探測耗時趨勢</title>
                        <desc id="response-time-chart-description">共 {{ $responseTime['count'] }} 筆；平均 {{ number_format($responseTime['average_ms']) }} 毫秒，P95 {{ number_format($responseTime['p95_ms']) }} 毫秒（95% 探測耗時不高於此值），最快 {{ number_format($responseTime['minimum_ms']) }} 毫秒，最慢 {{ number_format($responseTime['maximum_ms']) }} 毫秒。</desc>
                        @foreach ($responseTimeAxis as $tick)
                            <line class="response-time-grid-line" x1="48" x2="570" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" />
                            <text class="response-time-axis-label" x="42" y="{{ $tick['y'] + 4 }}" text-anchor="end">{{ number_format($tick['value']) }} ms</text>
                        @endforeach
                        <line class="response-time-grid-line" x1="48" x2="48" y1="28" y2="144" />
                        @foreach ($responseTime['segments'] as $segment)
                            <polyline class="response-time-line" points="{{ $segment }}" />
                        @endforeach
                        @foreach ($responseTime['points'] as $point)
                            <circle class="response-time-point" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3" />
                        @endforeach
                        <text class="response-time-axis-label" x="48" y="170" text-anchor="start">24 小時前</text>
                        <text class="response-time-axis-label" x="309" y="170" text-anchor="middle">12 小時前</text>
                        <text class="response-time-axis-label" x="570" y="170" text-anchor="end">現在</text>
                    </svg>
                    <figcaption class="response-time-caption">
                        平均 {{ number_format($responseTime['average_ms']) }} 毫秒 · P95 {{ number_format($responseTime['p95_ms']) }} 毫秒（95% 探測耗時不高於此值） · 最快 {{ number_format($responseTime['minimum_ms']) }} 毫秒 · 最慢 {{ number_format($responseTime['maximum_ms']) }} 毫秒
                    </figcaption>
                </figure>

                <details class="response-time-data">
                    <summary>查看圖表資料表（{{ $responseTime['count'] }} 筆）</summary>
                    <div class="data-table-wrap">
                        <table class="data-table">
                            <caption class="sr-only">最近 24 小時的一般 HTTP 探測耗時資料</caption>
                            <thead>
                                <tr>
                                    <th scope="col">完成時間</th>
                                    <th scope="col">HTTP 狀態</th>
                                    <th scope="col">探測耗時</th>
                                    <th scope="col">訊號</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($responseTime['points'] as $point)
                                    <tr>
                                        <td><time class="timestamp" datetime="{{ $point['finished_at']->toIso8601String() }}">{{ $point['finished_at']->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time></td>
                                        <td class="numeric">{{ $point['normal_status'] ?? '—' }}</td>
                                        <td class="numeric">{{ number_format($point['elapsed_ms']) }} 毫秒</td>
                                        <td>{{ $point['error_type'] ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="dns-change-history-title">
        <div class="panel-header">
            <div>
                <h2 id="dns-change-history-title" class="panel-title">DNS 位址變更</h2>
                <p class="panel-description">顯示最近 50 筆已完成排程檢查偵測到的公開 A/AAAA IP 回答變更，也包含主機暫時沒有公開 IP 回答的中斷。</p>
            </div>
            <a class="button button-secondary button-small" href="{{ route('monitors.dns-changes.export', $monitor) }}">匯出 DNS 變更 CSV</a>
        </div>

        @if ($dnsChanges->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">尚無 DNS 位址變更記錄</h3>
                <p class="empty-copy">累積至少兩次排程檢查後，若主機公開 IP 回答改變或變成無公開回答，變更紀錄會顯示於此處。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="dns-change-history-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">{{ $monitor->name }}最近 50 筆主機 DNS 公開 IP 位址變更</caption>
                    <thead>
                        <tr>
                            <th scope="col">偵測時間</th>
                            <th scope="col">主機先前 IP</th>
                            <th scope="col">主機目前 IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dnsChanges as $dnsChange)
                            <tr>
                                <td><time class="timestamp" datetime="{{ $dnsChange->detected_at->toIso8601String() }}">{{ $dnsChange->detected_at->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time></td>
                                <td class="numeric">
                                    {{ $dnsChange->previousIpsLabel() }}
                                    @unless ($dnsChange->monitor_host_only)<span class="secondary-text">舊版紀錄，未區分重新導向主機</span>@endunless
                                </td>
                                <td class="numeric">
                                    {{ $dnsChange->currentIpsLabel() }}
                                    @unless ($dnsChange->monitor_host_only)<span class="secondary-text">舊版紀錄，未區分重新導向主機</span>@endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="incident-history-title">
        <div class="panel-header">
            <div>
                <h2 id="incident-history-title" class="panel-title">事件紀錄</h2>
                <p class="panel-description">分類偵測訊號，並留下簡短備註供日後檢視。</p>
            </div>
            <a class="button button-secondary button-small" href="{{ route('incidents.index', ['monitor_id' => $monitor->id]) }}">查看此監測的全部事件</a>
        </div>
        @if ($incidents->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">尚無事件紀錄</h3>
                <p class="empty-copy">檢查持續發現異常時，相關事件與歷程會列於此處。</p>
            </div>
        @else
            <div class="incident-list">
                @foreach ($incidents as $incident)
                    <article class="incident-card" aria-labelledby="incident-{{ $incident->id }}-title">
                        <div class="incident-card-header">
                            <div>
                                <h3 id="incident-{{ $incident->id }}-title" class="incident-card-title">
                                    {{ $ruleLabels[$incident->rule_key] ?? '其他監測異常' }}
                                    <x-state-badge :state="$incident->state" />
                                </h3>
                                <div class="incident-meta">
                                    <span>{{ $incident->severity === 'critical' ? '嚴重' : '警告' }}</span>
                                    <span>設定版本 {{ $incident->config_version }}</span>
                                    <span>{{ $incident->failure_count }} 次失敗</span>
                                    @if ($incident->opened_at)
                                        <span>成立於 <time datetime="{{ $incident->opened_at->toIso8601String() }}">{{ $incident->opened_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></span>
                                    @endif
                                    @if ($incident->resolved_at)
                                        <span>恢復於 <time datetime="{{ $incident->resolved_at->toIso8601String() }}">{{ $incident->resolved_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></span>
                                    @endif
                                    @if ($incident->acknowledged_at)
                                        <span>由 {{ $incident->acknowledgedBy?->name ?? '帳戶已刪除' }} 接手</span>
                                        <span>接手於 <time datetime="{{ $incident->acknowledged_at->toIso8601String() }}">{{ $incident->acknowledged_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></span>
                                    @endif
                                </div>
                            </div>
                            @if ($incident->classification)
                                <x-state-badge :state="$incident->classification" />
                            @endif
                        </div>

                        <div class="incident-card-body">
                            @if ($incident->events->isNotEmpty())
                                <ol class="timeline-list" aria-label="事件歷程">
                                    @foreach ($incident->events as $event)
                                        <li class="timeline-item">
                                            <span class="timeline-rail"><span class="timeline-point" aria-hidden="true"></span></span>
                                            <span class="timeline-copy">
                                                {{ $eventLabels[$event->event_type] ?? '事件狀態更新' }}
                                                @if ($event->event_type === 'escalation_queued' && isset($event->evidence['stage']))
                                                    <span class="secondary-text">第 {{ $event->evidence['stage'] }} 階段</span>
                                                @endif
                                                @if ($incident->rule_key === 'response_time' && isset($event->evidence['threshold_ms'], $event->evidence['elapsed_ms']))
                                                    <span class="secondary-text">HTTP 回應 {{ number_format((int) $event->evidence['elapsed_ms']) }} 毫秒；門檻 {{ number_format((int) $event->evidence['threshold_ms']) }} 毫秒</span>
                                                @endif
                                                @if (in_array($incident->rule_key, ['content', 'forbidden_text'], true) && isset($event->evidence['expected_text_mode']) && array_key_exists('expected_text_found', $event->evidence))
                                                    @php
                                                        $eventExpectedTextMatchResult = $event->checkRun !== null
                                                            ? $event->checkRun->expectedTextMatchResult()
                                                            : $event->evidence['expected_text_found'];
                                                    @endphp
                                                    <span class="secondary-text">
                                                        條件：{{ match ($event->evidence['expected_text_mode']) { 'absent' => '不得出現任一片段', 'present_any' => '至少包含一個片段', default => '必須包含全部片段' } }}；
                                                        比對：{{ $eventExpectedTextMatchResult === null ? '無法判定' : match ($event->evidence['expected_text_mode']) { 'absent' => $eventExpectedTextMatchResult ? '原始回應找到禁止文字' : '原始回應未找到禁止文字', 'present_any' => $eventExpectedTextMatchResult ? '原始回應找到至少一個片段' : '原始回應未找到任何片段', default => $eventExpectedTextMatchResult ? '原始回應找到全部片段' : '原始回應缺少至少一個片段' } }}
                                                    </span>
                                                @endif
                                                @if ($incident->rule_key === 'response_header' && isset($event->evidence['expected_response_header_name']) && array_key_exists('expected_response_header_found', $event->evidence))
                                                    @php
                                                        $eventExpectedResponseHeaderMatchResult = $event->checkRun !== null
                                                            ? $event->checkRun->expectedResponseHeaderMatchResult()
                                                            : $event->evidence['expected_response_header_found'];
                                                    @endphp
                                                    <span class="secondary-text">標頭 {{ $event->evidence['expected_response_header_name'] }}；比對：{{ $eventExpectedResponseHeaderMatchResult === null ? '無法判定' : ($eventExpectedResponseHeaderMatchResult ? '符合' : '不符合') }}</span>
                                                @endif
                                                @if (in_array($event->event_type, ['acknowledged', 'unacknowledged', 'notifications_snoozed', 'notifications_resumed', 'classification_changed', 'incident_note_added', 'incident_note_cleared'], true))
                                                    <span class="secondary-text">由 {{ $event->evidence['actor_name'] ?? '使用者' }}</span>
                                                @endif
                                                @if ($event->event_type === 'notifications_snoozed' && isset($event->evidence['duration_minutes'], $event->evidence['snoozed_until']))
                                                    <span class="secondary-text">暫停 {{ $event->evidence['duration_minutes'] }} 分鐘，至 {{ \Illuminate\Support\Carbon::parse($event->evidence['snoozed_until'])->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</span>
                                                @endif
                                                @if ($event->event_type === 'classification_changed')
                                                    <span class="secondary-text">{{ $classificationLabels[$event->evidence['from'] ?? ''] ?? '未分類' }} → {{ $classificationLabels[$event->evidence['to'] ?? ''] ?? '未分類' }}</span>
                                                @endif
                                                @if ($event->event_type === 'incident_note_added' && isset($event->evidence['note']))
                                                    <span class="incident-note-display">{{ $event->evidence['note'] }}</span>
                                                @endif
                                                <time class="secondary-text" datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->timezone('Asia/Taipei')->format('Y/n/j · H:i:s') }}</time>
                                            </span>
                                        </li>
                                    @endforeach
                                </ol>
                            @else
                                <p class="field-help">此事件尚無歷程資料。</p>
                            @endif

                            @if ($incident->note)
                                <p class="incident-note-display"><strong>已儲存備註：</strong> {{ $incident->note }}</p>
                            @endif

                            @if ($incident->state === \App\Models\Incident::STATE_OPEN)
                                @php
                                    $snoozeErrorKey = 'duration_minutes.'.$incident->id;
                                    $snoozeError = $errors->first($snoozeErrorKey);
                                    $snoozedUntil = $incident->snoozed_until;
                                    $notificationsAreSnoozed = $snoozedUntil !== null && $snoozedUntil->isFuture();
                                @endphp
                                <p class="field-help">此功能只暫緩持續提醒與升級通知，不會延後最初告警或恢復通知。</p>
                                @if ($notificationsAreSnoozed)
                                    <p class="field-help">提醒與升級目前暫停至 <time datetime="{{ $snoozedUntil->toIso8601String() }}">{{ $snoozedUntil->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>。</p>
                                    <form class="form-actions" action="{{ route('incidents.notifications.snooze.destroy', $incident) }}" method="POST" aria-labelledby="incident-{{ $incident->id }}-title">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button button-secondary button-small" type="submit">立即恢復通知</button>
                                    </form>
                                @endif
                                <form class="incident-snooze-form" action="{{ route('incidents.notifications.snooze', $incident) }}" method="POST" aria-labelledby="incident-{{ $incident->id }}-title">
                                    @csrf
                                    <fieldset class="incident-snooze-settings" aria-describedby="incident-{{ $incident->id }}-snooze-help{{ $snoozeError ? ' incident-'.$incident->id.'-snooze-error' : '' }}">
                                        <legend class="field-label">暫停提醒與升級多久</legend>
                                        <p id="incident-{{ $incident->id }}-snooze-help" class="field-help">暫停期間新提醒不會排入；已排隊的提醒與升級會保留，恢復後再處理。</p>
                                        <div class="incident-snooze-options">
                                            @foreach ([30 => '30 分鐘', 60 => '1 小時', 240 => '4 小時', 1440 => '24 小時'] as $duration => $label)
                                                <label class="field-check incident-snooze-option" for="incident-{{ $incident->id }}-snooze-{{ $duration }}">
                                                    <input id="incident-{{ $incident->id }}-snooze-{{ $duration }}" name="duration_minutes[{{ $incident->id }}]" type="radio" value="{{ $duration }}" required @checked((int) old($snoozeErrorKey, 60) === $duration) @if ($snoozeError) aria-invalid="true" @if ($loop->first) autofocus @endif @endif>
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                        @if ($snoozeError)
                                            <p id="incident-{{ $incident->id }}-snooze-error" class="field-error" role="alert">{{ $snoozeError }}</p>
                                        @endif
                                    </fieldset>
                                    <div class="form-actions">
                                        <button class="button button-secondary button-small" type="submit">{{ $notificationsAreSnoozed ? '重新設定暫停時間' : '暫停通知' }}</button>
                                    </div>
                                </form>
                            @endif

                            @if (in_array($incident->state, [\App\Models\Incident::STATE_SUSPECT, \App\Models\Incident::STATE_OPEN], true))
                                <p class="field-help">認領只記錄接手人，不會自動結束事件或停止告警通知。</p>
                                <div class="form-actions">
                                    @if ($incident->acknowledged_at)
                                        <form action="{{ route('incidents.acknowledgement.destroy', $incident) }}" method="POST" aria-labelledby="incident-{{ $incident->id }}-title">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button button-secondary button-small" type="submit">解除認領</button>
                                        </form>
                                    @else
                                        <form action="{{ route('incidents.acknowledgement.store', $incident) }}" method="POST" aria-labelledby="incident-{{ $incident->id }}-title">
                                            @csrf
                                            <button class="button button-secondary button-small" type="submit">認領處理</button>
                                        </form>
                                    @endif
                                </div>
                            @endif

                            <form class="incident-form-grid" action="{{ route('incidents.update', $incident) }}" method="POST">
                                @csrf
                                @method('PATCH')
                                <div class="field-group">
                                    <label class="field-label" for="classification-{{ $incident->id }}">事件分類</label>
                                    <select class="field-select @error('classification') is-invalid @enderror" id="classification-{{ $incident->id }}" name="classification" aria-label="分類事件 {{ $incident->id }}">
                                        <option value="">未分類</option>
                                        <option value="true_issue" @selected(old('classification', $incident->classification) === 'true_issue')>確認為真實異常</option>
                                        <option value="false_alarm" @selected(old('classification', $incident->classification) === 'false_alarm')>確認為誤報</option>
                                        <option value="unknown" @selected(old('classification', $incident->classification) === 'unknown')>尚不確定</option>
                                    </select>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="note-{{ $incident->id }}">檢視備註</label>
                                    <textarea class="field-textarea @error('note') is-invalid @enderror" id="note-{{ $incident->id }}" name="note" rows="2" maxlength="1000" placeholder="補充下次檢視時需要的資訊">{{ old('note', $incident->note) }}</textarea>
                                </div>
                                <div><button class="button button-secondary button-small" type="submit">儲存檢視結果</button></div>
                            </form>
                            @error('classification')<p class="field-error">{{ $message }}</p>@enderror
                            @error('note')<p class="field-error">{{ $message }}</p>@enderror

                            @if ($incident->outboxMessages->isNotEmpty())
                                <div class="notification-list" aria-label="通知傳送紀錄">
                                    @foreach ($incident->outboxMessages as $outbox)
                                        <div class="notification-row">
                                            <span>{{ $eventLabels[$outbox->event_type] ?? '事件狀態更新' }}通知</span>
                                            <span class="notification-state">
                                                <x-state-badge :state="$outbox->status" />
                                                <span>嘗試 {{ $outbox->attempts }} 次</span>
                                            </span>
                                        </div>
                                        @if (in_array($outbox->status, ['failed', 'unknown'], true))
                                            <form class="acknowledge-box" action="{{ route('notifications.retry', $outbox) }}" method="POST">
                                                @csrf
                                                <label class="field-check">
                                                    <input type="checkbox" name="acknowledge_duplicate_risk" value="1" required>
                                                    <span>我了解重試可能會重複傳送通知。</span>
                                                </label>
                                                <button class="button button-secondary button-small" type="submit">重試傳送</button>
                                            </form>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="check-history-title">
        <div class="panel-header">
            <div>
                <h2 id="check-history-title" class="panel-title">檢查紀錄</h2>
                <p class="panel-description">顯示保留期間內的檢查結果；可展開查看最終網址與重新導向路徑，網址中的敏感參數會遮罩。</p>
            </div>
            <div class="row-actions">
                <a class="button button-secondary button-small" href="{{ $checkRunExportUrl }}">匯出檢查 CSV</a>
                <span class="secondary-text">
                    @if ($hasCheckRunFilters)
                        符合條件 {{ $runs->total() }} 次
                    @else
                        共 {{ $runs->total() }} 次
                    @endif
                </span>
            </div>
        </div>

        <search class="check-history-search" aria-labelledby="check-history-filter-title">
            <h3 id="check-history-filter-title">篩選檢查紀錄</h3>
            <form class="check-history-filter" action="{{ route('monitors.show', $monitor) }}" method="GET" aria-labelledby="check-history-filter-title">
                <div class="field-group">
                    <label class="field-label" for="check-run-status">執行狀態</label>
                    <select
                        class="field-select @error('status') is-invalid @enderror"
                        id="check-run-status"
                        name="status"
                        @if ($errors->has('status')) aria-invalid="true" aria-describedby="check-run-status-error" @endif
                    >
                        <option value="">所有狀態</option>
                        @foreach ($checkRunStatusOptions as $statusValue => $statusLabel)
                            <option value="{{ $statusValue }}" @selected($checkRunFilters['status'] === $statusValue)>{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                    @error('status')
                        <p id="check-run-status-error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field-group">
                    <label class="field-label" for="check-run-source">檢查來源</label>
                    <select
                        class="field-select @error('source') is-invalid @enderror"
                        id="check-run-source"
                        name="source"
                        @if ($errors->has('source')) aria-invalid="true" aria-describedby="check-run-source-error" @endif
                    >
                        <option value="">所有來源</option>
                        @foreach ($checkRunSourceOptions as $sourceValue => $sourceLabel)
                            <option value="{{ $sourceValue }}" @selected($checkRunFilters['source'] === $sourceValue)>{{ $sourceLabel }}</option>
                        @endforeach
                    </select>
                    @error('source')
                        <p id="check-run-source-error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field-group">
                    <label class="field-label" for="check-run-http-status">HTTP 回應碼</label>
                    <input
                        class="field-input @error('http_status') is-invalid @enderror"
                        id="check-run-http-status"
                        name="http_status"
                        type="number"
                        inputmode="numeric"
                        min="100"
                        max="599"
                        step="1"
                        value="{{ $checkRunFilters['http_status'] }}"
                        aria-describedby="{{ $errors->has('http_status') ? 'check-run-http-status-help check-run-http-status-error' : 'check-run-http-status-help' }}"
                        @if ($errors->has('http_status')) aria-invalid="true" @endif
                    >
                    <p id="check-run-http-status-help" class="field-help">輸入 100–599 的狀態碼，例如 403 或 429。</p>
                    @error('http_status')
                        <p id="check-run-http-status-error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field-group">
                    <label class="field-label" for="check-run-signal">訊號代碼</label>
                    <input
                        class="field-input @error('signal') is-invalid @enderror"
                        id="check-run-signal"
                        name="signal"
                        type="text"
                        maxlength="40"
                        value="{{ $checkRunFilters['signal'] }}"
                        autocomplete="off"
                        spellcheck="false"
                        aria-describedby="{{ $errors->has('signal') ? 'check-run-signal-help check-run-signal-error' : 'check-run-signal-help' }}"
                        @if ($errors->has('signal')) aria-invalid="true" @endif
                    >
                    <p id="check-run-signal-help" class="field-help">輸入表格中的代碼精確篩選，例如 dns_timeout 或 tls_verify；一般與 AdsBot 訊號都會搜尋。</p>
                    @error('signal')
                        <p id="check-run-signal-error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field-group">
                    <label class="field-label" for="check-run-date-from">開始日期</label>
                    <input
                        class="field-input @error('date_from') is-invalid @enderror"
                        id="check-run-date-from"
                        name="date_from"
                        type="date"
                        value="{{ $checkRunFilters['date_from'] }}"
                        @if ($errors->has('date_from')) aria-invalid="true" aria-describedby="check-run-date-from-error" @endif
                    >
                    @error('date_from')
                        <p id="check-run-date-from-error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field-group">
                    <label class="field-label" for="check-run-date-to">結束日期</label>
                    <input
                        class="field-input @error('date_to') is-invalid @enderror"
                        id="check-run-date-to"
                        name="date_to"
                        type="date"
                        value="{{ $checkRunFilters['date_to'] }}"
                        @if ($errors->has('date_to')) aria-invalid="true" aria-describedby="check-run-date-to-error" @endif
                    >
                    @error('date_to')
                        <p id="check-run-date-to-error" class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                @if ($monitor->expected_text)
                    <div class="field-group">
                        <label class="field-label" for="check-run-content-match">文字比對結果</label>
                        <select
                            class="field-select @error('content_match') is-invalid @enderror"
                            id="check-run-content-match"
                            name="content_match"
                            @if ($errors->has('content_match')) aria-invalid="true" aria-describedby="check-run-content-match-error" @endif
                        >
                            <option value="">所有比對結果</option>
                            @foreach ($checkRunContentMatchOptions as $matchValue => $matchLabel)
                                <option value="{{ $matchValue }}" @selected($checkRunFilters['content_match'] === $matchValue)>{{ $matchLabel }}</option>
                            @endforeach
                        </select>
                        <p class="field-help">篩選依每次檢查當時的文字比對結果；歷史紀錄會顯示當次使用的條件。</p>
                        @error('content_match')
                            <p id="check-run-content-match-error" class="field-error">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                <p class="field-help">日期採台灣時間，開始與結束日期均包含當日。</p>

                <div class="check-history-filter-actions">
                    <button class="button button-primary button-small" type="submit">套用篩選</button>
                    @if ($hasCheckRunFilters)
                        <a class="button button-quiet button-small" href="{{ route('monitors.show', $monitor) }}">清除篩選</a>
                    @endif
                </div>
            </form>
        </search>

        @if ($runs->isEmpty())
            <div class="empty-state">
                @if ($hasCheckRunFilters)
                    <h3 class="empty-title">沒有符合篩選條件的檢查紀錄</h3>
                    <p class="empty-copy">請調整篩選條件，或清除篩選查看所有檢查紀錄。</p>
                @else
                    <h3 class="empty-title">尚無檢查紀錄</h3>
                    <p class="empty-copy">排程或手動檢查執行後，紀錄會顯示於此處。</p>
                @endif
            </div>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">{{ $monitor->name }}的檢查紀錄</caption>
                    <thead>
                        <tr>
                            <th scope="col">排定時間</th>
                            <th scope="col">來源</th>
                            <th scope="col">執行狀態</th>
                            <th scope="col">HTTP</th>
                            <th scope="col">AdsBot</th>
                            <th scope="col">頁面回應摘要</th>
                            @if ($monitor->expected_text)
                                <th scope="col">文字比對</th>
                            @endif
                            @if ($monitor->expectedResponseHeaderName() !== null)
                                <th scope="col">回應標頭</th>
                            @endif
                            <th scope="col">檢查總耗時</th>
                            <th scope="col">一般／AdsBot 訊號</th>
                            <th scope="col">網址路徑</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr>
                                <td>
                                    <time class="timestamp" datetime="{{ $run->scheduled_at->toIso8601String() }}">{{ $run->scheduled_at->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time>
                                </td>
                                <td>{{ ['manual' => '手動檢查', 'confirmation' => '確認延遲檢查', 'tls_daily' => '每日 TLS 檢查', 'domain_daily' => '每日網域期限檢查'][$run->source] ?? '排程檢查' }}</td>
                                <td><x-state-badge :state="$run->status" /></td>
                                <td class="numeric">{{ $run->normal_status ?? '—' }}</td>
                                <td class="numeric">{{ $run->adsbot_status ?? ($socialPlatform ? '略過' : '—') }}</td>
                                <td>
                                    @if ($run->response_title || $run->response_description)
                                        <details>
                                            <summary>查看回應摘要</summary>
                                            @if ($run->response_title)
                                                <p>分享標題：{{ $run->response_title }}</p>
                                            @endif
                                            @if ($run->response_description)
                                                <p>分享摘要：{{ $run->response_description }}</p>
                                            @endif
                                        </details>
                                    @else
                                        <span class="secondary-text">未提供</span>
                                    @endif
                                </td>
                                @if ($monitor->expected_text)
                                    <td>
                                        <p>{{ $run->expectedTextMatchResult() === null ? '未進行比對或無法判定' : match ($run->expectedTextMode()) { 'absent' => $run->expectedTextMatchResult() ? '找到禁止文字' : '未找到禁止文字', 'present_any' => $run->expectedTextMatchResult() ? '找到至少一個指定片段' : '未找到任何指定片段', default => $run->expectedTextMatchResult() ? '找到全部指定片段' : '缺少至少一個指定片段' } }}</p>
                                        @php
                                            $textMatchDetails = $run->expectedTextMatchDetails();
                                        @endphp
                                        @if ($textMatchDetails !== null)
                                            <details>
                                                <summary>查看逐項片段結果</summary>
                                                <ul aria-label="逐項文字片段結果">
                                                    @foreach ($textMatchDetails as $matchDetail)
                                                        <li>{{ $matchDetail['term'] }}：{{ $matchDetail['matched'] === null ? '回應截斷，無法判定' : ($matchDetail['matched'] ? '原始回應有此片段' : '原始回應未找到此片段') }}</li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @endif
                                    </td>
                                @endif
                                @if ($monitor->expectedResponseHeaderName() !== null)
                                    <td>{{ $run->expectedResponseHeaderMatchResult() === null ? '未檢查或無法判定' : ($run->expectedResponseHeaderMatchResult() ? '符合' : '不符合') }}</td>
                                @endif
                                <td class="numeric">{{ $run->elapsed_ms !== null ? number_format($run->elapsed_ms).' 毫秒' : '—' }}</td>
                                <td>
                                    @if ($run->error_type)
                                        <p>一般探測：{{ $run->error_type }}</p>
                                    @endif
                                    @if ($run->adsbot_error_type)
                                        <p>AdsBot 模擬：{{ $run->adsbot_error_type }}</p>
                                    @endif
                                    @if (! $run->error_type && ! $run->adsbot_error_type)
                                        <span class="secondary-text">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($run->masked_final_url || $run->masked_redirects !== [])
                                        <details>
                                            <summary>{{ $run->masked_redirects === [] ? '查看最終網址' : '查看 '.count($run->masked_redirects).' 次重新導向' }}</summary>
                                            @if ($run->masked_final_url)
                                                <p>最終網址：<span class="masked-url">{{ $run->masked_final_url }}</span></p>
                                            @endif
                                            @if ($run->masked_redirects !== [])
                                                <p>重新導向路徑：</p>
                                                <ol>
                                                    @foreach ($run->masked_redirects as $redirectUrl)
                                                        <li><span class="masked-url">{{ $redirectUrl }}</span></li>
                                                    @endforeach
                                                </ol>
                                            @endif
                                        </details>
                                    @else
                                        <span class="secondary-text">未記錄</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($runs->hasPages())
                <div class="panel-body pagination-wrap">{{ $runs->links('vendor.pagination.monitor') }}</div>
            @endif
        @endif
    </section>

    <div class="danger-zone spaced-top">
        <div>
            <h2 class="danger-zone-title">刪除監測項目</h2>
            <p>刪除後也會一併移除此監測項目的檢查與事件紀錄。</p>
        </div>
        <form action="{{ route('monitors.destroy', $monitor) }}" method="POST" data-confirm="確定刪除此監測項目及其所有紀錄？此操作無法復原。">
            @csrf
            @method('DELETE')
            <button class="button button-danger" type="submit">刪除監測項目</button>
        </form>
    </div>
@endsection
