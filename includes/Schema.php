<?php
/**
 * Activity table schema.
 *
 * @package StudentActivityForMasterStudy
 */

namespace StudentActivityForMasterStudy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {

	/** Bump when the table definition changes. */
	public const DB_VERSION = 1;

	public const DB_VERSION_OPTION = 'mssa_db_version';

	/**
	 * Fully-qualified activity table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mssa_activity';
	}

	/**
	 * Create or update the table.
	 *
	 * The uniq_daily index is load-bearing, not just a constraint: combined with
	 * INSERT IGNORE it collapses repeat views of the same lesson on the same day
	 * into one row, which both caps table growth and makes "distinct active days"
	 * a plain COUNT(DISTINCT event_date).
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one
		// field/key per line, and no backticks around the table name.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL DEFAULT 0,
			item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			item_type varchar(20) NOT NULL DEFAULT 'lesson',
			event varchar(20) NOT NULL DEFAULT 'view',
			source varchar(20) NOT NULL DEFAULT 'live',
			event_time int(10) unsigned NOT NULL,
			event_date date NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_daily (user_id,item_id,event,event_date),
			KEY user_time (user_id,event_time),
			KEY course_time (course_id,event_time)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Re-run install() when the stored db version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether the activity table actually exists.
	 *
	 * Used to fail soft in the admin rather than throwing SQL errors if
	 * activation was interrupted. Resolved at most once per request: this is
	 * called from several read paths and SHOW TABLES is not free.
	 */
	public static function table_exists(): bool {
		static $exists = null;

		if ( null !== $exists ) {
			return $exists;
		}

		global $wpdb;

		// Underscores are LIKE wildcards, and the table prefix is full of them.
		$like = $wpdb->esc_like( self::table() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return $exists;
	}

	/**
	 * Drop the table. Only ever called from uninstall.php behind an opt-in.
	 */
	public static function drop(): void {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		delete_option( self::DB_VERSION_OPTION );
	}
}
