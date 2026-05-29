=== MXP CF7 Shopline Payment ===
Contributors: mxp
Tags: contact form 7, payment, shopline, taiwan, credit card, line pay
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

讓 Contact Form 7 表單具備 SHOPLINE Payments 收款能力。

== Description ==

MXP CF7 Shopline Payment 是台灣唯一的 CF7 在地金流外掛，支援：

* 信用卡（含分期 3/6/9/12/18/24 期）
* Apple Pay
* LINE Pay
* ATM 銀行轉帳
* 街口支付
* 中租 zingla 銀角零卡分期

= 特色 =

* 在 CF7 表單編輯頁直接設定固定金額、顧客自填金額或讀取 CF7 欄位金額
* 可設定付款金額上下限與建議金額快速選項
* 簡易模式：數位商品僅需 Email 即可收款
* 自動偵測表單欄位映射
* 付款成功自動發送 CF7 郵件通知
* 後台訂單管理和交易記錄
* 支援退款操作
* Webhook 即時狀態更新
* 付款失敗可一鍵重試（免重填表單）

== Installation ==

1. 上傳外掛到 `/wp-content/plugins/` 目錄
2. 在 WordPress 後台啟用外掛
3. 前往「聯絡表單 → Shopline Payment」設定 API 金鑰
4. 在 CF7 表單編輯頁的「付款」Tab 設定金額和付款方式
5. 在表單中插入 `[shopline_payment]` 標籤

== Frequently Asked Questions ==

= 需要 SHOPLINE Payments 帳號嗎？ =

是的，請先到 shoplinepayments.com 註冊並完成特店審核。

= 支援哪些付款方式？ =

信用卡、Apple Pay、LINE Pay、ATM 銀行轉帳、街口支付、中租零卡分期。

= 可以設定分期嗎？ =

可以，在付款 Tab 中勾選需要的分期期數即可。

== Changelog ==

= 1.1.1 =
* 修正 CF7 後台付款設定頁可能顯示 JavaScript 原始碼的問題

= 1.1.0 =
* 新增固定金額、顧客自填金額與 CF7 欄位金額模式
* 新增付款金額上下限與建議金額快速選項
* 動態金額自動使用導轉式付款，避免 SDK 初始化金額與實際訂單不一致
* 訂單記錄金額來源與欄位名稱，重試付款沿用原訂單金額
* 新增動態金額完整流程驗證腳本

= 1.0.1 =
* 強化付款設定、金額、付款方式與退款資料驗證
* 改善 API 憑證檢查、金鑰加密完整性與 webhook 冪等清理
* 修正 CF7 REST submission 來源頁 fallback 與付款成功寄信冪等狀態
* 新增本機 smoke、webhook、SHOPLINE sandbox session 與完整流程驗證腳本

= 1.0.0 =
* 初始版本
* 支援導轉式付款（全部 6 種付款方式）
* 內嵌式 SDK 支援（信用卡/Apple Pay）
* 訂單管理後台
* Webhook 即時通知
* 退款功能
* CSV 匯出
