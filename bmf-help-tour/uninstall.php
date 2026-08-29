<?php
/**
 * Uninstall handler for BMF Help Tour.
 *
 * Site options and license data are removed. User completion meta is left in
 * place so reinstalling does not re-show finished tours. Uncomment the user-meta
 * cleanup below for a hard wipe.
 *
 * @package BMF_Help_Tour
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bmht_options' );
delete_option( 'bmht_license_key' );
delete_option( 'bmht_license_status' );
delete_transient( 'bmht_license_last_check' );

$timestamp = wp_next_scheduled( 'bmht_daily_license_check' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'bmht_daily_license_check' );
}

/*
 * Optional hard cleanup of per-user completion data:
 *
 * delete_metadata( 'user', 0, 'bmht_completed_tours', '', true );
 * delete_metadata( 'user', 0, 'bmf_completed_help_tours', '', true );
 */
