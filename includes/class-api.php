<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MXP_SLP_API {

	private static ?self $instance = null;

	private const SANDBOX_URL = 'https://api-sandbox.shoplinepayments.com';
	private const LIVE_URL    = 'https://api.shoplinepayments.com';

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function get_settings(): array {
		return get_option( 'mxp_slp_settings', [] );
	}

	private function get_prefix( array $settings ): string {
		$env = $settings['environment'] ?? 'sandbox';
		return 'production' === $env ? 'live' : $env;
	}

	private function get_base_url(): string {
		$settings = $this->get_settings();
		$env = $settings['environment'] ?? 'sandbox';
		return 'production' === $env ? self::LIVE_URL : self::SANDBOX_URL;
	}

	private function get_merchant_id(): string {
		$settings = $this->get_settings();
		$prefix = $this->get_prefix( $settings );
		return $settings[ $prefix . '_merchant_id' ] ?? '';
	}

	private function get_api_key(): string {
		$settings = $this->get_settings();
		$prefix = $this->get_prefix( $settings );
		$encrypted = $settings[ $prefix . '_api_key' ] ?? '';
		return MXP_SLP_Security::decrypt( $encrypted );
	}

	public function get_sign_key(): string {
		$settings = $this->get_settings();
		$prefix = $this->get_prefix( $settings );
		$encrypted = $settings[ $prefix . '_sign_key' ] ?? '';
		return MXP_SLP_Security::decrypt( $encrypted );
	}

	public function get_merchant_id_public(): string {
		return $this->get_merchant_id();
	}

	public function get_api_key_public(): string {
		return $this->get_api_key();
	}

	public function get_client_key(): string {
		$settings = $this->get_settings();
		$prefix = $this->get_prefix( $settings );
		return $settings[ $prefix . '_client_key' ] ?? '';
	}

	public function get_environment(): string {
		$settings = $this->get_settings();
		return $settings['environment'] ?? 'sandbox';
	}

	public function has_credentials(): bool {
		return '' !== $this->get_merchant_id() && '' !== $this->get_api_key();
	}

	private function request( string $endpoint, array $body = [] ): array|false {
		if ( ! $this->has_credentials() ) {
			return false;
		}

		$url = $this->get_base_url() . $endpoint;

		$response = wp_remote_post( $url, [
			'timeout' => 30,
			'headers' => [
				'Content-Type' => 'application/json',
				'merchantId'   => $this->get_merchant_id(),
				'apiKey'       => $this->get_api_key(),
				'requestId'    => wp_generate_uuid4(),
			],
			'body' => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			if ( WP_DEBUG ) {
				error_log( '[MXP_SLP] API Error: ' . $response->get_error_message() );
			}
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$data = json_decode( $body_raw, true );

		if ( 200 === $code ) {
			return $data ?? [];
		}

		if ( WP_DEBUG ) {
			error_log( sprintf(
				'[MXP_SLP] API %d: %s | %s',
				$code,
				$data['code'] ?? 'unknown',
				$data['msg'] ?? $body_raw
			) );
		}

		return false;
	}

	// --- Session API ---

	public function create_session( array $params ): array|false {
		return $this->request( '/api/v1/trade/sessions/create', $params );
	}

	public function query_session( string $session_id ): array|false {
		return $this->request( '/api/v1/trade/sessions/query', [
			'sessionId' => $session_id,
		] );
	}

	// --- Payment API ---

	public function create_payment( array $params ): array|false {
		return $this->request( '/api/v1/trade/payment/create', $params );
	}

	public function query_payment( string $trade_order_id ): array|false {
		return $this->request( '/api/v1/trade/payment/get', [
			'tradeOrderId' => $trade_order_id,
		] );
	}

	// --- Refund API ---

	public function create_refund( string $trade_order_id, int $amount_cents, string $reason = '' ): array|false {
		return $this->request( '/api/v1/trade/refund/create', [
			'referenceOrderId' => 'ref_' . wp_generate_uuid4(),
			'tradeOrderId'     => $trade_order_id,
			'amount'           => [
				'value'    => $amount_cents,
				'currency' => 'TWD',
			],
			'reason'     => $reason,
			'callbackUrl' => rest_url( 'mxp-cf7-slp/v1/webhook' ),
		] );
	}

	public function query_refund( string $refund_order_id ): array|false {
		return $this->request( '/api/v1/trade/refund/get', [
			'refundOrderId' => $refund_order_id,
		] );
	}

	// --- Utility ---

	public function test_connection(): bool {
		if ( ! $this->has_credentials() ) {
			return false;
		}

		$url = $this->get_base_url() . '/api/v1/trade/sessions/query';

		$response = wp_remote_post( $url, [
			'timeout' => 30,
			'headers' => [
				'Content-Type' => 'application/json',
				'merchantId'   => $this->get_merchant_id(),
				'apiKey'       => $this->get_api_key(),
				'requestId'    => wp_generate_uuid4(),
			],
			'body' => wp_json_encode( [ 'sessionId' => 'test_nonexistent' ] ),
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return false;
		}

		$error_code = $body['code'] ?? '';

		if ( 200 === $code ) {
			return true;
		}

		// 2005 = Access Denied = 金鑰無效
		// 其他錯誤碼（1004, 1018 等）= 金鑰有效但查詢參數有誤 = 連線成功
		return in_array( (string) $error_code, [ '1004', '1018' ], true );
	}
}
