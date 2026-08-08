<?php
/**
 * BioVoicePrint – Database tables & low-level access.
 * Follows the same dbDelta + static style as class-bmf-repository.php.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Repository {

	const DB_VERSION = '0.2.20';

	/**
	 * Create / upgrade tables. Safe to call repeatedly (dbDelta).
	 */
	public static function install_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$protocols = $wpdb->prefix . 'bm_biovoice_protocols';
		$steps     = $wpdb->prefix . 'bm_biovoice_protocol_steps';
		$groups    = $wpdb->prefix . 'bm_biovoice_session_groups';
		$sessions  = $wpdb->prefix . 'bm_biovoice_sessions';
		$results   = $wpdb->prefix . 'bm_biovoice_results';
		$scripts   = $wpdb->prefix . 'bm_biovoice_scripts';
		$user_script = $wpdb->prefix . 'bm_biovoice_user_script';

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
			analysis_status VARCHAR(20) NULL,
			analysis_error_json LONGTEXT NULL,
			analysis_at DATETIME NULL,
			started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			completed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY is_current (is_current),
			KEY is_final (is_final),
			KEY protocol_id (protocol_id),
			KEY analysis_status (analysis_status)
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
			schema_version VARCHAR(40) NOT NULL DEFAULT 'stage7',
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

		dbDelta( "CREATE TABLE {$scripts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			script_code VARCHAR(64) NOT NULL,
			category VARCHAR(32) NOT NULL,
			language VARCHAR(8) NOT NULL DEFAULT 'en',
			title VARCHAR(128) NOT NULL,
			description TEXT NULL,
			body_text LONGTEXT NOT NULL,
			estimated_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 60,
			version VARCHAR(16) NOT NULL DEFAULT '1.0',
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			sort_order SMALLINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY script_code (script_code),
			KEY lang_cat_active (language, category, is_active)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$user_script} (
			user_id BIGINT UNSIGNED NOT NULL,
			script_id BIGINT UNSIGNED NOT NULL,
			locked_at DATETIME NOT NULL,
			baseline_series INT UNSIGNED NOT NULL DEFAULT 1,
			PRIMARY KEY  (user_id, baseline_series),
			KEY script_id (script_id)
		) {$charset};" );

		update_option( 'bmf_biovoice_db_version', self::DB_VERSION );
	}

	/* ─── Analysis results ────────────────────────────────────── */

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
		return $db->get_row(
			$db->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
	}

	/** Latest analysis result for a user (newest analyzed_at / id). */
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

	/** Stage7 (or any) result for one session group — newest first. */
	public static function get_result_for_session_group( int $session_group_id, string $schema_version = 'stage7' ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_results' );
		if ( $schema_version ) {
			return $db->get_row(
				$db->prepare(
					"SELECT * FROM {$t} WHERE session_group_id = %d AND schema_version = %s ORDER BY id DESC LIMIT 1",
					$session_group_id,
					$schema_version
				),
				ARRAY_A
			);
		}
		return $db->get_row(
			$db->prepare(
				"SELECT * FROM {$t} WHERE session_group_id = %d ORDER BY id DESC LIMIT 1",
				$session_group_id
			),
			ARRAY_A
		);
	}

	/**
	 * Stored baseline reference JSON for a user (worker / comparison).
	 * Uses schema_version = baseline_reference; payload in pattern_payload_json.
	 */
	public static function get_baseline_reference_for_user( int $user_id ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_results' );
		return $db->get_row(
			$db->prepare(
				"SELECT * FROM {$t} WHERE user_id = %d AND schema_version = %s ORDER BY id DESC LIMIT 1",
				$user_id,
				'baseline_reference'
			),
			ARRAY_A
		);
	}

	/**
	 * Insert or update a result row by session_group_id + schema_version when group id set.
	 *
	 * @return int|false Result id
	 */
	public static function upsert_result( array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_results' );

		$schema = isset( $data['schema_version'] ) ? (string) $data['schema_version'] : 'stage7';
		$group_id = ! empty( $data['session_group_id'] ) ? (int) $data['session_group_id'] : 0;
		$user_id  = ! empty( $data['user_id'] ) ? (int) $data['user_id'] : 0;

		$existing = null;
		if ( $schema === 'baseline_reference' && $user_id > 0 ) {
			$existing = self::get_baseline_reference_for_user( $user_id );
		} elseif ( $group_id > 0 ) {
			$existing = self::get_result_for_session_group( $group_id, $schema );
		}

		$now = current_time( 'mysql', true );
		$data['updated_at'] = $now;

		if ( $existing ) {
			$id = (int) $existing['id'];
			unset( $data['created_at'] );
			$ok = $db->update( $t, $data, [ 'id' => $id ] );
			return ( false === $ok ) ? false : $id;
		}

		$data = array_merge( [
			'schema_version' => $schema,
			'source'         => 'engine',
			'created_at'     => $now,
		], $data );

		$ok = $db->insert( $t, $data );
		return $ok ? (int) $db->insert_id : false;
	}

	/**
	 * Final groups for a user + purpose, oldest first, limited.
	 *
	 * @return array<int, array>
	 */
	public static function get_final_groups_for_user( int $user_id, string $purpose, int $limit = 3 ): array {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		$limit = max( 1, min( 50, $limit ) );
		$rows = $db->get_results(
			$db->prepare(
				"SELECT * FROM {$t}
				WHERE user_id = %d AND purpose = %s AND is_final = 1
				ORDER BY COALESCE(completed_at, started_at) ASC, id ASC
				LIMIT %d",
				$user_id,
				sanitize_key( $purpose ),
				$limit
			),
			ARRAY_A
		);
		return $rows ?: [];
	}

	/* ─── Protocols ───────────────────────────────────────────── */

	public static function insert_protocol( array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_protocols' );
		$data = array_merge( [
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		], $data );
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
			return $db->get_row(
				$db->prepare( "SELECT * FROM {$t} WHERE is_active = 1 AND purpose = %s ORDER BY id DESC LIMIT 1", sanitize_key( $purpose ) ),
				ARRAY_A
			);
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
		return $db->get_results(
			$db->prepare( "SELECT * FROM {$t} WHERE protocol_id = %d ORDER BY sort_order ASC, step_number ASC", $protocol_id ),
			ARRAY_A
		) ?: [];
	}

	public static function get_step_by_task( int $protocol_id, string $task_code ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_protocol_steps' );
		return $db->get_row(
			$db->prepare(
				"SELECT * FROM {$t} WHERE protocol_id = %d AND task_code = %s LIMIT 1",
				$protocol_id,
				sanitize_key( $task_code )
			),
			ARRAY_A
		);
	}

	/* ─── Session groups ──────────────────────────────────────── */

	public static function insert_group( array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		$data = array_merge( [
			'status'     => 'in_progress',
			'is_current' => 1,
			'is_final'   => 0,
			'started_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		], $data );
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

	/**
	 * Admin unlock: reopen group as current in-progress (clears completed_at).
	 */
	public static function unlock_group_row( int $group_id ): bool {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		$now = current_time( 'mysql', true );
		$result = $db->query(
			$db->prepare(
				"UPDATE {$t} SET status = 'in_progress', is_final = 0, is_current = 1, completed_at = NULL,
					analysis_status = NULL, analysis_error_json = NULL, analysis_at = NULL, updated_at = %s WHERE id = %d",
				$now,
				$group_id
			)
		);
		return false !== $result;
	}

	/**
	 * Persist analysis outcome on a session group (worker success/failure).
	 *
	 * @param int         $group_id
	 * @param string      $status  ok|failed|processing
	 * @param array|null  $error   Optional structured error payload
	 */
	public static function set_group_analysis_status( int $group_id, string $status, $error = null ): bool {
		$status = sanitize_key( $status );
		if ( ! in_array( $status, [ 'ok', 'failed', 'processing' ], true ) ) {
			return false;
		}
		$data = [
			'analysis_status' => $status,
			'analysis_at'     => current_time( 'mysql', true ),
		];
		if ( $status === 'ok' ) {
			$data['analysis_error_json'] = null;
		} elseif ( is_array( $error ) ) {
			$data['analysis_error_json'] = wp_json_encode( $error );
		} elseif ( is_string( $error ) && $error !== '' ) {
			$data['analysis_error_json'] = wp_json_encode( [ 'message' => $error ] );
		}
		return self::update_group( $group_id, $data );
	}

	public static function get_current_group_for_user( int $user_id, string $purpose = '' ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		if ( $purpose ) {
			return $db->get_row(
				$db->prepare(
					"SELECT * FROM {$t} WHERE user_id = %d AND is_current = 1 AND status = 'in_progress' AND purpose = %s ORDER BY id DESC LIMIT 1",
					$user_id,
					sanitize_key( $purpose )
				),
				ARRAY_A
			);
		}
		return $db->get_row(
			$db->prepare(
				"SELECT * FROM {$t} WHERE user_id = %d AND is_current = 1 AND status = 'in_progress' ORDER BY id DESC LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
	}

	public static function count_final_groups( int $user_id, string $purpose = 'baseline' ): int {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		return (int) $db->get_var(
			$db->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND is_final = 1 AND purpose = %s",
				$user_id,
				sanitize_key( $purpose )
			)
		);
	}

	/**
	 * Groups for a user (newest first). Optional purpose filter.
	 *
	 * @return array<int, array>
	 */
	public static function get_groups_for_user( int $user_id, array $args = [] ): array {
		$db       = BMF_BioVoice_DBX::$db;
		$t        = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		$purpose  = isset( $args['purpose'] ) ? sanitize_key( $args['purpose'] ) : '';
		$limit    = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$offset   = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$sql      = "SELECT * FROM {$t} WHERE user_id = %d";
		$params   = [ $user_id ];
		if ( $purpose ) {
			$sql   .= ' AND purpose = %s';
			$params[] = $purpose;
		}
		$sql .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;
		return $db->get_results( $db->prepare( $sql, $params ), ARRAY_A ) ?: [];
	}

	/**
	 * Count session groups for a user (optional purpose filter).
	 */
	public static function count_groups_for_user( int $user_id, array $args = [] ): int {
		$db      = BMF_BioVoice_DBX::$db;
		$t       = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		$purpose = isset( $args['purpose'] ) ? sanitize_key( $args['purpose'] ) : '';
		$sql     = "SELECT COUNT(*) FROM {$t} WHERE user_id = %d";
		$params  = [ $user_id ];
		if ( $purpose ) {
			$sql     .= ' AND purpose = %s';
			$params[] = $purpose;
		}
		return (int) $db->get_var( $db->prepare( $sql, $params ) );
	}

	/**
	 * Count recording sessions for a user (same filters as get_sessions_for_user).
	 */
	public static function count_sessions_for_user( int $user_id, array $args = [] ): int {
		$db     = BMF_BioVoice_DBX::$db;
		$t      = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );
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
		if ( ! empty( $args['session_group_id'] ) ) {
			$where[]  = 'session_group_id = %d';
			$params[] = (int) $args['session_group_id'];
		}
		if ( ! empty( $args['task_code'] ) ) {
			$where[]  = 'task_code = %s';
			$params[] = sanitize_key( $args['task_code'] );
		}

		$sql = 'SELECT COUNT(*) FROM ' . $t . ' WHERE ' . implode( ' AND ', $where );
		return (int) $db->get_var( $db->prepare( $sql, $params ) );
	}

	/** Count groups flagged device_mismatch for user (any purpose). */
	public static function count_device_mismatch_groups( int $user_id ): int {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		return (int) $db->get_var(
			$db->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND device_mismatch = 1",
				$user_id
			)
		);
	}

	public static function clear_current_groups( int $user_id, string $purpose = '' ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		if ( $purpose ) {
			$db->query(
				$db->prepare(
					"UPDATE {$t} SET is_current = 0 WHERE user_id = %d AND purpose = %s AND is_current = 1",
					$user_id,
					sanitize_key( $purpose )
				)
			);
		} else {
			$db->query(
				$db->prepare(
					"UPDATE {$t} SET is_current = 0 WHERE user_id = %d AND is_current = 1",
					$user_id
				)
			);
		}
	}

	public static function get_sessions_for_group( int $group_id ): array {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );
		return $db->get_results(
			$db->prepare(
				"SELECT * FROM {$t} WHERE session_group_id = %d ORDER BY step_number ASC, id ASC",
				$group_id
			),
			ARRAY_A
		) ?: [];
	}

	/* ─── Sessions (takes) ────────────────────────────────────── */

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

	public static function get_session( int $session_id ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_sessions' );
		return $db->get_row(
			$db->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $session_id ),
			ARRAY_A
		);
	}

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
		if ( ! empty( $args['session_group_id'] ) ) {
			$where[]  = 'session_group_id = %d';
			$params[] = (int) $args['session_group_id'];
		}
		if ( ! empty( $args['task_code'] ) ) {
			$where[]  = 'task_code = %s';
			$params[] = sanitize_key( $args['task_code'] );
		}

		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) .
			" ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

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

	/* ─── Scripts & user lock ─────────────────────────────────── */

	public static function insert_script( array $data ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_scripts' );
		$data = array_merge( [
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		], $data );
		$ok = $db->insert( $t, $data );
		return $ok ? (int) $db->insert_id : false;
	}

	public static function get_script( int $id ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_scripts' );
		return $db->get_row(
			$db->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
	}

	public static function get_script_by_code( string $code ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_scripts' );
		return $db->get_row(
			$db->prepare( "SELECT * FROM {$t} WHERE script_code = %s LIMIT 1", $code ),
			ARRAY_A
		);
	}

	/**
	 * Active scripts, optionally filtered by language.
	 *
	 * @return array<int, array>
	 */
	public static function get_active_scripts( string $language = '' ): array {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_scripts' );
		if ( $language ) {
			return $db->get_results(
				$db->prepare(
					"SELECT * FROM {$t} WHERE is_active = 1 AND language = %s ORDER BY sort_order ASC, id ASC",
					sanitize_key( $language )
				),
				ARRAY_A
			) ?: [];
		}
		return $db->get_results(
			"SELECT * FROM {$t} WHERE is_active = 1 ORDER BY language ASC, sort_order ASC, id ASC",
			ARRAY_A
		) ?: [];
	}

	/**
	 * Current (highest baseline_series) lock for a user, or null.
	 */
	public static function get_user_script_lock( int $user_id ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_user_script' );
		return $db->get_row(
			$db->prepare(
				"SELECT * FROM {$t} WHERE user_id = %d ORDER BY baseline_series DESC LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
	}

	/**
	 * Lock a script for the user. Refuses if a lock already exists for the given series.
	 *
	 * @return int|false|WP_Error  rows affected / false / error
	 */
	public static function lock_user_script( int $user_id, int $script_id, int $baseline_series = 1 ) {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_user_script' );

		$existing = $db->get_row(
			$db->prepare(
				"SELECT * FROM {$t} WHERE user_id = %d AND baseline_series = %d LIMIT 1",
				$user_id,
				$baseline_series
			),
			ARRAY_A
		);
		if ( $existing ) {
			return new WP_Error(
				'bmf_biovoice_script_locked',
				'A script is already locked for this baseline series.',
				[ 'status' => 409 ]
			);
		}

		$ok = $db->insert( $t, [
			'user_id'         => $user_id,
			'script_id'       => $script_id,
			'locked_at'       => current_time( 'mysql', true ),
			'baseline_series' => $baseline_series,
		] );
		return $ok ? 1 : false;
	}

	/**
	 * One-time: lock every user who already has session groups onto the legacy script.
	 * Safe to call repeatedly (skips users who already have a lock).
	 */
	public static function migrate_existing_users_to_legacy_script(): int {
		$legacy = self::get_script_by_code( 'legacy_en_v1' );
		if ( ! $legacy ) {
			return 0;
		}
		$script_id = (int) $legacy['id'];

		$db = BMF_BioVoice_DBX::$db;
		$groups_t = BMF_BioVoice_DBX::t( 'bm_biovoice_session_groups' );
		$lock_t   = BMF_BioVoice_DBX::t( 'bm_biovoice_user_script' );

		$users = $db->get_col( "SELECT DISTINCT user_id FROM {$groups_t}" );
		$locked = 0;
		$now = current_time( 'mysql', true );

		foreach ( $users as $uid ) {
			$uid = (int) $uid;
			if ( $uid < 1 ) {
				continue;
			}
			$exists = $db->get_var(
				$db->prepare( "SELECT 1 FROM {$lock_t} WHERE user_id = %d LIMIT 1", $uid )
			);
			if ( $exists ) {
				continue;
			}
			$ok = $db->insert( $lock_t, [
				'user_id'         => $uid,
				'script_id'       => $script_id,
				'locked_at'       => $now,
				'baseline_series' => 1,
			] );
			if ( $ok ) {
				$locked++;
			}
		}
		return $locked;
	}

	/**
	 * All scripts (active + inactive), newest language/sort first.
	 *
	 * @return array<int, array>
	 */
	public static function get_all_scripts(): array {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_scripts' );
		return $db->get_results(
			"SELECT * FROM {$t} ORDER BY language ASC, sort_order ASC, id ASC",
			ARRAY_A
		) ?: [];
	}

	/**
	 * Update a script. script_code is not changed here.
	 *
	 * @return bool
	 */
	public static function update_script( int $id, array $data ): bool {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_scripts' );
		unset( $data['id'], $data['script_code'], $data['created_at'] );
		$data['updated_at'] = current_time( 'mysql', true );
		return false !== $db->update( $t, $data, [ 'id' => $id ] );
	}

	/**
	 * Set is_active flag.
	 */
	public static function set_script_active( int $id, bool $active ): bool {
		return self::update_script( $id, [ 'is_active' => $active ? 1 : 0 ] );
	}

	/**
	 * How many user locks reference this script.
	 */
	public static function count_locks_for_script( int $script_id ): int {
		$db = BMF_BioVoice_DBX::$db;
		$t  = BMF_BioVoice_DBX::t( 'bm_biovoice_user_script' );
		return (int) $db->get_var(
			$db->prepare( "SELECT COUNT(*) FROM {$t} WHERE script_id = %d", $script_id )
		);
	}
}
