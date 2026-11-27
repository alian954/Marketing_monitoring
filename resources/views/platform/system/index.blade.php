@extends('layouts.platform')

@section('title', '系統狀態')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">Platform operations</p>
            <h1 class="page-title">系統狀態</h1>
            <p class="page-description">全平台共用的排程器與佇列執行狀態，客戶平台不會顯示這些資訊。</p>
        </div>
    </div>

    @if ($attention !== null)
        <div class="alert alert-warning dashboard-system-attention" role="status">
            <p class="alert-title">{{ $attention['title'] }}</p>
            {{ $attention['description'] }}
        </div>
    @else
        <div class="alert alert-success" role="status">排程器與佇列目前沒有需要處理的狀況。</div>
    @endif

    <section class="panel heartbeat-panel spaced-top" aria-labelledby="runtime-title">
        <div class="heartbeat-item">
            <div class="heartbeat-heading">
                <h2 id="runtime-title" class="panel-title">執行服務</h2>
            </div>
            <div class="heartbeat-heading heartbeat-subheading">
                <span class="heartbeat-dot {{ $heartbeatStatus === 'healthy' ? 'is-fresh' : 'is-stale' }}" aria-hidden="true"></span>
                <h3 class="heartbeat-title">排程器</h3>
                <x-state-badge :state="$heartbeatStatus" />
            </div>
            @if ($heartbeat?->last_succeeded_at)
                <p class="heartbeat-copy">最近一次成功執行</p>
                <time class="heartbeat-time" datetime="{{ $heartbeat->last_succeeded_at->toIso8601String() }}">{{ $heartbeat->last_succeeded_at->timezone('Asia/Taipei')->format('Y/n/j · H:i:s') }}</time>
            @else
                <p class="heartbeat-copy">尚未記錄成功的排程器執行。</p>
            @endif
            @if ($heartbeat?->last_started_at)
                <p class="heartbeat-copy">最近啟動：<time datetime="{{ $heartbeat->last_started_at->toIso8601String() }}">{{ $heartbeat->last_started_at->timezone('Asia/Taipei')->format('n/j H:i') }}</time></p>
            @endif
            @if ($heartbeatError !== null)
                <p class="heartbeat-copy">最近執行錯誤：{{ $heartbeatError }}</p>
            @endif
        </div>

        <div class="heartbeat-item">
            <div class="heartbeat-heading heartbeat-subheading">
                <h3 class="heartbeat-title">Queue worker</h3>
                @if ($queueWorkerEnabled)
                    <span class="heartbeat-dot {{ $workerHeartbeatFresh ? 'is-fresh' : 'is-stale' }}" aria-hidden="true"></span>
                    <x-state-badge :state="$workerHeartbeatFresh ? 'healthy' : 'stale'" />
                @endif
            </div>
            @if (! $queueWorkerEnabled)
                <p class="heartbeat-copy">目前使用 sync 同步佇列，工作由目前程序直接執行，沒有獨立 worker 心跳。</p>
            @else
                @if ($queueSize === null)
                    <p class="heartbeat-copy">目前無法取得佇列工作數。</p>
                @else
                    <p class="heartbeat-copy">目前佇列工作總數：{{ number_format($queueSize) }} 筆（包含延遲與處理中的工作）</p>
                @endif
                @if ($queueStateCounts !== null)
                    <p class="heartbeat-copy">可立即處理 {{ number_format($queueStateCounts['pending']) }} 筆、延遲等待 {{ number_format($queueStateCounts['delayed']) }} 筆、保留中 {{ number_format($queueStateCounts['reserved']) }} 筆。</p>
                @endif
                @if ($checkRunQueueHealth !== null)
                    <p class="heartbeat-copy">監測檢查：到期排隊 {{ number_format($checkRunQueueHealth['due_queued_count']) }} 筆、執行中 {{ number_format($checkRunQueueHealth['running_count']) }} 筆。</p>
                    @if ($checkRunQueueHealth['oldest_due_wait_seconds'] !== null || $checkRunQueueHealth['longest_running_seconds'] !== null)
                        <p class="heartbeat-copy">
                            @if ($checkRunQueueHealth['oldest_due_wait_seconds'] !== null)
                                最久等待 {{ number_format($checkRunQueueHealth['oldest_due_wait_seconds'] / 60, 1) }} 分鐘
                            @endif
                            @if ($checkRunQueueHealth['oldest_due_wait_seconds'] !== null && $checkRunQueueHealth['longest_running_seconds'] !== null)
                                ；
                            @endif
                            @if ($checkRunQueueHealth['longest_running_seconds'] !== null)
                                最久執行 {{ number_format($checkRunQueueHealth['longest_running_seconds'] / 60, 1) }} 分鐘
                            @endif
                        </p>
                    @endif
                    @if ($checkRunQueueHealth['queued_warning'])
                        <p class="heartbeat-copy">有到期檢查已等待超過 2 分鐘，請確認佇列處理狀況。</p>
                    @endif
                    @if ($checkRunQueueHealth['running_warning'])
                        <p class="heartbeat-copy">有檢查已執行超過 3 分鐘，請查看 worker 與該次檢查紀錄。</p>
                    @endif
                @endif
                @if ($workerHeartbeat?->last_succeeded_at)
                    <p class="heartbeat-copy">最近成功處理佇列工作的時間</p>
                    <time class="heartbeat-time" datetime="{{ $workerHeartbeat->last_succeeded_at->toIso8601String() }}">{{ $workerHeartbeat->last_succeeded_at->timezone('Asia/Taipei')->format('Y/n/j · H:i:s') }}</time>
                    @unless ($workerHeartbeatFresh)
                        <p class="heartbeat-copy">超過 2 分鐘沒有心跳，請檢查 queue worker 是否停止或佇列是否塞住。</p>
                    @endunless
                @else
                    <p class="heartbeat-copy">尚未收到 worker 心跳；請確認 queue worker 已啟動且佇列能正常處理工作。</p>
                @endif
            @endif
        </div>
        @if ($failedQueueJobStorageSupported)
            <div class="heartbeat-item">
                <div class="heartbeat-heading heartbeat-subheading">
                    <h3 class="heartbeat-title">失敗佇列工作</h3>
                </div>
                @if ($failedQueueJobCount === null)
                    <p class="heartbeat-copy">目前無法取得失敗佇列工作數。</p>
                @else
                    <p class="heartbeat-copy">目前記錄 {{ number_format($failedQueueJobCount) }} 件失敗工作。</p>
                @endif
            </div>
        @endif
    </section>
@endsection
