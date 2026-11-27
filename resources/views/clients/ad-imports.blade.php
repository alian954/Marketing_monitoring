@extends('layouts.app')

@section('title', $client->name.'｜TikTok 與 LINE 廣告')

@section('content')
    @php
        $canManage = ! auth()->user()?->isReadOnlyWorkspaceMember();
        $importResult = session('importResult');
        $selectedPlatform = old('platform', array_key_first($platforms));
        $exportTips = [
            'tiktok_ads' => 'TikTok Ads Manager →「報表」→ 建立自訂報表，維度選「日期」與「廣告活動名稱」，指標選花費、曝光、點擊、轉換與轉換價值，匯出 CSV。',
            'line_ads' => 'LINE 廣告後台 →「成效報表」→ 依日期與廣告活動細分，匯出 CSV（Big5 或 UTF-8 皆可）。',
        ];
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">TikTok 與 LINE 廣告</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">{{ $client->name }}｜TikTok 與 LINE 廣告</h1>
            <p class="page-description">上傳廣告後台匯出的每日報表，數據會併入跨平台總覽、月預算進度與 AI 摘要。同一天、同一個廣告活動重複匯入時會以新檔案為準。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('clients.cross-platform', $client) }}">跨平台總覽</a>
            <a class="button button-secondary" href="{{ route('clients.index') }}">返回客戶</a>
        </div>
    </div>

    @if (is_array($importResult))
        <section class="panel spaced-top" aria-labelledby="ad-import-result-title">
            <div class="panel-header">
                <h2 id="ad-import-result-title" class="panel-title">匯入結果（{{ $platforms[$importResult['platform']]['label'] ?? '' }}）</h2>
            </div>
            <div class="panel-body">
                <ul class="insight-list">
                    <li>匯入 {{ number_format($importResult['imported']) }} 筆每日數據、{{ number_format($importResult['campaigns']) }} 個廣告活動@if ($importResult['from'] !== null)，日期 {{ str_replace('-', '/', $importResult['from']) }}–{{ str_replace('-', '/', $importResult['to']) }}@endif。</li>
                    @foreach ($importResult['spend_by_currency'] as $currencyCode => $spend)
                        <li>花費合計 {{ number_format($spend, 2) }} {{ $currencyCode }}</li>
                    @endforeach
                    @if ($importResult['truncated'])
                        <li class="field-error">檔案超過 {{ number_format(\App\Services\ClientAdPlatformMetricImporter::MAX_ROWS) }} 列，超出部分未匯入，請分批上傳。</li>
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
        <section class="panel spaced-top" aria-labelledby="ad-import-title">
            <div class="panel-header">
                <h2 id="ad-import-title" class="panel-title">匯入廣告報表</h2>
            </div>
            <div class="panel-body">
                <form action="{{ route('clients.ad-imports.store', $client) }}" method="POST" enctype="multipart/form-data" class="client-create-form" aria-labelledby="ad-import-title">
                    @csrf
                    <fieldset class="report-share-targets">
                        <legend class="field-label">廣告平台</legend>
                        <div class="report-share-target-list">
                            @foreach ($platforms as $platform => $definition)
                                <label class="report-share-target-option">
                                    <input type="radio" name="platform" value="{{ $platform }}" @checked($selectedPlatform === $platform) required>
                                    <span>
                                        <strong>{{ $definition['label'] }}</strong>
                                        <small>{{ $exportTips[$platform] ?? '' }}</small>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('platform')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </fieldset>
                    <div class="field-group">
                        <label class="field-label" for="ad-import-csv">CSV 檔案</label>
                        <input class="field-input{{ $errors->has('csv_file') ? ' is-invalid' : '' }}" id="ad-import-csv" name="csv_file" type="file" accept=".csv,text/csv" required aria-describedby="ad-import-csv-help{{ $errors->has('csv_file') ? ' ad-import-csv-error' : '' }}" @if ($errors->has('csv_file')) aria-invalid="true" @endif>
                        <p id="ad-import-csv-help" class="field-help">必要欄位：日期、花費（Cost）。選填：廣告活動名稱、曝光、點擊、轉換、轉換價值、幣別。中英文欄位名稱都能辨識，最多 5 MB。</p>
                        @error('csv_file')<p id="ad-import-csv-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="ad-import-currency">預設幣別</label>
                        <input class="field-input{{ $errors->has('currency_code') ? ' is-invalid' : '' }}" id="ad-import-currency" name="currency_code" type="text" maxlength="3" required autocapitalize="characters" spellcheck="false" value="{{ old('currency_code', $defaultCurrencyCode) }}">
                        @error('currency_code')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">匯入</button>
                    </div>
                </form>
            </div>
        </section>
    @endif

    <section class="panel spaced-top" aria-labelledby="ad-import-campaigns-title">
        <div class="panel-header">
            <div>
                <h2 id="ad-import-campaigns-title" class="panel-title">已匯入的廣告活動</h2>
                <p class="panel-description">依最近資料日期排序，顯示前 50 個。</p>
            </div>
        </div>
        @if ($campaigns->isEmpty())
            <p class="empty-copy panel-body">尚未匯入 TikTok 或 LINE 廣告數據。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">已匯入廣告活動的期間、花費與成效</caption>
                    <thead>
                        <tr>
                            <th scope="col">廣告活動</th>
                            <th scope="col">資料期間</th>
                            <th scope="col" class="numeric">花費</th>
                            <th scope="col" class="numeric">曝光</th>
                            <th scope="col" class="numeric">點擊</th>
                            <th scope="col" class="numeric">轉換</th>
                            <th scope="col" class="numeric">ROAS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $campaign)
                            @php $spendTotal = (float) $campaign->spend_total; @endphp
                            <tr>
                                <th scope="row">
                                    {{ $campaign->campaign_name }}
                                    <span class="secondary-text">{{ $platforms[$campaign->platform]['label'] ?? $campaign->platform }} · {{ number_format((int) $campaign->day_count) }} 天</span>
                                </th>
                                <td>{{ str_replace('-', '/', substr((string) $campaign->first_on, 0, 10)) }}–{{ str_replace('-', '/', substr((string) $campaign->last_on, 0, 10)) }}</td>
                                <td class="numeric">{{ number_format($spendTotal, 0) }} {{ $campaign->currency_code }}</td>
                                <td class="numeric">{{ number_format((int) $campaign->impressions_total) }}</td>
                                <td class="numeric">{{ number_format((int) $campaign->clicks_total) }}</td>
                                <td class="numeric">{{ number_format((float) $campaign->conversions_total, 1) }}</td>
                                <td class="numeric">{{ $spendTotal > 0 ? number_format((float) $campaign->value_total / $spendTotal, 2).' 倍' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($canManage && $campaigns->isNotEmpty())
        <section class="panel spaced-top" aria-labelledby="ad-import-delete-title">
            <div class="panel-header">
                <div>
                    <h2 id="ad-import-delete-title" class="panel-title">刪除匯入數據</h2>
                    <p class="panel-description">匯入錯誤時，可刪除指定平台與日期範圍的數據後重新匯入。刪除後無法復原。</p>
                </div>
            </div>
            <div class="panel-body">
                <form action="{{ route('clients.ad-imports.destroy', $client) }}" method="POST" class="client-create-form" aria-labelledby="ad-import-delete-title">
                    @csrf
                    @method('DELETE')
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="ad-delete-platform">平台</label>
                            <select class="field-input" id="ad-delete-platform" name="platform" required>
                                @foreach ($platforms as $platform => $definition)
                                    <option value="{{ $platform }}">{{ $definition['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="ad-delete-from">開始日期</label>
                            <input class="field-input" id="ad-delete-from" name="from" type="date" required>
                            @error('from')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="ad-delete-to">結束日期</label>
                            <input class="field-input" id="ad-delete-to" name="to" type="date" required>
                            @error('to')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="button button-quiet" type="submit">刪除此期間數據</button>
                    </div>
                </form>
            </div>
        </section>
    @endif
@endsection
