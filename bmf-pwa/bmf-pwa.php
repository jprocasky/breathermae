<?php
/**
 * Plugin Name:       BMF PWA
 * Plugin URI:        https://breathermae.com
 * Description:       Turns Breathermae into an installable Progressive Web App (PWA) and provides an Install button shortcode.
 * Version:           1.0.0
 * Author:            Breathermae / Jeff Procasky
 * Author URI:        https://breathermae.com
 * Text Domain:       bmf-pwa
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMF_PWA_VERSION', '1.0.0' );
define( 'BMF_PWA_PLUGIN_FILE', __FILE__ );
define( 'BMF_PWA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BMF_PWA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once BMF_PWA_PLUGIN_DIR . 'includes/class-bmf-pwa.php';
require_once BMF_PWA_PLUGIN_DIR . 'includes/class-bmf-pwa-settings.php';
require_once BMF_PWA_PLUGIN_DIR . 'includes/class-bmf-pwa-manifest.php';
require_once BMF_PWA_PLUGIN_DIR . 'includes/class-bmf-pwa-button.php';

/**
 * Bootstrap the plugin.
 */
function bmf_pwa_init() {
	BMF_PWA::instance();
}
add_action( 'plugins_loaded', 'bmf_pwa_init' );

/**
 * Activation: flush rewrite rules so the service worker and manifest endpoints work.
 */
function bmf_pwa_activate() {
	BMF_PWA_Manifest::add_rewrite_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'bmf_pwa_activate' );

/**
 * Deactivation: clean up rewrite rules.
 */
function bmf_pwa_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'bmf_pwa_deactivate' );
