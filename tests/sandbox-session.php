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

$form_id = 5;
$original_settings = get_post_meta( $form_id, '_slp_payment_settings', true );
update_post_meta( $form_id, '_slp_payment_settings', [
	'enabled'           => true,
	'amount_mode'       => 'fixed',
	'amount'            => 401,
	'amount_min'        => 1,
	'amount_max'        => 10000000,
	'amount_field'      => '',
	'suggested_amounts' => [ 300, 500, 1000 ],
	'currency'          => 'TWD',
	'payment_methods'   => [ 'CreditCard', 'LinePay' ],
	'cc_installments'   => [ '0', '3', '6' ],
	'simple_mode'       => true,
	'button_text'       => '',
] );

$token = MXP_SLP_Security::generate_order_token();
$body = MXP_SLP_Request_Builder::build_session_request(
	$form_id,
	[
		'your-name'  => '王小明',
		'your-email' => 'buyer@example.com',
		'your-tel'   => '0912345678',
	],
	$token,
	home_url( '/slp-payment-return/?token=' . $token )
);

if ( false === $original_settings || '' === $original_settings ) {
	delete_post_meta( $form_id, '_slp_payment_settings' );
} else {
	update_post_meta( $form_id, '_slp_payment_settings', $original_settings );
}

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
