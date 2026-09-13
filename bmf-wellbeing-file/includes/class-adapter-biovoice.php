<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Wellbeing_Adapter_BioVoice {

	public static function load( int $user_id, string $email ): array {
		$cfg   = BMF_Wellbeing_Map::source( 'biovoice' );
		$empty = self::shell( $cfg );
		if ( $user_id <= 0 && $email === '' ) {
			return $empty;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bm_biovoice_results';
		$row   = null;
		if ( $user_id > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					 WHERE user_id = %d AND schema_version = %s
					 ORDER BY COALESCE(analyzed_at, created_at) DESC, id DESC
					 LIMIT 1",
					$user_id,
					'stage7'
				),
				ARRAY_A
			);
		}
		if ( ! $row && $email !== '' ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					 WHERE user_email = %s AND schema_version = %s
					 ORDER BY COALESCE(analyzed_at, created_at) DESC, id DESC
					 LIMIT 1",
					$email,
					'stage7'
				),
				ARRAY_A
			);
		}

		$progress = self::group_progress( $user_id, $email );
		if ( ! $row ) {
			$empty['progress'] = $progress;
			if ( $progress['baseline_final'] > 0 || $progress['comparison_final'] > 0 ) {
				$empty['status'] = 'missing';
			}
			return $empty;
		}

		$when  = $row['analyzed_at'] ?? $row['created_at'] ?? '';
		$date  = BMF_Wellbeing_Freshness::normalize_date( $when );
		$fresh = BMF_Wellbeing_Freshness::evaluate( 'biovoice', $date );

		$rdi = isset( $row['rdi_score'] ) && $row['rdi_score'] !== '' && $row['rdi_score'] !== null
			? (float) $row['rdi_score']
			: null;
		$band_label = trim( (string) ( $row['rdi_band'] ?? '' ) );
		$band       = $band_label !== ''
			? [ 'key' => self::band_key( $rdi, $band_label ), 'label' => $band_label, 'ratio' => $rdi !== null ? $rdi / 100 : null ]
			: BMF_Wellbeing_Freshness::band( $rdi, 'low_better', 100 );

		$report  = self::decode_json( $row['plain_report_json'] ?? '' );
		$summary = '';
		if ( is_array( $report ) ) {
			$summary = (string) ( $report['overall']['plain_language_summary']
				?? $report['overall_biovoiceprint_summary']
				?? $report['plain_language_summary']
				?? '' );
		}
		$markers = [];
		$findings = is_array( $report ) ? ( $report['key_findings']['most_shifted_markers'] ?? [] ) : [];
		if ( is_array( $findings ) ) {
			foreach ( array_slice( $findings, 0, 3 ) as $m ) {
				$markers[] = [
					'label' => $m['display_name'] ?? $m['feature_name'] ?? '',
					'score' => isset( $m['score_0_100'] ) ? (float) $m['score_0_100'] : null,
					'band'  => $m['band'] ?? '',
					'note'  => $m['common_language_meaning'] ?? '',
				];
			}
		}

		$scores = [
			[
				'code'      => 'rdi',
				'label'     => 'RDI (shift)',
				'value'     => $rdi !== null ? round( $rdi, 1 ) : null,
				'scale'     => 100,
				'direction' => 'low_better',
				'band'      => $band,
			],
		];

		return array_merge( $empty, $fresh, [
			'present'  => true,
			'scores'   => $scores,
			'summary'  => $summary,
			'markers'  => $markers,
			'progress' => $progress,
			'history'  => self::history( $user_id, $email ),
			'raw_id'   => isset( $row['id'] ) ? (int) $row['id'] : 0,
		] );
	}

	private static function band_key( $rdi, string $label ): string {
		$l = strtolower( $label );
		if ( strpos( $l, 'extreme' ) !== false || strpos( $l, 'significant' ) !== false ) {
			return 'high';
		}
		if ( strpos( $l, 'moderate' ) !== false ) {
			return 'elevated';
		}
		if ( strpos( $l, 'mild' ) !== false || strpos( $l, 'minimal' ) !== false ) {
			return 'moderate';
		}
		if ( $rdi !== null && (float) $rdi >= 50 ) {
			return 'elevated';
		}
		if ( $rdi !== null && (float) $rdi >= 25 ) {
			return 'moderate';
		}
		return 'optimal';
	}

	private static function decode_json( $raw ): ?array {
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}
		$j = json_decode( $raw, true );
		return is_array( $j ) ? $j : null;
	}

	private static function group_progress( int $user_id, string $email ): array {
		$out = [ 'baseline_final' => 0, 'comparison_final' => 0, 'device_mismatch' => false ];
		global $wpdb;
		$table = $wpdb->prefix . 'bm_biovoice_session_groups';
		$sql   = "SELECT purpose, is_final, device_mismatch FROM {$table} WHERE ";
		if ( $user_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql . 'user_id = %d', $user_id ), ARRAY_A );
		} elseif ( $email !== '' ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql . 'user_email = %s', $email ), ARRAY_A );
		} else {
			return $out;
		}
		if ( ! $rows ) {
			return $out;
		}
		foreach ( $rows as $r ) {
			if ( empty( $r['is_final'] ) ) {
				continue;
			}
			$p = strtolower( (string) ( $r['purpose'] ?? '' ) );
			if ( $p === 'baseline' ) {
				$out['baseline_final']++;
			} elseif ( $p === 'comparison' ) {
				$out['comparison_final']++;
			}
			if ( ! empty( $r['device_mismatch'] ) ) {
				$out['device_mismatch'] = true;
			}
		}
		return $out;
	}

	private static function history( int $user_id, string $email ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bm_biovoice_results';
		if ( $user_id > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT rdi_score, analyzed_at, created_at FROM {$table}
					 WHERE user_id = %d AND schema_version = %s AND rdi_score IS NOT NULL
					 ORDER BY COALESCE(analyzed_at, created_at) ASC, id ASC",
					$user_id,
					'stage7'
				),
				ARRAY_A
			);
		} elseif ( $email !== '' ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT rdi_score, analyzed_at, created_at FROM {$table}
					 WHERE user_email = %s AND schema_version = %s AND rdi_score IS NOT NULL
					 ORDER BY COALESCE(analyzed_at, created_at) ASC, id ASC",
					$email,
					'stage7'
				),
				ARRAY_A
			);
		} else {
			return [];
		}
		if ( ! $rows ) {
			return [];
		}
		if ( count( $rows ) > 8 ) {
			$rows = array_slice( $rows, -8 );
		}
		$out = [];
		foreach ( $rows as $r ) {
			$d = BMF_Wellbeing_Freshness::normalize_date( $r['analyzed_at'] ?? $r['created_at'] ?? '' );
			if ( $d === '' || $r['rdi_score'] === null || $r['rdi_score'] === '' ) {
				continue;
			}
			$out[] = [ 'date' => $d, 'rdi' => round( (float) $r['rdi_score'], 1 ) ];
		}
		return $out;
	}

	private static function shell( array $cfg ): array {
		return [
			'key'      => 'biovoice',
			'label'    => $cfg['label'] ?? 'BioVoicePrint',
			'enabled'  => ! empty( $cfg['enabled'] ),
			'clock'    => $cfg['clock'] ?? 'state',
			'present'  => false,
			'status'   => 'missing',
			'date'     => '',
			'age_days' => null,
			'scores'   => [],
			'summary'  => '',
			'markers'  => [],
			'progress' => [ 'baseline_final' => 0, 'comparison_final' => 0, 'device_mismatch' => false ],
			'history'  => [],
		];
	}
}
