<?php
/**
 * Plugin bootstrap: lifecycle hooks and wiring.
 *
 * @package StudentActivityForMasterStudy
 */

namespace StudentActivityForMasterStudy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/**
	 * Runs on activation: create the table and queue the historical backfill.
	 */
	public static function activate(): void {
		Schema::install();
		Backfill::schedule( true );
	}

	/**
	 * Runs on deactivation. Deliberately destructive of nothing — the activity
	 * table survives so that deactivating for a moment does not lose history.
	 */
	public static function deactivate(): void {
		Backfill::unschedule();
		Mailchimp::unschedule();
	}

	/**
	 * Wire everything up. Called on plugins_loaded.
	 */
	public static function init(): void {
		/*
		 * Front-end cost of this plugin must stay at zero queries. The schema
		 * check reads a non-autoloaded option, so running it on every request
		 * would add one query to every page view for every visitor — admin_init
		 * is soon enough for a table that activation already created.
		 */
		add_action( 'admin_init', array( Schema::class, 'maybe_upgrade' ) );

		// Two add_action calls; the hooks only ever fire inside the course player.
		Recorder::init();
		Backfill::init();
		Mailchimp::init();

		if ( is_admin() ) {
			AdminPage::init();
		}
	}
}
