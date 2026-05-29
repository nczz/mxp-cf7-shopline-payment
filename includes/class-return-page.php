<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MXP_SLP_Return_Page {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'slp_return_page', [ $this, 'render_shortcode' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'mxp-cf7-slp/v1', '/order-status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_order_status' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'token' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		register_rest_route( 'mxp-cf7-slp/v1', '/retry-payment', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_retry' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'token' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
	}

	public function render_shortcode(): string {
		wp_enqueue_script(
			'mxp-cf7-slp-return-page',
			MXP_SLP_PLUGIN_URL . '/assets/js/return-page.js',
			[],
			MXP_SLP_VERSION,
			[ 'in_footer' => true ]
		);

		wp_localize_script( 'mxp-cf7-slp-return-page', 'slpReturnPage', [
			'apiRoot' => esc_url_raw( rest_url() ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		] );

		wp_enqueue_style( 'mxp-cf7-slp-frontend', MXP_SLP_PLUGIN_URL . '/assets/css/frontend.css', [], MXP_SLP_VERSION );

		return '<div class="slp-return-page" id="slp-return-page"><div class="slp-loading">載入中...</div></div>';
	}

	public function handle_order_status( \WP_REST_Request $request ): \WP_REST_Response {
		$token = $request->get_param( 'token' );
		if ( ! MXP_SLP_Security::is_valid_order_token( $token ) ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}

		$rate_key = ( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ) . ':order-status:' . $token;
		if ( ! MXP_SLP_Security::check_rate_limit( $rate_key, 30, 60 ) ) {
			return new \WP_REST_Response( [ 'error' => 'rate_limited' ], 429 );
		}

		$order_id = MXP_SLP_Order::find_by_token( $token );

		if ( ! $order_id ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}

		$status = get_post_meta( $order_id, '_slp_status', true );

		// 如果仍為 CREATED 或 PENDING，主動查詢 SLP 確認
		if ( in_array( $status, [ 'CREATED', 'PENDING' ], true ) ) {
			$session_id = get_post_meta( $order_id, '_slp_session_id', true );
			$api = MXP_SLP_API::get_instance();
			$session = $api->has_credentials() ? $api->query_session( $session_id ) : false;

			if ( $session ) {
				$slp_status = $session['status'] ?? '';

				if ( 'SUCCEEDED' === $slp_status ) {
					MXP_SLP_Order::update_status( $order_id, 'SUCCEEDED' );
					$status = 'SUCCEEDED';

					$details = $session['paymentDetails'][0] ?? [];
					if ( $details ) {
						update_post_meta( $order_id, '_slp_trade_order_id', $details['tradeOrderId'] ?? '' );
						update_post_meta( $order_id, '_slp_payment_method', $details['paymentMethod'] ?? '' );
					}

					MXP_SLP_Mail_Handler::send_payment_confirmation( $token );

				} elseif ( 'EXPIRED' === $slp_status ) {
					MXP_SLP_Order::update_status( $order_id, 'EXPIRED' );
					$status = 'EXPIRED';

				} elseif ( 'PENDING' === $slp_status ) {
					// PENDING = 顧客已在 SLP 選擇付款方式並提交
					// 如果顧客回到 return page，有兩種情況：
					// 1. ATM 轉帳：取得虛擬帳號後回來等待（正常）
					// 2. 其他付款方式失敗後被導回（應顯示失敗）
					// 判斷方式：檢查 paymentDetails 是否有成功的 trade
					$payment_details = $session['paymentDetails'] ?? [];
					$has_success = false;
					foreach ( $payment_details as $detail ) {
						if ( 'SUCCEEDED' === ( $detail['paymentStatus'] ?? $detail['status'] ?? '' ) ) {
							$has_success = true;
						}
					}

					if ( ! $has_success && empty( $payment_details ) ) {
						// 無 paymentDetails 且為 PENDING → 付款方式失敗後回來
						// 標記為 FAILED 讓使用者可以重試
						MXP_SLP_Order::update_status( $order_id, 'FAILED' );
						$status = 'FAILED';
					}
					// 有 paymentDetails 但未成功 → 可能是 ATM 等待中，保持 PENDING

				} elseif ( 'CREATED' === $slp_status ) {
					// Session 仍為 CREATED（顧客可能未選擇付款方式就回來了）
					// 保持 CREATED，前端 polling 會在 10 次後超時顯示失敗
				}
			} else {
				// API 查詢失敗，不改變狀態，讓前端繼續 polling（但有次數限制）
			}
		}

		// 組裝回應
		$amount = (int) get_post_meta( $order_id, '_slp_amount', true );
		$email  = '';
		$posted = get_post_meta( $order_id, '_slp_posted_data', true );
		if ( is_array( $posted ) ) {
			$mapping = MXP_SLP_Request_Builder::auto_detect_mapping( 0, $posted );
			$email = $mapping['email'] ?? '';
		}

		$response = [
			'status'              => $status,
			'amount'              => $amount,
			'currency'            => 'TWD',
			'form_title'          => get_the_title( (int) get_post_meta( $order_id, '_slp_form_id', true ) ) ?: '',
			'order_number'        => get_the_title( $order_id ),
			'customer_email_masked' => $email ? self::mask_email( $email ) : '',
			'payment_method'      => MXP_SLP_Request_Builder::get_method_label( get_post_meta( $order_id, '_slp_payment_method', true ) ?: '' ),
			'virtual_account'     => null,
			'referer_url'         => get_post_meta( $order_id, '_slp_referer_url', true ) ?: '',
		];

		// ATM 虛擬帳號資訊
		$payment_method = get_post_meta( $order_id, '_slp_payment_method', true );
		$trade_order_id = get_post_meta( $order_id, '_slp_trade_order_id', true );

		if ( 'VirtualAccount' === $payment_method && $trade_order_id && in_array( $status, [ 'CREATED', 'PENDING' ], true ) ) {
			$api = MXP_SLP_API::get_instance();
			$trade = $api->query_payment( $trade_order_id );
			if ( $trade && ! empty( $trade['payment']['virtualAccount'] ) ) {
				$va = $trade['payment']['virtualAccount'];
				$response['virtual_account'] = [
					'bank_code'      => $va['recipientBankCode'] ?? '',
					'account_number' => $va['recipientAccountNum'] ?? '',
					'due_date'       => $va['dueDate'] ?? '',
					'due_date_desc'  => $va['dueDateDesc'] ?? '',
				];
			}
		}

		return new \WP_REST_Response( $response, 200 );
	}

	public function handle_retry( \WP_REST_Request $request ): \WP_REST_Response {
		$token = $request->get_param( 'token' );
		if ( ! MXP_SLP_Security::is_valid_order_token( $token ) ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}

		$rate_key = ( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ) . ':retry-payment:' . $token;
		if ( ! MXP_SLP_Security::check_rate_limit( $rate_key, 5, 300 ) ) {
			return new \WP_REST_Response( [ 'error' => 'rate_limited' ], 429 );
		}

		$order_id = MXP_SLP_Order::find_by_token( $token );

		if ( ! $order_id ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}

		$status = get_post_meta( $order_id, '_slp_status', true );
		if ( ! in_array( $status, [ 'EXPIRED', 'FAILED' ], true ) ) {
			return new \WP_REST_Response( [ 'error' => 'invalid_status' ], 400 );
		}

		$retry_count = (int) get_post_meta( $order_id, '_slp_retry_count', true );
		if ( $retry_count >= 3 ) {
			return new \WP_REST_Response( [ 'error' => 'max_retries' ], 400 );
		}

		// 用原始資料建立新 Session
		$form_id     = (int) get_post_meta( $order_id, '_slp_form_id', true );
		$posted_data = get_post_meta( $order_id, '_slp_posted_data', true );

		if ( ! $form_id || ! is_array( $posted_data ) ) {
			return new \WP_REST_Response( [ 'error' => 'missing_data' ], 400 );
		}

		$new_token = MXP_SLP_Security::generate_order_token();
		$return_page = get_page_by_path( 'slp-payment-return' );
		$return_url = $return_page
			? add_query_arg( 'token', $new_token, get_permalink( $return_page ) )
			: home_url( '/?slp_return=1&token=' . $new_token );

		$request_body = MXP_SLP_Request_Builder::build_session_request( $form_id, $posted_data, $new_token, $return_url );
		$api = MXP_SLP_API::get_instance();
		if ( ! $api->has_credentials() ) {
			return new \WP_REST_Response( [ 'error' => 'payment_not_configured' ], 503 );
		}

		$result = $api->create_session( $request_body );

		if ( ! $result || empty( $result['sessionId'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'api_failed' ], 500 );
		}

		// 建立新訂單
		MXP_SLP_Order::create( [
			'token'        => $new_token,
			'session_id'   => $result['sessionId'],
			'reference_id' => $new_token,
			'form_id'      => $form_id,
			'posted_data'  => $posted_data,
			'amount'       => (int) get_post_meta( $order_id, '_slp_amount', true ),
			'currency'     => 'TWD',
			'status'       => 'CREATED',
			'mail_sent'    => false,
			'retry_count'  => 0,
			'referer_url'  => get_post_meta( $order_id, '_slp_referer_url', true ),
		] );

		// 更新原訂單重試次數
		update_post_meta( $order_id, '_slp_retry_count', $retry_count + 1 );

		return new \WP_REST_Response( [
			'session_url' => $result['sessionUrl'],
			'new_token'   => $new_token,
		], 200 );
	}

	private static function mask_email( string $email ): string {
		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) {
			return '***';
		}
		$local = $parts[0];
		$masked = substr( $local, 0, 1 ) . str_repeat( '*', max( 1, strlen( $local ) - 2 ) ) . substr( $local, -1 );
		return $masked . '@' . $parts[1];
	}
}

MXP_SLP_Return_Page::get_instance();
