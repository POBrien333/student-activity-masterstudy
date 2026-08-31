<?php
/**
 * Tags disengaged students in Mailchimp so you can build a segment there.
 *
 * Deliberately does not send email itself. Members are normally already in your
 * Mailchimp audience (pmpro-mailchimp and similar keep them in sync), so all
 * this needs to do is mark the quiet ones. Writing and sending the campaign
 * stays in Mailchimp, where unsubscribe handling, deliverability and consent
 * records already live.
 *
 * @package StudentActivityForMasterStudy
 */

namespace StudentActivityForMasterStudy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mailchimp {

	public const CRON_HOOK = 'mssa_mailchimp_sync';

	public const STATE_OPTION = 'mssa_mailchimp_state';

	/** Statuses that count as "should be nudged". */
	public const TARGET_STATUSES = array(
		Query::STATUS_SLIPPING,
		Query::STATUS_DORMANT,
		Query::STATUS_NEVER,
	);

	/** Mailchimp asks for no more than ~10 concurrent connections; we go serial. */
	private const MAX_PER_RUN = 500;

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	public static function is_configured(): bool {
		return '' !== (string) Settings::get( 'mailchimp_api_key' )
			&& '' !== (string) Settings::get( 'mailchimp_list_id' );
	}

	public static function is_enabled(): bool {
		return (bool) Settings::get( 'mailchimp_enabled' ) && self::is_configured();
	}

	/* ------------------------------------------------------------------ API */

	/**
	 * The datacenter is the suffix of the API key, e.g. "...-us7".
	 */
	private static function datacenter(): string {
		$key   = (string) Settings::get( 'mailchimp_api_key' );
		$parts = explode( '-', $key );

		return ( count( $parts ) > 1 ) ? end( $parts ) : '';
	}

	/**
	 * One Mailchimp API call.
	 *
	 * @param string $method HTTP verb.
	 * @param string $path   Path below /3.0/, no leading slash.
	 * @param array  $body   Optional JSON body.
	 *
	 * @return array|\WP_Error Decoded response, or WP_Error on failure.
	 */
	public static function request( string $method, string $path, array $body = array() ) {
		$key = (string) Settings::get( 'mailchimp_api_key' );
		$dc  = self::datacenter();

		if ( '' === $key || '' === $dc ) {
			return new \WP_Error( 'mssa_no_key', __( 'No Mailchimp API key is configured.', 'student-activity-masterstudy' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				// Mailchimp accepts HTTP Basic with any username and the key as password.
				'Authorization' => 'Basic ' . base64_encode( 'mssa:' . $key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Basic auth encoding, not obfuscation.
				'Content-Type'  => 'application/json',
			),
		);

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( "https://{$dc}.api.mailchimp.com/3.0/{$path}", $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			return new \WP_Error(
				'mssa_mailchimp_http_' . $code,
				is_array( $data ) && ! empty( $data['detail'] )
					? $data['detail']
					: sprintf(
						/* translators: %d: HTTP status code */
						__( 'Mailchimp returned HTTP %d.', 'student-activity-masterstudy' ),
						$code
					)
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Audiences on the account, for the settings dropdown.
	 *
	 * @return array<string,string>|\WP_Error id => name
	 */
	public static function audiences() {
		$data = self::request( 'GET', 'lists?count=100&fields=lists.id,lists.name' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$out = array();

		foreach ( $data['lists'] ?? array() as $list ) {
			$out[ (string) $list['id'] ] = (string) $list['name'];
		}

		return $out;
	}

	/* ------------------------------------------------------- Sync ---------- */

	/**
	 * Bring the Mailchimp tag in line with who is currently disengaged.
	 *
	 * Adds the tag to targeted students and removes it from everyone else, so a
	 * student who comes back drops out of your segment on the next run instead
	 * of being nudged forever.
	 *
	 * @param bool $dry_run Compute the changes but send nothing.
	 *
	 * @return array Report: tagged, untagged, skipped, errors, and the dry-run lists.
	 */
	public static function run( bool $dry_run = false ): array {
		$report = array(
			'ran_at'   => time(),
			'dry_run'  => $dry_run,
			'tag'      => (string) Settings::get( 'mailchimp_tag' ),
			'tagged'   => 0,
			'untagged' => 0,
			'skipped'  => 0,
			'errors'   => array(),
			'preview'  => array(
				'add'    => array(),
				'remove' => array(),
			),
		);

		if ( ! self::is_configured() ) {
			$report['errors'][] = __( 'Mailchimp is not configured.', 'student-activity-masterstudy' );

			return $report;
		}

		$list_id = (string) Settings::get( 'mailchimp_list_id' );
		$tag     = (string) Settings::get( 'mailchimp_tag' );

		if ( '' === $tag ) {
			$report['errors'][] = __( 'No tag name is set.', 'student-activity-masterstudy' );

			return $report;
		}

		// Paying members only — an active PMPro membership is the whole point of
		// the nudge, and emailing lapsed customers is a different conversation.
		$students = Query::get_students(
			array(
				'membership' => 'active',
				'per_page'   => self::MAX_PER_RUN,
				'with_total' => false,
			)
		);

		foreach ( $students['items'] as $student ) {
			$email = (string) $student['user_email'];

			if ( '' === $email || ! is_email( $email ) ) {
				++$report['skipped'];
				continue;
			}

			$should_tag = in_array( $student['status'], self::TARGET_STATUSES, true );
			$bucket     = $should_tag ? 'add' : 'remove';

			if ( $dry_run ) {
				if ( count( $report['preview'][ $bucket ] ) < 25 ) {
					$report['preview'][ $bucket ][] = $email;
				}
				++$report[ $should_tag ? 'tagged' : 'untagged' ];
				continue;
			}

			$result = self::set_tag( $list_id, $email, $tag, $should_tag );

			if ( is_wp_error( $result ) ) {
				// A member Mailchimp has never seen is normal, not a failure.
				if ( 'mssa_mailchimp_http_404' === $result->get_error_code() ) {
					++$report['skipped'];
					continue;
				}

				if ( count( $report['errors'] ) < 10 ) {
					$report['errors'][] = $email . ': ' . $result->get_error_message();
				}
				continue;
			}

			++$report[ $should_tag ? 'tagged' : 'untagged' ];
		}

		if ( ! $dry_run ) {
			update_option( self::STATE_OPTION, $report, false );
		}

		return $report;
	}

	/**
	 * Add or remove one tag on one subscriber.
	 *
	 * @return true|\WP_Error
	 */
	private static function set_tag( string $list_id, string $email, string $tag, bool $active ) {
		$hash = md5( strtolower( $email ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5 -- Mailchimp's documented subscriber hash.

		$result = self::request(
			'POST',
			"lists/{$list_id}/members/{$hash}/tags",
			array(
				'tags' => array(
					array(
						'name'   => $tag,
						'status' => $active ? 'active' : 'inactive',
					),
				),
			)
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Result of the last real sync, for display.
	 */
	public static function last_run(): array {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/* ------------------------------------------------------- Scheduling ---- */

	public static function schedule(): void {
		if ( self::is_enabled() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
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
	 * Keep the schedule in step with the enabled setting.
	 */
	public static function sync_schedule(): void {
		if ( self::is_enabled() ) {
			self::schedule();
		} else {
			self::unschedule();
		}
	}
}
