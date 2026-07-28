<?php
if (!defined('ABSPATH')) {
    exit;
}

final class BMAE_AVF_Security {
    public static function can_view_user_dashboard(int $requested_user_id): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        $current_user_id = get_current_user_id();

        if ($requested_user_id === $current_user_id) {
            return true;
        }

        return current_user_can('list_users') || current_user_can('edit_users');
    }

    public static function resolve_shortcode_user_id(int $requested_user_id): int {
        $current_user_id = get_current_user_id();

        if ($requested_user_id <= 0) {
            return $current_user_id;
        }

        if (self::can_view_user_dashboard($requested_user_id)) {
            return $requested_user_id;
        }

        return $current_user_id;
    }

    public static function rest_permission(WP_REST_Request $request): bool {
        $requested_user_id = absint($request->get_param('user_id'));
        return self::can_view_user_dashboard($requested_user_id);
    }

    public static function sanitize_title(string $title): string {
        return sanitize_text_field(wp_strip_all_tags($title));
    }

    public static function nonce(): string {
        return wp_create_nonce('wp_rest');
    }
}
