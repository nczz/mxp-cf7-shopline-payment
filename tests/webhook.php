<?php
/**
 * Local webhook integration checks.
 *
 * Run from the project root:
 * ddev exec 'wp --path=/var/www/html/wordpress eval-file /var/www/html/repo/tests/webhook.php'
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$failures = [];
$created_order_ids = [];
$event_ids = [];

$assert = static function( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$send_webhook = static function( array $payload, ?string $timestamp = null, ?string $override_sign = null ): \WP_REST_Response {
	$body = wp_json_encode( $payload );
	$timestamp = $timestamp ?: (string) ( time() * 1000 );
	$sign_key = MXP_SLP_API::get_instance()->get_sign_key();
	$sign = $override_sign ?? hash_hmac( 'sha256', $timestamp . '.' . $body, $sign_key );

	$request = new \WP_REST_Request( 'POST', '/mxp-cf7-slp/v1/webhook' );
	$request->set_body( $body );
	$request->set_header( 'timestamp', $timestamp );
	$request->set_header( 'sign', $sign );

	return MXP_SLP_Webhook::get_instance()->handle( $request );
};

$sign_key = MXP_SLP_API::get_instance()->get_sign_key();
if ( '' === $sign_key ) {
	WP_CLI::error( 'Webhook test requires a configured SHOPLINE Sign Key.' );
}

$token = str_repeat( 'd', 32 );
$session_id = 'smoke-webhook-session-' . wp_generate_password( 8, false, false );
$order_id = MXP_SLP_Order::create( [
	'token'        => $token,
	'session_id'   => $session_id,
	'reference_id' => $token,
	'form_id'      => 0,
	'posted_data'  => [ 'your-email' => 'buyer@example.com' ],
	'amount'       => 401,
	'currency'     => 'TWD',
	'status'       => 'CREATED',
	'mail_sent'    => false,
	'retry_count'  => 0,
	'referer_url'  => home_url( '/payment-test/' ),
] );
$created_order_ids[] = $order_id;

$event_id = 'evt_smoke_' . wp_generate_password( 8, false, false );
$event_ids[] = $event_id;
$payload = [
	'id'   => $event_id,
	'type' => 'session.succeeded',
	'data' => [
		'sessionId'      => $session_id,
		'paymentDetails' => [
			[
				'tradeOrderId'  => 'trade_smoke_1',
				'paymentMethod' => 'CreditCard',
			],
		],
	],
];

$response = $send_webhook( $payload );
$assert( 200 === $response->get_status(), 'valid session.succeeded webhook returns 200' );
$assert( 'SUCCEEDED' === get_post_meta( $order_id, '_slp_status', true ), 'session.succeeded marks order succeeded' );
$assert( 'trade_smoke_1' === get_post_meta( $order_id, '_slp_trade_order_id', true ), 'session.succeeded stores trade order id' );
$assert( 'CreditCard' === get_post_meta( $order_id, '_slp_payment_method', true ), 'session.succeeded stores payment method' );

$duplicate = $send_webhook( $payload );
$duplicate_data = $duplicate->get_data();
$assert( 200 === $duplicate->get_status() && 'already_processed' === ( $duplicate_data['status'] ?? '' ), 'duplicate webhook is idempotent' );

$bad_signature = $send_webhook( [
	'id'   => 'evt_bad_signature',
	'type' => 'session.succeeded',
	'data' => [ 'sessionId' => $session_id ],
], null, 'bad-signature' );
$assert( 401 === $bad_signature->get_status(), 'invalid webhook signature is rejected' );

$expired_event_id = 'evt_expired_' . wp_generate_password( 8, false, false );
$event_ids[] = $expired_event_id;
$old_timestamp = (string) ( ( time() - 600 ) * 1000 );
$expired = $send_webhook( [
	'id'   => $expired_event_id,
	'type' => 'session.succeeded',
	'data' => [ 'sessionId' => $session_id ],
], $old_timestamp );
$assert( 401 === $expired->get_status(), 'expired webhook timestamp is rejected' );

$failed_token = str_repeat( 'e', 32 );
$failed_order_id = MXP_SLP_Order::create( [
	'token'        => $failed_token,
	'session_id'   => 'smoke-webhook-failed',
	'reference_id' => $failed_token,
	'form_id'      => 0,
	'posted_data'  => [ 'your-email' => 'buyer@example.com' ],
	'amount'       => 401,
	'currency'     => 'TWD',
	'status'       => 'CREATED',
	'mail_sent'    => false,
	'retry_count'  => 0,
	'referer_url'  => home_url( '/payment-test/' ),
] );
$created_order_ids[] = $failed_order_id;

$failed_event_id = 'evt_failed_' . wp_generate_password( 8, false, false );
$event_ids[] = $failed_event_id;
$failed = $send_webhook( [
	'id'   => $failed_event_id,
	'type' => 'trade.failed',
	'data' => [
		'tradeOrderId'     => 'trade_failed_1',
		'referenceOrderId' => $failed_token,
		'paymentMsg'       => [
			'code' => 'TEST_FAILED',
			'msg'  => 'Smoke failure',
		],
	],
] );
$assert( 200 === $failed->get_status(), 'valid trade.failed webhook returns 200' );
$assert( 'FAILED' === get_post_meta( $failed_order_id, '_slp_status', true ), 'trade.failed marks order failed' );
$assert( 'TEST_FAILED' === get_post_meta( $failed_order_id, '_slp_error_code', true ), 'trade.failed stores error code' );

foreach ( $created_order_ids as $created_order_id ) {
	if ( $created_order_id ) {
		wp_delete_post( $created_order_id, true );
	}
}

foreach ( $event_ids as $id ) {
	delete_option( '_slp_evt_' . substr( md5( $id ), 0, 16 ) );
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		WP_CLI::error( $failure, false );
	}
	WP_CLI::halt( 1 );
}

WP_CLI::success( 'MXP CF7 Shopline Payment webhook checks passed.' );
