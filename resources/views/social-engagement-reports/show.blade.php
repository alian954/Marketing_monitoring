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

        <section class="campaign-report-panel" aria-labelledby="campaign-report-meta-title">
            <div class="campaign-report-section-header">
                <div>
                    <h2 id="campaign-report-meta-title">Meta Ads 投放成效</h2>
                    <p class="campaign-report-secondary">
                        活動 ID：<code>{{ $target->meta_campaign_id ?? '尚未設定' }}</code>
                    </p>
                </div>
                @if ($metaMarketingConnection && $metaMarketingConnection->ad_account_id && $target->meta_campaign_id && $target->starts_on && $target->ends_on && (! $metaMarketingConnection->token_expires_at || $metaMarketingConnection->token_expires_at->isFuture()))
                    <form action="{{ route('social-engagement-targets.meta-marketing.sync', $target) }}" method="POST" class="ga4-sync-actions">
                        @csrf
                        <button class="button button-secondary button-small" type="submit">更新 Meta Ads 數據</button>
                    </form>
                @endif
            </div>

            @if (! $metaMarketingConnection)
                <p class="campaign-report-empty">尚未連結 Meta Ads。<a href="{{ route('integrations.meta-marketing.edit') }}">設定 Meta Ads</a></p>
            @elseif ($metaMarketingConnection->token_expires_at && $metaMarketingConnection->token_expires_at->isPast())
                <p class="campaign-report-empty">Meta 授權已到期。<a href="{{ route('integrations.meta-marketing.edit') }}">重新連結帳戶</a></p>
            @elseif (! $metaMarketingConnection->ad_account_id)
                <p class="campaign-report-empty">尚未設定 Meta 廣告帳戶。<a href="{{ route('integrations.meta-marketing.edit') }}">前往設定</a></p>
            @elseif (! $target->meta_campaign_id)
                <p class="campaign-report-empty">在總覽的活動互動目標設定 Meta 活動 ID，即可同步投放成效。</p>
            @elseif (! $target->starts_on || ! $target->ends_on)
                <p class="campaign-report-empty">設定活動開始日與結束日後，即可讀取相同期間的 Meta Ads 成效。</p>
            @elseif (! $metaMarketingReport)
                <p class="campaign-report-empty">尚無 Meta Ads 讀值。按「更新 Meta Ads 數據」開始同步。</p>
            @endif

            @if ($metaMarketingReport)
                <p class="campaign-report-secondary">
                    {{ $metaMarketingReport->campaign_name }} · {{ $metaMarketingReport->currency_code }}；
                    更新於 <time datetime="{{ $metaMarketingReport->synced_at->toIso8601String() }}">{{ $metaMarketingReport->synced_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                </p>
                @if ($metaMarketingReportIsStale)
                    <p class="campaign-report-empty">Meta Ads 超過 24 小時未成功更新。</p>
                @endif
                <dl class="campaign-report-summary ga4-report-summary">
                    <div>
                        <dt>投放花費</dt>
                        <dd>{{ number_format((float) $metaMarketingReport->spend_amount, 4) }} {{ $metaMarketingReport->currency_code }}</dd>
                    </div>
                    <div>
                        <dt>曝光</dt>
                        <dd>{{ number_format($metaMarketingReport->impressions) }}</dd>
                    </div>
                    <div>
                        <dt>觸及</dt>
                        <dd>{{ number_format($metaMarketingReport->reach) }}</dd>
                    </div>
                    <div>
                        <dt>Meta Ads 頻率</dt>
                        <dd>{{ $adSpendAnalysis['meta_ads_frequency'] === null ? '—' : number_format($adSpendAnalysis['meta_ads_frequency'], 2).' 次／人' }}</dd>
                    </div>
                    <div>
                        <dt>點擊</dt>
                        <dd>{{ number_format($metaMarketingReport->clicks) }}</dd>
                    </div>
                    <div>
                        <dt>Meta Ads CTR</dt>
                        <dd>{{ $adSpendAnalysis['meta_ads_ctr_percent'] === null ? '—' : number_format($adSpendAnalysis['meta_ads_ctr_percent'], 2).'%' }}</dd>
                    </div>
                    <div>
                        <dt>Meta Ads CPC</dt>
                        <dd>{{ $adSpendAnalysis['meta_ads_cost_per_click'] === null ? '—' : number_format($adSpendAnalysis['meta_ads_cost_per_click'], 4).' '.$metaMarketingReport->currency_code }}</dd>
                    </div>
                    <div>
                        <dt>Meta Ads CPM</dt>
                        <dd>{{ $adSpendAnalysis['meta_ads_cost_per_thousand_impressions'] === null ? '—' : number_format($adSpendAnalysis['meta_ads_cost_per_thousand_impressions'], 4).' '.$metaMarketingReport->currency_code }}</dd>
                    </div>
                </dl>
                <p class="campaign-report-secondary">數據來自已授權廣告帳戶；CTR 以平台點擊除以曝光計算，CPC 與 CPM 使用此活動的花費。</p>

                <div class="campaign-report-section-header">
                    <h3 id="meta-marketing-trend-title">Meta Ads 歷史讀值</h3>
                    @if ($metaMarketingTrend !== [])
                        <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.paid-media.history', [$target, 'meta-marketing']) }}">匯出趨勢 CSV</a>
                    @endif
                </div>
                @if ($metaMarketingTrend === [])
                    <p class="campaign-report-empty">下一次成功同步後會開始累積。</p>
                @else
                    <p class="campaign-report-secondary">括號是與前次讀值的差異；不同同步間隔不代表單日成效。最多顯示最近 30 次。</p>
                    <div class="data-table-wrap" role="region" aria-labelledby="meta-marketing-trend-title" tabindex="0">
                        <table class="data-table campaign-report-table">
                            <caption>Meta Ads 最近 30 次累計成效讀值與前次差異</caption>
                            <thead>
                                <tr>
                                    <th scope="col">讀值時間</th>
                                    <th scope="col">花費</th>
                                    <th scope="col">曝光</th>
                                    <th scope="col">觸及</th>
                                    <th scope="col">頻率</th>
                                    <th scope="col">點擊</th>
                                    <th scope="col">CTR</th>
                                    <th scope="col">CPC</th>
                                    <th scope="col">CPM</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($metaMarketingTrend as $snapshot)
                                    <tr>
                                        <th scope="row">
                                            <time datetime="{{ $snapshot['synced_at']->toIso8601String() }}">{{ $snapshot['synced_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                        </th>
                                        <td class="numeric">
                                            {{ number_format((float) $snapshot['spend_amount'], 2) }} {{ $snapshot['currency_code'] }}
                                            <span class="campaign-report-secondary">({{ ! $snapshot['has_previous_snapshot'] ? '基準' : (! $snapshot['currency_comparable'] ? '幣別不同' : (($snapshot['spend_change'] > 0 ? '+' : '').number_format($snapshot['spend_change'], 2))) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ number_format($snapshot['impressions']) }}
                                            <span class="campaign-report-secondary">({{ $snapshot['impressions_change'] === null ? '基準' : ($snapshot['impressions_change'] > 0 ? '+' : '').number_format($snapshot['impressions_change']) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ number_format($snapshot['reach'] ?? 0) }}
                                            <span class="campaign-report-secondary">({{ $snapshot['reach_change'] === null ? ($snapshot['has_previous_snapshot'] ? '無法比較' : '基準') : ($snapshot['reach_change'] > 0 ? '+' : '').number_format($snapshot['reach_change']) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ $snapshot['frequency'] === null ? '—' : number_format($snapshot['frequency'], 2).' 次／人' }}
                                            <span class="campaign-report-secondary">({{ $snapshot['frequency_change'] === null ? ($snapshot['has_previous_snapshot'] ? '無法比較' : '基準') : ($snapshot['frequency_change'] > 0 ? '+' : '').number_format($snapshot['frequency_change'], 2) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ number_format($snapshot['clicks']) }}
                                            <span class="campaign-report-secondary">({{ $snapshot['clicks_change'] === null ? '基準' : ($snapshot['clicks_change'] > 0 ? '+' : '').number_format($snapshot['clicks_change']) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ $snapshot['ctr_percent'] === null ? '—' : number_format($snapshot['ctr_percent'], 2).'%' }}
                                            <span class="campaign-report-secondary">({{ $snapshot['ctr_change_percentage_points'] === null ? ($snapshot['has_previous_snapshot'] ? '無法比較' : '基準') : ($snapshot['ctr_change_percentage_points'] > 0 ? '+' : '').number_format($snapshot['ctr_change_percentage_points'], 2).' pp' }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ $snapshot['cost_per_click'] === null ? '—' : number_format($snapshot['cost_per_click'], 4).' '.$snapshot['currency_code'] }}
                                            <span class="campaign-report-secondary">({{ ! $snapshot['has_previous_snapshot'] ? '基準' : (! $snapshot['currency_comparable'] ? '幣別不同' : ($snapshot['cost_per_click_change'] === null ? '無法比較' : ($snapshot['cost_per_click_change'] > 0 ? '+' : '').number_format($snapshot['cost_per_click_change'], 4).' '.$snapshot['currency_code'])) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ $snapshot['cost_per_thousand_impressions'] === null ? '—' : number_format($snapshot['cost_per_thousand_impressions'], 4).' '.$snapshot['currency_code'] }}
                                            <span class="campaign-report-secondary">({{ ! $snapshot['has_previous_snapshot'] ? '基準' : (! $snapshot['currency_comparable'] ? '幣別不同' : ($snapshot['cost_per_thousand_impressions_change'] === null ? '無法比較' : ($snapshot['cost_per_thousand_impressions_change'] > 0 ? '+' : '').number_format($snapshot['cost_per_thousand_impressions_change'], 4).' '.$snapshot['currency_code'])) }})</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </section>

        <section class="campaign-report-panel" aria-labelledby="campaign-report-google-ads-title">
            <div class="campaign-report-section-header">
                <div>
                    <h2 id="campaign-report-google-ads-title">Google Ads 投放成效</h2>
                    <p class="campaign-report-secondary">
                        活動 ID：<code>{{ $target->google_ads_campaign_id ?? '尚未設定' }}</code>
                    </p>
                </div>
                @if ($googleAdsConnection && $googleAdsConnection->customer_id && $target->google_ads_campaign_id && $target->starts_on && $target->ends_on)
                    <form action="{{ route('social-engagement-targets.google-ads.sync', $target) }}" method="POST" class="ga4-sync-actions">
                        @csrf
                        <button class="button button-secondary button-small" type="submit">更新 Google Ads 數據</button>
                    </form>
                @endif
            </div>

            @if (! $googleAdsConnection)
                <p class="campaign-report-empty">尚未連結 Google Ads。<a href="{{ route('integrations.google-ads.edit') }}">設定 Google Ads</a></p>
            @elseif (! $googleAdsConnection->customer_id)
                <p class="campaign-report-empty">尚未設定 Google Ads 客戶帳戶。<a href="{{ route('integrations.google-ads.edit') }}">前往設定</a></p>
            @elseif (! $target->google_ads_campaign_id)
                <p class="campaign-report-empty">在總覽的活動互動目標設定 Google Ads 活動 ID，即可同步投放成效。</p>
            @elseif (! $target->starts_on || ! $target->ends_on)
                <p class="campaign-report-empty">設定活動開始日與結束日後，即可讀取相同期間的 Google Ads 成效。</p>
            @elseif (! $googleAdsReport)
                <p class="campaign-report-empty">尚無 Google Ads 讀值。按「更新 Google Ads 數據」開始同步。</p>
            @endif

            @if ($googleAdsReport)
                <p class="campaign-report-secondary">
                    {{ $googleAdsReport->campaign_name }} · {{ $googleAdsReport->currency_code }}；
                    更新於 <time datetime="{{ $googleAdsReport->synced_at->toIso8601String() }}">{{ $googleAdsReport->synced_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                </p>
                @if ($googleAdsReportIsStale)
                    <p class="campaign-report-empty">Google Ads 超過 24 小時未成功更新。</p>
                @endif
                <dl class="campaign-report-summary ga4-report-summary">
                    <div>
                        <dt>投放花費</dt>
                        <dd>{{ number_format((float) $googleAdsReport->spend_amount, 4) }} {{ $googleAdsReport->currency_code }}</dd>
                    </div>
                    <div>
                        <dt>曝光</dt>
                        <dd>{{ number_format($googleAdsReport->impressions) }}</dd>
                    </div>
                    <div>
                        <dt>點擊</dt>
                        <dd>{{ number_format($googleAdsReport->clicks) }}</dd>
                    </div>
                    <div>
                        <dt>轉換</dt>
                        <dd>{{ number_format((float) $googleAdsReport->conversions, 2) }}</dd>
                    </div>
                    <div>
                        <dt>轉換價值</dt>
                        <dd>{{ number_format((float) $googleAdsReport->conversions_value, 2) }} {{ $googleAdsReport->currency_code }}</dd>
                    </div>
                    <div>
                        <dt>Google Ads 轉換價值 ROAS</dt>
                        <dd>
                            {{ (float) $googleAdsReport->spend_amount > 0 ? number_format((float) $googleAdsReport->conversions_value / (float) $googleAdsReport->spend_amount, 2).'x' : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt>Google Ads CTR</dt>
                        <dd>{{ $adSpendAnalysis['google_ads_ctr_percent'] === null ? '—' : number_format($adSpendAnalysis['google_ads_ctr_percent'], 2).'%' }}</dd>
                    </div>
                    <div>
                        <dt>Google Ads CPC</dt>
                        <dd>{{ $adSpendAnalysis['google_ads_cost_per_click'] === null ? '—' : number_format($adSpendAnalysis['google_ads_cost_per_click'], 4).' '.$googleAdsReport->currency_code }}</dd>
                    </div>
                    <div>
                        <dt>Google Ads CPM</dt>
                        <dd>{{ $adSpendAnalysis['google_ads_cost_per_thousand_impressions'] === null ? '—' : number_format($adSpendAnalysis['google_ads_cost_per_thousand_impressions'], 4).' '.$googleAdsReport->currency_code }}</dd>
                    </div>
                    <div>
                        <dt>Google Ads 每次轉換成本</dt>
                        <dd>{{ $adSpendAnalysis['google_ads_cost_per_conversion'] === null ? '—' : number_format($adSpendAnalysis['google_ads_cost_per_conversion'], 4).' '.$googleAdsReport->currency_code }}</dd>
                    </div>
                    <div>
                        <dt>Google Ads 點擊轉換率</dt>
                        <dd>{{ $adSpendAnalysis['google_ads_conversion_rate_percent'] === null ? '—' : number_format($adSpendAnalysis['google_ads_conversion_rate_percent'], 2).'%' }}</dd>
                    </div>
                </dl>
                <p class="campaign-report-secondary">CTR、CPC 與 CPM 依 Google Ads 點擊、曝光及花費計算；轉換成本與點擊轉換率依帳戶轉換設定歸因。日期區間依 Google Ads 帳戶時區計算。</p>

                <div class="campaign-report-section-header">
                    <h3 id="google-ads-trend-title">Google Ads 歷史讀值</h3>
                    @if ($googleAdsTrend !== [])
                        <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.paid-media.history', [$target, 'google-ads']) }}">匯出趨勢 CSV</a>
                    @endif
                </div>
                @if ($googleAdsTrend === [])
                    <p class="campaign-report-empty">下一次成功同步後會開始累積。</p>
                @else
                    <p class="campaign-report-secondary">括號是與前次讀值的差異；不同同步間隔不代表單日成效。最多顯示最近 30 次。</p>
                    <div class="data-table-wrap" role="region" aria-labelledby="google-ads-trend-title" tabindex="0">
                        <table class="data-table campaign-report-table">
                            <caption>Google Ads 最近 30 次累計成效讀值與前次差異</caption>
                            <thead>
                                <tr>
                                    <th scope="col">讀值時間</th>
                                    <th scope="col">花費</th>
                                    <th scope="col">曝光</th>
                                    <th scope="col">點擊</th>
                                    <th scope="col">轉換</th>
                                    <th scope="col">轉換價值</th>
                                    <th scope="col">CTR</th>
                                    <th scope="col">CPC</th>
                                    <th scope="col">CPM</th>
                                    <th scope="col">每次轉換成本</th>
                                    <th scope="col">點擊轉換率</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($googleAdsTrend as $snapshot)
                                    <tr>
                                        <th scope="row">
                                            <time datetime="{{ $snapshot['synced_at']->toIso8601String() }}">{{ $snapshot['synced_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                        </th>
                                        <td class="numeric">
                                            {{ number_format((float) $snapshot['spend_amount'], 2) }} {{ $snapshot['currency_code'] }}
                                            <span class="campaign-report-secondary">({{ ! $snapshot['has_previous_snapshot'] ? '基準' : (! $snapshot['currency_comparable'] ? '幣別不同' : (($snapshot['spend_change'] > 0 ? '+' : '').number_format($snapshot['spend_change'], 2))) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ number_format($snapshot['impressions']) }}
                                            <span class="campaign-report-secondary">({{ $snapshot['impressions_change'] === null ? '基準' : ($snapshot['impressions_change'] > 0 ? '+' : '').number_format($snapshot['impressions_change']) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ number_format($snapshot['clicks']) }}
                                            <span class="campaign-report-secondary">({{ $snapshot['clicks_change'] === null ? '基準' : ($snapshot['clicks_change'] > 0 ? '+' : '').number_format($snapshot['clicks_change']) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ number_format((float) $snapshot['conversions'], 2) }}
                                            <span class="campaign-report-secondary">({{ $snapshot['conversions_change'] === null ? '基準' : ($snapshot['conversions_change'] > 0 ? '+' : '').number_format($snapshot['conversions_change'], 2) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ number_format((float) $snapshot['conversions_value'], 2) }} {{ $snapshot['currency_code'] }}
                                            <span class="campaign-report-secondary">({{ ! $snapshot['has_previous_snapshot'] ? '基準' : (! $snapshot['currency_comparable'] ? '幣別不同' : (($snapshot['conversions_value_change'] > 0 ? '+' : '').number_format($snapshot['conversions_value_change'], 2))) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ $snapshot['ctr_percent'] === null ? '—' : number_format($snapshot['ctr_percent'], 2).'%' }}
                                            <span class="campaign-report-secondary">({{ $snapshot['ctr_change_percentage_points'] === null ? ($snapshot['has_previous_snapshot'] ? '無法比較' : '基準') : ($snapshot['ctr_change_percentage_points'] > 0 ? '+' : '').number_format($snapshot['ctr_change_percentage_points'], 2).' pp' }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ $snapshot['cost_per_click'] === null ? '—' : number_format($snapshot['cost_per_click'], 4).' '.$snapshot['currency_code'] }}
                                            <span class="campaign-report-secondary">({{ ! $snapshot['has_previous_snapshot'] ? '基準' : (! $snapshot['currency_comparable'] ? '幣別不同' : ($snapshot['cost_per_click_change'] === null ? '無法比較' : ($snapshot['cost_per_click_change'] > 0 ? '+' : '').number_format($snapshot['cost_per_click_change'], 4).' '.$snapshot['currency_code'])) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ $snapshot['cost_per_thousand_impressions'] === null ? '—' : number_format($snapshot['cost_per_thousand_impressions'], 4).' '.$snapshot['currency_code'] }}
                                            <span class="campaign-report-secondary">({{ ! $snapshot['has_previous_snapshot'] ? '基準' : (! $snapshot['currency_comparable'] ? '幣別不同' : ($snapshot['cost_per_thousand_impressions_change'] === null ? '無法比較' : ($snapshot['cost_per_thousand_impressions_change'] > 0 ? '+' : '').number_format($snapshot['cost_per_thousand_impressions_change'], 4).' '.$snapshot['currency_code'])) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ $snapshot['cost_per_conversion'] === null ? '—' : number_format($snapshot['cost_per_conversion'], 4).' '.$snapshot['currency_code'] }}
                                            <span class="campaign-report-secondary">({{ ! $snapshot['has_previous_snapshot'] ? '基準' : (! $snapshot['currency_comparable'] ? '幣別不同' : ($snapshot['cost_per_conversion_change'] === null ? '無法比較' : ($snapshot['cost_per_conversion_change'] > 0 ? '+' : '').number_format($snapshot['cost_per_conversion_change'], 4).' '.$snapshot['currency_code'])) }})</span>
                                        </td>
                                        <td class="numeric">
                                            {{ $snapshot['conversion_rate_percent'] === null ? '—' : number_format($snapshot['conversion_rate_percent'], 2).'%' }}
                                            <span class="campaign-report-secondary">({{ $snapshot['conversion_rate_change_percentage_points'] === null ? ($snapshot['has_previous_snapshot'] ? '無法比較' : '基準') : ($snapshot['conversion_rate_change_percentage_points'] > 0 ? '+' : '').number_format($snapshot['conversion_rate_change_percentage_points'], 2).' pp' }})</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
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
                    更新於 <time datetime="{{ $googleAnalyticsReport->synced_at->toIso8601String() }}">{{ $googleAnalyticsReport->synced_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                </p>
                @if ($googleAnalyticsReportIsStale)
                    <p class="campaign-report-empty">GA4 超過 24 小時未成功更新。</p>
                @endif
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

        <section class="campaign-report-panel" aria-labelledby="campaign-report-efficiency-title">
            <h2 id="campaign-report-efficiency-title">投放成本效率</h2>
            <dl class="campaign-report-summary campaign-efficiency-summary">
                <div>
                    <dt>投放花費（{{ $adSpendAnalysis['ad_spend_source_label'] ?? '未設定' }}）</dt>
                    <dd>
                        @if ($adSpendAnalysis['ad_spend_amount'] === null)
                            —
                        @else
                            {{ number_format((float) $adSpendAnalysis['ad_spend_amount'], 4) }} {{ $adSpendAnalysis['ad_spend_currency'] }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>GA4 營收 ROAS</dt>
                    <dd>{{ $adSpendAnalysis['roas'] === null ? '—' : number_format($adSpendAnalysis['roas'], 2).'x' }}</dd>
                </div>
                <div>
                    <dt>每工作階段成本</dt>
                    <dd>
                        {{ $adSpendAnalysis['cost_per_session'] === null ? '—' : number_format($adSpendAnalysis['cost_per_session'], 4).' '.$adSpendAnalysis['ad_spend_currency'] }}
                    </dd>
                </div>
                <div>
                    <dt>每重要事件成本</dt>
                    <dd>
                        {{ $adSpendAnalysis['cost_per_key_event'] === null ? '—' : number_format($adSpendAnalysis['cost_per_key_event'], 4).' '.$adSpendAnalysis['ad_spend_currency'] }}
                    </dd>
                </div>
                <div>
                    <dt>每公開互動成本</dt>
                    <dd>
                        {{ $adSpendAnalysis['cost_per_public_interaction'] === null ? '—' : number_format($adSpendAnalysis['cost_per_public_interaction'], 4).' '.$adSpendAnalysis['ad_spend_currency'] }}
                    </dd>
                </div>
            </dl>
            @if ($adSpendAnalysis['roas'] === null)
                <p class="campaign-report-secondary">
                    {{ match ($adSpendAnalysis['roas_unavailable_reason']) {
                        'missing_spend' => '同步 Meta Ads 或輸入投放花費後即可計算 ROAS。',
                        'zero_spend' => '投放花費須大於 0 才可計算 ROAS。',
                        'missing_ga4' => '同步 GA4 活動數據後即可計算 ROAS。',
                        'missing_spend_currency' => '請輸入花費幣別後再計算 ROAS。',
                        'missing_ga4_currency' => 'GA4 未提供營收幣別，暫不計算 ROAS。',
                        'currency_mismatch' => '花費與 GA4 營收幣別不同，暫不計算 ROAS。',
                        'ad_platform_currency_mismatch' => 'Meta Ads 與 Google Ads 的花費幣別不同，未合併花費或計算 ROAS。',
                        default => '目前無法計算 ROAS。',
                    } }}
                </p>
            @endif
            <p class="campaign-report-secondary">ROAS 為同幣別 GA4 活動營收除以投放花費，不扣除商品成本；Meta Ads 與 Google Ads 讀值會合併，同幣別才計入總花費，尚無 API 讀值時使用人工記錄花費。</p>

            <form action="{{ route('social-engagement-targets.ad-spend.update', $target) }}" method="POST" class="campaign-spend-form" aria-labelledby="campaign-spend-form-title">
                @csrf
                @method('PUT')
                <h3 id="campaign-spend-form-title">設定活動投放花費</h3>
                <div class="field-group">
                    <label class="field-label" for="campaign-ad-spend-amount">花費金額</label>
                    <input class="field-input{{ $errors->getBag('socialEngagementSpend')->has('ad_spend_amount') ? ' is-invalid' : '' }}" id="campaign-ad-spend-amount" name="ad_spend_amount" type="number" inputmode="decimal" min="0" max="999999999999999.9999" step="0.0001" value="{{ old('ad_spend_amount', $target->ad_spend_amount) }}" aria-describedby="campaign-ad-spend-amount-help{{ $errors->getBag('socialEngagementSpend')->has('ad_spend_amount') ? ' campaign-ad-spend-amount-error' : '' }}" @if ($errors->getBag('socialEngagementSpend')->has('ad_spend_amount')) aria-invalid="true" @endif>
                    <p id="campaign-ad-spend-amount-help" class="field-help">最多 4 位小數；兩欄留白可清除花費。Meta Ads 或 Google Ads 有讀值時會優先使用 API 花費。</p>
                    @error('ad_spend_amount', 'socialEngagementSpend')<p id="campaign-ad-spend-amount-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="field-group">
                    <label class="field-label" for="campaign-ad-spend-currency">花費幣別</label>
                    <input class="field-input{{ $errors->getBag('socialEngagementSpend')->has('ad_spend_currency') ? ' is-invalid' : '' }}" id="campaign-ad-spend-currency" name="ad_spend_currency" type="text" minlength="3" maxlength="3" pattern="[A-Za-z]{3}" autocapitalize="characters" autocomplete="off" value="{{ old('ad_spend_currency', $target->ad_spend_currency) }}" aria-describedby="campaign-ad-spend-currency-help{{ $errors->getBag('socialEngagementSpend')->has('ad_spend_currency') ? ' campaign-ad-spend-currency-error' : '' }}" @if ($errors->getBag('socialEngagementSpend')->has('ad_spend_currency')) aria-invalid="true" @endif>
                    <p id="campaign-ad-spend-currency-help" class="field-help">輸入 3 個英文字母，例如 TWD、USD；兩欄留白可清除。</p>
                    @error('ad_spend_currency', 'socialEngagementSpend')<p id="campaign-ad-spend-currency-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="campaign-spend-form-actions">
                    <button class="button button-primary" type="submit">儲存花費</button>
                </div>
            </form>
        </section>

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
