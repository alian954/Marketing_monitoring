@extends('layouts.app')

@section('title', '匯入監測項目')

@section('content')
    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('monitors.index') }}">監測項目</a><span aria-hidden="true">/</span>
        <span aria-current="page">匯入 CSV</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">批次設定</p>
            <h1 class="page-title">匯入監測項目</h1>
            <p class="page-description">上傳 CSV 後逐列檢視，確認可匯入的監測項目後才會儲存。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('monitors.import.template') }}">下載匯入 CSV 範本</a>
            <a class="button button-secondary" href="{{ route('monitors.export') }}">下載目前清單</a>
        </div>
    </div>

    <section class="panel form-panel" aria-labelledby="csv-upload-title">
        <div class="panel-header">
            <div>
                <h2 id="csv-upload-title" class="panel-title">CSV 檔案</h2>
                <p class="panel-description">最多 500 列。第一列必須以 <code>name,url,label</code> 開頭，可依序加入文字、HTTP 標頭、排程與效能條件。</p>
            </div>
        </div>
        <div class="panel-body">
            <form action="{{ route('monitors.import.preview') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="field-group">
                    <label class="field-label" for="csv_file">選擇 CSV 檔案<span class="required-mark" aria-hidden="true">*</span></label>
                    <input class="field-input @error('csv_file') is-invalid @enderror" id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required aria-describedby="{{ $errors->has('csv_file') ? 'csv-help csv-file-error' : 'csv-help' }}">
                    <p id="csv-help" class="field-help">必要欄位：<code>name</code>、<code>url</code>、<code>label</code>。延伸欄位依序為 <code>expected_text</code>、<code>expected_text_mode</code>、<code>alert_on_visible_text_change</code>、<code>alert_on_response_metadata_change</code>、<code>expected_response_header</code>、<code>interval_seconds</code>、<code>slo_target_basis_points</code>、<code>timeout_seconds</code>、<code>response_time_threshold_ms</code>、<code>min_response_body_bytes</code>、<code>max_response_body_bytes</code>、<code>accepted_status_codes</code>、<code>request_headers</code>、<code>allowed_redirect_hosts</code>、<code>notification_email</code>、<code>notification_escalation_email</code>、<code>notification_escalation_delay_minutes</code>、<code>notification_escalation_stage_2_email</code>、<code>notification_escalation_stage_2_delay_minutes</code>、<code>notification_slack_webhook</code>、<code>notification_discord_webhook</code>、<code>notification_teams_webhook</code>、<code>confirmation_delay_seconds</code>、<code>enabled</code>、<code>public_status</code>；可省略不用欄位，但需保持順序。<code>slo_target_basis_points</code> 支援 9900、9950、9990、9995 或 9999；留空或省略時使用 9990（99.90%）。可用 <code>expected_text_mode=present</code> 要求全部片段都出現，或用 <code>present_any</code> 只要求任一片段出現；<code>absent</code> 會在任一片段出現時告警。社群貼文需加入 <code>expected_text</code>，並使用 <code>present</code> 或 <code>present_any</code>。省略比對方式時預設為 <code>present</code>。<code>alert_on_visible_text_change</code> 支援 1/0、true/false、yes/no 或 on/off；留空或省略時關閉。開啟後以連續完整回應的可見文字摘要比對變更。<code>expected_response_header</code> 格式為 <code>X-Robots-Tag: noindex</code>；空白預期值只檢查標頭是否存在。<code>interval_seconds</code> 支援 60、300、900、1800 或 3600 秒；留空或省略時使用全域預設。<code>timeout_seconds</code> 可設 1–15 秒；<code>response_time_threshold_ms</code> 可設 100–14,000 毫秒，且必須低於逾時上限；留空時不啟用回應時間警示。<code>min_response_body_bytes</code> 與 <code>max_response_body_bytes</code> 可設定 1 至 {{ (int) config('monitoring.max_body_bytes', 1048576) }} bytes；可只設定一側，兩側同時設定時最小值不可大於最大值。超過本文讀取上限時大小會標示為無法判定。<code>accepted_status_codes</code> 可用逗號或空白分隔 400–599 狀態碼；未設定時接受 200–399。<code>request_headers</code> 每行使用 <code>名稱: 值</code>，最多 20 個且網址必須是 HTTPS；預覽只顯示名稱，不顯示值。<code>allowed_redirect_hosts</code> 每行或以逗號分隔主機名稱；留空時不允許一般跨主機重新導向。<code>notification_email</code> 最多 10 個信箱，可用換行、逗號或分號分隔；預覽只顯示數量，留空時使用全域收件信箱。升級收件信箱欄位同樣最多 10 個信箱；設定收件人時必須提供 5–1440 分鐘等待時間，第二階段需先設定第一階段，且等待時間要更晚。預覽只顯示各階段收件人數量與等待時間。<code>notification_slack_webhook</code>、<code>notification_discord_webhook</code> 與 <code>notification_teams_webhook</code> 僅接受對應平台 HTTPS webhook；預覽只顯示已設定的通道，不會顯示網址，留空時使用全域設定。<code>confirmation_delay_seconds</code> 可設定 30–300 秒以確認異常與恢復；留空或省略時使用全域預設。<code>enabled</code> 支援 1/0、true/false、yes/no 或 on/off；留白或省略時啟用，設為 false 可先匯入並暫停檢查。所有資料列先預覽，確認後才會儲存；網址、標頭條件值、收件信箱與 webhook 網址不會在預覽顯示。</p>
                    <p class="field-help"><code>label</code> 最多可設定 10 個標籤，以逗號、分號或換行分隔；每個標籤最多 100 字。</p>
                    <p class="field-help"><code>public_status</code> 可用 1/0、true/false、yes/no 或 on/off；留白或省略時不公開。開啟後，監測結果會納入已發布的公開狀態頁。</p>
                    <p class="field-help">在 <code>expected_text_mode</code>、<code>alert_on_visible_text_change</code> 之後，可依序加入 <code>alert_on_response_metadata_change</code>；支援 1/0、true/false、yes/no 或 on/off，留空或省略時關閉。開啟後會比對分享標題、摘要、圖片參照、Open Graph 類型、標準網址參照及發佈／修改時間，變更會建立警告。</p>
                    @error('csv_file')<p id="csv-file-error" class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="form-actions">
                    <a class="button button-secondary" href="{{ route('monitors.index') }}">取消</a>
                    <button class="button button-primary" type="submit">預覽匯入內容</button>
                </div>
            </form>
        </div>
    </section>

    @isset($previewRows)
        @php
            $rowCount = count($previewRows);
            $canCommit = isset($previewToken) && ! empty($previewToken) && ($validCount ?? 0) > 0;
        @endphp

        <section class="panel import-preview" aria-labelledby="preview-title">
            <div class="panel-header">
                <div>
                    <h2 id="preview-title" class="panel-title">檢視匯入預覽</h2>
                    <p class="panel-description">已解析 {{ $rowCount }} 列。確認前請檢查錯誤與重複項目。</p>
                </div>
            </div>
            <div class="panel-body">
                <div class="import-summary" aria-label="預覽統計">
                    <span class="summary-chip is-good">{{ $validCount ?? 0 }} 列可匯入</span>
                    <span class="summary-chip is-bad">{{ $invalidCount ?? 0 }} 列需處理</span>
                </div>

                @if (count($previewRows) === 0)
                    <div class="empty-state">
                        <h3 class="empty-title">找不到資料列</h3>
                        <p class="empty-copy">檔案中沒有可供預覽的監測資料。</p>
                    </div>
                @else
                    <div class="data-table-wrap preview-table-frame" role="region" aria-labelledby="preview-title" tabindex="0">
                        <table class="data-table">
                            <caption class="sr-only">CSV 資料列、遮罩目的網址、檢查頻率、服務可用率目標、異常確認延遲、匯入後監測狀態、公開狀態頁設定、請求逾時、回應時間與本文大小門檻、額外接受狀態碼、自訂請求標頭、允許的重新導向主機、告警信箱數量、升級通知階段、Webhook 通道、預期文字與 HTTP 標頭條件及匯入資格</caption>
                            <thead>
                                <tr>
                                    <th scope="col">列號</th>
                                    <th scope="col">監測名稱</th>
                                    <th scope="col">目的網址</th>
                                    <th scope="col">標籤</th>
                                    <th scope="col">檢查頻率</th>
                                    <th scope="col">服務可用率目標</th>
                                    <th scope="col">異常確認延遲</th>
                                    <th scope="col">匯入後監測狀態</th>
                                    <th scope="col">公開狀態頁</th>
                                    <th scope="col">請求逾時</th>
                                    <th scope="col">回應時間／本文大小門檻</th>
                                    <th scope="col">額外接受狀態碼</th>
                                    <th scope="col">自訂請求標頭</th>
                                    <th scope="col">允許的重新導向主機</th>
                                    <th scope="col">告警信箱</th>
                                    <th scope="col">升級通知</th>
                                    <th scope="col">Webhook 通道</th>
                                    <th scope="col">預期文字條件</th>
                                    <th scope="col">HTTP 標頭條件</th>
                                    <th scope="col">檢查結果</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($previewRows as $previewRow)
                                    @php
                                        $rowErrors = data_get($previewRow, 'errors', []);
                                        $rowErrors = is_array($rowErrors) ? $rowErrors : ($rowErrors ? [$rowErrors] : []);
                                        $isDuplicate = (bool) data_get($previewRow, 'duplicate', false);
                                        $intervalSeconds = (int) data_get($previewRow, 'interval_seconds', config('monitoring.interval_seconds'));
                                        $intervalLabel = $intervalSeconds >= 3600 && $intervalSeconds % 3600 === 0
                                            ? intdiv($intervalSeconds, 3600).' 小時'
                                            : intdiv($intervalSeconds, 60).' 分鐘';
                                        $sloTargetBasisPoints = (int) data_get($previewRow, 'slo_target_basis_points', \App\Models\Monitor::DEFAULT_SLO_TARGET_BASIS_POINTS);
                                        $confirmationDelaySeconds = (int) data_get($previewRow, 'confirmation_delay_seconds', config('monitoring.confirmation_delay_seconds'));
                                        $monitorEnabled = (bool) data_get($previewRow, 'enabled', true);
                                        $monitorPublicStatus = (bool) data_get($previewRow, 'public_status', false);
                                        $timeoutSeconds = (int) data_get($previewRow, 'timeout_seconds', config('monitoring.request_timeout_seconds'));
                                        $responseTimeThresholdMs = data_get($previewRow, 'response_time_threshold_ms');
                                        $minimumResponseBodyBytes = data_get($previewRow, 'min_response_body_bytes');
                                        $maximumResponseBodyBytes = data_get($previewRow, 'max_response_body_bytes');
                                        $acceptedStatusCodes = data_get($previewRow, 'accepted_status_codes', []);
                                        $requestHeaderNames = data_get($previewRow, 'request_header_names', []);
                                        $allowedRedirectHosts = data_get($previewRow, 'allowed_redirect_hosts', []);
                                        $notificationEmailCount = (int) data_get($previewRow, 'notification_email_count', 0);
                                        $escalationEmailCount = (int) data_get($previewRow, 'notification_escalation_email_count', 0);
                                        $escalationDelayMinutes = data_get($previewRow, 'notification_escalation_delay_minutes');
                                        $stageTwoEmailCount = (int) data_get($previewRow, 'notification_escalation_stage_2_email_count', 0);
                                        $stageTwoDelayMinutes = data_get($previewRow, 'notification_escalation_stage_2_delay_minutes');
                                        $webhookChannels = [];
                                        if (data_get($previewRow, 'notification_slack_webhook_configured', false)) {
                                            $webhookChannels[] = 'Slack';
                                        }
                                        if (data_get($previewRow, 'notification_discord_webhook_configured', false)) {
                                            $webhookChannels[] = 'Discord';
                                        }
                                        if (data_get($previewRow, 'notification_teams_webhook_configured', false)) {
                                            $webhookChannels[] = 'Microsoft Teams';
                                        }
                                    @endphp
                                    <tr>
                                        <td class="numeric">{{ data_get($previewRow, 'line', '—') }}</td>
                                        <td>{{ data_get($previewRow, 'name', '—') }}</td>
                                        <td><span class="masked-url">{{ data_get($previewRow, 'masked_url', '—') }}</span></td>
                                        <td>{{ data_get($previewRow, 'label') ?: '—' }}</td>
                                        <td>{{ $intervalLabel }}</td>
                                        <td>{{ \App\Models\Monitor::SLO_TARGET_OPTIONS[$sloTargetBasisPoints] ?? '—' }}</td>
                                        <td>{{ $confirmationDelaySeconds }} 秒</td>
                                        <td>{{ $monitorEnabled ? '啟用，排程檢查' : '已暫停，不排程檢查' }}</td>
                                        <td>{{ $monitorPublicStatus ? '加入公開狀態頁' : '僅內部監測' }}</td>
                                        <td>{{ $timeoutSeconds }} 秒</td>
                                        <td>
                                            回應時間：{{ $responseTimeThresholdMs === null ? '—' : number_format((int) $responseTimeThresholdMs).' 毫秒' }}
                                            <br>本文大小：
                                            @if ($minimumResponseBodyBytes !== null && $maximumResponseBodyBytes !== null)
                                                {{ number_format((int) $minimumResponseBodyBytes) }}–{{ number_format((int) $maximumResponseBodyBytes) }} bytes
                                            @elseif ($minimumResponseBodyBytes !== null)
                                                至少 {{ number_format((int) $minimumResponseBodyBytes) }} bytes
                                            @elseif ($maximumResponseBodyBytes !== null)
                                                至多 {{ number_format((int) $maximumResponseBodyBytes) }} bytes
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $acceptedStatusCodes === [] ? '預設 200–399' : implode(', ', $acceptedStatusCodes) }}</td>
                                        <td>{{ $requestHeaderNames === [] ? '—' : '已設定 '.implode('、', $requestHeaderNames).'（值已遮蔽）' }}</td>
                                        <td>{{ $allowedRedirectHosts === [] ? '未設定' : implode('、', $allowedRedirectHosts) }}</td>
                                        <td>{{ $notificationEmailCount === 0 ? '使用全域預設' : '已設定 '.$notificationEmailCount.' 個' }}</td>
                                        <td>
                                            @if ($escalationEmailCount > 0)
                                                第一階段：{{ $escalationEmailCount }} 個／{{ $escalationDelayMinutes }} 分鐘
                                                @if ($stageTwoEmailCount > 0)
                                                    <br>第二階段：{{ $stageTwoEmailCount }} 個／{{ $stageTwoDelayMinutes }} 分鐘
                                                @endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $webhookChannels === [] ? '使用全域預設' : implode('、', $webhookChannels).' 已設定（網址已遮蔽）' }}</td>
                                        <td>
                                            @if (data_get($previewRow, 'expected_text', '') !== '')
                                                {{ match (data_get($previewRow, 'expected_text_mode')) { 'absent' => '不得出現任一片段：', 'present_any' => '至少包含一個片段：', default => '必須包含全部片段：' } }}{{ str_replace("\n", '、', data_get($previewRow, 'expected_text')) }}
                                            @else
                                                —
                                            @endif
                                            <br>可見文字變更警示：{{ data_get($previewRow, 'alert_on_visible_text_change', false) ? '已啟用' : '未啟用' }}
                                            <br>分享預覽變更警示：{{ data_get($previewRow, 'alert_on_response_metadata_change', false) ? '已啟用' : '未啟用' }}
                                        </td>
                                        <td>
                                            @if (data_get($previewRow, 'expected_response_header_name'))
                                                已設定 {{ data_get($previewRow, 'expected_response_header_name') }}（條件值已遮蔽）
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            @if ($isDuplicate)
                                                <x-state-badge state="issue" />
                                                <span class="secondary-text">重複項目</span>
                                            @elseif (data_get($previewRow, 'eligible', false))
                                                <span class="row-valid">可匯入</span>
                                            @else
                                                <x-state-badge state="issue" />
                                            @endif
                                            @if ($rowErrors)
                                                <ul class="row-issues">
                                                    @foreach ($rowErrors as $rowError)
                                                        <li>{{ $rowError }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($canCommit)
                    <div class="confirmation-panel">
                        <span class="heartbeat-dot is-stale" aria-hidden="true"></span>
                        <div>
                            <p><strong>已完成檢視？</strong> 確認後將新增 {{ $validCount }} 個可匯入的監測項目。有錯誤或網址重複的資料列將會略過。</p>
                            <form action="{{ route('monitors.import.commit') }}" method="POST" class="confirm-submit">
                                @csrf
                                <input type="hidden" name="preview_token" value="{{ $previewToken }}">
                                <button class="button button-primary" type="submit">確認並匯入 {{ $validCount }} 個監測項目</button>
                            </form>
                        </div>
                    </div>
                @else
                    <div class="form-note import-empty-note">目前沒有可匯入的資料列。請修正 CSV 中列出的問題後重新上傳。</div>
                @endif
            </div>
        </section>
    @endisset
@endsection
