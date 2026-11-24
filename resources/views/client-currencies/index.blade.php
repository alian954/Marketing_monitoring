@extends('layouts.app')

@section('title', '幣別與匯率')

@section('content')
    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">幣別與匯率</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">幣別與匯率</h1>
            <p class="page-description">{{ $client->name }}</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('clients.index') }}">返回客戶</a>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <section class="panel spaced-top" aria-labelledby="client-reporting-currency-title">
        <div class="panel-header">
            <div>
                <h2 id="client-reporting-currency-title" class="panel-title">報表幣別</h2>
                <p class="panel-description">跨幣別活動依每日有效匯率換算；未設定幣別時只顯示原幣報表。</p>
            </div>
        </div>
        <div class="panel-body">
            <form action="{{ route('clients.currencies.reporting.update', $client) }}" method="POST" class="client-currency-setting-form" aria-labelledby="client-reporting-currency-title">
                @csrf
                @method('PATCH')
                <div class="field-group">
                    <label class="field-label" for="reporting-currency-code">報表幣別</label>
                    <input
                        class="field-input{{ $errors->has('reporting_currency_code') ? ' is-invalid' : '' }}"
                        id="reporting-currency-code"
                        name="reporting_currency_code"
                        type="text"
                        value="{{ old('reporting_currency_code', $client->reporting_currency_code) }}"
                        maxlength="3"
                        pattern="[A-Za-z]{3}"
                        autocapitalize="characters"
                        autocorrect="off"
                        spellcheck="false"
                        aria-describedby="reporting-currency-help{{ $errors->has('reporting_currency_code') ? ' reporting-currency-error' : '' }}"
                    >
                    <p id="reporting-currency-help" class="field-help">輸入 3 碼 ISO 4217 代碼，例如 TWD、USD、JPY；留空可停用換算。</p>
                    @error('reporting_currency_code')<p id="reporting-currency-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <button class="button button-primary" type="submit">儲存報表幣別</button>
            </form>
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="client-exchange-rates-title">
        <div class="panel-header">
            <div>
                <h2 id="client-exchange-rates-title" class="panel-title">每日匯率</h2>
                <p class="panel-description">每筆匯率代表「1 單位來源幣別可兌換的報表幣別」。生效日取當日或之前最近一筆。</p>
            </div>
        </div>

        @if ($client->reporting_currency_code)
            @php($createExchangeRateErrors = $errors->getBag('createExchangeRate'))
            <div class="panel-body">
                <form action="{{ route('clients.exchange-rates.store', $client) }}" method="POST" class="client-currency-rate-form client-currency-rate-create-form" aria-labelledby="client-exchange-rates-title">
                    @csrf
                    <input type="hidden" name="reporting_currency_code" value="{{ $client->reporting_currency_code }}">
                    <div class="field-group">
                        <label class="field-label" for="new-source-currency-code">來源幣別</label>
                        <input class="field-input{{ $createExchangeRateErrors->has('source_currency_code') ? ' is-invalid' : '' }}" id="new-source-currency-code" name="source_currency_code" type="text" value="{{ old('source_currency_code', '', 'createExchangeRate') }}" maxlength="3" pattern="[A-Za-z]{3}" autocapitalize="characters" autocorrect="off" spellcheck="false" required aria-describedby="new-source-currency-help{{ $createExchangeRateErrors->has('source_currency_code') ? ' new-source-currency-error' : '' }}">
                        <p id="new-source-currency-help" class="field-help">例如 USD</p>
                        @error('source_currency_code', 'createExchangeRate')<p id="new-source-currency-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="new-exchange-effective-on">生效日</label>
                        <input class="field-input{{ $createExchangeRateErrors->has('effective_on') ? ' is-invalid' : '' }}" id="new-exchange-effective-on" name="effective_on" type="date" value="{{ old('effective_on', now()->toDateString(), 'createExchangeRate') }}" required aria-describedby="new-exchange-effective-on-help{{ $createExchangeRateErrors->has('effective_on') ? ' new-exchange-effective-on-error' : '' }}">
                        <p id="new-exchange-effective-on-help" class="field-help">按監測平台日期</p>
                        @error('effective_on', 'createExchangeRate')<p id="new-exchange-effective-on-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="new-exchange-rate">1 來源幣別兌 {{ $client->reporting_currency_code }}</label>
                        <input class="field-input{{ $createExchangeRateErrors->has('rate_to_reporting_currency') ? ' is-invalid' : '' }}" id="new-exchange-rate" name="rate_to_reporting_currency" type="number" inputmode="decimal" min="0.00000001" step="0.00000001" value="{{ old('rate_to_reporting_currency', '', 'createExchangeRate') }}" required aria-describedby="new-exchange-rate-help{{ $createExchangeRateErrors->has('rate_to_reporting_currency') ? ' new-exchange-rate-error' : '' }}">
                        <p id="new-exchange-rate-help" class="field-help">最多 8 位小數</p>
                        @error('rate_to_reporting_currency', 'createExchangeRate')<p id="new-exchange-rate-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <button class="button button-primary" type="submit">新增匯率</button>
                </form>
            </div>
        @else
            <div class="panel-body">
                <p class="field-help">請先設定報表幣別，再新增來源幣別的有效匯率。</p>
            </div>
        @endif

        <div class="data-table-wrap" role="region" aria-label="客戶每日匯率清單" tabindex="0">
            <table class="data-table client-currency-rate-table">
                <caption class="sr-only">來源幣別、報表幣別、生效日期與兌換匯率</caption>
                <thead>
                    <tr>
                        <th scope="col">來源幣別</th>
                        <th scope="col">報表幣別</th>
                        <th scope="col">生效日</th>
                        <th scope="col" class="numeric">每單位兌換匯率</th>
                        <th scope="col"><span class="sr-only">操作</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($exchangeRates as $exchangeRate)
                        @php($exchangeRateErrors = $errors->getBag('exchangeRate'.$exchangeRate->id))
                        @php($exchangeRateFormId = 'exchange-rate-'.$exchangeRate->id)
                        <tr>
                            <td>
                                <label class="sr-only" for="exchange-rate-source-{{ $exchangeRate->id }}">來源幣別</label>
                                <input class="field-input{{ $exchangeRateErrors->has('source_currency_code') ? ' is-invalid' : '' }}" id="exchange-rate-source-{{ $exchangeRate->id }}" form="{{ $exchangeRateFormId }}" name="source_currency_code" type="text" value="{{ old('source_currency_code', $exchangeRate->source_currency_code, 'exchangeRate'.$exchangeRate->id) }}" maxlength="3" pattern="[A-Za-z]{3}" autocapitalize="characters" autocorrect="off" spellcheck="false" required>
                                @error('source_currency_code', 'exchangeRate'.$exchangeRate->id)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            </td>
                            <td>{{ $exchangeRate->reporting_currency_code }}</td>
                            <td>
                                <label class="sr-only" for="exchange-rate-date-{{ $exchangeRate->id }}">生效日</label>
                                <input class="field-input{{ $exchangeRateErrors->has('effective_on') ? ' is-invalid' : '' }}" id="exchange-rate-date-{{ $exchangeRate->id }}" form="{{ $exchangeRateFormId }}" name="effective_on" type="date" value="{{ old('effective_on', $exchangeRate->effective_on->toDateString(), 'exchangeRate'.$exchangeRate->id) }}" required>
                                @error('effective_on', 'exchangeRate'.$exchangeRate->id)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            </td>
                            <td class="numeric">
                                <label class="sr-only" for="exchange-rate-value-{{ $exchangeRate->id }}">每單位兌換匯率</label>
                                <input class="field-input{{ $exchangeRateErrors->has('rate_to_reporting_currency') ? ' is-invalid' : '' }}" id="exchange-rate-value-{{ $exchangeRate->id }}" form="{{ $exchangeRateFormId }}" name="rate_to_reporting_currency" type="number" inputmode="decimal" min="0.00000001" step="0.00000001" value="{{ old('rate_to_reporting_currency', $exchangeRate->rate_to_reporting_currency, 'exchangeRate'.$exchangeRate->id) }}" required>
                                @error('rate_to_reporting_currency', 'exchangeRate'.$exchangeRate->id)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            </td>
                            <td>
                                <div class="client-currency-rate-actions">
                                    <form id="{{ $exchangeRateFormId }}" action="{{ route('clients.exchange-rates.update', [$client, $exchangeRate]) }}" method="POST" class="inline-form">
                                        @csrf
                                        @method('PUT')
                                        <button class="button button-secondary button-small" type="submit">儲存</button>
                                    </form>
                                    <form action="{{ route('clients.exchange-rates.destroy', [$client, $exchangeRate]) }}" method="POST" class="inline-form">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button button-quiet button-small" type="submit" aria-label="刪除 {{ $exchangeRate->source_currency_code }} 至 {{ $exchangeRate->reporting_currency_code }} {{ $exchangeRate->effective_on->toDateString() }} 匯率">刪除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="secondary-text">尚未設定每日匯率。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="field-help panel-body">更新或刪除匯率會影響相同生效日期之後的換算；原始 GA4 與廣告平台幣別金額不會變更。</p>
    </section>
@endsection
