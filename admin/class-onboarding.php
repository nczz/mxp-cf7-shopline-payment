<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MXP_SLP_Onboarding {

	public function __construct() {
		add_action( 'admin_notices', [ $this, 'maybe_show_notice' ] );
	}

	public function maybe_show_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = get_option( 'mxp_slp_settings', [] );
		$env = $settings['environment'] ?? 'sandbox';

		if ( ! empty( $settings[ $env . '_api_key' ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( '🎉 SHOPLINE Payments 已啟用！請先設定 API 金鑰以開始收款。', 'mxp-cf7-slp' ),
			esc_url( admin_url( 'admin.php?page=mxp-slp-settings' ) ),
			esc_html__( '前往設定', 'mxp-cf7-slp' )
		);
	}
}

new MXP_SLP_Onboarding();
