<?php
/**
 * BioVoicePrint – Session business logic.
 * Keeps shortcodes and REST thin.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Session_Service {

	/** Request-level cache for user session lists. */
	private static $list_cache = [];

	/**
	 * Create a new recorded session from an uploaded file.
	 *
	 * @param int   $user_id
	 * @param array $file     $_FILES-style array (tmp_name, name, type, size, error)
	 * @param array $meta     Optional: session_type, duration_sec, device_info, notes, wellness_anchor, context_flags
	 * @return array|WP_Error { session_id, storage_key } or error
	 */
	public static function create_from_upload( int $user_id, array $file, array $meta = [] ) {
		if ( $user_id < 1 ) {
			return new WP_Error( 'bmf_biovoice_auth', 'User must be logged in.', [ 'status' => 401 ] );
		}

		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new WP_Error( 'bmf_biovoice_upload', 'No valid audio file received.', [ 'status' => 400 ] );
		}

		// Basic MIME / extension guard (POC-level).
		$allowed_mimes = [
			'audio/webm'               => 'webm',
			'audio/wav'                => 'wav',
			'audio/x-wav'              => 'wav',
			'audio/mpeg'               => 'mp3',
			'audio/mp4'                => 'm4a',
			'audio/ogg'                => 'ogg',
			'video/webm'               => 'webm', // some browsers report webm audio as video/webm
		];

		$mime = isset( $file['type'] ) ? strtolower( $file['type'] ) : '';
		$ext  = $allowed_mimes[ $mime ] ?? null;

		if ( ! $ext ) {
			// Fallback: sniff from filename.
			$orig = isset( $file['name'] ) ? $file['name'] : '';
			$ext  = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, [ 'webm', 'wav', 'mp3', 'm4a', 'ogg' ], true ) ) {
				return new WP_Error( 'bmf_biovoice_mime', 'Unsupported audio format.', [ 'status' => 400 ] );
			}
		}

		// Size guard (20 MB for POC).
		$max_bytes = 20 * 1024 * 1024;
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
			return new WP_Error( 'bmf_biovoice_size', 'File exceeds 20 MB limit.', [ 'status' => 400 ] );
		}

		$storage_key = BMF_BioVoice_Storage::store_from_tmp( $user_id, $file['tmp_name'], $ext );
		if ( ! $storage_key ) {
			return new WP_Error( 'bmf_biovoice_store', 'Failed to store audio file.', [ 'status' => 500 ] );
		}

		$user      = get_userdata( $user_id );
		$user_email = $user ? $user->user_email : null;

		$row = [
			'user_id'           => $user_id,
			'user_email'        => $user_email,
			'session_type'      => isset( $meta['session_type'] ) ? sanitize_key( $meta['session_type'] ) : 'comparison',
			'status'            => 'recorded',
			'storage_key'       => $storage_key,
			'original_filename' => isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : null,
			'mime_type'         => $mime ?: null,
			'file_size'         => isset( $file['size'] ) ? (int) $file['size'] : BMF_BioVoice_Storage::get_size( $storage_key ),
			'duration_sec'      => isset( $meta['duration_sec'] ) ? (float) $meta['duration_sec'] : null,
			'device_info'       => isset( $meta['device_info'] ) ? sanitize_textarea_field( $meta['device_info'] ) : null,
			'notes'             => isset( $meta['notes'] ) ? sanitize_textarea_field( $meta['notes'] ) : null,
		];

		// Optional JSON placeholders for future protocol fields.
		if ( ! empty( $meta['wellness_anchor'] ) && is_array( $meta['wellness_anchor'] ) ) {
			$row['wellness_anchor_json'] = wp_json_encode( $meta['wellness_anchor'] );
		}
		if ( ! empty( $meta['context_flags'] ) && is_array( $meta['context_flags'] ) ) {
			$row['context_flags_json'] = wp_json_encode( $meta['context_flags'] );
		}

		$session_id = BMF_BioVoice_Repository::insert_session( $row );
		if ( ! $session_id ) {
			// Clean up orphan file.
			BMF_BioVoice_Storage::delete( $storage_key );
			return new WP_Error( 'bmf_biovoice_db', 'Failed to create session record.', [ 'status' => 500 ] );
		}

		self::invalidate_list_cache( $user_id );

		return [
			'session_id'  => $session_id,
			'storage_key' => $storage_key,
			'status'      => 'recorded',
		];
	}

	/**
	 * Get sessions for the current (or specified) user.
	 */
	public static function get_user_sessions( int $user_id, array $args = [] ) {
		$cache_key = $user_id . '|' . md5( wp_json_encode( $args ) );
		if ( isset( self::$list_cache[ $cache_key ] ) ) {
			return self::$list_cache[ $cache_key ];
		}
		$rows = BMF_BioVoice_Repository::get_sessions_for_user( $user_id, $args );
		self::$list_cache[ $cache_key ] = $rows;
		return $rows;
	}

	/**
	 * Ownership + capability check.
	 */
	public static function user_can_access_session( int $user_id, array $session ): bool {
		if ( empty( $session['user_id'] ) ) {
			return false;
		}
		// Owner.
		if ( (int) $session['user_id'] === $user_id ) {
			return true;
		}
		// Admins / managers.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		// Future: provider / coach role via WP Fusion or custom capability.
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
}
