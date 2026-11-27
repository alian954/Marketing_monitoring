@extends('layouts.app')

@section('title', $client->name.'｜名單成效')

@section('content')
    @php
        $canManage = ! auth()->user()?->isReadOnlyWorkspaceMember();
        $importResult = session('importResult');
        $formatDate = static fn (string $date): string => str_replace('-', '/', $date);
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">名單成效</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">{{ $client->name }}｜名單成效</h1>
            <p class="page-description">匯入 CRM 或表單工具的名單，依來源計算有效名單率、成交率與每個階段的成本。適合房產、教育、B2B 等以名單為目標的廣告。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('clients.cross-platform', ['client' => $client, ...$period]) }}">跨平台總覽</a>
            <a class="button button-secondary" href="{{ route('clients.index') }}">返回客戶</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="lead-period-title">
        <div class="panel-header">
            <h2 id="lead-period-title" class="panel-title">名單建立期間</h2>
        </div>
        <div class="panel-body">
            <form action="{{ route('clients.leads.index', $client) }}" method="GET" aria-labelledby="lead-period-title">
                <div class="form-grid">
                    <div class="field-group">
                        <label class="field-label" for="lead-from">開始日期</label>
                        <input class="field-input" id="lead-from" name="from" type="date" value="{{ $period['from'] }}" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="lead-to">結束日期</label>
                        <input class="field-input" id="lead-to" name="to" type="date" value="{{ $period['to'] }}" required>
                    </div>
                </div>
                @error('from')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                <div class="form-actions">
                    <button class="button button-secondary" type="submit">更新</button>
                    <a class="strong-link" href="{{ route('clients.leads.index', $client) }}">近 30 天</a>
                </div>
            </form>
        </div>
    </section>

    @if (is_array($importResult))
        <section class="panel spaced-top" aria-labelledby="lead-import-result-title">
            <div class="panel-header">
                <h2 id="lead-import-result-title" class="panel-title">匯入結果</h2>
            </div>
            <div class="panel-body">
                <ul class="insight-list">
                    <li>匯入 {{ number_format($importResult['imported']) }} 筆名單@if ($importResult['from'] !== null)，建立日期 {{ $formatDate($importResult['from']) }}–{{ $formatDate($importResult['to']) }}@endif。</li>
                    <li>
                        @foreach ($importResult['stages'] as $stage => $count)
                            {{ \App\Models\ClientLead::STAGE_LABELS[$stage] }} {{ number_format($count) }}@if (! $loop->last)、@endif
                        @endforeach
                    </li>
                    @if ($importResult['truncated'])
                        <li class="field-error">檔案超過 {{ number_format(\App\Services\ClientLeadImporter::MAX_ROWS) }} 列，超出部分未匯入。</li>
                    @endif
                </ul>
                @if ($importResult['error_count'] > 0)
                    <p class="field-error spaced-top" role="alert">{{ number_format($importResult['error_count']) }} 列無法匯入：</p>
                    <ul class="insight-list">
                        @foreach ($importResult['errors'] as $error)
                            <li>第 {{ $error['line'] }} 列：{{ $error['message'] }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    @endif

    <section class="panel spaced-top" aria-labelledby="lead-funnel-title">
        <div class="panel-header">
            <div>
                <h2 id="lead-funnel-title" class="panel-title">名單漏斗（{{ $funnel['currency_code'] ?? '—' }}）</h2>
                <p class="panel-description">{{ $formatDate($period['from']) }}–{{ $formatDate($period['to']) }} 建立的名單，依最新匯入的狀態計算；花費取自同期廣告數據。共保存 {{ number_format($leadCount) }} 筆名單。</p>
            </div>
        </div>
        @if (! $funnel['has_data'])
            <p class="empty-copy panel-body">此期間沒有名單資料。</p>
        @else
            @include('clients.partials.lead-funnel-table', ['funnel' => $funnel])
        @endif
    </section>

    @if ($canManage)
        <section class="panel spaced-top" aria-labelledby="lead-import-title">
            <div class="panel-header">
                <div>
                    <h2 id="lead-import-title" class="panel-title">匯入名單 CSV</h2>
                    <p class="panel-description">同一筆名單重複匯入時會更新狀態，可每週匯出 CRM 最新狀態覆蓋。Email 與電話只用雜湊值辨識，不會保存。</p>
                </div>
            </div>
            <div class="panel-body">
                <form action="{{ route('clients.leads.store', $client) }}" method="POST" enctype="multipart/form-data" class="client-create-form" aria-labelledby="lead-import-title">
                    @csrf
                    <div class="field-group">
                        <label class="field-label" for="lead-csv">CSV 檔案</label>
                        <input class="field-input{{ $errors->has('csv_file') ? ' is-invalid' : '' }}" id="lead-csv" name="csv_file" type="file" accept=".csv,text/csv" required aria-describedby="lead-csv-help{{ $errors->has('csv_file') ? ' lead-csv-error' : '' }}" @if ($errors->has('csv_file')) aria-invalid="true" @endif>
                        <p id="lead-csv-help" class="field-help">必要欄位：建立日期，以及名單編號、Email 或電話其中一個。建議加上：來源（utm_source）、活動名稱、狀態（有效／無效／成交）、成交金額、成交日期、gclid。</p>
                        @error('csv_file')<p id="lead-csv-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="lead-currency">成交金額幣別</label>
                        <input class="field-input" id="lead-currency" name="currency_code" type="text" maxlength="3" required autocapitalize="characters" spellcheck="false" value="{{ old('currency_code', $defaultCurrencyCode) }}">
                        @error('currency_code')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">匯入</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="panel spaced-top" aria-labelledby="lead-offline-title">
            <div class="panel-header">
                <div>
                    <h2 id="lead-offline-title" class="panel-title">Google Ads 離線轉換檔</h2>
                    <p class="panel-description">把期間內成交且帶有 gclid 的名單匯出成 Google Ads「轉換 → 上傳」可用的格式，讓智慧出價學習真正成交的名單。目前有 {{ number_format($exportableCount) }} 筆可匯出。</p>
                </div>
            </div>
            <div class="panel-body">
                <form action="{{ route('clients.leads.offline-conversions', $client) }}" method="GET" class="client-create-form" aria-labelledby="lead-offline-title">
                    <input type="hidden" name="from" value="{{ $period['from'] }}">
                    <input type="hidden" name="to" value="{{ $period['to'] }}">
                    <div class="field-group">
                        <label class="field-label" for="lead-conversion-name">離線轉換動作名稱</label>
                        <input class="field-input{{ $errors->has('conversion_name') ? ' is-invalid' : '' }}" id="lead-conversion-name" name="conversion_name" type="text" maxlength="100" required placeholder="與 Google Ads 轉換動作名稱完全相同">
                        @error('conversion_name')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-actions">
                        <button class="button button-secondary" type="submit" @disabled($exportableCount === 0)>下載 CSV</button>
                    </div>
                </form>
            </div>
        </section>

        @if ($leadCount > 0)
            <section class="panel spaced-top" aria-labelledby="lead-delete-title">
                <div class="panel-header">
                    <div>
                        <h2 id="lead-delete-title" class="panel-title">刪除名單</h2>
                        <p class="panel-description">刪除指定建立日期範圍的名單，刪除後無法復原。</p>
                    </div>
                </div>
                <div class="panel-body">
                    <form action="{{ route('clients.leads.destroy', $client) }}" method="POST" class="client-create-form" aria-labelledby="lead-delete-title">
                        @csrf
                        @method('DELETE')
                        <div class="form-grid">
                            <div class="field-group">
                                <label class="field-label" for="lead-delete-from">開始日期</label>
                                <input class="field-input" id="lead-delete-from" name="from" type="date" required>
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="lead-delete-to">結束日期</label>
                                <input class="field-input" id="lead-delete-to" name="to" type="date" required>
                                @error('to')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <div class="form-actions">
                            <button class="button button-quiet" type="submit">刪除此期間名單</button>
                        </div>
                    </form>
                </div>
            </section>
        @endif
    @endif
@endsection
