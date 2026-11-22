@extends('layouts.app')

@section('title', 'Meta Ads 整合')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">資料來源</p>
            <h1>Meta Ads</h1>
            <p class="page-description">讀取已授權廣告帳戶的花費、曝光、觸及與點擊，補充 GA4 導流和公開貼文互動數。</p>
        </div>
    </div>

    <section class="panel" aria-labelledby="meta-marketing-connection-title">
        <div class="panel-header">
            <div>
                <h2 id="meta-marketing-connection-title" class="panel-title">帳戶連線</h2>
                <p class="panel-description">使用 Meta Marketing API 的 <code>ads_read</code> 唯讀權限；同步時才向 Meta 讀取資料。</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($oauthConfigured)
                <p class="field-help">Meta OAuth 重新導向 URI：<code>{{ $redirectUri }}</code></p>
                <form action="{{ route('integrations.meta-marketing.connect') }}" method="POST">
                    @csrf
                    <button class="button button-primary" type="submit">{{ $connection ? '重新連結 Meta 帳戶' : '連結 Meta 帳戶' }}</button>
                </form>
            @else
                <p class="alert alert-error" role="status">請先在伺服器設定 META_MARKETING_CLIENT_ID、META_MARKETING_CLIENT_SECRET 與 META_MARKETING_REDIRECT_URI，再建立連線。</p>
                <p class="field-help">在 Meta for Developers 建立應用程式、啟用 Marketing API，設定 OAuth 重新導向 URI 並取得 <code>ads_read</code> 權限。</p>
                <p class="field-help">重新導向 URI：<code>{{ $redirectUri }}</code></p>
            @endif

            @if ($connection)
                <p class="connection-status" role="status">
                    Meta 帳戶已連結。
                    @if ($connection->token_expires_at)
                        授權期限：{{ $connection->token_expires_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}
                    @endif
                </p>

                <form action="{{ route('integrations.meta-marketing.ad-account.update') }}" method="POST" class="check-history-filter">
                    @csrf
                    @method('PUT')
                    <div class="field-group">
                        <label class="field-label" for="meta-marketing-ad-account-id">廣告帳戶 ID</label>
                        <input class="field-input{{ $errors->getBag('metaMarketing')->has('ad_account_id') ? ' is-invalid' : '' }}" id="meta-marketing-ad-account-id" name="ad_account_id" type="text" inputmode="numeric" pattern="(act_)?[0-9]{5,32}" maxlength="36" value="{{ old('ad_account_id', $connection->ad_account_id) }}" required aria-describedby="meta-marketing-ad-account-help{{ $errors->getBag('metaMarketing')->has('ad_account_id') ? ' meta-marketing-ad-account-error' : '' }}" @if ($errors->getBag('metaMarketing')->has('ad_account_id')) aria-invalid="true" @endif>
                        <p id="meta-marketing-ad-account-help" class="field-help">可從 Meta Ads Manager 的帳戶設定複製 ID，也可輸入含 <code>act_</code> 前綴的格式。</p>
                        @error('ad_account_id', 'metaMarketing')<p id="meta-marketing-ad-account-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <button class="button button-secondary" type="submit">驗證並儲存帳戶</button>
                </form>

                @if ($connection->ad_account_id)
                    <p class="connection-status" role="status">目前帳戶：{{ $connection->ad_account_name }}（{{ $connection->ad_account_id }} · {{ $connection->currency_code }}）</p>
                @endif

                <form action="{{ route('integrations.meta-marketing.disconnect') }}" method="POST" class="integration-disconnect-form">
                    @csrf
                    @method('DELETE')
                    <button class="button button-secondary" type="submit">中斷連結</button>
                </form>
            @endif
        </div>
    </section>

    <section class="panel" aria-labelledby="meta-marketing-usage-title">
        <div class="panel-body">
            <h2 id="meta-marketing-usage-title" class="panel-title">數據如何對應</h2>
            <p class="panel-description">先連結有權限的 Meta 廣告帳戶，再於活動互動目標填入 Meta 活動 ID 與活動日期，即可同步該活動的投放花費、曝光、觸及和點擊。</p>
            <p class="panel-description">Meta Ads 數據僅涵蓋已授權廣告帳戶；它不會讀取任意第三方貼文的私有行銷數據。公開貼文互動仍由網址監測取得，GA4 繼續提供網站流量、重要事件與營收。</p>
            <p class="panel-description">活動報告每次手動同步，並每日自動更新。Meta Ads 花費會優先用於活動效率與 ROAS；沒有 Meta 讀值時，才使用活動中手動記錄的花費。幣別不同時不計算 ROAS。</p>
        </div>
    </section>
@endsection
