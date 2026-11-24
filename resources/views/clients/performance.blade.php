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
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">客戶成效</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">客戶成效</h1>
            <p class="page-description">比較各客戶的營收、廣告花費與平台 ROAS。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('clients.index') }}">客戶管理</a>
        </div>
    </div>

    <section class="panel spaced-top" aria-labelledby="client-performance-table-title">
        <div class="panel-header">
            <div>
                <h2 id="client-performance-table-title" class="panel-title">行銷成效</h2>
                <p class="panel-description">ROAS 採平台歸因；營收與花費依報表幣別或原幣列示。</p>
            </div>
        </div>

        @if ($clientPortfolioRows->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">目前沒有可檢視的客戶</h3>
            </div>
        @else
            <div class="data-table-wrap" tabindex="0" role="region" aria-labelledby="client-performance-table-title">
                <table class="data-table">
                    <caption class="sr-only">各客戶活動數量、GA4 營收、廣告花費、平台歸因 ROAS 和資料更新狀態</caption>
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
                                        @empty
                                            <span class="secondary-text">—</span>
                                        @endforelse
                                    </td>
                                @endforeach
                                <td class="numeric">
                                    @forelse (collect($row['roas_groups'])->filter(static fn (array $group): bool => $group['roas'] !== null) as $roasGroup)
                                        <span class="secondary-text">{{ $roasGroup['source_label'] }} {{ number_format($roasGroup['roas'], 2) }}× ({{ $roasGroup['currency_code'] }})</span>
                                    @empty
                                        <span class="secondary-text">—</span>
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
