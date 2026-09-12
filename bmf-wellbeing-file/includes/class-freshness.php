<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Wellbeing_Freshness {

	public static function normalize_date( $raw ): string {
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

	public static function age_days( string $date, $as_of = null ): ?int {
		$date = self::normalize_date( $date );
		if ( $date === '' ) {
			return null;
		}
		$as_of = $as_of ? self::normalize_date( $as_of ) : gmdate( 'Y-m-d' );
		$a     = strtotime( $date . ' UTC' );
		$b     = strtotime( $as_of . ' UTC' );
		if ( ! $a || ! $b ) {
			return null;
		}
		return (int) floor( ( $b - $a ) / DAY_IN_SECONDS );
	}

	/**
	 * @return array{status:string,age_days:?int,date:string,clock:string}
	 */
	public static function evaluate( string $source_key, string $date, $as_of = null ): array {
		$cfg  = BMF_Wellbeing_Map::source( $source_key );
		$date = self::normalize_date( $date );
		$age  = $date !== '' ? self::age_days( $date, $as_of ) : null;
		if ( $date === '' || $age === null ) {
			return [
				'status'   => empty( $cfg['enabled'] ) ? 'reserved' : 'missing',
				'age_days' => null,
				'date'     => '',
				'clock'    => $cfg['clock'] ?? '',
			];
		}
		$stale = (int) ( $cfg['stale_days'] ?? 90 );
		$aging = (int) ( $cfg['aging_days'] ?? $stale );
		if ( $age >= $stale ) {
			$status = 'stale';
		} elseif ( $age >= $aging ) {
			$status = 'aging';
		} else {
			$status = 'fresh';
		}
		return [
			'status'   => $status,
			'age_days' => $age,
			'date'     => $date,
			'clock'    => $cfg['clock'] ?? '',
		];
	}

	public static function band( $value, string $direction, $scale ): array {
		if ( $value === null || $scale === null || (float) $scale <= 0 ) {
			return [ 'key' => 'none', 'label' => '—', 'ratio' => null ];
		}
		$ratio = (float) $value / (float) $scale;
		if ( $direction === 'low_better' ) {
			$pct = $ratio * 100;
			if ( $pct < 25 ) {
				return [ 'key' => 'optimal', 'label' => 'Optimal', 'ratio' => $ratio ];
			}
			if ( $pct < 50 ) {
				return [ 'key' => 'moderate', 'label' => 'Moderate', 'ratio' => $ratio ];
			}
			if ( $pct < 75 ) {
				return [ 'key' => 'elevated', 'label' => 'Elevated', 'ratio' => $ratio ];
			}
			return [ 'key' => 'high', 'label' => 'High strain', 'ratio' => $ratio ];
		}
		// high_better
		if ( $ratio >= 0.8 ) {
			return [ 'key' => 'strong', 'label' => 'Strong', 'ratio' => $ratio ];
		}
		if ( $ratio >= 0.6 ) {
			return [ 'key' => 'building', 'label' => 'Building', 'ratio' => $ratio ];
		}
		if ( $ratio >= 0.4 ) {
			return [ 'key' => 'watch', 'label' => 'Watch', 'ratio' => $ratio ];
		}
		return [ 'key' => 'focus', 'label' => 'Needs focus', 'ratio' => $ratio ];
	}

	public static function to_percent( $value, $scale ): ?float {
		if ( $value === null || $scale === null || (float) $scale <= 0 ) {
			return null;
		}
		$v = (float) $value;
		if ( (float) $scale == 100.0 && $v <= 1.0 ) {
			return round( $v * 100, 1 );
		}
		if ( (float) $scale == 100.0 ) {
			return round( $v, 1 );
		}
		return round( ( $v / (float) $scale ) * 100, 1 );
	}
}
