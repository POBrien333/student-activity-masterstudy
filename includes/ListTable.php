<?php
/**
 * The student activity list table.
 *
 * @package StudentActivityForMasterStudy
 */

namespace StudentActivityForMasterStudy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class ListTable extends \WP_List_Table {

	/** @var array */
	private $args;

	/** @var array<string,int> */
	private $status_counts = array();

	/** @var int Unix time the displayed figures were computed. */
	private $generated_at = 0;

	public function __construct( array $args ) {
		$this->args = $args;

		parent::__construct(
			array(
				'singular' => 'student',
				'plural'   => 'students',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'student'     => __( 'Student', 'student-activity-masterstudy' ),
			'status'      => __( 'Status', 'student-activity-masterstudy' ),
			'last_active' => __( 'Last active', 'student-activity-masterstudy' ),
			'days'        => __( 'Active days', 'student-activity-masterstudy' ),
			'completions' => __( 'Lessons completed', 'student-activity-masterstudy' ),
			'courses'     => __( 'Courses', 'student-activity-masterstudy' ),
			'membership'  => __( 'Membership', 'student-activity-masterstudy' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'student'     => array( 'name', false ),
			'status'      => array( 'last_activity', false ),
			'last_active' => array( 'last_activity', true ),
			'days'        => array( 'active_days_short', false ),
			'completions' => array( 'completions_total', false ),
			'courses'     => array( 'enrolled', false ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		// Status counts deliberately ignore the status filter, so they always
		// describe the whole filtered population — which means the row total can
		// be derived from them instead of running a third aggregate query.
		$this->status_counts = Query::get_status_counts( $this->args );

		$status = $this->args['status'];
		$total  = '' === $status
			? array_sum( $this->status_counts )
			: (int) ( $this->status_counts[ $status ] ?? 0 );

		$result = Query::get_students( array_merge( $this->args, array( 'with_total' => false ) ) );

		$this->items        = $result['items'];
		$this->generated_at = (int) ( $result['generated_at'] ?? time() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => (int) $this->args['per_page'],
			)
		);
	}

	/**
	 * @return array<string,int>
	 */
	public function status_counts(): array {
		return $this->status_counts;
	}

	public function generated_at(): int {
		return $this->generated_at;
	}

	public function no_items(): void {
		esc_html_e( 'No students match these filters.', 'student-activity-masterstudy' );
	}

	/**
	 * @param array  $item
	 * @param string $column_name
	 *
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	public function column_student( array $item ): string {
		$url = AdminPage::student_url( $item['user_id'] );

		return sprintf(
			'<strong><a href="%1$s">%2$s</a></strong><br><span class="mssa-muted">%3$s</span>',
			esc_url( $url ),
			esc_html( $item['display_name'] ? $item['display_name'] : $item['user_login'] ),
			esc_html( $item['user_email'] )
		);
	}

	public function column_status( array $item ): string {
		return AdminPage::status_badge( $item['status'] );
	}

	public function column_last_active( array $item ): string {
		if ( empty( $item['last_activity'] ) ) {
			return '<span class="mssa-muted">' . esc_html__( 'Never', 'student-activity-masterstudy' ) . '</span>';
		}

		return sprintf(
			'%1$s<br><span class="mssa-muted">%2$s</span>',
			esc_html( AdminPage::format_timestamp( (int) $item['last_activity'] ) ),
			esc_html( AdminPage::describe_age( (int) $item['days_since'] ) )
		);
	}

	public function column_days( array $item ): string {
		$active   = (int) Settings::get( 'active_days' );
		$slipping = (int) Settings::get( 'slipping_days' );

		return sprintf(
			'<strong>%1$d</strong> <span class="mssa-muted">/ %2$dd</span><br><span class="mssa-muted">%3$d / %4$dd</span>',
			(int) $item['active_days_short'],
			$active,
			(int) $item['active_days_long'],
			$slipping
		);
	}

	public function column_completions( array $item ): string {
		return sprintf(
			'<strong>%1$d</strong><br><span class="mssa-muted">%2$s</span>',
			(int) $item['completions_total'],
			esc_html(
				sprintf(
					/* translators: 1: number of lessons, 2: number of days */
					__( '%1$d in last %2$dd', 'student-activity-masterstudy' ),
					(int) $item['completions_recent'],
					(int) Settings::get( 'active_days' )
				)
			)
		);
	}

	public function column_courses( array $item ): string {
		return sprintf(
			'<strong>%1$d</strong> <span class="mssa-muted">%2$s %3$d</span>',
			(int) $item['courses_touched'],
			esc_html__( 'of', 'student-activity-masterstudy' ),
			(int) $item['enrolled']
		);
	}

	public function column_membership( array $item ): string {
		if ( empty( $item['membership_id'] ) ) {
			return '<span class="mssa-muted">' . esc_html__( '— none', 'student-activity-masterstudy' ) . '</span>';
		}

		$name = AdminPage::membership_name( (int) $item['membership_id'] );
		$out  = '<span class="mssa-badge mssa-badge--member">' . esc_html( $name ) . '</span>';

		if ( ! empty( $item['membership_enddate'] ) && '0000-00-00 00:00:00' !== $item['membership_enddate'] ) {
			$out .= '<br><span class="mssa-muted">' . esc_html(
				sprintf(
					/* translators: %s: expiry date */
					__( 'until %s', 'student-activity-masterstudy' ),
					mysql2date( (string) get_option( 'date_format', 'Y-m-d' ), $item['membership_enddate'] )
				)
			) . '</span>';
		}

		return $out;
	}
}
