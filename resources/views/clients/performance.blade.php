@extends('layouts.app')

@section('title', '客戶成效')

@section('content')
    @php
        $metricDefinitions = [
            'ga4_revenue' => ['label' => 'GA4 營收', 'count_key' => 'ga4_campaign_count'],
            'meta_spend' => ['label' => 'Meta Ads 花費', 'count_key' => 'meta_campaign_count'],
            'google_ads_spend' => ['label' => 'Google Ads 花費', 'count_key' => 'google_ads_campaign_count'],
        ];
        $coverageSources = [
            'google_analytics' => 'GA4',
            'meta_marketing' => 'Meta',
            'google_ads' => 'Google Ads',
        ];
        $exportParameters = $reportPeriod
            + ($comparison === 'previous' ? ['comparison' => 'previous'] : [])
            + ($clientSearch === '' ? [] : ['search' => $clientSearch]);
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">客戶成效</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">客戶成效</h1>
            <p class="page-description">比較客戶在相同日期區間的行銷成效。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('clients.performance.export', $exportParameters) }}">匯出 CSV</a>
            <a class="button button-secondary" href="{{ route('clients.index') }}">客戶管理</a>
        </div>
    </div>

    <section class="panel spaced-top" aria-labelledby="client-performance-table-title">
        <div class="panel-header">
            <div>
                <h2 id="client-performance-table-title" class="panel-title">行銷成效</h2>
                <p class="panel-description">
                    {{ str_replace('-', '/', $reportPeriod['from']) }}–{{ str_replace('-', '/', $reportPeriod['to']) }}
                    @if ($comparisonPeriod !== null)
                        · 比較前期 {{ str_replace('-', '/', $comparisonPeriod['from']) }}–{{ str_replace('-', '/', $comparisonPeriod['to']) }}
                    @endif
                    · ROAS 採平台歸因，金額依報表幣別或原幣列示。
                </p>
            </div>
        </div>

        <div class="panel-body">
            <search aria-label="篩選客戶成效">
                <form action="{{ route('clients.performance') }}" method="GET">
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="client-performance-search">客戶</label>
                            <input
                                class="field-input{{ $errors->has('search') ? ' is-invalid' : '' }}"
                                id="client-performance-search"
                                name="search"
                                type="search"
                                value="{{ old('search', $clientSearch) }}"
                                maxlength="80"
                                placeholder="搜尋客戶名稱"
                                aria-describedby="client-performance-search-help{{ $errors->has('search') ? ' client-performance-search-error' : '' }}"
                                @if ($errors->has('search')) aria-invalid="true" @endif
                            >
                            <p id="client-performance-search-help" class="field-help">顯示名稱相符的客戶。</p>
                            @error('search')
                                <p id="client-performance-search-error" class="field-error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="client-performance-from">開始日期</label>
                            <input
                                class="field-input{{ $errors->has('from') ? ' is-invalid' : '' }}"
                                id="client-performance-from"
                                name="from"
                                type="date"
                                value="{{ old('from', $reportPeriod['from']) }}"
                                required
                                aria-describedby="client-performance-period-help{{ $errors->has('from') ? ' client-performance-from-error' : '' }}"
                                @if ($errors->has('from')) aria-invalid="true" @endif
                            >
                            @error('from')
                                <p id="client-performance-from-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="client-performance-to">結束日期</label>
                            <input
                                class="field-input{{ $errors->has('to') ? ' is-invalid' : '' }}"
                                id="client-performance-to"
                                name="to"
                                type="date"
                                value="{{ old('to', $reportPeriod['to']) }}"
                                required
                                aria-describedby="client-performance-period-help{{ $errors->has('to') ? ' client-performance-to-error' : '' }}"
                                @if ($errors->has('to')) aria-invalid="true" @endif
                            >
                            @error('to')
                                <p id="client-performance-to-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="client-performance-comparison">比較期間</label>
                            <select
                                class="field-select{{ $errors->has('comparison') ? ' is-invalid' : '' }}"
                                id="client-performance-comparison"
                                name="comparison"
                                aria-describedby="client-performance-comparison-help{{ $errors->has('comparison') ? ' client-performance-comparison-error' : '' }}"
                                @if ($errors->has('comparison')) aria-invalid="true" @endif
                            >
                                <option value="" @selected($comparison === '')>不比較</option>
                                <option value="previous" @selected($comparison === 'previous')>前一個等長期間</option>
                            </select>
                            @error('comparison')
                                <p id="client-performance-comparison-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <p id="client-performance-period-help" class="field-help">最多 365 天，結束日期最晚為昨天。</p>
                    <p id="client-performance-comparison-help" class="field-help">僅在前後期都有同幣別有效資料時顯示變化。</p>
                    <div class="form-actions">
                        <button class="button button-secondary" type="submit">更新報表</button>
                        <a class="strong-link" href="{{ route('clients.performance') }}">清除</a>
                    </div>
                </form>
            </search>
        </div>

        @if ($errors->has('from') || $errors->has('to') || $errors->has('search') || $errors->has('comparison'))
            <div class="empty-state" role="alert">
                <h3 class="empty-title">請修正篩選條件</h3>
            </div>
        @elseif ($clientPortfolioRows->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">目前沒有可檢視的客戶</h3>
            </div>
        @else
            <div class="data-table-wrap" tabindex="0" role="region" aria-labelledby="client-performance-table-title">
                <table class="data-table">
                    <caption class="sr-only">{{ $reportPeriod['from'] }} 至 {{ $reportPeriod['to'] }} 各客戶活動數量、GA4 營收、廣告花費、平台歸因 ROAS 和資料更新狀態@if ($comparisonPeriod !== null)，並比較 {{ $comparisonPeriod['from'] }} 至 {{ $comparisonPeriod['to'] }}@endif</caption>
                    <thead>
                        <tr>
                            <th scope="col">客戶</th>
                            <th scope="col" class="numeric">活動</th>
                            @foreach ($metricDefinitions as $definition)
                                <th scope="col" class="numeric">{{ $definition['label'] }}</th>
                            @endforeach
                            <th scope="col" class="numeric">平台 ROAS</th>
                            <th scope="col">資料更新</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($clientPortfolioRows as $row)
                            <tr>
                                <th scope="row">
                                    <a class="monitor-name-link" href="{{ route('dashboard', ['client_id' => $row['client']->getKey()]) }}" aria-label="查看{{ $row['client']->name }}行銷報告">
                                        {{ $row['client']->name }}
                                    </a>
                                    @if ($row['client']->reporting_currency_code)
                                        <span class="secondary-text">報表幣別 {{ $row['client']->reporting_currency_code }}</span>
                                    @elseif ($row['money_groups'] !== [])
                                        <span class="secondary-text">原始幣別</span>
                                    @else
                                        <span class="secondary-text">尚未設定報表幣別</span>
                                    @endif
                                </th>
                                <td class="numeric">{{ number_format($row['campaign_count']) }}</td>
                                @foreach ($metricDefinitions as $amountKey => $definition)
                                    @php
                                        $availableGroups = collect($row['money_groups'])
                                            ->filter(static fn (array $group): bool => ($group[$definition['count_key']] ?? 0) > 0);
                                    @endphp
                                    <td class="numeric">
                                        @forelse ($availableGroups as $group)
                                            <span class="secondary-text">{{ number_format((float) $group[$amountKey], 2) }} {{ $group['currency_code'] }}</span>
                                            @if ($comparisonPeriod !== null)
                                                @php
                                                    $metricChange = $row['comparison']['money'][$amountKey][$group['currency_code']] ?? null;
                                                @endphp
                                                @if (is_array($metricChange) && $metricChange['status'] === 'available')
                                                    <span class="secondary-text">
                                                        較前期 {{ ($metricChange['delta'] > 0 ? '+' : '').number_format($metricChange['delta'], 2) }} {{ $group['currency_code'] }}（{{ ($metricChange['percent_change'] > 0 ? '+' : '').number_format($metricChange['percent_change'], 2) }}%）
                                                    </span>
                                                @elseif (is_array($metricChange) && $metricChange['status'] === 'zero_baseline')
                                                    <span class="secondary-text">較前期 {{ ($metricChange['delta'] > 0 ? '+' : '').number_format($metricChange['delta'], 2) }} {{ $group['currency_code'] }}（前期為 0）</span>
                                                @else
                                                    <span class="secondary-text">比較資料不足</span>
                                                @endif
                                            @endif
                                        @empty
                                            <span class="secondary-text">—</span>
                                            @if ($comparisonPeriod !== null)
                                                <span class="secondary-text">比較資料不足</span>
                                            @endif
                                        @endforelse
                                    </td>
                                @endforeach
                                <td class="numeric">
                                    @forelse (collect($row['roas_groups'])->filter(static fn (array $group): bool => $group['roas'] !== null) as $roasGroup)
                                        <span class="secondary-text">{{ $roasGroup['source_label'] }} {{ number_format($roasGroup['roas'], 2) }}× ({{ $roasGroup['currency_code'] }})</span>
                                        @if ($comparisonPeriod !== null)
                                            @php
                                                $roasKey = $roasGroup['source'].'|'.$roasGroup['currency_code'];
                                                $roasChange = $row['comparison']['roas'][$roasKey] ?? null;
                                            @endphp
                                            @if (is_array($roasChange) && $roasChange['status'] === 'available')
                                                <span class="secondary-text">
                                                    較前期 {{ ($roasChange['delta'] > 0 ? '+' : '').number_format($roasChange['delta'], 2) }}×（{{ ($roasChange['percent_change'] > 0 ? '+' : '').number_format($roasChange['percent_change'], 2) }}%）
                                                </span>
                                            @elseif (is_array($roasChange) && $roasChange['status'] === 'zero_baseline')
                                                <span class="secondary-text">較前期 {{ ($roasChange['delta'] > 0 ? '+' : '').number_format($roasChange['delta'], 2) }}×（前期為 0）</span>
                                            @else
                                                <span class="secondary-text">比較資料不足</span>
                                            @endif
                                        @endif
                                    @empty
                                        <span class="secondary-text">—</span>
                                        @if ($comparisonPeriod !== null)
                                            <span class="secondary-text">比較資料不足</span>
                                        @endif
                                    @endforelse
                                </td>
                                <td>
                                    @php
                                        $configuredSources = collect($row['coverage'])
                                            ->filter(static fn (array $coverage): bool => $coverage['configured_campaigns'] > 0);
                                    @endphp
                                    @forelse ($configuredSources as $source => $coverage)
                                        <span class="secondary-text" title="{{ $coverageSources[$source] ?? $source }}新資料／已設定活動">
                                            {{ $coverageSources[$source] ?? $source }} 最新 {{ number_format($coverage['fresh_campaigns']) }}/{{ number_format($coverage['configured_campaigns']) }}
                                        </span>
                                        @if ($coverage['missing_campaigns'] > 0)
                                            <span class="secondary-text">{{ $coverageSources[$source] ?? $source }} 期間資料不足 {{ number_format($coverage['missing_campaigns']) }}</span>
                                        @endif
                                    @empty
                                        <span class="secondary-text">—</span>
                                    @endforelse
                                    @foreach ($row['money_groups'] as $group)
                                        @foreach ($group['conversion_coverage'] ?? [] as $source => $conversionCoverage)
                                            @if ($conversionCoverage['missing_rate_campaigns'] > 0 || $conversionCoverage['missing_daily_data_campaigns'] > 0)
                                                <span class="secondary-text">
                                                    {{ $coverageSources[$source] ?? $source }}
                                                    @if ($conversionCoverage['missing_rate_campaigns'] > 0)
                                                        缺匯率 {{ number_format($conversionCoverage['missing_rate_campaigns']) }}
                                                    @endif
                                                    @if ($conversionCoverage['missing_daily_data_campaigns'] > 0)
                                                        缺逐日資料 {{ number_format($conversionCoverage['missing_daily_data_campaigns']) }}
                                                    @endif
                                                </span>
                                            @endif
                                        @endforeach
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
