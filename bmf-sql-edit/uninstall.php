<?php
/**
 * Uninstall handler for BMF SQL Edit.
 *
 * History table is kept by default. Uncomment the DROP to remove it.
 *
 * @package BMF_SQL_Edit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Optional: drop history table.
/*
global $wpdb;
$table = $wpdb->prefix . 'bmse_sql_history';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
*/

// Clean up options and transients.
delete_option( 'bmse_defaults' );
delete_option( 'bmse_license_key' );
delete_option( 'bmse_license_status' );
delete_transient( 'bmse_license_last_check' );

// Clear scheduled license checks.
$timestamp = wp_next_scheduled( 'bmse_daily_license_check' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'bmse_daily_license_check' );
}
