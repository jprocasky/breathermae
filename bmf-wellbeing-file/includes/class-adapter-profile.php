<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health profile context from uls_ULS_CF_BIO.
 * Does not score. Race / income / education / marital stay off the card.
 */
class BMF_Wellbeing_Adapter_Profile {

	public static function load( int $user_id, string $email ): array {
		$cfg   = BMF_Wellbeing_Map::source( 'profile' );
		$empty = self::shell( $cfg );
		$row   = self::fetch_row( $user_id, $email );
		if ( ! $row ) {
			return $empty;
		}

		$sex    = self::text( $row['f_sex'] ?? '' ) ?: self::text( $row['f_gender'] ?? '' );
		$dob    = BMF_Wellbeing_Freshness::normalize_date( $row['f_dateofbirth'] ?? '' );
		$age    = self::age_years( $dob );
		$ft     = self::intish( $row['f_heightft'] ?? null );
		$inch   = self::intish( $row['f_heightin'] ?? null );
		$height = self::height_inches( $ft, $inch );
		$weight = self::intish( $row['f_weight'] ?? null );
		$bmi    = self::bmi( $height, $weight );

		$diet   = self::text( $row['f_dietarypattern'] ?? '' );
		$sens   = self::text( $row['f_foodsensitivities'] ?? '' );
		$job    = self::text( $row['f_employment'] ?? '' );
		$deps   = self::dependents( $row['f_dependent_count'] ?? '', $row['f_dependents'] ?? '' );

		$useful = $age !== null || $sex !== '' || $height !== null || $weight !== null || $diet !== '' || $job !== '' || $deps['count'] !== null || $deps['flag'];
		$core   = $age !== null || $sex !== '' || ( $height !== null && $weight !== null );

		$parts = [];
		if ( $age !== null ) {
			$parts[] = 'Age ' . $age . ' (' . self::age_band( $age ) . ')';
		}
		if ( $sex !== '' ) {
			$parts[] = $sex;
		}
		if ( $ft !== null || $inch !== null ) {
			$parts[] = self::height_label( $ft, $inch );
		}
		if ( $weight !== null ) {
			$parts[] = $weight . ' lb';
		}
		if ( $bmi !== null ) {
			$parts[] = 'BMI ' . $bmi['band'];
		}
		if ( $diet !== '' ) {
			$parts[] = 'Diet: ' . self::clip( $diet, 40 );
		}
		if ( $sens !== '' ) {
			$parts[] = 'Food sensitivity on file';
		}
		if ( $job !== '' ) {
			$parts[] = 'Work: ' . self::clip( $job, 36 );
		}
		if ( $deps['count'] !== null && $deps['count'] > 0 ) {
			$parts[] = 'Care load: ' . $deps['count'];
		} elseif ( $deps['flag'] ) {
			$parts[] = 'Care load on file';
		}

		return array_merge( $empty, [
			'present'    => $useful,
			'status'     => ! $useful ? 'missing' : ( $core ? 'present' : 'incomplete' ),
			'date'       => BMF_Wellbeing_Freshness::normalize_date( $row['updated_at'] ?? $row['created_at'] ?? '' ),
			'age_days'   => null,
			'strip'      => implode( ' · ', $parts ),
			'facts'      => [
				'age'              => $age,
				'age_band'         => $age !== null ? self::age_band( $age ) : '',
				'sex'              => $sex,
				'height_in'        => $height,
				'weight_lb'        => $weight,
				'bmi'              => $bmi['value'] ?? null,
				'bmi_band'         => $bmi['band'] ?? '',
				'diet'             => $diet,
				'food_sensitivity' => $sens !== '',
				'employment'       => $job,
				'dependents'       => $deps['count'],
				'care_load'        => $deps['flag'] || ( $deps['count'] !== null && $deps['count'] > 0 ),
			],
		] );
	}

	private static function fetch_row( int $user_id, string $email ): ?array {
		global $wpdb;
		$candidates = [ 'uls_ULS_CF_BIO', 'uls_uls_cf_bio', $wpdb->prefix . 'uls_ULS_CF_BIO' ];
		$table      = null;
		foreach ( $candidates as $name ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
				continue;
			}
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ) {
				$table = $name;
				break;
			}
		}
		if ( ! $table ) {
			return null;
		}
		$row = null;
		if ( $user_id > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1", $user_id ),
				ARRAY_A
			);
		}
		if ( ! $row && $email !== '' ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM `{$table}` WHERE email = %s ORDER BY updated_at DESC, id DESC LIMIT 1", $email ),
				ARRAY_A
			);
		}
		return is_array( $row ) ? $row : null;
	}

	private static function text( $v ): string {
		$s = trim( wp_strip_all_tags( (string) $v ) );
		if ( $s === '' || strtolower( $s ) === 'null' ) {
			return '';
		}
		return $s;
	}

	private static function clip( string $s, int $n ): string {
		if ( strlen( $s ) <= $n ) {
			return $s;
		}
		return rtrim( substr( $s, 0, $n - 1 ) ) . '…';
	}

	private static function intish( $v ): ?int {
		if ( $v === null || $v === '' || ! is_numeric( $v ) ) {
			return null;
		}
		return (int) $v;
	}

	private static function height_inches( ?int $ft, ?int $inch ): ?int {
		if ( $ft === null && $inch === null ) {
			return null;
		}
		return ( ( $ft ?? 0 ) * 12 ) + ( $inch ?? 0 );
	}

	private static function height_label( ?int $ft, ?int $inch ): string {
		$ft   = $ft ?? 0;
		$inch = $inch ?? 0;
		return $ft . "'" . $inch . '"';
	}

	private static function age_years( string $dob ): ?int {
		if ( $dob === '' ) {
			return null;
		}
		try {
			$d = new DateTimeImmutable( $dob, new DateTimeZone( 'UTC' ) );
			$n = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			$y = (int) $n->diff( $d )->y;
			return ( $y >= 0 && $y < 120 ) ? $y : null;
		} catch ( Exception $e ) {
			return null;
		}
	}

	private static function age_band( int $age ): string {
		if ( $age < 30 ) {
			return 'under 30';
		}
		if ( $age < 45 ) {
			return '30–44';
		}
		if ( $age < 60 ) {
			return '45–59';
		}
		return '60+';
	}

	private static function bmi( ?int $height_in, ?int $weight_lb ): ?array {
		if ( ! $height_in || $height_in < 48 || ! $weight_lb || $weight_lb < 70 ) {
			return null;
		}
		$val = round( ( $weight_lb / ( $height_in * $height_in ) ) * 703, 1 );
		if ( $val < 18.5 ) {
			$band = 'lower range';
		} elseif ( $val < 25 ) {
			$band = 'mid range';
		} elseif ( $val < 30 ) {
			$band = 'higher range';
		} else {
			$band = 'higher range';
		}
		return [ 'value' => $val, 'band' => $band ];
	}

	private static function dependents( $count_raw, $flag_raw ): array {
		$count = null;
		if ( is_numeric( $count_raw ) ) {
			$count = (int) $count_raw;
		} elseif ( preg_match( '/(\d+)/', (string) $count_raw, $m ) ) {
			$count = (int) $m[1];
		}
		$flag = self::text( $flag_raw ) !== '';
		return [ 'count' => $count, 'flag' => $flag ];
	}

	private static function shell( array $cfg ): array {
		return [
			'key'      => 'profile',
			'label'    => $cfg['label'] ?? 'Profile',
			'enabled'  => ! empty( $cfg['enabled'] ),
			'clock'    => 'context',
			'present'  => false,
			'status'   => 'missing',
			'date'     => '',
			'age_days' => null,
			'strip'    => '',
			'facts'    => [],
			'scores'   => [],
			'history'  => [],
		];
	}
}
