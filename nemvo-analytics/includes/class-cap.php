<?php
/**
 * Optional Cap proof-of-work verification (https://capjs.js.org, self-hosted).
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * When Cap is on, the tracker solves one invisible Cap challenge per visit
 * and the collector verifies the token with Cap's siteverify endpoint.
 * Browsers talk to WordPress, which relays challenge and redeem requests to
 * the Cap server; so Cap can stay on an internal network (e.g. http://cap:3000
 * in Docker) and visitors load nothing from another host. A public Cap URL
 * can be set instead to skip the relay.
 */
class Cap {

	/** Version of the Cap widget bundled in assets/vendor/cap. */
	const WIDGET_VERSION = '0.1.63';

	/**
	 * Cached configuration (false = not read yet).
	 *
	 * @var array|null|false
	 */
	private static $cfg = false;

	/**
	 * Forget the cached configuration (after settings change, in tests).
	 */
	public static function reset() {
		self::$cfg = false;
	}

	/**
	 * Configuration, or null when Cap is off. NEMVO_ANALYTICS_CAP_URL,
	 * _SITE_KEY and _SECRET (constants or environment) turn it on.
	 *
	 * @return array{url:string,public_url:string,site_key:string,secret:string}|null
	 */
	public static function config() {
		if ( false !== self::$cfg ) {
			return self::$cfg;
		}
		if ( '' !== Settings::env( 'NEMVO_ANALYTICS_CAP_URL' ) ) {
			$cfg = array(
				'url'        => untrailingslashit( Settings::env( 'NEMVO_ANALYTICS_CAP_URL' ) ),
				'public_url' => untrailingslashit( Settings::env( 'NEMVO_ANALYTICS_CAP_PUBLIC_URL' ) ),
				'site_key'   => Settings::env( 'NEMVO_ANALYTICS_CAP_SITE_KEY' ),
				'secret'     => Settings::env( 'NEMVO_ANALYTICS_CAP_SECRET' ),
			);
		} else {
			$s   = Settings::services();
			$cfg = $s['cap_enabled'] ? array(
				'url'        => $s['cap_url'],
				'public_url' => $s['cap_public_url'],
				'site_key'   => $s['cap_site_key'],
				'secret'     => $s['cap_secret'],
			) : array();
		}
		self::$cfg = $cfg && self::configured( $cfg ) ? $cfg : null;
		return self::$cfg;
	}

	/**
	 * Whether a settings array has everything Cap needs.
	 *
	 * @param array $s Settings with url/cap_url, site_key/cap_site_key and secret/cap_secret.
	 * @return bool
	 */
	public static function configured( array $s ) {
		$url = $s['url'] ?? ( $s['cap_url'] ?? '' );
		$key = $s['site_key'] ?? ( $s['cap_site_key'] ?? '' );
		$sec = $s['secret'] ?? ( $s['cap_secret'] ?? '' );
		return '' !== $url && '' !== $key && '' !== $sec;
	}

	/**
	 * Whether Cap is in use.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return null !== self::config();
	}

	/**
	 * What the tracker needs: the API endpoint (the relay or the public
	 * server) and the bundled widget files.
	 *
	 * @return array
	 */
	public static function client_config() {
		$cfg = self::config();
		$dir = NEMVO_ANALYTICS_URL . 'assets/vendor/cap/';
		return array(
			'endpoint' => '' !== $cfg['public_url'] ? trailingslashit( $cfg['public_url'] ) . rawurlencode( $cfg['site_key'] ) . '/' : trailingslashit( rest_url( Collector::NS . '/cap' ) ),
			'script'   => $dir . 'cap.min.js?ver=' . self::WIDGET_VERSION,
			'wasm'     => $dir . 'cap_wasm_bg.wasm',
			'hashwx'   => $dir . 'hashwx.wasm',
			'pako'     => $dir . 'pako_inflate.min.js',
		);
	}

	/**
	 * Register the relay routes.
	 */
	public static function register_routes() {
		foreach ( Collector::NAMESPACES as $ns ) {
			foreach ( array( 'challenge', 'redeem' ) as $action ) {
				register_rest_route(
					$ns,
					'/cap/' . $action,
					array(
						'methods'             => 'POST',
						'callback'            => array( __CLASS__, 'relay' ),
						// Public on purpose: visitors' browsers solve the challenge. Only answers while Cap is
						// on, forwards nothing but the body (max 64 KB) to the configured Cap server, and each
						// visitor (by anonymous hash) is throttled; Cap rate-limits per visitor address as well.
						'permission_callback' => '__return_true',
					)
				);
			}
		}
	}

	/**
	 * Forward a challenge or redeem request to the Cap server.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function relay( \WP_REST_Request $request ) {
		$cfg = self::config();
		if ( ! $cfg ) {
			return new \WP_REST_Response( array( 'error' => 'Cap is not enabled.' ), 404 );
		}
		$action = substr( $request->get_route(), strrpos( $request->get_route(), '/' ) + 1 );
		$body   = (string) $request->get_body();
		if ( strlen( $body ) > 65536 ) {
			return new \WP_REST_Response( array( 'error' => 'Too large.' ), 413 );
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '';
		if ( ! Throttle::allow( 'cap', Privacy::visitor_hash( Privacy::client_ip(), $ua ) ) ) {
			return new \WP_REST_Response( array( 'error' => 'Too many requests.' ), 429 );
		}
		$response = wp_remote_post(
			trailingslashit( $cfg['url'] ) . rawurlencode( $cfg['site_key'] ) . '/' . $action,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'    => 'application/json',
					// Cap rate-limits per visitor IP.
					'X-Forwarded-For' => Privacy::client_ip(),
				),
				'body'    => '' === $body ? '{}' : $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_REST_Response( array( 'error' => 'Cap is not reachable.' ), 502 );
		}
		$out = new \WP_REST_Response( json_decode( wp_remote_retrieve_body( $response ), true ), (int) wp_remote_retrieve_response_code( $response ) );
		$out->header( 'Cache-Control', 'no-store' );
		return $out;
	}

	/**
	 * Verify a token with siteverify.
	 *
	 * @param string $token Token from the widget.
	 * @return bool
	 */
	public static function verify( $token ) {
		$cfg = self::config();
		if ( ! $cfg || '' === $token || strlen( $token ) > 512 ) {
			return false;
		}
		$response = wp_remote_post(
			trailingslashit( $cfg['url'] ) . rawurlencode( $cfg['site_key'] ) . '/siteverify',
			array(
				'timeout' => 5,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'secret'   => $cfg['secret'],
						'response' => $token,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		// A rejected secret fails every visit's verification: Site Health reports it.
		$error = is_array( $data ) && ! empty( $data['error'] ) && is_scalar( $data['error'] ) ? (string) $data['error'] : '';
		self::note_rejection( false !== stripos( $error, 'secret' ) ? $error : '' );
		return ! empty( $data['success'] );
	}

	/**
	 * Remember (or forget) that Cap rejected the keys, for Site Health. Writes
	 * the option only when the state changes.
	 *
	 * @param string $error Cap's error message, or '' when the keys were accepted.
	 */
	public static function note_rejection( $error ) {
		$env = get_option( Health::OPTION, array() );
		$env = is_array( $env ) ? $env : array();
		if ( '' === $error && ! empty( $env['cap_rejected'] ) ) {
			unset( $env['cap_rejected'] );
			update_option( Health::OPTION, $env, true );
		} elseif ( '' !== $error && empty( $env['cap_rejected'] ) ) {
			$env['cap_rejected'] = time();
			update_option( Health::OPTION, $env, true );
		}
	}

	/**
	 * When Cap last rejected the keys while verifying a visit (0 = not since they last worked).
	 *
	 * @return int Unix time.
	 */
	public static function rejected_since() {
		$env = get_option( Health::OPTION, array() );
		return is_array( $env ) ? (int) ( $env['cap_rejected'] ?? 0 ) : 0;
	}

	/**
	 * Check that the server is reachable and accepts the keys (settings page).
	 * Cap checks a token's shape (<site key>:<id>:<n>) before the keys, so the
	 * made-up token has that shape: valid keys then get "Token not found",
	 * wrong ones "Invalid site key or secret".
	 *
	 * @param array $s Services settings.
	 * @return array{ok:bool,message:string}
	 */
	public static function test( array $s ) {
		if ( ! self::configured( $s ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Enter the server address, site key and secret key first.', 'nemvo-analytics' ),
			);
		}
		$response = wp_remote_post(
			trailingslashit( $s['cap_url'] ) . rawurlencode( $s['cap_site_key'] ) . '/siteverify',
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'secret'   => $s['cap_secret'],
						'response' => $s['cap_site_key'] . ':connection-test:0',
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				/* translators: %s: error message */
				'message' => sprintf( __( 'Cap is not reachable from WordPress: %s', 'nemvo-analytics' ), $response->get_error_message() ),
			);
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! array_key_exists( 'success', $data ) ) {
			return array(
				'ok'      => false,
				/* translators: %d: HTTP status */
				'message' => sprintf( __( 'The server answered with HTTP %d but not like Cap. Check the address and the site key.', 'nemvo-analytics' ), (int) wp_remote_retrieve_response_code( $response ) ),
			);
		}
		$error = ! empty( $data['error'] ) && is_scalar( $data['error'] ) ? (string) $data['error'] : '';
		if ( false !== stripos( $error, 'secret' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Cap rejected the site key or the secret key.', 'nemvo-analytics' ),
			);
		}
		if ( empty( $data['success'] ) && false === stripos( $error, 'not found' ) ) {
			return array(
				'ok'      => false,
				/* translators: %s: error message from Cap */
				'message' => sprintf( __( 'Cap answered, but the keys could not be checked: %s', 'nemvo-analytics' ), '' !== $error ? $error : __( 'unknown error', 'nemvo-analytics' ) ),
			);
		}
		return array(
			'ok'      => true,
			'message' => __( 'Cap is reachable and accepts the keys.', 'nemvo-analytics' ),
		);
	}
}
