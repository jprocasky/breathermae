<?php
if (!defined('ABSPATH')) {
    exit;
}

final class BMAE_AVF_REST_Controller {
    public static function register_routes(): void {
        register_rest_route('breathermae/v1', '/framework/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'framework_status'],
            'permission_callback' => static fn(): bool => is_user_logged_in(),
        ]);

        register_rest_route('breathermae/v1', '/dashboard/eight-pillars/(?P<user_id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'eight_pillars'],
            'permission_callback' => [BMAE_AVF_Security::class, 'rest_permission'],
            'args' => [
                'user_id' => [
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => static fn($value): bool => is_numeric($value) && (int) $value > 0,
                ],
            ],
        ]);
    }

    public static function framework_status(): WP_REST_Response {
        return new WP_REST_Response([
            'status' => 'ready',
            'plugin_version' => BMAE_AVF_VERSION,
            'engine_version' => BMAE_AVF_Config::setting('engine_version', BMAE_AVF_VERSION),
            'schema_version' => BMAE_AVF_Config::setting('schema_version', '2.0.0'),
            'registered_dashboards' => array_keys(BMAE_AVF_Config::dashboard_registry()),
        ], 200);
    }

    public static function eight_pillars(WP_REST_Request $request): WP_REST_Response {
        $user_id = absint($request->get_param('user_id'));
        $payload = BMAE_AVF_Eight_Pillars_Provider::dashboard_payload($user_id);

        return new WP_REST_Response($payload, 200, [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
