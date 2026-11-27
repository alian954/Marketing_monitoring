@php
    $oauthApplicationErrors = $errors->getBag('oauthApplication');
    $oauthClientIdFieldId = 'oauth-client-id-'.$providerCode;
    $oauthClientSecretFieldId = 'oauth-client-secret-'.$providerCode;
@endphp

<div class="integration-oauth-credentials" aria-labelledby="{{ $oauthClientIdFieldId }}-heading">
    <h3 id="{{ $oauthClientIdFieldId }}-heading" class="panel-title">{{ $providerName }} OAuth App</h3>
    <p class="field-help">憑證僅供此工作區使用；用戶端密鑰會加密保存且不會再次顯示。</p>

    @if ($oauthCredentialSource === 'server')
        <p class="field-help">目前使用舊版伺服器憑證；儲存後會改用此工作區的憑證。</p>
    @elseif ($hasWorkspaceCredential)
        <p class="connection-status" role="status">已設定工作區憑證。變更用戶端時，可能需要重新連結帳戶。</p>
    @else
        <p class="field-help">尚未設定 OAuth App。請貼上此平台 OAuth App 的用戶端 ID 與密鑰。</p>
    @endif

    <form action="{{ route('integrations.oauth-application.update', $providerCode) }}" method="POST" class="check-history-filter" aria-labelledby="{{ $oauthClientIdFieldId }}-heading">
        @csrf
        @method('PUT')
        <div class="field-group">
            <label class="field-label" for="{{ $oauthClientIdFieldId }}">用戶端 ID（必填）</label>
            <input
                class="field-input{{ $oauthApplicationErrors->has('oauth_client_id') ? ' is-invalid' : '' }}"
                id="{{ $oauthClientIdFieldId }}"
                name="oauth_client_id"
                type="text"
                maxlength="255"
                value="{{ old('oauth_client_id', $oauthClientId) }}"
                autocomplete="off"
                autocorrect="off"
                autocapitalize="none"
                spellcheck="false"
                @if ($oauthApplicationErrors->has('oauth_client_id')) autofocus @endif
                required
                @if ($oauthApplicationErrors->has('oauth_client_id')) aria-invalid="true" aria-describedby="{{ $oauthClientIdFieldId }}-error" @endif
            >
            @if ($oauthApplicationErrors->has('oauth_client_id'))
                <p id="{{ $oauthClientIdFieldId }}-error" class="field-error" role="alert">{{ $oauthApplicationErrors->first('oauth_client_id') }}</p>
            @endif
        </div>
        <div class="field-group">
            <label class="field-label" for="{{ $oauthClientSecretFieldId }}">用戶端密鑰（{{ $hasWorkspaceCredential ? '選填' : '必填' }}）</label>
            <input
                class="field-input{{ $oauthApplicationErrors->has('oauth_client_secret') ? ' is-invalid' : '' }}"
                id="{{ $oauthClientSecretFieldId }}"
                name="oauth_client_secret"
                type="password"
                maxlength="4096"
                value=""
                autocomplete="new-password"
                @if (! $oauthApplicationErrors->has('oauth_client_id') && $oauthApplicationErrors->has('oauth_client_secret')) autofocus @endif
                @if (! $hasWorkspaceCredential) required @endif
                @if ($oauthApplicationErrors->has('oauth_client_secret')) aria-invalid="true" aria-describedby="{{ $oauthClientSecretFieldId }}-help {{ $oauthClientSecretFieldId }}-error" @else aria-describedby="{{ $oauthClientSecretFieldId }}-help" @endif
            >
            <p id="{{ $oauthClientSecretFieldId }}-help" class="field-help">{{ $hasWorkspaceCredential ? '用戶端 ID 不變時可留空保留原密鑰；更換 ID 時請輸入新密鑰。' : '輸入 OAuth App 提供的密鑰。' }}</p>
            @if ($oauthApplicationErrors->has('oauth_client_secret'))
                <p id="{{ $oauthClientSecretFieldId }}-error" class="field-error" role="alert">{{ $oauthApplicationErrors->first('oauth_client_secret') }}</p>
            @endif
        </div>
        <p class="field-help">請在平台 OAuth App 設定中加入重新導向 URI：<code>{{ $redirectUri }}</code></p>
        <div class="campaign-spend-form-actions">
            <button class="button button-secondary" type="submit">儲存憑證</button>
        </div>
    </form>

    @if ($hasWorkspaceCredential)
        <form action="{{ route('integrations.oauth-application.destroy', $providerCode) }}" method="POST" class="integration-disconnect-form" aria-labelledby="{{ $oauthClientIdFieldId }}-heading">
            @csrf
            @method('DELETE')
            <button class="button button-secondary" type="submit">移除工作區憑證</button>
        </form>
    @endif
</div>
