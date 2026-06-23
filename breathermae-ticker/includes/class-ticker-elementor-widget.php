<?php
/**
 * Elementor Widget: BreatherMae Ticker
 */

if (!defined('ABSPATH')) {
    exit;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

class BM_Ticker_Elementor_Widget extends Widget_Base {

    public function get_name() {
        return 'bm-ticker';
    }

    public function get_title() {
        return __('BreatherMae Ticker', 'breathermae-ticker');
    }

    public function get_icon() {
        return 'eicon-animation-text';
    }

    public function get_categories() {
        return ['general', 'breathermae'];
    }

    public function get_keywords() {
        return ['ticker', 'marquee', 'scroll', 'tip', 'health', 'daily', 'breathermae'];
    }

    protected function register_controls() {

        // === CONTENT SECTION ===
        $this->start_controls_section(
            'content_section',
            [
                'label' => __('Content', 'breathermae-ticker'),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'prefix',
            [
                'label'       => __('Prefix Text', 'breathermae-ticker'),
                'type'        => Controls_Manager::TEXT,
                'default'     => 'Health Tip: ',
                'placeholder' => 'Health Tip: ',
                'label_block' => true,
            ]
        );

        $this->add_control(
            'postfix',
            [
                'label'       => __('Postfix Text', 'breathermae-ticker'),
                'type'        => Controls_Manager::TEXT,
                'default'     => ' • BreatherMae',
                'placeholder' => ' • BreatherMae',
                'label_block' => true,
            ]
        );

        $this->add_control(
            'rotation_mode',
            [
                'label'   => __('Rotation Mode', 'breathermae-ticker'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'daily',
                'options' => [
                    'daily'  => __('Daily (same tip all day)', 'breathermae-ticker'),
                    'visit'  => __('Visit-based (next tip each visit)', 'breathermae-ticker'),
                    'random' => __('Random each time', 'breathermae-ticker'),
                ],
            ]
        );

        $this->add_control(
            'tip_types',
            [
                'label'       => __('Tip Types to Show', 'breathermae-ticker'),
                'type'        => Controls_Manager::TEXT,
                'default'     => 'general',
                'description' => __('Comma-separated list. Example: general,pro,nutrition', 'breathermae-ticker'),
                'label_block' => true,
            ]
        );

        $this->add_control(
            'only_active',
            [
                'label'   => __('Only Show Active Tips', 'breathermae-ticker'),
                'type'    => Controls_Manager::SWITCHER,
                'default' => 'yes',
                'return_value' => 'yes',
            ]
        );

        $this->end_controls_section();

        // === STYLE SECTION ===
        $this->start_controls_section(
            'style_section',
            [
                'label' => __('Style', 'breathermae-ticker'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'background_color',
            [
                'label'     => __('Background Color', 'breathermae-ticker'),
                'type'      => Controls_Manager::COLOR,
                'default'   => '#0f172a',
                'selectors' => [
                    '{{WRAPPER}} .bm-ticker' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'text_color',
            [
                'label'     => __('Text Color', 'breathermae-ticker'),
                'type'      => Controls_Manager::COLOR,
                'default'   => '#e2e8f0',
                'selectors' => [
                    '{{WRAPPER}} .bm-ticker, {{WRAPPER}} .bm-ticker__item' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'typography',
                'label'    => __('Typography', 'breathermae-ticker'),
                'selector' => '{{WRAPPER}} .bm-ticker__item',
            ]
        );

        $this->add_control(
            'scroll_speed',
            [
                'label'       => __('Scroll Duration (seconds)', 'breathermae-ticker'),
                'type'        => Controls_Manager::SLIDER,
                'description' => __('Lower number = faster scrolling. 8–15s is usually good for a fast ticker. 25–40s feels slower and more relaxed.', 'breathermae-ticker'),
                'range'       => [
                    's' => [
                        'min'  => 5,
                        'max'  => 60,
                        'step' => 1,
                    ],
                ],
                'default' => [
                    'unit' => 's',
                    'size' => 12,
                ],
            ]
        );

        $this->add_control(
            'pause_on_hover',
            [
                'label'   => __('Pause on Hover', 'breathermae-ticker'),
                'type'    => Controls_Manager::SWITCHER,
                'default' => 'yes',
                'return_value' => 'yes',
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();

        $prefix       = !empty($settings['prefix']) ? $settings['prefix'] : '';
        $postfix      = !empty($settings['postfix']) ? $settings['postfix'] : '';
        $mode         = $settings['rotation_mode'] ?? 'daily';
        $types_raw    = $settings['tip_types'] ?? 'general';
        $only_active  = ($settings['only_active'] ?? 'yes') === 'yes';

        // Parse types
        $types = array_map('trim', explode(',', $types_raw));
        $types = array_filter($types);

        if (empty($types)) {
            $types = ['general'];
        }

        // Get the single tip
        require_once BM_TICKER_PATH . 'includes/class-ticker-db.php';

        $tip = BM_Ticker_DB::get_single_tip([
            'mode'        => $mode,
            'types'       => $types,
            'only_active' => $only_active,
        ]);

        if (!$tip || empty($tip['message'])) {
            // Fallback message
            echo '<div class="bm-ticker bm-ticker--empty">';
            echo '<div class="bm-ticker__track">';
            echo '<span class="bm-ticker__item">' . esc_html($prefix . 'No active tips available right now.' . $postfix) . '</span>';
            echo '</div></div>';
            return;
        }

        // Build the full scrolling string
        $full_text = trim($prefix . ' ' . $tip['message'] . ' ' . $postfix);
        $full_text = wp_strip_all_tags($full_text); // safety

        // Data attributes for JS
        $duration = isset($settings['scroll_speed']['size']) ? intval($settings['scroll_speed']['size']) : 12;
        $pause = ($settings['pause_on_hover'] ?? 'yes') === 'yes' ? 'true' : 'false';

        ?>
        <div class="bm-ticker"
             data-duration="<?php echo esc_attr($duration); ?>"
             data-pause-on-hover="<?php echo esc_attr($pause); ?>">

            <div class="bm-ticker__track">
                <?php
                // Start with 2 copies — JS will duplicate more if needed for narrow screens
                for ($i = 0; $i < 2; $i++) :
                ?>
                    <span class="bm-ticker__item"><?php echo esc_html($full_text); ?></span>
                <?php endfor; ?>
            </div>
        </div>
        <?php
    }

    protected function content_template() {
        // Live preview in Elementor editor
        ?>
        <#
        var prefix = settings.prefix || 'Health Tip: ';
        var postfix = settings.postfix || ' • BreatherMae';
        var fullText = prefix + ' ' + 'This is a sample health tip that will scroll continuously.' + ' ' + postfix;
        var durationValue = (settings.scroll_speed && settings.scroll_speed.size) ? settings.scroll_speed.size : 12;
        #>
        <div class="bm-ticker" data-duration="{{ durationValue }}" data-pause-on-hover="true"
             style="background-color: {{ settings.background_color }}; color: {{ settings.text_color }};">
            <div class="bm-ticker__track">
                <span class="bm-ticker__item">{{{ fullText }}}</span>
                <span class="bm-ticker__item">{{{ fullText }}}</span>
                <span class="bm-ticker__item">{{{ fullText }}}</span>
            </div>
        </div>
        <?php
    }
}
