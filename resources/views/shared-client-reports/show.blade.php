@extends('layouts.public-status')

@section('title', '行銷成效報表')

@section('content')
    <div class="public-status-header">
        <span class="public-status-brand">監測台｜行銷報表</span>
    </div>

    <header class="public-status-overview">
        <p class="eyebrow">客戶報表</p>
        <h1 class="page-title">{{ $client->name }}</h1>
        <p class="public-status-description">行銷活動成效</p>
        <p class="public-status-updated">分享連結有效至 {{ $share->expires_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</p>
    </header>

    <div class="campaign-report-actions campaign-report-export">
        <a class="button button-secondary" href="{{ route('client-report-shares.public.export', ['token' => $token]) }}">下載 CSV</a>
    </div>

    @if ($reportingCurrencyGroups !== [])
        <section class="panel public-status-panel" aria-labelledby="shared-report-currency-title">
            <div class="panel-body">
                <h2 id="shared-report-currency-title" class="panel-title">報表幣別彙總</h2>
                <p class="campaign-report-secondary">依每日有效匯率換算；缺少任一日匯率的來源活動不納入金額。</p>
                <div class="data-table-wrap spaced-top" role="region" aria-labelledby="shared-report-currency-title" tabindex="0">
                    <table class="data-table">
                        <caption class="sr-only">客戶報表幣別下的 GA4 營收與廣告花費</caption>
                        <thead>
                            <tr>
                                <th scope="col">報表幣別</th>
                                <th scope="col" class="numeric">GA4 營收</th>
                                <th scope="col" class="numeric">Meta 花費</th>
                                <th scope="col" class="numeric">Google Ads 花費</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reportingCurrencyGroups as $reportingCurrencyGroup)
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
                                                {{ number_format($reportingCurrencyGroup[$metric['amount']], 2) }} {{ $reportingCurrencyGroup['currency_code'] }}
                                                <span class="campaign-report-secondary">{{ number_format($conversionCoverage['converted_campaigns']) }} 個來源活動</span>
                                            @else
                                                —
                                            @endif
                                            @if ($conversionCoverage['missing_rate_campaigns'] > 0 || $conversionCoverage['missing_daily_data_campaigns'] > 0)
                                                <span class="campaign-report-secondary">缺匯率 {{ number_format($conversionCoverage['missing_rate_campaigns']) }} 個活動／{{ number_format($conversionCoverage['missing_rate_observations']) }} 個日期；缺逐日資料 {{ number_format($conversionCoverage['missing_daily_data_campaigns']) }} 個</span>
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
                    <div>
                        <dt>GA4 工作階段</dt>
                        <dd>{{ $googleAnalytics['sessions'] === null ? '—' : number_format($googleAnalytics['sessions']) }}</dd>
                        <span class="campaign-report-secondary">{{ $googleAnalytics['status_label'] }}</span>
                    </div>
                    <div>
                        <dt>GA4 營收</dt>
                        <dd>{{ $googleAnalytics['revenue'] === null ? '—' : number_format((float) $googleAnalytics['revenue'], 2).' '.($googleAnalytics['currency_code'] ?? '') }}</dd>
                        <span class="campaign-report-secondary">{{ $googleAnalytics['status_label'] }}</span>
                    </div>
                    <div>
                        <dt>Meta 花費</dt>
                        <dd>{{ $metaMarketing['spend_amount'] === null ? '—' : number_format((float) $metaMarketing['spend_amount'], 2).' '.($metaMarketing['currency_code'] ?? '') }}</dd>
                        <span class="campaign-report-secondary">{{ $metaMarketing['status_label'] }}</span>
                    </div>
                    <div>
                        <dt>Google Ads 花費</dt>
                        <dd>{{ $googleAds['spend_amount'] === null ? '—' : number_format((float) $googleAds['spend_amount'], 2).' '.($googleAds['currency_code'] ?? '') }}</dd>
                        <span class="campaign-report-secondary">{{ $googleAds['status_label'] }}</span>
                    </div>
                    <div>
                        <dt>GA4 ROAS</dt>
                        <dd>{{ $efficiency['roas'] === null ? '—' : number_format($efficiency['roas'], 2).' 倍' }}</dd>
                        <span class="campaign-report-secondary">{{ $efficiency['roas_unavailable_reason'] === null ? '依同幣別營收與花費計算' : '尚無可比較數據' }}</span>
                    </div>
                </dl>

                <dl class="campaign-report-freshness" aria-label="各資料來源最近擷取時間">
                    @foreach ([
                        '公開互動' => $publicEngagement['latest_at'],
                        'GA4' => $googleAnalytics['synced_at'],
                        'Meta Ads' => $metaMarketing['synced_at'],
                        'Google Ads' => $googleAds['synced_at'],
                    ] as $sourceLabel => $capturedAt)
                        <div>
                            <dt>{{ $sourceLabel }}</dt>
                            <dd>
                                @if ($capturedAt !== null)
                                    <time datetime="{{ $capturedAt->toIso8601String() }}">{{ $capturedAt->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
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
