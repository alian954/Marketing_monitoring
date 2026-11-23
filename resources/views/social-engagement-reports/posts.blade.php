@extends('layouts.app')

@section('title', $target->tag.' 完整貼文歸因')

@section('content')
    @php
        $platformLabels = [
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'threads' => 'Threads',
            'dcard' => 'Dcard',
        ];
        $metricSourceLabels = [
            'json_ld' => 'JSON-LD',
            'embedded_json' => '內嵌 JSON',
            'meta' => 'Meta 標籤',
            'rendered_dom' => '瀏覽器呈現',
        ];
    @endphp

    <article class="campaign-report">
        <header class="campaign-report-header">
            <div>
                <p class="campaign-report-eyebrow">活動貼文明細</p>
                <h1>完整貼文歸因</h1>
                <p class="campaign-report-subtitle">
                    {{ $target->tag }} · {{ $platformLabels[$target->social_platform] ?? $target->social_platform }} ·
                    @if ($target->starts_on && $target->ends_on)
                        {{ $target->starts_on->format('Y/n/j') }} 至 {{ $target->ends_on->format('Y/n/j') }}
                    @else
                        不限日期
                    @endif
                </p>
            </div>
            <div class="campaign-report-actions">
                <a class="button button-secondary" href="{{ route('social-engagement-targets.report', $target) }}">返回活動報告</a>
                <a class="button button-secondary" href="{{ route('social-engagement-targets.report.posts.export', $target) }}">匯出全部 CSV</a>
            </div>
        </header>

        <section class="campaign-report-panel" aria-labelledby="campaign-post-details-title">
            <div class="campaign-report-section-header">
                <div>
                    <h2 id="campaign-post-details-title">貼文成效</h2>
                    <p class="campaign-report-secondary">公開互動數依平台可取得的讀值呈現；GA4 依 utm_content 完全相同值配對。</p>
                    <p class="campaign-report-secondary">共 {{ number_format($paginator->total()) }} 篇貼文</p>
                </div>
            </div>

            <form method="GET" action="{{ route('social-engagement-targets.posts', $target) }}" class="check-history-filter" aria-labelledby="campaign-post-sort-title">
                <div class="field-group">
                    <label class="field-label" id="campaign-post-sort-title" for="campaign-post-sort">排序方式</label>
                    <select class="field-select" id="campaign-post-sort" name="sort">
                        <option value="published_at" @selected($sort === 'published_at')>發布時間：新到舊</option>
                        <option value="name" @selected($sort === 'name')>貼文名稱：A 到 Z</option>
                    </select>
                </div>
                <div class="check-history-filter-actions">
                    <button class="button button-primary" type="submit">套用排序</button>
                </div>
            </form>

            @if ($posts === [])
                <p class="campaign-report-empty">活動期間尚無符合條件的貼文讀值。</p>
            @else
                <div class="data-table-wrap campaign-post-details-table-wrap" role="region" aria-labelledby="campaign-post-table-caption" tabindex="0">
                    <table class="data-table campaign-report-table campaign-report-post-table campaign-post-details-table">
                        <caption id="campaign-post-table-caption">{{ $target->tag }} 活動貼文的公開互動與 GA4 歸因資料</caption>
                        <thead>
                            <tr>
                                <th scope="col">貼文</th>
                                <th scope="col">發布時間</th>
                                <th scope="col">觀看</th>
                                <th scope="col">讚</th>
                                <th scope="col">留言</th>
                                <th scope="col">分享</th>
                                <th scope="col">互動</th>
                                <th scope="col">utm_content</th>
                                <th scope="col">GA4 工作階段</th>
                                <th scope="col">GA4 參與工作階段</th>
                                <th scope="col">GA4 參與率</th>
                                <th scope="col">GA4 重要事件</th>
                                <th scope="col">GA4 收益</th>
                                <th scope="col">GA4 對應</th>
                                <th scope="col">讀值狀態</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($posts as $post)
                                @php($attribution = $post['google_analytics_attribution'])
                                <tr>
                                    <th scope="row">
                                        <a href="{{ $post['url'] }}" target="_blank" rel="noopener noreferrer">{{ $post['name'] }}</a>
                                    </th>
                                    <td>
                                        @if ($post['published_at'])
                                            <time datetime="{{ $post['published_at']->toIso8601String() }}">{{ $post['published_at']->format('Y/n/j H:i') }}</time>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    @foreach (['views', 'likes', 'comments', 'shares'] as $metric)
                                        @php($source = $post['metric_sources'][$metric] ?? null)
                                        <td class="numeric">
                                            {{ $post[$metric] === null ? '—' : number_format($post[$metric]) }}
                                            @if (is_string($source) && isset($metricSourceLabels[$source]))
                                                <span class="secondary-text">來源：{{ $metricSourceLabels[$source] }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="numeric">{{ $post['interaction_total'] === null ? '—' : number_format($post['interaction_total']) }}</td>
                                    <td>{{ $attribution['utm_content'] ?? '—' }}</td>
                                    <td class="numeric">{{ $attribution['sessions'] === null ? '—' : number_format($attribution['sessions']) }}</td>
                                    <td class="numeric">{{ $attribution['engaged_sessions'] === null ? '—' : number_format($attribution['engaged_sessions']) }}</td>
                                    <td class="numeric">{{ $attribution['engagement_rate'] === null ? '—' : number_format((float) $attribution['engagement_rate'] * 100, 2).'%' }}</td>
                                    <td class="numeric">{{ $attribution['key_events'] === null ? '—' : number_format((float) $attribution['key_events'], 2) }}</td>
                                    <td class="numeric">
                                        @if ($attribution['total_revenue'] !== null)
                                            {{ $attribution['currency_code'] ?? '' }} {{ number_format((float) $attribution['total_revenue'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $attribution['status_label'] }}</td>
                                    <td>
                                        {{ $post['metrics_freshness'] }}
                                        @if ($post['finished_at'])
                                            <span class="secondary-text">讀取時間：<time datetime="{{ $post['finished_at']->toIso8601String() }}">{{ $post['finished_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $paginator->links('vendor.pagination.monitor') }}
            @endif
        </section>
    </article>
@endsection
