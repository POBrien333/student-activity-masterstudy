<?php
/**
 * Lazy, on-demand caching for the report aggregates.
 *
 * Deliberately NOT a scheduled recompute. The report only costs anything when
 * somebody opens the page, so a daily cron would run the aggregate 365 times a
 * year to serve a screen that gets opened a handful of times — strictly more
 * work, not less. Caching on read instead means the first view in a window pays
 * for it and every sort, filter and page-turn after that is free.
 *
 * @package StudentActivityForMasterStudy
 */

namespace StudentActivityForMasterStudy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cache {

	/** Transient name prefix. Also used to find them all again for flushing. */
	private const PREFIX = 'mssa_c_';

	/** How long a computed aggregate stays usable. */
	public const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Return a cached value, computing and storing it on a miss.
	 *
	 * @param string   $kind     Short label for the query family.
	 * @param array    $args     Everything that changes the result.
	 * @param callable $callback Produces the value on a miss.
	 *
	 * @return array{data:mixed,generated_at:int}
	 */
	public static function remember( string $kind, array $args, callable $callback ): array {
		$key    = self::key( $kind, $args );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && array_key_exists( 'data', $cached ) ) {
			return $cached;
		}

		$payload = array(
			'data'         => $callback(),
			'generated_at' => time(),
		);

		set_transient( $key, $payload, self::TTL );

		self::remember_key( $key );

		return $payload;
	}

	/**
	 * Drop every cached aggregate.
	 *
	 * Called from the Refresh button and after the backfill writes new rows.
	 */
	public static function flush(): void {
		$keys = get_option( self::PREFIX . 'keys', array() );

		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				delete_transient( $key );
			}
		}

		delete_option( self::PREFIX . 'keys' );
	}

	/**
	 * Track which transients we created.
	 *
	 * Without a persistent object cache transients live in the options table, so
	 * this could be a LIKE query instead — but keeping an explicit list means
	 * flushing never scans options, and it works identically when APCu or Redis
	 * is handling transients (where a LIKE query would find nothing at all).
	 */
	private static function remember_key( string $key ): void {
		$option = self::PREFIX . 'keys';
		$keys   = get_option( $option, array() );

		if ( ! is_array( $keys ) ) {
			$keys = array();
		}

		if ( in_array( $key, $keys, true ) ) {
			return;
		}

		$keys[] = $key;

		// Bound the list so an unusual run of filter combinations cannot grow it
		// without limit; the oldest entries simply expire on their own TTL.
		if ( count( $keys ) > 60 ) {
			$keys = array_slice( $keys, -60 );
		}

		update_option( $option, $keys, false );
	}

	private static function key( string $kind, array $args ): string {
		ksort( $args );

		// Settings change what the numbers mean, so they belong in the key.
		$args['__settings'] = Settings::all();

		return self::PREFIX . $kind . '_' . md5( (string) wp_json_encode( $args ) );
	}
}
