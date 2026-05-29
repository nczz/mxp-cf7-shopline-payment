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
		string $return_url,
		?int $amount_override = null
	): array {
		$settings = self::normalize_settings( get_post_meta( $form_id, '_slp_payment_settings', true ) ?: [] );
		$amount = null !== $amount_override ? $amount_override : self::resolve_amount( $posted_data, $settings )['amount'];
		$amount_cents = $amount * 100;
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
		$payment_methods = array_values( array_filter(
			(array) ( $settings['payment_methods'] ?? [ 'CreditCard' ] ),
			fn( $method ) => in_array( $method, array_keys( self::PAYMENT_METHOD_LABELS ), true )
		) );
		if ( empty( $payment_methods ) ) {
			$payment_methods = [ 'CreditCard' ];
		}
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

	public static function normalize_settings( array $settings ): array {
		$settings = wp_parse_args( $settings, [
			'amount_mode'       => 'fixed',
			'amount'            => 0,
			'amount_min'        => 1,
			'amount_max'        => 10000000,
			'amount_field'      => '',
			'suggested_amounts' => [],
			'payment_methods'   => [ 'CreditCard' ],
			'cc_installments'   => [ '0' ],
			'simple_mode'       => false,
		] );

		if ( ! in_array( $settings['amount_mode'], [ 'fixed', 'user_input', 'field_mapping' ], true ) ) {
			$settings['amount_mode'] = 'fixed';
		}

		$settings['amount'] = absint( $settings['amount'] );
		$settings['amount_min'] = max( 1, absint( $settings['amount_min'] ) );
		$settings['amount_max'] = max( $settings['amount_min'], absint( $settings['amount_max'] ) );
		$settings['amount_field'] = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $settings['amount_field'] );
		$settings['cc_installments'] = array_values( array_unique( array_filter(
			array_map( 'strval', (array) $settings['cc_installments'] ),
			fn( $installment ) => in_array( $installment, [ '0', '3', '6', '9', '12', '18', '24' ], true )
		) ) );
		if ( empty( $settings['cc_installments'] ) ) {
			$settings['cc_installments'] = [ '0' ];
		}
		$suggested_amounts = is_string( $settings['suggested_amounts'] )
			? preg_split( '/[,\s]+/', $settings['suggested_amounts'] )
			: (array) $settings['suggested_amounts'];
		$settings['suggested_amounts'] = array_values( array_unique( array_filter(
			array_map( 'absint', $suggested_amounts ),
			fn( $amount ) => $amount >= $settings['amount_min'] && $amount <= $settings['amount_max']
		) ) );

		if ( empty( $settings['suggested_amounts'] ) ) {
			$settings['suggested_amounts'] = [
				$settings['amount_min'],
				min( $settings['amount_max'], max( $settings['amount_min'], 500 ) ),
				min( $settings['amount_max'], max( $settings['amount_min'], 1000 ) ),
			];
			$settings['suggested_amounts'] = array_values( array_unique( $settings['suggested_amounts'] ) );
		}

		return $settings;
	}

	/**
	 * @return array{amount:int,source:string,field:string,error:string}
	 */
	public static function resolve_amount( array $posted_data, array $settings ): array {
		$settings = self::normalize_settings( $settings );
		$mode = $settings['amount_mode'];
		$field = '';
		$raw_amount = null;

		if ( 'fixed' === $mode ) {
			$raw_amount = $settings['amount'];
		} elseif ( 'user_input' === $mode ) {
			$raw_amount = $posted_data['slp_amount'] ?? ( $posted_data['_slp_amount'] ?? null );
			$field = 'slp_amount';
		} else {
			$field = $settings['amount_field'];
			$raw_amount = $field ? ( $posted_data[ $field ] ?? null ) : null;
		}

		$amount = self::parse_twd_amount( $raw_amount );
		if ( null === $amount ) {
			return [
				'amount' => 0,
				'source' => $mode,
				'field'  => $field,
				'error'  => __( '請輸入有效的付款金額', 'mxp-cf7-slp' ),
			];
		}

		if ( $amount < $settings['amount_min'] ) {
			return [
				'amount' => $amount,
				'source' => $mode,
				'field'  => $field,
				'error'  => sprintf(
					/* translators: %s: minimum amount */
					__( '付款金額不可低於 NT$%s', 'mxp-cf7-slp' ),
					number_format( $settings['amount_min'] )
				),
			];
		}

		if ( $amount > $settings['amount_max'] ) {
			return [
				'amount' => $amount,
				'source' => $mode,
				'field'  => $field,
				'error'  => sprintf(
					/* translators: %s: maximum amount */
					__( '付款金額不可高於 NT$%s', 'mxp-cf7-slp' ),
					number_format( $settings['amount_max'] )
				),
			];
		}

		return [
			'amount' => $amount,
			'source' => $mode,
			'field'  => $field,
			'error'  => '',
		];
	}

	private static function parse_twd_amount( mixed $value ): ?int {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		$value = trim( (string) $value );
		$value = str_replace( [ ',', 'NT$', 'nt$', '$', ' ' ], '', $value );

		if ( '' === $value || ! preg_match( '/^\d+$/', $value ) ) {
			return null;
		}

		return absint( $value );
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
