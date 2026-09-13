<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Wellbeing_Adapter_Pillars {

	public static function load( int $user_id, string $email ): array {
		$cfg   = BMF_Wellbeing_Map::source( 'pillars' );
		$empty = self::shell( $cfg );
		if ( $email === '' ) {
			return $empty;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bm_pillars_results';
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
		$fresh = BMF_Wellbeing_Freshness::evaluate( 'pillars', $date );
		$cols  = [
			'physical'      => 'Physical',
			'mental'        => 'Mental',
			'emotional'     => 'Emotional',
			'financial'     => 'Financial',
			'occupational'  => 'Occupational',
			'environmental' => 'Environmental',
			'spiritual'     => 'Spiritual',
			'social'        => 'Social',
		];
		$metrics = [];
		foreach ( BMF_Wellbeing_Map::metrics_for( 'pillars' ) as $m ) {
			$metrics[ $m['code'] ] = $m;
		}

		$scores = [];
		foreach ( $cols as $col => $label ) {
			$raw  = isset( $row[ $col ] ) && $row[ $col ] !== '' && $row[ $col ] !== null ? (float) $row[ $col ] : null;
			$pct  = BMF_Wellbeing_Freshness::to_percent( $raw, 100 );
			$band = BMF_Wellbeing_Freshness::band( $pct, 'high_better', 100 );
			$scores[] = [
				'code'      => $col,
				'label'     => $label,
				'value'     => $pct,
				'scale'     => 100,
				'direction' => 'high_better',
				'band'      => $band,
				'domains'   => $metrics[ $col ]['domains'] ?? [],
			];
		}

		$master = isset( $row['master_score'] ) && $row['master_score'] !== '' ? (float) $row['master_score'] : null;
		if ( $master !== null && $master <= 1 ) {
			$master = round( $master * 100, 1 );
		} elseif ( $master !== null ) {
			$master = round( $master, 1 );
		}

		$comparison = null;
		if ( class_exists( 'BMF_QA_Extremes_Shortcodes' ) && $user_id && $date ) {
			$comparison = BMF_QA_Extremes_Shortcodes::pillars_comparison( $user_id, $date );
		} else {
			$comparison = self::rank_comparison( $row, $scores );
		}

		return array_merge( $empty, $fresh, [
			'present'     => true,
			'scores'      => $scores,
			'master'      => $master,
			'comparison'  => $comparison,
			'history'     => self::history( $email ),
			'raw_id'      => isset( $row['id'] ) ? (int) $row['id'] : 0,
		] );
	}

	private static function history( string $email ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bm_pillars_results';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT results_date, master_score FROM {$table}
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
				'date'   => $d,
				'master' => BMF_Wellbeing_Freshness::to_percent( $r['master_score'] ?? null, 100 ),
			];
		}
		return $out;
	}

	private static function rank_comparison( array $row, array $scores ): ?array {
		$by = [];
		foreach ( $scores as $s ) {
			if ( $s['value'] !== null ) {
				$by[ $s['label'] ] = $s['value'];
			}
		}
		arsort( $by );
		$actual = array_keys( $by );
		$rank   = $row['rank'] ?? '';
		$perc   = [];
		if ( $rank ) {
			$perc = array_map( 'ucfirst', array_map( 'trim', explode( ',', urldecode( (string) $rank ) ) ) );
		}
		$items = [];
		$max   = max( count( $perc ), count( $actual ) );
		for ( $i = 0; $i < $max; $i++ ) {
			$p    = $perc[ $i ] ?? '—';
			$a    = $actual[ $i ] ?? '—';
			$pos  = ( $a !== '—' ) ? array_search( strtolower( $a ), array_map( 'strtolower', $perc ), true ) : false;
			$diff = ( $pos !== false ) ? ( (int) $pos - $i ) : null;
			$items[] = [
				'perceived' => $p,
				'actual'    => $a,
				'score'     => $by[ $a ] ?? null,
				'diff'      => $diff,
			];
		}
		return [ 'master' => null, 'items' => $items ];
	}

	private static function shell( array $cfg ): array {
		return [
			'key'         => 'pillars',
			'label'       => $cfg['label'] ?? '8 Pillars',
			'enabled'     => ! empty( $cfg['enabled'] ),
			'clock'       => $cfg['clock'] ?? 'cycle',
			'present'     => false,
			'status'      => 'missing',
			'date'        => '',
			'age_days'    => null,
			'scores'      => [],
			'master'      => null,
			'comparison'  => null,
			'history'     => [],
		];
	}
}
