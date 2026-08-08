<?php
/**
 * BioVoicePrint – analysis worker queue + job payloads.
 *
 * Pull-based: dedicated PC polls GET /worker/queue, downloads audio,
 * runs Python CLIs, POSTs results / baseline reference.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Worker_Service {

	/** Map wizard wellness keys → engine / CLI keys. */
	public const WELLNESS_KEY_MAP = [
		'balanced'                     => 'balanced_centered',
		'balanced_centered'            => 'balanced_centered',
		'clear'                        => 'mental_clarity_focus',
		'mental_clarity_focus'         => 'mental_clarity_focus',
		'energized'                    => 'physical_energy',
		'physical_energy'              => 'physical_energy',
		'ready'                        => 'recording_comfort_readiness',
		'recording_comfort_readiness'  => 'recording_comfort_readiness',
		'restored'                     => 'restored_recovered',
		'restored_recovered'           => 'restored_recovered',
	];

	public const ENGINE_WELLNESS_KEYS = [
		'balanced_centered',
		'mental_clarity_focus',
		'physical_energy',
		'recording_comfort_readiness',
		'restored_recovered',
	];

	/**
	 * Shared secret for the dedicated-PC analysis worker.
	 * Preferred auth for server-to-server (Application Passwords often fail when
	 * the host strips the Authorization header).
	 *
	 * Set via (first match wins):
	 *   1. define( 'BMF_BIOVOICE_WORKER_KEY', '...' ); in wp-config.php
	 *   2. option bmf_biovoice_worker_api_key
	 *   3. filter 'bmf_biovoice_worker_api_key'
	 */
	public static function get_worker_api_key(): string {
		$key = '';
		if ( defined( 'BMF_BIOVOICE_WORKER_KEY' ) && BMF_BIOVOICE_WORKER_KEY ) {
			$key = (string) BMF_BIOVOICE_WORKER_KEY;
		} else {
			$key = (string) get_option( 'bmf_biovoice_worker_api_key', '' );
		}
		/**
		 * Filter the worker API key.
		 *
		 * @param string $key
		 */
		$key = (string) apply_filters( 'bmf_biovoice_worker_api_key', $key );
		return trim( $key );
	}

	/**
	 * Ensure a random worker key exists (option). Safe to call on activation.
	 */
	public static function ensure_worker_api_key(): string {
		$existing = (string) get_option( 'bmf_biovoice_worker_api_key', '' );
		if ( $existing !== '' ) {
			return $existing;
		}
		// 32 bytes → 64 hex chars.
		$key = bin2hex( random_bytes( 32 ) );
		update_option( 'bmf_biovoice_worker_api_key', $key, false );
		return $key;
	}

	/**
	 * Extract presented worker key from the request (header preferred).
	 *
	 * Headers:
	 *   X-BMF-BioVoice-Key: <key>
	 *   Authorization: Bearer <key>
	 * Query (fallback only): bmf_worker_key=
	 */
	public static function request_worker_key( WP_REST_Request $request ): string {
		$header = $request->get_header( 'x_bmf_biovoice_key' );
		if ( ! $header ) {
			// Some stacks normalize differently.
			$header = $request->get_header( 'X-BMF-BioVoice-Key' );
		}
		if ( is_string( $header ) && $header !== '' ) {
			return trim( $header );
		}

		$auth = $request->get_header( 'authorization' );
		if ( is_string( $auth ) && preg_match( '/^\s*Bearer\s+(.+)$/i', $auth, $m ) ) {
			return trim( $m[1] );
		}

		$q = $request->get_param( 'bmf_worker_key' );
		if ( is_string( $q ) && $q !== '' ) {
			return trim( $q );
		}
		return '';
	}

	/**
	 * True when the request presents a valid worker API key.
	 */
	public static function request_has_valid_worker_key( WP_REST_Request $request ): bool {
		$expected = self::get_worker_api_key();
		if ( $expected === '' ) {
			return false;
		}
		$presented = self::request_worker_key( $request );
		if ( $presented === '' ) {
			return false;
		}
		return hash_equals( $expected, $presented );
	}

	/**
	 * Whether the current WP user may run the analysis worker API
	 * (Application Password / cookie session path).
	 */
	public static function current_user_can_worker(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( current_user_can( 'bmf_biovoice_worker' ) ) {
			return true;
		}
		// Same staff gate used for ULS member inspection.
		if ( method_exists( 'BMF_BioVoice_Session_Service', 'can_inspect_member_sessions' )
			&& BMF_BioVoice_Session_Service::can_inspect_member_sessions() ) {
			return true;
		}
		return false;
	}

	/**
	 * Combined gate for REST permission_callback.
	 */
	public static function allow_worker_request( WP_REST_Request $request ): bool {
		if ( self::request_has_valid_worker_key( $request ) ) {
			return true;
		}
		return self::current_user_can_worker();
	}

	/**
	 * Normalize wellness answers to the five engine keys (ints 1–5).
	 *
	 * @param array|null $raw
	 * @return array<string,int>
	 */
	public static function normalize_wellness( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $k => $v ) {
			$key = self::WELLNESS_KEY_MAP[ sanitize_key( (string) $k ) ] ?? null;
			if ( ! $key ) {
				continue;
			}
			$n = (int) $v;
			if ( $n < 1 || $n > 5 ) {
				continue;
			}
			$out[ $key ] = $n;
		}
		return $out;
	}

	/**
	 * Build the worker job queue.
	 *
	 * @param int $limit Max jobs to return.
	 * @return array{jobs: array, generated_at: string}
	 */
	public static function build_queue( int $limit = 20 ): array {
		$limit = max( 1, min( 50, $limit ) );
		$jobs  = [];

		// 1) Baseline jobs: users with ≥3 final baseline groups and no stored baseline reference.
		$baseline_jobs = self::find_baseline_jobs( $limit );
		foreach ( $baseline_jobs as $job ) {
			$jobs[] = $job;
			if ( count( $jobs ) >= $limit ) {
				break;
			}
		}

		// 2) Comparison / ongoing jobs: final groups with no stage7 result row.
		if ( count( $jobs ) < $limit ) {
			$cmp = self::find_comparison_jobs( $limit - count( $jobs ) );
			foreach ( $cmp as $job ) {
				$jobs[] = $job;
			}
		}

		return [
			'jobs'         => $jobs,
			'generated_at' => current_time( 'mysql', true ),
			'count'        => count( $jobs ),
		];
	}

	/**
	 * @return array<int, array>
	 */
	private static function find_baseline_jobs( int $limit ): array {
		$db = BMF_BioVoice_DBX::$db;
		$g  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		$r  = BMF_BioVoice_DBX::t( 'bm_biovoice_results' );
		$required = (int) BMF_BioVoice_Session_Service::BASELINE_REQUIRED;

		// Users with ≥3 final baseline groups AND no baseline_reference row yet.
		// Exclusion is done in SQL so a completed baseline cannot re-queue.
		$sql = "
			SELECT g.user_id, COUNT(*) AS n
			FROM {$g} g
			WHERE g.purpose = 'baseline'
			  AND g.is_final = 1
			  AND NOT EXISTS (
				SELECT 1 FROM {$r} r
				WHERE r.user_id = g.user_id
				  AND r.schema_version = 'baseline_reference'
			  )
			GROUP BY g.user_id
			HAVING n >= %d
			ORDER BY MAX(g.completed_at) DESC, g.user_id ASC
			LIMIT %d
		";
		$rows = $db->get_results( $db->prepare( $sql, $required, $limit * 3 ), ARRAY_A );
		if ( ! $rows ) {
			return [];
		}

		$jobs = [];
		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id'];
			// Belt-and-suspenders PHP check (same predicate as SQL).
			if ( BMF_BioVoice_Repository::get_baseline_reference_for_user( $user_id ) ) {
				continue;
			}
			$groups = BMF_BioVoice_Repository::get_final_groups_for_user( $user_id, 'baseline', $required );
			if ( count( $groups ) < $required ) {
				continue;
			}
			$jobs[] = self::format_baseline_job( $user_id, $groups );
			if ( count( $jobs ) >= $limit ) {
				break;
			}
		}
		return $jobs;
	}

	/**
	 * @return array<int, array>
	 */
	private static function find_comparison_jobs( int $limit ): array {
		$db = BMF_BioVoice_DBX::$db;
		$g  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		$r  = BMF_BioVoice_DBX::t( 'bm_biovoice_results' );

		// Final comparison/ongoing groups with no stage7 result for that group.
		// Skip groups already marked analysis_status=failed (until staff unlock clears it).
		$sql = "
			SELECT g.*
			FROM {$g} g
			LEFT JOIN {$r} r
				ON r.session_group_id = g.id
				AND r.schema_version = 'stage7'
			WHERE g.is_final = 1
				AND g.purpose IN ('comparison', 'ongoing')
				AND r.id IS NULL
				AND (g.analysis_status IS NULL OR g.analysis_status = '' OR g.analysis_status = 'ok')
			ORDER BY g.completed_at ASC, g.id ASC
			LIMIT %d
		";
		$rows = $db->get_results( $db->prepare( $sql, $limit ), ARRAY_A );
		if ( ! $rows ) {
			return [];
		}

		$jobs = [];
		foreach ( $rows as $group ) {
			$user_id = (int) $group['user_id'];
			$baseline = BMF_BioVoice_Repository::get_baseline_reference_for_user( $user_id );
			if ( ! $baseline ) {
				// Cannot compare until baseline reference exists.
				continue;
			}
			$jobs[] = self::format_comparison_job( $group, $baseline );
		}
		return $jobs;
	}

	/**
	 * @param array<int, array> $groups Final baseline groups (oldest first).
	 */
	private static function format_baseline_job( int $user_id, array $groups ): array {
		$user = get_userdata( $user_id );
		$bundle_groups = [];
		foreach ( $groups as $g ) {
			$bundle_groups[] = self::format_group_bundle( $g );
		}
		$device = self::guess_device_type( $groups[0] ?? null );

		return [
			'type'           => 'baseline',
			'user_id'        => $user_id,
			'user_email'     => $user ? $user->user_email : ( $groups[0]['user_email'] ?? null ),
			'participant_id' => (string) $user_id,
			'device_type'     => $device,
			'group_ids'      => array_map( static function ( $g ) {
				return (int) $g['id'];
			}, $groups ),
			'groups'         => $bundle_groups,
		];
	}

	private static function format_comparison_job( array $group, array $baseline_row ): array {
		$user_id = (int) $group['user_id'];
		$user    = get_userdata( $user_id );
		$ref     = null;
		if ( ! empty( $baseline_row['pattern_payload_json'] ) ) {
			$ref = json_decode( $baseline_row['pattern_payload_json'], true );
		}
		if ( ! is_array( $ref ) && ! empty( $baseline_row['plain_report_json'] ) ) {
			$ref = json_decode( $baseline_row['plain_report_json'], true );
		}

		return [
			'type'                 => 'comparison',
			'user_id'              => $user_id,
			'user_email'           => $user ? $user->user_email : ( $group['user_email'] ?? null ),
			'participant_id'       => (string) $user_id,
			'device_type'           => self::guess_device_type( $group ),
			'session_group_id'     => (int) $group['id'],
			'purpose'              => $group['purpose'],
			'group'                => self::format_group_bundle( $group ),
			'baseline_result_id'   => (int) $baseline_row['id'],
			'baseline_reference'   => is_array( $ref ) ? $ref : null,
		];
	}

	/**
	 * Group + takes + wellness for the worker.
	 */
	public static function format_group_bundle( array $group ): array {
		$takes = BMF_BioVoice_Repository::get_sessions_for_group( (int) $group['id'] );
		$files = [];
		foreach ( $takes as $t ) {
			$code = $t['task_code'] ?? '';
			if ( ! $code || $code === 'mic_check' ) {
				continue;
			}
			$files[] = [
				'session_id'   => (int) $t['id'],
				'task_code'    => $code,
				'step_number'  => isset( $t['step_number'] ) ? (int) $t['step_number'] : null,
				'duration_sec' => isset( $t['duration_sec'] ) ? (float) $t['duration_sec'] : null,
				'mime_type'    => $t['mime_type'] ?? null,
				'original_filename' => $t['original_filename'] ?? null,
				'download_url' => rest_url( 'bmf-biovoice/v1/worker/sessions/' . (int) $t['id'] . '/audio' ),
			];
		}

		$wellness_raw = null;
		if ( ! empty( $group['wellness_anchor_json'] ) ) {
			$wellness_raw = json_decode( $group['wellness_anchor_json'], true );
		}

		return [
			'group_id'     => (int) $group['id'],
			'purpose'      => $group['purpose'] ?? 'baseline',
			'status'       => $group['status'] ?? null,
			'is_final'     => ! empty( $group['is_final'] ),
			'started_at'   => $group['started_at'] ?? null,
			'completed_at' => $group['completed_at'] ?? null,
			'wellness'     => self::normalize_wellness( is_array( $wellness_raw ) ? $wellness_raw : [] ),
			'wellness_raw' => is_array( $wellness_raw ) ? $wellness_raw : null,
			'files'        => $files,
		];
	}

	private static function guess_device_type( $group ): string {
		if ( ! is_array( $group ) ) {
			return 'Unknown';
		}
		if ( ! empty( $group['device_summary_json'] ) ) {
			$d = json_decode( $group['device_summary_json'], true );
			if ( is_array( $d ) ) {
				if ( ! empty( $d['label'] ) ) {
					return sanitize_text_field( $d['label'] );
				}
				if ( ! empty( $d['mic_label'] ) ) {
					return sanitize_text_field( $d['mic_label'] );
				}
			}
		}
		return 'Unknown';
	}
}
