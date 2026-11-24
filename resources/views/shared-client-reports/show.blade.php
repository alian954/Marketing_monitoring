@extends('layouts.public-status')

@section('title', '行銷成效報表')
@section('body-class', 'public-client-report-body')

@push('scripts')
    @vite(['resources/js/app.js'])
@endpush

@section('content')
    @php
        $reportSectionMap = array_fill_keys($reportSections, true);
        $currencySourceMetrics = [];

        if (isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_GOOGLE_ANALYTICS])) {
            $currencySourceMetrics['google_analytics'] = ['amount' => 'ga4_revenue', 'count' => 'ga4_campaign_count', 'label' => 'GA4 營收'];
        }
        if (isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_META_MARKETING])) {
            $currencySourceMetrics['meta_marketing'] = ['amount' => 'meta_spend', 'count' => 'meta_campaign_count', 'label' => 'Meta 花費'];
        }
        if (isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_GOOGLE_ADS])) {
            $currencySourceMetrics['google_ads'] = ['amount' => 'google_ads_spend', 'count' => 'google_ads_campaign_count', 'label' => 'Google Ads 花費'];
        }

    @endphp

    <div class="public-status-header">
        <span class="public-status-brand">{{ $share->report_brand_name ?: '監測台｜行銷報表' }}</span>
    </div>

    <header class="public-status-overview">
        <p class="eyebrow">客戶報表</p>
        <h1 class="page-title">{{ $client->name }}</h1>
        <p class="public-status-description">行銷活動成效</p>
        @if ($reportPeriod !== null)
            <p class="public-status-updated">報表期間 {{ $reportPeriod['label'] }}</p>
            @if ($comparisonPeriod !== null)
                <p class="public-status-updated">比較前期 {{ $comparisonPeriod['label'] }}</p>
            @endif
            @if (isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_PUBLIC_ENGAGEMENT]))
                <p class="campaign-report-secondary">期間內貼文的最新公開互動數。{{ $comparisonPeriod !== null ? '前期比較只含 GA4 與廣告花費。' : '' }}</p>
            @endif
        @endif
        <p class="public-status-updated">分享連結有效至 {{ $share->expires_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</p>
    </header>

    <div class="campaign-report-actions campaign-report-export">
        <button class="button button-secondary" type="button" data-print-page>列印／存成 PDF</button>
        <a class="button button-secondary" href="{{ route('client-report-shares.public.export', ['token' => $token]) }}">下載 CSV</a>
    </div>

    @if ($share->snapshots->isNotEmpty())
        <section class="panel public-status-panel spaced-top" aria-labelledby="shared-report-snapshots-title">
            <div class="panel-body">
                <h2 id="shared-report-snapshots-title" class="panel-title">封存報表</h2>
                <ul class="report-snapshot-list">
                    @foreach ($share->snapshots as $snapshot)
                        @php
                            $snapshotPeriodLabel = $snapshot->report_starts_on !== null && $snapshot->report_ends_on !== null
                                ? $snapshot->report_starts_on->format('Y/n/j').'–'.$snapshot->report_ends_on->format('Y/n/j')
                                : $snapshot->created_at->timezone('Asia/Taipei')->format('Y/n/j');
                        @endphp
                        <li>
                            <a href="{{ route('client-report-shares.public.snapshots.export', ['token' => $token, 'snapshot' => $snapshot->id]) }}">下載 {{ $snapshotPeriodLabel }}</a>
                            <time datetime="{{ $snapshot->created_at->toIso8601String() }}">建立於 {{ $snapshot->created_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    @if ($currencySourceMetrics !== [] && $reportingCurrencyGroups !== [])
        <section class="panel public-status-panel" aria-labelledby="shared-report-currency-title">
            <div class="panel-body">
                <h2 id="shared-report-currency-title" class="panel-title">報表幣別彙總</h2>
                <p class="campaign-report-secondary">依每日有效匯率換算；缺少任一日匯率的來源活動不納入金額。</p>
                <div class="data-table-wrap spaced-top" role="region" aria-labelledby="shared-report-currency-title" tabindex="0">
                    <table class="data-table">
                        <caption class="sr-only">客戶報表幣別下的 {{ implode('、', array_column($currencySourceMetrics, 'label')) }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">報表幣別</th>
                                @foreach ($currencySourceMetrics as $metric)
                                    <th scope="col" class="numeric">{{ $metric['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reportingCurrencyGroups as $reportingCurrencyGroup)
                                <tr>
                                    <th scope="row">{{ $reportingCurrencyGroup['currency_code'] }}</th>
                                    @foreach ($currencySourceMetrics as $source => $metric)
                                        @php
                                            $conversionCoverage = $reportingCurrencyGroup['conversion_coverage'][$source] ?? ['converted_campaigns' => 0, 'missing_rate_campaigns' => 0, 'missing_daily_data_campaigns' => 0, 'missing_rate_observations' => 0];
                                        @endphp
                                        <td class="numeric">
                                            @if ($reportingCurrencyGroup[$metric['count']] > 0)
                                                {{ number_format($reportingCurrencyGroup[$metric['amount']], 2) }} {{ $reportingCurrencyGroup['currency_code'] }}
                                                <span class="campaign-report-secondary">{{ number_format($conversionCoverage['converted_campaigns']) }} 個來源活動</span>
                                            @else
                                                —
                                            @endif
                                            @if ($conversionCoverage['missing_rate_campaigns'] > 0 || $conversionCoverage['missing_daily_data_campaigns'] > 0)
                                                <span class="campaign-report-secondary">缺匯率 {{ number_format($conversionCoverage['missing_rate_campaigns']) }} 個活動／{{ number_format($conversionCoverage['missing_rate_observations']) }} 個日期；缺逐日資料 {{ number_format($conversionCoverage['missing_daily_data_campaigns']) }} 個</span>
                                            @endif
                                            @if ($comparisonPeriod !== null)
                                                @php
                                                    $metricChange = $reportingCurrencyGroup['comparison'][$metric['amount']][$reportingCurrencyGroup['currency_code']] ?? null;
                                                @endphp
                                                @if (is_array($metricChange) && $metricChange['status'] === 'available')
                                                    <span class="campaign-report-secondary">較前期 {{ ($metricChange['delta'] > 0 ? '+' : '').number_format($metricChange['delta'], 2) }} {{ $reportingCurrencyGroup['currency_code'] }}（{{ ($metricChange['percent_change'] > 0 ? '+' : '').number_format($metricChange['percent_change'], 2) }}%）</span>
                                                @elseif (is_array($metricChange) && $metricChange['status'] === 'zero_baseline')
                                                    <span class="campaign-report-secondary">較前期 {{ ($metricChange['delta'] > 0 ? '+' : '').number_format($metricChange['delta'], 2) }} {{ $reportingCurrencyGroup['currency_code'] }}（前期為 0）</span>
                                                @else
                                                    <span class="campaign-report-secondary">比較資料不足</span>
                                                @endif
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif

    @if ($campaignRows->isEmpty())
        <section class="panel public-status-panel" aria-labelledby="shared-report-empty-title">
            <div class="panel-body">
                <h2 id="shared-report-empty-title" class="panel-title">尚無行銷活動</h2>
            </div>
        </section>
    @else
        @foreach ($campaignRows as $campaignRow)
            @php
                $target = $campaignRow['target'];
                $publicEngagement = $campaignRow['public_engagement'];
                $marketing = $campaignRow['marketing'];
                $efficiency = $marketing['efficiency'];
                $googleAnalytics = $marketing['google_analytics'];
                $metaMarketing = $marketing['meta_marketing'];
                $googleAds = $marketing['google_ads'];
                $showCampaignEfficiency = isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_GOOGLE_ANALYTICS])
                    && match ($efficiency['roas_spend_source'] ?? null) {
                        'google_ads' => isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_GOOGLE_ADS]),
                        'meta_marketing' => isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_META_MARKETING]),
                        'manual' => isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_META_MARKETING]) || isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_GOOGLE_ADS]),
                        default => false,
                    };
            @endphp

            <section class="campaign-report-panel public-status-panel" aria-labelledby="shared-campaign-{{ $target->id }}-title">
                <div class="campaign-report-section-header">
                    <div>
                        <h2 id="shared-campaign-{{ $target->id }}-title">{{ $target->tag }}</h2>
                        <p class="campaign-report-subtitle">{{ ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'threads' => 'Threads', 'dcard' => 'Dcard'][$target->social_platform] ?? $target->social_platform }}</p>
                    </div>
                    @if ($target->starts_on || $target->ends_on)
                        <p class="campaign-report-generated">{{ $target->starts_on?->format('Y/n/j') ?? '不限起日' }} – {{ $target->ends_on?->format('Y/n/j') ?? '不限迄日' }}</p>
                    @endif
                </div>

                <dl class="campaign-report-summary spaced-top">
                    @if (isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_PUBLIC_ENGAGEMENT]))
                        <div>
                            <dt>公開互動</dt>
                            <dd>{{ $publicEngagement['interaction_total'] === null ? '—' : number_format($publicEngagement['interaction_total']) }}</dd>
                            <span class="campaign-report-secondary">
                                @if ($publicEngagement['tracked_posts'] > 0)
                                    {{ number_format($publicEngagement['available_posts']) }} / {{ number_format($publicEngagement['tracked_posts']) }} 篇取得完整互動數
                                @else
                                    尚無追蹤貼文
                                @endif
                            </span>
                        </div>
                    @endif
                    @if (isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_GOOGLE_ANALYTICS]))
                        <div>
                            <dt>GA4 工作階段</dt>
                            <dd>{{ $googleAnalytics['sessions'] === null ? '—' : number_format($googleAnalytics['sessions']) }}</dd>
                            <span class="campaign-report-secondary">{{ $googleAnalytics['status_label'] }}</span>
                        </div>
                        <div>
                            <dt>GA4 營收</dt>
                            <dd>{{ $googleAnalytics['revenue'] === null ? '—' : number_format((float) $googleAnalytics['revenue'], 2).' '.($googleAnalytics['currency_code'] ?? '') }}</dd>
                            <span class="campaign-report-secondary">{{ $googleAnalytics['status_label'] }}</span>
                            @if ($comparisonPeriod !== null)
                                @php
                                    $ga4Change = $campaignRow['comparison']['money']['ga4_revenue'][$googleAnalytics['currency_code'] ?? ''] ?? null;
                                @endphp
                                @if (is_array($ga4Change) && $ga4Change['status'] === 'available')
                                    <span class="campaign-report-secondary">較前期 {{ ($ga4Change['delta'] > 0 ? '+' : '').number_format($ga4Change['delta'], 2) }} {{ $googleAnalytics['currency_code'] }}（{{ ($ga4Change['percent_change'] > 0 ? '+' : '').number_format($ga4Change['percent_change'], 2) }}%）</span>
                                @elseif (is_array($ga4Change) && $ga4Change['status'] === 'zero_baseline')
                                    <span class="campaign-report-secondary">較前期 {{ ($ga4Change['delta'] > 0 ? '+' : '').number_format($ga4Change['delta'], 2) }} {{ $googleAnalytics['currency_code'] }}（前期為 0）</span>
                                @else
                                    <span class="campaign-report-secondary">比較資料不足</span>
                                @endif
                            @endif
                        </div>
                    @endif
                    @if (isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_META_MARKETING]))
                        <div>
                            <dt>Meta 花費</dt>
                            <dd>{{ $metaMarketing['spend_amount'] === null ? '—' : number_format((float) $metaMarketing['spend_amount'], 2).' '.($metaMarketing['currency_code'] ?? '') }}</dd>
                            <span class="campaign-report-secondary">{{ $metaMarketing['status_label'] }}</span>
                            @if ($comparisonPeriod !== null)
                                @php
                                    $metaChange = $campaignRow['comparison']['money']['meta_spend'][$metaMarketing['currency_code'] ?? ''] ?? null;
                                @endphp
                                @if (is_array($metaChange) && $metaChange['status'] === 'available')
                                    <span class="campaign-report-secondary">較前期 {{ ($metaChange['delta'] > 0 ? '+' : '').number_format($metaChange['delta'], 2) }} {{ $metaMarketing['currency_code'] }}（{{ ($metaChange['percent_change'] > 0 ? '+' : '').number_format($metaChange['percent_change'], 2) }}%）</span>
                                @elseif (is_array($metaChange) && $metaChange['status'] === 'zero_baseline')
                                    <span class="campaign-report-secondary">較前期 {{ ($metaChange['delta'] > 0 ? '+' : '').number_format($metaChange['delta'], 2) }} {{ $metaMarketing['currency_code'] }}（前期為 0）</span>
                                @else
                                    <span class="campaign-report-secondary">比較資料不足</span>
                                @endif
                            @endif
                        </div>
                    @endif
                    @if (isset($reportSectionMap[\App\Models\ClientReportShare::SECTION_GOOGLE_ADS]))
                        <div>
                            <dt>Google Ads 花費</dt>
                            <dd>{{ $googleAds['spend_amount'] === null ? '—' : number_format((float) $googleAds['spend_amount'], 2).' '.($googleAds['currency_code'] ?? '') }}</dd>
                            <span class="campaign-report-secondary">{{ $googleAds['status_label'] }}</span>
                            @if ($comparisonPeriod !== null)
                                @php
                                    $googleAdsChange = $campaignRow['comparison']['money']['google_ads_spend'][$googleAds['currency_code'] ?? ''] ?? null;
                                @endphp
                                @if (is_array($googleAdsChange) && $googleAdsChange['status'] === 'available')
                                    <span class="campaign-report-secondary">較前期 {{ ($googleAdsChange['delta'] > 0 ? '+' : '').number_format($googleAdsChange['delta'], 2) }} {{ $googleAds['currency_code'] }}（{{ ($googleAdsChange['percent_change'] > 0 ? '+' : '').number_format($googleAdsChange['percent_change'], 2) }}%）</span>
                                @elseif (is_array($googleAdsChange) && $googleAdsChange['status'] === 'zero_baseline')
                                    <span class="campaign-report-secondary">較前期 {{ ($googleAdsChange['delta'] > 0 ? '+' : '').number_format($googleAdsChange['delta'], 2) }} {{ $googleAds['currency_code'] }}（前期為 0）</span>
                                @else
                                    <span class="campaign-report-secondary">比較資料不足</span>
                                @endif
                            @endif
                        </div>
                    @endif
                    @if ($showCampaignEfficiency)
                        <div>
                        <dt>GA4 ROAS</dt>
                        <dd>{{ $efficiency['roas'] === null ? '—' : number_format($efficiency['roas'], 2).' 倍' }}</dd>
                        <span class="campaign-report-secondary">{{ $efficiency['roas_unavailable_reason'] === null ? '依同幣別營收與花費計算' : '尚無可比較數據' }}</span>
                        @if ($comparisonPeriod !== null)
                            @php
                                $roasCurrencyCode = $efficiency['roas_spend_currency'] ?? '';
                                $roasChange = $campaignRow['comparison']['roas']['ga4_roas|'.$roasCurrencyCode] ?? null;
                            @endphp
                            @if (is_array($roasChange) && $roasChange['status'] === 'available')
                                <span class="campaign-report-secondary">較前期 {{ ($roasChange['delta'] > 0 ? '+' : '').number_format($roasChange['delta'], 2) }}×（{{ ($roasChange['percent_change'] > 0 ? '+' : '').number_format($roasChange['percent_change'], 2) }}%）</span>
                            @elseif (is_array($roasChange) && $roasChange['status'] === 'zero_baseline')
                                <span class="campaign-report-secondary">較前期 {{ ($roasChange['delta'] > 0 ? '+' : '').number_format($roasChange['delta'], 2) }}×（前期為 0）</span>
                            @else
                                <span class="campaign-report-secondary">比較資料不足</span>
                            @endif
                        @endif
                        </div>
                    @endif
                </dl>

                <dl class="campaign-report-freshness" aria-label="各資料來源最近擷取時間">
                    @foreach ([
                        \App\Models\ClientReportShare::SECTION_PUBLIC_ENGAGEMENT => ['label' => '公開互動', 'captured_at' => $publicEngagement['latest_at']],
                        \App\Models\ClientReportShare::SECTION_GOOGLE_ANALYTICS => ['label' => 'GA4', 'captured_at' => $googleAnalytics['synced_at']],
                        \App\Models\ClientReportShare::SECTION_META_MARKETING => ['label' => 'Meta Ads', 'captured_at' => $metaMarketing['synced_at']],
                        \App\Models\ClientReportShare::SECTION_GOOGLE_ADS => ['label' => 'Google Ads', 'captured_at' => $googleAds['synced_at']],
                    ] as $section => $source)
                        @continue(! isset($reportSectionMap[$section]))
                        <div>
                            <dt>{{ $source['label'] }}</dt>
                            <dd>
                                @if ($source['captured_at'] !== null)
                                    <time datetime="{{ $source['captured_at']->toIso8601String() }}">{{ $source['captured_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                @else
                                    尚無成功擷取
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    @endif
@endsection

@section('footer', '行銷成效資料由監測台提供')
