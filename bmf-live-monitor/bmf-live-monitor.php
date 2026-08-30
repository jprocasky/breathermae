<?php
/**
 * Plugin Name:       BMF Live Monitor
 * Plugin URI:        https://blackmountainfactory.com
 * Description:       Live session count, who's on the site, and a 24/48-hour activity graph — as shortcodes. Lite tracks logged-in users. Pro adds guests, extra gauges, colors, history, and PII columns.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Black Mountain Factory
 * Author URI:        https://blackmountainfactory.com
 * Text Domain:       bmf-live-monitor
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package BMF_Live_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMLM_VERSION', '1.0.0' );
define( 'BMLM_FILE', __FILE__ );
define( 'BMLM_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMLM_URL', plugin_dir_url( __FILE__ ) );
define( 'BMLM_DB_VERSION', '1.0.0' );

/**
 * Emergency local override: define BMLM_FORCE_PRO true in wp-config.php.
 * Define BMLM_ENABLED false to force Lite regardless of license.
 */
if ( ! defined( 'BMLM_ENABLED' ) ) {
	define( 'BMLM_ENABLED', true );
}

require_once BMLM_PATH . 'includes/class-bmlm-activator.php';
require_once BMLM_PATH . 'includes/class-bmlm-license.php';
require_once BMLM_PATH . 'includes/class-bmlm-settings.php';
require_once BMLM_PATH . 'includes/class-bmlm-tracker.php';
require_once BMLM_PATH . 'includes/class-bmlm-snapshots.php';
require_once BMLM_PATH . 'includes/class-bmlm-shortcodes.php';
require_once BMLM_PATH . 'includes/class-bmlm-frontend.php';
require_once BMLM_PATH . 'includes/class-bmlm-admin.php';
require_once BMLM_PATH . 'includes/class-bmlm-elementor.php';

register_activation_hook( __FILE__, array( 'BMLM_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BMLM_Activator', 'deactivate' ) );

add_filter( 'cron_schedules', array( 'BMLM_Settings', 'cron_schedules' ) );

/**
 * Bootstrap.
 */
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'bmf-live-monitor', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		BMLM_License::instance();
		BMLM_Settings::init();
		BMLM_Tracker::init();
		BMLM_Snapshots::init();
		BMLM_Shortcodes::init();
		BMLM_Frontend::init();
		BMLM_Admin::init();

		if ( did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' ) ) {
			BMLM_Elementor::init();
		} else {
			add_action( 'elementor/loaded', array( 'BMLM_Elementor', 'init' ) );
		}
	}
);

/**
 * Settings link on the Plugins list row.
 *
 * @param array $links Plugin action links.
 * @return array
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url     = admin_url( 'options-general.php?page=bmlm-settings' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'bmf-live-monitor' ) . '</a>';
		return $links;
	}
);
