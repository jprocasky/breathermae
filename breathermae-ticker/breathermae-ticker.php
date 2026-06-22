<?php
/**
 * Plugin Name: BreatherMae Ticker
 * Plugin URI: https://github.com/jprocasky/breathermae
 * Description: Custom Elementor widget for a single daily/visit-based/random scrolling health tip ticker. Pulls from a custom database table.
 * Version: 1.0.0
 * Author: BreatherMae
 * Author URI: https://www.breathermae.com
 * Text Domain: breathermae-ticker
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BM_TICKER_VERSION', '1.0.0');
define('BM_TICKER_PATH', plugin_dir_path(__FILE__));
define('BM_TICKER_URL', plugin_dir_url(__FILE__));

// Activation hook - create custom table
register_activation_hook(__FILE__, 'bm_ticker_activate');

function bm_ticker_activate() {
    require_once BM_TICKER_PATH . 'includes/class-ticker-db.php';
    BM_Ticker_DB::create_table();
}

// Load the Elementor widget
add_action('elementor/widgets/widgets_registered', 'bm_ticker_register_widget');

function bm_ticker_register_widget() {
    // Make sure Elementor is loaded
    if (!did_action('elementor/loaded')) {
        return;
    }
    
    require_once BM_TICKER_PATH . 'includes/class-ticker-elementor-widget.php';
    
    \Elementor\Plugin::instance()->widgets_manager->register_widget_type(
        new \BM_Ticker_Elementor_Widget()
    );
}

// Enqueue frontend assets
add_action('wp_enqueue_scripts', 'bm_ticker_enqueue_assets');

function bm_ticker_enqueue_assets() {
    wp_enqueue_style(
        'bm-ticker-style',
        BM_TICKER_URL . 'assets/css/ticker.css',
        [],
        BM_TICKER_VERSION
    );

    wp_enqueue_script(
        'bm-ticker-script',
        BM_TICKER_URL . 'assets/js/ticker.js',
        [],
        BM_TICKER_VERSION,
        true
    );
}

// Optional: Simple admin page for managing tips (you can also edit directly via Excel VBA)
add_action('admin_menu', 'bm_ticker_admin_menu');

function bm_ticker_admin_menu() {
    add_menu_page(
        'BreatherMae Tips',
        'BM Tips',
        'manage_options',
        'breathermae-ticker',
        'bm_ticker_admin_page',
        'dashicons-megaphone',
        25
    );
}

function bm_ticker_admin_page() {
    require_once BM_TICKER_PATH . 'includes/class-ticker-db.php';
    require_once BM_TICKER_PATH . 'includes/class-ticker-admin.php';
    BM_Ticker_Admin::render_page();
}
