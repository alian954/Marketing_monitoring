@extends('layouts.app')

@php
    $healthState = $monitor->getAttribute('health_state') ?? $monitor->healthState();
    $allowedRedirectHosts = is_array($monitor->allowed_redirect_hosts) ? $monitor->allowed_redirect_hosts : [];
    $intervalLabel = $monitor->interval_seconds >= 60
        ? intdiv($monitor->interval_seconds, 60).' 分鐘'
        : $monitor->interval_seconds.' 秒';
    $ruleLabels = [
        'http' => 'HTTP 回應異常',
        'probe_restricted' => '檢查受到限制',
        'content' => '缺少預期文字',
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
            <a class="button button-secondary" href="{{ route('monitors.edit', $monitor) }}">編輯</a>
        </div>
    </div>

    @if (! $monitor->enabled)
        <div class="form-note spaced-bottom">監測目前已暫停。請先恢復監測，再要求立即檢查。</div>
    @endif
    @error('check')
        <div class="alert alert-error" role="alert">{{ $message }}</div>
    @enderror

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
                        <dt>預期回應文字</dt>
                        <dd>{{ $monitor->expected_text ?: '未設定必要文字' }}</dd>
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
                            <dt>耗時</dt>
                            <dd>{{ $monitor->latestCheckRun->elapsed_ms !== null ? number_format($monitor->latestCheckRun->elapsed_ms).' 毫秒' : '—' }}</dd>
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
                            <dt>預期文字</dt>
                            <dd>
                                @if ($monitor->latestCheckRun->expected_text_found === null)
                                    未檢查
                                @elseif ($monitor->latestCheckRun->expected_text_found)
                                    已找到
                                @else
                                    未找到
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
                            <th scope="col">耗時</th>
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
