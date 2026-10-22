<?php
/**
 * Plugin Name:       BMF Guided Path
 * Plugin URI:        https://breathermae.com
 * Description:       Data-driven next-step guidance for logged-in members. Evaluates ordered workflow steps against WP Fusion tags and surfaces the matching step on the member-home dashboard.
 * Version:           0.1.8-poc
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Breathermae
 * Text Domain:       bmf-guided-path
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMF_GUIDED_PATH_VERSION', '0.1.8-poc' );
define( 'BMF_GUIDED_PATH_FILE', __FILE__ );
define( 'BMF_GUIDED_PATH_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMF_GUIDED_PATH_URL', plugin_dir_url( __FILE__ ) );

require_once BMF_GUIDED_PATH_PATH . 'includes/class-activator.php';
require_once BMF_GUIDED_PATH_PATH . 'includes/class-evaluator.php';
require_once BMF_GUIDED_PATH_PATH . 'includes/class-history.php';
require_once BMF_GUIDED_PATH_PATH . 'includes/class-api.php';
require_once BMF_GUIDED_PATH_PATH . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'BMF_Guided_Path_Activator', 'activate' ) );

add_action( 'plugins_loaded', array( 'BMF_Guided_Path_API', 'init' ) );
add_action( 'plugins_loaded', array( 'BMF_Guided_Path_Admin', 'init' ) );
add_action( 'plugins_loaded', function () {
	if ( get_option( 'bmf_guided_path_db_version' ) !== '1.1.0' ) {
		BMF_Guided_Path_Activator::create_tables();
		update_option( 'bmf_guided_path_db_version', '1.1.0' );
	}
} );
