@extends('layouts.app')

@section('title', '監測總覽')

@section('content')
    @php
        $totalMonitors = $monitors->count();
    @endphp

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">監測總覽</p>
            <h1 class="page-title">{{ now()->timezone('Asia/Taipei')->format('A') === 'AM' ? '早安' : '您好' }}</h1>
            <p class="page-description">查看監測狀態、進行中的事件，以及排程器運作情形。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('monitors.export') }}">匯出 CSV</a>
            <a class="button button-primary" href="{{ route('monitors.create') }}">新增監測項目</a>
        </div>
    </div>

    <section class="stat-grid" aria-label="監測項目摘要">
        <article class="stat-card">
            <div class="stat-topline"><span class="stat-label">正常</span><x-state-badge state="healthy" /></div>
            <strong class="stat-value">{{ $counts['healthy'] ?? 0 }}</strong>
            <p class="stat-hint">網站回應正常</p>
        </article>
        <article class="stat-card">
            <div class="stat-topline"><span class="stat-label">需留意</span><x-state-badge state="issue" /></div>
            <strong class="stat-value">{{ $counts['issue'] ?? 0 }}</strong>
            <p class="stat-hint">異常已確認或仍在確認中</p>
        </article>
        <article class="stat-card">
            <div class="stat-topline"><span class="stat-label">資料逾時</span><x-state-badge state="stale" /></div>
            <strong class="stat-value">{{ $counts['stale'] ?? 0 }}</strong>
            <p class="stat-hint">最近沒有完成的檢查</p>
        </article>
        <article class="stat-card">
            <div class="stat-topline"><span class="stat-label">已暫停</span><x-state-badge state="paused" /></div>
            <strong class="stat-value">{{ $counts['paused'] ?? 0 }}</strong>
            <p class="stat-hint">目前未執行監測</p>
        </article>
    </section>

    <div class="dashboard-top">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">監測項目</h2>
                    <p class="panel-description">目前共 {{ $totalMonitors }} 個網站</p>
                </div>
                <a class="strong-link" href="{{ route('monitors.index') }}">查看全部 <span aria-hidden="true">→</span></a>
            </div>

            @if ($monitors->isEmpty())
                <div class="empty-state">
                    <h3 class="empty-title">目前沒有監測項目</h3>
                    <p class="empty-copy">新增網站後，即可開始檢查連線狀態與預期內容。</p>
                    <a class="button button-primary" href="{{ route('monitors.create') }}">新增第一個監測項目</a>
                </div>
            @else
                <div class="data-table-wrap">
                    <table class="data-table">
                        <caption class="sr-only">監測項目狀態與最近一次檢查</caption>
                        <thead>
                            <tr>
                                <th scope="col">監測項目</th>
                                <th scope="col">狀態</th>
                                <th scope="col">最近檢查</th>
                                <th scope="col"><span class="sr-only">操作</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($monitors->take(8) as $monitor)
                                <tr>
                                    <td>
                                        <a class="monitor-name-link" href="{{ route('monitors.show', $monitor) }}">{{ $monitor->name }}</a>
                                        @if ($monitor->label)
                                            <span class="secondary-text">{{ $monitor->label }}</span>
                                        @endif
                                        @if ($monitor->getAttribute('masked_url'))
                                            <span class="secondary-text masked-url">{{ $monitor->getAttribute('masked_url') }}</span>
                                        @endif
                                    </td>
                                    <td><x-state-badge :state="$monitor->health_state" /></td>
                                    <td>
                                        @if ($monitor->last_checked_at)
                                            <time class="timestamp" datetime="{{ $monitor->last_checked_at->toIso8601String() }}">{{ $monitor->last_checked_at->timezone('Asia/Taipei')->format('n/j H:i') }}</time>
                                            @if ($monitor->latestCheckRun?->normal_status !== null)
                                                <span class="secondary-text">HTTP {{ $monitor->latestCheckRun->normal_status }}</span>
                                            @elseif ($monitor->latestCheckRun?->error_type)
                                                <span class="secondary-text">{{ $monitor->latestCheckRun->error_type }}</span>
                                            @endif
                                        @else
                                                <span class="secondary-text">等待首次檢查</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <form class="inline-form" action="{{ route('monitors.toggle', $monitor) }}" method="POST">
                                                @csrf
                                                <button class="button button-quiet button-small" type="submit" aria-label="{{ $monitor->enabled ? '暫停' : '恢復' }}{{ $monitor->name }}的監測">
                                                    {{ $monitor->enabled ? '暫停' : '恢復' }}
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="panel heartbeat-panel" aria-labelledby="heartbeat-title">
            <div>
                <div class="heartbeat-heading">
                    <span class="heartbeat-dot {{ $heartbeatFresh ? 'is-fresh' : 'is-stale' }}" aria-hidden="true"></span>
                    <h2 id="heartbeat-title" class="panel-title">排程器</h2>
                    <x-state-badge :state="$heartbeatFresh ? 'healthy' : 'stale'" />
                </div>
                @if ($heartbeat?->last_succeeded_at)
                    <p class="heartbeat-copy">最近一次成功執行</p>
                    <time class="heartbeat-time" datetime="{{ $heartbeat->last_succeeded_at->toIso8601String() }}">{{ $heartbeat->last_succeeded_at->timezone('Asia/Taipei')->format('Y/n/j · H:i:s') }}</time>
                @else
                    <p class="heartbeat-copy">尚未記錄成功的排程器執行。</p>
                @endif
            </div>
            @if ($heartbeat?->last_started_at)
                <p class="heartbeat-copy">最近啟動：<time datetime="{{ $heartbeat->last_started_at->toIso8601String() }}">{{ $heartbeat->last_started_at->timezone('Asia/Taipei')->format('n/j H:i') }}</time></p>
            @endif
        </section>
    </div>

    <section class="panel spaced-top" aria-labelledby="incidents-title">
        <div class="panel-header">
            <div>
                <h2 id="incidents-title" class="panel-title">進行中的事件</h2>
                <p class="panel-description">異常會經過多次檢查確認後才會成立。</p>
            </div>
        </div>
        @if ($incidents->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">目前沒有進行中的事件</h3>
                <p class="empty-copy">監測中的網站目前沒有待確認或已成立的事件。</p>
            </div>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                        <caption class="sr-only">進行中的監測事件</caption>
                    <thead>
                        <tr>
                            <th scope="col">監測項目</th>
                            <th scope="col">狀態</th>
                            <th scope="col">偵測訊號</th>
                            <th scope="col">最近發現</th>
                            <th scope="col"><span class="sr-only">查看詳情</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($incidents as $incident)
                            <tr>
                                <td><span class="monitor-name-link">{{ $incident->monitor?->name ?? '已刪除的監測項目' }}</span></td>
                                <td><x-state-badge :state="$incident->state" /></td>
                                <td>{{ ['http' => 'HTTP 回應異常', 'probe_restricted' => '檢查受到限制', 'content' => '缺少預期文字', 'adsbot_difference' => 'AdsBot 模擬結果不同', 'tls_expired' => 'TLS 憑證已過期', 'tls_handshake' => 'TLS 交握失敗', 'tls_7d' => 'TLS 憑證將於 7 天內到期', 'tls_14d' => 'TLS 憑證將於 14 天內到期'][$incident->rule_key] ?? '其他異常' }}</td>
                                <td>
                                    @if ($incident->last_seen_at)
                                        <time class="timestamp" datetime="{{ $incident->last_seen_at->toIso8601String() }}">{{ $incident->last_seen_at->timezone('Asia/Taipei')->format('n/j H:i') }}</time>
                                    @else
                                        <span class="secondary-text">未記錄</span>
                                    @endif
                                </td>
                                <td class="row-actions"><a class="strong-link" href="{{ route('monitors.show', $incident->monitor) }}">查看 <span aria-hidden="true">→</span></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
