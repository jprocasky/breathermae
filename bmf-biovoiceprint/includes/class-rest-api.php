<?php
/**
 * BioVoicePrint – REST API endpoints.
 *
 * Routes:
 *   POST   /wp-json/bmf-biovoice/v1/sessions          → upload audio + create session
 *   GET    /wp-json/bmf-biovoice/v1/sessions          → list current user's sessions
 *   GET    /wp-json/bmf-biovoice/v1/sessions/{id}/play → stream audio (owner or admin)
 *   DELETE /wp-json/bmf-biovoice/v1/sessions/{id}     → delete session + file
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

	/**
	 * POST /sessions
	 * Expects multipart/form-data with field "audio".
	 * Optional fields: session_type, duration_sec, device_info, notes
	 */
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

		// Optional JSON fields (stringified by the client).
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
	 * GET /sessions
	 */
	public static function list_sessions( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$args    = [
			'limit'  => min( 100, absint( $request->get_param( 'limit' ) ?: 50 ) ),
			'offset' => absint( $request->get_param( 'offset' ) ?: 0 ),
		];
		if ( $request->get_param( 'session_type' ) ) {
			$args['session_type'] = sanitize_key( $request->get_param( 'session_type' ) );
		}

		$rows = BMF_BioVoice_Session_Service::get_user_sessions( $user_id, $args );

		$out = [];
		foreach ( $rows as $row ) {
			$out[] = self::format_session_summary( $row );
		}

		return rest_ensure_response( [
			'sessions' => $out,
			'count'    => count( $out ),
		] );
	}

	/**
	 * GET /sessions/{id}/play
	 * Streams the audio file with correct Content-Type.
	 */
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

		// Stream the file.
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $size );
		header( 'Content-Disposition: inline; filename="biovoice-' . $session_id . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Cache-Control: private, no-store' );

		// Simple full-file stream for POC (range support can be added later).
		$fp = fopen( $path, 'rb' );
		if ( $fp ) {
			fpassthru( $fp );
			fclose( $fp );
		}
		exit;
	}

	/**
	 * DELETE /sessions/{id}
	 */
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

		// Only owner or admin for now.
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

	/**
	 * Public-safe session summary (no storage_key).
	 */
	private static function format_session_summary( array $row ): array {
		return [
			'id'                => (int) $row['id'],
			'session_type'      => $row['session_type'],
			'status'            => $row['status'],
			'duration_sec'      => $row['duration_sec'] !== null ? (float) $row['duration_sec'] : null,
			'file_size'         => $row['file_size'] ? (int) $row['file_size'] : null,
			'mime_type'         => $row['mime_type'],
			'original_filename' => $row['original_filename'],
			'created_at'        => $row['created_at'],
			'play_url'          => rest_url( self::NS . '/sessions/' . (int) $row['id'] . '/play' ),
		];
	}
}
