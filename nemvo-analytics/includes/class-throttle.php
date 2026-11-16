<?php
/**
 * Request throttle for the public endpoints.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * A fixed one-minute window per anonymous visitor hash (never the IP) and
 * endpoint. Counts live in the persistent object cache when the site has
 * one, otherwise in a short-lived transient. A real visitor sends a few
 * hits a minute; the limits are far above that and only stop floods.
 */
class Throttle {

	/**
	 * Hits per minute and visitor: the collector, and the Cap relay.
	 */
	const LIMITS = array(
		'hit' => 120,
		'cap' => 20,
	);

	/**
	 * Count one request and say whether it is within the limit.
	 *
	 * @param string $bucket  hit|cap.
	 * @param string $visitor Visitor hash.
	 * @return bool
	 */
	public static function allow( $bucket, $visitor ) {
		/**
		 * Filters the requests per minute a visitor may send to a public endpoint (0 = no limit).
		 *
		 * @param int    $limit  Limit.
		 * @param string $bucket hit (collector) or cap (Cap relay).
		 */
		$limit = (int) apply_filters( 'nemvo_analytics_rate_limit', self::LIMITS[ $bucket ] ?? 60, $bucket );
		if ( $limit <= 0 ) {
			return true;
		}
		$key = 'nemvo_analytics_rl_' . $bucket . '_' . substr( md5( $visitor ), 0, 12 ) . '_' . (int) floor( time() / MINUTE_IN_SECONDS );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'nemvo_analytics', MINUTE_IN_SECONDS );
			$count = (int) wp_cache_incr( $key, 1, 'nemvo_analytics' );
		} else {
			$count = (int) get_transient( $key ) + 1;
			set_transient( $key, $count, MINUTE_IN_SECONDS );
		}
		return $count <= $limit;
	}
}
