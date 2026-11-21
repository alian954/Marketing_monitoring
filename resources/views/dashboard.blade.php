@extends('layouts.app')

@section('title', '監測總覽')

@section('content')
    @php
        $totalMonitors = $monitorCount;
    @endphp

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">監測總覽</p>
            <h1 class="page-title">{{ now()->timezone('Asia/Taipei')->format('A') === 'AM' ? '早安' : '您好' }}</h1>
            <p class="page-description">查看監測狀態、進行中的事件，以及排程器與佇列工作者運作情形。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('status-pages.edit') }}">公開狀態頁</a>
            <a class="button button-secondary" href="{{ route('monitors.export') }}">匯出 CSV</a>
            <a class="button button-primary" href="{{ route('monitors.create') }}">新增監測項目</a>
        </div>
    </div>

    <section class="stat-grid" aria-label="監測項目摘要">
        <article class="stat-card">
            <div class="stat-topline"><span class="stat-label">正常</span><x-state-badge state="healthy" /></div>
            <strong class="stat-value">{{ $counts['healthy'] ?? 0 }}</strong>
            <p class="stat-hint">網站或貼文回應正常</p>
        </article>
        <article class="stat-card">
            <div class="stat-topline"><span class="stat-label">需留意</span><x-state-badge state="issue" /></div>
            <strong class="stat-value">{{ $counts['issue'] ?? 0 }}</strong>
            <p class="stat-hint">異常已確認或仍在確認中</p>
        </article>
        <article class="stat-card">
            <div class="stat-topline"><span class="stat-label">效能警告</span><x-state-badge state="warning" /></div>
            <strong class="stat-value">{{ $counts['warning'] ?? 0 }}</strong>
            <p class="stat-hint">回應變慢或檢查受到限制</p>
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

    <section class="panel spaced-top" aria-labelledby="social-platform-summary-title">
        <div class="panel-header">
            <div>
                <h2 id="social-platform-summary-title" class="panel-title">社群平台狀態</h2>
                <p class="panel-description">依最近一次檢查列出 Facebook、Instagram 與 Threads 貼文狀態；「需留意」包含待確認或已成立的異常。</p>
            </div>
        </div>
        @if (collect($platformCounts)->sum('total') === 0)
            <div class="empty-state">
                <h3 class="empty-title">尚無社群貼文監測項目</h3>
                <p class="empty-copy">新增 Facebook、Instagram 或 Threads 公開貼文網址後，這裡會顯示各平台的檢查狀態。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="social-platform-summary-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">Facebook、Instagram 與 Threads 公開貼文健康狀態統計</caption>
                    <thead>
                        <tr>
                            <th scope="col">平台</th>
                            <th scope="col">監測貼文</th>
                            <th scope="col">正常</th>
                            <th scope="col">需留意</th>
                            <th scope="col">警告</th>
                            <th scope="col">逾時</th>
                            <th scope="col">已暫停</th>
                            <th scope="col">待檢查</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($platformCounts as $platform => $platformCount)
                            <tr>
                                <th scope="row"><a class="strong-link" href="{{ route('monitors.index', ['platform' => $platform]) }}">{{ $platformCount['label'] }}</a></th>
                                <td class="numeric">{{ $platformCount['total'] }}</td>
                                <td class="numeric">{{ $platformCount['states']['healthy'] }}</td>
                                <td class="numeric">{{ $platformCount['states']['issue'] }}</td>
                                <td class="numeric">{{ $platformCount['states']['warning'] }}</td>
                                <td class="numeric">{{ $platformCount['states']['stale'] }}</td>
                                <td class="numeric">{{ $platformCount['states']['paused'] }}</td>
                                <td class="numeric">{{ $platformCount['states']['pending'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="social-platform-availability-title">
        <div class="panel-header">
            <div>
                <h2 id="social-platform-availability-title" class="panel-title">受監測貼文歷史品質</h2>
                <p class="panel-description">依已啟用貼文的排程檢查觀測時間加權；資料空窗與維護時段不計入。這是受監測貼文樣本的結果，不代表 Facebook、Instagram 或 Threads 的整體 SLA。</p>
            </div>
        </div>
        @if (collect($fleetAvailability['social_platform_statistics'])->sum('monitor_count') === 0)
            <div class="empty-state">
                <h3 class="empty-title">尚無啟用中的社群貼文監測</h3>
                <p class="empty-copy">啟用 Facebook、Instagram 或 Threads 貼文監測後，這裡會比較各平台樣本的可用率與資料覆蓋度。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="social-platform-availability-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">Facebook、Instagram 與 Threads 受監測貼文的估算可用率與資料覆蓋率</caption>
                    <thead>
                        <tr>
                            <th scope="col">平台</th>
                            <th scope="col">啟用貼文</th>
                            <th scope="col">24 小時可用率</th>
                            <th scope="col">24 小時覆蓋率</th>
                            <th scope="col">7 天可用率</th>
                            <th scope="col">7 天覆蓋率</th>
                            <th scope="col">30 天可用率</th>
                            <th scope="col">30 天覆蓋率</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fleetAvailability['social_platform_statistics'] as $platformStatistics)
                            @if ($platformStatistics['monitor_count'] > 0)
                                @php
                                    $platformPeriods = collect($platformStatistics['statistics'])->keyBy('label');
                                @endphp
                                <tr>
                                    <th scope="row">{{ $platformStatistics['label'] }}</th>
                                    <td class="numeric">{{ $platformStatistics['monitor_count'] }}</td>
                                    @foreach (['24 小時', '7 天', '30 天'] as $period)
                                        <td class="numeric">{{ $platformPeriods[$period]['availability_rate'] === null ? '—' : number_format($platformPeriods[$period]['availability_rate'], 2).'%' }}</td>
                                        <td class="numeric">{{ $platformPeriods[$period]['coverage_rate'] === null ? '—' : number_format($platformPeriods[$period]['coverage_rate'], 2).'%' }}</td>
                                    @endforeach
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="fleet-availability-title">
        <div class="panel-header">
            <div>
                <h2 id="fleet-availability-title" class="panel-title">整體監測品質</h2>
                <p class="panel-description">比較最近 24 小時、7 天與 30 天；依啟用中項目的實際觀測時間加權，維護時段與資料空窗不計入。</p>
            </div>
            <div class="heading-actions">
                <span class="secondary-text">{{ $fleetAvailability['monitor_count'] }} 個啟用項目</span>
                <a class="button button-secondary button-small" href="{{ route('monitors.availability.export.fleet') }}">匯出全部啟用項目可用率 CSV</a>
            </div>
        </div>
        @if ($fleetAvailability['monitor_count'] === 0)
            <div class="empty-state">
                <h3 class="empty-title">沒有啟用中的監測項目</h3>
                <p class="empty-copy">恢復監測後，這裡會顯示整體可用率與檢查覆蓋度。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="fleet-availability-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">整體監測品質最近 24 小時、7 天與 30 天統計</caption>
                    <thead>
                        <tr>
                            <th scope="col">期間</th>
                            <th scope="col">估算可用率</th>
                            <th scope="col">估算不可用分鐘</th>
                            <th scope="col">檢查覆蓋率</th>
                            <th scope="col">觀測／應監測分鐘</th>
                            <th scope="col">維護排除分鐘</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fleetAvailability['statistics'] as $statistic)
                            <tr>
                                <th scope="row">{{ $statistic['label'] }}</th>
                                <td class="numeric">{{ $statistic['availability_rate'] === null ? '—' : number_format($statistic['availability_rate'], 2).'%' }}</td>
                                <td class="numeric">{{ number_format($statistic['unavailable_minutes'], 1) }}</td>
                                <td class="numeric">{{ $statistic['coverage_rate'] === null ? '—' : number_format($statistic['coverage_rate'], 2).'%' }}</td>
                                <td class="numeric">{{ number_format($statistic['observed_minutes'], 1) }} / {{ number_format($statistic['eligible_minutes'], 1) }}</td>
                                <td class="numeric">{{ number_format($statistic['maintenance_excluded_minutes'], 1) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="dashboard-top">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">監測項目</h2>
                    <p class="panel-description">目前共 {{ $totalMonitors }} 個網站或公開貼文</p>
                </div>
                <a class="strong-link" href="{{ route('monitors.index') }}">查看全部 <span aria-hidden="true">→</span></a>
            </div>

            @if ($monitors->isEmpty())
                <div class="empty-state">
                    <h3 class="empty-title">目前沒有監測項目</h3>
                    <p class="empty-copy">新增網站或公開貼文網址，即可開始檢查連線狀態與指定內容。</p>
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
                                        @if ($monitor->socialPlatform())
                                            <span class="secondary-text">{{ $monitor->socialPlatform() }} 公開貼文</span>
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

        <section class="panel heartbeat-panel" aria-labelledby="runtime-title">
            <div class="heartbeat-item">
                <div class="heartbeat-heading">
                    <h2 id="runtime-title" class="panel-title">執行服務</h2>
                </div>
                <div class="heartbeat-heading heartbeat-subheading">
                    <span class="heartbeat-dot {{ $heartbeatFresh ? 'is-fresh' : 'is-stale' }}" aria-hidden="true"></span>
                    <h3 class="heartbeat-title">排程器</h3>
                    <x-state-badge :state="$heartbeatFresh ? 'healthy' : 'stale'" />
                </div>
                @if ($heartbeat?->last_succeeded_at)
                    <p class="heartbeat-copy">最近一次成功執行</p>
                    <time class="heartbeat-time" datetime="{{ $heartbeat->last_succeeded_at->toIso8601String() }}">{{ $heartbeat->last_succeeded_at->timezone('Asia/Taipei')->format('Y/n/j · H:i:s') }}</time>
                @else
                    <p class="heartbeat-copy">尚未記錄成功的排程器執行。</p>
                @endif
                @if ($heartbeat?->last_started_at)
                    <p class="heartbeat-copy">最近啟動：<time datetime="{{ $heartbeat->last_started_at->toIso8601String() }}">{{ $heartbeat->last_started_at->timezone('Asia/Taipei')->format('n/j H:i') }}</time></p>
                @endif
            </div>

            <div class="heartbeat-item">
                <div class="heartbeat-heading heartbeat-subheading">
                    <h3 class="heartbeat-title">Queue worker</h3>
                    @if ($queueWorkerEnabled)
                        <span class="heartbeat-dot {{ $workerHeartbeatFresh ? 'is-fresh' : 'is-stale' }}" aria-hidden="true"></span>
                        <x-state-badge :state="$workerHeartbeatFresh ? 'healthy' : 'stale'" />
                    @endif
                </div>
                @if (! $queueWorkerEnabled)
                    <p class="heartbeat-copy">目前使用 sync 同步佇列，工作由目前程序直接執行，沒有獨立 worker 心跳。</p>
                @else
                    @if ($queueSize === null)
                        <p class="heartbeat-copy">目前無法取得佇列工作數。</p>
                    @else
                        <p class="heartbeat-copy">目前佇列工作總數：{{ number_format($queueSize) }} 筆（包含延遲與處理中的工作）</p>
                    @endif
                    @if ($queueStateCounts !== null)
                        <p class="heartbeat-copy">可立即處理 {{ number_format($queueStateCounts['pending']) }} 筆、延遲等待 {{ number_format($queueStateCounts['delayed']) }} 筆、保留中 {{ number_format($queueStateCounts['reserved']) }} 筆。</p>
                    @endif
                    @if ($checkRunQueueHealth !== null)
                        <p class="heartbeat-copy">監測檢查：到期排隊 {{ number_format($checkRunQueueHealth['due_queued_count']) }} 筆、執行中 {{ number_format($checkRunQueueHealth['running_count']) }} 筆。</p>
                        @if ($checkRunQueueHealth['oldest_due_wait_seconds'] !== null || $checkRunQueueHealth['longest_running_seconds'] !== null)
                            <p class="heartbeat-copy">
                                @if ($checkRunQueueHealth['oldest_due_wait_seconds'] !== null)
                                    最久等待 {{ number_format($checkRunQueueHealth['oldest_due_wait_seconds'] / 60, 1) }} 分鐘
                                @endif
                                @if ($checkRunQueueHealth['oldest_due_wait_seconds'] !== null && $checkRunQueueHealth['longest_running_seconds'] !== null)
                                    ；
                                @endif
                                @if ($checkRunQueueHealth['longest_running_seconds'] !== null)
                                    最久執行 {{ number_format($checkRunQueueHealth['longest_running_seconds'] / 60, 1) }} 分鐘
                                @endif
                            </p>
                        @endif
                        @if ($checkRunQueueHealth['queued_warning'])
                            <p class="heartbeat-copy">有到期檢查已等待超過 2 分鐘，請確認佇列處理狀況。</p>
                        @endif
                        @if ($checkRunQueueHealth['running_warning'])
                            <p class="heartbeat-copy">有檢查已執行超過 3 分鐘，請查看 worker 與該次檢查紀錄。</p>
                        @endif
                    @endif
                    @if ($workerHeartbeat?->last_succeeded_at)
                        <p class="heartbeat-copy">最近成功處理佇列工作的時間</p>
                        <time class="heartbeat-time" datetime="{{ $workerHeartbeat->last_succeeded_at->toIso8601String() }}">{{ $workerHeartbeat->last_succeeded_at->timezone('Asia/Taipei')->format('Y/n/j · H:i:s') }}</time>
                        @unless ($workerHeartbeatFresh)
                            <p class="heartbeat-copy">超過 2 分鐘沒有心跳，請檢查 queue worker 是否停止或佇列是否塞住。</p>
                        @endunless
                    @else
                        <p class="heartbeat-copy">尚未收到 worker 心跳；請確認 queue worker 已啟動且佇列能正常處理工作。</p>
                    @endif
                @endif
            </div>
            @if ($failedQueueJobStorageSupported)
                <div class="heartbeat-item">
                    <div class="heartbeat-heading heartbeat-subheading">
                        <h3 class="heartbeat-title">失敗佇列工作</h3>
                    </div>
                    @if ($failedQueueJobCount === null)
                        <p class="heartbeat-copy">目前無法取得失敗佇列工作數。</p>
                    @else
                        <p class="heartbeat-copy">目前記錄 {{ number_format($failedQueueJobCount) }} 件失敗工作。</p>
                    @endif
                </div>
            @endif
        </section>
    </div>

    <section class="panel spaced-top" aria-labelledby="dashboard-maintenance-title">
        <div class="panel-header">
            <div>
                <h2 id="dashboard-maintenance-title" class="panel-title">近期預定維護</h2>
                <p class="panel-description">顯示進行中的維護，以及未來 30 天內即將開始的維護時段。</p>
            </div>
            @if ($maintenanceSummary['count'] > count($maintenanceSummary['items']))
                <span class="secondary-text">近 30 天共有 {{ number_format($maintenanceSummary['count']) }} 段（顯示最近 {{ count($maintenanceSummary['items']) }} 段）</span>
            @endif
        </div>
        @if ($maintenanceSummary['items'] === [])
            <div class="empty-state">
                <h3 class="empty-title">近期沒有預定維護時段</h3>
                <p class="empty-copy">未來 30 天沒有預定維護時段。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="dashboard-maintenance-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">目前進行中與未來 30 天的維護時段</caption>
                    <thead>
                        <tr>
                            <th scope="col">監測項目</th>
                            <th scope="col">狀態</th>
                            <th scope="col">維護原因</th>
                            <th scope="col">開始時間（台灣時間）</th>
                            <th scope="col">結束時間（台灣時間）</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($maintenanceSummary['items'] as $maintenance)
                            <tr>
                                <td><a class="monitor-name-link" href="{{ route('monitors.show', ['monitor' => $maintenance['monitor_id']]) }}">{{ $maintenance['monitor_name'] }}</a></td>
                                <td>{{ $maintenance['state'] === 'active' ? '維護中' : '即將維護' }}</td>
                                <td>{{ $maintenance['reason'] }}</td>
                                <td><time datetime="{{ $maintenance['starts_at']->toIso8601String() }}">{{ $maintenance['starts_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></td>
                                <td><time datetime="{{ $maintenance['ends_at']->toIso8601String() }}">{{ $maintenance['ends_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="incidents-title">
        <div class="panel-header">
            <div>
                <h2 id="incidents-title" class="panel-title">進行中的事件</h2>
                <p class="panel-description">異常會經過多次檢查確認後才會成立；認領事件可標示已有人接手。</p>
            </div>
            <a class="button button-secondary button-small" href="{{ route('incidents.index') }}">查看所有事件</a>
        </div>
        @if ($incidents->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">目前沒有進行中的事件</h3>
                <p class="empty-copy">目前沒有待確認或已成立的網站／貼文監測事件。</p>
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
                                <td>
                                    <x-state-badge :state="$incident->state" />
                                    @if ($incident->acknowledged_at)
                                        <span class="secondary-text">由 {{ $incident->acknowledgedBy?->name ?? '帳戶已刪除' }} 接手</span>
                                    @else
                                        <span class="secondary-text">尚未認領</span>
                                    @endif
                                </td>
                                <td>{{ $incident->ruleLabel() }}</td>
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

    <section class="panel spaced-top" aria-labelledby="tls-expiry-title">
        <div class="panel-header">
            <div>
                <h2 id="tls-expiry-title" class="panel-title">TLS 憑證期限</h2>
                <p class="panel-description">依最近一次每日獨立檢查結果，列出已過期或 30 天內到期的 HTTPS 憑證；超過 48 小時未取得新觀測時會標示資料逾時。</p>
            </div>
            @if ($expiringCertificateCount > 0)
                <span class="secondary-text">{{ $expiringCertificateCount }} 張</span>
            @endif
        </div>
        @if ($expiringCertificates->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">沒有即將到期的憑證</h3>
                <p class="empty-copy">最近 TLS 觀測未顯示已過期或 30 天內到期的憑證。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-label="已過期或 30 天內到期的 TLS 憑證清單" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">已過期或 30 天內到期的 HTTPS 憑證</caption>
                    <thead>
                        <tr>
                            <th scope="col">監測項目</th>
                            <th scope="col">最近觀測到期日</th>
                            <th scope="col">狀態</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($expiringCertificates as $certificate)
                            <tr>
                                <th scope="row">
                                    <a class="monitor-name-link" href="{{ route('monitors.show', $certificate['monitor']) }}">{{ $certificate['monitor']->name }}</a>
                                </th>
                                <td>
                                    <time datetime="{{ $certificate['expires_at']->toIso8601String() }}">{{ $certificate['expires_at']->timezone('Asia/Taipei')->format('Y/n/j') }}</time>
                                    <span class="secondary-text">觀測於 <time datetime="{{ $certificate['observed_at']->toIso8601String() }}">{{ $certificate['observed_at']->timezone('Asia/Taipei')->format('n/j H:i') }}</time></span>
                                </td>
                                <td>
                                    @if ($certificate['is_stale'])
                                        <x-state-badge state="stale" />
                                        <span>資料逾時，需重新檢查</span>
                                    @elseif ($certificate['is_expired'])
                                        <x-state-badge state="issue" />
                                        <span>已過期</span>
                                    @else
                                        <x-state-badge state="warning" />
                                        <span>{{ $certificate['days_until_expiry'] === 0 ? '不足 1 天到期' : '剩餘 '.$certificate['days_until_expiry'].' 天' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($expiringCertificateCount > $expiringCertificates->count())
                <p class="panel-description">另有 {{ $expiringCertificateCount - $expiringCertificates->count() }} 張憑證未列出；此處先顯示最早到期的 10 張。</p>
            @endif
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="domain-expiry-title">
        <div class="panel-header">
            <div>
                <h2 id="domain-expiry-title" class="panel-title">網域註冊期限</h2>
                <p class="panel-description">每日透過 IANA RDAP 清單查詢網站網域的註冊到期日；部分註冊機構不公開期限資料。超過 48 小時未更新的觀測會標示逾時。</p>
            </div>
            @if ($expiringDomainCount > 0)
                <span class="secondary-text">{{ $expiringDomainCount }} 個</span>
            @endif
        </div>
        @if ($expiringDomains->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">沒有已知的近期到期網域</h3>
                <p class="empty-copy">目前沒有成功查得已過期或 30 天內到期的網站網域。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-label="已過期或 30 天內到期的網站網域清單" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">已過期或 30 天內到期的網站網域註冊期限</caption>
                    <thead>
                        <tr>
                            <th scope="col">監測項目</th>
                            <th scope="col">註冊網域</th>
                            <th scope="col">到期日</th>
                            <th scope="col">狀態</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($expiringDomains as $domain)
                            <tr>
                                <th scope="row">
                                    <a class="monitor-name-link" href="{{ route('monitors.show', $domain['monitor']) }}">{{ $domain['monitor']->name }}</a>
                                </th>
                                <td>{{ $domain['domain_name'] ?? '未辨識' }}</td>
                                <td>
                                    <time datetime="{{ $domain['expires_at']->toIso8601String() }}">{{ $domain['expires_at']->timezone('Asia/Taipei')->format('Y/n/j') }}</time>
                                    @if ($domain['observed_at'])
                                        <span class="secondary-text">觀測於 <time datetime="{{ $domain['observed_at']->toIso8601String() }}">{{ $domain['observed_at']->timezone('Asia/Taipei')->format('n/j H:i') }}</time></span>
                                    @endif
                                </td>
                                <td>
                                    @if ($domain['is_stale'])
                                        <x-state-badge state="stale" />
                                        <span>資料逾時，需重新查詢</span>
                                    @elseif ($domain['is_expired'])
                                        <x-state-badge state="issue" />
                                        <span>已到期</span>
                                    @else
                                        <x-state-badge state="warning" />
                                        <span>{{ $domain['days_until_expiry'] === 0 ? '不足 1 天到期' : '剩餘 '.$domain['days_until_expiry'].' 天' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($expiringDomainCount > $expiringDomains->count())
                <p class="panel-description">另有 {{ $expiringDomainCount - $expiringDomains->count() }} 個網域未列出；此處先顯示最早到期的 10 個。</p>
            @endif
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="dns-changes-title">
        <div class="panel-header">
            <div>
                <h2 id="dns-changes-title" class="panel-title">DNS 記錄變更</h2>
                <p class="panel-description">追蹤監測網址主機的 A/AAAA、CNAME、MX、NS、TXT 與 CAA 回答；舊版紀錄可能包含重新導向目的主機。</p>
            </div>
        </div>
        @if ($dnsChanges->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">尚未偵測到 DNS 記錄變更</h3>
                <p class="empty-copy">累積至少兩次排程檢查後，若主機 IP、別名、郵件、名稱伺服器或文字政策記錄改變，變更紀錄會顯示於此處。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="dns-changes-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">最近偵測到的監測主機 A/AAAA、CNAME、MX、NS、TXT 與 CAA 回答變更</caption>
                    <thead>
                        <tr>
                            <th scope="col">監測項目</th>
                            <th scope="col">偵測時間</th>
                            <th scope="col">監測主機先前 DNS 回答</th>
                            <th scope="col">監測主機目前 DNS 回答</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dnsChanges as $dnsChange)
                            <tr>
                                <td>
                                    @if ($dnsChange->monitor)
                                        <a class="monitor-name-link" href="{{ route('monitors.show', $dnsChange->monitor) }}">{{ $dnsChange->monitor->name }}</a>
                                    @else
                                        <span class="secondary-text">已刪除的監測項目</span>
                                    @endif
                                </td>
                                <td><time class="timestamp" datetime="{{ $dnsChange->detected_at->toIso8601String() }}">{{ $dnsChange->detected_at->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time></td>
                                <td class="masked-url">
                                    <div>IP：{{ $dnsChange->previousIpsLabel() }}</div>
                                    <div class="secondary-text">CNAME：{{ $dnsChange->previousCnamesLabel() }}</div>
                                    <div class="secondary-text">MX／NS／TXT／CAA：{{ $dnsChange->previousRecordsLabel() }}</div>
                                    @unless ($dnsChange->monitor_host_only)<span class="secondary-text">舊版紀錄，未區分重新導向主機</span>@endunless
                                </td>
                                <td class="masked-url">
                                    <div>IP：{{ $dnsChange->currentIpsLabel() }}</div>
                                    <div class="secondary-text">CNAME：{{ $dnsChange->currentCnamesLabel() }}</div>
                                    <div class="secondary-text">MX／NS／TXT／CAA：{{ $dnsChange->currentRecordsLabel() }}</div>
                                    @unless ($dnsChange->monitor_host_only)<span class="secondary-text">舊版紀錄，未區分重新導向主機</span>@endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
