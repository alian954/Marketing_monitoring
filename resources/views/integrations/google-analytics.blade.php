@extends('layouts.app')

@section('title', 'GA4 整合')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">資料來源</p>
            <h1>Google Analytics 4</h1>
            <p class="page-description">讀取網站流量與重要事件，補充社群貼文的公開互動數。</p>
        </div>
    </div>

    <section class="panel" aria-labelledby="google-analytics-connection-title">
        <div class="panel-header">
            <div>
                <h2 id="google-analytics-connection-title" class="panel-title">帳戶連線</h2>
                <p class="panel-description">使用 Analytics 唯讀權限；更新報表時才向 Google 讀取資料。</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($oauthConfigured)
                <p class="field-help">Google OAuth 重新導向 URI：<code>{{ $redirectUri }}</code></p>
                <form action="{{ route('integrations.google-analytics.connect') }}" method="POST">
                    @csrf
                    <button class="button button-primary" type="submit">{{ $connection ? '重新連結 Google 帳戶' : '連結 Google 帳戶' }}</button>
                </form>
            @else
                <p class="alert alert-error" role="status">請先在伺服器設定 GOOGLE_ANALYTICS_CLIENT_ID、GOOGLE_ANALYTICS_CLIENT_SECRET 與 GOOGLE_ANALYTICS_REDIRECT_URI，再建立連線。</p>
                <p class="field-help">在 Google Cloud 建立 OAuth 網頁用戶端、啟用 Google Analytics Data API，並將上方重新導向 URI 加入授權清單。</p>
                <p class="field-help">重新導向 URI：<code>{{ $redirectUri }}</code></p>
            @endif

            @if ($connection)
                <p class="connection-status" role="status">帳戶已連結。</p>
                <form action="{{ route('integrations.google-analytics.property.update') }}" method="POST" class="check-history-filter">
                    @csrf
                    @method('PUT')
                    <div class="field-group">
                        <label class="field-label" for="google-analytics-property-id">GA4 資源 ID</label>
                        <input class="field-input{{ $errors->has('property_id') ? ' is-invalid' : '' }}" id="google-analytics-property-id" name="property_id" type="text" inputmode="numeric" pattern="[0-9]{1,20}" maxlength="20" value="{{ old('property_id', $connection->property_id) }}" required aria-describedby="google-analytics-property-help{{ $errors->has('property_id') ? ' google-analytics-property-error' : '' }}" @if ($errors->has('property_id')) aria-invalid="true" @endif>
                        <p id="google-analytics-property-help" class="field-help">可在 GA4 管理設定的「資源詳細資料」找到，輸入純數字 ID。</p>
                        @error('property_id')<p id="google-analytics-property-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <button class="button button-secondary" type="submit">儲存資源</button>
                </form>

                <form action="{{ route('integrations.google-analytics.disconnect') }}" method="POST" class="integration-disconnect-form">
                    @csrf
                    @method('DELETE')
                    <button class="button button-secondary" type="submit">中斷連結</button>
                </form>
            @endif
        </div>
    </section>

    <section class="panel" aria-labelledby="google-analytics-usage-title">
        <div class="panel-body">
            <h2 id="google-analytics-usage-title" class="panel-title">數據如何對應</h2>
            <p class="panel-description">活動報告以貼文導流網址中的 <code>utm_campaign</code> 對應 GA4 活動名稱，並依活動日期擷取工作階段、參與工作階段、重要事件與營收。若活動表單未填 GA4 活動名稱，系統會使用活動標籤。</p>
            <p class="panel-description">同一活動名稱若用於多個平台，請在活動目標填入 <code>utm_source</code>，例如 <code>facebook</code>，才能分開歸因；未填時會彙總該活動名稱的所有來源。</p>
            <p class="panel-description">GA4 只顯示有導到已安裝 GA4 的網站，且標記符合的流量。它不提供貼文觸及或公開讚數；重要事件與營收須先在 GA4 正確設定。</p>
        </div>
    </section>
@endsection
