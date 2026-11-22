@extends('layouts.app')

@section('title', $target->tag.' 活動成效報告')

@section('content')
    <article class="campaign-report">
        <header class="campaign-report-header">
            <div>
                <p class="campaign-report-eyebrow">活動成效報告</p>
                <h1>{{ $target->tag }}</h1>
                <p class="campaign-report-subtitle">
                    {{ $report['platform_label'] }} ·
                    @if ($target->starts_on && $target->ends_on)
                        {{ $target->starts_on->format('Y/n/j') }} 至 {{ $target->ends_on->format('Y/n/j') }}
                    @else
                        不限日期
                    @endif
                </p>
                <p class="campaign-report-generated">
                    報告時間
                    <time datetime="{{ $report['generated_at']->toIso8601String() }}">{{ $report['generated_at']->format('Y/n/j H:i') }}</time>
                </p>
            </div>
            <div class="campaign-report-actions">
                <button class="button button-primary" type="button" data-print-page>列印／另存 PDF</button>
                <a class="button button-secondary" href="{{ route('dashboard', ['engagement_platform' => $target->social_platform, 'engagement_tag' => 'tag:'.$target->tag]) }}">返回總覽</a>
            </div>
        </header>

        <section class="campaign-report-panel" aria-labelledby="campaign-report-summary-title">
            <h2 id="campaign-report-summary-title">活動摘要</h2>
            <dl class="campaign-report-summary">
                <div>
                    <dt>互動目標</dt>
                    <dd>{{ number_format((int) $target->target_interactions) }}</dd>
                </div>
                <div>
                    <dt>目前互動</dt>
                    <dd>{{ $report['interaction_total'] === null ? '—' : number_format($report['interaction_total']) }}</dd>
                </div>
                <div>
                    <dt>目標達成率</dt>
                    <dd>{{ $report['target_completion_percent'] === null ? '—' : number_format($report['target_completion_percent'], 1).'%' }}</dd>
                </div>
                <div>
                    <dt>完整讀值覆蓋</dt>
                    <dd>
                        {{ number_format($report['complete_read_count']) }}／{{ number_format($report['tracked_post_count']) }} 篇
                        <span class="campaign-report-secondary">({{ $report['capture_coverage_percent'] === null ? '—' : number_format($report['capture_coverage_percent'], 1).'%' }})</span>
                    </dd>
                </div>
                <div>
                    <dt>平均互動／篇</dt>
                    <dd>{{ $report['average_interactions_per_post'] === null ? '—' : number_format($report['average_interactions_per_post'], 1) }}</dd>
                </div>
                <div>
                    <dt>觀看互動率</dt>
                    <dd>{{ $report['engagement_rate_percent'] === null ? '—' : number_format($report['engagement_rate_percent'], 2).'%' }}</dd>
                </div>
            </dl>
        </section>

        <section class="campaign-report-panel" aria-labelledby="campaign-report-ga4-title">
            <div class="campaign-report-section-header">
                <div>
                    <h2 id="campaign-report-ga4-title">GA4 導流成效</h2>
                    <p class="campaign-report-secondary">
                        比對活動名稱：<code>{{ $googleAnalyticsCampaignName }}</code>
                        · 來源：<code>{{ $googleAnalyticsSourceName ?? '所有來源' }}</code>
                    </p>
                </div>
                @if ($googleAnalyticsConnection && $googleAnalyticsConnection->property_id && $target->starts_on && $target->ends_on)
                    <form action="{{ route('social-engagement-targets.google-analytics.sync', $target) }}" method="POST" class="ga4-sync-actions">
                        @csrf
                        <button class="button button-secondary button-small" type="submit">更新 GA4 數據</button>
                    </form>
                @endif
            </div>

            @if (! $googleAnalyticsConnection)
                <p class="campaign-report-empty">尚未連結 GA4。<a href="{{ route('integrations.google-analytics.edit') }}">設定 Google Analytics</a></p>
            @elseif (! $googleAnalyticsConnection->property_id)
                <p class="campaign-report-empty">尚未設定 GA4 資源 ID。<a href="{{ route('integrations.google-analytics.edit') }}">前往設定</a></p>
            @elseif (! $target->starts_on || ! $target->ends_on)
                <p class="campaign-report-empty">設定活動開始日與結束日後，即可讀取相同期間的 GA4 成效。</p>
            @elseif (! $googleAnalyticsReport)
                <p class="campaign-report-empty">尚無 GA4 讀值。按「更新 GA4 數據」開始同步。</p>
            @else
                <p class="campaign-report-secondary">
                    資料期間 {{ $googleAnalyticsReport->starts_on->format('Y/n/j') }} 至 {{ $googleAnalyticsReport->ends_on->format('Y/n/j') }}；
                    更新於 <time datetime="{{ $googleAnalyticsReport->synced_at->toIso8601String() }}">{{ $googleAnalyticsReport->synced_at->format('Y/n/j H:i') }}</time>
                </p>
                <dl class="campaign-report-summary ga4-report-summary">
                    <div>
                        <dt>工作階段</dt>
                        <dd>{{ number_format($googleAnalyticsReport->sessions) }}</dd>
                    </div>
                    <div>
                        <dt>參與工作階段</dt>
                        <dd>{{ number_format($googleAnalyticsReport->engaged_sessions) }}</dd>
                    </div>
                    <div>
                        <dt>重要事件</dt>
                        <dd>{{ number_format((float) $googleAnalyticsReport->key_events, 2) }}</dd>
                    </div>
                    <div>
                        <dt>總營收 <span class="campaign-report-secondary">{{ $googleAnalyticsReport->currency_code ?? '幣別未知' }}</span></dt>
                        <dd>{{ number_format((float) $googleAnalyticsReport->total_revenue, 2) }}</dd>
                    </div>
                </dl>
                <p class="campaign-report-secondary">GA4 的「重要事件」依該資源標記的事件計算；營收採該資源報告幣別。</p>
            @endif
        </section>

        @if ($googleAnalyticsReport)
            <section class="campaign-report-panel" aria-labelledby="campaign-report-ga4-trend-title">
                <div class="campaign-report-section-header">
                    <div>
                        <h2 id="campaign-report-ga4-trend-title">GA4 累計成效變化</h2>
                        <p class="campaign-report-secondary">括號為與前次成功讀值的差異；間隔可能跨越多天，不代表 GA4 單日區間數據。第一筆只作基準，幣別不同或未提供時不計算營收差異。</p>
                    </div>
                    @if ($googleAnalyticsTrend !== [])
                        <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.google-analytics.history', $target) }}">匯出趨勢 CSV</a>
                    @endif
                </div>

                @if ($googleAnalyticsTrend === [])
                    <p class="campaign-report-empty">目前沒有保留中的趨勢讀值；下一次同步後會開始累積。</p>
                @else
                    <div class="data-table-wrap" role="region" aria-labelledby="campaign-report-ga4-trend-title" tabindex="0">
                        <table class="data-table campaign-report-table campaign-report-ga4-trend-table">
                            <caption>最近 30 次 GA4 活動累計讀值與前次讀值差異</caption>
                            <thead>
                                <tr>
                                    <th scope="col">讀值時間</th>
                                    <th scope="col">工作階段<br>累計（差異）</th>
                                    <th scope="col">參與工作階段<br>累計（差異）</th>
                                    <th scope="col">重要事件<br>累計（差異）</th>
                                    <th scope="col">總營收<br>累計（差異）</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($googleAnalyticsTrend as $snapshot)
                                    <tr>
                                        <th scope="row">
                                            <time datetime="{{ $snapshot['snapshot_date']->toDateString() }}">{{ $snapshot['snapshot_date']->format('Y/n/j') }}</time>
                                            <span class="campaign-report-secondary">{{ $snapshot['synced_at']->timezone('Asia/Taipei')->format('H:i') }}</span>
                                        </th>
                                        <td class="numeric">
                                            {{ number_format($snapshot['sessions']) }}
                                            <span class="campaign-report-secondary">({{ $snapshot['sessions_change'] === null ? '基準' : ($snapshot['sessions_change'] > 0 ? '+' : '').number_format($snapshot['sessions_change']) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ number_format($snapshot['engaged_sessions']) }}
                                            <span class="campaign-report-secondary">({{ $snapshot['engaged_sessions_change'] === null ? '基準' : ($snapshot['engaged_sessions_change'] > 0 ? '+' : '').number_format($snapshot['engaged_sessions_change']) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ number_format((float) $snapshot['key_events'], 2) }}
                                            <span class="campaign-report-secondary">({{ $snapshot['key_events_change'] === null ? '基準' : ($snapshot['key_events_change'] > 0 ? '+' : '').number_format($snapshot['key_events_change'], 2) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ number_format((float) $snapshot['total_revenue'], 2) }} {{ $snapshot['currency_code'] ?? '' }}
                                            <span class="campaign-report-secondary">
                                                (@if (! $snapshot['has_previous_snapshot'])
                                                    基準
                                                @elseif ($snapshot['revenue_currency_comparable'])
                                                    {{ ($snapshot['total_revenue_change'] > 0 ? '+' : '').number_format($snapshot['total_revenue_change'], 2) }} {{ $snapshot['currency_code'] }}
                                                @else
                                                    幣別無法比較
                                                @endif)
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        @if ($target->starts_on && $target->ends_on)
            <section class="campaign-report-panel" aria-labelledby="campaign-report-weekly-growth-title">
                <h2 id="campaign-report-weekly-growth-title">每週觀測互動變化</h2>
                <p class="campaign-report-secondary">依活動期間內的貼文與已保存的完整讀值計算；首次讀值只作基準。若沒有前後讀值可比，該週不列為 0。</p>
                @if ($report['weekly_interaction_changes'] === [])
                    <p class="campaign-report-empty">保留期間內尚無可比較的前後讀值。</p>
                @else
                    <div class="data-table-wrap" role="region" aria-labelledby="campaign-report-weekly-growth-title" tabindex="0">
                        <table class="data-table campaign-report-table">
                            <caption>活動期間內，貼文公開互動數逐週的觀測淨變化；口徑為{{ $report['interaction_scope_label'] }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">活動週</th>
                                    <th scope="col">觀測互動淨變化</th>
                                    <th scope="col">可比較貼文</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['weekly_interaction_changes'] as $weeklyChange)
                                    <tr>
                                        <th scope="row">{{ $weeklyChange['label'] }}</th>
                                        <td class="numeric">{{ number_format($weeklyChange['interaction_change']) }}</td>
                                        <td class="numeric">{{ number_format($weeklyChange['compared_post_count']) }} 篇</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        <section class="campaign-report-panel" aria-labelledby="campaign-report-metrics-title">
            <h2 id="campaign-report-metrics-title">公開指標</h2>
                <div class="data-table-wrap">
                <table class="data-table campaign-report-table campaign-report-metrics-table">
                    <caption>活動貼文公開指標合計與可讀篇數</caption>
                    <thead>
                        <tr>
                            <th scope="col">指標</th>
                            <th scope="col">合計</th>
                            <th scope="col">可讀篇數</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (['views' => '觀看', 'likes' => '讚', 'comments' => '留言', 'shares' => '分享'] as $metric => $label)
                            <tr>
                                <th scope="row">{{ $label }}</th>
                                <td class="numeric">{{ $report['metrics'][$metric]['total'] === null ? '—' : number_format($report['metrics'][$metric]['total']) }}</td>
                                <td class="numeric">{{ number_format($report['metrics'][$metric]['available']) }}／{{ number_format($report['tracked_post_count']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="campaign-report-panel" aria-labelledby="campaign-report-posts-title">
            <h2 id="campaign-report-posts-title">互動量排行 <span class="campaign-report-secondary">前 10 篇</span></h2>
            @if ($report['top_posts'] === [])
                <p class="campaign-report-empty">此活動期間尚無符合條件的貼文。</p>
            @else
                <div class="data-table-wrap" role="region" aria-labelledby="campaign-report-posts-title" tabindex="0">
                    <table class="data-table campaign-report-table campaign-report-post-table">
                        <caption>依公開互動數排序的活動貼文；互動口徑為{{ $report['interaction_scope_label'] }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">貼文</th>
                                <th scope="col">發布時間</th>
                                <th scope="col">觀看</th>
                                <th scope="col">讚</th>
                                <th scope="col">留言</th>
                                <th scope="col">分享</th>
                                <th scope="col">互動</th>
                                <th scope="col">讀值狀態</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['top_posts'] as $post)
                                <tr>
                                    <th scope="row">{{ $post['name'] }}</th>
                                    <td>
                                        @if ($post['published_at'])
                                            <time datetime="{{ $post['published_at']->toIso8601String() }}">{{ $post['published_at']->format('Y/n/j H:i') }}</time>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="numeric">{{ $post['views'] === null ? '—' : number_format($post['views']) }}</td>
                                    <td class="numeric">{{ $post['likes'] === null ? '—' : number_format($post['likes']) }}</td>
                                    <td class="numeric">{{ $post['comments'] === null ? '—' : number_format($post['comments']) }}</td>
                                    <td class="numeric">{{ $post['shares'] === null ? '—' : number_format($post['shares']) }}</td>
                                    <td class="numeric">{{ $post['interaction_total'] === null ? '—' : number_format($post['interaction_total']) }}</td>
                                    <td>{{ $post['metrics_freshness'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <p class="campaign-report-note">
            互動數依平台口徑計算。未讀到的公開數據以「—」表示，不視為 0。
            @if ($report['unknown_publication_date_count'] > 0)
                另有 {{ number_format($report['unknown_publication_date_count']) }} 篇未取得發布時間，未納入活動期間。
            @endif
        </p>
    </article>
@endsection
