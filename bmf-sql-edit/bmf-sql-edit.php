<?php
/**
 * Plugin Name:       BMF SQL Edit
 * Plugin URI:        https://blackmountainfactory.com
 * Description:       Developer SQL console with optional inline cell editing. Lite (free) supports read-only queries; Pro unlocks write queries and edit mode. Requires a valid license for Pro features.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Black Mountain Factory
 * Author URI:        https://blackmountainfactory.com
 * Text Domain:       bmf-sql-edit
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 */
define( 'BMSE_VERSION', '1.2.0' );
define( 'BMSE_FILE', __FILE__ );
define( 'BMSE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMSE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Backward-compatible enable flag.
 * Prefer the license system; this constant still forces Lite when false.
 * Define BMSE_ENABLED true in wp-config.php only for emergency local override.
 */
if ( ! defined( 'BMSE_ENABLED' ) ) {
	define( 'BMSE_ENABLED', true );
}

require_once BMSE_PATH . 'includes/class-bmse-activator.php';
require_once BMSE_PATH . 'includes/class-bmse-license.php';
require_once BMSE_PATH . 'includes/class-bmse-admin.php';
require_once BMSE_PATH . 'includes/class-bmse-settings.php';

/**
 * Activation: create history table and schedule daily license poll.
 */
register_activation_hook( __FILE__, array( 'BMSE_Activator', 'activate' ) );

/**
 * Deactivation: clear scheduled license checks.
 */
register_deactivation_hook( __FILE__, array( 'BMSE_Activator', 'deactivate' ) );

/**
 * Bootstrap on plugins_loaded.
 */
add_action( 'plugins_loaded', function () {
	// Always load license helper so is_pro() is available.
	BMSE_License::instance();

	if ( is_admin() ) {
		new BMSE_Admin();
		BMSE_Settings::init();
	}
} );

/**
 * Add Settings link on the Plugins list row.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'tools.php?page=bmse-settings' );
	$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'bmf-sql-edit' ) . '</a>';
	return $links;
} );
