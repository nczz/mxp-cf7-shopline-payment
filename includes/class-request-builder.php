<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MXP_SLP_Request_Builder {

	private const PAYMENT_METHOD_LABELS = [
		'CreditCard'     => '信用卡',
		'ApplePay'       => 'Apple Pay',
		'LinePay'        => 'LINE Pay',
		'VirtualAccount' => 'ATM 轉帳',
		'JKOPay'         => '街口支付',
		'ChaileaseBNPL'  => '中租零卡分期',
	];

	public static function get_method_label( string $method ): string {
		return self::PAYMENT_METHOD_LABELS[ $method ] ?? $method;
	}

	public static function build_session_request(
		int $form_id,
		array $posted_data,
		string $order_token,
		string $return_url
	): array {
		$settings = get_post_meta( $form_id, '_slp_payment_settings', true ) ?: [];
		$amount_cents = intval( round( ( $settings['amount'] ?? 0 ) * 100 ) );
		$mapping = self::auto_detect_mapping( $form_id, $posted_data );

		// 顧客資訊
		$email = $mapping['email'] ?? '';
		$name  = $mapping['name'] ?? '';
		$phone = $mapping['phone'] ?? '';
		$address = $mapping['address'] ?? '';

		// 姓名拆分
		[ $first_name, $last_name ] = self::split_name( $name );

		// 電話格式化
		$phone = self::format_phone( $phone );

		// personalInfo
		$personal_info = array_filter( [
			'firstName' => $first_name,
			'lastName'  => $last_name ?: $email, // fallback: 用 email 當 lastName
			'email'     => $email,
			'phone'     => $phone,
		] );

		// 地址
		$address_data = [
			'countryCode' => 'TW',
			'street'      => $address ?: ( $settings['simple_mode'] ? '數位商品無需寄送' : '未提供' ),
		];

		// 付款方式選項
		$payment_methods = $settings['payment_methods'] ?? [ 'CreditCard' ];
		$payment_options = [];

		if ( in_array( 'CreditCard', $payment_methods, true ) ) {
			$installments = $settings['cc_installments'] ?? [];
			// 只有勾選了非 0 的期數才傳 installmentCounts（SLP：不帶入=僅一般交易）
			$has_installment = array_filter( $installments, fn( $v ) => '0' !== $v );
			if ( $has_installment ) {
				$payment_options['CreditCard'] = [ 'installmentCounts' => array_values( $installments ) ];
			}
		}

		if ( in_array( 'ChaileaseBNPL', $payment_methods, true ) ) {
			$bnpl_installments = $settings['bnpl_installments'] ?? [];
			$has_bnpl_installment = array_filter( $bnpl_installments, fn( $v ) => '0' !== $v );
			$bnpl_options = [ 'paymentExpireTime' => 4320 ];
			if ( $has_bnpl_installment ) {
				$bnpl_options['installmentCounts'] = array_values( $bnpl_installments );
			}
			$payment_options['ChaileaseBNPL'] = $bnpl_options;
		}

		if ( in_array( 'VirtualAccount', $payment_methods, true ) ) {
			$payment_options['VirtualAccount'] = [ 'paymentExpireTime' => 4320 ];
		}

		if ( in_array( 'JKOPay', $payment_methods, true ) ) {
			$payment_options['JKOPay'] = [ 'paymentExpireTime' => 60 ];
		}

		$contact_form = wpcf7_contact_form( $form_id );
		$product_name = $contact_form ? $contact_form->title() : '商品';

		$body = [
			'referenceId'            => $order_token,
			'mode'                   => 'regular',
			'amount'                 => [ 'value' => $amount_cents, 'currency' => 'TWD' ],
			'returnUrl'              => $return_url,
			'allowPaymentMethodList' => array_values( $payment_methods ),
			'order'                  => [
				'products' => [ [
					'id'       => (string) $form_id,
					'name'     => $product_name,
					'quantity' => 1,
					'amount'   => [ 'value' => $amount_cents, 'currency' => 'TWD' ],
				] ],
				'shipping' => [
					'shippingMethod' => $settings['simple_mode'] ? '數位商品' : '宅配',
					'carrier'        => $settings['simple_mode'] ? '電子郵件' : '宅配',
					'personalInfo'   => $personal_info,
					'address'        => $address_data,
				],
			],
			'customer' => [
				'referenceCustomerId' => $email ? md5( $email ) : 'guest_' . substr( $order_token, 0, 16 ),
				'personalInfo'        => $personal_info,
			],
			'client' => [
				'ip' => self::get_client_ip(),
			],
			'billing' => [
				'personalInfo' => $personal_info,
				'address'      => $address_data,
			],
		];

		if ( ! empty( $payment_options ) ) {
			$body['paymentMethodOptions'] = $payment_options;
		}

		return $body;
	}

	public static function auto_detect_mapping( int $form_id, array $posted_data ): array {
		$mapping = [];

		foreach ( $posted_data as $key => $value ) {
			$val = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			$lower = strtolower( $key );

			if ( ! isset( $mapping['email'] ) && ( str_contains( $lower, 'email' ) || str_contains( $lower, 'mail' ) ) ) {
				if ( is_email( $val ) ) {
					$mapping['email'] = $val;
				}
			}

			if ( ! isset( $mapping['name'] ) && preg_match( '/name|姓名|名字/', $lower ) ) {
				if ( '' !== $val ) {
					$mapping['name'] = $val;
				}
			}

			if ( ! isset( $mapping['phone'] ) && preg_match( '/tel|phone|電話|手機/', $lower ) ) {
				if ( '' !== $val ) {
					$mapping['phone'] = $val;
				}
			}

			if ( ! isset( $mapping['address'] ) && preg_match( '/address|地址/', $lower ) ) {
				if ( '' !== $val ) {
					$mapping['address'] = $val;
				}
			}
		}

		return $mapping;
	}

	public static function split_name( string $name ): array {
		$name = trim( $name );
		if ( '' === $name ) {
			return [ '', '' ];
		}

		// 判斷是否為中文（非 ASCII）
		if ( preg_match( '/^[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+$/u', $name ) ) {
			$chars = mb_str_split( $name );
			$last_name = $chars[0];
			$first_name = implode( '', array_slice( $chars, 1 ) );
			return [ $first_name, $last_name ];
		}

		// 英文或混合：以空格分割
		$parts = preg_split( '/\s+/', $name );
		if ( count( $parts ) === 1 ) {
			return [ '', $name ];
		}

		$last_name = array_pop( $parts );
		$first_name = implode( ' ', $parts );
		return [ $first_name, $last_name ];
	}

	public static function format_phone( string $phone ): string {
		$phone = trim( $phone );
		if ( '' === $phone ) {
			return '';
		}

		// 已有國碼
		if ( str_starts_with( $phone, '+' ) ) {
			return $phone;
		}

		// 台灣手機 09 開頭
		if ( preg_match( '/^09\d{8}$/', $phone ) ) {
			return '+886' . substr( $phone, 1 );
		}

		return $phone;
	}

	private static function get_client_ip(): string {
		// 優先順序：Cloudflare → X-Forwarded-For → REMOTE_ADDR
		$headers = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
		foreach ( $headers as $header ) {
			$ip = $_SERVER[ $header ] ?? '';
			if ( $ip ) {
				// X-Forwarded-For 可能含多個 IP，取第一個
				$ip = explode( ',', $ip )[0];
				$ip = trim( $ip );
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}
		return filter_var( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', FILTER_VALIDATE_IP ) ?: '127.0.0.1';
	}

	/**
	 * @return array|null  null=通過, array=['field'=>欄位名, 'message'=>錯誤訊息]
	 */
	public static function validate_required_fields( array $posted_data, array $settings ): ?array {
		$mapping = self::auto_detect_mapping( 0, $posted_data );

		// email 或 phone 至少一個
		if ( empty( $mapping['email'] ) && empty( $mapping['phone'] ) ) {
			// 找出 email 欄位名
			$email_field = '';
			foreach ( $posted_data as $key => $val ) {
				if ( preg_match( '/email|mail/i', $key ) ) { $email_field = $key; break; }
			}
			return [
				'field'   => $email_field ?: 'your-email',
				'message' => __( '請填寫 Email 或電話', 'mxp-cf7-slp' ),
			];
		}

		// 非簡易模式需要姓名
		if ( empty( $settings['simple_mode'] ) && empty( $mapping['name'] ) ) {
			$name_field = '';
			foreach ( $posted_data as $key => $val ) {
				if ( preg_match( '/name|姓名/i', $key ) ) { $name_field = $key; break; }
			}
			return [
				'field'   => $name_field ?: 'your-name',
				'message' => __( '請填寫姓名', 'mxp-cf7-slp' ),
			];
		}

		return null;
	}
}
