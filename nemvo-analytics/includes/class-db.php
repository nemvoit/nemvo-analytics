<?php
/**
 * Statistics storage: where it is, connections and schema.
 *
 * @package NemvoAnalytics
 */

namespace NemvoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Statistics are stored in one of two places ("targets"):
 *  - local:    the WordPress database, tables {wp_prefix}nemvo_analytics_*
 *              (default);
 *  - external: a separate MySQL/MariaDB database, configured with
 *              NEMVO_ANALYTICS_DB_* constants, environment variables or the
 *              settings page; tables {prefix}*, prefix "nemvo_analytics_" by
 *              default.
 * The active target is stored in STORAGE_OPTION and changed by Migration.
 *
 * Up to 1.0.2 the tables were called {wp_prefix}nemvo_* and the external
 * default prefix was "nemvo_". install() renames such tables once, in one
 * atomic RENAME TABLE, when the new ones don't exist yet. An external prefix
 * of "nemvo_" (the old default, also when set explicitly) now means
 * "nemvo_analytics_"; any other prefix is used as it is.
 */
class DB {

	const SCHEMA_OPTION   = 'nemvo_analytics_schema';
	const STORAGE_OPTION  = 'nemvo_analytics_storage';
	const TABLES          = array( 'sessions', 'pageviews', 'events' );
	const ALL_TABLES      = array( 'events', 'pageviews', 'sessions', 'meta' );
	const LOCAL_PREFIX    = 'nemvo_analytics_';
	const EXTERNAL_PREFIX = 'nemvo_analytics_';
	const LEGACY_PREFIX   = 'nemvo_';

	/**
	 * Open connections per target (false after a failed attempt).
	 *
	 * @var array
	 */
	private static $conns = array();

	/**
	 * Connection errors per target.
	 *
	 * @var array
	 */
	private static $errors = array();

	/**
	 * Cached active target.
	 *
	 * @var string|null
	 */
	private static $active = null;

	/**
	 * The external database configuration, or null when none is set.
	 *
	 * @return array{source:string,host:string,name:string,user:string,password:string,prefix:string}|null
	 */
	public static function external_config() {
		if ( '' !== Settings::env( 'NEMVO_ANALYTICS_DB_NAME' ) ) {
			$cfg = array(
				'source'   => defined( 'NEMVO_ANALYTICS_DB_NAME' ) ? 'constant' : 'env',
				'host'     => Settings::env( 'NEMVO_ANALYTICS_DB_HOST' ),
				'name'     => Settings::env( 'NEMVO_ANALYTICS_DB_NAME' ),
				'user'     => Settings::env( 'NEMVO_ANALYTICS_DB_USER' ),
				'password' => Settings::env( 'NEMVO_ANALYTICS_DB_PASSWORD' ),
				'prefix'   => Settings::env( 'NEMVO_ANALYTICS_DB_PREFIX' ),
			);
		} else {
			$cfg = Settings::db_settings();
			if ( '' === $cfg['name'] ) {
				return null;
			}
			$cfg['source'] = 'settings';
		}
		$cfg['host']   = '' === $cfg['host'] ? 'localhost' : $cfg['host'];
		$cfg['prefix'] = (string) preg_replace( '/[^A-Za-z0-9_]/', '', (string) $cfg['prefix'] );
		// The default prefix, and the one of 1.0.0-1.0.2 whose tables install() renames.
		$cfg['legacy'] = '' === $cfg['prefix'] || self::LEGACY_PREFIX === $cfg['prefix'] ? self::LEGACY_PREFIX : '';
		$cfg['prefix'] = '' !== $cfg['legacy'] ? self::EXTERNAL_PREFIX : $cfg['prefix'];
		return $cfg;
	}

	/**
	 * The active target: what the admin chose through a migration, else
	 * NEMVO_ANALYTICS_STORAGE, else external when wp-config.php or the
	 * environment defines an external database, else local.
	 *
	 * @return string local|external
	 */
	public static function active() {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$external = self::external_config();
		$mode     = get_option( self::STORAGE_OPTION, '' );
		if ( ! in_array( $mode, array( 'local', 'external' ), true ) ) {
			$env  = Settings::env( 'NEMVO_ANALYTICS_STORAGE' );
			$mode = in_array( $env, array( 'local', 'external' ), true ) ? $env : ( $external && 'settings' !== $external['source'] ? 'external' : 'local' );
		}
		self::$active = 'external' === $mode && ! $external ? 'local' : $mode;
		return self::$active;
	}

	/**
	 * Forget cached connections and the active target (uninstall on
	 * multisite, tests).
	 */
	public static function reset() {
		self::$active = null;
		self::$conns  = array();
		self::$errors = array();
	}

	/**
	 * Switch the active target (after a migration).
	 *
	 * @param string $target local|external.
	 */
	public static function set_active( $target ) {
		update_option( self::STORAGE_OPTION, $target, true );
		self::$active = $target;
	}

	/**
	 * Whether statistics live outside the WordPress database.
	 *
	 * @return bool
	 */
	public static function is_external() {
		return 'external' === self::active();
	}

	/**
	 * Table name prefix of a target.
	 *
	 * @param string|null $target Target (default: active).
	 * @return string
	 */
	public static function prefix( $target = null ) {
		global $wpdb;
		$target = $target ? $target : self::active();
		if ( 'external' === $target ) {
			$cfg = self::external_config();
			return $cfg ? $cfg['prefix'] : self::EXTERNAL_PREFIX;
		}
		return $wpdb->prefix . self::LOCAL_PREFIX;
	}

	/**
	 * Table name prefix the target used up to 1.0.2, or '' when its tables
	 * were never renamed (a custom external prefix).
	 *
	 * @param string $target local|external.
	 * @return string
	 */
	public static function legacy_prefix( $target ) {
		global $wpdb;
		if ( 'external' === $target ) {
			$cfg = self::external_config();
			return $cfg ? $cfg['legacy'] : '';
		}
		return $wpdb->prefix . self::LEGACY_PREFIX;
	}

	/**
	 * Fully qualified table name.
	 *
	 * @param string      $name   sessions|pageviews|events|meta.
	 * @param string|null $target Target (default: active).
	 * @return string
	 */
	public static function table( $name, $target = null ) {
		return self::prefix( $target ) . $name;
	}

	/**
	 * The connection of a target, or null when it is unreachable.
	 *
	 * @param string|null $target Target (default: active).
	 * @return \wpdb|null
	 */
	public static function conn( $target = null ) {
		global $wpdb;
		$target = $target ? $target : self::active();
		if ( 'local' === $target ) {
			return $wpdb;
		}
		if ( isset( self::$conns[ $target ] ) ) {
			return self::$conns[ $target ] ? self::$conns[ $target ] : null;
		}
		$cfg = self::external_config();
		if ( ! $cfg ) {
			self::$errors[ $target ] = __( 'No external database is configured.', 'nemvo-analytics' );
			self::$conns[ $target ]  = false;
			return null;
		}
		$conn = new Connection( $cfg['user'], $cfg['password'], $cfg['name'], $cfg['host'] );
		if ( ! $conn->open() ) {
			self::$errors[ $target ] = $conn->connect_error;
			self::$conns[ $target ]  = false;
			return null;
		}
		self::$conns[ $target ] = $conn;
		return $conn;
	}

	/**
	 * Last connection error of a target.
	 *
	 * @param string|null $target Target (default: active).
	 * @return string
	 */
	public static function error( $target = null ) {
		$target = $target ? $target : self::active();
		return self::$errors[ $target ] ?? '';
	}

	/**
	 * Identifies a database so the schema check follows a switch.
	 *
	 * @param string $target Target.
	 * @return string
	 */
	private static function signature( $target ) {
		if ( 'local' === $target ) {
			return 'local|' . self::prefix( 'local' );
		}
		$cfg = self::external_config();
		return md5( ( $cfg ? $cfg['host'] . '|' . $cfg['name'] : '' ) . '|' . self::prefix( 'external' ) );
	}

	/**
	 * Whether a target has an up-to-date schema (cheap: an autoloaded option).
	 *
	 * @param string|null $target Target (default: active).
	 * @return bool
	 */
	public static function schema_ok( $target = null ) {
		$target = $target ? $target : self::active();
		$known  = get_option( self::SCHEMA_OPTION, array() );
		$sig    = self::signature( $target );
		return is_array( $known ) && isset( $known[ $sig ] ) && (int) $known[ $sig ] >= Install::DB_VERSION;
	}

	/**
	 * Install or upgrade the active target when needed.
	 *
	 * @return bool
	 */
	public static function maybe_install() {
		return self::schema_ok() || self::install();
	}

	/**
	 * Create or upgrade the tables in a target: rename the tables of 1.0.0-1.0.2
	 * if there are any, then dbDelta. Holds a database lock meanwhile, so hits
	 * arriving during an upgrade wait for it instead of failing.
	 *
	 * @param string|null $target Target (default: active).
	 * @return bool
	 */
	public static function install( $target = null ) {
		$target = $target ? $target : self::active();
		$db     = self::conn( $target );
		if ( ! $db ) {
			return false;
		}
		$lock   = 'nemvo_analytics_install';
		$locked = (bool) $db->get_var( $db->prepare( 'SELECT GET_LOCK( %s, 15 )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock, no table.
		try {
			if ( ! self::rename_legacy( $target ) ) {
				return false;
			}
			$ok = self::create_tables( $target );
		} finally {
			if ( $locked ) {
				$db->query( $db->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock, no table.
			}
		}
		if ( ! $ok ) {
			return false;
		}

		$db->replace(
			self::table( 'meta', $target ),
			array(
				'meta_key'   => 'schema_version', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the plugin's own key-value table.
				'meta_value' => (string) Install::DB_VERSION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the plugin's own key-value table.
			)
		);

		$known                               = get_option( self::SCHEMA_OPTION, array() );
		$known                               = is_array( $known ) ? $known : array();
		$known[ self::signature( $target ) ] = Install::DB_VERSION;
		update_option( self::SCHEMA_OPTION, $known, true );
		return true;
	}

	/**
	 * Whether a table exists in a target.
	 *
	 * @param \wpdb  $db    Connection.
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $db, $table ) {
		return $table === $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.
	}

	/**
	 * Whether 1.0.0-1.0.2 tables of this plugin are still under their old
	 * names (their meta table carries our schema version).
	 *
	 * @param string $target local|external.
	 * @return bool
	 */
	public static function has_legacy( $target ) {
		$old = self::legacy_prefix( $target );
		$db  = '' === $old ? null : self::conn( $target );
		if ( ! $db || ! self::table_exists( $db, $old . 'meta' ) ) {
			return false;
		}
		$suppress = $db->suppress_errors();
		$version  = $db->get_var( "SELECT meta_value FROM `{$old}meta` WHERE meta_key = 'schema_version'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- the plugin's own old table.
		$db->suppress_errors( $suppress );
		return null !== $version;
	}

	/**
	 * Rename the tables of 1.0.0-1.0.2 ({prefix}nemvo_*) to the current names,
	 * in one atomic statement, when none of the new tables exists yet. Does
	 * nothing when there are no old tables or the new ones exist already.
	 *
	 * @param string $target local|external.
	 * @return bool False when the rename failed.
	 */
	public static function rename_legacy( $target ) {
		$db  = self::conn( $target );
		$old = self::legacy_prefix( $target );
		if ( ! $db || ! self::has_legacy( $target ) ) {
			return (bool) $db;
		}
		$pairs = array();
		foreach ( self::ALL_TABLES as $name ) {
			if ( self::table_exists( $db, self::table( $name, $target ) ) ) {
				return true; // Already renamed, or created anew: leave both alone.
			}
			if ( self::table_exists( $db, $old . $name ) ) {
				$pairs[] = '`' . $old . $name . '` TO `' . self::table( $name, $target ) . '`';
			}
		}
		if ( ! $pairs ) {
			return true;
		}
		if ( false === $db->query( 'RENAME TABLE ' . implode( ', ', $pairs ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- renaming the plugin's own tables on upgrade.
			self::$errors[ $target ] = $db->last_error;
			return false;
		}
		return true;
	}

	/**
	 * Create or update the tables with dbDelta. dbDelta works on the global
	 * $wpdb, so it is pointed at the external connection for the duration.
	 *
	 * @param string $target local|external.
	 * @return bool
	 */
	private static function create_tables( $target ) {
		global $wpdb;
		$db = self::conn( $target );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
		$s    = self::table( 'sessions', $target );
		$p    = self::table( 'pageviews', $target );
		$e    = self::table( 'events', $target );
		$m    = self::table( 'meta', $target );

		$sql = array(
			"CREATE TABLE $s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				visitor char(16) NOT NULL,
				started_at datetime NOT NULL,
				last_seen datetime NOT NULL,
				duration int(10) unsigned NOT NULL DEFAULT 0,
				pageviews smallint(5) unsigned NOT NULL DEFAULT 0,
				events smallint(5) unsigned NOT NULL DEFAULT 0,
				is_bounce tinyint(1) NOT NULL DEFAULT 1,
				entry_path varchar(255) NOT NULL DEFAULT '',
				exit_path varchar(255) NOT NULL DEFAULT '',
				exit_pv char(16) NOT NULL DEFAULT '',
				channel varchar(32) NOT NULL DEFAULT '',
				source varchar(100) NOT NULL DEFAULT '',
				referrer_host varchar(191) NOT NULL DEFAULT '',
				referrer_url varchar(255) NOT NULL DEFAULT '',
				utm_source varchar(100) NOT NULL DEFAULT '',
				utm_medium varchar(100) NOT NULL DEFAULT '',
				utm_campaign varchar(150) NOT NULL DEFAULT '',
				utm_term varchar(150) NOT NULL DEFAULT '',
				utm_content varchar(150) NOT NULL DEFAULT '',
				country char(2) NOT NULL DEFAULT '',
				region varchar(100) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				language varchar(16) NOT NULL DEFAULT '',
				device varchar(16) NOT NULL DEFAULT '',
				os varchar(32) NOT NULL DEFAULT '',
				browser varchar(40) NOT NULL DEFAULT '',
				screen varchar(8) NOT NULL DEFAULT '',
				asn int(10) unsigned NOT NULL DEFAULT 0,
				org varchar(100) NOT NULL DEFAULT '',
				network varchar(16) NOT NULL DEFAULT '',
				company varchar(100) NOT NULL DEFAULT '',
				bot_score tinyint(3) unsigned NOT NULL DEFAULT 0,
				bot_flags int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY visitor_seen (visitor,last_seen),
				KEY started_at (started_at),
				KEY last_seen (last_seen)
			) $opts;",
			"CREATE TABLE $p (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NOT NULL,
				pv char(16) NOT NULL,
				visitor char(16) NOT NULL,
				created_at datetime NOT NULL,
				path varchar(255) NOT NULL DEFAULT '',
				title varchar(255) NOT NULL DEFAULT '',
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				content_type varchar(32) NOT NULL DEFAULT '',
				prev_path varchar(255) NOT NULL DEFAULT '',
				engaged_ms int(10) unsigned NOT NULL DEFAULT 0,
				scroll tinyint(3) unsigned NOT NULL DEFAULT 0,
				is_entry tinyint(1) NOT NULL DEFAULT 0,
				is_exit tinyint(1) NOT NULL DEFAULT 1,
				status smallint(5) unsigned NOT NULL DEFAULT 200,
				PRIMARY KEY  (id),
				UNIQUE KEY pv (pv),
				KEY session_id (session_id),
				KEY created_at (created_at),
				KEY path_created (path(191),created_at),
				KEY prev_path (prev_path(191)),
				KEY post_created (post_id,created_at)
			) $opts;",
			"CREATE TABLE $e (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NOT NULL,
				pv char(16) NOT NULL DEFAULT '',
				visitor char(16) NOT NULL,
				created_at datetime NOT NULL,
				name varchar(64) NOT NULL,
				label varchar(255) NOT NULL DEFAULT '',
				meta varchar(255) NOT NULL DEFAULT '',
				path varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY name_created (name,created_at),
				KEY created_at (created_at)
			) $opts;",
			"CREATE TABLE $m (
				meta_key varchar(64) NOT NULL,
				meta_value longtext NOT NULL,
				PRIMARY KEY  (meta_key)
			) $opts;",
		);

		$global = $wpdb;
		$wpdb   = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- dbDelta() only works on the global connection; restored below.
		try {
			$suppress       = $db->suppress_errors();
			$db->last_error = '';
			dbDelta( $sql );
			$db->suppress_errors( $suppress );
			$ok = self::table_exists( $db, $s ) && self::table_exists( $db, $p ) && self::table_exists( $db, $e ) && self::table_exists( $db, $m );
		} finally {
			$wpdb = $global; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the WordPress connection.
		}
		if ( ! $ok ) {
			self::$errors[ $target ] = '' !== $db->last_error ? $db->last_error : __( 'Could not create the statistics tables.', 'nemvo-analytics' );
		}
		return $ok;
	}

	/**
	 * Approximate row counts per table, or null when unreachable.
	 *
	 * @param string|null $target Target (default: active).
	 * @param bool        $exact  COUNT(*) instead of table statistics.
	 * @return array|null
	 */
	public static function counts( $target = null, $exact = false ) {
		$target = $target ? $target : self::active();
		$db     = self::conn( $target );
		if ( ! $db ) {
			return null;
		}
		$out = array();
		foreach ( self::TABLES as $name ) {
			$table = self::table( $name, $target );
			if ( $exact ) {
				$n = $db->get_var( "SELECT COUNT(*) FROM `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			} else {
				$n = $db->get_var( $db->prepare( 'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			$out[ $name ] = null === $n ? 0 : (int) $n;
		}
		return $out;
	}

	/**
	 * Everything the settings page shows about a target.
	 *
	 * @param string|null $target Target (default: active).
	 * @return array
	 */
	public static function status( $target = null ) {
		global $wpdb;
		$target = $target ? $target : self::active();
		$cfg    = 'external' === $target ? self::external_config() : null;
		$status = array(
			'target'     => $target,
			'active'     => self::active() === $target,
			'source'     => $cfg ? $cfg['source'] : ( 'local' === $target ? 'wordpress' : '' ),
			'host'       => $cfg ? $cfg['host'] : DB_HOST,
			'name'       => $cfg ? $cfg['name'] : DB_NAME,
			'user'       => $cfg ? $cfg['user'] : DB_USER,
			'prefix'     => self::prefix( $target ),
			'configured' => 'local' === $target || null !== $cfg,
			'connected'  => false,
			'error'      => '',
			'server'     => '',
			'schema'     => self::schema_ok( $target ),
			'tables'     => array(),
			'bytes'      => 0,
			'oldest'     => null,
		);
		if ( ! $status['configured'] ) {
			return $status;
		}

		$db = self::conn( $target );
		if ( ! $db ) {
			$status['error'] = self::error( $target );
			return $status;
		}
		$status['connected'] = true;
		$status['server']    = (string) $db->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$rows = $db->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$db->prepare(
				'SELECT TABLE_NAME AS t, TABLE_ROWS AS r, DATA_LENGTH + INDEX_LENGTH AS b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s)',
				self::table( 'sessions', $target ),
				self::table( 'pageviews', $target ),
				self::table( 'events', $target )
			)
		);
		foreach ( (array) $rows as $row ) {
			$status['tables'][ $row->t ] = array(
				'rows'  => (int) $row->r,
				'bytes' => (int) $row->b,
			);
			$status['bytes']            += (int) $row->b;
		}
		if ( $status['tables'] ) {
			$status['oldest'] = $db->get_var( 'SELECT MIN(started_at) FROM `' . self::table( 'sessions', $target ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		return $status;
	}

	/**
	 * Try a set of credentials without changing anything.
	 *
	 * @param array $cfg host/name/user/password.
	 * @return array{ok:bool,message:string}
	 */
	public static function test( array $cfg ) {
		$conn = new Connection( $cfg['user'], $cfg['password'], $cfg['name'], $cfg['host'] ? $cfg['host'] : 'localhost' );
		if ( ! $conn->open() ) {
			return array(
				'ok'      => false,
				'message' => $conn->connect_error,
			);
		}
		$version = $conn->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$grants  = (array) $conn->get_col( 'SHOW GRANTS FOR CURRENT_USER()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$create  = (bool) preg_match( '/ALL PRIVILEGES|CREATE/i', implode( ' ', $grants ) );
		$conn->close();
		return array(
			'ok'      => true,
			/* translators: %s: database server version */
			'message' => sprintf( __( 'Connected to %s.', 'nemvo-analytics' ), $version ) . ( $create ? '' : ' ' . __( 'Warning: the user may lack the CREATE privilege needed to create tables.', 'nemvo-analytics' ) ),
		);
	}

	/**
	 * Delete everything older than a timestamp, in small batches to keep
	 * locks short.
	 *
	 * @param string $before Local MySQL datetime.
	 * @return int Rows deleted.
	 */
	public static function purge_before( $before ) {
		$db = self::conn();
		if ( ! $db ) {
			return 0;
		}
		$deleted = 0;
		$jobs    = array(
			self::table( 'events' )    => 'created_at',
			self::table( 'pageviews' ) => 'created_at',
			self::table( 'sessions' )  => 'last_seen',
		);
		foreach ( $jobs as $table => $column ) {
			for ( $i = 0; $i < 200; $i++ ) {
				$n        = (int) $db->query( $db->prepare( "DELETE FROM `$table` WHERE `$column` < %s LIMIT 5000", $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$deleted += $n;
				if ( $n < 5000 ) {
					break;
				}
			}
		}
		return $deleted;
	}

	/**
	 * Remove all statistics from a target (keeps the tables).
	 *
	 * @param string|null $target Target (default: active).
	 * @return bool
	 */
	public static function truncate( $target = null ) {
		$db = self::conn( $target );
		if ( ! $db ) {
			return false;
		}
		foreach ( self::TABLES as $t ) {
			$db->query( 'TRUNCATE TABLE `' . self::table( $t, $target ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		return true;
	}

	/**
	 * Drop the tables of a target (uninstall), including tables of 1.0.0-1.0.2
	 * that were never renamed.
	 *
	 * @param string $target Target.
	 */
	public static function drop( $target ) {
		$db = self::conn( $target );
		if ( ! $db ) {
			return;
		}
		$prefixes = array( self::prefix( $target ) );
		if ( self::has_legacy( $target ) ) {
			$prefixes[] = self::legacy_prefix( $target );
		}
		foreach ( $prefixes as $prefix ) {
			foreach ( self::ALL_TABLES as $t ) {
				$db->query( 'DROP TABLE IF EXISTS `' . $prefix . $t . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own tables, on uninstall.
			}
		}
	}
}
