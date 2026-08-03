<?php
/**
 * Plugin Name:       Breathermae BioVoicePrint
 * Plugin URI:        https://breathermae.com
 * Description:       BioVoicePrint voice recording, protocol steps, session groups, and private storage. Scoring UI later.
 * Version:           0.2.10-poc
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Breathermae
 * Text Domain:       bmf-biovoice
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMF_BIOVOICE_VERSION', '0.2.10-poc' );
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
require_once BMF_BIOVOICE_PATH . 'includes/class-protocol-service.php';
require_once BMF_BIOVOICE_PATH . 'includes/class-session-service.php';
require_once BMF_BIOVOICE_PATH . 'includes/class-results-service.php';
require_once BMF_BIOVOICE_PATH . 'includes/class-rest-api.php';
require_once BMF_BIOVOICE_PATH . 'includes/class-play.php';
require_once BMF_BIOVOICE_PATH . 'includes/class-shortcodes.php';
require_once BMF_BIOVOICE_PATH . 'includes/class-shortcodes-report.php';
require_once BMF_BIOVOICE_PATH . 'includes/class-shortcodes-scores.php';

/**
 * Activation: tables + storage + protocol seed.
 */
register_activation_hook( __FILE__, function () {
	BMF_BioVoice_Repository::install_tables();
	BMF_BioVoice_Storage::ensure_directory();
	BMF_BioVoice_Protocol_Service::seed_v1_if_needed();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );

add_action( 'plugins_loaded', function () {
	BMF_BioVoice_DBX::init();
	BMF_BioVoice_Protocol_Service::maybe_upgrade();
	BMF_BioVoice_Shortcodes::init();
	BMF_BioVoice_Shortcodes_Report::init();
	BMF_BioVoice_Shortcodes_Scores::init();
	BMF_BioVoice_REST_API::init();
	BMF_BioVoice_Play::init();
} );

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || $screen->id !== 'plugins' ) {
		return;
	}
	echo '<div class="notice notice-info"><p><strong>BioVoicePrint</strong> v' . esc_html( BMF_BIOVOICE_VERSION ) . ' — session, status, report, scores ([bmf_biovoice_session], [bmf_biovoice_status], [bmf_biovoice_report], [bmf_biovoice_scores]).</p></div>';
} );
