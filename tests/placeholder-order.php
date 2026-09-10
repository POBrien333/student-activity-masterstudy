<?php
/**
 * Regression test: query placeholders must line up with their parameters.
 *
 * $wpdb->prepare() fills placeholders in the order they appear in the SQL TEXT,
 * not the order the clauses were assembled in PHP. Query::base_query() builds
 * its WHERE clauses before the SELECT list that precedes them, so it is easy to
 * push parameters in the wrong order — and the failure is silent, because the
 * placeholder count still matches. A course ID lands in a timestamp comparison,
 * a timestamp lands in the course filter, and the query quietly returns nothing.
 *
 * That shipped once (0.1.1: filtering by course showed every student as having
 * no activity). This asserts the substituted values are the right *kind* of
 * value, which catches the whole class rather than one instance.
 *
 * Run with plain PHP, no dependencies:
 *
 *     php tests/placeholder-order.php
 *
 * @package StudentActivityForMasterStudy
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$root = dirname( __DIR__ ) . '/';

class Mssa_Test_Wpdb {
	public $prefix   = 'wp_';
	public $users    = 'wp_users';
	public $usermeta = 'wp_usermeta';
	public $posts    = 'wp_posts';

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}
}

$GLOBALS['wpdb'] = new Mssa_Test_Wpdb();

function get_option( $name, $default = false ) {
	return $default;
}
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}
function __( $text, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return $text;
}

require $root . 'includes/Settings.php';
require $root . 'includes/Schema.php';
require $root . 'includes/Query.php';

use StudentActivityForMasterStudy\Query;

/** Substitute placeholders in SQL-text order, exactly as $wpdb->prepare() does. */
function mssa_fake_prepare( string $sql, array $args, ?int &$used = null ): string {
	$i   = 0;
	$out = preg_replace_callback(
		'/%[dsf]/',
		function ( $m ) use ( &$i, $args ) {
			$value = $args[ $i ] ?? '<<MISSING>>';
			++$i;

			return '%d' === $m[0] ? (string) (int) $value : "'" . $value . "'";
		},
		$sql
	);
	$used = $i;

	return $out;
}

$failures = array();
$checks   = 0;

function mssa_assert( bool $ok, string $what, array &$failures ): void {
	global $checks;
	++$checks;

	if ( ! $ok ) {
		$failures[] = $what;
	}
}

$cases = array(
	'no filters'            => array(),
	'course filter'         => array( 'course_id' => 8131 ),
	'course + search'       => array( 'course_id' => 8131, 'search' => 'ana' ),
	'course + single user'  => array( 'course_id' => 8131, 'user_id' => 414 ),
	'search only'           => array( 'search' => 'ana' ),
	'single user only'      => array( 'user_id' => 414 ),
);

$method = new ReflectionMethod( Query::class, 'base_query' );
$method->setAccessible( true );

foreach ( $cases as $label => $overrides ) {
	$args = array_merge( Query::default_args(), $overrides );
	$base = $method->invoke( null, $args );

	$used = 0;
	$sql  = mssa_fake_prepare( $base['sql'], $base['params'], $used );

	mssa_assert(
		count( $base['params'] ) === $used,
		sprintf( '%s: %d placeholders but %d parameters', $label, $used, count( $base['params'] ) ),
		$failures
	);

	mssa_assert(
		false === strpos( $sql, '<<MISSING>>' ),
		$label . ': ran out of parameters part-way through the query',
		$failures
	);

	// A timestamp comparison must receive something that looks like a timestamp.
	preg_match_all( '/event_time >= (\d+)/', $sql, $times );

	foreach ( $times[1] as $value ) {
		mssa_assert(
			(int) $value > 1000000000,
			sprintf( '%s: event_time compared against %s, which is not a timestamp', $label, $value ),
			$failures
		);
	}

	// A course filter must receive something that looks like a post ID.
	preg_match_all( '/course_id = (\d+)/', $sql, $courses );

	foreach ( $courses[1] as $value ) {
		mssa_assert(
			(int) $value > 0 && (int) $value < 100000000,
			sprintf( '%s: course_id compared against %s, which looks like a timestamp', $label, $value ),
			$failures
		);
	}

	// The long window must reach further back than the short one.
	if ( count( $times[1] ) >= 3 ) {
		mssa_assert(
			(int) $times[1][2] < (int) $times[1][0],
			$label . ': the long activity window is not older than the short one',
			$failures
		);
	}

	printf( "  %-22s %2d placeholders, %d parameters\n", $label, $used, count( $base['params'] ) );
}

echo "\n";

if ( $failures ) {
	echo "FAILED (" . count( $failures ) . " of {$checks} checks):\n";

	foreach ( $failures as $f ) {
		echo '  - ' . $f . "\n";
	}

	exit( 1 );
}

echo "OK — {$checks} checks passed across " . count( $cases ) . " query shapes.\n";
exit( 0 );
