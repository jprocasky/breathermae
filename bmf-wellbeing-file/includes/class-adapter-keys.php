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
		] );
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
		];
	}
}
