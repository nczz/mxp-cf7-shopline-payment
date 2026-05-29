# MXP CF7 Shopline Payment — 開發計劃（v2 修正版）

## 專案概述

WordPress Contact Form 7 延伸外掛，整合 SHOPLINE Payments 金流服務，
讓 CF7 表單具備收款能力。

**核心設計原則：**
- Phase 1 全部使用導轉式（降低複雜度，快速上線）
- Phase 2 加入信用卡/ApplePay 內嵌式（進階體驗）
- 付款設定綁在 CF7 表單上（非獨立商品 CPT）
- 雙重觸發郵件（returnUrl 主動查詢 + Webhook）
- 最小資料結構先行，完整訂單管理後補

---

## 架構修正記錄

| # | 原設計 | 修正為 | 原因 |
|---|--------|--------|------|
| 1 | 獨立商品 CPT | 付款設定綁在 CF7 表單 | 減少操作步驟，避免過度設計 |
| 2 | Phase 1 混合模式 | Phase 1 全導轉，Phase 2 加內嵌 | 複雜度減半，測試範圍縮小 |
| 3 | 純 Webhook 觸發郵件 | returnUrl 主動查詢 + Webhook 雙重觸發 | 消除延遲風險 |
| 4 | 全域欄位映射 | 表單級別設定 + 自動偵測 | 不同表單欄位名不同 |
| 5 | Phase 1 完整訂單 CPT | Phase 1 最小資料結構，Phase 2 完整訂單 | MVP 精簡 |
| 6 | PHP 7.4 | PHP 8.0 最低版本 | PHP 7.4 已 EOL 超過 3 年 |
| 7 | 訂單存 wp_options | 訂單用 slp_order CPT | options LIKE 查詢效能差，CPT 有索引 |

---

## 第一層維度：核心開發項目

| # | 項目 | Phase | 說明 |
|---|------|-------|------|
| 1 | 外掛基礎架構 | 1 | 入口、載入、生命週期、依賴檢查 |
| 2 | 金流 API 整合 | 1 | SLP Session API 封裝（建立/查詢） |
| 3 | 前端導轉模組 | 1 | wpcf7submit 事件監聽 + window.location.href |
| 4 | Webhook 通知處理 | 1 | 簽章驗證、狀態更新、郵件觸發 |
| 5 | CF7 整合層 | 1 | Form Tag、表單提交攔截、付款設定 UI |
| 6 | 後台設定介面 | 1 | 金鑰管理、環境切換、連線測試 |
| 7 | 安全性機制 | 1 | 金額驗證、Webhook 防偽、Rate Limit |
| 8 | 欄位映射與驗證 | 1 | 自動偵測 + 表單級設定 + 預設值填充 |
| 9 | 付款結果頁 | 1 | returnUrl 頁面、主動查詢、狀態顯示 |
| 10 | 訂單管理系統 | 2 | 訂單 CPT、列表/詳情/退款/匯出 |
| 11 | 內嵌式付款模組 | 2 | SLP SDK 整合、信用卡/ApplePay 頁內付款 |
| 12 | Tag Generator UI | 2 | CF7 編輯器視覺化按鈕 |
| 13 | 前端環境相容性 | 2 | 快取繞過、降級策略、多實例 |

---

## 第二層維度：子項目展開

### 1. 外掛基礎架構

| # | 子項目 | 說明 |
|---|--------|------|
| 1.1 | 主入口檔案 | Plugin header、常數、require load.php |
| 1.2 | 依賴檢查 | CF7 >= 5.9、PHP >= 8.0、WP >= 6.4 |
| 1.3 | 啟用/停用 | 建立 return page、註冊 cron |
| 1.4 | 國際化 | load_plugin_textdomain、zh_TW |

### 2. 金流 API 整合

| # | 子項目 | 說明 |
|---|--------|------|
| 2.1 | API 基礎類別 | HTTP 封裝、Header 組裝、錯誤處理 |
| 2.2 | Session API | 建立結帳交易、查詢結帳交易 |
| 2.3 | Payment Query API | 查詢付款交易（ATM 虛擬帳號資訊） |
| 2.3 | Refund API | 建立退款、查詢退款（Phase 2 UI，Phase 1 底層） |
| 2.4 | 環境切換 | 沙盒/正式 URL + 金鑰分離 |

### 3. 前端導轉模組

| # | 子項目 | 說明 |
|---|--------|------|
| 3.1 | JS 載入 | wp_enqueue_script、dependency 宣告 |
| 3.2 | wpcf7submit 監聽 | 偵測 status=payment_required → 導轉 |
| 3.3 | 導轉前處理 | history.replaceState 防回上頁重複提交 |

### 4. Webhook 通知處理

| # | 子項目 | 說明 |
|---|--------|------|
| 4.1 | REST 端點註冊 | /wp-json/mxp-cf7-slp/v1/webhook |
| 4.2 | HMAC 簽章驗證 | signKey + timestamp + body |
| 4.3 | 事件路由 | session.succeeded / session.expired |
| 4.4 | 狀態更新 | 更新訂單 CPT post_meta 狀態 |
| 4.5 | 郵件觸發 | 讀取 posted_data → 替換 mail tag → wp_mail |
| 4.6 | 冪等性 | event id 去重 |

### 5. CF7 整合層

| # | 子項目 | 說明 |
|---|--------|------|
| 5.1 | Form Tag 註冊 | [shopline_payment] 標籤 |
| 5.2 | Form Tag Handler | 渲染付款按鈕 HTML |
| 5.3 | 表單提交攔截 | wpcf7_before_send_mail → abort + 建立 Session |
| 5.4 | 付款設定 Meta Box | 在 CF7 表單編輯頁加入付款設定 tab/區塊 |
| 5.5 | Special Mail Tags | [_slp_amount]、[_slp_order_number] 等 |
| 5.6 | Flamingo 整合 | 付款資訊寫入記錄 |

### 6. 後台設定介面

| # | 子項目 | 說明 |
|---|--------|------|
| 6.1 | 設定頁面 | 金鑰、環境、預設付款方式 |
| 6.2 | 連線測試 | AJAX 測試 API 連線 |
| 6.3 | Webhook URL 顯示 | 自動產生 + 複製按鈕 |
| 6.4 | CF7 Integration 頁面 | WPCF7_Service 子類 |

### 7. 安全性機制

| # | 子項目 | 說明 |
|---|--------|------|
| 7.1 | 金額 Server 端驗證 | 從表單設定讀取價格，不信任前端 |
| 7.2 | Webhook 簽章 | HMAC-SHA256 + 時間戳 5 分鐘窗口 |
| 7.3 | Rate Limiting | 同 IP 每分鐘 5 筆交易上限 |
| 7.4 | 資料保護 | 金鑰加密儲存、輸出遮罩 |
| 7.5 | 防重複提交 | idempotentKey + posted_data_hash |

### 8. 欄位映射與驗證

| # | 子項目 | 說明 |
|---|--------|------|
| 8.1 | 自動偵測 | 掃描表單 tag 智慧匹配常見命名 |
| 8.2 | 表單級設定 | CF7 表單編輯頁中的映射覆蓋 |
| 8.3 | 提交前驗證 | email/phone 至少一個、lastName 必填 |
| 8.4 | API 請求組裝 | posted_data + mapping + defaults → API body |
| 8.5 | 簡易模式 | 勾選後僅需 email，其他預設填充 |

### 9. 付款結果頁

| # | 子項目 | 說明 |
|---|--------|------|
| 9.1 | Return Page 建立 | 啟用時自動建立頁面 |
| 9.2 | 主動查詢機制 | 頁面載入時 AJAX 查詢 SLP 訂單狀態 |
| 9.3 | 狀態顯示 | 成功/處理中/失敗 三種 UI |
| 9.4 | 郵件觸發（雙重） | 查詢確認成功時也觸發郵件（冪等） |

---

## 第三至五層維度：Phase 1 細部展開

### 1. 外掛基礎架構

#### 1.1 主入口檔案
- L3: Plugin Header（Name, Version, Requires Plugins: contact-form-7）
- L3: 常數：MXP_SLP_VERSION, MXP_SLP_PLUGIN_DIR, MXP_SLP_PLUGIN_URL
  - L4: require includes/class-loader.php → Loader 負責所有 require
    - L5: Loader 按順序載入：api → webhook → form-tag → admin（條件載入）

#### 1.2 依賴檢查
- L3: CF7 是否啟用、版本 >= 5.9
- L3: admin_notices 顯示缺少依賴警告
  - L4: 不滿足時 early return，外掛功能完全不載入
    - L5: 警告含安裝 CF7 的連結

#### 1.3 啟用/停用
- L3: activation → 建立 return page（wp_insert_post）
- L3: deactivation → 清除 cron
  - L4: Return page 用 post_name='slp-payment-return'，檢查是否已存在
    - L5: 頁面內容用 shortcode [slp_return_page] 渲染

#### 1.4 國際化
- L3: load_plugin_textdomain('mxp-cf7-slp')
  - L4: 預設 zh_TW + en_US
    - L5: SLP API language 參數對應 WP locale

### 2. 金流 API 整合

#### 2.1 API 基礎類別
- L3: MXP_SLP_API 類別，封裝 wp_remote_post
- L3: Header：merchantId, apiKey, requestId, Content-Type
  - L4: timeout=30, requestId=wp_generate_uuid4()
  - L4: 回應處理：200→解析 / 400+→記錄錯誤+回傳 false / WP_Error→記錄
    - L5: idempotentKey 對建立類 API 自動帶入
    - L5: 錯誤日誌用 wpcf7_log_remote_request（若可用）或 error_log

#### 2.2 Session API
- L3: create_session($params) → POST /api/v1/trade/sessions/create
- L3: query_session($session_id) → POST /api/v1/trade/sessions/query
  - L4: 金額轉換：intval(round($amount * 100))（防浮點精度）
  - L4: allowPaymentMethodList 從表單付款設定讀取
    - L5: paymentMethodOptions 組裝（分期設定）
    - L5: referenceId 用訂單 token（32 字元隨機）

#### 2.3 Refund API
- L3: create_refund($trade_order_id, $amount, $reason)
- L3: query_refund($refund_order_id)
  - L4: Phase 1 只實作底層方法，Phase 2 加 UI
    - L5: 退款前驗證金額不超過原始金額

#### 2.4 環境切換
- L3: sandbox URL / production URL
- L3: 兩組金鑰分開儲存
  - L4: get_api_url() 根據 option 回傳對應 domain
    - L5: 切換時不清除另一組金鑰

### 3. 前端導轉模組

#### 3.1 JS 載入
- L3: wp_enqueue_script('mxp-cf7-slp-frontend', dependencies: ['contact-form-7'])
- L3: 僅在含 [shopline_payment] tag 的頁面載入
  - L4: wp_localize_script 傳入 REST API root URL
    - L5: 不傳入任何動態資料（快取安全）

#### 3.2 wpcf7submit 監聽
- L3: document.addEventListener('wpcf7submit', handler)
- L3: 判斷 event.detail.status === 'payment_required'
  - L4: 讀取 event.detail.apiResponse.shopline_payment.session_url
    - L5: 若 session_url 存在 → window.location.href 導轉
    - L5: 若不存在 → 顯示錯誤訊息

#### 3.3 導轉前處理
- L3: history.replaceState 替換當前 history entry
- L3: 防止瀏覽器 back button 回到表單頁重複提交
  - L4: 儲存 order_token 到 sessionStorage（returnUrl 頁面用）
    - L5: key: 'slp_order_' + form_id, value: {token, timestamp}

### 4. Webhook 通知處理

#### 4.1 REST 端點註冊
- L3: register_rest_route('mxp-cf7-slp/v1', '/webhook', POST)
- L3: permission_callback: '__return_true'
  - L4: 內部用簽章驗證取代 WP 認證
    - L5: 回傳 200 表示處理成功（SLP 不再重試）

#### 4.2 HMAC 簽章驗證
- L3: $body = $request->get_body()（原始 body，不 decode）
- L3: $payload = $timestamp . '.' . $body
- L3: $expected = hash_hmac('sha256', $payload, $signKey)
  - L4: hash_equals($expected, $sign) 防 timing attack
  - L4: abs(time() - $timestamp/1000) < 300（5 分鐘窗口）
    - L5: 失敗 → 記錄 IP + 回傳 401
    - L5: Content-Length > 1MB → 直接拒絕

#### 4.3 事件路由
- L3: 解析 body.type → switch 分發
  - L4: session.succeeded → handle_session_success($data)
  - L4: session.expired → handle_session_expired($data)
    - L5: 未知 type → log + 回傳 200（不讓 SLP 重試）

#### 4.4 狀態更新
- L3: 用 sessionId 查找對應的訂單 CPT（meta_query）
- L3: 更新狀態 + 記錄 paymentDetails
  - L4: 狀態機：CREATED → SUCCEEDED / EXPIRED（不可逆）
    - L5: 已 SUCCEEDED 收到重複通知 → 忽略（冪等）
    - L5: 記錄 tradeOrderId、paymentMethod、paymentSuccessTime

#### 4.5 郵件觸發
- L3: 狀態變為 SUCCEEDED 時觸發
- L3: 從訂單資料取得 cf7_form_id + posted_data
  - L4: 載入 WPCF7_ContactForm → 取得 mail property
  - L4: 用 wpcf7_mail_tag_replaced filter 注入 posted_data
    - L5: 呼叫 WPCF7_Mail::send($mail_prop, 'mail')
    - L5: 若 mail_2 active → 也發送 mail_2
    - L5: 發送後標記 mail_sent=true（防重複）
    - L5: 耗時操作：先回 200 給 SLP，用 shutdown hook 發郵件

#### 4.6 冪等性
- L3: 記錄已處理的 event.id
- L3: 重複 event.id → 直接回 200
  - L4: 儲存在 wp_options：'_slp_evt_' + substr(md5($event_id), 0, 16)
    - L5: 每日 cron 清理 7 天前的記錄

### 5. CF7 整合層

#### 5.1 Form Tag 註冊
- L3: wpcf7_add_form_tag('shopline_payment', handler, ['display-block', 'singular'])
  - L4: 在 wpcf7_init hook 中註冊
    - L5: singular=true → 一個表單只能有一個付款標籤

#### 5.2 Form Tag Handler
- L3: 回傳 HTML：付款按鈕 + hidden inputs
  - L4: HTML 結構：
    - div.wpcf7-shopline-payment
      - span.slp-amount-display（顯示金額，AJAX 更新）
      - button[type=submit].slp-submit-btn（按鈕文字從 tag values 取）
  - L4: 按鈕文字預設「前往付款」，可在 tag 中自訂
    - L5: hidden input: _slp_form_payment=1（標記此表單有付款）

#### 5.3 表單提交攔截
- L3: add_action('wpcf7_before_send_mail', handler, 10, 3)
- L3: 檢查表單是否含 shopline_payment tag → 建立 Session → abort
  - L4: 流程：
    1. 從表單 additional_settings 或 meta 讀取付款設定（金額、付款方式）
    2. 用欄位映射組裝 API request body
    3. 呼叫 sessions/create
    4. 儲存訂單資料（wp_insert_post slp_order CPT）
    5. $abort = true, set_status('payment_required')
    6. add_result_props(['shopline_payment' => ['session_url' => $url]])
  - L4: 儲存的訂單資料：
    - L5: wp_insert_post slp_order CPT + update_post_meta
    - L5: 內容：{session_id, reference_id, form_id, posted_data, amount, status, created_at}
    - L5: TTL: 設定 expiration（24 小時後自動清理）

#### 5.4 付款設定 Meta Box（在 CF7 表單編輯頁）
- L3: wpcf7_editor_panels filter 加入付款 Tab
- L3: 設定項：金額、付款方式、分期、簡易模式
  - L4: 使用 wpcf7_save_contact_form hook 儲存設定
  - L4: 設定存在 contact form 的 post_meta 中
    - L5: meta key: _slp_payment_settings
    - L5: 值：{amount, currency, payment_methods[], cc_installments[], bnpl_installments[], simple_mode}
    - L5: 金額欄位支援固定值或「從表單欄位讀取」（動態金額）

#### 5.5 Special Mail Tags
- L3: add_filter('wpcf7_special_mail_tags', handler, 10, 4)
  - L4: 支援的 tags：
    - [_slp_amount] → 格式化金額（NT$ 590）
    - [_slp_order_number] → 訂單 token 前 8 碼
    - [_slp_payment_method] → 付款方式中文名
    - [_slp_session_id] → SLP Session ID
    - [_slp_trade_order_id] → SLP Trade Order ID
    - L5: 從 submission pocket 或訂單 post_meta 中讀取

#### 5.6 Flamingo 整合
- L3: add_filter('wpcf7_flamingo_inbound_message_parameters', handler)
  - L4: 加入 meta: slp_amount, slp_payment_method, slp_status
    - L5: function_exists('Flamingo_Inbound_Message') 檢查

### 6. 後台設定介面

#### 6.1 設定頁面
- L3: add_submenu_page 在 wpcf7 menu 下
- L3: 區塊：連線設定、預設付款方式
  - L4: 連線：environment, merchant_id, api_key, sign_key, client_key(Phase 2)
  - L4: 預設付款方式：6 種 checkbox + 分期設定
    - L5: Settings API：register_setting('mxp_slp_settings', ...)
    - L5: 金鑰欄位 type=password + 遮罩顯示

#### 6.2 連線測試
- L3: AJAX 按鈕 → 呼叫 sessions/query 測試
  - L4: 預期 400+1018 = 金鑰有效；401 = 金鑰無效
    - L5: 即時顯示 ✅/❌ 狀態

#### 6.3 Webhook URL 顯示
- L3: 顯示 rest_url('mxp-cf7-slp/v1/webhook') + 複製按鈕
  - L4: 偵測非 HTTPS 時顯示警告
    - L5: 提示使用者到 SLP 後台「開發者管理 > Webhook 管理」設定

#### 6.4 CF7 Integration 頁面
- L3: WPCF7_Service 子類，註冊到 payments category
  - L4: 顯示連線狀態 + 連結到設定頁面
    - L5: is_active() = apiKey 已設定且測試通過

### 7. 安全性機制

#### 7.1 金額 Server 端驗證
- L3: 建立 Session 時從表單 meta 讀取金額
- L3: 不接受前端傳入的金額
  - L4: 動態金額場景：從 posted_data 指定欄位讀取 → absint() → 上限檢查
    - L5: 上限可在設定中配置（預設 100000 TWD）

#### 7.2 Webhook 簽章
- L3: 見 4.2 完整實作
  - L4: 額外：拒絕 Content-Type 非 application/json
    - L5: 拒絕 body > 1MB

#### 7.3 Rate Limiting
- L3: transient 計數：'_slp_rate_' + md5(IP)，TTL 60s
  - L4: 超過 5 次 → 回傳 CF7 validation error
    - L5: 訊息：「請求過於頻繁，請稍後再試」

#### 7.4 資料保護
- L3: 金鑰加密儲存（openssl_encrypt + AUTH_KEY）
- L3: 後台顯示遮罩
  - L4: 日誌中只顯示前 4 碼 + '***'
    - L5: apiKey 不出現在前端 HTML 中

#### 7.5 防重複提交
- L3: idempotentKey = order_token（sessions/create 時帶入）
- L3: CF7 的 posted_data_hash 機制
  - L4: 同一 token 不建立第二個 Session
    - L5: 前端 history.replaceState 防 back button

### 8. 欄位映射與驗證

#### 8.1 自動偵測
- L3: 掃描表單 scan_form_tags()，匹配常見命名
  - L4: 規則表：
    - /email/ → email
    - /name|姓名/ → lastName（全名）
    - /tel|phone|電話/ → phone
    - /address|地址/ → street
  - L4: 匹配結果快取在表單 meta 中
    - L5: 每次表單儲存時重新偵測

#### 8.2 表單級設定
- L3: 在 CF7 表單的付款設定 Meta Box 中加入映射覆蓋
  - L4: 只顯示自動偵測失敗的欄位讓使用者手動選
    - L5: UI：「我們偵測到 email 欄位為 [your-email] ✓」
    - L5: 偵測失敗：「請選擇 email 對應的欄位 [dropdown]」

#### 8.3 提交前驗證
- L3: email 或 phone 至少一個有值
- L3: lastName（姓名欄位）不可為空
  - L4: 中文姓名拆分：第一字=lastName，其餘=firstName
  - L4: 電話自動補國碼：09開頭 → +886
    - L5: 驗證失敗 → CF7 validation_failed + 具體欄位錯誤

#### 8.4 API 請求組裝
- L3: MXP_SLP_Request_Builder::build($form_id, $posted_data)
  - L4: 組裝順序：mapping 取值 → 格式轉換 → 預設值填充 → 組裝完整 body
    - L5: 預設值：shipping={數位商品,電子郵件}, billing.street=線上交易
    - L5: customer.referenceCustomerId = md5(email) 或 'guest_' + uniqid()
    - L5: client.ip = sanitized REMOTE_ADDR

#### 8.5 簡易模式
- L3: 表單付款設定中勾選「簡易模式（數位商品）」
- L3: 啟用後僅需 email，其他全部預設填充
  - L4: 簡易模式下隱藏地址/物流映射設定
    - L5: POC 已驗證：SLP 接受 street="數位商品無需寄送" ✓

### 9. 付款結果頁

#### 9.1 Return Page 建立
- L3: 啟用時 wp_insert_post 建立頁面
- L3: 內容：[slp_return_page] shortcode
  - L4: post_name: 'slp-payment-return'
    - L5: 若已存在則不重複建立

#### 9.2 主動查詢機制
- L3: 頁面載入時 JS 呼叫 REST API 查詢訂單狀態
- L3: REST endpoint: GET /mxp-cf7-slp/v1/order-status?token=xxx
  - L4: Server 端：先查本地 CPT post_meta 狀態
  - L4: 若狀態仍為 CREATED → 呼叫 SLP sessions/query 確認
    - L5: SLP 回傳 SUCCEEDED → 更新本地狀態 + 觸發郵件
    - L5: SLP 回傳 CREATED/PENDING → 回傳「處理中」
    - L5: 前端 polling：每 3 秒查詢一次，最多 60 次（3 分鐘）

#### 9.3 狀態顯示
- L3: 三種 UI 狀態：成功 / 處理中 / 失敗
  - L4: 成功：綠色 ✓ + 「付款成功，確認信已發送」
  - L4: 處理中：spinner + 「付款處理中，請稍候...」
  - L4: 失敗/逾期：紅色 ✗ + 「付款未完成」+ 重試連結
    - L5: 重試連結回到原始表單頁面

#### 9.4 郵件觸發（雙重機制）
- L3: returnUrl 查詢確認成功時觸發郵件
- L3: Webhook 到達時也觸發郵件
- L3: 兩者都檢查 mail_sent 標記（冪等，不重複發）
  - L4: 先到者觸發，後到者跳過
    - L5: mail_sent 標記存在訂單 post_meta 中
    - L5: 使用 wp_cache lock 或 option update 的原子性防 race condition

---

## Phase 2 概要（完整體驗）

### 10. 訂單管理系統
- 訂單 CPT（slp_order）：加入完整列表頁、詳情頁、退款 UI
- 列表頁：自訂 columns、狀態篩選、搜尋
- 詳情頁：表單資料、付款資訊、狀態時間軸
- 退款 UI：全額/部分退款 Modal + AJAX
- CSV 匯出

### 11. 內嵌式付款模組
- SLP JS SDK CDN 載入
- SDK 初始化（CreditCard/ApplePay）
- createPayment → Server create payment → pay(nextAction)
- 降級策略：SDK 失敗 → fallback 到導轉式
- ApplePay 需 user gesture 觸發 createPayment

### 12. Tag Generator UI
- CF7 編輯器加入「Shopline Payment」按鈕
- Modal：設定金額、付款方式、按鈕文字
- 即時預覽 shortcode

### 13. 前端環境相容性
- 快取繞過：AJAX 取得動態資料
- JS defer 容錯：SDK retry 機制
- 多實例隔離：同頁多表單
- CSP 相容：不用 inline script
- 快取外掛偵測 + 設定建議

---

## 專家注意事項（精簡版）

### WordPress 專家
1. REST API permission_callback 不可省略（Webhook 用 __return_true + 內部簽章驗證）
2. wp_remote_post timeout 設 30 秒（預設 5 秒太短）
3. WP Cron 不精確，訂單過期不能依賴精確時間
4. uninstall.php 中不能用外掛的常數和函式

### CF7 專家
1. WPCF7_Submission 是 singleton，Webhook 中不存在 → 用 filter 注入 posted_data
2. wpcf7_before_send_mail 的 $abort 是 reference（&$abort）
3. set_status('payment_required') 會正確傳到前端 wpcf7submit 事件
4. special mail tags 在無 Submission 時安全回傳空字串（已確認原始碼）

### SLP API 專家
1. 金額單位是「分」（TWD × 100），用整數計算
2. requestId 每次唯一（wp_generate_uuid4）
3. Session URL 禁止 iframe/window.open，必須 location.href
4. 一筆 Session 可能對應多筆 Trade（顧客換付款方式）
5. Webhook 端點必須 < 5 秒回 200（郵件用 shutdown hook 異步發）
6. 簽章用原始 body（$request->get_body()），不能 decode 再 encode

### 金流安全專家
1. 永遠不信任前端金額，Server 從 DB 讀取
2. Webhook 是唯一可信的付款確認（returnUrl 不代表成功）
3. signKey 保護等級最高（可偽造付款成功）
4. 防重複：idempotentKey + event id 去重 + mail_sent 標記

### 前端專家
1. Phase 2 的 createPayment() 必須在 click event 中呼叫（ApplePay 限制）
2. CF7 用 fetch AJAX 提交，頁面不重載
3. 導轉前 history.replaceState 防 back button 重複提交
4. fetch 呼叫自己站 API 需 credentials: 'same-origin'

---

## 資料結構設計（Phase 1 最小版）

### 訂單資料（slp_order CPT）

```
Post Type: slp_order (public=false, show_ui=true)
Post Meta:
    'token'          => string,     // 訂單 token
    'session_id'     => string,     // SLP Session ID
    'reference_id'   => string,     // 特店訂單號（= token）
    'form_id'        => int,        // CF7 表單 ID
    'posted_data'    => array,      // 表單提交資料（serialized）
    'amount'         => int,        // 金額（元）
    'currency'       => 'TWD',
    'status'         => string,     // CREATED|SUCCEEDED|EXPIRED|FAILED
    'payment_method' => string,     // 實際付款方式（Webhook 回填）
    'trade_order_id' => string,     // SLP Trade Order ID（Webhook 回填）
    'mail_sent'      => bool,       // 郵件是否已發送
    'created_at'     => int,        // timestamp
    'updated_at'     => int,        // timestamp
]
post_title = "SLP-{sequential_number}"
post_status = "publish"
post_date = 建立時間
```

### 全域設定（wp_options）

```
Key: 'mxp_slp_settings'
Post Meta:
    'environment'          => 'sandbox'|'production',
    'sandbox_merchant_id'  => string,
    'sandbox_api_key'      => string (encrypted),
    'sandbox_sign_key'     => string (encrypted),
    'sandbox_client_key'   => string,
    'live_merchant_id'     => string,
    'live_api_key'         => string (encrypted),
    'live_sign_key'        => string (encrypted),
    'live_client_key'      => string,
    'default_payment_methods' => array,
    'default_cc_installments' => array,
    'return_page_id'       => int,
]
```

### 表單付款設定（post_meta on wpcf7_contact_form）

```
Key: '_slp_payment_settings'
Post Meta:
    'enabled'            => bool,
    'amount'             => int,        // 固定金額（元）
    'amount_field'       => string,     // 或從表單欄位讀取
    'currency'           => 'TWD',
    'payment_methods'    => array,      // ['CreditCard','LinePay',...]
    'cc_installments'    => array,      // ['0','3','6']
    'bnpl_installments'  => array,      // ['0','3','6']
    'simple_mode'        => bool,       // 簡易模式
    'field_mapping'      => array,      // 手動覆蓋的映射
    'button_text'        => string,     // 按鈕文字
]
```

---

## 檔案結構（Phase 1）

```
mxp-cf7-shopline-payment/
├── mxp-cf7-shopline-payment.php    # 主入口
├── uninstall.php                   # 解除安裝清理
├── includes/
│   ├── class-loader.php            # 載入管理
│   ├── class-api.php               # SLP API 基礎 + Session + Refund
│   ├── class-webhook.php           # Webhook 接收 + 簽章驗證 + 事件處理
│   ├── class-form-tag.php          # CF7 Form Tag 註冊 + Handler
│   ├── class-form-handler.php      # 表單提交攔截 + Session 建立
│   ├── class-request-builder.php   # API 請求組裝（映射 + 預設值）
│   ├── class-mail-handler.php      # 郵件觸發（filter 注入 + 發送）
│   ├── class-return-page.php       # Return Page shortcode + 狀態查詢
│   └── class-security.php          # Rate limit + 加密 + 驗證
├── admin/
│   ├── class-settings.php          # 全域設定頁
│   ├── class-payment-panel.php     # CF7 表單編輯頁「付款」Tab
│   └── class-service.php           # WPCF7_Service 子類
├── assets/
│   ├── js/
│   │   ├── frontend.js             # wpcf7submit 監聽 + 導轉
│   │   ├── return-page.js          # 狀態查詢 + polling
│   │   └── admin.js                # 設定頁互動（連線測試等）
│   └── css/
│       ├── frontend.css            # 付款按鈕 + return page 樣式
│       └── admin.css               # 後台樣式
├── languages/
│   └── mxp-cf7-slp.pot
└── readme.txt
```

---

## 開發順序（Phase 1 建議）

```
Day 1:
  ├── 1.1-1.4 外掛基礎架構
  ├── 6.1 設定頁面（金鑰輸入）
  └── 2.1-2.2 API 類別 + Session API

Day 2:
  ├── 5.4 CF7 表單付款設定 Meta Box
  ├── 8.1-8.5 欄位映射 + 請求組裝器
  └── 5.1-5.3 Form Tag + 提交攔截

Day 3:
  ├── 3.1-3.3 前端導轉模組
  ├── 4.1-4.6 Webhook 完整實作
  └── 5.5 Special Mail Tags

Day 4:
  ├── 9.1-9.4 Return Page + 主動查詢 + 郵件雙重觸發
  ├── 7.1-7.5 安全性機制
  └── 6.2-6.4 連線測試 + Webhook URL + Integration

Day 5:
  ├── 整合測試（完整付款流程）
  ├── 沙盒環境端對端驗證
  └── Bug 修復 + 邊界情況處理
```

---

## 驗收標準（Phase 1）

- [ ] 在 CF7 表單編輯頁可設定金額和付款方式
- [ ] 表單中插入 [shopline_payment] 後前端顯示付款按鈕
- [ ] 提交表單後正確導轉到 SLP 付款頁
- [ ] SLP 付款頁顯示正確金額和付款方式選項
- [ ] 付款完成後導轉回 return page 顯示成功
- [ ] Webhook 正確接收並驗證簽章
- [ ] 付款成功後 CF7 郵件正確發送（含 mail tag 替換）
- [ ] 簡易模式（僅 email）可正常建立交易
- [ ] 同一筆訂單不會重複發送郵件
- [ ] 金額無法從前端竄改
- [ ] 偽造的 Webhook 被正確拒絕
- [ ] WP_DEBUG=true 下無 notice/warning

---

## 領域知識需求

| 類別 | 精通 | 熟練 | 了解 |
|------|------|------|------|
| WordPress 核心（hooks, REST API, options, cron） | 4 | 6 | 2 |
| Contact Form 7 內部機制（tag, submission, mail） | 5 | 3 | 2 |
| SHOPLINE Payments API（Session, Webhook, 狀態碼） | 4 | 4 | 1 |
| 前端 JS（fetch, DOM events, sessionStorage） | 2 | 2 | 1 |
| 安全性（HMAC, CSRF, XSS, Rate Limit） | 3 | 2 | 2 |
| 運維（ddev, ngrok, debug） | 0 | 2 | 2 |

---

## POC 驗證結果

| # | 項目 | 結果 | 證據 |
|---|------|------|------|
| 1 | CF7 郵件在無 Submission 下發送 | ✅ 可行 | 原始碼確認 null check + wpcf7_mail_tag_replaced filter 可注入 |
| 2 | SLP API 接受簡易模式預設值 | ✅ 通過 | curl 實測 HTTP 200，Session 建立成功 |
| 3 | SLP 沙盒 API 連線 | ✅ 通過 | apiKey sk_sandbox_... 驗證成功 |

---

## UX 修正（v2.1）

### 修正清單

| # | 問題 | 修正 | 實作方式 |
|---|------|------|---------|
| U1 | 付款設定位置不直覺 | CF7 獨立 Tab（非 Meta Box） | wpcf7_editor_panels filter 加入 'payment-panel' |
| U2 | 無 onboarding 引導 | Admin notice + 步驟指引 | 未設定金鑰時顯示引導 notice |
| U3 | 管理員無法查看交易 | 極簡交易記錄頁 | admin page 列出最近 20 筆 |
| U4 | 兩個按鈕混淆 | payment tag 取代 submit | 渲染含 type=submit 的按鈕，隱藏原 [submit] |
| U5 | 導轉前無回饋 | Spinner + disabled + 文字變化 | 利用 CF7 的 .submitting class + 自訂 |
| U6 | Return page 太簡陋 | 訂單摘要 + ATM 資訊 | 顯示商品名/金額/email + ATM 虛擬帳號 |
| U7 | 失敗後體驗斷裂 | 重新付款按鈕（免重填） | 用儲存的 posted_data 建立新 Session |

### U1：付款設定改為 CF7 獨立 Tab

**技術確認：** CF7 提供 `wpcf7_editor_panels` filter，可加入自訂 panel。

```php
add_filter('wpcf7_editor_panels', function($panels) {
    $panels['payment-panel'] = [
        'title'    => __('付款', 'mxp-cf7-slp'),
        'callback' => 'mxp_slp_editor_panel_payment',
    ];
    return $panels;
});
```

**Tab 內容：**
- 啟用付款：checkbox（勾選後展開以下設定）
- 金額：number input 或「從欄位讀取」dropdown
- 付款方式：6 種 checkbox
- 分期設定：條件顯示
- 簡易模式：checkbox
- 欄位映射：自動偵測結果 + 手動覆蓋
- 按鈕文字：text input

### U2：Onboarding 引導

**觸發條件：** 外掛啟用但 apiKey 未設定時

**流程：**
1. Admin notice（全站）：「SHOPLINE Payments 已啟用！請先設定金鑰 → [前往設定]」
2. 設定頁面頂部：步驟指引
   - Step 1: 填入金鑰 ← 你在這裡
   - Step 2: 在 CF7 表單中啟用付款
   - Step 3: 測試付款流程
3. CF7 付款 Tab 在未設定金鑰時：顯示「請先完成金鑰設定 → [前往設定]」

**消除條件：** apiKey 設定完成 + 連線測試通過後 notice 消失

### U3：極簡交易記錄頁

**位置：** 外掛設定頁面的子頁面（或同頁面的 tab）

**內容：**
```
最近交易（最新 20 筆）

時間          | 金額    | 狀態   | 付款方式 | 顧客 Email
2026/05/29 21:00 | NT$401 | ✅ 成功 | 信用卡   | t***@example.com
2026/05/29 20:30 | NT$590 | ⏳ 等待 | ATM轉帳  | j***@gmail.com
2026/05/29 20:00 | NT$401 | ❌ 逾期 | 街口支付  | a***@yahoo.com
```

**實作：** WP_Query 查詢 slp_order CPT，按 post_date DESC 排序，取最新 20 筆。
不需要 WP_List_Table，簡單的 HTML table 即可。

### U4：Payment Tag 取代 Submit

**行為：** `[shopline_payment]` 渲染的按鈕具有 `type="submit"` 屬性，功能等同 CF7 的 [submit]。

**處理原有 [submit]：**
- 如果表單同時有 [submit] 和 [shopline_payment]，用 CSS 隱藏 [submit]
- 或在 form tag handler 中偵測並輸出提示：「此表單已有付款按鈕，不需要額外的 [submit]」

**按鈕 HTML：**
```html
<div class="wpcf7-shopline-payment">
  <span class="slp-amount-display">NT$ 401</span>
  <button type="submit" class="wpcf7-form-control wpcf7-submit slp-submit-btn">
    <span class="slp-btn-text">前往付款</span>
    <span class="slp-btn-spinner hidden">處理中...</span>
  </button>
</div>
```

### U5：導轉前視覺回饋

**CSS：**
```css
.wpcf7-form.submitting .slp-submit-btn { opacity: 0.7; pointer-events: none; }
.wpcf7-form.submitting .slp-btn-text { display: none; }
.wpcf7-form.submitting .slp-btn-spinner { display: inline-block; }
```

**JS 額外處理：**
- wpcf7submit 事件觸發後、導轉前：按鈕文字改為「正在跳轉到付款頁面...」
- 防止使用者在這段時間內操作

### U6：Return Page 訂單摘要

**顯示內容：**
- 商品/服務名稱（從表單 title 或付款設定取得）
- 付款金額：NT$ 401
- 顧客 Email：t***@example.com（部分遮罩）
- 付款狀態：成功 ✓ / 處理中 ⏳ / 失敗 ✗

**ATM 轉帳特殊處理：**
- 當 paymentMethod=VirtualAccount 且狀態為 PENDING 時
- 顯示：轉帳銀行代碼、虛擬帳號、截止日期
- 這些資訊從 SLP trade query API 的 virtualAccount 欄位取得
- 提示：「請於 {截止日期} 前完成轉帳」

### U7：重新付款（免重填）

**觸發：** Return page 顯示失敗/逾期時，出現「重新付款」按鈕

**流程：**
1. 點擊「重新付款」
2. JS 呼叫 REST API：POST /mxp-cf7-slp/v1/retry-payment?token=xxx
3. Server 端：用原訂單的 posted_data 建立新 Session
4. 回傳新的 session_url → 前端導轉

**限制：**
- 同一筆原始訂單最多重試 3 次
- 原訂單建立超過 24 小時不允許重試（posted_data 可能已過期）
- 重試建立的新訂單關聯到原始訂單（_slp_retry_of = original_token）

---

## 更新後的檔案結構（Phase 1 最終版）

```
mxp-cf7-shopline-payment/
├── mxp-cf7-shopline-payment.php
├── uninstall.php
├── includes/
│   ├── class-loader.php
│   ├── class-api.php               # SLP API（Session + Refund + Query）
│   ├── class-webhook.php           # Webhook 接收 + 驗證 + 事件處理
│   ├── class-form-tag.php          # Form Tag 註冊 + Handler（含 submit 按鈕）
│   ├── class-form-handler.php      # 表單提交攔截 + Session 建立
│   ├── class-request-builder.php   # API 請求組裝（映射 + 預設值）
│   ├── class-mail-handler.php      # 郵件觸發（filter 注入 + 冪等發送）
│   ├── class-return-page.php       # Return Page（摘要 + 查詢 + 重試）
│   └── class-security.php          # Rate limit + 加密 + 驗證
├── admin/
│   ├── class-settings.php          # 全域設定頁 + 交易記錄
│   ├── class-payment-panel.php     # CF7 表單編輯頁「付款」Tab
│   ├── class-service.php           # WPCF7_Service 子類
│   └── class-onboarding.php        # 引導 notice + 步驟提示
├── assets/
│   ├── js/
│   │   ├── frontend.js             # wpcf7submit + 導轉 + spinner
│   │   ├── return-page.js          # 狀態查詢 + polling + 重試
│   │   └── admin.js                # 設定頁 + 付款 Tab 互動
│   └── css/
│       ├── frontend.css            # 按鈕 + spinner + return page
│       └── admin.css               # 後台樣式
├── languages/
│   └── mxp-cf7-slp.pot
└── readme.txt
```

---

## 更新後的開發順序

```
Day 1:
  ├── 外掛基礎架構（入口、載入、依賴檢查）
  ├── 全域設定頁（金鑰輸入 + 環境切換）
  ├── API 類別（基礎 + Session API）
  └── 連線測試 AJAX

Day 2:
  ├── CF7 付款 Tab（wpcf7_editor_panels）
  ├── 欄位映射自動偵測 + 請求組裝器
  ├── Form Tag 註冊 + Handler（含 submit 按鈕）
  └── 表單提交攔截 + Session 建立

Day 3:
  ├── 前端 JS（wpcf7submit + spinner + 導轉）
  ├── Webhook 完整實作（簽章 + 事件 + 狀態更新）
  ├── 郵件觸發（filter 注入 + 冪等）
  └── Special Mail Tags

Day 4:
  ├── Return Page（摘要 + 主動查詢 + ATM 資訊 + 重試）
  ├── 安全性（金額驗證 + Rate Limit + 防重複）
  ├── Onboarding 引導
  └── 極簡交易記錄頁

Day 5:
  ├── 整合測試（完整付款流程 × 6 種付款方式）
  ├── 邊界情況（失敗/逾期/重試/重複提交）
  ├── Flamingo 整合
  └── Bug 修復
```

---

## 更新後的驗收標準（Phase 1）

### 管理員體驗
- [ ] 安裝後有明確的引導 notice 指向設定頁
- [ ] 設定頁可輸入金鑰並測試連線
- [ ] CF7 表單編輯頁有獨立的「付款」Tab
- [ ] 付款 Tab 中可設定金額、付款方式、簡易模式
- [ ] 欄位映射自動偵測正確（email、name）
- [ ] 交易記錄頁可查看最近交易狀態
- [ ] Webhook URL 顯示正確且可複製

### 消費者體驗
- [ ] 表單中只有一個付款按鈕（無重複 submit）
- [ ] 按鈕顯示金額（NT$ 401）
- [ ] 點擊後按鈕變為「處理中...」+ spinner
- [ ] 正確導轉到 SLP 付款頁（顯示正確金額和付款方式）
- [ ] 付款完成後回到 return page 顯示訂單摘要
- [ ] ATM 轉帳顯示虛擬帳號和截止日期
- [ ] 付款失敗可點「重新付款」免重填表單
- [ ] 收到付款成功確認信（含正確的表單資料）

### 安全性
- [ ] 前端無法竄改金額
- [ ] 偽造 Webhook 被拒絕
- [ ] 同一訂單不重複發信
- [ ] Rate limit 正常運作
- [ ] WP_DEBUG=true 無 warning
