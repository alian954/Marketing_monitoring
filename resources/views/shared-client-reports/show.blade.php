<div>
    <!-- Knowing is not enough; we must apply. Being willing is not enough; we must do. - Leonardo da Vinci -->
</div>
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
            </section>
        @endforeach
    @endif
@endsection

@section('footer', '行銷成效資料由監測台提供')
