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
                <p class="panel-description">貼文監測讀取頁面公開互動數；活動報告可連結 GA4 API 查看導流、渠道、登陸頁、重要事件與營收，也可連結 Meta Marketing API 讀取已授權廣告帳戶的花費、曝光、觸及、點擊、行動數量與轉換價值，以及 Google Ads API 讀取客戶帳戶的花費、曝光、點擊、轉換總計、轉換價值與轉換動作；未連結時可手動記錄投放花費。只有廣告花費與 GA4 營收幣別相同時才計算 ROAS；此數值未扣除商品成本。</p>
                <p class="panel-description">支援網站、Facebook、Instagram、Threads 與 Dcard 公開網址；Dcard 先讀公開 HTML，互動數不完整且未遇限制時才以 Playwright Chromium 渲染。無需平台 API 金鑰或帳號授權；不保存完整頁面或截圖，遇到驗證時不會自動處理，預設延後 6 小時再檢查。取得監測同意後，可向 Dcard 提供背景工作出口 IP 申請放行。</p>
                <p class="panel-description">若平台只回傳登入頁或動態載入外殼，系統無法確認貼文內容。HTTP 檢查正常只代表收到回應，不代表貼文一定成功發布或仍對所有人可見。</p>
                <p class="panel-description">若原始回應含明確的觀看、讚、留言或分享數，會記錄每次檢查並呈現保存範圍內的趨勢與前次變化；前次為 0 時不計比例，缺值會標示無法比較。</p>
                <p class="panel-description">互動數擷取覆蓋率依近 7／30 天取得 HTTP 狀態碼的完成檢查計算，包含 4xx／5xx；完整讀取須符合該平台互動欄位口徑。</p>
                <p class="panel-description">儀表板貼文清單每頁 50 篇並可分頁；可依平台或活動標籤篩選貼文、互動排行與彙總，並匯出 CSV。支援比較 Facebook、Instagram、Threads 與 Dcard 的公開互動數、每篇平均互動及觀看互動率。Instagram 與 Dcard 以公開的讚與留言計；Facebook 與 Threads 需有讚、留言及分享完整數據。全部平台的互動排行與彙總只計 Facebook／Threads 的完整三項數據；Instagram／Dcard 請篩選平台，以讚＋留言比較。排行顯示計算口徑與資料篇數。貼文排行可依互動合計或觀看互動率排序；標籤排行可依平均互動或觀看互動率排序。無觀看數的貼文不計觀看互動率。首頁顯示近 7／30／90 天每日互動淨變化、前五篇貼文變化、互動成長率及同篇前期變化差；單篇排行可依淨變化或成長率排序，完整排行可匯出 CSV。期初互動量為零時不計成長率；前期比較只計兩期都有讀值的同項指標。單篇歷史可查看期初至期末變化並匯出 CSV。同篇多標籤會計入每個標籤。</p>
                <p class="panel-description">發文時間成效依台灣時間分為四個時段（00–05、06–11、12–17、18–23），並按星期與平台口徑計算平均互動及篇數；只有發布時間與互動數完整的貼文會納入。結果反映目前樣本，不代表發文時間造成成效差異。</p>
                <p class="panel-description">貼文年齡成效比較發文後 24 小時、7 天及 30 天的平均與中位數互動、觀看互動率；取目標時間前後 6 小時內最近一次完整讀值，沿用目前平台與標籤篩選並可匯出 CSV。</p>
                <p class="panel-description">活動互動目標可依標籤與平台設定期間，只計入期間內發布的貼文；未提供發布日期的貼文另列。進行中有完整讀值時，以尚差互動數除以剩餘活動日並進位。尚未開始則按活動日數分配目標；進行中資料不足、已達成或活動結束時不估算。目標、達成進度與每日所需互動可匯出 CSV。</p>
                <p class="panel-description">活動報告顯示 Meta Ads 的頻率、CTR、CPC、CPM、各行動類型數量與轉換價值，以及 Google Ads 的轉換動作、每次轉換成本與點擊轉換率。各平台行動數據依帳戶追蹤與歸因設定回傳，類型可能重疊，不合併計算。兩平台歷史趨勢可匯出 CSV；資料超過 24 小時未更新時會提示。跨平台花費僅同幣別合計；ROAS 以同幣別 GA4 營收計算且不扣商品成本。</p>
                <p class="panel-description">Meta Ads 活動報告另提供廣告層級曝光、觸及、點擊、CTR、花費、CPC、CPM 與 omni_purchase 數據；依花費排序最多列出 200 則並可匯出 CSV，明細加密保存 30 天。</p>
                <p class="panel-description">Meta Ads 另按 Facebook、Instagram 等平台與版位拆分曝光、觸及、點擊、CTR、花費、CPC、CPM 與 omni_purchase；可排序及匯出 CSV，明細加密保存 30 天。版位觸及可能重疊，不應加總；轉換依 Meta 歸因設定回傳。</p>
                <p class="panel-description">逐日成效表與 CSV 提供 CTR＝點擊 ÷ 曝光、CPC＝花費 ÷ 點擊、CPM＝花費 ÷ 曝光 × 1,000、CPA＝花費 ÷ 購買或轉換；Meta 頻率＝曝光 ÷ 觸及，Google Ads 轉換率＝轉換 ÷ 點擊。分母缺值或為零時留空。</p>
                <p class="panel-description">Google Ads 搜尋與購物活動提供每日搜尋曝光、預算流失及排名流失比重。平台將低於 10%／高於 90% 的數值標記為界線值，畫面顯示 &lt;10%／&gt;90%，CSV 保留原始標記。</p>
                <p class="panel-description">Google Ads 搜尋字詞報表僅支援搜尋活動，查看最近 30 個完整日，依花費排序最多列出 200 筆，可按花費、轉換、ROAS 或 CTR 排序並匯出 CSV。平台會隱去低量查詢；轉換依帳戶納入轉換指標的設定。字詞加密保存，最後同步 30 天後不再顯示，並由每日排程清除。報表僅標記有花費無轉換的字詞，不會自動變更關鍵字。</p>
                <p class="panel-description">Google Ads 投放單位報表查看最近 30 個完整日的花費、曝光、點擊、CTR、轉換、CPA、轉換價值與 ROAS，依花費排序最多列出 200 筆並可匯出 CSV。一般活動以廣告群組彙總，Performance Max 以資產群組彙總；轉換依帳戶納入轉換指標的設定。明細加密保存 30 天，並由每日排程清除。</p>
                <p class="panel-description">Google Ads 裝置報表比較最近 30 個完整日的電腦、手機、平板等曝光、點擊、花費、轉換與轉換價值，並計算 CTR、CPC、CPA 與 ROAS；可排序及匯出 CSV。未分類裝置會獨立顯示，明細加密保存 30 天並由每日排程清除。</p>
                <p class="panel-description">活動報告可設定總預算，對照 Meta Ads 與 Google Ads 實際花費，顯示使用率、活動期間進度與目前消耗速度推估。計算須有同幣別花費及活動日期；活動平均每日消耗僅供參考，實際花費仍以同步資料為準。</p>
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
                <p class="panel-description">公開互動數可匯出最新值或近 30 天歷史 CSV，含平台、標籤、互動數與資料時間；監測項目、通知紀錄、事件與可用率資料也可匯出，監測項目支援 CSV 批次匯入。</p>
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
