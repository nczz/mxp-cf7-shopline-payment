<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MXP_SLP_Security {

	private const CIPHER = 'aes-256-cbc';

	public static function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$key = self::get_encryption_key();
		$iv  = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );

		$encrypted = openssl_encrypt( $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return $value; // fallback: 明文
		}

		return base64_encode( $iv . $encrypted );
	}

	public static function decrypt( string $encrypted ): string {
		if ( '' === $encrypted ) {
			return '';
		}

		$data = base64_decode( $encrypted, true );

		if ( false === $data ) {
			return $encrypted; // 可能是未加密的舊資料
		}

		$key     = self::get_encryption_key();
		$iv_len  = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $data ) <= $iv_len ) {
			return $encrypted;
		}

		$iv        = substr( $data, 0, $iv_len );
		$ciphertext = substr( $data, $iv_len );

		$decrypted = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return false !== $decrypted ? $decrypted : $encrypted;
	}

	private static function get_encryption_key(): string {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'mxp-slp-default-key';
		return substr( hash( 'sha256', $salt ), 0, 32 );
	}

	public static function check_rate_limit( string $ip, int $max = 5, int $window = 60 ): bool {
		$key   = '_slp_rate_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );
		return true;
	}

	public static function generate_order_token(): string {
		return wp_generate_password( 32, false, false );
	}

	public static function validate_amount( int $amount, int $max = 10000000 ): bool {
		return $amount > 0 && $amount <= $max;
	}
}
