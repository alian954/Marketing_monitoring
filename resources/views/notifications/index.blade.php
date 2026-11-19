@extends('layouts.app')

@section('title', '通知紀錄')

@section('content')
    @php
        $hasFilters = $filters['status'] !== '' || $filters['event_type'] !== '' || $filters['channel'] !== '' || $filters['monitor_id'] !== '' || $filters['date_from'] !== '' || $filters['date_to'] !== '';
    @endphp

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">工作區</p>
            <h1 class="page-title">通知紀錄</h1>
            <p class="page-description">查看電子郵件、Slack 或 Discord 的寄送狀態；可在此測試全域通知管道，也可在監測詳情測試個別收件設定。失敗或結果不明的通知可以重新排入寄送佇列。</p>
        </div>
        <div class="heading-actions">
            <form action="{{ route('notifications.test') }}" method="POST" aria-label="傳送測試通知">
                @csrf
                <button class="button button-secondary" type="submit">傳送測試通知</button>
            </form>
        </div>
    </div>

    <section class="panel" aria-labelledby="notification-list-title">
        <div class="panel-header">
            <div>
                <h2 id="notification-list-title" class="panel-title">寄送紀錄</h2>
                <p class="panel-description">{{ $notifications->total() }} 筆符合條件的通知，依建立時間排序。</p>
            </div>
            <a class="button button-secondary button-small" href="{{ route('notifications.export', array_filter($filters)) }}">匯出 CSV</a>
        </div>

        <div class="panel-body">
            <search>
                <form action="{{ route('notifications.index') }}" method="GET" aria-label="搜尋與篩選通知紀錄">
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="notification-status">寄送狀態</label>
                            <select class="field-select" id="notification-status" name="status">
                                <option value="" @selected($filters['status'] === '')>全部狀態</option>
                                <option value="pending" @selected($filters['status'] === 'pending')>排隊中</option>
                                <option value="sending" @selected($filters['status'] === 'sending')>傳送中</option>
                                <option value="sent" @selected($filters['status'] === 'sent')>已送出</option>
                                <option value="failed" @selected($filters['status'] === 'failed')>失敗</option>
                                <option value="unknown" @selected($filters['status'] === 'unknown')>結果不明</option>
                                <option value="cancelled" @selected($filters['status'] === 'cancelled')>已取消</option>
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="notification-type">通知類型</label>
                            <select class="field-select" id="notification-type" name="event_type">
                                <option value="" @selected($filters['event_type'] === '')>全部類型</option>
                                <option value="opened" @selected($filters['event_type'] === 'opened')>異常通知</option>
                                <option value="recovered" @selected($filters['event_type'] === 'recovered')>恢復通知</option>
                                <option value="reminder" @selected($filters['event_type'] === 'reminder')>持續事件提醒</option>
                                <option value="escalation" @selected($filters['event_type'] === 'escalation')>事件升級通知</option>
                                <option value="escalation_stage_2" @selected($filters['event_type'] === 'escalation_stage_2')>第二階段升級通知</option>
                                <option value="dns_daily_summary" @selected($filters['event_type'] === 'dns_daily_summary')>每日 DNS 變更摘要</option>
                                <option value="{{ \App\Models\NotificationOutbox::EVENT_TYPE_STATUS_OPENED }}" @selected($filters['event_type'] === \App\Models\NotificationOutbox::EVENT_TYPE_STATUS_OPENED)>公開狀態頁異常更新</option>
                                <option value="{{ \App\Models\NotificationOutbox::EVENT_TYPE_STATUS_RECOVERED }}" @selected($filters['event_type'] === \App\Models\NotificationOutbox::EVENT_TYPE_STATUS_RECOVERED)>公開狀態頁恢復更新</option>
                                <option value="test" @selected($filters['event_type'] === 'test')>測試通知</option>
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="notification-channel">通知管道</label>
                            <select class="field-select" id="notification-channel" name="channel">
                                <option value="" @selected($filters['channel'] === '')>全部管道</option>
                                <option value="email" @selected($filters['channel'] === 'email')>電子郵件</option>
                                <option value="slack" @selected($filters['channel'] === 'slack')>Slack webhook</option>
                                <option value="discord" @selected($filters['channel'] === 'discord')>Discord webhook</option>
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="notification-monitor">監測項目</label>
                            <select class="field-select" id="notification-monitor" name="monitor_id">
                                <option value="" @selected($filters['monitor_id'] === '')>全部監測項目</option>
                                @foreach ($monitors as $monitorOption)
                                    <option value="{{ $monitorOption->id }}" @selected($filters['monitor_id'] === (string) $monitorOption->id)>{{ $monitorOption->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="notification-date-from">建立日期（起）</label>
                            <input
                                class="field-input @error('date_from') is-invalid @enderror"
                                id="notification-date-from"
                                name="date_from"
                                type="date"
                                value="{{ old('date_from', $filters['date_from']) }}"
                                @if ($errors->has('date_from')) aria-invalid="true" aria-describedby="notification-date-from-error" @endif
                            >
                            @error('date_from')
                                <p id="notification-date-from-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="notification-date-to">建立日期（迄）</label>
                            <input
                                class="field-input @error('date_to') is-invalid @enderror"
                                id="notification-date-to"
                                name="date_to"
                                type="date"
                                value="{{ old('date_to', $filters['date_to']) }}"
                                @if ($errors->has('date_to')) aria-invalid="true" aria-describedby="notification-date-to-error" @endif
                            >
                            @error('date_to')
                                <p id="notification-date-to-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <p class="field-help">日期依通知建立時間的台灣日期篩選，起訖日都包含當日。</p>
                    <div class="form-actions">
                        <button class="button button-secondary" type="submit">套用篩選</button>
                        @if ($hasFilters)
                            <a class="strong-link" href="{{ route('notifications.index') }}">清除篩選</a>
                        @endif
                    </div>
                </form>
            </search>
        </div>

        @if ($notifications->isEmpty())
            <div class="empty-state">
                @if ($hasFilters)
                    <h3 class="empty-title">找不到符合條件的通知</h3>
                    <p class="empty-copy">請調整通知篩選條件。</p>
                    <a class="button button-secondary" href="{{ route('notifications.index') }}">清除篩選</a>
                @else
                    <h3 class="empty-title">尚無通知紀錄</h3>
                    <p class="empty-copy">事件通知或每日 DNS 摘要建立後，寄送狀態會顯示於此處。</p>
                @endif
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="notification-list-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">通知類型、通知管道、遮蔽目的地、寄送狀態、嘗試次數與建立及送出時間</caption>
                    <thead>
                        <tr>
                            <th scope="col">通知</th>
                            <th scope="col">管道</th>
                            <th scope="col">目的地</th>
                            <th scope="col">狀態</th>
                            <th scope="col">嘗試次數</th>
                            <th scope="col">建立時間</th>
                            <th scope="col">送出時間</th>
                            <th scope="col">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($notifications as $notification)
                            @php
                                $eventLabel = $eventLabels[$notification->event_type] ?? '狀態通知';
                                $monitor = $notification->monitor ?? $notification->incident?->monitor;
                                $badgeStatus = $notification->status === 'pending' ? 'queued' : $notification->status;
                            @endphp
                            <tr>
                                <th scope="row">
                                    @if ($monitor)
                                        <a id="notification-label-{{ $notification->id }}" class="monitor-name-link" href="{{ route('monitors.show', $monitor) }}">{{ $monitor->name }}</a>
                                        <span id="notification-type-{{ $notification->id }}" class="secondary-text">{{ $eventLabel }}</span>
                                    @else
                                        <span id="notification-label-{{ $notification->id }}" class="monitor-name-link">{{ $eventLabel }}</span>
                                    @endif
                                </th>
                                <td>{{ $channelLabels[$notification->channel] ?? $notification->channel }}</td>
                                <td><span dir="ltr">{{ $notification->masked_recipient }}</span></td>
                                <td><x-state-badge :state="$badgeStatus" /></td>
                                <td>{{ $notification->attempts }}</td>
                                <td><time class="timestamp" datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></td>
                                <td>
                                    @if ($notification->sent_at)
                                        <time class="timestamp" datetime="{{ $notification->sent_at->toIso8601String() }}">{{ $notification->sent_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                    @else
                                        <span aria-label="尚未送出">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if (in_array($notification->status, ['failed', 'unknown'], true))
                                        <form class="acknowledge-box" action="{{ route('notifications.retry', $notification) }}" method="POST" @if ($monitor) aria-labelledby="notification-label-{{ $notification->id }} notification-type-{{ $notification->id }}" @else aria-labelledby="notification-label-{{ $notification->id }}" @endif>
                                            @csrf
                                            <label class="field-check" for="acknowledge-duplicate-risk-{{ $notification->id }}">
                                                <input id="acknowledge-duplicate-risk-{{ $notification->id }}" type="checkbox" name="acknowledge_duplicate_risk" value="1" required>
                                                <span>我了解重試可能會重複傳送通知。</span>
                                            </label>
                                            <button class="button button-secondary button-small" type="submit">重試傳送<span class="sr-only">：{{ $eventLabel }}@if ($monitor) {{ $monitor->name }}@endif</span></button>
                                        </form>
                                    @else
                                        <span aria-label="無可用操作">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($notifications->hasPages())
                <div class="panel-body pagination-wrap">{{ $notifications->links('vendor.pagination.monitor') }}</div>
            @endif
        @endif
    </section>
@endsection
