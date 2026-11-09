<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Member_Home_Due {

	/**
	 * @return array{status:string,label:string,date:string,utc:string,age:?int}
	 */
	public static function for_card( array $card, string $email ): array {
		$cadence = max( 1, (int) ( $card['cadence'] ?? 90 ) );
		$utc     = self::latest_utc( (string) ( $card['source'] ?? '' ), $email );
		$date    = self::date_from_utc( $utc );
		if ( $date === '' ) {
			return array(
				'status' => 'missing',
				'label'  => 'Not started',
				'date'   => '',
				'utc'    => '',
				'age'    => null,
			);
		}
		$age = self::age_days( $date );
		if ( $age === null ) {
			return array(
				'status' => 'missing',
				'label'  => 'Not started',
				'date'   => '',
				'utc'    => '',
				'age'    => null,
			);
		}
		$base = array(
			'date' => $date,
			'utc'  => $utc,
			'age'  => $age,
		);
		if ( $age >= $cadence ) {
			return $base + array(
				'status' => 'due',
				'label'  => 'Available',
			);
		}
		return $base + array(
			'status' => 'ok',
			'label'  => 'Current',
		);
	}

	private static function latest_utc( string $source, string $email ): string {
		if ( $email === '' || $source === '' ) {
			return '';
		}
		global $wpdb;
		if ( $source === 'keys' ) {
			return self::keys_utc( $email );
		}
		if ( $source === 'biovoice' ) {
			return self::biovoice_utc( $email );
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
		$sql = "SELECT COALESCE(updated_at, results_date) FROM {$table} WHERE user_email = %s AND is_final = 1{$extra} ORDER BY COALESCE(updated_at, results_date) DESC, id DESC LIMIT 1";
		$raw = $wpdb->get_var( $wpdb->prepare( $sql, $email ) );
		return self::to_iso( $raw );
	}

	private static function latest_date( string $source, string $email ): string {
		return self::date_from_utc( self::latest_utc( $source, $email ) );
	}

	private static function biovoice_utc( string $email ): string {
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
		return self::to_iso( $raw );
	}

	private static function keys_utc( string $email ): string {
		global $wpdb;
		$candidates = array( 'uls_key_essentials', $wpdb->prefix . 'uls_key_essentials' );
		foreach ( $candidates as $table ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT datetime FROM {$table} WHERE user_email = %s ORDER BY datetime DESC, id DESC LIMIT 1", $email ) );
			$iso = self::to_iso( $raw );
			if ( $iso !== '' ) {
				return $iso;
			}
		}
		return '';
	}

	private static function biovoice_date( string $email ): string {
		return self::date_from_utc( self::biovoice_utc( $email ) );
	}

	private static function keys_date( string $email ): string {
		return self::date_from_utc( self::keys_utc( $email ) );
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	private static function normalize_date( $raw ): string {
		return self::date_from_utc( self::to_iso( $raw ) );
	}

	/**
	 * Datetime values become UTC ISO (…Z) so the browser can shift to local.
	 * Date-only values stay YYYY-MM-DD so they are not shifted.
	 */
	private static function to_iso( $raw ): string {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return '';
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $raw, $m ) ) {
			return $m[1] . 'T' . $m[2] . 'Z';
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $raw, $m ) ) {
			return $m[1];
		}
		$ts = strtotime( $raw );
		return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : '';
	}

	private static function date_from_utc( string $iso ): string {
		if ( $iso === '' ) {
			return '';
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $iso, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * True when the comma-separated required/missing lists are satisfied (any-logic).
	 * Empty lists impose no constraint. Returns true when both are empty.
	 */
	public static function tags_allow( string $required_csv, string $missing_csv, int $user_id ): bool {
		$required = self::parse_tags( $required_csv );
		$missing  = self::parse_tags( $missing_csv );
		if ( ! $required && ! $missing ) {
			return true;
		}
		if ( $user_id <= 0 || ! function_exists( 'wpf_has_tag' ) ) {
			return false;
		}
		if ( $required ) {
			$has_any = false;
			foreach ( $required as $tag ) {
				if ( wpf_has_tag( $tag, $user_id ) ) {
					$has_any = true;
					break;
				}
			}
			if ( ! $has_any ) {
				return false;
			}
		}
		if ( $missing ) {
			$missing_any = false;
			foreach ( $missing as $tag ) {
				if ( ! wpf_has_tag( $tag, $user_id ) ) {
					$missing_any = true;
					break;
				}
			}
			if ( ! $missing_any ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * All finalized result dates for a source, ascending.
	 *
	 * @return string[] YYYY-MM-DD
	 */
	public static function all_dates( string $source, string $email ): array {
		if ( $email === '' || $source === '' ) {
			return array();
		}
		global $wpdb;
		if ( $source === 'keys' ) {
			return self::keys_all_dates( $email );
		}
		if ( $source === 'biovoice' ) {
			return self::biovoice_all_dates( $email );
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
			return array();
		}
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		$sql = "SELECT COALESCE(updated_at, results_date) FROM {$table} WHERE user_email = %s AND is_final = 1{$extra} ORDER BY COALESCE(updated_at, results_date) ASC, id ASC";
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $email ) );
		$out  = array();
		foreach ( (array) $rows as $raw ) {
			$d = self::to_iso( $raw );
			if ( $d !== '' && ! in_array( $d, $out, true ) ) {
				$out[] = $d;
			}
		}
		return $out;
	}

	private static function keys_all_dates( string $email ): array {
		global $wpdb;
		$candidates = array( 'uls_key_essentials', $wpdb->prefix . 'uls_key_essentials' );
		foreach ( $candidates as $table ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}
			$rows = $wpdb->get_col( $wpdb->prepare(
				"SELECT datetime FROM {$table} WHERE user_email = %s ORDER BY datetime ASC, id ASC",
				$email
			) );
			$out = array();
			foreach ( (array) $rows as $raw ) {
				$d = self::to_iso( $raw );
				if ( $d !== '' && ! in_array( $d, $out, true ) ) {
					$out[] = $d;
				}
			}
			if ( $out ) {
				return $out;
			}
		}
		return array();
	}

	private static function biovoice_all_dates( string $email ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array();
		}
		$table = $wpdb->prefix . 'bm_biovoice_session_groups';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT updated_at FROM {$table} WHERE user_id = %d AND status = 'final' ORDER BY updated_at ASC, id ASC",
			$user->ID
		) );
		$out = array();
		foreach ( (array) $rows as $raw ) {
			$d = self::to_iso( $raw );
			if ( $d !== '' && ! in_array( $d, $out, true ) ) {
				$out[] = $d;
			}
		}
		return $out;
	}

	private static function parse_tags( string $csv ): array {
		$parts = array_map( 'trim', explode( ',', $csv ) );
		return array_values( array_filter( $parts, static function ( $t ) {
			return $t !== '';
		} ) );
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
