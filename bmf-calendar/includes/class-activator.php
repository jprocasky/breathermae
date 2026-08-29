<?php
/**
 * Activation / deactivation.
 *
 * @package BMF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Calendar_Activator {

	const DB_VERSION = '1.1.0';

	public static function activate() {
		self::create_tables();
		self::maybe_add_caps();
		update_option( 'bmf_calendar_db_version', self::DB_VERSION );

		add_rewrite_rule( '^bmf-calendar/outlook-callback/?$', 'index.php?bmf_cal_outlook=1', 'top' );
		add_rewrite_rule( '^bmf-calendar/outlook-connect/?$', 'index.php?bmf_cal_outlook_start=1', 'top' );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Create core tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Appointments
		$table = $wpdb->prefix . 'bmf_appointments';
		$sql   = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NULL,
			member_email VARCHAR(191) NOT NULL DEFAULT '',
			provider_id BIGINT UNSIGNED NULL,
			start_at DATETIME NOT NULL,
			end_at DATETIME NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'requested',
			subject VARCHAR(255) NOT NULL DEFAULT '',
			description LONGTEXT NULL,
			location VARCHAR(255) NULL,
			outlook_event_id VARCHAR(255) NULL,
			uls_member_reminder_id BIGINT UNSIGNED NULL,
			uls_provider_reminder_id BIGINT UNSIGNED NULL,
			created_by BIGINT UNSIGNED NULL,
			updated_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
			is_deleted TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_member (member_id),
			KEY idx_member_email (member_email),
			KEY idx_provider (provider_id),
			KEY idx_start (start_at),
			KEY idx_status (status)
		) {$charset};";
		dbDelta( $sql );

		// Provider availability (recurring windows + exceptions)
		$table = $wpdb->prefix . 'bmf_provider_availability';
		$sql   = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'recurring',
			day_of_week TINYINT NULL,
			start_time TIME NULL,
			end_time TIME NULL,
			date_specific DATE NULL,
			is_available TINYINT(1) NOT NULL DEFAULT 1,
			notes VARCHAR(255) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_provider (provider_id),
			KEY idx_type (type)
		) {$charset};";
		dbDelta( $sql );

		// Explicit Provider ↔ Member links (used when WP Fusion tags are not the source of truth)
		$table = $wpdb->prefix . 'bmf_provider_member';
		$sql   = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider_id BIGINT UNSIGNED NOT NULL,
			member_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_pair (provider_id, member_id),
			KEY idx_member (member_id)
		) {$charset};";
		dbDelta( $sql );

		// Outlook connection metadata (tokens stored encrypted in user meta or options later)
		$table = $wpdb->prefix . 'bmf_outlook_connections';
		$sql   = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			ms_account_id VARCHAR(255) NULL,
			display_name VARCHAR(255) NULL,
			email VARCHAR(191) NULL,
			calendar_id VARCHAR(255) NULL,
			token_expires_at DATETIME NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'connected',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_user (user_id)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Ensure a basic capability exists for non-WP-Fusion sites.
	 */
	private static function maybe_add_caps() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( 'bmf_calendar_provider' ) ) {
			$role->add_cap( 'bmf_calendar_provider' );
		}
		// Editors can also be Providers if desired; leave for settings later.
	}
}
