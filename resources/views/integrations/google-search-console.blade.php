@extends('layouts.app')

@section('title', 'Google Search Console')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">資料來源</p>
            <h1>Google Search Console</h1>
            <p class="page-description">查看自然搜尋的曝光、點擊與排名。</p>
        </div>
    </div>

    <section class="panel" aria-labelledby="search-console-connection-title">
        <div class="panel-header">
            <div>
                <h2 id="search-console-connection-title" class="panel-title">帳戶連線</h2>
                <p class="panel-description">只要求 Search Console 唯讀權限；報表執行時才向 Google 讀取資料。</p>
            </div>
        </div>
        <div class="panel-body">
            @include('integrations.partials.oauth-application-credentials', ['providerCode' => 'google_search_console', 'providerName' => 'Google Search Console'])
            @if ($oauthConfigured)
                <form action="{{ route('integrations.google-search-console.connect') }}" method="POST">
                    @csrf
                    <button class="button button-primary" type="submit">{{ $connection ? '重新連結 Google 帳戶' : '連結 Google 帳戶' }}</button>
                </form>
            @else
                <p class="field-help">儲存 OAuth App 憑證後即可連結 Search Console。</p>
            @endif

            @if ($connection)
                <p class="connection-status" role="status">Google Search Console 已連結。</p>
                @if ($siteListError)
                    <p class="alert alert-error" role="alert">{{ $siteListError }}</p>
                @endif
                <h3 class="spaced-top">客戶網站</h3>
                <p class="field-help">每個客戶選擇一個已授權網站。</p>
                @forelse ($clients as $client)
                    @php
                        $clientSite = $clientSites->get($client->getKey());
                        $savedSiteUrl = $clientSite?->site_url ?? ($clients->count() === 1 ? $connection->site_url : null);
                        $clientSiteValue = (string) old('client_id') === (string) $client->getKey()
                            ? old('site_url', $savedSiteUrl)
                            : $savedSiteUrl;
                        $clientSiteFieldId = 'search-console-site-url-'.$client->getKey();
                        $clientSiteHelpId = $clientSiteFieldId.'-help';
                        $clientSiteErrorId = $clientSiteFieldId.'-error';
                    @endphp
                    <form action="{{ route('integrations.google-search-console.site.update') }}" method="POST" class="check-history-filter">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                        <div class="field-group">
                            <label class="field-label" for="{{ $clientSiteFieldId }}">{{ $client->name }} · Search Console 網站</label>
                            @if ($sites !== [])
                                <select class="field-input{{ (string) old('client_id') === (string) $client->getKey() && $errors->has('site_url') ? ' is-invalid' : '' }}" id="{{ $clientSiteFieldId }}" name="site_url" required aria-describedby="{{ $clientSiteHelpId }}{{ (string) old('client_id') === (string) $client->getKey() && $errors->has('site_url') ? ' '.$clientSiteErrorId : '' }}" @if ((string) old('client_id') === (string) $client->getKey() && $errors->has('site_url')) aria-invalid="true" @endif>
                                    <option value="">選擇網站</option>
                                    @foreach ($sites as $site)
                                        <option value="{{ $site['site_url'] }}" @selected($clientSiteValue === $site['site_url'])>{{ $site['site_url'] }} · {{ match ($site['permission_level']) {'siteOwner' => '擁有者', 'siteFullUser' => '完整使用者', 'siteRestrictedUser' => '受限使用者', default => '未驗證使用者'} }}</option>
                                    @endforeach
                                </select>
                            @else
                                <p class="field-help">此 Google 帳戶目前沒有可讀取的 Search Console 網站。</p>
                            @endif
                            <p id="{{ $clientSiteHelpId }}" class="field-help">只會列出目前 Google 帳戶已授權讀取的網站。</p>
                            @if ((string) old('client_id') === (string) $client->getKey())
                                @error('site_url')<p id="{{ $clientSiteErrorId }}" class="field-error" role="alert">{{ $message }}</p>@enderror
                            @endif
                        </div>
                        @if ($sites !== [])
                            <button class="button button-secondary" type="submit">儲存客戶網站</button>
                        @endif
                    </form>
                @empty
                    <p class="field-help">目前沒有可設定的客戶。</p>
                @endforelse

                <form action="{{ route('integrations.google-search-console.disconnect') }}" method="POST" class="integration-disconnect-form">
                    @csrf
                    @method('DELETE')
                    <button class="button button-secondary" type="submit">中斷連結</button>
                </form>
            @endif
            @error('google_search_console')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </div>
    </section>

    @if ($connection && $clients->isNotEmpty())
        <section class="panel spaced-top" aria-labelledby="search-console-report-title">
            <div class="panel-header">
                <div>
                    <h2 id="search-console-report-title" class="panel-title">自然搜尋成效</h2>
                    <p class="panel-description">{{ $selectedClient?->name ?? '選擇客戶' }} · {{ $selectedSiteUrl ?? '尚未設定網站' }}</p>
                </div>
            </div>
            <div class="panel-body">
                <form action="{{ route('integrations.google-search-console.report') }}" method="GET" class="campaign-spend-form" aria-labelledby="search-console-report-form-title">
                    <h3 id="search-console-report-form-title">報表條件</h3>
                    @php
                        $selectedSearchType = old('search_type', $reportInputs['search_type'] ?? 'web');
                        $availableDimensions = match ($selectedSearchType) {
                            'discover' => ['page' => '熱門頁面', 'country' => '國家／地區'],
                            'googleNews', 'webMultimodal' => ['page' => '熱門頁面', 'country' => '國家／地區', 'device' => '裝置'],
                            default => ['query' => '搜尋查詢', 'page' => '熱門頁面', 'country' => '國家／地區', 'device' => '裝置'],
                        };
                    @endphp
                    <div class="field-group">
                        <label class="field-label" for="search-console-client">客戶</label>
                        <select class="field-input{{ $errors->has('client_id') ? ' is-invalid' : '' }}" id="search-console-client" name="client_id" required @if ($errors->has('client_id')) aria-invalid="true" aria-describedby="search-console-client-error" @endif>
                            @foreach ($clients as $client)
                                @php
                                    $clientSite = $clientSites->get($client->getKey());
                                    $clientSiteUrl = $clientSite?->site_url ?? ($clients->count() === 1 ? $connection->site_url : null);
                                @endphp
                                <option value="{{ $client->getKey() }}" @selected((string) old('client_id', $reportInputs['client_id'] ?? $selectedClient?->getKey()) === (string) $client->getKey())>{{ $client->name }} · {{ $clientSiteUrl ?? '尚未設定網站' }}</option>
                            @endforeach
                        </select>
                        @error('client_id')<p id="search-console-client-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="search-console-start-date">開始日期</label>
                        <input class="field-input{{ $errors->has('start_date') ? ' is-invalid' : '' }}" id="search-console-start-date" name="start_date" type="date" min="{{ now('America/Los_Angeles')->subMonthsNoOverflow(16)->toDateString() }}" max="{{ $defaultEndDate }}" value="{{ old('start_date', $reportInputs['start_date'] ?? $defaultStartDate) }}" required @if ($errors->has('start_date')) aria-invalid="true" aria-describedby="search-console-start-date-error" @endif>
                        @error('start_date')<p id="search-console-start-date-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="search-console-end-date">結束日期</label>
                        <input class="field-input{{ $errors->has('end_date') ? ' is-invalid' : '' }}" id="search-console-end-date" name="end_date" type="date" min="{{ now('America/Los_Angeles')->subMonthsNoOverflow(16)->toDateString() }}" max="{{ $defaultEndDate }}" value="{{ old('end_date', $reportInputs['end_date'] ?? $defaultEndDate) }}" required @if ($errors->has('end_date')) aria-invalid="true" aria-describedby="search-console-end-date-error" @endif>
                        @error('end_date')<p id="search-console-end-date-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="search-console-dimension">分析維度</label>
                        <select class="field-input{{ $errors->has('dimension') ? ' is-invalid' : '' }}" id="search-console-dimension" name="dimension" required @if ($errors->has('dimension')) aria-invalid="true" aria-describedby="search-console-dimension-error" @endif>
                            @foreach ($availableDimensions as $value => $label)
                                <option value="{{ $value }}" @selected(old('dimension', $reportInputs['dimension'] ?? array_key_first($availableDimensions)) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="field-help">Discover 僅支援頁面與國家；Google News 和多模態搜尋不提供搜尋查詢。</p>
                        @error('dimension')<p id="search-console-dimension-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="search-console-search-type">搜尋類型</label>
                        <select class="field-input{{ $errors->has('search_type') ? ' is-invalid' : '' }}" id="search-console-search-type" name="search_type" required aria-describedby="search-console-search-type-help{{ $errors->has('search_type') ? ' search-console-search-type-error' : '' }}" @if ($errors->has('search_type')) aria-invalid="true" @endif>
                            <option value="web" @selected(old('search_type', $reportInputs['search_type'] ?? 'web') === 'web')>網頁</option>
                            <option value="image" @selected(old('search_type', $reportInputs['search_type'] ?? 'web') === 'image')>圖片</option>
                            <option value="video" @selected(old('search_type', $reportInputs['search_type'] ?? 'web') === 'video')>影片</option>
                            <option value="news" @selected(old('search_type', $reportInputs['search_type'] ?? 'web') === 'news')>新聞分頁</option>
                            <option value="discover" @selected(old('search_type', $reportInputs['search_type'] ?? 'web') === 'discover')>Discover</option>
                            <option value="googleNews" @selected(old('search_type', $reportInputs['search_type'] ?? 'web') === 'googleNews')>Google News</option>
                            <option value="webMultimodal" @selected(old('search_type', $reportInputs['search_type'] ?? 'web') === 'webMultimodal')>多模態搜尋</option>
                        </select>
                        <p id="search-console-search-type-help" class="field-help">網頁、圖片、影片、新聞與 Discover 數據分開查詢。</p>
                        @error('search_type')<p id="search-console-search-type-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <label for="search-console-compare">
                        <input id="search-console-compare" name="compare" type="checkbox" value="1" @checked(old('compare', $reportInputs['compare'] ?? false))>
                        比較前一個相同天數區間
                    </label>
                    <div class="campaign-spend-form-actions">
                        <button class="button button-primary" type="submit">產生報表</button>
                        <button class="button button-secondary" type="submit" formaction="{{ route('integrations.google-search-console.report.export') }}">匯出 CSV</button>
                    </div>
                </form>
                <p class="field-help">Search Console 日期採太平洋時間；通常延遲 2–3 天。只接受最近 16 個月的完整資料。</p>
            </div>
        </section>
    @endif

    @if ($report)
        @php
            $metricLabels = [
                'clicks' => '點擊',
                'impressions' => '曝光',
                'ctr' => '點閱率',
                'position' => '平均排名',
            ];
            $currentTotals = $report['current']['totals'];
            $previousReport = $report['comparison']['report'];
        @endphp
        <section class="panel spaced-top" aria-labelledby="search-console-results-title">
            <div class="panel-header">
                <div>
                    <h2 id="search-console-results-title" class="panel-title">{{ $report['dimension_label'] }}成效</h2>
                    <p class="panel-description">{{ $report['client_name'] }} · {{ $report['site_url'] }} · {{ $report['search_type_label'] }} · {{ $report['current']['starts_on'] }} 至 {{ $report['current']['ends_on'] }}</p>
                </div>
            </div>
            <div class="panel-body">
                <dl class="campaign-report-summary ga4-report-summary">
                    @foreach ($metricLabels as $metric => $label)
                        <div>
                            <dt>{{ $label }}</dt>
                            <dd>
                                @switch($metric)
                                    @case('ctr'){{ number_format($currentTotals[$metric] * 100, 2) }}%@break
                                    @case('position'){{ number_format($currentTotals[$metric], 2) }}@break
                                    @default{{ number_format($currentTotals[$metric]) }}
                                @endswitch
                            </dd>
                            @if ($previousReport)
                                @php($change = $report['comparison']['changes'][$metric])
                                <span class="campaign-report-secondary">
                                    @if ($metric === 'ctr')
                                        前期 {{ number_format($previousReport['totals'][$metric] * 100, 2) }}% · Δ {{ $change['difference'] > 0 ? '+' : '' }}{{ number_format($change['difference'], 2) }} 個百分點
                                    @elseif ($metric === 'position')
                                        前期 {{ number_format($previousReport['totals'][$metric], 2) }} · Δ {{ $change['difference'] > 0 ? '+' : '' }}{{ number_format($change['difference'], 2) }}（負值代表排名提升）
                                    @else
                                        前期 {{ number_format($previousReport['totals'][$metric]) }} · Δ {{ $change['difference'] > 0 ? '+' : '' }}{{ number_format($change['difference']) }}
                                        @if ($change['percent'] !== null)
                                            （{{ $change['percent'] > 0 ? '+' : '' }}{{ number_format($change['percent'], 1) }}%）
                                        @endif
                                    @endif
                                </span>
                            @endif
                        </div>
                    @endforeach
                </dl>
                @if ($report['comparison']['status'] === 'outside_retention')
                    <p class="campaign-report-secondary spaced-top">前期比較區間早於 Search Console 可用的 16 個月資料範圍。</p>
                @elseif ($report['comparison']['status'] === 'available')
                    <p class="campaign-report-secondary spaced-top">前期：{{ $report['comparison']['starts_on'] }} 至 {{ $report['comparison']['ends_on'] }}。</p>
                @endif
                <h3 class="spaced-top">{{ $report['dimension_label'] }}</h3>
                <p class="campaign-report-secondary">最多列出 100 筆，依點擊數排序。Google 可能略去低量或隱私敏感的搜尋查詢，列值不一定涵蓋全部流量。</p>
                <div class="data-table-wrap" role="region" aria-labelledby="search-console-results-table-title" tabindex="0">
                    <table class="data-table">
                        <caption id="search-console-results-table-title">{{ $report['dimension_label'] }}的自然搜尋成效</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ $report['dimension_label'] }}</th>
                                <th scope="col" class="numeric">點擊</th>
                                <th scope="col" class="numeric">曝光</th>
                                <th scope="col" class="numeric">點閱率</th>
                                <th scope="col" class="numeric">平均排名</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['current']['rows'] as $row)
                                <tr>
                                    <th scope="row">{{ $row['label'] }}</th>
                                    <td class="numeric">{{ number_format($row['clicks']) }}</td>
                                    <td class="numeric">{{ number_format($row['impressions']) }}</td>
                                    <td class="numeric">{{ number_format($row['ctr'] * 100, 2) }}%</td>
                                    <td class="numeric">{{ number_format($row['position'], 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5">此期間沒有可顯示的資料。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($report['current']['rows_may_be_incomplete'])
                    <p class="campaign-report-secondary spaced-top">已達 100 筆顯示上限，表格可能尚未涵蓋其他資料列。</p>
                @endif
            </div>
        </section>
    @endif
@endsection
