@extends('layouts.app')

@section('title', '公開狀態頁設定')

@section('content')
    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <span aria-current="page">公開狀態頁</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">對外服務資訊</p>
            <h1 class="page-title">公開狀態頁</h1>
            <p class="page-description">選擇要公開的監測項目，分享目前服務狀態。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('dashboard') }}">返回總覽</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="status-page-settings-title">
        <div class="panel-header">
            <div>
                <h2 id="status-page-settings-title" class="panel-title">狀態頁設定</h2>
                <p class="panel-description">公開頁只顯示項目名稱、狀態與最近檢查時間，不會顯示目的網址或檢查證據。</p>
            </div>
            @if ($statusPage?->is_published)
                <x-state-badge state="enabled" />
            @else
                <x-state-badge state="disabled" />
            @endif
        </div>

        <div class="panel-body">
            <form action="{{ route('status-pages.update') }}" method="POST" aria-labelledby="status-page-settings-title">
                @csrf
                <div class="form-grid">
                    <div class="field-group field-group-full">
                        <label class="field-label" for="title">頁面標題<span class="required-mark" aria-hidden="true">*</span></label>
                        <input class="field-input @error('title') is-invalid @enderror" id="title" name="title" type="text" value="{{ old('title', $statusPage?->title ?? '服務狀態') }}" maxlength="120" required aria-describedby="title-help{{ $errors->has('title') ? ' title-error' : '' }}">
                        <p id="title-help" class="field-help">使用客戶與團隊熟悉的服務名稱。</p>
                        @error('title')<p id="title-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>

                    <div class="field-group field-group-full">
                        <input type="hidden" name="is_published" value="0">
                        <label class="field-check" for="is_published">
                            <input id="is_published" name="is_published" type="checkbox" value="1" @checked(old('is_published', $statusPage?->is_published ?? false)) aria-describedby="publish-help{{ $errors->has('is_published') ? ' publish-error' : '' }}">
                            <span>發布狀態頁</span>
                        </label>
                        <p id="publish-help" class="field-help">關閉後，現有分享連結會立即回傳 404。</p>
                        @error('is_published')<p id="publish-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>

                    <div class="field-group field-group-full">
                        <label class="field-check" for="rotate_public_link">
                            <input id="rotate_public_link" name="rotate_public_link" type="checkbox" value="1" aria-describedby="rotate-link-help">
                            <span>重新產生分享連結</span>
                        </label>
                        <p id="rotate-link-help" class="field-help">舊連結會立即失效。儲存前請先確認需要換發。</p>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit">儲存狀態頁設定</button>
                </div>
            </form>

            @if ($publicUrl !== null)
                <div class="public-status-share spaced-top">
                    <div class="field-group">
                        <label class="field-label" for="public-status-url">公開分享連結</label>
                        <input class="field-input" id="public-status-url" type="url" value="{{ $publicUrl }}" readonly aria-describedby="public-status-url-help">
                        <p id="public-status-url-help" class="field-help">此連結可供任何人查看已選取的監測狀態。</p>
                    </div>
                    <a class="strong-link" href="{{ $publicUrl }}">開啟狀態頁 <span aria-hidden="true">→</span></a>
                </div>
            @else
                <p class="form-note spaced-top">目前尚未發布。發布後才會顯示可分享的連結。</p>
            @endif
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="published-monitors-title">
        <div class="panel-header">
            <div>
                <h2 id="published-monitors-title" class="panel-title">狀態頁項目</h2>
                <p class="panel-description">目前選取 {{ $publicMonitorCount }} 個項目。分享頁只顯示名稱、狀態與最近檢查時間。</p>
            </div>
            <a class="strong-link" href="{{ route('monitors.index') }}">管理監測項目 <span aria-hidden="true">→</span></a>
        </div>

        @if ($monitors->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">尚無監測項目</h3>
                <p class="empty-copy">先新增監測項目，再選取要顯示在狀態頁上的服務。</p>
                <a class="button button-secondary" href="{{ route('monitors.index') }}">查看監測項目</a>
            </div>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">監測項目及其公開狀態頁顯示設定</caption>
                    <thead>
                        <tr>
                            <th scope="col">監測項目</th>
                            <th scope="col">目前狀態</th>
                            <th scope="col">最近檢查</th>
                            <th scope="col">公開設定</th>
                            <th scope="col"><span class="sr-only">管理</span></th>
                        </tr>
                    </thead>
                    @foreach ($monitorGroups as $group)
                        <tbody>
                            <tr class="status-page-monitor-group">
                                <th scope="rowgroup" colspan="5">
                                    {{ $group['label'] }}
                                    <span class="status-page-monitor-group-count">{{ $group['monitors']->count() }} 個項目</span>
                                </th>
                            </tr>
                            @foreach ($group['monitors'] as $monitor)
                                <tr>
                                    <td>{{ $monitor->name }}</td>
                                    <td><x-state-badge :state="$monitor->health_state" /></td>
                                    <td>
                                        @if ($monitor->last_checked_at)
                                            <time class="timestamp" datetime="{{ $monitor->last_checked_at->toIso8601String() }}">{{ $monitor->last_checked_at->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time>
                                        @else
                                            <span class="secondary-text">尚未檢查</span>
                                        @endif
                                    </td>
                                    <td><x-state-badge :state="$monitor->public_status ? 'enabled' : 'disabled'" /></td>
                                    <td class="row-actions">
                                        <form class="inline-form" action="{{ route('status-pages.monitors.toggle', $monitor) }}" method="POST">
                                            @csrf
                                            <button class="button button-secondary button-small" type="submit" aria-label="{{ $monitor->public_status ? '從公開狀態頁移除' : '加入公開狀態頁' }}：{{ $monitor->name }}">
                                                {{ $monitor->public_status ? '移除' : '加入' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>
        @endif
    </section>
@endsection
