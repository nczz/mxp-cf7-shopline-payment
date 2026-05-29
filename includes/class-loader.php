<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MXP_SLP_Loader {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ], 20 );
		register_activation_hook( MXP_SLP_PLUGIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( MXP_SLP_PLUGIN_FILE, [ $this, 'deactivate' ] );
	}

	public function init(): void {
		if ( ! $this->check_dependencies() ) {
			return;
		}

		$this->load_textdomain();
		$this->load_includes();

		// Cron 清理過期訂單
		add_action( 'mxp_slp_cleanup_events', [ $this, 'cleanup_expired_orders' ] );
		if ( ! wp_next_scheduled( 'mxp_slp_cleanup_events' ) ) {
			wp_schedule_event( time(), 'hourly', 'mxp_slp_cleanup_events' );
		}

		if ( is_admin() ) {
			$this->load_admin();
		}
	}

	private function check_dependencies(): bool {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			add_action( 'admin_notices', [ $this, 'notice_cf7_missing' ] );
			return false;
		}

		if ( version_compare( WPCF7_VERSION, '5.9', '<' ) ) {
			add_action( 'admin_notices', [ $this, 'notice_cf7_version' ] );
			return false;
		}

		return true;
	}

	private function load_textdomain(): void {
		load_plugin_textdomain(
			'mxp-cf7-slp',
			false,
			dirname( MXP_SLP_PLUGIN_BASENAME ) . '/languages/'
		);
	}

	private function load_includes(): void {
		require_once MXP_SLP_PLUGIN_DIR . '/includes/class-security.php';
		require_once MXP_SLP_PLUGIN_DIR . '/includes/class-api.php';
		require_once MXP_SLP_PLUGIN_DIR . '/includes/class-order.php';
		require_once MXP_SLP_PLUGIN_DIR . '/includes/class-request-builder.php';
		require_once MXP_SLP_PLUGIN_DIR . '/includes/class-webhook.php';
		require_once MXP_SLP_PLUGIN_DIR . '/includes/class-mail-handler.php';
		require_once MXP_SLP_PLUGIN_DIR . '/includes/class-form-tag.php';
		require_once MXP_SLP_PLUGIN_DIR . '/includes/class-form-handler.php';
		require_once MXP_SLP_PLUGIN_DIR . '/includes/class-return-page.php';

		MXP_SLP_Order::get_instance();

		// Admin REST routes 需要在所有環境註冊（REST 請求不走 is_admin）
		add_action( 'rest_api_init', [ $this, 'register_admin_routes' ] );
	}

	public function register_admin_routes(): void {
		register_rest_route( 'mxp-cf7-slp/v1', '/admin/test-connection', [
			'methods'             => 'POST',
			'callback'            => function() {
				$api = MXP_SLP_API::get_instance();
				$success = $api->test_connection();
				return new \WP_REST_Response( [
					'success' => $success,
					'message' => $success ? __( '連線成功', 'mxp-cf7-slp' ) : __( '連線失敗', 'mxp-cf7-slp' ),
				] );
			},
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( 'mxp-cf7-slp/v1', '/admin/refund', [
			'methods'             => 'POST',
			'callback'            => function( $request ) {
				$order_id = absint( $request->get_param( 'order_id' ) );
				$amount   = absint( $request->get_param( 'amount' ) );
				$reason   = sanitize_text_field( $request->get_param( 'reason' ) ?: '' );

				if ( ! $order_id || ! $amount ) {
					return new \WP_REST_Response( [ 'success' => false, 'message' => '參數錯誤' ], 400 );
				}

				$trade_id = get_post_meta( $order_id, '_slp_trade_order_id', true );
				if ( ! $trade_id ) {
					return new \WP_REST_Response( [ 'success' => false, 'message' => '無付款交易 ID' ], 400 );
				}

				$api = MXP_SLP_API::get_instance();
				$result = $api->create_refund( $trade_id, $amount * 100, $reason );

				if ( $result ) {
					update_post_meta( $order_id, '_slp_status', 'REFUNDED' );
					return new \WP_REST_Response( [ 'success' => true ] );
				}

				return new \WP_REST_Response( [ 'success' => false, 'message' => '退款失敗' ], 500 );
			},
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( 'mxp-cf7-slp/v1', '/admin/export-csv', [
			'methods'             => 'GET',
			'callback'            => function() {
				$orders = get_posts( [ 'post_type' => 'slp_order', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC' ] );
				header( 'Content-Type: text/csv; charset=UTF-8' );
				header( 'Content-Disposition: attachment; filename="slp-orders-' . date( 'Y-m-d' ) . '.csv"' );
				$out = fopen( 'php://output', 'w' );
				fprintf( $out, chr(0xEF) . chr(0xBB) . chr(0xBF) );
				fputcsv( $out, [ '訂單', '金額', '狀態', '付款方式', 'Email', '日期' ] );
				foreach ( $orders as $o ) {
					$posted = get_post_meta( $o->ID, '_slp_posted_data', true );
					$email = is_array( $posted ) ? ( MXP_SLP_Request_Builder::auto_detect_mapping( 0, $posted )['email'] ?? '' ) : '';
					fputcsv( $out, [ $o->post_title, get_post_meta($o->ID,'_slp_amount',true), get_post_meta($o->ID,'_slp_status',true), get_post_meta($o->ID,'_slp_payment_method',true), $email, get_the_date('Y-m-d H:i',$o) ] );
				}
				fclose( $out );
				exit;
			},
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( 'mxp-cf7-slp/v1', '/admin/resend-mail', [
			'methods'             => 'POST',
			'callback'            => function( $request ) {
				$token = sanitize_text_field( $request->get_param( 'token' ) );
				if ( ! $token ) {
					return new \WP_REST_Response( [ 'success' => false, 'message' => '缺少 token' ], 400 );
				}
				$order_id = MXP_SLP_Order::find_by_token( $token );
				if ( ! $order_id ) {
					return new \WP_REST_Response( [ 'success' => false, 'message' => '找不到訂單' ], 404 );
				}
				// 重置 mail_sent 標記以允許重發
				delete_post_meta( $order_id, '_slp_mail_lock' );
				update_post_meta( $order_id, '_slp_mail_sent', false );
				$sent = MXP_SLP_Mail_Handler::send_payment_confirmation( $token );
				return new \WP_REST_Response( [ 'success' => $sent ] );
			},
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );
	}

	public function cleanup_expired_orders(): void {
		$expired = get_posts( [
			'post_type'   => 'slp_order',
			'numberposts' => 50,
			'meta_query'  => [
				[ 'key' => '_slp_status', 'value' => 'CREATED' ],
			],
			'date_query'  => [
				[ 'before' => '24 hours ago' ],
			],
		] );

		foreach ( $expired as $order ) {
			update_post_meta( $order->ID, '_slp_status', 'EXPIRED' );
		}
	}

	private function load_admin(): void {
		require_once MXP_SLP_PLUGIN_DIR . '/admin/class-settings.php';
		require_once MXP_SLP_PLUGIN_DIR . '/admin/class-payment-panel.php';
		require_once MXP_SLP_PLUGIN_DIR . '/admin/class-service.php';
		require_once MXP_SLP_PLUGIN_DIR . '/admin/class-onboarding.php';
	}

	public function activate(): void {
		$this->create_return_page();
	}

	public function deactivate(): void {
		wp_clear_scheduled_hook( 'mxp_slp_cleanup_events' );
	}

	private function create_return_page(): void {
		$existing = get_page_by_path( 'slp-payment-return' );
		if ( $existing ) {
			return;
		}

		wp_insert_post( [
			'post_title'   => __( '付款結果', 'mxp-cf7-slp' ),
			'post_name'    => 'slp-payment-return',
			'post_content' => '[slp_return_page]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		] );
	}

	public function notice_cf7_missing(): void {
		$install_url = wp_nonce_url(
			admin_url( 'plugin-install.php?s=contact+form+7&tab=search&type=term' ),
			'install-plugin_contact-form-7'
		);
		printf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'MXP CF7 Shopline Payment 需要 Contact Form 7 才能運作。', 'mxp-cf7-slp' ),
			esc_url( $install_url ),
			esc_html__( '安裝 Contact Form 7', 'mxp-cf7-slp' )
		);
	}

	public function notice_cf7_version(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'MXP CF7 Shopline Payment 需要 Contact Form 7 5.9 或更新版本。', 'mxp-cf7-slp' )
		);
	}
}

MXP_SLP_Loader::get_instance();
