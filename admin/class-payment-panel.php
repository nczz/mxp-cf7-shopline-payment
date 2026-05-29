<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MXP_SLP_Payment_Panel {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'wpcf7_editor_panels', [ $this, 'add_panel' ] );
		add_action( 'wpcf7_save_contact_form', [ $this, 'save_settings' ], 10, 3 );
		add_action( 'wpcf7_admin_init', [ $this, 'register_tag_generator' ], 60 );
	}

	public function register_tag_generator(): void {
		$tag_generator = WPCF7_TagGenerator::get_instance();
		$tag_generator->add(
			'shopline-payment',
			__( 'Shopline Payment', 'mxp-cf7-slp' ),
			[ $this, 'render_tag_generator_panel' ],
			[ 'version' => '2' ]
		);
	}

	public function render_tag_generator_panel( $contact_form, $options ): void {
		?>
		<header class="description-box">
			<h3><?php esc_html_e( '插入付款按鈕', 'mxp-cf7-slp' ); ?></h3>
			<p><?php esc_html_e( '在表單中插入 Shopline Payment 付款按鈕。請先在「付款」Tab 中設定金額和付款方式。', 'mxp-cf7-slp' ); ?></p>
		</header>
		<div class="control-box">
			<fieldset>
				<legend><?php esc_html_e( '按鈕文字', 'mxp-cf7-slp' ); ?></legend>
				<input type="text" id="slp-tag-btn-text" class="oneline" placeholder="<?php esc_attr_e( '前往付款', 'mxp-cf7-slp' ); ?>" />
			</fieldset>
		</div>
		<footer class="insert-box">
			<div class="insert-box-content">
				<input type="text" name="shopline_payment" class="tag code" readonly="readonly" onfocus="this.select()" value='[shopline_payment "前往付款"]' />
			</div>
			<div class="submitbox">
				<input type="button" class="button button-primary insert-tag" value="<?php echo esc_attr( __( '插入標籤', 'mxp-cf7-slp' ) ); ?>" />
			</div>
		</footer>
		<?php
	}

	public function add_panel( array $panels ): array {
		$panels['payment-panel'] = [
			'title'    => __( '付款', 'mxp-cf7-slp' ),
			'callback' => [ $this, 'render_panel' ],
		];
		return $panels;
	}

	public function render_panel( $contact_form ): void {
		$form_id = $contact_form->id();
		$settings = get_post_meta( $form_id, '_slp_payment_settings', true );
		$settings = MXP_SLP_Request_Builder::normalize_settings( wp_parse_args( $settings ?: [], $this->get_defaults() ) );
		$suggested_amounts = implode( ', ', $settings['suggested_amounts'] );

		$global = get_option( 'mxp_slp_settings', [] );
		$has_key = ! empty( $global[ ( $global['environment'] ?? 'sandbox' ) . '_api_key' ] );
		?>
		<h2><?php esc_html_e( '付款設定', 'mxp-cf7-slp' ); ?></h2>

		<?php if ( ! $has_key ) : ?>
			<p class="description" style="color:#d63638;">
				⚠️ <?php printf( __( '請先 %s 才能使用付款功能。', 'mxp-cf7-slp' ), '<a href="' . esc_url( admin_url( 'admin.php?page=mxp-slp-settings' ) ) . '">' . __( '設定 API 金鑰', 'mxp-cf7-slp' ) . '</a>' ); ?>
			</p>
		<?php endif; ?>

		<fieldset>
			<legend><?php esc_html_e( '基本設定', 'mxp-cf7-slp' ); ?></legend>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( '啟用付款', 'mxp-cf7-slp' ); ?></th>
					<td>
						<label><input type="checkbox" name="slp_payment[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>> <?php esc_html_e( '啟用此表單的付款功能', 'mxp-cf7-slp' ); ?></label>
						<p class="description"><?php esc_html_e( '啟用後，請在上方「表單」Tab 中插入 [shopline_payment] 標籤（可使用 Tag Generator 的「Shopline Payment」按鈕）。', 'mxp-cf7-slp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( '金額模式', 'mxp-cf7-slp' ); ?></th>
					<td>
						<fieldset class="slp-amount-mode-options">
							<label><input type="radio" name="slp_payment[amount_mode]" value="fixed" <?php checked( $settings['amount_mode'], 'fixed' ); ?>> <?php esc_html_e( '固定金額', 'mxp-cf7-slp' ); ?></label><br>
							<label><input type="radio" name="slp_payment[amount_mode]" value="user_input" <?php checked( $settings['amount_mode'], 'user_input' ); ?>> <?php esc_html_e( '顧客自填金額', 'mxp-cf7-slp' ); ?></label><br>
							<label><input type="radio" name="slp_payment[amount_mode]" value="field_mapping" <?php checked( $settings['amount_mode'], 'field_mapping' ); ?>> <?php esc_html_e( '讀取 CF7 金額欄位', 'mxp-cf7-slp' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'SHOPLINE Payments API 使用 TWD 元 x 100；外掛會在送出前自動轉換，後端會重新驗證金額範圍。', 'mxp-cf7-slp' ); ?></p>
						<p class="description"><?php esc_html_e( '顧客自填與欄位金額會使用導轉式付款；內嵌 SDK 僅適用固定金額，避免前端初始化金額與實際訂單不一致。', 'mxp-cf7-slp' ); ?></p>
					</td>
				</tr>
				<tr class="slp-amount-fixed-row">
					<th><?php esc_html_e( '固定金額（TWD）', 'mxp-cf7-slp' ); ?></th>
					<td><input type="number" name="slp_payment[amount]" value="<?php echo esc_attr( $settings['amount'] ); ?>" min="1" step="1" class="small-text"> <span class="description"><?php esc_html_e( '元', 'mxp-cf7-slp' ); ?></span></td>
				</tr>
				<tr class="slp-amount-range-row">
					<th><?php esc_html_e( '可付款金額範圍', 'mxp-cf7-slp' ); ?></th>
					<td>
						<input type="number" name="slp_payment[amount_min]" value="<?php echo esc_attr( $settings['amount_min'] ); ?>" min="1" step="1" class="small-text" aria-label="<?php esc_attr_e( '最低付款金額', 'mxp-cf7-slp' ); ?>">
						<span class="description"><?php esc_html_e( '元 至', 'mxp-cf7-slp' ); ?></span>
						<input type="number" name="slp_payment[amount_max]" value="<?php echo esc_attr( $settings['amount_max'] ); ?>" min="1" step="1" class="small-text" aria-label="<?php esc_attr_e( '最高付款金額', 'mxp-cf7-slp' ); ?>">
						<span class="description"><?php esc_html_e( '元', 'mxp-cf7-slp' ); ?></span>
					</td>
				</tr>
				<tr class="slp-amount-field-row">
					<th><?php esc_html_e( 'CF7 金額欄位名稱', 'mxp-cf7-slp' ); ?></th>
					<td>
						<input type="text" name="slp_payment[amount_field]" value="<?php echo esc_attr( $settings['amount_field'] ); ?>" class="regular-text" placeholder="donation-amount">
						<p class="description"><?php esc_html_e( '請填入表單中的 number/text 欄位 name，例如 donation-amount。後端只接受 TWD 整數。', 'mxp-cf7-slp' ); ?></p>
					</td>
				</tr>
				<tr class="slp-amount-suggested-row">
					<th><?php esc_html_e( '建議金額', 'mxp-cf7-slp' ); ?></th>
					<td>
						<input type="text" name="slp_payment[suggested_amounts]" value="<?php echo esc_attr( $suggested_amounts ); ?>" class="regular-text" placeholder="300, 500, 1000">
						<p class="description"><?php esc_html_e( '顧客自填金額時顯示為快速選項，請用逗號分隔。超出範圍的金額會自動忽略。', 'mxp-cf7-slp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( '付款方式', 'mxp-cf7-slp' ); ?></th>
					<td>
						<?php
						$methods = [
							'CreditCard'    => __( '信用卡', 'mxp-cf7-slp' ),
							'ApplePay'      => 'Apple Pay',
							'LinePay'       => 'LINE Pay',
							'VirtualAccount'=> __( 'ATM 轉帳', 'mxp-cf7-slp' ),
							'JKOPay'        => __( '街口支付', 'mxp-cf7-slp' ),
							'ChaileaseBNPL' => __( '中租零卡分期', 'mxp-cf7-slp' ),
						];
						foreach ( $methods as $value => $label ) :
						?>
							<label style="display:inline-block;margin-right:12px;margin-bottom:4px;">
								<input type="checkbox" name="slp_payment[payment_methods][]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $settings['payment_methods'], true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( '信用卡分期', 'mxp-cf7-slp' ); ?></th>
					<td>
						<?php
						$installments = [ '0' => __( '不分期', 'mxp-cf7-slp' ), '3' => '3期', '6' => '6期', '9' => '9期', '12' => '12期', '18' => '18期', '24' => '24期' ];
						foreach ( $installments as $value => $label ) :
						?>
							<label style="display:inline-block;margin-right:8px;">
								<input type="checkbox" name="slp_payment[cc_installments][]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $settings['cc_installments'], true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( '簡易模式', 'mxp-cf7-slp' ); ?></th>
					<td><label><input type="checkbox" name="slp_payment[simple_mode]" value="1" <?php checked( $settings['simple_mode'] ); ?>> <?php esc_html_e( '數位商品模式（僅需 Email 即可付款）', 'mxp-cf7-slp' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( '按鈕文字', 'mxp-cf7-slp' ); ?></th>
					<td><input type="text" name="slp_payment[button_text]" value="<?php echo esc_attr( $settings['button_text'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( '前往付款', 'mxp-cf7-slp' ); ?>"></td>
				</tr>
			</table>
		</fieldset>

		<script>
		(function(){
			var cb = document.querySelector('input[name="slp_payment[enabled]"]');
			var fields = cb ? cb.closest('fieldset').querySelectorAll('tr:not(:first-child)') : [];
			function toggle() {
				fields.forEach(function(tr) { tr.style.display = cb.checked ? '' : 'none'; });
				toggleAmountMode();
			}

			var modeInputs = document.querySelectorAll('input[name="slp_payment[amount_mode]"]');
			function toggleAmountMode() {
				if (cb && !cb.checked) return;
				var checked = document.querySelector('input[name="slp_payment[amount_mode]"]:checked');
				var mode = checked ? checked.value : 'fixed';
				document.querySelectorAll('.slp-amount-fixed-row').forEach(function(row) { row.style.display = mode === 'fixed' ? '' : 'none'; });
				document.querySelectorAll('.slp-amount-field-row').forEach(function(row) { row.style.display = mode === 'field_mapping' ? '' : 'none'; });
				document.querySelectorAll('.slp-amount-suggested-row').forEach(function(row) { row.style.display = mode === 'user_input' ? '' : 'none'; });
			}
			if (cb) { toggle(); cb.addEventListener('change', toggle); }
			modeInputs.forEach(function(input) { input.addEventListener('change', toggleAmountMode); });
			toggleAmountMode();
		})();
		</script>
		<?php
	}

	public function save_settings( $contact_form, $data, $context ): void {
		if ( 'save' !== $context ) {
			return;
		}

		$input = isset( $_POST['slp_payment'] ) && is_array( $_POST['slp_payment'] )
			? wp_unslash( $_POST['slp_payment'] )
			: [];

		$payment_methods = array_values( array_filter(
			(array) ( $input['payment_methods'] ?? [] ),
			fn( $m ) => in_array( $m, [ 'CreditCard', 'ApplePay', 'LinePay', 'VirtualAccount', 'JKOPay', 'ChaileaseBNPL' ], true )
		) );

		if ( empty( $payment_methods ) ) {
			$payment_methods = [ 'CreditCard' ];
		}

		$cc_installments = array_values( array_filter(
			(array) ( $input['cc_installments'] ?? [] ),
			fn( $v ) => in_array( $v, [ '0', '3', '6', '9', '12', '18', '24' ], true )
		) );

		if ( empty( $cc_installments ) ) {
			$cc_installments = [ '0' ];
		}

		$amount = absint( $input['amount'] ?? 0 );
		$amount_min = max( 1, absint( $input['amount_min'] ?? 1 ) );
		$amount_max = max( $amount_min, absint( $input['amount_max'] ?? 10000000 ) );
		$amount_mode = in_array( $input['amount_mode'] ?? '', [ 'fixed', 'user_input', 'field_mapping' ], true ) ? $input['amount_mode'] : 'fixed';
		$suggested_amounts = array_values( array_unique( array_filter(
			array_map( 'absint', preg_split( '/[,\s]+/', (string) ( $input['suggested_amounts'] ?? '' ) ) ),
			fn( $value ) => $value >= $amount_min && $value <= $amount_max
		) ) );

		$settings = [
			'enabled'         => ! empty( $input['enabled'] ),
			'amount_mode'     => $amount_mode,
			'amount'          => MXP_SLP_Security::validate_amount( $amount ) ? $amount : 0,
			'amount_min'      => $amount_min,
			'amount_max'      => $amount_max,
			'amount_field'    => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $input['amount_field'] ?? '' ) ),
			'suggested_amounts' => $suggested_amounts,
			'currency'        => 'TWD',
			'payment_methods' => $payment_methods,
			'cc_installments' => $cc_installments,
			'simple_mode'     => ! empty( $input['simple_mode'] ),
			'button_text'     => sanitize_text_field( $input['button_text'] ?? '' ),
		];

		update_post_meta( $contact_form->id(), '_slp_payment_settings', $settings );
	}

	private function get_defaults(): array {
		return [
			'enabled'         => false,
			'amount_mode'     => 'fixed',
			'amount'          => 0,
			'amount_min'      => 1,
			'amount_max'      => 10000000,
			'amount_field'    => '',
			'suggested_amounts' => [ 300, 500, 1000 ],
			'currency'        => 'TWD',
			'payment_methods' => [ 'CreditCard' ],
			'cc_installments' => [ '0' ],
			'simple_mode'     => false,
			'button_text'     => '',
		];
	}
}

MXP_SLP_Payment_Panel::get_instance();
