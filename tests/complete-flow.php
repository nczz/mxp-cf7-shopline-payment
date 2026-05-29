<?php
/**
 * Complete local flow check with real SHOPLINE sandbox session creation.
 *
 * Run from the project root:
 * ddev exec 'wp --path=/var/www/html/wordpress eval-file /var/www/html/repo/tests/complete-flow.php'
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$failures = [];
$created_order_id = null;
$event_id = null;

$assert = static function( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$cleanup = static function() use ( &$created_order_id, &$event_id ): void {
	if ( $created_order_id ) {
		wp_delete_post( $created_order_id, true );
	}

	if ( $event_id ) {
		delete_option( '_slp_evt_' . substr( md5( $event_id ), 0, 16 ) );
	}
};

$api = MXP_SLP_API::get_instance();
if ( ! $api->has_credentials() ) {
	WP_CLI::error( 'Complete flow test requires configured SHOPLINE Merchant ID and API Key.' );
}

if ( '' === $api->get_sign_key() ) {
	WP_CLI::error( 'Complete flow test requires configured SHOPLINE Sign Key.' );
}

$form = wpcf7_contact_form( 5 );
if ( ! $form ) {
	WP_CLI::error( 'Complete flow test requires CF7 form ID 5.' );
}

$_POST = [
	'your-name'             => '王小明',
	'your-email'            => 'buyer@example.com',
	'your-tel'              => '0912345678',
	'_wpcf7'                => '5',
	'_wpcf7_version'        => defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : '',
	'_wpcf7_locale'         => 'zh_TW',
	'_wpcf7_unit_tag'       => 'wpcf7-f5-p13-o1',
	'_wpcf7_container_post' => '13',
];

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/payment-test/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.' . random_int( 50, 250 );
$_SERVER['HTTP_REFERER'] = home_url( '/payment-test/' );
$_SERVER['HTTP_USER_AGENT'] = 'MXP SLP complete-flow test';

$submission = WPCF7_Submission::get_instance( $form );
if ( ! $submission ) {
	WP_CLI::error( 'Could not create CF7 submission instance.' );
}

$result = $submission->get_result();
$slp = $result['shopline_payment'] ?? [];

$assert( 'payment_required' === ( $result['status'] ?? '' ), 'CF7 submission requires payment' );
$assert( ! empty( $slp['session_url'] ), 'CF7 submission returns SHOPLINE session URL' );
$assert( ! empty( $slp['order_token'] ) && MXP_SLP_Security::is_valid_order_token( $slp['order_token'] ), 'CF7 submission returns valid order token' );

$created_order_id = ! empty( $slp['order_token'] ) ? MXP_SLP_Order::find_by_token( $slp['order_token'] ) : null;
$assert( is_int( $created_order_id ) && $created_order_id > 0, 'CF7 submission creates local order' );

if ( $created_order_id ) {
	$assert( 'CREATED' === get_post_meta( $created_order_id, '_slp_status', true ), 'created order starts as CREATED' );
	$assert( '401' === (string) get_post_meta( $created_order_id, '_slp_amount', true ), 'created order stores configured amount' );
	$referer_url = get_post_meta( $created_order_id, '_slp_referer_url', true );
	$assert(
		home_url( '/payment-test/' ) === $referer_url,
		sprintf( 'created order stores form page referer; got %s', $referer_url )
	);
}

$session_id = $created_order_id ? get_post_meta( $created_order_id, '_slp_session_id', true ) : '';
$assert( '' !== $session_id, 'created order stores SHOPLINE session id' );

$event_id = 'evt_complete_' . wp_generate_password( 8, false, false );
$payload = [
	'id'   => $event_id,
	'type' => 'session.succeeded',
	'data' => [
		'sessionId'      => $session_id,
		'paymentDetails' => [
			[
				'tradeOrderId'  => 'trade_complete_1',
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

$assert( 200 === $webhook_response->get_status(), 'signed session.succeeded webhook returns 200' );
if ( $created_order_id ) {
	$assert( 'SUCCEEDED' === get_post_meta( $created_order_id, '_slp_status', true ), 'webhook marks CF7-created order succeeded' );
	$assert( 'trade_complete_1' === get_post_meta( $created_order_id, '_slp_trade_order_id', true ), 'webhook stores trade id on CF7-created order' );
}

$status_request = new \WP_REST_Request( 'GET', '/mxp-cf7-slp/v1/order-status' );
$status_request->set_param( 'token', $slp['order_token'] ?? '' );
$status_response = MXP_SLP_Return_Page::get_instance()->handle_order_status( $status_request );
$status_data = $status_response->get_data();

$assert( 200 === $status_response->get_status(), 'order-status returns 200 for CF7-created order' );
$assert( 'SUCCEEDED' === ( $status_data['status'] ?? '' ), 'order-status reports succeeded after webhook' );
$assert( 'SLP-' === substr( (string) ( $status_data['order_number'] ?? '' ), 0, 4 ), 'order-status includes order number' );
$assert( 'b***r@example.com' === ( $status_data['customer_email_masked'] ?? '' ), 'order-status masks customer email' );

$cleanup();

if ( $failures ) {
	foreach ( $failures as $failure ) {
		WP_CLI::error( $failure, false );
	}
	WP_CLI::halt( 1 );
}

WP_CLI::success( 'MXP CF7 Shopline Payment complete flow checks passed.' );
