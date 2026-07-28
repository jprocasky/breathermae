<?php
if (!defined('ABSPATH')) {
    exit;
}

final class BMAE_AVF_Activator {
    public static function activate(): void {
        self::assert_environment();

        update_option(BMAE_AVF_Config::OPTION_VERSION, BMAE_AVF_VERSION, false);
        update_option(
            BMAE_AVF_Config::OPTION_SCHEMA_VERSION,
            (string) BMAE_AVF_Config::defaults()['schema_version'],
            false
        );

        if (get_option(BMAE_AVF_Config::OPTION_SETTINGS, null) === null) {
            add_option(
                BMAE_AVF_Config::OPTION_SETTINGS,
                BMAE_AVF_Config::defaults(),
                '',
                false
            );
        }

        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    private static function assert_environment(): void {
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            deactivate_plugins(BMAE_AVF_BASENAME);
            wp_die(
                esc_html__('Breathermae Adaptive Visualization Framework requires PHP 8.0 or newer.', 'breathermae-adaptive-visualization'),
                esc_html__('Plugin activation failed', 'breathermae-adaptive-visualization'),
                ['back_link' => true]
            );
        }

        global $wp_version;
        if (version_compare((string) $wp_version, '6.4', '<')) {
            deactivate_plugins(BMAE_AVF_BASENAME);
            wp_die(
                esc_html__('Breathermae Adaptive Visualization Framework requires WordPress 6.4 or newer.', 'breathermae-adaptive-visualization'),
                esc_html__('Plugin activation failed', 'breathermae-adaptive-visualization'),
                ['back_link' => true]
            );
        }
    }
}
