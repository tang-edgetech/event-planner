<?php
/**
 * Encrypts and decrypts stored secrets (the SMTP password).
 *
 * AES-256-GCM with a key derived from AUTH_KEY + SECURE_AUTH_SALT; falls back to AES-256-CBC with an
 * HMAC-SHA256 tag when GCM is not available. Stored values look like "tmg1:g:<base64>" or "tmg1:c:<base64>".
 * Changing those wp-config.php keys makes the saved password unreadable (the admin types it again).
 *
 * Used by: TMG_SMTP_Settings.
 *
 * @package TamagoSMTP
 */

defined( 'ABSPATH' ) || exit;

class TMG_SMTP_Crypto {

	const PREFIX = 'tmg1:';

	/** Two independent keys (encryption, authentication) derived from the site's secret keys. */
	private static function keys(): array {
		$secret = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		if ( '' === $secret ) {
			// Sites without keys in wp-config.php still get the salts WordPress keeps in the database.
			$secret = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}
		return array(
			'enc' => hash_hmac( 'sha256', 'tamago-smtp|enc', $secret, true ),
			'mac' => hash_hmac( 'sha256', 'tamago-smtp|mac', $secret, true ),
		);
	}

	public static function gcm_available(): bool {
		return function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true );
	}

	public static function openssl_available(): bool {
		return function_exists( 'openssl_encrypt' );
	}

	public static function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * @param string $plain Secret to encrypt. Empty stays empty.
	 * @param string $mode  'auto', 'gcm' or 'cbc' (the last two exist for tests).
	 */
	public static function encrypt( string $plain, string $mode = 'auto' ): string {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! self::openssl_available() ) {
			// No OpenSSL at all: keep it working, the Tools tab warns that the secret is not encrypted.
			return self::PREFIX . 'p:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$keys = self::keys();
		if ( 'cbc' !== $mode && self::gcm_available() ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $keys['enc'], OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( false !== $ct ) {
				return self::PREFIX . 'g:' . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}
		$iv  = random_bytes( 16 );
		$ct  = (string) openssl_encrypt( $plain, 'aes-256-cbc', $keys['enc'], OPENSSL_RAW_DATA, $iv );
		$mac = hash_hmac( 'sha256', $iv . $ct, $keys['mac'], true );
		return self::PREFIX . 'c:' . base64_encode( $iv . $mac . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** Returns the secret, or '' when the value was tampered with or the keys changed. Unprefixed values are returned as-is. */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored || ! self::is_encrypted( $stored ) ) {
			return $stored;
		}
		$mode = substr( $stored, strlen( self::PREFIX ), 1 );
		$raw  = base64_decode( substr( $stored, strlen( self::PREFIX ) + 2 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return '';
		}
		if ( 'p' === $mode ) {
			return $raw;
		}
		if ( ! self::openssl_available() ) {
			return '';
		}
		$keys = self::keys();
		if ( 'g' === $mode ) {
			if ( strlen( $raw ) < 29 || ! self::gcm_available() ) {
				return '';
			}
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $keys['enc'], OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? '' : $plain;
		}
		if ( 'c' === $mode ) {
			if ( strlen( $raw ) < 49 ) {
				return '';
			}
			$iv  = substr( $raw, 0, 16 );
			$mac = substr( $raw, 16, 32 );
			$ct  = substr( $raw, 48 );
			if ( ! hash_equals( hash_hmac( 'sha256', $iv . $ct, $keys['mac'], true ), $mac ) ) {
				return '';
			}
			$plain = openssl_decrypt( $ct, 'aes-256-cbc', $keys['enc'], OPENSSL_RAW_DATA, $iv );
			return false === $plain ? '' : $plain;
		}
		return '';
	}
}
