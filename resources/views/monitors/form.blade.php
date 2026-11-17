@extends('layouts.app')

@php
    $isEditing = strtoupper((string) $formMethod) !== 'POST';
    $maskedUrl = $monitor->getAttribute('masked_url');
    $redirectHostsValue = old('redirect_hosts');
    if ($redirectHostsValue === null) {
        $redirectHosts = $monitor->allowed_redirect_hosts;
        $redirectHostsValue = is_array($redirectHosts) ? implode("\n", $redirectHosts) : '';
    }
    $acceptedStatusCodes = $monitor->accepted_status_codes;
    $acceptedStatusCodesDefault = is_array($acceptedStatusCodes) ? implode(', ', $acceptedStatusCodes) : '';
    $acceptedStatusCodesValue = old('accepted_status_codes', $acceptedStatusCodesDefault);
@endphp

@section('title', $isEditing ? '編輯監測項目' : '新增監測項目')

@section('content')
    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('monitors.index') }}">監測項目</a><span aria-hidden="true">/</span>
        <span aria-current="page">{{ $isEditing ? '編輯監測項目' : '新增監測項目' }}</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">監測設定</p>
            <h1 class="page-title">{{ $isEditing ? '編輯監測項目' : '新增監測項目' }}</h1>
            <p class="page-description">設定要檢查的網站，以及回應中應包含的內容。</p>
        </div>
    </div>

    <section class="panel form-panel" aria-labelledby="monitor-form-title">
        <div class="panel-header">
            <div>
                <h2 id="monitor-form-title" class="panel-title">網站資訊</h2>
                <p class="panel-description">檢查會使用具備重新導向與目的地保護的安全 HTTP 請求。</p>
            </div>
        </div>
        <div class="panel-body">
            <form action="{{ $formAction }}" method="POST">
                @csrf
                @if ($isEditing)
                    @method($formMethod)
                @endif

                <div class="form-section">
                    <div class="form-grid">
                        <div class="field-group field-group-full">
                            <label class="field-label" for="name">監測名稱<span class="required-mark" aria-hidden="true">*</span></label>
                            <input class="field-input @error('name') is-invalid @enderror" id="name" name="name" type="text" value="{{ old('name', $monitor->name) }}" maxlength="120" autocomplete="off" required aria-describedby="{{ $errors->has('name') ? 'name-help name-error' : 'name-help' }}">
                            <p id="name-help" class="field-help">請使用容易在總覽中辨識的簡短名稱。</p>
                            @error('name')<p id="name-error" class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group field-group-full">
                            <label class="field-label" for="url">網站網址 @unless ($isEditing)<span class="required-mark" aria-hidden="true">*</span>@endunless</label>
                            @if ($isEditing && $maskedUrl)
                                <p class="field-help">目前目的網址：<span class="masked-url">{{ $maskedUrl }}</span></p>
                            @endif
                            <input class="field-input @error('url') is-invalid @enderror" id="url" name="url" type="url" inputmode="url" autocomplete="url" maxlength="2048" placeholder="{{ $isEditing ? '留空即可保留目前網址' : 'https://example.com/health' }}" @unless ($isEditing) required @endunless aria-describedby="{{ $errors->has('url') ? 'url-help url-error' : 'url-help' }}">
                            <p id="url-help" class="field-help">完整網址會以加密方式儲存，儲存後只會顯示遮罩網址。編輯時留空即可保留目前網址。</p>
                            @error('url')<p id="url-error" class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="label">標籤</label>
                            <input class="field-input @error('label') is-invalid @enderror" id="label" name="label" type="text" value="{{ old('label', $monitor->label) }}" maxlength="100" autocomplete="off" placeholder="正式環境、API、商店" aria-describedby="{{ $errors->has('label') ? 'label-error' : '' }}">
                            @error('label')<p id="label-error" class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group field-group-full">
                            <label class="field-label" for="accepted_status_codes">額外接受的 HTTP 狀態碼</label>
                            <input class="field-input @error('accepted_status_codes') is-invalid @enderror" id="accepted_status_codes" name="accepted_status_codes" type="text" value="{{ $acceptedStatusCodesValue }}" maxlength="1000" autocomplete="off" placeholder="例如：401, 404 429" aria-describedby="{{ $errors->has('accepted_status_codes') ? 'accepted-status-codes-help accepted-status-codes-error' : 'accepted-status-codes-help' }}" @if ($errors->has('accepted_status_codes')) aria-invalid="true" @endif>
                            <p id="accepted-status-codes-help" class="field-help">選填，輸入以逗號或空白分隔的 400–599 狀態碼。預設只接受 200–399；加入 429 後，429 會視為預期回應，不會觸發限制警告。</p>
                            @error('accepted_status_codes')<p id="accepted-status-codes-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="interval_seconds">檢查頻率</label>
                            <select class="field-select @error('interval_seconds') is-invalid @enderror" id="interval_seconds" name="interval_seconds" required @if ($errors->has('interval_seconds')) aria-invalid="true" @endif aria-describedby="{{ $errors->has('interval_seconds') ? 'interval-help interval-error' : 'interval-help' }}">
                                @foreach ($intervalOptions as $seconds => $label)
                                    <option value="{{ $seconds }}" @selected((string) old('interval_seconds', $monitor->interval_seconds ?? config('monitoring.interval_seconds')) === (string) $seconds)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p id="interval-help" class="field-help">檢查越頻繁，發出的請求越多。手動檢查不會更改排程頻率。</p>
                            @error('interval_seconds')<p id="interval-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group field-group-full">
                            <label class="field-label" for="expected_text">預期回應文字</label>
                            <input class="field-input @error('expected_text') is-invalid @enderror" id="expected_text" name="expected_text" type="text" value="{{ old('expected_text', $monitor->expected_text) }}" maxlength="500" autocomplete="off" placeholder="選填；要比對的文字" aria-describedby="{{ $errors->has('expected_text') ? 'expected-text-help expected-text-error' : 'expected-text-help' }}">
                            <p id="expected-text-help" class="field-help">留空時不比對文字。比對不區分大小寫。</p>
                            @error('expected_text')<p id="expected-text-error" class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="expected_text_mode">文字符合條件</label>
                            <select class="field-select @error('expected_text_mode') is-invalid @enderror" id="expected_text_mode" name="expected_text_mode" required aria-describedby="{{ $errors->has('expected_text_mode') ? 'expected-text-mode-help expected-text-mode-error' : 'expected-text-mode-help' }}" @if ($errors->has('expected_text_mode')) aria-invalid="true" @endif>
                                <option value="present" @selected(old('expected_text_mode', $monitor->expected_text_mode ?? 'present') === 'present')>回應中必須包含</option>
                                <option value="absent" @selected(old('expected_text_mode', $monitor->expected_text_mode ?? 'present') === 'absent')>回應中不得包含</option>
                            </select>
                            <p id="expected-text-mode-help" class="field-help">只在上方輸入文字時套用這項條件。</p>
                            @error('expected_text_mode')<p id="expected-text-mode-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="response_time_threshold_ms">回應時間警示門檻（毫秒）</label>
                            <input class="field-input @error('response_time_threshold_ms') is-invalid @enderror" id="response_time_threshold_ms" name="response_time_threshold_ms" type="number" inputmode="numeric" min="100" max="9000" step="1" value="{{ old('response_time_threshold_ms', $monitor->response_time_threshold_ms) }}" aria-invalid="{{ $errors->has('response_time_threshold_ms') ? 'true' : 'false' }}" aria-describedby="{{ $errors->has('response_time_threshold_ms') ? 'response-time-threshold-help response-time-threshold-error' : 'response-time-threshold-help' }}">
                            <p id="response-time-threshold-help" class="field-help">留空即可停用。一般 HTTP 檢查成功且耗時超過 100–9000 毫秒門檻時，系統會建立警告事件。</p>
                            @error('response_time_threshold_ms')<p id="response-time-threshold-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                    </div>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">允許的重新導向主機</h3>
                    <p class="form-section-copy">只有在網站預期會重新導向至其他主機時才需新增。檢查不會跟隨清單以外的主機。</p>
                    <div class="field-group">
                        <label class="field-label" for="redirect_hosts">主機名稱</label>
                        <textarea class="field-textarea @error('redirect_hosts') is-invalid @enderror" id="redirect_hosts" name="redirect_hosts" rows="3" autocomplete="off" placeholder="www.example.com&#10;login.example.com" aria-describedby="{{ $errors->has('redirect_hosts') ? 'redirect-hosts-help redirect-hosts-error' : 'redirect-hosts-help' }}">{{ $redirectHostsValue }}</textarea>
                        <p id="redirect-hosts-help" class="field-help">每行輸入一個主機名稱，也可用逗號分隔。請勿包含通訊協定或路徑。</p>
                        @error('redirect_hosts')<p id="redirect-hosts-error" class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                @if ($isEditing)
                    <div class="form-note spaced-top">
                        <strong>儲存設定會建立新的版本。</strong> 舊版本排隊中的檢查會標記為過期。
                    </div>
                @endif

                <div class="form-actions">
                    <a class="button button-secondary" href="{{ $isEditing ? route('monitors.show', $monitor) : route('monitors.index') }}">取消</a>
                    <button class="button button-primary" type="submit">{{ $isEditing ? '儲存變更' : '新增監測項目' }}</button>
                </div>
            </form>
        </div>
    </section>
@endsection
