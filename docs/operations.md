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

## 客戶開通、人工收款與續約

平台目前沒有收款、發票、付款紀錄或自動續約功能。收款與憑證需在既有帳務流程中完成並留存；確認款項後，再由平台管理員更新組織狀態。平台頁只管理方案名稱、資源上限、席次、到期日與啟用狀態。

1. 平台管理員登入後開啟「總管理平台」。在「新增組織」建立組織與負責人帳號，填入方案名稱、到期日、席次及客戶／監測／活動上限。試用帳戶應設定明確的試用截止日與上限；欄位留空代表不設限制。新組織建立後預設啟用。
2. 設定至少 12 個字元的初始密碼，透過安全管道交付負責人；建議負責人首次登入後，到「帳戶密碼」頁更換密碼。系統不會自動寄出開通信或收款通知。
3. 收到並核對款項後，在組織列表更新方案名稱、到期日與配額，並確認狀態為「啟用」。將收款日期、金額、憑證編號、服務期間及組織負責人記在外部帳務系統，平台不會保存這些付款資料。
4. 續約時先完成外部收款核對，再延長到期日並確認組織已啟用。若要立即暫停服務，將狀態設為「停用」；到期日當天結束後也會自動失去存取權。停用或到期會阻止登入、結束既有登入工作階段，並略過該組織尚未執行的監測工作。
5. 重新開通時，同時確認狀態為「啟用」且到期日仍有效；只切換啟用狀態無法恢復已到期的訂閱。降級或降低上限不會刪除既有資料，但超出新上限後會阻止新增資源，直到使用量降至上限以下或管理員調高上限。

席次會計入負責人、有效成員與尚未接受且未過期的邀請；客戶上限不計「未分類」預設客戶。平台頁會顯示目前方案用量與狀態，可用它確認開通和續約設定是否生效。此流程由管理員人工核款與維護；尚未實際串接收款服務或外部帳務系統。

## 日常操作

- 新增 URL 前，先確認你有權檢查該網站。正式傳輸預設為 `MONITOR_TRANSPORT=secure`，只允許 HTTP 80 / HTTPS 443，對所有 A/AAAA 結果採保守公開 IP 規則，停用重新導向與代理的自動處理，並將檢查固定至已驗證 IP。重新導向需在該監測項目的允許清單中逐一指定主機。
- 排程器每分鐘派送到期監測，佇列工作者執行檢查和郵件 outbox。一般檢查間隔預設 15 分鐘，派送最多加入 60 秒抖動（不超過檢查間隔的四分之一）；第一次異常需跨過 60 秒確認，事件成立後，需再經兩次成功檢查確認恢復。
- Compose 預設只有一個佇列工作者。若增加 worker，HTTP 檢查與每日 TLS 檢查仍會以同一把快取鎖序列化相同正規化主機名稱的請求；不同子網域使用不同鎖。鎖衝突的工作延遲 30 秒重試，鎖在 60 秒後到期；所有 worker 必須共用支援原子鎖的快取，預設資料庫快取符合此條件。
- 每日任務依 `APP_TIMEZONE` 執行：03:00 派送 HTTPS 憑證檢查，06:00 建立前一個應用程式時區日期的 DNS 摘要，02:05 清除保留期限外的已結束檢查。DNS 摘要只在前一日有變更且已設定 `MONITOR_NOTIFY_EMAIL` 時建立郵件；摘要事件時間以 UTC 顯示。
- 總覽會分開顯示排程器與 `Queue worker` 心跳。Worker 區塊也會列出佇列總數、可立即處理／延遲／保留中的工作，以及最近成功處理時間；超過 2 分鐘沒有 worker 心跳時，請先檢查 `worker` 容器與記錄：

  ```sh
  docker compose ps worker
  docker compose logs --tail=100 worker
  ```

- 若總覽中的排程器心跳過期，檢查 `scheduler` 容器和最近記錄；再用 `schedule:list` 確認目前映像內已註冊的任務與時區。`schedule:list` 只顯示排程設定，不代表排程器正在執行；仍要確認容器狀態與心跳：

  ```sh
  docker compose ps scheduler
  docker compose logs --tail=100 scheduler
  docker compose exec app php artisan schedule:list
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

- `.env.example` 預設 `APP_TIMEZONE=UTC`，資料庫時間以 UTC 儲存；每日排程依 `APP_TIMEZONE` 執行（預設即 UTC）。若調整此值，DNS 摘要的日期分界及排程時間也會跟著改變。使用者介面以台灣時間（Asia/Taipei，UTC+8）顯示時間，CSV 欄位 `last_checked_at_utc` 仍是 ISO 8601 UTC。請勿在 DB 中手動加入已換算成台灣時間的值。
- HTTPS 一般檢查會嘗試讀取 TLS 憑證期限；獨立 TLS-only 任務每天檢查一次，使用已驗證的公開 IP 與主機 SNI 取得憑證期限，不發送 HTTP 請求。HTTP 憑證鏈由一般 HTTPS 檢查驗證；獨立檢查若 TLS 交握失敗則無法取得憑證期限。`tls_observations` 每個監測項目每日只保留一列；事件在憑證到期前 30、14、7 天分級通知。
- DNS 變更由成功的排程檢查比較前後已驗證的公開主機 IP，06:00 摘要前一個 `APP_TIMEZONE` 日期。系統不保存遭封鎖的答案集，也不聲稱追蹤完整 DNS 記錄。
- 監測詳情頁提供 robots.txt 根路徑人工開啟入口；應用程式不會抓取或解析其內容。AdsBot 僅模擬 User-Agent 字串，不代表 Google 官方擷取。
- CSV 匯入先預覽再確認，最多 500 筆資料列且上傳檔案不得超過 5 MB；超額資料列會顯示錯誤並略過。CSV 匯出以串流方式輸出。功能測試覆蓋 500 筆匯入/匯出邊界，正式 MySQL 大量資料負載尚未驗收。
- `monitors:prune` 每日 02:05 清除超過 `MONITOR_CHECK_RUN_RETENTION_DAYS`（預設 30 天）且已有 `finished_at` 的檢查摘要，包含成功、失敗等已結束結果；在 `.env` 調整此值可延長或縮短歷史保留時間，最少為 1 天。仍在佇列或執行中的記錄會保留。事件與事件記錄會保留，刪除檢查摘要時事件的 `check_run_id` 會清空。大量資料、法規或完整歷史保留需求尚未驗收。

## 備份

備份前暫停應用程式、工作者與排程器，避免備份期間資料仍變更。命令會先寫入同目錄的暫存檔，成功後才取代 `monitoring.sql`；若匯出失敗，會刪除暫存檔並嘗試重新啟動服務。`--single-transaction` 讓 InnoDB 資料以一致交易匯出；`--no-tablespaces` 可避免備份帳號需要全域 `PROCESS` 權限。密碼透過權限受限的臨時 MySQL option file 提供，不放在 mysqldump 命令列參數中。帳密須為單行值；範例會跳脫反斜線和雙引號，並以雙引號包覆值。

```sh
set -e
temporary_backup=$(mktemp monitoring.sql.XXXXXX)
restart_services() {
  rm -f "$temporary_backup"
  docker compose start app worker scheduler
}
trap restart_services EXIT

docker compose stop app worker scheduler
docker compose exec -T db sh -c '
set -eu
umask 077
credentials=$(mktemp)
trap "rm -f $credentials" EXIT
escape_option_value() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}
escaped_user=$(escape_option_value "$MYSQL_USER")
escaped_password=$(escape_option_value "$MYSQL_PASSWORD")
printf '[client]\nuser="%s"\npassword="%s"\n' "$escaped_user" "$escaped_password" > "$credentials"
mysqldump --defaults-extra-file="$credentials" --single-transaction --no-tablespaces --default-character-set=utf8mb4 "$MYSQL_DATABASE"
' > "$temporary_backup"
mv "$temporary_backup" monitoring.sql
shasum -a 256 monitoring.sql
docker compose start app worker scheduler
trap - EXIT
```

請另外以加密、受限存取的方式備份 `.env` 中的 `APP_KEY` 及資料庫密碼；不要將明文機密放在與 SQL 檔相同的公開或一般共享位置。`APP_KEY` 是解密監測 URL、檢查設定快照與預覽資料的必要金鑰，遺失後無法還原這些資料。備份完成後可計算雜湊並將檔案移至加密儲存：

```sh
shasum -a 256 monitoring.sql
```

## 還原

還原會覆蓋備份中同名資料表的內容。先確認 `.env` 指向正確的專用資料庫，並備份目前目標資料庫；不要直接匯入共用或非本應用程式的資料庫。

1. 先確認 `.env` 的原始 `APP_KEY` 和資料庫密碼仍可用，啟動資料庫並停止其他服務：

   ```sh
   docker compose up -d db
   docker compose stop app worker scheduler
   ```

2. 將資料庫還原至此應用程式專用資料庫：

   ```sh
   docker compose exec -T db sh -c '
   set -eu
   umask 077
   credentials=$(mktemp)
   trap "rm -f $credentials" EXIT
   escape_option_value() {
     printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
   }
   escaped_user=$(escape_option_value "$MYSQL_USER")
   escaped_password=$(escape_option_value "$MYSQL_PASSWORD")
   printf '[client]\nuser="%s"\npassword="%s"\n' "$escaped_user" "$escaped_password" > "$credentials"
   mysql --defaults-extra-file="$credentials" "$MYSQL_DATABASE"
   ' < monitoring.sql
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

正式操作路徑以 Docker Compose/MySQL 為準，仍需在具備 Docker Compose v2 daemon 的主機實際建置、啟動、遷移、工作者、排程器與郵件記錄。MySQL 8.4 的備份與還原命令尚未實際演練；本機 SQLite 測試不能替代此驗收。CSV 500 筆上限、串流匯出、30 天保留行為與排程功能已有 SQLite 功能測試；正式 MySQL 負載仍未驗收。其他未完成項目包含：外部 SMTP 投遞驗收、TLS 到期門檻與 AdsBot 模擬的正式目標驗收、完整瀏覽器互動驗收，以及部署前審查。AdsBot 僅模擬 User-Agent 字串，不代表 Google 官方檢查結果。
