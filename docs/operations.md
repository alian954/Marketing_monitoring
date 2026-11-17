# 操作與維護手冊

## 環境與首次啟動

主要本機路徑是 Docker Compose + MySQL 8.4 + PHP 8.4。PHP 容器以 `php:8.4-cli` 建置，`docker compose build --pull` 會取得目前可用的 PHP 8.4 映像修補版；目前開發主機的 PHP 是 8.4.1，僅用於本機語法檢查與測試，不能代表最新 PHP 安全修補狀態。此工作階段的主機沒有可用的 Compose v2 外掛或 Docker daemon，因此映像建置與 Compose 啟動尚未在此主機驗證。

1. 複製範例並安裝 PHP 套件：

   ```sh
   cp .env.example .env
   composer install
   php artisan key:generate
   ```

2. 產生兩組不同的資料庫密碼，將十六進位輸出貼入 `.env` 的 `DB_PASSWORD` 和 `MYSQL_ROOT_PASSWORD`：

   ```sh
   openssl rand -hex 32
   openssl rand -hex 32
   ```

   請勿將正式機密放入版本控制。`.env`、資料庫備份與 `APP_KEY` 都應以受限權限保存。範例預設 `APP_DEBUG=false`、工作階段 cookie 加密、HttpOnly 與 SameSite=Lax；本機 HTTP 的 `SESSION_SECURE_COOKIE=false` 只適用於 loopback 測試。

3. 建置並啟動：

   ```sh
   docker compose config -q
   docker compose build --pull
   docker compose up -d
   docker compose ps
   ```

   `db` 只在 Compose 私有網路內提供 MySQL。`migrate` 必須成功結束後，`app`、`worker` 與 `scheduler` 才會啟動；網站僅綁定 `127.0.0.1:8000`。登入頁為 <http://127.0.0.1:8000/login>。

4. 建立唯一管理者帳號：

   ```sh
   docker compose exec app php artisan monitor:create-manager
   ```

   指令會以隱藏輸入讀取密碼，最低 12 個字元並要求確認。公開註冊已關閉。重設密碼：

   ```sh
   docker compose exec app php artisan monitor:create-manager --reset
   ```

## 日常操作

- 新增 URL 前，先確認你有權檢查該網站。正式式傳輸預設為 `MONITOR_TRANSPORT=secure`，只允許 HTTP 80 / HTTPS 443，對所有 A/AAAA 結果採保守公開 IP 規則，停用重新導向與代理的自動處理，並將檢查固定至已驗證 IP。重新導向需在該監測項目的允許清單中逐一指定主機。
- 排程器每分鐘派送到期監測，佇列工作者執行檢查和郵件 outbox。一般檢查間隔為 15 分鐘；第一次異常需跨過 60 秒確認，事件成立後，需再經兩次成功檢查確認恢復。
- Compose 預設只有一個佇列工作者，檢查會依佇列逐筆執行；目前沒有加入排程抖動或同網域專屬的併發限制。若自行增加 worker 數量，請先評估同網域同時檢查造成的負載。
- 排程器心跳會顯示在總覽頁。若心跳過期，先看 `scheduler` 容器狀態，再看其錯誤記錄：

  ```sh
  docker compose ps
  docker compose logs --tail=100 scheduler worker
  ```

- `MONITOR_TRANSPORT=fixture` 是完全離線的合成測試傳輸，只接受 `fixture.test` 與 `healthy.fixture.test`，可用 `/ok`、`/403`、`/404`、`/500`、`/429`、`/missing-text`、`/timeout`、`/redirect-private` 等固定路徑檢視狀態。啟用方式是修改 `.env` 後重建服務設定：

  ```sh
  # 將 .env 的 MONITOR_TRANSPORT 設為 fixture
  docker compose up -d --force-recreate app worker scheduler
  ```

  此模式只適用於本機示範或測試；`APP_ENV=production` 時會拒絕載入。

## 郵件與記錄

預設 `MAIL_MAILER=log`，不會寄送郵件。若已設定 `MONITOR_NOTIFY_EMAIL`，事件郵件內容會寫入應用程式記錄，可用以下方式檢視：

```sh
docker compose exec app tail -f storage/logs/laravel.log
docker compose logs --tail=100 worker
```

日誌可能包含郵件內容與監測狀態，請限制可讀取 `storage/logs` 的人員與備份範圍。切換 SMTP 前，請在 `.env` 設定 `MAIL_MAILER=smtp`、`MAIL_HOST`、`MAIL_PORT`、帳號密碼、`MAIL_SCHEME`、`MAIL_FROM_ADDRESS` 與 `MONITOR_NOTIFY_EMAIL`，再重啟 `app`、`worker`、`scheduler`。請先以測試信箱驗證實際收件與退信處理；此版本尚未完成外部 SMTP 驗收。

Outbox 為每個事件建立唯一的邏輯通知事件。郵件伺服器已收信、但程序在寫入成功狀態前中斷時，系統無法得知遠端結果，會標示為「結果不明」且不自動重寄。管理者可在檢查過收件匣後手動重試，但可能造成重複郵件；系統不宣稱實體郵件恰好寄送一次。

## 時區與保留

- 資料庫欄位與排程以 UTC 儲存及計算；資料庫服務固定使用 UTC。使用者介面以台灣時間（Asia/Taipei，UTC+8）顯示時間，CSV 欄位 `last_checked_at_utc` 仍是 ISO 8601 UTC。請勿在 DB 中手動加入已換算成台灣時間的值。
- HTTPS 檢查每次都嘗試讀取 TLS 憑證期限並以 14/7 天門檻判斷事件；`tls_observations` 每個監測項目每日只保留一列。尚無額外的每日 TLS-only 任務。若 cURL 在憑證驗證失敗時無法提供 `CURLINFO_CERTINFO` 到期欄位，工具會將其歸為一般 TLS 驗證/交握錯誤，不能可靠判定為已過期。每次完成檢查只保留安全政策驗證過的公開 DNS IP；DNS 變更日摘要與遭封鎖答案集的詳細保存尚未實作。
- robots.txt 完整解析與人工檢視入口尚未實作；不要把 User-Agent 模擬或目前的 HTTP 狀態解讀成 Google 的正式 AdsBot 判定。
- `monitors:prune` 每日清除超過 30 天的已完成檢查摘要；事件與事件記錄不會由這個命令刪除。此為第一階段預設，尚未針對大量資料、法規或完整歷史保留需求驗收。

## 備份

備份前建議暫停應用程式、工作者與排程器，避免備份期間資料仍變更。MySQL 使用 InnoDB，可用交易一致性匯出：

```sh
docker compose stop app worker scheduler
docker compose exec -T db sh -c 'exec mysqldump --single-transaction -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > monitoring.sql
docker compose start app worker scheduler
```

請另外以加密、受限存取的方式備份 `.env` 中的 `APP_KEY` 及資料庫密碼；不要將明文機密放在與 SQL 檔相同的公開或一般共享位置。`APP_KEY` 是解密監測 URL、檢查設定快照與預覽資料的必要金鑰，遺失後無法還原這些資料。備份完成後可計算雜湊並將檔案移至加密儲存：

```sh
shasum -a 256 monitoring.sql
```

## 還原

1. 先確認 `.env` 的原始 `APP_KEY` 和資料庫密碼仍可用，啟動資料庫並停止其他服務：

   ```sh
   docker compose up -d db
   docker compose stop app worker scheduler
   ```

2. 將資料庫還原至此應用程式專用資料庫：

   ```sh
   docker compose exec -T db sh -c 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < monitoring.sql
   ```

3. 若程式版本含有較新的遷移，再執行遷移並重新啟動：

   ```sh
   docker compose run --rm migrate
   docker compose up -d app worker scheduler
   docker compose ps
   ```

請在還原後確認能登入、URL 顯示已遮蔽、排程器心跳恢復，以及 outbox/佇列狀態符合備份時間點。不要用 `docker compose down -v` 作為一般停止方式；`-v` 會刪除 MySQL 與應用程式儲存卷中的資料。

## 測試與目前限制

本機測試依 `phpunit.xml` 使用記憶體 SQLite，不會連到正式網站，也不會連到 MySQL：

```sh
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run build
```

正式操作路徑以 Docker Compose/MySQL 為準，仍需在具備 Docker Compose v2 daemon 的主機實際建置、啟動、遷移、工作者、排程器與郵件記錄。其他未完成項目包含：外部 SMTP 投遞驗收、TLS 門檻與 AdsBot 模擬的正式目標驗收、CSV 大量匯入/匯出容量與保留政策、完整瀏覽器互動驗收，以及部署前審查。AdsBot 僅模擬 User-Agent 字串，不代表 Google 官方檢查結果。
