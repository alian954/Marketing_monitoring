@extends('layouts.app')

@php
    $healthState = $monitor->getAttribute('health_state') ?? $monitor->healthState();
    $allowedRedirectHosts = is_array($monitor->allowed_redirect_hosts) ? $monitor->allowed_redirect_hosts : [];
    $intervalLabel = $monitor->interval_seconds >= 3600 && $monitor->interval_seconds % 3600 === 0
        ? intdiv($monitor->interval_seconds, 3600).' 小時'
        : ($monitor->interval_seconds >= 60
            ? intdiv($monitor->interval_seconds, 60).' 分鐘'
            : $monitor->interval_seconds.' 秒');
    $ruleLabels = [
        'http' => 'HTTP 回應異常',
        'probe_restricted' => '檢查受到限制',
        'content' => '缺少預期文字',
        'forbidden_text' => '回應包含不應出現的文字',
        'response_time' => 'HTTP 回應時間超過設定門檻',
        'adsbot_difference' => 'AdsBot 模擬結果不同',
        'tls_expired' => 'TLS 憑證已過期',
        'tls_handshake' => 'TLS 交握失敗',
        'tls_7d' => 'TLS 憑證將於 7 天內到期',
        'tls_14d' => 'TLS 憑證將於 14 天內到期',
    ];
    $eventLabels = [
        'failure_seen' => '發現異常',
        'opened' => '事件已確認',
        'false_alarm' => '確認為誤報',
        'recovery_seen' => '發現恢復跡象',
        'recovered' => '網站已恢復',
        'paused' => '監測已暫停',
        'superseded' => '舊事件已因設定變更而結束',
    ];
    $responseTimeAxis = [
        ['y' => 28, 'value' => $responseTime['scale_max_ms']],
        ['y' => 86, 'value' => (int) round($responseTime['scale_max_ms'] / 2)],
        ['y' => 144, 'value' => 0],
    ];
    $maintenanceWindows = $monitor->maintenanceWindows()
        ->where('ends_at', '>', now())
        ->orderBy('starts_at')
        ->get();
    $activeMaintenanceWindow = $monitor->maintenanceWindows()
        ->where('starts_at', '<=', now())
        ->where('ends_at', '>', now())
        ->first();
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
    @error('check')
        <div class="alert alert-error" role="alert">{{ $message }}</div>
    @enderror
    @if ($activeMaintenanceWindow)
        <div class="form-note spaced-bottom" role="status">
            維護時段進行中：{{ $activeMaintenanceWindow->reason }}。檢查會持續執行；期間內成立的事件不會建立通知，已排入佇列的通知仍照原排程處理。時段結束於
            <time datetime="{{ $activeMaintenanceWindow->ends_at->toIso8601String() }}">{{ $activeMaintenanceWindow->ends_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>。
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
                        <dt>預期回應文字</dt>
                        <dd>
                            @if ($monitor->expected_text)
                                {{ $monitor->expected_text_mode === 'absent' ? '不得包含：' : '必須包含：' }}{{ $monitor->expected_text }}
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
                    <p class="panel-description">最近的檢查結果與回應訊號。</p>
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
                            <dd>{{ $monitor->latestCheckRun->adsbot_status ?? '未記錄' }}</dd>
                        </div>
                        <div class="metadata-item">
                                <dt>文字比對結果</dt>
                            <dd>
                                @if ($monitor->latestCheckRun->expected_text_found === null)
                                    未檢查
                                @elseif ($monitor->latestCheckRun->expected_text_found)
                                        已找到指定文字
                                @else
                                        未找到指定文字
                                @endif
                            </dd>
                        </div>
                        @if ($monitor->latestCheckRun->error_type || $monitor->latestCheckRun->adsbot_error_type)
                            <div class="metadata-item field-group-full">
                                <dt>檢查訊號</dt>
                                <dd>{{ $monitor->latestCheckRun->error_type ?: $monitor->latestCheckRun->adsbot_error_type }}</dd>
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
                <p class="empty-copy">每個監測項目的單次維護時段最長 14 天，且不能彼此重疊。</p>
            </div>
        @else
            <div class="incident-list">
                @foreach ($maintenanceWindows as $maintenanceWindow)
                    <article class="incident-card">
                        <div class="incident-card-header">
                            <div>
                                <h3 class="incident-card-title">{{ $maintenanceWindow->starts_at->lte(now()) ? '進行中' : '已排定' }}</h3>
                                <p>{{ $maintenanceWindow->reason }}</p>
                                <div class="incident-meta">
                                    <span>
                                        <time datetime="{{ $maintenanceWindow->starts_at->toIso8601String() }}">{{ $maintenanceWindow->starts_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                        至
                                        <time datetime="{{ $maintenanceWindow->ends_at->toIso8601String() }}">{{ $maintenanceWindow->ends_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                        （台灣時間）
                                    </span>
                                </div>
                            </div>
                            <form class="inline-form" action="{{ route('monitors.maintenance-windows.destroy', [$monitor, $maintenanceWindow]) }}" method="POST" data-confirm="{{ $maintenanceWindow->starts_at->lte(now()) ? '取消維護時段會立即恢復事件通知。確定繼續？' : '確定取消這個維護時段？' }}">
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
                    <h3 id="maintenance-form-title" class="form-section-title">排定單次維護</h3>
                    <p class="form-section-copy">時間以台灣時間輸入。維護期間仍會更新檢查狀態與事件紀錄，但不會建立新的事件通知。</p>
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
                            <p id="ends-at-help" class="field-help">必須晚於開始時間；單次時段最多 14 天。</p>
                            @error('ends_at')<p id="ends-at-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
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

    <section class="panel spaced-top" aria-labelledby="availability-title">
        <div class="panel-header">
            <div>
                <h2 id="availability-title" class="panel-title">歷史檢查成功率</h2>
                <p class="panel-description">以完成的排程檢查計算成功率，不估算檢查間隔內的時間；只把符合目前 HTTP 狀態設定且沒有連線錯誤的檢查列為成功。</p>
            </div>
        </div>
        <div class="stat-grid monitor-availability-grid" aria-label="不同期間的檢查成功率">
            @foreach ($availabilityStats as $stat)
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">最近 {{ $stat['label'] }}</span></div>
                    <strong class="stat-value">{{ $stat['availability'] === null ? '—' : number_format($stat['availability'], 2).'%' }}</strong>
                    <p class="stat-hint">成功 {{ $stat['successful_checks'] }} / 完成 {{ $stat['completed_checks'] }} 次</p>
                </article>
            @endforeach
        </div>
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
                        <desc id="response-time-chart-description">共 {{ $responseTime['count'] }} 筆；平均 {{ number_format($responseTime['average_ms']) }} 毫秒，最快 {{ number_format($responseTime['minimum_ms']) }} 毫秒，最慢 {{ number_format($responseTime['maximum_ms']) }} 毫秒。</desc>
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
                        平均 {{ number_format($responseTime['average_ms']) }} 毫秒 · 最快 {{ number_format($responseTime['minimum_ms']) }} 毫秒 · 最慢 {{ number_format($responseTime['maximum_ms']) }} 毫秒
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

    <section class="panel spaced-top" aria-labelledby="incident-history-title">
        <div class="panel-header">
            <div>
                <h2 id="incident-history-title" class="panel-title">事件紀錄</h2>
                <p class="panel-description">分類偵測訊號，並留下簡短備註供日後檢視。</p>
            </div>
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
                                                @if ($incident->rule_key === 'response_time' && isset($event->evidence['threshold_ms'], $event->evidence['elapsed_ms']))
                                                    <span class="secondary-text">HTTP 回應 {{ number_format((int) $event->evidence['elapsed_ms']) }} 毫秒；門檻 {{ number_format((int) $event->evidence['threshold_ms']) }} 毫秒</span>
                                                @endif
                                                @if (in_array($incident->rule_key, ['content', 'forbidden_text'], true) && isset($event->evidence['expected_text_mode']) && array_key_exists('expected_text_found', $event->evidence))
                                                    <span class="secondary-text">
                                                        條件：{{ $event->evidence['expected_text_mode'] === 'absent' ? '不得包含' : '必須包含' }}；
                                                        比對：{{ $event->evidence['expected_text_found'] === null ? '無法判定' : ($event->evidence['expected_text_found'] ? '已找到指定文字' : '未找到指定文字') }}
                                                    </span>
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
                <p class="panel-description">最近 100 次執行結果；不顯示敏感的目的地詳細資料。</p>
            </div>
            <span class="secondary-text">共 {{ $runs->count() }} 次</span>
        </div>
        @if ($runs->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">尚無檢查紀錄</h3>
                <p class="empty-copy">排程或手動檢查執行後，紀錄會顯示於此處。</p>
            </div>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">{{ $monitor->name }}的最近檢查紀錄</caption>
                    <thead>
                        <tr>
                            <th scope="col">排定時間</th>
                            <th scope="col">來源</th>
                            <th scope="col">執行狀態</th>
                            <th scope="col">HTTP</th>
                            <th scope="col">AdsBot</th>
                            <th scope="col">檢查總耗時</th>
                            <th scope="col">訊號</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr>
                                <td>
                                    <time class="timestamp" datetime="{{ $run->scheduled_at->toIso8601String() }}">{{ $run->scheduled_at->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time>
                                </td>
                                <td>{{ $run->source === 'manual' ? '手動檢查' : '排程檢查' }}</td>
                                <td><x-state-badge :state="$run->status" /></td>
                                <td class="numeric">{{ $run->normal_status ?? '—' }}</td>
                                <td class="numeric">{{ $run->adsbot_status ?? '—' }}</td>
                                <td class="numeric">{{ $run->elapsed_ms !== null ? number_format($run->elapsed_ms).' 毫秒' : '—' }}</td>
                                <td>{{ $run->error_type ?: ($run->adsbot_error_type ?: '—') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
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
