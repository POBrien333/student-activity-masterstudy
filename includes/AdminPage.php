<?php
/**
 * Admin screen: student list, per-student drill-down, CSV export, settings.
 *
 * @package StudentActivityForMasterStudy
 */

namespace StudentActivityForMasterStudy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminPage {

	public const SLUG = 'student-activity-masterstudy';

	public const CAPABILITY = 'manage_options';

	/** MasterStudy's top-level menu slug. */
	private const MS_PARENT = 'stm-lms-settings';

	public static function init(): void {
		/*
		 * Registered late on purpose. MasterStudy rebuilds its whole submenu in
		 * masterstudy_lms_sort_admin_submenu_pages() at priority 100005, so
		 * anything added earlier — including via its own
		 * masterstudy_lms_admin_submenu_items filter — is at the mercy of that
		 * rebuild. Adding the page afterwards makes it deterministic, and we
		 * reposition it ourselves.
		 */
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 100010 );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_action( 'admin_post_mssa_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_mssa_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_mssa_backfill', array( __CLASS__, 'handle_backfill' ) );
		add_action( 'admin_post_mssa_refresh', array( __CLASS__, 'handle_refresh' ) );
	}

	/**
	 * Add the page under MasterStudy's menu, or stand alone if it is not there.
	 */
	public static function register_menu(): void {
		global $submenu, $admin_page_hooks;

		$has_ms_menu = isset( $admin_page_hooks[ self::MS_PARENT ] )
			|| ( ! empty( $submenu[ self::MS_PARENT ] ) && is_array( $submenu[ self::MS_PARENT ] ) );

		if ( ! $has_ms_menu ) {
			add_menu_page(
				__( 'Student Activity', 'student-activity-masterstudy' ),
				__( 'Student Activity', 'student-activity-masterstudy' ),
				self::CAPABILITY,
				self::SLUG,
				array( __CLASS__, 'render' ),
				'dashicons-chart-line',
				58
			);

			return;
		}

		/*
		 * Plain text on purpose. MasterStudy's "⤷ …" items wrap their title in
		 * .stm-lms-contextual-submenu-title, which its own admin CSS hides
		 * outright:
		 *
		 *   li#toplevel_page_stm-lms-settings .wp-submenu
		 *     li:has(.stm-lms-contextual-submenu-title) { display: none }
		 *
		 * Those items are revealed only when lms_sub_menu.js adds
		 * .stm-lms-contextual-submenu-visible, and it does so from a hardcoded
		 * list of its own slugs. Borrowing that markup makes the item
		 * permanently invisible.
		 */
		add_submenu_page(
			self::MS_PARENT,
			__( 'Student Activity', 'student-activity-masterstudy' ),
			__( 'Student Activity', 'student-activity-masterstudy' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);

		self::position_after_students();
	}

	/**
	 * Move our freshly-appended entry to sit directly below "Students".
	 *
	 * Purely cosmetic — if the anchor is missing the entry simply stays at the
	 * end of the menu rather than disappearing.
	 */
	private static function position_after_students(): void {
		global $submenu;

		if ( empty( $submenu[ self::MS_PARENT ] ) || ! is_array( $submenu[ self::MS_PARENT ] ) ) {
			return;
		}

		$ours = null;
		$rest = array();

		foreach ( array_values( $submenu[ self::MS_PARENT ] ) as $item ) {
			if ( self::SLUG === ( $item[2] ?? '' ) ) {
				$ours = $item;
				continue;
			}

			$rest[] = $item;
		}

		if ( null === $ours ) {
			return;
		}

		$anchor = null;

		foreach ( $rest as $index => $item ) {
			if ( 'manage_students' === ltrim( (string) ( $item[2] ?? '' ), '/' ) ) {
				$anchor = $index;
				break;
			}
		}

		if ( null === $anchor ) {
			$rest[] = $ours;
		} else {
			array_splice( $rest, $anchor + 1, 0, array( $ours ) );
		}

		/*
		 * Writing to $submenu is the only way to reposition a submenu entry;
		 * WordPress exposes no API for it. Confined to this plugin's parent menu.
		 */
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$submenu[ self::MS_PARENT ] = $rest;
	}

	public static function enqueue( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'student-activity-masterstudy',
			MSSA_URL . 'assets/admin.css',
			array(),
			MSSA_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'student-activity-masterstudy' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$student_id = isset( $_GET['student_id'] ) ? absint( $_GET['student_id'] ) : 0;

		echo '<div class="wrap mssa">';

		if ( $student_id > 0 ) {
			self::render_detail( $student_id );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * Read and sanitise the filter args from the query string.
	 */
	public static function current_args(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$args = array(
			'search'     => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'status'     => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'membership' => isset( $_GET['membership'] ) ? sanitize_key( wp_unslash( $_GET['membership'] ) ) : '',
			'course_id'  => isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0,
			'orderby'    => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'last_activity',
			'order'      => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'ASC',
			'paged'      => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
			'per_page'   => isset( $_GET['per_page'] ) ? min( 500, max( 10, absint( $_GET['per_page'] ) ) ) : 50,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$valid_status = array_keys( Query::status_labels() );

		if ( ! in_array( $args['status'], $valid_status, true ) ) {
			$args['status'] = '';
		}

		if ( ! in_array( $args['membership'], array( 'active', 'none' ), true ) ) {
			$args['membership'] = '';
		}

		return $args;
	}

	private static function render_list(): void {
		$args  = self::current_args();
		$table = new ListTable( $args );
		$table->prepare_items();

		$counts = $table->status_counts();
		$labels = Query::status_labels();

		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Student Activity', 'student-activity-masterstudy' ); ?></h1>
		<a href="<?php echo esc_url( self::export_url( $args ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Export CSV', 'student-activity-masterstudy' ); ?>
		</a>
		<hr class="wp-header-end">

		<?php self::render_backfill_notice(); ?>

		<p class="mssa-freshness">
			<?php
			$age = time() - $table->generated_at();

			if ( $age < 60 ) {
				esc_html_e( 'Figures computed just now.', 'student-activity-masterstudy' );
			} else {
				printf(
					/* translators: %s: how long ago, e.g. "2 hours" */
					esc_html__( 'Figures computed %s ago.', 'student-activity-masterstudy' ),
					esc_html( human_time_diff( $table->generated_at() ) )
				);
			}
			?>
			<span class="mssa-muted">
				<?php esc_html_e( 'Cached so repeat sorting and filtering costs nothing.', 'student-activity-masterstudy' ); ?>
			</span>
			<?php self::render_refresh_button(); ?>
		</p>

		<div class="mssa-tiles">
			<?php foreach ( $labels as $key => $label ) : ?>
				<a class="mssa-tile mssa-tile--<?php echo esc_attr( $key ); ?> <?php echo $args['status'] === $key ? 'is-current' : ''; ?>"
					href="<?php echo esc_url( self::list_url( array( 'status' => $args['status'] === $key ? '' : $key ) ) ); ?>">
					<span class="mssa-tile__count"><?php echo esc_html( number_format_i18n( $counts[ $key ] ?? 0 ) ); ?></span>
					<span class="mssa-tile__label"><?php echo esc_html( $label ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<form method="get" class="mssa-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">

			<select name="status">
				<option value=""><?php esc_html_e( 'Any status', 'student-activity-masterstudy' ); ?></option>
				<?php foreach ( $labels as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['status'], $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="membership">
				<option value=""><?php esc_html_e( 'Any membership', 'student-activity-masterstudy' ); ?></option>
				<option value="active" <?php selected( $args['membership'], 'active' ); ?>>
					<?php esc_html_e( 'Paying members only', 'student-activity-masterstudy' ); ?>
				</option>
				<option value="none" <?php selected( $args['membership'], 'none' ); ?>>
					<?php esc_html_e( 'No active membership', 'student-activity-masterstudy' ); ?>
				</option>
			</select>

			<select name="course_id">
				<option value="0"><?php esc_html_e( 'All courses', 'student-activity-masterstudy' ); ?></option>
				<?php foreach ( self::courses() as $course_id => $title ) : ?>
					<option value="<?php echo esc_attr( $course_id ); ?>" <?php selected( $args['course_id'], $course_id ); ?>>
						<?php echo esc_html( $title ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php $table->search_box( __( 'Search students', 'student-activity-masterstudy' ), 'mssa-search' ); ?>

			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'student-activity-masterstudy' ); ?></button>
		</form>

		<?php if ( $args['course_id'] > 0 ) : ?>
			<p class="description">
				<?php esc_html_e( 'Activity figures are scoped to the selected course. Some imported historical events could not be attributed to a course and are excluded from this view.', 'student-activity-masterstudy' ); ?>
			</p>
		<?php endif; ?>

		<?php
		$table->display();

		self::render_settings_form();
	}

	private static function render_detail( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			echo '<h1>' . esc_html__( 'Student Activity', 'student-activity-masterstudy' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That user no longer exists.', 'student-activity-masterstudy' ) . '</p></div>';
			return;
		}

		$detail  = Query::get_student_detail( $user_id );
		$summary = $detail['summary'];

		?>
		<h1 class="wp-heading-inline"><?php echo esc_html( $user->display_name ); ?></h1>
		<a href="<?php echo esc_url( self::list_url() ); ?>" class="page-title-action">
			<?php esc_html_e( '← All students', 'student-activity-masterstudy' ); ?>
		</a>
		<hr class="wp-header-end">

		<div class="mssa-profile">
			<p>
				<strong><?php echo esc_html( $user->user_email ); ?></strong><br>
				<span class="mssa-muted">
					<?php
					printf(
						/* translators: %s: registration date */
						esc_html__( 'Registered %s', 'student-activity-masterstudy' ),
						esc_html( mysql2date( (string) get_option( 'date_format', 'Y-m-d' ), $user->user_registered ) )
					);
					?>
				</span>
			</p>

			<?php if ( $summary ) : ?>
				<p>
					<?php echo self::status_badge( $summary['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php if ( ! empty( $summary['last_activity'] ) ) : ?>
						<span class="mssa-muted">
							<?php
							printf(
								/* translators: 1: date, 2: relative age */
								esc_html__( 'Last active %1$s (%2$s)', 'student-activity-masterstudy' ),
								esc_html( self::format_timestamp( (int) $summary['last_activity'] ) ),
								esc_html( self::describe_age( (int) $summary['days_since'] ) )
							);
							?>
						</span>
					<?php endif; ?>
				</p>

				<ul class="mssa-stats">
					<li><strong><?php echo esc_html( number_format_i18n( (int) $summary['completions_total'] ) ); ?></strong>
						<?php esc_html_e( 'lessons completed', 'student-activity-masterstudy' ); ?></li>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $summary['active_days_short'] ) ); ?></strong>
						<?php
						printf(
							/* translators: %d: number of days */
							esc_html__( 'active days in the last %d', 'student-activity-masterstudy' ),
							(int) Settings::get( 'active_days' )
						);
						?>
					</li>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $summary['courses_touched'] ) ); ?></strong>
						<?php
						printf(
							/* translators: %d: number of enrolled courses */
							esc_html__( 'of %d enrolled courses touched', 'student-activity-masterstudy' ),
							(int) $summary['enrolled']
						);
						?>
					</li>
					<li>
						<?php if ( ! empty( $summary['membership_id'] ) ) : ?>
							<strong><?php echo esc_html( self::membership_name( (int) $summary['membership_id'] ) ); ?></strong>
							<?php esc_html_e( 'membership', 'student-activity-masterstudy' ); ?>
						<?php else : ?>
							<span class="mssa-muted"><?php esc_html_e( 'No active membership', 'student-activity-masterstudy' ); ?></span>
						<?php endif; ?>
					</li>
				</ul>
			<?php endif; ?>
		</div>

		<h2><?php esc_html_e( 'By course', 'student-activity-masterstudy' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Course', 'student-activity-masterstudy' ); ?></th>
					<th><?php esc_html_e( 'First touched', 'student-activity-masterstudy' ); ?></th>
					<th><?php esc_html_e( 'Last touched', 'student-activity-masterstudy' ); ?></th>
					<th><?php esc_html_e( 'Active days', 'student-activity-masterstudy' ); ?></th>
					<th><?php esc_html_e( 'Items opened', 'student-activity-masterstudy' ); ?></th>
					<th><?php esc_html_e( 'Items completed', 'student-activity-masterstudy' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $detail['courses'] ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No recorded activity for this student.', 'student-activity-masterstudy' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $detail['courses'] as $row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( self::course_title( (int) $row['course_id'] ) ); ?></strong></td>
						<td><?php echo esc_html( self::format_timestamp( (int) $row['first_activity'] ) ); ?></td>
						<td><?php echo esc_html( self::format_timestamp( (int) $row['last_activity'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['active_days'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['items_touched'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['items_completed'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Activity timeline', 'student-activity-masterstudy' ); ?></h2>
		<?php self::render_timeline( $detail['events'], $detail['total'] ); ?>
		<?php
	}

	/**
	 * Group the raw event rows by day for display.
	 */
	private static function render_timeline( array $events, int $total ): void {
		if ( empty( $events ) ) {
			echo '<p>' . esc_html__( 'Nothing recorded yet.', 'student-activity-masterstudy' ) . '</p>';
			return;
		}

		// One query for every referenced post rather than one per row.
		$post_ids = array_filter( array_unique( array_map( 'intval', wp_list_pluck( $events, 'item_id' ) ) ) );

		if ( $post_ids ) {
			_prime_post_caches( $post_ids, false, false );
		}

		$by_day = array();

		foreach ( $events as $event ) {
			$by_day[ $event['event_date'] ][] = $event;
		}

		echo '<p class="mssa-muted">';
		printf(
			/* translators: 1: number shown, 2: total number of events */
			esc_html__( 'Showing the %1$s most recent of %2$s recorded events.', 'student-activity-masterstudy' ),
			esc_html( number_format_i18n( count( $events ) ) ),
			esc_html( number_format_i18n( $total ) )
		);
		echo '</p>';

		echo '<div class="mssa-timeline">';

		foreach ( $by_day as $date => $day_events ) {
			echo '<div class="mssa-day">';
			echo '<h3>' . esc_html( mysql2date( (string) get_option( 'date_format', 'Y-m-d' ), $date . ' 00:00:00' ) ) . '</h3>';
			echo '<ul>';

			foreach ( $day_events as $event ) {
				$is_complete = Recorder::EVENT_COMPLETE === $event['event'];
				$title       = get_the_title( (int) $event['item_id'] );

				if ( '' === $title ) {
					$title = sprintf(
						/* translators: %d: post ID of a deleted item */
						__( 'Deleted item #%d', 'student-activity-masterstudy' ),
						(int) $event['item_id']
					);
				}

				printf(
					'<li><span class="mssa-event mssa-event--%1$s">%2$s</span> <strong>%3$s</strong> <span class="mssa-muted">%4$s · %5$s</span></li>',
					esc_attr( $event['event'] ),
					esc_html( $is_complete ? __( 'Completed', 'student-activity-masterstudy' ) : __( 'Opened', 'student-activity-masterstudy' ) ),
					esc_html( $title ),
					esc_html( self::course_title( (int) $event['course_id'] ) ),
					esc_html( wp_date( (string) get_option( 'time_format', 'H:i' ), (int) $event['event_time'] ) )
				);
			}

			echo '</ul></div>';
		}

		echo '</div>';
	}

	private static function render_backfill_notice(): void {
		$state = Backfill::state();

		if ( 'done' === $state['status'] ) {
			return;
		}

		$class = 'running' === $state['status'] ? 'notice-info' : 'notice-warning';

		echo '<div class="notice ' . esc_attr( $class ) . '"><p>';

		if ( 'running' === $state['status'] ) {
			printf(
				/* translators: 1: current stage, 2: rows scanned, 3: events written */
				esc_html__( 'Importing history (%1$s): %2$s source rows scanned, %3$s activity events written. Figures below are incomplete until this finishes.', 'student-activity-masterstudy' ),
				esc_html( $state['stage'] ),
				esc_html( number_format_i18n( (int) $state['scanned'] ) ),
				esc_html( number_format_i18n( (int) $state['inserted'] ) )
			);
		} else {
			esc_html_e( 'The historical import has not run yet. Figures below only cover activity recorded since this plugin was activated.', 'student-activity-masterstudy' );
		}

		echo ' ';
		self::render_backfill_button( __( 'Run import now', 'student-activity-masterstudy' ) );
		echo '</p></div>';
	}

	private static function render_refresh_button(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<input type="hidden" name="action" value="mssa_refresh">
			<?php wp_nonce_field( 'mssa_refresh' ); ?>
			<button type="submit" class="button button-small"><?php esc_html_e( 'Refresh now', 'student-activity-masterstudy' ); ?></button>
		</form>
		<?php
	}

	private static function render_backfill_button( string $label, bool $reset = false ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<input type="hidden" name="action" value="mssa_backfill">
			<input type="hidden" name="reset" value="<?php echo $reset ? 1 : 0; ?>">
			<?php wp_nonce_field( 'mssa_backfill' ); ?>
			<button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function render_settings_form(): void {
		$settings = Settings::all();
		$state    = Backfill::state();

		?>
		<h2><?php esc_html_e( 'Settings', 'student-activity-masterstudy' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mssa-settings">
			<input type="hidden" name="action" value="mssa_settings">
			<?php wp_nonce_field( 'mssa_settings' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mssa-active"><?php esc_html_e( 'Active within', 'student-activity-masterstudy' ); ?></label></th>
					<td>
						<input type="number" min="1" max="3650" id="mssa-active" name="active_days"
							value="<?php echo esc_attr( $settings['active_days'] ); ?>" class="small-text">
						<?php esc_html_e( 'days', 'student-activity-masterstudy' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mssa-slipping"><?php esc_html_e( 'Dormant after', 'student-activity-masterstudy' ); ?></label></th>
					<td>
						<input type="number" min="2" max="3650" id="mssa-slipping" name="slipping_days"
							value="<?php echo esc_attr( $settings['slipping_days'] ); ?>" class="small-text">
						<?php esc_html_e( 'days', 'student-activity-masterstudy' ); ?>
						<p class="description"><?php esc_html_e( 'Students between the two thresholds are shown as Slipping.', 'student-activity-masterstudy' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'What counts as activity', 'student-activity-masterstudy' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="count_views" value="1" <?php checked( $settings['count_views'], 1 ); ?>>
							<?php esc_html_e( 'Count opening a lesson, not just completing it', 'student-activity-masterstudy' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Recommended. Many students watch without ever clicking Complete.', 'student-activity-masterstudy' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'On uninstall', 'student-activity-masterstudy' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $settings['delete_on_uninstall'], 1 ); ?>>
							<?php esc_html_e( 'Delete the activity table when this plugin is deleted', 'student-activity-masterstudy' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Off by default. View history cannot be reconstructed once deleted.', 'student-activity-masterstudy' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Historical import', 'student-activity-masterstudy' ); ?></th>
					<td>
						<p>
							<?php
							printf(
								/* translators: 1: status, 2: rows scanned, 3: events written */
								esc_html__( 'Status: %1$s — %2$s source rows scanned, %3$s events written.', 'student-activity-masterstudy' ),
								esc_html( $state['status'] ),
								esc_html( number_format_i18n( (int) $state['scanned'] ) ),
								esc_html( number_format_i18n( (int) $state['inserted'] ) )
							);
							?>
						</p>
						<?php self::render_backfill_button( __( 'Re-run import from scratch', 'student-activity-masterstudy' ), true ); ?>
						<p class="description"><?php esc_html_e( 'Safe to re-run: existing events are never duplicated.', 'student-activity-masterstudy' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save settings', 'student-activity-masterstudy' ) ); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Form handlers
	 * ------------------------------------------------------------------ */

	public static function handle_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'student-activity-masterstudy' ) );
		}

		check_admin_referer( 'mssa_settings' );

		// Hand Settings::update() only the keys it owns, rather than the whole
		// superglobal. It sanitises everything it reads, but there is no reason
		// for unrelated POST data to reach it at all.
		Settings::update(
			array(
				'active_days'         => isset( $_POST['active_days'] ) ? absint( $_POST['active_days'] ) : null,
				'slipping_days'       => isset( $_POST['slipping_days'] ) ? absint( $_POST['slipping_days'] ) : null,
				'count_views'         => ! empty( $_POST['count_views'] ),
				'delete_on_uninstall' => ! empty( $_POST['delete_on_uninstall'] ),
			)
		);

		wp_safe_redirect( add_query_arg( 'mssa_saved', '1', self::list_url() ) );
		exit;
	}

	/**
	 * Discard cached aggregates so the next view recomputes from live data.
	 */
	public static function handle_refresh(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'student-activity-masterstudy' ) );
		}

		check_admin_referer( 'mssa_refresh' );

		Cache::flush();

		wp_safe_redirect( self::list_url() );
		exit;
	}

	public static function handle_backfill(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'student-activity-masterstudy' ) );
		}

		check_admin_referer( 'mssa_backfill' );

		$reset = ! empty( $_POST['reset'] );

		if ( $reset ) {
			update_option( Backfill::STATE_OPTION, Backfill::default_state(), false );
		}

		// Run one batch synchronously so the admin sees immediate progress, then
		// let cron carry the rest.
		Backfill::run();
		Backfill::schedule();

		wp_safe_redirect( add_query_arg( 'mssa_backfill_started', '1', self::list_url() ) );
		exit;
	}

	public static function handle_export(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'student-activity-masterstudy' ) );
		}

		check_admin_referer( 'mssa_export' );

		$args             = self::current_args();
		$args['paged']    = 1;
		$args['per_page'] = 10000;

		$result = Query::get_students( $args );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header(
			'Content-Disposition: attachment; filename=student-activity-' . gmdate( 'Y-m-d' ) . '.csv'
		);

		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				'user_id',
				'name',
				'email',
				'status',
				'last_active',
				'days_since_last_active',
				'active_days_short_window',
				'active_days_long_window',
				'lessons_completed_total',
				'lessons_completed_recent',
				'courses_touched',
				'courses_enrolled',
				'membership',
				'membership_ends',
				'registered',
			)
		);

		$labels = Query::status_labels();

		foreach ( $result['items'] as $row ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array(
						$row['user_id'],
						$row['display_name'],
						$row['user_email'],
						$labels[ $row['status'] ] ?? $row['status'],
						$row['last_activity'] ? self::format_timestamp( (int) $row['last_activity'] ) : '',
						null === $row['days_since'] ? '' : $row['days_since'],
						$row['active_days_short'],
						$row['active_days_long'],
						$row['completions_total'],
						$row['completions_recent'],
						$row['courses_touched'],
						$row['enrolled'],
						empty( $row['membership_id'] ) ? '' : self::membership_name( (int) $row['membership_id'] ),
						$row['membership_enddate'] ?? '',
						$row['user_registered'],
					)
				)
			);
		}

		// php://output is an output stream, not a file on disk. WP_Filesystem has no
		// API for streaming a download, so the stream functions are the only option.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}

	/**
	 * Neutralise spreadsheet formula injection.
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public static function csv_safe( $value ) {
		if ( is_string( $value ) && '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/* ---------------------------------------------------------------------
	 * Presentation helpers
	 * ------------------------------------------------------------------ */

	public static function list_url( array $extra = array() ): string {
		$args = self::current_args();

		$query = array(
			'page'       => self::SLUG,
			's'          => $args['search'],
			'status'     => $args['status'],
			'membership' => $args['membership'],
			'course_id'  => $args['course_id'] ? $args['course_id'] : '',
		);

		$query = array_merge( $query, $extra );
		$query = array_filter(
			$query,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	public static function student_url( int $user_id ): string {
		return add_query_arg(
			array(
				'page'       => self::SLUG,
				'student_id' => $user_id,
			),
			admin_url( 'admin.php' )
		);
	}

	public static function export_url( array $args ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'mssa_export',
					's'          => $args['search'],
					'status'     => $args['status'],
					'membership' => $args['membership'],
					'course_id'  => $args['course_id'],
				),
				admin_url( 'admin-post.php' )
			),
			'mssa_export'
		);
	}

	public static function status_badge( string $status ): string {
		$labels = array(
			Query::STATUS_ACTIVE   => __( 'Active', 'student-activity-masterstudy' ),
			Query::STATUS_SLIPPING => __( 'Slipping', 'student-activity-masterstudy' ),
			Query::STATUS_DORMANT  => __( 'Dormant', 'student-activity-masterstudy' ),
			Query::STATUS_NEVER    => __( 'Never started', 'student-activity-masterstudy' ),
		);

		return sprintf(
			'<span class="mssa-badge mssa-badge--%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $labels[ $status ] ?? $status )
		);
	}

	public static function format_timestamp( int $timestamp ): string {
		if ( $timestamp < Recorder::MIN_TIMESTAMP ) {
			return '—';
		}

		$format = trim( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ) );

		return (string) wp_date( $format, $timestamp );
	}

	public static function describe_age( int $days ): string {
		if ( $days <= 0 ) {
			return __( 'today', 'student-activity-masterstudy' );
		}

		if ( 1 === $days ) {
			return __( 'yesterday', 'student-activity-masterstudy' );
		}

		/* translators: %s: number of days */
		return sprintf( __( '%s days ago', 'student-activity-masterstudy' ), number_format_i18n( $days ) );
	}

	public static function membership_name( int $level_id ): string {
		static $cache = array();

		if ( isset( $cache[ $level_id ] ) ) {
			return $cache[ $level_id ];
		}

		$name = '';

		if ( function_exists( 'pmpro_getLevel' ) ) {
			$level = pmpro_getLevel( $level_id );

			if ( $level && ! empty( $level->name ) ) {
				$name = $level->name;
			}
		}

		if ( '' === $name ) {
			/* translators: %d: membership level ID */
			$name = sprintf( __( 'Level %d', 'student-activity-masterstudy' ), $level_id );
		}

		$cache[ $level_id ] = $name;

		return $name;
	}

	public static function course_title( int $course_id ): string {
		if ( $course_id <= 0 ) {
			return __( 'Unattributed', 'student-activity-masterstudy' );
		}

		$title = get_the_title( $course_id );

		if ( '' === $title ) {
			/* translators: %d: course post ID */
			return sprintf( __( 'Deleted course #%d', 'student-activity-masterstudy' ), $course_id );
		}

		return $title;
	}

	/**
	 * Published courses, for the filter dropdown.
	 *
	 * @return array<int,string>
	 */
	public static function courses(): array {
		static $courses = null;

		if ( null !== $courses ) {
			return $courses;
		}

		$posts = get_posts(
			array(
				'post_type'        => 'stm-courses',
				'post_status'      => array( 'publish', 'draft', 'private' ),
				// Bounded on purpose: this fills one filter dropdown, not a listing.
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts
				'numberposts'      => 200,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$courses = array();

		foreach ( $posts as $post ) {
			$courses[ $post->ID ] = $post->post_title;
		}

		return $courses;
	}
}
