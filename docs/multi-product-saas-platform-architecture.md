# 多產品 SaaS 中央管理平台：現況盤點與目標架構

**狀態：架構方向已確認，採漸進式實作。** 第一版以 Organization 同時代表公司、租戶及 Workspace，不另建重複的公司層；`Client` 仍是工作區下的外部客戶。保留模組化單體，已建立 workspace identity mapping，並將 `clients`、監測根表、通知 outbox、四種 OAuth 連線及 per-client property/site/ad account 對應加入 workspace scope。`user_id` 暫時保留作相容及未完成資料回填時的 fallback；其他產品關聯資料仍待遷移。

## 本階段範圍調整（2026-11-27）

本階段優先完成中央平台的產品登錄、產品生命週期、功能目錄、產品入口對應及平台治理。既有方案、訂閱管理程式與資料表先封存保留，訂閱開通、訂單、收款、金流及付費權益執行暫緩；它們不作為目前平台功能可用性的依據，也不在本階段擴充。產品與功能路由只依程式登錄、同步後的目錄及平台管理員設定的產品狀態治理。

**後續調整（2026-11-27）**：依平台商業化目標，人工帳務（訂單、訂單項目、人工收款紀錄）已重啟並完成第一版，金額一律取自可銷售方案、收款只能新增並寫入稽核。付費權益可由平台管理員逐一組織啟用，未啟用的既有組織維持原行為；金流 gateway、退款與發票仍待商業流程確認。

## 1. 決策摘要

目標是「中央控制平台 + 多個有清楚責任邊界的產品」，不是把所有產品業務塞進中央後台，也不要求立即拆成微服務。

建議先維持一個 Laravel 部署，逐步整理成模組化單體：中央層負責身分、組織、產品目錄、方案權益、帳務、稽核及平台健康；產品層保留各自的業務規則、資料、報表和工作。產品之間只能經由明確的共用服務、契約或事件交換資料，不直接讀取彼此的 Eloquent 模型。只有當部署、擴充或故障隔離需求已實際出現，才將個別產品獨立部署。

現有功能初步分成兩個有商業化可能的產品：

1. **網站可靠度監測**：網站可用性、TLS、DNS、網域到期、robots、事件、通知及公開狀態頁。
2. **行銷成效分析**：社群貼文公開互動、GA4、Google Search Console、Meta Ads、Google Ads、活動歸因、成效報表及客戶報告分享。

代理商工作區、成員、客戶主檔、登入、訂閱權益及付款應由中央共用能力提供。跨產品報告分享可共用權限、到期、撤銷與匯出機制，但各產品仍負責提供自己的報表資料。社群貼文追蹤和廣告平台連結目前先留在行銷成效產品內，不依現有選單或資料表數量硬拆成更多產品。

## 2. 目前架構盤點

### 2.1 執行形態

- Laravel 13.42.0；專案執行環境目標為 PHP 8.4。前端是 Blade/Vite。
- `routes/web.php` 是主要應用路由；`routes/console.php` 宣告排程。專案沒有 `routes/api.php`，目前沒有供第一方子平台使用的版本化 API。
- `compose.yaml` 定義一個 Laravel app、一個 MySQL 8.4、一個共用資料庫佇列 worker、一個 scheduler，以及一次性 migration 服務。網站監測與行銷同步共用同一個應用程式、資料庫及 worker 執行環境。
- 測試使用記憶體 SQLite。`php artisan migrate:status` 顯示目前本機資料庫的遷移皆已執行；這不等於 MySQL 8.4 或 Compose 部署驗收。
- 本機 Compose YAML 可解析，但盤點環境沒有 Docker CLI/daemon，因此映像建置、容器啟動及 MySQL 8.4 執行結果尚未驗證。

```mermaid
flowchart TB
    U[使用者瀏覽器] --> W[Laravel Web routes / Blade / session auth]
    W --> P[平台管理：Organization / quotas / enabled]
    W --> T[工作區：owner + WorkspaceMembership + Client]
    T --> M[網站監測：Monitor / CheckRun / Incident / StatusPage]
    T --> A[行銷分析：SocialEngagementTarget / GA4 / GSC / Meta / Google Ads]
    M --> DB[(單一資料庫)]
    A --> DB
    M --> Q[(Database queue)]
    A --> DB
    S[共用 Scheduler] --> Q
    Q --> J[共用 Queue worker]
    J --> M
    J --> A
    M --> N[Notification outbox / Email / Webhooks]
```

### 2.2 身分、租戶與權限

- `users` 是本機 email/password 帳號；登入、登出及密碼重設由 Laravel Web session 處理。目前沒有 OIDC 身分提供者、跨產品 SSO 或標準 access token。
- `is_platform_admin` 是使用者上的全域布林旗標。總管理介面可選擇組織進入該組織的工作區上下文。
- `organizations.owner_user_id` 唯一連到一個 owner 使用者。已新增一對一 `workspaces.organization_id` 身分映射和穩定 `public_id`；同一使用者可加入多個組織的工作區。
- `workspace_memberships` 以 `(owner_user_id, member_user_id)` 唯一，一個帳戶可在多個工作區各有一筆會員關係；被撤銷後重新受邀會沿用同一筆紀錄。角色為 `editor` 或 `read_only`，客戶範圍依各會員關係設定。
- 目前工作區存在 session（`current_workspace_owner_id`），由頁首切換器變更；選擇只在該帳戶仍有效的會員關係或自有組織中生效，否則依序回退至自有組織、最早加入的工作區，並優先選擇未停用的組織。已有帳戶的受邀者須以受邀 email 登入後接受邀請；平台管理員不能加入組織。
- `Client` 是代理商的外部客戶/品牌，並非 SaaS 租戶。它保留 `user_id` 相容欄位，並以 `workspace_id` 作主要 scope；既有資料透過 organization owner 對應 workspace。
- 共用客戶目錄、`monitors`、`social_engagement_targets`、`status_pages`、`notification_outbox`、GA4/GSC/Meta/Google Ads 連線及 per-client property/site/ad account 對應已改用 workspace scope。根連線仍保留 `user_id` 作相容 fallback；對應表以 workspace-scoped composite foreign keys 確保連線與客戶屬於同一工作區。其他監測子記錄透過根資料及關聯隔離。

### 2.3 資料表和產品責任

現有資料集中在同一資料庫，主要資料群如下：

| 資料群 | 代表資料表 / 模型 | 目前歸屬方式 | 目標責任 |
| --- | --- | --- | --- |
| 身分與工作區 | `users`, `organizations`, `workspaces`, `workspace_memberships`, `workspace_invitations`, `clients` | Organization/Workspace 一對一；Client 同時帶 `workspace_id` 及 legacy `user_id` | 中央平台：全域身分、組織、工作區、成員及客戶主檔 |
| 網站可靠度 | `monitors`, `check_runs`, `incidents`, `incident_events`, `maintenance_windows`, `tls_observations`, `dns_change_events`, `robots_observations`, `status_pages` | `monitors`、`status_pages` 已帶 `workspace_id`；子記錄仍以 monitor/status page 關聯及既有 `user_id`、`client_id` 欄位為主 | 網站可靠度產品擁有業務資料；通知投遞底層可共用 |
| 行銷活動與公開貼文 | `social_engagement_targets`, `check_runs` 社群監測欄位、貼文及活動報表/快照 | `social_engagement_targets` 已帶 `workspace_id`；其他紀錄仍以 `user_id`、`client_id` 及關聯為主 | 行銷成效產品；不依 Facebook、Instagram、Threads 拆成獨立產品 |
| 外部分析與廣告連線 | `google_analytics_connections`, `google_search_console_connections`, `meta_marketing_connections`, `google_ads_connections` 及 per-client property/account 對應 | 連線與對應表均帶 `workspace_id`；對應表以 composite foreign keys 連到同一工作區的 Client 和 provider connection | 行銷產品的連接器模組；憑證設定服務可共用 |
| 報表與分享 | `client_report_shares`, `client_report_snapshots`, `client_report_templates`, `client_exchange_rates` | 分享連到 client，快照連到分享 | 分享連結、撤銷、期限、匯出可共用；報表資料由各產品提供 |
| 通知與背景工作 | `notification_outbox`, `jobs`, `failed_jobs`, `scheduler_heartbeats` | `notification_outbox` 已帶 `workspace_id`，並保留 incident/monitor 關聯；佇列共用 | 通知、outbox、健康度是共用執行服務；事件來源及處理規則由產品擁有 |

### 2.4 整合憑證現況

目前有兩種不同憑證，不應混為一談：

- 每個工作區 owner 透過平台 OAuth 流程授權自己的 Google/Meta 帳戶。refresh/access token 存在各連線資料表，模型使用 Laravel `encrypted` cast；Google Ads/Meta 模型也隱藏 token 序列化欄位。
- 四種 connection model 的 access/refresh token 均使用 encrypted cast，並由模型序列化隱藏；測試會確認回傳陣列不包含 token。
- GA4、Search Console、Meta Ads、Google Ads 的 OAuth App `client_id` 與 `client_secret` 已可由工作區 owner 在整合頁設定；資料存在 `integration_application_credentials`，secret 使用 encrypted cast 和 `TEXT` 欄位，模型序列化時隱藏。表單不回顯 secret，空白更新會保留原密鑰。
- `integration_application_credential_events` 記錄工作區、操作者、provider 與建立/更新/刪除動作，不保存憑證值。callback URI 仍由平台設定並顯示給管理者，須在 provider app 登記。
- 為不中斷既有租戶，若工作區尚未填入憑證，OAuth resolver 暫時回退至 `config/services.php` 的舊伺服器設定。完成既有連線遷移後，應移除這項 fallback。更換 app 憑證可能需要重新授權；授權後的使用者 token 是獨立資料，不會因憑證表單更新而直接刪除。

工作區 owner 路由限制憑證管理權限，保存/刪除端點另有節流。`APP_KEY` 仍是站台層級加密根金鑰，不能改由每個整合帳號填入；APP_KEY 輪替、正式金鑰管理服務與稽核保留政策尚需規劃。

### 2.5 路由、API、排程與測試證據

- 平台管理、會員邀請、客戶、整合設定、監測、報表和匯出目前都是同一組登入後 Web routes；產品功能路由已加入 manifest 對應，產品維護/停售狀態會由共用 middleware 套用，公開狀態頁、狀態頁訂閱及公開報表分享連結也納入同一檢查；manifest 的 `scheduled_commands` 宣告各產品的排程指令，產品停售時停止派送，維護中仍繼續執行以免監測與資料同步中斷，資料清理等中央指令不受產品狀態影響。測試確保每條產品路由都套用此 middleware、每個排程指令都歸屬某產品或列為中央指令；不同路徑另由 `EnsurePlatformAdmin`、`EnsureWorkspaceOwner`、`RejectReadOnlyWorkspaceMemberWrites` 及相關 scopes 控制。租戶級付費權益 enforcement 暫緩。
- GA4、Meta Ads、Google Ads、Google Search Console 是呼叫外部 provider 的連接器，不是內部產品 API。OAuth App 憑證管理路由受 workspace owner 權限及節流保護。`routes` 目前只有 Web 與 Console 路由檔。
- 排程器每分鐘派送網站監測與 worker heartbeat，另排每日 TLS、網域、robots、DNS 摘要及行銷資料同步。所有工作目前共用同一資料庫佇列，產品間沒有 worker/queue 邊界。
- `AgencyWorkflowAcceptanceTest` 建立兩個組織、不同成員角色、多個客戶，並檢查 GA4 帳號對應、報表、CSV 匯出及分享內容隔離。它以 HTTP fake 驗證應用程式流程，不是 Google 實際 OAuth/API 驗收。
- `IntegrationApplicationCredentialTest` 驗證工作區隔離、encrypted cast、secret 不寫入驗證 session、不進稽核紀錄，以及四個 provider 皆使用該工作區憑證交換授權碼。這些測試以 HTTP fake 驗證應用流程，不代表 provider production OAuth/API 的實際驗收。
- `AccountDataIsolationTest`、`ClientReportShareTest`、`ClientReportSnapshotControllerTest`、`CommercialUsageLimitTest`、`PlatformOrganizationTest` 等測試涵蓋資料隔離、權限、匯出、分享與配額；整合 provider 仍須以各 provider 的正式 OAuth app 另行驗證。

## 3. 功能分類

### 3.1 應由中央平台管理的共用核心

| 能力 | 現況 | 中央平台目標責任 |
| --- | --- | --- |
| 全域身分 | 本機 `users`、session login；無 SSO | 全域唯一 subject、登入、SSO、帳號生命週期、MFA/安全政策 |
| 公司、組織、租戶、工作區 | `Organization` 綁單一 owner，Workspace 一對一映射；帳戶可加入多個工作區並以 session 切換 | 公司/帳務主體、租戶、工作區及多對多會員關係明確化 |
| 全域角色 | `is_platform_admin`；工作區 `editor/read_only` | Super Admin、組織管理角色、產品管理角色與客戶範圍分離 |
| 產品註冊及開通 | 已有 manifest registry、資料庫 catalog、`products:sync`、產品生命週期管理及功能到路由對應；租戶級付費開通暫緩 | 中央 catalog、產品狀態及產品功能入口治理 |
| 訂閱/方案/權益 | 已有方案、訂閱管理程式和 server-side entitlement resolver，但 UI 已封存且不接入產品入口；租戶級付費授權暫緩 | 商業模式確認後再規劃方案、功能額度、試用/付款/到期/取消 |
| 訂單、付款、帳務 | 已有中央訂單、訂單項目、人工收款及退款 ledger（跨產品、依方案價格、收款與退款只能新增）；尚無發票或金流 gateway | 訂單項目、人工收款紀錄、退款/調整、對帳及財務匯出 |
| 稽核與營運 | 已有只能新增的 `platform_audit_events`，涵蓋組織建立/設定/平台管理員進入、產品狀態、方案、封存訂閱及成員邀請/權限/撤銷，平台管理員可依操作、組織及日期查詢，組織 owner 可在「組織成員 › 操作紀錄」檢視自己工作區的紀錄（平台人員只顯示為「平台管理員」）；scheduler/worker heartbeat 另行記錄 | 管理操作稽核、全域通知、產品健康、跨產品使用量/成本彙總 |
| 客戶主檔 | `clients` 是 workspace 下的代理商客戶，已有使用中 | 中央共用 CRM-lite 客戶主檔；保留產品特有映射在產品模組 |

### 3.2 可獨立商業化的子平台

1. **網站可靠度監測產品**：監測目標、HTTP/TLS/DNS/robots/網域資訊、事件與復原、維護時段、通知規則、網站公開狀態頁。
2. **行銷成效分析產品**：公開貼文互動監測、社群平台來源、GA4/GSC/Meta Ads/Google Ads 連線、活動指標、歸因、預算/ROAS 分析、產品報表。

兩者可以共用帳號、租戶、客戶、訂閱、事件投遞、匯出和報表分享能力；不需因為目前放在同一個 app 就融合成一個產品，也不需為每個資料來源建立獨立產品。

### 3.3 保留在特定產品的內部模組

- 網站產品：HTTP 與內容檢查、TLS、DNS 變更、domain expiry、robots、SLO/incident、maintenance、status page。
- 行銷產品：公開社群貼文擷取、GA4 渠道/事件/商品/landing page、Google Ads 活動/素材/裝置/競價、Meta Ads ad set/placement/demographics、貨幣與歸因分析。
- 報表頁內容由產品模組產生；共用報表服務只管理選取範圍、品牌、期限、分享 token、快照完整性及下載授權。

### 3.4 可由多個子平台共用的基礎服務

全域身分、組織/工作區、client 主檔、RBAC/授權、產品目錄、entitlement 查詢、工作區 credential vault 與不含秘密值的憑證異動紀錄、通知 outbox、佇列與排程管理、稽核、健康探測、檔案匯出/分享骨架及 API gateway/client registry。

### 3.5 尚未實作但目標架構必須具備

- 多組織/多工作區的正式租戶模型；使用者可加入多組織，且可在各產品有不同角色。
- 具產品識別碼、狀態和部署版本的產品目錄；產品和產品專屬 entitlement/plan 註冊契約。
- OIDC SSO issuer/client 流程；產品 audience 與 scope 的跨平台授權。
- 稽核紀錄的保留期限與匯出；OAuth 憑證異動目前仍記在獨立的 `integration_application_credential_events`，待確認是否併入中央稽核。
- OAuth secret 的正式 key management / APP_KEY 輪替演練與保留政策；待舊 `.env` fallback 移除後，再統一以工作區設定為準。
- 客戶訂閱、方案/feature entitlement、用量 ledger 和跨產品 bundle（已列入後續階段，非本階段工作）。
- 訂單、人工付款紀錄、交易/退款/發票狀態及商業統計（MRR、ARR、續訂、流失）。
- 跨產品 API 使用量、資源消耗/成本歸屬；產品專屬 queue、worker、health 與錯誤隔離。
- 產品模組契約、版本相容性、事件 outbox/inbox 及未來獨立部署通訊介面。

## 4. 建議目標架構

```mermaid
flowchart LR
    B[使用者] --> IDP[中央身分與 SSO]
    IDP --> CP[中央管理平台 / Control Plane]
    CP --> ORG[公司 / 組織 / 工作區 / 成員]
    CP --> CAT[產品目錄 / 方案 / 權益 / 訂閱]
    CP --> BILL[訂單 / 付款 / 帳務]
    CP --> OPS[稽核 / 使用量 / 健康 / 通知]
    B --> WP[網站可靠度產品入口]
    B --> MP[行銷成效產品入口]
    IDP -. OIDC session/token .-> WP
    IDP -. OIDC session/token .-> MP
    CP -. entitlement / product APIs .-> WP
    CP -. entitlement / product APIs .-> MP
    WP --> WD[(網站產品資料邊界)]
    MP --> MD[(行銷產品資料邊界)]
    WP --> WO[網站專用 queue / worker]
    MP --> MO[行銷專用 queue / worker]
    WP -. 版本化 API / outbox events .-> CP
    MP -. 版本化 API / outbox events .-> CP
```

**過渡期部署方式**：Control Plane、網站產品及行銷產品都仍可在一個 Laravel repository、同一個 MySQL 服務內，以有明確命名空間和服務介面的模組化單體執行。產品的資料表、路由、權限、排程、工作及報表 provider 要有可測試的邊界，但不先引進微服務網路、服務發現或多套資料庫維運成本。

**獨立部署門檻**：只有在需要不同擴縮、版本發布、法規/資料落地區域，或某產品故障確實需與其他產品隔離時，才將該產品拆成獨立 deployable。拆分前必須已有穩定 API/event contract、產品專屬資料 ownership、獨立健康檢查、worker 和回復流程。

## 5. 資料所有權與租戶設計

### 5.1 名詞定義

| 名詞 | 定義 | 所有者 |
| --- | --- | --- |
| User / Identity | 全平台登入主體；以不可變 UUID/subject 識別，不以 email 當跨租戶主鍵 | 中央身分平台 |
| Company / Billing Account | 付款及合約主體，可持有多個組織/工作區 | 中央平台 |
| Organization / Tenant | 權限、資料隔離與產品訂閱的上層客戶邊界 | 中央平台 |
| Workspace | 成員共同作業的資料範圍；可對應現有代理商組織，也可由一個 organization 管理多個 workspace | 中央平台 |
| Client | 代理商服務的外部客戶/品牌，不是登入租戶 | 共用 client directory，隸屬 workspace |
| Product | 可獨立授權及發版的產品識別，不等同於客戶或工作區 | 中央產品目錄 |
| Product Data | 某產品專屬的監測目標、活動、資料來源、報表和歷程 | 對應子平台 |

### 5.2 建議的邏輯資料模型

```text
users (global identity; immutable subject_id)
companies / billing_accounts
organizations (company_id)
workspaces (organization_id)
organization_memberships (user_id, organization_id, global_role)
workspace_memberships (user_id, workspace_id, workspace_role)
workspace_client_memberships (workspace_membership_id, client_id)
clients (workspace_id, external customer profile)

products (product_code, name, version, manifest_version, lifecycle, entry_route)
product_features (product_id, feature_key)
product_plans (product_id, plan_code, version, status, billing_interval, amount_minor, currency_code)
plan_entitlements (product_plan_id, product_id, feature_key, is_enabled, limit_value)
subscriptions (organization_id, status, starts_at, current_period, trial/cancel state, provider reference)
subscription_items (organization_id, subscription_id, product_id, product_plan_id, status, quantity, period)
product_usages (product_id, workspace_id, feature_key, period, amount)

orders / order_items / payment_transactions / invoices / manual_payment_records
integration_app_credentials (workspace_id, product_id, provider, encrypted client secret)
integration_connections (workspace_id, provider, encrypted account tokens, status)
product roots and independently addressable tables (workspace_id, client_id, product-owned foreign keys)
dependent history rows (parent foreign key; optional workspace_id for multi-parent links)
platform_audit_logs / outbox_events / inbox_events
```

這是目標邏輯模型，不是一次完成的 schema 重寫。已採用第一版簡化決策：`Organization` 同時代表公司、租戶及工作區，不先建立獨立 company/billing-account 層。新增 `Workspace` identity 映射與穩定公開 UUID，一個 workspace 對應一個 Organization；產品資料仍先沿用既有 `user_id` scope，分批加入 canonical workspace 關聯並以資料對帳驗證。若未來同一公司需多工作區或獨立帳務主體，再以 additive migration 拆出層級。

### 5.3 隔離規則

- 中央資料由中央服務擁有；產品資料只能由該產品模組讀寫。通用 client ID 只能指向同 workspace 的 client。
- 產品根資料及可獨立尋址的資源含 `workspace_id`；純歷史/子記錄可由一個不可變 parent foreign key 繼承租戶範圍，並透過 parent 的 workspace scope 查詢，避免重複存放容易不一致的 workspace 欄位。若一筆資料直接連結多個租戶資源，應加 `workspace_id` 與 composite foreign key/unique key 確認所有 parent 同屬一個 workspace。客戶範圍資料另含 `client_id`。
- API 的 tenant/product scope 來自已驗證身份及中央授權結果，不信任瀏覽器或 caller 任意傳入的 tenant ID。
- 報表分享固定 snapshot 應帶 workspace/client/share 範圍，分享 token 使用雜湊存放；公開分享只提供建立時指定的產品區段、客戶及期間。
- 產品間讀取採「報表 provider/contract」回傳 DTO；禁止在 Marketing controller 直接 join Website product 的內部表，反之亦然。
- 敏感 OAuth client secret 和 provider token 使用獨立 encrypted 欄位、序列化隱藏、最小授權與稽核；外部產品未連線時不建立空白/假權杖。

## 6. 統一登入與跨產品授權流程

建議以 OpenID Connect Code Flow 作為第一方產品 SSO，中央身分層負責登入；產品依自己的 client ID、redirect URI、audience 和授權 scope 驗證登入結果。瀏覽器端使用 state、nonce 與 PKCE `S256`；服務端驗證 issuer、signature、audience、nonce、有效期間和單次 authorization code。互動式 OAuth 安全建議參考 OAuth 2.0 Security BCP（RFC 9700）；OpenID Connect Core 1.0 定義 Code Flow 及 ID Token。詳見文件最後的標準連結。

1. 使用者從產品入口登入；產品建立一次性 `state`、`nonce` 及 PKCE verifier，將瀏覽器導向中央授權端點。
2. 中央平台驗證全域 User session；使用者沒有租戶或該產品權益時，顯示選擇/開通下一步，不回傳產品 session。
3. 中央平台依 membership、角色、產品訂閱和 feature entitlement 決定 `sub`、產品 audience 和有限權限；只回傳該產品必要的租戶/角色 claims。
4. 產品後端以一次性 code 換 token，驗證所有 OIDC 欄位後，建立自己的安全 session。瀏覽器端使用 Secure、HttpOnly、適當 SameSite cookie；不將長效 bearer token 存於 localStorage。
5. 產品呼叫中央 entitlement/API 時使用產品專屬 audience/scope 的短效 token；產品對產品的機器通訊使用獨立 client identity，不能沿用使用者 token。
6. 中央停用帳號/租戶/產品或撤銷訂閱後，撤銷或縮短 session/token 有效期；產品每次敏感讀寫仍需授權，不只在登入時檢查一次。

不建議由各子平台自行新增 User/password 資料庫，亦不建議只用共享 cookie 假裝跨網域 SSO。應選擇可維護 OIDC 的集中 IdP 或成熟套件，再由各產品註冊為 client；不在此規劃自行發明登入協定或指定供應商。

## 7. 產品註冊、開通及產品模組契約

### 7.1 執行期產品註冊

第一階段以 `config/products.php` 和 `App\Services\ProductRegistry` 建立 boot-time manifest registry，提供 `all`、`find`、`declaresFeature` 查詢，並驗證產品代碼、名稱、版本、生命週期、入口 route 名稱、不重複的 feature keys 與逐項顯示名稱。現有 `website-monitoring` 和 `marketing-insights` 已登錄；測試證明新增第三個產品只需增加 manifest。第二階段新增中央產品/功能 catalog、方案、訂閱及訂閱項目資料表，`products:sync` 可冪等同步程式碼 manifest，`ProductEntitlementService` 會依產品、功能鍵、租戶、方案、訂閱狀態與有效期間計算授權；跨租戶及跨產品方案組合由複合外鍵阻擋。

目前已有平台管理員專用的產品方案目錄、方案版本/功能權益設定、方案上架/停售，以及組織訂閱合約與產品項目管理；`ProductEntitlementService` 仍未接入既有產品路由、API 或背景工作。新資料模型沒有回填舊 `Organization` 方案，也沒有付款、訂單或收款 ledger。`EnsureOrganizationActive` 與既有配額欄位保持原狀，entitlement resolver 仍額外尊重舊組織停用/到期狀態，避免新模型意外重新開放舊帳戶。後續需先完成舊客戶映射、可回復的 entitlement 對照與各產品入口測試，才逐步切換舊方案欄位。

產品目錄只接受經版本控制及審查的程式碼 manifest，不允許管理者上傳任意 PHP/plugin 程式。每個產品 manifest 至少聲明：

- `product_code`（永久穩定、不可重用）、名稱、版本及狀態。
- 入口/route prefix、回呼 URL、導覽項、產品權限 key。
- migrations、worker queues、scheduled commands、health probe。
- 可提供的報表區段/匯出 provider、事件 schema、需要的共用服務契約版本。
- 支援的方案和 entitlement key；未知 entitlement 一律拒絕，不做預設放行。

同一 repo 先由 boot-time manifest registry 註冊，中央訂閱只引用 `product_code`。若新增產品必須改中央訂閱 controller 的產品判斷分支，代表 registry/contract 尚未達成擴充目標。

### 7.2 組織開通流程

1. Super Admin 建立 company/organization/workspace 和第一位 owner，指定試用/免費方案或待付款狀態。
2. 建立一筆或多筆 `subscription_items`，每一筆明確指定 product、plan、期間及目前狀態；bundle 以多個明確 item 展開，不以一個無法拆解的字串表示。
3. 中央 entitlement service 依有效日期、付款狀態及方案計算功能權益；產品入口、頁面操作和 server-side data query 使用同一個授權 service。
4. 產品需要外部資料時，workspace owner 在產品設定頁輸入自己的 provider app client ID/secret（依使用者明確要求），選擇 OAuth provider 並授權。系統儲存加密的 app 設定與獨立的 access/refresh token，顯示連線狀態、權限、更新時間及重新授權步驟。
5. 首次登入進產品 onboarding；只顯示該產品的連線設定、空白狀態和下一步，不要求在中央主頁理解產品細節。
6. 到期、付款失敗、管理員停用或取消訂閱時，中央更新 item state 和 entitlement，產品顯示原因、寬限期、可讀資料政策及恢復路徑。

## 8. 訂閱、權益、方案、金流及用量

- 方案屬於產品，不是全域一個 plan 名稱。方案定義價格/幣別/計價週期、feature keys、席次/客戶數/監測數/API 用量/保留天數等 typed limit。
- 同一 organization 可有多個有效 `subscription_items`，各產品有不同 plan、期間及狀態；bundle 是由中央商品目錄定義的組合，結帳後仍產生分產品授權列，允許單產品升級、取消或到期。
- `free`、`trial`、`paid`、`enterprise` 是方案/合約種類；`active`、`past_due`、`suspended`、`canceled` 是訂閱狀態，不要把商業方案名稱、開通布林值和付款成功混在同一欄。
- 功能授權以 server-side entitlement (`product_code + feature_key + workspace + valid_at`) 判斷；前端隱藏按鈕只是 UX，不是授權。
- 降級不自動刪除超額資源；阻止新增/同步超出限制的內容，提供清理或升級途徑。到期後保留讀取/匯出或唯讀寬限政策需由商業規則決定，實作不可自行假設。
- 用量事件要有計量單位、期間、冪等鍵、來源產品和 workspace；中央帳務以追加式 ledger 聚合，避免直接把易變的即時 count 當付款依據。
- 首批人工收款可以不接金流，但中央仍應留付款方式 `manual`、收款時間、幣別/金額、服務期間、收據/交易參考、操作人、核對狀態及不可竄改的 audit event。金流 gateway 以介面接入，不讓產品直接呼叫特定金流供應商。
- MRR、ARR、續訂率和流失率必須以已核對的有效訂閱/付款 ledger 計算；目前不存在訂單及付款資料，不能從 `plan_name` 或 `subscription_ends_on` 推算真實營收。

## 9. API、事件與跨平台通訊規範

### 9.1 模組化單體階段

- 同一程序內的產品由 PHP contract/service interface 溝通，資料使用小型明確 DTO；不得互相注入對方 controller、存取對方 Eloquent model 或依賴對方表格命名。
- 同步 HTTP/DB 交易只用於需要即時回應的授權/查詢；通知、報表重新計算、同步狀態和健康事件透過 database outbox/queue，consumer 必須冪等。
- queue 分成 `central`、`website`、`marketing` 等具名隊列；同一套 Laravel/DB 可先執行，但 worker 可各自擴縮/重啟，避免昂貴的行銷同步占住網站監測工作者。

### 9.2 獨立部署階段

- 第一方 HTTP API 採 `/api/v1/...`，每個 endpoint 文件列出 owner、tenant scope、product scope、所需 feature entitlement、輸入輸出 schema、錯誤代碼、rate limit、idempotency 和資料保存期限。
- 授權用短效 access token，驗證 issuer、audience、scope、expiry；使用 service identity 進行機器對機器呼叫。不得信任 query/body 中的 `workspace_id` 作為授權證據。
- 寫入類 API 支援 idempotency key；錯誤回應使用穩定機器代碼及可供操作的訊息；記錄 correlation/request ID，不記錄 token、OAuth code、client secret 或個資。
- 非同步事件使用 outbox/inbox：每筆 event 含 `event_id`、`event_type`、`schema_version`、`occurred_at`、`organization_id`、`workspace_id`、`product_code` 和必要 payload；至少一次投遞，consumer 以 `event_id` 去重。
- 先維持向後相容的 additive schema/event changes。必要 breaking change 以新 major API/event schema 並存一段轉移期，不要求所有產品同日發布。

## 10. 漸進式重構階段與驗收

| 階段 | 目標及主要工作 | 主要風險 | 驗收與測試門檻 |
| --- | --- | --- | --- |
| 0. 架構確認 | 已採用模組化單體、Organization = company/tenant/workspace、Client 為外部客戶、工作區角色與產品 entitlement 分離、先人工帳務等第一版方向。 | 未確認的發票、退款及資料刪除期限可能延誤商用。 | 每階段仍記錄決策；不在本階段接金流或自動刪除客戶資料。 |
| 1. Canonical 租戶 | 已新增 Workspace/Organization 一對一 identity mapping，並將 `clients`、監測根表、`notification_outbox`、四種 provider connection 和 per-client property/site/ad account 對應回填及切換至 workspace scope；純子記錄沿用受限 parent scope，後續審查跨根資料連結是否需 composite key。 | 不一致的 owner/workspace mapping 可能造成跨租戶外洩或背景工作漏資料。 | 每次新增資料表 scope 前後比對筆數與 owner/workspace；兩個組織、同 ID 資源、不同角色的 CRUD、report/CSV/share、job recheck；保留 legacy fallback 至資料對帳完成。 |
| 2. RBAC 與產品目錄 | 已建立 registry manifest、產品功能標籤、catalog 同步、產品方案/訂閱封存區、產品生命週期管理、功能路由對應（含公開入口）、產品排程歸屬及中央稽核紀錄；本階段先補齊中央目錄、功能範圍、產品健康與平台治理，不執行客戶付費授權。 | manifest 與資料庫目錄不同步；產品維護/停售狀態未套用到所有入口。 | 驗證 manifest 每條產品路由都有已登錄功能、catalog sync 保留管理員生命週期設定、維護/停售狀態套用至路由、平台管理權限及 workspace 隔離。 |
| 3. 訂閱與商業帳務（進行中） | 2026-11-27 依平台商業化目標重啟，先做人工帳務：已建立 `billing_orders`、`billing_order_items`、只能新增的 `billing_payments` 及 `BillingLedger`，訂單價格只取自可銷售方案，收款不可超過未收餘額、收齊自動轉為已收款、已收款訂單不可取消，所有操作寫入中央稽核；平台管理員可在「訂單與收款」頁操作。訂單收齊時 `SubscriptionProvisioner` 在同一交易內開通或續約訂閱項目（續約延長到期、換方案改用新方案、已過期則自新服務期間重新開始；服務期間以台灣日期計算，到期日次日 00:00 失效），並以來源訂單編號寫入稽核。付費權益已可逐一組織啟用：`organizations.entitlements_enforced_at` 為空時維持舊模式；平台管理員啟用後，登入後的產品路由會以 `ProductEntitlementService` 檢查訂閱授權，平台管理員不受限。另修正兩個互相抵消的時區錯誤：權益判斷原以台灣時間字串比較 UTC 儲存的訂閱時間，封存訂閱頁也未把台灣時間輸入轉為 UTC；先前以舊頁面建立的訂閱時間可能偏移 8 小時，需人工檢查。背景排程（監測派送、TLS/網域/robots 檢查、GA4/Meta/Google Ads 同步）會以 `ProductEntitlementService::unentitledOwnerIds` 排除已啟用訂閱授權但缺少對應功能的組織。公開狀態頁、狀態頁訂閱與公開報表分享也依擁有者組織的權益開放（未授權回應找不到）；退訂連結刻意不受產品狀態與權益限制，收件者永遠可以退訂。已排入佇列的監測、TLS、網域與 robots 檢查在執行前透過 `Monitor::ownerAllowsChecks()` 同時複查組織狀態與權益，失去授權的檢查會標示暫停。`php artisan products:entitlement-readiness` 可只讀取地列出尚未啟用的組織、啟用後會失去的功能，供舊客戶對照決策。舊客戶訂閱對照與批次啟用已完成：管理後台「授權切換」頁可為勾選的舊組織依所選方案建立對照訂閱（到期日沿用舊方案到期日，已停用或到期的組織略過），並只對啟用後不會失去功能的組織批次啟用授權，所有操作以 `legacy_migration` 來源寫入稽核。尚未做：發票、金流 gateway。退款已完成：只能新增的 `billing_refunds`，只可對已收款訂單退款且不超過已收淨額，全額退款標示為已退款、部分退款視為折讓，退款不自動停用訂閱（交由平台管理員判斷）。到期提醒已完成：`billing:send-expiry-reminders` 每日台灣時間 09:13 寄信給組織擁有者，提醒 14 天內到期的產品；同一到期日只提醒一次，續約改變到期日後會再提醒，同組織多產品合併為一封。 | 舊客戶未完成訂閱對照就啟用授權會失去功能；時區或金額計算錯誤造成授權或帳務不一致；退款後服務狀態需人工判斷。 | 訂單金額只取自方案、收款/退款只能新增且不超過餘額、收齊才開通、續約與換方案行為、跨組織複合外鍵、時區回歸測試、各入口（登入頁面、公開入口、排程、佇列工作）依權益放行、退訂永遠可用、到期提醒不重複；舊客戶需先以 readiness 報表確認後再逐一啟用。 |
| 4. 全域 SSO | 中央 OIDC issuer/IdP 與網站、行銷產品 clients；逐步將本機 login 綁定 global subject，不自動依未驗證 email 合併使用者。 | 帳號錯誤合併、token 重放、logout/revocation 不一致。 | OIDC test provider integration、PKCE/state/nonce 失敗、錯誤 issuer/audience、過期/replay、無訂閱、跨租戶角色、帳號停用及跨產品登出驗收。 |
| 5. 整合憑證與資料來源 | 新增 workspace-level provider app 設定頁和 encrypted secret storage；拆分 app client credential 與使用者 OAuth grant，為每個 client 設定 data source mapping。 | Secret 外洩、callback URI/domain 白名單錯誤、provider 審核限制。 | secret 寫入/更新/遮蔽/刪除、權限、log redaction、加密備份還原、錯誤 app credentials、token revoke/refresh；以正式測試 app 完成每家 provider 真實 OAuth/API 驗收。 |
| 6. 產品模組和工作隔離 | 先建立兩個產品的內部 contracts、report providers、具名 queues/worker 與每產品 health/heartbeat；逐模組遷移，不重寫功能。 | contract 遺漏導致報表差異；某產品工作延遲。 | 舊/新 report golden dataset 比對；單產品 worker 故障不阻塞另一產品；retry、poison message、replay、scheduler 和 alert 測試；organization/client 資料歸屬不變。 |
| 7. API/event contracts | 只有已確定要分開部署的模組才發布 versioned API/OpenAPI 及事件；建立相容性測試和冪等 consumers。 | schema drift、重送造成重複收款/通知、API tenant bypass。 | contract test、破壞性版本相容測試、idempotency/replay、auth scope、rate-limit 和故障復原驗收。 |
| 8. 選擇性獨立部署 | 依故障/擴充需求抽離單一產品，採各自部署、queue 和資料存取權；中央保留身份/授權/商務 control plane。 | 分散式操作成本、跨產品資料不足、產品部署失敗。 | deploy/rollback 演練、資料備份還原、服務/事件契約、SLO/告警、佇列隔離、故障演練均通過；有實際負載或客戶需求支持投入。 |

每階段均採 expand-and-contract：先加相容欄位/讀取、回填並比對、切換寫入、觀察後才移除舊欄位。不可用 migration 將 ownerless 或有歧義的舊資料猜分到某個客戶；無法確定的記錄需進人工對帳清單。

## 11. 新增第五及第十個子平台的流程

### 第五個產品

1. 產品需求先證明它是可獨立定價/授權/維運的產品，否則加入既有產品模組。
2. 在版控中新增 `product_code` 和 manifest，宣告 route、功能權益、專屬表/遷移、queue、health check、報表 provider 及需用的 central contracts。
3. 實作產品自己的業務邏輯；共用 identity、client、subscription、credential vault、notifications、audit 和 report sharing 只經由已發布介面使用。
4. 跑 registry/entitlement/security/tenant contract suite，加入第二租戶不可讀寫、產品停用、background failure 和匯出/share 測試。
5. 初期仍隨同一版本部署。新增產品不能要求中央訂閱/總覽 controller 加一個產品名稱判斷或在其他產品表新增欄位。

### 第十個產品

除第五個產品流程外，必須使用穩定 major version 的 OIDC/API/event contract，有自己的 worker queue/health 和可回滾 migration。新產品需能單獨部署或停用；中央平台只需增加受審核的產品 manifest、產品 catalog/plan 設定及必要的 IdP/API client 設定。CI 執行全部 shared-contract tests 加該產品專屬 suite；若要更改中央核心 schema/API，必須先檢查是否是既有 contract 缺口，並以向後相容方式擴充。

「第五/第十」不是為了達成數量而建立空殼產品。產品是否拆分依客戶購買方式、獨立負責人、資料/權限邊界、故障隔離和發布需求決定。

## 12. 主要風險與尚待產品決策

| 議題 | 現況/風險 | 規劃前需要確認 |
| --- | --- | --- |
| OAuth 憑證 ownership | 四種 provider 已支援工作區 owner 自填 OAuth app ID/secret；加密保存並記錄不含秘密值的異動事件，舊 `.env` 設定仍作暫時 fallback。 | 完成遷移後移除 fallback；確認 provider app review、callback 登記方式、secret 輪替和稽核保留政策。 |
| 同一使用者多組織 | 已支援一個帳戶加入多個工作區，並以 session 選擇目前工作區、頁首切換。 | 角色仍是 workspace-wide；待全域 SSO 階段再確認跨產品角色與 global subject 綁定。 |
| 到期後資料 | 現行到期/停用即阻止登入並中止監測工作。產品訂閱後可能需單一產品停用且其他產品繼續。 | 建議採產品級停用、停止寫入與排程，並提供暫時唯讀/匯出；資料刪除期限保持可設定，正式收費前依合約和法規決定。 |
| 產品角色 | 現行角色是 workspace-level editor/read-only，所有業務共用。 | 第一版沿用 workspace-wide 角色，產品功能由獨立 entitlement 控制；待企業客戶要求再增設產品角色或 client 細分權限。 |
| 訂閱與訂單 | 已有部分方案/訂閱程式，但本階段封存；目前不實作訂單、付款、人工收款 ledger 或金流，也不以其狀態控制產品入口。 | 產品/功能平台穩定後，再確認商業模式、稅務、退款、發票與核款流程。 |
| 分析資料憑證 | app client secret 加密後依賴 `APP_KEY`；key rotation 會影響可讀性。 | 密鑰管理/輪替/備份責任人、workspace 權限、刪除與重新授權政策。 |
| 子平台隔離 | 模組化單體可避免程式重複，但 process/DB/部署故障仍可能跨產品影響。 | 何種 RTO/RPO、流量、provider 限制或客戶合約才觸發獨立 worker、DB 或部署。 |

## 13. 盤點依據

此盤點對照下列現有檔案與命令輸出；路徑代表目前實作依據，不表示它們就是最終模組邊界：

- 身分、平台管理與工作區：`app/Models/User.php`、`Organization.php`、`WorkspaceMembership.php`、`AccountScopeContext.php`；`app/Http/Controllers/AuthController.php`、`PlatformOrganizationController.php`、`WorkspaceAccessController.php`；`app/Http/Middleware/`；`routes/web.php`。
- 核心 schema：`database/migrations/` 中 `organizations`、`workspaces`、`workspace_memberships`、`workspace_invitations`、`clients`、monitoring、social engagement、provider connections、client report shares/snapshots migrations。
- 行銷整合：`app/Http/Controllers/*IntegrationController.php`、`config/services.php`、`app/Models/*Connection.php` 及 `app/Services/*DataApi.php`。
- 排程與部署：`routes/console.php`、`app/Console/Commands/`、`app/Jobs/`、`compose.yaml`、`Dockerfile`、`.env.example`、`README.md`、`docs/operations.md`。
- 租戶及分享驗收：`tests/Feature/AgencyWorkflowAcceptanceTest.php`、`AccountDataIsolationTest.php`、`PlatformOrganizationTest.php`、`CommercialUsageLimitTest.php`、`ClientReportShareTest.php`、`ClientReportSnapshotControllerTest.php`。
- 路由盤點：`php artisan route:list --except-vendor --path=platform|integrations|team`；資料庫遷移狀態：`php artisan migrate:status`；執行時資料庫設定：`php artisan config:show database.default`。

## 14. 標準參考

- [OpenID Connect Core 1.0](https://openid.net/specs/openid-connect-core-1_0.html)：第一方跨產品登入及 ID Token 的標準規格。
- [RFC 9700 — OAuth 2.0 Security Best Current Practice](https://www.rfc-editor.org/rfc/rfc9700.html)：OAuth authorization code 防注入、PKCE 和 CSRF 安全建議。對 confidential web client，RFC 建議使用 PKCE；本提案建議各互動式 client 統一使用 transaction-specific PKCE `S256`。

---

**預設決策**：本階段先沿用工作區層級 `editor/read_only`，優先治理中央產品目錄、產品生命週期與功能入口；既有方案/訂閱功能封存保留，訂單、收款及金流延後，暫不以付費 entitlement 控制入口。provider app 憑證由工作區管理者在平台輸入、加密保存，並與使用者 OAuth grant token 分開管理。商業模式、正式金流、發票、憑證金鑰輪替與資料刪除期限待相應階段確認。
