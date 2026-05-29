<?php
/**
 * SHOPLINE sandbox API smoke check.
 *
 * Run from the project root:
 * ddev exec 'wp --path=/var/www/html/wordpress eval-file /var/www/html/repo/tests/sandbox-session.php'
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$api = MXP_SLP_API::get_instance();
if ( ! $api->has_credentials() ) {
	WP_CLI::error( 'Sandbox session test requires configured SHOPLINE Merchant ID and API Key.' );
}

$token = MXP_SLP_Security::generate_order_token();
$body = MXP_SLP_Request_Builder::build_session_request(
	5,
	[
		'your-name'  => '王小明',
		'your-email' => 'buyer@example.com',
		'your-tel'   => '0912345678',
	],
	$token,
	home_url( '/slp-payment-return/?token=' . $token )
);

$session = $api->create_session( $body );
if ( ! is_array( $session ) || empty( $session['sessionId'] ) || empty( $session['sessionUrl'] ) ) {
	WP_CLI::error( 'SHOPLINE sandbox create_session did not return sessionId/sessionUrl.' );
}

$queried = $api->query_session( $session['sessionId'] );
if ( ! is_array( $queried ) ) {
	WP_CLI::error( 'SHOPLINE sandbox query_session failed for created session.' );
}

WP_CLI::success( sprintf(
	'SHOPLINE sandbox session created and queried: %s',
	$session['sessionId']
) );
