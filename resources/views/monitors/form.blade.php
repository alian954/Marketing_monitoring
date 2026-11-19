@extends('layouts.app')

@php
    $isEditing = strtoupper((string) $formMethod) !== 'POST';
    $maskedUrl = $monitor->getAttribute('masked_url');
    $hasSlackWebhook = filled($monitor->notification_slack_webhook);
    $hasDiscordWebhook = filled($monitor->notification_discord_webhook);
    $hasTeamsWebhook = filled($monitor->notification_teams_webhook);
    $redirectHostsValue = old('redirect_hosts');
    if ($redirectHostsValue === null) {
        $redirectHosts = $monitor->allowed_redirect_hosts;
        $redirectHostsValue = is_array($redirectHosts) ? implode("\n", $redirectHosts) : '';
    }
    $acceptedStatusCodes = $monitor->accepted_status_codes;
    $acceptedStatusCodesDefault = is_array($acceptedStatusCodes) ? implode(', ', $acceptedStatusCodes) : '';
    $acceptedStatusCodesValue = old('accepted_status_codes', $acceptedStatusCodesDefault);
    $configuredRequestHeaders = $monitor->request_headers ?? [];
    $configuredResponseHeaderName = $monitor->expectedResponseHeaderName();
@endphp

@section('title', $isEditing ? '編輯監測項目' : '新增監測項目')

@section('content')
    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('monitors.index') }}">監測項目</a><span aria-hidden="true">/</span>
        <span aria-current="page">{{ $isEditing ? '編輯監測項目' : '新增監測項目' }}</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">監測設定</p>
            <h1 class="page-title">{{ $isEditing ? '編輯監測項目' : '新增監測項目' }}</h1>
            <p class="page-description">可監測網站或 Facebook、Instagram、Threads 公開貼文，定期確認連結回應與指定內容。</p>
        </div>
    </div>

    <section class="panel form-panel" aria-labelledby="monitor-form-title">
        <div class="panel-header">
            <div>
                <h2 id="monitor-form-title" class="panel-title">監測目標</h2>
                <p class="panel-description">檢查會使用具備重新導向與目的地保護的安全 HTTP 請求，不會登入社群平台或呼叫平台 API。可先預檢一次網址；預檢不保存回應內容，也不建立監測紀錄或事件。</p>
            </div>
        </div>
        <div class="panel-body">
            @if (is_array(session('probe_preview')))
                @php($probePreview = session('probe_preview'))
                <section class="alert alert-{{ $probePreview['state'] === 'passed' ? 'success' : ($probePreview['state'] === 'incomplete' ? 'warning' : 'error') }}" role="status" aria-live="polite" aria-labelledby="probe-preview-title">
                    <h3 id="probe-preview-title" class="alert-title">{{ $probePreview['label'] }}</h3>
                    <p>{{ $probePreview['message'] }}</p>
                    <ul>
                        <li>HTTP 狀態碼：{{ $probePreview['status_code'] ?? '未收到' }}</li>
                        <li>回應時間：{{ $probePreview['elapsed_ms'] }} 毫秒</li>
                        @if ($probePreview['expected_text_checked'])
                            <li>文字比對：{{ match ([$probePreview['expected_text_state'], $probePreview['expected_text_mode']]) { ['found', 'absent'] => '找到不得出現的文字', ['missing', 'absent'] => '未找到不得出現的文字', ['found', 'present_any'] => '已找到至少一個指定片段', ['missing', 'present_any'] => '未找到任何指定片段', ['found', 'present'] => '已找到全部指定片段', ['missing', 'present'] => '至少缺少一個指定片段', default => '無法判定' } }}</li>
                        @endif
                        @if ($probePreview['expected_text_matches'] !== [])
                            <li>
                                逐項文字片段比對：
                                <ul aria-label="逐項文字片段預檢結果">
                                    @foreach ($probePreview['expected_text_matches'] as $matchDetail)
                                        <li>{{ $matchDetail['term'] }}：{{ $matchDetail['matched'] === null ? '無法判定' : ($matchDetail['matched'] ? '原始回應找到此片段' : '原始回應未找到此片段') }}</li>
                                    @endforeach
                                </ul>
                            </li>
                        @endif
                        @if ($probePreview['expected_response_header_checked'])
                            <li>回應標頭比對：{{ $probePreview['expected_response_header_found'] === null ? '無法判定' : ($probePreview['expected_response_header_found'] ? '符合' : '不符合') }}</li>
                        @endif
                        @if ($probePreview['final_url'])
                            <li>最終網址：<span class="masked-url">{{ $probePreview['final_url'] }}</span></li>
                        @endif
                    </ul>
                </section>
            @endif

            <form action="{{ $formAction }}" method="POST" aria-labelledby="monitor-form-title">
                @csrf
                @if ($isEditing)
                    @method($formMethod)
                @endif

                <div class="form-section">
                    <div class="form-grid">
                        <div class="field-group field-group-full">
                            <label class="field-label" for="name">監測名稱<span class="required-mark" aria-hidden="true">*</span></label>
                            <input class="field-input @error('name') is-invalid @enderror" id="name" name="name" type="text" value="{{ old('name', $monitor->name) }}" maxlength="120" autocomplete="off" required aria-describedby="{{ $errors->has('name') ? 'name-help name-error' : 'name-help' }}">
                            <p id="name-help" class="field-help">請使用容易在總覽中辨識的簡短名稱。</p>
                            @error('name')<p id="name-error" class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group field-group-full">
                            <label class="field-label" for="url">網站或公開貼文網址 @unless ($isEditing)<span class="required-mark" aria-hidden="true">*</span>@endunless</label>
                            @if ($isEditing && $maskedUrl)
                                <p class="field-help">目前目的網址：<span class="masked-url">{{ $maskedUrl }}</span></p>
                            @endif
                            <input class="field-input @error('url') is-invalid @enderror" id="url" name="url" type="url" inputmode="url" autocomplete="url" maxlength="2048" placeholder="{{ $isEditing ? '留空即可保留目前網址' : 'https://example.com/health' }}" @unless ($isEditing) required @endunless aria-describedby="{{ $errors->has('url') ? 'url-help url-error' : 'url-help' }}">
                            <p id="url-help" class="field-help">可貼上 Facebook、Instagram 或 Threads 的公開貼文網址。社群貼文需設定下方文字片段；系統會比對伺服器回傳的原始 HTTP 內容，不執行 JavaScript。若平台回傳登入頁或動態載入外殼，文字可能無法確認，這不代表貼文未發布。網址會加密儲存，畫面只顯示遮罩網址；編輯時留空可保留原網址。</p>
                            @error('url')<p id="url-error" class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="label">標籤</label>
                            <input class="field-input @error('label') is-invalid @enderror" id="label" name="label" type="text" value="{{ old('label', $monitor->label) }}" maxlength="100" autocomplete="off" placeholder="正式環境、API、商店" aria-describedby="{{ $errors->has('label') ? 'label-error' : '' }}">
                            @error('label')<p id="label-error" class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group field-group-full">
                            <label class="field-label" for="notification_email">告警收件信箱</label>
                            <textarea class="field-textarea @error('notification_email') is-invalid @enderror" id="notification_email" name="notification_email" rows="3" maxlength="2549" autocomplete="email" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="{{ $errors->has('notification_email') ? 'notification-email-help notification-email-error' : 'notification-email-help' }}" @if ($errors->has('notification_email')) aria-invalid="true" @endif>{{ old('notification_email', $monitor->notification_email) }}</textarea>
                            <p id="notification-email-help" class="field-help">選填，最多 10 個信箱；每行一個，也可用逗號或分號分隔。填寫後，此監測的異常與恢復通知會寄給清單中的收件人；留空時使用全域 MONITOR_NOTIFY_EMAIL。重複信箱會自動合併。</p>
                            @error('notification_email')<p id="notification-email-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group field-group-full">
                            <label class="field-label" for="notification_escalation_email">升級告警收件信箱</label>
                            <textarea class="field-textarea @error('notification_escalation_email') is-invalid @enderror" id="notification_escalation_email" name="notification_escalation_email" rows="2" maxlength="2549" autocomplete="email" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="{{ $errors->has('notification_escalation_email') ? 'notification-escalation-email-help notification-escalation-email-error' : 'notification-escalation-email-help' }}" @if ($errors->has('notification_escalation_email')) aria-invalid="true" @endif>{{ old('notification_escalation_email', $monitor->notification_escalation_email) }}</textarea>
                            <p id="notification-escalation-email-help" class="field-help">選填，每行一個信箱。事件持續未恢復且未被認領時，等待下方時間後只寄送一次升級通知；此清單不會收到一般異常通知。</p>
                            @error('notification_escalation_email')<p id="notification-escalation-email-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="notification_escalation_delay_minutes">升級等待時間（分鐘）</label>
                            <input class="field-input @error('notification_escalation_delay_minutes') is-invalid @enderror" id="notification_escalation_delay_minutes" name="notification_escalation_delay_minutes" type="number" inputmode="numeric" min="5" max="1440" step="1" value="{{ old('notification_escalation_delay_minutes', $monitor->notification_escalation_delay_minutes) }}" aria-invalid="{{ $errors->has('notification_escalation_delay_minutes') ? 'true' : 'false' }}" aria-describedby="{{ $errors->has('notification_escalation_delay_minutes') ? 'notification-escalation-delay-help notification-escalation-delay-error' : 'notification-escalation-delay-help' }}">
                            <p id="notification-escalation-delay-help" class="field-help">設定升級收件信箱時必填，範圍為 5–1440 分鐘。事件若已恢復或在寄送前被認領，升級通知會取消。</p>
                            @error('notification_escalation_delay_minutes')<p id="notification-escalation-delay-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group field-group-full">
                            <label class="field-label" for="notification_escalation_stage_2_email">第二階段升級收件信箱</label>
                            <textarea class="field-textarea @error('notification_escalation_stage_2_email') is-invalid @enderror" id="notification_escalation_stage_2_email" name="notification_escalation_stage_2_email" rows="2" maxlength="2549" autocomplete="email" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="{{ $errors->has('notification_escalation_stage_2_email') ? 'notification-escalation-stage-2-email-help notification-escalation-stage-2-email-error' : 'notification-escalation-stage-2-email-help' }}" @if ($errors->has('notification_escalation_stage_2_email')) aria-invalid="true" @endif>{{ old('notification_escalation_stage_2_email', $monitor->notification_escalation_stage_2_email) }}</textarea>
                            <p id="notification-escalation-stage-2-email-help" class="field-help">選填，第一階段後仍未恢復且未被認領時，等待第二階段時間後通知這組收件人。需先設定第一階段。</p>
                            @error('notification_escalation_stage_2_email')<p id="notification-escalation-stage-2-email-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="notification_escalation_stage_2_delay_minutes">第二階段等待時間（分鐘）</label>
                            <input class="field-input @error('notification_escalation_stage_2_delay_minutes') is-invalid @enderror" id="notification_escalation_stage_2_delay_minutes" name="notification_escalation_stage_2_delay_minutes" type="number" inputmode="numeric" min="5" max="1440" step="1" value="{{ old('notification_escalation_stage_2_delay_minutes', $monitor->notification_escalation_stage_2_delay_minutes) }}" aria-invalid="{{ $errors->has('notification_escalation_stage_2_delay_minutes') ? 'true' : 'false' }}" aria-describedby="{{ $errors->has('notification_escalation_stage_2_delay_minutes') ? 'notification-escalation-stage-2-delay-help notification-escalation-stage-2-delay-error' : 'notification-escalation-stage-2-delay-help' }}">
                            <p id="notification-escalation-stage-2-delay-help" class="field-help">設定第二階段收件信箱時必填，範圍為 5–1440 分鐘，且必須晚於第一階段；時間從事件開始計算。</p>
                            @error('notification_escalation_stage_2_delay_minutes')<p id="notification-escalation-stage-2-delay-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group field-group-full">
                            <label class="field-label" for="notification_slack_webhook">Slack Incoming Webhook</label>
                            <p id="notification-slack-webhook-help" class="field-help">
                                @if ($hasSlackWebhook)
                                    此監測已設定專用 Slack webhook（網址已遮蔽）；留白會保留目前設定。
                                @else
                                    選填，留白時使用全域 MONITOR_SLACK_WEBHOOK_URL。Webhook 僅接受 Slack HTTPS 網址，並會加密儲存。
                                @endif
                            </p>
                            <div class="secret-input-row">
                                <input class="field-input @error('notification_slack_webhook') is-invalid @enderror" id="notification_slack_webhook" name="notification_slack_webhook" type="password" value="" maxlength="2048" autocomplete="new-password" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="notification-slack-webhook-help{{ $errors->has('notification_slack_webhook') ? ' notification-slack-webhook-error' : '' }}" @if ($errors->has('notification_slack_webhook')) aria-invalid="true" @endif>
                                <button class="button button-secondary" type="button" data-secret-toggle aria-controls="notification_slack_webhook" aria-pressed="false" data-show-label="顯示網址" data-hide-label="隱藏網址" hidden>顯示網址</button>
                            </div>
                            @if ($isEditing && $hasSlackWebhook)
                                <label class="field-check spaced-top" for="clear_notification_slack_webhook">
                                    <input id="clear_notification_slack_webhook" name="clear_notification_slack_webhook" type="checkbox" value="1" @checked(old('clear_notification_slack_webhook'))>
                                    移除此監測專用 webhook，改用全域 Slack 設定
                                </label>
                            @endif
                            @error('notification_slack_webhook')<p id="notification-slack-webhook-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group field-group-full">
                            <label class="field-label" for="notification_discord_webhook">Discord Incoming Webhook</label>
                            <p id="notification-discord-webhook-help" class="field-help">
                                @if ($hasDiscordWebhook)
                                    此監測已設定專用 Discord webhook（網址已遮蔽）；留白會保留目前設定。
                                @else
                                    選填，留白時使用全域 MONITOR_DISCORD_WEBHOOK_URL。Webhook 僅接受 discord.com HTTPS 網址，並會加密儲存。
                                @endif
                            </p>
                            <div class="secret-input-row">
                                <input class="field-input @error('notification_discord_webhook') is-invalid @enderror" id="notification_discord_webhook" name="notification_discord_webhook" type="password" value="" maxlength="2048" autocomplete="new-password" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="notification-discord-webhook-help{{ $errors->has('notification_discord_webhook') ? ' notification-discord-webhook-error' : '' }}" @if ($errors->has('notification_discord_webhook')) aria-invalid="true" @endif>
                                <button class="button button-secondary" type="button" data-secret-toggle aria-controls="notification_discord_webhook" aria-pressed="false" data-show-label="顯示網址" data-hide-label="隱藏網址" hidden>顯示網址</button>
                            </div>
                            @if ($isEditing && $hasDiscordWebhook)
                                <label class="field-check spaced-top" for="clear_notification_discord_webhook">
                                    <input id="clear_notification_discord_webhook" name="clear_notification_discord_webhook" type="checkbox" value="1" @checked(old('clear_notification_discord_webhook'))>
                                    移除此監測專用 webhook，改用全域 Discord 設定
                                </label>
                            @endif
                            @error('notification_discord_webhook')<p id="notification-discord-webhook-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group field-group-full">
                            <label class="field-label" for="notification_teams_webhook">Microsoft Teams Workflows Webhook</label>
                            <p id="notification-teams-webhook-help" class="field-help">
                                @if ($hasTeamsWebhook)
                                    此監測已設定專用 Teams Workflows webhook（網址已遮蔽）；留白會保留目前設定。
                                @else
                                    選填，留白時使用全域 MONITOR_NOTIFY_TEAMS_WEBHOOK_URL。請建立 Teams Workflows Incoming Webhook，並選擇允許外部 webhook 呼叫；網址會加密儲存。<a href="https://learn.microsoft.com/microsoftteams/platform/webhooks-and-connectors/how-to/add-incoming-webhook">查看 Microsoft 設定說明</a>。
                                @endif
                            </p>
                            <div class="secret-input-row">
                                <input class="field-input @error('notification_teams_webhook') is-invalid @enderror" id="notification_teams_webhook" name="notification_teams_webhook" type="password" value="" maxlength="2048" autocomplete="new-password" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="notification-teams-webhook-help{{ $errors->has('notification_teams_webhook') ? ' notification-teams-webhook-error' : '' }}" @if ($errors->has('notification_teams_webhook')) aria-invalid="true" @endif>
                                <button class="button button-secondary" type="button" data-secret-toggle aria-controls="notification_teams_webhook" aria-pressed="false" data-show-label="顯示網址" data-hide-label="隱藏網址" hidden>顯示網址</button>
                            </div>
                            @if ($isEditing && $hasTeamsWebhook)
                                <label class="field-check spaced-top" for="clear_notification_teams_webhook">
                                    <input id="clear_notification_teams_webhook" name="clear_notification_teams_webhook" type="checkbox" value="1" @checked(old('clear_notification_teams_webhook'))>
                                    移除此監測專用 webhook，改用全域 Teams 設定
                                </label>
                            @endif
                            @error('notification_teams_webhook')<p id="notification-teams-webhook-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group field-group-full">
                            <label class="field-label" for="accepted_status_codes">額外接受的 HTTP 狀態碼</label>
                            <input class="field-input @error('accepted_status_codes') is-invalid @enderror" id="accepted_status_codes" name="accepted_status_codes" type="text" value="{{ $acceptedStatusCodesValue }}" maxlength="1000" autocomplete="off" placeholder="例如：401, 404 429" aria-describedby="{{ $errors->has('accepted_status_codes') ? 'accepted-status-codes-help accepted-status-codes-error' : 'accepted-status-codes-help' }}" @if ($errors->has('accepted_status_codes')) aria-invalid="true" @endif>
                            <p id="accepted-status-codes-help" class="field-help">選填，輸入以逗號或空白分隔的 400–599 狀態碼。預設只接受 200–399；加入 429 後，429 會視為預期回應，不會觸發限制警告。</p>
                            @error('accepted_status_codes')<p id="accepted-status-codes-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="interval_seconds">檢查頻率</label>
                            <select class="field-select @error('interval_seconds') is-invalid @enderror" id="interval_seconds" name="interval_seconds" required @if ($errors->has('interval_seconds')) aria-invalid="true" @endif aria-describedby="{{ $errors->has('interval_seconds') ? 'interval-help interval-error' : 'interval-help' }}">
                                @foreach ($intervalOptions as $seconds => $label)
                                    <option value="{{ $seconds }}" @selected((string) old('interval_seconds', $monitor->interval_seconds ?? config('monitoring.interval_seconds')) === (string) $seconds)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p id="interval-help" class="field-help">檢查越頻繁，發出的請求越多。手動檢查不會更改排程頻率。</p>
                            @error('interval_seconds')<p id="interval-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group field-group-full">
                            <label class="field-label" for="expected_text">預期貼文／回應文字片段</label>
                            <textarea class="field-textarea @error('expected_text') is-invalid @enderror" id="expected_text" name="expected_text" rows="4" maxlength="500" autocomplete="off" aria-describedby="{{ $errors->has('expected_text') ? 'expected-text-help expected-text-error' : 'expected-text-help' }}" @if ($errors->has('expected_text')) aria-invalid="true" @endif>{{ old('expected_text', $monitor->expected_text) }}</textarea>
                            <p id="expected-text-help" class="field-help">每行輸入一個文字片段，最多 500 字。「全部片段都需包含」要求每一行都找到；「至少包含一個片段」找到任一行即可；「不得出現」只要找到任一行就告警。任一片段模式適合原始回應只呈現部分貼文文字的情況；請使用具辨識度的片語降低誤符合。社群貼文需填文字並選擇前兩種條件之一。系統不保存整份回應或截圖；比對不區分大小寫。</p>
                            @error('expected_text')<p id="expected-text-error" class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="expected_text_mode">文字符合條件</label>
                            <select class="field-select @error('expected_text_mode') is-invalid @enderror" id="expected_text_mode" name="expected_text_mode" required aria-describedby="{{ $errors->has('expected_text_mode') ? 'expected-text-mode-help expected-text-mode-error' : 'expected-text-mode-help' }}" @if ($errors->has('expected_text_mode')) aria-invalid="true" @endif>
                                <option value="present" @selected(old('expected_text_mode', $monitor->expected_text_mode ?? 'present') === 'present')>全部片段都需包含</option>
                                <option value="present_any" @selected(old('expected_text_mode', $monitor->expected_text_mode ?? 'present') === 'present_any')>至少包含一個片段</option>
                                <option value="absent" @selected(old('expected_text_mode', $monitor->expected_text_mode ?? 'present') === 'absent')>回應中不得包含</option>
                            </select>
                            <p id="expected-text-mode-help" class="field-help">只在上方輸入文字時套用。全部片段都需包含會檢查每一行；至少包含一個片段會檢查任一行；不得包含會在找到任一行時告警。</p>
                            @error('expected_text_mode')<p id="expected-text-mode-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="response_time_threshold_ms">回應時間警示門檻（毫秒）</label>
                            <input class="field-input @error('response_time_threshold_ms') is-invalid @enderror" id="response_time_threshold_ms" name="response_time_threshold_ms" type="number" inputmode="numeric" min="100" max="14000" step="1" value="{{ old('response_time_threshold_ms', $monitor->response_time_threshold_ms) }}" aria-invalid="{{ $errors->has('response_time_threshold_ms') ? 'true' : 'false' }}" aria-describedby="{{ $errors->has('response_time_threshold_ms') ? 'response-time-threshold-help response-time-threshold-error' : 'response-time-threshold-help' }}">
                            <p id="response-time-threshold-help" class="field-help">留空即可停用。一般 HTTP 檢查成功且耗時超過 100–14,000 毫秒門檻時，系統會建立警告事件；此值需低於請求逾時上限。</p>
                            @error('response_time_threshold_ms')<p id="response-time-threshold-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="timeout_seconds">請求逾時上限（秒）</label>
                            <input class="field-input @error('timeout_seconds') is-invalid @enderror" id="timeout_seconds" name="timeout_seconds" type="number" inputmode="numeric" min="1" max="15" step="1" value="{{ old('timeout_seconds', $monitor->timeout_seconds ?? config('monitoring.request_timeout_seconds')) }}" required aria-invalid="{{ $errors->has('timeout_seconds') ? 'true' : 'false' }}" aria-describedby="{{ $errors->has('timeout_seconds') ? 'timeout-seconds-help timeout-seconds-error' : 'timeout-seconds-help' }}">
                            <p id="timeout-seconds-help" class="field-help">每次 HTTP 檢查可設定 1–15 秒；一般檢查與 AdsBot 比對各自使用此上限，整個佇列工作仍有 45 秒總期限。</p>
                            @error('timeout_seconds')<p id="timeout-seconds-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="confirmation_delay_seconds">異常與恢復確認延遲（秒）</label>
                            <input class="field-input @error('confirmation_delay_seconds') is-invalid @enderror" id="confirmation_delay_seconds" name="confirmation_delay_seconds" type="number" inputmode="numeric" min="30" max="300" step="15" value="{{ old('confirmation_delay_seconds', $monitor->confirmation_delay_seconds ?? config('monitoring.confirmation_delay_seconds')) }}" required aria-invalid="{{ $errors->has('confirmation_delay_seconds') ? 'true' : 'false' }}" aria-describedby="{{ $errors->has('confirmation_delay_seconds') ? 'confirmation-delay-help confirmation-delay-error' : 'confirmation-delay-help' }}">
                            <p id="confirmation-delay-help" class="field-help">首次異常或恢復後，等待此時間再執行確認檢查；可設 30–300 秒。預設 60 秒，可降低短暫波動造成的誤報或過早恢復。</p>
                            @error('confirmation_delay_seconds')<p id="confirmation-delay-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>

                    </div>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">HTTP 回應標頭條件</h3>
                    <p class="form-section-copy">可額外確認回應標頭是否存在或包含預期片語。條件會加密儲存；為避免洩漏，儲存或預檢後不會重新填入預期值。</p>
                    <div class="field-group field-group-full">
                        <label class="field-label" for="expected_response_header">回應標頭名稱與預期值</label>
                        <p id="expected-response-header-help" class="field-help">格式為「名稱: 預期值」，比對不區分大小寫並檢查值是否包含片語；只確認標頭存在時，冒號後留空。留白會保留現有條件；輸入新條件會取代。最多 1200 字元。</p>
                        <input class="field-input @error('expected_response_header') is-invalid @enderror" id="expected_response_header" name="expected_response_header" type="text" value="" maxlength="1200" autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" placeholder="X-Robots-Tag: noindex" aria-describedby="expected-response-header-help{{ $errors->has('expected_response_header') ? ' expected-response-header-error' : '' }}" @if ($errors->has('expected_response_header')) aria-invalid="true" @endif>
                        @if ($configuredResponseHeaderName !== null)
                            <p class="field-help">目前監測標頭「{{ $configuredResponseHeaderName }}」；{{ $monitor->expectedResponseHeaderChecksPresenceOnly() ? '只檢查是否存在' : '預期值已加密保存' }}。</p>
                            <label class="field-check spaced-top" for="clear_expected_response_header">
                                <input id="clear_expected_response_header" name="clear_expected_response_header" type="checkbox" value="1" aria-describedby="expected-response-header-help{{ $errors->has('clear_expected_response_header') ? ' clear-expected-response-header-error' : '' }}" @checked(old('clear_expected_response_header'))>
                                移除目前回應標頭條件
                            </label>
                        @endif
                        @error('expected_response_header')<p id="expected-response-header-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        @error('clear_expected_response_header')<p id="clear-expected-response-header-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">自訂 HTTP 請求標頭</h3>
                    <p class="form-section-copy">可設定 Authorization、Cookie 或其他網站所需標頭；設定後監測網址必須使用 HTTPS。敏感值會加密保存，只傳送至原始主機，不會帶到跨主機重新導向。</p>
                    <div class="field-group field-group-full">
                        <label class="field-label" for="request_headers">標頭名稱與值</label>
                        <textarea class="field-textarea @error('request_headers') is-invalid @enderror" id="request_headers" name="request_headers" rows="4" maxlength="8192" autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="{{ $errors->has('request_headers') ? 'request-headers-help request-headers-error' : 'request-headers-help' }}" @if ($errors->has('request_headers')) aria-invalid="true" @endif>{{ old('request_headers', '') }}</textarea>
                        <p id="request-headers-help" class="field-help">每行輸入一個「名稱: 值」，最多 20 個，每個值最多 2048 字元，總長度最多 8192 位元組。User-Agent、Host、Proxy 與連線控制標頭由安全設定管理。預檢或驗證失敗後為保護憑證，標頭值不會留在表單；如要新增，請重新輸入。</p>
                        @if ($configuredRequestHeaders !== [])
                            <p class="field-help">目前已加密保存 {{ count($configuredRequestHeaders) }} 個標頭：{{ implode('、', array_keys($configuredRequestHeaders)) }}。留白會保留原設定；輸入新清單會取代原設定。</p>
                            <label class="field-check spaced-top" for="clear_request_headers">
                                <input id="clear_request_headers" name="clear_request_headers" type="checkbox" value="1" aria-describedby="{{ $errors->has('clear_request_headers') ? 'request-headers-help clear-request-headers-error' : 'request-headers-help' }}" @checked(old('clear_request_headers'))>
                                移除目前全部自訂標頭
                            </label>
                        @endif
                        @error('request_headers')<p id="request-headers-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        @error('clear_request_headers')<p id="clear-request-headers-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">允許的重新導向主機</h3>
                    <p class="form-section-copy">只有在網站預期會重新導向至其他主機時才需新增。檢查不會跟隨清單以外的主機。</p>
                    <div class="field-group">
                        <label class="field-label" for="redirect_hosts">主機名稱</label>
                        <textarea class="field-textarea @error('redirect_hosts') is-invalid @enderror" id="redirect_hosts" name="redirect_hosts" rows="3" autocomplete="off" placeholder="www.example.com&#10;login.example.com" aria-describedby="{{ $errors->has('redirect_hosts') ? 'redirect-hosts-help redirect-hosts-error' : 'redirect-hosts-help' }}">{{ $redirectHostsValue }}</textarea>
                        <p id="redirect-hosts-help" class="field-help">每行輸入一個主機名稱，也可用逗號分隔。請勿包含通訊協定或路徑。</p>
                        @error('redirect_hosts')<p id="redirect-hosts-error" class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                @if ($isEditing)
                    <div class="form-note spaced-top">
                        <strong>儲存設定會建立新的版本。</strong> 舊版本排隊中的檢查會標記為過期。
                    </div>
                @endif

                <div class="form-actions">
                    <a class="button button-secondary" href="{{ $isEditing ? route('monitors.show', $monitor) : route('monitors.index') }}">取消</a>
                    <button class="button button-secondary" type="submit" formaction="{{ $previewAction }}">預檢網址</button>
                    <button class="button button-primary" type="submit">{{ $isEditing ? '儲存變更' : '新增監測項目' }}</button>
                </div>
            </form>
        </div>
    </section>
@endsection
