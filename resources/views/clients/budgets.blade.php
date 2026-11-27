@extends('layouts.app')

@section('title', $client->name.'｜月預算')

@section('content')
    @php
        $canManage = ! auth()->user()?->isReadOnlyWorkspaceMember();
        $money = static fn (?float $amount): string => $amount === null ? '—' : number_format($amount, 0);
        $percent = static fn (?float $value): string => $value === null ? '—' : number_format($value, 1).'%';
        $platformLabels = ['google_ads' => 'Google Ads', 'meta_marketing' => 'Meta Ads'];
        $statusLabels = \App\Services\ClientBudgetPacing::STATUS_LABELS;
        $monthLabel = static fn (string $value): string => str_replace('-', ' 年 ', $value).' 月';
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">月預算</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">{{ $client->name }}｜月預算</h1>
            <p class="page-description">設定每月廣告總預算，系統依已同步的 Google Ads 與 Meta Ads 花費計算消耗進度、預估月底花費與剩餘每日可花金額。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('clients.cross-platform', $client) }}">跨平台總覽</a>
            <a class="button button-secondary" href="{{ route('clients.index') }}">返回客戶</a>
        </div>
    </div>

    <nav class="budget-month-nav" aria-label="切換月份">
        <a class="button button-secondary button-small" href="{{ route('clients.budgets.index', ['client' => $client, 'month' => $previousMonth]) }}">‹ 上個月</a>
        <strong>{{ $monthLabel($month) }}</strong>
        <a class="button button-secondary button-small" href="{{ route('clients.budgets.index', ['client' => $client, 'month' => $nextMonth]) }}">下個月 ›</a>
        @if ($month !== $currentMonth)
            <a class="strong-link" href="{{ route('clients.budgets.index', $client) }}">回到本月</a>
        @endif
    </nav>

    @if ($pacing === null)
        <section class="panel spaced-top">
            <div class="empty-state">
                <h2 class="empty-title">{{ $monthLabel($month) }}尚未設定預算</h2>
                <p class="empty-copy">設定後即可追蹤消耗進度。</p>
            </div>
        </section>
    @else
        @php $total = $pacing['total']; @endphp
        <section class="panel spaced-top" aria-labelledby="budget-pacing-title">
            <div class="panel-header">
                <div>
                    <h2 id="budget-pacing-title" class="panel-title">消耗進度（{{ $pacing['currency_code'] }}）</h2>
                    <p class="panel-description">
                        @if ($pacing['data_through'] === null)
                            本月尚無完整數據。
                        @else
                            數據至 {{ str_replace('-', '/', $pacing['data_through']) }} · 已過 {{ $pacing['elapsed_days'] }} / {{ $pacing['days_in_month'] }} 天
                        @endif
                    </p>
                </div>
                @if ($total['status'] !== null)
                    <span class="budget-status budget-status--{{ $total['status'] }}">{{ $statusLabels[$total['status']] }}</span>
                @endif
            </div>
            <div class="panel-body">
                <div class="budget-progress" role="img" aria-label="已花費 {{ $percent($total['spent_percent']) }}，時間進度 {{ number_format($pacing['elapsed_days'] / $pacing['days_in_month'] * 100, 1) }}%">
                    <span class="budget-progress-fill budget-progress-fill--{{ $total['status'] ?? 'on_track' }}" style="width: {{ min(100, $total['spent_percent'] ?? 0) }}%"></span>
                    <span class="budget-progress-time" style="left: {{ $pacing['elapsed_days'] / $pacing['days_in_month'] * 100 }}%"></span>
                </div>
                <p class="field-help">色條為已花費占預算比例，直線為時間進度。</p>
                <section class="stat-grid cross-platform-stat-grid" aria-label="月預算摘要">
                    <article class="stat-card">
                        <div class="stat-topline"><span class="stat-label">已花費 / 預算</span></div>
                        <strong class="stat-value">{{ $money($total['spend']) }}</strong>
                        <p class="stat-hint">預算 {{ $money($total['budget']) }} · {{ $percent($total['spent_percent']) }}</p>
                    </article>
                    <article class="stat-card">
                        <div class="stat-topline"><span class="stat-label">消耗速度</span></div>
                        <strong class="stat-value">{{ $percent($total['pace_percent']) }}</strong>
                        <p class="stat-hint">相對時間進度應花 {{ $money($total['expected_spend']) }}</p>
                    </article>
                    <article class="stat-card">
                        <div class="stat-topline"><span class="stat-label">預估月底花費</span></div>
                        <strong class="stat-value">{{ $money($total['projected_spend']) }}</strong>
                        <p class="stat-hint">預算的 {{ $percent($total['projected_percent']) }}</p>
                    </article>
                    <article class="stat-card">
                        <div class="stat-topline"><span class="stat-label">剩餘每日可花</span></div>
                        <strong class="stat-value">{{ $money($total['suggested_daily_spend']) }}</strong>
                        <p class="stat-hint">剩餘 {{ $money($total['remaining_budget']) }} · {{ $pacing['remaining_days'] }} 天</p>
                    </article>
                </section>

                @if ($pacing['unmatched_currency_spend'] !== [])
                    <p class="cross-platform-attribution cross-platform-attribution--platform_lower spaced-top" role="status">
                        以下花費的幣別與預算不同，未計入進度：
                        @foreach ($pacing['unmatched_currency_spend'] as $currencyCode => $spend)
                            {{ number_format($spend, 2) }} {{ $currencyCode }}@if (! $loop->last)、@endif
                        @endforeach
                    </p>
                @endif
            </div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">各平台預算、花費與消耗進度</caption>
                    <thead>
                        <tr>
                            <th scope="col">平台</th>
                            <th scope="col" class="numeric">預算</th>
                            <th scope="col" class="numeric">已花費</th>
                            <th scope="col" class="numeric">已花費比例</th>
                            <th scope="col" class="numeric">消耗速度</th>
                            <th scope="col" class="numeric">預估月底</th>
                            <th scope="col" class="numeric">剩餘每日可花</th>
                            <th scope="col">狀態</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pacing['platforms'] as $source => $line)
                            <tr>
                                <th scope="row">{{ $platformLabels[$source] }}</th>
                                <td class="numeric">{{ $line['budget'] === null ? '未分配' : $money($line['budget']) }}</td>
                                <td class="numeric">{{ $money($line['spend']) }}</td>
                                <td class="numeric">{{ $percent($line['spent_percent']) }}</td>
                                <td class="numeric">{{ $percent($line['pace_percent']) }}</td>
                                <td class="numeric">{{ $money($line['projected_spend']) }}</td>
                                <td class="numeric">{{ $money($line['suggested_daily_spend']) }}</td>
                                <td>@if ($line['status'] !== null)<span class="budget-status budget-status--{{ $line['status'] }}">{{ $statusLabels[$line['status']] }}</span>@else — @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($budget->note !== null)
                <p class="panel-body secondary-text annotation-note">備註：{{ $budget->note }}</p>
            @endif
        </section>
    @endif

    @if ($canManage)
        <section class="panel spaced-top" aria-labelledby="budget-form-title">
            <div class="panel-header">
                <div>
                    <h2 id="budget-form-title" class="panel-title">{{ $budget === null ? '設定' : '修改' }}{{ $monthLabel($month) }}預算</h2>
                    <p class="panel-description">平台預算選填；只填總預算時，各平台只顯示花費不計算進度。</p>
                </div>
                @if ($budget !== null)
                    <form action="{{ route('clients.budgets.destroy', [$client, $budget]) }}" method="POST" class="inline-form">
                        @csrf
                        @method('DELETE')
                        <button class="button button-quiet button-small" type="submit">刪除本月預算</button>
                    </form>
                @endif
            </div>
            <div class="panel-body">
                <form action="{{ route('clients.budgets.store', $client) }}" method="POST" class="client-create-form" aria-labelledby="budget-form-title">
                    @csrf
                    <input type="hidden" name="month" value="{{ $month }}">
                    @error('month')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="budget-total">總預算</label>
                            <input class="field-input{{ $errors->has('total_budget') ? ' is-invalid' : '' }}" id="budget-total" name="total_budget" type="number" step="0.01" min="0" inputmode="decimal" required value="{{ old('total_budget', $budget?->total_budget) }}" @if ($errors->has('total_budget')) aria-invalid="true" aria-describedby="budget-total-error" @endif>
                            @error('total_budget')<p id="budget-total-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="budget-currency">幣別</label>
                            <input class="field-input{{ $errors->has('currency_code') ? ' is-invalid' : '' }}" id="budget-currency" name="currency_code" type="text" maxlength="3" required autocapitalize="characters" spellcheck="false" value="{{ old('currency_code', $budget?->currency_code ?? $defaultCurrencyCode) }}" aria-describedby="budget-currency-help{{ $errors->has('currency_code') ? ' budget-currency-error' : '' }}" @if ($errors->has('currency_code')) aria-invalid="true" @endif>
                            <p id="budget-currency-help" class="field-help">需與廣告帳戶幣別相同。</p>
                            @error('currency_code')<p id="budget-currency-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="budget-google-ads">Google Ads 預算（選填）</label>
                            <input class="field-input{{ $errors->has('google_ads_budget') ? ' is-invalid' : '' }}" id="budget-google-ads" name="google_ads_budget" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old('google_ads_budget', $budget?->google_ads_budget) }}" @if ($errors->has('google_ads_budget')) aria-invalid="true" aria-describedby="budget-google-ads-error" @endif>
                            @error('google_ads_budget')<p id="budget-google-ads-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="budget-meta">Meta Ads 預算（選填）</label>
                            <input class="field-input{{ $errors->has('meta_marketing_budget') ? ' is-invalid' : '' }}" id="budget-meta" name="meta_marketing_budget" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old('meta_marketing_budget', $budget?->meta_marketing_budget) }}" @if ($errors->has('meta_marketing_budget')) aria-invalid="true" aria-describedby="budget-meta-error" @endif>
                            @error('meta_marketing_budget')<p id="budget-meta-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="budget-note">備註（選填）</label>
                        <input class="field-input{{ $errors->has('note') ? ' is-invalid' : '' }}" id="budget-note" name="note" type="text" maxlength="500" value="{{ old('note', $budget?->note) }}" @if ($errors->has('note')) aria-invalid="true" aria-describedby="budget-note-error" @endif>
                        @error('note')<p id="budget-note-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">儲存預算</button>
                    </div>
                </form>
            </div>
        </section>
    @endif

    @if ($history->isNotEmpty())
        <section class="panel spaced-top" aria-labelledby="budget-history-title">
            <div class="panel-header">
                <h2 id="budget-history-title" class="panel-title">過往月份</h2>
            </div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">過往月份的預算與實際花費</caption>
                    <thead>
                        <tr>
                            <th scope="col">月份</th>
                            <th scope="col" class="numeric">預算</th>
                            <th scope="col" class="numeric">實際花費</th>
                            <th scope="col" class="numeric">達成率</th>
                            <th scope="col">結果</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $row)
                            @php $pastTotal = $row['pacing']['total']; @endphp
                            <tr>
                                <th scope="row"><a href="{{ route('clients.budgets.index', ['client' => $client, 'month' => $row['budget']->month]) }}">{{ $monthLabel($row['budget']->month) }}</a></th>
                                <td class="numeric">{{ $money($pastTotal['budget']) }} {{ $row['pacing']['currency_code'] }}</td>
                                <td class="numeric">{{ $money($pastTotal['spend']) }}</td>
                                <td class="numeric">{{ $percent($pastTotal['spent_percent']) }}</td>
                                <td>@if ($pastTotal['status'] !== null)<span class="budget-status budget-status--{{ $pastTotal['status'] }}">{{ $statusLabels[$pastTotal['status']] }}</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
