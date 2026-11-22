@extends('layouts.app')

@section('title', 'Google Ads 整合')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">資料來源</p>
            <h1>Google Ads</h1>
            <p class="page-description">讀取已授權客戶帳戶的花費、曝光、點擊與轉換，補充 GA4 導流及營收數據。</p>
        </div>
    </div>

    <section class="panel" aria-labelledby="google-ads-connection-title">
        <div class="panel-header">
            <div>
                <h2 id="google-ads-connection-title" class="panel-title">帳戶連線</h2>
                <p class="panel-description">使用 Google Ads API 的 <code>adwords</code> OAuth 範圍；此範圍本身涵蓋檢視與變更權限，但本應用只發送報表與客戶資料讀取查詢，不會修改廣告設定。</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($oauthConfigured)
                <p class="field-help">Google OAuth 重新導向 URI：<code>{{ $redirectUri }}</code></p>
                <form action="{{ route('integrations.google-ads.connect') }}" method="POST">
                    @csrf
                    <button class="button button-primary" type="submit">{{ $connection ? '重新連結 Google 帳戶' : '連結 Google 帳戶' }}</button>
                </form>
            @else
                <p class="alert alert-error" role="status">請先在伺服器設定 GOOGLE_ADS_CLIENT_ID、GOOGLE_ADS_CLIENT_SECRET 與 GOOGLE_ADS_REDIRECT_URI，再建立連線。</p>
                <p class="field-help">在 Google Cloud 專案啟用 Google Ads API、設定 OAuth 網頁用戶端和同意畫面，並加入下方重新導向 URI。</p>
                <p class="field-help">重新導向 URI：<code>{{ $redirectUri }}</code></p>
            @endif

            @if ($connection)
                <p class="connection-status" role="status">Google Ads 帳戶已連結。</p>
                <form action="{{ route('integrations.google-ads.customer.update') }}" method="POST" class="check-history-filter">
                    @csrf
                    @method('PUT')
                    <div class="field-group">
                        <label class="field-label" for="google-ads-customer-id">客戶 ID</label>
                        <input class="field-input{{ $errors->getBag('googleAds')->has('customer_id') ? ' is-invalid' : '' }}" id="google-ads-customer-id" name="customer_id" type="text" inputmode="numeric" pattern="[0-9]{3}-?[0-9]{3}-?[0-9]{4}" maxlength="12" value="{{ old('customer_id', $connection->customer_id) }}" required aria-describedby="google-ads-customer-help{{ $errors->getBag('googleAds')->has('customer_id') ? ' google-ads-customer-error' : '' }}" @if ($errors->getBag('googleAds')->has('customer_id')) aria-invalid="true" @endif>
                        <p id="google-ads-customer-help" class="field-help">從 Google Ads 帳戶名稱旁複製 10 位客戶 ID，可含連字號。</p>
                        @error('customer_id', 'googleAds')<p id="google-ads-customer-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="google-ads-login-customer-id">管理員客戶 ID（選填）</label>
                        <input class="field-input{{ $errors->getBag('googleAds')->has('login_customer_id') ? ' is-invalid' : '' }}" id="google-ads-login-customer-id" name="login_customer_id" type="text" inputmode="numeric" pattern="[0-9]{3}-?[0-9]{3}-?[0-9]{4}" maxlength="12" value="{{ old('login_customer_id', $connection->login_customer_id) }}" aria-describedby="google-ads-manager-help{{ $errors->getBag('googleAds')->has('login_customer_id') ? ' google-ads-manager-error' : '' }}" @if ($errors->getBag('googleAds')->has('login_customer_id')) aria-invalid="true" @endif>
                        <p id="google-ads-manager-help" class="field-help">若透過管理員帳戶存取客戶，請填管理員客戶 ID；否則留白。</p>
                        @error('login_customer_id', 'googleAds')<p id="google-ads-manager-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <button class="button button-secondary" type="submit">驗證並儲存客戶</button>
                </form>

                @if ($connection->customer_id)
                    <p class="connection-status" role="status">目前客戶：{{ $connection->customer_name }}（{{ $connection->customer_id }} · {{ $connection->currency_code }}）</p>
                @endif

                <form action="{{ route('integrations.google-ads.disconnect') }}" method="POST" class="integration-disconnect-form">
                    @csrf
                    @method('DELETE')
                    <button class="button button-secondary" type="submit">中斷連結</button>
                </form>
            @endif
        </div>
    </section>

    <section class="panel" aria-labelledby="google-ads-usage-title">
        <div class="panel-body">
            <h2 id="google-ads-usage-title" class="panel-title">數據如何對應</h2>
            <p class="panel-description">先連結 Google 帳戶並驗證客戶 ID，再於活動報告載入並選取帳戶活動，或在活動互動目標填入活動 ID 與期間，即可同步投放成效。日期區間依 Google Ads 帳戶時區計算。</p>
            <p class="panel-description">Google Ads 的轉換與轉換價值依該帳戶的轉換設定歸因，不等同 GA4 工作階段或 GA4 營收。活動效率會合併幣別相同的 Google Ads 與 Meta Ads 花費；若幣別不同，不會混算。</p>
            <p class="panel-description">Google Ads API 存取層級由 Google Cloud 專案管理；讀取正式客戶資料需先取得 production access，現行流程不需 developer token。<a href="https://developers.google.com/google-ads/api/docs/api-policy/developer-token">官方存取說明</a></p>
            <p class="panel-description"><code>adwords</code> OAuth 範圍涵蓋廣告資料讀寫；本應用只發送讀取查詢。OAuth 審查依應用狀態與 Google 要求辦理。<a href="https://developers.google.com/identity/protocols/oauth2/scopes">OAuth 範圍說明</a></p>
        </div>
    </section>
@endsection
