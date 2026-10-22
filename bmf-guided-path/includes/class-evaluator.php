<?php
/**
 * Evaluate ordered steps against the current user's WP Fusion tags.
 *
 * @package BMF_Guided_Path
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Guided_Path_Evaluator {

	/**
	 * Return the first matching enabled step for a user, or null.
	 *
	 * @param int $user_id
	 * @return array|null Step row as associative array, or null.
	 */
	public static function get_current_step( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bm_workflow_steps';

		$steps = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE enabled = 1 ORDER BY sort_order ASC, id ASC",
			ARRAY_A
		);

		if ( empty( $steps ) ) {
			return null;
		}

		foreach ( $steps as $step ) {
			if ( self::step_matches( $step, $user_id ) ) {
				self::cache_state( $user_id, (int) $step['id'] );
				return $step;
			}
		}

		self::cache_state( $user_id, null );
		return null;
	}

	/**
	 * Check whether a step's conditions match the user's tags.
	 *
	 * @param array $step
	 * @param int   $user_id
	 * @return bool
	 */
	public static function step_matches( $step, $user_id ) {
		$conditions = json_decode( $step['conditions'] ?? '{}', true );
		if ( ! is_array( $conditions ) ) {
			return false;
		}

		// all_present: every listed tag must exist.
		if ( ! empty( $conditions['all_present'] ) && is_array( $conditions['all_present'] ) ) {
			foreach ( $conditions['all_present'] as $tag ) {
				if ( ! self::has_tag( $tag, $user_id ) ) {
					return false;
				}
			}
		}

		// any_present: at least one listed tag must exist (empty = ignore).
		if ( ! empty( $conditions['any_present'] ) && is_array( $conditions['any_present'] ) ) {
			$any = false;
			foreach ( $conditions['any_present'] as $tag ) {
				if ( self::has_tag( $tag, $user_id ) ) {
					$any = true;
					break;
				}
			}
			if ( ! $any ) {
				return false;
			}
		}

		// none_present: none of the listed tags may exist.
		if ( ! empty( $conditions['none_present'] ) && is_array( $conditions['none_present'] ) ) {
			foreach ( $conditions['none_present'] as $tag ) {
				if ( self::has_tag( $tag, $user_id ) ) {
					return false;
				}
			}
		}

		// days_since last activity (usermeta _breathermae_last_active).
		// Ignored when the condition keys are absent. If the user has no timestamp,
		// a days condition fails to match.
		if ( isset( $conditions['days_since_gt'] ) || isset( $conditions['days_since_lt'] ) ) {
			$days = self::days_since_last_active( $user_id );
			if ( $days === null ) {
				return false;
			}
			if ( isset( $conditions['days_since_gt'] ) && $days <= (int) $conditions['days_since_gt'] ) {
				return false;
			}
			if ( isset( $conditions['days_since_lt'] ) && $days >= (int) $conditions['days_since_lt'] ) {
				return false;
			}
		}

		// Suppress if the user recently viewed a results section (or ever, if days is 0).
		// Requires an event key. Days blank = ignore. Days 0 = suppress forever once any row exists.
		$event_key = trim( (string) ( $conditions['suppress_event_key'] ?? '' ) );
		$seen_days = array_key_exists( 'suppress_if_seen_days', $conditions ) ? (int) $conditions['suppress_if_seen_days'] : null;
		if ( $event_key !== '' && $seen_days !== null ) {
			if ( self::event_seen_within( $user_id, $event_key, $seen_days ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Wrapper around wpf_has_tag with graceful fallback.
	 *
	 * @param string $tag
	 * @param int    $user_id
	 * @return bool
	 */
	protected static function has_tag( $tag, $user_id ) {
		if ( function_exists( 'wpf_has_tag' ) ) {
			return (bool) wpf_has_tag( $tag, $user_id );
		}
		return false;
	}

	/**
	 * Days since last activity from usermeta _breathermae_last_active.
	 * Returns null when no timestamp is stored.
	 */
	protected static function days_since_last_active( $user_id ) {
		$raw = get_user_meta( $user_id, '_breathermae_last_active', true );
		if ( ! $raw ) {
			return null;
		}
		$ts = is_numeric( $raw ) ? (int) $raw : strtotime( $raw );
		if ( ! $ts ) {
			return null;
		}
		$diff = time() - $ts;
		return $diff < 0 ? 0 : (int) floor( $diff / DAY_IN_SECONDS );
	}

	/**
	 * True when uls_user_event_log has a matching event_key for this user.
	 * $days = 0 means any existing row suppresses. $days > 0 means last_updated within that many days.
	 */
	protected static function event_seen_within( $user_id, $event_key, $days ) {
		global $wpdb;
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}
		$table = 'uls_user_event_log';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT last_updated FROM {$table} WHERE user_email = %s AND event_key = %s LIMIT 1",
			$user->user_email,
			$event_key
		) );
		if ( ! $raw ) {
			return false;
		}
		if ( $days <= 0 ) {
			return true;
		}
		$ts = strtotime( $raw );
		if ( ! $ts ) {
			return false;
		}
		$age = (int) floor( ( time() - $ts ) / DAY_IN_SECONDS );
		return $age >= 0 && $age < $days;
	}

	/**
	 * Record (or refresh) an event row so a one-time step can suppress itself.
	 */
	public static function record_event( $user_id, $event_key ) {
		global $wpdb;
		$event_key = trim( (string) $event_key );
		if ( $event_key === '' ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}
		$table = 'uls_user_event_log';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_email = %s AND event_key = %s LIMIT 1",
			$user->user_email,
			$event_key
		) );
		$now = current_time( 'mysql' );
		if ( $existing ) {
			$wpdb->update(
				$table,
				array( 'event_value' => '1', 'last_updated' => $now ),
				array( 'id' => (int) $existing ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'user_email'   => $user->user_email,
					'event_key'    => $event_key,
					'event_value'  => '1',
					'last_updated' => $now,
				),
				array( '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Cache the current step id for the user.
	 *
	 * @param int      $user_id
	 * @param int|null $step_id
	 */
	protected static function cache_state( $user_id, $step_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bm_workflow_user_state';

		$wpdb->replace(
			$table,
			array(
				'user_id'         => $user_id,
				'current_step_id' => $step_id,
				'last_evaluated'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s' )
		);
	}
}
