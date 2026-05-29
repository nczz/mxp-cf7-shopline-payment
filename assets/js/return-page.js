(function() {
	'use strict';

	var container = document.getElementById('slp-return-page');
	if (!container) return;

	var params = new URLSearchParams(window.location.search);
	var token = params.get('token');

	if (!token) {
		container.innerHTML = '<div class="slp-status slp-status-failed"><div class="slp-icon">✗</div><h2>無效的連結</h2><p>找不到對應的訂單資訊。</p></div>';
		return;
	}

	pollStatus(token, 0);

	function pollStatus(token, attempt) {
		var maxAttempts = 60;
		var interval = 3000;

		fetch(slpReturnPage.apiRoot + 'mxp-cf7-slp/v1/order-status?token=' + encodeURIComponent(token), {
			credentials: 'same-origin'
		})
		.then(function(res) { return res.json(); })
		.then(function(data) {
			if (data.error === 'not_found') {
				showError('找不到此訂單');
				return;
			}

			if (data.status === 'SUCCEEDED') {
				showSuccess(data);
				clearStoredToken();
			} else if (data.status === 'EXPIRED' || data.status === 'FAILED') {
				showFailed(data, token);
			} else if (data.virtual_account) {
				showATMPending(data);
			} else if (attempt >= 10) {
				showFailed(data, token);
			} else if (attempt < maxAttempts) {
				showPending();
				setTimeout(function() { pollStatus(token, attempt + 1); }, interval);
			} else {
				showTimeout();
			}
		})
		.catch(function() {
			if (attempt < 3) {
				setTimeout(function() { pollStatus(token, attempt + 1); }, interval);
			} else {
				showError('查詢失敗，請稍後重新整理頁面');
			}
		});
	}

	function showSuccess(data) {
		var html = '<div class="slp-status slp-status-success">';
		html += '<div class="slp-icon">✓</div>';
		html += '<h2>付款成功</h2>';
		html += '<div class="slp-summary">';
		if (data.order_number) html += '<p><strong>訂單編號：</strong>' + esc(data.order_number) + '</p>';
		html += '<p><strong>金額：</strong>NT$' + numberFormat(data.amount) + '</p>';
		if (data.payment_method) html += '<p><strong>付款方式：</strong>' + esc(data.payment_method) + '</p>';
		html += '</div>';
		if (data.customer_email_masked) {
			html += '<p class="slp-email-notice">確認信已發送至 <strong>' + esc(data.customer_email_masked) + '</strong><br><small>如未收到，請檢查垃圾郵件資料夾</small></p>';
		}
		if (data.referer_url) {
			html += '<div class="slp-actions"><a href="' + esc(data.referer_url) + '" class="slp-back-link">← 回到表單頁面</a></div>';
		}
		html += '</div>';
		container.innerHTML = html;
	}

	function showFailed(data, token) {
		var html = '<div class="slp-status slp-status-failed">';
		html += '<div class="slp-icon">✗</div>';
		html += '<h2>付款未完成</h2>';

		// #6: 顯示失敗原因
		var reason = getFailureReason(data);
		html += '<p>' + esc(reason) + '</p>';

		html += '<div class="slp-summary">';
		html += '<p><strong>金額：</strong>NT$' + numberFormat(data.amount) + '</p>';
		html += '</div>';
		html += '<div class="slp-actions">';
		html += '<button type="button" class="slp-retry-btn" id="slp-retry-btn">重新付款</button>';
		// #7: 重試說明
		html += '<span class="slp-retry-hint">不需要重新填寫表單，點擊即可直接前往付款頁面</span>';
		if (data.referer_url) {
			html += '<br><a href="' + esc(data.referer_url) + '" class="slp-back-link">回到表單重新填寫</a>';
		}
		html += '</div></div>';
		container.innerHTML = html;

		document.getElementById('slp-retry-btn').addEventListener('click', function() {
			doRetry(token, this);
		});
	}

	function showATMPending(data) {
		var va = data.virtual_account;
		var html = '<div class="slp-status slp-status-pending">';
		html += '<div class="slp-icon"></div>';
		html += '<h2>等待轉帳</h2>';
		html += '<p>請使用以下資訊完成轉帳付款</p>';
		html += '<div class="slp-virtual-account">';
		html += '<h3>轉帳資訊</h3>';
		html += '<p><strong>銀行代碼：</strong>' + esc(va.bank_code) + '</p>';
		// #8: 帳號加複製按鈕
		html += '<p><strong>虛擬帳號：</strong><code id="slp-va-num">' + esc(va.account_number) + '</code> <button type="button" class="slp-copy-btn" onclick="slpCopy()">複製</button></p>';
		html += '<p><strong>截止日期：</strong>' + esc(va.due_date_desc || va.due_date) + '</p>';
		html += '<p><strong>金額：</strong>NT$' + numberFormat(data.amount) + '</p>';
		html += '</div>';
		html += '<p style="color:#856404;font-size:0.9em;">轉帳完成後，系統將自動確認並發送通知信。</p>';
		html += '</div>';
		container.innerHTML = html;
	}

	function showPending() {
		container.innerHTML = '<div class="slp-status slp-status-pending"><div class="slp-icon"></div><h2>確認付款中</h2><p>正在確認您的付款結果，請稍候...</p></div>';
	}

	function showTimeout() {
		container.innerHTML = '<div class="slp-status slp-status-pending"><h2>處理中</h2><p>付款結果確認中，確認完成後將發送通知信至您的信箱。</p></div>';
	}

	function showError(msg) {
		container.innerHTML = '<div class="slp-status slp-status-failed"><div class="slp-icon">✗</div><h2>錯誤</h2><p>' + esc(msg) + '</p></div>';
	}

	// #6: 失敗原因判斷
	function getFailureReason(data) {
		if (data.status === 'EXPIRED') {
			return '付款已逾時，請重新嘗試。';
		}
		// 可以根據 error_msg 顯示更具體的原因（未來從 API 取得）
		return '您的付款尚未成功完成，可能是付款方式驗證失敗或交易被取消。';
	}

	function doRetry(token, btn) {
		btn.disabled = true;
		btn.textContent = '處理中...';

		fetch(slpReturnPage.apiRoot + 'mxp-cf7-slp/v1/retry-payment', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': slpReturnPage.nonce },
			credentials: 'same-origin',
			body: JSON.stringify({ token: token })
		})
		.then(function(res) { return res.json(); })
		.then(function(data) {
			if (data.session_url) {
				window.location.href = data.session_url;
			} else {
				var msg = data.error === 'max_retries' ? '已超過重試次數上限（3次），請聯繫客服。' : '重試失敗，請稍後再試。';
				alert(msg);
				btn.disabled = false;
				btn.textContent = '重新付款';
			}
		})
		.catch(function() {
			alert('網路錯誤，請稍後再試。');
			btn.disabled = false;
			btn.textContent = '重新付款';
		});
	}

	// #8: 複製帳號
	window.slpCopy = function() {
		var num = document.getElementById('slp-va-num');
		if (num && navigator.clipboard) {
			navigator.clipboard.writeText(num.textContent).then(function() {
				var btn = num.nextElementSibling;
				if (btn) { btn.textContent = '已複製'; setTimeout(function() { btn.textContent = '複製'; }, 2000); }
			});
		}
	};

	function clearStoredToken() {
		if (!window.sessionStorage) return;
		// 清除所有 slp_order_ 開頭的 key
		for (var i = sessionStorage.length - 1; i >= 0; i--) {
			var key = sessionStorage.key(i);
			if (key && key.startsWith('slp_order_')) {
				sessionStorage.removeItem(key);
			}
		}
	}

	function esc(str) {
		var div = document.createElement('div');
		div.textContent = str || '';
		return div.innerHTML;
	}

	function numberFormat(n) {
		return (n || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}
})();
