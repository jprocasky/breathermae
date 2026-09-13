<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Wellbeing_Adapter_BSI {

	public static function load( int $user_id, string $email ): array {
		$cfg   = BMF_Wellbeing_Map::source( 'bsi' );
		$empty = self::shell( $cfg );
		if ( $email === '' ) {
			return $empty;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bm_bsi_results';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_email = %s AND is_final = 1 ORDER BY results_date DESC, id DESC LIMIT 1",
				$email
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return $empty;
		}

		$date  = BMF_Wellbeing_Freshness::normalize_date( $row['results_date'] ?? '' );
		$fresh = BMF_Wellbeing_Freshness::evaluate( 'bsi', $date );
		$forms = BMF_Wellbeing_Map::definition()['bsi_forms'] ?? [];

		$f_scores = [];
		foreach ( $forms as $code => $meta ) {
			$pct  = self::field_pct( $row, $code );
			$band = BMF_Wellbeing_Freshness::band( $pct, 'low_better', 100 );
			$f_scores[] = [
				'code'      => $code,
				'label'     => $meta['label'] ?? $code,
				'title'     => $meta['title'] ?? $code,
				'composite' => $meta['composite'] ?? '',
				'value'     => $pct,
				'scale'     => 100,
				'direction' => 'low_better',
				'band'      => $band,
			];
		}

		$composites = [
			[ 'code' => 'drivers', 'label' => 'Drivers', 'cols' => [ 'F4', 'F7', 'F8' ] ],
			[ 'code' => 'mediators', 'label' => 'Mediators', 'cols' => [ 'F2', 'F3', 'F5' ] ],
			[ 'code' => 'outcomes', 'label' => 'Outcomes', 'cols' => [ 'F1', 'F6', 'F9' ] ],
		];
		$comp_scores = [];
		foreach ( $composites as $c ) {
			$pct  = self::avg_pct( $row, $c['cols'] );
			$band = BMF_Wellbeing_Freshness::band( $pct, 'low_better', 100 );
			$comp_scores[] = [
				'code'      => $c['code'],
				'label'     => $c['label'],
				'value'     => $pct,
				'scale'     => 100,
				'direction' => 'low_better',
				'band'      => $band,
			];
		}

		return array_merge( $empty, $fresh, [
			'present'    => true,
			'scores'     => $comp_scores,
			'forms'      => $f_scores,
			'history'    => self::history( $email ),
			'raw_id'     => isset( $row['id'] ) ? (int) $row['id'] : 0,
		] );
	}

	private static function field_pct( array $row, string $col ): ?float {
		if ( ! isset( $row[ $col ] ) || $row[ $col ] === '' || $row[ $col ] === null || ! is_numeric( $row[ $col ] ) ) {
			return null;
		}
		return BMF_Wellbeing_Freshness::to_percent( (float) $row[ $col ], 100 );
	}

	private static function avg_pct( array $row, array $cols ): ?float {
		$vals = [];
		foreach ( $cols as $col ) {
			$v = self::field_pct( $row, $col );
			if ( $v !== null ) {
				$vals[] = $v;
			}
		}
		if ( ! $vals ) {
			return null;
		}
		return round( array_sum( $vals ) / count( $vals ), 1 );
	}

	private static function history( string $email ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bm_bsi_results';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT results_date, F1, F2, F3, F4, F5, F6, F7, F8, F9
				 FROM {$table}
				 WHERE user_email = %s AND is_final = 1
				 ORDER BY results_date ASC, id ASC",
				$email
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return [];
		}
		if ( count( $rows ) > 8 ) {
			$rows = array_slice( $rows, -8 );
		}
		$out = [];
		foreach ( $rows as $r ) {
			$d = BMF_Wellbeing_Freshness::normalize_date( $r['results_date'] ?? '' );
			if ( $d === '' ) {
				continue;
			}
			$out[] = [
				'date'      => $d,
				'drivers'   => self::avg_pct( $r, [ 'F4', 'F7', 'F8' ] ),
				'mediators' => self::avg_pct( $r, [ 'F2', 'F3', 'F5' ] ),
				'outcomes'  => self::avg_pct( $r, [ 'F1', 'F6', 'F9' ] ),
			];
		}
		return $out;
	}

	private static function shell( array $cfg ): array {
		return [
			'key'      => 'bsi',
			'label'    => $cfg['label'] ?? 'BSI',
			'enabled'  => ! empty( $cfg['enabled'] ),
			'clock'    => $cfg['clock'] ?? 'cycle',
			'present'  => false,
			'status'   => 'missing',
			'date'     => '',
			'age_days' => null,
			'scores'   => [],
			'forms'    => [],
			'history'  => [],
		];
	}
}
