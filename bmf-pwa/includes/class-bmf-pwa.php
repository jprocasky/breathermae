<?php
/**
 * Main plugin class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_PWA {

	/** @var BMF_PWA */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Settings page.
		BMF_PWA_Settings::instance();

		// Manifest + Service Worker endpoints.
		BMF_PWA_Manifest::instance();

		// Install button shortcode + assets.
		BMF_PWA_Button::instance();

		// Add manifest link + theme-color in <head>.
		add_action( 'wp_head', array( $this, 'print_head_tags' ), 1 );
	}

	/**
	 * Output the <link rel="manifest"> and theme-color meta.
	 */
	public function print_head_tags() {
		$settings = BMF_PWA_Settings::get_settings();

		$manifest_url = home_url( '/bmf-pwa-manifest.webmanifest' );
		echo '<link rel="manifest" href="' . esc_url( $manifest_url ) . '">' . "\n";

		if ( ! empty( $settings['theme_color'] ) ) {
			echo '<meta name="theme-color" content="' . esc_attr( $settings['theme_color'] ) . '">' . "\n";
		}

		// Helpful for iOS "Add to Home Screen".
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
		if ( ! empty( $settings['short_name'] ) ) {
			echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $settings['short_name'] ) . '">' . "\n";
		}

		// Apple touch icon (use 192 if available).
		if ( ! empty( $settings['icon_192'] ) ) {
			echo '<link rel="apple-touch-icon" href="' . esc_url( $settings['icon_192'] ) . '">' . "\n";
		}
	}
}
