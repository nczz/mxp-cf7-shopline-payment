<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MXP_SLP_Security {

	private const CIPHER = 'aes-256-cbc';
	private const ENCRYPTION_PREFIX = 'v2:';

	public static function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$key = self::get_encryption_key( 'enc' );
		$mac_key = self::get_encryption_key( 'mac' );
		$iv  = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );

		$encrypted = openssl_encrypt( $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			return '';
		}

		$payload = $iv . $encrypted;
		$mac = hash_hmac( 'sha256', $payload, $mac_key, true );

		return self::ENCRYPTION_PREFIX . base64_encode( $mac . $payload );
	}

	public static function decrypt( string $encrypted ): string {
		if ( '' === $encrypted ) {
			return '';
		}

		if ( str_starts_with( $encrypted, self::ENCRYPTION_PREFIX ) ) {
			return self::decrypt_v2( substr( $encrypted, strlen( self::ENCRYPTION_PREFIX ) ) );
		}

		return self::decrypt_legacy( $encrypted );
	}

	private static function decrypt_v2( string $encrypted ): string {
		$data = base64_decode( $encrypted, true );

		if ( false === $data ) {
			return '';
		}

		$key     = self::get_encryption_key( 'enc' );
		$mac_key = self::get_encryption_key( 'mac' );
		$iv_len  = openssl_cipher_iv_length( self::CIPHER );
		$mac_len = 32;

		if ( strlen( $data ) <= $mac_len + $iv_len ) {
			return '';
		}

		$mac = substr( $data, 0, $mac_len );
		$payload = substr( $data, $mac_len );
		$expected = hash_hmac( 'sha256', $payload, $mac_key, true );

		if ( ! hash_equals( $expected, $mac ) ) {
			return '';
		}

		$iv = substr( $payload, 0, $iv_len );
		$ciphertext = substr( $payload, $iv_len );

		$decrypted = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return false !== $decrypted ? $decrypted : '';
	}

	private static function decrypt_legacy( string $encrypted ): string {
		$data = base64_decode( $encrypted, true );

		if ( false === $data ) {
			return $encrypted;
		}

		$key    = self::get_legacy_encryption_key();
		$iv_len = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $data ) <= $iv_len ) {
			return $encrypted;
		}

		$iv = substr( $data, 0, $iv_len );
		$ciphertext = substr( $data, $iv_len );
		$decrypted = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return false !== $decrypted ? $decrypted : $encrypted;
	}

	private static function get_encryption_key( string $context ): string {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'mxp-slp-default-key';
		return hash_hmac( 'sha256', $context, $salt, true );
	}

	private static function get_legacy_encryption_key(): string {
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

	public static function is_valid_order_token( string $token ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9]{32}$/', $token );
	}

	public static function validate_amount( int $amount, int $max = 10000000 ): bool {
		return $amount > 0 && $amount <= $max;
	}
}
