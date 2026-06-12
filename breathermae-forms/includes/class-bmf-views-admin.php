<?php
// includes/class-bmf-views-admin.php
if (!defined('ABSPATH')) exit;

class BMF_Views_Admin {

    public static function render_admin() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'bmf'));
        }

        $action  = isset($_POST['bmf_views_action']) ? sanitize_text_field($_POST['bmf_views_action']) : '';
        $results = null;
        $errors  = [];

        if (!empty($action)) {
            check_admin_referer('bmf_views_build', 'bmf_views_nonce');

            if ($action === 'build_long') {
                $results = ['long' => BMF_Views::create_long_view()];
            } elseif ($action === 'build_selected') {
                $slugs = isset($_POST['bmf_form_slugs']) ? (array) $_POST['bmf_form_slugs'] : [];
                $slugs = array_map('sanitize_text_field', $slugs);
                $pivots = [];
                foreach ($slugs as $slug) {
                    $pivots[$slug] = BMF_Views::create_pivot_view_for_form($slug);
                }
                $results = ['pivots' => $pivots];
            } elseif ($action === 'build_all') {
                $results = ['long' => BMF_Views::create_long_view(), 'pivots' => BMF_Views::create_all_pivot_views('published')];
            }
        }

        // Fetch available (published) form slugs for the UI
        $slugs = BMF_Views::get_form_slugs_by_status('published');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Breathermae Forms – Analysis Views', 'bmf'); ?></h1>
            <p><?php esc_html_e('Build or rebuild database views used for external analysis. This will attempt CREATE OR REPLACE VIEW statements. Your database user must have the CREATE VIEW privilege.', 'bmf'); ?></p>

            <?php if ($results): ?>
                <div class="notice notice-info is-dismissible">
                    <p><strong><?php esc_html_e('Results', 'bmf'); ?>:</strong></p>
                    <ul>
                        <?php
                        if (!empty($results['long'])) {
                            $r = $results['long'];
                            if (!empty($r['ok'])) {
                                printf('<li>%s: <code>%s</code> — %s</li>',
                                    esc_html__('Long view', 'bmf'),
                                    esc_html($r['view']),
                                    esc_html__('OK', 'bmf')
                                );
                            } else {
                                printf('<li>%s: %s — <span style="color:#b32d2e">%s</span></li>',
                                    esc_html__('Long view', 'bmf'),
                                    esc_html__('Failed', 'bmf'),
                                    esc_html($r['error'])
                                );
                            }
                        }
                        if (!empty($results['pivots'])) {
                            foreach ($results['pivots'] as $slug => $r) {
                                if (!empty($r['ok'])) {
                                    printf('<li>%s <code>%s</code>: <code>%s</code> — %s</li>',
                                        esc_html__('Pivot for', 'bmf'),
                                        esc_html($slug),
                                        esc_html($r['view']),
                                        esc_html__('OK', 'bmf')
                                    );
                                } else {
                                    printf('<li>%s <code>%s</code>: %s — <span style="color:#b32d2e">%s</span></li>',
                                        esc_html__('Pivot for', 'bmf'),
                                        esc_html($slug),
                                        esc_html__('Failed', 'bmf'),
                                        esc_html($r['error'])
                                    );
                                }
                            }
                        }
                        ?>
                    </ul>
                </div>
            <?php endif; ?>

            <hr />

            <form method="post" style="margin-bottom:20px;">
                <?php wp_nonce_field('bmf_views_build', 'bmf_views_nonce'); ?>
                <input type="hidden" name="bmf_views_action" value="build_long" />
                <p><button type="submit" class="button button-primary">
                    <?php esc_html_e('Build / Refresh Long View', 'bmf'); ?>
                </button></p>
                <p class="description">
                    <?php esc_html_e('Creates or replaces the universal tidy view: {prefix}vw_bm_section_scores_long', 'bmf'); ?>
                </p>
            </form>

            <form method="post" style="margin-bottom:20px;">
                <?php wp_nonce_field('bmf_views_build', 'bmf_views_nonce'); ?>
                <input type="hidden" name="bmf_views_action" value="build_selected" />
                <h2><?php esc_html_e('Build / Refresh Pivot Views (Selected Forms)', 'bmf'); ?></h2>
                <?php if (empty($slugs)): ?>
                    <p><?php esc_html_e('No published forms found.', 'bmf'); ?></p>
                <?php else: ?>
                    <p><?php esc_html_e('Select one or more form slugs:', 'bmf'); ?></p>
                    <ul style="columns:2;-webkit-columns:2;-moz-columns:2;max-width:800px;">
                        <?php foreach ($slugs as $slug): ?>
                            <li>
                                <label>
                                    <input type="checkbox" name="bmf_form_slugs[]" value="<?php echo esc_attr($slug); ?>" />
                                    <code><?php echo esc_html($slug); ?></code>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p><button type="submit" class="button">
                        <?php esc_html_e('Build Selected Pivot Views', 'bmf'); ?>
                    </button></p>
                    <p class="description">
                        <?php esc_html_e('Each view will be named: {prefix}vw_bm_{form-slug}_pivot and will contain one column per section_id (s{ID}).', 'bmf'); ?>
                    </p>
                <?php endif; ?>
            </form>

            <form method="post">
                <?php wp_nonce_field('bmf_views_build', 'bmf_views_nonce'); ?>
                <input type="hidden" name="bmf_views_action" value="build_all" />
                <h2><?php esc_html_e('Build / Refresh All Views', 'bmf'); ?></h2>
                <p><button type="submit" class="button"><?php esc_html_e('Build Long View + All Pivot Views (Published Forms)', 'bmf'); ?></button></p>
            </form>

            <hr />
            <p class="description">
                <?php esc_html_e('Note: If your MySQL/MariaDB user lacks CREATE VIEW privileges, these actions may fail. In that case, we can switch to materialized tables with a scheduled rebuild.', 'bmf'); ?>
            </p>
        </div>
        <?php
    }
}
