<?php
/**
 * Uninstall: removes settings, scheduled events, transients and downloaded
 * IP databases, on every site of a network. The statistics tables are
 * dropped only when "Delete the statistics tables when the plugin is
 * uninstalled" is ticked.
 *
 * @package NemvoAnalytics
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/nemvo-analytics.php';

/**
 * Remove the plugin's data from the current site.
 */
function nemvo_analytics_uninstall_site() {
	global $wpdb;
	NemvoAnalytics\Settings::flush();
	NemvoAnalytics\DB::reset();

	$settings = get_option( 'nemvo_analytics_settings', array() );
	if ( ! empty( $settings['drop_on_uninstall'] ) ) {
		NemvoAnalytics\DB::drop( 'local' );
		if ( NemvoAnalytics\DB::external_config() ) {
			NemvoAnalytics\DB::drop( 'external' );
		}
	}

	$options = array(
		'nemvo_analytics_settings',
		'nemvo_analytics_db',
		'nemvo_analytics_services',
		'nemvo_analytics_storage',
		'nemvo_analytics_schema',
		'nemvo_analytics_db_version',
		'nemvo_analytics_salt',
		'nemvo_analytics_geo',
		'nemvo_analytics_env',
		'nemvo_analytics_queue_state',
		'nemvo_analytics_migration',
		'nemvo_analytics_migrated',
		'nemvo_analytics_diag_log',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}
	// The activation notice and the request throttle's short-lived counters.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_nemvo_analytics_' ) . '%', $wpdb->esc_like( '_transient_timeout_nemvo_analytics_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removing the plugin's transients on uninstall.

	foreach ( array( 'nemvo_analytics_queue', 'nemvo_analytics_hourly', 'nemvo_analytics_daily', 'nemvo_analytics_weekly' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// Downloaded DB-IP databases.
	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'nemvo-analytics';
	if ( is_dir( $dir ) ) {
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			wp_delete_file( $file );
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own, now empty directory.
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'number' => 0,
			'fields' => 'ids',
		)
	) as $nemvo_analytics_site ) {
		switch_to_blog( $nemvo_analytics_site );
		nemvo_analytics_uninstall_site();
		restore_current_blog();
	}
} else {
	nemvo_analytics_uninstall_site();
}
