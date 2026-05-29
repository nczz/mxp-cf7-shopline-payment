<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wpcf7_before_send_mail', 'mxp_slp_handle_before_send_mail', 10, 3 );

function mxp_slp_handle_before_send_mail( $contact_form, &$abort, $submission ): void {
	// 檢查是否為付款表單
	$tags = $contact_form->scan_form_tags( [ 'type' => 'shopline_payment' ] );
	if ( empty( $tags ) ) {
		return;
	}

	$form_id = $contact_form->id();
	$settings = get_post_meta( $form_id, '_slp_payment_settings', true ) ?: [];

	if ( empty( $settings['enabled'] ) ) {
		return;
	}

	$amount = absint( $settings['amount'] ?? 0 );
	if ( ! MXP_SLP_Security::validate_amount( $amount ) ) {
		$abort = true;
		$submission->set_status( 'validation_failed' );
		$submission->set_response( __( '付款金額設定有誤，請聯繫網站管理員', 'mxp-cf7-slp' ) );
		return;
	}

	// Rate limit
	$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
	if ( ! MXP_SLP_Security::check_rate_limit( $ip ) ) {
		$abort = true;
		$submission->set_status( 'validation_failed' );
		$submission->set_response( __( '請求過於頻繁，請稍後再試', 'mxp-cf7-slp' ) );
		return;
	}

	// 欄位驗證
	$posted_data = $submission->get_posted_data();
	$validation_error = MXP_SLP_Request_Builder::validate_required_fields( $posted_data, $settings );
	if ( $validation_error ) {
		$abort = true;
		$submission->set_status( 'validation_failed' );
		$submission->set_response( $validation_error['message'] );
		return;
	}

	// 產生訂單 token
	$token = MXP_SLP_Security::generate_order_token();

	// Return URL
	$return_page = get_page_by_path( 'slp-payment-return' );
	$return_url = $return_page
		? add_query_arg( 'token', $token, get_permalink( $return_page ) )
		: home_url( '/?slp_return=1&token=' . $token );

	// 組裝 API 請求
	$request_body = MXP_SLP_Request_Builder::build_session_request(
		$form_id,
		$posted_data,
		$token,
		$return_url
	);

	// 呼叫 SLP API
	$api = MXP_SLP_API::get_instance();
	if ( ! $api->has_credentials() ) {
		$abort = true;
		$submission->set_status( 'aborted' );
		$submission->set_response( __( '付款服務尚未完成設定，請聯繫網站管理員', 'mxp-cf7-slp' ) );
		return;
	}

	$result = $api->create_session( $request_body );

	if ( ! $result || empty( $result['sessionId'] ) ) {
		$abort = true;
		$submission->set_status( 'aborted' );
		$submission->set_response( __( '付款服務暫時無法使用，請稍後再試', 'mxp-cf7-slp' ) );
		return;
	}

	// 建立訂單
	MXP_SLP_Order::create( [
		'token'        => $token,
		'session_id'   => $result['sessionId'],
		'reference_id' => $token,
		'form_id'      => $form_id,
		'posted_data'  => $posted_data,
		'amount'       => $amount,
		'currency'     => 'TWD',
		'status'       => 'CREATED',
		'mail_sent'    => false,
		'retry_count'  => 0,
		'referer_url'  => mxp_slp_get_submission_referer_url( $submission ),
	] );

	// 設定回應
	$abort = true;
	$submission->set_status( 'payment_required' );
	$submission->set_response( __( '正在導向付款頁面...', 'mxp-cf7-slp' ) );
	$submission->add_result_props( [
		'shopline_payment' => [
			'session_url'  => $result['sessionUrl'],
			'order_token'  => $token,
		],
	] );
}

function mxp_slp_get_submission_referer_url( $submission ): string {
	$url = (string) $submission->get_meta( 'url' );
	$rest_base = untrailingslashit( rest_url() );
	$home = home_url( '/' );

	if ( $url && ! str_starts_with( $url, $rest_base ) && $home !== $url ) {
		return esc_url_raw( $url );
	}

	$container_post_id = (int) $submission->get_meta( 'container_post_id' );
	if ( $container_post_id ) {
		$permalink = get_permalink( $container_post_id );
		if ( $permalink ) {
			return esc_url_raw( $permalink );
		}
	}

	return home_url( '/' );
}

// SDK 模式的 create-payment REST endpoint
add_action( 'rest_api_init', function() {
	register_rest_route( 'mxp-cf7-slp/v1', '/create-payment', [
		'methods'             => 'POST',
		'callback'            => 'mxp_slp_handle_create_payment',
		'permission_callback' => '__return_true',
	] );
} );

function mxp_slp_handle_create_payment( WP_REST_Request $request ): WP_REST_Response {
	$token       = sanitize_text_field( $request->get_param( 'order_token' ) );
	$pay_session = $request->get_param( 'paySession' );

	if ( ! $token || ! MXP_SLP_Security::is_valid_order_token( $token ) || ! $pay_session ) {
		return new WP_REST_Response( [ 'error' => 'missing_params' ], 400 );
	}

	$order_id = MXP_SLP_Order::find_by_token( $token );
	if ( ! $order_id ) {
		return new WP_REST_Response( [ 'error' => 'order_not_found' ], 404 );
	}

	// 驗證訂單狀態必須為 CREATED（防止重複付款）
	$status = get_post_meta( $order_id, '_slp_status', true );
	if ( 'CREATED' !== $status ) {
		return new WP_REST_Response( [ 'error' => 'invalid_order_status' ], 400 );
	}

	// Rate limit
	$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
	if ( ! MXP_SLP_Security::check_rate_limit( $ip ) ) {
		return new WP_REST_Response( [ 'error' => 'rate_limited' ], 429 );
	}

	$form_id     = (int) get_post_meta( $order_id, '_slp_form_id', true );
	$posted_data = get_post_meta( $order_id, '_slp_posted_data', true );
	$settings    = get_post_meta( $form_id, '_slp_payment_settings', true ) ?: [];
	$amount_cents = intval( round( ( $settings['amount'] ?? 0 ) * 100 ) );

	$mapping = MXP_SLP_Request_Builder::auto_detect_mapping( $form_id, $posted_data );
	$email = $mapping['email'] ?? '';
	$name  = $mapping['name'] ?? '';
	[ $first_name, $last_name ] = MXP_SLP_Request_Builder::split_name( $name );
	$phone = MXP_SLP_Request_Builder::format_phone( $mapping['phone'] ?? '' );

	$personal_info = array_filter( [
		'firstName' => $first_name,
		'lastName'  => $last_name ?: $email,
		'email'     => $email,
		'phone'     => $phone,
	] );

	$return_page = get_page_by_path( 'slp-payment-return' );
	$return_url = $return_page
		? add_query_arg( 'token', $token, get_permalink( $return_page ) )
		: home_url( '/?slp_return=1&token=' . $token );

	$contact_form = wpcf7_contact_form( $form_id );
	$product_name = $contact_form ? $contact_form->title() : '商品';

	$body = [
		'acquirerType'     => 'SDK',
		'referenceOrderId' => $token,
		'amount'           => [ 'value' => $amount_cents, 'currency' => 'TWD' ],
		'language'         => 'zh-TW',
		'returnUrl'        => $return_url,
		'paySession'       => $pay_session,
		'confirm'          => [
			'paymentMethod'   => 'CreditCard',
			'paymentBehavior' => 'Regular',
			'autoCapture'     => true,
		],
		'order' => [
			'products' => [ [
				'id'       => (string) $form_id,
				'name'     => $product_name,
				'quantity' => 1,
				'amount'   => [ 'value' => $amount_cents, 'currency' => 'TWD' ],
			] ],
			'shipping' => [
				'shippingMethod' => '數位商品',
				'carrier'        => '電子郵件',
				'personalInfo'   => $personal_info,
				'address'        => [ 'countryCode' => 'TW', 'street' => '數位商品無需寄送' ],
			],
		],
		'customer' => [
			'referenceCustomerId' => $email ? md5( $email ) : 'guest_' . substr( $token, 0, 16 ),
			'personalInfo'        => $personal_info,
		],
		'client' => [ 'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ],
		'billing' => [
			'personalInfo' => $personal_info,
			'address'      => [ 'countryCode' => 'TW', 'street' => '線上交易' ],
		],
	];

	// 呼叫 SLP Payment API（使用 API 類別的 create_payment 方法）
	$api = MXP_SLP_API::get_instance();
	if ( ! $api->has_credentials() ) {
		return new WP_REST_Response( [ 'error' => 'payment_not_configured' ], 503 );
	}

	$data = $api->create_payment( $body );

	if ( ! $data || empty( $data['nextAction'] ) ) {
		return new WP_REST_Response( [ 'error' => 'payment_failed' ], 400 );
	}

	// 記錄 tradeOrderId
	if ( ! empty( $data['tradeOrderId'] ) ) {
		update_post_meta( $order_id, '_slp_trade_order_id', $data['tradeOrderId'] );
	}

	return new WP_REST_Response( [
		'nextAction'   => $data['nextAction'],
		'tradeOrderId' => $data['tradeOrderId'] ?? '',
	] );
}

// Special Mail Tags — 在正常 submission 流程中也能使用
add_filter( 'wpcf7_special_mail_tags', 'mxp_slp_special_mail_tags', 20, 4 );

function mxp_slp_special_mail_tags( $output, $name, $html, $mail_tag = null ) {
	if ( ! str_starts_with( $name, '_slp_' ) ) {
		return $output;
	}

	$submission = WPCF7_Submission::get_instance();
	if ( ! $submission ) {
		return $output;
	}

	// 從 submission 的 result props 中取得（如果有的話）
	$result = $submission->get_result();
	$slp = $result['shopline_payment'] ?? null;

	if ( ! $slp ) {
		return $output;
	}

	return match ( $name ) {
		'_slp_order_token' => $slp['order_token'] ?? '',
		default            => $output,
	};
}
