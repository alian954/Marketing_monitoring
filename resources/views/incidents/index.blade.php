@extends('layouts.app')

@section('title', '事件紀錄')

@section('content')
    @php
        $hasFilters = $filters['search'] !== '' || $filters['state'] !== '' || $filters['severity'] !== '' || $filters['acknowledgement'] !== '' || $filters['notification_status'] !== '' || $filters['monitor_id'] !== '' || $filters['rule_key'] !== '' || $filters['date_from'] !== '' || $filters['date_to'] !== '';
        $exportFilters = array_filter($filters, static fn (string $value): bool => $value !== '');
        $hasSelectableIncidents = $incidents->getCollection()->contains(static fn ($incident): bool => in_array($incident->state, [\App\Models\Incident::STATE_SUSPECT, \App\Models\Incident::STATE_OPEN], true));
    @endphp

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">工作區</p>
            <h1 class="page-title">事件紀錄</h1>
            <p class="page-description">集中檢視所有監測事件，依監測項目、訊號、嚴重度與處理狀態搜尋；完整事件脈絡可在監測詳情頁查看。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('incidents.export', $exportFilters) }}">匯出符合條件的 CSV</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="incident-list-title">
        <div class="panel-header">
            <div>
                <h2 id="incident-list-title" class="panel-title">所有事件</h2>
                <p class="panel-description">{{ $incidents->total() }} 筆符合條件，依最近發現時間排序。</p>
            </div>
        </div>

        <div class="panel-body">
            <search aria-label="搜尋與篩選事件紀錄">
                <form action="{{ route('incidents.index') }}" method="GET">
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="incident-search">監測項目名稱或事件編號</label>
                            <input class="field-input" id="incident-search" name="search" type="search" value="{{ old('search', $filters['search']) }}" maxlength="120" autocomplete="off">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="incident-state">事件狀態</label>
                            <select class="field-select" id="incident-state" name="state">
                                <option value="" @selected(old('state', $filters['state']) === '')>全部狀態</option>
                                @foreach ($stateLabels as $state => $label)
                                    <option value="{{ $state }}" @selected(old('state', $filters['state']) === $state)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="incident-severity">嚴重度</label>
                            <select class="field-select" id="incident-severity" name="severity">
                                <option value="" @selected(old('severity', $filters['severity']) === '')>全部嚴重度</option>
                                @foreach ($severityLabels as $severity => $label)
                                    <option value="{{ $severity }}" @selected(old('severity', $filters['severity']) === $severity)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="incident-acknowledgement">認領狀態</label>
                            <select class="field-select" id="incident-acknowledgement" name="acknowledgement">
                                <option value="" @selected(old('acknowledgement', $filters['acknowledgement']) === '')>全部認領狀態</option>
                                <option value="acknowledged" @selected(old('acknowledgement', $filters['acknowledgement']) === 'acknowledged')>已認領</option>
                                <option value="unacknowledged" @selected(old('acknowledgement', $filters['acknowledgement']) === 'unacknowledged')>尚未認領</option>
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="incident-notification-status">提醒狀態</label>
                            <select class="field-select" id="incident-notification-status" name="notification_status">
                                <option value="" @selected(old('notification_status', $filters['notification_status']) === '')>全部提醒狀態</option>
                                <option value="active" @selected(old('notification_status', $filters['notification_status']) === 'active')>提醒啟用</option>
                                <option value="snoozed" @selected(old('notification_status', $filters['notification_status']) === 'snoozed')>提醒暫停中</option>
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="incident-monitor">監測項目</label>
                            <select class="field-select" id="incident-monitor" name="monitor_id">
                                <option value="" @selected(old('monitor_id', $filters['monitor_id']) === '')>全部監測項目</option>
                                @foreach ($monitors as $monitor)
                                    <option value="{{ $monitor->id }}" @selected((string) old('monitor_id', $filters['monitor_id']) === (string) $monitor->id)>{{ $monitor->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="incident-rule">偵測訊號</label>
                            <select class="field-select" id="incident-rule" name="rule_key">
                                <option value="" @selected(old('rule_key', $filters['rule_key']) === '')>全部訊號</option>
                                @foreach ($ruleLabels as $ruleKey => $label)
                                    <option value="{{ $ruleKey }}" @selected(old('rule_key', $filters['rule_key']) === $ruleKey)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="incident-date-from">首次發現日期（起）</label>
                            <input
                                class="field-input @error('date_from') is-invalid @enderror"
                                id="incident-date-from"
                                name="date_from"
                                type="date"
                                value="{{ old('date_from', $filters['date_from']) }}"
                                @if ($errors->has('date_from')) aria-invalid="true" aria-describedby="incident-date-from-error" @endif
                            >
                            @error('date_from')
                                <p id="incident-date-from-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="incident-date-to">首次發現日期（迄）</label>
                            <input
                                class="field-input @error('date_to') is-invalid @enderror"
                                id="incident-date-to"
                                name="date_to"
                                type="date"
                                value="{{ old('date_to', $filters['date_to']) }}"
                                @if ($errors->has('date_to')) aria-invalid="true" aria-describedby="incident-date-to-error" @endif
                            >
                            @error('date_to')
                                <p id="incident-date-to-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <p class="field-help">日期依首次發現時間的台灣日期篩選，起訖日都包含當日。</p>
                    <div class="form-actions">
                        <button class="button button-secondary" type="submit">套用篩選</button>
                        @if ($hasFilters)
                            <a class="strong-link" href="{{ route('incidents.index') }}">清除篩選</a>
                        @endif
                    </div>
                </form>
            </search>
        </div>

        @if ($incidents->isEmpty())
            <div class="empty-state">
                @if ($hasFilters)
                    <h3 class="empty-title">找不到符合條件的事件</h3>
                    <p class="empty-copy">請調整搜尋文字或篩選條件。</p>
                    <a class="button button-secondary" href="{{ route('incidents.index') }}">清除篩選</a>
                @else
                    <h3 class="empty-title">尚無事件紀錄</h3>
                    <p class="empty-copy">監測異常經過確認後，事件與處理狀態會顯示於此處。</p>
                @endif
            </div>
        @else
            <form id="bulk-incident-acknowledgement-form" action="{{ route('incidents.bulk-acknowledge', request()->query()) }}" method="POST">
                @csrf
                @error('acknowledgement_action')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                @error('duration_minutes')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                @error('incident_ids')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                @error('incident_ids.*')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                <div class="form-grid">
                    <div class="field-group">
                        <label class="field-label" for="bulk-incident-acknowledgement-action">批次事件操作</label>
                        <select class="field-select" id="bulk-incident-acknowledgement-action" name="acknowledgement_action" required aria-describedby="bulk-incident-help">
                            <option value="acknowledge" @selected(old('acknowledgement_action', 'acknowledge') === 'acknowledge')>認領所選事件</option>
                            <option value="unacknowledge" @selected(old('acknowledgement_action') === 'unacknowledge')>解除所選事件的認領</option>
                            <option value="snooze_notifications" @selected(old('acknowledgement_action') === 'snooze_notifications')>暫停所選事件的通知</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="bulk-incident-snooze-duration">通知暫停時間</label>
                        <select class="field-select" id="bulk-incident-snooze-duration" name="duration_minutes" aria-describedby="bulk-incident-help">
                            <option value="30" @selected(old('duration_minutes', '60') === '30')>30 分鐘</option>
                            <option value="60" @selected(old('duration_minutes', '60') === '60')>1 小時</option>
                            <option value="240" @selected(old('duration_minutes') === '240')>4 小時</option>
                            <option value="1440" @selected(old('duration_minutes') === '1440')>24 小時</option>
                        </select>
                    </div>
                    <p id="bulk-incident-help" class="field-help">選取本頁進行中的事件；批次暫停只會套用至已成立且通知尚未暫停的事件。認領會略過已認領事件，解除認領會略過尚未認領事件。每次最多處理 25 件，已恢復或已結束的事件無法變更。</p>
                </div>
                <div class="data-table-wrap" role="region" aria-labelledby="incident-list-title" tabindex="0">
                    <table class="data-table">
                        <caption class="sr-only">符合篩選條件的監測事件與處理狀態</caption>
                        <thead>
                            <tr>
                                <th scope="col">
                                    @if ($hasSelectableIncidents)
                                        <label class="field-check monitor-selection-label">
                                            <input type="checkbox" data-incident-select-all aria-controls="bulk-incident-acknowledgement-form">
                                            <span class="sr-only">選取本頁所有進行中的事件</span>
                                        </label>
                                    @else
                                        <span class="sr-only">選取</span>
                                    @endif
                                </th>
                                <th scope="col">事件</th>
                                <th scope="col">監測項目</th>
                                <th scope="col">偵測訊號</th>
                                <th scope="col">嚴重度</th>
                                <th scope="col">狀態</th>
                                <th scope="col">提醒狀態</th>
                                <th scope="col">分類</th>
                                <th scope="col">失敗次數</th>
                                <th scope="col">首次發現</th>
                                <th scope="col">成立時間</th>
                                <th scope="col">恢復時間</th>
                                <th scope="col">認領者</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($incidents as $incident)
                                @php
                                    $classificationLabel = match ($incident->classification) {
                                        'true_issue' => '確認為異常',
                                        'false_alarm' => '誤報',
                                        'unknown' => '尚不確定',
                                        default => '未分類',
                                    };
                                @endphp
                                <tr>
                                    <td>
                                        @if (in_array($incident->state, [\App\Models\Incident::STATE_SUSPECT, \App\Models\Incident::STATE_OPEN], true))
                                            <label class="field-check monitor-selection-label">
                                                <input type="checkbox" name="incident_ids[]" value="{{ $incident->id }}" data-incident-select aria-label="選取事件 #{{ $incident->id }}：{{ $incident->monitor?->name ?? '已刪除的監測項目' }}" aria-describedby="bulk-incident-help">
                                                <span class="sr-only">選取事件 #{{ $incident->id }}</span>
                                            </label>
                                        @else
                                            <span class="secondary-text" aria-hidden="true">—</span><span class="sr-only">此事件不符合批次認領條件</span>
                                        @endif
                                    </td>
                                    <th scope="row">#{{ $incident->id }}</th>
                                    <td>
                                        @if ($incident->monitor)
                                            <a class="monitor-name-link" href="{{ route('monitors.show', $incident->monitor) }}">{{ $incident->monitor->name }}</a>
                                        @else
                                            <span class="secondary-text">已刪除的監測項目</span>
                                        @endif
                                    </td>
                                    <td>{{ $ruleLabels[$incident->rule_key] ?? '其他監測異常' }}</td>
                                    <td><x-state-badge :state="$incident->severity" /></td>
                                    <td><x-state-badge :state="$incident->state" /></td>
                                    <td>
                                        @if ($incident->snoozed_until?->isFuture())
                                            提醒暫停至 <time class="timestamp" datetime="{{ $incident->snoozed_until->toIso8601String() }}">{{ $incident->snoozed_until->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                        @else
                                            提醒啟用
                                        @endif
                                    </td>
                                    <td>{{ $classificationLabel }}</td>
                                    <td class="numeric">{{ $incident->failure_count }}</td>
                                    <td><time class="timestamp" datetime="{{ $incident->created_at->toIso8601String() }}">{{ $incident->created_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></td>
                                    <td>
                                        @if ($incident->opened_at)
                                            <time class="timestamp" datetime="{{ $incident->opened_at->toIso8601String() }}">{{ $incident->opened_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                        @else
                                            <span class="secondary-text">尚未成立</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($incident->resolved_at)
                                            <time class="timestamp" datetime="{{ $incident->resolved_at->toIso8601String() }}">{{ $incident->resolved_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                        @else
                                            <span class="secondary-text">—</span>
                                        @endif
                                    </td>
                                    <td>{{ $incident->acknowledgedBy?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="form-actions">
                    <button class="button button-secondary" type="submit">套用至所選事件</button>
                </div>
            </form>
            @if ($incidents->hasPages())
                <div class="panel-body pagination-wrap">{{ $incidents->links('vendor.pagination.monitor') }}</div>
            @endif
        @endif
    </section>
@endsection
