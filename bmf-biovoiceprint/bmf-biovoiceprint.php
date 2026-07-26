<?php
/**
 * Plugin Name:       Breathermae BioVoicePrint
 * Plugin URI:        https://breathermae.com
 * Description:       Skeleton for BioVoicePrint voice recording, private storage, and session tracking. Framework only — no scoring yet.
 * Version:           0.1.5-poc
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Breathermae
 * Text Domain:       bmf-biovoice
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMF_BIOVOICE_VERSION', '0.1.5-poc' );
define( 'BMF_BIOVOICE_FILE', __FILE__ );
define( 'BMF_BIOVOICE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMF_BIOVOICE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Lightweight DB accessor — same pattern used by BSI shortcodes.
 */
if ( ! class_exists( 'BMF_BioVoice_DBX' ) ) {
	class BMF_BioVoice_DBX {
		/** @var wpdb */
		public static $db;

		public static function init() {
			global $wpdb;
			self::$db = $wpdb;
		}

		public static function t( $suffix ) {
			return self::$db->prefix . $suffix;
		}
	}
	BMF_BioVoice_DBX::init();
}

/**
 * Elementor editor detection (shared helper style).
 */
if ( ! function_exists( 'bmf_biovoice_in_elementor_editor' ) ) {
	function bmf_biovoice_in_elementor_editor(): bool {
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
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) {
			return true;
		}
		return false;
	}
}

require_once BMF_BIOVOICE_PATH . 'includes/class-repository.php';
require_once BMF_BIOVOICE_PATH . 'includes/class-storage.php';
require_once BMF_BIOVOICE_PATH . 'includes/class-session-service.php';
require_once BMF_BIOVOICE_PATH . 'includes/class-rest-api.php';
require_once BMF_BIOVOICE_PATH . 'includes/class-play.php';
require_once BMF_BIOVOICE_PATH . 'includes/class-shortcodes.php';

/**
 * Activation: create tables + ensure private storage directory exists.
 */
register_activation_hook( __FILE__, function () {
	BMF_BioVoice_Repository::install_tables();
	BMF_BioVoice_Storage::ensure_directory();
	flush_rewrite_rules();
} );

/**
 * Deactivation: just flush rewrites. Leave data intact.
 */
register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );

/**
 * Bootstrap.
 */
add_action( 'plugins_loaded', function () {
	BMF_BioVoice_DBX::init();
	BMF_BioVoice_Shortcodes::init();
	BMF_BioVoice_REST_API::init();
	BMF_BioVoice_Play::init();
} );

/**
 * Admin notice for POC status.
 */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || $screen->id !== 'plugins' ) {
		return;
	}
	echo '<div class="notice notice-info"><p><strong>BioVoicePrint</strong> is running in POC / skeleton mode (v' . esc_html( BMF_BIOVOICE_VERSION ) . '). Recording + private storage only — scoring and results UI will be added later.</p></div>';
} );
