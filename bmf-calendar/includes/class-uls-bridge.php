<?php
/**
 * Optional bridge to uls-members appointment reminders.
 *
 * ULS stores one reminder row per person (keyed by that user's email).
 * On BMF confirm we write two rows when possible: member + provider.
 *
 * @package BMF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Calendar_ULS_Bridge {

	public static function is_available() {
		global $wpdb;
		$table = self::table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	public static function enabled() {
		if ( get_option( 'bmf_calendar_uls_reminders', '1' ) !== '1' ) {
			return false;
		}
		return self::is_available();
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'uls_member_appointments';
	}

	/**
	 * Create dashboard reminders for member and provider.
	 */
	public static function on_confirmed( $appointment ) {
		if ( ! self::enabled() || empty( $appointment['id'] ) ) {
			return;
		}
		if ( ! empty( $appointment['uls_member_reminder_id'] ) && ! empty( $appointment['uls_provider_reminder_id'] ) ) {
			return;
		}

		$start = isset( $appointment['start_at'] ) ? $appointment['start_at'] : '';
		$end   = ! empty( $appointment['end_at'] ) ? $appointment['end_at'] : '';
		if ( $start && ! $end ) {
			try {
				$dt = new DateTime( $start, wp_timezone() );
				$dt->modify( '+30 minutes' );
				$end = $dt->format( 'Y-m-d H:i:s' );
			} catch ( Exception $e ) {
				$end = $start;
			}
		}

		$member_email = self::email_for_member( $appointment );
		$prov_email   = self::email_for_provider( $appointment );
		$member_name  = self::name_for_member( $appointment );
		$prov_name    = self::name_for_provider( $appointment );
		$topic        = ! empty( $appointment['subject'] ) ? $appointment['subject'] : __( 'Appointment', 'bmf-calendar' );

		$ids = array(
			'member'   => ! empty( $appointment['uls_member_reminder_id'] ) ? (int) $appointment['uls_member_reminder_id'] : 0,
			'provider' => ! empty( $appointment['uls_provider_reminder_id'] ) ? (int) $appointment['uls_provider_reminder_id'] : 0,
		);

		if ( $member_email && ! $ids['member'] ) {
			$ids['member'] = self::insert_reminder(
				$member_email,
				$start,
				$end,
				sprintf( __( 'Appointment with %s', 'bmf-calendar' ), $prov_name ? $prov_name : __( 'your provider', 'bmf-calendar' ) ),
				$topic,
				isset( $appointment['location'] ) ? $appointment['location'] : ''
			);
		}

		if ( $prov_email && $prov_email !== $member_email && ! $ids['provider'] ) {
			$ids['provider'] = self::insert_reminder(
				$prov_email,
				$start,
				$end,
				sprintf( __( 'Appointment with %s', 'bmf-calendar' ), $member_name ? $member_name : __( 'a member', 'bmf-calendar' ) ),
				$topic,
				isset( $appointment['location'] ) ? $appointment['location'] : ''
			);
		}

		global $wpdb;
		$wpdb->update(
			BMF_Calendar_DB::appointments_table(),
			array(
				'uls_member_reminder_id'   => $ids['member'] ? $ids['member'] : null,
				'uls_provider_reminder_id' => $ids['provider'] ? $ids['provider'] : null,
			),
			array( 'id' => (int) $appointment['id'] ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Soft-delete the paired ULS reminders when a BMF appointment is cancelled.
	 */
	public static function on_cancelled( $appointment ) {
		if ( ! self::is_available() ) {
			return;
		}
		$ids = array();
		if ( ! empty( $appointment['uls_member_reminder_id'] ) ) {
			$ids[] = (int) $appointment['uls_member_reminder_id'];
		}
		if ( ! empty( $appointment['uls_provider_reminder_id'] ) ) {
			$ids[] = (int) $appointment['uls_provider_reminder_id'];
		}
		if ( ! $ids ) {
			return;
		}

		global $wpdb;
		$table = self::table();
		foreach ( $ids as $id ) {
			$wpdb->update(
				$table,
				array(
					'is_deleted' => 1,
					'updated_by' => get_current_user_id(),
				),
				array( 'id' => $id ),
				array( '%d', '%d' ),
				array( '%d' )
			);
		}
	}

	private static function insert_reminder( $email, $start, $end, $subject, $description, $location ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		$ok   = $wpdb->insert(
			self::table(),
			array(
				'member_email'   => $email,
				'member_user_id' => $user ? (int) $user->ID : null,
				'start_at'       => $start,
				'end_at'         => $end ? $end : null,
				'subject'        => substr( $subject, 0, 200 ),
				'description'    => $description,
				'location'       => $location ? substr( $location, 0, 255 ) : null,
				'created_by'     => get_current_user_id(),
				'created_at'     => current_time( 'mysql' ),
				'is_deleted'     => 0,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	private static function email_for_member( $appt ) {
		if ( ! empty( $appt['member_email'] ) ) {
			return $appt['member_email'];
		}
		if ( ! empty( $appt['member_id'] ) ) {
			$u = get_userdata( (int) $appt['member_id'] );
			return $u ? $u->user_email : '';
		}
		return '';
	}

	private static function email_for_provider( $appt ) {
		if ( empty( $appt['provider_id'] ) ) {
			return '';
		}
		$u = get_userdata( (int) $appt['provider_id'] );
		return $u ? $u->user_email : '';
	}

	private static function name_for_member( $appt ) {
		if ( ! empty( $appt['member_id'] ) ) {
			$u = get_userdata( (int) $appt['member_id'] );
			if ( $u ) {
				return $u->display_name ? $u->display_name : $u->user_email;
			}
		}
		return ! empty( $appt['member_email'] ) ? $appt['member_email'] : '';
	}

	private static function name_for_provider( $appt ) {
		if ( empty( $appt['provider_id'] ) ) {
			return '';
		}
		$u = get_userdata( (int) $appt['provider_id'] );
		if ( ! $u ) {
			return '';
		}
		return $u->display_name ? $u->display_name : $u->user_email;
	}
}
