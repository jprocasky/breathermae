<?php
/**
 * Activation / deactivation routines for BMF SQL Edit.
 *
 * @package BMF_SQL_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BMSE_Activator
 */
class BMSE_Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . 'bmse_sql_history';

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NULL,
			query_text LONGTEXT NOT NULL,
			is_select TINYINT(1) NOT NULL DEFAULT 0,
			affected_rows INT NULL,
			runtime_ms INT NULL,
			error_message TEXT NULL,
			tables_json TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Schedule daily license status poll if not already scheduled.
		if ( ! wp_next_scheduled( 'bmse_daily_license_check' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'bmse_daily_license_check' );
		}
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'bmse_daily_license_check' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'bmse_daily_license_check' );
		}
	}
}
