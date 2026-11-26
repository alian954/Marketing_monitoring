@props(['connection', 'dailySyncTime'])

@if ($connection->scheduled_sync_status !== null)
    @php
        $statusPresentation = match ($connection->scheduled_sync_status) {
            'succeeded' => ['label' => '同步正常', 'badge' => 'healthy'],
            'partial' => ['label' => '部分失敗', 'badge' => 'warning'],
            'failed' => ['label' => '同步失敗', 'badge' => 'issue'],
            'running' => ['label' => '同步中', 'badge' => 'running'],
            default => ['label' => '狀態未知', 'badge' => 'unknown'],
        };
        $statusTime = $connection->scheduled_sync_finished_at ?? $connection->scheduled_sync_started_at;
        $statusTimeLabel = $connection->scheduled_sync_status === 'running' ? '開始時間' : '最近執行';
    @endphp

    <section class="integration-sync-health" aria-labelledby="scheduled-sync-health-title">
        <div class="integration-sync-health__heading">
            <h3 id="scheduled-sync-health-title">排程同步</h3>
            <span class="badge badge-{{ $statusPresentation['badge'] }}">{{ $statusPresentation['label'] }}</span>
        </div>
        @if ($statusTime)
            <p>{{ $statusTimeLabel }}：<time datetime="{{ $statusTime->toIso8601String() }}">{{ $statusTime->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></p>
        @endif
        <p>更新 {{ number_format((int) $connection->scheduled_sync_synced_count) }} 個活動 · 略過 {{ number_format((int) $connection->scheduled_sync_skipped_count) }} 個 · 失敗 {{ number_format((int) $connection->scheduled_sync_failed_count) }} 個</p>
        @if (is_string($connection->scheduled_sync_error) && trim($connection->scheduled_sync_error) !== '')
            <p class="integration-sync-health__error">{{ $connection->scheduled_sync_error }}</p>
        @endif
    </section>
@else
    <section class="integration-sync-health" aria-labelledby="scheduled-sync-health-title">
        <div class="integration-sync-health__heading">
            <h3 id="scheduled-sync-health-title">排程同步</h3>
            <span class="badge badge-pending">尚無紀錄</span>
        </div>
        <p>完成客戶資料來源設定並建立活動目標後，排程每日 <time datetime="{{ $dailySyncTime }}">{{ $dailySyncTime }}</time>（台北時間）檢查待更新資料。</p>
    </section>
@endif
