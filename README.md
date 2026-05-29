# MXP CF7 Shopline Payment

讓 Contact Form 7 表單具備 SHOPLINE Payments 收款能力。台灣唯一的 CF7 在地金流外掛。

## 支援的付款方式

- 💳 信用卡（含分期 3/6/9/12/18/24 期）
- 🍎 Apple Pay
- 💚 LINE Pay
- 🏧 ATM 銀行轉帳
- 📱 街口支付
- 🏦 中租 zingla 銀角零卡分期

## 功能特色

- 在 CF7 表單編輯頁直接設定付款金額和方式（獨立「付款」Tab）
- 簡易模式：數位商品僅需 Email 即可收款
- 自動偵測表單欄位映射（姓名、Email、電話、地址）
- 付款成功自動發送 CF7 郵件通知（支援所有 mail tags）
- 後台訂單管理（列表、搜尋、篩選、詳情、退款）
- Webhook 即時狀態更新 + HMAC-SHA256 簽章驗證
- 付款失敗可一鍵重試（免重填表單）
- ATM 轉帳顯示虛擬帳號 + 複製按鈕
- CSV 訂單匯出
- 內嵌式 SDK 支援（信用卡/Apple Pay 頁內付款）
- 導轉式 + 內嵌式混合模式（自動降級）
- 完整的安全機制（金額驗證、Rate Limit、防重複提交）

## 系統需求

- WordPress 6.4+
- PHP 8.0+
- Contact Form 7 5.9+
- SHOPLINE Payments 特店帳號

## 安裝

1. 下載外掛並上傳到 `/wp-content/plugins/` 目錄
2. 在 WordPress 後台啟用外掛
3. 前往「聯絡表單 → Shopline Payment」設定 API 金鑰
4. 在 CF7 表單編輯頁的「付款」Tab 設定金額和付款方式
5. 在表單中插入 `[shopline_payment]` 標籤

## SHOPLINE Payments API 重要資訊

### 環境 URL

| 環境 | API URL |
|------|---------|
| 沙盒 | https://api-sandbox.shoplinepayments.com |
| 正式 | https://api.shoplinepayments.com |

### 金鑰類型

| 金鑰 | 用途 |
|------|------|
| API Key | Server-API 串接認證 |
| Client Key | SDK 前端串接認證 |
| Sign Key | Webhook Event 通知簽章驗證 |

### API 端點

| 端點 | 用途 |
|------|------|
| POST /api/v1/trade/sessions/create | 建立結帳交易（導轉式） |
| POST /api/v1/trade/sessions/query | 查詢結帳交易狀態 |
| POST /api/v1/trade/payment/create | 建立付款交易（SDK 內嵌式） |
| POST /api/v1/trade/payment/get | 查詢付款交易（含 ATM 虛擬帳號） |
| POST /api/v1/trade/refund/create | 建立退款 |

### Webhook 簽章驗證

```
payload = {timestamp}.{body}
sign = HMAC-SHA256(payload, signKey)
```

- Header: `timestamp`（毫秒）、`sign`（hex）
- 時間窗口：5 分鐘
- 回傳 200 表示處理成功（SLP 不再重試）
- 非 200 會重試 16 次

### 金額格式

- API 傳送：元 × 100（如 NT$401 傳 40100）
- 幣種：目前僅支援 TWD

### 沙盒測試規則

- 金額為 3 的倍數 → 進入 3D 驗證
- 非 3 倍數 + 去掉末尾 00 後為單數 → 成功
- 非 3 倍數 + 去掉末尾 00 後為雙數 → 失敗

### 付款方式代碼

| 代碼 | 名稱 |
|------|------|
| CreditCard | 信用卡/信用卡分期 |
| VirtualAccount | ATM 銀行轉帳 |
| ApplePay | Apple Pay |
| JKOPay | 街口支付 |
| LinePay | LINE Pay |
| ChaileaseBNPL | 中租 zingla 銀角零卡 |

### Session 狀態碼

| 狀態 | 說明 |
|------|------|
| CREATED | 建立 |
| PENDING | 處理中 |
| SUCCEEDED | 成功 |
| EXPIRED | 逾期 |

## 開發文件

- [DEVELOPMENT_PLAN.md](DEVELOPMENT_PLAN.md) — 架構設計、開發順序、專家建議
- [COMPONENT_SPEC.md](COMPONENT_SPEC.md) — 元件規格、介面契約、驗收條件

## 授權

GPL v2 or later
