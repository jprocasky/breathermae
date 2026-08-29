<?php
/**
 * AJAX handlers – appointments CRUD (Phase A).
 *
 * @package BMF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Calendar_Ajax {

	/** @var BMF_Calendar_Ajax|null */
	private static $instance = null;

	const STATUSES = array( 'requested', 'confirmed', 'completed', 'cancelled', 'no_show' );

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_bmf_cal_get_appointments', array( $this, 'get_appointments' ) );
		add_action( 'wp_ajax_bmf_cal_save_appointment', array( $this, 'save_appointment' ) );
		add_action( 'wp_ajax_bmf_cal_delete_appointment', array( $this, 'delete_appointment' ) );
		add_action( 'wp_ajax_bmf_cal_get_slots', array( $this, 'get_slots' ) );
		add_action( 'wp_ajax_bmf_cal_request_appointment', array( $this, 'request_appointment' ) );
		add_action( 'wp_ajax_bmf_cal_get_availability', array( $this, 'get_availability' ) );
		add_action( 'wp_ajax_bmf_cal_save_availability', array( $this, 'save_availability' ) );
		add_action( 'wp_ajax_bmf_cal_delete_availability', array( $this, 'delete_availability' ) );
		add_action( 'wp_ajax_bmf_cal_list_providers', array( $this, 'list_providers' ) );
		add_action( 'wp_ajax_bmf_cal_get_open_requests', array( $this, 'get_open_requests' ) );
		add_action( 'wp_ajax_bmf_cal_set_status', array( $this, 'set_status' ) );
		add_action( 'wp_ajax_bmf_cal_get_coverage', array( $this, 'get_coverage' ) );
	}

	private function verify() {
		check_ajax_referer( 'bmf_calendar', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
		}
	}

	/**
	 * Can the current user manage appointments for this member?
	 */
	private function can_manage_member( $member_id, $member_email = '' ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( ! BMF_Calendar_Provider::is_provider() ) {
			return false;
		}
		// For now any Provider can manage any member.
		// Later we can tighten via provider_member links or WP Fusion relations.
		return true;
	}

	/**
	 * Format a DB row for the front-end.
	 */
	private static function user_display_name( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return '';
		}
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return '';
		}
		return $u->display_name ? $u->display_name : $u->user_login;
	}

	private static function member_display_name( $row ) {
		if ( ! empty( $row['member_id'] ) ) {
			$u = get_userdata( (int) $row['member_id'] );
			if ( $u ) {
				return $u->display_name ? $u->display_name : $u->user_email;
			}
		}
		return ! empty( $row['member_email'] ) ? $row['member_email'] : '';
	}

	private function format_appointment( $row ) {
		$site_tz = wp_timezone();
		$start   = new DateTime( $row['start_at'], $site_tz );
		$end     = ! empty( $row['end_at'] ) ? new DateTime( $row['end_at'], $site_tz ) : null;

		$fmt = trim( get_option( 'date_format', 'M j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ) );

		return array(
			'id'           => (int) $row['id'],
			'member_id'    => $row['member_id'] ? (int) $row['member_id'] : null,
			'member_email' => (string) $row['member_email'],
			'provider_id'  => $row['provider_id'] ? (int) $row['provider_id'] : null,
			'start_at'     => (string) $row['start_at'],
			'end_at'       => $row['end_at'] ? (string) $row['end_at'] : null,
			'start_fmt'    => wp_date( $fmt, $start->getTimestamp() ),
			'end_fmt'      => $end ? wp_date( $fmt, $end->getTimestamp() ) : '',
			'start_ts'     => $start->getTimestamp(),
			'end_ts'       => $end ? $end->getTimestamp() : null,
			'status'       => (string) $row['status'],
			'subject'      => (string) $row['subject'],
			'description'  => (string) ( $row['description'] ?? '' ),
			'location'     => (string) ( $row['location'] ?? '' ),
			'member_name'  => self::member_display_name( $row ),
			'provider_name'=> self::user_display_name( $row['provider_id'] ?? 0 ),
			'created_by'   => $row['created_by'] ? (int) $row['created_by'] : null,
			'created_at'   => (string) $row['created_at'],
		);
	}

	/**
	 * List appointments.
	 *
	 * POST params:
	 *   mode       = member | provider
	 *   member_id  = (provider mode)
	 *   email      = (provider mode, fallback)
	 *   future     = 1|0 (default 1)
	 *   status     = optional filter
	 */
	public function get_appointments() {
		$this->verify();

		$mode      = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'member';
		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$future    = ! isset( $_POST['future'] ) || (string) $_POST['future'] === '1';
		$status    = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		$current_id = get_current_user_id();
		$current    = wp_get_current_user();

		global $wpdb;
		$table = BMF_Calendar_DB::appointments_table();

		$where  = array( 'is_deleted = 0' );
		$params = array();

		if ( 'booked' === $mode ) {
			if ( ! BMF_Calendar_Provider::is_provider() && ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
			}
			$where[] = "status IN ('requested','confirmed')";
			$status  = '';
			$until   = new DateTime( 'now', wp_timezone() );
			$until->modify( '+31 days' );
			$where[]  = 'start_at < %s';
			$params[] = $until->format( 'Y-m-d H:i:s' );
		} elseif ( 'agenda' === $mode ) {
			if ( ! BMF_Calendar_Provider::is_provider() && ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
			}
			$where[]  = 'provider_id = %d';
			$params[] = $current_id;
			$where[]  = "status IN ('requested','confirmed')";
			$status   = '';
			$until    = new DateTime( 'now', wp_timezone() );
			$until->modify( '+31 days' );
			$where[]  = 'start_at < %s';
			$params[] = $until->format( 'Y-m-d H:i:s' );
		} elseif ( 'member' === $mode ) {
			// Own appointments only.
			$where[]  = '(member_id = %d OR member_email = %s)';
			$params[] = $current_id;
			$params[] = $current->user_email;
		} else {
			// Provider / admin view for a specific member.
			if ( ! $this->can_manage_member( $member_id, $email ) ) {
				wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
			}
			if ( $member_id ) {
				$where[]  = 'member_id = %d';
				$params[] = $member_id;
			} elseif ( $email ) {
				$where[]  = 'member_email = %s';
				$params[] = $email;
			} else {
				// No member selected yet – return empty rather than error.
				wp_send_json_success( array( 'appointments' => array() ) );
			}
		}

		if ( $future ) {
			$where[]  = 'start_at >= %s';
			$params[] = current_time( 'mysql' );
		}

		if ( $status && in_array( $status, self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$limit = in_array( $mode, array( 'booked', 'agenda' ), true ) ? 400 : 100;
		$sql   = "SELECT * FROM `{$table}` WHERE " . implode( ' AND ', $where ) . ' ORDER BY start_at ASC LIMIT ' . $limit;

		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}

		$exclude = array();
		if ( 'booked' === $mode && isset( $_POST['exclude'] ) ) {
			$exclude = BMF_Calendar_Provider::parse_exclude( wp_unslash( $_POST['exclude'] ) );
		}

		$appts = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( $exclude && ! empty( $row['provider_id'] ) && BMF_Calendar_Provider::is_excluded( (int) $row['provider_id'], $exclude ) ) {
					continue;
				}
				$appts[] = $this->format_appointment( $row );
			}
		}

		wp_send_json_success( array( 'appointments' => $appts ) );
	}

	/**
	 * Create or update an appointment.
	 *
	 * POST params:
	 *   id            (optional – update if present)
	 *   member_id
	 *   email
	 *   provider_id   (optional, defaults to current user if Provider)
	 *   start         (ISO or "Y-m-d\TH:i" local)
	 *   end           (optional)
	 *   start_utc     (preferred – ISO UTC from browser)
	 *   end_utc
	 *   subject
	 *   description
	 *   location
	 *   status
	 */
	public function save_appointment() {
		$this->verify();

		$id          = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$member_id   = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		$email       = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$provider_id = isset( $_POST['provider_id'] ) ? absint( $_POST['provider_id'] ) : 0;
		$subject     = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$location    = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		$status      = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'confirmed';

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'confirmed';
		}

		if ( $subject === '' ) {
			wp_send_json_error( array( 'message' => 'Subject is required' ), 400 );
		}

		// Resolve member.
		if ( ! $member_id && $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$member_id = (int) $user->ID;
			}
		}
		if ( $member_id && ! $email ) {
			$user = get_userdata( $member_id );
			if ( $user ) {
				$email = $user->user_email;
			}
		}

		if ( ! $member_id && ! $email ) {
			wp_send_json_error( array( 'message' => 'Member is required' ), 400 );
		}

		if ( ! $this->can_manage_member( $member_id, $email ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		// Default provider to current user when they are a Provider.
		if ( ! $provider_id && BMF_Calendar_Provider::is_provider() ) {
			$provider_id = get_current_user_id();
		}

		// Parse times – prefer UTC payload from browser.
		$start_utc = isset( $_POST['start_utc'] ) ? sanitize_text_field( wp_unslash( $_POST['start_utc'] ) ) : '';
		$end_utc   = isset( $_POST['end_utc'] ) ? sanitize_text_field( wp_unslash( $_POST['end_utc'] ) ) : '';
		$start_in  = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
		$end_in    = isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : '';

		$start_at = $this->parse_to_site_mysql( $start_utc, $start_in );
		$end_at   = $this->parse_to_site_mysql( $end_utc, $end_in );

		if ( ! $start_at ) {
			wp_send_json_error( array( 'message' => 'Start date/time is required' ), 400 );
		}

		// Default duration 30 minutes.
		$site_tz  = wp_timezone();
		$start_dt = new DateTime( $start_at, $site_tz );
		$end_dt   = $end_at ? new DateTime( $end_at, $site_tz ) : null;
		if ( ! $end_dt || $end_dt <= $start_dt ) {
			$end_dt = clone $start_dt;
			$end_dt->modify( '+30 minutes' );
		}
		$end_at = $end_dt->format( 'Y-m-d H:i:s' );

		$raw_desc = isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '';
		$allowed  = array(
			'a'      => array( 'href' => true, 'title' => true, 'target' => true, 'rel' => true ),
			'br'     => array(),
			'p'      => array(),
			'strong' => array(),
			'em'     => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
		$description = wp_kses( $raw_desc, $allowed );

		global $wpdb;
		$table       = BMF_Calendar_DB::appointments_table();
		$current_uid = get_current_user_id();

		if ( $id > 0 ) {
			// Update existing.
			$existing = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND is_deleted = 0", $id ),
				ARRAY_A
			);
			if ( ! $existing ) {
				wp_send_json_error( array( 'message' => 'Appointment not found' ), 404 );
			}
			if ( ! $this->can_manage_member( (int) $existing['member_id'], $existing['member_email'] ) ) {
				wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
			}

			$ok = $wpdb->update(
				$table,
				array(
					'member_id'    => $member_id ?: null,
					'member_email' => $email,
					'provider_id'  => $provider_id ?: null,
					'start_at'     => $start_at,
					'end_at'       => $end_at,
					'status'       => $status,
					'subject'      => $subject,
					'description'  => $description,
					'location'     => $location,
					'updated_by'   => $current_uid,
				),
				array( 'id' => $id ),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);

			if ( false === $ok ) {
				wp_send_json_error( array( 'message' => 'Update failed' ), 500 );
			}

			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );
		} else {
			// Insert.
			$ok = $wpdb->insert(
				$table,
				array(
					'member_id'    => $member_id ?: null,
					'member_email' => $email,
					'provider_id'  => $provider_id ?: null,
					'start_at'     => $start_at,
					'end_at'       => $end_at,
					'status'       => $status,
					'subject'      => $subject,
					'description'  => $description,
					'location'     => $location,
					'created_by'   => $current_uid,
					'created_at'   => current_time( 'mysql' ),
					'is_deleted'   => 0,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' )
			);

			if ( ! $ok ) {
				wp_send_json_error( array( 'message' => 'Insert failed' ), 500 );
			}

			$new_id = (int) $wpdb->insert_id;
			$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $new_id ), ARRAY_A );
		}

		if ( $row && isset( $row['status'] ) && 'confirmed' === $row['status'] ) {
			BMF_Calendar_Outlook::on_confirmed( $row );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $row['id'] ), ARRAY_A );
			BMF_Calendar_ULS_Bridge::on_confirmed( $row );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $row['id'] ), ARRAY_A );
		}

		wp_send_json_success( array( 'appointment' => $this->format_appointment( $row ) ) );
	}

	/**
	 * Soft-delete an appointment.
	 */
	public function delete_appointment() {
		$this->verify();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid id' ), 400 );
		}

		global $wpdb;
		$table = BMF_Calendar_DB::appointments_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND is_deleted = 0", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'Not found' ), 404 );
		}

		if ( ! $this->can_manage_member( (int) $row['member_id'], $row['member_email'] ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$ok = $wpdb->update(
			$table,
			array(
				'is_deleted' => 1,
				'updated_by' => get_current_user_id(),
				'status'     => 'cancelled',
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $ok ) {
			wp_send_json_error( array( 'message' => 'Delete failed' ), 500 );
		}

		BMF_Calendar_Outlook::on_cancelled( $row );
		BMF_Calendar_ULS_Bridge::on_cancelled( $row );

		wp_send_json_success( array( 'deleted' => 1, 'id' => $id ) );
	}

	/**
	 * Parse either UTC ISO or local datetime-local string into site-local MySQL datetime.
	 */
	private function parse_to_site_mysql( $utc_iso, $local_fallback ) {
		$utc_iso = trim( (string) $utc_iso );
		if ( $utc_iso !== '' ) {
			try {
				$dt = new DateTime( $utc_iso, new DateTimeZone( 'UTC' ) );
				$dt->setTimezone( wp_timezone() );
				return $dt->format( 'Y-m-d H:i:s' );
			} catch ( Exception $e ) {
				// fall through
			}
		}

		$local_fallback = trim( (string) $local_fallback );
		if ( $local_fallback === '' ) {
			return null;
		}

		// Accept HTML5 datetime-local "YYYY-MM-DDTHH:MM"
		$dt = date_create_from_format( 'Y-m-d\TH:i', $local_fallback, wp_timezone() );
		if ( ! $dt ) {
			// Also try with seconds
			$dt = date_create_from_format( 'Y-m-d\TH:i:s', $local_fallback, wp_timezone() );
		}
		if ( ! $dt ) {
			try {
				$dt = new DateTime( $local_fallback, wp_timezone() );
			} catch ( Exception $e ) {
				return null;
			}
		}
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Available slots for a provider (Calendly-style).
	 */
	public function get_slots() {
		$this->verify();

		$provider_id = isset( $_POST['provider_id'] ) ? absint( $_POST['provider_id'] ) : 0;
		$days        = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : BMF_Calendar_Availability::DEFAULT_DAYS;

		if ( ! $provider_id ) {
			wp_send_json_success( array( 'slots' => array() ) );
		}

		$slots = BMF_Calendar_Availability::get_slots( $provider_id, $days );
		wp_send_json_success( array( 'slots' => $slots ) );
	}

	/**
	 * Member requests a slot (or a general unassigned appointment).
	 */
	public function request_appointment() {
		$this->verify();

		$current     = wp_get_current_user();
		$provider_id = isset( $_POST['provider_id'] ) ? absint( $_POST['provider_id'] ) : 0;
		$subject     = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$notes       = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( $subject === '' ) {
			$subject = __( 'Appointment request', 'bmf-calendar' );
		}

		$start_utc = isset( $_POST['start_utc'] ) ? sanitize_text_field( wp_unslash( $_POST['start_utc'] ) ) : '';
		$end_utc   = isset( $_POST['end_utc'] ) ? sanitize_text_field( wp_unslash( $_POST['end_utc'] ) ) : '';
		$start_in  = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
		$end_in    = isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : '';

		$start_at = $this->parse_to_site_mysql( $start_utc, $start_in );
		$end_at   = $this->parse_to_site_mysql( $end_utc, $end_in );

		if ( ! $start_at ) {
			wp_send_json_error( array( 'message' => 'Start date/time is required' ), 400 );
		}

		$site_tz  = wp_timezone();
		$start_dt = new DateTime( $start_at, $site_tz );
		$end_dt   = $end_at ? new DateTime( $end_at, $site_tz ) : null;
		if ( ! $end_dt || $end_dt <= $start_dt ) {
			$end_dt = clone $start_dt;
			$end_dt->modify( '+' . BMF_Calendar_Availability::DEFAULT_DURATION . ' minutes' );
		}
		$end_at = $end_dt->format( 'Y-m-d H:i:s' );

		// If a provider is specified, reject slots that are no longer free.
		if ( $provider_id ) {
			$slots   = BMF_Calendar_Availability::get_slots( $provider_id, 21 );
			$matched = false;
			foreach ( $slots as $s ) {
				$state = isset( $s['state'] ) ? $s['state'] : 'open';
				if ( $s['start'] === $start_at && 'open' === $state ) {
					$matched = true;
					$end_at  = $s['end'];
					break;
				}
			}
			if ( ! $matched ) {
				wp_send_json_error( array( 'message' => 'That time is no longer available. Please pick another slot.' ), 409 );
			}
		}

		global $wpdb;
		$table = BMF_Calendar_DB::appointments_table();
		$ok    = $wpdb->insert(
			$table,
			array(
				'member_id'    => $current->ID,
				'member_email' => $current->user_email,
				'provider_id'  => $provider_id ?: null,
				'start_at'     => $start_at,
				'end_at'       => $end_at,
				'status'       => 'requested',
				'subject'      => $subject,
				'description'  => $notes,
				'created_by'   => $current->ID,
				'created_at'   => current_time( 'mysql' ),
				'is_deleted'   => 0,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' )
		);

		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => 'Could not create request' ), 500 );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $wpdb->insert_id ),
			ARRAY_A
		);

		BMF_Calendar_Mail::notify_new_request( $row, $current );

		wp_send_json_success( array( 'appointment' => $this->format_appointment( $row ) ) );
	}

	/**
	 * List availability rules for the current (or specified) provider.
	 */
	public function get_availability() {
		$this->verify();

		$provider_id = isset( $_POST['provider_id'] ) ? absint( $_POST['provider_id'] ) : get_current_user_id();
		if ( ! $this->can_edit_availability( $provider_id ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$display_tz = isset( $_POST['display_tz'] ) ? sanitize_text_field( wp_unslash( $_POST['display_tz'] ) ) : '';
		$rules      = BMF_Calendar_Availability::get_for_provider( $provider_id );
		$out        = array();
		foreach ( $rules as $rule ) {
			$out[] = BMF_Calendar_Availability::present_rule( $rule, $display_tz );
		}
		wp_send_json_success( array( 'rules' => $out ) );
	}

	/**
	 * Create / update an availability rule.
	 */
	public function save_availability() {
		$this->verify();

		$provider_id = isset( $_POST['provider_id'] ) ? absint( $_POST['provider_id'] ) : get_current_user_id();
		if ( ! $this->can_edit_availability( $provider_id ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$result = BMF_Calendar_Availability::save(
			array(
				'id'            => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
				'provider_id'   => $provider_id,
				'type'          => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'recurring',
				'day_of_week'   => isset( $_POST['day_of_week'] ) ? absint( $_POST['day_of_week'] ) : 0,
				'start_time'    => isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '',
				'end_time'      => isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '',
				'date_specific' => isset( $_POST['date_specific'] ) ? sanitize_text_field( wp_unslash( $_POST['date_specific'] ) ) : '',
				'is_available'  => isset( $_POST['is_available'] ) ? (int) $_POST['is_available'] : 1,
				'notes'         => isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '',
				'display_tz'    => isset( $_POST['display_tz'] ) ? sanitize_text_field( wp_unslash( $_POST['display_tz'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'rule' => $result ) );
	}

	/**
	 * Delete an availability rule.
	 */
	public function delete_availability() {
		$this->verify();

		$id          = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$provider_id = isset( $_POST['provider_id'] ) ? absint( $_POST['provider_id'] ) : get_current_user_id();

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'Invalid id' ), 400 );
		}
		if ( ! $this->can_edit_availability( $provider_id ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$ok = BMF_Calendar_Availability::delete( $id, $provider_id );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => 'Delete failed' ), 500 );
		}
		wp_send_json_success( array( 'deleted' => 1, 'id' => $id ) );
	}

	/**
	 * Lightweight provider list for the request UI.
	 */
	public function list_providers() {
		$this->verify();

		$exclude = array();
		if ( isset( $_POST['exclude'] ) ) {
			$exclude = BMF_Calendar_Provider::parse_exclude( wp_unslash( $_POST['exclude'] ) );
		}

		$ids  = BMF_Calendar_Provider::get_provider_ids();
		$list = array();
		foreach ( $ids as $id ) {
			if ( BMF_Calendar_Provider::is_excluded( $id, $exclude ) ) {
				continue;
			}
			$user = get_userdata( $id );
			if ( ! $user ) {
				continue;
			}
			$list[] = array(
				'id'   => (int) $id,
				'name' => $user->display_name ? $user->display_name : $user->user_login,
			);
		}

		wp_send_json_success( array( 'providers' => $list ) );
	}

	/**
	 * Provider inbox: requested appointments for this provider + unassigned.
	 */
	public function get_open_requests() {
		$this->verify();
		if ( ! BMF_Calendar_Provider::is_provider() && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$provider_id = get_current_user_id();
		global $wpdb;
		$table = BMF_Calendar_DB::appointments_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}`
				 WHERE is_deleted = 0
				   AND status = 'requested'
				   AND (provider_id = %d OR provider_id IS NULL OR provider_id = 0)
				 ORDER BY start_at ASC
				 LIMIT 100",
				$provider_id
			),
			ARRAY_A
		);

		$appts = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$item              = $this->format_appointment( $row );
				$member            = $row['member_id'] ? get_userdata( (int) $row['member_id'] ) : null;
				$item['member_name'] = $member ? ( $member->display_name ? $member->display_name : $member->user_login ) : $row['member_email'];
				$item['unassigned']  = empty( $row['provider_id'] );
				$appts[]             = $item;
			}
		}

		wp_send_json_success( array( 'appointments' => $appts ) );
	}

	/**
	 * Confirm / decline a request from the provider inbox.
	 */
	public function set_status() {
		$this->verify();
		if ( ! BMF_Calendar_Provider::is_provider() && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! $id || ! in_array( $status, array( 'confirmed', 'cancelled' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid request' ), 400 );
		}

		global $wpdb;
		$table = BMF_Calendar_DB::appointments_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND is_deleted = 0", $id ),
			ARRAY_A
		);
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'Not found' ), 404 );
		}

		$current = get_current_user_id();
		$owned   = empty( $row['provider_id'] ) || (int) $row['provider_id'] === $current;
		if ( ! $owned && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$update = array(
			'status'     => $status,
			'updated_by' => $current,
		);
		// Claiming an unassigned request on confirm.
		if ( 'confirmed' === $status && empty( $row['provider_id'] ) ) {
			$update['provider_id'] = $current;
		}

		$ok = $wpdb->update( $table, $update, array( 'id' => $id ) );
		if ( false === $ok ) {
			wp_send_json_error( array( 'message' => 'Update failed' ), 500 );
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );
		if ( $row && 'confirmed' === $status ) {
			BMF_Calendar_Outlook::on_confirmed( $row );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );
			BMF_Calendar_ULS_Bridge::on_confirmed( $row );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );
			BMF_Calendar_Mail::notify_member_decision( $row, 'confirmed' );
		} elseif ( $row && 'cancelled' === $status ) {
			BMF_Calendar_Outlook::on_cancelled( $row );
			BMF_Calendar_ULS_Bridge::on_cancelled( $row );
			BMF_Calendar_Mail::notify_member_decision( $row, 'cancelled' );
		}
		wp_send_json_success( array( 'appointment' => $this->format_appointment( $row ) ) );
	}

	/**
	 * Coverage board: each provider's hours + booked appointments for N days.
	 */
	public function get_coverage() {
		$this->verify();
		if ( ! BMF_Calendar_Provider::is_provider() && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$days    = isset( $_POST['days'] ) ? max( 1, min( 31, absint( $_POST['days'] ) ) ) : 30;
		$exclude = array();
		if ( isset( $_POST['exclude'] ) ) {
			$exclude = BMF_Calendar_Provider::parse_exclude( wp_unslash( $_POST['exclude'] ) );
		}

		$ids = BMF_Calendar_Provider::get_provider_ids();
		$out = array();

		foreach ( $ids as $pid ) {
			if ( BMF_Calendar_Provider::is_excluded( $pid, $exclude ) ) {
				continue;
			}
			$out[] = array(
				'id'      => (int) $pid,
				'name'    => self::user_display_name( $pid ),
				'windows' => BMF_Calendar_Availability::get_windows( $pid, $days ),
			);
		}

		wp_send_json_success( array( 'providers' => $out, 'days' => $days ) );
	}

	private function can_edit_availability( $provider_id ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return BMF_Calendar_Provider::is_provider() && (int) $provider_id === get_current_user_id();
	}
}
