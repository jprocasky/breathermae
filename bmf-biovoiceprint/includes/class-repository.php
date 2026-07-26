<?php
/**
 * BioVoicePrint – Database tables & low-level access.
 * Follows the same dbDelta + static style as class-bmf-repository.php.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Repository {

	/**
	 * Create / upgrade tables on activation.
	 */
	public static function install_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sessions = $wpdb->prefix . 'bm_biovoice_sessions';

		dbDelta( "CREATE TABLE {$sessions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			user_email VARCHAR(191) COLLATE utf8mb4_unicode_520_ci NULL,
			session_type VARCHAR(20) NOT NULL DEFAULT 'comparison',
			status VARCHAR(20) NOT NULL DEFAULT 'recorded',
			storage_key VARCHAR(255) NOT NULL,
			original_filename VARCHAR(255) NULL,
			mime_type VARCHAR(100) NULL,
			file_size BIGINT UNSIGNED NULL,
			duration_sec DECIMAL(8,2) NULL,
			device_info TEXT NULL,
			wellness_anchor_json LONGTEXT NULL,
			context_flags_json LONGTEXT NULL,
			quality_json LONGTEXT NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY session_type (session_type),
			KEY created_at (created_at)
		) {$charset};" );

		// Placeholder for future baseline summary table.
		// Left as a comment so the structure is visible when we expand.
		/*
		$baselines = $wpdb->prefix . 'bm_biovoice_baselines';
		dbDelta( "CREATE TABLE {$baselines} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			established_at DATETIME NULL,
			n_sessions INT UNSIGNED DEFAULT 0,
			baseline_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_id (user_id)
		) {$charset};" );
		*/
	}

	/**
	 * Insert a new session row.
	 *
	 * @param array $data Associative array of column => value.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function insert_session( array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );

		$defaults = [
			'session_type' => 'comparison',
			'status'       => 'recorded',
			'created_at'   => current_time( 'mysql', true ),
			'updated_at'   => current_time( 'mysql', true ),
		];
		$data = array_merge( $defaults, $data );

		$ok = $db->insert( $t, $data );
		if ( ! $ok ) {
			return false;
		}
		return (int) $db->insert_id;
	}

	/**
	 * Get a single session by ID.
	 */
	public static function get_session( int $session_id ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );
		return $db->get_row(
			$db->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $session_id ),
			ARRAY_A
		);
	}

	/**
	 * List sessions for a user (newest first).
	 *
	 * @param int   $user_id
	 * @param array $args  Optional: limit, offset, session_type, status
	 * @return array
	 */
	public static function get_sessions_for_user( int $user_id, array $args = [] ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );

		$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 50;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$where  = [ 'user_id = %d' ];
		$params = [ $user_id ];

		if ( ! empty( $args['session_type'] ) ) {
			$where[]  = 'session_type = %s';
			$params[] = sanitize_key( $args['session_type'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) .
			" ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		return $db->get_results( $db->prepare( $sql, $params ), ARRAY_A ) ?: [];
	}

	/**
	 * Update session fields.
	 */
	public static function update_session( int $session_id, array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );
		$data['updated_at'] = current_time( 'mysql', true );
		return false !== $db->update( $t, $data, [ 'id' => $session_id ] );
	}

	/**
	 * Delete a session row (does not delete the audio file — caller must handle storage).
	 */
	public static function delete_session( int $session_id ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );
		return false !== $db->delete( $t, [ 'id' => $session_id ], [ '%d' ] );
	}
}
