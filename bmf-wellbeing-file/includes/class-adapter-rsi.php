<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Wellbeing_Adapter_RSI {

	public static function load( int $user_id, string $email ): array {
		$cfg    = BMF_Wellbeing_Map::source( 'rsi' );
		$empty  = self::shell( $cfg );
		if ( $email === '' ) {
			return $empty;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bm_rsi_results';
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

		$date = BMF_Wellbeing_Freshness::normalize_date( $row['results_date'] ?? '' );
		$fresh = BMF_Wellbeing_Freshness::evaluate( 'rsi', $date );
		$scores = [];
		foreach ( [ 'R11' => 'Core', 'R12' => 'Performance' ] as $col => $label ) {
			$raw = isset( $row[ $col ] ) && $row[ $col ] !== '' && $row[ $col ] !== null ? (float) $row[ $col ] : null;
			$pct = BMF_Wellbeing_Freshness::to_percent( $raw, 100 );
			$band = BMF_Wellbeing_Freshness::band( $pct, 'low_better', 100 );
			$eng  = $row[ $col . '_engagement' ] ?? '';
			$scores[] = [
				'code'        => $col,
				'label'       => $label,
				'value'       => $pct,
				'scale'       => 100,
				'direction'   => 'low_better',
				'band'        => $band,
				'engagement'  => is_string( $eng ) ? $eng : '',
				'domains'     => $col === 'R11' ? [ 'core_regulation' ] : [ 'performance_load' ],
			];
		}

		return array_merge( $empty, $fresh, [
			'present' => true,
			'scores'  => $scores,
			'raw_id'  => isset( $row['id'] ) ? (int) $row['id'] : 0,
		] );
	}

	private static function shell( array $cfg ): array {
		return [
			'key'       => 'rsi',
			'label'     => $cfg['label'] ?? 'RSI',
			'enabled'   => ! empty( $cfg['enabled'] ),
			'clock'     => $cfg['clock'] ?? 'pulse',
			'present'   => false,
			'status'    => 'missing',
			'date'      => '',
			'age_days'  => null,
			'scores'    => [],
		];
	}
}
