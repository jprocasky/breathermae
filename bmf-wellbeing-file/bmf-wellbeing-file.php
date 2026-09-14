<?php
/**
 * Plugin Name:       BMF Wellbeing File
 * Plugin URI:        https://breathermae.com
 * Description:       Canonical wellbeing brief from RSI, Pillars, Keys, BSI, BioVoicePrint, and Fitbit nights. Status + brief shortcodes.
 * Version:           0.1.8-poc
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Breathermae
 * Text Domain:       bmf-wellbeing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMF_WELLBEING_VERSION', '0.1.8-poc' );
define( 'BMF_WELLBEING_FILE', __FILE__ );
define( 'BMF_WELLBEING_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMF_WELLBEING_URL', plugin_dir_url( __FILE__ ) );

if ( ! function_exists( 'bmf_wellbeing_in_elementor_editor' ) ) {
	function bmf_wellbeing_in_elementor_editor(): bool {
		if ( function_exists( 'bmf_in_elementor_editor' ) ) {
			return (bool) bmf_in_elementor_editor();
		}
		if ( ! defined( 'ELEMENTOR_VERSION' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		$plugin = \Elementor\Plugin::$instance;
		if ( $plugin->editor && $plugin->editor->is_edit_mode() ) {
			return true;
		}
		if ( $plugin->preview && $plugin->preview->is_preview_mode() ) {
			return true;
		}
		return isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] );
	}
}

require_once BMF_WELLBEING_PATH . 'includes/class-map.php';
require_once BMF_WELLBEING_PATH . 'includes/class-freshness.php';
require_once BMF_WELLBEING_PATH . 'includes/class-access.php';
require_once BMF_WELLBEING_PATH . 'includes/class-adapter-rsi.php';
require_once BMF_WELLBEING_PATH . 'includes/class-adapter-pillars.php';
require_once BMF_WELLBEING_PATH . 'includes/class-adapter-keys.php';
require_once BMF_WELLBEING_PATH . 'includes/class-adapter-bsi.php';
require_once BMF_WELLBEING_PATH . 'includes/class-adapter-biovoice.php';
require_once BMF_WELLBEING_PATH . 'includes/class-adapter-fitbit.php';
require_once BMF_WELLBEING_PATH . 'includes/class-adapter-profile.php';
require_once BMF_WELLBEING_PATH . 'includes/class-assembler.php';
require_once BMF_WELLBEING_PATH . 'includes/class-shortcodes.php';

add_action( 'init', [ 'BMF_Wellbeing_Shortcodes', 'init' ] );
