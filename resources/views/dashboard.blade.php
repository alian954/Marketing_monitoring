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
            <p class="page-description">監測狀態、事件與執行服務。</p>
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
                <p class="panel-description">顯示各平台貼文狀態，以及啟用貼文最近一次檢查讀到互動數的篇數。</p>
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
                <p class="panel-description">最近可讀取的貼文觀看、讚、留言與分享數；依最近檢查時間排序，每頁 50 篇。</p>
            </div>
            <div class="row-actions">
                <a class="strong-link" href="{{ route('monitors.social-engagement.export', ['engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag]) }}">最新值 CSV <span aria-hidden="true">↓</span></a>
                <a class="strong-link" href="{{ route('monitors.social-engagement.export.history', ['engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag, 'engagement_period' => $socialEngagementChangePeriodDays]) }}">{{ $socialEngagementChangePeriodDays }} 天歷史 CSV <span aria-hidden="true">↓</span></a>
            </div>
        </div>
        <div class="panel-body">
            <search aria-label="篩選社群互動成效">
                <form action="{{ route('dashboard') }}" method="GET" class="check-history-filter">
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
                    <caption class="sr-only">社群貼文最近一次可讀取的公開互動數與觀看互動率</caption>
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
                        @foreach ($socialEngagementMonitors as $socialEngagementMonitor)
                            @php
                                $engagementRun = $socialEngagementMonitor->latestPublicEngagementCheckRun;
                                if ($engagementRun?->config_version !== $socialEngagementMonitor->config_version) {
                                    $engagementRun = null;
                                }
                                $engagementMetrics = $engagementRun?->response_public_engagement_metrics ?? [];
                            @endphp
                            <tr>
                                <th scope="row"><a href="{{ route('monitors.show', $socialEngagementMonitor) }}">{{ $socialEngagementMonitor->name }}</a></th>
                                <td>{{ $socialEngagementMonitor->socialPlatform() }}</td>
                                <td>{{ $socialEngagementMonitor->getAttribute('social_engagement_capture_status') }}</td>
                                @foreach (['views', 'likes', 'comments', 'shares'] as $metric)
                                    <td class="numeric">{{ isset($engagementMetrics[$metric]) ? number_format((int) $engagementMetrics[$metric]) : '—' }}</td>
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
                <p class="panel-description">可依互動合計或觀看互動率排序前五篇，並標示資料時間。全部平台時只比較 Facebook／Threads 的完整三項數據；Instagram／Dcard 請篩選平台，以讚＋留言排行。</p>
            </div>
            @if ($socialEngagementTopPosts !== [])
                <nav class="heading-actions" aria-label="互動量排行排序">
                    @foreach ($socialEngagementTopPostSortOptions as $sort => $sortLabel)
                        <a
                            class="button button-small {{ $socialEngagementTopPostSort === $sort ? 'button-primary' : 'button-secondary' }}"
                            href="{{ route('dashboard', ['engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag, 'engagement_period' => $socialEngagementChangePeriodDays, 'engagement_tag_sort' => $socialEngagementTagSort, 'engagement_post_sort' => $sort, 'engagement_growth_sort' => $socialEngagementGrowthPostSort]) }}"
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
                <p class="panel-description">本次／前次擷取篇數另列。全部平台時，平均互動與觀看互動率只計 Facebook／Threads 的完整三項數據；篩選 Instagram／Dcard 時以讚＋留言計。觀看互動率＝同口徑互動合計÷觀看數，只計觀看大於 0 的貼文；跨標籤可能重複計入。</p>
            </div>
            @if ($socialEngagementSummaries !== [])
                <nav class="heading-actions" aria-label="標籤成效排序">
                    @foreach ($socialEngagementTagSortOptions as $sort => $sortLabel)
                        <a
                            class="button button-small {{ $socialEngagementTagSort === $sort ? 'button-primary' : 'button-secondary' }}"
                            href="{{ route('dashboard', ['engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag, 'engagement_period' => $socialEngagementChangePeriodDays, 'engagement_tag_sort' => $sort, 'engagement_post_sort' => $socialEngagementTopPostSort, 'engagement_growth_sort' => $socialEngagementGrowthPostSort]) }}"
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
                                        <a href="{{ route('dashboard', ['engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => 'tag:'.$socialEngagementSummary['filter_label'], 'engagement_period' => $socialEngagementChangePeriodDays, 'engagement_tag_sort' => $socialEngagementTagSort, 'engagement_post_sort' => $socialEngagementTopPostSort, 'engagement_growth_sort' => $socialEngagementGrowthPostSort]) }}">{{ $socialEngagementSummary['tag'] }}</a>
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
                <p class="panel-description">目前值依各貼文最近一次完整讀值加總，括號列出資料覆蓋篇數；進行中有完整讀值時，以尚差互動數除以剩餘活動日並進位。尚未開始則按活動日數分配目標；進行中資料不足、已達成或活動結束時不估算。</p>
            </div>
            <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.export') }}">匯出目標 CSV</a>
        </div>
        <div class="panel-body">
            <form action="{{ route('social-engagement-targets.store') }}" method="POST" class="check-history-filter" aria-describedby="social-engagement-target-help">
                @csrf
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
                    <caption class="sr-only">各平台活動互動目標的目前累計值、達成率、每日所需互動及完整讀值覆蓋篇數</caption>
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
                                    <form action="{{ route('social-engagement-targets.destroy', $socialEngagementTarget['id']) }}" method="POST">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="engagement_platform" value="{{ $socialEngagementSelectedPlatform }}">
                                        <input type="hidden" name="engagement_tag" value="{{ $socialEngagementSelectedTag }}">
                                        <button class="button button-danger button-small" type="submit" aria-label="刪除 {{ $socialEngagementTarget['tag'] }} 的 {{ $socialEngagementTarget['platform_label'] }} 互動目標">刪除</button>
                                    </form>
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
                <p class="panel-description">依台灣時間的公開發布時間，列出各平台每週各日與四個時段的平均互動及篇數。只反映目前追蹤樣本，不代表發文時間造成成效差異。</p>
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

    <section class="panel spaced-top" aria-labelledby="cross-platform-engagement-title">
        <div class="panel-header">
            <div>
                <h2 id="cross-platform-engagement-title" class="panel-title">活動標籤跨平台比較</h2>
                <p class="panel-description">依標籤並列各平台最近可讀數字，並標示本次讀取與沿用前次資料的篇數。</p>
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
                                    <a href="{{ route('dashboard', ['engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $comparisonSummary['filter_label'] === null ? 'unlabeled' : 'tag:'.$comparisonSummary['filter_label'], 'engagement_period' => $socialEngagementChangePeriodDays, 'engagement_tag_sort' => $socialEngagementTagSort, 'engagement_post_sort' => $socialEngagementTopPostSort, 'engagement_growth_sort' => $socialEngagementGrowthPostSort]) }}">{{ $comparisonSummary['tag'] }}</a>
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
                <p class="panel-description">圖表逐篇比較每個可讀取指標與前次數值；兩次都有讀值的讚、留言或分享會分別計入，首筆不計，下降保留。</p>
                <p class="secondary-text">下表只計有兩次讀值的貼文；前期比較限同時有兩期讀值的貼文。</p>
                @if (! $socialEngagementChangeComparisonAvailable)
                    <p class="secondary-text">前期比較需保留 {{ $socialEngagementChangePeriodDays * 2 }} 天；目前設定保留 {{ $socialEngagementRetentionDays }} 天。</p>
                @endif
            </div>
            <nav class="heading-actions" aria-label="互動變化期間">
                @foreach ($socialEngagementChangePeriodOptions as $periodDays => $periodLabel)
                    <a
                        class="button button-small {{ $periodDays === $socialEngagementChangePeriodDays ? 'button-primary' : 'button-secondary' }}"
                        href="{{ route('dashboard', ['engagement_period' => $periodDays, 'engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag, 'engagement_tag_sort' => $socialEngagementTagSort, 'engagement_post_sort' => $socialEngagementTopPostSort, 'engagement_growth_sort' => $socialEngagementGrowthPostSort]) }}"
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
                <p class="panel-description">{{ $socialEngagementGrowthPostSort === 'growth_rate' ? '依互動成長率由高到低排序；期初為 0 的貼文列在最後。' : '依互動淨變化由高到低排序。' }} 成長率＝淨變化÷期初互動量；期初為 0 時無法計算，缺少的指標不當成 0。</p>
                @if ($socialEngagementChangeComparisonAvailable)
                    <p class="panel-description">較前期變化差＝本期增量減前期增量，只計兩期都有讀值的相同指標。</p>
                @endif
                @if ($socialEngagementGrowthPosts === [])
                    <div class="empty-state">
                        <h4 class="empty-title">尚無單篇互動變化</h4>
                        <p class="empty-copy">貼文需在所選期間內至少兩次讀到同項互動數，才會列入排行。</p>
                    </div>
                @else
                    <div class="heading-actions spaced-top">
                        <a class="strong-link" href="{{ route('monitors.social-engagement.export.growth', ['engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag, 'engagement_period' => $socialEngagementChangePeriodDays, 'engagement_growth_sort' => $socialEngagementGrowthPostSort]) }}">匯出完整排行 CSV <span aria-hidden="true">↓</span></a>
                    </div>
                    <nav class="heading-actions spaced-top" aria-label="單篇互動變化排行排序">
                        @foreach ($socialEngagementGrowthPostSortOptions as $sort => $sortLabel)
                            <a
                                class="button button-small {{ $socialEngagementGrowthPostSort === $sort ? 'button-primary' : 'button-secondary' }}"
                                href="{{ route('dashboard', ['engagement_platform' => $socialEngagementSelectedPlatform, 'engagement_tag' => $socialEngagementSelectedTag, 'engagement_period' => $socialEngagementChangePeriodDays, 'engagement_tag_sort' => $socialEngagementTagSort, 'engagement_post_sort' => $socialEngagementTopPostSort, 'engagement_growth_sort' => $sort]) }}"
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

    <section class="panel spaced-top" aria-labelledby="social-platform-availability-title">
        <div class="panel-header">
            <div>
                <h2 id="social-platform-availability-title" class="panel-title">受監測貼文歷史品質</h2>
                <p class="panel-description">依實際觀測估算；空窗與維護時段不計，覆蓋率另列。結果只代表受監測樣本。</p>
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

    <section class="panel spaced-top" aria-labelledby="fleet-availability-title">
        <div class="panel-header">
            <div>
                <h2 id="fleet-availability-title" class="panel-title">整體監測品質</h2>
                <p class="panel-description">比較近 24 小時、7 天與 30 天的實際觀測。</p>
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
                <p class="panel-description">查看近 30 天錯誤預算與資料覆蓋率；資料不足時會提示。</p>
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
                    <p class="stat-hint">觀測到的不可用時間已超過目標允許值</p>
                </article>
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">預算高用量</span><span class="badge badge-warning">≥ 80%</span></div>
                    <strong class="stat-value">{{ $fleetAvailability['slo_budget_summary']['high_usage_count'] }}</strong>
                    <p class="stat-hint">已使用至少 80% 的錯誤預算</p>
                </article>
                <article class="stat-card">
                    <div class="stat-topline"><span class="stat-label">資料覆蓋不足</span><span class="badge badge-unknown">待補觀測</span></div>
                    <strong class="stat-value">{{ $fleetAvailability['slo_budget_summary']['insufficient_coverage_count'] }}</strong>
                    <p class="stat-hint">無可用觀測，或最近 30 天覆蓋率低於 90%</p>
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
                    <p class="panel-description spaced-top">顯示最需留意的前 {{ count($fleetAvailability['slo_budget_summary']['priority_monitors']) }} 項；共 {{ $fleetAvailability['slo_budget_summary']['flagged_monitor_count'] }} 項有提醒。</p>
                @endif
            @endif
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
                <p class="panel-description">列出已過期或 30 天內到期的 HTTPS 憑證；觀測逾 48 小時標示逾時。</p>
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
                <p class="panel-description">每日查詢網域到期日；部分註冊機構不提供資料，觀測逾 48 小時標示逾時。</p>
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
                <p class="panel-description">追蹤監測主機的 DNS 記錄與變更。</p>
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
