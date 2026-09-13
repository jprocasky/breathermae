<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fitbit nights — state clock.
 *
 * Data sources, in order:
 * 1. Filter `bmf_wellbeing_fitbit_nights` → array of nights
 * 2. `uls_bm_fitbit_sleep_summary` (wp_user_id, date_of_sleep, minutes_asleep, efficiency)
 * 3. First leftover %fitbit% table with a date + sleep column
 *
 * Night shape:
 *   date (Y-m-d), sleep_hours, sleep_minutes, efficiency (0–100),
 *   resting_hr, steps
 */
class BMF_Wellbeing_Adapter_Fitbit {

	public static function load( int $user_id, string $email ): array {
		$cfg   = BMF_Wellbeing_Map::source( 'fitbit' );
		$empty = self::shell( $cfg );

		$nights = apply_filters( 'bmf_wellbeing_fitbit_nights', null, $user_id, $email );
		if ( ! is_array( $nights ) ) {
			$nights = self::load_from_tables( $user_id, $email );
		}
		$nights = self::normalize_nights( is_array( $nights ) ? $nights : [] );
		if ( ! $nights ) {
			return $empty;
		}

		usort(
			$nights,
			static function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		$last  = $nights[ count( $nights ) - 1 ];
		$fresh = BMF_Wellbeing_Freshness::evaluate( 'fitbit', $last['date'] );
		$week  = self::last_n_days( $nights, 7 );

		$avg_h = self::avg_field( $week, 'sleep_hours' );
		$avg_e = self::avg_field( $week, 'efficiency' );
		$last_h = $last['sleep_hours'];

		$scores = [
			[
				'code'      => 'last_night',
				'label'     => 'Last night (hrs)',
				'value'     => $last_h !== null ? round( $last_h, 1 ) : null,
				'scale'     => 8,
				'direction' => 'high_better',
				'band'      => BMF_Wellbeing_Freshness::band( $last_h, 'high_better', 8 ),
			],
			[
				'code'      => 'sleep_7d',
				'label'     => '7-night avg (hrs)',
				'value'     => $avg_h !== null ? round( $avg_h, 1 ) : null,
				'scale'     => 8,
				'direction' => 'high_better',
				'band'      => BMF_Wellbeing_Freshness::band( $avg_h, 'high_better', 8 ),
			],
			[
				'code'      => 'efficiency_7d',
				'label'     => '7-night efficiency',
				'value'     => $avg_e !== null ? round( $avg_e, 0 ) : null,
				'scale'     => 100,
				'direction' => 'high_better',
				'band'      => BMF_Wellbeing_Freshness::band( $avg_e, 'high_better', 100 ),
			],
		];

		$short = 0;
		foreach ( $week as $n ) {
			if ( $n['sleep_hours'] !== null && (float) $n['sleep_hours'] < 6 ) {
				$short++;
			}
		}

		$history = [];
		$slice   = array_slice( $nights, -14 );
		foreach ( $slice as $n ) {
			$hours = $n['sleep_hours'];
			$history[] = [
				'date'       => $n['date'],
				'sleep_hours'=> $hours,
				'sleep_pct'  => $hours !== null ? (int) min( 100, round( ( $hours / 8 ) * 100 ) ) : null,
				'efficiency' => $n['efficiency'],
			];
		}

		return array_merge( $empty, $fresh, [
			'present'     => true,
			'scores'      => $scores,
			'nights_7d'   => count( $week ),
			'short_nights'=> $short,
			'history'     => $history,
		] );
	}

	private static function last_n_days( array $nights, int $days ): array {
		if ( ! $nights ) {
			return [];
		}
		$end = end( $nights )['date'];
		$cut = gmdate( 'Y-m-d', strtotime( $end . ' UTC' ) - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
		return array_values(
			array_filter(
				$nights,
				static function ( $n ) use ( $cut ) {
					return $n['date'] >= $cut;
				}
			)
		);
	}

	private static function avg_field( array $rows, string $field ): ?float {
		$vals = [];
		foreach ( $rows as $r ) {
			if ( isset( $r[ $field ] ) && $r[ $field ] !== null ) {
				$vals[] = (float) $r[ $field ];
			}
		}
		if ( ! $vals ) {
			return null;
		}
		return array_sum( $vals ) / count( $vals );
	}

	private static function normalize_nights( array $raw ): array {
		$out = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$date = BMF_Wellbeing_Freshness::normalize_date( $row['date'] ?? $row['sleep_date'] ?? $row['night'] ?? $row['log_date'] ?? '' );
			if ( $date === '' ) {
				continue;
			}
			$mins  = self::num( $row['sleep_minutes'] ?? $row['minutes_asleep'] ?? $row['minutesAsleep'] ?? null );
			$hours = self::num( $row['sleep_hours'] ?? $row['hours'] ?? null );
			if ( $hours === null && $mins !== null ) {
				$hours = $mins / 60;
			}
			if ( $mins === null && $hours !== null ) {
				$mins = $hours * 60;
			}
			if ( isset( $out[ $date ] ) && ( $hours === null || ( $out[ $date ]['sleep_hours'] ?? 0 ) >= $hours ) ) {
				continue;
			}
			$out[ $date ] = [
				'date'          => $date,
				'sleep_hours'   => $hours,
				'sleep_minutes' => $mins,
				'efficiency'    => self::num( $row['efficiency'] ?? $row['sleep_efficiency'] ?? null ),
				'resting_hr'    => self::num( $row['resting_hr'] ?? $row['resting_heart_rate'] ?? $row['rhr'] ?? null ),
				'steps'         => self::num( $row['steps'] ?? null ),
			];
		}
		return array_values( $out );
	}

	private static function num( $v ): ?float {
		if ( $v === null || $v === '' || ! is_numeric( $v ) ) {
			return null;
		}
		return (float) $v;
	}

	private static function load_uls_summary( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}
		global $wpdb;
		$candidates = [
			'uls_bm_fitbit_sleep_summary',
			$wpdb->prefix . 'uls_bm_fitbit_sleep_summary',
			$wpdb->prefix . 'bm_fitbit_sleep_summary',
		];
		$table = null;
		foreach ( $candidates as $name ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
				continue;
			}
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
			if ( $found ) {
				$table = $name;
				break;
			}
		}
		if ( ! $table ) {
			return [];
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date_of_sleep AS date, minutes_asleep AS sleep_minutes, efficiency,
				        minutes_deep, minutes_rem, minutes_awake
				 FROM `{$table}`
				 WHERE wp_user_id = %d
				 ORDER BY date_of_sleep DESC, id DESC
				 LIMIT 30",
				$user_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	private static function load_from_tables( int $user_id, string $email ): array {
		$pinned = self::load_uls_summary( $user_id );
		if ( $pinned ) {
			return $pinned;
		}
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES LIKE \'%fitbit%\'' );
		if ( ! $tables ) {
			return [];
		}
		foreach ( $tables as $table ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table ) ) {
				continue;
			}
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
			if ( ! $cols ) {
				continue;
			}
			$map = self::map_columns( $cols );
			if ( ! $map['date'] || ( ! $map['minutes'] && ! $map['hours'] && ! $map['efficiency'] ) ) {
				continue;
			}
			$user_col = $map['user_id'];
			$email_col = $map['email'];
			$where = '1=1';
			$args  = [];
			if ( $user_col && $user_id > 0 ) {
				$where .= " AND `{$user_col}` = %d";
				$args[] = $user_id;
			} elseif ( $email_col && $email !== '' ) {
				$where .= " AND `{$email_col}` = %s";
				$args[] = $email;
			} else {
				continue;
			}
			$select = [ "`{$map['date']}` AS date" ];
			if ( $map['minutes'] ) {
				$select[] = "`{$map['minutes']}` AS sleep_minutes";
			}
			if ( $map['hours'] ) {
				$select[] = "`{$map['hours']}` AS sleep_hours";
			}
			if ( $map['efficiency'] ) {
				$select[] = "`{$map['efficiency']}` AS efficiency";
			}
			if ( $map['rhr'] ) {
				$select[] = "`{$map['rhr']}` AS resting_hr";
			}
			if ( $map['steps'] ) {
				$select[] = "`{$map['steps']}` AS steps";
			}
			$sql = "SELECT " . implode( ', ', $select ) . " FROM `{$table}` WHERE {$where} ORDER BY `{$map['date']}` DESC LIMIT 30";
			$rows = $args
				? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A )
				: $wpdb->get_results( $sql, ARRAY_A );
			if ( $rows ) {
				return $rows;
			}
		}
		return [];
	}

	private static function map_columns( array $cols ): array {
		$lc = [];
		foreach ( $cols as $c ) {
			$lc[ strtolower( (string) $c ) ] = (string) $c;
		}
		$pick = static function ( array $cands ) use ( $lc ) {
			foreach ( $cands as $c ) {
				if ( isset( $lc[ $c ] ) ) {
					return $lc[ $c ];
				}
			}
			return null;
		};
		return [
			'date'       => $pick( [ 'date_of_sleep', 'sleep_date', 'log_date', 'night_date', 'date', 'day', 'results_date' ] ),
			'minutes'    => $pick( [ 'minutes_asleep', 'sleep_minutes', 'minutesasleep', 'asleep_minutes' ] ),
			'hours'      => $pick( [ 'sleep_hours', 'hours_asleep', 'hours' ] ),
			'efficiency' => $pick( [ 'efficiency', 'sleep_efficiency' ] ),
			'rhr'        => $pick( [ 'resting_hr', 'resting_heart_rate', 'rhr' ] ),
			'steps'      => $pick( [ 'steps' ] ),
			'user_id'    => $pick( [ 'user_id', 'wp_user_id', 'member_id' ] ),
			'email'      => $pick( [ 'user_email', 'email' ] ),
		];
	}

	private static function shell( array $cfg ): array {
		return [
			'key'          => 'fitbit',
			'label'        => $cfg['label'] ?? 'Fitbit',
			'enabled'      => ! empty( $cfg['enabled'] ),
			'clock'        => $cfg['clock'] ?? 'state',
			'present'      => false,
			'status'       => 'missing',
			'date'         => '',
			'age_days'     => null,
			'scores'       => [],
			'nights_7d'    => 0,
			'short_nights' => 0,
			'history'      => [],
		];
	}
}
