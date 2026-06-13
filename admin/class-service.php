<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPCF7_Service' ) ) {
	return;
}

final class MXP_SLP_Service extends WPCF7_Service {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get_title(): string {
		return 'SHOPLINE Payments';
	}

	public function is_active(): bool {
		$settings = get_option( 'mxp_slp_settings', [] );
		$env = $settings['environment'] ?? 'sandbox';
		$prefix = 'production' === $env ? 'live' : $env;
		return ! empty( $settings[ $prefix . '_api_key' ] );
	}

	public function get_categories(): array {
		return [ 'payments' ];
	}

	public function link(): void {
		echo wp_kses_data( wpcf7_link(
			'https://www.shoplinepayments.com/',
			'shoplinepayments.com'
		) );
	}

	public function display( $action = '' ): void {
		$description = __( 'SHOPLINE Payments 是台灣在地金流服務，支援信用卡、LINE Pay、街口支付、ATM 轉帳、Apple Pay、中租零卡分期。', 'mxp-cf7-slp' );
		echo '<p>' . esc_html( $description ) . '</p>';

		if ( $this->is_active() ) {
			echo '<p class="dashicons-before dashicons-yes"> ' . esc_html__( 'SHOPLINE Payments 已啟用。', 'mxp-cf7-slp' ) . '</p>';
		}

		printf(
			'<p><a href="%s" class="button">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=mxp-slp-settings' ) ),
			esc_html__( '前往設定', 'mxp-cf7-slp' )
		);
	}
}

add_action( 'wpcf7_init', function() {
	$integration = WPCF7_Integration::get_instance();
	$integration->add_service( 'shopline-payments', MXP_SLP_Service::get_instance() );
}, 50 );
