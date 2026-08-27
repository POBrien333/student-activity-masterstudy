<?php
/**
 * Uninstall handler.
 *
 * Only destroys data when the site owner explicitly opted in. Deactivating or
 * deleting the plugin otherwise leaves the activity table alone — view history
 * cannot be reconstructed once it is gone.
 *
 * @package StudentActivityForMasterStudy
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$mssa_settings = get_option( 'mssa_settings', array() );

if ( ! is_array( $mssa_settings ) || empty( $mssa_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$mssa_table = $wpdb->prefix . 'mssa_activity';

/*
 * Dropping our own table is the whole point of this file, and it only runs when
 * the site owner ticked the opt-in checked above. The name is built from
 * $wpdb->prefix and cannot be a prepare() placeholder.
 */
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
$wpdb->query( "DROP TABLE IF EXISTS {$mssa_table}" );

// Cached report aggregates. The plugin tracks the transient names it created, so
// clean those up before dropping the list itself.
$mssa_cache_keys = get_option( 'mssa_c_keys', array() );

if ( is_array( $mssa_cache_keys ) ) {
	foreach ( $mssa_cache_keys as $mssa_cache_key ) {
		delete_transient( $mssa_cache_key );
	}
}

delete_option( 'mssa_c_keys' );
delete_option( 'mssa_settings' );
delete_option( 'mssa_backfill_state' );
delete_option( 'mssa_db_version' );

wp_clear_scheduled_hook( 'mssa_backfill_batch' );
