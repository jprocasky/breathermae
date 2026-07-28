<?php
if (!defined('ABSPATH')) {
    exit;
}

final class BMAE_AVF_Plugin {
    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('rest_api_init', [BMAE_AVF_REST_Controller::class, 'register_routes']);
        add_action('init', [BMAE_AVF_Shortcodes::class, 'register']);
        add_action('admin_notices', [$this, 'elementor_information_notice']);
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'breathermae-adaptive-visualization',
            false,
            dirname(BMAE_AVF_BASENAME) . '/languages'
        );
    }

    public function register_assets(): void {
        wp_register_style(
            'bmae-avf-dashboard',
            BMAE_AVF_URL . 'assets/css/framework.css',
            [],
            BMAE_AVF_VERSION
        );

        wp_register_script(
            'bmae-avf-dashboard',
            BMAE_AVF_URL . 'assets/js/framework.js',
            [],
            BMAE_AVF_VERSION,
            true
        );

        wp_add_inline_script(
            'bmae-avf-dashboard',
            'window.BMAE_AVF = ' . wp_json_encode(BMAE_AVF_Config::public_bootstrap_config()) . ';',
            'before'
        );
    }

    public function elementor_information_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!did_action('elementor/loaded')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'plugins') {
            return;
        }

        echo '<div class="notice notice-info is-dismissible"><p>';
        echo esc_html__(
            'Breathermae Adaptive Visualization Framework is Elementor-compatible through the Elementor Shortcode widget. Use [breathermae_eight_pillars].',
            'breathermae-adaptive-visualization'
        );
        echo '</p></div>';
    }
}
