<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div
    id="<?php echo esc_attr($instance_id); ?>"
    class="bmae-avf-root <?php echo esc_attr($custom_class); ?>"
    data-bmae-dashboard="eight-pillars"
    data-bmae-module="2"
    aria-live="polite"
>
    <div class="bmae-avf-loading">
        <span class="bmae-avf-loader" aria-hidden="true"></span>
        <div>
            <strong><?php echo esc_html($title); ?></strong>
            <p><?php echo esc_html__('Loading your Eight Pillars wellness history…', 'breathermae-adaptive-visualization'); ?></p>
        </div>
    </div>
</div>
