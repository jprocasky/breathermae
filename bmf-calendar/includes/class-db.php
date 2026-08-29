<?php
/**
 * Thin DB helpers / table name constants.
 *
 * @package BMF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Calendar_DB {

	public static function appointments_table() {
		global $wpdb;
		return $wpdb->prefix . 'bmf_appointments';
	}

	public static function availability_table() {
		global $wpdb;
		return $wpdb->prefix . 'bmf_provider_availability';
	}

	public static function provider_member_table() {
		global $wpdb;
		return $wpdb->prefix . 'bmf_provider_member';
	}

	public static function outlook_table() {
		global $wpdb;
		return $wpdb->prefix . 'bmf_outlook_connections';
	}
}
