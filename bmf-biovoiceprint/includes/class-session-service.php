<?php
/**
 * BioVoicePrint - Session / group business logic.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Session_Service {

	/** Request-level cache for user session lists. */
	private static $list_cache = [];

	/**
	 * Create or resume an in-progress session group for the user.
	 *
	 * @param int    $user_id
	 * @param string $purpose baseline|comparison
	 * @param array  $wellness Optional wellness anchor (set only when creating).
	 * @return array|WP_Error
	 */
	public static function ensure_group( int $user_id, string $purpose = 'baseline', array $wellness = [] ) {
		if ( $user_id < 1 ) {
			return new WP_Error( 'bmf_biovoice_auth', 'User must be logged in.', [ 'status' => 401 ] );
		}

		$purpose = sanitize_key( $purpose ) ?: 'baseline';
		$current = BMF_BioVoice_Repository::get_current_group_for_user( $user_id, $purpose );
		if ( $current ) {
			return self::format_group_state( $current );
		}

		$payload  = BMF_BioVoice_Protocol_Service::get_active_payload( $purpose );
		$protocol = $payload['protocol'];
		if ( ! $protocol ) {
			return new WP_Error( 'bmf_biovoice_protocol', 'No active protocol configured.', [ 'status' => 500 ] );
		}

		$user = get_userdata( $user_id );
		BMF_BioVoice_Repository::clear_current_groups( $user_id, $purpose );

		$row = [
			'user_id'           => $user_id,
			'user_email'        => $user ? $user->user_email : null,
			'protocol_id'       => (int) $protocol['id'],
			'protocol_version'  => $protocol['version'],
			'purpose'           => $purpose,
			'status'            => 'in_progress',
			'is_current'        => 1,
			'is_final'          => 0,
		];
		if ( $wellness ) {
			$row['wellness_anchor_json'] = wp_json_encode( $wellness );
		}

		$group_id = BMF_BioVoice_Repository::insert_group( $row );
		if ( ! $group_id ) {
			return new WP_Error( 'bmf_biovoice_db', 'Failed to create session group.', [ 'status' => 500 ] );
		}

		$group = BMF_BioVoice_Repository::get_group( $group_id );
		return self::format_group_state( $group );
	}

	/**
	 * Attach wellness answers to an in-progress group (once).
	 */
	public static function save_wellness( int $user_id, int $group_id, array $wellness ) {
		$group = BMF_BioVoice_Repository::get_group( $group_id );
		if ( ! $group || (int) $group['user_id'] !== $user_id ) {
			return new WP_Error( 'bmf_biovoice_forbidden', 'Group not found.', [ 'status' => 404 ] );
		}
		if ( $group['status'] !== 'in_progress' ) {
			return new WP_Error( 'bmf_biovoice_state', 'Group is not in progress.', [ 'status' => 400 ] );
		}
		BMF_BioVoice_Repository::update_group( $group_id, [
			'wellness_anchor_json' => wp_json_encode( $wellness ),
		] );
		return self::format_group_state( BMF_BioVoice_Repository::get_group( $group_id ) );
	}

	/**
	 * Hard-delete one completed task (or that task + all later steps) so the user can retake.
	 *
	 * Only allowed while the group is still in_progress. Completed / final groups are locked.
	 *
	 * @param int    $user_id
	 * @param int    $group_id
	 * @param string $task_code      Protocol task_code to rewind to.
	 * @param bool   $clear_forward  When true, also delete every later protocol step's take.
	 * @return array|WP_Error        Updated group state on success.
	 */
	public static function retake_from_step( int $user_id, int $group_id, string $task_code, bool $clear_forward = false ) {
		if ( $user_id < 1 ) {
			return new WP_Error( 'bmf_biovoice_auth', 'User must be logged in.', [ 'status' => 401 ] );
		}

		$task_code = sanitize_key( $task_code );
		if ( ! $task_code || $task_code === 'mic_check' ) {
			return new WP_Error( 'bmf_biovoice_task', 'Invalid task for retake.', [ 'status' => 400 ] );
		}

		$group = BMF_BioVoice_Repository::get_group( $group_id );
		if ( ! $group || (int) $group['user_id'] !== $user_id ) {
			return new WP_Error( 'bmf_biovoice_forbidden', 'Group not found.', [ 'status' => 404 ] );
		}

		// Completed groups are locked - no retakes after finalization.
		if ( $group['status'] !== 'in_progress' || ! empty( $group['is_final'] ) ) {
			return new WP_Error(
				'bmf_biovoice_locked',
				'This session is complete and locked. Retakes are only available while recording is in progress.',
				[ 'status' => 400 ]
			);
		}

		$steps = BMF_BioVoice_Repository::get_steps_for_protocol( (int) $group['protocol_id'] );
		$target_step = null;
		$target_index = -1;
		foreach ( $steps as $i => $s ) {
			if ( $s['task_code'] === $task_code ) {
				$target_step  = $s;
				$target_index = $i;
				break;
			}
		}

		if ( ! $target_step ) {
			return new WP_Error( 'bmf_biovoice_task', 'Task is not part of this protocol.', [ 'status' => 400 ] );
		}

		if ( empty( $target_step['allow_retake'] ) ) {
			return new WP_Error( 'bmf_biovoice_retake', 'This step does not allow retakes.', [ 'status' => 400 ] );
		}

		// Build the set of task_codes to hard-delete.
		$codes_to_clear = [];
		if ( $clear_forward ) {
			for ( $i = $target_index; $i < count( $steps ); $i++ ) {
				$code = $steps[ $i ]['task_code'];
				if ( $code === 'mic_check' ) {
					continue;
				}
				$codes_to_clear[ $code ] = true;
			}
		} else {
			$codes_to_clear[ $task_code ] = true;
		}

		$takes   = BMF_BioVoice_Repository::get_sessions_for_group( $group_id );
		$deleted = 0;

		foreach ( $takes as $take ) {
			$code = $take['task_code'] ?? '';
			if ( ! $code || empty( $codes_to_clear[ $code ] ) ) {
				continue;
			}
			if ( ! empty( $take['storage_key'] ) ) {
				BMF_BioVoice_Storage::delete( $take['storage_key'] );
			}
			BMF_BioVoice_Repository::delete_session( (int) $take['id'] );
			$deleted++;
		}

		if ( $deleted < 1 ) {
			return new WP_Error(
				'bmf_biovoice_retake',
				'No saved recording found for that step.',
				[ 'status' => 400 ]
			);
		}

		self::invalidate_list_cache( $user_id );

		$state = self::format_group_state( BMF_BioVoice_Repository::get_group( $group_id ) );
		$state['retake'] = [
			'task_code'     => $task_code,
			'clear_forward' => $clear_forward,
			'deleted'       => $deleted,
		];
		return $state;
	}

	/**
	 * Group progress payload for the client.
	 */
	public static function format_group_state( array $group ): array {
		$takes = BMF_BioVoice_Repository::get_sessions_for_group( (int) $group['id'] );
		$done  = [];
		foreach ( $takes as $t ) {
			if ( ! empty( $t['task_code'] ) ) {
				$done[ $t['task_code'] ] = (int) $t['id'];
			}
		}

		$steps     = BMF_BioVoice_Repository::get_steps_for_protocol( (int) $group['protocol_id'] );
		$next_step = null;
		foreach ( $steps as $s ) {
			$code = $s['task_code'];
			// mic_check is client-only gate - never stored as a take.
			if ( $code === 'mic_check' ) {
				continue;
			}
			if ( empty( $done[ $code ] ) ) {
				$next_step = BMF_BioVoice_Protocol_Service::format_step( $s );
				break;
			}
		}

		$complete = ( $next_step === null && ! empty( $steps ) );
		$final_n  = BMF_BioVoice_Repository::count_final_groups( (int) $group['user_id'], $group['purpose'] );

		// Include final status in progress count once this group is finalized.
		$progress_n = $final_n;
		if ( $complete && empty( $group['is_final'] ) ) {
			$progress_n = $final_n + 1;
		}

		$formatted_steps = [];
		foreach ( $steps as $s ) {
			$formatted_steps[] = BMF_BioVoice_Protocol_Service::format_step( $s );
		}

		return [
			'group_id'          => (int) $group['id'],
			'protocol_id'       => (int) $group['protocol_id'],
			'protocol_version'  => $group['protocol_version'],
			'purpose'           => $group['purpose'],
			'status'            => $group['status'],
			'is_final'          => (bool) $group['is_final'],
			'device_mismatch'   => (bool) $group['device_mismatch'],
			'wellness_anchor'   => $group['wellness_anchor_json']
				? json_decode( $group['wellness_anchor_json'], true )
				: null,
			'completed_tasks'   => $done,
			'next_step'         => $next_step,
			'steps'             => $formatted_steps,
			'is_group_complete' => $complete,
			'baseline_progress' => [
				'complete_groups' => $progress_n,
				'required'        => 3,
			],
			'started_at'        => $group['started_at'],
			'updated_at'        => $group['updated_at'],
		];
	}

	/**
	 * Create a take from upload; validates min duration when task is known.
	 *
	 * @param int   $user_id
	 * @param array $file     $_FILES-style
	 * @param array $meta     session_type, duration_sec, device_info, device_info_json,
	 *                        session_group_id, task_code, notes, wellness_anchor, context_flags
	 * @return array|WP_Error
	 */
	public static function create_from_upload( int $user_id, array $file, array $meta = [] ) {
		if ( $user_id < 1 ) {
			return new WP_Error( 'bmf_biovoice_auth', 'User must be logged in.', [ 'status' => 401 ] );
		}

		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new WP_Error( 'bmf_biovoice_upload', 'No valid audio file received.', [ 'status' => 400 ] );
		}

		$allowed_mimes = [
			'audio/webm'               => 'webm',
			'audio/wav'                => 'wav',
			'audio/x-wav'              => 'wav',
			'audio/wave'               => 'wav',
			'audio/mpeg'               => 'mp3',
			'audio/mp3'                => 'mp3',
			'audio/mp4'                => 'm4a',
			'audio/m4a'                => 'm4a',
			'audio/x-m4a'              => 'm4a',
			'audio/aac'                => 'm4a',
			'audio/x-aac'              => 'm4a',
			'audio/ogg'                => 'ogg',
			'video/webm'               => 'webm',
			'video/mp4'                => 'm4a',
			'application/octet-stream' => null,
		];

		$allowed_exts = [ 'webm', 'wav', 'mp3', 'm4a', 'mp4', 'aac', 'ogg', 'caf' ];

		$mime_raw = isset( $file['type'] ) ? strtolower( trim( $file['type'] ) ) : '';
		$mime     = $mime_raw ? trim( explode( ';', $mime_raw )[0] ) : '';

		$ext = null;
		if ( $mime && array_key_exists( $mime, $allowed_mimes ) && $allowed_mimes[ $mime ] ) {
			$ext = $allowed_mimes[ $mime ];
		}

		if ( ! $ext ) {
			$orig      = isset( $file['name'] ) ? $file['name'] : '';
			$from_name = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
			if ( in_array( $from_name, $allowed_exts, true ) ) {
				$ext = in_array( $from_name, [ 'mp4', 'aac', 'caf' ], true ) ? 'm4a' : $from_name;
			}
		}

		if ( ! $ext ) {
			return new WP_Error(
				'bmf_biovoice_mime',
				'Unsupported audio format' . ( $mime ? ' (' . $mime . ')' : '' ) . '.',
				[ 'status' => 400 ]
			);
		}

		if ( ! $mime || $mime === 'application/octet-stream' ) {
			$mime = ( $ext === 'm4a' ) ? 'audio/mp4' : ( 'audio/' . $ext );
		}

		$max_bytes = 20 * 1024 * 1024;
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
			return new WP_Error( 'bmf_biovoice_size', 'File exceeds 20 MB limit.', [ 'status' => 400 ] );
		}

		$task_code  = isset( $meta['task_code'] ) ? sanitize_key( $meta['task_code'] ) : '';
		$group_id   = isset( $meta['session_group_id'] ) ? absint( $meta['session_group_id'] ) : 0;
		$duration   = isset( $meta['duration_sec'] ) ? (float) $meta['duration_sec'] : null;
		$group      = null;
		$step       = null;
		$protocol_id = null;

		if ( $task_code === 'mic_check' ) {
			return new WP_Error(
				'bmf_biovoice_mic_check',
				'Mic check is client-side only and is not stored.',
				[ 'status' => 400 ]
			);
		}

		if ( $group_id > 0 ) {
			$group = BMF_BioVoice_Repository::get_group( $group_id );
			if ( ! $group || (int) $group['user_id'] !== $user_id ) {
				return new WP_Error( 'bmf_biovoice_group', 'Invalid session group.', [ 'status' => 400 ] );
			}
			if ( $group['status'] !== 'in_progress' ) {
				return new WP_Error( 'bmf_biovoice_group', 'Session group is not open for new takes.', [ 'status' => 400 ] );
			}
			$protocol_id = (int) $group['protocol_id'];

			if ( $task_code ) {
				$step = BMF_BioVoice_Repository::get_step_by_task( $protocol_id, $task_code );
				if ( ! $step ) {
					return new WP_Error( 'bmf_biovoice_task', 'Unknown task for this protocol.', [ 'status' => 400 ] );
				}
				$min = (float) $step['min_seconds'];
				if ( $duration !== null && $min > 0 && $duration + 0.05 < $min ) {
					return new WP_Error(
						'bmf_biovoice_too_short',
						sprintf( 'Recording too short. Minimum is %s seconds - please retake.', $min ),
						[ 'status' => 400, 'min_seconds' => $min, 'duration_sec' => $duration ]
					);
				}
			}
		}

		$storage_key = BMF_BioVoice_Storage::store_from_tmp( $user_id, $file['tmp_name'], $ext );
		if ( ! $storage_key ) {
			return new WP_Error( 'bmf_biovoice_store', 'Failed to store audio file.', [ 'status' => 500 ] );
		}

		$user       = get_userdata( $user_id );
		$user_email = $user ? $user->user_email : null;

		$device_json = null;
		if ( ! empty( $meta['device_info_json'] ) ) {
			if ( is_string( $meta['device_info_json'] ) ) {
				$decoded = json_decode( $meta['device_info_json'], true );
				$device_json = is_array( $decoded ) ? $decoded : null;
			} elseif ( is_array( $meta['device_info_json'] ) ) {
				$device_json = $meta['device_info_json'];
			}
		}

		$row = [
			'user_id'           => $user_id,
			'user_email'        => $user_email,
			'session_type'      => isset( $meta['session_type'] ) ? sanitize_key( $meta['session_type'] ) : ( $group['purpose'] ?? 'comparison' ),
			'status'            => 'recorded',
			'storage_key'       => $storage_key,
			'original_filename' => isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : null,
			'mime_type'         => $mime ?: null,
			'file_size'         => isset( $file['size'] ) ? (int) $file['size'] : BMF_BioVoice_Storage::get_size( $storage_key ),
			'duration_sec'      => $duration,
			'device_info'       => isset( $meta['device_info'] ) ? sanitize_textarea_field( $meta['device_info'] ) : null,
			'notes'             => isset( $meta['notes'] ) ? sanitize_textarea_field( $meta['notes'] ) : null,
		];

		if ( $group_id > 0 ) {
			$row['session_group_id'] = $group_id;
			$row['protocol_id']      = $protocol_id;
		}
		if ( $task_code ) {
			$row['task_code'] = $task_code;
		}
		if ( $step ) {
			$row['step_number'] = (int) $step['step_number'];
		}
		if ( $device_json ) {
			$row['device_info_json'] = wp_json_encode( $device_json );
		}

		if ( ! empty( $meta['wellness_anchor'] ) && is_array( $meta['wellness_anchor'] ) ) {
			$row['wellness_anchor_json'] = wp_json_encode( $meta['wellness_anchor'] );
		}
		if ( ! empty( $meta['context_flags'] ) && is_array( $meta['context_flags'] ) ) {
			$row['context_flags_json'] = wp_json_encode( $meta['context_flags'] );
		}

		$session_id = BMF_BioVoice_Repository::insert_session( $row );
		if ( ! $session_id ) {
			BMF_BioVoice_Storage::delete( $storage_key );
			return new WP_Error( 'bmf_biovoice_db', 'Failed to create session record.', [ 'status' => 500 ] );
		}

		$group_state = null;
		if ( $group ) {
			self::update_group_after_take( $group, $device_json );
			$group_state = self::format_group_state( BMF_BioVoice_Repository::get_group( (int) $group['id'] ) );

			// Auto-complete group when all non-mic_check steps have takes.
			if ( ! empty( $group_state['is_group_complete'] ) && $group['status'] === 'in_progress' ) {
				BMF_BioVoice_Repository::update_group( (int) $group['id'], [
					'status'       => 'complete',
					'is_final'     => 1,
					'is_current'   => 0,
					'completed_at' => current_time( 'mysql', true ),
				] );
				$group_state = self::format_group_state( BMF_BioVoice_Repository::get_group( (int) $group['id'] ) );
			}
		}

		self::invalidate_list_cache( $user_id );

		return [
			'session_id'  => $session_id,
			'storage_key' => $storage_key,
			'status'      => 'recorded',
			'task_code'   => $task_code ?: null,
			'group'       => $group_state,
		];
	}

	/**
	 * Roll up device summary + mismatch flag after a take.
	 */
	private static function update_group_after_take( array $group, $device_json ) {
		$updates = [];

		if ( is_array( $device_json ) && $device_json ) {
			$summary = $group['device_summary_json']
				? json_decode( $group['device_summary_json'], true )
				: null;

			if ( ! is_array( $summary ) || empty( $summary ) ) {
				$updates['device_summary_json'] = wp_json_encode( $device_json );
			} else {
				$prev_class = $summary['device_class'] ?? '';
				$new_class  = $device_json['device_class'] ?? '';
				$prev_mic   = $summary['mic_label'] ?? '';
				$new_mic    = $device_json['mic_label'] ?? '';
				if ( ( $prev_class && $new_class && $prev_class !== $new_class )
					|| ( $prev_mic && $new_mic && $prev_mic !== $new_mic ) ) {
					$updates['device_mismatch'] = 1;
				}
			}
		}

		if ( $updates ) {
			BMF_BioVoice_Repository::update_group( (int) $group['id'], $updates );
		}
	}

	public static function get_user_sessions( int $user_id, array $args = [] ) {
		$cache_key = $user_id . '|' . md5( wp_json_encode( $args ) );
		if ( isset( self::$list_cache[ $cache_key ] ) ) {
			return self::$list_cache[ $cache_key ];
		}
		$rows = BMF_BioVoice_Repository::get_sessions_for_user( $user_id, $args );
		self::$list_cache[ $cache_key ] = $rows;
		return $rows;
	}

	public static function can_inspect_member_sessions(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return (bool) apply_filters( 'bmf_biovoice_can_inspect_member_sessions', true );
	}

	public static function user_can_access_session( int $user_id, array $session ): bool {
		if ( empty( $session['user_id'] ) ) {
			return false;
		}
		if ( (int) $session['user_id'] === $user_id ) {
			return true;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		if ( (int) $user_id === get_current_user_id() && self::can_inspect_member_sessions() ) {
			return true;
		}
		return false;
	}

	public static function invalidate_list_cache( int $user_id = 0 ) {
		if ( $user_id > 0 ) {
			foreach ( array_keys( self::$list_cache ) as $k ) {
				if ( strpos( $k, $user_id . '|' ) === 0 ) {
					unset( self::$list_cache[ $k ] );
				}
			}
		} else {
			self::$list_cache = [];
		}
	}

	/** Baseline groups required to establish baseline. */
	public const BASELINE_REQUIRED = 3;

	/** Soft target for comparison groups (display / product; not a hard cap). */
	public const COMPARISON_TARGET_DEFAULT = 6;

	/**
	 * Status panel payload for a user.
	 *
	 * Phases: baseline → comparison → ongoing.
	 *
	 * @param int $user_id
	 * @param int $comparison_target Soft target for comparison nodes (default 6).
	 * @return array
	 */
	public static function get_status_summary( int $user_id, int $comparison_target = 0 ): array {
		$baseline_required  = self::BASELINE_REQUIRED;
		$comparison_target  = $comparison_target > 0 ? $comparison_target : self::COMPARISON_TARGET_DEFAULT;

		$baseline_done   = BMF_BioVoice_Repository::count_final_groups( $user_id, 'baseline' );
		$comparison_done = BMF_BioVoice_Repository::count_final_groups( $user_id, 'comparison' );
		$ongoing_done    = BMF_BioVoice_Repository::count_final_groups( $user_id, 'ongoing' );
		$mismatch_n      = BMF_BioVoice_Repository::count_device_mismatch_groups( $user_id );

		// In-progress group: prefer active phase purpose.
		if ( $baseline_done < $baseline_required ) {
			$phase = 'baseline';
		} elseif ( $comparison_done < $comparison_target ) {
			$phase = 'comparison';
		} else {
			$phase = 'ongoing';
		}

		$current = BMF_BioVoice_Repository::get_current_group_for_user( $user_id, $phase );
		if ( ! $current ) {
			// Fallback: any in-progress group.
			$current = BMF_BioVoice_Repository::get_current_group_for_user( $user_id, '' );
		}

		$current_state = null;
		if ( $current ) {
			$current_state = self::format_group_state( $current );
		}

		$baseline_pct   = min( 100, (int) round( ( $baseline_done / max( 1, $baseline_required ) ) * 100 ) );
		$comparison_pct = min( 100, (int) round( ( $comparison_done / max( 1, $comparison_target ) ) * 100 ) );

		$headline = 'Get started';
		if ( $phase === 'baseline' ) {
			$headline = $baseline_done >= $baseline_required
				? 'Baseline complete'
				: sprintf( 'Baseline in progress · %d of %d', $baseline_done, $baseline_required );
		} elseif ( $phase === 'comparison' ) {
			$headline = sprintf( 'Comparison series · %d of %d', $comparison_done, $comparison_target );
		} else {
			$headline = $ongoing_done > 0
				? sprintf( 'Ongoing · %d session%s', $ongoing_done, $ongoing_done === 1 ? '' : 's' )
				: 'Comparison complete · ongoing available';
		}

		$next_label = null;
		if ( $current_state && ! empty( $current_state['next_step']['title'] ) ) {
			$next_label = $current_state['next_step']['title'];
		} elseif ( $current_state && ! empty( $current_state['is_group_complete'] ) ) {
			$next_label = 'Session complete';
		} elseif ( ! $current_state ) {
			if ( $phase === 'baseline' && $baseline_done < $baseline_required ) {
				$next_label = 'Start next baseline session';
			} elseif ( $phase === 'comparison' ) {
				$next_label = 'Start next comparison session';
			} else {
				$next_label = 'Start an ongoing session';
			}
		}

		return [
			'phase'              => $phase,
			'headline'           => $headline,
			'next_label'         => $next_label,
			'baseline'           => [
				'done'     => $baseline_done,
				'required' => $baseline_required,
				'pct'      => $baseline_pct,
				'complete' => $baseline_done >= $baseline_required,
			],
			'comparison'         => [
				'done'     => $comparison_done,
				'target'   => $comparison_target,
				'pct'      => $comparison_pct,
				'complete' => $comparison_done >= $comparison_target,
			],
			'ongoing'            => [
				'done' => $ongoing_done,
			],
			'device_mismatch'    => $mismatch_n > 0,
			'device_mismatch_n'  => $mismatch_n,
			'current_group'      => $current_state,
		];
	}
}
