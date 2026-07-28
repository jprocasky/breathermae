<?php
if (!defined('ABSPATH')) {
    exit;
}

final class BMAE_AVF_Shortcodes {
    public static function register(): void {
        add_shortcode('breathermae_eight_pillars', [self::class, 'render_eight_pillars']);
    }

    public static function render_eight_pillars(array $atts = []): string {
        if (!is_user_logged_in()) {
            return '<div class="bmae-avf-notice bmae-avf-notice--authentication">'
                . esc_html__('Please sign in to view your wellness dashboard.', 'breathermae-adaptive-visualization')
                . '</div>';
        }

        $atts = shortcode_atts([
            'user_id' => get_current_user_id(),
            'title' => '8-Pillars of Wellness Evolution™',
            'class' => '',
        ], $atts, 'breathermae_eight_pillars');

        $requested_user_id = absint($atts['user_id']);
        $user_id = BMAE_AVF_Security::resolve_shortcode_user_id($requested_user_id);
        $title = BMAE_AVF_Security::sanitize_title((string) $atts['title']);
        $custom_class = sanitize_html_class((string) $atts['class']);

        wp_enqueue_style('bmae-avf-dashboard');
        wp_enqueue_script('bmae-avf-dashboard');

        $instance_id = 'bmae-avf-' . wp_generate_uuid4();
        $bootstrap = [
            'instanceId' => $instance_id,
            'dashboardId' => 'eight-pillars',
            'userId' => $user_id,
            'title' => $title,
            'restUrl' => esc_url_raw(
                rest_url('breathermae/v1/dashboard/eight-pillars/' . $user_id)
            ),
            'nonce' => BMAE_AVF_Security::nonce(),
            'module' => 2,
        ];

        wp_add_inline_script(
            'bmae-avf-dashboard',
            'window.BMAE_AVF_QUEUE = window.BMAE_AVF_QUEUE || [];'
            . 'window.BMAE_AVF_QUEUE.push(' . wp_json_encode($bootstrap) . ');',
            'before'
        );

        $template = BMAE_AVF_DIR . 'templates/dashboard-shell.php';

        if (!file_exists($template)) {
            return '<div class="bmae-avf-notice bmae-avf-notice--error">'
                . esc_html__('Dashboard template unavailable.', 'breathermae-adaptive-visualization')
                . '</div>';
        }

        ob_start();
        include $template;
        return (string) ob_get_clean();
    }
}
