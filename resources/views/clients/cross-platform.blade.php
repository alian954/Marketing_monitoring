@extends('layouts.app')

@section('title', $client->name.'｜跨平台總覽')

@section('content')
    @php
        $primaryGroup = $overview['currency_groups'][0] ?? null;
        $primaryCurrency = $overview['primary_currency_code'];
        $formatDate = static fn (string $date): string => str_replace('-', '/', $date);
        $changeText = static function (?array $change, string $suffix = '', int $decimals = 2): string {
            if ($change === null) {
                return '前期無資料';
            }

            $delta = ($change['delta'] > 0 ? '+' : '').number_format($change['delta'], $decimals).$suffix;

            return $change['percent_change'] === null
                ? '較前期 '.$delta
                : '較前期 '.$delta.'（'.($change['percent_change'] > 0 ? '+' : '').number_format($change['percent_change'], 1).'%）';
        };
        $money = static fn (?float $amount, string $currency): string => $amount === null ? '—' : number_format($amount, 2).' '.$currency;
        $metric = static fn (?float $value, int $decimals = 2, string $suffix = ''): string => $value === null ? '—' : number_format($value, $decimals).$suffix;
        $attributionLabels = [
            'platform_higher' => '廣告平台回報的轉換明顯多於 GA4，常見原因是各平台都把同一筆轉換算給自己（重複歸因），或使用瀏覽歸因。',
            'platform_lower' => '廣告平台回報的轉換明顯少於 GA4，可能是平台轉換追蹤漏設，或 GA4 計入了非廣告帶來的轉換。',
            'aligned' => '廣告平台與 GA4 的轉換數接近。',
            'ga4_zero' => 'GA4 在此期間沒有記錄到重要事件。',
            'ga4_unavailable' => '此期間沒有可用的 GA4 資料，無法對照。',
        ];
        $statusLabel = static fn (array $coverage): string => match (true) {
            $coverage['configured_campaigns'] === 0 => '未綁定',
            $coverage['campaigns_with_data'] === 0 => '無資料',
            $coverage['stale_campaigns'] > 0 || $coverage['unavailable_campaigns'] > 0 => '部分資料',
            default => '已同步',
        };
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">跨平台總覽</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">{{ $client->name }}｜跨平台總覽</h1>
            <p class="page-description">
                {{ $formatDate($period['from']) }}–{{ $formatDate($period['to']) }} · 比較前期 {{ $formatDate($previousPeriod['from']) }}–{{ $formatDate($previousPeriod['to']) }} · {{ number_format($campaignCount) }} 個活動
            </p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('clients.annotations.index', $client) }}">行銷日曆</a>
            <a class="button button-secondary" href="{{ route('clients.performance', $period) }}">所有客戶成效</a>
            <a class="button button-secondary" href="{{ route('clients.index') }}">返回客戶</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="cross-platform-period-title">
        <div class="panel-header">
            <h2 id="cross-platform-period-title" class="panel-title">報表期間</h2>
        </div>
        <div class="panel-body">
            <form action="{{ route('clients.cross-platform', $client) }}" method="GET" aria-labelledby="cross-platform-period-title">
                <div class="form-grid">
                    <div class="field-group">
                        <label class="field-label" for="cross-platform-from">開始日期</label>
                        <input class="field-input{{ $errors->has('from') ? ' is-invalid' : '' }}" id="cross-platform-from" name="from" type="date" value="{{ old('from', $period['from']) }}" required aria-describedby="cross-platform-period-help{{ $errors->has('from') ? ' cross-platform-from-error' : '' }}" @if ($errors->has('from')) aria-invalid="true" @endif>
                        @error('from')<p id="cross-platform-from-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="cross-platform-to">結束日期</label>
                        <input class="field-input{{ $errors->has('to') ? ' is-invalid' : '' }}" id="cross-platform-to" name="to" type="date" value="{{ old('to', $period['to']) }}" required aria-describedby="cross-platform-period-help{{ $errors->has('to') ? ' cross-platform-to-error' : '' }}" @if ($errors->has('to')) aria-invalid="true" @endif>
                        @error('to')<p id="cross-platform-to-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </div>
                <p id="cross-platform-period-help" class="field-help">最多 92 天，結束日期最晚為昨天；自動與前一個等長期間比較。</p>
                <div class="form-actions">
                    <button class="button button-secondary" type="submit">更新報表</button>
                    <a class="strong-link" href="{{ route('clients.cross-platform', $client) }}">近 30 天</a>
                </div>
            </form>
        </div>
    </section>

    @if ($primaryGroup === null)
        <section class="panel spaced-top">
            <div class="empty-state">
                <h2 class="empty-title">此期間沒有廣告數據</h2>
                <p class="empty-copy">請確認活動已綁定 Google Ads 或 Meta Ads 活動，且已完成同步。</p>
            </div>
        </section>
    @else
        @php
            $total = $primaryGroup['total'];
            $primaryComparison = $comparison['currency_groups'][$primaryCurrency] ?? [];
        @endphp
        <section class="stat-grid cross-platform-stat-grid spaced-top" aria-label="{{ $primaryCurrency }} 跨平台合計">
            <article class="stat-card">
                <div class="stat-topline"><span class="stat-label">總廣告花費</span></div>
                <strong class="stat-value">{{ number_format($total['spend'], 0) }}</strong>
                <p class="stat-hint">{{ $primaryCurrency }} · {{ $changeText($primaryComparison['spend'] ?? null) }}</p>
            </article>
            <article class="stat-card">
                <div class="stat-topline"><span class="stat-label">平台回報轉換</span></div>
                <strong class="stat-value">{{ number_format($total['conversions'], 1) }}</strong>
                <p class="stat-hint">{{ $changeText($primaryComparison['conversions'] ?? null, '', 1) }}</p>
            </article>
            <article class="stat-card">
                <div class="stat-topline"><span class="stat-label">混合 ROAS</span></div>
                <strong class="stat-value">{{ $metric($total['roas'], 2, ' 倍') }}</strong>
                <p class="stat-hint">{{ $changeText($primaryComparison['roas'] ?? null, ' 倍') }}</p>
            </article>
            <article class="stat-card">
                <div class="stat-topline"><span class="stat-label">平均轉換成本</span></div>
                <strong class="stat-value">{{ $metric($total['cost_per_conversion'], 0) }}</strong>
                <p class="stat-hint">{{ $primaryCurrency }} · {{ $changeText($primaryComparison['cost_per_conversion'] ?? null) }}</p>
            </article>
        </section>
    @endif

    @if ($budgetPacing !== null && $budgetPacing['total']['status'] !== null)
        <section class="panel spaced-top" aria-labelledby="cross-platform-budget-title">
            <div class="panel-header">
                <div>
                    <h2 id="cross-platform-budget-title" class="panel-title">{{ $budgetPacing['month'] }} 月預算</h2>
                    <p class="panel-description">
                        已花費 {{ number_format($budgetPacing['total']['spend'], 0) }} / {{ number_format($budgetPacing['total']['budget'], 0) }} {{ $budgetPacing['currency_code'] }}
                        · 預估月底 {{ $budgetPacing['total']['projected_spend'] === null ? '—' : number_format($budgetPacing['total']['projected_spend'], 0) }}
                    </p>
                </div>
                <div class="row-actions">
                    <span class="budget-status budget-status--{{ $budgetPacing['total']['status'] }}">{{ \App\Services\ClientBudgetPacing::STATUS_LABELS[$budgetPacing['total']['status']] }}</span>
                    <a class="strong-link" href="{{ route('clients.budgets.index', ['client' => $client, 'month' => $budgetPacing['month']]) }}">查看月預算</a>
                </div>
            </div>
        </section>
    @endif

    <section class="panel spaced-top" aria-labelledby="cross-platform-insight-title">
        <div class="panel-header">
            <div>
                <h2 id="cross-platform-insight-title" class="panel-title">AI 洞察摘要</h2>
                <p class="panel-description">由 Claude 依本頁數據、月預算與期間事件寫出重點、異常與建議。產生時會把彙總後的成效數據傳送給 Anthropic，不含帳號憑證或聯絡資料。</p>
            </div>
            @unless (auth()->user()?->isReadOnlyWorkspaceMember())
                @if ($insightAvailable && $primaryGroup !== null && ! ($insight?->isPending() ?? false))
                    <form action="{{ route('clients.insights.store', $client) }}" method="POST" class="inline-form">
                        @csrf
                        <input type="hidden" name="from" value="{{ $period['from'] }}">
                        <input type="hidden" name="to" value="{{ $period['to'] }}">
                        <button class="button button-secondary button-small" type="submit">{{ $insight === null ? '產生 AI 摘要' : '重新產生' }}</button>
                    </form>
                @endif
            @endunless
        </div>
        <div class="panel-body">
            @error('insight')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            @if (! $insightAvailable)
                <p class="empty-copy">系統尚未設定 ANTHROPIC_API_KEY，暫時無法使用 AI 摘要。</p>
            @elseif ($insight === null)
                <p class="empty-copy">{{ $primaryGroup === null ? '此期間沒有廣告數據，無法產生摘要。' : '尚未產生此期間的摘要。' }}</p>
            @elseif ($insight->isPending())
                <p class="empty-copy" role="status">AI 摘要產生中，請稍後<a href="{{ route('clients.cross-platform', ['client' => $client, 'from' => $period['from'], 'to' => $period['to']]) }}">重新整理</a>。</p>
            @elseif (! $insight->isCompleted() || $insight->sections() === null)
                <p class="field-error" role="alert">{{ $insight->error_message ?? 'AI 摘要產生失敗，請重新產生。' }}</p>
            @else
                @php $sections = $insight->sections(); @endphp
                <p class="insight-summary">{{ $sections['summary'] }}</p>
                <div class="insight-grid">
                    <div>
                        <h3 class="insight-heading">表現亮點</h3>
                        <ul class="insight-list">
                            @foreach ($sections['highlights'] as $item)<li>{{ $item }}</li>@endforeach
                        </ul>
                    </div>
                    <div>
                        <h3 class="insight-heading">需要注意</h3>
                        <ul class="insight-list">
                            @foreach ($sections['issues'] as $issue)<li><strong>{{ $issue['title'] }}</strong>：{{ $issue['detail'] }}</li>@endforeach
                        </ul>
                    </div>
                    <div>
                        <h3 class="insight-heading">下期建議</h3>
                        <ul class="insight-list">
                            @foreach ($sections['recommendations'] as $item)<li>{{ $item }}</li>@endforeach
                        </ul>
                    </div>
                </div>
                <p class="field-help">產生於 <time datetime="{{ $insight->completed_at?->toIso8601String() }}">{{ $insight->completed_at?->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>。AI 摘要可能有誤，請對照上方數據確認後再提供給客戶。</p>
            @endif
        </div>
    </section>

    @foreach ($overview['currency_groups'] as $group)
        <section class="panel spaced-top" aria-labelledby="cross-platform-table-{{ $group['currency_code'] }}">
            <div class="panel-header">
                <div>
                    <h2 id="cross-platform-table-{{ $group['currency_code'] }}" class="panel-title">平台比較（{{ $group['currency_code'] }}）</h2>
                    <p class="panel-description">轉換與轉換價值採各平台自己的歸因；Meta Ads 以購買（omni_purchase）計算。同一個廣告活動綁定在多個活動時只計算一次。</p>
                </div>
            </div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">{{ $group['currency_code'] }} 各廣告平台的花費、成效與轉換</caption>
                    <thead>
                        <tr>
                            <th scope="col">平台</th>
                            <th scope="col" class="numeric">花費</th>
                            <th scope="col" class="numeric">花費占比</th>
                            <th scope="col" class="numeric">曝光</th>
                            <th scope="col" class="numeric">點擊</th>
                            <th scope="col" class="numeric">CTR</th>
                            <th scope="col" class="numeric">CPC</th>
                            <th scope="col" class="numeric">轉換</th>
                            <th scope="col" class="numeric">轉換成本</th>
                            <th scope="col" class="numeric">轉換價值</th>
                            <th scope="col" class="numeric">ROAS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (\App\Services\ClientCrossPlatformOverview::PAID_SOURCES as $source => $definition)
                            @php $platform = $group['platforms'][$source]; @endphp
                            <tr>
                                <th scope="row">
                                    {{ $definition['label'] }}
                                    <span class="secondary-text">{{ number_format($platform['campaign_count']) }} 個廣告活動 · {{ number_format($platform['active_days']) }} 天有數據</span>
                                </th>
                                <td class="numeric">{{ number_format($platform['spend'], 2) }}</td>
                                <td class="numeric">{{ $metric($platform['spend_share_percent'], 1, '%') }}</td>
                                <td class="numeric">{{ number_format($platform['impressions']) }}</td>
                                <td class="numeric">{{ number_format($platform['clicks']) }}</td>
                                <td class="numeric">{{ $metric($platform['ctr_percent'], 2, '%') }}</td>
                                <td class="numeric">{{ $metric($platform['cost_per_click']) }}</td>
                                <td class="numeric">{{ number_format($platform['conversions'], 1) }}<span class="secondary-text">{{ $definition['conversion_label'] }}</span></td>
                                <td class="numeric">{{ $metric($platform['cost_per_conversion']) }}</td>
                                <td class="numeric">{{ number_format($platform['conversion_value'], 2) }}</td>
                                <td class="numeric">{{ $metric($platform['roas'], 2, ' 倍') }}</td>
                            </tr>
                        @endforeach
                        @php $groupTotal = $group['total']; @endphp
                        <tr class="cross-platform-total-row">
                            <th scope="row">合計</th>
                            <td class="numeric">{{ number_format($groupTotal['spend'], 2) }}</td>
                            <td class="numeric">100%</td>
                            <td class="numeric">{{ number_format($groupTotal['impressions']) }}</td>
                            <td class="numeric">{{ number_format($groupTotal['clicks']) }}</td>
                            <td class="numeric">{{ $metric($groupTotal['ctr_percent'], 2, '%') }}</td>
                            <td class="numeric">{{ $metric($groupTotal['cost_per_click']) }}</td>
                            <td class="numeric">{{ number_format($groupTotal['conversions'], 1) }}</td>
                            <td class="numeric">{{ $metric($groupTotal['cost_per_conversion']) }}</td>
                            <td class="numeric">{{ number_format($groupTotal['conversion_value'], 2) }}</td>
                            <td class="numeric">{{ $metric($groupTotal['roas'], 2, ' 倍') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    @if ($chart !== null)
        <section class="panel spaced-top" aria-labelledby="cross-platform-chart-title">
            <div class="panel-header">
                <div>
                    <h2 id="cross-platform-chart-title" class="panel-title">每日花費（{{ $primaryCurrency }}）</h2>
                    <p class="panel-description">
                        <span class="cross-platform-legend cross-platform-legend--google">Google Ads</span>
                        <span class="cross-platform-legend cross-platform-legend--meta">Meta Ads</span>
                    </p>
                </div>
            </div>
            <div class="metric-trend-chart">
                <figure class="metric-trend-figure">
                    <svg class="metric-trend-svg" viewBox="0 0 600 196" role="img" aria-labelledby="cross-platform-chart-svg-title">
                        <title id="cross-platform-chart-svg-title">{{ $formatDate($period['from']) }}–{{ $formatDate($period['to']) }} 每日 Google Ads 與 Meta Ads 花費堆疊長條圖，最高單日 {{ $chart['maximum_label'] }} {{ $primaryCurrency }}</title>
                        <line class="metric-trend-grid-line" x1="48" y1="24" x2="576" y2="24"></line>
                        <line class="metric-trend-grid-line" x1="48" y1="154" x2="576" y2="154"></line>
                        <text class="metric-trend-axis-label" x="44" y="28" text-anchor="end">{{ $chart['maximum_label'] }}</text>
                        <text class="metric-trend-axis-label" x="44" y="158" text-anchor="end">0</text>
                        @foreach ($chart['bars'] as $bar)
                            <g>
                                <title>{{ $bar['label'] }}</title>
                                @if ($bar['google_ads']['height'] > 0)
                                    <rect class="cross-platform-bar--google" x="{{ $bar['x'] }}" y="{{ $bar['google_ads']['y'] }}" width="{{ $bar['width'] }}" height="{{ $bar['google_ads']['height'] }}"></rect>
                                @endif
                                @if ($bar['meta_marketing']['height'] > 0)
                                    <rect class="cross-platform-bar--meta" x="{{ $bar['x'] }}" y="{{ $bar['meta_marketing']['y'] }}" width="{{ $bar['width'] }}" height="{{ $bar['meta_marketing']['height'] }}"></rect>
                                @endif
                            </g>
                        @endforeach
                        @foreach ($chartMarkers as $marker)
                            <g class="timeline-marker timeline-marker--{{ $marker['sources'][0] }}">
                                <title>{{ $marker['label'] }}</title>
                                <line x1="{{ $marker['x'] }}" y1="18" x2="{{ $marker['x'] }}" y2="154"></line>
                                <circle cx="{{ $marker['x'] }}" cy="14" r="4"></circle>
                            </g>
                        @endforeach
                        @foreach ($chart['axis_labels'] as $axisLabel)
                            <text class="metric-trend-axis-label" x="{{ $axisLabel['x'] }}" y="174" text-anchor="middle">{{ $axisLabel['label'] }}</text>
                        @endforeach
                    </svg>
                </figure>
                <details class="metric-trend-data">
                    <summary>查看每日數據</summary>
                    <div class="data-table-wrap">
                        <table class="data-table">
                            <caption class="sr-only">每日各平台花費與轉換</caption>
                            <thead>
                                <tr>
                                    <th scope="col">日期</th>
                                    <th scope="col" class="numeric">Google Ads 花費</th>
                                    <th scope="col" class="numeric">Meta Ads 花費</th>
                                    <th scope="col" class="numeric">平台轉換</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (array_reverse($overview['daily']) as $day)
                                    <tr>
                                        <th scope="row"><time datetime="{{ $day['date'] }}">{{ $formatDate($day['date']) }}</time></th>
                                        <td class="numeric">{{ number_format($day['google_ads_spend'], 2) }}</td>
                                        <td class="numeric">{{ number_format($day['meta_marketing_spend'], 2) }}</td>
                                        <td class="numeric">{{ number_format($day['conversions'], 1) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </section>
    @endif

    <section class="panel spaced-top" aria-labelledby="cross-platform-timeline-title">
        <div class="panel-header">
            <div>
                <h2 id="cross-platform-timeline-title" class="panel-title">期間事件</h2>
                <p class="panel-description">
                    @foreach (\App\Services\ClientMarketingTimeline::SOURCE_LABELS as $source => $sourceLabel)
                        <span class="timeline-legend timeline-legend--{{ $source }}">{{ $sourceLabel }}</span>
                    @endforeach
                    · 上方圖表的圓點標示有事件的日期。
                </p>
            </div>
            @unless (auth()->user()?->isReadOnlyWorkspaceMember())
                <a class="button button-secondary button-small" href="{{ route('clients.annotations.index', $client) }}">新增註記</a>
            @endunless
        </div>
        @if ($timeline === [])
            <p class="empty-copy panel-body">此期間沒有行銷註記、網站監測事件或行銷警示。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">期間內的行銷註記、網站監測事件與行銷警示</caption>
                    <thead>
                        <tr>
                            <th scope="col">日期</th>
                            <th scope="col">來源</th>
                            <th scope="col">類型</th>
                            <th scope="col">內容</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($timeline as $entry)
                            <tr>
                                <td><time datetime="{{ $entry['date'] }}">{{ $formatDate($entry['date']) }}</time></td>
                                <td><span class="timeline-legend timeline-legend--{{ $entry['source'] }}">{{ \App\Services\ClientMarketingTimeline::SOURCE_LABELS[$entry['source']] }}</span></td>
                                <td>{{ $entry['category_label'] }}</td>
                                <td>
                                    {{ $entry['title'] }}
                                    @if ($entry['note'] !== null)
                                        <span class="secondary-text annotation-note">{{ $entry['note'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="cross-platform-ga4-title">
        <div class="panel-header">
            <div>
                <h2 id="cross-platform-ga4-title" class="panel-title">GA4 對照與重複歸因</h2>
                <p class="panel-description">GA4 以網站實際發生的工作階段與重要事件計算，可用來檢查各平台是否重複認列同一筆轉換。</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($overview['ga4']['has_data'])
                <dl class="cross-platform-facts">
                    <div>
                        <dt>GA4 工作階段</dt>
                        <dd>{{ number_format($overview['ga4']['sessions']) }}<span class="secondary-text">{{ $changeText($comparison['ga4']['sessions'], '', 0) }}</span></dd>
                    </div>
                    <div>
                        <dt>GA4 重要事件</dt>
                        <dd>{{ number_format($overview['ga4']['key_events'], 1) }}<span class="secondary-text">{{ $changeText($comparison['ga4']['key_events'], '', 1) }}</span></dd>
                    </div>
                    <div>
                        <dt>GA4 營收</dt>
                        <dd>
                            @forelse ($overview['ga4']['revenue_groups'] as $currencyCode => $revenue)
                                <span>{{ $money($revenue, $currencyCode) }}</span>
                            @empty
                                —
                            @endforelse
                        </dd>
                    </div>
                    <div>
                        <dt>平台轉換 ÷ GA4 重要事件</dt>
                        <dd>{{ $metric($overview['attribution']['ratio'], 2, ' 倍') }}</dd>
                    </div>
                </dl>
            @endif
            <p class="cross-platform-attribution cross-platform-attribution--{{ $overview['attribution']['status'] }}" role="status">
                {{ $attributionLabels[$overview['attribution']['status']] }}
                @if ($overview['attribution']['ga4_key_events'] !== null)
                    廣告平台合計 {{ number_format($overview['attribution']['platform_conversions'], 1) }} 次，GA4 記錄 {{ number_format($overview['attribution']['ga4_key_events'], 1) }} 次。
                @endif
            </p>
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="cross-platform-coverage-title">
        <div class="panel-header">
            <div>
                <h2 id="cross-platform-coverage-title" class="panel-title">資料完整度</h2>
                <p class="panel-description">資料不完整時，上方合計可能偏低。</p>
            </div>
        </div>
        <div class="data-table-wrap">
            <table class="data-table">
                <caption class="sr-only">各資料來源已綁定、有資料、逾期與重複排除的活動數</caption>
                <thead>
                    <tr>
                        <th scope="col">資料來源</th>
                        <th scope="col">狀態</th>
                        <th scope="col" class="numeric">已綁定</th>
                        <th scope="col" class="numeric">有資料</th>
                        <th scope="col" class="numeric">資料逾期</th>
                        <th scope="col" class="numeric">無法讀取</th>
                        <th scope="col" class="numeric">重複綁定已排除</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($overview['coverage'] as $coverage)
                        <tr>
                            <th scope="row">{{ $coverage['label'] }}</th>
                            <td>{{ $statusLabel($coverage) }}</td>
                            <td class="numeric">{{ number_format($coverage['configured_campaigns']) }}</td>
                            <td class="numeric">{{ number_format($coverage['campaigns_with_data']) }}</td>
                            <td class="numeric">{{ number_format($coverage['stale_campaigns']) }}</td>
                            <td class="numeric">{{ number_format($coverage['unavailable_campaigns']) }}</td>
                            <td class="numeric">{{ number_format($coverage['duplicate_mappings_excluded']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
