<?php
/**
 * BioVoicePrint – Analysis results (plain report + pattern payload).
 * Real engine output wires into the same table later; fixture mode uses bundled samples.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Results_Service {

	public static function color_class( string $color ): string {
		$color = strtolower( trim( $color ) );
		$map   = [
			'light_green' => 'is-green',
			'green'       => 'is-green',
			'yellow'      => 'is-yellow',
			'orange'      => 'is-orange',
			'red'         => 'is-red',
			'light_red'   => 'is-red',
			'blue'        => 'is-blue',
			'gray'        => 'is-muted',
			'grey'        => 'is-muted',
		];
		return $map[ $color ] ?? 'is-muted';
	}

	public static function load_fixture_plain_report(): ?array {
		$path = BMF_BIOVOICE_PATH . 'fixtures/sample_plain_language_report.json';
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	public static function load_fixture_pattern_payload(): ?array {
		$path = BMF_BIOVOICE_PATH . 'fixtures/sample_bsi_pattern_payload.json';
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Priority: fixture → latest DB result for user.
	 *
	 * @return array{report: array, meta: array}|null
	 */
	public static function resolve_plain_report( int $user_id, bool $use_fixture = false ): ?array {
		if ( $use_fixture ) {
			$report = self::load_fixture_plain_report();
			if ( ! $report ) {
				return null;
			}
			return [
				'report' => $report,
				'meta'   => [
					'source'      => 'fixture',
					'result_id'   => 0,
					'analyzed_at' => null,
					'is_fixture'  => true,
				],
			];
		}

		if ( $user_id < 1 ) {
			return null;
		}

		$row = BMF_BioVoice_Repository::get_latest_result_for_user( $user_id );
		if ( ! $row || empty( $row['plain_report_json'] ) ) {
			return null;
		}

		$report = json_decode( $row['plain_report_json'], true );
		if ( ! is_array( $report ) ) {
			return null;
		}

		return [
			'report' => $report,
			'meta'   => [
				'source'      => $row['source'] ?? 'engine',
				'result_id'   => (int) $row['id'],
				'analyzed_at' => $row['analyzed_at'] ?? $row['created_at'] ?? null,
				'is_fixture'  => false,
			],
		];
	}

	/**
	 * Resolve BSI pattern payload for scores UI.
	 * Priority: fixture → latest DB pattern_payload_json.
	 *
	 * @return array{payload: array, meta: array}|null
	 */
	public static function resolve_pattern_payload( int $user_id, bool $use_fixture = false ): ?array {
		if ( $use_fixture ) {
			$payload = self::load_fixture_pattern_payload();
			if ( ! $payload ) {
				return null;
			}
			return [
				'payload' => $payload,
				'meta'    => [
					'source'      => 'fixture',
					'result_id'   => 0,
					'analyzed_at' => null,
					'is_fixture'  => true,
				],
			];
		}

		if ( $user_id < 1 ) {
			return null;
		}

		$row = BMF_BioVoice_Repository::get_latest_result_for_user( $user_id );
		if ( ! $row || empty( $row['pattern_payload_json'] ) ) {
			return null;
		}

		$payload = json_decode( $row['pattern_payload_json'], true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		return [
			'payload' => $payload,
			'meta'    => [
				'source'      => $row['source'] ?? 'engine',
				'result_id'   => (int) $row['id'],
				'analyzed_at' => $row['analyzed_at'] ?? $row['created_at'] ?? null,
				'is_fixture'  => false,
			],
		];
	}

	public static function store_result( int $user_id, array $args ) {
		$user = get_userdata( $user_id );
		$plain = isset( $args['plain_report'] ) && is_array( $args['plain_report'] )
			? $args['plain_report']
			: null;
		$pattern = isset( $args['pattern_payload'] ) && is_array( $args['pattern_payload'] )
			? $args['pattern_payload']
			: null;

		$rdi_score = null;
		$rdi_band  = null;
		$rdi_color = null;
		if ( $plain && isset( $plain['overall_result'] ) && is_array( $plain['overall_result'] ) ) {
			$or        = $plain['overall_result'];
			$rdi_score = isset( $or['rdi_score_0_100'] ) ? (float) $or['rdi_score_0_100'] : null;
			$rdi_band  = isset( $or['rdi_band'] ) ? sanitize_text_field( $or['rdi_band'] ) : null;
			$rdi_color = isset( $or['rdi_color'] ) ? sanitize_key( $or['rdi_color'] ) : null;
		}

		$row = [
			'user_id'               => $user_id,
			'user_email'            => $user ? $user->user_email : null,
			'session_group_id'      => isset( $args['session_group_id'] ) ? absint( $args['session_group_id'] ) : null,
			'comparison_session_id' => isset( $args['comparison_session_id'] )
				? sanitize_text_field( $args['comparison_session_id'] )
				: ( $plain['comparison_session_id'] ?? null ),
			'schema_version'        => isset( $args['schema_version'] ) ? sanitize_text_field( $args['schema_version'] ) : 'stage7',
			'source'                => isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'engine',
			'rdi_score'             => $rdi_score,
			'rdi_band'              => $rdi_band,
			'rdi_color'             => $rdi_color,
			'plain_report_json'     => $plain ? wp_json_encode( $plain ) : null,
			'pattern_payload_json'  => $pattern ? wp_json_encode( $pattern ) : null,
			'analyzed_at'           => isset( $args['analyzed_at'] )
				? $args['analyzed_at']
				: current_time( 'mysql', true ),
		];

		return BMF_BioVoice_Repository::insert_result( $row );
	}
}
