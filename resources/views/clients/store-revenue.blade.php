@extends('layouts.app')

@section('title', $client->name.'｜電商營收')

@section('content')
    @php
        $canManage = ! auth()->user()?->isReadOnlyWorkspaceMember();
        $importResult = session('importResult');
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">電商營收</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">{{ $client->name }}｜電商營收</h1>
            <p class="page-description">匯入商店後台的訂單 CSV，跨平台總覽會用實際營收計算 MER（營收 ÷ 總廣告花費）與新客取得成本，並對照各廣告平台自己回報的轉換價值。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('clients.cross-platform', $client) }}">跨平台總覽</a>
            <a class="button button-secondary" href="{{ route('clients.index') }}">返回客戶</a>
        </div>
    </div>

    @if (is_array($importResult))
        <section class="panel spaced-top" aria-labelledby="store-import-result-title">
            <div class="panel-header">
                <h2 id="store-import-result-title" class="panel-title">匯入結果</h2>
            </div>
            <div class="panel-body">
                <ul class="insight-list">
                    <li>{{ $importResult['mode'] === 'orders' ? '依訂單編號匯入，重複匯入同一訂單會更新而不會重複計算。' : '檔案沒有訂單編號欄位，已依日期加總為每日營收。' }}</li>
                    <li>匯入 {{ number_format($importResult['imported']) }} 筆@if ($importResult['from'] !== null)，日期 {{ str_replace('-', '/', $importResult['from']) }}–{{ str_replace('-', '/', $importResult['to']) }}@endif。</li>
                    @foreach ($importResult['revenue_by_currency'] as $currencyCode => $revenue)
                        <li>營收合計 {{ number_format($revenue, 2) }} {{ $currencyCode }}</li>
                    @endforeach
                    @if ($importResult['skipped_cancelled'] > 0)
                        <li>略過 {{ number_format($importResult['skipped_cancelled']) }} 筆已取消或退款的訂單。</li>
                    @endif
                    @if ($importResult['truncated'])
                        <li class="field-error">檔案超過 {{ number_format(\App\Services\ClientStoreOrderImporter::MAX_ROWS) }} 列，超出部分未匯入，請分批上傳。</li>
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

    @if ($canManage)
        <section class="panel spaced-top" aria-labelledby="store-import-title">
            <div class="panel-header">
                <div>
                    <h2 id="store-import-title" class="panel-title">匯入訂單 CSV</h2>
                    <p class="panel-description">支援 Shopify、WooCommerce、SHOPLINE、91APP、CYBERBIZ 等後台匯出的訂單檔，或自行整理的每日營收表。系統會自動辨識中英文欄位名稱與 Big5 編碼。</p>
                </div>
            </div>
            <div class="panel-body">
                <form action="{{ route('clients.store-revenue.store', $client) }}" method="POST" enctype="multipart/form-data" class="client-create-form" aria-labelledby="store-import-title">
                    @csrf
                    <div class="field-group">
                        <label class="field-label" for="store-csv">CSV 檔案</label>
                        <input class="field-input{{ $errors->has('csv_file') ? ' is-invalid' : '' }}" id="store-csv" name="csv_file" type="file" accept=".csv,text/csv" required aria-describedby="store-csv-help{{ $errors->has('csv_file') ? ' store-csv-error' : '' }}" @if ($errors->has('csv_file')) aria-invalid="true" @endif>
                        <p id="store-csv-help" class="field-help">必要欄位：訂單日期、訂單金額。選填：訂單編號（建議，可避免重複計算）、訂單狀態（取消與退款會略過）、新客、幣別、訂單數。最多 5 MB、{{ number_format(\App\Services\ClientStoreOrderImporter::MAX_ROWS) }} 列。</p>
                        @error('csv_file')<p id="store-csv-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="store-currency">預設幣別</label>
                            <input class="field-input{{ $errors->has('currency_code') ? ' is-invalid' : '' }}" id="store-currency" name="currency_code" type="text" maxlength="3" required autocapitalize="characters" spellcheck="false" value="{{ old('currency_code', $defaultCurrencyCode) }}" aria-describedby="store-currency-help">
                            <p id="store-currency-help" class="field-help">檔案沒有幣別欄位時使用。</p>
                            @error('currency_code')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="store-source">來源名稱（選填）</label>
                            <input class="field-input" id="store-source" name="source_label" type="text" maxlength="60" value="{{ old('source_label') }}" placeholder="例：官網 SHOPLINE">
                            @error('source_label')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">匯入</button>
                    </div>
                </form>
            </div>
        </section>
    @endif

    <section class="panel spaced-top" aria-labelledby="store-month-title">
        <div class="panel-header">
            <div>
                <h2 id="store-month-title" class="panel-title">已匯入營收</h2>
                <p class="panel-description">
                    共 {{ number_format($orderCount) }} 筆
                    @if ($lastImportedAt !== null) · 最後匯入 {{ \Illuminate\Support\Carbon::parse($lastImportedAt)->timezone('Asia/Taipei')->format('Y/n/j H:i') }}@endif
                    · 顯示最近 12 個月
                </p>
            </div>
        </div>
        @if ($months->isEmpty())
            <p class="empty-copy panel-body">尚未匯入營收資料。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">每月匯入的營收、訂單數與新客訂單數</caption>
                    <thead>
                        <tr>
                            <th scope="col">月份</th>
                            <th scope="col" class="numeric">營收</th>
                            <th scope="col" class="numeric">訂單數</th>
                            <th scope="col" class="numeric">新客訂單</th>
                            <th scope="col" class="numeric">平均客單價</th>
                            <th scope="col">資料日期</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($months as $month)
                            <tr>
                                <th scope="row">{{ $month->month }}</th>
                                <td class="numeric">{{ number_format((float) $month->revenue_total, 0) }} {{ $month->currency_code }}</td>
                                <td class="numeric">{{ number_format((int) $month->orders_total) }}</td>
                                <td class="numeric">{{ (int) $month->new_known > 0 ? number_format((int) $month->new_total) : '—' }}</td>
                                <td class="numeric">{{ (int) $month->orders_total > 0 ? number_format((float) $month->revenue_total / (int) $month->orders_total, 0) : '—' }}</td>
                                <td>{{ str_replace('-', '/', substr((string) $month->first_on, 0, 10)) }}–{{ str_replace('-', '/', substr((string) $month->last_on, 0, 10)) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($canManage && $orderCount > 0)
        <section class="panel spaced-top" aria-labelledby="store-delete-title">
            <div class="panel-header">
                <div>
                    <h2 id="store-delete-title" class="panel-title">刪除營收資料</h2>
                    <p class="panel-description">匯入錯誤時，可刪除指定日期範圍的資料後重新匯入。刪除後無法復原。</p>
                </div>
            </div>
            <div class="panel-body">
                <form action="{{ route('clients.store-revenue.destroy', $client) }}" method="POST" class="client-create-form" aria-labelledby="store-delete-title">
                    @csrf
                    @method('DELETE')
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="store-delete-from">開始日期</label>
                            <input class="field-input" id="store-delete-from" name="from" type="date" required>
                            @error('from')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="store-delete-to">結束日期</label>
                            <input class="field-input" id="store-delete-to" name="to" type="date" required>
                            @error('to')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="button button-quiet" type="submit">刪除此期間資料</button>
                    </div>
                </form>
            </div>
        </section>
    @endif
@endsection
