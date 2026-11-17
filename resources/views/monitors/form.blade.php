@extends('layouts.app')

@php
    $isEditing = strtoupper((string) $formMethod) !== 'POST';
    $maskedUrl = $monitor->getAttribute('masked_url');
    $redirectHostsValue = old('redirect_hosts');
    if ($redirectHostsValue === null) {
        $redirectHosts = $monitor->allowed_redirect_hosts;
        $redirectHostsValue = is_array($redirectHosts) ? implode("\n", $redirectHosts) : '';
    }
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
                            <label class="field-label" for="expected_text">預期回應文字</label>
                            <input class="field-input @error('expected_text') is-invalid @enderror" id="expected_text" name="expected_text" type="text" value="{{ old('expected_text', $monitor->expected_text) }}" maxlength="500" autocomplete="off" placeholder="選填；回應中必須包含的文字" aria-describedby="{{ $errors->has('expected_text') ? 'expected-text-help expected-text-error' : 'expected-text-help' }}">
                            <p id="expected-text-help" class="field-help">留空時只檢查 HTTP 回應。文字比對不區分大小寫。</p>
                            @error('expected_text')<p id="expected-text-error" class="field-error">{{ $message }}</p>@enderror
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
