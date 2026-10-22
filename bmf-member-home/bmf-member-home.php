<?php
/**
 * Plugin Name:       BMF Member Home
 * Plugin URI:        https://breathermae.com
 * Description:       Logged-in Today dashboard. Dark/light tokens shared with the wellbeing file. Links live in Settings, not in code.
 * Version:           0.1.19-poc
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Breathermae
 * Text Domain:       bmf-member-home
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMF_MEMBER_HOME_VERSION', '0.1.19-poc' );
define( 'BMF_MEMBER_HOME_FILE', __FILE__ );
define( 'BMF_MEMBER_HOME_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMF_MEMBER_HOME_URL', plugin_dir_url( __FILE__ ) );

require_once BMF_MEMBER_HOME_PATH . 'includes/class-settings.php';
require_once BMF_MEMBER_HOME_PATH . 'includes/class-due.php';
require_once BMF_MEMBER_HOME_PATH . 'includes/class-shortcodes.php';

add_action( 'init', array( 'BMF_Member_Home_Shortcodes', 'init' ) );
add_action( 'admin_menu', array( 'BMF_Member_Home_Settings', 'admin_menu' ) );
add_action( 'admin_init', array( 'BMF_Member_Home_Settings', 'register' ) );
