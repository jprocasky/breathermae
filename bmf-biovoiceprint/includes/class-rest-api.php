<?php
/**
 * BioVoicePrint – REST API endpoints.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_REST_API {

	const NS = 'bmf-biovoice/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes() {
		register_rest_route( self::NS, '/protocol', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_protocol' ],
			'permission_callback' => [ __CLASS__, 'require_logged_in' ],
		] );

		register_rest_route( self::NS, '/groups', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'ensure_group' ],
				'permission_callback' => [ __CLASS__, 'require_logged_in' ],
			],
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_current_group' ],
				'permission_callback' => [ __CLASS__, 'require_logged_in' ],
			],
		] );

		register_rest_route( self::NS, '/groups/(?P<id>\d+)/wellness', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'save_wellness' ],
			'permission_callback' => [ __CLASS__, 'require_logged_in' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( self::NS, '/groups/(?P<id>\d+)/retake', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'retake_step' ],
			'permission_callback' => [ __CLASS__, 'require_logged_in' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( self::NS, '/groups/(?P<id>\d+)/unlock', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'unlock_group' ],
			'permission_callback' => [ __CLASS__, 'require_logged_in' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( self::NS, '/admin/groups', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'list_admin_groups' ],
			'permission_callback' => [ __CLASS__, 'require_logged_in' ],
		] );

		register_rest_route( self::NS, '/sessions', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_session' ],
				'permission_callback' => [ __CLASS__, 'require_logged_in' ],
			],
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'list_sessions' ],
				'permission_callback' => [ __CLASS__, 'require_logged_in' ],
			],
		] );

		register_rest_route( self::NS, '/sessions/(?P<id>\d+)/play', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'play_session' ],
			'permission_callback' => [ __CLASS__, 'require_logged_in' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( self::NS, '/sessions/(?P<id>\d+)', [
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => [ __CLASS__, 'delete_session' ],
			'permission_callback' => [ __CLASS__, 'require_logged_in' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( self::NS, '/status', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_status' ],
			'permission_callback' => [ __CLASS__, 'require_logged_in' ],
		] );

		// ── Analysis worker (pull-based, Application Password) ──
		register_rest_route( self::NS, '/worker/queue', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'worker_queue' ],
			'permission_callback' => [ __CLASS__, 'require_worker' ],
		] );

		register_rest_route( self::NS, '/worker/groups/(?P<id>\d+)', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'worker_group_bundle' ],
			'permission_callback' => [ __CLASS__, 'require_worker' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( self::NS, '/worker/sessions/(?P<id>\d+)/audio', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'worker_session_audio' ],
			'permission_callback' => [ __CLASS__, 'require_worker' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( self::NS, '/worker/results', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'worker_post_results' ],
			'permission_callback' => [ __CLASS__, 'require_worker' ],
		] );

		register_rest_route( self::NS, '/worker/baseline', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'worker_post_baseline' ],
			'permission_callback' => [ __CLASS__, 'require_worker' ],
		] );

		register_rest_route( self::NS, '/worker/errors', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'worker_post_error' ],
			'permission_callback' => [ __CLASS__, 'require_worker' ],
		] );
	}

	public static function require_logged_in() {
		return is_user_logged_in();
	}

	/**
	 * Worker routes: API key (preferred) OR logged-in admin (Application Password).
	 *
	 * @param WP_REST_Request $request Passed by WP to permission_callback.
	 * @return true|WP_Error
	 */
	public static function require_worker( $request ) {
		if ( $request instanceof WP_REST_Request
			&& BMF_BioVoice_Worker_Service::allow_worker_request( $request ) ) {
			return true;
		}
		// Fallback if callback signature ever omits request.
		if ( BMF_BioVoice_Worker_Service::current_user_can_worker() ) {
			return true;
		}
		return new WP_Error(
			'bmf_biovoice_worker_forbidden',
			'Analysis worker authentication required. Send header X-BMF-BioVoice-Key with the worker API key (recommended), or use an admin Application Password.',
			[ 'status' => 403 ]
		);
	}

	/**
	 * GET /worker/queue?limit=20
	 */
	public static function worker_queue( WP_REST_Request $request ) {
		$limit = absint( $request->get_param( 'limit' ) ) ?: 20;
		return rest_ensure_response( BMF_BioVoice_Worker_Service::build_queue( $limit ) );
	}

	/**
	 * GET /worker/groups/{id} — single group bundle (files + wellness).
	 */
	public static function worker_group_bundle( WP_REST_Request $request ) {
		$id    = absint( $request['id'] );
		$group = BMF_BioVoice_Repository::get_group( $id );
		if ( ! $group ) {
			return new WP_Error( 'bmf_biovoice_not_found', 'Group not found.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( BMF_BioVoice_Worker_Service::format_group_bundle( $group ) );
	}

	/**
	 * GET /worker/sessions/{id}/audio — stream private audio for the worker.
	 */
	public static function worker_session_audio( WP_REST_Request $request ) {
		$id      = absint( $request['id'] );
		$session = BMF_BioVoice_Repository::get_session( $id );
		if ( ! $session ) {
			return new WP_Error( 'bmf_biovoice_not_found', 'Session not found.', [ 'status' => 404 ] );
		}
		$path = BMF_BioVoice_Storage::get_path( $session['storage_key'] );
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error( 'bmf_biovoice_missing_file', 'Audio file is missing.', [ 'status' => 404 ] );
		}

		$mime = ! empty( $session['mime_type'] ) ? $session['mime_type'] : 'application/octet-stream';
		$filename = ! empty( $session['original_filename'] )
			? $session['original_filename']
			: ( 'session_' . $id . '.bin' );

		// Binary response — bypass JSON envelope.
		$response = new WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', $mime );
		$response->header( 'Content-Disposition', 'attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		$response->header( 'Content-Length', (string) filesize( $path ) );
		$response->header( 'Cache-Control', 'no-store' );

		// Stream via send_file-style callback.
		add_filter( 'rest_pre_serve_request', static function ( $served, $result, $request, $server ) use ( $path, $response ) {
			if ( $result !== $response ) {
				return $served;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			readfile( $path );
			return true;
		}, 10, 4 );

		return $response;
	}

	/**
	 * POST /worker/results — stage7 plain report + pattern payload for a comparison group.
	 *
	 * Body JSON:
	 *   user_id, session_group_id, plain_report (object), pattern_payload (object), source?
	 */
	public static function worker_post_results( WP_REST_Request $request ) {
		$user_id = absint( $request->get_param( 'user_id' ) );
		$group_id = absint( $request->get_param( 'session_group_id' ) );
		if ( $user_id < 1 || $group_id < 1 ) {
			return new WP_Error( 'bmf_biovoice_params', 'user_id and session_group_id are required.', [ 'status' => 400 ] );
		}

		$plain = $request->get_param( 'plain_report' );
		$pattern = $request->get_param( 'pattern_payload' );
		if ( is_string( $plain ) ) {
			$plain = json_decode( $plain, true );
		}
		if ( is_string( $pattern ) ) {
			$pattern = json_decode( $pattern, true );
		}
		if ( ! is_array( $plain ) && ! is_array( $pattern ) ) {
			return new WP_Error( 'bmf_biovoice_params', 'plain_report and/or pattern_payload required.', [ 'status' => 400 ] );
		}

		$id = BMF_BioVoice_Results_Service::store_result( $user_id, [
			'session_group_id' => $group_id,
			'plain_report'     => is_array( $plain ) ? $plain : null,
			'pattern_payload'  => is_array( $pattern ) ? $pattern : null,
			'schema_version'   => 'stage7',
			'source'           => sanitize_key( (string) $request->get_param( 'source' ) ) ?: 'engine',
		] );

		if ( ! $id ) {
			return new WP_Error( 'bmf_biovoice_db', 'Failed to store result.', [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'ok'         => true,
			'result_id'  => (int) $id,
			'user_id'    => $user_id,
			'session_group_id' => $group_id,
		] );
	}

	/**
	 * POST /worker/baseline — store baseline_reference.json for a user.
	 *
	 * Body JSON: user_id, baseline_reference (object)
	 */
	public static function worker_post_baseline( WP_REST_Request $request ) {
		$user_id = absint( $request->get_param( 'user_id' ) );
		$ref     = $request->get_param( 'baseline_reference' );
		if ( is_string( $ref ) ) {
			$ref = json_decode( $ref, true );
		}
		if ( $user_id < 1 || ! is_array( $ref ) ) {
			return new WP_Error( 'bmf_biovoice_params', 'user_id and baseline_reference object required.', [ 'status' => 400 ] );
		}

		$group_ids = $request->get_param( 'group_ids' );
		if ( is_string( $group_ids ) ) {
			$group_ids = array_filter( array_map( 'absint', explode( ',', $group_ids ) ) );
		}
		if ( ! is_array( $group_ids ) ) {
			$group_ids = [];
		}

		$id = BMF_BioVoice_Results_Service::store_baseline_reference( $user_id, $ref, [
			'source'    => sanitize_key( (string) $request->get_param( 'source' ) ) ?: 'engine',
			'group_ids' => $group_ids,
		] );

		if ( ! $id ) {
			return new WP_Error( 'bmf_biovoice_db', 'Failed to store baseline reference.', [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'ok'        => true,
			'result_id' => (int) $id,
			'user_id'   => $user_id,
			'schema'    => 'baseline_reference',
		] );
	}

	/**
	 * POST /worker/errors — analysis failure: mark group(s), Fusion tag, support email.
	 *
	 * Body: user_id, job_type, message, session_group_id?, group_ids?, task_codes?, detail?
	 */
	public static function worker_post_error( WP_REST_Request $request ) {
		$user_id = absint( $request->get_param( 'user_id' ) );
		$message = (string) $request->get_param( 'message' );
		if ( $message === '' ) {
			return new WP_Error( 'bmf_biovoice_params', 'message is required.', [ 'status' => 400 ] );
		}

		$group_ids = $request->get_param( 'group_ids' );
		if ( is_string( $group_ids ) ) {
			$group_ids = array_filter( array_map( 'absint', explode( ',', $group_ids ) ) );
		}
		if ( ! is_array( $group_ids ) ) {
			$group_ids = [];
		}

		$task_codes = $request->get_param( 'task_codes' );
		if ( is_string( $task_codes ) ) {
			$task_codes = array_filter( array_map( 'sanitize_key', explode( ',', $task_codes ) ) );
		}
		if ( ! is_array( $task_codes ) ) {
			$task_codes = [];
		}

		$result = BMF_BioVoice_Results_Service::report_analysis_error( [
			'user_id'          => $user_id,
			'job_type'         => (string) $request->get_param( 'job_type' ),
			'message'          => $message,
			'detail'           => (string) $request->get_param( 'detail' ),
			'session_group_id' => absint( $request->get_param( 'session_group_id' ) ),
			'group_ids'        => $group_ids,
			'task_codes'       => $task_codes,
		] );

		return rest_ensure_response( $result );
	}

	public static function get_protocol( WP_REST_Request $request ) {
		$purpose = sanitize_key( (string) $request->get_param( 'purpose' ) ) ?: 'baseline';
		return rest_ensure_response( BMF_BioVoice_Protocol_Service::get_active_payload( $purpose ) );
	}

	public static function ensure_group( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$purpose = sanitize_key( (string) $request->get_param( 'purpose' ) ) ?: 'baseline';
		$wellness = [];
		$wa = $request->get_param( 'wellness_anchor' );
		if ( $wa ) {
			$decoded = is_string( $wa ) ? json_decode( $wa, true ) : $wa;
			if ( is_array( $decoded ) ) {
				$wellness = $decoded;
			}
		}
		$result = BMF_BioVoice_Session_Service::ensure_group( $user_id, $purpose, $wellness );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function get_current_group( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$purpose = sanitize_key( (string) $request->get_param( 'purpose' ) ) ?: 'baseline';
		$group   = BMF_BioVoice_Repository::get_current_group_for_user( $user_id, $purpose );
		if ( ! $group ) {
			return rest_ensure_response( [ 'group' => null ] );
		}
		return rest_ensure_response( BMF_BioVoice_Session_Service::format_group_state( $group ) );
	}

	public static function save_wellness( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$group_id = (int) $request['id'];
		$wa       = $request->get_param( 'wellness_anchor' );
		$wellness = is_string( $wa ) ? json_decode( $wa, true ) : $wa;
		if ( ! is_array( $wellness ) ) {
			return new WP_Error( 'bmf_biovoice_wellness', 'wellness_anchor must be an object.', [ 'status' => 400 ] );
		}
		$result = BMF_BioVoice_Session_Service::save_wellness( $user_id, $group_id, $wellness );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * POST /groups/{id}/retake — hard-delete take(s) and rewind wizard to that step.
	 * Body: { task_code: string, clear_forward?: bool }
	 */
	public static function retake_step( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$group_id = (int) $request['id'];
		$task     = sanitize_key( (string) $request->get_param( 'task_code' ) );
		$forward  = (bool) $request->get_param( 'clear_forward' );

		if ( ! $task ) {
			return new WP_Error( 'bmf_biovoice_task', 'task_code is required.', [ 'status' => 400 ] );
		}

		$result = BMF_BioVoice_Session_Service::retake_from_step( $user_id, $group_id, $task, $forward );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * POST /groups/{id}/unlock — admin/staff only (can_inspect_member_sessions).
	 * Body: { clear_takes?: bool, reason?: string }
	 */
	public static function unlock_group( WP_REST_Request $request ) {
		$group_id    = (int) $request['id'];
		$clear_takes = (bool) $request->get_param( 'clear_takes' );
		$reason      = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );

		$result = BMF_BioVoice_Session_Service::admin_unlock_group( $group_id, $clear_takes, $reason );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * GET /admin/groups — list session groups for a member (inspect permission).
	 * Query: user_id | email, optional purpose, limit.
	 */
	public static function list_admin_groups( WP_REST_Request $request ) {
		if ( ! BMF_BioVoice_Session_Service::can_inspect_member_sessions() ) {
			return new WP_Error( 'bmf_biovoice_forbidden', 'You cannot inspect member groups.', [ 'status' => 403 ] );
		}

		$target_id = 0;
		$req_user  = absint( $request->get_param( 'user_id' ) );
		$req_email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( $req_user > 0 ) {
			$target_id = $req_user;
		} elseif ( $req_email ) {
			$user = get_user_by( 'email', $req_email );
			if ( ! $user ) {
				return new WP_Error( 'bmf_biovoice_user', 'No user found for that email.', [ 'status' => 404 ] );
			}
			$target_id = (int) $user->ID;
		}

		if ( $target_id < 1 ) {
			return new WP_Error( 'bmf_biovoice_user', 'user_id or email is required.', [ 'status' => 400 ] );
		}

		$limit  = min( 100, absint( $request->get_param( 'limit' ) ?: 50 ) );
		$page   = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$offset = absint( $request->get_param( 'offset' ) );
		if ( ! $request->get_param( 'offset' ) && $page > 1 ) {
			$offset = ( $page - 1 ) * $limit;
		}

		$purpose = $request->get_param( 'purpose' );
		$groups  = BMF_BioVoice_Session_Service::list_groups_for_admin( $target_id, [
			'purpose' => $purpose,
			'limit'   => $limit,
			'offset'  => $offset,
		] );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}

		$total = BMF_BioVoice_Repository::count_groups_for_user( $target_id, [
			'purpose' => $purpose ? sanitize_key( (string) $purpose ) : '',
		] );

		$target_user = get_userdata( $target_id );
		return rest_ensure_response( [
			'groups'         => $groups,
			'count'          => count( $groups ),
			'total'          => $total,
			'limit'          => $limit,
			'offset'         => $offset,
			'page'           => $page,
			'pages'          => $limit > 0 ? (int) ceil( $total / $limit ) : 1,
			'target_user_id' => $target_id,
			'target_email'   => $target_user ? $target_user->user_email : null,
			'target_display' => $target_user ? $target_user->display_name : null,
		] );
	}

	public static function create_session( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$files   = $request->get_file_params();
		if ( empty( $files['audio'] ) ) {
			return new WP_Error( 'bmf_biovoice_missing', 'Missing "audio" file field.', [ 'status' => 400 ] );
		}
		$meta = [
			'session_type'     => $request->get_param( 'session_type' ) ?: 'comparison',
			'duration_sec'     => $request->get_param( 'duration_sec' ),
			'device_info'      => $request->get_param( 'device_info' ),
			'device_info_json' => $request->get_param( 'device_info_json' ),
			'session_group_id' => $request->get_param( 'session_group_id' ),
			'task_code'        => $request->get_param( 'task_code' ),
			'notes'            => $request->get_param( 'notes' ),
		];
		$cf = $request->get_param( 'context_flags' );
		if ( $cf ) {
			$decoded = is_string( $cf ) ? json_decode( $cf, true ) : $cf;
			if ( is_array( $decoded ) ) {
				$meta['context_flags'] = $decoded;
			}
		}
		$result = BMF_BioVoice_Session_Service::create_from_upload( $user_id, $files['audio'], $meta );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( [
			'success'    => true,
			'session_id' => $result['session_id'],
			'status'     => $result['status'],
			'task_code'  => $result['task_code'] ?? null,
			'group'      => $result['group'] ?? null,
			'message'    => 'Recording saved.',
		] );
	}

	/**
	 * GET /status — current user, or (with inspect permission) another member.
	 */
	public static function get_status( WP_REST_Request $request ) {
		$current_id  = get_current_user_id();
		$target_id   = $current_id;
		$can_inspect = BMF_BioVoice_Session_Service::can_inspect_member_sessions();

		if ( $can_inspect ) {
			$req_user  = absint( $request->get_param( 'user_id' ) );
			$req_email = sanitize_email( (string) $request->get_param( 'email' ) );

			if ( $req_user > 0 ) {
				$target_id = $req_user;
			} elseif ( $req_email ) {
				$user = get_user_by( 'email', $req_email );
				if ( ! $user ) {
					return new WP_Error( 'bmf_biovoice_user', 'No user found for that email.', [ 'status' => 404 ] );
				}
				$target_id = (int) $user->ID;
			}
		}

		$target = max( 1, absint( $request->get_param( 'comparison_target' ) ?: BMF_BioVoice_Session_Service::COMPARISON_TARGET_DEFAULT ) );
		$summary = BMF_BioVoice_Session_Service::get_status_summary( $target_id, $target );

		$target_user = get_userdata( $target_id );

		return rest_ensure_response( array_merge( $summary, [
			'target_user_id' => $target_id,
			'target_email'   => $target_user ? $target_user->user_email : null,
			'target_display' => $target_user ? $target_user->display_name : null,
		] ) );
	}

	public static function list_sessions( WP_REST_Request $request ) {
		$current_id  = get_current_user_id();
		$target_id   = $current_id;
		$can_inspect = BMF_BioVoice_Session_Service::can_inspect_member_sessions();
		if ( $can_inspect ) {
			$req_user  = absint( $request->get_param( 'user_id' ) );
			$req_email = sanitize_email( (string) $request->get_param( 'email' ) );
			if ( $req_user > 0 ) {
				$target_id = $req_user;
			} elseif ( $req_email ) {
				$user = get_user_by( 'email', $req_email );
				if ( ! $user ) {
					return new WP_Error( 'bmf_biovoice_user', 'No user found for that email.', [ 'status' => 404 ] );
				}
				$target_id = (int) $user->ID;
			}
		}
		$limit  = min( 100, absint( $request->get_param( 'limit' ) ?: 50 ) );
		$page   = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$offset = absint( $request->get_param( 'offset' ) );
		if ( ! $request->get_param( 'offset' ) && $page > 1 ) {
			$offset = ( $page - 1 ) * $limit;
		}
		$args = [
			'limit'  => $limit,
			'offset' => $offset,
		];
		if ( $request->get_param( 'session_type' ) ) {
			$args['session_type'] = sanitize_key( $request->get_param( 'session_type' ) );
		}
		$rows = BMF_BioVoice_Session_Service::get_user_sessions( $target_id, $args );
		$out = [];
		foreach ( $rows as $row ) {
			$out[] = self::format_session_summary( $row, $can_inspect );
		}
		$total = BMF_BioVoice_Repository::count_sessions_for_user( $target_id, $args );
		$target_user = get_userdata( $target_id );
		return rest_ensure_response( [
			'sessions'       => $out,
			'count'          => count( $out ),
			'total'          => $total,
			'limit'          => $limit,
			'offset'         => $offset,
			'page'           => $page,
			'pages'          => $limit > 0 ? (int) ceil( $total / $limit ) : 1,
			'target_user_id' => $target_id,
			'target_email'   => $target_user ? $target_user->user_email : null,
			'target_display' => $target_user ? $target_user->display_name : null,
		] );
	}

	public static function play_session( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$session_id = (int) $request['id'];
		$session    = BMF_BioVoice_Repository::get_session( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'bmf_biovoice_not_found', 'Session not found.', [ 'status' => 404 ] );
		}
		if ( ! BMF_BioVoice_Session_Service::user_can_access_session( $user_id, $session ) ) {
			return new WP_Error( 'bmf_biovoice_forbidden', 'You cannot access this recording.', [ 'status' => 403 ] );
		}
		$path = BMF_BioVoice_Storage::get_path( $session['storage_key'] );
		if ( ! $path ) {
			return new WP_Error( 'bmf_biovoice_missing_file', 'Audio file is missing.', [ 'status' => 404 ] );
		}
		$mime = $session['mime_type'] ?: 'audio/webm';
		$size = filesize( $path );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $size );
		header( 'Content-Disposition: inline; filename="biovoice-' . $session_id . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Cache-Control: private, no-store' );
		$fp = fopen( $path, 'rb' );
		if ( $fp ) {
			fpassthru( $fp );
			fclose( $fp );
		}
		exit;
	}

	public static function delete_session( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$session_id = (int) $request['id'];
		$session    = BMF_BioVoice_Repository::get_session( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'bmf_biovoice_not_found', 'Session not found.', [ 'status' => 404 ] );
		}
		if ( ! BMF_BioVoice_Session_Service::user_can_access_session( $user_id, $session ) ) {
			return new WP_Error( 'bmf_biovoice_forbidden', 'You cannot delete this recording.', [ 'status' => 403 ] );
		}
		if ( (int) $session['user_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'bmf_biovoice_forbidden', 'You cannot delete this recording.', [ 'status' => 403 ] );
		}
		BMF_BioVoice_Storage::delete( $session['storage_key'] );
		BMF_BioVoice_Repository::delete_session( $session_id );
		BMF_BioVoice_Session_Service::invalidate_list_cache( (int) $session['user_id'] );
		return rest_ensure_response( [ 'success' => true, 'message' => 'Session deleted.' ] );
	}

	private static function format_session_summary( array $row, bool $with_debug = false ): array {
		$out = [
			'id'           => (int) $row['id'],
			'session_type' => $row['session_type'],
			'task_code'    => $row['task_code'] ?? null,
			'status'       => $row['status'],
			'duration_sec' => $row['duration_sec'] !== null ? (float) $row['duration_sec'] : null,
			'file_size'    => $row['file_size'] ? (int) $row['file_size'] : null,
			'mime_type'    => $row['mime_type'],
			'created_at'   => $row['created_at'],
			'play_url'     => class_exists( 'BMF_BioVoice_Play' )
				? BMF_BioVoice_Play::url( (int) $row['id'] )
				: rest_url( self::NS . '/sessions/' . (int) $row['id'] . '/play' ),
		];
		if ( $with_debug ) {
			$out['device_info']       = $row['device_info'] ?? null;
			$out['user_id']           = isset( $row['user_id'] ) ? (int) $row['user_id'] : null;
			$out['session_group_id']  = isset( $row['session_group_id'] ) ? (int) $row['session_group_id'] : null;
			if ( ! empty( $row['context_flags_json'] ) ) {
				$flags = json_decode( $row['context_flags_json'], true );
				if ( is_array( $flags ) ) {
					$out['context_flags'] = $flags;
				}
			}
		}
		return $out;
	}
}
