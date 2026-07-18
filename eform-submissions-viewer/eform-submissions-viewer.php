<?php
/**
 * Plugin Name: Elementor Form Submissions Viewer
 * Description: Display Elementor form submissions with AJAX detail view.
 * Version: 1.8
 * Author: Jeff Procasky
 */

if (!defined('ABSPATH')) exit;

class EForm_Submissions_Viewer {




    public function create_tables() {
        global $wpdb;

        $table = $wpdb->prefix . 'eform_field_map';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_name VARCHAR(255),
            field_key VARCHAR(255),
            field_label TEXT,
            UNIQUE KEY unique_field (form_name, field_key)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function __construct() {
        add_shortcode('e_form_submissions', [$this, 'render_shortcode']);
        add_shortcode('e_form_details', [$this, 'eform_submission_details_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_get_submission_details', [$this, 'get_submission_details']);
        add_action('wp_ajax_nopriv_get_submission_details', [$this, 'get_submission_details']);
        add_action('wp_ajax_eform_run_sync', [$this, 'ajax_run_sync']);
        add_action('wp_ajax_nopriv_eform_run_sync', [$this, 'ajax_run_sync']);
        add_action('wp_ajax_eform_get_values', [$this, 'ajax_get_filter_values']);
        add_action('wp_ajax_nopriv_eform_get_values', [$this, 'ajax_get_filter_values']);
        add_action('wp_ajax_eform_filter_submissions', [$this, 'ajax_filter_submissions']);
        add_action('wp_ajax_nopriv_eform_filter_submissions', [$this, 'ajax_filter_submissions']);    
        
        add_shortcode('e_form_filter_panel', function() {
            return '<div id="eform-filter-panel" class="eform-filter-panel"></div>';
        });        
        
        add_shortcode('eform_sync', function($atts) {

            $atts = shortcode_atts([
                'form_name' => ''
            ], $atts);

            if (empty($atts['form_name'])) {
                return '<p>Missing form_name</p>';
            }

            ob_start();
            ?>

            <div class="eform-sync-wrapper">
                <button 
                    class="eform-sync-btn" 
                    data-form="<?php echo esc_attr($atts['form_name']); ?>"
                >
                    Reload Field Maps - (<?php echo esc_html($atts['form_name']); ?>)
                </button>

                <div class="eform-sync-status"></div>
            </div>

            <?php
            return ob_get_clean();
        });     
    }

    public function enqueue_assets() {
        wp_enqueue_script(
            'eform-js',
            plugin_dir_url(__FILE__) . 'assets/script.js',
            [],
            '1.0',
            true
        );

        wp_localize_script('eform-js', 'eform_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('eform_nonce')
        ]);

        wp_enqueue_style(
            'eform-css',
            plugin_dir_url(__FILE__) . 'assets/style.css'
        );
    }

    public function eform_submission_details_shortcode() {
        return '<div id="eform-details" class="eform-details">
                    <p>Select a row to view details.</p>
                </div>';
    }

    public function ajax_filter_submissions() {
        check_ajax_referer('eform_nonce', 'nonce');

        global $wpdb;

        $form_name = sanitize_text_field($_POST['form_name']);
        $filters = json_decode(stripslashes($_POST['filters']), true);
        $page = intval($_POST['page'] ?? 1);
        $rows = intval($_POST['rows'] ?? 10);

        $offset = ($page - 1) * $rows;        

        if (empty($form_name)) {
            wp_send_json_error('Missing form name');
        }

        // ✅ Build WHERE for submission IDs (NOT join)
        $where_ids = "WHERE form_name = '" . esc_sql($form_name) . "'";

        if (!empty($filters)) {
            foreach ($filters as $key => $value) {

                if (!$value) continue;

                $key = esc_sql($key);
                $value = esc_sql($value);

                $where_ids .= " AND id IN (
                    SELECT submission_id
                    FROM uls_e_submissions_values
                    WHERE `key` = '{$key}'
                    AND `value` LIKE '%{$value}%'
                )";
            }
        }

        // ✅ STEP 1: Get correct submission IDs WITH pagination
        $submission_ids = $wpdb->get_col("
            SELECT id
            FROM uls_e_submissions
            $where_ids
            ORDER BY created_at DESC
            LIMIT {$rows} OFFSET {$offset}
        ");


        if (empty($submission_ids)) {
            wp_send_json_success([]);
        }

        // ✅ STEP 2: Fetch ALL fields for those submissions
        $id_list = implode(',', array_map('intval', $submission_ids));

        $results = $wpdb->get_results("
            SELECT 
                s.id,
                s.created_at,
                v.key,
                v.value
            FROM uls_e_submissions s
            LEFT JOIN uls_e_submissions_values v 
                ON s.id = v.submission_id
            WHERE s.id IN ($id_list)
            ORDER BY FIELD(s.id, $id_list)
        ");

        // ✅ STEP 3: Rebuild structured dataset
        $data = [];

        // Step 2A: build grouped data
        foreach ($results as $row) {

            if (!isset($data[$row->id])) {
                $data[$row->id] = [];
            }

            if (!isset($data[$row->id]['created_at'])) {
                $data[$row->id]['created_at'] = $row->created_at;
            }

            if (!empty($row->key)) {
                $data[$row->id][$row->key] = $row->value;
            }
        }

        // Step 2B: REORDER data to match $submission_ids
        $ordered_data = [];

        foreach ($submission_ids as $id) {
            if (isset($data[$id])) {
                $ordered_data[$id] = $data[$id];
            }
        }

        $final_data = [];

        foreach ($submission_ids as $id) {
            if (isset($data[$id])) {
                $row = $data[$id];
                $row['id'] = $id; // add id so JS still has it
                $final_data[] = $row;
            }
        }

        wp_send_json_success($final_data);
    }


    public function ajax_get_filter_values() {
        check_ajax_referer('eform_nonce', 'nonce');

        global $wpdb;

        $key = sanitize_text_field($_POST['key']);

        $values = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT value
            FROM uls_e_submissions_values
            WHERE `key` = %s
            ORDER BY value ASC
        ", $key));

        wp_send_json_success($values);
    }    
    
    public function ajax_run_sync() {
        check_ajax_referer('eform_nonce', 'nonce');

        $form_name = sanitize_text_field($_POST['form_name']);

        if (empty($form_name)) {
            wp_send_json_error('Missing form name');
        }

        $this->eform_sync_field_map($form_name);

        wp_send_json_success("Field map synced for: {$form_name}");
    }


    public function render_shortcode($atts) {
        global $wpdb;

        $atts = shortcode_atts([
            'form_name' => '',
            'rows' => 10,
            'page' => 1,
            'fields' => ''
        ], $atts);

        if (empty($atts['form_name'])) {
            return '<p>Form name is required.</p>';
        }

        $fields = array_map('trim', explode(',', $atts['fields']));
        $rows = intval($atts['rows']);
        $page = max(1, intval($atts['page']));
        $offset = ($page - 1) * $rows;
        $form_name = esc_sql($atts['form_name']);

        // Step 1: Get paginated submission IDs
        $submission_ids = $wpdb->get_col($wpdb->prepare("
            SELECT id
            FROM uls_e_submissions
            WHERE form_name = %s
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d
        ", $atts['form_name'], $rows, $offset));

        if (empty($submission_ids)) {
            return '<p>No submissions found.</p>';
        }

        // Step 2: Get all values for those submissions
        $id_list = implode(',', array_map('intval', $submission_ids));

        $results = $wpdb->get_results("
            SELECT 
                s.id,
                s.created_at,
                v.key,
                v.value
            FROM uls_e_submissions s
            LEFT JOIN uls_e_submissions_values v 
                ON s.id = v.submission_id
            WHERE s.id IN ($id_list)
            ORDER BY s.created_at DESC
        ");

        // Group results
        $data = [];
        foreach ($results as $row) {
            $data[$row->id]['created_at'] = $row->created_at;
            $data[$row->id][$row->key] = $row->value;
        }

        ob_start();
        ?>

        <div class="eform-wrapper"
            data-form="<?php echo esc_attr($atts['form_name']); ?>"
            data-rows="<?php echo esc_attr($rows); ?>">

            <div class="eform-table-scroll">
                <table class="eform-table">
                    <thead>
                        <tr>
                            <?php foreach ($fields as $f): ?>
                                <th data-key="<?php echo esc_attr($f); ?>">
                                    <?php echo esc_html($f); ?>
                                </th>   
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $id => $row): ?>
                            <tr class="eform-row" data-id="<?php echo esc_attr($id); ?>">
                                <?php foreach ($fields as $f): ?>
                                    <td><?php echo esc_html($row[$f] ?? ''); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div><br>
            <div class="eform-pagination">
                <button type="button" class="eform-prev">&laquo; Prev</button>
                <span class="eform-page"><?php echo $page; ?></span>
                <button type="button" class="eform-next">Next &raquo;</button>
            </div>

        </div>

        <?php
        return ob_get_clean();
    }



    public function get_submission_details() {
        check_ajax_referer('eform_nonce', 'nonce');

        global $wpdb;

        $id = intval($_POST['submission_id']);

        if (!$id) {
            wp_send_json_error('Invalid ID');
        }

        $map_table = $wpdb->prefix . 'eform_field_map';

        // Get form name first
        $form_name = $wpdb->get_var($wpdb->prepare("
            SELECT form_name 
            FROM uls_e_submissions 
            WHERE id = %d
        ", $id));

        // Get label map for this form
        $labels = $wpdb->get_results($wpdb->prepare("
            SELECT field_key, field_label 
            FROM $map_table 
            WHERE form_name = %s
        ", $form_name), OBJECT_K);

        // Get submission values
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT `key`, `value`
            FROM uls_e_submissions_values
            WHERE submission_id = %d
        ", $id));

        // Attach labels
        $filtered_results = [];

        foreach ($results as $row) {

            if (isset($labels[$row->key])) {
                $label = $labels[$row->key]->field_label;

                // ✅ Skip if label is same as key OR looks auto-generated
                if ($label === $row->key || preg_match('/^field_/i', $row->key)) {
                    continue;
                }

                $row->label = $label;
                $filtered_results[] = $row;
            }
        }

        $results = $filtered_results;

        if (!$results) {
            wp_send_json_error('No data found');
        }

        wp_send_json_success($results);
    }

    public function eform_sync_field_map($form_name) {
        global $wpdb;

        $map_table = $wpdb->prefix . 'eform_field_map';

        // Step 1: Get all keys used in submissions
        $keys = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT v.key
            FROM uls_e_submissions_values v
            JOIN uls_e_submissions s ON s.id = v.submission_id
            WHERE s.form_name = %s
        ", $form_name));

        if (empty($keys)) return;

        // Step 2: Find Elementor forms
        $posts = get_posts([
            'post_type' => ['page', 'elementor_library'],
            'numberposts' => -1
        ]);

        $field_map = [];

        foreach ($posts as $post) {
            $data = get_post_meta($post->ID, '_elementor_data', true);

            if (!$data) continue;

            $json = json_decode($data, true);

            $this->eform_extract_form_fields($json, $field_map);
        }

        // Step 3: Save matches
        foreach ($keys as $key) {

            $label = $field_map[$key] ?? $key;

            $wpdb->replace($map_table, [
                'form_name' => $form_name,
                'field_key' => $key,
                'field_label' => $label
            ]);
        }
    }

    public function eform_extract_form_fields($elements, &$map) {

        if (!is_array($elements)) return;

        foreach ($elements as $el) {

            // Check if this is a form widget
            if (isset($el['widgetType']) && $el['widgetType'] === 'form') {

                if (!empty($el['settings']['form_fields'])) {

                    foreach ($el['settings']['form_fields'] as $field) {
                        if (!empty($field['custom_id'])) {
                            $map[$field['custom_id']] = $field['field_label'] ?? $field['custom_id'];
                        }
                    }
                }
            }

            if (!empty($el['elements'])) {
                $this->eform_extract_form_fields($el['elements'], $map);
            }
        }
    }    


}

new EForm_Submissions_Viewer();

register_activation_hook(__FILE__, function() {
    $plugin = new EForm_Submissions_Viewer();
    $plugin->create_tables();
});