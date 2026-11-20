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

    @php
        $fleetDailyAvailability = collect($fleetAvailability['statistics'])->firstWhere('label', '24 小時');
    @endphp
    <section class="panel spaced-top" aria-labelledby="fleet-availability-title">
        <div class="panel-header">
            <div>
                <h2 id="fleet-availability-title" class="panel-title">整體監測品質</h2>
                <p class="panel-description">最近 24 小時，依啟用中項目的實際觀測時間加權；維護時段不計入。</p>
            </div>
            <span class="secondary-text">{{ $fleetAvailability['monitor_count'] }} 個啟用項目</span>
        </div>
        @if ($fleetAvailability['monitor_count'] === 0)
            <div class="empty-state">
                <h3 class="empty-title">沒有啟用中的監測項目</h3>
                <p class="empty-copy">恢復監測後，這裡會顯示整體可用率與檢查覆蓋度。</p>
            </div>
        @else
            <div class="stat-grid monitor-availability-grid">
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">估算可用率</span></div>
                    <strong class="stat-value">{{ $fleetDailyAvailability['availability_rate'] === null ? '—' : number_format($fleetDailyAvailability['availability_rate'], 2).'%' }}</strong>
                    <p class="stat-hint">已觀測時間內，各項目通過檢查的加權比例</p>
                </article>
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">檢查覆蓋率</span></div>
                    <strong class="stat-value">{{ $fleetDailyAvailability['coverage_rate'] === null ? '—' : number_format($fleetDailyAvailability['coverage_rate'], 2).'%' }}</strong>
                    <p class="stat-hint">實際觀測時間 ÷ 應監測時間</p>
                </article>
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">觀測資料</span></div>
                    <strong class="stat-value">{{ number_format($fleetDailyAvailability['observed_minutes'], 1) }} 分鐘</strong>
                    <p class="stat-hint">應監測 {{ number_format($fleetDailyAvailability['eligible_minutes'], 1) }} 分鐘；維護排除 {{ number_format($fleetDailyAvailability['maintenance_excluded_minutes'], 1) }} 分鐘</p>
                </article>
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
                @elseif ($workerHeartbeat?->last_succeeded_at)
                    <p class="heartbeat-copy">最近成功處理佇列工作的時間</p>
                    <time class="heartbeat-time" datetime="{{ $workerHeartbeat->last_succeeded_at->toIso8601String() }}">{{ $workerHeartbeat->last_succeeded_at->timezone('Asia/Taipei')->format('Y/n/j · H:i:s') }}</time>
                    @unless ($workerHeartbeatFresh)
                        <p class="heartbeat-copy">超過 2 分鐘沒有心跳，請檢查 queue worker 是否停止或佇列是否塞住。</p>
                    @endunless
                @else
                    <p class="heartbeat-copy">尚未收到 worker 心跳；請確認 queue worker 已啟動且佇列能正常處理工作。</p>
                @endif
            </div>
        </section>
    </div>

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
                                <td>{{ ['http' => 'HTTP 回應異常', 'probe_restricted' => '檢查受到限制', 'probe_incomplete' => '回應過大，驗證未完成', 'content' => '原始回應未包含預期文字', 'forbidden_text' => '回應包含不應出現的文字', 'response_header' => 'HTTP 回應標頭不符合設定', 'response_time' => 'HTTP 回應時間超過設定門檻', 'response_size' => 'HTTP 回應本文大小超出設定範圍或無法判定', 'response_content_change' => '原始回應可見文字已變更', 'monitor_stale' => '監測資料逾時', 'adsbot_difference' => 'AdsBot 模擬結果不同', 'tls_expired' => 'TLS 憑證已過期', 'tls_handshake' => 'TLS 交握失敗', 'tls_7d' => 'TLS 憑證將於 7 天內到期', 'tls_14d' => 'TLS 憑證將於 14 天內到期', 'tls_30d' => 'TLS 憑證將於 30 天內到期', 'domain_expired' => '網域已到期', 'domain_7d' => '網域將於 7 天內到期', 'domain_14d' => '網域將於 14 天內到期', 'domain_30d' => '網域將於 30 天內到期'][$incident->rule_key] ?? '其他異常' }}</td>
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
                <h2 id="dns-changes-title" class="panel-title">DNS 位址變更</h2>
                <p class="panel-description">新紀錄只追蹤監測網址主機的公開 A/AAAA IP 回答，並記錄解析中斷；舊版紀錄可能包含重新導向目的主機。</p>
            </div>
        </div>
        @if ($dnsChanges->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">尚未偵測到 DNS 位址變更</h3>
                <p class="empty-copy">累積至少兩次排程檢查後，若主機公開 IP 回答改變或變成無公開回答，變更紀錄會顯示於此處。</p>
            </div>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">最近偵測到的 DNS 公開 IP 位址變更</caption>
                    <thead>
                        <tr>
                            <th scope="col">監測項目</th>
                            <th scope="col">偵測時間</th>
                            <th scope="col">監測主機先前 IP</th>
                            <th scope="col">監測主機目前 IP</th>
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
                                    {{ $dnsChange->previousIpsLabel() }}
                                    @unless ($dnsChange->monitor_host_only)<span class="secondary-text">舊版紀錄，未區分重新導向主機</span>@endunless
                                </td>
                                <td class="masked-url">
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
@endsection
