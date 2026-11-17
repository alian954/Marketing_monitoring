@extends('layouts.public-status')

@section('title', $statusPage->title)

@section('content')
    @php
        $summary = match ($pageState) {
            'healthy' => ['label' => '所有服務正常', 'description' => '目前沒有偵測到服務異常。'],
            'issue' => ['label' => '部分服務受到影響', 'description' => '至少一個服務目前有異常。'],
            default => ['label' => '部分服務需要留意', 'description' => '部分服務回應變慢、檢查逾時或尚未完成檢查。'],
        };
    @endphp

    <header class="public-status-header">
        <a class="public-status-brand" href="{{ route('status-pages.public', ['token' => request()->route('token')]) }}" aria-label="{{ $statusPage->title }}狀態首頁">
            <span class="brand-mark" aria-hidden="true">M</span>
            <span>服務狀態</span>
        </a>
        <span class="secondary-text">即時監測</span>
    </header>

    <section class="public-status-overview" aria-labelledby="public-status-title">
        <div class="public-status-title-row">
            <div>
                <p class="eyebrow">服務狀態</p>
                <h1 id="public-status-title" class="page-title">{{ $statusPage->title }}</h1>
            </div>
            <x-state-badge :state="$pageState" />
        </div>
        <p class="public-status-summary">{{ $summary['label'] }}</p>
        <p class="public-status-description">{{ $summary['description'] }}</p>
        @if ($lastCheckedAt)
            <p class="public-status-updated">最近更新：<time datetime="{{ $lastCheckedAt->toIso8601String() }}">{{ $lastCheckedAt->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}（台灣時間）</time></p>
        @else
            <p class="public-status-updated">監測項目尚未完成第一次檢查。</p>
        @endif
    </section>

    <section class="panel public-status-panel" aria-labelledby="public-status-services-title">
        <div class="panel-header">
            <div>
                <h2 id="public-status-services-title" class="panel-title">監測服務</h2>
                <p class="panel-description">此頁只公開所選服務的名稱、狀態與檢查時間。</p>
            </div>
        </div>
        <div class="public-status-service-list">
            @foreach ($monitors as $monitor)
                <article class="public-status-service">
                    <div class="public-status-service-name">
                        <h3>{{ $monitor->name }}</h3>
                        @if ($monitor->last_checked_at)
                            <p>最近檢查 <time datetime="{{ $monitor->last_checked_at->toIso8601String() }}">{{ $monitor->last_checked_at->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time></p>
                        @else
                            <p>尚未完成第一次檢查</p>
                        @endif
                    </div>
                    <x-state-badge :state="$monitor->health_state" />
                </article>
            @endforeach
        </div>
    </section>

    <p class="public-status-disclaimer">此頁只顯示已選取的服務狀態，不包含網站網址、檢查內容或內部事件備註。</p>
@endsection
