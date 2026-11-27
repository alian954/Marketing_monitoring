@extends('layouts.app')

@section('title', 'Google Ads 整合')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">資料來源</p>
            <h1>Google Ads</h1>
            <p class="page-description">連結 Google 帳戶，並為各客戶分別設定 Ads 客戶帳戶。</p>
        </div>
    </div>

    <section class="panel" aria-labelledby="google-ads-connection-title">
        <div class="panel-header">
            <div>
                <h2 id="google-ads-connection-title" class="panel-title">帳戶連線</h2>
                <p class="panel-description">本應用只讀取廣告報表與客戶資料，不會修改廣告設定。</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($errors->has('google_ads'))
                <p class="alert alert-error" role="alert">{{ $errors->first('google_ads') }}</p>
            @endif
            @include('integrations.partials.oauth-application-credentials', ['providerCode' => 'google_ads', 'providerName' => 'Google Ads'])
            @if ($oauthConfigured)
                <form action="{{ route('integrations.google-ads.connect') }}" method="POST">
                    @csrf
                    <button class="button button-primary" type="submit">{{ $connection ? '重新連結 Google 帳戶' : '連結 Google 帳戶' }}</button>
                </form>
            @else
                <p class="field-help">儲存 OAuth App 憑證後即可連結 Google Ads。</p>
            @endif

            @if ($connection)
                <p class="connection-status" role="status">Google Ads 帳戶已連結。</p>
                <x-scheduled-sync-health :connection="$connection" daily-sync-time="06:50" />
                <p class="panel-description">每個客戶需設定自己的 10 位 Google Ads 客戶 ID；若經管理員帳戶存取，也要為該客戶填入管理員 ID。系統會向 Google 驗證後儲存幣別。</p>

                <div class="client-ga4-properties">
                    @foreach ($clients as $client)
                        @php
                            $account = $clientAccounts->get($client->getKey());
                            $isSubmittedClient = (string) old('client_id') === (string) $client->getKey();
                            $errorsForForm = $errors->getBag('googleAds');
                            $hasCustomerError = $isSubmittedClient && $errorsForForm->has('customer_id');
                            $hasManagerError = $isSubmittedClient && $errorsForForm->has('login_customer_id');
                            $formTitleId = 'google-ads-client-title-'.$client->getKey();
                            $customerFieldId = 'google-ads-customer-id-'.$client->getKey();
                            $customerHelpId = 'google-ads-customer-help-'.$client->getKey();
                            $customerErrorId = 'google-ads-customer-error-'.$client->getKey();
                            $managerFieldId = 'google-ads-manager-id-'.$client->getKey();
                            $managerHelpId = 'google-ads-manager-help-'.$client->getKey();
                            $managerErrorId = 'google-ads-manager-error-'.$client->getKey();
                        @endphp
                        <form action="{{ route('integrations.google-ads.clients.customer.update', $client) }}" method="POST" class="check-history-filter" aria-labelledby="{{ $formTitleId }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                            <div class="field-group">
                                <h3 id="{{ $formTitleId }}" class="panel-title">{{ $client->name }}</h3>
                                <label class="field-label" for="{{ $customerFieldId }}">Google Ads 客戶 ID</label>
                                <p id="{{ $customerHelpId }}" class="field-help">輸入 10 位數字，可含連字號；系統會先向 Google 驗證。</p>
                                <input class="field-input{{ $hasCustomerError ? ' is-invalid' : '' }}" id="{{ $customerFieldId }}" name="customer_id" type="text" inputmode="numeric" pattern="[0-9]{3}-?[0-9]{3}-?[0-9]{4}" maxlength="12" value="{{ $isSubmittedClient ? old('customer_id', $account?->customer_id) : $account?->customer_id }}" required autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="{{ $customerHelpId }}{{ $hasCustomerError ? ' '.$customerErrorId : '' }}" @if ($hasCustomerError) aria-invalid="true" @endif>
                                @if ($hasCustomerError)
                                    <p id="{{ $customerErrorId }}" class="field-error" role="alert">{{ $errorsForForm->first('customer_id') }}</p>
                                @endif
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="{{ $managerFieldId }}">管理員客戶 ID（選填）</label>
                                <p id="{{ $managerHelpId }}" class="field-help">透過管理員帳戶讀取時填入 10 位 ID；直接存取時留白。</p>
                                <input class="field-input{{ $hasManagerError ? ' is-invalid' : '' }}" id="{{ $managerFieldId }}" name="login_customer_id" type="text" inputmode="numeric" pattern="[0-9]{3}-?[0-9]{3}-?[0-9]{4}" maxlength="12" value="{{ $isSubmittedClient ? old('login_customer_id', $account?->login_customer_id) : $account?->login_customer_id }}" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="{{ $managerHelpId }}{{ $hasManagerError ? ' '.$managerErrorId : '' }}" @if ($hasManagerError) aria-invalid="true" @endif>
                                @if ($hasManagerError)
                                    <p id="{{ $managerErrorId }}" class="field-error" role="alert">{{ $errorsForForm->first('login_customer_id') }}</p>
                                @endif
                            </div>
                            @if ($account !== null)
                                <p class="connection-status">目前客戶：{{ $account->customer_name }}（{{ $account->customer_id }} · {{ $account->currency_code }}）</p>
                            @else
                                <p class="field-help">待設定客戶帳戶。</p>
                            @endif
                            <button class="button button-secondary" type="submit">驗證並儲存 {{ $client->name }} 帳戶</button>
                        </form>
                    @endforeach
                </div>

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
            <p class="panel-description">活動報告只讀取該客戶已驗證帳戶中的活動；日期區間依 Google Ads 帳戶時區計算。未設定帳戶的客戶不會送出同步請求。</p>
            <p class="panel-description">轉換與轉換價值依 Google Ads 帳戶的轉換設定歸因，不等同 GA4 工作階段或營收；跨平台花費只合併幣別相同的來源。</p>
            <p class="panel-description">正式客戶資料需先取得 Google Ads API production access。<a href="https://developers.google.com/google-ads/api/docs/api-policy/developer-token">官方存取說明</a></p>
        </div>
    </section>
@endsection
