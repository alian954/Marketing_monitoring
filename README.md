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
- 這一版尚未完成 TLS/使用者代理檢查規則的正式環境驗收、每日獨立 TLS 檢查、DNS 變更日摘要、robots.txt 人工檢視入口、排程抖動、同網域併發限制、CSV 大檔容量與 30 天保留政策驗收、SMTP 實際投遞、瀏覽器端完整使用者驗收，以及 Docker Compose 執行環境驗證。目前 HTTPS 探測會在每次 HTTP 檢查時讀取 TLS 憑證資料；若 cURL 在憑證驗證失敗時沒有提供到期欄位，就無法將該次失敗與一般 TLS 交握/驗證錯誤區分。只保存已驗證的公開解析 IP，不保存完整 DNS 答案集。預設 Compose 僅啟動一個佇列工作者。AdsBot 是 User-Agent 字串模擬，不是 Google 官方擷取。

此版本尚未部署；請勿將其直接對外公開。操作與已知限制均列於 [操作手冊](docs/operations.md)。
