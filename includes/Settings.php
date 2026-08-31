<?php
/**
 * Plugin settings (thresholds and behaviour flags).
 *
 * @package StudentActivityForMasterStudy
 */

namespace StudentActivityForMasterStudy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public const OPTION = 'mssa_settings';

	/**
	 * Defaults. active_days / slipping_days are the status thresholds in days.
	 */
	public static function defaults(): array {
		return array(
			'active_days'         => 30,
			'slipping_days'       => 90,
			'count_views'         => 1,
			'delete_on_uninstall' => 0,
			'mailchimp_enabled'   => 0,
			'mailchimp_api_key'   => '',
			'mailchimp_list_id'   => '',
			'mailchimp_tag'       => 'inactive-learner',
		);
	}

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/**
	 * Resolved settings.
	 *
	 * Cached per request: status_for() consults these for every student row, so
	 * on a full list this would otherwise re-run get_option() and wp_parse_args()
	 * hundreds of times.
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = wp_parse_args( $stored, self::defaults() );

		return self::$cache;
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Sanitise and persist a settings payload.
	 */
	public static function update( array $input ): array {
		$current = self::all();

		$active   = isset( $input['active_days'] ) ? absint( $input['active_days'] ) : $current['active_days'];
		$slipping = isset( $input['slipping_days'] ) ? absint( $input['slipping_days'] ) : $current['slipping_days'];

		// Guard against nonsense that would make the status buckets overlap.
		$active   = max( 1, min( 3650, $active ) );
		$slipping = max( $active + 1, min( 3650, $slipping ) );

		$clean = array(
			'active_days'         => $active,
			'slipping_days'       => $slipping,
			'count_views'         => empty( $input['count_views'] ) ? 0 : 1,
			'delete_on_uninstall' => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
			'mailchimp_enabled'   => empty( $input['mailchimp_enabled'] ) ? 0 : 1,
			'mailchimp_api_key'   => isset( $input['mailchimp_api_key'] )
				? trim( (string) $input['mailchimp_api_key'] )
				: (string) $current['mailchimp_api_key'],
			'mailchimp_list_id'   => isset( $input['mailchimp_list_id'] )
				? sanitize_text_field( (string) $input['mailchimp_list_id'] )
				: (string) $current['mailchimp_list_id'],
			'mailchimp_tag'       => isset( $input['mailchimp_tag'] ) && '' !== trim( (string) $input['mailchimp_tag'] )
				? sanitize_text_field( trim( (string) $input['mailchimp_tag'] ) )
				: (string) $current['mailchimp_tag'],
		);

		update_option( self::OPTION, $clean, false );

		self::$cache = $clean;

		return $clean;
	}
}
