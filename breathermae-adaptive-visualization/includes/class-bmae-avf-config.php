<?php
if (!defined('ABSPATH')) {
    exit;
}

final class BMAE_AVF_Config {
    public const OPTION_VERSION = 'bmae_avf_version';
    public const OPTION_SCHEMA_VERSION = 'bmae_avf_schema_version';
    public const OPTION_SETTINGS = 'bmae_avf_settings';

    public static function defaults(): array {
        return [
            'enable_demo_data' => true,
            'require_authentication' => true,
            'default_dashboard' => 'eight-pillars',
            'cache_ttl_seconds' => 300,
            'engine_version' => '1.1.0',
            'schema_version' => '2.0.0',
        ];
    }

    public static function settings(): array {
        $stored = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return wp_parse_args($stored, self::defaults());
    }

    public static function setting(string $key, mixed $fallback = null): mixed {
        $settings = self::settings();
        return array_key_exists($key, $settings) ? $settings[$key] : $fallback;
    }

    public static function dashboard_registry(): array {
        $registry = [
            'eight-pillars' => [
                'id' => 'eight-pillars',
                'label' => 'Eight Pillars of Wellness',
                'shortcode' => 'breathermae_eight_pillars',
                'rest_namespace' => 'breathermae/v1',
                'rest_route' => '/dashboard/eight-pillars/(?P<user_id>\d+)',
                'template' => 'dashboard-shell.php',
                'asset_handle' => 'bmae-avf-dashboard',
            ],
        ];

        return apply_filters('bmae_avf_dashboard_registry', $registry);
    }

    public static function dashboard(string $dashboard_id): ?array {
        $registry = self::dashboard_registry();
        return $registry[$dashboard_id] ?? null;
    }

    public static function public_bootstrap_config(): array {
        return [
            'pluginVersion' => BMAE_AVF_VERSION,
            'engineVersion' => (string) self::setting('engine_version', BMAE_AVF_VERSION),
            'schemaVersion' => (string) self::setting('schema_version', '2.0.0'),
            'restRoot' => esc_url_raw(rest_url()),
            'isAuthenticated' => is_user_logged_in(),
        ];
    }
}
