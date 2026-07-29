<?php
/**
 * BioVoicePrint – Database tables & low-level access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Repository {

	const DB_VERSION = '0.2.6';

	public static function install_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$protocols = $wpdb->prefix . 'bm_biovoice_protocols';
		$steps     = $wpdb->prefix . 'bm_biovoice_protocol_steps';
		$groups    = $wpdb->prefix . 'bm_biovoice_session_groups';
		$sessions  = $wpdb->prefix . 'bm_biovoice_sessions';
		$results   = $wpdb->prefix . 'bm_biovoice_results';

		dbDelta( "CREATE TABLE {$protocols} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			version VARCHAR(20) NOT NULL,
			name VARCHAR(120) NOT NULL,
			purpose VARCHAR(20) NOT NULL DEFAULT 'baseline',
			is_active TINYINT(1) NOT NULL DEFAULT 0,
			notes TEXT NULL,
			published_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY version (version),
			KEY is_active (is_active),
			KEY purpose (purpose)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$steps} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			protocol_id BIGINT UNSIGNED NOT NULL,
			step_number INT NOT NULL DEFAULT 0,
			task_code VARCHAR(40) NOT NULL,
			title VARCHAR(120) NOT NULL DEFAULT '',
			directions TEXT NULL,
			prompt_text TEXT NULL,
			min_seconds DECIMAL(6,2) NOT NULL DEFAULT 0,
			max_seconds DECIMAL(6,2) NULL,
			requires_audio TINYINT(1) NOT NULL DEFAULT 1,
			is_silence TINYINT(1) NOT NULL DEFAULT 0,
			is_speech TINYINT(1) NOT NULL DEFAULT 0,
			allow_retake TINYINT(1) NOT NULL DEFAULT 1,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY protocol_id (protocol_id),
			KEY task_code (task_code),
			UNIQUE KEY protocol_task (protocol_id, task_code)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$groups} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			user_email VARCHAR(191) COLLATE utf8mb4_unicode_520_ci NULL,
			protocol_id BIGINT UNSIGNED NOT NULL,
			protocol_version VARCHAR(20) NULL,
			purpose VARCHAR(20) NOT NULL DEFAULT 'baseline',
			status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
			is_current TINYINT(1) NOT NULL DEFAULT 1,
			is_final TINYINT(1) NOT NULL DEFAULT 0,
			wellness_anchor_json LONGTEXT NULL,
			device_summary_json LONGTEXT NULL,
			device_mismatch TINYINT(1) NOT NULL DEFAULT 0,
			started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			completed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY is_current (is_current),
			KEY is_final (is_final),
			KEY protocol_id (protocol_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$sessions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			user_email VARCHAR(191) COLLATE utf8mb4_unicode_520_ci NULL,
			session_group_id BIGINT UNSIGNED NULL,
			protocol_id BIGINT UNSIGNED NULL,
			task_code VARCHAR(40) NULL,
			step_number INT NULL,
			session_type VARCHAR(20) NOT NULL DEFAULT 'comparison',
			status VARCHAR(20) NOT NULL DEFAULT 'recorded',
			storage_key VARCHAR(255) NOT NULL,
			original_filename VARCHAR(255) NULL,
			mime_type VARCHAR(100) NULL,
			file_size BIGINT UNSIGNED NULL,
			duration_sec DECIMAL(8,2) NULL,
			device_info TEXT NULL,
			device_info_json LONGTEXT NULL,
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
			KEY created_at (created_at),
			KEY session_group_id (session_group_id),
			KEY task_code (task_code)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$results} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			user_email VARCHAR(191) COLLATE utf8mb4_unicode_520_ci NULL,
			session_group_id BIGINT UNSIGNED NULL,
			comparison_session_id VARCHAR(120) NULL,
			schema_version VARCHAR(20) NOT NULL DEFAULT 'stage7',
			source VARCHAR(40) NOT NULL DEFAULT 'engine',
			rdi_score DECIMAL(8,2) NULL,
			rdi_band VARCHAR(60) NULL,
			rdi_color VARCHAR(40) NULL,
			plain_report_json LONGTEXT NULL,
			pattern_payload_json LONGTEXT NULL,
			analyzed_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY session_group_id (session_group_id),
			KEY analyzed_at (analyzed_at),
			KEY source (source)
		) {$charset};" );

		update_option( 'bmf_biovoice_db_version', self::DB_VERSION );
	}

	public static function insert_result( array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_results' );
		$data = array_merge( [
			'schema_version' => 'stage7',
			'source'         => 'engine',
			'created_at'     => current_time( 'mysql', true ),
			'updated_at'     => current_time( 'mysql', true ),
		], $data );
		$ok = $db->insert( $t, $data );
		return $ok ? (int) $db->insert_id : false;
	}

	public static function get_result( int $id ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_results' );
		return $db->get_row( $db->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
	}

	public static function get_latest_result_for_user( int $user_id ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_results' );
		return $db->get_row(
			$db->prepare(
				"SELECT * FROM {$t} WHERE user_id = %d ORDER BY COALESCE(analyzed_at, created_at) DESC, id DESC LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
	}

	public static function insert_protocol( array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_protocols' );
		$data = array_merge( [ 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ], $data );
		$ok = $db->insert( $t, $data );
		return $ok ? (int) $db->insert_id : false;
	}

	public static function get_protocol( int $id ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_protocols' );
		return $db->get_row( $db->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
	}

	public static function get_protocol_by_version( string $version ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_protocols' );
		return $db->get_row( $db->prepare( "SELECT * FROM {$t} WHERE version = %s LIMIT 1", $version ), ARRAY_A );
	}

	public static function get_active_protocol( string $purpose = '' ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_protocols' );
		if ( $purpose ) {
			return $db->get_row( $db->prepare( "SELECT * FROM {$t} WHERE is_active = 1 AND purpose = %s ORDER BY id DESC LIMIT 1", sanitize_key( $purpose ) ), ARRAY_A );
		}
		return $db->get_row( "SELECT * FROM {$t} WHERE is_active = 1 ORDER BY id DESC LIMIT 1", ARRAY_A );
	}

	public static function insert_protocol_step( array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_protocol_steps' );
		$ok = $db->insert( $t, $data );
		return $ok ? (int) $db->insert_id : false;
	}

	public static function get_steps_for_protocol( int $protocol_id ): array {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_protocol_steps' );
		return $db->get_results( $db->prepare( "SELECT * FROM {$t} WHERE protocol_id = %d ORDER BY sort_order ASC, step_number ASC", $protocol_id ), ARRAY_A ) ?: [];
	}

	public static function get_step_by_task( int $protocol_id, string $task_code ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_protocol_steps' );
		return $db->get_row( $db->prepare( "SELECT * FROM {$t} WHERE protocol_id = %d AND task_code = %s LIMIT 1", $protocol_id, sanitize_key( $task_code ) ), ARRAY_A );
	}

	public static function insert_group( array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		$data = array_merge( [ 'status' => 'in_progress', 'is_current' => 1, 'is_final' => 0, 'started_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ], $data );
		$ok = $db->insert( $t, $data );
		return $ok ? (int) $db->insert_id : false;
	}

	public static function get_group( int $group_id ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		return $db->get_row( $db->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $group_id ), ARRAY_A );
	}

	public static function update_group( int $group_id, array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		$data['updated_at'] = current_time( 'mysql', true );
		return false !== $db->update( $t, $data, [ 'id' => $group_id ] );
	}

	public static function get_current_group_for_user( int $user_id, string $purpose = '' ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		if ( $purpose ) {
			return $db->get_row( $db->prepare( "SELECT * FROM {$t} WHERE user_id = %d AND is_current = 1 AND status = 'in_progress' AND purpose = %s ORDER BY id DESC LIMIT 1", $user_id, sanitize_key( $purpose ) ), ARRAY_A );
		}
		return $db->get_row( $db->prepare( "SELECT * FROM {$t} WHERE user_id = %d AND is_current = 1 AND status = 'in_progress' ORDER BY id DESC LIMIT 1", $user_id ), ARRAY_A );
	}

	public static function count_final_groups( int $user_id, string $purpose = 'baseline' ): int {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		return (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND is_final = 1 AND purpose = %s", $user_id, sanitize_key( $purpose ) ) );
	}

	public static function get_groups_for_user( int $user_id, array $args = [] ): array {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		$purpose = isset( $args['purpose'] ) ? sanitize_key( $args['purpose'] ) : '';
		$limit = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$sql = "SELECT * FROM {$t} WHERE user_id = %d";
		$params = [ $user_id ];
		if ( $purpose ) {
			$sql .= ' AND purpose = %s';
			$params[] = $purpose;
		}
		$sql .= ' ORDER BY id DESC LIMIT %d';
		$params[] = $limit;
		return $db->get_results( $db->prepare( $sql, $params ), ARRAY_A ) ?: [];
	}

	public static function count_device_mismatch_groups( int $user_id ): int {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		return (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND device_mismatch = 1", $user_id ) );
	}

	public static function clear_current_groups( int $user_id, string $purpose = '' ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		if ( $purpose ) {
			$db->query( $db->prepare( "UPDATE {$t} SET is_current = 0 WHERE user_id = %d AND purpose = %s AND is_current = 1", $user_id, sanitize_key( $purpose ) ) );
		} else {
			$db->query( $db->prepare( "UPDATE {$t} SET is_current = 0 WHERE user_id = %d AND is_current = 1", $user_id ) );
		}
	}

	public static function get_sessions_for_group( int $group_id ): array {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );
		return $db->get_results( $db->prepare( "SELECT * FROM {$t} WHERE session_group_id = %d ORDER BY step_number ASC, id ASC", $group_id ), ARRAY_A ) ?: [];
	}

	public static function insert_session( array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );
		$data = array_merge( [ 'session_type' => 'comparison', 'status' => 'recorded', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ], $data );
		$ok = $db->insert( $t, $data );
		return $ok ? (int) $db->insert_id : false;
	}

	public static function get_session( int $session_id ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );
		return $db->get_row( $db->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $session_id ), ARRAY_A );
	}

	public static function get_sessions_for_user( int $user_id, array $args = [] ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );
		$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 50;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$where  = [ 'user_id = %d' ];
		$params = [ $user_id ];
		if ( ! empty( $args['session_type'] ) ) { $where[] = 'session_type = %s'; $params[] = sanitize_key( $args['session_type'] ); }
		if ( ! empty( $args['status'] ) ) { $where[] = 'status = %s'; $params[] = sanitize_key( $args['status'] ); }
		if ( ! empty( $args['session_group_id'] ) ) { $where[] = 'session_group_id = %d'; $params[] = (int) $args['session_group_id']; }
		if ( ! empty( $args['task_code'] ) ) { $where[] = 'task_code = %s'; $params[] = sanitize_key( $args['task_code'] ); }
		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . " ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit; $params[] = $offset;
		return $db->get_results( $db->prepare( $sql, $params ), ARRAY_A ) ?: [];
	}

	public static function update_session( int $session_id, array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );
		$data['updated_at'] = current_time( 'mysql', true );
		return false !== $db->update( $t, $data, [ 'id' => $session_id ] );
	}

	public static function delete_session( int $session_id ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );
		return false !== $db->delete( $t, [ 'id' => $session_id ], [ '%d' ] );
	}
}
