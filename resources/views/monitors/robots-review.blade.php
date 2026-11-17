@extends('layouts.app')

@section('title', 'robots.txt 人工檢視')

@section('content')
    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('monitors.index') }}">監測項目</a><span aria-hidden="true">/</span>
        <a href="{{ route('monitors.show', $monitor) }}">{{ $monitor->name }}</a><span aria-hidden="true">/</span>
        <span aria-current="page">robots.txt</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">人工檢視</p>
            <h1 class="page-title">robots.txt</h1>
            <p class="page-description">檢視「{{ $monitor->name }}」網站根目錄的 robots.txt 檔案。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('monitors.show', $monitor) }}">返回監測項目</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="robots-review-title">
        <div class="panel-header">
            <div>
                <h2 id="robots-review-title" class="panel-title">開啟檢視</h2>
                <p class="panel-description">監測台不會擷取或解析 robots.txt；請在新分頁人工檢視檔案內容。</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($robotsUrl !== null)
                <dl class="metadata-list">
                    <div class="metadata-item">
                        <dt>網站來源</dt>
                        <dd><code>{{ $origin }}</code></dd>
                    </div>
                    <div class="metadata-item">
                        <dt>檢視路徑</dt>
                        <dd><code>/robots.txt</code></dd>
                    </div>
                </dl>
                <div class="form-actions spaced-top">
                    <a class="button button-primary" href="{{ $robotsUrl }}" target="_blank" rel="noopener noreferrer">在新分頁開啟 robots.txt</a>
                </div>
                <p class="form-note spaced-top">這是人工檢視入口，頁面內容與規則不會被監測台保存或判定。AdsBot 使用者代理模擬不代表 Google 官方檢查結果。</p>
            @else
                <div class="alert alert-error" role="alert">無法從此監測網址建立 robots.txt 檢視連結。</div>
            @endif
        </div>
    </section>
@endsection
