<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Wellbeing_Adapter_Keys {

	public static function load( int $user_id, string $email ): array {
		$cfg   = BMF_Wellbeing_Map::source( 'keys' );
		$empty = self::shell( $cfg );
		if ( $email === '' ) {
			return $empty;
		}
		global $wpdb;
		$table = 'uls_key_essentials';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, form_id, datetime, total_score, average_score
				 FROM {$table}
				 WHERE user_email = %s
				 ORDER BY datetime DESC, id DESC",
				$email
			),
			ARRAY_A
		);

		$metrics = [];
		foreach ( BMF_Wellbeing_Map::metrics_for( 'keys' ) as $m ) {
			$metrics[ $m['code'] ] = $m;
		}

		$latest = [];
		$newest = '';
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$fid = (string) ( $row['form_id'] ?? '' );
				if ( $fid === '' || isset( $latest[ $fid ] ) ) {
					continue;
				}
				$latest[ $fid ] = $row;
				$d = BMF_Wellbeing_Freshness::normalize_date( $row['datetime'] ?? '' );
				if ( $d !== '' && ( $newest === '' || $d > $newest ) ) {
					$newest = $d;
				}
			}
		}

		if ( ! $latest ) {
			return $empty;
		}

		$fresh  = BMF_Wellbeing_Freshness::evaluate( 'keys', $newest );
		$scores = [];
		foreach ( $metrics as $code => $m ) {
			$row  = $latest[ $code ] ?? null;
			$avg  = $row && $row['average_score'] !== '' && $row['average_score'] !== null ? (float) $row['average_score'] : null;
			$date = $row ? BMF_Wellbeing_Freshness::normalize_date( $row['datetime'] ?? '' ) : '';
			$item_fresh = $date !== '' ? BMF_Wellbeing_Freshness::evaluate( 'keys', $date ) : [ 'status' => 'missing', 'age_days' => null, 'date' => '' ];
			$scores[] = [
				'code'      => $code,
				'label'     => $m['label'],
				'value'     => $avg !== null ? round( $avg, 2 ) : null,
				'scale'     => 5,
				'percent'   => $avg !== null ? (int) round( ( $avg / 5 ) * 100 ) : null,
				'direction' => 'high_better',
				'band'      => BMF_Wellbeing_Freshness::band( $avg, 'high_better', 5 ),
				'domains'   => $m['domains'],
				'date'      => $item_fresh['date'],
				'status'    => $item_fresh['status'],
				'age_days'  => $item_fresh['age_days'],
			];
		}

		return array_merge( $empty, $fresh, [
			'present' => true,
			'scores'  => $scores,
			'history' => self::history( $rows, array_keys( $metrics ) ),
		] );
	}

	/**
	 * Overall essentials 0–100 as-of each distinct date (latest row per form on or before that date).
	 */
	private static function history( array $rows, array $form_codes ): array {
		if ( ! $rows ) {
			return [];
		}
		$dates = [];
		foreach ( $rows as $row ) {
			$d = BMF_Wellbeing_Freshness::normalize_date( $row['datetime'] ?? '' );
			if ( $d !== '' ) {
				$dates[ $d ] = true;
			}
		}
		$dates = array_keys( $dates );
		sort( $dates );
		if ( count( $dates ) > 16 ) {
			$dates = array_slice( $dates, -16 );
		}
		$out = [];
		foreach ( $dates as $d ) {
			$latest = [];
			foreach ( $rows as $row ) {
				$rd = BMF_Wellbeing_Freshness::normalize_date( $row['datetime'] ?? '' );
				if ( $rd === '' || $rd > $d ) {
					continue;
				}
				$fid = (string) ( $row['form_id'] ?? '' );
				if ( $fid === '' || ! in_array( $fid, $form_codes, true ) ) {
					continue;
				}
				if ( ! isset( $latest[ $fid ] ) || $rd >= BMF_Wellbeing_Freshness::normalize_date( $latest[ $fid ]['datetime'] ?? '' ) ) {
					$latest[ $fid ] = $row;
				}
			}
			$vals = [];
			foreach ( $latest as $row ) {
				if ( $row['average_score'] !== '' && $row['average_score'] !== null ) {
					$vals[] = (float) $row['average_score'];
				}
			}
			if ( ! $vals ) {
				continue;
			}
			$avg = array_sum( $vals ) / count( $vals );
			$out[] = [
				'date'    => $d,
				'overall' => (int) round( ( $avg / 5 ) * 100 ),
				'n'       => count( $vals ),
			];
		}
		return $out;
	}

	private static function shell( array $cfg ): array {
		return [
			'key'      => 'keys',
			'label'    => $cfg['label'] ?? 'Key Essentials',
			'enabled'  => ! empty( $cfg['enabled'] ),
			'clock'    => $cfg['clock'] ?? 'state',
			'present'  => false,
			'status'   => 'missing',
			'date'     => '',
			'age_days' => null,
			'scores'   => [],
			'history'  => [],
		];
	}
}
