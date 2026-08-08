<?php
/**
 * BioVoicePrint – Analysis results (plain report + pattern payload).
 * Real engine output wires into the same table later; fixture mode uses bundled samples.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Results_Service {

	/**
	 * Map engine color tokens → CSS modifier class.
	 *
	 * @param string $color
	 * @return string
	 */
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

	/**
	 * Load bundled stage-7 sample plain-language report.
	 *
	 * @return array|null
	 */
	public static function load_fixture_plain_report(): ?array {
		$path = BMF_BIOVOICE_PATH . 'fixtures/sample_plain_language_report.json';
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Load bundled stage-7 sample BSI pattern payload.
	 *
	 * @return array|null
	 */
	public static function load_fixture_pattern_payload(): ?array {
		$path = BMF_BIOVOICE_PATH . 'fixtures/sample_bsi_pattern_payload.json';
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Resolve plain-language report for display.
	 *
	 * Priority:
	 * 1. fixture=1 → bundled sample
	 * 2. latest DB result for user (plain_report_json)
	 *
	 * @param int  $user_id
	 * @param bool $use_fixture
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
					'source'     => 'fixture',
					'result_id'  => 0,
					'analyzed_at'=> null,
					'is_fixture' => true,
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
	 *
	 * Priority:
	 * 1. fixture=1 → bundled sample
	 * 2. latest DB result for user (pattern_payload_json)
	 *
	 * @param int  $user_id
	 * @param bool $use_fixture
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
					'source'     => 'fixture',
					'result_id'  => 0,
					'analyzed_at'=> null,
					'is_fixture' => true,
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

	/**
	 * Persist a result row (engine or admin import).
	 *
	 * @param int   $user_id
	 * @param array $args
	 * @return int|false Result id
	 */
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
			'user_id'                => $user_id,
			'user_email'             => $user ? $user->user_email : null,
			'session_group_id'       => isset( $args['session_group_id'] ) ? absint( $args['session_group_id'] ) : null,
			'comparison_session_id'  => isset( $args['comparison_session_id'] )
				? sanitize_text_field( $args['comparison_session_id'] )
				: ( $plain['comparison_session_id'] ?? null ),
			'schema_version'         => isset( $args['schema_version'] ) ? sanitize_text_field( $args['schema_version'] ) : 'stage7',
			'source'                 => isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'engine',
			'rdi_score'              => $rdi_score,
			'rdi_band'               => $rdi_band,
			'rdi_color'              => $rdi_color,
			'plain_report_json'      => $plain ? wp_json_encode( $plain ) : null,
			'pattern_payload_json'   => $pattern ? wp_json_encode( $pattern ) : null,
			'analyzed_at'            => isset( $args['analyzed_at'] )
				? $args['analyzed_at']
				: current_time( 'mysql', true ),
		];

		// Upsert by session_group_id + schema so re-runs update history cleanly.
		$result_id = BMF_BioVoice_Repository::upsert_result( $row );

		// Stage7 comparison results → WP Fusion tag so Results page can be gated.
		$schema = isset( $row['schema_version'] ) ? (string) $row['schema_version'] : 'stage7';
		if ( $result_id && $schema === 'stage7' ) {
			self::apply_results_ready_tag( $user_id );
			$gid = ! empty( $row['session_group_id'] ) ? (int) $row['session_group_id'] : 0;
			if ( $gid > 0 ) {
				BMF_BioVoice_Repository::set_group_analysis_status( $gid, 'ok' );
			}
			// Clear attention tag if a prior failure had applied it.
			self::remove_analysis_attention_tag( $user_id );
		}

		return $result_id;
	}

	/**
	 * WP Fusion tag(s) applied when a stage7 result is stored.
	 *
	 * Configure (first non-empty wins):
	 *   1. define( 'BMF_BIOVOICE_RESULTS_READY_TAG', 'BioVoicePrint Results Ready' ); in wp-config
	 *   2. option bmf_biovoice_results_ready_tag (string or CSV of tag names/IDs)
	 *   3. filter 'bmf_biovoice_results_ready_tags'
	 *
	 * Default label: "BioVoicePrint Results Ready"
	 *
	 * @param int $user_id
	 * @return bool True if at least one tag was applied (or already present).
	 */
	public static function apply_results_ready_tag( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}

		$tags = self::get_results_ready_tags();
		if ( ! $tags ) {
			return false;
		}

		/**
		 * Fires before WP Fusion tagging (for custom CRM hooks).
		 *
		 * @param int   $user_id
		 * @param array $tags
		 */
		do_action( 'bmf_biovoice_before_results_ready_tag', $user_id, $tags );

		$applied = false;

		// Preferred: WP Fusion global helper.
		if ( function_exists( 'wpf_apply_tags' ) ) {
			wpf_apply_tags( $tags, $user_id );
			$applied = true;
		} elseif ( function_exists( 'wp_fusion' ) ) {
			$wpf = wp_fusion();
			if ( $wpf && isset( $wpf->user ) && method_exists( $wpf->user, 'apply_tags' ) ) {
				$wpf->user->apply_tags( $tags, $user_id );
				$applied = true;
			}
		}

		/**
		 * Fires after attempted tagging.
		 *
		 * @param int   $user_id
		 * @param array $tags
		 * @param bool  $applied
		 */
		do_action( 'bmf_biovoice_after_results_ready_tag', $user_id, $tags, $applied );

		return $applied;
	}

	/**
	 * Resolve tag names/IDs for the Results Ready gate.
	 *
	 * @return array<int, string|int>
	 */
	public static function get_results_ready_tags(): array {
		$raw = '';
		if ( defined( 'BMF_BIOVOICE_RESULTS_READY_TAG' ) && BMF_BIOVOICE_RESULTS_READY_TAG ) {
			$raw = (string) BMF_BIOVOICE_RESULTS_READY_TAG;
		} else {
			$raw = (string) get_option( 'bmf_biovoice_results_ready_tag', 'BioVoicePrint Results Ready' );
		}

		$tags = array_filter( array_map( 'trim', preg_split( '/[,;]+/', $raw ) ?: [] ) );

		/**
		 * Filter the tag list applied when stage7 results are stored.
		 *
		 * @param array $tags
		 */
		$tags = apply_filters( 'bmf_biovoice_results_ready_tags', $tags );

		return is_array( $tags ) ? array_values( $tags ) : [];
	}

	/**
	 * Store or replace the user's baseline_reference.json payload.
	 *
	 * @param int   $user_id
	 * @param array $reference Decoded baseline_reference.json
	 * @param array $args      Optional source, analyzed_at
	 * @return int|false
	 */
	public static function store_baseline_reference( int $user_id, array $reference, array $args = [] ) {
		$user = get_userdata( $user_id );
		$row  = [
			'user_id'               => $user_id,
			'user_email'            => $user ? $user->user_email : null,
			'session_group_id'      => null,
			'comparison_session_id' => null,
			'schema_version'        => 'baseline_reference',
			'source'                => isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'engine',
			'rdi_score'             => null,
			'rdi_band'              => null,
			'rdi_color'             => null,
			'plain_report_json'     => null,
			'pattern_payload_json'  => wp_json_encode( $reference ),
			'analyzed_at'           => isset( $args['analyzed_at'] )
				? $args['analyzed_at']
				: current_time( 'mysql', true ),
		];
		$result_id = BMF_BioVoice_Repository::upsert_result( $row );

		// Mark baseline groups as analysis ok when worker supplies group_ids.
		if ( $result_id && ! empty( $args['group_ids'] ) && is_array( $args['group_ids'] ) ) {
			foreach ( $args['group_ids'] as $gid ) {
				$gid = (int) $gid;
				if ( $gid > 0 ) {
					BMF_BioVoice_Repository::set_group_analysis_status( $gid, 'ok' );
				}
			}
		}

		return $result_id;
	}

	/**
	 * Record a worker analysis failure: group status, Fusion tag, support email.
	 *
	 * @param array $payload {
	 *   user_id:int, job_type:string, message:string,
	 *   session_group_id?:int, group_ids?:int[], task_codes?:string[], detail?:string
	 * }
	 * @return array{ok:bool, emailed:bool, tagged:bool}
	 */
	public static function report_analysis_error( array $payload ): array {
		$user_id  = isset( $payload['user_id'] ) ? (int) $payload['user_id'] : 0;
		$job_type = isset( $payload['job_type'] ) ? sanitize_key( (string) $payload['job_type'] ) : 'unknown';
		$message  = isset( $payload['message'] ) ? sanitize_text_field( (string) $payload['message'] ) : 'Analysis failed';
		$detail   = isset( $payload['detail'] ) ? wp_strip_all_tags( (string) $payload['detail'] ) : '';
		$task_codes = [];
		if ( ! empty( $payload['task_codes'] ) && is_array( $payload['task_codes'] ) ) {
			foreach ( $payload['task_codes'] as $tc ) {
				$task_codes[] = sanitize_key( (string) $tc );
			}
		}

		$group_ids = [];
		if ( ! empty( $payload['session_group_id'] ) ) {
			$group_ids[] = (int) $payload['session_group_id'];
		}
		if ( ! empty( $payload['group_ids'] ) && is_array( $payload['group_ids'] ) ) {
			foreach ( $payload['group_ids'] as $gid ) {
				$group_ids[] = (int) $gid;
			}
		}
		$group_ids = array_values( array_unique( array_filter( $group_ids ) ) );

		$error = [
			'job_type'   => $job_type,
			'message'    => $message,
			'detail'     => $detail,
			'task_codes' => $task_codes,
			'reported_at'=> current_time( 'c', true ),
			'source'     => 'worker',
		];

		foreach ( $group_ids as $gid ) {
			BMF_BioVoice_Repository::set_group_analysis_status( $gid, 'failed', $error );
		}

		$tagged = false;
		if ( $user_id > 0 ) {
			$tagged = self::apply_analysis_attention_tag( $user_id );
		}

		$emailed = self::email_analysis_error( $user_id, $group_ids, $error );

		/**
		 * @param int   $user_id
		 * @param array $group_ids
		 * @param array $error
		 */
		do_action( 'bmf_biovoice_analysis_error', $user_id, $group_ids, $error );

		return [
			'ok'      => true,
			'emailed' => $emailed,
			'tagged'  => $tagged,
			'groups'  => $group_ids,
		];
	}

	/**
	 * Default / configurable attention tag for failed analysis.
	 *
	 * @return array<int, string|int>
	 */
	public static function get_analysis_attention_tags(): array {
		$raw = '';
		if ( defined( 'BMF_BIOVOICE_ANALYSIS_ATTENTION_TAG' ) && BMF_BIOVOICE_ANALYSIS_ATTENTION_TAG ) {
			$raw = (string) BMF_BIOVOICE_ANALYSIS_ATTENTION_TAG;
		} else {
			$raw = (string) get_option( 'bmf_biovoice_analysis_attention_tag', 'BVP_Analysis_Needs_Attention' );
		}
		$tags = array_filter( array_map( 'trim', preg_split( '/[,;]+/', $raw ) ?: [] ) );
		$tags = apply_filters( 'bmf_biovoice_analysis_attention_tags', $tags );
		return is_array( $tags ) ? array_values( $tags ) : [];
	}

	public static function apply_analysis_attention_tag( int $user_id ): bool {
		$tags = self::get_analysis_attention_tags();
		if ( ! $tags || $user_id < 1 ) {
			return false;
		}
		if ( function_exists( 'wpf_apply_tags' ) ) {
			wpf_apply_tags( $tags, $user_id );
			return true;
		}
		if ( function_exists( 'wp_fusion' ) ) {
			$wpf = wp_fusion();
			if ( $wpf && isset( $wpf->user ) && method_exists( $wpf->user, 'apply_tags' ) ) {
				$wpf->user->apply_tags( $tags, $user_id );
				return true;
			}
		}
		return false;
	}

	public static function remove_analysis_attention_tag( int $user_id ): bool {
		$tags = self::get_analysis_attention_tags();
		if ( ! $tags || $user_id < 1 ) {
			return false;
		}
		if ( function_exists( 'wpf_remove_tags' ) ) {
			wpf_remove_tags( $tags, $user_id );
			return true;
		}
		if ( function_exists( 'wp_fusion' ) ) {
			$wpf = wp_fusion();
			if ( $wpf && isset( $wpf->user ) && method_exists( $wpf->user, 'remove_tags' ) ) {
				$wpf->user->remove_tags( $tags, $user_id );
				return true;
			}
		}
		return false;
	}

	/**
	 * Email support about a failed analysis job (throttled per group per hour).
	 */
	public static function email_analysis_error( int $user_id, array $group_ids, array $error ): bool {
		$to = (string) apply_filters(
			'bmf_biovoice_analysis_error_email',
			get_option( 'bmf_biovoice_analysis_error_email', 'support@breathermae.com' )
		);
		if ( ! is_email( $to ) ) {
			return false;
		}

		// Throttle: one email per group (or user) per hour.
		$throttle_key = 'bmf_bv_err_mail_' . md5( $user_id . '|' . implode( ',', $group_ids ) );
		if ( get_transient( $throttle_key ) ) {
			return false;
		}

		$user  = $user_id > 0 ? get_userdata( $user_id ) : null;
		$email = $user ? $user->user_email : '(unknown)';
		$name  = $user ? $user->display_name : '(unknown)';
		$msg   = isset( $error['message'] ) ? $error['message'] : 'Analysis failed';
		$detail = isset( $error['detail'] ) ? $error['detail'] : '';
		$tasks  = ! empty( $error['task_codes'] ) ? implode( ', ', (array) $error['task_codes'] ) : '—';
		$job    = isset( $error['job_type'] ) ? $error['job_type'] : '—';
		$glist  = $group_ids ? implode( ', ', $group_ids ) : '—';

		$subject = sprintf( '[BioVoicePrint] Analysis failed — user %s (groups %s)', $user_id ?: '?', $glist );
		$body    = "BioVoicePrint worker reported an analysis failure.\n\n"
			. "User ID: {$user_id}\n"
			. "Name: {$name}\n"
			. "Email: {$email}\n"
			. "Job type: {$job}\n"
			. "Session group ID(s): {$glist}\n"
			. "Task code(s): {$tasks}\n"
			. "Message: {$msg}\n";
		if ( $detail ) {
			$body .= "\nDetail:\n{$detail}\n";
		}
		$body .= "\nGroups are marked analysis_status=failed and will not re-queue until unlocked.\n"
			. "Admin: ULS member sessions panel → Unlock (or Unlock & clear takes) after investigation.\n";

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
		$sent    = wp_mail( $to, $subject, $body, $headers );
		if ( $sent ) {
			set_transient( $throttle_key, 1, HOUR_IN_SECONDS );
		}
		return (bool) $sent;
	}
}
