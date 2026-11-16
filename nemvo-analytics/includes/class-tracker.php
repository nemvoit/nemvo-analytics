<?php
/**
 * Front-end tracker output.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the tracking script with page context (post id, 404, search).
 *
 * The tracker is a registered script handle. Printed inline (the default) it
 * has no source file and its code is added with wp_add_inline_script(), so
 * it costs no extra request; as a file it loads assets/js/tracker.js with
 * the configuration added before it. Either way the tags carry the
 * attributes that keep caching and optimization plugins away from it.
 */
class Tracker {

	/**
	 * Script handle (tags get the ids nemvo-analytics-tracker-js*).
	 */
	const HANDLE = 'nemvo-analytics-tracker';

	/**
	 * Handle of the opt-out shortcode's script.
	 */
	const OPTOUT_HANDLE = 'nemvo-analytics-optout';

	/**
	 * Id of the pageview recorded on the server for this request.
	 *
	 * @var string
	 */
	private static $pv = '';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'record_request' ), 9999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 99 );
		add_filter( 'wp_script_attributes', array( __CLASS__, 'tag_attributes' ) );
		add_filter( 'wp_inline_script_attributes', array( __CLASS__, 'tag_attributes' ) );
		add_shortcode( 'nemvo_analytics_optout', array( __CLASS__, 'optout' ) );
	}

	/**
	 * Add the opt-out attributes to the tracker's script tags.
	 *
	 * @param array $attributes Tag attributes.
	 * @return array
	 */
	public static function tag_attributes( $attributes ) {
		if ( isset( $attributes['id'] ) && 0 === strpos( (string) $attributes['id'], self::HANDLE . '-js' ) ) {
			$attributes += self::script_attributes();
		}
		return $attributes;
	}

	/**
	 * Attributes that keep caching and optimization plugins from delaying,
	 * combining or rewriting the tracker.
	 *
	 * @return array
	 */
	private static function script_attributes() {
		return array(
			'data-cfasync'            => 'false',
			'data-no-optimize'        => '1',
			'data-no-defer'           => '1',
			'data-no-minify'          => '1',
			'data-noptimize'          => '1',
			'nowprocket'              => true,
			'data-pagespeed-no-defer' => true,
		);
	}

	/**
	 * [nemvo_analytics_optout]: a checkbox visitors can untick to stop being
	 * counted in this browser (for your privacy policy page).
	 *
	 * @param array $atts Shortcode attributes: label.
	 * @return string
	 */
	public static function optout( $atts ) {
		$atts = shortcode_atts(
			array(
				'label' => __( 'Include my visits in this site\'s anonymous statistics.', 'nemvo-analytics' ),
			),
			$atts,
			'nemvo_analytics_optout'
		);
		// The flag the tracker checks; "nva_ignore" is its name from before 1.0.3.
		$js = '(function(){var k="nemvo-analytics-ignore",o="nva_ignore";document.querySelectorAll("[data-nemvo-analytics-optout]").forEach(function(c){try{c.checked=localStorage.getItem(k)!=="1"&&localStorage.getItem(o)!=="1";}catch(e){c.disabled=true;}c.addEventListener("change",function(){try{localStorage.removeItem(o);if(c.checked){localStorage.removeItem(k);}else{localStorage.setItem(k,"1");}}catch(e){}});});})();';
		if ( ! wp_script_is( self::OPTOUT_HANDLE, 'registered' ) ) {
			wp_register_script( self::OPTOUT_HANDLE, false, array(), NEMVO_ANALYTICS_VERSION, true );
			wp_add_inline_script( self::OPTOUT_HANDLE, $js );
		}
		wp_enqueue_script( self::OPTOUT_HANDLE );
		return '<label class="nva-optout"><input type="checkbox" data-nemvo-analytics-optout checked> ' . esc_html( $atts['label'] ) . '</label>';
	}

	/**
	 * Whether the current visitor should be tracked at all.
	 *
	 * @return bool
	 */
	public static function should_track() {
		if ( ! Settings::get( 'tracking_enabled' ) || is_admin() || is_preview() || is_customize_preview() || wp_doing_ajax() ) {
			return false;
		}
		if ( is_user_logged_in() ) {
			$excluded = (array) Settings::get( 'exclude_roles' );
			if ( array_intersect( $excluded, (array) wp_get_current_user()->roles ) ) {
				return false;
			}
		}
		/**
		 * Filters whether the current request is tracked.
		 *
		 * @param bool $track Whether to print the tracker.
		 */
		return (bool) apply_filters( 'nemvo_analytics_should_track', true );
	}

	/**
	 * Record the page request on the server (after redirects have happened).
	 */
	public static function record_request() {
		if ( ! Settings::get( 'server_hits' ) || ! self::should_track() || is_feed() || is_robots() || is_favicon() || is_trackback() || is_embed() || get_query_var( 'sitemap' ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		self::$pv = Collector::server_hit();
	}

	/**
	 * Page context the browser cannot know.
	 *
	 * @return array
	 */
	public static function context() {
		$ctx = array(
			'type' => 'other',
			'id'   => 0,
		);
		if ( is_404() ) {
			$ctx['type'] = '404';
			$ctx['nf']   = 1;
		} elseif ( is_search() ) {
			$ctx['type'] = 'search';
			if ( Settings::get( 'track_search' ) ) {
				$ctx['sq'] = mb_substr( get_search_query( false ), 0, 100 );
			}
		} elseif ( is_front_page() || is_home() ) {
			$ctx['type'] = 'home';
		} elseif ( is_singular() ) {
			$ctx['type'] = get_post_type();
			$ctx['id']   = get_queried_object_id();
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$ctx['type'] = 'taxonomy';
		} elseif ( is_author() ) {
			$ctx['type'] = 'author';
		} elseif ( is_archive() ) {
			$ctx['type'] = 'archive';
		}
		return $ctx;
	}

	/**
	 * Enqueue the tracker (printed in the footer).
	 */
	public static function enqueue() {
		if ( ! self::should_track() ) {
			return;
		}

		$config = array(
			'endpoint'  => esc_url_raw( rest_url( Collector::NS . '/e' ) ),
			'dnt'       => (bool) Settings::get( 'respect_dnt' ),
			'outbound'  => (bool) Settings::get( 'track_outbound' ),
			'downloads' => Settings::get( 'track_downloads' ) ? explode( ',', Settings::get( 'download_extensions' ) ) : array(),
			'forms'     => (bool) Settings::get( 'track_forms' ),
			'contact'   => (bool) Settings::get( 'track_contact_links' ),
			'scroll'    => (bool) Settings::get( 'track_scroll' ),
			'ctx'       => self::context(),
			'cap'       => Cap::enabled() ? Cap::client_config() : null,
			'diag'      => Diagnostics::active(),
			'pv'        => self::$pv,
		);
		$config = 'window.nemvoAnalyticsConfig=' . wp_json_encode( $config ) . ';';

		$file = NEMVO_ANALYTICS_DIR . 'assets/js/tracker.min.js';
		if ( ! is_readable( $file ) ) {
			$file = NEMVO_ANALYTICS_DIR . 'assets/js/tracker.js';
		}

		if ( Settings::get( 'inline_script' ) && is_readable( $file ) ) {
			// Inline: no extra request, nothing for URL-based blockers to match.
			wp_register_script( self::HANDLE, false, array(), NEMVO_ANALYTICS_VERSION, true );
			wp_add_inline_script( self::HANDLE, $config . "\n" . file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own script, printed inline.
		} else {
			wp_register_script( self::HANDLE, NEMVO_ANALYTICS_URL . 'assets/js/tracker.js', array(), NEMVO_ANALYTICS_VERSION, true );
			wp_script_add_data( self::HANDLE, 'strategy', 'defer' );
			wp_add_inline_script( self::HANDLE, $config, 'before' );
		}
		wp_enqueue_script( self::HANDLE );
	}
}
