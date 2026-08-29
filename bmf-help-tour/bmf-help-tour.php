<?php
/**
 * Plugin Name:       BMF Help Tour
 * Plugin URI:        https://blackmountainfactory.com
 * Description:       Attribute-driven guided help tours for Elementor pages. Lite is free (one auto-start tour per page for logged-in users). Pro unlocks guest tours, named/multiple tours, start rules, theme controls, and admin reset tools.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Black Mountain Factory
 * Author URI:        https://blackmountainfactory.com
 * Text Domain:       bmf-help-tour
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package BMF_Help_Tour
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMHT_VERSION', '1.0.0' );
define( 'BMHT_FILE', __FILE__ );
define( 'BMHT_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMHT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Emergency local override: define BMHT_FORCE_PRO true in wp-config.php.
 * Define BMHT_ENABLED false to force Lite regardless of license.
 */
if ( ! defined( 'BMHT_ENABLED' ) ) {
	define( 'BMHT_ENABLED', true );
}

require_once BMHT_PATH . 'includes/class-bmht-activator.php';
require_once BMHT_PATH . 'includes/class-bmht-license.php';
require_once BMHT_PATH . 'includes/class-bmht-settings.php';
require_once BMHT_PATH . 'includes/class-bmht-frontend.php';
require_once BMHT_PATH . 'includes/class-bmht-elementor.php';

register_activation_hook( __FILE__, array( 'BMHT_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BMHT_Activator', 'deactivate' ) );

/**
 * Bootstrap.
 */
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'bmf-help-tour', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		BMHT_License::instance();
		BMHT_Settings::init();
		BMHT_Frontend::init();

		if ( did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' ) ) {
			BMHT_Elementor::init();
		} else {
			add_action( 'elementor/loaded', array( 'BMHT_Elementor', 'init' ) );
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
		$url     = admin_url( 'options-general.php?page=bmht-settings' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'bmf-help-tour' ) . '</a>';
		return $links;
	}
);
