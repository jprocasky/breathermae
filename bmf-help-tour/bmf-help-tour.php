<?php
/**
 * Plugin Name: BMF Help Tour
 * Description: Attribute-driven guided help tours for Elementor pages. Uses bmf-help-* attributes on widgets. Tracks completion in user meta. Mobile-first. Elementor controls on Containers, Sections, Columns, and all widgets.
 * Version: 0.2.5-poc
 * Author: Breathermae
 * Text Domain: bmf-help-tour
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMF_HELP_TOUR_VERSION', '0.2.5-poc' );
define( 'BMF_HELP_TOUR_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMF_HELP_TOUR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main plugin bootstrap.
 */
final class BMF_Help_Tour {

	/** @var BMF_Help_Tour|null */
	private static $instance = null;

	/** User meta key that stores completed tour IDs (array). */
	const META_KEY = 'bmf_completed_help_tours';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_bmf_help_tour_complete', array( $this, 'ajax_mark_complete' ) );
		add_action( 'wp_ajax_bmf_help_tour_reset', array( $this, 'ajax_reset' ) );
		add_shortcode( 'bmf_help_tour_restart', array( $this, 'shortcode_restart' ) );

		// Elementor Container / Section controls (optional — only if Elementor is active).
		add_action( 'plugins_loaded', array( $this, 'maybe_load_elementor' ) );
		// No nopriv — only logged-in members.
	}

	/**
	 * Load Elementor integration when Elementor is present.
	 */
	public function maybe_load_elementor() {
		if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
			// Elementor may load later; still require the file and let it hook elementor/init.
		}
		require_once BMF_HELP_TOUR_PATH . 'includes/class-elementor-controls.php';
		BMF_Help_Tour_Elementor_Controls::instance();
	}

	/**
	 * Enqueue only for logged-in users on the frontend.
	 */
	public function enqueue_assets() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}

		// Driver.js from CDN (POC). Switch to local later if desired.
		wp_enqueue_style(
			'driver-js',
			'https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css',
			array(),
			'1.3.1'
		);

		wp_enqueue_script(
			'driver-js',
			'https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js',
			array(),
			'1.3.1',
			true
		);

		wp_enqueue_style(
			'bmf-help-tour',
			BMF_HELP_TOUR_URL . 'assets/css/bmf-help-tour.css',
			array( 'driver-js' ),
			BMF_HELP_TOUR_VERSION
		);

		wp_enqueue_script(
			'bmf-help-tour',
			BMF_HELP_TOUR_URL . 'assets/js/bmf-help-tour.js',
			array( 'driver-js' ),
			BMF_HELP_TOUR_VERSION,
			true
		);

		$user_id   = get_current_user_id();
		$completed = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $completed ) ) {
			$completed = array();
		}

		$post_id = get_queried_object_id();

		wp_localize_script(
			'bmf-help-tour',
			'bmfHelpTour',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'bmf_help_tour' ),
				'postId'    => $post_id,
				'completed' => $completed,
				'strings'   => array(
					'next'  => __( 'Next', 'bmf-help-tour' ),
					'prev'  => __( 'Back', 'bmf-help-tour' ),
					'done'  => __( 'Done', 'bmf-help-tour' ),
					'skip'  => __( 'Skip tour', 'bmf-help-tour' ),
					'close' => __( 'Close', 'bmf-help-tour' ),
				),
				// Debug flag – set true while developing.
				'debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
			)
		);
	}

	/**
	 * Shortcode: [bmf_help_tour_restart]
	 *
	 * Optional attributes:
	 *   text="Restart tour"   – link/button label (default: "Restart tour")
	 *   class="my-extra"      – extra CSS classes
	 *
	 * You can also put the class "bmf-help-tour-restart" on any Elementor
	 * icon, button, or link — no shortcode required.
	 */
	public function shortcode_restart( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'text'  => __( 'Restart tour', 'bmf-help-tour' ),
				'class' => '',
			),
			$atts,
			'bmf_help_tour_restart'
		);

		$classes = trim( 'bmf-help-tour-restart ' . $atts['class'] );

		return sprintf(
			'<a href="#" class="%s" role="button" aria-label="%s">%s</a>',
			esc_attr( $classes ),
			esc_attr( $atts['text'] ),
			esc_html( $atts['text'] )
		);
	}

	/**
	 * AJAX: mark a tour as completed for the current user.
	 */
	public function ajax_mark_complete() {
		check_ajax_referer( 'bmf_help_tour', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Not logged in' ), 403 );
		}

		$tour_id = isset( $_POST['tour_id'] ) ? sanitize_text_field( wp_unslash( $_POST['tour_id'] ) ) : '';
		if ( empty( $tour_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing tour_id' ), 400 );
		}

		$user_id   = get_current_user_id();
		$completed = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $completed ) ) {
			$completed = array();
		}

		if ( ! in_array( $tour_id, $completed, true ) ) {
			$completed[] = $tour_id;
			update_user_meta( $user_id, self::META_KEY, $completed );
		}

		wp_send_json_success( array(
			'tour_id'   => $tour_id,
			'completed' => $completed,
		) );
	}

	/**
	 * AJAX: remove a tour from the completed list so it can run again.
	 */
	public function ajax_reset() {
		check_ajax_referer( 'bmf_help_tour', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Not logged in' ), 403 );
		}

		$tour_id = isset( $_POST['tour_id'] ) ? sanitize_text_field( wp_unslash( $_POST['tour_id'] ) ) : '';
		if ( empty( $tour_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing tour_id' ), 400 );
		}

		$user_id   = get_current_user_id();
		$completed = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $completed ) ) {
			$completed = array();
		}

		$completed = array_values( array_filter( $completed, function ( $id ) use ( $tour_id ) {
			return $id !== $tour_id;
		} ) );

		update_user_meta( $user_id, self::META_KEY, $completed );

		wp_send_json_success( array(
			'tour_id'   => $tour_id,
			'completed' => $completed,
		) );
	}
}

BMF_Help_Tour::instance();
