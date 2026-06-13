<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MXP_SLP_Settings {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		// REST routes 已移至 class-loader.php
	}

	public function add_menu(): void {
		add_submenu_page(
			'wpcf7',
			__( 'Shopline Payment', 'mxp-cf7-slp' ),
			__( 'Shopline Payment', 'mxp-cf7-slp' ),
			'manage_options',
			'mxp-slp-settings',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'mxp_slp_settings', 'mxp_slp_settings', [
			'sanitize_callback' => [ $this, 'sanitize_settings' ],
		] );
	}

	public function sanitize_settings( array $input ): array {
		$old = get_option( 'mxp_slp_settings', [] );
		$environment = in_array( $input['environment'] ?? '', [ 'sandbox', 'production' ], true ) ? $input['environment'] : 'sandbox';

		return [
			'environment'          => $environment,
			'sandbox_merchant_id'  => sanitize_text_field( $input['sandbox_merchant_id'] ?? $old['sandbox_merchant_id'] ?? '' ),
			'sandbox_api_key'      => ! empty( $input['sandbox_api_key'] ) ? MXP_SLP_Security::encrypt( sanitize_text_field( $input['sandbox_api_key'] ) ) : ( $old['sandbox_api_key'] ?? '' ),
			'sandbox_sign_key'     => ! empty( $input['sandbox_sign_key'] ) ? MXP_SLP_Security::encrypt( sanitize_text_field( $input['sandbox_sign_key'] ) ) : ( $old['sandbox_sign_key'] ?? '' ),
			'live_merchant_id'     => sanitize_text_field( $input['live_merchant_id'] ?? $old['live_merchant_id'] ?? '' ),
			'live_api_key'         => ! empty( $input['live_api_key'] ) ? MXP_SLP_Security::encrypt( sanitize_text_field( $input['live_api_key'] ) ) : ( $old['live_api_key'] ?? '' ),
			'live_sign_key'        => ! empty( $input['live_sign_key'] ) ? MXP_SLP_Security::encrypt( sanitize_text_field( $input['live_sign_key'] ) ) : ( $old['live_sign_key'] ?? '' ),
			'sandbox_client_key'      => sanitize_text_field( $input['sandbox_client_key'] ?? $old['sandbox_client_key'] ?? '' ),
			'live_client_key'         => sanitize_text_field( $input['live_client_key'] ?? $old['live_client_key'] ?? '' ),
			'default_payment_methods' => array_filter( (array) ( $input['default_payment_methods'] ?? [] ), fn( $m ) => in_array( $m, [ 'CreditCard', 'ApplePay', 'LinePay', 'VirtualAccount', 'JKOPay', 'ChaileaseBNPL' ], true ) ),
		];
	}

	public function render_page(): void {
		$settings = get_option( 'mxp_slp_settings', [] );
		$env = $settings['environment'] ?? 'sandbox';
		$prefix = 'production' === $env ? 'live' : $env;
		$webhook_url = rest_url( 'mxp-cf7-slp/v1/webhook' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SHOPLINE Payments 設定', 'mxp-cf7-slp' ); ?></h1>
			<?php
			if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( '設定已儲存', 'mxp-cf7-slp' ) );
			}
			?>

			<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin:16px 0;">
				<h4 style="margin:0 0 8px"><?php esc_html_e( '如何取得金鑰？', 'mxp-cf7-slp' ); ?></h4>
				<ol style="margin:0;padding-left:20px;">
					<li><?php printf( __( '前往 %s 註冊帳號', 'mxp-cf7-slp' ), '<a href="https://www.shoplinepayments.com/" target="_blank">SHOPLINE Payments</a>' ); ?></li>
					<li><?php printf( __( '登入 %s', 'mxp-cf7-slp' ), '<a href="https://login.shoplinepayments.com/" target="_blank">Payment Center</a>' ); ?></li>
					<li><?php esc_html_e( '進入「設定 → 開發者管理」取得 API Key 和 Sign Key', 'mxp-cf7-slp' ); ?></li>
				</ol>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'mxp_slp_settings' ); ?>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( '環境', 'mxp-cf7-slp' ); ?></th>
						<td>
							<label><input type="radio" name="mxp_slp_settings[environment]" value="sandbox" <?php checked( $env, 'sandbox' ); ?>> <?php esc_html_e( '沙盒測試', 'mxp-cf7-slp' ); ?></label>&nbsp;&nbsp;
							<label><input type="radio" name="mxp_slp_settings[environment]" value="production" <?php checked( $env, 'production' ); ?>> <?php esc_html_e( '正式環境', 'mxp-cf7-slp' ); ?></label>
							<p class="description"><?php printf( esc_html__( '目前使用 %s 環境的金鑰。切換環境後請重新填入對應的金鑰。', 'mxp-cf7-slp' ), '<strong>' . esc_html( 'sandbox' === $env ? '沙盒' : '正式' ) . '</strong>' ); ?></p>
						</td>
					</tr>
					<tr>
						<th>Merchant ID</th>
						<td><input type="text" name="mxp_slp_settings[<?php echo esc_attr( $prefix ); ?>_merchant_id]" value="<?php echo esc_attr( $settings[ $prefix . '_merchant_id' ] ?? '' ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th>API Key</th>
						<td><input type="password" name="mxp_slp_settings[<?php echo esc_attr( $prefix ); ?>_api_key]" value="" class="regular-text" placeholder="<?php echo ! empty( $settings[ $prefix . '_api_key' ] ) ? '••••••（已設定）' : ''; ?>"></td>
					</tr>
					<tr>
						<th>Sign Key</th>
						<td><input type="password" name="mxp_slp_settings[<?php echo esc_attr( $prefix ); ?>_sign_key]" value="" class="regular-text" placeholder="<?php echo ! empty( $settings[ $prefix . '_sign_key' ] ) ? '••••••（已設定）' : ''; ?>"></td>
					</tr>
					<tr>
						<th>Client Key <small style="color:#666;">(SDK)</small></th>
						<td><input type="text" name="mxp_slp_settings[<?php echo esc_attr( $prefix ); ?>_client_key]" value="<?php echo esc_attr( $settings[ $prefix . '_client_key' ] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( '選填，內嵌式付款需要', 'mxp-cf7-slp' ); ?>"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( '連線測試', 'mxp-cf7-slp' ); ?></th>
						<td>
							<button type="button" id="slp-test-btn" class="button"><?php esc_html_e( '測試連線', 'mxp-cf7-slp' ); ?></button>
							<span id="slp-test-result" style="margin-left:10px;"></span>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Webhook', 'mxp-cf7-slp' ); ?></h2>
				<table class="form-table">
					<tr>
						<th>Webhook URL</th>
						<td>
							<code id="slp-wh-url"><?php echo esc_html( $webhook_url ); ?></code>
							<button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('slp-wh-url').textContent)"><?php esc_html_e( '複製', 'mxp-cf7-slp' ); ?></button>
							<p class="description"><?php esc_html_e( '請在 SLP 後台「開發者管理 → Webhook 管理」設定此 URL，並訂閱：session.succeeded、session.expired', 'mxp-cf7-slp' ); ?></p>
							<?php
							$last = get_option( '_slp_webhook_last_received' );
							if ( $last ) {
								printf( '<p>✅ 最後接收：%s（%s前）</p>', esc_html( $last['event'] ), esc_html( human_time_diff( $last['time'] ) ) );
							}
							?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php $this->render_recent_orders(); ?>

			<script>
			document.getElementById('slp-test-btn')?.addEventListener('click', async function() {
				const r = document.getElementById('slp-test-result');
				this.disabled = true; r.textContent = '...';
				try {
					const res = await fetch('<?php echo esc_url( rest_url( 'mxp-cf7-slp/v1/admin/test-connection' ) ); ?>', {
						method: 'POST', headers: {'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'}, credentials: 'same-origin'
					});
					const d = await res.json();
					r.textContent = (d.success ? '✅ ' : '❌ ') + d.message;
					r.style.color = d.success ? '#00a32a' : '#d63638';
				} catch(e) { r.textContent = '❌ 網路錯誤'; r.style.color = '#d63638'; }
				this.disabled = false;
			});
			</script>
		</div>
		<?php
	}

	private function render_recent_orders(): void {
		$count = wp_count_posts( 'slp_order' );
		$total = ( $count->private ?? 0 ) + ( $count->publish ?? 0 );
		?>
		<h2 style="display:inline-block;"><?php esc_html_e( '交易記錄', 'mxp-cf7-slp' ); ?></h2>
		<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=slp_order' ) ); ?>" class="button" style="margin-left:12px;"><?php printf( esc_html__( '查看所有訂單（%d）', 'mxp-cf7-slp' ), $total ); ?></a>
		<a href="<?php echo esc_url( rest_url( 'mxp-cf7-slp/v1/admin/export-csv' ) . '?_wpnonce=' . wp_create_nonce( 'wp_rest' ) ); ?>" class="button" style="margin-left:8px;"><?php esc_html_e( '匯出 CSV', 'mxp-cf7-slp' ); ?></a>
		<?php if ( 0 === $total ) : ?>
			<p class="description"><?php esc_html_e( '尚無交易記錄', 'mxp-cf7-slp' ); ?></p>
		<?php endif;
	}
}

MXP_SLP_Settings::get_instance();
