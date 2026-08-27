<?php
/**
 * Live capture of student activity, plus the single write path shared with the backfill.
 *
 * @package StudentActivityForMasterStudy
 */

namespace StudentActivityForMasterStudy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Recorder {

	/** Timestamps before 2000-01-01 are junk (the LMS stores 0 for some rows). */
	public const MIN_TIMESTAMP = 946684800;

	public const EVENT_VIEW     = 'view';
	public const EVENT_COMPLETE = 'complete';

	/** How long the "already recorded today" marker lives. */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	private const CACHE_GROUP = 'mssa';

	/**
	 * Register the capture hooks.
	 *
	 * stm_lms_lesson_started fires from the course-player template on EVERY render
	 * of a lesson/quiz/assignment for a student with course access — which is the
	 * whole point: MasterStudy's own storage only ever records the first open and
	 * the completion, so re-watches are invisible to it.
	 *
	 * Priority 20 so MasterStudy's own handler (priority 10) runs first.
	 */
	public static function init(): void {
		add_action( 'stm_lms_lesson_started', array( __CLASS__, 'record_view' ), 20, 3 );
		add_action( 'stm_lms_lesson_passed', array( __CLASS__, 'record_completion' ), 20, 3 );
	}

	/**
	 * Student opened a course-player item.
	 *
	 * Hook signature: ( $item_id, $course_id, $user_id ).
	 */
	public static function record_view( $item_id, $course_id, $user_id ): void {
		$user_id = (int) $user_id;
		$item_id = (int) $item_id;

		if ( $user_id <= 0 || $item_id <= 0 ) {
			return;
		}

		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$now = time();
		$key = sprintf( 'seen_%d_%d_%s', $user_id, $item_id, wp_date( 'Ymd', $now ) );

		/*
		 * Short-circuit repeat views. This only spans requests when a persistent
		 * object cache is installed; without one it is request-scoped and the
		 * write below happens on each lesson view. That is still exactly one
		 * INSERT IGNORE against a narrow indexed table, which the unique key
		 * turns into a no-op after the first of the day — and it is no more than
		 * MasterStudy itself does on the same render, where
		 * stm_lms_update_user_current_lesson() runs an UPDATE every time.
		 *
		 * Checking first with a SELECT would cost a query too, so this is the
		 * minimum: at most one query per lesson view, and zero on every other
		 * page of the site.
		 */
		if ( false !== wp_cache_get( $key, self::CACHE_GROUP ) ) {
			return;
		}

		self::insert_events(
			array(
				array(
					'user_id'    => $user_id,
					'course_id'  => (int) $course_id,
					'item_id'    => $item_id,
					'item_type'  => self::item_type_for( $item_id ),
					'event'      => self::EVENT_VIEW,
					'source'     => 'live',
					'event_time' => $now,
				),
			)
		);

		wp_cache_set( $key, 1, self::CACHE_GROUP, self::CACHE_TTL );
	}

	/**
	 * Student completed a lesson.
	 *
	 * Hook signature: ( $user_id, $lesson_id, $course_id ) — note this is a
	 * different argument order to stm_lms_lesson_started.
	 */
	public static function record_completion( $user_id, $lesson_id, $course_id ): void {
		$user_id   = (int) $user_id;
		$lesson_id = (int) $lesson_id;

		if ( $user_id <= 0 || $lesson_id <= 0 ) {
			return;
		}

		self::insert_events(
			array(
				array(
					'user_id'    => $user_id,
					'course_id'  => (int) $course_id,
					'item_id'    => $lesson_id,
					'item_type'  => self::item_type_for( $lesson_id ),
					'event'      => self::EVENT_COMPLETE,
					'source'     => 'live',
					'event_time' => time(),
				),
			)
		);
	}

	/**
	 * The single write path. Multi-row INSERT IGNORE so the backfill can reuse it.
	 *
	 * @param array $rows Each: user_id, course_id, item_id, item_type, event, source, event_time.
	 *
	 * @return int Rows actually inserted (duplicates are silently skipped).
	 */
	public static function insert_events( array $rows ): int {
		global $wpdb;

		if ( empty( $rows ) ) {
			return 0;
		}

		$max_time     = time() + DAY_IN_SECONDS; // Tolerate mild clock skew, reject nonsense.
		$placeholders = array();
		$values       = array();

		foreach ( $rows as $row ) {
			$user_id    = (int) ( $row['user_id'] ?? 0 );
			$event_time = (int) ( $row['event_time'] ?? 0 );

			if ( $user_id <= 0 || $event_time < self::MIN_TIMESTAMP || $event_time > $max_time ) {
				continue;
			}

			$event_date = wp_date( 'Y-m-d', $event_time );

			if ( ! $event_date ) {
				continue;
			}

			$placeholders[] = '(%d,%d,%d,%s,%s,%s,%d,%s)';

			array_push(
				$values,
				$user_id,
				(int) ( $row['course_id'] ?? 0 ),
				(int) ( $row['item_id'] ?? 0 ),
				(string) ( $row['item_type'] ?? 'lesson' ),
				(string) ( $row['event'] ?? self::EVENT_VIEW ),
				(string) ( $row['source'] ?? 'live' ),
				$event_time,
				$event_date
			);
		}

		if ( empty( $placeholders ) ) {
			return 0;
		}

		$table = Schema::table();
		$sql   = "INSERT IGNORE INTO {$table}
			(user_id, course_id, item_id, item_type, event, source, event_time, event_date)
			VALUES " . implode( ',', $placeholders );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$inserted = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		return max( 0, (int) $inserted );
	}

	/**
	 * Map a MasterStudy post type onto our short item_type.
	 */
	public static function item_type_for( int $post_id ): string {
		return self::normalise_item_type( get_post_type( $post_id ) );
	}

	/**
	 * @param string|false $post_type
	 */
	public static function normalise_item_type( $post_type ): string {
		switch ( $post_type ) {
			case 'stm-quizzes':
				return 'quiz';
			case 'stm-assignments':
				return 'assignment';
			case 'stm-google-meets':
				return 'google_meet';
			case 'stm-lessons':
				return 'lesson';
			default:
				return 'lesson';
		}
	}
}
