<?php
/**
 * Provider availability + slot calculator.
 *
 * Recurring windows: type=recurring, day_of_week 1–7 (Mon–Sun), start_time, end_time.
 * Exceptions: type=exception, date_specific. is_available=0 blocks the whole day
 * (or a time range if start/end provided). is_available=1 adds a one-off window.
 *
 * @package BMF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Calendar_Availability {

	const DEFAULT_DURATION = 30; // minutes
	const DEFAULT_DAYS     = 14;

	/**
	 * List availability rows for a provider.
	 *
	 * @return array
	 */
	public static function get_for_provider( $provider_id ) {
		global $wpdb;
		$table = BMF_Calendar_DB::availability_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE provider_id = %d ORDER BY type ASC, day_of_week ASC, date_specific ASC, start_time ASC",
				(int) $provider_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Insert or update an availability row.
	 *
	 * @param array $data
	 * @return array|WP_Error
	 */
	public static function save( $data ) {
		global $wpdb;
		$table = BMF_Calendar_DB::availability_table();

		$id          = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$provider_id = isset( $data['provider_id'] ) ? absint( $data['provider_id'] ) : 0;
		$type        = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'recurring';
		if ( ! in_array( $type, array( 'recurring', 'exception' ), true ) ) {
			$type = 'recurring';
		}

		$day       = isset( $data['day_of_week'] ) && $data['day_of_week'] !== '' ? absint( $data['day_of_week'] ) : null;
		$start     = isset( $data['start_time'] ) ? self::normalize_time( $data['start_time'] ) : null;
		$end       = isset( $data['end_time'] ) ? self::normalize_time( $data['end_time'] ) : null;
		$date      = isset( $data['date_specific'] ) ? self::normalize_date( $data['date_specific'] ) : null;
		$available = isset( $data['is_available'] ) ? (int) (bool) $data['is_available'] : 1;
		$notes     = isset( $data['notes'] ) ? sanitize_text_field( $data['notes'] ) : '';

		if ( ! $provider_id ) {
			return new WP_Error( 'invalid', 'Provider is required' );
		}

		if ( 'recurring' === $type ) {
			if ( ! $day || $day < 1 || $day > 7 ) {
				return new WP_Error( 'invalid', 'Day of week is required (1–7, Monday–Sunday)' );
			}
			if ( ! $start || ! $end ) {
				return new WP_Error( 'invalid', 'Start and end times are required' );
			}
			$date = null;

			$display_tz = isset( $data['display_tz'] ) ? sanitize_text_field( $data['display_tz'] ) : '';
			if ( $display_tz && self::safe_timezone( $display_tz ) ) {
				$windows = self::localize_incoming_window( $day, $start, $end, $display_tz );
				if ( empty( $windows ) ) {
					return new WP_Error( 'invalid', 'Could not convert those hours to site time' );
				}
				$first = array_shift( $windows );
				$day   = $first['day_of_week'];
				$start = $first['start_time'];
				$end   = $first['end_time'];
				// Extra pieces (midnight split) are inserted after the primary row.
				$data['_extra_windows'] = $windows;
			}
		} else {
			if ( ! $date ) {
				return new WP_Error( 'invalid', 'Date is required for an exception' );
			}
			$day = null;
		}

		$row = array(
			'provider_id'   => $provider_id,
			'type'          => $type,
			'day_of_week'   => $day,
			'start_time'    => $start,
			'end_time'      => $end,
			'date_specific' => $date,
			'is_available'  => $available,
			'notes'         => $notes,
		);
		$fmt = array( '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' );

		if ( $id ) {
			$ok = $wpdb->update( $table, $row, array( 'id' => $id ), $fmt, array( '%d' ) );
			if ( false === $ok ) {
				return new WP_Error( 'db', 'Update failed' );
			}
		} else {
			$ok = $wpdb->insert( $table, $row, $fmt );
			if ( ! $ok ) {
				return new WP_Error( 'db', 'Insert failed' );
			}
			$id = (int) $wpdb->insert_id;
		}

		if ( ! empty( $data['_extra_windows'] ) ) {
			foreach ( $data['_extra_windows'] as $extra ) {
				$wpdb->insert(
					$table,
					array(
						'provider_id'   => $provider_id,
						'type'          => 'recurring',
						'day_of_week'   => $extra['day_of_week'],
						'start_time'    => $extra['start_time'],
						'end_time'      => $extra['end_time'],
						'date_specific' => null,
						'is_available'  => $available,
						'notes'         => $notes,
					),
					$fmt
				);
			}
		}

		$saved = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $saved ? $saved : new WP_Error( 'db', 'Could not reload row' );
	}

	/**
	 * Delete an availability row.
	 */
	public static function delete( $id, $provider_id = 0 ) {
		global $wpdb;
		$table = BMF_Calendar_DB::availability_table();
		$where = array( 'id' => absint( $id ) );
		$fmt   = array( '%d' );
		if ( $provider_id ) {
			$where['provider_id'] = absint( $provider_id );
			$fmt[]                = '%d';
		}
		return false !== $wpdb->delete( $table, $where, $fmt );
	}

	/**
	 * Availability windows (not subtracted by bookings) for the next N days.
	 *
	 * @return array[] { date, date_ts, start, end, start_ts, end_ts }
	 */
	public static function get_windows( $provider_id, $days = 7 ) {
		$provider_id = (int) $provider_id;
		$days        = max( 1, min( 31, (int) $days ) );
		$rules       = self::get_for_provider( $provider_id );
		if ( ! $provider_id || empty( $rules ) ) {
			return array();
		}

		$tz    = wp_timezone();
		$now   = new DateTime( 'now', $tz );
		$out   = array();
		$fmt_t = get_option( 'time_format', 'g:i a' );
		$fmt_d = get_option( 'date_format', 'M j, Y' );

		for ( $i = 0; $i < $days; $i++ ) {
			$day = clone $now;
			$day->setTime( 0, 0, 0 );
			if ( $i > 0 ) {
				$day->modify( '+' . $i . ' days' );
			}
			$dow      = (int) $day->format( 'N' );
			$date_str = $day->format( 'Y-m-d' );
			foreach ( self::windows_for_date( $rules, $dow, $date_str ) as $win ) {
				$start = DateTime::createFromFormat( 'Y-m-d H:i:s', $date_str . ' ' . $win['start'], $tz );
				$end   = DateTime::createFromFormat( 'Y-m-d H:i:s', $date_str . ' ' . $win['end'], $tz );
				if ( ! $start || ! $end ) {
					continue;
				}
				$out[] = array(
					'date'      => $date_str,
					'date_fmt'  => wp_date( $fmt_d, $start->getTimestamp() ),
					'start'     => $start->format( 'Y-m-d H:i:s' ),
					'end'       => $end->format( 'Y-m-d H:i:s' ),
					'start_ts'  => $start->getTimestamp(),
					'end_ts'    => $end->getTimestamp(),
					'start_fmt' => wp_date( $fmt_t, $start->getTimestamp() ),
					'end_fmt'   => wp_date( $fmt_t, $end->getTimestamp() ),
				);
			}
		}
		return $out;
	}

	/**
	 * Generate bookable slots for a provider over the next N days.
	 *
	 * @param int $provider_id
	 * @param int $days
	 * @param int $duration_min
	 * @return array List of slots: { start, end, start_ts, end_ts, start_fmt, date }
	 */
	public static function get_slots( $provider_id, $days = null, $duration_min = null ) {
		$provider_id  = (int) $provider_id;
		$days         = $days ? max( 1, min( 60, (int) $days ) ) : self::DEFAULT_DAYS;
		$duration_min = $duration_min ? max( 15, (int) $duration_min ) : self::DEFAULT_DURATION;

		if ( ! $provider_id ) {
			return array();
		}

		$rules = self::get_for_provider( $provider_id );
		if ( empty( $rules ) ) {
			return array();
		}

		$tz    = wp_timezone();
		$now   = new DateTime( 'now', $tz );
		$busy  = self::get_busy_ranges( $provider_id, $now, $days );
		$slots = array();
		$fmt   = trim( get_option( 'time_format', 'g:i a' ) );

		for ( $i = 0; $i < $days; $i++ ) {
			$day = clone $now;
			$day->setTime( 0, 0, 0 );
			if ( $i > 0 ) {
				$day->modify( '+' . $i . ' days' );
			}
			$dow      = (int) $day->format( 'N' ); // 1=Mon … 7=Sun
			$date_str = $day->format( 'Y-m-d' );

			$windows = self::windows_for_date( $rules, $dow, $date_str );
			foreach ( $windows as $win ) {
				$cursor = DateTime::createFromFormat( 'Y-m-d H:i:s', $date_str . ' ' . $win['start'], $tz );
				$end    = DateTime::createFromFormat( 'Y-m-d H:i:s', $date_str . ' ' . $win['end'], $tz );
				if ( ! $cursor || ! $end ) {
					continue;
				}
				while ( $cursor < $end ) {
					$slot_end = clone $cursor;
					$slot_end->modify( '+' . $duration_min . ' minutes' );
					if ( $slot_end > $end ) {
						break;
					}
					// Skip past slots.
					if ( $cursor <= $now ) {
						$cursor = $slot_end;
						continue;
					}
					$hit = self::busy_hit( $cursor, $slot_end, $busy );
					$slot = array(
						'start'     => $cursor->format( 'Y-m-d H:i:s' ),
						'end'       => $slot_end->format( 'Y-m-d H:i:s' ),
						'start_ts'  => $cursor->getTimestamp(),
						'end_ts'    => $slot_end->getTimestamp(),
						'start_fmt' => wp_date( $fmt, $cursor->getTimestamp() ),
						'date'      => $date_str,
						'date_fmt'  => wp_date( get_option( 'date_format', 'M j, Y' ), $cursor->getTimestamp() ),
						'state'     => $hit ? $hit['state'] : 'open',
						'mine'      => $hit ? ! empty( $hit['mine'] ) : false,
					);
					// Open slots always returned. The viewer's own holds
					// (requested or confirmed) are returned so chips can update.
					if ( 'open' === $slot['state'] || ! empty( $slot['mine'] ) ) {
						$slots[] = $slot;
					}
					$cursor = $slot_end;
				}
			}
		}

		return $slots;
	}

	/**
	 * Build available time windows for a given date.
	 */
	private static function windows_for_date( $rules, $dow, $date_str ) {
		$windows = array();
		$blocked = false;

		foreach ( $rules as $r ) {
			if ( 'exception' === $r['type'] && $r['date_specific'] === $date_str && ! (int) $r['is_available'] ) {
				// Full-day block if no times; otherwise handled as a subtract later.
				if ( empty( $r['start_time'] ) && empty( $r['end_time'] ) ) {
					$blocked = true;
				}
			}
		}
		if ( $blocked ) {
			return array();
		}

		foreach ( $rules as $r ) {
			if ( 'recurring' === $r['type'] && (int) $r['day_of_week'] === $dow && (int) $r['is_available'] ) {
				$windows[] = array(
					'start' => self::normalize_time( $r['start_time'] ),
					'end'   => self::normalize_time( $r['end_time'] ),
				);
			}
			if ( 'exception' === $r['type'] && $r['date_specific'] === $date_str && (int) $r['is_available'] && $r['start_time'] && $r['end_time'] ) {
				$windows[] = array(
					'start' => self::normalize_time( $r['start_time'] ),
					'end'   => self::normalize_time( $r['end_time'] ),
				);
			}
		}

		// Subtract partial-day blocks.
		$filtered = array();
		foreach ( $windows as $win ) {
			$pieces = array( $win );
			foreach ( $rules as $r ) {
				if ( 'exception' !== $r['type'] || $r['date_specific'] !== $date_str || (int) $r['is_available'] ) {
					continue;
				}
				if ( empty( $r['start_time'] ) || empty( $r['end_time'] ) ) {
					continue;
				}
				$block_s = self::normalize_time( $r['start_time'] );
				$block_e = self::normalize_time( $r['end_time'] );
				$next    = array();
				foreach ( $pieces as $p ) {
					$next = array_merge( $next, self::subtract_range( $p['start'], $p['end'], $block_s, $block_e ) );
				}
				$pieces = $next;
			}
			$filtered = array_merge( $filtered, $pieces );
		}

		return $filtered;
	}

	private static function subtract_range( $start, $end, $block_s, $block_e ) {
		// No overlap.
		if ( $block_e <= $start || $block_s >= $end ) {
			return array( array( 'start' => $start, 'end' => $end ) );
		}
		$out = array();
		if ( $block_s > $start ) {
			$out[] = array( 'start' => $start, 'end' => $block_s );
		}
		if ( $block_e < $end ) {
			$out[] = array( 'start' => $block_e, 'end' => $end );
		}
		return $out;
	}

	/**
	 * Appointments that hold a slot: requested, confirmed, completed, no_show.
	 * Requested is intentionally treated as busy so two members cannot claim
	 * the same time while a provider is still reviewing.
	 *
	 * @return array[] {start DateTime, end DateTime, state, mine, start_key}
	 */
	private static function get_busy_ranges( $provider_id, DateTime $from, $days ) {
		global $wpdb;
		$table   = BMF_Calendar_DB::appointments_table();
		$tz      = wp_timezone();
		$to      = clone $from;
		$to->modify( '+' . (int) $days . ' days' );
		$viewer  = get_current_user_id();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT start_at, end_at, status, member_id, member_email
				 FROM {$table}
				 WHERE provider_id = %d
				   AND is_deleted = 0
				   AND status IN ('requested','confirmed','completed','no_show')
				   AND start_at < %s
				   AND start_at >= %s",
				$provider_id,
				$to->format( 'Y-m-d H:i:s' ),
				$from->format( 'Y-m-d 00:00:00' )
			),
			ARRAY_A
		);

		$busy = array();
		if ( ! is_array( $rows ) ) {
			return $busy;
		}

		$viewer_email = '';
		if ( $viewer ) {
			$u = get_userdata( $viewer );
			$viewer_email = $u ? strtolower( $u->user_email ) : '';
		}

		foreach ( $rows as $r ) {
			$s = self::parse_site_dt( $r['start_at'], $tz );
			if ( ! $s ) {
				continue;
			}
			$e = self::parse_site_dt( $r['end_at'], $tz );
			if ( ! $e || $e <= $s ) {
				$e = clone $s;
				$e->modify( '+' . self::DEFAULT_DURATION . ' minutes' );
			}
			$mine = ( $viewer && (int) $r['member_id'] === $viewer )
				|| ( $viewer_email && strtolower( (string) $r['member_email'] ) === $viewer_email );
			$busy[] = array(
				'start'     => $s,
				'end'       => $e,
				'state'     => $r['status'] ? $r['status'] : 'busy',
				'mine'      => $mine,
				'start_key' => $s->format( 'Y-m-d H:i' ),
			);
		}
		return $busy;
	}

	/**
	 * @return array|null {state, mine}
	 */
	private static function busy_hit( DateTime $start, DateTime $end, $busy ) {
		$key = $start->format( 'Y-m-d H:i' );
		foreach ( $busy as $b ) {
			if ( ! empty( $b['start_key'] ) && $b['start_key'] === $key ) {
				return $b;
			}
			if ( $start < $b['end'] && $end > $b['start'] ) {
				return $b;
			}
		}
		return null;
	}

	private static function parse_site_dt( $value, DateTimeZone $tz ) {
		$value = trim( (string) $value );
		if ( $value === '' || $value === '0000-00-00 00:00:00' ) {
			return null;
		}
		$value = substr( $value, 0, 19 );
		$dt    = DateTime::createFromFormat( 'Y-m-d H:i:s', $value, $tz );
		if ( $dt instanceof DateTime ) {
			return $dt;
		}
		try {
			return new DateTime( $value, $tz );
		} catch ( Exception $e ) {
			return null;
		}
	}

	private static function normalize_time( $t ) {
		$t = trim( (string) $t );
		if ( $t === '' ) {
			return null;
		}
		// Accept HH:MM or HH:MM:SS
		if ( preg_match( '/^\d{2}:\d{2}$/', $t ) ) {
			return $t . ':00';
		}
		if ( preg_match( '/^\d{2}:\d{2}:\d{2}$/', $t ) ) {
			return $t;
		}
		return null;
	}

	private static function normalize_date( $d ) {
		$d = trim( (string) $d );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
			return $d;
		}
		return null;
	}

	/**
	 * Convert a weekday + wall clock in $from_tz to site timezone.
	 *
	 * @return array{day_of_week:int,time:string,dt:DateTime}|null
	 */
	public static function local_wall_to_site( $dow, $time, $from_tz ) {
		$dow  = (int) $dow;
		$norm = self::normalize_time( $time );
		if ( $dow < 1 || $dow > 7 || ! $norm ) {
			return null;
		}
		$from_tz = self::safe_timezone( $from_tz );
		if ( ! $from_tz ) {
			$from_tz = wp_timezone();
		}

		$now = new DateTime( 'now', $from_tz );
		$n   = (int) $now->format( 'N' );
		$dt  = clone $now;
		$dt->modify( '-' . ( $n - 1 ) . ' days' ); // Monday this week in from_tz
		$dt->modify( '+' . ( $dow - 1 ) . ' days' );
		$parts = explode( ':', $norm );
		$dt->setTime( (int) $parts[0], (int) $parts[1], 0 );
		$dt->setTimezone( wp_timezone() );

		return array(
			'day_of_week' => (int) $dt->format( 'N' ),
			'time'        => $dt->format( 'H:i:s' ),
			'dt'          => $dt,
		);
	}

	/**
	 * Convert a stored site weekday + time back to a display timezone.
	 *
	 * @return array{day_of_week:int,time:string}
	 */
	public static function site_wall_to_local( $dow, $time, $to_tz ) {
		$to_tz = self::safe_timezone( $to_tz );
		if ( ! $to_tz ) {
			return array(
				'day_of_week' => (int) $dow,
				'time'        => self::normalize_time( $time ),
			);
		}
		$converted = self::local_wall_to_site( $dow, $time, wp_timezone()->getName() );
		if ( ! $converted ) {
			return array(
				'day_of_week' => (int) $dow,
				'time'        => self::normalize_time( $time ),
			);
		}
		$dt = clone $converted['dt'];
		$dt->setTimezone( $to_tz );
		return array(
			'day_of_week' => (int) $dt->format( 'N' ),
			'time'        => $dt->format( 'H:i:s' ),
		);
	}

	/**
	 * Attach display_* fields for the viewer timezone.
	 */
	public static function present_rule( $row, $display_tz = '' ) {
		if ( ! is_array( $row ) ) {
			return $row;
		}
		$row['display_day_of_week'] = isset( $row['day_of_week'] ) ? (int) $row['day_of_week'] : null;
		$row['display_start_time']  = isset( $row['start_time'] ) ? $row['start_time'] : null;
		$row['display_end_time']    = isset( $row['end_time'] ) ? $row['end_time'] : null;
		$row['display_tz']          = $display_tz;

		if ( 'recurring' === $row['type'] && $display_tz ) {
			$s = self::site_wall_to_local( $row['day_of_week'], $row['start_time'], $display_tz );
			$e = self::site_wall_to_local( $row['day_of_week'], $row['end_time'], $display_tz );
			$row['display_day_of_week'] = $s['day_of_week'];
			$row['display_start_time']  = $s['time'];
			$row['display_end_time']    = $e['time'];
		}
		return $row;
	}

	/**
	 * Convert an incoming recurring window from viewer TZ → site TZ.
	 * May return two windows if the converted range crosses midnight.
	 *
	 * @return array[]
	 */
	public static function localize_incoming_window( $dow, $start, $end, $display_tz ) {
		$start_c = self::local_wall_to_site( $dow, $start, $display_tz );
		$end_c   = self::local_wall_to_site( $dow, $end, $display_tz );
		if ( ! $start_c || ! $end_c ) {
			return array();
		}

		$windows = array();
		if ( $start_c['day_of_week'] === $end_c['day_of_week'] && $end_c['time'] > $start_c['time'] ) {
			$windows[] = array(
				'day_of_week' => $start_c['day_of_week'],
				'start_time'  => $start_c['time'],
				'end_time'    => $end_c['time'],
			);
			return $windows;
		}

		// Crossed midnight in site TZ — split.
		$windows[] = array(
			'day_of_week' => $start_c['day_of_week'],
			'start_time'  => $start_c['time'],
			'end_time'    => '23:59:00',
		);
		$windows[] = array(
			'day_of_week' => $end_c['day_of_week'],
			'start_time'  => '00:00:00',
			'end_time'    => $end_c['time'],
		);
		return $windows;
	}

	public static function safe_timezone( $name ) {
		$name = trim( (string) $name );
		if ( $name === '' ) {
			return null;
		}
		try {
			return new DateTimeZone( $name );
		} catch ( Exception $e ) {
			return null;
		}
	}
}
