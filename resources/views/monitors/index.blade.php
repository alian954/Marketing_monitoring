@extends('layouts.app')

@section('title', '監測項目')

@section('content')
    @php
        $hasFilters = $filters['search'] !== '' || $filters['label'] !== '' || $filters['platform'] !== 'all' || $filters['state'] !== 'all';
        $hasListOptions = $hasFilters || $filters['sort'] !== 'name';
        $monitorExportFilters = array_filter(
            $filters,
            static fn (string $filter, string $name): bool => $name !== 'sort' && $filter !== '' && ! (in_array($name, ['platform', 'state'], true) && $filter === 'all'),
            ARRAY_FILTER_USE_BOTH,
        );
        $oldMonitorIds = old('monitor_ids', []);
        $selectedMonitorIds = is_array($oldMonitorIds)
            ? array_map('intval', array_filter($oldMonitorIds, 'is_scalar'))
            : [];
    @endphp

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">工作區</p>
            <h1 class="page-title">監測項目</h1>
            <p class="page-description">管理網站與 Facebook、Instagram、Threads 公開貼文，查看最近一次檢查結果。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('monitors.export', $monitorExportFilters) }}">{{ $hasFilters ? '匯出符合條件 CSV' : '匯出 CSV' }}</a>
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
            <search aria-label="搜尋與篩選監測項目">
                <form action="{{ route('monitors.index') }}" method="GET">
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="monitor-search">名稱、標籤或完整網址</label>
                            <input class="field-input" id="monitor-search" name="search" type="search" value="{{ old('search', $filters['search']) }}" maxlength="2048" autocomplete="off" aria-describedby="monitor-search-help">
                            <p id="monitor-search-help" class="field-help">名稱與標籤支援部分搜尋；網址請貼上完整連結。</p>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="monitor-label">標籤篩選</label>
                            <input class="field-input" id="monitor-label" name="label" type="text" list="monitor-label-options" value="{{ old('label', $filters['label']) }}" maxlength="100" autocomplete="off" spellcheck="false" aria-describedby="monitor-label-help">
                            <datalist id="monitor-label-options">
                                @foreach ($labels as $label)
                                    <option value="{{ $label }}">{{ $label }}</option>
                                @endforeach
                            </datalist>
                            <p id="monitor-label-help" class="field-help">輸入或選取一個完整標籤，精確篩選該標籤的監測項目。</p>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="monitor-platform">監測平台</label>
                            <select class="field-select" id="monitor-platform" name="platform">
                                <option value="all" @selected(old('platform', $filters['platform']) === 'all')>全部平台</option>
                                <option value="website" @selected(old('platform', $filters['platform']) === 'website')>一般網站</option>
                                <option value="facebook" @selected(old('platform', $filters['platform']) === 'facebook')>Facebook</option>
                                <option value="instagram" @selected(old('platform', $filters['platform']) === 'instagram')>Instagram</option>
                                <option value="threads" @selected(old('platform', $filters['platform']) === 'threads')>Threads</option>
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="monitor-state">監測狀態</label>
                            <select class="field-select" id="monitor-state" name="state">
                                <option value="all" @selected(old('state', $filters['state']) === 'all')>全部</option>
                                <option value="enabled" @selected(old('state', $filters['state']) === 'enabled')>啟用中</option>
                                <option value="paused" @selected(old('state', $filters['state']) === 'paused')>已暫停</option>
                                <option value="incidents" @selected(old('state', $filters['state']) === 'incidents')>有未結束事件</option>
                                <option value="stale" @selected(old('state', $filters['state']) === 'stale')>檢查已逾時</option>
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="monitor-sort">排序方式</label>
                            <select class="field-select" id="monitor-sort" name="sort">
                                <option value="name" @selected(old('sort', $filters['sort']) === 'name')>名稱（A–Z）</option>
                                <option value="last_checked_desc" @selected(old('sort', $filters['sort']) === 'last_checked_desc')>最近檢查（新到舊）</option>
                                <option value="last_checked_asc" @selected(old('sort', $filters['sort']) === 'last_checked_asc')>最久未檢查（未檢查項目在前）</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="button button-secondary" type="submit">套用篩選</button>
                        @if ($hasListOptions)
                            <a class="strong-link" href="{{ route('monitors.index') }}">清除篩選</a>
                        @endif
                    </div>
                </form>
            </search>
        </div>

        @if ($monitors->isEmpty())
            <div class="empty-state">
                @if ($hasFilters)
                    <h3 class="empty-title">找不到符合條件的監測項目</h3>
                    <p class="empty-copy">請調整名稱、標籤、網址搜尋或監測狀態篩選條件。</p>
                    <a class="button button-secondary" href="{{ route('monitors.index') }}">清除篩選</a>
                @else
                    <h3 class="empty-title">尚未新增監測項目</h3>
                    <p class="empty-copy">新增網站或公開貼文網址，即可開始檢查連線與指定內容。</p>
                    <div class="button-row centered-actions">
                        <a class="button button-secondary" href="{{ route('monitors.import') }}">匯入 CSV</a>
                        <a class="button button-primary" href="{{ route('monitors.create') }}">新增監測項目</a>
                    </div>
                @endif
            </div>
        @else
            <div class="panel-body">
                <form id="monitor-bulk-status-form" action="{{ route('monitors.bulk-status') }}" method="POST">
                    @csrf
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="bulk-monitor-action">批次操作</label>
                            <select class="field-select @error('action') is-invalid @enderror" id="bulk-monitor-action" name="action" required aria-describedby="bulk-monitor-action-help{{ $errors->has('action') ? ' bulk-monitor-action-error' : '' }}" @if ($errors->has('action')) aria-invalid="true" @endif>
                                <option value="" @selected(old('action', '') === '')>選擇操作</option>
                                <option value="pause" @selected(old('action') === 'pause')>暫停所選監測項目</option>
                                <option value="resume" @selected(old('action') === 'resume')>恢復所選監測項目</option>
                                <option value="label" @selected(old('action') === 'label')>更新所選項目的標籤</option>
                                <option value="check" @selected(old('action') === 'check')>立即檢查所選項目</option>
                            </select>
                            <p id="bulk-monitor-action-help" class="field-help">請勾選下方項目；每次最多 50 個，只會套用到本頁所選項目。立即檢查會略過已暫停、冷卻中或已有檢查執行的項目。</p>
                            @error('action')<p id="bulk-monitor-action-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="bulk-monitor-label">新標籤</label>
                            <input class="field-input @error('label') is-invalid @enderror" id="bulk-monitor-label" name="label" type="text" value="{{ old('label') }}" maxlength="100" autocomplete="off" aria-describedby="bulk-monitor-label-help{{ $errors->has('label') ? ' bulk-monitor-label-error' : '' }}" @if ($errors->has('label')) aria-invalid="true" @endif>
                            <p id="bulk-monitor-label-help" class="field-help">只有選擇「更新所選項目的標籤」時才會使用；留空會清除所選項目的標籤。</p>
                            @error('label')<p id="bulk-monitor-label-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    @error('monitor_ids')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    @error('monitor_ids.*')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    <div class="form-actions">
                        <button class="button button-secondary" type="submit">套用至所選項目</button>
                    </div>
                </form>
            </div>
            <div class="data-table-wrap" tabindex="0" role="region" aria-labelledby="monitor-table-caption">
                <table class="data-table">
                    <caption id="monitor-table-caption" class="sr-only">符合篩選條件的監測項目與最近一次健康檢查結果</caption>
                    <thead>
                        <tr>
                            <th scope="col">
                                <label class="field-check monitor-selection-label">
                                    <input type="checkbox" data-monitor-select-all>
                                    <span class="sr-only">選取本頁全部監測項目</span>
                                </label>
                            </th>
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
                                    <label class="field-check monitor-selection-label">
                                        <input type="checkbox" name="monitor_ids[]" value="{{ $monitor->id }}" form="monitor-bulk-status-form" data-monitor-select @checked(in_array($monitor->id, $selectedMonitorIds, true))>
                                        <span class="sr-only">選取 {{ $monitor->name }}</span>
                                    </label>
                                </td>
                                <td>
                                    <a class="monitor-name-link" href="{{ route('monitors.show', $monitor) }}">{{ $monitor->name }}</a>
                                    @if ($monitor->label)
                                        <span class="secondary-text">{{ $monitor->label }}</span>
                                    @endif
                                    @if ($monitor->socialPlatform())
                                        <span class="secondary-text">{{ $monitor->socialPlatform() }} 公開貼文</span>
                                    @endif
                                    @if ($monitor->expected_text !== null && $monitor->expected_text !== '')
                                        <span class="secondary-text">
                                            文字比對：
                                            @if ($monitor->latestCheckRun === null)
                                                尚未檢查
                                            @elseif ($monitor->latestCheckRun->expected_text_found === null)
                                                尚未比對
                                            @elseif ($monitor->latestCheckRun->expected_text_found)
                                                已找到指定文字
                                            @else
                                                未找到指定文字
                                            @endif
                                        </span>
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
                                        @if ($monitor->enabled)
                                            <form class="inline-form" action="{{ route('monitors.check', $monitor) }}" method="POST">
                                                @csrf
                                                <button class="button button-quiet button-small" type="submit">立即檢查</button>
                                            </form>
                                        @endif
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
