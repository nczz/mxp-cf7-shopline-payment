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

$fixed_amount = MXP_SLP_Request_Builder::resolve_amount( [], [
	'amount_mode' => 'fixed',
	'amount'      => 401,
	'amount_min'  => 1,
	'amount_max'  => 1000,
] );
$assert( 401 === $fixed_amount['amount'] && '' === $fixed_amount['error'], 'fixed amount resolves within range' );

$user_amount = MXP_SLP_Request_Builder::resolve_amount( [ 'slp_amount' => '1,200' ], [
	'amount_mode' => 'user_input',
	'amount_min'  => 100,
	'amount_max'  => 2000,
] );
$assert( 1200 === $user_amount['amount'] && 'user_input' === $user_amount['source'], 'user-input amount resolves with comma formatting' );

$field_amount = MXP_SLP_Request_Builder::resolve_amount( [ 'donation-amount' => '300' ], [
	'amount_mode'  => 'field_mapping',
	'amount_field' => 'donation-amount',
	'amount_min'   => 100,
	'amount_max'   => 500,
] );
$assert( 300 === $field_amount['amount'] && 'donation-amount' === $field_amount['field'], 'field-mapped amount resolves from configured CF7 field' );

$low_amount = MXP_SLP_Request_Builder::resolve_amount( [ 'slp_amount' => '99' ], [
	'amount_mode' => 'user_input',
	'amount_min'  => 100,
	'amount_max'  => 2000,
] );
$assert( '' !== $low_amount['error'], 'amount below minimum fails validation' );

$form = wpcf7_contact_form( 5 );
if ( $form ) {
	$original_settings = get_post_meta( 5, '_slp_payment_settings', true );
	update_post_meta( 5, '_slp_payment_settings', [
		'enabled'           => true,
		'amount_mode'       => 'fixed',
		'amount'            => 401,
		'amount_min'        => 1,
		'amount_max'        => 10000000,
		'amount_field'      => '',
		'suggested_amounts' => [],
		'currency'          => 'TWD',
		'payment_methods'   => [ 'CreditCard' ],
		'cc_installments'   => [ '0', '3', '6' ],
		'simple_mode'       => true,
		'button_text'       => '',
	] );
	$GLOBALS['wpcf7_contact_form'] = $form;
	$tag = new WPCF7_FormTag( [ 'type' => 'shopline_payment', 'name' => 'shopline_payment', 'raw_values' => [], 'values' => [] ] );
	$payment_tag_html = mxp_slp_form_tag_handler( $tag );
	unset( $GLOBALS['wpcf7_contact_form'] );
	if ( false === $original_settings || '' === $original_settings ) {
		delete_post_meta( 5, '_slp_payment_settings' );
	} else {
		update_post_meta( 5, '_slp_payment_settings', $original_settings );
	}
	$assert( false !== strpos( $payment_tag_html, 'data-sdk-amount="40100"' ), 'fixed amount SDK widget exposes amount in cents' );
	$assert( false !== strpos( $payment_tag_html, 'data-cc-installments="[&quot;0&quot;,&quot;3&quot;,&quot;6&quot;]"' ), 'fixed amount SDK widget exposes credit-card installments' );
}

$normalized_installments = MXP_SLP_Request_Builder::normalize_settings( [
	'cc_installments' => [ 3, '6', 'invalid', '6' ],
] );
$assert( [ '3', '6' ] === $normalized_installments['cc_installments'], 'credit-card installments normalize to unique strings' );

require_once WP_PLUGIN_DIR . '/mxp-cf7-shopline-payment/admin/class-payment-panel.php';
$form = wpcf7_contact_form( 5 );
if ( $form ) {
	$original_settings = get_post_meta( 5, '_slp_payment_settings', true );
	update_post_meta( 5, '_slp_payment_settings', [
		'enabled'           => true,
		'amount_mode'       => 'fixed',
		'amount'            => 401,
		'amount_min'        => 1,
		'amount_max'        => 10000000,
		'amount_field'      => '',
		'suggested_amounts' => [],
		'currency'          => 'TWD',
		'payment_methods'   => [ 'CreditCard' ],
		'cc_installments'   => [ '3', '6' ],
		'simple_mode'       => true,
		'button_text'       => '',
	] );
	ob_start();
	MXP_SLP_Payment_Panel::get_instance()->render_panel( $form );
	$payment_panel_html = ob_get_clean();
	if ( false === $original_settings || '' === $original_settings ) {
		delete_post_meta( 5, '_slp_payment_settings' );
	} else {
		update_post_meta( 5, '_slp_payment_settings', $original_settings );
	}
	$assert( false !== strpos( $payment_panel_html, 'value="3"  checked' ), 'payment panel restores checked 3-installment option' );
	$assert( false !== strpos( $payment_panel_html, 'value="6"  checked' ), 'payment panel restores checked 6-installment option' );
}

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
