<?php
if (!defined('ABSPATH')) { exit; }

class BMSE_Settings {
    const OPTION = 'bmse_defaults';

    public static function init() {
        // Use a slightly later priority to ensure menus exist
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_init', [__CLASS__, 'register']);
    }

    public static function menu() {
        // ✅ Place Settings directly under Tools (not under our bmse-sql submenu)
        add_submenu_page(
            'tools.php',                              // parent under Tools
            __('BMSE Settings','bmse'),               // page title
            __('BMSE Settings','bmse'),               // menu title (explicit)
            'manage_options',                         // capability
            'bmse-settings',                          // slug
            [__CLASS__, 'render']                     // callback
        );
    }

    public static function register() {
        register_setting('bmse_settings', self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => self::defaults(),
        ]);

        add_settings_section(
            'bmse_section_main',
            __('Toolbar Defaults','bmse'),
            function () {
                echo '<p>'.esc_html__(
                    'Choose the default state of toolbar toggles when the page loads. Users can still adjust them per session.',
                    'bmse'
                ).'</p>';
            },
            'bmse_settings'
        );

        self::checkbox('allow_write',      __('Allow write queries (default)','bmse'));
        self::checkbox('append_limit',     __('Append LIMIT when missing (default)','bmse'));
        self::checkbox('edit_mode',        __('Edit mode (default)','bmse'));
        self::checkbox('auto_run',         __('Auto-run updates (default)','bmse'));
        self::checkbox('auto_add_pk',      __('Auto-add PK for edits (default)','bmse'));
        self::checkbox('auto_run_history', __('Auto-run history SELECT (default)','bmse'));
    }

    private static function checkbox($key, $label) {
        add_settings_field(
            'bmse_'.$key,
            $label,
            function () use ($key) {
                $opts = get_option(self::OPTION, self::defaults());
                $checked = !empty($opts[$key]) ? 'checked' : '';
                echo '<label><input type="checkbox" name="'.esc_attr(self::OPTION).'['.esc_attr($key).']" value="1" '.$checked.'> ';
                echo '</label>';
            },
            'bmse_settings',
            'bmse_section_main'
        );
    }

    public static function sanitize($input) {
        $out = self::defaults();
        foreach ($out as $k => $v) {
            $out[$k] = !empty($input[$k]) ? 1 : 0;
        }
        return $out;
    }

    public static function defaults() {
        return [
            'allow_write'      => 0, // conservative
            'append_limit'     => 1,
            'edit_mode'        => 0,
            'auto_run'         => 0,
            'auto_add_pk'      => 1,
            'auto_run_history' => 0,
        ];
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.','bmse'));
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Breathermae SQL Edit — Settings', 'bmse'); ?></h1>

            <form method="post" action="options.php">
                <?php
                    settings_fields('bmse_settings');
                    do_settings_sections('bmse_settings');
                    submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}