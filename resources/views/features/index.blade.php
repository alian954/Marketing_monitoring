@extends('layouts.app')

@section('title', '功能說明')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">使用指南</p>
            <h1 class="page-title">功能說明</h1>
            <p class="page-description">監測方式、事件判讀與資料範圍。</p>
        </div>
    </div>

    <section class="panel" id="social-monitoring" aria-labelledby="social-monitoring-title">
        <div class="panel-header">
            <div>
                <h2 id="social-monitoring-title" class="panel-title">監測範圍</h2>
                <p class="panel-description">這是網站與貼文健康檢查，也會追蹤頁面公開的互動數；不提供帳號洞察，例如觸及、曝光、點擊、轉換或廣告花費。</p>
                <p class="panel-description">支援網站、Facebook、Instagram、Threads 與 Dcard 公開網址。系統定期讀取原始 HTTP 回應，不登入、不使用平台 API，也不執行 JavaScript；完整回應與截圖不會保存。</p>
                <p class="panel-description">若平台只回傳登入頁或動態載入外殼，系統無法確認貼文內容。HTTP 檢查正常只代表收到回應，不代表貼文一定成功發布或仍對所有人可見。</p>
                <p class="panel-description">若原始回應含明確的觀看、讚、留言或分享數，會記錄每次檢查並呈現近 30 天趨勢與前次變化比例；前次為 0 時不計比例，缺值會標示無法比較。</p>
            </div>
        </div>
    </section>

    <section class="panel spaced-top" id="content-checks" aria-labelledby="content-checks-title">
        <div class="panel-header">
            <div>
                <h2 id="content-checks-title" class="panel-title">文字與分享資訊比對</h2>
                <p class="panel-description">預期文字每行一個片語，可設定全部出現、任一出現或不得出現。比對不分大小寫；社群平台回應不完整時，建議選擇「任一出現」並使用容易辨識的片語。</p>
                <p class="panel-description">可見文字變更警示會以首次完整回應建立基準，之後偵測到變更時告警；連續兩次內容穩定後恢復。動態時間或輪播內容也可能觸發警告。</p>
                <p class="panel-description">分享資訊警示會比較標題、摘要、圖片參照、Open Graph 類型、標準網址與發佈／修改時間。首次完整回應建立基準。</p>
            </div>
        </div>
    </section>

    <section class="panel spaced-top" id="website-checks" aria-labelledby="website-checks-title">
        <div class="panel-header">
            <div>
                <h2 id="website-checks-title" class="panel-title">網站檢查</h2>
                <p class="panel-description">可檢查 HTTP 狀態碼、回應標頭、回應時間與本文大小，並追蹤重新導向、DNS、TLS 憑證、網域到期日及 robots 設定。網站也可模擬 AdsBot；社群貼文略過此檢查。HTTP 狀態預設接受 200–399。</p>
                <p class="panel-description">可先預檢網址；預檢不會保存完整回應或建立監測紀錄。連線會驗證目的位址並封鎖私有及保留 IP；網址和自訂請求標頭會加密保存。</p>
                <p class="panel-description">主機暫時沒有可用 DNS 位址時，仍可先建立監測，排程會繼續檢查。</p>
                <p class="panel-description">設定排程、逾時與異常確認時間。手動檢查不會改變排程。</p>
            </div>
        </div>
    </section>

    <section class="panel spaced-top" id="availability" aria-labelledby="availability-title">
        <div class="panel-header">
            <div>
                <h2 id="availability-title" class="panel-title">可用率與 SLO</h2>
                <p class="panel-description">可用率依排程檢查實際觀測時間計算，不推估資料空窗。覆蓋率顯示有觀測時間占非維護時間的比例；維護時段不計入。</p>
                <p class="panel-description">總覽與監測詳情會比較近 24 小時、7 天及 30 天資料。資料覆蓋不足時，未觀測時間不會當作正常。</p>
                <p class="panel-description">社群貼文需設定預期文字，或啟用可見文字基準，才會計入成功率與可用率；首次可比較回應只建立基準。</p>
            </div>
        </div>
    </section>

    <section class="panel spaced-top" id="events-notifications" aria-labelledby="events-notifications-title">
        <div class="panel-header">
            <div>
                <h2 id="events-notifications-title" class="panel-title">事件與通知</h2>
                <p class="panel-description">異常可設定延遲確認，減少短暫波動誤報。事件可認領、加註備註、暫停提醒或安排維護；認領不會自動結束事件。</p>
                <p class="panel-description">通知支援電子郵件、Slack、Discord 與 Microsoft Teams。可使用全域設定或為監測項目指定收件人，並設定持續未恢復事件的升級通知。</p>
            </div>
        </div>
    </section>

    <section class="panel spaced-top" id="status-pages" aria-labelledby="status-pages-title">
        <div class="panel-header">
            <div>
                <h2 id="status-pages-title" class="panel-title">公開狀態頁與資料匯出</h2>
                <p class="panel-description">公開狀態頁可顯示已選取項目的狀態、成功率、可用率、近期事件與維護；不公開網址、比對文字或事件備註。訪客可訂閱異常與恢復通知。</p>
                <p class="panel-description">監測項目、通知紀錄、事件與可用率資料可匯出 CSV；監測項目也可透過 CSV 批次匯入。</p>
            </div>
        </div>
    </section>

    <section class="panel spaced-top" id="csv-import" aria-labelledby="csv-import-title">
        <div class="panel-header">
            <div>
                <h2 id="csv-import-title" class="panel-title">CSV 匯入</h2>
                <p class="panel-description">最多匯入 500 列。下載範本，填寫必要欄位與需要的選項；預覽確認後才會儲存。</p>
                <p class="panel-description"><a href="{{ route('monitors.import.template') }}">下載匯入 CSV 範本</a></p>
                <details class="public-status-history-details">
                    <summary>查看欄位格式與限制</summary>
                    <div class="spaced-top">
                        <p class="field-help">必要欄位依序為 <code>name</code>、<code>url</code>、<code>label</code>。選填欄位要照範本順序，可省略未使用的欄位。</p>
                        <p class="field-help"><code>label</code> 最多 10 個標籤，每個最多 100 字；以逗號、分號或換行分隔。</p>
                        <p class="field-help"><code>expected_text</code> 搭配 <code>expected_text_mode</code> 使用：<code>present</code> 比對全部片語、<code>present_any</code> 比對任一片語、<code>absent</code> 在片語出現時告警。社群網址需使用前兩種模式。</p>
                        <p class="field-help"><code>alert_on_visible_text_change</code> 與 <code>alert_on_response_metadata_change</code> 接受 1/0、true/false、yes/no 或 on/off；留白時關閉。分享資訊警示比對標題、摘要、圖片參照、Open Graph 類型、標準網址及發佈／修改時間。</p>
                        <p class="field-help">檢查選項：頻率為 60、300、900、1800 或 3600 秒；SLO 為 99.00%、99.50%、99.90%、99.95% 或 99.99%；逾時 1–15 秒。回應時間門檻為 100–14,000 毫秒且低於逾時值。</p>
                        <p class="field-help">本文大小門檻為 1 至 {{ number_format((int) config('monitoring.max_body_bytes', 1048576)) }} bytes。狀態碼欄位只需填額外接受的 400–599；預設接受 200–399。預期回應標頭格式為 <code>X-Robots-Tag: noindex</code>。</p>
                        <p class="field-help">自訂請求標頭每行一組 <code>名稱: 值</code>，最多 20 組，且網址需使用 HTTPS；預覽會遮蔽標頭值。重新導向主機可逐行或以逗號分隔。</p>
                        <p class="field-help">通知信箱最多 10 個，升級等待時間為 5–1440 分鐘；第二階段需晚於第一階段。Slack、Discord 與 Teams webhook 必須使用 HTTPS。留白時採用全域通知設定。</p>
                        <p class="field-help">異常確認時間為 30–300 秒。<code>enabled</code> 與 <code>public_status</code> 接受 1/0、true/false、yes/no 或 on/off；留白時分別預設啟用與不公開。所有資料列先預覽，敏感欄位值不會顯示。</p>
                    </div>
                </details>
            </div>
        </div>
    </section>

    <div class="heading-actions spaced-top">
        <a class="button button-primary" href="{{ route('monitors.index') }}">前往監測項目</a>
        <a class="button button-secondary" href="{{ route('status-pages.edit') }}">管理公開狀態頁</a>
    </div>
@endsection
