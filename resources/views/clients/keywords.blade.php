@extends('layouts.app')

@section('title', $client->name.'｜關鍵字排名')

@section('content')
    @php
        $canManage = ! auth()->user()?->isReadOnlyWorkspaceMember();
        $formatDate = static fn (string $date): string => str_replace('-', '/', $date);
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">關鍵字排名</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">{{ $client->name }}｜關鍵字排名</h1>
            <p class="page-description">每週一從 Search Console 讀取追蹤關鍵字近 7 天的平均排名、點擊與曝光。Search Console 數據約延遲 3 天，本週區間為 {{ $formatDate($window['starts_on']) }}–{{ $formatDate($window['ends_on']) }}。</p>
        </div>
        <div class="heading-actions">
            @if ($canManage && $rows->isNotEmpty() && $hasSearchConsoleSite)
                <form action="{{ route('clients.keywords.refresh', $client) }}" method="POST" class="inline-form">
                    @csrf
                    <button class="button button-secondary" type="submit">立即更新排名</button>
                </form>
            @endif
            <a class="button button-secondary" href="{{ route('clients.index') }}">返回客戶</a>
        </div>
    </div>

    @error('refresh')<p class="field-error" role="alert">{{ $message }}</p>@enderror

    @unless ($hasSearchConsoleSite)
        <section class="panel spaced-top">
            <div class="empty-state">
                <h2 class="empty-title">尚未設定 Search Console 網站</h2>
                <p class="empty-copy">
                    排名來自 Google Search Console，請先
                    @if (auth()->user()?->isWorkspaceOwner())
                        到 <a href="{{ route('integrations.google-search-console.edit', ['client_id' => $client->getKey()]) }}">Search Console 整合</a> 為此客戶選擇網站。
                    @else
                        請組織擁有者為此客戶設定 Search Console 網站。
                    @endif
                </p>
            </div>
        </section>
    @endunless

    <section class="panel spaced-top" aria-labelledby="keyword-list-title">
        <div class="panel-header">
            <div>
                <h2 id="keyword-list-title" class="panel-title">追蹤中的關鍵字</h2>
                <p class="panel-description">{{ $rows->count() }} / {{ $maxKeywords }} 個。平均排名為 Google 自然搜尋結果的平均位置，數字越小越前面；「—」表示該週沒有曝光。</p>
            </div>
        </div>
        @if ($rows->isEmpty())
            <p class="empty-copy panel-body">尚未追蹤任何關鍵字。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">追蹤關鍵字的最新排名、變化、點擊、曝光與近 8 週排名</caption>
                    <thead>
                        <tr>
                            <th scope="col">關鍵字</th>
                            <th scope="col" class="numeric">平均排名</th>
                            <th scope="col" class="numeric">較前週</th>
                            <th scope="col" class="numeric">點擊</th>
                            <th scope="col" class="numeric">曝光</th>
                            <th scope="col">近 8 週</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $keyword = $row['keyword'];
                                $latest = $row['latest'];
                            @endphp
                            <tr>
                                <th scope="row">
                                    {{ $keyword->keyword }}
                                    @if ($keyword->target_position !== null)
                                        <span class="secondary-text">目標前 {{ $keyword->target_position }} 名@if ($row['meets_target'] === true) · 已達成@elseif ($row['meets_target'] === false) · 未達成@endif</span>
                                    @endif
                                    @if ($keyword->last_sync_error !== null)
                                        <span class="field-error">{{ $keyword->last_sync_error }}</span>
                                    @endif
                                </th>
                                <td class="numeric">{{ $latest?->position === null ? '—' : number_format((float) $latest->position, 1) }}</td>
                                <td class="numeric">
                                    @if ($row['change'] === null)
                                        —
                                    @elseif ($row['change'] > 0)
                                        <span class="keyword-change keyword-change--up">↑ {{ number_format($row['change'], 1) }}</span>
                                    @elseif ($row['change'] < 0)
                                        <span class="keyword-change keyword-change--down">↓ {{ number_format(abs($row['change']), 1) }}</span>
                                    @else
                                        持平
                                    @endif
                                </td>
                                <td class="numeric">{{ $latest === null ? '—' : number_format($latest->clicks) }}</td>
                                <td class="numeric">{{ $latest === null ? '—' : number_format($latest->impressions) }}</td>
                                <td>
                                    <span class="keyword-history">
                                        @forelse ($row['history'] as $point)
                                            <span title="{{ $formatDate($point['measured_on']) }}">{{ $point['position'] === null ? '—' : number_format($point['position'], 0) }}</span>
                                        @empty
                                            <span class="secondary-text">尚無資料</span>
                                        @endforelse
                                    </span>
                                </td>
                                <td>
                                    @if ($canManage)
                                        <form action="{{ route('clients.keywords.destroy', [$client, $keyword]) }}" method="POST" class="inline-form">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button button-quiet button-small" type="submit" aria-label="停止追蹤 {{ $keyword->keyword }}">移除</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($canManage)
        <section class="panel spaced-top" aria-labelledby="keyword-create-title">
            <div class="panel-header">
                <h2 id="keyword-create-title" class="panel-title">新增追蹤關鍵字</h2>
            </div>
            <div class="panel-body">
                <form action="{{ route('clients.keywords.store', $client) }}" method="POST" class="client-create-form" aria-labelledby="keyword-create-title">
                    @csrf
                    <div class="field-group">
                        <label class="field-label" for="keyword-input">關鍵字</label>
                        <textarea class="field-textarea{{ $errors->has('keywords') ? ' is-invalid' : '' }}" id="keyword-input" name="keywords" rows="4" required aria-describedby="keyword-input-help">{{ old('keywords') }}</textarea>
                        <p id="keyword-input-help" class="field-help">一行一個，或以逗號分隔。需與使用者實際搜尋的字詞完全相同，英文不分大小寫。</p>
                        @foreach ($errors->get('keywords*') as $messages)
                            @foreach ((array) $messages as $message)<p class="field-error" role="alert">{{ $message }}</p>@endforeach
                        @endforeach
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="keyword-target">目標排名（選填）</label>
                        <input class="field-input" id="keyword-target" name="target_position" type="number" min="1" max="100" step="1" value="{{ old('target_position') }}" placeholder="例：3">
                        @error('target_position')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">新增</button>
                    </div>
                </form>
            </div>
        </section>
    @endif
@endsection
