<?php
/**
 * Local smoke checks for MXP CF7 Shopline Payment.
 *
 * Run from the project root:
 * ddev exec 'wp --path=/var/www/html/wordpress eval-file /var/www/html/repo/tests/smoke.php'
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$failures = [];

$assert = static function( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( class_exists( 'MXP_SLP_Security' ), 'MXP_SLP_Security is loaded' );
$assert( class_exists( 'MXP_SLP_API' ), 'MXP_SLP_API is loaded' );
$assert( class_exists( 'MXP_SLP_Request_Builder' ), 'MXP_SLP_Request_Builder is loaded' );
$assert( class_exists( 'MXP_SLP_Order' ), 'MXP_SLP_Order is loaded' );

$secret = 'smoke-secret-' . wp_generate_password( 8, false, false );
$encrypted = MXP_SLP_Security::encrypt( $secret );
$assert( '' !== $encrypted && $encrypted !== $secret, 'secrets are encrypted at rest' );
$assert( $secret === MXP_SLP_Security::decrypt( $encrypted ), 'encrypted secrets decrypt correctly' );
$assert( '' === MXP_SLP_Security::decrypt( 'v2:not-base64' ), 'tampered v2 secrets fail closed' );
$assert( MXP_SLP_Security::is_valid_order_token( str_repeat( 'a', 32 ) ), '32-char order token is valid' );
$assert( ! MXP_SLP_Security::is_valid_order_token( str_repeat( 'a', 31 ) ), 'short order token is invalid' );
$assert( ! MXP_SLP_Security::is_valid_order_token( str_repeat( 'a', 31 ) . '-' ), 'non-alphanumeric order token is invalid' );
$assert( MXP_SLP_Security::validate_amount( 1 ), 'minimum positive amount is valid' );
$assert( ! MXP_SLP_Security::validate_amount( 0 ), 'zero amount is invalid' );
$assert( MXP_SLP_Security::check_rate_limit( 'smoke-' . wp_generate_uuid4(), 1, 60 ), 'rate limiter allows first hit' );

$api = MXP_SLP_API::get_instance();
$assert( is_bool( $api->has_credentials() ), 'credential readiness check returns a boolean' );

$request = MXP_SLP_Request_Builder::build_session_request(
	0,
	[
		'your-email' => 'buyer@example.com',
		'your-name'  => '王小明',
		'your-tel'   => '0912345678',
	],
	str_repeat( 'b', 32 ),
	home_url( '/slp-payment-return/?token=' . str_repeat( 'b', 32 ) )
);

$assert( [ 'CreditCard' ] === ( $request['allowPaymentMethodList'] ?? [] ), 'payment methods fall back to CreditCard' );
$assert( 0 === ( $request['amount']['value'] ?? null ), 'missing form amount remains explicit in request builder' );
$assert( '+886912345678' === ( $request['customer']['personalInfo']['phone'] ?? '' ), 'Taiwan mobile numbers are normalized' );

$order_id = MXP_SLP_Order::create( [
	'token'        => str_repeat( 'c', 32 ),
	'session_id'   => 'smoke-session',
	'reference_id' => str_repeat( 'c', 32 ),
	'form_id'      => 0,
	'posted_data'  => [ 'your-email' => 'buyer@example.com' ],
	'amount'       => 100,
	'currency'     => 'TWD',
	'status'       => 'CREATED',
	'mail_sent'    => false,
	'retry_count'  => 0,
	'referer_url'  => home_url( '/' ),
] );

$assert( is_int( $order_id ) && $order_id > 0, 'order can be created' );
if ( is_int( $order_id ) && $order_id > 0 ) {
	$assert( $order_id === MXP_SLP_Order::find_by_token( str_repeat( 'c', 32 ) ), 'order can be found by token' );
	$assert( $order_id === MXP_SLP_Order::find_by_session_id( 'smoke-session' ), 'order can be found by session id' );
	$assert( MXP_SLP_Order::update_status( $order_id, 'SUCCEEDED' ), 'order transitions from CREATED to SUCCEEDED' );
	$assert( ! MXP_SLP_Order::update_status( $order_id, 'FAILED' ), 'terminal SUCCEEDED order does not regress to FAILED' );
	wp_delete_post( $order_id, true );
}

$old_event_key = '_slp_evt_' . substr( md5( 'old-smoke-event' ), 0, 16 );
update_option( $old_event_key, time() - ( 91 * DAY_IN_SECONDS ), false );
MXP_SLP_Loader::get_instance()->cleanup_expired_orders();
$assert( false === get_option( $old_event_key, false ), 'cleanup removes old webhook event dedupe records' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		WP_CLI::error( $failure, false );
	}
	WP_CLI::halt( 1 );
}

WP_CLI::success( 'MXP CF7 Shopline Payment smoke checks passed.' );
