<?php
/**
 * Plugin Name: BMF Calendar
 * Description: Provider–Member appointment scheduling with availability, Calendly-style requests, and Outlook integration. WP Fusion optional. Mobile-capable.
 * Version: 0.3.4-poc
 * Author: Breathermae / Black Mountain Factory
 * Text Domain: bmf-calendar
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMF_CALENDAR_VERSION', '0.3.4-poc' );
define( 'BMF_CALENDAR_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMF_CALENDAR_URL', plugin_dir_url( __FILE__ ) );
define( 'BMF_CALENDAR_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main bootstrap.
 */
final class BMF_Calendar {

	/** @var BMF_Calendar|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		$this->hooks();
	}

	private function includes() {
		require_once BMF_CALENDAR_PATH . 'includes/class-activator.php';
		require_once BMF_CALENDAR_PATH . 'includes/class-db.php';
		require_once BMF_CALENDAR_PATH . 'includes/class-provider.php';
		require_once BMF_CALENDAR_PATH . 'includes/class-settings.php';
		require_once BMF_CALENDAR_PATH . 'includes/class-shortcodes.php';
		require_once BMF_CALENDAR_PATH . 'includes/class-availability.php';
		require_once BMF_CALENDAR_PATH . 'includes/class-mail.php';
		require_once BMF_CALENDAR_PATH . 'includes/class-uls-bridge.php';
		require_once BMF_CALENDAR_PATH . 'includes/class-ajax.php';
		require_once BMF_CALENDAR_PATH . 'includes/class-outlook.php';
	}

	private function hooks() {
		register_activation_hook( __FILE__, array( 'BMF_Calendar_Activator', 'activate' ) );
		register_deactivation_hook( __FILE__, array( 'BMF_Calendar_Activator', 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function init() {
		// Capability registration only in admin.
		add_action( 'init', array( 'BMF_Calendar_Provider', 'register_capability' ) );

		if ( get_option( 'bmf_calendar_db_version' ) !== BMF_Calendar_Activator::DB_VERSION ) {
			BMF_Calendar_Activator::create_tables();
			update_option( 'bmf_calendar_db_version', BMF_Calendar_Activator::DB_VERSION );
		}

		BMF_Calendar_Settings::instance();
		BMF_Calendar_Shortcodes::instance();
		BMF_Calendar_Ajax::instance();
		BMF_Calendar_Outlook::instance();

		// Front-end assets only when needed (shortcodes will trigger).
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (but do not always enqueue) front-end assets.
	 * Skip on login / register screens so we never interfere with auth AJAX.
	 */
	public function register_assets() {
		if ( is_admin() ) {
			return;
		}

		global $pagenow;
		if ( isset( $pagenow ) && in_array( $pagenow, array( 'wp-login.php', 'wp-register.php' ), true ) ) {
			return;
		}

		$css_ver = @filemtime( BMF_CALENDAR_PATH . 'assets/css/bmf-calendar.css' ) ?: BMF_CALENDAR_VERSION;
		$js_ver  = @filemtime( BMF_CALENDAR_PATH . 'assets/js/bmf-calendar.js' ) ?: BMF_CALENDAR_VERSION;

		wp_register_style(
			'bmf-calendar',
			BMF_CALENDAR_URL . 'assets/css/bmf-calendar.css',
			array(),
			$css_ver
		);

		wp_register_script(
			'bmf-calendar',
			BMF_CALENDAR_URL . 'assets/js/bmf-calendar.js',
			array( 'jquery' ),
			$js_ver,
			true
		);

		$tz      = wp_timezone();
		$tz_now  = new DateTime( 'now', $tz );

		wp_localize_script(
			'bmf-calendar',
			'BMF_CALENDAR',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'bmf_calendar' ),
				'siteTz'        => $tz->getName(),
				'siteTzAbbr'    => $tz_now->format( 'T' ),
				'siteUtcOffset' => (int) $tz->getOffset( $tz_now ),
				'i18n'          => array(
					'loading'   => __( 'Loading…', 'bmf-calendar' ),
					'error'     => __( 'Something went wrong. Please try again.', 'bmf-calendar' ),
					'confirm'   => __( 'Confirm', 'bmf-calendar' ),
					'cancel'    => __( 'Cancel', 'bmf-calendar' ),
					'requested' => __( 'Requested', 'bmf-calendar' ),
				),
			)
		);
	}
}

BMF_Calendar::instance();
