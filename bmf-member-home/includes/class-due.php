<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Member_Home_Due {

	/**
	 * @return array{status:string,label:string,date:string,age:?int}
	 */
	public static function for_card( array $card, string $email ): array {
		$cadence = max( 1, (int) ( $card['cadence'] ?? 90 ) );
		$date    = self::latest_date( (string) ( $card['source'] ?? '' ), $email );
		if ( $date === '' ) {
			return array(
				'status' => 'missing',
				'label'  => 'Not started',
				'date'   => '',
				'age'    => null,
			);
		}
		$age = self::age_days( $date );
		if ( $age === null ) {
			return array(
				'status' => 'missing',
				'label'  => 'Not started',
				'date'   => '',
				'age'    => null,
			);
		}
		if ( $age >= $cadence ) {
			return array(
				'status' => 'due',
				'label'  => 'Available',
				'date'   => $date,
				'age'    => $age,
			);
		}
		return array(
			'status' => 'ok',
			'label'  => 'Current',
			'date'   => $date,
			'age'    => $age,
		);
	}

	private static function latest_date( string $source, string $email ): string {
		if ( $email === '' || $source === '' ) {
			return '';
		}
		global $wpdb;
		if ( $source === 'keys' ) {
			return self::keys_date( $email );
		}
		if ( $source === 'biovoice' ) {
			return self::biovoice_date( $email );
		}
		$table = $wpdb->prefix . 'bm_rsi_results';
		$extra = '';
		if ( $source === 'bsi' ) {
			$table = $wpdb->prefix . 'bm_bsi_results';
		} elseif ( $source === 'pillars_rapid' ) {
			$table = $wpdb->prefix . 'bm_pillars_results';
			$extra = " AND series = 'rapid'";
		} elseif ( $source === 'pillars_full' ) {
			$table = $wpdb->prefix . 'bm_pillars_results';
			$extra = " AND ( series = 'full' OR series IS NULL OR series = '' )";
		} elseif ( $source !== 'rsi' ) {
			return '';
		}
		if ( ! self::table_exists( $table ) ) {
			return '';
		}
		$sql = "SELECT results_date FROM {$table} WHERE user_email = %s AND is_final = 1{$extra} ORDER BY results_date DESC, id DESC LIMIT 1";
		$raw = $wpdb->get_var( $wpdb->prepare( $sql, $email ) );
		return self::normalize_date( $raw );
	}

	private static function biovoice_date( string $email ): string {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return '';
		}
		$table = $wpdb->prefix . 'bm_biovoice_session_groups';
		if ( ! self::table_exists( $table ) ) {
			return '';
		}
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT 1",
			$user->ID
		) );
		return self::normalize_date( $raw );
	}

	private static function keys_date( string $email ): string {
		global $wpdb;
		$candidates = array( 'uls_key_essentials', $wpdb->prefix . 'uls_key_essentials' );
		foreach ( $candidates as $table ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT datetime FROM {$table} WHERE user_email = %s ORDER BY datetime DESC, id DESC LIMIT 1", $email ) );
			$date = self::normalize_date( $raw );
			if ( $date !== '' ) {
				return $date;
			}
		}
		return '';
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	private static function normalize_date( $raw ): string {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return '';
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $raw, $m ) ) {
			return $m[1];
		}
		$ts = strtotime( $raw );
		return $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}

	private static function age_days( string $date ): ?int {
		$a = strtotime( $date . ' UTC' );
		$b = strtotime( gmdate( 'Y-m-d' ) . ' UTC' );
		if ( ! $a || ! $b ) {
			return null;
		}
		return (int) floor( ( $b - $a ) / DAY_IN_SECONDS );
	}
}
