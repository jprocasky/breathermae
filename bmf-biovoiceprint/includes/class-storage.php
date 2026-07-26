<?php
/**
 * BioVoicePrint – Local private file storage.
 *
 * Files live under wp-content/uploads/bmf-biovoice-private/
 * and are never exposed via direct URL. Playback goes through
 * a capability-checked REST endpoint.
 *
 * Storage interface is kept simple so an Azure / S3 implementation
 * can be dropped in later without changing callers.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Storage {

	const SUBDIR = 'bmf-biovoice-private';

	/**
	 * Absolute path to the private directory.
	 */
	public static function get_base_dir(): string {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . self::SUBDIR;
	}

	/**
	 * Ensure the private directory exists and is protected.
	 */
	public static function ensure_directory(): bool {
		$dir = self::get_base_dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Deny direct web access (Apache).
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}

		// Empty index for extra safety.
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return true;
	}

	/**
	 * Build a storage key (relative path under the private dir).
	 * Format: {user_id}/{Y}/{m}/{uniqid}.webm
	 */
	public static function build_key( int $user_id, string $extension = 'webm' ): string {
		$extension = preg_replace( '/[^a-z0-9]/i', '', $extension ) ?: 'webm';
		$uniq      = uniqid( 'bv_', true );
		return sprintf(
			'%d/%s/%s/%s.%s',
			$user_id,
			gmdate( 'Y' ),
			gmdate( 'm' ),
			$uniq,
			$extension
		);
	}

	/**
	 * Absolute filesystem path for a storage key.
	 */
	public static function key_to_path( string $storage_key ): string {
		$storage_key = ltrim( str_replace( [ '..', '\\' ], '', $storage_key ), '/' );
		return trailingslashit( self::get_base_dir() ) . $storage_key;
	}

	/**
	 * Store a temporary uploaded file into private storage.
	 *
	 * @param int    $user_id
	 * @param string $tmp_path   Path to the temporary uploaded file.
	 * @param string $extension  Desired extension (webm, wav, etc.).
	 * @return string|false      Storage key on success, false on failure.
	 */
	public static function store_from_tmp( int $user_id, string $tmp_path, string $extension = 'webm' ) {
		if ( ! is_uploaded_file( $tmp_path ) && ! is_readable( $tmp_path ) ) {
			return false;
		}

		self::ensure_directory();

		$key  = self::build_key( $user_id, $extension );
		$path = self::key_to_path( $key );
		$dir  = dirname( $path );

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Prefer move for real uploads; fall back to copy for testing.
		$moved = @move_uploaded_file( $tmp_path, $path );
		if ( ! $moved ) {
			$moved = @copy( $tmp_path, $path );
			if ( $moved && is_uploaded_file( $tmp_path ) ) {
				@unlink( $tmp_path );
			}
		}

		if ( ! $moved || ! file_exists( $path ) ) {
			return false;
		}

		// Restrict permissions.
		@chmod( $path, 0640 );

		return $key;
	}

	/**
	 * Store raw binary data (e.g. from a blob already in memory).
	 *
	 * @return string|false Storage key or false.
	 */
	public static function store_bytes( int $user_id, string $bytes, string $extension = 'webm' ) {
		self::ensure_directory();

		$key  = self::build_key( $user_id, $extension );
		$path = self::key_to_path( $key );
		$dir  = dirname( $path );

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$written = file_put_contents( $path, $bytes );
		if ( false === $written ) {
			return false;
		}

		@chmod( $path, 0640 );
		return $key;
	}

	/**
	 * Does the file exist?
	 */
	public static function exists( string $storage_key ): bool {
		$path = self::key_to_path( $storage_key );
		return $storage_key && file_exists( $path ) && is_readable( $path );
	}

	/**
	 * Absolute path (for streaming). Returns empty string if missing.
	 */
	public static function get_path( string $storage_key ): string {
		if ( ! self::exists( $storage_key ) ) {
			return '';
		}
		return self::key_to_path( $storage_key );
	}

	/**
	 * Delete a stored file.
	 */
	public static function delete( string $storage_key ): bool {
		$path = self::key_to_path( $storage_key );
		if ( file_exists( $path ) ) {
			return @unlink( $path );
		}
		return true;
	}

	/**
	 * File size in bytes.
	 */
	public static function get_size( string $storage_key ): int {
		$path = self::get_path( $storage_key );
		return $path ? (int) filesize( $path ) : 0;
	}
}
