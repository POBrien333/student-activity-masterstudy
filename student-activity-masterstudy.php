<?php
/**
 * Plugin Name:       Student Activity for MasterStudy LMS
 * Plugin URI:        https://github.com/POBrien333/student-activity-masterstudy
 * Description:       Answers "is this student actually active?" — records every lesson view, including the re-watches MasterStudy does not track, and reports last-active date, activity streaks and membership status in one admin screen.
 * Version:           0.1.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Patrick O'Brien
 * Author URI:        https://github.com/POBrien333/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       student-activity-masterstudy
 *
 * Student Activity for MasterStudy LMS is free software: you can redistribute it
 * and/or modify it under the terms of the GNU General Public License as published
 * by the Free Software Foundation, either version 2 of the License, or (at your
 * option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * This plugin is not affiliated with or endorsed by StyleMix Themes, the makers of
 * MasterStudy LMS. "MasterStudy" is their trademark and is used here only to
 * describe what this plugin is compatible with.
 *
 * @package StudentActivityForMasterStudy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MSSA_VERSION', '0.1.2' );
define( 'MSSA_FILE', __FILE__ );
define( 'MSSA_PATH', plugin_dir_path( __FILE__ ) );
define( 'MSSA_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal PSR-4-ish autoloader for the plugin namespace.
 *
 * Keeps the plugin dependency-free — no Composer, no build step.
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'StudentActivityForMasterStudy\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = MSSA_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( \StudentActivityForMasterStudy\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \StudentActivityForMasterStudy\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \StudentActivityForMasterStudy\Plugin::class, 'init' ) );
