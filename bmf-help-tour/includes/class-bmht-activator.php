<?php
/**
 * Activation / deactivation routines for BMF Help Tour.
 *
 * @package BMF_Help_Tour
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BMHT_Activator
 */
class BMHT_Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		$opts = get_option( BMHT_Settings::OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}

		update_option( BMHT_Settings::OPTION, wp_parse_args( $opts, BMHT_Settings::defaults() ), false );

		if ( ! wp_next_scheduled( 'bmht_daily_license_check' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'bmht_daily_license_check' );
		}
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'bmht_daily_license_check' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'bmht_daily_license_check' );
		}
	}
}
