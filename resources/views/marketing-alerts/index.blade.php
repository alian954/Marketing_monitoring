@extends('layouts.app')

@section('title', '行銷警示')

@section('content')
    @php
        $recipientErrors = collect($errors->getMessages())
            ->filter(static fn (array $messages, string $key): bool => str_starts_with($key, 'recipients'))
            ->flatten()
            ->unique();
        $selectedMetric = old('metric', \App\Models\MarketingAlertRule::METRIC_ROAS_BELOW);
        $canManage = ! auth()->user()?->isReadOnlyWorkspaceMember();
        $sourceLabels = ['meta_marketing' => 'Meta Ads', 'google_ads' => 'Google Ads', 'budget' => '活動預算'];
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">行銷警示</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">{{ $client->name }}｜行銷警示</h1>
            <p class="page-description">每天台北時間 07:23 檢查進行中活動前一日的 Meta Ads 與 Google Ads 數據，同一事件只通知一次。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('clients.index') }}">返回客戶</a>
        </div>
    </div>

    <section class="panel spaced-top" aria-labelledby="marketing-alert-rule-list-title">
        <div class="panel-header">
            <div>
                <h2 id="marketing-alert-rule-list-title" class="panel-title">警示規則</h2>
                <p class="panel-description">{{ number_format($rules->count()) }} 條規則，套用到此客戶所有進行中的活動。</p>
            </div>
        </div>
        @if ($rules->isEmpty())
            <p class="empty-copy panel-body">尚未設定警示規則。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">行銷警示規則的類型、門檻、通知對象、狀態與操作</caption>
                    <thead>
                        <tr>
                            <th scope="col">警示類型</th>
                            <th scope="col">門檻</th>
                            <th scope="col">通知對象</th>
                            <th scope="col">狀態</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rules as $rule)
                            @php
                                $destinationLabels = $rule->recipientList();

                                if ($rule->webhook_channel !== null) {
                                    $destinationLabels[] = ['slack' => 'Slack', 'teams' => 'Teams', 'discord' => 'Discord'][$rule->webhook_channel] ?? $rule->webhook_channel;
                                }
                            @endphp
                            <tr>
                                <th scope="row">
                                    {{ $rule->label() }}
                                    <span class="secondary-text">{{ \App\Models\MarketingAlertRule::METRICS[$rule->metric]['help'] ?? '' }}</span>
                                </th>
                                <td>
                                    @if ($rule->requiresThreshold())
                                        {{ \App\Models\MarketingAlertRule::METRICS[$rule->metric]['threshold_label'] }}：{{ rtrim(rtrim((string) $rule->threshold, '0'), '.') }}
                                    @else
                                        <span class="secondary-text">不需門檻</span>
                                    @endif
                                </td>
                                <td>{{ implode('、', $destinationLabels) }}</td>
                                <td>
                                    {{ $rule->isPaused() ? '已暫停' : '啟用中' }}
                                    @if ($rule->last_triggered_at !== null)
                                        <span class="secondary-text">最近觸發 <time datetime="{{ $rule->last_triggered_at->toIso8601String() }}">{{ $rule->last_triggered_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></span>
                                    @endif
                                </td>
                                <td>
                                    @if ($canManage)
                                        <div class="row-actions">
                                            <form action="{{ route('clients.marketing-alerts.update', [$client, $rule]) }}" method="POST" class="inline-form">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="paused" value="{{ $rule->isPaused() ? '0' : '1' }}">
                                                <button class="button button-secondary button-small" type="submit">{{ $rule->isPaused() ? '恢復' : '暫停' }}</button>
                                            </form>
                                            <form action="{{ route('clients.marketing-alerts.destroy', [$client, $rule]) }}" method="POST" class="inline-form">
                                                @csrf
                                                @method('DELETE')
                                                <button class="button button-quiet button-small" type="submit" aria-label="刪除{{ $rule->label() }}規則">刪除</button>
                                            </form>
                                        </div>
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
        <section class="panel spaced-top" aria-labelledby="create-marketing-alert-title">
            <div class="panel-header">
                <h2 id="create-marketing-alert-title" class="panel-title">新增警示規則</h2>
            </div>
            <div class="panel-body">
                <form action="{{ route('clients.marketing-alerts.store', $client) }}" method="POST" class="client-create-form" aria-labelledby="create-marketing-alert-title">
                    @csrf
                    <fieldset class="report-share-targets" aria-describedby="marketing-alert-metric-help{{ $errors->has('metric') ? ' marketing-alert-metric-error' : '' }}">
                        <legend class="field-label">警示類型</legend>
                        <p id="marketing-alert-metric-help" class="field-help">每條規則只檢查一種情況，可建立多條規則。</p>
                        <div class="report-share-target-list">
                            @foreach (\App\Models\MarketingAlertRule::METRICS as $metric => $definition)
                                <label class="report-share-target-option">
                                    <input type="radio" name="metric" value="{{ $metric }}" @checked($selectedMetric === $metric) required>
                                    <span>
                                        <strong>{{ $definition['label'] }}</strong>
                                        <small>{{ $definition['help'] }}@if ($definition['threshold_label'] !== null) 門檻：{{ $definition['threshold_label'] }}@if ($definition['default_threshold'] !== null)，建議 {{ $definition['default_threshold'] }}@endif。@endif</small>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('metric')<p id="marketing-alert-metric-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </fieldset>
                    <div class="field-group">
                        <label class="field-label" for="marketing-alert-threshold">門檻</label>
                        <input class="field-input{{ $errors->has('threshold') ? ' is-invalid' : '' }}" id="marketing-alert-threshold" name="threshold" type="number" step="any" min="0" inputmode="decimal" value="{{ old('threshold') }}" aria-describedby="marketing-alert-threshold-help{{ $errors->has('threshold') ? ' marketing-alert-threshold-error' : '' }}" @if ($errors->has('threshold')) aria-invalid="true" @endif>
                        <p id="marketing-alert-threshold-help" class="field-help">依所選類型填寫；留白時套用建議值，轉換歸零與數據未更新不需門檻。</p>
                        @error('threshold')<p id="marketing-alert-threshold-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="marketing-alert-recipients">收件信箱</label>
                        <textarea class="field-textarea{{ $recipientErrors->isNotEmpty() ? ' is-invalid' : '' }}" id="marketing-alert-recipients" name="recipients" rows="3" autocomplete="email" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="marketing-alert-recipients-help">{{ old('recipients') }}</textarea>
                        <p id="marketing-alert-recipients-help" class="field-help">最多 10 個，以換行或逗號分隔。</p>
                        @foreach ($recipientErrors as $message)
                            <p class="field-error" role="alert">{{ $message }}</p>
                        @endforeach
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="marketing-alert-webhook">Slack、Teams 或 Discord Webhook（選填）</label>
                        <input class="field-input{{ $errors->has('webhook_url') ? ' is-invalid' : '' }}" id="marketing-alert-webhook" name="webhook_url" type="url" maxlength="2048" autocomplete="off" spellcheck="false" aria-describedby="marketing-alert-webhook-help{{ $errors->has('webhook_url') ? ' marketing-alert-webhook-error' : '' }}" @if ($errors->has('webhook_url')) aria-invalid="true" @endif>
                        <p id="marketing-alert-webhook-help" class="field-help">網址會加密保存，建立後不再顯示。</p>
                        @error('webhook_url')<p id="marketing-alert-webhook-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">建立警示規則</button>
                    </div>
                </form>
            </div>
        </section>
    @endif

    <section class="panel spaced-top" aria-labelledby="marketing-alert-event-list-title">
        <div class="panel-header">
            <div>
                <h2 id="marketing-alert-event-list-title" class="panel-title">最近觸發</h2>
                <p class="panel-description">顯示最近 30 則。</p>
            </div>
        </div>
        @if ($recentEvents->isEmpty())
            <p class="empty-copy panel-body">尚無觸發紀錄。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">最近觸發的行銷警示</caption>
                    <thead>
                        <tr>
                            <th scope="col">資料日期</th>
                            <th scope="col">警示</th>
                            <th scope="col">來源</th>
                            <th scope="col">內容</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentEvents as $event)
                            <tr>
                                <td><time datetime="{{ $event->observed_on->toDateString() }}">{{ $event->observed_on->format('Y/n/j') }}</time></td>
                                <td>{{ $event->rule?->label() }}</td>
                                <td>{{ $sourceLabels[$event->source] ?? $event->source }}</td>
                                <td>
                                    @if ($event->target !== null)
                                        <a href="{{ route('social-engagement-targets.report', $event->target) }}">{{ $event->message }}</a>
                                    @else
                                        {{ $event->message }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
