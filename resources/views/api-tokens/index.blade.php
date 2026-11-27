@extends('layouts.app')

@section('title', '資料 API')

@section('content')
    @php
        $baseUrl = url('/api/v1');
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <span aria-current="page">資料 API</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">資料 API</h1>
            <p class="page-description">建立唯讀 API 金鑰，讓 Looker Studio、Google Sheets（Apps Script）、Power BI 或自家系統讀取客戶的跨平台成效。金鑰只能讀取資料，權限與建立者相同。</p>
        </div>
    </div>

    @if (is_string($plainTextToken))
        <section class="panel spaced-top" aria-labelledby="api-token-created-title">
            <div class="panel-header">
                <h2 id="api-token-created-title" class="panel-title">新的 API 金鑰</h2>
            </div>
            <div class="panel-body">
                <p class="field-help">請立即複製並妥善保存，離開此頁後將無法再次查看。</p>
                <input class="field-input api-token-value" type="text" readonly value="{{ $plainTextToken }}" aria-label="新的 API 金鑰" spellcheck="false">
            </div>
        </section>
    @endif

    <section class="panel spaced-top" aria-labelledby="api-token-create-title">
        <div class="panel-header">
            <h2 id="api-token-create-title" class="panel-title">建立 API 金鑰</h2>
        </div>
        <div class="panel-body">
            <form action="{{ route('api-tokens.store') }}" method="POST" class="client-create-form" aria-labelledby="api-token-create-title">
                @csrf
                <div class="form-grid">
                    <div class="field-group">
                        <label class="field-label" for="api-token-name">名稱</label>
                        <input class="field-input{{ $errors->has('name') ? ' is-invalid' : '' }}" id="api-token-name" name="name" type="text" maxlength="80" required value="{{ old('name') }}" placeholder="例：Looker Studio 月報" @if ($errors->has('name')) aria-invalid="true" aria-describedby="api-token-name-error" @endif>
                        @error('name')<p id="api-token-name-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="api-token-expiry">有效期限</label>
                        <select class="field-input" id="api-token-expiry" name="expires_in">
                            @foreach ($expiryOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('expires_in', '90') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('expires_in')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="form-actions">
                    <button class="button button-primary" type="submit">建立金鑰</button>
                </div>
            </form>
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="api-token-list-title">
        <div class="panel-header">
            <h2 id="api-token-list-title" class="panel-title">已建立的金鑰</h2>
        </div>
        @if ($tokens->isEmpty())
            <p class="empty-copy panel-body">尚未建立 API 金鑰。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">API 金鑰的名稱、建立者、最後使用時間與到期日</caption>
                    <thead>
                        <tr>
                            <th scope="col">名稱</th>
                            <th scope="col">建立者</th>
                            <th scope="col">最後使用</th>
                            <th scope="col">到期</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr>
                                <th scope="row">{{ $token->name }}<span class="secondary-text">{{ $token->token_prefix }}…</span></th>
                                <td>{{ $token->user?->name ?? '—' }}</td>
                                <td>{{ $token->last_used_at?->timezone('Asia/Taipei')->format('Y/n/j H:i') ?? '尚未使用' }}</td>
                                <td>
                                    @if ($token->expires_at === null)
                                        不過期
                                    @else
                                        {{ $token->expires_at->timezone('Asia/Taipei')->format('Y/n/j') }}@if ($token->isExpired())（已過期）@endif
                                    @endif
                                </td>
                                <td>
                                    <form action="{{ route('api-tokens.destroy', $token) }}" method="POST" class="inline-form">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button button-quiet button-small" type="submit" aria-label="撤銷金鑰：{{ $token->name }}">撤銷</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="api-docs-title">
        <div class="panel-header">
            <h2 id="api-docs-title" class="panel-title">使用方式</h2>
        </div>
        <div class="panel-body">
            <p class="field-help">在 HTTP 標頭加上 <code>Authorization: Bearer &lt;API 金鑰&gt;</code>。期間參數 <code>from</code>、<code>to</code> 格式為 YYYY-MM-DD，最多 92 天，預設為近 30 天；每分鐘最多 60 次請求。</p>
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">可用的 API 端點</caption>
                    <thead>
                        <tr>
                            <th scope="col">端點</th>
                            <th scope="col">說明</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row"><code>GET {{ $baseUrl }}/clients</code></th>
                            <td>可讀取的客戶清單。</td>
                        </tr>
                        <tr>
                            <th scope="row"><code>GET {{ $baseUrl }}/clients/{id}/summary</code></th>
                            <td>期間內各平台花費、轉換、ROAS、GA4、實際營收與 MER、月預算進度，並與前期比較。</td>
                        </tr>
                        <tr>
                            <th scope="row"><code>GET {{ $baseUrl }}/clients/{id}/daily-performance</code></th>
                            <td>每日各平台花費、轉換與實際營收；加上 <code>?format=csv</code> 可下載 CSV。</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endsection
