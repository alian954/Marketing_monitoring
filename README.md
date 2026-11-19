# 私人網站健康監測台

這是第一階段的本機版網站監測工具，採用 Laravel 13、PHP 8.4 與 MySQL 8.4。登入後可新增、暫停、手動檢查、匯入及匯出監測項目，並檢視檢查結果、事件與通知佇列。沒有公開註冊功能。

## 本機啟動

主要執行路徑使用 Docker Compose 與 MySQL。請先安裝 Docker Engine/Desktop、Docker Compose v2 與 Composer。

```sh
cp .env.example .env
composer install
php artisan key:generate
```

編輯 `.env`，將 `DB_PASSWORD` 與 `MYSQL_ROOT_PASSWORD` 換成不同的隨機密碼。接著建置並啟動服務：

```sh
docker compose build --pull
docker compose up -d
docker compose exec app php artisan monitor:create-manager
```

開啟 <http://127.0.0.1:8000/login>，以剛建立的管理者帳號登入。`app` 只綁定本機回環位址；資料庫沒有對主機公開連接埠。第一次啟動由 `migrate` 服務執行資料庫遷移，網站、佇列工作者和排程器會等待遷移成功才開始。

詳細的環境變數、日常操作、郵件記錄、備份與還原方式請看 [操作手冊](docs/operations.md)。

## 測試與離線範例

```sh
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
```

測試套件使用記憶體 SQLite；正式本機執行設定仍以 MySQL 為主。若要在登入後用預設樣本頁面體驗流程，先在 `.env` 設定 `MONITOR_TRANSPORT=fixture` 並重啟服務，只可新增 `fixture.test` 網址。此模式完全不連接網路，且應用程式在 `APP_ENV=production` 時會拒絕啟用。

## 目前範圍

- HTTPS/HTTP 檢查會逐次解析並檢查所有 A/AAAA 位址、封鎖私有與特殊用途 IP、拒絕未允許的跨網域重新導向，並將已驗證 IP 固定到 cURL 連線；自動重新導向與代理均停用。
- URL 與檢查設定快照使用 Laravel 加密儲存。頁面、CSV 匯出與郵件中的敏感查詢值會遮蔽。請保管 `APP_KEY`；更換金鑰會使既有加密資料無法解密。
- 連續檢查會確認事件；電子郵件以資料庫 outbox 管理。事件郵件有一筆邏輯通知記錄，但 SMTP 在遠端已收信而本機尚未記錄成功時，無法保證實體郵件恰好寄送一次；狀態會標示為結果不明，重試前請先查收件匣。
- Facebook、Instagram 與 Threads 公開貼文可用網址加預期文字監測。系統只讀取免登入的原始 HTTP 回應，不呼叫平台 API、不執行 JavaScript、不保存頁面或截圖；平台若把內容動態載入，找不到文字不代表貼文未發布。
- 排程每 03:00 執行獨立 HTTPS 憑證檢查、每 06:00 彙整前一個 `APP_TIMEZONE` 日期的 DNS 變更，並於 02:05 清除超過 30 天且已結束的檢查摘要。一般檢查的派送延遲最多 60 秒；HTTP 與 TLS 檢查對相同主機名稱共用併發鎖，不同子網域可分開執行。
- CSV 匯入支援預覽與確認，檔案上限 5 MB、最多 500 筆資料；匯出採串流。測試已覆蓋 500 筆邊界、超額列略過與 30 天保留邏輯，正式 MySQL 大量資料負載仍待驗收。DNS 只保存安全政策驗證通過的公開解析 IP，不保存完整答案集；AdsBot 是 User-Agent 字串模擬，不是 Google 官方擷取。
- 尚未完成外部 SMTP 實際投遞、正式目標的 TLS/AdsBot 驗收、完整瀏覽器使用者驗收，以及 Docker Compose 建置與啟動驗證。HTTP 檢查會嘗試讀取 TLS 到期資訊；獨立 TLS 檢查可在憑證鏈驗證失敗時讀取到期時間，但若 TLS 交握失敗則無法取得憑證。預設 Compose 僅啟動一個佇列工作者。

此版本尚未部署；請勿將其直接對外公開。操作與已知限制均列於 [操作手冊](docs/operations.md)。
