<?php
/**
 * All read queries for the report.
 *
 * @package StudentActivityForMasterStudy
 */

namespace StudentActivityForMasterStudy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Query {

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_SLIPPING = 'slipping';
	public const STATUS_DORMANT  = 'dormant';
	public const STATUS_NEVER    = 'never';

	/**
	 * Human labels for each status, using the configured thresholds.
	 */
	public static function status_labels(): array {
		$active   = (int) Settings::get( 'active_days' );
		$slipping = (int) Settings::get( 'slipping_days' );

		return array(
			self::STATUS_ACTIVE   => sprintf(
				/* translators: %d: number of days */
				__( 'Active (last %d days)', 'student-activity-masterstudy' ),
				$active
			),
			self::STATUS_SLIPPING => sprintf(
				/* translators: 1: start of range in days, 2: end of range in days */
				__( 'Slipping (%1$d–%2$d days)', 'student-activity-masterstudy' ),
				$active + 1,
				$slipping
			),
			self::STATUS_DORMANT  => sprintf(
				/* translators: %d: number of days */
				__( 'Dormant (over %d days)', 'student-activity-masterstudy' ),
				$slipping
			),
			self::STATUS_NEVER    => __( 'Never started', 'student-activity-masterstudy' ),
		);
	}

	/**
	 * Derive a status from a last-activity timestamp.
	 *
	 * Deliberately computed rather than stored, so changing the thresholds
	 * reclassifies everybody instantly with no re-processing.
	 *
	 * @param int|null $last_activity Unix timestamp, or null/0 for no activity.
	 */
	public static function status_for( ?int $last_activity ): string {
		if ( empty( $last_activity ) ) {
			return self::STATUS_NEVER;
		}

		$age_days = ( time() - $last_activity ) / DAY_IN_SECONDS;

		if ( $age_days <= (int) Settings::get( 'active_days' ) ) {
			return self::STATUS_ACTIVE;
		}

		if ( $age_days <= (int) Settings::get( 'slipping_days' ) ) {
			return self::STATUS_SLIPPING;
		}

		return self::STATUS_DORMANT;
	}

	public static function default_args(): array {
		return array(
			'search'     => '',
			'status'     => '',
			'membership' => '',
			'course_id'  => 0,
			'orderby'    => 'last_activity',
			'order'      => 'ASC',
			'paged'      => 1,
			'per_page'   => 50,
			'with_total' => true,
		);
	}

	/**
	 * The student list.
	 *
	 * @return array{items:array,total:int}
	 */
	public static function get_students( array $args = array() ): array {
		$args = wp_parse_args( $args, self::default_args() );

		if ( ! Schema::table_exists() ) {
			return array(
				'items'        => array(),
				'total'        => 0,
				'generated_at' => time(),
			);
		}

		$payload = Cache::remember(
			'students',
			$args,
			static function () use ( $args ) {
				return self::query_students( $args );
			}
		);

		return array_merge( $payload['data'], array( 'generated_at' => $payload['generated_at'] ) );
	}

	/**
	 * @return array{items:array,total:int}
	 */
	private static function query_students( array $args ): array {
		global $wpdb;

		$base = self::base_query( $args );

		$where = self::outer_where( $args );

		$order_by = self::order_clause( $args );

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['paged'] - 1 ) * $per_page );

		$total = 0;

		/*
		 * The query text is assembled from base_query() and outer_where(), which
		 * emit only fixed SQL plus %d/%s placeholders — every user-supplied value
		 * travels in the params array to prepare(), and ORDER BY comes from a
		 * whitelist in order_clause(). Static analysis cannot see through the
		 * assembly, so the sniffs are disabled across the whole statement rather
		 * than one line of it.
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// The list screen already knows the total from the status-count query, so
		// it opts out here rather than paying for a third scan of the same data.
		if ( ! empty( $args['with_total'] ) ) {
			$count_sql = "SELECT COUNT(*) FROM ({$base['sql']}) s {$where['sql']}";

			$total = (int) $wpdb->get_var(
				$wpdb->prepare( $count_sql, array_merge( $base['params'], $where['params'] ) )
			);
		}

		$list_sql = "SELECT * FROM ({$base['sql']}) s {$where['sql']} {$order_by} LIMIT %d OFFSET %d";

		$items = $wpdb->get_results(
			$wpdb->prepare(
				$list_sql,
				array_merge( $base['params'], $where['params'], array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $items as &$item ) {
			$item = self::decorate( $item );
		}
		unset( $item );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Counts per status for the current filter, ignoring the status filter itself
	 * so the summary tiles always show the full breakdown.
	 *
	 * @return array<string,int>
	 */
	public static function get_status_counts( array $args = array() ): array {
		$empty = array(
			self::STATUS_ACTIVE   => 0,
			self::STATUS_SLIPPING => 0,
			self::STATUS_DORMANT  => 0,
			self::STATUS_NEVER    => 0,
		);

		if ( ! Schema::table_exists() ) {
			return $empty;
		}

		$args           = wp_parse_args( $args, self::default_args() );
		$args['status'] = '';

		// Paging and sorting do not affect the counts; keeping them out of the
		// cache key means every page of a filtered list reuses one entry.
		unset( $args['paged'], $args['orderby'], $args['order'], $args['with_total'] );

		$payload = Cache::remember(
			'counts',
			$args,
			static function () use ( $args, $empty ) {
				return self::query_status_counts( $args, $empty );
			}
		);

		return $payload['data'];
	}

	/**
	 * @param array $counts Zeroed bucket template.
	 *
	 * @return array<string,int>
	 */
	private static function query_status_counts( array $args, array $counts ): array {
		global $wpdb;

		$base  = self::base_query( $args );
		$where = self::outer_where( $args );

		$now      = time();
		$active   = $now - ( (int) Settings::get( 'active_days' ) * DAY_IN_SECONDS );
		$slipping = $now - ( (int) Settings::get( 'slipping_days' ) * DAY_IN_SECONDS );

		$sql = "SELECT
				SUM(CASE WHEN s.last_activity IS NULL THEN 1 ELSE 0 END) AS never_started,
				SUM(CASE WHEN s.last_activity >= %d THEN 1 ELSE 0 END) AS is_active,
				SUM(CASE WHEN s.last_activity < %d AND s.last_activity >= %d THEN 1 ELSE 0 END) AS is_slipping,
				SUM(CASE WHEN s.last_activity < %d THEN 1 ELSE 0 END) AS is_dormant
			FROM ({$base['sql']}) s {$where['sql']}";

		// Placeholder order follows the SQL text: the four threshold comparisons in
		// the SELECT list come before the inner query's params and the WHERE's.
		$params = array_merge(
			array( $active, $active, $slipping, $slipping ),
			$base['params'],
			$where['params']
		);

		// Same assembled-SQL situation as get_students(); every value is a placeholder.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );

		if ( ! $row ) {
			return $counts;
		}

		return array(
			self::STATUS_ACTIVE   => (int) $row['is_active'],
			self::STATUS_SLIPPING => (int) $row['is_slipping'],
			self::STATUS_DORMANT  => (int) $row['is_dormant'],
			self::STATUS_NEVER    => (int) $row['never_started'],
		);
	}

	/**
	 * Everything the per-student drill-down needs.
	 */
	public static function get_student_detail( int $user_id, int $events_page = 1, int $events_per_page = 200 ): array {
		global $wpdb;

		$table = Schema::table();

		$summary = self::get_students(
			array(
				'per_page' => 1,
				'user_id'  => $user_id,
			)
		);

		$detail = array(
			'summary' => $summary['items'][0] ?? null,
			'courses' => array(),
			'events'  => array(),
			'total'   => 0,
		);

		if ( ! Schema::table_exists() ) {
			return $detail;
		}

		/*
		 * Every query below reads only this plugin's own table, whose name comes
		 * from $wpdb->prefix via Schema::table(). A table name cannot be passed as
		 * a prepare() placeholder, so it has to be interpolated; $user_id and the
		 * paging values are placeholders as normal.
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$detail['courses'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT course_id,
					MAX(event_time) AS last_activity,
					MIN(event_time) AS first_activity,
					COUNT(DISTINCT event_date) AS active_days,
					COUNT(DISTINCT CASE WHEN event = 'complete' THEN item_id END) AS items_completed,
					COUNT(DISTINCT item_id) AS items_touched
				FROM {$table}
				WHERE user_id = %d
				GROUP BY course_id
				ORDER BY last_activity DESC",
				$user_id
			),
			ARRAY_A
		);

		$per_page = max( 1, $events_per_page );
		$offset   = max( 0, ( $events_page - 1 ) * $per_page );

		$detail['total'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
				$user_id
			)
		);

		$detail['events'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_date, event_time, event, item_id, item_type, course_id, source
				FROM {$table}
				WHERE user_id = %d
				ORDER BY event_time DESC
				LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $detail;
	}

	/**
	 * The inner query: one row per student, with all aggregates attached.
	 *
	 * @return array{sql:string,params:array}
	 */
	private static function base_query( array $args ): array {
		global $wpdb;

		$activity  = Schema::table();
		$courses   = $wpdb->prefix . 'stm_lms_user_courses';
		$pmpro     = $wpdb->prefix . 'pmpro_memberships_users';
		$course_id = (int) ( $args['course_id'] ?? 0 );

		$now         = time();
		$short_since = $now - ( (int) Settings::get( 'active_days' ) * DAY_IN_SECONDS );
		$long_since  = $now - ( (int) Settings::get( 'slipping_days' ) * DAY_IN_SECONDS );

		$params = array();

		// When views are not counted as activity, exclude them from every
		// aggregate so last_activity and the day counts stay consistent.
		$event_filter = Settings::get( 'count_views' ) ? '' : " AND event = 'complete'";

		$activity_where = 'WHERE 1=1' . $event_filter;

		if ( $course_id > 0 ) {
			$activity_where .= ' AND course_id = %d';
		}

		/*
		 * prepare() fills placeholders in the order they appear in the SQL TEXT,
		 * not the order the clauses were assembled in PHP. The three window
		 * comparisons live in the SELECT list, which precedes the WHERE clause,
		 * so they must be pushed first — even though $activity_where was built
		 * above. Getting this backwards silently substitutes a course ID into a
		 * timestamp comparison and a timestamp into the course filter, which
		 * matches no rows at all.
		 */
		$params[] = $short_since;
		$params[] = $short_since;
		$params[] = $long_since;

		if ( $course_id > 0 ) {
			$params[] = $course_id;
		}

		$activity_sub = "SELECT user_id,
				MAX(event_time) AS last_activity,
				MIN(event_time) AS first_activity,
				SUM(CASE WHEN event = 'complete' THEN 1 ELSE 0 END) AS completions_total,
				SUM(CASE WHEN event = 'complete' AND event_time >= %d THEN 1 ELSE 0 END) AS completions_recent,
				COUNT(DISTINCT CASE WHEN event_time >= %d THEN event_date END) AS active_days_short,
				COUNT(DISTINCT CASE WHEN event_time >= %d THEN event_date END) AS active_days_long,
				COUNT(DISTINCT NULLIF(course_id, 0)) AS courses_touched
			FROM {$activity}
			{$activity_where}
			GROUP BY user_id";

		$enrol_where = '';

		if ( $course_id > 0 ) {
			$enrol_where = 'WHERE course_id = %d';
			$params[]    = $course_id;
		}

		$enrol_sub = "SELECT user_id, COUNT(*) AS enrolled, MIN(start_time) AS first_enrolled
			FROM {$courses}
			{$enrol_where}
			GROUP BY user_id";

		$pmpro_sub = "SELECT user_id, MAX(membership_id) AS membership_id, MAX(enddate) AS membership_enddate
			FROM {$pmpro}
			WHERE status = 'active'
			GROUP BY user_id";

		$user_filter = '';

		if ( ! empty( $args['user_id'] ) ) {
			$user_filter = ' AND u.ID = %d';
			$params[]    = (int) $args['user_id'];
		}

		$search_filter = '';

		if ( '' !== (string) $args['search'] ) {
			$like          = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$search_filter = ' AND (u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)';
			array_push( $params, $like, $like, $like );
		}

		$sql = "SELECT
				u.ID AS user_id,
				u.display_name,
				u.user_email,
				u.user_login,
				u.user_registered,
				a.last_activity,
				a.first_activity,
				COALESCE(a.completions_total, 0) AS completions_total,
				COALESCE(a.completions_recent, 0) AS completions_recent,
				COALESCE(a.active_days_short, 0) AS active_days_short,
				COALESCE(a.active_days_long, 0) AS active_days_long,
				COALESCE(a.courses_touched, 0) AS courses_touched,
				COALESCE(e.enrolled, 0) AS enrolled,
				e.first_enrolled,
				m.membership_id,
				m.membership_enddate
			FROM {$wpdb->users} u
			LEFT JOIN ({$activity_sub}) a ON a.user_id = u.ID
			LEFT JOIN ({$enrol_sub}) e ON e.user_id = u.ID
			LEFT JOIN ({$pmpro_sub}) m ON m.user_id = u.ID
			WHERE (e.enrolled > 0 OR m.user_id IS NOT NULL){$user_filter}{$search_filter}";

		return array(
			'sql'    => $sql,
			'params' => $params,
		);
	}

	/**
	 * Status / membership filters applied to the wrapped inner query.
	 *
	 * @return array{sql:string,params:array}
	 */
	private static function outer_where( array $args ): array {
		$clauses = array();
		$params  = array();

		$now      = time();
		$active   = $now - ( (int) Settings::get( 'active_days' ) * DAY_IN_SECONDS );
		$slipping = $now - ( (int) Settings::get( 'slipping_days' ) * DAY_IN_SECONDS );

		switch ( $args['status'] ) {
			case self::STATUS_ACTIVE:
				$clauses[] = 's.last_activity >= %d';
				$params[]  = $active;
				break;
			case self::STATUS_SLIPPING:
				$clauses[] = 's.last_activity < %d AND s.last_activity >= %d';
				$params[]  = $active;
				$params[]  = $slipping;
				break;
			case self::STATUS_DORMANT:
				$clauses[] = 's.last_activity < %d';
				$params[]  = $slipping;
				break;
			case self::STATUS_NEVER:
				$clauses[] = 's.last_activity IS NULL';
				break;
		}

		if ( 'active' === $args['membership'] ) {
			$clauses[] = 's.membership_id IS NOT NULL';
		} elseif ( 'none' === $args['membership'] ) {
			$clauses[] = 's.membership_id IS NULL';
		}

		return array(
			'sql'    => $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '',
			'params' => $params,
		);
	}

	/**
	 * Whitelisted ORDER BY. Never interpolates user input.
	 */
	private static function order_clause( array $args ): string {
		$allowed = array(
			'name'               => 's.display_name',
			'email'              => 's.user_email',
			'last_activity'      => 's.last_activity',
			'active_days_short'  => 's.active_days_short',
			'active_days_long'   => 's.active_days_long',
			'completions_total'  => 's.completions_total',
			'completions_recent' => 's.completions_recent',
			'enrolled'           => 's.enrolled',
			'registered'         => 's.user_registered',
		);

		$column = $allowed[ $args['orderby'] ] ?? 's.last_activity';
		$order  = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';

		// Ascending on last_activity puts NULLs (never started) first, which is
		// the intent: the people who need attention surface at the top.
		return "ORDER BY {$column} {$order}, s.display_name ASC";
	}

	/**
	 * Add derived presentation fields to a row.
	 */
	private static function decorate( array $row ): array {
		$last = isset( $row['last_activity'] ) ? (int) $row['last_activity'] : 0;

		$row['user_id']        = (int) $row['user_id'];
		$row['last_activity']  = $last ? $last : null;
		$row['status']         = self::status_for( $row['last_activity'] );
		$row['days_since']     = $last ? (int) floor( ( time() - $last ) / DAY_IN_SECONDS ) : null;
		$row['has_membership'] = ! empty( $row['membership_id'] );

		return $row;
	}
}
