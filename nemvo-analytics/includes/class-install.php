<?php
/**
 * Versioned upgrades.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Brings a site from an older version to the current one, once, on the first
 * request after an update (and on activation). Every step is idempotent, so
 * a step that runs twice or is interrupted does no harm.
 *
 * DB_VERSION history:
 *  1  1.0.0-1.0.2: tables {prefix}nemvo_*, REST namespace nemvo/v1.
 *  2  1.0.3: tables {prefix}nemvo_analytics_* (renamed by DB::install()),
 *     encrypted connection secrets, cron schedule nemvo_analytics_minute.
 */
class Install {

	/**
	 * Raise when the schema or stored data change; dbDelta adds new columns
	 * and keys in each statistics database the next time it is used.
	 */
	const DB_VERSION = 2;

	const OPTION = 'nemvo_analytics_db_version';

	/**
	 * Upgrade when the stored version is older (one autoloaded option read).
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::OPTION, 0 ) < self::DB_VERSION ) {
			self::upgrade();
		}
	}

	/**
	 * Run the upgrade steps.
	 */
	public static function upgrade() {
		global $wpdb;
		$lock   = 'nemvo_analytics_upgrade';
		$locked = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 15 )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock, no table.
		try {
			// Another request may have finished while this one waited for the lock.
			$from = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uncached read on purpose.
			if ( $from >= self::DB_VERSION ) {
				wp_cache_delete( 'alloptions', 'options' );
				return;
			}
			$stored = get_option( Settings::OPTION );
			if ( false === $stored ) {
				add_option( Settings::OPTION, Settings::defaults() );
			} elseif ( is_array( $stored ) && ! array_key_exists( 'rdns_lookup', $stored ) ) {
				// Sites from before 1.0.3 had reverse DNS on by default: keep their behaviour.
				$stored['rdns_lookup'] = 1;
				update_option( Settings::OPTION, $stored );
			}
			Settings::flush();

			// The old default external prefix now means the new one (DB::external_config() maps it too).
			$db_option = get_option( Settings::DB_OPTION );
			if ( is_array( $db_option ) && DB::LEGACY_PREFIX === ( $db_option['prefix'] ?? '' ) ) {
				$db_option['prefix'] = DB::EXTERNAL_PREFIX;
				update_option( Settings::DB_OPTION, $db_option );
			}

			// Passwords and keys: encrypted, not autoloaded.
			Settings::protect_secrets();
			Cap::reset();
			Queue::reset();
			DB::reset();

			// The queue's minute schedule was called "nemvo_minute"; Cron::schedule() adds it under the new name.
			wp_clear_scheduled_hook( Cron::QUEUE );

			// Signatures of the old table names are of no use any more.
			update_option( DB::SCHEMA_OPTION, array(), true );

			// Rename and update the tables of the WordPress database, and of the external one when it is reachable now
			// (otherwise DB::maybe_install() does it on first use).
			DB::install( 'local' );
			if ( DB::external_config() && DB::conn( 'external' ) ) {
				DB::install( 'external' );
			}
			update_option( self::OPTION, self::DB_VERSION, true );
		} finally {
			if ( $locked ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock, no table.
			}
		}
		Cron::schedule();
	}
}
