<?php
/**
 * Appointment notification emails.
 *
 * @package BMF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Calendar_Mail {

	public static function subject_prefix() {
		$custom = trim( (string) get_option( 'bmf_calendar_email_subject_prefix', '' ) );
		if ( $custom !== '' ) {
			return $custom;
		}
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Site-local appointment time with timezone label.
	 */
	public static function format_when( $mysql ) {
		$mysql = substr( (string) $mysql, 0, 19 );
		if ( $mysql === '' ) {
			return '';
		}
		$tz = wp_timezone();
		$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $mysql, $tz );
		if ( ! $dt ) {
			try {
				$dt = new DateTime( $mysql, $tz );
			} catch ( Exception $e ) {
				return $mysql;
			}
		}
		$date = $dt->format( 'F j, Y' );
		$time = $dt->format( 'g:i a' );
		$zone = self::zone_label( $dt );
		return trim( $date . ' ' . $time . ' ' . $zone );
	}

	private static function zone_label( DateTime $dt ) {
		$abbr = $dt->format( 'T' );
		$name = $dt->getTimezone()->getName();
		$raw  = $abbr ? $abbr : $name;
		if ( preg_match( '/^([+\-])00:?00$/', $raw ) || 'Z' === $raw || 'GMT+0000' === $raw || 'GMT+00:00' === $raw ) {
			return 'UTC';
		}
		if ( preg_match( '/^GMT([+\-])(\d{1,2})/', $raw, $m ) ) {
			$h = (int) $m[2];
			return $h === 0 ? 'UTC' : 'GMT' . $m[1] . $h;
		}
		if ( 'UTC' === $name || 'Etc/UTC' === $name || 'Etc/GMT' === $name ) {
			return 'UTC';
		}
		if ( $abbr && $abbr !== $name && ! preg_match( '/^[+\-]/', $abbr ) ) {
			return $abbr;
		}
		return $name ? $name : 'UTC';
	}

	public static function notify_new_request( $row, $member ) {
		if ( empty( $row ) ) {
			return;
		}

		$assigned = ! empty( $row['provider_id'] );
		$to       = '';
		if ( $assigned ) {
			$prov = get_userdata( (int) $row['provider_id'] );
			$to   = $prov ? $prov->user_email : '';
		}
		if ( ! $to ) {
			$to = get_option( 'bmf_calendar_unassigned_email', '' );
		}
		if ( ! $to ) {
			$to = get_option( 'admin_email' );
		}
		if ( ! $to ) {
			return;
		}

		$name = self::member_name( $member, $row );
		$tag  = $assigned ? '' : __( 'Unassigned: ', 'bmf-calendar' );
		$subj = sprintf(
			'[%s] %s%s %s',
			self::subject_prefix(),
			$tag,
			__( 'New appointment request from', 'bmf-calendar' ),
			$name
		);

		$lines   = array();
		$lines[] = sprintf( __( '%s requested an appointment%s.', 'bmf-calendar' ), $name, $assigned ? '' : ' (no provider selected)' );
		$lines[] = '';
		$lines[] = __( 'When:', 'bmf-calendar' ) . ' ' . self::format_when( isset( $row['start_at'] ) ? $row['start_at'] : '' );
		if ( ! empty( $row['subject'] ) ) {
			$lines[] = __( 'Subject:', 'bmf-calendar' ) . ' ' . $row['subject'];
		}
		$lines[] = '';
		$inbox   = esc_url_raw( get_option( 'bmf_calendar_provider_inbox_url', '' ) );
		if ( $inbox ) {
			$lines[] = __( 'Review and confirm or decline this request:', 'bmf-calendar' );
		} else {
			$lines[] = __( 'Log in to your provider portal to confirm or decline this request.', 'bmf-calendar' );
		}

		self::send( $to, $subj, $lines, $inbox, __( 'Open requests', 'bmf-calendar' ) );
	}

	public static function notify_member_decision( $row, $decision ) {
		if ( empty( $row ) ) {
			return;
		}
		$to = '';
		if ( ! empty( $row['member_email'] ) ) {
			$to = $row['member_email'];
		} elseif ( ! empty( $row['member_id'] ) ) {
			$u  = get_userdata( (int) $row['member_id'] );
			$to = $u ? $u->user_email : '';
		}
		if ( ! $to ) {
			return;
		}

		$when    = self::format_when( isset( $row['start_at'] ) ? $row['start_at'] : '' );
		$subject = isset( $row['subject'] ) ? $row['subject'] : __( 'Appointment', 'bmf-calendar' );
		$link    = esc_url_raw( get_option( 'bmf_calendar_member_appointments_url', '' ) );
		$prefix  = self::subject_prefix();

		if ( 'confirmed' === $decision ) {
			$subj  = sprintf( '[%s] %s', $prefix, __( 'Your appointment is confirmed', 'bmf-calendar' ) );
			$lines = array(
				__( 'Your appointment request has been confirmed.', 'bmf-calendar' ),
				'',
				__( 'When:', 'bmf-calendar' ) . ' ' . $when,
				__( 'Subject:', 'bmf-calendar' ) . ' ' . $subject,
			);
			$cta = __( 'View my appointments', 'bmf-calendar' );
		} else {
			$subj  = sprintf( '[%s] %s', $prefix, __( 'Your appointment request was declined', 'bmf-calendar' ) );
			$lines = array(
				__( 'Your appointment request was declined. You can request another time from the appointments page.', 'bmf-calendar' ),
				'',
				__( 'Requested time:', 'bmf-calendar' ) . ' ' . $when,
				__( 'Subject:', 'bmf-calendar' ) . ' ' . $subject,
			);
			$cta = __( 'Request another time', 'bmf-calendar' );
		}

		self::send( $to, $subj, $lines, $link, $cta );
	}

	private static function member_name( $member, $row ) {
		if ( $member && ! empty( $member->display_name ) ) {
			return $member->display_name;
		}
		if ( $member && ! empty( $member->user_email ) ) {
			return $member->user_email;
		}
		if ( ! empty( $row['member_email'] ) ) {
			return $row['member_email'];
		}
		return __( 'A member', 'bmf-calendar' );
	}

	private static function send( $to, $subject, $lines, $cta_url = '', $cta_label = '' ) {
		$paras = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			if ( filter_var( $line, FILTER_VALIDATE_URL ) ) {
				$paras[] = '<p><a href="' . esc_url( $line ) . '">' . esc_html( $line ) . '</a></p>';
			} else {
				$paras[] = '<p>' . esc_html( $line ) . '</p>';
			}
		}
		if ( $cta_url && $cta_label ) {
			$paras[] = '<p><a href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_label ) . '</a></p>';
		}
		$html    = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;color:#1a202c">' . implode( '', $paras ) . '</div>';
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $to, $subject, $html, $headers );
	}
}
