<?php
/**
 * Uninstall script for BreatherMae Ticker
 * Removes the custom table when the plugin is deleted
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'breathermae_ticker_items';

// Remove the custom table
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Optional: Remove any transients or options if we add them later
// delete_option('bm_ticker_version');
