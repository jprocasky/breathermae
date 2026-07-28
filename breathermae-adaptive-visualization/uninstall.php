<?php
/**
 * Uninstall handler for the Breathermae Adaptive Visualization Framework.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Module 1 creates no custom database tables.
 * Remove only framework-owned options.
 */
delete_option('bmae_avf_version');
delete_option('bmae_avf_schema_version');
delete_option('bmae_avf_settings');
