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
                <a class="button button-secondary" href="{{ route('social-engagement-targets.report.export', $target) }}">匯出 CSV</a>
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

        @php
            $goalForecast = $report['goal_forecast'];
            $goalForecastBadgeState = match ($goalForecast['status']) {
                'on_track', 'achieved' => 'healthy',
                'at_risk' => 'issue',
                'insufficient_data', 'missing_period' => 'warning',
                default => 'stale',
            };
        @endphp
        <section class="campaign-report-panel" aria-labelledby="campaign-report-goal-forecast-title">
            <div class="campaign-report-section-header">
                <div>
                    <h2 id="campaign-report-goal-forecast-title">互動目標預測</h2>
                    <p class="campaign-report-secondary">依各貼文活動期間首末完整讀值的每日淨變化，線性推估活動結束時的互動總數。</p>
                </div>
                <span class="badge badge-{{ $goalForecastBadgeState }}">{{ $goalForecast['status_label'] }}</span>
            </div>
            @if ($goalForecast['projected_interaction_total'] !== null)
                <dl class="campaign-report-summary campaign-efficiency-summary">
                    <div>
                        <dt>推估期末互動</dt>
                        <dd>{{ number_format($goalForecast['projected_interaction_total']) }}</dd>
                    </div>
                    <div>
                        <dt>推估目標達成率</dt>
                        <dd>{{ number_format($goalForecast['projected_completion_percent'], 1) }}%</dd>
                    </div>
                    <div>
                        <dt>平均每日淨變化</dt>
                        <dd>{{ number_format($goalForecast['average_daily_interaction_change'], 1) }}</dd>
                    </div>
                    <div>
                        <dt>推估可信度</dt>
                        <dd>{{ $goalForecast['confidence_label'] }}</dd>
                    </div>
                </dl>
            @else
                <p class="campaign-report-empty">
                    @switch($goalForecast['status'])
                        @case('missing_period')
                            設定有效的活動開始日與結束日後即可推估。
                            @break
                        @case('not_started')
                            活動開始後，累積可比較的公開互動讀值即可推估。
                            @break
                        @case('ended')
                            活動已結束，請參考目前互動總數與歷史變化。
                            @break
                        @case('achieved')
                            目前公開互動數已達目標。
                            @break
                        @default
                            至少需 3 篇貼文有相隔一天以上的完整讀值、樣本覆蓋達 50%、近 24 小時完整讀值覆蓋達 80%。
                    @endswitch
                </p>
            @endif
            <p class="campaign-report-secondary">
                可比較 {{ number_format($goalForecast['comparable_post_count']) }}／{{ number_format($report['tracked_post_count']) }} 篇
                （{{ $goalForecast['sample_coverage_percent'] === null ? '—' : number_format($goalForecast['sample_coverage_percent'], 1).'%' }}）
                · 近 24 小時完整讀值覆蓋 {{ $goalForecast['fresh_read_coverage_percent'] === null ? '—' : number_format($goalForecast['fresh_read_coverage_percent'], 1).'%' }}
                @if ($goalForecast['average_daily_interaction_change'] !== null)
                    · 平均每日淨變化 {{ number_format($goalForecast['average_daily_interaction_change'], 1) }}
                @endif
                @if ($goalForecast['latest_observed_at'] !== null)
                    · 最近讀值 <time datetime="{{ $goalForecast['latest_observed_at']->toIso8601String() }}">{{ $goalForecast['latest_observed_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                @endif
            </p>
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
                    <div>
                        <dt>購買轉換價值</dt>
                        <dd>{{ $adSpendAnalysis['meta_ads_omni_purchase_value'] === null ? '—' : number_format((float) $adSpendAnalysis['meta_ads_omni_purchase_value'], 4).' '.($metaMarketingReport->currency_code ?: '幣別未知') }}</dd>
                    </div>
                    <div>
                        <dt>Meta Ads 購買 ROAS</dt>
                        <dd>{{ $adSpendAnalysis['meta_ads_purchase_roas'] === null ? '—' : number_format($adSpendAnalysis['meta_ads_purchase_roas'], 2).'x' }}</dd>
                    </div>
                </dl>
                <p class="campaign-report-secondary">CTR 以平台點擊除以曝光計算，CPC 與 CPM 使用此活動花費；購買 ROAS 只採用 <code>omni_purchase</code>，不混加其他可能重疊的行動類型。</p>

                <h3 id="meta-marketing-actions-title">Meta Ads 行動成效</h3>
                <p class="campaign-report-secondary">依廣告帳戶追蹤設定與歸因列出原始行動類型；不同類型可能重疊，因此不合併成單一轉換總數。</p>
                @if (($metaMarketingReport->actions ?? []) === [] && ($metaMarketingReport->action_values ?? []) === [])
                    <p class="campaign-report-empty">Meta 尚未回傳行動數據。</p>
                @else
                    @if (($metaMarketingReport->actions ?? []) !== [])
                        <div class="data-table-wrap" role="region" aria-labelledby="meta-marketing-actions-title" tabindex="0">
                            <table class="data-table campaign-report-table">
                                <caption>Meta Ads 原始行動類型與歸因數量</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">行動類型</th>
                                        <th scope="col">數量</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($metaMarketingReport->actions as $action)
                                        <tr>
                                            <th scope="row"><code>{{ $action['action_type'] }}</code></th>
                                            <td class="numeric">{{ number_format((float) $action['value'], 4) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if (($metaMarketingReport->action_values ?? []) !== [])
                        <div class="data-table-wrap spaced-top" role="region" aria-labelledby="meta-marketing-actions-title" tabindex="0">
                            <table class="data-table campaign-report-table">
                                <caption>Meta Ads 原始行動類型與轉換價值（{{ $metaMarketingReport->currency_code }}）</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">行動類型</th>
                                        <th scope="col">價值</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($metaMarketingReport->action_values as $actionValue)
                                        <tr>
                                            <th scope="row"><code>{{ $actionValue['action_type'] }}</code></th>
                                            <td class="numeric">{{ number_format((float) $actionValue['value'], 4) }} {{ $metaMarketingReport->currency_code }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif

                <div class="campaign-report-section-header">
                    <h3 id="meta-marketing-trend-title">Meta Ads 歷史讀值</h3>
                    @if ($metaMarketingTrend !== [])
                        <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.paid-media.history', [$target, 'meta-marketing']) }}">匯出趨勢 CSV</a>
                    @endif
                </div>
                @if ($metaMarketingTrend === [])
                    <p class="campaign-report-empty">下一次成功同步後會開始累積。</p>
                @else
                    <p class="campaign-report-secondary">括號是與前次讀值的差異；不同同步間隔不代表單日成效。Meta 購買 ROAS 以 omni_purchase 轉換價值除以花費；幣別不同時不比較 ROAS 差異。最多顯示最近 30 次。</p>
                    <div class="data-table-wrap" role="region" aria-labelledby="meta-marketing-trend-title" tabindex="0">
                        <table class="data-table campaign-report-table">
                            <caption>Meta Ads 最近 30 次累計成效讀值與前次差異</caption>
                            <thead>
                                <tr>
                                    <th scope="col">讀值時間</th>
                                    <th scope="col">花費</th>
                                    <th scope="col">購買 ROAS</th>
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
                                            {{ $snapshot['roas'] === null ? '—' : number_format($snapshot['roas'], 2).'x' }}
                                            <span class="campaign-report-secondary">({{ ! $snapshot['has_previous_snapshot'] ? '基準' : (! $snapshot['currency_comparable'] ? '幣別不同' : ($snapshot['roas_change'] === null ? '無法比較' : ($snapshot['roas_change'] > 0 ? '+' : '').number_format($snapshot['roas_change'], 2).'x')) }})</span>
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
                @if ($googleAdsConnection && $googleAdsConnection->customer_id)
                    <div class="campaign-report-actions">
                        <form action="{{ route('social-engagement-targets.google-ads.campaign-options', $target) }}" method="POST">
                            @csrf
                            <button class="button button-secondary button-small" type="submit">載入活動清單</button>
                        </form>
                        @if ($target->google_ads_campaign_id && $target->starts_on && $target->ends_on)
                            <form action="{{ route('social-engagement-targets.google-ads.sync', $target) }}" method="POST">
                                @csrf
                                <button class="button button-secondary button-small" type="submit">更新 Google Ads 數據</button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            @if (! $googleAdsConnection)
                <p class="campaign-report-empty">尚未連結 Google Ads。<a href="{{ route('integrations.google-ads.edit') }}">設定 Google Ads</a></p>
            @elseif (! $googleAdsConnection->customer_id)
                <p class="campaign-report-empty">尚未設定 Google Ads 客戶帳戶。<a href="{{ route('integrations.google-ads.edit') }}">前往設定</a></p>
            @elseif (! $target->google_ads_campaign_id)
                <p class="campaign-report-empty">載入活動清單選取活動，或在總覽輸入 Google Ads 活動 ID。</p>
            @elseif (! $target->starts_on || ! $target->ends_on)
                <p class="campaign-report-empty">設定活動開始日與結束日後，即可讀取相同期間的 Google Ads 成效。</p>
            @elseif (! $googleAdsReport)
                <p class="campaign-report-empty">尚無 Google Ads 讀值。按「更新 Google Ads 數據」開始同步。</p>
            @endif

            @if ($googleAdsCampaignOptions !== null)
                <h3 id="google-ads-campaign-picker-title">選擇 Google Ads 活動</h3>
                @if ($googleAdsCampaignOptions === [])
                    <p class="campaign-report-empty">此客戶帳戶沒有可選的 Google Ads 活動。</p>
                @else
                    @php
                        $currentCampaignIsAvailable = collect($googleAdsCampaignOptions)->contains('campaign_id', (string) $target->google_ads_campaign_id);
                    @endphp
                    <form action="{{ route('social-engagement-targets.google-ads.campaign.update', $target) }}" method="POST" class="check-history-filter" aria-labelledby="google-ads-campaign-picker-title">
                        @csrf
                        @method('PUT')
                        <div class="field-group">
                            <label class="field-label" for="google-ads-campaign-picker">廣告活動</label>
                            <select class="field-select" id="google-ads-campaign-picker" name="google_ads_campaign_id" required aria-describedby="google-ads-campaign-picker-help">
                                <option value="" disabled @selected(! $currentCampaignIsAvailable)>
                                    @if ($target->google_ads_campaign_id && ! $currentCampaignIsAvailable)
                                        目前 ID {{ $target->google_ads_campaign_id }} 不在活動清單中，請改選
                                    @else
                                        請選擇活動
                                    @endif
                                </option>
                                @foreach ($googleAdsCampaignOptions as $campaignOption)
                                    <option value="{{ $campaignOption['campaign_id'] }}" @selected($target->google_ads_campaign_id === $campaignOption['campaign_id'])>
                                        {{ $campaignOption['campaign_name'] }} · {{ $campaignOption['campaign_id'] }} · {{ $campaignOption['status'] }} · {{ $campaignOption['channel_type'] }}
                                    </option>
                                @endforeach
                            </select>
                            <p id="google-ads-campaign-picker-help" class="field-help">清單包含最多 500 個未移除活動。套用後，這個活動目標將改用所選活動。</p>
                        </div>
                        <button class="button button-secondary button-small" type="submit">套用活動</button>
                    </form>
                @endif
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
                        <dd>{{ $googleAdsReport->conversions_value === null ? '—' : number_format((float) $googleAdsReport->conversions_value, 2).' '.($googleAdsReport->currency_code ?: '幣別未知') }}</dd>
                    </div>
                    <div>
                        <dt>Google Ads 轉換價值 ROAS</dt>
                        <dd>
                            {{ $adSpendAnalysis['google_ads_conversion_value_roas'] === null ? '—' : number_format($adSpendAnalysis['google_ads_conversion_value_roas'], 2).'x' }}
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

                <h3 id="google-ads-conversion-actions-title">Google Ads 轉換動作</h3>
                <p class="campaign-report-secondary">依 Google Ads 轉換設定分列；零轉換動作不會列出，總計以此頁上方的轉換與轉換價值為準。</p>
                @if (($googleAdsReport->conversion_actions ?? []) === [])
                    <p class="campaign-report-empty">Google Ads 尚未回傳轉換動作明細。</p>
                @else
                    <div class="data-table-wrap" role="region" aria-labelledby="google-ads-conversion-actions-title" tabindex="0">
                        <table class="data-table campaign-report-table">
                            <caption>Google Ads 轉換動作、轉換數與轉換價值</caption>
                            <thead>
                                <tr>
                                    <th scope="col">轉換動作</th>
                                    <th scope="col">轉換</th>
                                    <th scope="col">轉換價值（{{ $googleAdsReport->currency_code }}）</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($googleAdsReport->conversion_actions as $conversionAction)
                                    <tr>
                                        <th scope="row">{{ $conversionAction['conversion_action_name'] }}</th>
                                        <td class="numeric">{{ number_format((float) $conversionAction['conversions'], 2) }}</td>
                                        <td class="numeric">{{ number_format((float) $conversionAction['conversion_value'], 2) }} {{ $googleAdsReport->currency_code }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="campaign-report-section-header">
                    <h3 id="google-ads-search-terms-title">Google Ads 搜尋字詞</h3>
                    @if ($googleAdsSearchTermReport['status'] === 'complete' && $googleAdsSearchTermReport['rows'] !== [])
                        <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.google-ads.search-terms', $target) }}">匯出搜尋字詞 CSV</a>
                    @endif
                </div>
                @if ($googleAdsSearchTermReport['status'] === 'complete')
                    <p class="campaign-report-secondary">
                        最近 30 個完整日（{{ $googleAdsSearchTermReport['window_start_on'] }} 至 {{ $googleAdsSearchTermReport['window_end_on'] }}）。Google Ads 會隱去低量查詢；最多列出花費最高的 200 筆，未必涵蓋全部搜尋字詞。
                    </p>
                    @if ($googleAdsSearchTermReport['truncated'])
                        <p class="campaign-report-empty">符合條件的搜尋字詞超過 200 筆，表格僅顯示花費最高的 200 筆。</p>
                    @endif
                    @if ($googleAdsSearchTermReport['rows'] === [])
                        <p class="campaign-report-empty">目前沒有可列出的搜尋字詞；低量查詢可能已由 Google Ads 隱去。</p>
                    @else
                        <form class="campaign-report-actions" method="GET" action="{{ route('social-engagement-targets.report', $target) }}">
                            <label class="field-label" for="google-ads-search-terms-sort">排序依據</label>
                            <select class="field-select" id="google-ads-search-terms-sort" name="search_term_sort">
                                <option value="spend" @selected($googleAdsSearchTermReport['sort'] === 'spend')>花費</option>
                                <option value="conversions" @selected($googleAdsSearchTermReport['sort'] === 'conversions')>轉換</option>
                                <option value="roas" @selected($googleAdsSearchTermReport['sort'] === 'roas')>ROAS</option>
                                <option value="ctr" @selected($googleAdsSearchTermReport['sort'] === 'ctr')>CTR</option>
                            </select>
                            <button class="button button-secondary button-small" type="submit">排序</button>
                        </form>
                        <div class="data-table-wrap" role="region" aria-labelledby="google-ads-search-terms-title" tabindex="0">
                            <table class="data-table campaign-report-table">
                                <caption>Google Ads 最近 30 個完整日的搜尋字詞成效</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">搜尋字詞</th>
                                        <th scope="col">廣告群組</th>
                                        <th scope="col" class="numeric">曝光</th>
                                        <th scope="col" class="numeric">點擊</th>
                                        <th scope="col" class="numeric">CTR</th>
                                        <th scope="col" class="numeric">花費</th>
                                        <th scope="col" class="numeric">CPC</th>
                                        <th scope="col" class="numeric">轉換</th>
                                        <th scope="col" class="numeric">CPA</th>
                                        <th scope="col" class="numeric">轉換價值</th>
                                        <th scope="col" class="numeric">ROAS</th>
                                        <th scope="col">判讀</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($googleAdsSearchTermReport['rows'] as $searchTermRow)
                                        <tr>
                                            <th scope="row">{{ $searchTermRow['search_term'] }}</th>
                                            <td>{{ $searchTermRow['ad_group_name'] }}</td>
                                            <td class="numeric">{{ number_format((int) $searchTermRow['impressions']) }}</td>
                                            <td class="numeric">{{ number_format((int) $searchTermRow['clicks']) }}</td>
                                            <td class="numeric">{{ $searchTermRow['ctr_percent'] === null ? '—' : number_format($searchTermRow['ctr_percent'], 2).'%' }}</td>
                                            <td class="numeric">{{ number_format((float) $searchTermRow['spend_amount'], 2) }} {{ $googleAdsSearchTermReport['currency_code'] }}</td>
                                            <td class="numeric">{{ $searchTermRow['cost_per_click'] === null ? '—' : number_format($searchTermRow['cost_per_click'], 4).' '.$googleAdsSearchTermReport['currency_code'] }}</td>
                                            <td class="numeric">{{ number_format((float) $searchTermRow['conversions'], 2) }}</td>
                                            <td class="numeric">{{ $searchTermRow['cost_per_action'] === null ? '—' : number_format($searchTermRow['cost_per_action'], 4).' '.$googleAdsSearchTermReport['currency_code'] }}</td>
                                            <td class="numeric">{{ number_format((float) $searchTermRow['conversions_value'], 2) }} {{ $googleAdsSearchTermReport['currency_code'] }}</td>
                                            <td class="numeric">{{ $searchTermRow['roas'] === null ? '—' : number_format($searchTermRow['roas'], 2).'x' }}</td>
                                            <td>
                                                <span class="badge badge-{{ match ($searchTermRow['diagnostic_status']) {
                                                    'spend_without_conversions' => 'warning',
                                                    'has_conversions' => 'healthy',
                                                    default => 'stale',
                                                } }}">{{ match ($searchTermRow['diagnostic_status']) {
                                                    'spend_without_conversions' => '有花費無轉換',
                                                    'has_conversions' => '已產生轉換',
                                                    default => '尚無花費',
                                                } }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @else
                    <p class="campaign-report-empty">{{ match ($googleAdsSearchTermReport['status']) {
                        'unsupported' => '目前僅支援 Google Ads 搜尋活動。',
                        'no_complete_days' => '尚無完整日期可供搜尋字詞分析。',
                        'unavailable' => '搜尋字詞暫時無法同步，請稍後再更新 Google Ads 數據。',
                        'expired' => '搜尋字詞讀值已過期，請更新 Google Ads 數據。',
                        default => '尚無搜尋字詞讀值；更新 Google Ads 數據即可同步。',
                    } }}</p>
                @endif

                <div class="campaign-report-section-header">
                    <h3 id="google-ads-trend-title">Google Ads 歷史讀值</h3>
                    @if ($googleAdsTrend !== [])
                        <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.paid-media.history', [$target, 'google-ads']) }}">匯出趨勢 CSV</a>
                    @endif
                </div>
                @if ($googleAdsTrend === [])
                    <p class="campaign-report-empty">下一次成功同步後會開始累積。</p>
                @else
                    <p class="campaign-report-secondary">括號是與前次讀值的差異；不同同步間隔不代表單日成效。轉換價值 ROAS 以轉換價值除以花費；幣別不同時不比較 ROAS 差異。最多顯示最近 30 次。</p>
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
                                    <th scope="col">轉換價值 ROAS</th>
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
                                            {{ $snapshot['roas'] === null ? '—' : number_format($snapshot['roas'], 2).'x' }}
                                            <span class="campaign-report-secondary">({{ ! $snapshot['has_previous_snapshot'] ? '基準' : (! $snapshot['currency_comparable'] ? '幣別不同' : ($snapshot['roas_change'] === null ? '無法比較' : ($snapshot['roas_change'] > 0 ? '+' : '').number_format($snapshot['roas_change'], 2).'x')) }})</span>
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
                    @if ($target->googleAnalyticsAttributionMode() === 'google_ads_auto_tagging')
                        <p class="campaign-report-secondary">
                            比對 Google Ads 活動 ID：<code>{{ $target->google_ads_campaign_id }}</code> · 使用 GA4 自動標記歸因
                        </p>
                    @else
                        <p class="campaign-report-secondary">
                            比對活動名稱：<code>{{ $googleAnalyticsCampaignName }}</code>
                            · 來源：<code>{{ $googleAnalyticsSourceName ?? '所有來源' }}</code>
                            · 媒介：<code>{{ $googleAnalyticsMediumName ?? '所有媒介' }}</code>
                        </p>
                    @endif
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
                @if ($googleAnalyticsReport->attribution_mode === 'google_ads_auto_tagging')
                    <p class="campaign-report-secondary">歸因方式：Google Ads 自動標記，活動 ID <code>{{ $googleAnalyticsReport->campaign_id }}</code>；此查詢不套用 UTM 來源或媒介條件。</p>
                @else
                    <p class="campaign-report-secondary">歸因方式：GA4 手動活動名稱與 UTM 條件。</p>
                @endif
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

                <div class="campaign-report-section-header">
                    <h3 id="campaign-report-ga4-channel-title">渠道、來源與媒介成效</h3>
                    @if ($googleAnalyticsReport->source_medium_breakdown !== null && $googleAnalyticsReport->source_medium_breakdown !== [])
                        <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.google-analytics.channels', $target) }}">匯出明細 CSV</a>
                    @endif
                </div>
                @if ($googleAnalyticsReport->source_medium_breakdown === null)
                    <p class="campaign-report-empty">這筆 GA4 讀值尚未包含渠道明細，更新數據後即可查看。</p>
                @elseif ($googleAnalyticsReport->source_medium_breakdown === [])
                    <p class="campaign-report-empty">此活動期間沒有可用的來源／媒介數據。</p>
                @else
                    @if ($googleAnalyticsReport->source_medium_breakdown_truncated)
                        <p class="campaign-report-note">來源／媒介組合超過 10,000 筆，表格僅顯示前 10,000 筆。</p>
                    @endif
                    <div class="data-table-wrap" role="region" aria-labelledby="campaign-report-ga4-channel-title" tabindex="0">
                        <table class="data-table campaign-report-table campaign-report-ga4-channel-table">
                            <caption>GA4 活動渠道、來源與媒介的成效比較</caption>
                            <thead>
                                <tr>
                                    <th scope="col">預設渠道</th>
                                    <th scope="col">來源</th>
                                    <th scope="col">媒介</th>
                                    <th scope="col" class="numeric">工作階段</th>
                                    <th scope="col" class="numeric">參與工作階段</th>
                                    <th scope="col" class="numeric">重要事件</th>
                                    <th scope="col" class="numeric">營收（{{ $googleAnalyticsReport->currency_code ?? '幣別未知' }}）</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($googleAnalyticsReport->source_medium_breakdown as $channel)
                                    <tr>
                                        <th scope="row">{{ $channel['channel_group'] ?? '—' }}</th>
                                        <td>{{ $channel['source_name'] }}</td>
                                        <td>{{ $channel['medium_name'] }}</td>
                                        <td class="numeric">{{ number_format($channel['sessions']) }}</td>
                                        <td class="numeric">{{ number_format($channel['engaged_sessions']) }}</td>
                                        <td class="numeric">{{ number_format((float) $channel['key_events'], 2) }}</td>
                                        <td class="numeric">{{ number_format((float) $channel['total_revenue'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="campaign-report-section-header">
                    <h3 id="campaign-report-ga4-landing-page-title">登陸頁成效</h3>
                    @if ($googleAnalyticsLandingPageReport['status'] === 'complete' && $googleAnalyticsLandingPageReport['rows'] !== [])
                        <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.google-analytics.landing-pages', $target) }}">匯出登陸頁 CSV</a>
                    @endif
                </div>
                @if ($googleAnalyticsLandingPageReport['status'] === 'not_synced')
                    <p class="campaign-report-empty">這筆 GA4 讀值尚未包含登陸頁明細，更新數據後即可查看。</p>
                @elseif ($googleAnalyticsLandingPageReport['status'] === 'thresholded')
                    <p class="campaign-report-empty">GA4 因資料門檻未提供登陸頁數據。</p>
                @elseif ($googleAnalyticsLandingPageReport['status'] === 'unavailable')
                    <p class="campaign-report-empty">目前無法讀取登陸頁數據；活動總成效仍可查看。</p>
                @elseif ($googleAnalyticsLandingPageReport['status'] === 'expired')
                    <p class="campaign-report-empty">登陸頁明細已超過 30 天保存期限，更新數據後可重新讀取。</p>
                @elseif ($googleAnalyticsLandingPageReport['rows'] === [])
                    <p class="campaign-report-empty">此活動期間沒有可用的登陸頁數據。</p>
                @else
                    <p class="campaign-report-secondary">
                        {{ $googleAnalyticsLandingPageReport['window_start_on'] }} 至 {{ $googleAnalyticsLandingPageReport['window_end_on'] }} · 最多顯示 200 個登陸頁，依工作階段選入；路徑不含網址查詢參數，明細加密保存 30 天。
                    </p>
                    @if ($googleAnalyticsLandingPageReport['truncated'])
                        <p class="campaign-report-note">登陸頁超過顯示上限或 GA4 已彙總部分高基數資料，表格可能未包含所有路徑。</p>
                    @endif
                    <form method="GET" action="{{ route('social-engagement-targets.report', $target) }}" class="campaign-report-filter-form">
                        <input type="hidden" name="search_term_sort" value="{{ request('search_term_sort', 'spend') }}">
                        <label for="ga4-landing-page-sort">排序</label>
                        <select id="ga4-landing-page-sort" name="landing_page_sort">
                            <option value="sessions" @selected($googleAnalyticsLandingPageReport['sort'] === 'sessions')>工作階段</option>
                            <option value="key_events" @selected($googleAnalyticsLandingPageReport['sort'] === 'key_events')>重要事件</option>
                            <option value="total_revenue" @selected($googleAnalyticsLandingPageReport['sort'] === 'total_revenue')>營收</option>
                            <option value="engagement_rate" @selected($googleAnalyticsLandingPageReport['sort'] === 'engagement_rate')>參與率</option>
                        </select>
                        <button class="button button-secondary button-small" type="submit">套用</button>
                    </form>
                    <div class="data-table-wrap" role="region" aria-labelledby="campaign-report-ga4-landing-page-title" tabindex="0">
                        <table class="data-table campaign-report-table campaign-report-ga4-landing-page-table">
                            <caption>GA4 登陸頁工作階段、參與、重要事件與營收</caption>
                            <thead>
                                <tr>
                                    <th scope="col">登陸頁路徑</th>
                                    <th scope="col" class="numeric">工作階段</th>
                                    <th scope="col" class="numeric">參與工作階段</th>
                                    <th scope="col" class="numeric">參與率</th>
                                    <th scope="col" class="numeric">跳出率</th>
                                    <th scope="col" class="numeric">重要事件</th>
                                    <th scope="col" class="numeric">營收（{{ $googleAnalyticsLandingPageReport['currency_code'] ?? '幣別未知' }}）</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($googleAnalyticsLandingPageReport['rows'] as $landingPage)
                                    <tr>
                                        <th scope="row">{{ $landingPage['landing_page'] === '' ? '(not set)' : $landingPage['landing_page'] }}</th>
                                        <td class="numeric">{{ number_format((int) $landingPage['sessions']) }}</td>
                                        <td class="numeric">{{ number_format((int) $landingPage['engaged_sessions']) }}</td>
                                        <td class="numeric">{{ number_format((float) $landingPage['engagement_rate'] * 100, 2) }}%</td>
                                        <td class="numeric">{{ number_format((float) $landingPage['bounce_rate'] * 100, 2) }}%</td>
                                        <td class="numeric">{{ number_format((float) $landingPage['key_events'], 2) }}</td>
                                        <td class="numeric">{{ number_format((float) $landingPage['total_revenue'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
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

        @php
            $dailyMarketingSources = [
                [
                    'key' => 'google_analytics',
                    'title' => 'GA4',
                    'metrics' => [
                        ['key' => 'sessions', 'label' => '工作階段', 'format' => 'integer'],
                        ['key' => 'engaged_sessions', 'label' => '參與工作階段', 'format' => 'integer'],
                        ['key' => 'key_events', 'label' => '重要事件', 'format' => 'decimal'],
                        ['key' => 'total_revenue', 'label' => '總營收', 'format' => 'currency'],
                    ],
                ],
                [
                    'key' => 'meta_marketing',
                    'title' => 'Meta Ads',
                    'metrics' => [
                        ['key' => 'spend_amount', 'label' => '花費', 'format' => 'currency'],
                        ['key' => 'impressions', 'label' => '曝光', 'format' => 'integer'],
                        ['key' => 'reach', 'label' => '觸及', 'format' => 'integer'],
                        ['key' => 'clicks', 'label' => '點擊', 'format' => 'integer'],
                        ['key' => 'frequency', 'label' => '頻率', 'format' => 'frequency'],
                        ['key' => 'ctr_percent', 'label' => '點擊率', 'format' => 'percent'],
                        ['key' => 'cost_per_click', 'label' => '每次點擊成本', 'format' => 'currency_unit'],
                        ['key' => 'cost_per_thousand_impressions', 'label' => '每千次曝光成本', 'format' => 'currency_unit'],
                        ['key' => 'omni_purchase_count', 'label' => '購買', 'format' => 'decimal'],
                        ['key' => 'omni_purchase_value', 'label' => '購買價值', 'format' => 'currency'],
                        ['key' => 'cost_per_action', 'label' => '每次購買成本', 'format' => 'currency_unit'],
                        ['key' => 'purchase_roas', 'label' => '購買 ROAS', 'format' => 'ratio'],
                    ],
                ],
                [
                    'key' => 'google_ads',
                    'title' => 'Google Ads',
                    'metrics' => [
                        ['key' => 'spend_amount', 'label' => '花費', 'format' => 'currency'],
                        ['key' => 'impressions', 'label' => '曝光', 'format' => 'integer'],
                        ['key' => 'clicks', 'label' => '點擊', 'format' => 'integer'],
                        ['key' => 'ctr_percent', 'label' => '點擊率', 'format' => 'percent'],
                        ['key' => 'cost_per_click', 'label' => '每次點擊成本', 'format' => 'currency_unit'],
                        ['key' => 'cost_per_thousand_impressions', 'label' => '每千次曝光成本', 'format' => 'currency_unit'],
                        ['key' => 'conversions', 'label' => '轉換', 'format' => 'decimal'],
                        ['key' => 'conversion_rate_percent', 'label' => '轉換率', 'format' => 'percent'],
                        ['key' => 'cost_per_action', 'label' => '每次轉換成本', 'format' => 'currency_unit'],
                        ['key' => 'conversions_value', 'label' => '轉換價值', 'format' => 'currency'],
                        ['key' => 'conversion_value_roas', 'label' => '轉換價值 ROAS', 'format' => 'ratio'],
                        ['key' => 'search_impression_share', 'label' => '搜尋曝光比重', 'format' => 'search_share'],
                        ['key' => 'search_budget_lost_impression_share', 'label' => '預算流失比重', 'format' => 'search_share'],
                        ['key' => 'search_rank_lost_impression_share', 'label' => '排名流失比重', 'format' => 'search_share'],
                    ],
                ],
            ];
            $dailyMarketingStatusLabels = [
                'complete' => '完整',
                'partial' => '部分涵蓋',
                'unsupported' => '不支援',
                'not_synced' => '尚未同步',
            ];
        @endphp
        <section class="campaign-report-panel" aria-labelledby="campaign-report-daily-marketing-title">
            <div class="campaign-report-section-header">
                <div>
                    <h2 id="campaign-report-daily-marketing-title">逐日行銷成效</h2>
                    <p class="campaign-report-secondary">逐日顯示平台數據與衍生效率指標；同日 ROAS＝GA4 活動營收 ÷ 廣告花費，只作日期對照，不代表因果。</p>
                </div>
                <a class="button button-secondary button-small" href="{{ route('social-engagement-targets.daily-marketing-performance', $target) }}">匯出逐日 CSV</a>
            </div>

            @foreach ($dailyMarketingSources as $dailySource)
                @php
                    $sourceReport = $dailyMarketingPerformance[$dailySource['key']];
                    $sourceStatus = $sourceReport['status'];
                    $sourceStatusClass = match ($sourceStatus) {
                        'complete' => 'healthy',
                        'partial' => 'warning',
                        'unsupported' => 'stale',
                        default => 'warning',
                    };
                @endphp
                <div class="campaign-report-daily-source">
                    <div class="campaign-report-section-header">
                        <h3 id="campaign-report-daily-{{ $dailySource['key'] }}-title">{{ $dailySource['title'] }} 逐日成效</h3>
                        <span class="badge badge-{{ $sourceStatusClass }}">{{ $dailyMarketingStatusLabels[$sourceStatus] ?? '狀態未知' }}</span>
                    </div>
                    <p class="campaign-report-secondary">
                        API 請求：{{ $sourceReport['requested_start_on'] ?? '—' }} 至 {{ $sourceReport['requested_end_on'] ?? '—' }}
                        · API 涵蓋：{{ $sourceReport['coverage_start_on'] ?? '—' }} 至 {{ $sourceReport['coverage_end_on'] ?? '—' }}
                        · 幣別：{{ $sourceReport['currency_code'] ?? '未提供' }}
                        · 更新於：{{ $sourceReport['synced_at']?->timezone('Asia/Taipei')->format('Y/n/j H:i') ?? '—' }}
                    </p>
                    @if ($dailySource['key'] === 'google_ads')
                        <p class="campaign-report-secondary">搜尋指標資料可能延遲 1–2 天；<a href="{{ route('features.index') }}">搜尋指標判讀</a></p>
                    @endif
                    @if ($sourceReport['trend_chart']['points'] !== [])
                        <figure class="metric-trend-figure campaign-report-daily-trend">
                            <svg
                                class="metric-trend-svg"
                                viewBox="0 0 600 196"
                                role="img"
                                aria-labelledby="campaign-report-daily-{{ $dailySource['key'] }}-chart-title campaign-report-daily-{{ $dailySource['key'] }}-chart-description"
                            >
                                <title id="campaign-report-daily-{{ $dailySource['key'] }}-chart-title">{{ $dailySource['title'] }} 每日{{ $sourceReport['trend_chart']['metric_label'] }}趨勢</title>
                                <desc id="campaign-report-daily-{{ $dailySource['key'] }}-chart-description">
                                    {{ $sourceReport['trend_chart']['coverage_start_on'] }} 至 {{ $sourceReport['trend_chart']['coverage_end_on'] }}，{{ $sourceReport['trend_chart']['metric_label'] }}最高為 {{ $sourceReport['trend_chart']['maximum_label'] }}。未回傳的日期不補為零，折線只連接連續日期；精確數值見下方表格。
                                </desc>
                                <line class="metric-trend-grid-line" x1="48" x2="576" y1="24" y2="24" />
                                <line class="metric-trend-grid-line" x1="48" x2="576" y1="89" y2="89" />
                                <line class="metric-trend-grid-line" x1="48" x2="576" y1="154" y2="154" />
                                @foreach ($sourceReport['trend_chart']['segments'] as $segment)
                                    <line
                                        class="metric-trend-line"
                                        x1="{{ number_format($segment['x1'], 2, '.', '') }}"
                                        y1="{{ number_format($segment['y1'], 2, '.', '') }}"
                                        x2="{{ number_format($segment['x2'], 2, '.', '') }}"
                                        y2="{{ number_format($segment['y2'], 2, '.', '') }}"
                                    />
                                @endforeach
                                @foreach ($sourceReport['trend_chart']['points'] as $trendPoint)
                                    <circle
                                        class="metric-trend-point"
                                        cx="{{ number_format($trendPoint['x'], 2, '.', '') }}"
                                        cy="{{ number_format($trendPoint['y'], 2, '.', '') }}"
                                        r="3.5"
                                    >
                                        <title>{{ $trendPoint['date'] }}：{{ $sourceReport['trend_chart']['metric_label'] }} {{ $trendPoint['value_label'] }}</title>
                                    </circle>
                                @endforeach
                                <text class="metric-trend-axis-label" x="48" y="184">{{ $sourceReport['trend_chart']['coverage_start_on'] }}</text>
                                <text class="metric-trend-axis-label" x="576" y="184" text-anchor="end">{{ $sourceReport['trend_chart']['coverage_end_on'] }}</text>
                            </svg>
                            <figcaption class="campaign-report-secondary campaign-report-daily-trend-caption">
                                每日{{ $sourceReport['trend_chart']['metric_label'] }} · 最高 {{ $sourceReport['trend_chart']['maximum_label'] }} · 未回傳日期不視為 0
                            </figcaption>
                        </figure>
                    @endif
                    <div class="data-table-wrap" role="region" aria-labelledby="campaign-report-daily-{{ $dailySource['key'] }}-title" tabindex="0">
                        <table class="data-table campaign-report-table">
                            <caption class="sr-only">{{ $dailySource['title'] }}逐日行銷成效及 API 資料涵蓋範圍</caption>
                            <thead>
                                <tr>
                                    <th scope="col">日期</th>
                                    @foreach ($dailySource['metrics'] as $metricDefinition)
                                        <th scope="col" class="numeric">{{ $metricDefinition['label'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($sourceReport['rows'] as $dailyRow)
                                    <tr>
                                        <th scope="row">{{ $dailyRow['date'] }}</th>
                                        @foreach ($dailySource['metrics'] as $metricDefinition)
                                            <td class="numeric">
                                                @if ($dailyRow[$metricDefinition['key']] === null)
                                                    @if ($metricDefinition['format'] === 'search_share')
                                                        {{ match ($sourceReport['campaign_channel_type'] ?? null) {
                                                            'SEARCH', 'SHOPPING' => '平台未提供',
                                                            null => '尚未同步搜尋指標',
                                                            default => '不適用',
                                                        } }}
                                                    @else
                                                        —
                                                    @endif
                                                @elseif ($metricDefinition['format'] === 'integer')
                                                    {{ number_format((int) $dailyRow[$metricDefinition['key']]) }}
                                                @elseif ($metricDefinition['format'] === 'ratio')
                                                    {{ number_format((float) $dailyRow[$metricDefinition['key']], 2) }}x
                                                @elseif ($metricDefinition['format'] === 'frequency')
                                                    {{ number_format((float) $dailyRow[$metricDefinition['key']], 2) }} 次／人
                                                @elseif ($metricDefinition['format'] === 'percent')
                                                    {{ number_format((float) $dailyRow[$metricDefinition['key']], 2) }}%
                                                @elseif ($metricDefinition['format'] === 'currency_unit')
                                                    {{ number_format((float) $dailyRow[$metricDefinition['key']], 4) }} {{ $sourceReport['currency_code'] ?? '幣別未知' }}
                                                @elseif ($metricDefinition['format'] === 'search_share')
                                                    @if ($metricDefinition['key'] === 'search_impression_share' && (float) $dailyRow[$metricDefinition['key']] === 0.0999)
                                                        &lt;10%
                                                    @elseif ($metricDefinition['key'] !== 'search_impression_share' && (float) $dailyRow[$metricDefinition['key']] === 0.9001)
                                                        &gt;90%
                                                    @else
                                                        {{ number_format((float) $dailyRow[$metricDefinition['key']] * 100, 2) }}%
                                                    @endif
                                                @else
                                                    {{ number_format((float) $dailyRow[$metricDefinition['key']], 2) }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($dailySource['metrics']) + 1 }}" class="campaign-report-empty-cell">
                                            {{ $sourceReport['snapshot_available'] ? 'API 無逐日列。' : '尚未取得逐日資料。' }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach

            @php
                $dailyMarketingEfficiency = $dailyMarketingPerformance['combined_efficiency'];
                $dailyMarketingEfficiencyStatusClass = match ($dailyMarketingEfficiency['status']) {
                    'complete' => 'healthy',
                    'currency_mismatch' => 'stale',
                    'not_configured' => 'unknown',
                    default => 'warning',
                };
                $dailyMarketingPeriodComparison = $dailyMarketingEfficiency['period_comparison'];
                $dailyMarketingPeriodStatusClass = match ($dailyMarketingPeriodComparison['status']) {
                    'complete' => 'healthy',
                    'currency_mismatch' => 'stale',
                    'not_configured' => 'unknown',
                    default => 'warning',
                };
            @endphp
            <div class="campaign-report-daily-source" aria-labelledby="campaign-report-daily-efficiency-title">
                <div class="campaign-report-section-header">
                    <h3 id="campaign-report-daily-efficiency-title">每日廣告花費與同日 ROAS</h3>
                    <span class="badge badge-{{ $dailyMarketingEfficiencyStatusClass }}">{{ $dailyMarketingEfficiency['status_label'] }}</span>
                </div>
                <p class="campaign-report-secondary">
                    活動期間：{{ $dailyMarketingEfficiency['requested_start_on'] ?? '—' }} 至 {{ $dailyMarketingEfficiency['requested_end_on'] ?? '—' }}
                    · 更新於：{{ $dailyMarketingEfficiency['latest_synced_at']?->timezone('Asia/Taipei')->format('Y/n/j H:i') ?? '—' }}
                    · 合計花費只在已設定來源都有當日資料且幣別一致時合併；ROAS 分母使用 {{ $dailyMarketingEfficiency['roas_spend_source_label'] ?? 'GA4 歸因來源' }} 花費；缺少日期不補零。
                </p>
                <div class="campaign-report-section-header">
                    <h4>近 7 天與前 7 天</h4>
                    <span class="badge badge-{{ $dailyMarketingPeriodStatusClass }}">{{ $dailyMarketingPeriodComparison['status_label'] }}</span>
                </div>
                <p class="campaign-report-secondary">
                    近期：{{ $dailyMarketingPeriodComparison['current_period']['start_on'] ?? '—' }} 至 {{ $dailyMarketingPeriodComparison['current_period']['end_on'] ?? '—' }}
                    · 前期：{{ $dailyMarketingPeriodComparison['previous_period']['start_on'] ?? '—' }} 至 {{ $dailyMarketingPeriodComparison['previous_period']['end_on'] ?? '—' }}
                    · ROAS 分母使用 {{ $dailyMarketingEfficiency['roas_spend_source_label'] ?? 'GA4 歸因來源' }} 花費；兩期都需有完整逐日資料且幣別一致。
                </p>
                <div class="data-table-wrap campaign-report-daily-period-comparison" role="region" aria-label="近七天與前七天成效比較" tabindex="0">
                    <table class="data-table campaign-report-table">
                        <caption class="sr-only">近 7 天與前 7 天的 GA4 營收、廣告花費、ROAS 與差異</caption>
                        <thead>
                            <tr>
                                <th scope="col">指標</th>
                                <th scope="col" class="numeric">近 7 天</th>
                                <th scope="col" class="numeric">前 7 天</th>
                                <th scope="col" class="numeric">差異</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ([
                                ['key' => 'revenue', 'label' => 'GA4 營收'],
                                ['key' => 'spend', 'label' => 'ROAS 對應花費'],
                                ['key' => 'roas', 'label' => 'GA4 ROAS'],
                            ] as $periodMetric)
                                @php
                                    $currentPeriod = $dailyMarketingPeriodComparison['current_period'];
                                    $previousPeriod = $dailyMarketingPeriodComparison['previous_period'];
                                    $periodChange = match ($periodMetric['key']) {
                                        'revenue' => $dailyMarketingPeriodComparison['changes']['revenue_amount'],
                                        'spend' => $dailyMarketingPeriodComparison['changes']['spend_amount'],
                                        default => $dailyMarketingPeriodComparison['changes']['roas'],
                                    };
                                    $periodChangePercent = match ($periodMetric['key']) {
                                        'revenue' => $dailyMarketingPeriodComparison['changes']['revenue_percent'],
                                        'spend' => $dailyMarketingPeriodComparison['changes']['spend_percent'],
                                        default => null,
                                    };
                                @endphp
                                <tr>
                                    <th scope="row">{{ $periodMetric['label'] }}</th>
                                    @foreach ([$currentPeriod, $previousPeriod] as $periodValues)
                                        <td class="numeric">
                                            @if ($periodMetric['key'] === 'revenue' && $periodValues['revenue_amount'] !== null)
                                                {{ number_format((float) $periodValues['revenue_amount'], 2) }} {{ $periodValues['currency'] }}
                                            @elseif ($periodMetric['key'] === 'spend' && $periodValues['spend_amount'] !== null)
                                                {{ number_format((float) $periodValues['spend_amount'], 2) }} {{ $periodValues['currency'] }}
                                            @elseif ($periodMetric['key'] === 'roas' && $periodValues['roas'] !== null)
                                                {{ number_format($periodValues['roas'], 2) }}x
                                            @else
                                                {{ match ($periodValues['status']) {
                                                    'partial' => '資料不完整',
                                                    'currency_mismatch' => '幣別不同',
                                                    default => '—',
                                                } }}
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="numeric">
                                        @if ($periodChange === null)
                                            —
                                        @elseif ($periodMetric['key'] === 'roas')
                                            {{ $periodChange > 0 ? '+' : '' }}{{ number_format($periodChange, 2) }}x
                                        @else
                                            {{ (float) $periodChange > 0 ? '+' : '' }}{{ number_format((float) $periodChange, 2) }} {{ $currentPeriod['currency'] }}
                                            @if ($periodChangePercent !== null)
                                                <span class="campaign-report-secondary">({{ $periodChangePercent > 0 ? '+' : '' }}{{ number_format($periodChangePercent, 2) }}%)</span>
                                            @else
                                                <span class="campaign-report-secondary">(前期為 0，無法計算百分比)</span>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($dailyMarketingEfficiency['trend_chart']['points'] !== [])
                    <figure class="metric-trend-figure campaign-report-daily-trend">
                        <svg
                            class="metric-trend-svg"
                            viewBox="0 0 600 196"
                            role="img"
                            aria-labelledby="campaign-report-daily-efficiency-chart-title campaign-report-daily-efficiency-chart-description"
                        >
                            <title id="campaign-report-daily-efficiency-chart-title">每日 GA4 同日 ROAS 趨勢</title>
                            <desc id="campaign-report-daily-efficiency-chart-description">
                                {{ $dailyMarketingEfficiency['requested_start_on'] ?? '—' }} 至 {{ $dailyMarketingEfficiency['requested_end_on'] ?? '—' }}；ROAS 使用 {{ $dailyMarketingEfficiency['roas_spend_source_label'] ?? 'GA4 歸因來源' }} 花費；只繪製花費與 GA4 營收完整且幣別相同的日期，缺少資料不補零。
                            </desc>
                            <line class="metric-trend-grid-line" x1="48" x2="576" y1="24" y2="24" />
                            <line class="metric-trend-grid-line" x1="48" x2="576" y1="89" y2="89" />
                            <line class="metric-trend-grid-line" x1="48" x2="576" y1="154" y2="154" />
                            @foreach ($dailyMarketingEfficiency['trend_chart']['segments'] as $segment)
                                <line
                                    class="metric-trend-line"
                                    x1="{{ number_format($segment['x1'], 2, '.', '') }}"
                                    y1="{{ number_format($segment['y1'], 2, '.', '') }}"
                                    x2="{{ number_format($segment['x2'], 2, '.', '') }}"
                                    y2="{{ number_format($segment['y2'], 2, '.', '') }}"
                                />
                            @endforeach
                            @foreach ($dailyMarketingEfficiency['trend_chart']['points'] as $trendPoint)
                                <circle
                                    class="metric-trend-point"
                                    cx="{{ number_format($trendPoint['x'], 2, '.', '') }}"
                                    cy="{{ number_format($trendPoint['y'], 2, '.', '') }}"
                                    r="3.5"
                                >
                                    <title>{{ $trendPoint['date'] }}：ROAS {{ $trendPoint['value_label'] }}x</title>
                                </circle>
                            @endforeach
                            <text class="metric-trend-axis-label" x="48" y="184">{{ $dailyMarketingEfficiency['trend_chart']['coverage_start_on'] }}</text>
                            <text class="metric-trend-axis-label" x="576" y="184" text-anchor="end">{{ $dailyMarketingEfficiency['trend_chart']['coverage_end_on'] }}</text>
                        </svg>
                        <figcaption class="campaign-report-secondary campaign-report-daily-trend-caption">
                            每日 ROAS · 最高 {{ $dailyMarketingEfficiency['trend_chart']['maximum_label'] }}x · 精確數值見表格
                        </figcaption>
                    </figure>
                @endif
                <div class="data-table-wrap" role="region" aria-labelledby="campaign-report-daily-efficiency-title" tabindex="0">
                    <table class="data-table campaign-report-table">
                        <caption class="sr-only">每日 GA4 營收、Meta Ads 與 Google Ads 花費、同幣別合計花費、ROAS 對應花費及 ROAS</caption>
                        <thead>
                            <tr>
                                <th scope="col">日期</th>
                                <th scope="col" class="numeric">GA4 營收</th>
                                <th scope="col" class="numeric">Meta Ads 花費</th>
                                <th scope="col" class="numeric">Google Ads 花費</th>
                                <th scope="col" class="numeric">合計花費</th>
                                <th scope="col" class="numeric">ROAS 對應花費</th>
                                <th scope="col" class="numeric">GA4 ROAS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($dailyMarketingEfficiency['rows'] as $dailyEfficiencyRow)
                                <tr>
                                    <th scope="row">{{ $dailyEfficiencyRow['date'] }}</th>
                                    <td class="numeric">
                                        {{ $dailyEfficiencyRow['ga4_revenue'] === null ? '—' : number_format((float) $dailyEfficiencyRow['ga4_revenue'], 2).' '.($dailyEfficiencyRow['ga4_currency_code'] ?? '未提供幣別') }}
                                    </td>
                                    <td class="numeric">
                                        {{ $dailyEfficiencyRow['meta_spend_amount'] === null ? '—' : number_format((float) $dailyEfficiencyRow['meta_spend_amount'], 2).' '.($dailyEfficiencyRow['meta_currency_code'] ?? '未提供幣別') }}
                                    </td>
                                    <td class="numeric">
                                        {{ $dailyEfficiencyRow['google_ads_spend_amount'] === null ? '—' : number_format((float) $dailyEfficiencyRow['google_ads_spend_amount'], 2).' '.($dailyEfficiencyRow['google_ads_currency_code'] ?? '未提供幣別') }}
                                    </td>
                                    <td class="numeric">
                                        @if ($dailyEfficiencyRow['ad_spend_amount'] !== null)
                                            {{ number_format((float) $dailyEfficiencyRow['ad_spend_amount'], 2) }} {{ $dailyEfficiencyRow['ad_spend_currency'] ?? '未提供幣別' }}
                                        @elseif ($dailyEfficiencyRow['spend_status'] === 'currency_mismatch')
                                            廣告幣別不同
                                        @elseif ($dailyEfficiencyRow['spend_status'] === 'missing_currency')
                                            缺少花費幣別
                                        @elseif ($dailyEfficiencyRow['spend_status'] === 'not_configured')
                                            未設定廣告活動
                                        @else
                                            資料不完整
                                        @endif
                                    </td>
                                    <td class="numeric">
                                        {{ $dailyEfficiencyRow['roas_spend_amount'] === null ? $dailyEfficiencyRow['roas_status_label'] : number_format((float) $dailyEfficiencyRow['roas_spend_amount'], 2).' '.($dailyEfficiencyRow['roas_spend_currency'] ?? '未提供幣別') }}
                                    </td>
                                    <td class="numeric">
                                        {{ $dailyEfficiencyRow['roas'] === null ? $dailyEfficiencyRow['roas_status_label'] : number_format($dailyEfficiencyRow['roas'], 2).'x' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="campaign-report-empty-cell">尚未取得可對齊的逐日資料。</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

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
                    <dt>GA4 營收 ROAS（{{ $adSpendAnalysis['roas_spend_source_label'] ?? '無配對花費' }}）</dt>
                    <dd>
                        {{ $adSpendAnalysis['roas'] === null ? '—' : number_format($adSpendAnalysis['roas'], 2).'x' }}
                        @if ($adSpendAnalysis['roas_spend_amount'] !== null)
                            <span class="campaign-report-secondary">
                                分母 {{ $adSpendAnalysis['roas_spend_source_label'] }} {{ number_format((float) $adSpendAnalysis['roas_spend_amount'], 2) }} {{ $adSpendAnalysis['roas_spend_currency'] ?? '未提供幣別' }}
                            </span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>每工作階段成本</dt>
                    <dd>
                        {{ $adSpendAnalysis['cost_per_session'] === null ? '—' : number_format($adSpendAnalysis['cost_per_session'], 4).' '.$adSpendAnalysis['roas_spend_currency'] }}
                    </dd>
                </div>
                <div>
                    <dt>GA4 工作階段／{{ $adSpendAnalysis['paid_click_source_label'] ?? '廣告' }}點擊</dt>
                    <dd>
                        {{ $adSpendAnalysis['ga4_sessions_per_paid_click'] === null ? $adSpendAnalysis['paid_click_session_status_label'] : number_format($adSpendAnalysis['ga4_sessions_per_paid_click'], 2).' 次／點擊' }}
                        <span class="campaign-report-secondary">
                            {{ $adSpendAnalysis['paid_click_source_label'] ?? '廣告' }}點擊 {{ $adSpendAnalysis['paid_ad_clicks'] === null ? '—' : number_format($adSpendAnalysis['paid_ad_clicks']) }} · GA4 工作階段 {{ $googleAnalyticsReport?->sessions === null ? '—' : number_format($googleAnalyticsReport->sessions) }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>每重要事件成本</dt>
                    <dd>
                        {{ $adSpendAnalysis['cost_per_key_event'] === null ? '—' : number_format($adSpendAnalysis['cost_per_key_event'], 4).' '.$adSpendAnalysis['roas_spend_currency'] }}
                    </dd>
                </div>
                <div>
                    <dt>依最新累積數估算的每互動成本</dt>
                    <dd>
                        {{ $adSpendAnalysis['cost_per_public_interaction'] === null ? '—' : number_format($adSpendAnalysis['cost_per_public_interaction'], 4).' '.$adSpendAnalysis['ad_spend_currency'] }}
                    </dd>
                </div>
                <div>
                    <dt>活動期間每次淨增互動成本</dt>
                    <dd>
                        {{ $adSpendAnalysis['cost_per_campaign_interaction_change'] === null ? '—' : number_format($adSpendAnalysis['cost_per_campaign_interaction_change'], 4).' '.$adSpendAnalysis['ad_spend_currency'] }}
                        <span class="campaign-report-secondary">
                            淨增互動 {{ $adSpendAnalysis['observed_campaign_interaction_change'] === null ? '—' : number_format($adSpendAnalysis['observed_campaign_interaction_change']) }} · 可比較 {{ number_format($adSpendAnalysis['comparable_interaction_post_count']) }} 篇
                        </span>
                    </dd>
                </div>
            </dl>
            <p class="campaign-report-secondary">已設定 Google Ads 活動時使用 Google Ads 點擊；否則使用 Meta Ads 點擊。此比值僅供追蹤診斷，點擊與工作階段不一定一對一，不代表轉換率。</p>
            <p class="campaign-report-secondary">活動期間互動增量以每篇貼文在活動日期內的首、末完整讀值相減；至少兩次完整讀值才納入，下降的數量會抵銷增量。</p>
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
            <p class="campaign-report-secondary">GA4 ROAS 與每工作階段／重要事件成本使用符合 GA4 歸因的單一來源花費：Google Ads 自動標記時採 Google Ads，否則採 Meta Ads 或人工記錄。投放總花費仍會合併同幣別的 Meta Ads 與 Google Ads；ROAS 不扣除商品成本。</p>

            <section class="spaced-top" aria-labelledby="campaign-budget-pacing-title">
                <h3 id="campaign-budget-pacing-title">活動預算進度</h3>
                @if ($adSpendAnalysis['campaign_budget_amount'] === null)
                    <p class="campaign-report-secondary">尚未設定活動總預算。</p>
                @else
                    <dl class="campaign-report-summary campaign-efficiency-summary" aria-label="活動預算與消耗進度">
                        <div>
                            <dt>活動總預算</dt>
                            <dd>{{ number_format((float) $adSpendAnalysis['campaign_budget_amount'], 2) }} {{ $adSpendAnalysis['campaign_budget_currency'] }}</dd>
                        </div>
                        <div>
                            <dt>預算使用率</dt>
                            <dd>{{ $adSpendAnalysis['budget_utilization_percent'] === null ? '—' : number_format($adSpendAnalysis['budget_utilization_percent'], 2).'%' }}</dd>
                        </div>
                        <div>
                            <dt>活動期間進度</dt>
                            <dd>{{ $adSpendAnalysis['budget_elapsed_percent'] === null ? '—' : number_format($adSpendAnalysis['budget_elapsed_percent'], 2).'%' }}</dd>
                        </div>
                        <div>
                            <dt>依活動進度應有花費</dt>
                            <dd>{{ $adSpendAnalysis['budget_expected_spend_amount'] === null ? '—' : number_format($adSpendAnalysis['budget_expected_spend_amount'], 2).' '.$adSpendAnalysis['campaign_budget_currency'] }}</dd>
                        </div>
                        <div>
                            <dt>目前花費與應有進度差</dt>
                            <dd>
                                {{ $adSpendAnalysis['budget_pacing_difference_amount'] === null ? '—' : (($adSpendAnalysis['budget_pacing_difference_amount'] > 0 ? '+' : '').number_format($adSpendAnalysis['budget_pacing_difference_amount'], 2).' '.$adSpendAnalysis['campaign_budget_currency']) }}
                            </dd>
                        </div>
                        <div>
                            <dt>依目前進度推估總花費</dt>
                            <dd>{{ $adSpendAnalysis['budget_projected_spend_amount'] === null ? '—' : number_format($adSpendAnalysis['budget_projected_spend_amount'], 2).' '.$adSpendAnalysis['campaign_budget_currency'] }}</dd>
                        </div>
                    </dl>
                    <p class="campaign-report-secondary">
                        {{ match ($adSpendAnalysis['budget_status']) {
                            'zero_budget' => '預算為 0，無法計算使用率。',
                            'platform_currency_mismatch' => 'Meta Ads 與 Google Ads 幣別不同，暫不比較預算。',
                            'missing_spend' => '同步 Meta Ads／Google Ads 或輸入花費後即可比較預算。',
                            'missing_budget_currency' => '請設定活動預算幣別。',
                            'missing_spend_currency' => '實際花費沒有可用幣別，暫不比較預算。',
                            'currency_mismatch' => '預算與實際花費幣別不同，暫不比較預算。',
                            'missing_campaign_period' => '設定活動開始與結束日期後即可計算預算進度。',
                            'not_started' => '活動尚未開始；預算使用率會在取得花費讀值後顯示。',
                            'completed' => '活動已結束；依活動期間總花費顯示使用率，不推估後續花費。',
                            default => '應有花費依活動日數平均估算；推估值僅供掌握目前消耗速度。',
                        } }}
                    </p>
                @endif

                <form action="{{ route('social-engagement-targets.budget.update', $target) }}" method="POST" class="campaign-spend-form" aria-labelledby="campaign-budget-form-title">
                    @csrf
                    @method('PUT')
                    <h3 id="campaign-budget-form-title">設定活動總預算</h3>
                    <div class="field-group">
                        <label class="field-label" for="campaign-budget-amount">預算金額</label>
                        <input class="field-input{{ $errors->getBag('socialEngagementBudget')->has('campaign_budget_amount') ? ' is-invalid' : '' }}" id="campaign-budget-amount" name="campaign_budget_amount" type="number" inputmode="decimal" min="0" max="999999999999999.9999" step="0.0001" value="{{ old('campaign_budget_amount', $target->campaign_budget_amount) }}" aria-describedby="campaign-budget-amount-help{{ $errors->getBag('socialEngagementBudget')->has('campaign_budget_amount') ? ' campaign-budget-amount-error' : '' }}" @if ($errors->getBag('socialEngagementBudget')->has('campaign_budget_amount')) aria-invalid="true" @endif>
                        <p id="campaign-budget-amount-help" class="field-help">最多 4 位小數；金額與幣別留白可清除預算。</p>
                        @error('campaign_budget_amount', 'socialEngagementBudget')<p id="campaign-budget-amount-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="campaign-budget-currency">預算幣別</label>
                        <input class="field-input{{ $errors->getBag('socialEngagementBudget')->has('campaign_budget_currency') ? ' is-invalid' : '' }}" id="campaign-budget-currency" name="campaign_budget_currency" type="text" minlength="3" maxlength="3" pattern="[A-Za-z]{3}" autocapitalize="characters" autocomplete="off" value="{{ old('campaign_budget_currency', $target->campaign_budget_currency) }}" aria-describedby="campaign-budget-currency-help{{ $errors->getBag('socialEngagementBudget')->has('campaign_budget_currency') ? ' campaign-budget-currency-error' : '' }}" @if ($errors->getBag('socialEngagementBudget')->has('campaign_budget_currency')) aria-invalid="true" @endif>
                        <p id="campaign-budget-currency-help" class="field-help">輸入 3 個英文字母，例如 TWD、USD。</p>
                        @error('campaign_budget_currency', 'socialEngagementBudget')<p id="campaign-budget-currency-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="campaign-spend-form-actions">
                        <button class="button button-primary" type="submit">儲存預算</button>
                    </div>
                </form>
            </section>

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
                @php
                    $metricSourceLabels = [
                        'json_ld' => 'JSON-LD',
                        'embedded_json' => '內嵌 JSON',
                        'meta' => 'Meta 標籤',
                        'rendered_dom' => '瀏覽器呈現',
                    ];
                @endphp
                <div class="data-table-wrap" role="region" aria-labelledby="campaign-report-posts-title" tabindex="0">
                    <table class="data-table campaign-report-table campaign-report-post-table">
                        <caption>依公開互動數排序的活動貼文；互動口徑為{{ $report['interaction_scope_label'] }}，各指標列出擷取來源</caption>
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
                                    @foreach (['views', 'likes', 'comments', 'shares'] as $metric)
                                        @php
                                            $metricSource = $post['metric_sources'][$metric] ?? null;
                                            $metricSourceLabel = is_string($metricSource) ? ($metricSourceLabels[$metricSource] ?? null) : null;
                                        @endphp
                                        <td class="numeric">
                                            {{ $post[$metric] === null ? '—' : number_format($post[$metric]) }}
                                            @if ($metricSourceLabel !== null)
                                                <span class="secondary-text">來源：{{ $metricSourceLabel }}</span>
                                            @endif
                                        </td>
                                    @endforeach
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
