<?php
/**
 * One-time import of the activity history MasterStudy already stores.
 *
 * Two passes:
 *   A. {prefix}stm_lms_user_lessons — completions (end_time) and first opens (start_time).
 *   B. usermeta stm_lms_course_started_* — lessons opened but never completed.
 *
 * Both are idempotent: every write goes through INSERT IGNORE against the
 * uniq_daily index, so re-running is harmless.
 *
 * @package StudentActivityForMasterStudy
 */

namespace StudentActivityForMasterStudy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Backfill {

	public const STATE_OPTION = 'mssa_backfill_state';

	public const CRON_HOOK = 'mssa_backfill_batch';

	private const META_PREFIX = 'stm_lms_course_started_';

	/** Source rows read per query. */
	private const BATCH_SIZE = 500;

	/** Wall-clock budget for one cron run, in seconds. */
	private const TIME_BUDGET = 15;

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	public static function default_state(): array {
		return array(
			'status'      => 'pending',
			'stage'       => 'lessons',
			'last_id'     => 0,
			'scanned'     => 0,
			'inserted'    => 0,
			'started_at'  => 0,
			'finished_at' => 0,
		);
	}

	public static function state(): array {
		$state = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return wp_parse_args( $state, self::default_state() );
	}

	private static function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Queue the backfill.
	 *
	 * @param bool $reset Start from scratch rather than resuming.
	 */
	public static function schedule( bool $reset = false ): void {
		if ( $reset ) {
			self::save_state( self::default_state() );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Process as much as fits in the time budget, then reschedule if unfinished.
	 *
	 * @return array The state after this run.
	 */
	public static function run(): array {
		$state = self::state();

		if ( 'done' === $state['status'] ) {
			return $state;
		}

		if ( ! Schema::table_exists() ) {
			Schema::install();
		}

		if ( 'running' !== $state['status'] ) {
			$state['status']     = 'running';
			$state['started_at'] = time();
		}

		$deadline = microtime( true ) + self::TIME_BUDGET;

		while ( microtime( true ) < $deadline ) {
			if ( 'lessons' === $state['stage'] ) {
				$progressed = self::pass_lessons( $state );
			} elseif ( 'usermeta' === $state['stage'] ) {
				$progressed = self::pass_usermeta( $state );
			} else {
				$progressed = false;
			}

			if ( $progressed ) {
				continue;
			}

			// Current stage is exhausted — advance.
			if ( 'lessons' === $state['stage'] ) {
				$state['stage']   = 'usermeta';
				$state['last_id'] = 0;
				continue;
			}

			$state['stage']       = 'done';
			$state['status']      = 'done';
			$state['finished_at'] = time();

			// The import just changed every aggregate.
			Cache::flush();
			break;
		}

		self::save_state( $state );

		if ( 'done' !== $state['status'] ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}

		return $state;
	}

	/**
	 * Pass A — completions and first opens from the LMS lessons table.
	 *
	 * @param array $state Passed by reference; last_id/scanned/inserted are advanced.
	 *
	 * @return bool True if rows were processed, false if the pass is exhausted.
	 */
	private static function pass_lessons( array &$state ): bool {
		global $wpdb;

		$table = self::lessons_table();

		if ( ! self::source_table_exists( $table ) ) {
			return false;
		}

		// Keyset pagination on the PK — stable even if rows are inserted mid-run.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT user_lesson_id, user_id, course_id, lesson_id, start_time, end_time
				 FROM {$table}
				 WHERE user_lesson_id > %d
				 ORDER BY user_lesson_id ASC
				 LIMIT %d",
				(int) $state['last_id'],
				self::BATCH_SIZE
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return false;
		}

		$types  = self::item_types_for( wp_list_pluck( $rows, 'lesson_id' ) );
		$events = array();

		foreach ( $rows as $row ) {
			$state['last_id'] = (int) $row['user_lesson_id'];
			++$state['scanned'];

			$user_id   = (int) $row['user_id'];
			$course_id = (int) $row['course_id'];
			$lesson_id = (int) $row['lesson_id'];
			$item_type = $types[ $lesson_id ] ?? 'lesson';

			$end_time   = (int) $row['end_time'];
			$start_time = (int) $row['start_time'];

			if ( $end_time >= Recorder::MIN_TIMESTAMP ) {
				$events[] = array(
					'user_id'    => $user_id,
					'course_id'  => $course_id,
					'item_id'    => $lesson_id,
					'item_type'  => $item_type,
					'event'      => Recorder::EVENT_COMPLETE,
					'source'     => 'backfill_completion',
					'event_time' => $end_time,
				);
			}

			// start_time is 0 on some historical rows — insert_events() filters those.
			if ( $start_time >= Recorder::MIN_TIMESTAMP ) {
				$events[] = array(
					'user_id'    => $user_id,
					'course_id'  => $course_id,
					'item_id'    => $lesson_id,
					'item_type'  => $item_type,
					'event'      => Recorder::EVENT_VIEW,
					'source'     => 'backfill_completion',
					'event_time' => $start_time,
				);
			}
		}

		$state['inserted'] += Recorder::insert_events( $events );

		return true;
	}

	/**
	 * Pass B — lessons opened but never completed, from usermeta.
	 *
	 * @param array $state Passed by reference.
	 *
	 * @return bool True if rows were processed, false if the pass is exhausted.
	 */
	private static function pass_usermeta( array &$state ): bool {
		global $wpdb;

		// The underscores in the prefix are LIKE wildcards — they must be escaped,
		// or this matches far more keys than intended.
		$like = $wpdb->esc_like( self::META_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id, user_id, meta_key, meta_value
				 FROM {$wpdb->usermeta}
				 WHERE meta_key LIKE %s AND umeta_id > %d
				 ORDER BY umeta_id ASC
				 LIMIT %d",
				$like,
				(int) $state['last_id'],
				self::BATCH_SIZE
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return false;
		}

		$course_ids = self::course_id_set();
		$parsed     = array();

		foreach ( $rows as $row ) {
			$state['last_id'] = (int) $row['umeta_id'];
			++$state['scanned'];

			$event_time = (int) $row['meta_value'];

			if ( $event_time < Recorder::MIN_TIMESTAMP ) {
				continue;
			}

			$ids = self::parse_started_key( $row['meta_key'], $course_ids );

			$parsed[] = array(
				'user_id'    => (int) $row['user_id'],
				'course_id'  => $ids['course_id'],
				'item_id'    => $ids['item_id'],
				'event_time' => $event_time,
			);
		}

		$types  = self::item_types_for( wp_list_pluck( $parsed, 'item_id' ) );
		$events = array();

		foreach ( $parsed as $row ) {
			$events[] = array(
				'user_id'    => $row['user_id'],
				'course_id'  => $row['course_id'],
				'item_id'    => $row['item_id'],
				'item_type'  => $types[ $row['item_id'] ] ?? 'lesson',
				'event'      => Recorder::EVENT_VIEW,
				'source'     => 'backfill_open',
				'event_time' => $row['event_time'],
			);
		}

		$state['inserted'] += Recorder::insert_events( $events );

		return true;
	}

	/**
	 * Work out which ID is the lesson and which is the course.
	 *
	 * Two key formats exist in the wild. MasterStudy migrates the old
	 * ..._{course}_{lesson} form to ..._{lesson}_{course} lazily, so both are
	 * present, and some keys are malformed (a trailing underscore with nothing
	 * after it). When neither ID resolves to a course we still keep the event
	 * with course_id 0 — the timestamp is what the report needs; the course
	 * attribution is a bonus.
	 *
	 * @param array<int,bool> $course_ids Lookup set keyed by course post ID.
	 *
	 * @return array{course_id:int,item_id:int}
	 */
	public static function parse_started_key( string $meta_key, array $course_ids ): array {
		$rest  = substr( $meta_key, strlen( self::META_PREFIX ) );
		$parts = explode( '_', (string) $rest );

		$first  = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$second = isset( $parts[1] ) ? (int) $parts[1] : 0;

		// Current format: {lesson}_{course}.
		if ( $second > 0 && isset( $course_ids[ $second ] ) ) {
			return array(
				'course_id' => $second,
				'item_id'   => $first,
			);
		}

		// Legacy format: {course}_{lesson}.
		if ( $first > 0 && isset( $course_ids[ $first ] ) ) {
			return array(
				'course_id' => $first,
				'item_id'   => $second,
			);
		}

		// Unresolvable (deleted course, or a malformed key like "..._6976_").
		return array(
			'course_id' => 0,
			'item_id'   => $first > 0 ? $first : $second,
		);
	}

	/**
	 * All course post IDs, as a lookup set. Small (tens of rows) and cached.
	 *
	 * @return array<int,bool>
	 */
	public static function course_id_set(): array {
		static $set = null;

		if ( null !== $set ) {
			return $set;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'stm-courses' )
		);

		$set = array();

		foreach ( $ids as $id ) {
			$set[ (int) $id ] = true;
		}

		return $set;
	}

	/**
	 * Resolve post types for a batch of item IDs in one query.
	 *
	 * @param array $item_ids
	 *
	 * @return array<int,string> Keyed by post ID.
	 */
	private static function item_types_for( array $item_ids ): array {
		global $wpdb;

		$item_ids = array_filter( array_unique( array_map( 'intval', $item_ids ) ) );

		if ( empty( $item_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
				$item_ids
			),
			ARRAY_A
		);

		$types = array();

		foreach ( $rows as $row ) {
			$types[ (int) $row['ID'] ] = Recorder::normalise_item_type( $row['post_type'] );
		}

		return $types;
	}

	/**
	 * MasterStudy's user-lessons table, without depending on its helper being loaded.
	 */
	private static function lessons_table(): string {
		global $wpdb;

		if ( function_exists( 'stm_lms_user_lessons_name' ) ) {
			return stm_lms_user_lessons_name( $wpdb );
		}

		return $wpdb->prefix . 'stm_lms_user_lessons';
	}

	private static function source_table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
