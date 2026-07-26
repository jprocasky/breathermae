<?php
/**
 * BioVoicePrint – reliable audio playback outside the REST JSON stack.
 *
 * REST is fine for upload/list/delete, but browsers request <audio src>
 * as a plain GET. Streaming binary through WP REST is fragile
 * (headers already committed, JSON content-type, etc.).
 *
 * This handler uses a simple query var:
 *   ?bmf_biovoice_play={session_id}
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Play {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_query_var' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_stream' ] );
	}

	public static function register_query_var() {
		global $wp;
		$wp->add_query_var( 'bmf_biovoice_play' );
	}

	/**
	 * Build a play URL for a session (used by shortcodes + REST summaries).
	 */
	public static function url( int $session_id ): string {
		return add_query_arg(
			[
				'bmf_biovoice_play' => $session_id,
			],
			home_url( '/' )
		);
	}

	/**
	 * If the query var is present, stream the file and exit.
	 */
	public static function maybe_stream() {
		$session_id = get_query_var( 'bmf_biovoice_play' );
		if ( $session_id === '' || $session_id === null ) {
			// Also accept raw $_GET in case query var registration lags.
			if ( empty( $_GET['bmf_biovoice_play'] ) ) {
				return;
			}
			$session_id = $_GET['bmf_biovoice_play'];
		}

		$session_id = absint( $session_id );
		if ( $session_id < 1 ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			status_header( 401 );
			nocache_headers();
			wp_die( 'Authentication required.', 'BioVoicePrint', [ 'response' => 401 ] );
		}

		$user_id = get_current_user_id();
		$session = BMF_BioVoice_Repository::get_session( $session_id );

		if ( ! $session ) {
			status_header( 404 );
			nocache_headers();
			wp_die( 'Session not found.', 'BioVoicePrint', [ 'response' => 404 ] );
		}

		if ( ! BMF_BioVoice_Session_Service::user_can_access_session( $user_id, $session ) ) {
			status_header( 403 );
			nocache_headers();
			wp_die( 'You cannot access this recording.', 'BioVoicePrint', [ 'response' => 403 ] );
		}

		$path = BMF_BioVoice_Storage::get_path( $session['storage_key'] );
		if ( ! $path || ! is_readable( $path ) ) {
			status_header( 404 );
			nocache_headers();
			wp_die( 'Audio file is missing.', 'BioVoicePrint', [ 'response' => 404 ] );
		}

		$mime = ! empty( $session['mime_type'] ) ? $session['mime_type'] : 'audio/webm';
		$size = filesize( $path );

		// Clear any buffered output so the audio bytes are clean.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $size );
		header( 'Content-Disposition: inline; filename="biovoice-' . $session_id . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );

		$fp = fopen( $path, 'rb' );
		if ( $fp ) {
			fpassthru( $fp );
			fclose( $fp );
		}
		exit;
	}
}
