<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MXP_SLP_Order {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );

		if ( is_admin() ) {
			add_filter( 'manage_slp_order_posts_columns', [ $this, 'custom_columns' ] );
			add_action( 'manage_slp_order_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
			add_filter( 'manage_edit-slp_order_sortable_columns', [ $this, 'sortable_columns' ] );
			add_action( 'restrict_manage_posts', [ $this, 'status_filter_dropdown' ] );
			add_action( 'pre_get_posts', [ $this, 'filter_by_status' ] );
			add_action( 'pre_get_posts', [ $this, 'handle_orderby' ] );
			add_action( 'pre_get_posts', [ $this, 'extend_search' ] );
			add_action( 'add_meta_boxes', [ $this, 'add_detail_meta_box' ] );
		}
	}

	public function register_post_type(): void {
		register_post_type( 'slp_order', [
			'labels' => [
				'name'               => __( '付款訂單', 'mxp-cf7-slp' ),
				'singular_name'      => __( '付款訂單', 'mxp-cf7-slp' ),
				'all_items'          => __( '所有訂單', 'mxp-cf7-slp' ),
				'search_items'       => __( '搜尋訂單', 'mxp-cf7-slp' ),
				'not_found'          => __( '找不到訂單', 'mxp-cf7-slp' ),
			],
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => 'wpcf7',
			'supports'          => [ 'title' ],
			'capability_type'   => 'page',
			'map_meta_cap'      => true,
		] );
	}

	public function custom_columns( array $columns ): array {
		return [
			'cb'             => $columns['cb'],
			'title'          => __( '訂單', 'mxp-cf7-slp' ),
			'slp_customer'   => __( '顧客', 'mxp-cf7-slp' ),
			'slp_amount'     => __( '金額', 'mxp-cf7-slp' ),
			'slp_status'     => __( '狀態', 'mxp-cf7-slp' ),
			'slp_method'     => __( '付款方式', 'mxp-cf7-slp' ),
			'date'           => __( '日期', 'mxp-cf7-slp' ),
		];
	}

	public function render_column( string $column, int $post_id ): void {
		match ( $column ) {
			'slp_customer' => $this->render_customer_column( $post_id ),
			'slp_amount'   => printf( 'NT$%s', esc_html( number_format( (int) get_post_meta( $post_id, '_slp_amount', true ) ) ) ),
			'slp_status'   => $this->render_status_badge( $post_id ),
			'slp_method'   => printf( '%s', esc_html( MXP_SLP_Request_Builder::get_method_label( get_post_meta( $post_id, '_slp_payment_method', true ) ?: '-' ) ) ),
			default        => null,
		};
	}

	private function render_customer_column( int $post_id ): void {
		$posted = get_post_meta( $post_id, '_slp_posted_data', true );
		if ( ! is_array( $posted ) ) { echo '-'; return; }
		$mapping = MXP_SLP_Request_Builder::auto_detect_mapping( 0, $posted );
		$name = $mapping['name'] ?? '';
		$email = $mapping['email'] ?? '';
		if ( $name ) {
			echo esc_html( $name );
			if ( $email ) { echo '<br><small style="color:#666;">' . esc_html( $email ) . '</small>'; }
		} else {
			echo esc_html( $email ?: '-' );
		}
	}

	private function render_status_badge( int $post_id ): void {
		$status = get_post_meta( $post_id, '_slp_status', true );
		$styles = [
			'SUCCEEDED' => 'background:#d4edda;color:#155724;',
			'CREATED'   => 'background:#fff3cd;color:#856404;',
			'PENDING'   => 'background:#fff3cd;color:#856404;',
			'EXPIRED'   => 'background:#f8d7da;color:#721c24;',
			'FAILED'    => 'background:#f8d7da;color:#721c24;',
			'REFUNDED'  => 'background:#cfe2ff;color:#084298;',
		];
		$style = $styles[ $status ] ?? 'background:#e2e3e5;color:#383d41;';
		printf( '<span style="padding:2px 8px;border-radius:3px;font-size:12px;%s">%s</span>', esc_attr( $style ), esc_html( $status ) );
	}



	public function sortable_columns( array $columns ): array {
		$columns['slp_amount'] = 'slp_amount';
		return $columns;
	}

	public function handle_orderby( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) return;
		if ( 'slp_amount' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', '_slp_amount' );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	public function status_filter_dropdown( string $post_type ): void {
		if ( 'slp_order' !== $post_type ) {
			return;
		}
		$current = $_GET['slp_status'] ?? '';
		$statuses = [ 'CREATED', 'SUCCEEDED', 'EXPIRED', 'FAILED', 'REFUNDED' ];
		echo '<select name="slp_status">';
		echo '<option value="">' . esc_html__( '所有狀態', 'mxp-cf7-slp' ) . '</option>';
		foreach ( $statuses as $s ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $s ), selected( $current, $s, false ), esc_html( $s ) );
		}
		echo '</select>';
	}

	public function filter_by_status( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( ( $query->get( 'post_type' ) ?? '' ) !== 'slp_order' ) {
			return;
		}
		$status = $_GET['slp_status'] ?? '';
		if ( $status && in_array( $status, [ 'CREATED', 'SUCCEEDED', 'EXPIRED', 'FAILED', 'REFUNDED' ], true ) ) {
			$query->set( 'meta_key', '_slp_status' );
			$query->set( 'meta_value', $status );
		}
	}

	public function extend_search( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) return;
		if ( 'slp_order' !== $query->get( 'post_type' ) ) return;

		$search = $query->get( 's' );
		if ( empty( $search ) ) return;

		// 搜尋 meta（email、姓名在 posted_data 中，session_id）+ post_title
		$query->set( 's', '' );
		$query->set( 'meta_query', [
			'relation' => 'OR',
			[ 'key' => '_slp_session_id', 'value' => $search, 'compare' => 'LIKE' ],
			[ 'key' => '_slp_posted_data', 'value' => $search, 'compare' => 'LIKE' ],
			[ 'key' => '_slp_token', 'value' => $search, 'compare' => 'LIKE' ],
		] );

		// 一次性 filter 搜尋 post_title
		add_filter( 'posts_where', $cb = function( $where ) use ( $search, &$cb ) {
			global $wpdb;
			remove_filter( 'posts_where', $cb );
			$where .= $wpdb->prepare( " OR {$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' );
			return $where;
		} );
	}

	public function add_detail_meta_box(): void {
		add_meta_box(
			'slp_order_detail',
			__( '訂單詳情', 'mxp-cf7-slp' ),
			[ $this, 'render_detail_meta_box' ],
			'slp_order',
			'normal',
			'high'
		);
	}

	public function render_detail_meta_box( \WP_Post $post ): void {
		$order_id = $post->ID;
		$status   = get_post_meta( $order_id, '_slp_status', true );
		$amount   = (int) get_post_meta( $order_id, '_slp_amount', true );
		$method   = get_post_meta( $order_id, '_slp_payment_method', true );
		$trade_id = get_post_meta( $order_id, '_slp_trade_order_id', true );
		$session  = get_post_meta( $order_id, '_slp_session_id', true );
		$posted   = get_post_meta( $order_id, '_slp_posted_data', true );
		$error    = get_post_meta( $order_id, '_slp_error_msg', true );
		?>
		<style>.slp-detail-table td,.slp-detail-table th{padding:6px 12px;border-bottom:1px solid #eee;}.slp-detail-table th{text-align:left;width:120px;color:#666;}</style>

		<h4><?php esc_html_e( '付款資訊', 'mxp-cf7-slp' ); ?></h4>
		<table class="slp-detail-table">
			<tr><th><?php esc_html_e( '狀態', 'mxp-cf7-slp' ); ?></th><td><?php $this->render_status_badge( $order_id ); ?></td></tr>
			<tr><th><?php esc_html_e( '金額', 'mxp-cf7-slp' ); ?></th><td>NT$ <?php echo esc_html( number_format( $amount ) ); ?></td></tr>
			<tr><th><?php esc_html_e( '付款方式', 'mxp-cf7-slp' ); ?></th><td><?php echo esc_html( MXP_SLP_Request_Builder::get_method_label( $method ?: '-' ) ); ?></td></tr>
			<tr><th>Session ID</th><td><code><?php echo esc_html( $session ); ?></code></td></tr>
			<?php if ( $trade_id ) : ?>
			<tr><th>Trade Order ID</th><td><code><?php echo esc_html( $trade_id ); ?></code></td></tr>
			<?php endif; ?>
			<?php if ( $error ) : ?>
			<tr><th><?php esc_html_e( '錯誤', 'mxp-cf7-slp' ); ?></th><td style="color:#d63638;"><?php echo esc_html( $error ); ?></td></tr>
			<?php endif; ?>
		</table>

		<?php if ( 'SUCCEEDED' === $status ) : ?>
		<p style="margin-top:12px;">
			<?php if ( $trade_id ) : ?>
			<button type="button" class="button" id="slp-refund-btn" data-order="<?php echo esc_attr( $order_id ); ?>" data-amount="<?php echo esc_attr( $amount ); ?>" data-trade="<?php echo esc_attr( $trade_id ); ?>">
				<?php esc_html_e( '退款', 'mxp-cf7-slp' ); ?>
			</button>
			<?php endif; ?>
			<button type="button" class="button" id="slp-resend-btn" data-token="<?php echo esc_attr( get_post_meta( $order_id, '_slp_token', true ) ); ?>">
				<?php esc_html_e( '重發通知信', 'mxp-cf7-slp' ); ?>
			</button>
			<a href="https://login.shoplinepayments.com/" target="_blank" class="button"><?php esc_html_e( '前往 SLP 後台', 'mxp-cf7-slp' ); ?></a>
		</p>
		<script>
		document.getElementById('slp-refund-btn')?.addEventListener('click', function() {
			var amt = prompt('<?php echo esc_js( __( '請輸入退款金額（元）：', 'mxp-cf7-slp' ) ); ?>', this.dataset.amount);
			if (!amt || isNaN(amt) || amt <= 0) return;
			if (!confirm('確定退款 NT$ ' + amt + '？')) return;
			this.disabled = true; this.textContent = '處理中...';
			fetch('<?php echo esc_url( rest_url( 'mxp-cf7-slp/v1/admin/refund' ) ); ?>', {
				method: 'POST',
				headers: {'Content-Type':'application/json','X-WP-Nonce':'<?php echo wp_create_nonce( 'wp_rest' ); ?>'},
				credentials: 'same-origin',
				body: JSON.stringify({order_id: <?php echo $order_id; ?>, amount: parseInt(amt), reason: '後台退款'})
			}).then(r=>r.json()).then(d=>{
				alert(d.success ? '退款成功' : '退款失敗：' + (d.message||''));
				if(d.success) location.reload();
				else { this.disabled=false; this.textContent='退款'; }
			}).catch(()=>{ alert('網路錯誤'); this.disabled=false; this.textContent='退款'; });
		});
		document.getElementById('slp-resend-btn')?.addEventListener('click', function() {
			if (!confirm('確定重新發送通知信？')) return;
			this.disabled = true; this.textContent = '發送中...';
			fetch('<?php echo esc_url( rest_url( 'mxp-cf7-slp/v1/admin/resend-mail' ) ); ?>', {
				method: 'POST',
				headers: {'Content-Type':'application/json','X-WP-Nonce':'<?php echo wp_create_nonce( 'wp_rest' ); ?>'},
				credentials: 'same-origin',
				body: JSON.stringify({token: this.dataset.token})
			}).then(r=>r.json()).then(d=>{
				alert(d.success ? '郵件已重新發送' : '發送失敗：' + (d.message||''));
				this.disabled=false; this.textContent='重發通知信';
			}).catch(()=>{ alert('網路錯誤'); this.disabled=false; this.textContent='重發通知信'; });
		});
		</script>
		<?php endif; ?>

		<?php if ( is_array( $posted ) && ! empty( $posted ) ) : ?>
		<h4 style="margin-top:20px;"><?php esc_html_e( '表單資料', 'mxp-cf7-slp' ); ?></h4>
		<table class="slp-detail-table">
			<?php foreach ( $posted as $key => $value ) : ?>
			<tr>
				<th><?php echo esc_html( $key ); ?></th>
				<td><?php echo esc_html( is_array( $value ) ? implode( ', ', $value ) : $value ); ?></td>
			</tr>
			<?php endforeach; ?>
		</table>
		<?php endif;
	}

	// --- Static methods (unchanged from Phase 1) ---

	public static function create( array $data ): int|false {
		global $wpdb;

		// 原子 increment 防撞號
		$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('_slp_order_counter', 1, 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1" );
		$counter = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = '_slp_order_counter'" );

		$order_id = wp_insert_post( [
			'post_type'   => 'slp_order',
			'post_title'  => 'SLP-' . str_pad( $counter, 4, '0', STR_PAD_LEFT ),
			'post_status' => 'publish',
		] );

		if ( is_wp_error( $order_id ) ) {
			return false;
		}

		$meta_fields = [
			'_slp_token', '_slp_session_id', '_slp_reference_id',
			'_slp_form_id', '_slp_posted_data', '_slp_amount',
			'_slp_currency', '_slp_status', '_slp_payment_method',
			'_slp_trade_order_id', '_slp_mail_sent', '_slp_retry_count',
			'_slp_referer_url', '_slp_error_code', '_slp_error_msg',
		];

		foreach ( $meta_fields as $key ) {
			$field = str_replace( '_slp_', '', $key );
			if ( isset( $data[ $field ] ) ) {
				update_post_meta( $order_id, $key, $data[ $field ] );
			}
		}

		return $order_id;
	}

	public static function find_by_token( string $token ): ?int {
		$posts = get_posts( [
			'post_type'   => 'slp_order',
			'meta_key'    => '_slp_token',
			'meta_value'  => $token,
			'numberposts' => 1,
			'fields'      => 'ids',
		] );
		return $posts[0] ?? null;
	}

	public static function find_by_session_id( string $session_id ): ?int {
		$posts = get_posts( [
			'post_type'   => 'slp_order',
			'meta_key'    => '_slp_session_id',
			'meta_value'  => $session_id,
			'numberposts' => 1,
			'fields'      => 'ids',
		] );
		return $posts[0] ?? null;
	}

	public static function update_status( int $order_id, string $new_status ): bool {
		$current = get_post_meta( $order_id, '_slp_status', true );
		$allowed = [
			'CREATED' => [ 'PENDING', 'SUCCEEDED', 'EXPIRED', 'FAILED' ],
			'PENDING' => [ 'SUCCEEDED', 'EXPIRED', 'FAILED' ],
		];

		if ( isset( $allowed[ $current ] ) && in_array( $new_status, $allowed[ $current ], true ) ) {
			update_post_meta( $order_id, '_slp_status', $new_status );
			return true;
		}
		return $current === $new_status;
	}
}
