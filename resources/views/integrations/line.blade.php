@extends('layouts.app')

@section('title', 'LINE 通知')

@section('content')
    @php
        $lineChannelErrors = $errors->getBag('lineChannel');
        $activeTargets = $targets->filter(static fn ($target): bool => $target->isActive());
    @endphp

    <div class="page-heading">
        <div>
            <p class="eyebrow">通知管道</p>
            <h1>LINE 通知</h1>
            <p class="page-description">把行銷警示推播到 LINE 群組或個人。使用你自己的 LINE 官方帳號，訊息會計入該帳號每月的免費訊息額度。</p>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif
    @error('line_target')
        <div class="alert alert-error" role="alert">{{ $message }}</div>
    @enderror

    <section class="panel" aria-labelledby="line-channel-title">
        <div class="panel-header">
            <div>
                <h2 id="line-channel-title" class="panel-title">LINE 官方帳號</h2>
                <p class="panel-description">在 LINE Developers 建立 Messaging API 頻道後，貼上頻道密鑰與長期頻道存取權杖。兩者都會加密保存且不會再次顯示。</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($channel)
                <p class="connection-status" role="status">
                    已連結 {{ $channel->bot_display_name ?? 'LINE 官方帳號' }}@if ($channel->bot_basic_id)（{{ $channel->bot_basic_id }}）@endif。
                </p>
            @endif

            <form action="{{ route('integrations.line.update') }}" method="POST" class="check-history-filter" aria-labelledby="line-channel-title">
                @csrf
                @method('PUT')
                <div class="field-group">
                    <label class="field-label" for="line-channel-secret">頻道密鑰 Channel secret（{{ $channel ? '選填，留白保留原值' : '必填' }}）</label>
                    <input
                        class="field-input{{ $lineChannelErrors->has('channel_secret') ? ' is-invalid' : '' }}"
                        id="line-channel-secret"
                        name="channel_secret"
                        type="password"
                        maxlength="64"
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="none"
                        spellcheck="false"
                        aria-describedby="line-channel-secret-help{{ $lineChannelErrors->has('channel_secret') ? ' line-channel-secret-error' : '' }}"
                        @if (! $channel) required @endif
                        @if ($lineChannelErrors->has('channel_secret')) aria-invalid="true" @endif
                    >
                    <p id="line-channel-secret-help" class="field-help">位於頻道的 Basic settings 分頁。</p>
                    @if ($lineChannelErrors->has('channel_secret'))
                        <p id="line-channel-secret-error" class="field-error" role="alert">{{ $lineChannelErrors->first('channel_secret') }}</p>
                    @endif
                </div>
                <div class="field-group">
                    <label class="field-label" for="line-channel-access-token">頻道存取權杖 Channel access token（{{ $channel ? '選填，留白保留原值' : '必填' }}）</label>
                    <input
                        class="field-input{{ $lineChannelErrors->has('channel_access_token') ? ' is-invalid' : '' }}"
                        id="line-channel-access-token"
                        name="channel_access_token"
                        type="password"
                        maxlength="1024"
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="none"
                        spellcheck="false"
                        aria-describedby="line-channel-access-token-help{{ $lineChannelErrors->has('channel_access_token') ? ' line-channel-access-token-error' : '' }}"
                        @if (! $channel) required @endif
                        @if ($lineChannelErrors->has('channel_access_token')) aria-invalid="true" @endif
                    >
                    <p id="line-channel-access-token-help" class="field-help">位於 Messaging API 分頁，請發行長期（long-lived）權杖。儲存時會向 LINE 驗證。</p>
                    @if ($lineChannelErrors->has('channel_access_token'))
                        <p id="line-channel-access-token-error" class="field-error" role="alert">{{ $lineChannelErrors->first('channel_access_token') }}</p>
                    @endif
                </div>
                <div class="form-actions">
                    <button class="button button-primary" type="submit">{{ $channel ? '更新設定' : '連結 LINE 官方帳號' }}</button>
                </div>
            </form>

            @if ($channel)
                <form action="{{ route('integrations.line.destroy') }}" method="POST" class="integration-disconnect-form">
                    @csrf
                    @method('DELETE')
                    <button class="button button-secondary" type="submit">中斷連線</button>
                </form>
            @endif
        </div>
    </section>

    @if ($channel)
        <section class="panel spaced-top" aria-labelledby="line-webhook-title">
            <div class="panel-header">
                <div>
                    <h2 id="line-webhook-title" class="panel-title">設定 Webhook，取得推播對象</h2>
                    <p class="panel-description">LINE 只會在官方帳號被加入群組或被加為好友時告訴系統對象是誰，所以需要先完成這一步。</p>
                </div>
            </div>
            <div class="panel-body">
                <ol class="line-setup-steps">
                    <li>
                        到 LINE Developers 的 Messaging API 分頁，把下列網址貼到 Webhook URL，並開啟「Use webhook」。
                        <div class="field-group">
                            <label class="field-label" for="line-webhook-url">Webhook URL</label>
                            <input class="field-input" id="line-webhook-url" type="text" value="{{ $webhookUrl }}" readonly spellcheck="false">
                        </div>
                    </li>
                    <li>在 LINE Official Account Manager 的回應設定中允許「加入群組或多人聊天室」。</li>
                    <li>把官方帳號邀請進要接收警示的 LINE 群組，或用手機加官方帳號為好友。對象會自動出現在下方清單。</li>
                </ol>
                <p class="field-help">
                    最近一次收到 LINE 事件：
                    @if ($channel->last_webhook_received_at)
                        <time datetime="{{ $channel->last_webhook_received_at->toIso8601String() }}">{{ $channel->last_webhook_received_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                    @else
                        尚未收到
                    @endif
                </p>
            </div>
        </section>

        <section class="panel spaced-top" aria-labelledby="line-target-list-title">
            <div class="panel-header">
                <div>
                    <h2 id="line-target-list-title" class="panel-title">推播對象</h2>
                    <p class="panel-description">{{ number_format($activeTargets->count()) }} 個可推播對象。建立行銷警示規則時可選擇推播到哪一個。</p>
                </div>
            </div>
            @if ($targets->isEmpty())
                <p class="empty-copy panel-body">尚無推播對象。完成上方 Webhook 設定後，把官方帳號加入群組即可。</p>
            @else
                <div class="data-table-wrap">
                    <table class="data-table">
                        <caption class="sr-only">LINE 推播對象的名稱、狀態與操作</caption>
                        <thead>
                            <tr>
                                <th scope="col">對象</th>
                                <th scope="col">狀態</th>
                                <th scope="col"><span class="sr-only">操作</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($targets as $target)
                                <tr>
                                    <th scope="row">{{ $target->label() }}</th>
                                    <td>
                                        @if ($target->isActive())
                                            <span class="badge badge-enabled">可推播</span>
                                        @else
                                            <span class="badge badge-stale">已離開或封鎖</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            @if ($target->isActive())
                                                <form action="{{ route('integrations.line.targets.test', $target) }}" method="POST" class="inline-form">
                                                    @csrf
                                                    <button class="button button-secondary button-small" type="submit">傳送測試訊息</button>
                                                </form>
                                            @endif
                                            <form action="{{ route('integrations.line.targets.destroy', $target) }}" method="POST" class="inline-form">
                                                @csrf
                                                @method('DELETE')
                                                <button class="button button-quiet button-small" type="submit" aria-label="移除 {{ $target->label() }}">移除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
@endsection
