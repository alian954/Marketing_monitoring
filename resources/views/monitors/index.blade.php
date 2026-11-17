@extends('layouts.app')

@section('title', '監測項目')

@section('content')
    @php
        $hasFilters = $filters['search'] !== '' || $filters['state'] !== 'all';
    @endphp

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">工作區</p>
            <h1 class="page-title">監測項目</h1>
            <p class="page-description">管理受監測的網站，並查看最近一次健康檢查結果。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('monitors.export') }}">匯出 CSV</a>
            <a class="button button-secondary" href="{{ route('monitors.import') }}">匯入 CSV</a>
            <a class="button button-primary" href="{{ route('monitors.create') }}">新增監測項目</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="monitor-list-title">
        <div class="panel-header">
            <div>
                <h2 id="monitor-list-title" class="panel-title">監測項目</h2>
                <p class="panel-description">{{ $monitors->total() }} 個符合條件的項目</p>
            </div>
        </div>

        <div class="panel-body">
            <form action="{{ route('monitors.index') }}" method="GET" aria-label="搜尋與篩選監測項目">
                <div class="form-grid">
                    <div class="field-group">
                        <label class="field-label" for="monitor-search">名稱或標籤</label>
                        <input class="field-input" id="monitor-search" name="search" type="search" value="{{ old('search', $filters['search']) }}" maxlength="120" autocomplete="off" aria-describedby="monitor-search-help">
                        <p id="monitor-search-help" class="field-help">只搜尋監測項目名稱與標籤，不搜尋網址。</p>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="monitor-state">監測狀態</label>
                        <select class="field-select" id="monitor-state" name="state">
                            <option value="all" @selected(old('state', $filters['state']) === 'all')>全部</option>
                            <option value="enabled" @selected(old('state', $filters['state']) === 'enabled')>啟用中</option>
                            <option value="paused" @selected(old('state', $filters['state']) === 'paused')>已暫停</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="button button-secondary" type="submit">套用篩選</button>
                    @if ($hasFilters)
                        <a class="strong-link" href="{{ route('monitors.index') }}">清除篩選</a>
                    @endif
                </div>
            </form>
        </div>

        @if ($monitors->isEmpty())
            <div class="empty-state">
                @if ($hasFilters)
                    <h3 class="empty-title">找不到符合條件的監測項目</h3>
                    <p class="empty-copy">請調整名稱、標籤或監測狀態篩選條件。</p>
                    <a class="button button-secondary" href="{{ route('monitors.index') }}">清除篩選</a>
                @else
                    <h3 class="empty-title">尚未新增監測項目</h3>
                    <p class="empty-copy">新增網站或匯入 CSV，即可開始檢查網站狀態。</p>
                    <div class="button-row centered-actions">
                        <a class="button button-secondary" href="{{ route('monitors.import') }}">匯入 CSV</a>
                        <a class="button button-primary" href="{{ route('monitors.create') }}">新增監測項目</a>
                    </div>
                @endif
            </div>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">符合篩選條件的監測項目與最近一次健康檢查結果</caption>
                    <thead>
                        <tr>
                            <th scope="col">監測項目</th>
                            <th scope="col">目的網址</th>
                            <th scope="col">狀態</th>
                            <th scope="col">最近檢查</th>
                            <th scope="col">HTTP</th>
                            <th scope="col">監測功能</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($monitors as $monitor)
                            <tr>
                                <td>
                                    <a class="monitor-name-link" href="{{ route('monitors.show', $monitor) }}">{{ $monitor->name }}</a>
                                    @if ($monitor->label)
                                        <span class="secondary-text">{{ $monitor->label }}</span>
                                    @endif
                                </td>
                                <td><span class="masked-url">{{ $monitor->masked_url }}</span></td>
                                <td><x-state-badge :state="$monitor->health_state" /></td>
                                <td>
                                    @if ($monitor->last_checked_at)
                                        <time class="timestamp" datetime="{{ $monitor->last_checked_at->toIso8601String() }}">{{ $monitor->last_checked_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                    @else
                                        <span class="secondary-text">尚未檢查</span>
                                    @endif
                                </td>
                                <td class="numeric">
                                    @if ($monitor->latestCheckRun?->normal_status !== null)
                                        {{ $monitor->latestCheckRun->normal_status }}
                                    @else
                                        <span aria-label="沒有 HTTP 狀態碼">—</span>
                                    @endif
                                </td>
                                <td><x-state-badge :state="$monitor->enabled ? 'enabled' : 'paused'" /></td>
                                <td>
                                    <div class="row-actions">
                                        <form class="inline-form" action="{{ route('monitors.toggle', $monitor) }}" method="POST">
                                            @csrf
                                            <button class="button button-quiet button-small" type="submit">{{ $monitor->enabled ? '暫停' : '恢復' }}</button>
                                        </form>
                                        <a class="button button-secondary button-small" href="{{ route('monitors.edit', $monitor) }}">編輯</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($monitors->hasPages())
                <div class="panel-body pagination-wrap">{{ $monitors->links('vendor.pagination.monitor') }}</div>
            @endif
        @endif
    </section>
@endsection
