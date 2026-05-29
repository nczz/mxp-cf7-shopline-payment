# MXP CF7 Shopline Payment — 元件規格書

本文件定義每個元件的行為、技術實作、介面契約與驗收條件。
搭配 DEVELOPMENT_PLAN.md 使用，確保開發者可立即接手。

---

## 快速上手

1. 讀 DEVELOPMENT_PLAN.md 了解整體架構和設計決策
2. 讀本文件了解每個元件的具體規格
3. 按 DEVELOPMENT_PLAN.md 的 Day 1-5 順序開發
4. 每個元件完成後對照本文件的驗收條件確認

**關鍵技術事實（已驗證）：**
- SLP 沙盒 apiKey: `sk_sandbox_94a12542fff146c5833d6b78f46fb669`
- SLP merchantId: `2652289079513847808`
- SLP 接受簡易模式預設值（street="數位商品無需寄送"）✓
- CF7 的 wpcf7_mail_tag_replaced filter 可在無 Submission 下注入 posted_data ✓
- CF7 的 wpcf7_editor_panels filter 可加入自訂 Tab ✓
- CF7 special mail tags 在無 Submission 時安全回傳空字串（有 null check）✓

---

## 元件 A：外掛入口 (mxp-cf7-shopline-payment.php)

### 行為
- 定義 Plugin Header 和常數
- require includes/class-loader.php
- 不含任何業務邏輯

### 技術規格
```php
Plugin Name: MXP CF7 Shopline Payment
Version: 1.0.0
Requires PHP: 8.0
Requires at least: 6.4
Requires Plugins: contact-form-7

Constants:
  MXP_SLP_VERSION = '1.0.0'
  MXP_SLP_PLUGIN_DIR = __DIR__
  MXP_SLP_PLUGIN_URL = plugins_url('', __FILE__)
  MXP_SLP_PLUGIN_FILE = __FILE__
  MXP_SLP_PLUGIN_BASENAME = plugin_basename(__FILE__)
```

### 驗收條件
- [ ] 啟用外掛無 fatal error
- [ ] 常數在所有 include 檔案中可用
- [ ] CF7 未啟用時外掛不載入功能（只顯示 notice）

---

## 元件 B：載入器 (includes/class-loader.php)

### 行為
- 檢查依賴（CF7 版本、PHP 版本）
- 按順序 require 所有類別檔案
- 註冊 activation/deactivation hooks
- 初始化各模組

### 技術規格
```
載入順序：
1. class-security.php（無依賴）
2. class-api.php（無依賴）
3. class-request-builder.php（無依賴）
4. class-webhook.php（依賴 api, security）
5. class-mail-handler.php（依賴 api）
6. class-form-tag.php（無依賴）
7. class-form-handler.php（依賴 api, request-builder, security）
8. class-return-page.php（依賴 api, mail-handler）
9. admin/class-settings.php（僅 is_admin）
10. admin/class-payment-panel.php（僅 is_admin）
11. admin/class-service.php（僅 is_admin）
12. admin/class-onboarding.php（僅 is_admin）

Hooks:
- register_activation_hook → create_return_page(), schedule_cron()
- register_deactivation_hook → clear_cron()
- plugins_loaded (priority 20) → 依賴檢查 + 初始化
```

### 驗收條件
- [ ] CF7 未啟用 → admin notice + 功能不載入
- [ ] CF7 版本 < 5.9 → admin notice + 功能不載入
- [ ] 正常啟用 → 所有類別可用、hooks 已註冊

---

## 元件 C：API 類別 (includes/class-api.php)

### 行為
- 封裝所有 SLP API 呼叫
- 管理認證 Header
- 統一錯誤處理和日誌

### 介面契約
```php
class MXP_SLP_API {
    // Session API
    public function create_session(array $params): array|false;
    public function query_session(string $session_id): array|false;
    
    // Payment Query API（ATM 虛擬帳號需要）
    public function query_payment(string $trade_order_id): array|false;
    
    // Refund API
    public function create_refund(string $trade_order_id, int $amount, string $reason): array|false;
    public function query_refund(string $refund_order_id): array|false;
    
    // Utility
    public function test_connection(): bool;
    public static function get_instance(): self;
}
```

### 技術規格
```
Base URL:
  sandbox: https://api-sandbox.shoplinepayments.com
  production: https://api.shoplinepayments.com

Headers (每個請求):
  Content-Type: application/json
  merchantId: {from settings}
  apiKey: {from settings, decrypted}
  requestId: {wp_generate_uuid4()}

Timeout: 30 seconds
Retry: 不重試（由呼叫端決定）

金額轉換:
  to API: intval(round($amount_in_dollars * 100))
  from API: $value / 100

錯誤處理:
  HTTP 200 → json_decode body, return array
  HTTP 400/429/500 → log error, return false
  WP_Error → log error, return false

日誌:
  WP_DEBUG=true 時用 error_log 記錄請求/回應摘要
  不記錄完整 apiKey（只記錄前4碼）
```

### API 端點明細

| 方法 | 端點 | 用途 |
|------|------|------|
| create_session | POST /api/v1/trade/sessions/create | 建立結帳交易 |
| query_session | POST /api/v1/trade/sessions/query | 查詢結帳交易狀態 |
| query_payment | POST /api/v1/trade/payment/get | 查詢付款交易（含 ATM 虛擬帳號） |
| create_refund | POST /api/v1/trade/refund/create | 建立退款 |
| query_refund | POST /api/v1/trade/refund/get | 查詢退款狀態 |

### 驗收條件
- [ ] create_session 成功回傳含 sessionId + sessionUrl
- [ ] query_session 正確回傳狀態
- [ ] query_payment 回傳含 virtualAccount（ATM 場景）
- [ ] test_connection 正確判斷金鑰有效性
- [ ] 網路超時時回傳 false 不 fatal
- [ ] WP_DEBUG 下有日誌但不洩漏完整金鑰

---

## 元件 D：請求組裝器 (includes/class-request-builder.php)

### 行為
- 從 CF7 posted_data + 表單付款設定 + 欄位映射 → 組裝完整 SLP API request body
- 處理姓名拆分、電話格式、預設值填充

### 介面契約
```php
class MXP_SLP_Request_Builder {
    public static function build_session_request(
        int $form_id,
        array $posted_data,
        string $order_token,
        string $return_url
    ): array;
    
    public static function get_field_mapping(int $form_id): array;
    public static function auto_detect_mapping(int $form_id): array;
}
```

### 技術規格
```
自動偵測規則（正則匹配表單 tag name）:
  /email/i → email
  /name|姓名/i → full_name（拆分為 firstName + lastName）
  /tel|phone|電話/i → phone
  /address|地址/i → street

姓名拆分邏輯:
  中文（全部非 ASCII）: 第一字=lastName, 其餘=firstName
  英文（含空格）: 最後一段=lastName, 其餘=firstName
  單字: lastName=全名, firstName=空字串

電話格式化:
  09xxxxxxxx → +886 9xxxxxxxx
  已有 + 開頭 → 不處理
  其他 → 原樣傳入

簡易模式預設值:
  shipping.shippingMethod = "數位商品"
  shipping.carrier = "電子郵件"
  shipping.address = {countryCode: "TW", street: "數位商品無需寄送"}
  billing.address = {countryCode: "TW", street: "線上交易"}
  customer.referenceCustomerId = md5(email) 或 "guest_" + uniqid()
  client.ip = sanitized REMOTE_ADDR
```

### 驗收條件
- [ ] 中文姓名「王小明」→ lastName=王, firstName=小明
- [ ] 英文姓名「John Smith」→ lastName=Smith, firstName=John
- [ ] 電話「0912345678」→ +886912345678
- [ ] 簡易模式只有 email 時能組裝完整 request body
- [ ] 組裝結果可直接傳入 API create_session 成功

---

## 元件 E：Webhook 處理器 (includes/class-webhook.php)

### 行為
- 註冊 REST API 端點
- 驗證 HMAC-SHA256 簽章
- 路由事件到對應 handler
- 更新訂單狀態
- 觸發郵件（異步）

### 介面契約
```php
class MXP_SLP_Webhook {
    public function register_routes(): void;
    public function handle_webhook(WP_REST_Request $request): WP_REST_Response;
    
    private function verify_signature(string $body, string $timestamp, string $sign): bool;
    private function handle_session_success(array $data): void;
    private function handle_session_expired(array $data): void;
}
```

### 技術規格
```
端點: POST /wp-json/mxp-cf7-slp/v1/webhook
Permission: __return_true（公開，內部簽章驗證）

簽章驗證:
  $body = $request->get_body()  // 原始 body，不 decode
  $timestamp = $request->get_header('timestamp')
  $sign = $request->get_header('sign')
  $payload = $timestamp . '.' . $body
  $expected = hash_hmac('sha256', $payload, $sign_key)
  hash_equals($expected, $sign)

時間窗口: abs(time() - intval($timestamp)/1000) < 300

冪等性:
  $event_id = $body_decoded['id']
  $key = '_slp_evt_' . substr(md5($event_id), 0, 16)
  if (get_option($key)) → return 200（已處理）
  處理完成後 update_option($key, time())

郵件觸發（異步）:
  先回傳 200 給 SLP
  用 register_shutdown_function() 或 wp_schedule_single_event(time(), ...) 發郵件
  
  推薦做法：shutdown function（立即執行但不阻塞回應）
  注意：WordPress REST API 回應後 shutdown function 仍會執行
```

### 事件處理明細

| Event Type | Handler | 動作 |
|-----------|---------|------|
| session.succeeded | handle_session_success | 更新狀態→SUCCEEDED, 記錄 paymentDetails, 觸發郵件 |
| session.expired | handle_session_expired | 更新狀態→EXPIRED |
| session.pending | (忽略) | 記錄 log |
| trade.succeeded | (備用) | 若 session 事件未到，用此更新 |
| 其他 | (忽略) | 記錄 log, 回傳 200 |

### 驗收條件
- [ ] 正確簽章 → 200 + 狀態更新
- [ ] 錯誤簽章 → 401
- [ ] 過期 timestamp（>5min）→ 401
- [ ] 重複 event id → 200（不重複處理）
- [ ] session.succeeded → 訂單狀態變 SUCCEEDED + 郵件發送
- [ ] 端點回應時間 < 5 秒
- [ ] 郵件發送失敗不影響 200 回應

---

## 元件 F：表單標籤 (includes/class-form-tag.php)

### 行為
- 註冊 [shopline_payment] form tag
- 渲染前端 HTML（付款按鈕，取代 [submit]）

### 技術規格
```php
// 註冊（在 wpcf7_init hook 中）
wpcf7_add_form_tag('shopline_payment', 'mxp_slp_form_tag_handler', [
    'display-block' => true,
    'singular' => true,
]);

// Tag 語法
[shopline_payment "前往付款"]
// values[0] = 按鈕文字（可選，預設「前往付款」）
```

### 渲染 HTML
```html
<div class="wpcf7-shopline-payment">
  <div class="slp-product-summary">
    <span class="slp-product-name"></span>
    <span class="slp-amount-display"></span>
  </div>
  <button type="submit" class="wpcf7-form-control wpcf7-submit slp-submit-btn">
    <span class="slp-btn-text">前往付款</span>
    <span class="slp-btn-spinner" aria-hidden="true" style="display:none">處理中...</span>
  </button>
  <input type="hidden" name="_slp_form_payment" value="1" />
</div>
```

### 注意事項
- button type=submit 讓此按鈕觸發 CF7 表單提交
- 如果表單同時有 [submit]，用 CSS 隱藏：`.wpcf7-shopline-payment ~ .wpcf7-submit { display: none; }`
- slp-product-summary 由前端 JS 用 AJAX 填充（快取安全）

### 驗收條件
- [ ] 表單中插入 [shopline_payment] 後前端顯示按鈕
- [ ] 按鈕點擊觸發 CF7 表單提交流程
- [ ] 同一表單有 [submit] 時只顯示付款按鈕
- [ ] singular=true：同一表單第二個 [shopline_payment] 不渲染

---

## 元件 G：表單提交處理器 (includes/class-form-handler.php)

### 行為
- 攔截含付款標籤的表單提交
- 驗證欄位 + 建立 SLP Session
- 儲存訂單資料
- 設定 payment_required 狀態 + 回傳 session_url

### 介面契約
```php
class MXP_SLP_Form_Handler {
    public function __construct();  // 註冊 hooks
    public function handle_before_send_mail($contact_form, &$abort, $submission): void;
}
```

### 技術規格
```
Hook: wpcf7_before_send_mail (priority 10)

流程:
1. $tags = $contact_form->scan_form_tags(['type' => 'shopline_payment'])
   if empty → return（非付款表單）

2. 讀取付款設定: get_post_meta($contact_form->id(), '_slp_payment_settings', true)

3. 欄位驗證（透過 Request Builder）:
   - email/phone 至少一個
   - lastName 不為空
   驗證失敗 → $submission->set_status('validation_failed') + return

4. Rate limit 檢查:
   失敗 → $submission->set_status('validation_failed') + set_response('請求過於頻繁')

5. 產生 order_token: wp_generate_password(32, false, false)

6. 組裝 API request: MXP_SLP_Request_Builder::build_session_request(...)

7. 呼叫 API: $api->create_session($request_body)
   失敗 → $submission->set_status('aborted') + set_response('付款服務暫時無法使用')

8. 儲存訂單:
   update_option('_slp_order_' . $token, [...], false)  // autoload=no

9. 設定結果:
   $abort = true
   $submission->set_status('payment_required')
   $submission->set_response('正在導向付款頁面...')
   $submission->add_result_props([
       'shopline_payment' => [
           'session_url' => $result['sessionUrl'],
           'order_token' => $token,
       ]
   ])
```

### 訂單資料結構
```php
[
    'token'           => $token,
    'session_id'      => $result['sessionId'],
    'reference_id'    => $token,  // referenceId = token
    'form_id'         => $contact_form->id(),
    'form_title'      => $contact_form->title(),
    'posted_data'     => $submission->get_posted_data(),
    'amount'          => $payment_settings['amount'],
    'currency'        => 'TWD',
    'status'          => 'CREATED',
    'payment_method'  => null,  // Webhook 回填
    'trade_order_id'  => null,  // Webhook 回填
    'mail_sent'       => false,
    'retry_count'     => 0,
    'created_at'      => time(),
    'updated_at'      => time(),
]
```

### 驗收條件
- [ ] 非付款表單不受影響（正常發送郵件）
- [ ] 付款表單提交後 status=payment_required
- [ ] apiResponse 中含 session_url
- [ ] 訂單資料正確儲存到 wp_options
- [ ] API 呼叫失敗時顯示友善錯誤訊息
- [ ] Rate limit 超過時拒絕提交

---

## 元件 H：郵件處理器 (includes/class-mail-handler.php)

### 行為
- 付款成功後觸發 CF7 郵件發送
- 用 filter 注入 posted_data 替換 mail tags
- 冪等：不重複發送

### 介面契約
```php
class MXP_SLP_Mail_Handler {
    public static function send_payment_confirmation(string $order_token): bool;
}
```

### 技術規格
```
流程:
1. 讀取訂單: get_option('_slp_order_' . $token)
2. 檢查 mail_sent → true 則 return false（冪等）
3. 載入表單: wpcf7_contact_form($order['form_id'])
4. 取得 mail property: $contact_form->prop('mail')

5. 註冊 filter 注入 posted_data:
   add_filter('wpcf7_mail_tag_replaced', function($replaced, $submitted, $html, $mail_tag) {
       if (null === $replaced || '' === $replaced) {
           $field_name = $mail_tag->field_name();
           if (isset($this->posted_data[$field_name])) {
               $value = $this->posted_data[$field_name];
               if (is_array($value)) $value = implode(', ', $value);
               return $html ? esc_html($value) : $value;
           }
       }
       return $replaced;
   }, 10, 4);

6. 註冊 special mail tags filter（注入 _slp_* tags）

7. 呼叫 WPCF7_Mail::send($mail_prop, 'mail')
8. 若 mail_2 active → WPCF7_Mail::send($mail_2_prop, 'mail_2')

9. 移除 filters（避免影響其他郵件）
10. 更新訂單: mail_sent = true, updated_at = time()

11. 觸發 action: do_action('mxp_slp_payment_confirmed', $order_token, $order)
```

### 注意事項
- WPCF7_Mail::send() 內部會呼叫 wp_mail()
- filter 必須在 send 之前註冊，send 之後移除
- 若 WPCF7_Mail 類別不存在（CF7 被停用）→ fallback 用 wp_mail 直接發送簡易通知

### 驗收條件
- [ ] 付款成功後收到郵件
- [ ] 郵件中 [your-name]、[your-email] 等 tag 正確替換
- [ ] 郵件中 [_slp_amount] 等 special tag 正確替換
- [ ] mail_2（自動回覆）也正確發送
- [ ] 同一訂單呼叫兩次只發一封信
- [ ] CF7 被停用時 fallback 郵件仍能發送

---

## 元件 I：Return Page (includes/class-return-page.php)

### 行為
- 註冊 [slp_return_page] shortcode
- 顯示付款結果（訂單摘要 + 狀態）
- 主動查詢 SLP 確認狀態
- ATM 顯示虛擬帳號
- 提供重試按鈕

### 介面契約
```php
class MXP_SLP_Return_Page {
    public function register_shortcode(): void;
    public function render_return_page(): string;
    
    // REST endpoints
    public function register_routes(): void;
    public function get_order_status(WP_REST_Request $request): WP_REST_Response;
    public function retry_payment(WP_REST_Request $request): WP_REST_Response;
}
```

### REST 端點

| 方法 | 端點 | 用途 | 認證 |
|------|------|------|------|
| GET | /mxp-cf7-slp/v1/order-status?token=xxx | 查詢訂單狀態 | 無（token 即認證） |
| POST | /mxp-cf7-slp/v1/retry-payment | 重新付款 | 無（token + 限制） |

### order-status 回應格式
```json
{
  "status": "SUCCEEDED|CREATED|PENDING|EXPIRED|FAILED",
  "amount": 401,
  "currency": "TWD",
  "form_title": "商品購買表單",
  "customer_email_masked": "t***@example.com",
  "payment_method": "CreditCard",
  "virtual_account": null | {
    "bank_code": "812",
    "account_number": "12345678901234",
    "due_date": "2026/06/01",
    "due_date_desc": "請於 2026年6月1日 前完成轉帳"
  }
}
```

### 技術規格
```
order-status 流程:
1. 從 query param 取得 token
2. 讀取訂單 option
3. 若 status=CREATED → 呼叫 SLP sessions/query 確認
   - SLP 回傳 SUCCEEDED → 更新本地狀態 + 觸發郵件
   - SLP 回傳含 paymentDetails → 記錄 tradeOrderId
4. 若 paymentMethod=VirtualAccount 且需要帳號資訊:
   - 呼叫 query_payment(tradeOrderId) 取得 virtualAccount
5. 回傳狀態 + 摘要資訊

retry-payment 流程:
1. 驗證 token 存在且訂單狀態為 EXPIRED/FAILED
2. 檢查 retry_count < 3
3. 用原始 posted_data 建立新 Session
4. 建立新訂單 option（關聯原始 token）
5. 回傳新的 session_url
6. 原訂單 retry_count++

前端 JS (return-page.js):
- 頁面載入 → fetch order-status
- status=CREATED/PENDING → 每 3 秒 polling，最多 60 次
- status=SUCCEEDED → 顯示成功 UI
- status=EXPIRED/FAILED → 顯示失敗 + 重試按鈕
- 重試按鈕 → fetch retry-payment → location.href = session_url
```

### 驗收條件
- [ ] 付款成功回到頁面顯示「付款成功」+ 訂單摘要
- [ ] ATM 轉帳顯示虛擬帳號、銀行代碼、截止日期
- [ ] 付款處理中顯示 spinner + polling
- [ ] 付款失敗顯示「重新付款」按鈕
- [ ] 重新付款正確建立新 Session 並導轉
- [ ] 重試超過 3 次顯示「請聯繫客服」
- [ ] 無效 token 顯示 404 頁面
- [ ] Email 部分遮罩顯示

---

## 元件 J：安全性 (includes/class-security.php)

### 介面契約
```php
class MXP_SLP_Security {
    // 金鑰加密
    public static function encrypt(string $value): string;
    public static function decrypt(string $encrypted): string;
    
    // Rate limiting
    public static function check_rate_limit(string $ip, int $max = 5, int $window = 60): bool;
    
    // 訂單 token
    public static function generate_order_token(): string;
    
    // 金額驗證
    public static function validate_amount(int $amount, int $max = 10000000): bool;
}
```

### 技術規格
```
加密:
  method: aes-256-cbc
  key: substr(hash('sha256', AUTH_KEY), 0, 32)
  iv: random_bytes(16), prepend to ciphertext
  format: base64_encode(iv + ciphertext)
  fallback: 若 openssl 不可用 → 明文儲存 + admin warning

Rate limit:
  key: '_slp_rate_' . md5($ip)
  storage: transient (TTL = $window seconds)
  邏輯: get → increment → 超過 $max return false

Token:
  wp_generate_password(32, false, false)
  驗證唯一性: get_option('_slp_order_' . $token) === false

金額驗證:
  $amount > 0
  $amount <= $max (預設 100000 TWD = 10000000 分)
  is_int($amount)
```

### 驗收條件
- [ ] 加密後的值無法直接讀取原文
- [ ] 解密後還原正確
- [ ] 同 IP 第 6 次請求被拒絕
- [ ] 60 秒後計數重置
- [ ] 產生的 token 為 32 字元英數字
- [ ] 金額 0 或負數被拒絕

---

## 元件 K：全域設定頁 (admin/class-settings.php)

### 行為
- 註冊設定頁面（CF7 子選單）
- 金鑰管理 + 環境切換 + 連線測試
- Webhook URL 顯示
- 極簡交易記錄

### 技術規格
```
Menu:
  add_submenu_page('wpcf7', 'Shopline Payment', 'Shopline Payment', 'manage_options', 'mxp-slp-settings', callback)

Settings API:
  option_group: 'mxp_slp_settings'
  option_name: 'mxp_slp_settings'
  
Sections:
  1. 連線設定（environment, merchant_id, api_key, sign_key, client_key）
  2. 預設付款方式（6 種 checkbox + 分期）
  3. Webhook 資訊（唯讀顯示 URL + 複製按鈕）
  4. 交易記錄（最近 20 筆 table）

AJAX endpoints:
  POST /mxp-cf7-slp/v1/admin/test-connection → 測試 API 連線
  需要: nonce + manage_options capability

交易記錄查詢:
  從 wp_options 中 LIKE '_slp_order_%' 取得
  按 created_at DESC 排序，取 20 筆
  注意：大量訂單時效能問題 → Phase 2 遷移到 CPT 解決
```

### 驗收條件
- [ ] 設定頁面在 CF7 選單下可見
- [ ] 金鑰輸入後儲存（加密）
- [ ] 環境切換正確切換 URL
- [ ] 連線測試按鈕即時回饋結果
- [ ] Webhook URL 正確顯示且可複製
- [ ] 交易記錄顯示最近交易

---

## 元件 L：CF7 付款 Tab (admin/class-payment-panel.php)

### 行為
- 在 CF7 表單編輯頁加入「付款」Tab
- 提供付款設定 UI（金額、方式、映射、簡易模式）

### 技術規格
```php
// 註冊 Tab
add_filter('wpcf7_editor_panels', function($panels) {
    $panels['payment-panel'] = [
        'title'    => __('付款', 'mxp-cf7-slp'),
        'callback' => [$this, 'render_panel'],
    ];
    return $panels;
});

// 儲存設定
add_action('wpcf7_save_contact_form', [$this, 'save_payment_settings']);
```

### Panel UI 結構
```
☑ 啟用付款功能

金額設定:
  ◉ 固定金額: [___] TWD
  ○ 從表單欄位讀取: [dropdown: 選擇欄位]

付款方式:
  ☑ 信用卡  ☑ Apple Pay  ☑ LINE Pay
  ☑ ATM轉帳  ☑ 街口支付  ☑ 中租零卡
  
  信用卡分期: ☑不分期 ☑3期 ☑6期 ☐9期 ☐12期 ☐18期 ☐24期
  中租分期:   ☑不分期 ☑3期 ☑6期 ☐9期 ☐12期

簡易模式:
  ☑ 數位商品模式（僅需 Email 即可付款）

欄位映射:
  Email: [your-email ✓ 自動偵測]
  姓名: [your-name ✓ 自動偵測]
  電話: [未偵測到 - 選擇欄位 ▼]
  地址: [未偵測到 - 選擇欄位 ▼]（簡易模式下隱藏）

按鈕文字: [前往付款]
```

### 儲存格式
```php
// post_meta key: '_slp_payment_settings'
[
    'enabled'            => true,
    'amount'             => 401,
    'amount_field'       => '',  // 或 'payment-amount'
    'currency'           => 'TWD',
    'payment_methods'    => ['CreditCard', 'ApplePay', 'LinePay', 'VirtualAccount', 'JKOPay', 'ChaileaseBNPL'],
    'cc_installments'    => ['0', '3', '6'],
    'bnpl_installments'  => ['0', '3', '6'],
    'simple_mode'        => true,
    'field_mapping'      => [
        'email' => 'your-email',
        'name'  => 'your-name',
        'phone' => '',
        'address' => '',
    ],
    'button_text'        => '前往付款',
]
```

### 驗收條件
- [ ] CF7 表單編輯頁出現「付款」Tab
- [ ] 勾選啟用後展開設定區塊
- [ ] 金額輸入正確儲存
- [ ] 付款方式勾選正確儲存
- [ ] 簡易模式勾選後地址映射隱藏
- [ ] 欄位映射自動偵測結果正確顯示
- [ ] 儲存表單時付款設定一併儲存
- [ ] 未設定全域金鑰時顯示提示連結

---

## 元件 M：Onboarding (admin/class-onboarding.php)

### 行為
- 未設定金鑰時顯示全站 admin notice
- 設定完成後消失

### 技術規格
```
顯示條件: 
  is_admin() && current_user_can('manage_options') && !apiKey_configured

Notice 內容:
  "SHOPLINE Payments 已啟用！請先設定 API 金鑰以開始收款。[前往設定]"
  type: 'info', dismissible: false

消除條件:
  mxp_slp_settings['sandbox_api_key'] 或 mxp_slp_settings['live_api_key'] 非空
```

### 驗收條件
- [ ] 啟用外掛後立即看到引導 notice
- [ ] 點擊連結跳轉到設定頁
- [ ] 設定金鑰後 notice 消失
- [ ] 非管理員看不到 notice

---

## 元件 N：前端 JS (assets/js/frontend.js)

### 行為
- 監聽 wpcf7submit 事件
- 偵測 payment_required 狀態
- 執行導轉（含 spinner 和 history 處理）

### 技術規格
```javascript
document.addEventListener('DOMContentLoaded', function() {
    // 填充金額顯示（AJAX，快取安全）
    document.querySelectorAll('.wpcf7-shopline-payment').forEach(initWidget);
});

document.addEventListener('wpcf7submit', function(event) {
    if (event.detail.status !== 'payment_required') return;
    
    const slp = event.detail.apiResponse.shopline_payment;
    if (!slp || !slp.session_url) return;
    
    // 儲存 token 到 sessionStorage
    const formId = event.detail.contactFormId;
    sessionStorage.setItem('slp_order_' + formId, JSON.stringify({
        token: slp.order_token,
        timestamp: Date.now()
    }));
    
    // 防止 back button 重複提交
    history.replaceState({slp_submitted: true}, '');
    
    // 更新按鈕文字
    const btn = event.target.querySelector('.slp-submit-btn');
    if (btn) {
        btn.querySelector('.slp-btn-text').style.display = 'none';
        btn.querySelector('.slp-btn-spinner').style.display = 'inline-block';
        btn.querySelector('.slp-btn-spinner').textContent = '正在跳轉到付款頁面...';
    }
    
    // 導轉（SLP 要求用 location.href）
    window.location.href = slp.session_url;
});

function initWidget(el) {
    // 未來 Phase 2 用：AJAX 取得商品資料填充金額顯示
    // Phase 1：金額從 server-side render 的 data attribute 讀取
}
```

### 驗收條件
- [ ] 表單提交後按鈕變為「處理中...」
- [ ] payment_required 狀態正確觸發導轉
- [ ] 導轉前 sessionStorage 儲存 token
- [ ] history.replaceState 執行
- [ ] 非付款表單不受影響
- [ ] session_url 為空時不導轉（顯示錯誤）

---

## 元件 O：Return Page JS (assets/js/return-page.js)

### 技術規格
```javascript
document.addEventListener('DOMContentLoaded', async function() {
    const container = document.querySelector('.slp-return-page');
    if (!container) return;
    
    const token = new URLSearchParams(window.location.search).get('token');
    if (!token) { showError('無效的訂單連結'); return; }
    
    await pollStatus(token, container);
});

async function pollStatus(token, container, attempt = 0) {
    const maxAttempts = 60;
    const interval = 3000;
    
    const res = await fetch(
        `${slpReturnPage.apiRoot}mxp-cf7-slp/v1/order-status?token=${token}`,
        { credentials: 'same-origin' }
    );
    const data = await res.json();
    
    if (data.status === 'SUCCEEDED') {
        showSuccess(data, container);
    } else if (data.status === 'EXPIRED' || data.status === 'FAILED') {
        showFailed(data, container, token);
    } else if (attempt < maxAttempts) {
        showPending(container);
        setTimeout(() => pollStatus(token, container, attempt + 1), interval);
    } else {
        showTimeout(container);
    }
}

function showSuccess(data, container) {
    // 顯示：✓ 付款成功 + 訂單摘要 + email
}

function showFailed(data, container, token) {
    // 顯示：✗ 付款未完成 + 重試按鈕
    // 重試按鈕 click → POST retry-payment
}

function showPending(container) {
    // 顯示：⏳ 付款處理中... + spinner
}
```

### 驗收條件
- [ ] 成功狀態顯示綠色 ✓ + 摘要
- [ ] ATM 場景顯示虛擬帳號資訊
- [ ] 處理中顯示 spinner + 自動 polling
- [ ] 失敗顯示重試按鈕
- [ ] 重試按鈕點擊後導轉到新付款頁
- [ ] 無效 token 顯示錯誤訊息
- [ ] Polling 超時顯示「請查看信箱確認」

---

## 整合測試場景

### 場景 1：信用卡付款成功（完整流程）
```
1. 管理員：設定金鑰 → CF7 表單付款 Tab 設定金額 $401 + 信用卡
2. 消費者：填寫表單 → 點擊「前往付款」
3. 預期：按鈕變 spinner → 導轉到 SLP 付款頁
4. 消費者：在 SLP 頁面輸入測試卡號 4147633700198405
5. 預期：付款成功 → 導轉回 return page → 顯示「付款成功」
6. 預期：管理員收到通知信（含表單資料 + 付款資訊）
7. 預期：交易記錄頁顯示此筆交易
```

### 場景 2：ATM 轉帳（等待付款）
```
1. 設定：金額 $401 + ATM 轉帳
2. 消費者：提交 → 導轉 SLP → 選擇 ATM → 取得虛擬帳號
3. 預期：回到 return page → 顯示虛擬帳號 + 截止日期 + 「處理中」
4. 模擬：在沙盒頁面點擊「付款成功」
5. 預期：Webhook 到達 → 狀態更新 → 郵件發送
```

### 場景 3：付款失敗 + 重試
```
1. 設定：金額 $400（沙盒規則：雙數=失敗）
2. 消費者：提交 → 導轉 SLP → 付款失敗
3. 預期：回到 return page → 顯示「付款未完成」+ 重試按鈕
4. 消費者：點擊重試
5. 預期：導轉到新的 SLP 付款頁（金額仍為 $400）
```

### 場景 4：簡易模式（僅 email）
```
1. 設定：金額 $401 + 簡易模式 + 全部付款方式
2. 表單：只有 [email* your-email] + [shopline_payment]
3. 消費者：填 email → 點擊付款
4. 預期：成功建立 Session（預設值填充）→ 導轉 SLP
5. 預期：SLP 頁面顯示所有 6 種付款方式
```

### 場景 5：Webhook 先到 vs Return Page 先到
```
Case A（Webhook 先到）:
  1. 付款成功 → Webhook 到達 → 狀態更新 + 郵件發送
  2. 消費者回到 return page → 查詢狀態 = SUCCEEDED → 顯示成功
  3. 不重複發信（mail_sent=true）

Case B（Return Page 先到）:
  1. 付款成功 → 消費者回到 return page
  2. 查詢 SLP API → 確認 SUCCEEDED → 更新狀態 + 發信
  3. Webhook 稍後到達 → 檢查 mail_sent=true → 跳過
```

### 場景 6：安全性測試
```
1. 前端修改金額 → Server 仍用 DB 中的金額建立 Session ✓
2. 直接存取 return page（無 token）→ 顯示錯誤 ✓
3. 偽造 Webhook（錯誤簽章）→ 401 ✓
4. 重複 Webhook（相同 event id）→ 200 但不重複處理 ✓
5. 快速連續提交 6 次 → 第 6 次被 rate limit 擋 ✓
```

---

## 快速參考：SLP 沙盒測試規則

```
信用卡測試卡號:
  Visa:       4147633700198405  (03/30, CVV 638)
  MasterCard: 5149147700000300  (03/30, CVV 231)
  JCB:        3565586700000200  (03/30, CVV 484)

金額規則（去掉末尾 00 後）:
  3 的倍數 → 進入 3D 驗證（沙盒模擬頁面）
  非 3 倍數 + 單數 → 成功
  非 3 倍數 + 雙數 → 失敗

範例:
  $401 (40100) → 401 非3倍數, 401是單數 → 成功 ✓
  $400 (40000) → 400 非3倍數, 400是雙數 → 失敗 ✗
  $300 (30000) → 300 是3倍數 → 進入 3D
  $301 (30100) → 301 非3倍數, 301是單數 → 成功 ✓

沙盒帳號:
  merchantId: 2652289079513847808
  apiKey: sk_sandbox_94a12542fff146c5833d6b78f46fb669
  後台: https://login.shoplinepayments.com/zh-Hant/signin/
  帳號: slpsandbox2@shopline.com
  密碼: shoplinePayments123.
```

---

## 元件依賴圖

```
mxp-cf7-shopline-payment.php
  └── class-loader.php
        ├── class-security.php ←──────────────────────┐
        ├── class-api.php ←────────────────────┐      │
        ├── class-request-builder.php          │      │
        ├── class-webhook.php ─────────────────┤──────┤
        │     └── uses: class-mail-handler.php │      │
        ├── class-mail-handler.php ────────────┘      │
        ├── class-form-tag.php                        │
        ├── class-form-handler.php ───────────────────┤
        │     └── uses: api, request-builder, security│
        ├── class-return-page.php                     │
        │     └── uses: api, mail-handler             │
        └── [admin only]                              │
              ├── class-settings.php ─────────────────┘
              ├── class-payment-panel.php
              ├── class-service.php
              └── class-onboarding.php
```

---

## 文件清單

| 文件 | 用途 |
|------|------|
| DEVELOPMENT_PLAN.md | 整體架構、設計決策、開發順序、專家建議 |
| COMPONENT_SPEC.md | 每個元件的介面、行為、技術細節、驗收條件 |
| contact-form-7/ | CF7 原始碼參考（已研究完成） |

---

## 各角色視角補充需求

### 補充項目清單

| # | 角色 | 缺口 | 修正 | 歸屬元件 |
|---|------|------|------|---------|
| P1 | 安裝者 | 不知道怎麼申請 SLP 帳號 | 設定頁加「如何取得金鑰」連結+說明 | K (Settings) |
| P2 | 管理員 | 交易失敗不知道原因 | 訂單資料儲存 error msg，記錄頁顯示 | K (Settings) |
| P3 | 管理員 | 不知道 Webhook 是否正常 | 記錄最後接收時間+次數，設定頁顯示 | E (Webhook) + K |
| P4 | 管理員 | Phase 1 無法退款 | 交易記錄加「前往 SLP 後台處理」連結 | K (Settings) |
| P5 | 消費者 | 提交前不知道有哪些付款方式 | 按鈕下方顯示付款方式文字列表 | F (Form Tag) |
| P6 | 消費者 | 成功頁沒說確認信已發送 | 成功狀態加「確認信已發送到您的信箱」 | I (Return Page) |
| P7 | 消費者 | 成功頁沒有回到表單的連結 | 加「繼續購物」連結（回到表單所在頁面） | I (Return Page) |
| P8 | SLP 窗口 | 不知道要訂閱哪些事件 | Webhook URL 旁列出事件清單 | K (Settings) |
| P9 | 消費者 | 手機上可能跑版 | CSS 確保按鈕和 return page responsive | N+O (CSS) |

---

### P1：設定頁「如何取得金鑰」

在設定頁金鑰輸入區上方加入：
```html
<div class="slp-help-box">
  <h4>如何取得金鑰？</h4>
  <ol>
    <li>前往 <a href="https://www.shoplinepayments.com/" target="_blank">SHOPLINE Payments 官網</a> 註冊帳號</li>
    <li>完成特店審核後，登入 <a href="https://login.shoplinepayments.com/" target="_blank">Payment Center</a></li>
    <li>進入「設定 → 開發者管理 → 金鑰管理」取得 API Key</li>
    <li>在「Webhook 管理」中新增 Webhook，填入下方 URL</li>
  </ol>
</div>
```

### P2：交易記錄顯示錯誤原因

訂單資料結構新增欄位：
```php
'error_code' => null,    // SLP 回傳的錯誤碼
'error_msg'  => null,    // SLP 回傳的錯誤描述
```

交易記錄表格新增「備註」欄位：
- 成功 → 顯示付款方式
- 失敗 → 顯示錯誤原因（如「風控拒絕」「卡號無效」）
- 逾期 → 顯示「顧客未完成付款」

### P3：Webhook 健康狀態

在 Webhook 處理器中記錄：
```php
update_option('_slp_webhook_last_received', [
    'time'     => time(),
    'event'    => $event_type,
    'success'  => true,
], false);

// 計數器
$count = get_option('_slp_webhook_total_count', 0);
update_option('_slp_webhook_total_count', $count + 1, false);
```

設定頁 Webhook 區塊顯示：
```
Webhook 狀態：
  最後接收：2026/05/29 21:30（3 分鐘前）✅
  累計接收：42 次
  
  ⚠️ 若超過 24 小時未收到通知，請確認 URL 設定是否正確
```

### P4：交易記錄「前往 SLP 後台」連結

在交易記錄的操作欄加入：
```html
<a href="https://login.shoplinepayments.com/" target="_blank" class="button-small">
  前往 SLP 後台處理
</a>
```

### P5：付款方式預覽

Form Tag Handler 渲染的 HTML 補充：
```html
<div class="wpcf7-shopline-payment">
  <div class="slp-product-summary">
    <span class="slp-amount-display">NT$ 401</span>
  </div>
  <div class="slp-payment-methods-preview">
    <span class="slp-method">信用卡</span>
    <span class="slp-method">LINE Pay</span>
    <span class="slp-method">街口支付</span>
    <!-- 根據表單付款設定動態產生 -->
  </div>
  <button type="submit" class="wpcf7-form-control wpcf7-submit slp-submit-btn">
    ...
  </button>
</div>
```

付款方式中文名對照：
```php
$method_labels = [
    'CreditCard'    => '信用卡',
    'ApplePay'      => 'Apple Pay',
    'LinePay'       => 'LINE Pay',
    'VirtualAccount'=> 'ATM 轉帳',
    'JKOPay'        => '街口支付',
    'ChaileaseBNPL' => '中租零卡分期',
];
```

### P6：成功頁「確認信已發送」

Return page 成功狀態 HTML：
```html
<div class="slp-status-success">
  <span class="slp-icon">✓</span>
  <h2>付款成功</h2>
  <p class="slp-email-notice">確認信已發送至 <strong>t***@example.com</strong></p>
  <div class="slp-order-summary">...</div>
</div>
```

### P7：「繼續購物」連結

Return page 底部加入：
```html
<div class="slp-actions">
  <a href="{表單所在頁面 URL}" class="slp-back-link">← 回到表單頁面</a>
</div>
```

表單所在頁面 URL 來源：訂單資料中儲存 `referer_url`（從 submission meta 的 url 取得）。

訂單資料結構新增：
```php
'referer_url' => $submission->get_meta('url'),  // 表單所在頁面
```

### P8：Webhook 事件清單

設定頁 Webhook URL 區塊補充：
```html
<div class="slp-webhook-info">
  <p><strong>Webhook URL：</strong></p>
  <code>https://yoursite.com/wp-json/mxp-cf7-slp/v1/webhook</code> [複製]
  
  <p><strong>請訂閱以下事件：</strong></p>
  <ul>
    <li><code>session.succeeded</code> — 結帳成功（必要）</li>
    <li><code>session.expired</code> — 結帳逾期（必要）</li>
    <li><code>trade.succeeded</code> — 付款成功（建議）</li>
    <li><code>trade.failed</code> — 付款失敗（建議）</li>
  </ul>
</div>
```

### P9：RWD 響應式設計

```css
/* 付款按鈕 */
.wpcf7-shopline-payment {
  max-width: 100%;
}
.slp-submit-btn {
  width: 100%;
  padding: 12px 24px;
  font-size: 16px; /* 防止 iOS 自動放大 */
}
.slp-payment-methods-preview {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

/* Return page */
.slp-return-page {
  max-width: 600px;
  margin: 0 auto;
  padding: 20px;
}
.slp-virtual-account {
  background: #f9f9f9;
  padding: 16px;
  border-radius: 8px;
  word-break: break-all; /* 長帳號不溢出 */
}

@media (max-width: 480px) {
  .slp-return-page { padding: 12px; }
  .slp-submit-btn { padding: 14px 20px; }
}
```

---

### 更新後的驗收條件補充

- [ ] 設定頁有「如何取得金鑰」說明
- [ ] 交易失敗時記錄頁顯示錯誤原因
- [ ] 設定頁顯示 Webhook 最後接收時間
- [ ] 交易記錄有「前往 SLP 後台」連結
- [ ] 付款按鈕下方顯示可用付款方式
- [ ] 成功頁顯示「確認信已發送至 xxx」
- [ ] 成功頁有「回到表單頁面」連結
- [ ] Webhook URL 旁列出需訂閱的事件
- [ ] 手機上按鈕和 return page 正常顯示（無溢出）

---

## 修正：訂單儲存改用 CPT

### 原設計
訂單存在 wp_options（key: `_slp_order_{token}`）

### 修正為
訂單使用獨立 CPT `slp_order`，所有交易資料存在 post_meta 中

### 原因
- wp_options LIKE 查詢無索引，大量訂單時效能差
- CPT 原生支援 WP_Query 排序、分頁、meta_query
- 後台列表頁可直接用 WP 的 edit.php UI（或自訂）
- 未來擴展（搜尋、篩選、匯出）更容易

### 訂單 CPT 規格

```php
register_post_type('slp_order', [
    'public'       => false,
    'show_ui'      => true,
    'show_in_menu' => 'mxp-slp-settings', // 在外掛設定下
    'supports'     => ['title'],  // title = SLP-0001
    'labels'       => [...],
    'capability_type' => 'page',  // 限制只有 manage_options 可操作
    'map_meta_cap' => true,
]);
```

### Post Meta 欄位

| Meta Key | 類型 | 說明 |
|----------|------|------|
| _slp_token | string(32) | 訂單 token（隨機，用於 returnUrl） |
| _slp_session_id | string | SLP Session ID |
| _slp_reference_id | string | 特店訂單號（= token） |
| _slp_form_id | int | CF7 表單 ID |
| _slp_posted_data | serialized array | 表單提交資料 |
| _slp_amount | int | 金額（元） |
| _slp_currency | string | TWD |
| _slp_status | string | CREATED/SUCCEEDED/EXPIRED/FAILED |
| _slp_payment_method | string | 實際付款方式（Webhook 回填） |
| _slp_trade_order_id | string | SLP Trade Order ID（Webhook 回填） |
| _slp_mail_sent | bool | 郵件是否已發送 |
| _slp_retry_count | int | 重試次數 |
| _slp_referer_url | string | 表單所在頁面 URL |
| _slp_error_code | string | 錯誤碼（失敗時） |
| _slp_error_msg | string | 錯誤描述（失敗時） |

### 查詢方式

```php
// 用 token 查找訂單
$orders = get_posts([
    'post_type'  => 'slp_order',
    'meta_key'   => '_slp_token',
    'meta_value' => $token,
    'numberposts' => 1,
]);

// 用 session_id 查找（Webhook 用）
$orders = get_posts([
    'post_type'  => 'slp_order',
    'meta_key'   => '_slp_session_id',
    'meta_value' => $session_id,
    'numberposts' => 1,
]);

// 最近 20 筆交易
$orders = get_posts([
    'post_type'   => 'slp_order',
    'numberposts' => 20,
    'orderby'     => 'date',
    'order'       => 'DESC',
]);
```

### 建立訂單

```php
$order_id = wp_insert_post([
    'post_type'   => 'slp_order',
    'post_title'  => 'SLP-' . str_pad($next_number, 4, '0', STR_PAD_LEFT),
    'post_status' => 'publish',
]);

update_post_meta($order_id, '_slp_token', $token);
update_post_meta($order_id, '_slp_session_id', $session_id);
// ... 其他 meta
```

### 對其他元件的影響

| 元件 | 變更 |
|------|------|
| G (Form Handler) | `update_option` → `wp_insert_post` + `update_post_meta` |
| E (Webhook) | `get_option` → `get_posts(['meta_key' => '_slp_session_id', ...])` |
| I (Return Page) | `get_option` → `get_posts(['meta_key' => '_slp_token', ...])` |
| H (Mail Handler) | `get_option` → `get_post_meta($order_id, ...)` |
| K (Settings) | 交易記錄改用 `WP_Query` 查詢 |

### 序號產生

```php
$next = (int) get_option('_slp_order_counter', 0) + 1;
update_option('_slp_order_counter', $next);
$title = 'SLP-' . str_pad($next, 4, '0', STR_PAD_LEFT);
```

---

## 修正：PHP 最低版本 8.0

### 影響
- Plugin Header: `Requires PHP: 8.0`
- 可使用 PHP 8.0+ 特性：
  - Named arguments
  - Match expression
  - Nullsafe operator (`?->`)
  - Union types
  - Constructor promotion
- 依賴檢查中 PHP 版本改為 8.0

### 不使用的特性（相容性考量）
- PHP 8.1 Enums（保持 8.0 相容）
- PHP 8.1 Fibers
- PHP 8.2 readonly classes
