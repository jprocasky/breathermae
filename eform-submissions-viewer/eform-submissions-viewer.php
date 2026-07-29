<?php
/**
 * Plugin Name: Elementor Form Submissions Viewer
 * Description: Display Elementor form submissions with AJAX detail view and reply-by-email.
 * Version: 1.9.2
 * Author: Jeff Procasky
 */

if (!defined('ABSPATH')) exit;

class EForm_Submissions_Viewer {

    public function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Field map table
        $table = $wpdb->prefix . 'eform_field_map';
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_name VARCHAR(255),
            field_key VARCHAR(255),
            field_label TEXT,
            UNIQUE KEY unique_field (form_name, field_key)
        ) $charset_collate;";
        dbDelta($sql);

        // Email log table
        $table_log = $wpdb->prefix . 'eform_email_log';
        $sql_log = "CREATE TABLE IF NOT EXISTS $table_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id BIGINT UNSIGNED NOT NULL,
            form_name VARCHAR(255) DEFAULT '',
            recipient VARCHAR(255) NOT NULL,
            subject VARCHAR(500) NOT NULL,
            message_body LONGTEXT,
            cta_text VARCHAR(255) DEFAULT '',
            cta_url VARCHAR(500) DEFAULT '',
            sent_by BIGINT UNSIGNED DEFAULT 0,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY submission_id (submission_id),
            KEY sent_at (sent_at)
        ) $charset_collate;";
        dbDelta($sql_log);
    }

    public function __construct() {
        add_shortcode('e_form_submissions', [$this, 'render_shortcode']);
        add_shortcode('e_form_details', [$this, 'eform_submission_details_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // Existing AJAX
        add_action('wp_ajax_get_submission_details', [$this, 'get_submission_details']);
        add_action('wp_ajax_nopriv_get_submission_details', [$this, 'get_submission_details']);
        add_action('wp_ajax_eform_run_sync', [$this, 'ajax_run_sync']);
        add_action('wp_ajax_nopriv_eform_run_sync', [$this, 'ajax_run_sync']);
        add_action('wp_ajax_eform_get_values', [$this, 'ajax_get_filter_values']);
        add_action('wp_ajax_nopriv_eform_get_values', [$this, 'ajax_get_filter_values']);
        add_action('wp_ajax_eform_filter_submissions', [$this, 'ajax_filter_submissions']);
        add_action('wp_ajax_nopriv_eform_filter_submissions', [$this, 'ajax_filter_submissions']);

        // New: send reply email (logged-in only)
        add_action('wp_ajax_eform_send_reply', [$this, 'ajax_send_reply']);

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
            '1.9.2',
            true
        );

        // Quick-start bodies for careers / general use
        $quick_starts = [
            [
                'label' => '— Select a starter (optional) —',
                'body'  => ''
            ],
            [
                'label' => 'Careers: Application received',
                'body'  => "Thank you for applying to join the Breathermae team.\n\nWe have received your application and will review it carefully. If your background aligns with what we are looking for, we will be in touch regarding next steps.\n\nWe appreciate your interest in Breathermae."
            ],
            [
                'label' => 'Careers: Interview invitation',
                'body'  => "Thank you for your application to Breathermae.\n\nWe were impressed with your background and would like to invite you to a conversation to learn more about you and share more about the role and our team.\n\nPlease reply to this email with a few times that work for you over the next week, or use the button below if a scheduling link is provided."
            ],
            [
                'label' => 'Careers: Not moving forward',
                'body'  => "Thank you for taking the time to apply to Breathermae and for your interest in joining our team.\n\nAfter careful review of all applications, we have decided to move forward with other candidates whose experience more closely matches our current needs.\n\nWe truly appreciate the effort you put into your application and wish you every success in your search."
            ],
            [
                'label' => 'General: Thanks + we will follow up',
                'body'  => "Thank you for reaching out.\n\nWe have received your message and will review it shortly. Someone from the team will get back to you if a reply is needed.\n\nWe appreciate you taking the time to connect with us."
            ],
        ];

        wp_localize_script('eform-js', 'eform_ajax', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('eform_nonce'),
            'quick_starts' => $quick_starts,
        ]);

        wp_enqueue_style(
            'eform-css',
            plugin_dir_url(__FILE__) . 'assets/style.css',
            [],
            '1.9.2'
        );
    }

    public function eform_submission_details_shortcode($atts = []) {
        $atts = shortcode_atts([
            'reply_to' => '',
        ], $atts);

        $reply_to = sanitize_email($atts['reply_to']);

        return '<div id="eform-details" class="eform-details" data-reply-to="' . esc_attr($reply_to) . '">
                    <p>Select a row to view details.</p>
                </div>
                <!-- Reply email modal (hidden by default) -->
                <div id="eform-reply-modal" class="eform-modal" style="display:none;" aria-hidden="true">
                    <div class="eform-modal-overlay"></div>
                    <div class="eform-modal-content">
                        <div class="eform-modal-header">
                            <h3>Send Email Reply</h3>
                            <button type="button" class="eform-modal-close" aria-label="Close">&times;</button>
                        </div>
                        <div class="eform-modal-body">
                            <input type="hidden" id="eform-reply-submission-id" value="">
                            <input type="hidden" id="eform-reply-form-name" value="">
                            <input type="hidden" id="eform-reply-to-email" value="' . esc_attr($reply_to) . '">
                            <p class="eform-reply-to">To: <strong id="eform-reply-recipient"></strong></p>

                            <label for="eform-reply-subject">Subject</label>
                            <input type="text" id="eform-reply-subject" class="eform-input" placeholder="Subject line">

                            <label for="eform-reply-quickstart">Quick start (optional)</label>
                            <select id="eform-reply-quickstart" class="eform-input"></select>

                            <label for="eform-reply-body">Message</label>
                            <textarea id="eform-reply-body" class="eform-input" rows="10" placeholder="Type your message here..."></textarea>

                            <div class="eform-cta-toggle">
                                <label>
                                    <input type="checkbox" id="eform-include-cta"> Include Call-to-Action button
                                </label>
                            </div>
                            <div id="eform-cta-fields" style="display:none;">
                                <label for="eform-cta-text">Button text</label>
                                <input type="text" id="eform-cta-text" class="eform-input" value="Breathermae" placeholder="Button text">
                                <label for="eform-cta-url">Button URL</label>
                                <input type="url" id="eform-cta-url" class="eform-input" value="https://breathermae.com" placeholder="https://...">
                            </div>
                        </div>
                        <div class="eform-modal-footer">
                            <button type="button" class="eform-btn eform-btn-secondary eform-modal-cancel">Cancel</button>
                            <button type="button" class="eform-btn eform-btn-primary" id="eform-send-reply-btn">Send Email</button>
                        </div>
                        <div id="eform-reply-status" class="eform-reply-status"></div>
                    </div>
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

        $where_ids = "WHERE (form_name = '" . esc_sql($form_name) . "' OR element_id = '" . esc_sql($form_name) . "') AND status <> 'trash'";

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

        $data = [];
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

        $final_data = [];
        foreach ($submission_ids as $id) {
            if (isset($data[$id])) {
                $row = $data[$id];
                $row['id'] = $id;
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
            'rows'      => 10,
            'page'      => 1,
            'fields'    => '',
            'reply_to'  => '',
        ], $atts);

        if (empty($atts['form_name'])) {
            return '<p>Form name is required.</p>';
        }

        $reply_to = sanitize_email($atts['reply_to']);

        $fields = array_map('trim', explode(',', $atts['fields']));
        $rows = intval($atts['rows']);
        $page = max(1, intval($atts['page']));
        $offset = ($page - 1) * $rows;

        $submission_ids = $wpdb->get_col($wpdb->prepare("
            SELECT id
            FROM uls_e_submissions
            WHERE (form_name = %s OR element_id = %s)
            AND status <> 'trash'
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d
        ", $atts['form_name'], $atts['form_name'], $rows, $offset));

        if (empty($submission_ids)) {
            return '<p>No submissions found.</p>';
        }

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

        $data = [];
        foreach ($results as $row) {
            $data[$row->id]['created_at'] = $row->created_at;
            $data[$row->id][$row->key] = $row->value;
        }

        ob_start();
        ?>
        <div class="eform-wrapper"
            data-form="<?php echo esc_attr($atts['form_name']); ?>"
            data-rows="<?php echo esc_attr($rows); ?>"
            data-reply-to="<?php echo esc_attr($reply_to); ?>">

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

        $submission = $wpdb->get_row($wpdb->prepare("
            SELECT form_name, element_id
            FROM uls_e_submissions
            WHERE id = %d
        ", $id));

        if (!$submission) {
            wp_send_json_error('Submission not found');
        }

        $labels = $wpdb->get_results($wpdb->prepare("
            SELECT field_key, field_label
            FROM $map_table
            WHERE form_name = %s OR form_name = %s
        ", $submission->form_name, $submission->element_id), OBJECT_K);

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT `key`, `value`
            FROM uls_e_submissions_values
            WHERE submission_id = %d
        ", $id));

        if (empty($results)) {
            wp_send_json_error('No data found');
        }

        $filtered_results = [];

        foreach ($results as $row) {
            if (preg_match('/^field_/i', $row->key)) {
                continue;
            }

            if (isset($labels[$row->key])) {
                $label = $labels[$row->key]->field_label;
                $row->label = ($label && $label !== $row->key) ? $label : $row->key;
            } else {
                $row->label = $row->key;
            }

            $filtered_results[] = $row;
        }

        if (empty($filtered_results)) {
            wp_send_json_error('No data found');
        }

        // Also return form context so JS can set defaults
        wp_send_json_success([
            'fields'     => $filtered_results,
            'form_name'  => $submission->form_name ?: $submission->element_id,
            'submission_id' => $id,
        ]);
    }

    /**
     * AJAX: Send a reply email from the submissions viewer
     */
    public function ajax_send_reply() {
        check_ajax_referer('eform_nonce', 'nonce');

        // Access is controlled by WP Fusion on the page itself — no extra capability check here.

        $submission_id = intval($_POST['submission_id'] ?? 0);
        $recipient     = sanitize_email($_POST['recipient'] ?? '');
        $subject       = sanitize_text_field($_POST['subject'] ?? '');
        $body          = wp_kses_post(wp_unslash($_POST['body'] ?? '')); // allow basic formatting if pasted
        $cta_text      = sanitize_text_field($_POST['cta_text'] ?? '');
        $cta_url       = esc_url_raw($_POST['cta_url'] ?? '');
        $form_name     = sanitize_text_field($_POST['form_name'] ?? '');
        $include_cta   = !empty($_POST['include_cta']);
        $reply_to      = sanitize_email($_POST['reply_to'] ?? '');

        if (!$recipient || !is_email($recipient)) {
            wp_send_json_error('Invalid recipient email address.');
        }
        if (empty($subject)) {
            wp_send_json_error('Subject is required.');
        }
        if (empty(trim(strip_tags($body)))) {
            wp_send_json_error('Message body is required.');
        }
        if ($include_cta && (empty($cta_text) || empty($cta_url))) {
            wp_send_json_error('CTA button requires both text and a valid URL.');
        }

        // Build HTML email
        $html = $this->build_email_html($body, $include_cta ? $cta_text : '', $include_cta ? $cta_url : '');

        // From stays on a reliable noreply address for deliverability.
        // Reply-To controls where the recipient's reply goes (set via shortcode).
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: Breathermae <noreply@breathermae.com>',
        ];
        if ($reply_to && is_email($reply_to)) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        $sent = wp_mail($recipient, $subject, $html, $headers);

        if (!$sent) {
            wp_send_json_error('Failed to send email. Please check the site mail configuration.');
        }

        // Log it
        global $wpdb;
        $table_log = $wpdb->prefix . 'eform_email_log';
        $wpdb->insert($table_log, [
            'submission_id' => $submission_id,
            'form_name'     => $form_name,
            'recipient'     => $recipient,
            'subject'       => $subject,
            'message_body'  => $body,
            'cta_text'      => $include_cta ? $cta_text : '',
            'cta_url'       => $include_cta ? $cta_url : '',
            'sent_by'       => get_current_user_id(),
            'sent_at'       => current_time('mysql'),
        ]);

        wp_send_json_success([
            'message' => 'Email sent successfully to ' . $recipient,
            'log_id'  => $wpdb->insert_id,
        ]);
    }

    /**
     * Build the branded HTML email (matches existing Breathermae style)
     */
    private function build_email_html($body, $cta_text = '', $cta_url = '') {
        // Allow a small set of safe tags if staff pastes basic HTML; otherwise treat as plain text
        $allowed = [
            'br'     => [],
            'p'      => [],
            'strong' => [],
            'em'     => [],
            'b'      => [],
            'i'      => [],
            'a'      => ['href' => [], 'target' => [], 'rel' => []],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
        ];
        $clean = wp_kses($body, $allowed);
        // Convert remaining newlines to <br>
        $body_html = nl2br($clean);

        $cta_block = '';
        if ($cta_text && $cta_url) {
            $cta_block = '
            <div style="text-align: center; margin: 28px 0 10px;">
                <a href="' . esc_url($cta_url) . '"
                   style="display: inline-block; background-color: #40c6ff; color: #ffffff;
                          padding: 12px 24px; text-decoration: none; border-radius: 5px;
                          font-family: Arial, sans-serif; font-size: 15px; font-weight: bold;
                          border: 2px solid #001d50; box-shadow: 0 6px 12px rgba(0,0,0,0.35);">
                    ' . esc_html($cta_text) . '
                </a>
            </div>';
        }

        $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Message from Breathermae</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f1f1; font-family:Arial, sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
<tr>
<td align="center" valign="top" style="padding: 20px 10px;">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px; width:100%; background:#ffffff; border-radius:8px; overflow:hidden;">
    <!-- Logo -->
    <tr>
        <td align="center" style="padding: 24px 20px 10px;">
            <img src="https://breathermae.com/wp-content/uploads/2025/11/breathermae-6_1_black-on-white-background-scaled-e1763257543312.jpg"
                 width="150" height="auto" alt="Breathermae"
                 style="display:block; width:150px; height:auto; border:0;">
        </td>
    </tr>
    <!-- Tagline -->
    <tr>
        <td align="center" style="padding: 0 20px 10px;">
            <p style="margin:0; font-size:13px; color:#555; font-style:italic;">
                Your Journey Toward Greater Awareness, Insight, and Well-Being
            </p>
        </td>
    </tr>
    <!-- Body -->
    <tr>
        <td style="padding: 20px 28px 10px; color:#222; font-size:15px; line-height:1.55;">
            ' . $body_html . '
            ' . $cta_block . '
        </td>
    </tr>
    <!-- Footer -->
    <tr>
        <td align="center" style="padding: 30px 20px; border-top:1px solid #eee;">
            <p style="margin:0; font-size:13px; color:#555; line-height:1.5;">
                Breathermae, Inc.<br>
                <a href="https://breathermae.com" style="color:#001D50; text-decoration:none;">www.breathermae.com</a><br>
                © ' . date('Y') . ' Breathermae, Inc. All Rights Reserved.
            </p>
        </td>
    </tr>
</table>
</td>
</tr>
</table>
</body>
</html>';

        return $html;
    }

    public function eform_sync_field_map($form_name) {
        global $wpdb;

        $map_table = $wpdb->prefix . 'eform_field_map';

        $keys = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT v.key
            FROM uls_e_submissions_values v
            JOIN uls_e_submissions s ON s.id = v.submission_id
            WHERE s.form_name = %s
        ", $form_name));

        if (empty($keys)) return;

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
