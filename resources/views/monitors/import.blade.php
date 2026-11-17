@extends('layouts.app')

@section('title', '匯入監測項目')

@section('content')
    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('monitors.index') }}">監測項目</a><span aria-hidden="true">/</span>
        <span aria-current="page">匯入 CSV</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">批次設定</p>
            <h1 class="page-title">匯入監測項目</h1>
            <p class="page-description">上傳 CSV 後逐列檢視，確認可匯入的監測項目後才會儲存。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('monitors.export') }}">下載目前清單</a>
        </div>
    </div>

    <section class="panel form-panel" aria-labelledby="csv-upload-title">
        <div class="panel-header">
            <div>
                <h2 id="csv-upload-title" class="panel-title">CSV 檔案</h2>
                <p class="panel-description">最多 500 列。第一列必須包含完全相同的欄位名稱 <code>name,url,label</code>。</p>
            </div>
        </div>
        <div class="panel-body">
            <form action="{{ route('monitors.import.preview') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="field-group">
                    <label class="field-label" for="csv_file">選擇 CSV 檔案<span class="required-mark" aria-hidden="true">*</span></label>
                    <input class="field-input @error('csv_file') is-invalid @enderror" id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required aria-describedby="{{ $errors->has('csv_file') ? 'csv-help csv-file-error' : 'csv-help' }}">
                    <p id="csv-help" class="field-help">必要欄位：<code>name</code>、<code>url</code> 與 <code>label</code>。網址會保留於伺服器端，預覽時僅顯示遮罩後的網址。</p>
                    @error('csv_file')<p id="csv-file-error" class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="form-actions">
                    <a class="button button-secondary" href="{{ route('monitors.index') }}">取消</a>
                    <button class="button button-primary" type="submit">預覽匯入內容</button>
                </div>
            </form>
        </div>
    </section>

    @isset($previewRows)
        @php
            $rowCount = count($previewRows);
            $canCommit = isset($previewToken) && ! empty($previewToken) && ($validCount ?? 0) > 0;
        @endphp

        <section class="panel import-preview" aria-labelledby="preview-title">
            <div class="panel-header">
                <div>
                    <h2 id="preview-title" class="panel-title">檢視匯入預覽</h2>
                    <p class="panel-description">已解析 {{ $rowCount }} 列。確認前請檢查錯誤與重複項目。</p>
                </div>
            </div>
            <div class="panel-body">
                <div class="import-summary" aria-label="預覽統計">
                    <span class="summary-chip is-good">{{ $validCount ?? 0 }} 列可匯入</span>
                    <span class="summary-chip is-bad">{{ $invalidCount ?? 0 }} 列需處理</span>
                </div>

                @if (count($previewRows) === 0)
                    <div class="empty-state">
                        <h3 class="empty-title">找不到資料列</h3>
                        <p class="empty-copy">檔案中沒有可供預覽的監測資料。</p>
                    </div>
                @else
                    <div class="data-table-wrap preview-table-frame">
                        <table class="data-table">
                            <caption class="sr-only">CSV 資料列、遮罩目的網址與匯入資格</caption>
                            <thead>
                                <tr>
                                    <th scope="col">列號</th>
                                    <th scope="col">監測名稱</th>
                                    <th scope="col">目的網址</th>
                                    <th scope="col">標籤</th>
                                    <th scope="col">檢查結果</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($previewRows as $previewRow)
                                    @php
                                        $rowErrors = data_get($previewRow, 'errors', []);
                                        $rowErrors = is_array($rowErrors) ? $rowErrors : ($rowErrors ? [$rowErrors] : []);
                                        $isDuplicate = (bool) data_get($previewRow, 'duplicate', false);
                                    @endphp
                                    <tr>
                                        <td class="numeric">{{ data_get($previewRow, 'line', '—') }}</td>
                                        <td>{{ data_get($previewRow, 'name', '—') }}</td>
                                        <td><span class="masked-url">{{ data_get($previewRow, 'masked_url', '—') }}</span></td>
                                        <td>{{ data_get($previewRow, 'label') ?: '—' }}</td>
                                        <td>
                                            @if ($isDuplicate)
                                                <x-state-badge state="issue" />
                                                <span class="secondary-text">重複項目</span>
                                            @elseif (data_get($previewRow, 'eligible', false))
                                                <span class="row-valid">可匯入</span>
                                            @else
                                                <x-state-badge state="issue" />
                                            @endif
                                            @if ($rowErrors)
                                                <ul class="row-issues">
                                                    @foreach ($rowErrors as $rowError)
                                                        <li>{{ $rowError }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($canCommit)
                    <div class="confirmation-panel">
                        <span class="heartbeat-dot is-stale" aria-hidden="true"></span>
                        <div>
                            <p><strong>已完成檢視？</strong> 確認後將新增 {{ $validCount }} 個可匯入的監測項目。有錯誤或網址重複的資料列將會略過。</p>
                            <form action="{{ route('monitors.import.commit') }}" method="POST" class="confirm-submit">
                                @csrf
                                <input type="hidden" name="preview_token" value="{{ $previewToken }}">
                                <button class="button button-primary" type="submit">確認並匯入 {{ $validCount }} 個監測項目</button>
                            </form>
                        </div>
                    </div>
                @else
                    <div class="form-note import-empty-note">目前沒有可匯入的資料列。請修正 CSV 中列出的問題後重新上傳。</div>
                @endif
            </div>
        </section>
    @endisset
@endsection
