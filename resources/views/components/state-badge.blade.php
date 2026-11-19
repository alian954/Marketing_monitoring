@props(['state' => 'unknown'])

@php
    $normalizedState = strtolower((string) $state);
    $labels = [
        'healthy' => '正常',
        'issue' => '異常',
        'suspect' => '確認中',
        'warning' => '警告',
        'stale' => '逾時',
        'paused' => '已暫停',
        'superseded' => '設定已變更',
        'enabled' => '已啟用',
        'disabled' => '未啟用',
        'pending' => '待檢查',
        'sending' => '傳送中',
        'open' => '已確認',
        'resolved' => '已恢復',
        'completed' => '已完成',
        'queued' => '排隊中',
        'running' => '執行中',
        'failed' => '失敗',
        'error' => '錯誤',
        'unknown' => '未知',
        'stale_config' => '舊版設定',
        'false_alarm' => '誤報',
        'true_issue' => '確認為異常',
        'sent' => '已送出',
        'skipped' => '已略過',
        'cancelled' => '已取消',
    ];
@endphp

<span class="badge badge-{{ $normalizedState }}">
    {{ $labels[$normalizedState] ?? '未知' }}
</span>
