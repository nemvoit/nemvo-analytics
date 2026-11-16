<?php
/**
 * Encryption of stored secrets.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Passwords and keys saved on the settings page (external database
 * password, Valkey password, Cap secret key) are encrypted with
 * XSalsa20-Poly1305 (sodium's secretbox; WordPress bundles sodium_compat for
 * servers without the extension). The key comes from NEMVO_ANALYTICS_KEY
 * when defined, otherwise from the WordPress salts in wp-config.php, so a
 * copy of the database alone can't be decrypted. Values set in wp-config.php
 * or the environment are never stored.
 */
class Crypto {

	const PREFIX = 'nva1:';

	/**
	 * The 32-byte key.
	 *
	 * @return string
	 */
	private static function key() {
		$secret = Settings::env( 'NEMVO_ANALYTICS_KEY' );
		if ( '' === $secret ) {
			$secret = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}
		return hash_hmac( 'sha256', 'nemvo-analytics-secrets', $secret, true );
	}

	/**
	 * Whether a stored value is encrypted.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Encrypt a string.
	 *
	 * @param string $plain Plain text.
	 * @return string Prefixed base64, or '' for ''.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || self::is_encrypted( $plain ) ) {
			return $plain;
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encrypted binary data.
	}

	/**
	 * Decrypt a stored value. Values saved before 1.0.3 are plain text and
	 * returned as they are.
	 *
	 * @param mixed $value Stored value.
	 * @return string Plain text, '' when it can't be decrypted (e.g. the salts changed).
	 */
	public static function decrypt( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- encrypted binary data.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return '';
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		} catch ( \Throwable $e ) {
			return '';
		}
		return false === $plain ? '' : $plain;
	}
}
