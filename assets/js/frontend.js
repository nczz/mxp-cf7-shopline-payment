(function() {
	'use strict';

	var sdkInstances = {};

	// 初始化
	document.addEventListener('DOMContentLoaded', function() {
		// 隱藏原生 submit
		document.querySelectorAll('.wpcf7-shopline-payment').forEach(function(widget) {
			var form = widget.closest('.wpcf7-form');
			if (!form) return;
			form.querySelectorAll('.wpcf7-submit:not(.slp-submit-btn)').forEach(function(btn) {
				btn.style.display = 'none';
			});
			initAmountWidget(widget);
		});

		// 檢查是否有未完成的付款（上一頁回來的情況）
		checkPendingPayment();

		// SDK 初始化（hybrid 模式）
		var hybridWidgets = document.querySelectorAll('.wpcf7-shopline-payment[data-mode="hybrid"]');
		if (hybridWidgets.length && window.mxpSlpSettings && window.mxpSlpSettings.clientKey) {
			waitForSDK(function() {
				hybridWidgets.forEach(initSDKWidget);
			});
		}
	});

	// #2: 偵測未完成的付款（使用者按上一頁回來）
	function checkPendingPayment() {
		if (!window.sessionStorage) return;
		var forms = document.querySelectorAll('.wpcf7-shopline-payment');
		forms.forEach(function(widget) {
			var form = widget.closest('.wpcf7');
			if (!form) return;
			var formId = form.querySelector('[name="_wpcf7"]');
			if (!formId) return;
			var key = 'slp_order_' + formId.value;
			var stored = sessionStorage.getItem(key);
			if (!stored) return;

			try {
				var data = JSON.parse(stored);
				// 如果是 5 分鐘內的未完成付款
				if (data.token && (Date.now() - data.timestamp) < 300000) {
					showPendingNotice(widget, data.token);
				} else {
					sessionStorage.removeItem(key);
				}
			} catch(e) { sessionStorage.removeItem(key); }
		});
	}

	function showPendingNotice(widget, token) {
		var notice = document.createElement('div');
		notice.className = 'slp-pending-notice';
		notice.style.cssText = 'background:#fff3cd;border:1px solid #ffc107;padding:12px;border-radius:4px;margin-bottom:12px;font-size:14px;';
		notice.innerHTML = '<strong>您有一筆未完成的付款</strong><br>'
			+ '<a href="' + getReturnUrl(token) + '" style="color:#2271b1;">查看付款狀態</a>'
			+ ' 或 <a href="#" class="slp-dismiss-notice" style="color:#666;">重新填寫</a>';
		widget.insertBefore(notice, widget.firstChild);

		notice.querySelector('.slp-dismiss-notice').addEventListener('click', function(e) {
			e.preventDefault();
			notice.remove();
			var form = widget.closest('.wpcf7');
			var formId = form ? form.querySelector('[name="_wpcf7"]') : null;
			if (formId) sessionStorage.removeItem('slp_order_' + formId.value);
		});
	}

	function getReturnUrl(token) {
		// 從頁面中找 return page URL 或用預設路徑
		return window.location.origin + '/slp-payment-return/?token=' + encodeURIComponent(token);
	}

	function initAmountWidget(widget) {
		var input = widget.querySelector('input[name="slp_amount"]');
		if (!input) return;

		var display = widget.querySelector('.slp-amount-display');
		var min = parseInt(widget.dataset.amountMin, 10) || 1;
		var max = parseInt(widget.dataset.amountMax, 10) || 10000000;

		function format(n) {
			return 'NT$' + String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		}

		function refresh() {
			var value = parseInt(input.value, 10);
			if (display) {
				display.textContent = value ? format(value) : '請輸入 ' + format(min) + ' - ' + format(max);
			}
			if (input.value && (value < min || value > max)) {
				input.setCustomValidity('付款金額需介於 ' + format(min) + ' 到 ' + format(max));
			} else {
				input.setCustomValidity('');
			}
			widget.querySelectorAll('.slp-suggested-amount').forEach(function(button) {
				var selected = input.value === button.dataset.amount;
				button.classList.toggle('is-selected', selected);
				button.setAttribute('aria-pressed', selected ? 'true' : 'false');
			});
		}

		widget.querySelectorAll('.slp-suggested-amount').forEach(function(button) {
			button.addEventListener('click', function() {
				input.value = this.dataset.amount || '';
				input.dispatchEvent(new Event('input', { bubbles: true }));
				input.focus();
			});
		});

		input.addEventListener('input', refresh);
		refresh();
	}

	// 表單提交處理
	document.addEventListener('wpcf7submit', function(event) {
		if (event.detail.status !== 'payment_required') return;

		var slp = event.detail.apiResponse && event.detail.apiResponse.shopline_payment;
		if (!slp) return;

		var form = event.target;
		var widget = form.querySelector('.wpcf7-shopline-payment');
		var mode = widget ? widget.dataset.mode : 'redirect';
		var formId = widget ? widget.dataset.formId : '';

		updateButtonState(form, 'processing');

		if (mode === 'hybrid' && sdkInstances[formId] && slp.order_token) {
			handleEmbeddedPayment(sdkInstances[formId], slp.order_token, form);
		} else if (slp.session_url) {
			// 儲存 token
			saveOrderToken(form, slp.order_token);
			// 導轉
			updateButtonState(form, 'redirecting');
			if (window.history && window.history.replaceState) {
				window.history.replaceState({ slp_submitted: true }, '');
			}
			window.location.href = slp.session_url;
		}
	});

	// #11: 驗證失敗後恢復按鈕
	document.addEventListener('wpcf7invalid', function(event) {
		updateButtonState(event.target, 'reset');
	});

	document.addEventListener('wpcf7spam', function(event) {
		updateButtonState(event.target, 'reset');
	});

	function updateButtonState(form, state) {
		var btn = form.querySelector('.slp-submit-btn');
		if (!btn) return;
		var text = btn.querySelector('.slp-btn-text');
		var spinner = btn.querySelector('.slp-btn-spinner');

		if (state === 'processing') {
			if (text) text.style.display = 'none';
			if (spinner) { spinner.style.display = 'inline-flex'; spinner.querySelector('.slp-spinner-icon + *') || (spinner.lastChild.textContent = '處理中...'); }
			btn.disabled = true;
		} else if (state === 'redirecting') {
			if (spinner) { var txt = spinner.childNodes[spinner.childNodes.length - 1]; if (txt) txt.textContent = ' 正在跳轉到付款頁面...'; }
		} else if (state === 'reset') {
			if (text) text.style.display = 'inline';
			if (spinner) spinner.style.display = 'none';
			btn.disabled = false;
			// 移除錯誤訊息
			var err = form.querySelector('.slp-error-msg');
			if (err) err.remove();
		}
	}

	function saveOrderToken(form, token) {
		if (!token || !window.sessionStorage) return;
		var wpcf7 = form.closest('.wpcf7');
		var formIdInput = wpcf7 ? wpcf7.querySelector('[name="_wpcf7"]') : null;
		if (formIdInput) {
			sessionStorage.setItem('slp_order_' + formIdInput.value, JSON.stringify({
				token: token,
				timestamp: Date.now()
			}));
		}
	}

	// SDK 相關
	function waitForSDK(callback, attempts) {
		attempts = attempts || 0;
		if (window.ShoplinePayments) { callback(); return; }
		if (attempts >= 20) {
			document.querySelectorAll('.wpcf7-shopline-payment[data-mode="hybrid"]').forEach(function(w) {
				w.dataset.mode = 'redirect';
				var c = w.querySelector('.slp-sdk-container');
				if (c) c.remove();
			});
			return;
		}
		setTimeout(function() { waitForSDK(callback, attempts + 1); }, 300);
	}

	function initSDKWidget(widget) {
		var formId = widget.dataset.formId;
		var container = widget.querySelector('.slp-sdk-container');
		if (!container || !formId) return;

		var settings = window.mxpSlpSettings;
		if (!settings || !settings.clientKey) {
			widget.dataset.mode = 'redirect';
			if (container) container.remove();
			return;
		}

		container.style.display = 'block';
		ShoplinePayments({
			clientKey: settings.clientKey,
			merchantId: settings.merchantId,
			paymentMethod: 'CreditCard',
			currency: 'TWD',
			amount: parseInt(settings.amount) || 10000,
			element: '#' + container.id,
			env: settings.env || 'sandbox',
		}).then(function(result) {
			if (result.error) {
				widget.dataset.mode = 'redirect';
				container.style.display = 'none';
				return;
			}
			sdkInstances[formId] = result.payment;
		});
	}

	function handleEmbeddedPayment(payment, orderToken, form) {
		payment.createPayment().then(function(result) {
			if (result.error) {
				showPaymentError(form, result.error.message || '付款資訊有誤，請確認後重試');
				updateButtonState(form, 'reset');
				return;
			}

			var settings = window.mxpSlpSettings || {};
			return fetch(settings.apiRoot + 'mxp-cf7-slp/v1/create-payment', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ order_token: orderToken, paySession: result.paySession })
			}).then(function(res) { return res.json(); });
		}).then(function(data) {
			if (!data || !data.nextAction) {
				showPaymentError(form, '建立付款失敗，請稍後再試');
				updateButtonState(form, 'reset');
				return;
			}
			saveOrderToken(form, orderToken);
			return payment.pay(data.nextAction);
		}).then(function(payResult) {
			if (payResult && payResult.error) {
				showPaymentError(form, payResult.error.message || '付款失敗，請重試');
				updateButtonState(form, 'reset');
			}
		}).catch(function() {
			showPaymentError(form, '付款過程發生錯誤，請稍後再試');
			updateButtonState(form, 'reset');
		});
	}

	function showPaymentError(form, message) {
		var widget = form.querySelector('.wpcf7-shopline-payment');
		if (!widget) return;
		var existing = widget.querySelector('.slp-error-msg');
		if (existing) existing.remove();
		var msg = document.createElement('p');
		msg.className = 'slp-error-msg';
		msg.textContent = message;
		widget.appendChild(msg);
	}
})();
