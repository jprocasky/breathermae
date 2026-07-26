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
				'id' => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( self::NS, '/sessions/(?P<id>\d+)', [
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => [ __CLASS__, 'delete_session' ],
			'permission_callback' => [ __CLASS__, 'require_logged_in' ],
			'args'                => [
				'id' => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	public static function require_logged_in() {
		return is_user_logged_in();
	}

	public static function create_session( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$files   = $request->get_file_params();

		if ( empty( $files['audio'] ) ) {
			return new WP_Error( 'bmf_biovoice_missing', 'Missing "audio" file field.', [ 'status' => 400 ] );
		}

		$meta = [
			'session_type' => $request->get_param( 'session_type' ) ?: 'comparison',
			'duration_sec' => $request->get_param( 'duration_sec' ),
			'device_info'  => $request->get_param( 'device_info' ),
			'notes'        => $request->get_param( 'notes' ),
		];

		$wa = $request->get_param( 'wellness_anchor' );
		if ( $wa ) {
			$decoded = is_string( $wa ) ? json_decode( $wa, true ) : $wa;
			if ( is_array( $decoded ) ) {
				$meta['wellness_anchor'] = $decoded;
			}
		}
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
			'message'    => 'Recording saved.',
		] );
	}

	/**
	 * GET /sessions — staff may pass user_id or email to inspect another member.
	 */
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

		$args = [
			'limit'  => min( 100, absint( $request->get_param( 'limit' ) ?: 50 ) ),
			'offset' => absint( $request->get_param( 'offset' ) ?: 0 ),
		];
		if ( $request->get_param( 'session_type' ) ) {
			$args['session_type'] = sanitize_key( $request->get_param( 'session_type' ) );
		}

		$rows = BMF_BioVoice_Session_Service::get_user_sessions( $target_id, $args );

		$out = [];
		foreach ( $rows as $row ) {
			$out[] = self::format_session_summary( $row, $can_inspect );
		}

		$target_user = get_userdata( $target_id );

		return rest_ensure_response( [
			'sessions'       => $out,
			'count'          => count( $out ),
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

		// Delete remains owner or site admin only.
		if ( (int) $session['user_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'bmf_biovoice_forbidden', 'You cannot delete this recording.', [ 'status' => 403 ] );
		}

		BMF_BioVoice_Storage::delete( $session['storage_key'] );
		BMF_BioVoice_Repository::delete_session( $session_id );
		BMF_BioVoice_Session_Service::invalidate_list_cache( (int) $session['user_id'] );

		return rest_ensure_response( [
			'success' => true,
			'message' => 'Session deleted.',
		] );
	}

	private static function format_session_summary( array $row, bool $with_debug = false ): array {
		$out = [
			'id'                => (int) $row['id'],
			'session_type'      => $row['session_type'],
			'status'            => $row['status'],
			'duration_sec'      => $row['duration_sec'] !== null ? (float) $row['duration_sec'] : null,
			'file_size'         => $row['file_size'] ? (int) $row['file_size'] : null,
			'mime_type'         => $row['mime_type'],
			'original_filename' => $row['original_filename'],
			'created_at'        => $row['created_at'],
			'play_url'          => class_exists( 'BMF_BioVoice_Play' )
				? BMF_BioVoice_Play::url( (int) $row['id'] )
				: rest_url( self::NS . '/sessions/' . (int) $row['id'] . '/play' ),
		];

		if ( $with_debug ) {
			$out['device_info'] = $row['device_info'] ?? null;
			$out['user_id']     = isset( $row['user_id'] ) ? (int) $row['user_id'] : null;
		}

		return $out;
	}
}
