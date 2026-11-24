@extends('layouts.app')

@section('title', '監測總覽')

@section('content')
    @php
        $totalMonitors = $monitorCount;
        $attentionCount = ($counts['issue'] ?? 0) + ($counts['warning'] ?? 0) + ($counts['stale'] ?? 0);
        $campaignTargetCount = count($socialEngagementTargets);
        $hasSocialEngagementFilters = $socialEngagementSelectedPlatform !== 'all'
            || $socialEngagementSelectedTag !== 'all'
            || $socialEngagementChangePeriodDays !== 7;
    @endphp

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">監測總覽</h1>
        </div>
        <div class="heading-actions">
            @if ($clients->count() > 1)
                <form action="{{ route('dashboard') }}" method="GET" class="dashboard-client-filter">
                    <label class="field-label" for="dashboard-client">客戶</label>
                    <select class="field-select" id="dashboard-client" name="client_id">
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected($selectedClientId === $client->id)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                    <button class="button button-secondary button-small" type="submit">套用</button>
                </form>
            @endif
            <a class="strong-link dashboard-performance-link" href="{{ route('clients.performance') }}">行銷成效 <span aria-hidden="true">→</span></a>
            @if ($totalMonitors > 0 && $attentionCount === 0)
                <a class="button button-primary" href="{{ route('monitors.create', ['client_id' => $selectedClientId]) }}">新增監測項目</a>
            @endif
        </div>
    </div>

    <section class="panel dashboard-focus spaced-top" aria-labelledby="dashboard-focus-title" id="dashboard-overview">
        <div class="panel-header">
            <h2 id="dashboard-focus-title" class="panel-title">監測狀態</h2>
            @if ($attentionCount > 0)
                <a class="strong-link" href="{{ route('monitors.index', ['client_id' => $selectedClientId]) }}">查看清單 <span aria-hidden="true">→</span></a>
            @endif
        </div>
        @if ($attentionCount > 0)
            <div class="panel-body dashboard-focus-body">
                <p class="dashboard-focus-count-line">
                    <strong class="dashboard-focus-count">{{ number_format($attentionCount) }}</strong>
                    <span>項需處理</span>
                </p>
            </div>
        @elseif ($totalMonitors === 0)
            <div class="empty-state dashboard-focus-empty">
                <h3 class="empty-title">尚未新增監測項目</h3>
                <a class="button button-primary" href="{{ route('monitors.create', ['client_id' => $selectedClientId]) }}">新增監測項目</a>
            </div>
        @else
            <div class="dashboard-focus-clear">
                <span class="dashboard-focus-check" aria-hidden="true">✓</span>
                <p>目前無待處理項目</p>
            </div>
        @endif
    </section>

    <details class="dashboard-disclosure dashboard-detail-report spaced-top" aria-labelledby="dashboard-detail-reports-title">
        <summary>
            <span id="dashboard-detail-reports-title">詳細報表</span>
            @if ($hasSocialEngagementFilters)
                <span class="dashboard-disclosure-meta">已套用社群篩選</span>
            @endif
        </summary>
        <div class="dashboard-disclosure-content dashboard-detail-report-content">
    <details class="dashboard-disclosure" aria-labelledby="dashboard-monitor-summary">
        <summary>
            <span id="dashboard-monitor-summary">監測清單與執行狀態</span>
            <span class="dashboard-disclosure-meta">{{ number_format($totalMonitors) }} 項 · {{ number_format($attentionCount) }} 項需處理 · 排程器 {{ $heartbeatStatus === 'healthy' ? '正常' : '需留意' }}</span>
        </summary>
        <div class="dashboard-disclosure-content">
    <div class="dashboard-top">
        <section class="panel" id="dashboard-monitors">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">監測項目</h2>
                </div>
                <div class="row-actions">
                    @unless (auth()->user()?->isReadOnlyWorkspaceMember())
                    <a class="strong-link" href="{{ route('status-pages.edit') }}">公開狀態頁</a>
                    @endunless
                    <a class="strong-link" href="{{ route('monitors.export', ['client_id' => $selectedClientId]) }}">匯出 CSV</a>
                    <a class="strong-link" href="{{ route('monitors.index', ['client_id' => $selectedClientId]) }}">查看全部 <span aria-hidden="true">→</span></a>
                </div>
            </div>

            @if ($monitors->isEmpty())
                <div class="empty-state">
                    <h3 class="empty-title">目前沒有監測項目</h3>
                    <p class="empty-copy">新增網站或公開貼文網址，即可開始檢查連線狀態與指定內容。</p>
                    <a class="button button-primary" href="{{ route('monitors.create', ['client_id' => $selectedClientId]) }}">新增第一個監測項目</a>
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
                                            <span class="platform-chip platform-chip--{{ $monitor->social_platform }}">
                                                <span class="platform-chip__dot" aria-hidden="true"></span>
                                                {{ $monitor->socialPlatform() }} 公開貼文
                                            </span>
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
                    <span class="heartbeat-dot {{ $heartbeatStatus === 'healthy' ? 'is-fresh' : 'is-stale' }}" aria-hidden="true"></span>
                    <h3 class="heartbeat-title">排程器</h3>
                    <x-state-badge :state="$heartbeatStatus" />
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
                @if ($heartbeatError !== null)
                    <p class="heartbeat-copy">最近執行錯誤：{{ $heartbeatError }}</p>
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
        </div>
    </details>

    <details class="dashboard-disclosure" aria-labelledby="dashboard-marketing-summary">
        <summary>
            <span id="dashboard-marketing-summary">行銷數據</span>
            <span class="dashboard-disclosure-meta">{{ number_format($campaignTargetCount) }} 個活動 · 互動與投放</span>
        </summary>
        <div class="dashboard-disclosure-content">
    <section class="panel spaced-top" aria-labelledby="social-platform-summary-title">
        <div class="panel-header">
            <div>
                <h2 id="social-platform-summary-title" class="panel-title">社群平台狀態</h2>
            </div>
        </div>
        @if (collect($platformCounts)->sum('total') === 0)
            <div class="empty-state">
                <h3 class="empty-title">尚無社群貼文監測項目</h3>
                <p class="empty-copy">新增 Facebook、Instagram、Threads 或 Dcard 公開貼文網址後，這裡會顯示各平台的檢查狀態。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="social-platform-summary-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">Facebook、Instagram、Threads 與 Dcard 貼文健康狀態及最近讀到公開互動數的篇數</caption>
                    <thead>
                        <tr>
                            <th scope="col">平台</th>
                            <th scope="col">監測貼文</th>
                            <th scope="col">最近讀到互動數</th>
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
                                <th scope="row">
                                    <a class="platform-chip platform-chip--{{ $platform }}" href="{{ route('monitors.index', ['platform' => $platform]) }}">
                                        <span class="platform-chip__dot" aria-hidden="true"></span>
                                        {{ $platformCount['label'] }}
                                    </a>
                                </th>
                                <td class="numeric">{{ $platformCount['total'] }}</td>
                                <td class="numeric">{{ $platformCount['enabled_total'] > 0 ? number_format($platformCount['engagement_captured']).' / '.number_format($platformCount['enabled_total']) : '—' }}</td>
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

    <section class="panel spaced-top" aria-labelledby="public-engagement-title">
        <div class="panel-header">
            <div>
                <h2 id="public-engagement-title" class="panel-title">公開互動數</h2>
            </div>
            <div class="row-actions">
                <a class="strong-link" href="{{ route('monitors.social-engagement.export', ['client_id' => $selectedClientId, 'engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag]) }}">最新值 CSV <span aria-hidden="true">↓</span></a>
                <a class="strong-link" href="{{ route('monitors.social-engagement.export.history', ['client_id' => $selectedClientId, 'engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag, 'engagement_period' => $socialEngagementChangePeriodDays]) }}">{{ $socialEngagementChangePeriodDays }} 天歷史 CSV <span aria-hidden="true">↓</span></a>
            </div>
        </div>
        <div class="panel-body">
            <search aria-label="篩選社群互動成效">
                <form action="{{ route('dashboard') }}" method="GET" class="check-history-filter">
                    <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                    <div class="field-group">
                        <label class="field-label" for="dashboard-engagement-platform">社群平台</label>
                        <select class="field-select" id="dashboard-engagement-platform" name="engagement_platform">
                            @foreach ($socialEngagementPlatformOptions as $platform => $platformLabel)
                                <option value="{{ $platform }}" @selected($socialEngagementSelectedPlatform === $platform)>{{ $platformLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="dashboard-engagement-tag">活動標籤</label>
                        <select class="field-select" id="dashboard-engagement-tag" name="engagement_tag">
                            @foreach ($socialEngagementTagFilterOptions as $tag => $tagLabel)
                                <option value="{{ $tag }}" @selected($socialEngagementSelectedTag === $tag)>{{ $tagLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <input type="hidden" name="engagement_period" value="{{ $socialEngagementChangePeriodDays }}">
                    <input type="hidden" name="engagement_tag_sort" value="{{ $socialEngagementTagSort }}">
                    <input type="hidden" name="engagement_post_sort" value="{{ $socialEngagementTopPostSort }}">
                    <input type="hidden" name="engagement_growth_sort" value="{{ $socialEngagementGrowthPostSort }}">
                    <div class="check-history-filter-actions">
                        <button class="button button-secondary" type="submit">套用</button>
                    </div>
                    <p class="field-help">平台與標籤篩選會同步更新貼文清單、互動量排行、成效彙總及 CSV。</p>
                </form>
            </search>
        </div>
        @if ($socialEngagementMonitors->total() === 0)
            <div class="empty-state">
                <h3 class="empty-title">尚無社群貼文監測</h3>
                <p class="empty-copy">新增 Facebook、Instagram、Threads 或 Dcard 公開貼文網址後，這裡會記錄可讀取的互動數。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="public-engagement-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">社群貼文最近一次可讀取的公開互動數、各數值擷取來源與觀看互動率</caption>
                    <thead>
                        <tr>
                            <th scope="col">貼文</th>
                            <th scope="col">平台</th>
                            <th scope="col">擷取狀態</th>
                            <th scope="col">觀看</th>
                            <th scope="col">讚</th>
                            <th scope="col">留言</th>
                            <th scope="col">分享</th>
                            <th scope="col">總互動</th>
                            <th scope="col">觀看互動率</th>
                            <th scope="col">資料時間</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $metricSourceLabels = [
                                'json_ld' => 'JSON-LD',
                                'embedded_json' => '內嵌 JSON',
                                'meta' => 'Meta 標籤',
                                'rendered_dom' => '瀏覽器呈現',
                            ];
                        @endphp
                        @foreach ($socialEngagementMonitors as $socialEngagementMonitor)
                            @php
                                $engagementRun = $socialEngagementMonitor->latestPublicEngagementCheckRun;
                                if ($engagementRun?->config_version !== $socialEngagementMonitor->config_version) {
                                    $engagementRun = null;
                                }
                                $engagementMetrics = $engagementRun?->response_public_engagement_metrics ?? [];
                                $metricSources = is_array($engagementMetrics['metric_sources'] ?? null) ? $engagementMetrics['metric_sources'] : [];
                            @endphp
                            <tr>
                                <th scope="row"><a href="{{ route('monitors.show', $socialEngagementMonitor) }}">{{ $socialEngagementMonitor->name }}</a></th>
                                <td>{{ $socialEngagementMonitor->socialPlatform() }}</td>
                                <td>{{ $socialEngagementMonitor->getAttribute('social_engagement_capture_status') }}</td>
                                @foreach (['views', 'likes', 'comments', 'shares'] as $metric)
                                    @php
                                        $metricSource = is_string($metricSources[$metric] ?? null) ? $metricSources[$metric] : null;
                                    @endphp
                                    <td class="numeric">
                                        {{ isset($engagementMetrics[$metric]) ? number_format((int) $engagementMetrics[$metric]) : '—' }}
                                        @if (is_string($metricSource) && isset($metricSourceLabels[$metricSource]))
                                            <span class="secondary-text">來源：{{ $metricSourceLabels[$metricSource] }}</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="numeric">
                                    {{ $socialEngagementMonitor->getAttribute('social_engagement_interaction_total') !== null ? number_format((int) $socialEngagementMonitor->getAttribute('social_engagement_interaction_total')) : '—' }}
                                </td>
                                <td class="numeric">
                                    {{ $socialEngagementMonitor->getAttribute('social_engagement_rate_percent') !== null ? number_format((float) $socialEngagementMonitor->getAttribute('social_engagement_rate_percent'), 2).'%' : '—' }}
                                </td>
                                <td>
                                    @if ($engagementRun?->finished_at)
                                        <time class="timestamp" datetime="{{ $engagementRun->finished_at->toIso8601String() }}">{{ $engagementRun->finished_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($socialEngagementMonitors->hasPages())
                <div class="panel-body pagination-wrap">{{ $socialEngagementMonitors->links('vendor.pagination.monitor') }}</div>
            @endif
            <p class="field-help spaced-top">擷取狀態顯示最近一次 HTTP 檢查；若本次沒有互動數，表格仍會標示前次資料。Instagram 與 Dcard 以讚＋留言計算互動合計；讀不到的數值不當成 0。觀看為 0 時不計比例。</p>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="top-engagement-posts-title">
        <div class="panel-header">
            <div>
                <h2 id="top-engagement-posts-title" class="panel-title">互動量排行</h2>
            </div>
            @if ($socialEngagementTopPosts !== [])
                <nav class="heading-actions" aria-label="互動量排行排序">
                    @foreach ($socialEngagementTopPostSortOptions as $sort => $sortLabel)
                        <a
                            class="button button-small {{ $socialEngagementTopPostSort === $sort ? 'button-primary' : 'button-secondary' }}"
                            href="{{ route('dashboard', ['client_id' => $selectedClientId, 'engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag, 'engagement_period' => $socialEngagementChangePeriodDays, 'engagement_tag_sort' => $socialEngagementTagSort, 'engagement_post_sort' => $sort, 'engagement_growth_sort' => $socialEngagementGrowthPostSort]) }}"
                            @if ($socialEngagementTopPostSort === $sort) aria-current="page" @endif
                        >{{ $sortLabel }}</a>
                    @endforeach
                </nav>
            @endif
        </div>
        @if ($socialEngagementTopPosts === [])
            <div class="empty-state">
                <h3 class="empty-title">尚無符合條件的貼文可排名</h3>
                <p class="empty-copy">Instagram 與 Dcard 需讀到讚與留言；Facebook 與 Threads 需讀到讚、留言與分享。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="top-engagement-posts-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">{{ $socialEngagementTopPostSort === 'engagement_rate' ? '依觀看互動率' : '依'.$socialEngagementInteractionScopeLabel.'合計' }}排序的前五篇社群貼文</caption>
                    <thead>
                        <tr>
                            <th scope="col">排名</th>
                            <th scope="col">貼文</th>
                            <th scope="col">平台</th>
                            <th scope="col">活動標籤</th>
                            <th scope="col">觀看</th>
                            <th scope="col">讚</th>
                            <th scope="col">留言</th>
                            <th scope="col">分享</th>
                            <th scope="col">互動合計（{{ $socialEngagementInteractionScopeLabel }}）</th>
                            <th scope="col">觀看互動率</th>
                            <th scope="col">數據新鮮度</th>
                            <th scope="col">資料時間</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($socialEngagementTopPosts as $topPost)
                            <tr>
                                <th scope="row">{{ $loop->iteration }}</th>
                                <td><a href="{{ route('monitors.show', $topPost['id']) }}">{{ $topPost['name'] }}</a></td>
                                <td>{{ $topPost['platform'] }}</td>
                                <td>{{ $topPost['tags'] !== [] ? implode(', ', $topPost['tags']) : '未標籤' }}</td>
                                <td class="numeric">{{ $topPost['views'] !== null ? number_format($topPost['views']) : '—' }}</td>
                                <td class="numeric">{{ number_format($topPost['likes']) }}</td>
                                <td class="numeric">{{ number_format($topPost['comments']) }}</td>
                                <td class="numeric">{{ $topPost['shares'] !== null ? number_format($topPost['shares']) : '—' }}</td>
                                <td class="numeric">
                                    {{ number_format($topPost['interaction_total']) }}
                                    <span class="secondary-text">依 {{ $topPost['interaction_scope_label'] }} 計</span>
                                </td>
                                <td class="numeric">{{ $topPost['engagement_rate_percent'] !== null ? number_format($topPost['engagement_rate_percent'], 2).'%' : '—' }}</td>
                                <td>{{ $topPost['metrics_freshness'] }}</td>
                                <td>
                                    @if ($topPost['finished_at'] !== null)
                                        <time class="timestamp" datetime="{{ $topPost['finished_at']->toIso8601String() }}">{{ $topPost['finished_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="tagged-engagement-title">
        <div class="panel-header">
            <div>
                <h2 id="tagged-engagement-title" class="panel-title">標籤成效比較</h2>
            </div>
            @if ($socialEngagementSummaries !== [])
                <nav class="heading-actions" aria-label="標籤成效排序">
                    @foreach ($socialEngagementTagSortOptions as $sort => $sortLabel)
                        <a
                            class="button button-small {{ $socialEngagementTagSort === $sort ? 'button-primary' : 'button-secondary' }}"
                            href="{{ route('dashboard', ['client_id' => $selectedClientId, 'engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag, 'engagement_period' => $socialEngagementChangePeriodDays, 'engagement_tag_sort' => $sort, 'engagement_post_sort' => $socialEngagementTopPostSort, 'engagement_growth_sort' => $socialEngagementGrowthPostSort]) }}"
                            @if ($socialEngagementTagSort === $sort) aria-current="page" @endif
                        >{{ $sortLabel }}</a>
                    @endforeach
                </nav>
            @endif
        </div>
        @if ($socialEngagementSummaries === [])
            <div class="empty-state">
                <h3 class="empty-title">尚無標籤成效資料</h3>
                <p class="empty-copy">新增社群貼文監測後，這裡會比較不同標籤下的公開互動數。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="tagged-engagement-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">依目前排序方式排列的標籤成效與資料覆蓋篇數</caption>
                    <thead>
                        <tr>
                            <th scope="col">標籤</th>
                            <th scope="col">貼文數</th>
                            <th scope="col">平均互動／篇</th>
                            <th scope="col">擷取篇數</th>
                            @foreach (['views' => '觀看', 'likes' => '讚', 'comments' => '留言', 'shares' => '分享'] as $metricLabel)
                                <th scope="col">{{ $metricLabel }}</th>
                            @endforeach
                            <th scope="col">觀看互動率</th>
                            <th scope="col">最近資料</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($socialEngagementSummaries as $socialEngagementSummary)
                            <tr>
                                <th scope="row">
                                    @if ($socialEngagementSummary['filter_label'] !== null)
                                        <a href="{{ route('dashboard', ['client_id' => $selectedClientId, 'engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => 'tag:'.$socialEngagementSummary['filter_label'], 'engagement_period' => $socialEngagementChangePeriodDays, 'engagement_tag_sort' => $socialEngagementTagSort, 'engagement_post_sort' => $socialEngagementTopPostSort, 'engagement_growth_sort' => $socialEngagementGrowthPostSort]) }}">{{ $socialEngagementSummary['tag'] }}</a>
                                    @else
                                        {{ $socialEngagementSummary['tag'] }}
                                    @endif
                                </th>
                                <td class="numeric">{{ number_format($socialEngagementSummary['monitor_count']) }}</td>
                                <td class="numeric">
                                    {{ $socialEngagementSummary['average_interactions_per_post'] !== null ? number_format($socialEngagementSummary['average_interactions_per_post'], 1) : '—' }}
                                    <span class="secondary-text">({{ number_format($socialEngagementSummary['interaction_count']) }} 篇符合口徑)</span>
                                </td>
                                <td class="numeric">
                                    {{ number_format($socialEngagementSummary['capture_counts']['current']) }} 本次
                                    <span class="secondary-text">／{{ number_format($socialEngagementSummary['capture_counts']['previous']) }} 前次</span>
                                </td>
                                @foreach (['views', 'likes', 'comments', 'shares'] as $metric)
                                    @php
                                        $metricSummary = $socialEngagementSummary['metrics'][$metric];
                                    @endphp
                                    <td class="numeric">
                                        {{ $metricSummary['available'] > 0 ? number_format($metricSummary['total']) : '—' }}
                                        <span class="secondary-text">({{ number_format($metricSummary['available']) }}/{{ number_format($socialEngagementSummary['monitor_count']) }} 篇)</span>
                                    </td>
                                @endforeach
                                @php
                                    $engagementRate = $socialEngagementSummary['engagement_rate'];
                                @endphp
                                <td class="numeric">
                                    @if ($engagementRate['views'] > 0)
                                        {{ number_format($engagementRate['interactions'] / $engagementRate['views'] * 100, 2) }}%
                                    @else
                                        —
                                    @endif
                                    <span class="secondary-text">({{ number_format($engagementRate['available']) }}/{{ number_format($socialEngagementSummary['monitor_count']) }} 篇)</span>
                                </td>
                                <td>
                                    @if ($socialEngagementSummary['latest_at'])
                                        <time class="timestamp" datetime="{{ $socialEngagementSummary['latest_at']->toIso8601String() }}">{{ $socialEngagementSummary['latest_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="social-engagement-targets-title">
        <div class="panel-header">
            <div>
                <h2 id="social-engagement-targets-title" class="panel-title">活動互動目標</h2>
            </div>
            <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.export', ['client_id' => $selectedClientId]) }}">匯出目標 CSV</a>
        </div>
        <div class="panel-body">
            <form action="{{ route('social-engagement-targets.store') }}" method="POST" class="check-history-filter" aria-labelledby="social-engagement-targets-title" aria-describedby="social-engagement-target-help">
                @csrf
                <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                <p class="dashboard-target-client">客戶：{{ $clients->firstWhere('id', $selectedClientId)?->name }}</p>
                <div class="field-group">
                    <label class="field-label" for="social-engagement-target-platform">社群平台</label>
                    <select class="field-select{{ $errors->getBag('socialEngagementTarget')->has('social_platform') ? ' is-invalid' : '' }}" id="social-engagement-target-platform" name="social_platform" required aria-describedby="social-engagement-target-platform-help{{ $errors->getBag('socialEngagementTarget')->has('social_platform') ? ' social-engagement-target-platform-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('social_platform')) aria-invalid="true" @endif>
                        @foreach ($socialEngagementTargetPlatformLabels as $platform => $platformLabel)
                            <option value="{{ $platform }}" @selected(old('social_platform', $socialEngagementTargetFormPlatform) === $platform)>{{ $platformLabel }}</option>
                        @endforeach
                    </select>
                    <p id="social-engagement-target-platform-help" class="field-help">各平台目標分開計算。</p>
                    @error('social_platform', 'socialEngagementTarget')<p id="social-engagement-target-platform-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="field-group">
                    <label class="field-label" for="social-engagement-target-tag">活動標籤</label>
                    <select class="field-select{{ $errors->getBag('socialEngagementTarget')->has('tag') ? ' is-invalid' : '' }}" id="social-engagement-target-tag" name="tag" required aria-describedby="social-engagement-target-tag-help{{ $errors->getBag('socialEngagementTarget')->has('tag') ? ' social-engagement-target-tag-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('tag')) aria-invalid="true" @endif>
                        <option value="">選擇標籤</option>
                        @foreach ($socialEngagementTargetTagOptions as $tag => $platforms)
                            <option value="{{ $tag }}" @selected(old('tag', $socialEngagementTargetFormTag) === $tag)>{{ $tag }} · {{ implode('、', array_values($platforms)) }}</option>
                        @endforeach
                    </select>
                    <p id="social-engagement-target-tag-help" class="field-help">標籤必須有此平台的貼文。</p>
                    @error('tag', 'socialEngagementTarget')<p id="social-engagement-target-tag-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="field-group">
                    <label class="field-label" for="social-engagement-target-count">目標互動數</label>
                    <input class="field-input{{ $errors->getBag('socialEngagementTarget')->has('target_interactions') ? ' is-invalid' : '' }}" id="social-engagement-target-count" name="target_interactions" type="number" inputmode="numeric" min="1" max="1000000000" step="1" value="{{ old('target_interactions', $socialEngagementTargetFormTarget?->target_interactions) }}" required aria-describedby="social-engagement-target-count-help{{ $errors->getBag('socialEngagementTarget')->has('target_interactions') ? ' social-engagement-target-count-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('target_interactions')) aria-invalid="true" @endif>
                    <p id="social-engagement-target-count-help" class="field-help">至少 1；再次儲存相同標籤與平台會更新目標。</p>
                    @error('target_interactions', 'socialEngagementTarget')<p id="social-engagement-target-count-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <fieldset class="campaign-business-goal-fields">
                    <legend>GA4 商業目標</legend>
                    <div class="campaign-business-goal-grid">
                        <div class="field-group">
                            <label class="field-label" for="social-engagement-target-ga4-goal-metric">目標指標</label>
                            <select class="field-select{{ $errors->getBag('socialEngagementTarget')->has('ga4_goal_metric') ? ' is-invalid' : '' }}" id="social-engagement-target-ga4-goal-metric" name="ga4_goal_metric" aria-describedby="social-engagement-target-ga4-goal-metric-help{{ $errors->getBag('socialEngagementTarget')->has('ga4_goal_metric') ? ' social-engagement-target-ga4-goal-metric-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('ga4_goal_metric')) aria-invalid="true" @endif>
                                <option value="" @selected(old('ga4_goal_metric', $socialEngagementTargetFormTarget?->ga4_goal_metric) === null)>不設定</option>
                                <option value="key_events" @selected(old('ga4_goal_metric', $socialEngagementTargetFormTarget?->ga4_goal_metric) === 'key_events')>GA4 重要事件</option>
                                <option value="total_revenue" @selected(old('ga4_goal_metric', $socialEngagementTargetFormTarget?->ga4_goal_metric) === 'total_revenue')>GA4 營收</option>
                            </select>
                            <p id="social-engagement-target-ga4-goal-metric-help" class="field-help">每個活動擇一追蹤，不混合不同平台的歸因數據。</p>
                            @error('ga4_goal_metric', 'socialEngagementTarget')<p id="social-engagement-target-ga4-goal-metric-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="social-engagement-target-ga4-goal-value">目標數值</label>
                            <input class="field-input{{ $errors->getBag('socialEngagementTarget')->has('ga4_goal_value') ? ' is-invalid' : '' }}" id="social-engagement-target-ga4-goal-value" name="ga4_goal_value" type="number" inputmode="decimal" min="0.0001" max="9999999999999999" step="0.0001" value="{{ old('ga4_goal_value', $socialEngagementTargetFormTarget?->ga4_goal_value) }}" aria-describedby="social-engagement-target-ga4-goal-value-help{{ $errors->getBag('socialEngagementTarget')->has('ga4_goal_value') ? ' social-engagement-target-ga4-goal-value-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('ga4_goal_value')) aria-invalid="true" @endif>
                            <p id="social-engagement-target-ga4-goal-value-help" class="field-help">重要事件或營收目標都需大於 0。</p>
                            @error('ga4_goal_value', 'socialEngagementTarget')<p id="social-engagement-target-ga4-goal-value-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="social-engagement-target-ga4-goal-currency">營收幣別</label>
                            <input class="field-input{{ $errors->getBag('socialEngagementTarget')->has('ga4_goal_currency') ? ' is-invalid' : '' }}" id="social-engagement-target-ga4-goal-currency" name="ga4_goal_currency" type="text" inputmode="text" maxlength="3" pattern="[A-Z]{3}" autocapitalize="characters" spellcheck="false" value="{{ old('ga4_goal_currency', $socialEngagementTargetFormTarget?->ga4_goal_currency) }}" aria-describedby="social-engagement-target-ga4-goal-currency-help{{ $errors->getBag('socialEngagementTarget')->has('ga4_goal_currency') ? ' social-engagement-target-ga4-goal-currency-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('ga4_goal_currency')) aria-invalid="true" @endif>
                            <p id="social-engagement-target-ga4-goal-currency-help" class="field-help">營收目標需填 GA4 報表的 3 位大寫幣別；重要事件不使用幣別。</p>
                            @error('ga4_goal_currency', 'socialEngagementTarget')<p id="social-engagement-target-ga4-goal-currency-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </fieldset>
                <fieldset class="campaign-business-goal-fields">
                    <legend>付費媒體 ROAS 目標</legend>
                    <div class="campaign-business-goal-grid">
                        <div class="field-group">
                            <label class="field-label" for="social-engagement-target-paid-media-goal-source">目標來源</label>
                            <select class="field-select{{ $errors->getBag('socialEngagementTarget')->has('paid_media_goal_source') ? ' is-invalid' : '' }}" id="social-engagement-target-paid-media-goal-source" name="paid_media_goal_source" aria-describedby="social-engagement-target-paid-media-goal-source-help{{ $errors->getBag('socialEngagementTarget')->has('paid_media_goal_source') ? ' social-engagement-target-paid-media-goal-source-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('paid_media_goal_source')) aria-invalid="true" @endif>
                                <option value="" @selected(old('paid_media_goal_source', $socialEngagementTargetFormTarget?->paid_media_goal_source) === null)>不設定</option>
                                <option value="ga4" @selected(old('paid_media_goal_source', $socialEngagementTargetFormTarget?->paid_media_goal_source) === 'ga4')>GA4 ROAS</option>
                                <option value="meta_ads" @selected(old('paid_media_goal_source', $socialEngagementTargetFormTarget?->paid_media_goal_source) === 'meta_ads')>Meta Ads 購買 ROAS</option>
                                <option value="google_ads" @selected(old('paid_media_goal_source', $socialEngagementTargetFormTarget?->paid_media_goal_source) === 'google_ads')>Google Ads 轉換價值 ROAS</option>
                            </select>
                            <p id="social-engagement-target-paid-media-goal-source-help" class="field-help">只選一種來源；GA4 ROAS 使用活動目前配對的花費來源。</p>
                            @error('paid_media_goal_source', 'socialEngagementTarget')<p id="social-engagement-target-paid-media-goal-source-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="social-engagement-target-paid-media-goal-roas">目標 ROAS</label>
                            <input class="field-input{{ $errors->getBag('socialEngagementTarget')->has('paid_media_goal_roas') ? ' is-invalid' : '' }}" id="social-engagement-target-paid-media-goal-roas" name="paid_media_goal_roas" type="number" inputmode="decimal" min="0.0001" max="9999999999999999" step="0.0001" value="{{ old('paid_media_goal_roas', $socialEngagementTargetFormTarget?->paid_media_goal_roas) }}" aria-describedby="social-engagement-target-paid-media-goal-roas-help{{ $errors->getBag('socialEngagementTarget')->has('paid_media_goal_roas') ? ' social-engagement-target-paid-media-goal-roas-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('paid_media_goal_roas')) aria-invalid="true" @endif>
                            <p id="social-engagement-target-paid-media-goal-roas-help" class="field-help">例如 2.5 代表每 1 元花費帶來 2.5 元轉換價值；需大於 0。</p>
                            @error('paid_media_goal_roas', 'socialEngagementTarget')<p id="social-engagement-target-paid-media-goal-roas-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </fieldset>
                <fieldset class="campaign-business-goal-fields">
                    <legend>估算毛利貢獻</legend>
                    <div class="campaign-business-goal-grid">
                        <div class="field-group">
                            <label class="field-label" for="social-engagement-target-gross-margin-percent">毛利率（%）</label>
                            <input class="field-input{{ $errors->getBag('socialEngagementTarget')->has('gross_margin_percent') ? ' is-invalid' : '' }}" id="social-engagement-target-gross-margin-percent" name="gross_margin_percent" type="number" inputmode="decimal" min="0" max="100" step="0.0001" value="{{ old('gross_margin_percent', $socialEngagementTargetFormTarget?->gross_margin_percent) }}" aria-describedby="social-engagement-target-gross-margin-percent-help{{ $errors->getBag('socialEngagementTarget')->has('gross_margin_percent') ? ' social-engagement-target-gross-margin-percent-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('gross_margin_percent')) aria-invalid="true" @endif>
                            <p id="social-engagement-target-gross-margin-percent-help" class="field-help">選填，不預設毛利假設；留白不計算。估算不含物流、交易費與固定營運成本。</p>
                            @error('gross_margin_percent', 'socialEngagementTarget')<p id="social-engagement-target-gross-margin-percent-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </fieldset>
                <div class="field-group">
                    <label class="field-label" for="social-engagement-target-starts-on">活動開始日</label>
                    <input class="field-input{{ $errors->getBag('socialEngagementTarget')->has('starts_on') ? ' is-invalid' : '' }}" id="social-engagement-target-starts-on" name="starts_on" type="date" value="{{ old('starts_on', $socialEngagementTargetFormTarget?->starts_on?->toDateString()) }}" aria-describedby="social-engagement-target-period-help{{ $errors->getBag('socialEngagementTarget')->has('starts_on') ? ' social-engagement-target-start-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('starts_on')) aria-invalid="true" @endif>
                    @error('starts_on', 'socialEngagementTarget')<p id="social-engagement-target-start-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="field-group">
                    <label class="field-label" for="social-engagement-target-ends-on">活動結束日</label>
                    <input class="field-input{{ $errors->getBag('socialEngagementTarget')->has('ends_on') ? ' is-invalid' : '' }}" id="social-engagement-target-ends-on" name="ends_on" type="date" value="{{ old('ends_on', $socialEngagementTargetFormTarget?->ends_on?->toDateString()) }}" aria-describedby="social-engagement-target-period-help{{ $errors->getBag('socialEngagementTarget')->has('ends_on') ? ' social-engagement-target-end-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('ends_on')) aria-invalid="true" @endif>
                    @error('ends_on', 'socialEngagementTarget')<p id="social-engagement-target-end-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="field-group">
                    <label class="field-label" for="social-engagement-target-ga4-campaign">GA4 活動名稱（utm_campaign）</label>
                    <input class="field-input{{ $errors->getBag('socialEngagementTarget')->has('ga4_campaign_name') ? ' is-invalid' : '' }}" id="social-engagement-target-ga4-campaign" name="ga4_campaign_name" type="text" maxlength="100" value="{{ old('ga4_campaign_name', $socialEngagementTargetFormTarget?->ga4_campaign_name) }}" aria-describedby="social-engagement-target-ga4-campaign-help{{ $errors->getBag('socialEngagementTarget')->has('ga4_campaign_name') ? ' social-engagement-target-ga4-campaign-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('ga4_campaign_name')) aria-invalid="true" @endif>
                    <p id="social-engagement-target-ga4-campaign-help" class="field-help">需與導流網址的 utm_campaign 相同；留空時使用活動標籤。</p>
                    @error('ga4_campaign_name', 'socialEngagementTarget')<p id="social-engagement-target-ga4-campaign-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="field-group">
                    <label class="field-label" for="social-engagement-target-ga4-source">GA4 來源（utm_source）</label>
                    <input class="field-input{{ $errors->getBag('socialEngagementTarget')->has('ga4_source_name') ? ' is-invalid' : '' }}" id="social-engagement-target-ga4-source" name="ga4_source_name" type="text" maxlength="100" value="{{ old('ga4_source_name', $socialEngagementTargetFormTarget?->ga4_source_name) }}" aria-describedby="social-engagement-target-ga4-source-help{{ $errors->getBag('socialEngagementTarget')->has('ga4_source_name') ? ' social-engagement-target-ga4-source-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('ga4_source_name')) aria-invalid="true" @endif>
                    <p id="social-engagement-target-ga4-source-help" class="field-help">選填；用來區分平台流量，例如 facebook、instagram 或 threads。</p>
                    @error('ga4_source_name', 'socialEngagementTarget')<p id="social-engagement-target-ga4-source-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="field-group">
                    <label class="field-label" for="social-engagement-target-ga4-medium">GA4 媒介（utm_medium）</label>
                    <input class="field-input{{ $errors->getBag('socialEngagementTarget')->has('ga4_medium_name') ? ' is-invalid' : '' }}" id="social-engagement-target-ga4-medium" name="ga4_medium_name" type="text" maxlength="100" value="{{ old('ga4_medium_name', $socialEngagementTargetFormTarget?->ga4_medium_name) }}" aria-describedby="social-engagement-target-ga4-medium-help{{ $errors->getBag('socialEngagementTarget')->has('ga4_medium_name') ? ' social-engagement-target-ga4-medium-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('ga4_medium_name')) aria-invalid="true" @endif>
                    <p id="social-engagement-target-ga4-medium-help" class="field-help">選填；例如 paid_social、organic 或 email，用來分開付費與自然流量。</p>
                    @error('ga4_medium_name', 'socialEngagementTarget')<p id="social-engagement-target-ga4-medium-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="field-group">
                    <label class="field-label" for="social-engagement-target-meta-campaign">Meta Ads 活動 ID</label>
                    <input class="field-input{{ $errors->getBag('socialEngagementTarget')->has('meta_campaign_id') ? ' is-invalid' : '' }}" id="social-engagement-target-meta-campaign" name="meta_campaign_id" type="text" inputmode="numeric" maxlength="32" pattern="[0-9]{5,32}" value="{{ old('meta_campaign_id', $socialEngagementTargetFormTarget?->meta_campaign_id) }}" aria-describedby="social-engagement-target-meta-campaign-help{{ $errors->getBag('socialEngagementTarget')->has('meta_campaign_id') ? ' social-engagement-target-meta-campaign-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('meta_campaign_id')) aria-invalid="true" @endif>
                    <p id="social-engagement-target-meta-campaign-help" class="field-help">選填；已連結 Meta Ads 後，可同步此活動的投放成效與追蹤行動。</p>
                    @error('meta_campaign_id', 'socialEngagementTarget')<p id="social-engagement-target-meta-campaign-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="field-group">
                    <label class="field-label" for="social-engagement-target-google-ads-campaign">Google Ads 活動 ID</label>
                    <input class="field-input{{ $errors->getBag('socialEngagementTarget')->has('google_ads_campaign_id') ? ' is-invalid' : '' }}" id="social-engagement-target-google-ads-campaign" name="google_ads_campaign_id" type="text" inputmode="numeric" maxlength="32" pattern="[0-9]{5,32}" value="{{ old('google_ads_campaign_id', $socialEngagementTargetFormTarget?->google_ads_campaign_id) }}" aria-describedby="social-engagement-target-google-ads-campaign-help{{ $errors->getBag('socialEngagementTarget')->has('google_ads_campaign_id') ? ' social-engagement-target-google-ads-campaign-error' : '' }}" @if ($errors->getBag('socialEngagementTarget')->has('google_ads_campaign_id')) aria-invalid="true" @endif>
                    <p id="social-engagement-target-google-ads-campaign-help" class="field-help">選填；可在活動報告載入並套用帳戶活動，或輸入 ID 後同步投放數據。</p>
                    @error('google_ads_campaign_id', 'socialEngagementTarget')<p id="social-engagement-target-google-ads-campaign-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <input type="hidden" name="engagement_platform" value="{{ $socialEngagementSelectedPlatform }}">
                <input type="hidden" name="engagement_tag" value="{{ $socialEngagementSelectedTag }}">
                <div class="check-history-filter-actions">
                    <button class="button button-primary" type="submit">儲存目標</button>
                </div>
                <p id="social-engagement-target-period-help" class="field-help">活動期間需同時設定開始日與結束日；只納入期間內發布且有日期的貼文。</p>
                <p id="social-engagement-target-help" class="field-help">互動口徑依平台：Facebook／Threads 計讚、留言與分享；Instagram／Dcard 計讚與留言。目標不會把未讀到的數據當成 0。</p>
            </form>
        </div>
        @if ($socialEngagementTargets === [])
            <div class="empty-state">
                <h3 class="empty-title">尚未設定互動目標</h3>
                <p class="empty-copy">儲存後會顯示各活動標籤目前累計互動與讀值覆蓋率。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="social-engagement-targets-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">各平台活動互動目標與資料覆蓋及報告操作</caption>
                    <thead>
                        <tr>
                            <th scope="col">活動標籤</th>
                            <th scope="col">平台</th>
                            <th scope="col">活動期間</th>
                            <th scope="col">目前／目標互動</th>
                            <th scope="col">達成率</th>
                            <th scope="col">每日需新增</th>
                            <th scope="col">資料覆蓋</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($socialEngagementTargets as $socialEngagementTarget)
                            <tr>
                                <th scope="row">{{ $socialEngagementTarget['tag'] }}</th>
                                <td>{{ $socialEngagementTarget['platform_label'] }}</td>
                                <td>{{ $socialEngagementTarget['starts_on'] === null ? '不限日期' : $socialEngagementTarget['starts_on'].' 至 '.$socialEngagementTarget['ends_on'] }}</td>
                                <td class="numeric">{{ $socialEngagementTarget['current_interactions'] === null ? '—' : number_format($socialEngagementTarget['current_interactions']) }}／{{ number_format($socialEngagementTarget['target_interactions']) }}</td>
                                <td class="numeric">{{ $socialEngagementTarget['completion_percent'] === null ? '尚無完整讀值' : number_format($socialEngagementTarget['completion_percent'], 1).'%' }}</td>
                                <td class="numeric">
                                    @if ($socialEngagementTarget['daily_required_interactions'] !== null)
                                        {{ $socialEngagementTarget['pacing_status'] === 'scheduled' ? '每日目標 ' : '需新增 ' }}{{ number_format($socialEngagementTarget['daily_required_interactions']) }} 次
                                        <span class="secondary-text">／{{ $socialEngagementTarget['pacing_status'] === 'scheduled' ? '共' : '剩' }} {{ number_format($socialEngagementTarget['remaining_days']) }} 天</span>
                                    @else
                                        {{ $socialEngagementTarget['pacing_status_label'] }}
                                    @endif
                                </td>
                                <td class="numeric">已讀 {{ number_format($socialEngagementTarget['available_post_count']) }}／{{ number_format($socialEngagementTarget['tracked_post_count']) }} 篇@if ($socialEngagementTarget['unknown_publication_date_count'] > 0)<span class="secondary-text">另有 {{ number_format($socialEngagementTarget['unknown_publication_date_count']) }} 篇缺發布時間</span>@endif</td>
                                <td>
                                    <div class="social-target-actions">
                                        <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.report', $socialEngagementTarget['id']) }}" aria-label="查看 {{ $socialEngagementTarget['tag'] }} 的 {{ $socialEngagementTarget['platform_label'] }} 活動報告">報告</a>
                                        <form action="{{ route('social-engagement-targets.destroy', $socialEngagementTarget['id']) }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                                            <input type="hidden" name="engagement_platform" value="{{ $socialEngagementSelectedPlatform }}">
                                            <input type="hidden" name="engagement_tag" value="{{ $socialEngagementSelectedTag }}">
                                            <button class="button button-danger button-small" type="submit" aria-label="刪除 {{ $socialEngagementTarget['tag'] }} 的 {{ $socialEngagementTarget['platform_label'] }} 互動目標">刪除</button>
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

    <section class="panel spaced-top" aria-labelledby="campaign-marketing-overview-title">
        <div class="panel-header">
            <div>
                <h2 id="campaign-marketing-overview-title" class="panel-title">活動行銷成效</h2>
            </div>
        </div>
        @if ($socialEngagementTargets === [])
            <div class="empty-state">
                <h3 class="empty-title">尚無活動行銷成效</h3>
                <p class="empty-copy">設定活動目標並同步 GA4 或廣告平台後，這裡會顯示成效摘要。</p>
            </div>
        @else
            @php
                $portfolioCoverage = $campaignPortfolioMarketingSummary['coverage'];
                $budgetCoverage = $campaignPortfolioMarketingSummary['budget_coverage'];
                $ga4BusinessGoalCoverage = $campaignPortfolioMarketingSummary['ga4_business_goal_coverage'];
                $portfolioSourceLabels = [
                    'google_analytics' => 'GA4',
                    'meta_marketing' => 'Meta Ads',
                    'google_ads' => 'Google Ads',
                ];
            @endphp
            @if ($ga4BusinessGoalCoverage['configured_campaigns'] > 0)
                <p class="campaign-report-secondary">
                    GA4 目標：已設定 {{ number_format($ga4BusinessGoalCoverage['configured_campaigns']) }} 個，可比較 {{ number_format($ga4BusinessGoalCoverage['available_campaigns']) }} 個；領先 {{ number_format($ga4BusinessGoalCoverage['ahead_campaigns']) }}、落後 {{ number_format($ga4BusinessGoalCoverage['behind_campaigns']) }}、符合進度 {{ number_format($ga4BusinessGoalCoverage['on_track_campaigns']) }}、尚未開始 {{ number_format($ga4BusinessGoalCoverage['not_started_campaigns']) }}、已結束 {{ number_format($ga4BusinessGoalCoverage['completed_campaigns']) }}、進度資料不足 {{ number_format($ga4BusinessGoalCoverage['data_unavailable_campaigns']) }}。進度依活動日數線性比較。
                </p>
            @endif
            <div class="data-table-wrap spaced-top" role="region" aria-label="跨活動行銷數據總覽" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">目前篩選活動的 GA4 營收、廣告花費與可比較 ROAS，依幣別分組</caption>
                    <thead>
                        <tr>
                            <th scope="col">幣別</th>
                            <th scope="col" class="numeric">GA4 營收</th>
                            <th scope="col" class="numeric">Meta Ads 花費</th>
                            <th scope="col" class="numeric">Google Ads 花費</th>
                            <th scope="col" class="numeric">可比較 ROAS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($campaignPortfolioMarketingSummary['currency_groups'] as $portfolioGroup)
                            <tr>
                                <th scope="row">{{ $portfolioGroup['currency_code'] }}</th>
                                <td class="numeric">
                                    @if ($portfolioGroup['ga4_campaign_count'] > 0)
                                        {{ number_format($portfolioGroup['ga4_revenue'], 2) }}
                                        <span class="secondary-text">{{ number_format($portfolioGroup['ga4_campaign_count']) }} 個活動</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="numeric">
                                    @if ($portfolioGroup['meta_campaign_count'] > 0)
                                        {{ number_format($portfolioGroup['meta_spend'], 2) }}
                                        <span class="secondary-text">{{ number_format($portfolioGroup['meta_campaign_count']) }} 個活動</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="numeric">
                                    @if ($portfolioGroup['google_ads_campaign_count'] > 0)
                                        {{ number_format($portfolioGroup['google_ads_spend'], 2) }}
                                        <span class="secondary-text">{{ number_format($portfolioGroup['google_ads_campaign_count']) }} 個活動</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="numeric">
                                    @if ($portfolioGroup['roas'] !== null)
                                        {{ number_format($portfolioGroup['roas'], 2) }}x
                                        <span class="secondary-text">{{ number_format($portfolioGroup['roas_campaign_count']) }} 個可比較活動</span>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="secondary-text">目前沒有可彙總的營收或花費資料。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($campaignPortfolioMarketingSummary['reporting_currency_groups'] !== [])
                <section class="spaced-top" aria-labelledby="reporting-currency-overview-title">
                    <h3 id="reporting-currency-overview-title" class="panel-title">報表幣別彙總</h3>
                    <p class="campaign-report-secondary">依每日有效匯率換算至各客戶設定的報表幣別；跨幣別資料缺少任一日匯率時，該來源活動不納入金額。</p>
                    <div class="data-table-wrap" role="region" aria-labelledby="reporting-currency-overview-title" tabindex="0">
                        <table class="data-table">
                            <caption class="sr-only">依客戶設定的報表幣別換算 GA4 營收與 Meta、Google Ads 花費</caption>
                            <thead>
                                <tr>
                                    <th scope="col">報表幣別</th>
                                    <th scope="col" class="numeric">GA4 營收</th>
                                    <th scope="col" class="numeric">Meta Ads 花費</th>
                                    <th scope="col" class="numeric">Google Ads 花費</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campaignPortfolioMarketingSummary['reporting_currency_groups'] as $reportingCurrencyGroup)
                                    <tr>
                                        <th scope="row">{{ $reportingCurrencyGroup['currency_code'] }}</th>
                                        @foreach ([
                                            'google_analytics' => ['amount' => 'ga4_revenue', 'count' => 'ga4_campaign_count'],
                                            'meta_marketing' => ['amount' => 'meta_spend', 'count' => 'meta_campaign_count'],
                                            'google_ads' => ['amount' => 'google_ads_spend', 'count' => 'google_ads_campaign_count'],
                                        ] as $source => $metric)
                                            @php
                                                $conversionCoverage = $reportingCurrencyGroup['conversion_coverage'][$source] ?? ['converted_campaigns' => 0, 'missing_rate_campaigns' => 0, 'missing_daily_data_campaigns' => 0, 'missing_rate_observations' => 0];
                                            @endphp
                                            <td class="numeric">
                                                @if ($reportingCurrencyGroup[$metric['count']] > 0)
                                                    {{ number_format($reportingCurrencyGroup[$metric['amount']], 2) }}
                                                    <span class="secondary-text">{{ number_format($conversionCoverage['converted_campaigns']) }} 個來源活動</span>
                                                @else
                                                    —
                                                @endif
                                                @if ($conversionCoverage['missing_rate_campaigns'] > 0 || $conversionCoverage['missing_daily_data_campaigns'] > 0)
                                                    <span class="secondary-text">缺匯率 {{ number_format($conversionCoverage['missing_rate_campaigns']) }} 個活動／{{ number_format($conversionCoverage['missing_rate_observations']) }} 個日期；缺逐日資料 {{ number_format($conversionCoverage['missing_daily_data_campaigns']) }} 個</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
            <section class="spaced-top" aria-labelledby="campaign-native-roas-overview-title">
                <h3 id="campaign-native-roas-overview-title" class="panel-title">廣告平台歸因 ROAS</h3>
                <p class="campaign-report-secondary">
                    Meta Purchase ROAS 與 Google Ads 轉換價值 ROAS 依平台及幣別分開加權彙總；平台歸因價值可能重疊，不與 GA4 營收相加。
                </p>
                <div class="data-table-wrap" role="region" aria-labelledby="campaign-native-roas-overview-title" tabindex="0">
                    <table class="data-table">
                        <caption class="sr-only">Meta 與 Google Ads 平台歸因轉換價值、相符花費、加權 ROAS 與活動資料覆蓋</caption>
                        <thead>
                            <tr>
                                <th scope="col">廣告來源</th>
                                <th scope="col">幣別</th>
                                <th scope="col" class="numeric">平台轉換價值</th>
                                <th scope="col" class="numeric">相符花費</th>
                                <th scope="col" class="numeric">加權 ROAS</th>
                                <th scope="col">資料涵蓋</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($campaignPortfolioMarketingSummary['native_roas_groups'] as $nativeRoasGroup)
                                <tr>
                                    <th scope="row">{{ $nativeRoasGroup['source_label'] }}</th>
                                    <td>{{ $nativeRoasGroup['currency_code'] }}</td>
                                    <td class="numeric">{{ number_format($nativeRoasGroup['conversion_value_amount'], 2) }}</td>
                                    <td class="numeric">{{ number_format($nativeRoasGroup['matched_spend_amount'], 2) }}</td>
                                    <td class="numeric">
                                        @if ($nativeRoasGroup['roas'] !== null)
                                            {{ number_format($nativeRoasGroup['roas'], 2) }}x
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        {{ number_format($nativeRoasGroup['comparable_campaign_count']) }} / {{ number_format($nativeRoasGroup['campaign_count']) }} 個可比較
                                        <span class="secondary-text">新鮮 {{ number_format($nativeRoasGroup['fresh_campaign_count']) }}、過期 {{ number_format($nativeRoasGroup['stale_campaign_count']) }}、缺漏 {{ number_format($nativeRoasGroup['missing_campaign_count']) }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="secondary-text">尚無可彙總的平台轉換價值與花費。</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="campaign-report-secondary spaced-top">
                    Meta Ads：{{ number_format($campaignPortfolioMarketingSummary['native_roas_coverage']['meta_marketing']['comparable_campaigns']) }} / {{ number_format($campaignPortfolioMarketingSummary['native_roas_coverage']['meta_marketing']['configured_campaigns']) }} 個可比較，缺漏 {{ number_format($campaignPortfolioMarketingSummary['native_roas_coverage']['meta_marketing']['missing_campaigns']) }}，排除重複映射 {{ number_format($campaignPortfolioMarketingSummary['native_roas_coverage']['meta_marketing']['duplicate_mappings_excluded']) }} 筆；
                    Google Ads：{{ number_format($campaignPortfolioMarketingSummary['native_roas_coverage']['google_ads']['comparable_campaigns']) }} / {{ number_format($campaignPortfolioMarketingSummary['native_roas_coverage']['google_ads']['configured_campaigns']) }} 個可比較，缺漏 {{ number_format($campaignPortfolioMarketingSummary['native_roas_coverage']['google_ads']['missing_campaigns']) }}，排除重複映射 {{ number_format($campaignPortfolioMarketingSummary['native_roas_coverage']['google_ads']['duplicate_mappings_excluded']) }} 筆。
                </p>
            </section>
            <section class="spaced-top" aria-labelledby="campaign-mapping-audit-title">
                <h3 id="campaign-mapping-audit-title" class="panel-title">活動共用映射</h3>
                <p class="campaign-report-secondary">共用的廣告活動 ID 或 GA4 歸因條件會合併計算。清單列出共用標籤與目前採用的資料狀態。</p>
                @if ($campaignPortfolioMarketingSummary['mapping_audit_rows'] === [])
                    <p class="campaign-report-secondary">目前沒有共用映射。</p>
                @else
                    <div class="data-table-wrap" role="region" aria-labelledby="campaign-mapping-audit-title" tabindex="0">
                        <table class="data-table">
                            <caption class="sr-only">共用廣告平台活動 ID 與 GA4 歸因條件的合併狀況</caption>
                            <thead>
                                <tr>
                                    <th scope="col">映射條件</th>
                                    <th scope="col">活動標籤</th>
                                    <th scope="col">採用資料</th>
                                    <th scope="col" class="numeric">合併數</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campaignPortfolioMarketingSummary['mapping_audit_rows'] as $mappingAuditRow)
                                    <tr>
                                        <th scope="row">
                                            {{ $mappingAuditRow['type_label'] }}
                                            @foreach ($mappingAuditRow['mappings'] as $mapping)
                                                @if ($mapping['attribution_mode'] === 'manual_utm')
                                                    <span class="secondary-text">GA4 UTM：活動 {{ $mapping['campaign_name'] ?? '—' }} · 來源 {{ $mapping['source_name'] ?? '未設定' }} · 媒介 {{ $mapping['medium_name'] ?? '未設定' }}</span>
                                                @elseif ($mapping['attribution_mode'] === 'google_ads_auto_tagging')
                                                    <span class="secondary-text">{{ $mapping['source_label'] }} 自動標記活動 ID：<code>{{ $mapping['campaign_id'] ?? '—' }}</code></span>
                                                @else
                                                    <span class="secondary-text">{{ $mapping['source_label'] }} 活動 ID：<code>{{ $mapping['campaign_id'] ?? '—' }}</code></span>
                                                @endif
                                            @endforeach
                                        </th>
                                        <td>{{ implode('、', $mappingAuditRow['target_tags']) }}</td>
                                        <td>
                                            @foreach ($mappingAuditRow['mappings'] as $mapping)
                                                <span class="secondary-text">{{ $mapping['source_label'] }}：{{ $mapping['preferred_tag'] }} · {{ $mapping['freshness_label'] }}</span>
                                            @endforeach
                                        </td>
                                        <td class="numeric">
                                            {{ number_format(count($mappingAuditRow['target_tags'])) }} 個標籤
                                            <span class="secondary-text">已合併 {{ number_format($mappingAuditRow['merged_count']) }} 筆</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
            <section class="spaced-top" aria-labelledby="campaign-budget-pacing-overview-title">
                <h3 id="campaign-budget-pacing-overview-title" class="panel-title">跨活動預算節奏</h3>
                <p class="campaign-report-secondary">
                    依幣別彙總 {{ number_format($budgetCoverage['configured_campaigns']) }} 個去重活動；花費可比 {{ number_format($budgetCoverage['spend_campaigns']) }} 個（新鮮 {{ number_format($budgetCoverage['fresh_spend_campaigns']) }}、過期 {{ number_format($budgetCoverage['stale_spend_campaigns']) }}），排除重複廣告活動 {{ number_format($budgetCoverage['duplicate_mappings_excluded']) }} 筆。預估值僅含進行中且花費幣別相符的活動。
                </p>
                @if ($campaignPortfolioMarketingSummary['budget_currency_groups'] === [])
                    <p class="campaign-report-secondary">尚未設定可彙總的活動預算與幣別。</p>
                @else
                    <div class="data-table-wrap" role="region" aria-labelledby="campaign-budget-pacing-overview-title" tabindex="0">
                        <table class="data-table">
                            <caption class="sr-only">各幣別的活動預算、實際花費、對比時間進度的差額與預估超支</caption>
                            <thead>
                                <tr>
                                    <th scope="col">幣別</th>
                                    <th scope="col">活動預算</th>
                                    <th scope="col">目前花費</th>
                                    <th scope="col">較時間進度</th>
                                    <th scope="col">預估總花費</th>
                                    <th scope="col">預估超支／餘額</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campaignPortfolioMarketingSummary['budget_currency_groups'] as $budgetGroup)
                                    <tr>
                                        <th scope="row">{{ $budgetGroup['currency_code'] }}</th>
                                        <td class="numeric">
                                            {{ number_format($budgetGroup['budget_amount'], 2) }}
                                            <span class="secondary-text">{{ number_format($budgetGroup['budget_campaign_count']) }} 個活動</span>
                                        </td>
                                        <td class="numeric">
                                            @if ($budgetGroup['current_spend_campaign_count'] > 0)
                                                {{ number_format($budgetGroup['current_spend_amount'], 2) }}
                                                <span class="secondary-text">花費相符 {{ number_format($budgetGroup['current_spend_campaign_count']) }} 個活動</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="numeric">
                                            @if ($budgetGroup['pacing_campaign_count'] > 0)
                                                {{ $budgetGroup['pacing_difference_amount'] > 0 ? '+' : '' }}{{ number_format($budgetGroup['pacing_difference_amount'], 2) }}
                                                <span class="secondary-text">實際花費減時間進度預算（{{ number_format($budgetGroup['pacing_campaign_count']) }} 個活動）</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="numeric">
                                            @if ($budgetGroup['projected_campaign_count'] > 0)
                                                {{ number_format($budgetGroup['projected_spend_amount'], 2) }}
                                                <span class="secondary-text">進行中 {{ number_format($budgetGroup['projected_campaign_count']) }} 個活動</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="numeric">
                                            @if ($budgetGroup['projected_over_budget_campaign_count'] > 0)
                                                <span>超支 {{ number_format($budgetGroup['projected_over_budget_amount'], 2) }}</span>
                                                <span class="secondary-text">{{ number_format($budgetGroup['projected_over_budget_campaign_count']) }} 個活動</span>
                                            @endif
                                            @if ($budgetGroup['projected_under_budget_campaign_count'] > 0)
                                                <span>低於預算 {{ number_format($budgetGroup['projected_under_budget_amount'], 2) }}</span>
                                                <span class="secondary-text">{{ number_format($budgetGroup['projected_under_budget_campaign_count']) }} 個活動</span>
                                            @endif
                                            @if ($budgetGroup['projected_campaign_count'] === 0)
                                                —
                                            @elseif ($budgetGroup['projected_over_budget_campaign_count'] === 0 && $budgetGroup['projected_under_budget_campaign_count'] === 0)
                                                符合預算
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <h4 id="campaign-budget-risk-list-title" class="panel-title spaced-top">逐活動預算預測</h4>
                <p class="campaign-report-secondary">
                    僅列進行中、預算與花費幣別相同且來源可用的活動；廣告資料需在 24 小時內更新，人工花費會標示。相同廣告活動只列一次，預估超支比例較高者優先。
                </p>
                @if ($campaignPortfolioMarketingSummary['budget_risk_rows'] === [])
                    <p class="campaign-report-secondary">目前沒有可比較的活動預算預測。</p>
                @else
                    <div class="data-table-wrap" role="region" aria-labelledby="campaign-budget-risk-list-title" tabindex="0">
                        <table class="data-table">
                            <caption class="sr-only">進行中活動目前花費、預估總花費與預估超支風險，按相對預算超支比例由高到低排序</caption>
                            <thead>
                                <tr>
                                    <th scope="col">活動</th>
                                    <th scope="col">期間</th>
                                    <th scope="col">花費來源／資料新鮮度</th>
                                    <th scope="col" class="numeric">目前花費</th>
                                    <th scope="col" class="numeric">預估總花費</th>
                                    <th scope="col" class="numeric">預估超支／餘額</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campaignPortfolioMarketingSummary['budget_risk_rows'] as $budgetRiskRow)
                                    <tr>
                                        <th scope="row">{{ $budgetRiskRow['tag'] }}</th>
                                        <td>{{ $budgetRiskRow['starts_on'] }} 至 {{ $budgetRiskRow['ends_on'] }}</td>
                                        <td>
                                            {{ $budgetRiskRow['spend_source_label'] }}
                                            <span class="secondary-text">資料 {{ $budgetRiskRow['freshness_label'] }}</span>
                                        </td>
                                        <td class="numeric">{{ $budgetRiskRow['currency_code'] }} {{ number_format($budgetRiskRow['spend_amount'], 2) }}</td>
                                        <td class="numeric">{{ $budgetRiskRow['currency_code'] }} {{ number_format($budgetRiskRow['projected_spend_amount'], 2) }}</td>
                                        <td class="numeric">
                                            <strong>{{ $budgetRiskRow['status_label'] }}</strong>
                                            @if ($budgetRiskRow['projected_overrun_amount'] > 0)
                                                <span class="secondary-text">{{ $budgetRiskRow['currency_code'] }} {{ number_format($budgetRiskRow['projected_overrun_amount'], 2) }} · +{{ number_format($budgetRiskRow['projected_overrun_percent'], 1) }}%</span>
                                            @elseif ($budgetRiskRow['projected_overrun_amount'] < 0)
                                                <span class="secondary-text">{{ $budgetRiskRow['currency_code'] }} {{ number_format(abs($budgetRiskRow['projected_overrun_amount']), 2) }} · {{ number_format($budgetRiskRow['projected_overrun_percent'], 1) }}%</span>
                                            @else
                                                <span class="secondary-text">{{ $budgetRiskRow['currency_code'] }} 0.00 · 0.0%</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
            <section class="spaced-top" aria-labelledby="campaign-public-interaction-cost-title">
                <h3 id="campaign-public-interaction-cost-title" class="panel-title">社群貼文互動成本</h3>
                <p class="campaign-report-secondary">依外部廣告活動去重，以配對花費除以活動貼文的公開互動數；這是投放與貼文互動的效率參考，不代表廣告版位的付費互動成本。活動排名按廣告來源與幣別分組，只比較所有追蹤貼文都有讀值的活動。</p>
                @if ($campaignPortfolioMarketingSummary['interaction_cost_groups'] === [])
                    <p class="campaign-report-secondary">尚無可比較的投放花費。</p>
                @else
                    <div class="data-table-wrap" role="region" aria-labelledby="campaign-public-interaction-cost-title" tabindex="0">
                        <table class="data-table">
                            <caption class="sr-only">各廣告平台與幣別中，已去重活動的配對花費、貼文公開互動數、單次公開互動成本與讀值覆蓋</caption>
                            <thead>
                                <tr>
                                    <th scope="col">來源</th>
                                    <th scope="col">幣別</th>
                                    <th scope="col" class="numeric">配對花費</th>
                                    <th scope="col" class="numeric">公開互動</th>
                                    <th scope="col" class="numeric">每次互動成本</th>
                                    <th scope="col" class="numeric">貼文讀值覆蓋</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campaignPortfolioMarketingSummary['interaction_cost_groups'] as $interactionCostGroup)
                                    <tr>
                                        <th scope="row">{{ $interactionCostGroup['source_label'] }}</th>
                                        <td>{{ $interactionCostGroup['currency_code'] }}</td>
                                        <td class="numeric">
                                            @if ($interactionCostGroup['comparable_campaign_count'] > 0)
                                                {{ number_format($interactionCostGroup['comparable_spend_amount'], 2) }}
                                                <span class="secondary-text">{{ number_format($interactionCostGroup['comparable_campaign_count']) }} 個可比活動</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="numeric">
                                            @if ($interactionCostGroup['comparable_campaign_count'] > 0)
                                                {{ number_format($interactionCostGroup['public_interactions']) }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="numeric">
                                            @if ($interactionCostGroup['cost_per_public_interaction'] !== null)
                                                {{ number_format($interactionCostGroup['cost_per_public_interaction'], 4) }} {{ $interactionCostGroup['currency_code'] }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="numeric">
                                            @if ($interactionCostGroup['coverage_percent'] !== null)
                                                {{ number_format($interactionCostGroup['available_post_count']) }}/{{ number_format($interactionCostGroup['tracked_post_count']) }} 篇
                                                <span class="secondary-text">{{ number_format($interactionCostGroup['coverage_percent'], 1) }}%</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
            <p class="campaign-report-secondary spaced-top">
                資料涵蓋（依外部活動去重）：
                @foreach ($portfolioSourceLabels as $source => $sourceLabel)
                    @php
                        $sourceCoverage = $portfolioCoverage[$source];
                    @endphp
                    {{ $sourceLabel }} {{ number_format($sourceCoverage['available_campaigns']) }}/{{ number_format($sourceCoverage['configured_campaigns']) }} 個活動有資料（新鮮 {{ number_format($sourceCoverage['fresh_campaigns']) }}、過期 {{ number_format($sourceCoverage['stale_campaigns']) }}）{{ $sourceCoverage['duplicate_mappings_excluded'] > 0 ? '，排除重複綁定 '.number_format($sourceCoverage['duplicate_mappings_excluded']).' 筆' : '' }}
                    @if (! $loop->last)
                        ；
                    @endif
                @endforeach
                。ROAS 只納入同幣別且 GA4 營收與對應廣告花費可配對的活動，依合計營收除以合計花費計算。
            </p>
            <div class="data-table-wrap" role="region" aria-labelledby="campaign-marketing-overview-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">各活動的 GA4 成效與目標進度、廣告花費、ROAS 與預算使用率</caption>
                    <thead>
                        <tr>
                            <th scope="col">活動</th>
                            <th scope="col">GA4</th>
                            <th scope="col" class="numeric">GA4 目標進度</th>
                            <th scope="col">廣告花費</th>
                            <th scope="col">ROAS</th>
                            <th scope="col">同幣別 ROAS 排名</th>
                            <th scope="col">公開互動效率</th>
                            <th scope="col">預算使用率</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($socialEngagementTargets as $socialEngagementTarget)
                            @php
                                $marketing = $socialEngagementTarget['marketing'];
                                $efficiency = $marketing['efficiency'];
                                $paidMediaRoasGoal = $efficiency['paid_media_roas_goal'] ?? null;
                                $ga4BusinessGoal = $marketing['ga4_business_goal'];
                                $ga4BusinessGoalPacing = $ga4BusinessGoal['time_pacing'];
                                $ga4BusinessGoalBadgeClass = match ($ga4BusinessGoalPacing['status']) {
                                    'ahead', 'on_track' => 'healthy',
                                    'behind' => 'warning',
                                    'not_started', 'completed' => 'unknown',
                                    default => 'warning',
                                };
                                $roasUnavailableLabel = match ($efficiency['roas_unavailable_reason']) {
                                    'ad_platform_currency_mismatch' => '廣告幣別不同',
                                    'currency_mismatch' => '營收與花費幣別不同',
                                    'missing_spend' => '尚無花費',
                                    'zero_spend' => '花費為 0',
                                    'missing_ga4' => '尚無 GA4 報表',
                                    'missing_spend_currency' => '缺少花費幣別',
                                    'missing_ga4_currency' => '缺少營收幣別',
                                    default => '資料不足',
                                };
                                $budgetUnavailableLabel = match ($efficiency['budget_status']) {
                                    'platform_currency_mismatch', 'currency_mismatch' => '幣別不同',
                                    'missing_spend' => '尚無花費',
                                    'not_started' => '尚未開始',
                                    'missing_campaign_period' => '未設定活動期間',
                                    'zero_budget' => '預算為 0',
                                    default => '資料不足',
                                };
                            @endphp
                            <tr>
                                <th scope="row">
                                    {{ $socialEngagementTarget['tag'] }}
                                    <span class="secondary-text">{{ $socialEngagementTarget['platform_label'] }}</span>
                                </th>
                                <td class="numeric">
                                    @if ($marketing['google_analytics']['sessions'] !== null)
                                        {{ number_format($marketing['google_analytics']['sessions']) }} 工作階段
                                        <span class="secondary-text">營收 {{ $marketing['google_analytics']['currency_code'] ?? '—' }} {{ number_format((float) $marketing['google_analytics']['revenue'], 2) }}</span>
                                    @else
                                        <span class="secondary-text">尚無工作階段資料</span>
                                    @endif
                                    <span class="secondary-text">GA4：{{ $marketing['google_analytics']['status_label'] }}</span>
                                </td>
                                <td class="numeric">
                                    @if ($ga4BusinessGoal['status'] === 'not_configured')
                                        <span class="secondary-text">未設定</span>
                                    @else
                                        <span>
                                            {{ $ga4BusinessGoal['metric_label'] }}目標
                                            @if ($ga4BusinessGoal['metric'] === 'total_revenue')
                                                {{ $ga4BusinessGoal['goal_currency_code'] ?? '—' }} {{ number_format((float) $ga4BusinessGoal['target_value'], 2) }}
                                            @else
                                                {{ number_format((float) $ga4BusinessGoal['target_value'], 2) }}
                                            @endif
                                        </span>
                                        @if ($ga4BusinessGoal['completion_percent'] !== null)
                                            <span class="secondary-text">
                                                實際 {{ number_format((float) $ga4BusinessGoal['actual_value'], 2) }}{{ $ga4BusinessGoal['metric'] === 'total_revenue' ? ' '.$ga4BusinessGoal['report_currency_code'] : '' }}
                                                · 達成 {{ number_format($ga4BusinessGoal['completion_percent'], 1) }}%
                                            </span>
                                            <span class="badge badge-{{ $ga4BusinessGoalBadgeClass }}">{{ $ga4BusinessGoalPacing['status_label'] }}</span>
                                        @else
                                            <span class="badge badge-warning">{{ $ga4BusinessGoal['status_label'] }}</span>
                                            @if (in_array($ga4BusinessGoalPacing['status'], ['not_started', 'completed'], true))
                                                <span class="secondary-text">{{ $ga4BusinessGoalPacing['status_label'] }}</span>
                                            @endif
                                        @endif
                                    @endif
                                </td>
                                <td class="numeric">
                                    @if ($efficiency['ad_spend_amount'] !== null && $efficiency['ad_spend_currency'] !== null)
                                        {{ number_format((float) $efficiency['ad_spend_amount'], 2) }} {{ $efficiency['ad_spend_currency'] }}
                                        <span class="secondary-text">{{ $efficiency['ad_spend_source_label'] }}</span>
                                    @elseif ($efficiency['ad_spend_source'] === 'currency_mismatch')
                                        幣別不同，未合併
                                    @else
                                        <span class="secondary-text">尚無花費資料</span>
                                    @endif
                                    @foreach (['Meta Ads' => $marketing['meta_marketing'], 'Google Ads' => $marketing['google_ads']] as $sourceLabel => $sourceSummary)
                                        <span class="secondary-text">{{ $sourceLabel }}：{{ $sourceSummary['status_label'] }}</span>
                                    @endforeach
                                </td>
                                <td class="numeric">
                                    @if ($efficiency['roas'] !== null)
                                        {{ number_format($efficiency['roas'], 2) }}x
                                        <span class="secondary-text">分母：{{ $efficiency['roas_spend_source_label'] ?? '—' }} {{ number_format((float) $efficiency['roas_spend_amount'], 2) }} {{ $efficiency['roas_spend_currency'] ?? '' }}</span>
                                    @else
                                        {{ $roasUnavailableLabel }}
                                    @endif
                                    <span class="secondary-text">
                                        每次參與工作階段成本：{{ $efficiency['cost_per_engaged_session'] === null ? $efficiency['cost_per_engaged_session_status_label'] : number_format($efficiency['cost_per_engaged_session'], 4).' '.$efficiency['roas_spend_currency'] }}
                                    </span>
                                    @if ($paidMediaRoasGoal !== null && $paidMediaRoasGoal['status'] !== 'not_configured')
                                        <span class="secondary-text">{{ $paidMediaRoasGoal['source_label'] }}目標 {{ number_format($paidMediaRoasGoal['target_roas'], 4) }}x · 實際 {{ $paidMediaRoasGoal['actual_roas'] === null ? '—' : number_format($paidMediaRoasGoal['actual_roas'], 4).'x' }}{{ $paidMediaRoasGoal['attainment_percent'] === null ? ' · '.$paidMediaRoasGoal['status_label'] : ' · 達成率 '.number_format($paidMediaRoasGoal['attainment_percent'], 1).'%' }}</span>
                                    @endif
                                </td>
                                <td class="numeric">
                                    @if (isset($efficiency['portfolio_roas_rank']))
                                        #{{ number_format($efficiency['portfolio_roas_rank']['rank']) }}
                                        <span class="secondary-text">{{ $efficiency['portfolio_roas_rank']['currency_code'] }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="numeric">
                                    @if ($socialEngagementTarget['current_interactions'] !== null)
                                        {{ number_format($socialEngagementTarget['current_interactions']) }} 次互動
                                        <span class="secondary-text">
                                            @if ($efficiency['cost_per_public_interaction'] !== null)
                                                {{ number_format($efficiency['cost_per_public_interaction'], 4) }} {{ $efficiency['ad_spend_currency'] }}／次
                                            @else
                                                花費或互動數不足，未計成本
                                            @endif
                                        </span>
                                        @php
                                            $interactionCostRank = $efficiency['portfolio_interaction_cost_ranks'][$efficiency['ad_spend_source'] ?? ''] ?? null;
                                        @endphp
                                        @if ($interactionCostRank !== null)
                                            <span class="secondary-text">{{ $interactionCostRank['source_label'] }}／{{ $interactionCostRank['currency_code'] }} 成本排名 #{{ number_format($interactionCostRank['rank']) }}{{ $interactionCostRank['status'] === 'stale' ? '（資料過期）' : '' }}</span>
                                        @endif
                                        <span class="secondary-text">讀值 {{ number_format($socialEngagementTarget['available_post_count']) }}/{{ number_format($socialEngagementTarget['tracked_post_count']) }} 篇</span>
                                    @elseif ($socialEngagementTarget['tracked_post_count'] > 0)
                                        <span class="secondary-text">尚無完整互動讀值（{{ number_format($socialEngagementTarget['available_post_count']) }}/{{ number_format($socialEngagementTarget['tracked_post_count']) }} 篇）</span>
                                    @else
                                        <span class="secondary-text">尚無活動貼文</span>
                                    @endif
                                </td>
                                <td class="numeric">
                                    @if ($efficiency['campaign_budget_amount'] === null)
                                        未設定
                                    @elseif ($efficiency['budget_utilization_percent'] !== null)
                                        {{ number_format($efficiency['budget_utilization_percent'], 1) }}%
                                        <span class="secondary-text">{{ $efficiency['campaign_budget_currency'] }} {{ number_format((float) $efficiency['campaign_budget_amount'], 2) }}</span>
                                    @else
                                        {{ $budgetUnavailableLabel }}
                                    @endif
                                </td>
                                <td>
                                    <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.report', $socialEngagementTarget['id']) }}" aria-label="查看 {{ $socialEngagementTarget['tag'] }} 的行銷成效報告">報告</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="publishing-day-engagement-title">
        <div class="panel-header">
            <div>
                <h2 id="publishing-day-engagement-title" class="panel-title">發文週期成效</h2>
            </div>
        </div>
        @if ($socialEngagementPublishingDaySummaries === [])
            <div class="empty-state">
                <h3 class="empty-title">尚無發文週期資料</h3>
                <p class="empty-copy">貼文需同時有公開發布時間與符合平台口徑的完整互動數，才會納入星期與時段比較。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="publishing-day-engagement-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">依公開發布時間的星期分組、按平台口徑計算的每篇平均互動與貼文數</caption>
                    <thead>
                        <tr>
                            <th scope="col">平台與口徑</th>
                            @foreach (['週一', '週二', '週三', '週四', '週五', '週六', '週日'] as $weekdayLabel)
                                <th scope="col">{{ $weekdayLabel }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($socialEngagementPublishingDaySummaries as $publishingDaySummary)
                            <tr>
                                <th scope="row">
                                    {{ $publishingDaySummary['platform'] }}
                                    <span class="secondary-text">（{{ $publishingDaySummary['interaction_scope_label'] }}）</span>
                                </th>
                                @foreach ($publishingDaySummary['weekdays'] as $weekdaySummary)
                                    <td class="numeric">
                                        @if ($weekdaySummary['post_count'] > 0)
                                            {{ number_format($weekdaySummary['average_interactions_per_post'], 1) }}
                                            <span class="secondary-text">{{ number_format($weekdaySummary['post_count']) }} 篇</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <h3 id="publishing-time-engagement-title" class="panel-title spaced-top">發文時段成效</h3>
            <div class="data-table-wrap" role="region" aria-labelledby="publishing-time-engagement-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">依台灣時間發布時段，按平台口徑計算的每篇平均互動與貼文數</caption>
                    <thead>
                        <tr>
                            <th scope="col">平台與口徑</th>
                            @foreach ($socialEngagementPublishingTimeSummaries[0]['time_slots'] as $timeSlot)
                                <th scope="col">{{ $timeSlot['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($socialEngagementPublishingTimeSummaries as $publishingTimeSummary)
                            <tr>
                                <th scope="row">
                                    {{ $publishingTimeSummary['platform'] }}
                                    <span class="secondary-text">（{{ $publishingTimeSummary['interaction_scope_label'] }}）</span>
                                </th>
                                @foreach ($publishingTimeSummary['time_slots'] as $timeSlot)
                                    <td class="numeric">
                                        @if ($timeSlot['post_count'] > 0)
                                            {{ number_format($timeSlot['average_interactions_per_post'], 1) }}
                                            <span class="secondary-text">{{ number_format($timeSlot['post_count']) }} 篇</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="social-post-maturity-title">
        <div class="panel-header">
            <div>
                <h2 id="social-post-maturity-title" class="panel-title">貼文年齡成效</h2>
            </div>
            <a class="strong-link" href="{{ route('social-post-maturity.export', [
                'client_id' => $selectedClientId,
                'engagement_platform' => $socialEngagementSelectedPlatform,
                'engagement_tag' => $socialEngagementSelectedTag,
            ]) }}">匯出同齡比較 CSV <span aria-hidden="true">↓</span></a>
        </div>
        @if ($socialPostMaturityRows === [])
            <div class="empty-state">
                <h3 class="empty-title">尚無貼文年齡成效資料</h3>
                <p class="empty-copy">貼文需有公開發布時間，且目標年齡前後 6 小時內讀到平台所需的完整互動數。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="social-post-maturity-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">各平台貼文在發文後 24 小時、7 天與 30 天的平均及中位數互動、平均觀看數和觀看互動率</caption>
                    <thead>
                        <tr>
                            <th scope="col">貼文年齡</th>
                            <th scope="col">平台與口徑</th>
                            <th scope="col">平均互動／篇</th>
                            <th scope="col">中位數互動／篇</th>
                            <th scope="col">平均觀看／篇</th>
                            <th scope="col">觀看互動率</th>
                            <th scope="col">互動樣本</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($socialPostMaturityRows as $maturityRow)
                            <tr>
                                <th scope="row">{{ $maturityRow['maturity_label'] }}</th>
                                <td>
                                    {{ $maturityRow['platform'] }}
                                    <span class="secondary-text">（{{ $maturityRow['interaction_scope_label'] }}）</span>
                                </td>
                                <td class="numeric">{{ number_format($maturityRow['average_interactions_per_post'], 1) }}</td>
                                <td class="numeric">{{ number_format($maturityRow['median_interactions_per_post'], 1) }}</td>
                                <td class="numeric">
                                    @if ($maturityRow['average_views_per_post'] !== null)
                                        {{ number_format($maturityRow['average_views_per_post'], 1) }}
                                        <span class="secondary-text">{{ number_format($maturityRow['view_sample_count']) }} 篇</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="numeric">
                                    {{ $maturityRow['engagement_rate_percent'] === null ? '—' : number_format($maturityRow['engagement_rate_percent'], 2).'%' }}
                                </td>
                                <td class="numeric">{{ number_format($maturityRow['post_count']) }} 篇</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="cross-platform-engagement-title">
        <div class="panel-header">
            <div>
                <h2 id="cross-platform-engagement-title" class="panel-title">活動標籤跨平台比較</h2>
            </div>
        </div>
        @if ($socialEngagementComparisonSummaries === [])
            <div class="empty-state">
                <h3 class="empty-title">尚無跨平台標籤資料</h3>
                <p class="empty-copy">新增已標記的社群貼文並累積公開互動數後，這裡會並列比較各平台。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="cross-platform-engagement-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">依活動標籤比較 Facebook、Instagram、Threads 與 Dcard 公開互動數</caption>
                    <thead>
                        <tr>
                            <th scope="col">活動標籤</th>
                            @foreach ($socialEngagementComparisonPlatformOptions as $platformLabel)
                                <th scope="col">{{ $platformLabel }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($socialEngagementComparisonSummaries as $comparisonSummary)
                            <tr>
                                <th scope="row">
                                    <a href="{{ route('dashboard', ['client_id' => $selectedClientId, 'engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $comparisonSummary['filter_label'] === null ? 'unlabeled' : 'tag:'.$comparisonSummary['filter_label'], 'engagement_period' => $socialEngagementChangePeriodDays, 'engagement_tag_sort' => $socialEngagementTagSort, 'engagement_post_sort' => $socialEngagementTopPostSort, 'engagement_growth_sort' => $socialEngagementGrowthPostSort]) }}">{{ $comparisonSummary['tag'] }}</a>
                                </th>
                                @foreach ($socialEngagementComparisonPlatformOptions as $platform => $platformLabel)
                                    @php
                                        $platformSummary = $comparisonSummary['platforms'][$platform] ?? null;
                                    @endphp
                                    <td>
                                        @if ($platformSummary !== null)
                                            <strong>{{ number_format($platformSummary['monitor_count']) }} 篇</strong><br>
                                            <span class="secondary-text">本次讀取 {{ number_format($platformSummary['capture_counts']['current']) }} 篇；前次資料 {{ number_format($platformSummary['capture_counts']['previous']) }} 篇</span><br>
                                            <span class="secondary-text">觀看 {{ $platformSummary['metrics']['views']['available'] > 0 ? number_format($platformSummary['metrics']['views']['total']) : '—' }}（{{ number_format($platformSummary['metrics']['views']['available']) }} 篇）</span><br>
                                            <span class="secondary-text">互動合計 {{ $platformSummary['interaction_count'] > 0 ? number_format($platformSummary['interaction_total']) : '—' }}（{{ $platformSummary['interaction_scope_label'] }}，{{ number_format($platformSummary['interaction_count']) }} 篇）</span><br>
                                            <span class="secondary-text">平均互動／篇 {{ $platformSummary['average_interactions_per_post'] !== null ? number_format($platformSummary['average_interactions_per_post'], 1) : '—' }}（{{ number_format($platformSummary['interaction_count']) }} 篇）</span><br>
                                            <span class="secondary-text">觀看互動率
                                                @if ($platformSummary['engagement_rate']['views'] > 0)
                                                    {{ number_format($platformSummary['engagement_rate']['interactions'] / $platformSummary['engagement_rate']['views'] * 100, 2) }}%
                                                @else
                                                    —
                                                @endif
                                                （{{ number_format($platformSummary['engagement_rate']['available']) }} 篇）
                                            </span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="tagged-engagement-change-title">
        <div class="panel-header">
            <div>
                <h2 id="tagged-engagement-change-title" class="panel-title">近 {{ $socialEngagementChangePeriodDays }} 天互動變化</h2>
                <p class="secondary-text">下表只計有兩次讀值的貼文；前期比較限同時有兩期讀值的貼文。</p>
                @if (! $socialEngagementChangeComparisonAvailable)
                    <p class="secondary-text">前期比較需保留 {{ $socialEngagementChangePeriodDays * 2 }} 天；目前設定保留 {{ $socialEngagementRetentionDays }} 天。</p>
                @endif
            </div>
            <nav class="heading-actions" aria-label="互動變化期間">
                @foreach ($socialEngagementChangePeriodOptions as $periodDays => $periodLabel)
                    <a
                        class="button button-small {{ $periodDays === $socialEngagementChangePeriodDays ? 'button-primary' : 'button-secondary' }}"
                        href="{{ route('dashboard', ['client_id' => $selectedClientId, 'engagement_period' => $periodDays, 'engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag, 'engagement_tag_sort' => $socialEngagementTagSort, 'engagement_post_sort' => $socialEngagementTopPostSort, 'engagement_growth_sort' => $socialEngagementGrowthPostSort]) }}"
                        @if ($periodDays === $socialEngagementChangePeriodDays) aria-current="page" @endif
                    >{{ $periodLabel }}</a>
                @endforeach
            </nav>
        </div>
        @if ($socialEngagementChangeSummaries === [])
            <div class="empty-state">
                <h3 class="empty-title">尚無可比較的互動歷史</h3>
                <p class="empty-copy">貼文累積至少兩次可讀取數值後，這裡會彙總所選期間的變化。</p>
            </div>
        @else
            <div class="metric-trend-chart">
                @if ($socialEngagementTrendAvailable)
                    <figure class="metric-trend-figure">
                        <svg class="metric-trend-svg" viewBox="0 0 600 196" role="img" aria-labelledby="social-engagement-trend-chart-title social-engagement-trend-chart-description">
                            <title id="social-engagement-trend-chart-title">近 {{ $socialEngagementChangePeriodDays }} 天依觀測日彙總的互動淨變化</title>
                            <desc id="social-engagement-trend-chart-description">各指標只在前後兩次均可讀取時比較；正值向上，負值向下，資料不足時略過該指標。</desc>
                            <line class="metric-trend-grid-line" x1="52" x2="576" y1="31" y2="31" />
                            <line class="metric-trend-grid-line" x1="52" x2="576" y1="95" y2="95" />
                            <line class="metric-trend-grid-line" x1="52" x2="576" y1="159" y2="159" />
                            <text class="metric-trend-axis-label" x="46" y="35" text-anchor="end">+{{ number_format($socialEngagementTrendScale) }}</text>
                            <text class="metric-trend-axis-label" x="46" y="99" text-anchor="end">0</text>
                            <text class="metric-trend-axis-label" x="46" y="163" text-anchor="end">−{{ number_format($socialEngagementTrendScale) }}</text>
                            @foreach ($socialEngagementTrend as $trendPoint)
                                @if ($trendPoint['change'] !== null)
                                    <rect
                                        class="metric-trend-bar {{ $trendPoint['change'] < 0 ? 'metric-trend-bar--negative' : 'metric-trend-bar--positive' }}"
                                        x="{{ number_format($trendPoint['x'] - $trendPoint['width'] / 2, 2, '.', '') }}"
                                        y="{{ number_format($trendPoint['y'], 2, '.', '') }}"
                                        width="{{ number_format($trendPoint['width'], 2, '.', '') }}"
                                        height="{{ number_format($trendPoint['height'], 2, '.', '') }}"
                                        rx="2"
                                    >
                                        <title>{{ $trendPoint['label'] }}：{{ $trendPoint['change'] > 0 ? '+' : '' }}{{ number_format($trendPoint['change']) }}（{{ number_format($trendPoint['posts']) }} 篇有可比較數據）</title>
                                    </rect>
                                @endif
                                @if ($trendPoint['axis_label'])
                                    <text class="metric-trend-axis-label" x="{{ number_format($trendPoint['x'], 2, '.', '') }}" y="188" text-anchor="middle">{{ $trendPoint['label'] }}</text>
                                @endif
                            @endforeach
                        </svg>
                        <figcaption class="metric-trend-caption">互動淨變化為可比較的讚、留言與分享差額；每日納入篇數可能不同，缺資料日不代表 0，間隔期間的變化列在下一個觀測日。</figcaption>
                    </figure>
                    <details class="metric-trend-data">
                        <summary>查看每日數值</summary>
                        <div class="data-table-wrap" role="region" aria-label="每日社群互動淨變化數值" tabindex="0">
                            <table class="data-table">
                                <caption class="sr-only">依所選平台與標籤篩選的每日互動淨變化</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">日期</th>
                                        <th scope="col">互動淨變化</th>
                                        <th scope="col">貼文數</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($socialEngagementTrend as $trendPoint)
                                        <tr>
                                            <th scope="row">{{ $trendPoint['date'] }}</th>
                                            <td class="numeric">{{ $trendPoint['change'] === null ? '—' : ($trendPoint['change'] > 0 ? '+' : '').number_format($trendPoint['change']) }}</td>
                                            <td class="numeric">{{ number_format($trendPoint['posts']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @else
                    <div class="empty-state">
                        <h3 class="empty-title">尚無每日互動增量</h3>
                        <p class="empty-copy">貼文需至少兩次讀到同一項互動數，才能計算淨變化。</p>
                    </div>
                @endif
            </div>
            <div class="spaced-top" aria-labelledby="social-engagement-growth-title">
                <h3 id="social-engagement-growth-title" class="panel-title">單篇互動變化排行</h3>
                @if ($socialEngagementGrowthPosts === [])
                    <div class="empty-state">
                        <h4 class="empty-title">尚無單篇互動變化</h4>
                        <p class="empty-copy">貼文需在所選期間內至少兩次讀到同項互動數，才會列入排行。</p>
                    </div>
                @else
                    <div class="heading-actions spaced-top">
                        <a class="strong-link" href="{{ route('monitors.social-engagement.export.growth', ['client_id' => $selectedClientId, 'engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag, 'engagement_period' => $socialEngagementChangePeriodDays, 'engagement_growth_sort' => $socialEngagementGrowthPostSort]) }}">匯出完整排行 CSV <span aria-hidden="true">↓</span></a>
                    </div>
                    <nav class="heading-actions spaced-top" aria-label="單篇互動變化排行排序">
                        @foreach ($socialEngagementGrowthPostSortOptions as $sort => $sortLabel)
                            <a
                                class="button button-small {{ $socialEngagementGrowthPostSort === $sort ? 'button-primary' : 'button-secondary' }}"
                                href="{{ route('dashboard', ['client_id' => $selectedClientId, 'engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag, 'engagement_period' => $socialEngagementChangePeriodDays, 'engagement_tag_sort' => $socialEngagementTagSort, 'engagement_post_sort' => $socialEngagementTopPostSort, 'engagement_growth_sort' => $sort]) }}"
                                @if ($socialEngagementGrowthPostSort === $sort) aria-current="page" @endif
                            >{{ $sortLabel }}</a>
                        @endforeach
                    </nav>
                    <div class="data-table-wrap" role="region" aria-labelledby="social-engagement-growth-title" tabindex="0">
                        <table class="data-table">
                            <caption class="sr-only">依近 {{ $socialEngagementChangePeriodDays }} 天{{ $socialEngagementGrowthPostSort === 'growth_rate' ? '互動成長率' : '互動淨變化' }}排序的前五篇貼文</caption>
                            <thead>
                                <tr>
                                    <th scope="col">貼文</th>
                                    <th scope="col">平台</th>
                                    <th scope="col">互動淨變化</th>
                                    <th scope="col">互動成長率</th>
                                    @if ($socialEngagementChangeComparisonAvailable)
                                        <th scope="col">較前期變化差</th>
                                    @endif
                                    <th scope="col">指標變化</th>
                                    <th scope="col">最後觀測</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($socialEngagementGrowthPosts as $growthPost)
                                    <tr>
                                        <th scope="row"><a href="{{ route('monitors.show', $growthPost['id']) }}">{{ $growthPost['name'] }}</a></th>
                                        <td>{{ $growthPost['platform'] }}</td>
                                        <td class="numeric">{{ $growthPost['interaction_change'] > 0 ? '+' : '' }}{{ number_format($growthPost['interaction_change']) }}</td>
                                        <td class="numeric">
                                            @if ($growthPost['interaction_growth_rate'] === null)
                                                — <span class="secondary-text">(期初 {{ number_format($growthPost['starting_interactions']) }}，無法計算)</span>
                                            @else
                                                {{ $growthPost['interaction_growth_rate'] > 0 ? '+' : '' }}{{ number_format($growthPost['interaction_growth_rate'], 1) }}%
                                                <span class="secondary-text">(期初 {{ number_format($growthPost['starting_interactions']) }})</span>
                                            @endif
                                        </td>
                                        @if ($socialEngagementChangeComparisonAvailable)
                                            <td class="numeric">
                                                @if ($growthPost['period_interaction_change_difference'] === null)
                                                    — <span class="secondary-text">(無雙期可比較指標)</span>
                                                @else
                                                    {{ $growthPost['period_interaction_change_difference'] > 0 ? '+' : '' }}{{ number_format($growthPost['period_interaction_change_difference']) }}
                                                    <span class="secondary-text">(本期 {{ $growthPost['comparable_current_interaction_change'] > 0 ? '+' : '' }}{{ number_format($growthPost['comparable_current_interaction_change']) }}／前期 {{ $growthPost['previous_period_interaction_change'] > 0 ? '+' : '' }}{{ number_format($growthPost['previous_period_interaction_change']) }}；{{ $growthPost['period_comparable_metrics'] }}/3 項)</span>
                                                @endif
                                            </td>
                                        @endif
                                        <td>
                                            @foreach (['likes' => '讚', 'comments' => '留言', 'shares' => '分享'] as $metric => $metricLabel)
                                                <span>{{ $metricLabel }} {{ $growthPost['metric_changes'][$metric] === null ? '—' : ($growthPost['metric_changes'][$metric] > 0 ? '+' : '').number_format($growthPost['metric_changes'][$metric]) }}</span>@if (! $loop->last) <span aria-hidden="true">・</span> @endif
                                            @endforeach
                                            <span class="secondary-text">({{ $growthPost['available_metrics'] }}/3 項)</span>
                                        </td>
                                        <td><time class="timestamp" datetime="{{ $growthPost['finished_at']->toIso8601String() }}">{{ $growthPost['finished_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            <div class="data-table-wrap" role="region" aria-labelledby="tagged-engagement-change-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">依標籤彙總的社群貼文近 {{ $socialEngagementChangePeriodDays }} 天互動增量、前期比較與資料覆蓋篇數</caption>
                    <thead>
                        <tr>
                            <th scope="col">標籤</th>
                            @foreach (['views' => '觀看變化', 'likes' => '讚變化', 'comments' => '留言變化', 'shares' => '分享變化'] as $metricLabel)
                                <th scope="col">{{ $metricLabel }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($socialEngagementChangeSummaries as $socialEngagementChangeSummary)
                            <tr>
                                <th scope="row">
                                    @if ($socialEngagementChangeSummary['filter_label'] !== null)
                                        <a href="{{ route('monitors.index', ['label' => $socialEngagementChangeSummary['filter_label']]) }}">{{ $socialEngagementChangeSummary['tag'] }}</a>
                                    @else
                                        {{ $socialEngagementChangeSummary['tag'] }}
                                    @endif
                                </th>
                                @foreach (['views', 'likes', 'comments', 'shares'] as $metric)
                                    @php
                                        $changeSummary = $socialEngagementChangeSummary['metrics'][$metric];
                                    @endphp
                                    <td class="numeric">
                                        {{ $changeSummary['available'] > 0 ? ($changeSummary['change'] > 0 ? '+' : '').number_format($changeSummary['change']) : '—' }}
                                        <span class="secondary-text">({{ number_format($changeSummary['available']) }} 篇)</span>
                                        @if ($socialEngagementChangeComparisonAvailable)
                                            @if ($changeSummary['comparison']['available'] > 0)
                                                <span class="secondary-text">
                                                    同 {{ number_format($changeSummary['comparison']['available']) }} 篇：前期
                                                    {{ $changeSummary['comparison']['previous_change'] > 0 ? '+' : '' }}{{ number_format($changeSummary['comparison']['previous_change']) }} → 本期
                                                    {{ $changeSummary['comparison']['current_change'] > 0 ? '+' : '' }}{{ number_format($changeSummary['comparison']['current_change']) }}（較前期
                                                    {{ $changeSummary['comparison']['difference'] > 0 ? '+' : '' }}{{ number_format($changeSummary['comparison']['difference']) }}）
                                                </span>
                                            @endif
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="social-engagement-data-coverage-title">
        <div class="panel-header">
            <div>
                <h2 id="social-engagement-data-coverage-title" class="panel-title">互動數擷取覆蓋</h2>
            </div>
        </div>
        @if ($socialEngagementDataCoverageRows === [])
            <div class="empty-state">
                <h3 class="empty-title">尚無互動數擷取資料</h3>
                <p class="empty-copy">目前篩選條件下，近 7／30 天沒有完成的社群貼文檢查；至少保留 7 天歷史資料才能計算。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="social-engagement-data-coverage-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">近 7 天與 30 天內，各社群平台公開互動數擷取覆蓋率及未完整擷取原因；原因按檢查次數計算</caption>
                    <thead>
                        <tr>
                            <th scope="col">期間</th>
                            <th scope="col">平台與口徑</th>
                            <th scope="col">HTTP 回應／檢查</th>
                            <th scope="col">完整互動讀取</th>
                            <th scope="col">擷取覆蓋率</th>
                            <th scope="col">有數據貼文／有回應貼文</th>
                            <th scope="col">最近完整讀取</th>
                            <th scope="col">未完整擷取原因（次）</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($socialEngagementDataCoverageRows as $coverageRow)
                            <tr>
                                <th scope="row">{{ $coverageRow['period_label'] }}</th>
                                <td>
                                    {{ $coverageRow['platform'] }}
                                    <span class="secondary-text">（{{ $coverageRow['interaction_scope_label'] }}）</span>
                                </td>
                                <td class="numeric">{{ number_format($coverageRow['http_response_count']) }}／{{ number_format($coverageRow['check_count']) }}</td>
                                <td class="numeric">{{ number_format($coverageRow['complete_metric_read_count']) }}</td>
                                <td class="numeric">{{ $coverageRow['coverage_rate_percent'] === null ? '—' : number_format($coverageRow['coverage_rate_percent'], 1).'%' }}</td>
                                <td class="numeric">{{ number_format($coverageRow['posts_with_metrics_count']) }}／{{ number_format($coverageRow['posts_with_response_count']) }} 篇</td>
                                <td>{{ $coverageRow['latest_complete_capture_at'] ?? '—' }}</td>
                                <td>
                                    @forelse ($coverageRow['failure_reason_counts'] as $failureReason)
                                        <div>{{ $failureReason['label'] }}：{{ number_format($failureReason['count']) }}</div>
                                    @empty
                                        —
                                    @endforelse
                                    @if ($coverageRow['latest_failure_reason'] !== null)
                                        <span class="secondary-text">最近：{{ $coverageRow['latest_failure_reason'] }}（{{ $coverageRow['latest_failure_at'] }}）</span>
                                    @endif
                                </td>
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
            </div>
        </div>
        @if (collect($fleetAvailability['social_platform_statistics'])->sum('monitor_count') === 0)
            <div class="empty-state">
                <h3 class="empty-title">尚無啟用中的社群貼文監測</h3>
                <p class="empty-copy">啟用 Facebook、Instagram、Threads 或 Dcard 貼文監測後，這裡會比較各平台樣本的可用率與資料覆蓋度。</p>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="social-platform-availability-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">Facebook、Instagram、Threads 與 Dcard 受監測貼文的估算可用率與資料覆蓋率</caption>
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

        </div>
    </details>

    <details class="dashboard-disclosure" aria-labelledby="dashboard-operations-summary">
        <summary>
            <span id="dashboard-operations-summary">服務品質與維運</span>
            <span class="dashboard-disclosure-meta">事件清單 {{ number_format($incidents->count()) }} 筆 · {{ number_format($expiringCertificateCount) }} 張憑證 · {{ number_format($expiringDomainCount) }} 個網域</span>
        </summary>
        <div class="dashboard-disclosure-content">
    <section class="panel spaced-top" aria-labelledby="fleet-availability-title">
        <div class="panel-header">
            <div>
                <h2 id="fleet-availability-title" class="panel-title">整體監測品質</h2>
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

    <section class="panel spaced-top" aria-labelledby="fleet-slo-title">
        <div class="panel-header">
            <div>
                <h2 id="fleet-slo-title" class="panel-title">SLO 錯誤預算狀態</h2>
            </div>
            <a class="button button-secondary button-small" href="{{ route('monitors.availability.export.fleet') }}">匯出完整 SLO CSV</a>
        </div>
        @if ($fleetAvailability['slo_budget_summary']['monitor_count'] === 0)
            <div class="empty-state">
                <h3 class="empty-title">沒有啟用中的監測項目</h3>
                <p class="empty-copy">啟用監測後，這裡會整理近 30 天的錯誤預算與資料覆蓋情形。</p>
            </div>
        @else
            <div class="stat-grid">
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">已超出預算</span><span class="badge badge-issue">需處理</span></div>
                    <strong class="stat-value">{{ $fleetAvailability['slo_budget_summary']['exceeded_count'] }}</strong>
                </article>
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">預算高用量</span><span class="badge badge-warning">≥ 80%</span></div>
                    <strong class="stat-value">{{ $fleetAvailability['slo_budget_summary']['high_usage_count'] }}</strong>
                </article>
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">資料覆蓋不足</span><span class="badge badge-unknown">待補觀測</span></div>
                    <strong class="stat-value">{{ $fleetAvailability['slo_budget_summary']['insufficient_coverage_count'] }}</strong>
                </article>
            </div>

            @if ($fleetAvailability['slo_budget_summary']['priority_monitors'] === [])
                <div class="empty-state">
                    <h3 class="empty-title">目前沒有 SLO 預算提醒</h3>
                    <p class="empty-copy">啟用中的項目尚無預算超支、高用量或覆蓋不足狀況。</p>
                </div>
            @else
                <div class="data-table-wrap" role="region" aria-labelledby="fleet-slo-title" tabindex="0">
                    <table class="data-table">
                        <caption class="sr-only">最需要留意的監測項目近 30 天錯誤預算狀態</caption>
                        <thead>
                            <tr>
                                <th scope="col">監測項目</th>
                                <th scope="col">SLO 目標</th>
                                <th scope="col">錯誤預算用量</th>
                                <th scope="col">剩餘預算</th>
                                <th scope="col">資料覆蓋率</th>
                                <th scope="col">提醒</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fleetAvailability['slo_budget_summary']['priority_monitors'] as $sloMonitor)
                                <tr>
                                    <th scope="row"><a class="monitor-name-link" href="{{ route('monitors.show', $sloMonitor['id']) }}">{{ $sloMonitor['name'] }}</a></th>
                                    <td class="numeric">{{ number_format($sloMonitor['target_percent'], 2) }}%</td>
                                    <td class="numeric">{{ $sloMonitor['error_budget_used_percent'] === null ? '—' : number_format($sloMonitor['error_budget_used_percent'], 2).'%' }}</td>
                                    <td class="numeric">
                                        @if ($sloMonitor['remaining_error_budget_minutes'] === null)
                                            —
                                        @elseif ($sloMonitor['remaining_error_budget_minutes'] < 0)
                                            超支 {{ number_format(abs($sloMonitor['remaining_error_budget_minutes']), 1) }} 分鐘
                                        @else
                                            {{ number_format($sloMonitor['remaining_error_budget_minutes'], 1) }} 分鐘
                                        @endif
                                    </td>
                                    <td class="numeric">{{ $sloMonitor['coverage_rate'] === null ? '—' : number_format($sloMonitor['coverage_rate'], 2).'%' }}</td>
                                    <td>
                                        <div class="row-actions">
                                            @if ($sloMonitor['is_exhausted'])
                                                <span class="badge badge-issue">預算已超支</span>
                                            @endif
                                            @if ($sloMonitor['is_high_usage'])
                                                <span class="badge badge-warning">用量 ≥ 80%</span>
                                            @endif
                                            @if ($sloMonitor['insufficient_coverage'])
                                                <span class="badge badge-unknown">覆蓋不足</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($fleetAvailability['slo_budget_summary']['flagged_monitor_count'] > count($fleetAvailability['slo_budget_summary']['priority_monitors']))
                    <p class="secondary-text spaced-top">另有 {{ $fleetAvailability['slo_budget_summary']['flagged_monitor_count'] - count($fleetAvailability['slo_budget_summary']['priority_monitors']) }} 項需留意。</p>
                @endif
            @endif
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="dashboard-maintenance-title">
        <div class="panel-header">
            <div>
                <h2 id="dashboard-maintenance-title" class="panel-title">近期預定維護</h2>
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

    <section class="panel" aria-labelledby="incidents-title">
        <div class="panel-header">
            <div>
                <h2 id="incidents-title" class="panel-title">進行中的事件</h2>
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
                <p class="secondary-text spaced-top">另有 {{ $expiringCertificateCount - $expiringCertificates->count() }} 張憑證未列出。</p>
            @endif
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="domain-expiry-title">
        <div class="panel-header">
            <div>
                <h2 id="domain-expiry-title" class="panel-title">網域註冊期限</h2>
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
                <p class="secondary-text spaced-top">另有 {{ $expiringDomainCount - $expiringDomains->count() }} 個網域未列出。</p>
            @endif
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="dns-changes-title">
        <div class="panel-header">
            <div>
                <h2 id="dns-changes-title" class="panel-title">DNS 記錄變更</h2>
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
        </div>
    </details>
        </div>
    </details>

@endsection
