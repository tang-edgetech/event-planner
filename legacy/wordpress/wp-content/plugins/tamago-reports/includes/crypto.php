<?php
/**
 * Encrypts stored secrets (the geo provider token) with AES-256-GCM, falling back
 * to AES-256-CBC + HMAC-SHA256 where GCM is unavailable. Key derived from AUTH_KEY + SECURE_AUTH_SALT.
 *
 * Used by: TMG_Reports_Settings.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_Crypto {

	private static function key(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		return hash( 'sha256', 'tamago-reports|' . $material, true );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$key = self::key();
		if ( in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $ct ) {
				return 'g1:' . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}
		$iv  = random_bytes( 16 );
		$ct  = (string) openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$mac = hash_hmac( 'sha256', $iv . $ct, $key, true );
		return 'c1:' . base64_encode( $iv . $mac . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( string $stored ): string {
		$prefix = substr( $stored, 0, 3 );
		if ( ! in_array( $prefix, array( 'g1:', 'c1:' ), true ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $stored; // Plain value (e.g. saved before encryption was possible).
		}
		$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return '';
		}
		$key = self::key();
		if ( 'g1:' === $prefix ) {
			$out = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $out ? '' : $out;
		}
		$iv  = substr( $raw, 0, 16 );
		$mac = substr( $raw, 16, 32 );
		$ct  = substr( $raw, 48 );
		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $ct, $key, true ), $mac ) ) {
			return '';
		}
		$out = openssl_decrypt( $ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $out ? '' : $out;
	}
}
