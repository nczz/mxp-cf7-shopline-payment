<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MXP_SLP_Webhook {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'mxp-cf7-slp/v1', '/webhook', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body      = $request->get_body();
		$timestamp = $request->get_header( 'timestamp' );
		$sign      = $request->get_header( 'sign' );

		// 基本驗證
		if ( strlen( $body ) > 1048576 ) {
			return new \WP_REST_Response( [ 'error' => 'payload too large' ], 413 );
		}

		if ( ! $timestamp || ! $sign ) {
			return new \WP_REST_Response( [ 'error' => 'missing signature' ], 401 );
		}

		// 簽章驗證
		if ( ! $this->verify_signature( $body, $timestamp, $sign ) ) {
			if ( WP_DEBUG ) {
				error_log( '[MXP_SLP] Webhook signature verification failed from ' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
			}
			return new \WP_REST_Response( [ 'error' => 'invalid signature' ], 401 );
		}

		// 時間戳驗證（5 分鐘窗口）
		$ts_seconds = intval( $timestamp ) / 1000;
		if ( abs( time() - $ts_seconds ) > 300 ) {
			return new \WP_REST_Response( [ 'error' => 'timestamp expired' ], 401 );
		}

		// 解析 body
		$data = json_decode( $body, true );
		if ( ! $data || empty( $data['id'] ) || empty( $data['type'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'invalid payload' ], 400 );
		}

		// 冪等性檢查
		$event_key = '_slp_evt_' . substr( md5( $data['id'] ), 0, 16 );
		if ( get_option( $event_key ) ) {
			return new \WP_REST_Response( [ 'status' => 'already_processed' ], 200 );
		}

		// 事件路由
		$this->route_event( $data['type'], $data['data'] ?? [], $data );

		// 記錄已處理
		update_option( $event_key, time(), false );

		// 記錄 Webhook 狀態
		update_option( '_slp_webhook_last_received', [
			'time'  => time(),
			'event' => $data['type'],
		], false );

		return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}

	private function verify_signature( string $body, string $timestamp, string $sign ): bool {
		$sign_key = MXP_SLP_API::get_instance()->get_sign_key();
		if ( empty( $sign_key ) ) {
			return false;
		}

		$payload  = $timestamp . '.' . $body;
		$expected = hash_hmac( 'sha256', $payload, $sign_key );

		return hash_equals( $expected, $sign );
	}

	private function route_event( string $type, array $event_data, array $full_payload ): void {
		match ( $type ) {
			'session.succeeded'      => $this->handle_session_succeeded( $event_data ),
			'session.expired'        => $this->handle_session_expired( $event_data ),
			'trade.succeeded'        => $this->handle_trade_succeeded( $event_data ),
			'trade.failed'           => $this->handle_trade_failed( $event_data ),
			'trade.refund.succeeded' => $this->handle_refund_succeeded( $event_data ),
			default                  => $this->handle_unknown( $type ),
		};
	}

	private function handle_session_succeeded( array $data ): void {
		$session_id = $data['sessionId'] ?? '';
		if ( ! $session_id ) {
			return;
		}

		$order_id = MXP_SLP_Order::find_by_session_id( $session_id );
		if ( ! $order_id ) {
			return;
		}

		// 更新狀態
		$updated = MXP_SLP_Order::update_status( $order_id, 'SUCCEEDED' );
		if ( ! $updated ) {
			return; // 已經是 SUCCEEDED（冪等）
		}

		// 記錄付款詳情
		$payment_details = $data['paymentDetails'][0] ?? [];
		if ( $payment_details ) {
			update_post_meta( $order_id, '_slp_trade_order_id', $payment_details['tradeOrderId'] ?? '' );
			update_post_meta( $order_id, '_slp_payment_method', $payment_details['paymentMethod'] ?? '' );
		}

		// 異步觸發郵件（用 shutdown hook 不阻塞回應）
		$token = get_post_meta( $order_id, '_slp_token', true );
		register_shutdown_function( function() use ( $token ) {
			MXP_SLP_Mail_Handler::send_payment_confirmation( $token );
		} );
	}

	private function handle_session_expired( array $data ): void {
		$session_id = $data['sessionId'] ?? '';
		if ( ! $session_id ) {
			return;
		}

		$order_id = MXP_SLP_Order::find_by_session_id( $session_id );
		if ( $order_id ) {
			MXP_SLP_Order::update_status( $order_id, 'EXPIRED' );
		}
	}

	private function handle_trade_succeeded( array $data ): void {
		// 備用：如果 session 事件未到，用 trade 事件更新
		$trade_order_id = $data['tradeOrderId'] ?? '';
		if ( ! $trade_order_id ) {
			return;
		}

		// 嘗試用 referenceOrderId 找訂單（= token）
		$reference_id = $data['order']['referenceOrderId'] ?? ( $data['referenceOrderId'] ?? '' );
		if ( $reference_id ) {
			$order_id = MXP_SLP_Order::find_by_token( $reference_id );
			if ( $order_id ) {
				$updated = MXP_SLP_Order::update_status( $order_id, 'SUCCEEDED' );
				if ( $updated ) {
					update_post_meta( $order_id, '_slp_trade_order_id', $trade_order_id );
					update_post_meta( $order_id, '_slp_payment_method', $data['payment']['paymentMethod'] ?? '' );

					$token = get_post_meta( $order_id, '_slp_token', true );
					register_shutdown_function( function() use ( $token ) {
						MXP_SLP_Mail_Handler::send_payment_confirmation( $token );
					} );
				}
			}
		}
	}

	private function handle_trade_failed( array $data ): void {
		$reference_id = $data['order']['referenceOrderId'] ?? ( $data['referenceOrderId'] ?? '' );
		if ( $reference_id ) {
			$order_id = MXP_SLP_Order::find_by_token( $reference_id );
			if ( $order_id ) {
				MXP_SLP_Order::update_status( $order_id, 'FAILED' );
				update_post_meta( $order_id, '_slp_error_code', $data['paymentMsg']['code'] ?? '' );
				update_post_meta( $order_id, '_slp_error_msg', $data['paymentMsg']['msg'] ?? '' );
			}
		}
	}

	private function handle_refund_succeeded( array $data ): void {
		$trade_order_id = $data['tradeOrderId'] ?? '';
		if ( ! $trade_order_id ) {
			return;
		}

		$orders = get_posts( [
			'post_type'   => 'slp_order',
			'post_status' => 'any',
			'meta_key'    => '_slp_trade_order_id',
			'meta_value'  => $trade_order_id,
			'numberposts' => 1,
		] );

		if ( empty( $orders ) ) {
			return;
		}

		$order_id = $orders[0]->ID;
		MXP_SLP_Order::update_status( $order_id, 'REFUNDED' );
		update_post_meta( $order_id, '_slp_refund_order_id', $data['refundOrderId'] ?? '' );
		update_post_meta( $order_id, '_slp_refund_amount', $data['amount']['value'] ?? '' );
	}

	private function handle_unknown( string $type ): void {
		if ( WP_DEBUG ) {
			error_log( '[MXP_SLP] Unknown webhook event type: ' . $type );
		}
	}
}

MXP_SLP_Webhook::get_instance();
