<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Oura nights from breathermae-oura-integration tables.
 * Chip shows when a sleep row exists, or the member has the OURA tag.
 */
class BMF_Wellbeing_Adapter_Oura {

	public static function load( int $user_id, string $email ): array {
		unset( $email );
		$cfg    = BMF_Wellbeing_Map::source( 'oura' );
		$linked = function_exists( 'wpf_has_tag' ) && $user_id > 0 && wpf_has_tag( 'OURA', $user_id );
		$empty  = self::shell( $cfg, $linked );
		if ( $user_id <= 0 ) {
			return $empty;
		}

		global $wpdb;
		$sleep = $wpdb->prefix . 'bm_oura_sleep';
		$ready = $wpdb->prefix . 'bm_oura_readiness';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sleep ) ) !== $sleep ) {
			return $empty;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sleep_day, total_sleep_sec, efficiency, score
				 FROM `{$sleep}`
				 WHERE wp_user_id = %d
				 ORDER BY sleep_day ASC
				 LIMIT 30",
				$user_id
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return $empty;
		}

		$ready_rows = [];
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ready ) ) === $ready ) {
			$found = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT readiness_day, score FROM `{$ready}` WHERE wp_user_id = %d ORDER BY readiness_day ASC LIMIT 30",
					$user_id
				),
				ARRAY_A
			);
			foreach ( is_array( $found ) ? $found : [] as $row ) {
				$ready_rows[ (string) $row['readiness_day'] ] = $row['score'];
			}
		}

		$history = [];
		foreach ( $rows as $row ) {
			$day   = (string) $row['sleep_day'];
			$hours = isset( $row['total_sleep_sec'] ) ? round( ( (int) $row['total_sleep_sec'] ) / 3600, 1 ) : null;
			$history[] = [
				'date'       => $day,
				'sleep_hours'=> $hours,
				'sleep_pct'  => $hours !== null ? (int) min( 100, round( ( $hours / 8 ) * 100 ) ) : null,
				'efficiency' => isset( $row['efficiency'] ) && $row['efficiency'] !== null ? (int) $row['efficiency'] : null,
				'readiness'  => isset( $ready_rows[ $day ] ) && $ready_rows[ $day ] !== null ? (int) $ready_rows[ $day ] : null,
			];
		}
		$last        = $history[ count( $history ) - 1 ];
		$fresh       = BMF_Wellbeing_Freshness::evaluate( 'oura', $last['date'] );
		$ready_score = $last['readiness'];
		$scores      = [
			[
				'code'      => 'last_night',
				'label'     => 'Last night (hrs)',
				'value'     => $last['sleep_hours'],
				'scale'     => 8,
				'direction' => 'high_better',
				'band'      => BMF_Wellbeing_Freshness::band( $last['sleep_hours'], 'high_better', 8 ),
			],
		];
		if ( $ready_score !== null ) {
			$scores[] = [
				'code'      => 'readiness',
				'label'     => 'Readiness',
				'value'     => $ready_score,
				'scale'     => 100,
				'direction' => 'high_better',
				'band'      => BMF_Wellbeing_Freshness::band( (float) $ready_score, 'high_better', 100 ),
			];
		}

		return array_merge( $empty, $fresh, [
			'present' => true,
			'linked'  => $linked,
			'scores'  => $scores,
			'history' => $history,
		] );
	}

	private static function shell( array $cfg, bool $linked ): array {
		return [
			'key'      => 'oura',
			'label'    => $cfg['label'] ?? 'Oura',
			'enabled'  => ! empty( $cfg['enabled'] ),
			'clock'    => $cfg['clock'] ?? 'state',
			'present'  => false,
			'linked'   => $linked,
			'status'   => 'missing',
			'date'     => '',
			'age_days' => null,
			'scores'   => [],
			'history'  => [],
		];
	}
}
