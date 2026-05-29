<?php
/**
 * Complete CF7 flow check for customer-entered amount.
 *
 * Run from the project root:
 * ddev exec 'wp --path=/var/www/html/wordpress eval-file /var/www/html/repo/tests/dynamic-amount-flow.php'
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$failures = [];
$created_order_id = null;
$event_id = null;
$form_id = 5;
$original_settings = get_post_meta( $form_id, '_slp_payment_settings', true );

$assert = static function( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$cleanup = static function() use ( &$created_order_id, &$event_id, $form_id, $original_settings ): void {
	if ( $created_order_id ) {
		wp_delete_post( $created_order_id, true );
	}

	if ( $event_id ) {
		delete_option( '_slp_evt_' . substr( md5( $event_id ), 0, 16 ) );
	}

	if ( false === $original_settings || '' === $original_settings ) {
		delete_post_meta( $form_id, '_slp_payment_settings' );
	} else {
		update_post_meta( $form_id, '_slp_payment_settings', $original_settings );
	}
};

$api = MXP_SLP_API::get_instance();
if ( ! $api->has_credentials() || '' === $api->get_sign_key() ) {
	WP_CLI::error( 'Dynamic amount flow test requires configured SHOPLINE API credentials and Sign Key.' );
}

$form = wpcf7_contact_form( $form_id );
if ( ! $form ) {
	WP_CLI::error( 'Dynamic amount flow test requires CF7 form ID 5.' );
}

update_post_meta( $form_id, '_slp_payment_settings', [
	'enabled'           => true,
	'amount_mode'       => 'user_input',
	'amount'            => 0,
	'amount_min'        => 100,
	'amount_max'        => 2000,
	'amount_field'      => '',
	'suggested_amounts' => [ 300, 777, 1200 ],
	'currency'          => 'TWD',
	'payment_methods'   => [ 'CreditCard', 'LinePay' ],
	'cc_installments'   => [ '0' ],
	'simple_mode'       => true,
	'button_text'       => '',
] );

$_POST = [
	'your-name'             => '王小明',
	'your-email'            => 'buyer@example.com',
	'your-tel'              => '0912345678',
	'slp_amount'            => '777',
	'_wpcf7'                => (string) $form_id,
	'_wpcf7_version'        => defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : '',
	'_wpcf7_locale'         => 'zh_TW',
	'_wpcf7_unit_tag'       => 'wpcf7-f5-p13-o1',
	'_wpcf7_container_post' => '13',
];

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/payment-test/';
$_SERVER['REMOTE_ADDR'] = '127.0.1.' . random_int( 50, 250 );
$_SERVER['HTTP_REFERER'] = home_url( '/payment-test/' );
$_SERVER['HTTP_USER_AGENT'] = 'MXP SLP dynamic-amount-flow test';

$submission = WPCF7_Submission::get_instance( $form );
if ( ! $submission ) {
	$cleanup();
	WP_CLI::error( 'Could not create CF7 submission instance.' );
}

$result = $submission->get_result();
$slp = $result['shopline_payment'] ?? [];

$assert( 'payment_required' === ( $result['status'] ?? '' ), 'dynamic amount submission requires payment' );
$assert( ! empty( $slp['session_url'] ), 'dynamic amount submission returns SHOPLINE session URL' );
$assert( ! empty( $slp['order_token'] ) && MXP_SLP_Security::is_valid_order_token( $slp['order_token'] ), 'dynamic amount submission returns valid order token' );

$created_order_id = ! empty( $slp['order_token'] ) ? MXP_SLP_Order::find_by_token( $slp['order_token'] ) : null;
$assert( is_int( $created_order_id ) && $created_order_id > 0, 'dynamic amount submission creates local order' );

if ( $created_order_id ) {
	$assert( '777' === (string) get_post_meta( $created_order_id, '_slp_amount', true ), 'dynamic order stores customer-entered amount' );
	$assert( 'user_input' === get_post_meta( $created_order_id, '_slp_amount_source', true ), 'dynamic order stores user-input amount source' );
	$assert( 'slp_amount' === get_post_meta( $created_order_id, '_slp_amount_field', true ), 'dynamic order stores amount field name' );
}

$session_id = $created_order_id ? get_post_meta( $created_order_id, '_slp_session_id', true ) : '';
$assert( '' !== $session_id, 'dynamic order stores SHOPLINE session id' );

$event_id = 'evt_dynamic_' . wp_generate_password( 8, false, false );
$payload = [
	'id'   => $event_id,
	'type' => 'session.succeeded',
	'data' => [
		'sessionId'      => $session_id,
		'paymentDetails' => [
			[
				'tradeOrderId'  => 'trade_dynamic_1',
				'paymentMethod' => 'CreditCard',
			],
		],
	],
];
$body = wp_json_encode( $payload );
$timestamp = (string) ( time() * 1000 );
$sign = hash_hmac( 'sha256', $timestamp . '.' . $body, $api->get_sign_key() );

$request = new \WP_REST_Request( 'POST', '/mxp-cf7-slp/v1/webhook' );
$request->set_body( $body );
$request->set_header( 'timestamp', $timestamp );
$request->set_header( 'sign', $sign );
$webhook_response = MXP_SLP_Webhook::get_instance()->handle( $request );

$assert( 200 === $webhook_response->get_status(), 'dynamic amount signed webhook returns 200' );
if ( $created_order_id ) {
	$assert( 'SUCCEEDED' === get_post_meta( $created_order_id, '_slp_status', true ), 'dynamic amount webhook marks order succeeded' );
}

$status_request = new \WP_REST_Request( 'GET', '/mxp-cf7-slp/v1/order-status' );
$status_request->set_param( 'token', $slp['order_token'] ?? '' );
$status_response = MXP_SLP_Return_Page::get_instance()->handle_order_status( $status_request );
$status_data = $status_response->get_data();

$assert( 200 === $status_response->get_status(), 'dynamic amount order-status returns 200' );
$assert( 'SUCCEEDED' === ( $status_data['status'] ?? '' ), 'dynamic amount order-status reports succeeded' );
$assert( 777 === (int) ( $status_data['amount'] ?? 0 ), 'dynamic amount order-status returns customer-entered amount' );

$cleanup();

if ( $failures ) {
	foreach ( $failures as $failure ) {
		WP_CLI::error( $failure, false );
	}
	WP_CLI::halt( 1 );
}

WP_CLI::success( 'MXP CF7 Shopline Payment dynamic amount flow checks passed.' );
