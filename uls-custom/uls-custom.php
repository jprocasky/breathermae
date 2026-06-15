<?php
/**
 * Plugin Name: ULS Custom Functions (extracted from child theme)
 * Description: Migrates custom code from the child theme's functions.php (content placed after the "Write your code after this line" marker) into a standalone plugin.
 * Version: 1.0.0
 * Author: Jeff Procasky
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) { exit; }

// Helpful path constants (useful if you later add assets to this plugin)
define('ULS_CUSTOM_FILE', __FILE__);
define('ULS_CUSTOM_DIR', plugin_dir_path(__FILE__));
define('ULS_CUSTOM_URL', plugin_dir_url(__FILE__));

add_shortcode('logout_url', function () {
    return esc_url( wp_logout_url( home_url() ) );
});

// Example shortcode to display a product image linked to its page: [bm_product_image id="123" size="medium"]
function bm_product_image_link_shortcode($atts) {
    $atts = shortcode_atts([
        'id' => 0,
        'size' => 'thumbnail'
    ], $atts);

    $product_id = intval($atts['id']);
    if (!$product_id) return '';

    $product = wc_get_product($product_id);
    if (!$product) return '';

    $link = get_permalink($product_id);
    $image = $product->get_image($atts['size']);

    return '<a href="' . esc_url($link) . '">' . $image . '</a>';
}
add_shortcode('bm_product_image', 'bm_product_image_link_shortcode');


/* --------------------------------------------------------------------------
 * SHORTCODE: [dynamic_wpf_tag]
 * Returns a WP Fusion shortcode to update tags with the current user's ID.
 * -------------------------------------------------------------------------- */
function uls_dynamic_wpf_tag_shortcode() {
    $user_id = get_current_user_id();
    if ($user_id) {
        return '[wpf_update_tags tag="user_' . $user_id . '"]';
    }
    return '';
}
add_shortcode('dynamic_wpf_tag', 'uls_dynamic_wpf_tag_shortcode');

/* --------------------------------------------------------------------------
 * SHORTCODE: [list_users_with_ids]
 * Simple table of users: ID, username, email. For admin/debug usage.
 * -------------------------------------------------------------------------- */
function uls_list_wp_users_with_ids() {
    $users = get_users();
    $output = "<table><tr><th>User ID</th><th>Username</th><th>Email</th></tr>";
    foreach ($users as $user) {
        $output .= "<tr><td>{$user->ID}</td><td>{$user->user_login}</td><td>{$user->user_email}</td></tr>";
    }
    $output .= "</table>";
    return $output;
}
add_shortcode('list_users_with_ids', 'uls_list_wp_users_with_ids');

/* --------------------------------------------------------------------------
 * SHORTCODE: [user_survey_total]
 * Reads total score for the logged-in user's email from the table SurveyData.
 * NOTE: Table name is hard-coded. Consider using $wpdb->prefix for portability.
 * -------------------------------------------------------------------------- */
function uls_get_user_survey_total() {
    $current_user = wp_get_current_user();
    if (!$current_user->exists()) {
        return 'User not logged in.';
    }
    global $wpdb;
    $user_email = sanitize_email($current_user->user_email);
    $table_name = 'SurveyData';
    $total = $wpdb->get_var($wpdb->prepare("SELECT total FROM $table_name WHERE email = %s", $user_email));
    if ($total !== null) {
        return esc_html($total);
    }
    return 'N/A';
}
add_shortcode('user_survey_total', 'uls_get_user_survey_total');

/* --------------------------------------------------------------------------
 * WP Data Access (WPDA) integration: set SQL session variables for current user
 * This allows WPDA queries to reference @wpda_wp_user_login and @wpda_wp_user_email.
 * -------------------------------------------------------------------------- */
add_action('wpda_dbinit', function($wpdadb) {
    if (null !== $wpdadb) {
        $suppress_errors = $wpdadb->suppress_errors(true);
        $current_user    = wp_get_current_user();
        $wpdadb->query('SET @wpda_wp_user_login = "' . esc_sql($current_user->user_login) . '"');
        $wpdadb->query('SET @wpda_wp_user_email = "' . esc_sql($current_user->user_email) . '"');
        $wpdadb->suppress_errors($suppress_errors);
    }
}, 10, 1);



/* --------------------------------------------------------------------------
 * SHORTCODE: [custom_field field="col7" param="field"]
 *
 * Behavior:
 * - If 'field' attribute is provided, use it.
 * - Else, if 'param' attribute is provided (default 'field') and the query string has that key,
 *   use its value as the field (e.g., ?field=col7).
 * - Field name is sanitized and validated against a whitelist.
 * - Returns a styled span with the field value, or empty styled span if not found.
 *
 * Security:
 * - Whitelists allowable field names to prevent arbitrary column access.
 * - Escapes output with esc_html().
 * -------------------------------------------------------------------------- */
/* --------------------------------------------------------------------------
 * Helper: read a row from custom table uls_wptm_tbl_4 for the current user email.
 * Returns associative array of the row or a message when no data.
 * -------------------------------------------------------------------------- */
function uls_get_custom_table_fields() {
    global $wpdb;

    $current_user = wp_get_current_user();
    if (!$current_user || empty($current_user->user_email)) {
        return 'No user email available.';
    }

    $user_email = strtolower(trim($current_user->user_email));
    $table_name = 'uls_wptm_tbl_4';

    // Use prepare and LOWER() to match your original logic
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_name WHERE LOWER(col2) = %s LIMIT 1", $user_email),
        ARRAY_A
    );

    if (!$row) {
        return 'No data found for this user.';
    }

    return $row;
}

/* --------------------------------------------------------------------------
 * SHORTCODE: [custom_field field="col7" param="field"]
 *
 * Behavior:
 * - If 'field' attribute is provided, use it.
 * - Else, if 'param' attribute is provided (default 'field') and the query string has that key,
 *   use its value as the field (e.g., ?field=col7).
 * - Returns a styled span with the field value, or empty styled span if not found.
 * -------------------------------------------------------------------------- */
function uls_display_custom_field($atts) {
    $atts = shortcode_atts([
        'field'     => '',       // explicit field from shortcode
        'param'     => 'field',  // querystring key to look for if field is not provided
        'sanitize'  => 'basic',  // 'basic' (wp_kses), 'none' (raw), or 'strict' (esc_html)
        'runshortcodes' => 'no', // 'yes' to run inner shortcodes found in the field value
    ], $atts, 'custom_field');

    $field_attr = strtolower(trim($atts['field']));
    $param_key  = preg_replace('/[^a-z0-9_\-]/i', '', $atts['param']); // sanitize key name

    // Resolve field name (attribute wins; else from querystring)
    $field = $field_attr;
    if ($field === '' && $param_key !== '' && isset($_GET[$param_key])) {
        $candidate = strtolower(trim(wp_unslash($_GET[$param_key])));
        $candidate = preg_replace('/[^a-z0-9_\-]/', '', $candidate);
        $field = $candidate;
    }

    if ($field === '') {
        return '';
    }

    // Fetch data row for current user
    $data = uls_get_custom_table_fields();
    if (!is_array($data) || !array_key_exists($field, $data)) {
        return '';
    }

    $value = (string) $data[$field];

    // Optional: run nested shortcodes inside the field value if you store them
    if (strtolower($atts['runshortcodes']) === 'yes') {
        // Only run do_shortcode AFTER basic sanitization choice is decided below
        // We’ll run it right before returning.
        $run_inner = true;
    } else {
        $run_inner = false;
    }

    // Sanitization modes
    $mode = strtolower($atts['sanitize']);
    if ($mode === 'none') {
        // Render raw HTML (no stripping) — be sure the source is trusted!
        $out = $value;
    } elseif ($mode === 'strict') {
        // Escape everything (shows tags as text)
        $out = esc_html($value);
    } else {
        // 'basic' — allow a safe subset of tags/attributes
        $allowed = [
            'a'      => ['href' => true, 'title' => true, 'target' => true, 'rel' => true],
            'br'     => [],
            'em'     => [],
            'strong' => [],
            'b'      => [],
            'i'      => [],
            'u'      => [],
            'span'   => ['style' => true, 'class' => true],
            'p'      => ['style' => true, 'class' => true],
            'ul'     => ['class' => true],
            'ol'     => ['class' => true],
            'li'     => ['class' => true],
            'div'    => ['style' => true, 'class' => true],
            'img'    => ['src' => true, 'alt' => true, 'title' => true, 'width' => true, 'height' => true, 'class' => true, 'style' => true],
            'h1' => ['class' => true,'style' => true], 'h2' => ['class' => true,'style' => true],
            'h3' => ['class' => true,'style' => true], 'h4' => ['class' => true,'style' => true],
            'h5' => ['class' => true,'style' => true], 'h6' => ['class' => true,'style' => true],
        ];
        $out = wp_kses($value, $allowed);
    }

    if ($run_inner ?? false) {
        // If you allow nested shortcodes inside this field
        $out = do_shortcode($out);
    }

    // Return as-is (Elementor/WordPress will render the HTML)
    return $out;
}
add_shortcode('custom_field', 'uls_display_custom_field');

/* --------------------------------------------------------------------------
 * SHORTCODE: [wpf_tag_redirect]
 * Client-side redirect based on WP Fusion tags present.
 * Attributes: tags="tag1,tag2" match="any|all" redirect="https://..."
 * -------------------------------------------------------------------------- */
function uls_wpf_tag_redirect_dynamic($atts) {
    if (!is_user_logged_in()) { return ''; }
    $atts = shortcode_atts([
        'tags'     => '',
        'match'    => 'any',
        'redirect' => '',
    ], $atts);
    $user_id     = get_current_user_id();
    $tags        = array_map('trim', explode(',', strtolower($atts['tags'])));
    $match_type  = strtolower($atts['match']);
    $redirect_url= esc_url($atts['redirect']);
    if (empty($tags) || empty($redirect_url)) { return ''; }
    if (!function_exists('wpf_get_tags')) { return ''; }
    $user_tags   = array_map('strtolower', wpf_get_tags($user_id));
    $should_redirect = false;
    if ($match_type === 'all') {
        $should_redirect = !array_diff($tags, $user_tags);
    } else {
        $should_redirect = count(array_intersect($tags, $user_tags)) > 0;
    }
    if ($should_redirect) {
        return '<script>window.location.href = "' . $redirect_url . '";</script>';
    }
    return '';
}
add_shortcode('wpf_tag_redirect', 'uls_wpf_tag_redirect_dynamic');

/* --------------------------------------------------------------------------
 * SHORTCODE: [wpf_tag_redirect_not]
 * Redirects when user is missing tags.
 * Also avoids running inside wp-admin or Elementor editor.
 * -------------------------------------------------------------------------- */
function uls_wpf_tag_redirect_not($atts) {
    if (!is_user_logged_in()) { return ''; }
    if (is_admin() || (defined('ELEMENTOR_VERSION') && \Elementor\Plugin::$instance->editor->is_edit_mode())) {
        return '';
    }
    $atts = shortcode_atts([
        'tags'     => '',
        'match'    => 'any',
        'redirect' => '',
    ], $atts);
    $user_id     = get_current_user_id();
    $tags        = array_map('trim', explode(',', strtolower($atts['tags'])));
    $match_type  = strtolower($atts['match']);
    $redirect_url= esc_url($atts['redirect']);
    if (empty($tags) || empty($redirect_url)) { return ''; }
    if (!function_exists('wpf_get_tags')) { return ''; }
    $user_tags = array_map('strtolower', wpf_get_tags($user_id));
    $missing = false;
    if ($match_type === 'all') {
        $missing = array_diff($tags, $user_tags) ? true : false;
    } else {
        $missing = count(array_intersect($tags, $user_tags)) === 0;
    }
    if ($missing) {
        return '<script>window.location.href = "' . $redirect_url . '";</script>';
    }
    return '';
}
add_shortcode('wpf_tag_redirect_not', 'uls_wpf_tag_redirect_not');

/* --------------------------------------------------------------------------
 * SHORTCODE: [dynamic_redirect page="about-us" count="1" operator="lt|gt" redirect="/target"]
 * Redirects based on a recorded page-visit count stored in user meta.
 * -------------------------------------------------------------------------- */
function uls_dynamic_redirect_shortcode($atts) {
    if (isset($_GET['elementor-preview']) && $_GET['elementor-preview']) { return ''; }
    if (!is_user_logged_in()) { return ''; }
    $atts = shortcode_atts([
        'page'     => '',
        'count'    => 1,
        'operator' => 'lt',
        'redirect' => ''
    ], $atts);
    if (empty($atts['page']) || empty($atts['redirect'])) { return ''; }
    $user_id   = get_current_user_id();
    $meta_key  = 'page_visits_' . sanitize_title($atts['page']);
    $meta_val  = get_user_meta($user_id, $meta_key, true);
    $visit_cnt = 0;
    if (!empty($meta_val)) {
        $parts = explode("\n", $meta_val);
        $visit_cnt = intval($parts[0]);
    }
    $should = false;
    if ($atts['operator'] === 'lt' && $visit_cnt < intval($atts['count'])) { $should = true; }
    elseif ($atts['operator'] === 'gt' && $visit_cnt > intval($atts['count'])) { $should = true; }
    if ($should) {
        return '<script>window.location.href = "' . esc_url($atts['redirect']) . '";</script>';
    }
    return '';
}
add_shortcode('dynamic_redirect', 'uls_dynamic_redirect_shortcode');

/* --------------------------------------------------------------------------
 * SHORTCODE: [wpfusion_user_tags]
 * Renders a simple list of WP Fusion tags for the current user.
 * Registers only if WP Fusion functions are available.
 * -------------------------------------------------------------------------- */
function uls_register_wpfusion_user_tags_shortcode() {
    if (function_exists('wpf_get_tags') && function_exists('wpf_get_tag_name')) {
        add_shortcode('wpfusion_user_tags', 'uls_render_wpfusion_user_tags');
    }
}
add_action('init', 'uls_register_wpfusion_user_tags_shortcode');

function uls_render_wpfusion_user_tags() {
    if (!is_user_logged_in()) {
        return '<div class="wpfusion-tags">You must be logged in to see your tags.</div>';
    }
    $user_id = get_current_user_id();
    $tags    = wpf_get_tags($user_id);
    if (empty($tags)) {
        return '<div class="wpfusion-tags">No tags found for your account.</div>';
    }
    $out = '<div class="wpfusion-tags"><strong>Your Tags:</strong><ul>';
    foreach ($tags as $tag_id) {
        $tag_name = wpf_get_tag_name($tag_id);
        $out .= '<li>' . esc_html($tag_name) . '</li>';
    }
    $out .= '</ul></div>';
    return $out;
}

/* --------------------------------------------------------------------------
 * SHORTCODE: [dynamic_visibility]
 * Outputs CSS to hide/show a selector based on page visit counts.
 * -------------------------------------------------------------------------- */
function uls_dynamic_visibility_shortcode($atts) {
    if (isset($_GET['elementor-preview']) && $_GET['elementor-preview']) { return ''; }
    if (!is_user_logged_in()) { return ''; }
    $atts = shortcode_atts([
        'page'     => '',
        'count'    => 1,
        'operator' => 'lt',
        'selector' => '',
        'action'   => 'hide'
    ], $atts);
    if (empty($atts['page']) || empty($atts['selector'])) { return ''; }
    $user_id  = get_current_user_id();
    $meta_key = 'page_visits_' . sanitize_title($atts['page']);
    $meta_val = get_user_meta($user_id, $meta_key, true);
    $visit_cnt = 0;
    if (!empty($meta_val)) {
        $parts = explode("\n", $meta_val);
        $visit_cnt = intval($parts[0]);
    }
    $apply = false;
    if ($atts['operator'] === 'lt' && $visit_cnt < intval($atts['count'])) { $apply = true; }
    elseif ($atts['operator'] === 'gt' && $visit_cnt > intval($atts['count'])) { $apply = true; }
    if ($apply) {
        $css = ($atts['action'] === 'hide') ? ($atts['selector'] . '{display:none !important;}') : ($atts['selector'] . '{display:block !important;}');
        return '<style>' . esc_html($css) . '</style>';
    }
    return '';
}
add_shortcode('dynamic_visibility', 'uls_dynamic_visibility_shortcode');

/* --------------------------------------------------------------------------
 * SHORTCODE: [trigger_wpf_sync]
 * Forces a WP Fusion sync for the current user on page load.
 * -------------------------------------------------------------------------- */
function uls_wp_fusion_sync_on_page_load() {
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        if (function_exists('wpf_sync_user')) {
            wpf_sync_user($user_id);
        }
    }
    return '';
}
add_shortcode('trigger_wpf_sync', 'uls_wp_fusion_sync_on_page_load');

/* --------------------------------------------------------------------------
 * SHORTCODES: [pull_wpf_tags_debug] and [pull_wpf_tags]
 * Uses wpf_get_tags($user_id, true) to refresh tags from CRM.
 * -------------------------------------------------------------------------- */
function uls_wp_fusion_pull_tags_shortcode() {
    if (!is_user_logged_in()) {
        return '<p><strong>You must be logged in to pull tags.</strong></p>';
    }
    $user_id = get_current_user_id();
    if (!function_exists('wpf_get_tags')) {
        return '<p><strong>WP Fusion function <code>wpf_get_tags()</code> is not available.</strong></p>';
    }
    try {
        $pulled = wpf_get_tags($user_id, true);
        if (is_array($pulled)) {
            if (empty($pulled)) {
                return '<p><strong>Function ran, but no tags were returned.</strong></p>';
            }
            return '<p><strong>Pulled Tags:</strong> ' . implode(', ', $pulled) . '</p>';
        }
        return '<p><strong>Function ran, but did not return an array.</strong></p>';
    } catch (Exception $e) {
        return '<p><strong>Error pulling tags:</strong> ' . esc_html($e->getMessage()) . '</p>';
    }
}
add_shortcode('pull_wpf_tags_debug', 'uls_wp_fusion_pull_tags_shortcode');

function uls_wp_fusion_pull_tags_silent_shortcode() {
    if (is_user_logged_in() && function_exists('wpf_get_tags')) {
        $user_id = get_current_user_id();
        wpf_get_tags($user_id, true);
    }
    return '';
}
add_shortcode('pull_wpf_tags', 'uls_wp_fusion_pull_tags_silent_shortcode');

/* --------------------------------------------------------------------------
 * Zoho OAuth token helper + shortcodes to display Multi_Tags.
 * WARNING: client_id/secret/refresh_token are hard-coded. Prefer storing in options.
 * -------------------------------------------------------------------------- */
function uls_get_zoho_access_token() {
    $client_id     = '1000.56UNU6F2JT5T0NYCFLELI314ETVYNR';
    $client_secret = '0bfa6b5917e1996458773cd64d52dbc9fc7be51d09';
    $refresh_token = '1000.b2c243e471b7ce534a97d1a9b9b5db9e.a6fd4c42270fcb00ff99b19b0d496ed9';

    $access_token = get_option('zoho_access_token');
    $token_time   = get_option('zoho_token_time');
    if (!$access_token || !$token_time || (time() - $token_time > 3300)) {
        $url    = 'https://accounts.zoho.com/oauth/v2/token';
        $params = [
            'refresh_token' => $refresh_token,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'grant_type'    => 'refresh_token',
        ];
        $resp = wp_remote_post($url, ['body' => $params]);
        if (is_wp_error($resp)) { return false; }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $access_token = $body['access_token'] ?? false;
        if ($access_token) {
            update_option('zoho_access_token', $access_token);
            update_option('zoho_token_time', time());
        }
    }
    return $access_token;
}

function uls_display_multi_tags_from_zoho_array_safe() {
    $user  = wp_get_current_user();
    $email = $user->user_email;
    $access = uls_get_zoho_access_token();
    if (!$access) { return 'Unable to authenticate with Zoho.'; }
    $url = "https://www.zohoapis.com/crm/v2/Contacts/search?email=$email";
    $resp = wp_remote_get($url, [ 'headers' => [ 'Authorization' => "Zoho-oauthtoken $access" ] ]);
    if (is_wp_error($resp)) { return 'Error connecting to Zoho.'; }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!isset($body['data'][0])) { return 'Contact not found in Zoho.'; }
    $multi_tags = $body['data'][0]['Multi_Tags'] ?? [];
    if (is_array($multi_tags) && !empty($multi_tags)) {
        return 'Zoho Multi_Tags: <strong>' . implode(', ', $multi_tags) . '</strong>';
    }
    return 'Zoho Multi_Tags: <em>(empty or not set)</em>';
}
add_shortcode('show_multi_tags', 'uls_display_multi_tags_from_zoho_array_safe');

function uls_debug_multi_tags_from_zoho() {
    $user  = wp_get_current_user();
    $email = $user->user_email;
    $access = uls_get_zoho_access_token();
    if (!$access) { return 'Unable to authenticate with Zoho.'; }
    $url = "https://www.zohoapis.com/crm/v2/Contacts/search?email=$email";
    $resp = wp_remote_get($url, [ 'headers' => [ 'Authorization' => "Zoho-oauthtoken $access" ] ]);
    if (is_wp_error($resp)) { return 'Error connecting to Zoho.'; }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    echo '<pre>';
    print_r($body);
    echo '</pre>';
    return '';
}
add_shortcode('debug_multi_tags', 'uls_debug_multi_tags_from_zoho');

/* --------------------------------------------------------------------------
 * Headers: disable caching for logged-in users (helps dynamic content)
 * -------------------------------------------------------------------------- */
add_action('send_headers', function() {
    if (is_user_logged_in()) {
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
        header("X-Cache-Bypass: true");
    }
});

/* --------------------------------------------------------------------------
 * SHORTCODES per field from wpda_client_profile for current user.
 * Registers [user_Email], [user_FullName], etc. dynamically on init.
 * -------------------------------------------------------------------------- */
function uls_register_user_data_shortcodes() {
    if (!is_user_logged_in()) { return; }
    global $wpdb;
    $current_user = wp_get_current_user();
    $user_email   = $current_user->user_email;
    $table_name   = 'wpda_client_profile';
    $user_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE Email = %s", $user_email), ARRAY_A);
    if (!$user_data) { return; }
    foreach ($user_data as $field => $value) {
        add_shortcode("user_{$field}", function() use ($value) {
            return esc_html($value);
        });
    }
}
add_action('init', 'uls_register_user_data_shortcodes');

/* --------------------------------------------------------------------------
 * Elementor Pro forms hook: save Client Health Profile & Key Essentials.
 * - health_profile_form updates wpda_client_profile.
 * - key_* forms insert aggregate scores into uls_key_essentials.
 * -------------------------------------------------------------------------- */
add_action('elementor_pro/forms/new_record', function($record, $handler) {
    // Identify the form
    $form_name = $record->get_form_settings('form_name');

    global $wpdb;
    $current_user = wp_get_current_user();
    $user_email   = $current_user->user_email;

    // Raw fields array from Elementor
    $raw_fields = $record->get('fields');

    // Branch 1: Health Profile form
    if ($form_name === 'health_profile_form') {
        $table_name = 'wpda_client_profile';
        $data = [];
        foreach ($raw_fields as $id => $field) {
            $data[$id] = $field['value'];
        }
        $update_data = [
            'FullName'         => $data['name'] ?? '',
            'Gender'           => $data['gender'] ?? '',
            'Sex'              => $data['sex'] ?? '',
            'HeightFT'         => $data['heightft'] ?? '',
            'HeightIN'         => $data['heightin'] ?? '',
            'MaritalStatus'    => $data['maritalstatus'] ?? '',
            'FoodSensitivities'=> $data['foodsensitivities'] ?? '',
            'DietaryPattern'   => $data['dietarypattern'] ?? '',
            'DateOfBirth'      => $data['dateofbirth'] ?? ''
        ];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE Email = %s", $user_email));
        if (!$row) { return; }
        $wpdb->update($table_name, $update_data, ['Email' => $user_email]);
    }

    // Branch 2: Key Essentials aggregate scoring (7 forms)
    $key_essentials_forms = ['key_fluid_form','key_food_form','key_breath_form','key_movement_form','key_mind_form','key_sleep_form','key_nature_form'];
    if (in_array($form_name, $key_essentials_forms, true)) {
        $total = 0; $count = 0;
        foreach ($raw_fields as $field) {
            if (isset($field['value']) && is_numeric($field['value'])) {
                $total += (int) $field['value'];
                $count++;
            }
        }
        $average    = $count > 0 ? round($total / $count, 2) : 0;
        $unique_id  = uniqid('survey_', true);
        $table_name = 'uls_key_essentials';
        $wpdb->insert($table_name, [
            'unique_id'     => $unique_id,
            'form_id'       => $form_name,
            'datetime'      => current_time('mysql'),
            'user_email'    => $user_email,
            'total_score'   => $total,
            'average_score' => $average,
        ]);
    }
}, 10, 2);

/* --------------------------------------------------------------------------
 * SHORTCODE: [format_user_field column="ColumnName"]
 * Reads wpda_client_profile column for current user; formats JSON arrays as CSV.
 * -------------------------------------------------------------------------- */
add_shortcode('format_user_field', function($atts) {
    $atts = shortcode_atts(['column' => ''], $atts);
    if (!is_user_logged_in() || empty($atts['column'])) { return ''; }
    global $wpdb;
    $user_email = wp_get_current_user()->user_email;
    $column = sanitize_text_field($atts['column']);
    $row = $wpdb->get_row($wpdb->prepare("SELECT `$column` FROM wpda_client_profile WHERE Email = %s", $user_email));
    if (!$row) { return ''; }
    if (!isset($row->$column)) { return ''; }
    $raw = trim($row->$column);
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return implode(',', $decoded);
    }
    return esc_html($raw);
});

/* --------------------------------------------------------------------------
 * SHORTCODE: [referrer_url]
 * Displays HTTP referrer (best-effort).
 * -------------------------------------------------------------------------- */
function uls_get_referrer_shortcode() {
    $ref = isset($_SERVER['HTTP_REFERER']) ? esc_url($_SERVER['HTTP_REFERER']) : 'No referrer';
    return $ref;
}
add_shortcode('referrer_url', 'uls_get_referrer_shortcode');

/* --------------------------------------------------------------------------
 * SHORTCODE: [custom_rss url="..." items="5"]
 * Renders a basic list from an RSS/Atom feed URL.
 * -------------------------------------------------------------------------- */
function uls_display_custom_rss_feed($atts) {
    $atts = shortcode_atts([
        'url'   => 'http://feeds.bbci.co.uk/news/health/rss.xml',
        'items' => 5,
    ], $atts, 'custom_rss');
    include_once ABSPATH . WPINC . '/feed.php';
    $rss = fetch_feed($atts['url']);
    if (!is_wp_error($rss)) {
        $max = $rss->get_item_quantity($atts['items']);
        $items = $rss->get_items(0, $max);
        $out = '<ul>';
        foreach ($items as $item) {
            $out .= '<li><a href="' . esc_url($item->get_permalink()) . '" target="_blank">' . esc_html($item->get_title()) . '</a></li>';
        }
        $out .= '</ul>';
        return $out;
    }
    return '<p>Unable to fetch RSS feed.</p>';
}
add_shortcode('custom_rss', 'uls_display_custom_rss_feed');

/* --------------------------------------------------------------------------
 * SHORTCODE: [cart_count]
 * Returns current WooCommerce cart item count.
 * -------------------------------------------------------------------------- */
function uls_cart_item_count_shortcode() {
    if (function_exists('WC') && WC()->cart) {
        return WC()->cart->get_cart_contents_count();
    }
    return 0;
}
add_shortcode('cart_count', 'uls_cart_item_count_shortcode');

/* --------------------------------------------------------------------------
 * Page visit logger (template_redirect): stores visit count + last timestamp.
 * Skips logging when impersonation cookies indicate admin is impersonating.
 * -------------------------------------------------------------------------- */
add_action('template_redirect', function() {
    if (!is_user_logged_in() || !is_page()) { return; }

    // Impersonation guard (expects cookies set by your impersonation tool)
    $is_impersonating = (
        isset($_COOKIE['uls_impersonating'], $_COOKIE['uls_orig_uid']) &&
        $_COOKIE['uls_impersonating'] === '1' &&
        ctype_digit((string) $_COOKIE['uls_orig_uid']) &&
        (int) $_COOKIE['uls_orig_uid'] > 0
    );
    if ($is_impersonating) {
        $current_id = get_current_user_id();
        $orig_id    = (int) $_COOKIE['uls_orig_uid'];
        if ($current_id !== $orig_id) { return; }
    }

    $user_id   = get_current_user_id();
    $page_slug = sanitize_title(get_the_title());
    $meta_key  = 'page_visits_' . $page_slug;

    $existing = get_user_meta($user_id, $meta_key, true);
    $count = 0;
    if (!empty($existing)) {
        $parts = explode("|", $existing);
        $count = isset($parts[0]) ? (int) $parts[0] : 0;
    }
    $count++;
    $last_visit = current_time('mysql');
    $new_value  = $count . "|" . $last_visit;
    update_user_meta($user_id, $meta_key, $new_value);
});

/* --------------------------------------------------------------------------
 * CRON: recreate a dynamic SQL VIEW aggregating page visit meta per email.
 * WARNING: uses hard-coded tables uls_usermeta and uls_users.
 * -------------------------------------------------------------------------- */
if (!wp_next_scheduled('recreate_dynamic_view_event')) {
    wp_schedule_event(time(), 'hourly', 'recreate_dynamic_view_event');
}
add_action('recreate_dynamic_view_event', 'uls_check_and_recreate_dynamic_view');

function uls_check_and_recreate_dynamic_view() {
    global $wpdb;
    $view_name = 'user_page_visits_view';
    $meta_keys = $wpdb->get_col("SELECT DISTINCT meta_key FROM uls_usermeta WHERE meta_key LIKE 'page_visits_%'");
    if (empty($meta_keys)) { return; }
    $meta_keys = array_filter($meta_keys, function($key) { return !preg_match('/^page_visits_\d+$/', $key); });
    if (empty($meta_keys)) { return; }
    $select_parts = [];
    foreach ($meta_keys as $key) {
        $col_name = str_replace('page_visits_', '', $key);
        $col_name = preg_replace('/[^a-zA-Z0-9_]/', '_', $col_name);
        $col_name = 'fld_' . strtolower($col_name);
        $select_parts[] = "MAX(CASE WHEN um.meta_key = '$key' THEN um.meta_value END) AS `$col_name`";
    }
    $sql_body = "SELECT u.user_email, " . implode(', ', $select_parts) . " FROM uls_usermeta um JOIN uls_users u ON um.user_id = u.ID WHERE um.meta_key LIKE 'page_visits_%' GROUP BY u.user_email";

    $new_hash = md5($sql_body);
    $old_hash = get_option('page_visits_view_hash');
    if ($new_hash !== $old_hash) {
        $wpdb->query("CREATE OR REPLACE VIEW {$view_name} AS {$sql_body}");
        update_option('page_visits_view_hash', $new_hash);
    }
}

// Manual trigger for testing via URL parameter: ?run_view_test=1
add_action('init', function() {
    if (isset($_GET['run_view_test'])) {
        uls_check_and_recreate_dynamic_view();
        echo "View recreation attempted.";
        exit;
    }
});

/* --------------------------------------------------------------------------
 * REST API endpoint: POST /wp-json/custom-email/v1/send
 * Sends HTML emails. Secured by static API key + IP allow-list.
 * NOTE: Consider moving keys/IP to options and adding nonce or auth.
 * -------------------------------------------------------------------------- */
add_action('rest_api_init', function () {
    register_rest_route('custom-email/v1', '/send', [
        'methods'             => 'POST',
        'callback'            => 'uls_send_custom_email_endpoint',
        'permission_callback' => '__return_true',
    ]);
});

function uls_send_custom_email_endpoint(WP_REST_Request $request) {
    $api_key   = $request->get_header('x-api-key');
    $valid_key = 'b9f3a7d2-4c8e-4f91-9e6a-2c1d8f7e3a4b';
    $allowed_ip= '185.164.120.177';
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($client_ip !== $allowed_ip) {
        return new WP_REST_Response(['error' => 'Access denied: IP not allowed'], 403);
    }
    if ($api_key !== $valid_key) {
        return new WP_REST_Response(['error' => 'Unauthorized: Invalid API key'], 401);
    }
    $to      = sanitize_email($request->get_param('to'));
    $subject = sanitize_text_field($request->get_param('subject'));
    $body    = wp_kses_post($request->get_param('body'));
    if (empty($to) || empty($subject) || empty($body)) {
        return new WP_REST_Response(['error' => 'Missing required fields'], 400);
    }
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $sent = wp_mail($to, $subject, $body, $headers);
    if ($sent) { return new WP_REST_Response(['success' => true, 'message' => 'Email sent'], 200); }
    return new WP_REST_Response(['error' => 'Failed to send email'], 500);
}

/* --------------------------------------------------------------------------
 * SHORTCODE: [conditional_content id="123"]
 * Pulls content rules from wp_custom_content and applies WP Fusion tag logic.
 * -------------------------------------------------------------------------- */
add_shortcode('conditional_content', function($atts) {
    global $wpdb;
    $atts = shortcode_atts(['id' => ''], $atts);
    if (empty($atts['id'])) { return ''; }
    $table = 'wp_custom_content';
    $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE ID = %d", $atts['id']));
    if (!$row) { return ''; }
    $output = $row->default_value;
    if (function_exists('wp_fusion') && is_user_logged_in()) {
        $user_id   = get_current_user_id();
        $user_tags = wp_fusion()->user->get_tags($user_id);
        $required  = array_map('trim', explode(',', $row->tags));
        $match     = strtoupper($row->match_type);
        $has_tags  = false;
        if ($match === 'ALL') { $has_tags = !array_diff($required, $user_tags); }
        else { $has_tags = (bool) array_intersect($required, $user_tags); }
        if ($has_tags) { $output = $row->tag_value; }
    }
    return do_shortcode($output);
});

/* --------------------------------------------------------------------------
 * SHORTCODE: [custom_field_color field="col7" low="40" high="80" scale="1"]
 * Colors numeric values from uls_wptm_tbl_4.
 * -------------------------------------------------------------------------- */
function uls_display_custom_field_color($atts) {
    $atts = shortcode_atts([
        'field' => '',
        'low'   => 40,
        'high'  => 80,
        'scale' => 1,
    ], $atts);
    $field = strtolower($atts['field']);
    $data  = uls_get_custom_table_fields();
    if (!$data || !isset($data[$field])) {
        return '<span style="color:#808080;font-weight:bold;">N/A</span>';
    }
    $raw   = trim($data[$field]);
    $value = is_numeric($raw) ? (intval(floatval($raw)) / floatval($atts['scale'])) : null;
    if ($value === null) { $color = '#808080'; $display = 'N/A'; }
    elseif ($value >= intval($atts['high'])) { $color = '#0070c0'; $display = $value; }
    elseif ($value < intval($atts['low']))    { $color = '#ff0000'; $display = $value; }
    else                                      { $color = '#3b7e23'; $display = $value; }
    return '<span style="color:' . esc_attr($color) . ';font-weight:bold;">' . esc_html($display) . '</span>';
}
add_shortcode('custom_field_color', 'uls_display_custom_field_color');

/**
 * SHORTCODE: [custom_field_icon field="col7" low="40" high="80" scale="1"]
 * Renders a colored SVG icon with centered numeric value
 */
function uls_custom_field_icon( $atts ) {

    $atts = shortcode_atts([
        'field'         => '',
        'low'           => 40,
        'high'          => 80,
        'scale'         => 1,

        'shape'         => 'circle',   // circle | ring | square | diamond
        'size'          => 28,
        'stroke_width'  => 2,          // ring only
        'outline_width' => 0,
        'outline_color' => '#000000',

        'value_color'   => '#ffffff',
        'value_size'    => 11,
        'value_weight'  => 600,
        'value_offset_y'=> 0,
    ], $atts, 'custom_field_icon');

    $field = strtolower(trim($atts['field']));
    $data  = uls_get_custom_table_fields();

    if ( ! $field || ! $data || ! isset($data[$field]) ) {
        return '';
    }

    $raw = trim($data[$field]);

    // No score → show N/A
    if ( $raw === '' || ! is_numeric($raw) ) {
        return '<span style="color:#808080;font-weight:800;">N/A</span>';
    }

    $value = floatval($raw) / max(1, floatval($atts['scale']));
    $label = number_format(round($value), 0);

    /* ------------------------------------------------------------
     * Color logic (matches custom_field_color)
     * ------------------------------------------------------------ */
    if ( $value >= $atts['high'] ) {
        $color = '#0070c0';
    } elseif ( $value < $atts['low'] ) {
        $color = '#ff0000';
    } else {
        $color = '#3b7e23';
    }

    $size   = max(14, intval($atts['size']));
    $half   = $size / 2;
    $shape  = strtolower($atts['shape']);
    $stroke = max(1, intval($atts['stroke_width']));
    $ow     = max(0, intval($atts['outline_width']));
    $pad    = ($shape === 'ring') ? $stroke : $ow;

    /* ------------------------------------------------------------
     * SVG shape
     * ------------------------------------------------------------ */
    $shape_html = '';

    switch ($shape) {
        case 'ring':
            $r = max(1, $half - $stroke - 1);
            $shape_html = sprintf(
                '<circle cx="%1$d" cy="%1$d" r="%2$d" fill="none" stroke="%3$s" stroke-width="%4$d"/>',
                $half, $r, esc_attr($color), $stroke
            );
            break;

        case 'square':
            $w = $size - 2 * $pad;
            $shape_html = sprintf(
                '<rect x="%1$d" y="%1$d" width="%2$d" height="%2$d" rx="4" fill="%3$s" stroke="%4$s" stroke-width="%5$d"/>',
                $pad, $w, esc_attr($color), esc_attr($atts['outline_color']), $ow
            );
            break;

        case 'diamond':
            $i = max($pad, round($size * 0.12));
            $shape_html = sprintf(
                '<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" stroke="%10$s" stroke-width="%11$d"/>',
                $half,$i, $size-$i,$half, $half,$size-$i, $i,$half,
                esc_attr($color), esc_attr($atts['outline_color']), $ow
            );
            break;

        case 'circle':
        default:
            $r = max(1, $half - $pad);
            $shape_html = sprintf(
                '<circle cx="%1$d" cy="%1$d" r="%2$d" fill="%3$s" stroke="%4$s" stroke-width="%5$d"/>',
                $half, $r, esc_attr($color), esc_attr($atts['outline_color']), $ow
            );
    }

    /* ------------------------------------------------------------
     * Centered value
     * ------------------------------------------------------------ */
    $text = sprintf(
        '<text x="%d" y="%d" text-anchor="middle" dominant-baseline="middle"
            font-size="%d" font-weight="%s" fill="%s">%s</text>',
        $half,
        $half + intval($atts['value_offset_y']),
        max(8, intval($atts['value_size'])),
        esc_attr($atts['value_weight']),
        esc_attr($atts['value_color']),
        esc_html($label)
    );

    return sprintf(
        '<svg width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d" xmlns="http://www.w3.org/2000/svg">%2$s%3$s</svg>',
        $size,
        $shape_html,
        $text
    );
}

add_shortcode('custom_field_icon', 'uls_custom_field_icon');

/* --------------------------------------------------------------------------
 * Idle redirect script for front end (excludes certain paths/admins).
 * -------------------------------------------------------------------------- */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) { return; }
    // --- CONFIGURE ---
    $redirect_strategy  = 'fixed_url'; // 'site_home' | 'previous_or_home' | 'fixed_url'
    $fixed_redirect_url = home_url('/session-expired');
    $inactivity_seconds = 1800; // 30 minutes
    $exclude_paths      = [ '/user-monitor', '/session-expired' ];
    $exclude_admins     = true;
    // ---------------
    if ($exclude_admins && is_user_logged_in() && current_user_can('manage_options')) { return; }
    $current_path = parse_url(add_query_arg([]), PHP_URL_PATH);
    $normalize = function($p){ return rtrim($p ?? '', '/'); };
    $current_norm = $normalize($current_path);
    $ex_norm = array_map($normalize, $exclude_paths);
    if ($current_norm !== '' && in_array($current_norm, $ex_norm, true)) { return; }
    $site_home = home_url('/');
    $strategy  = in_array($redirect_strategy, ['site_home','previous_or_home','fixed_url'], true) ? $redirect_strategy : 'site_home';
    $payload   = [
        'strategy'         => $strategy,
        'fixedUrl'         => esc_url_raw($fixed_redirect_url),
        'siteHome'         => esc_url_raw($site_home),
        'inactivitySeconds'=> (int) $inactivity_seconds,
        'countInBackground'=> true,
    ];
    wp_register_script('bm-idle-redirect', false, [], null, true);
    wp_localize_script('bm-idle-redirect', 'BM_IDLE_REDIRECT', $payload);
    wp_add_inline_script('bm-idle-redirect', "(function(){try{var cfg=window.BM_IDLE_REDIRECT||{};var THRESHOLD_MS=(cfg.inactivitySeconds||1800)*1000;var STRATEGY=cfg.strategy||'site_home';var FIXED_URL=cfg.fixedUrl||'/';var SITE_HOME=cfg.siteHome||'/';function resolveRedirectUrl(){if(STRATEGY==='fixed_url')return FIXED_URL; if(STRATEGY==='previous_or_home'){var ref=document.referrer||'';var here=window.location.href; if(!ref||ref===here) return SITE_HOME; return ref;} return SITE_HOME;} var events=['mousemove','mousedown','keydown','scroll','touchstart','touchmove','wheel','pointermove']; var lastActive=Date.now(); function mark(){lastActive=Date.now();} events.forEach(function(e){window.addEventListener(e,mark,{passive:true});}); var HEARTBEAT_MS=10000; var id=null; function check(){var now=Date.now(); var elapsed=now-lastActive; if(elapsed>=THRESHOLD_MS){cleanup(); try{window.location.href=resolveRedirectUrl();}catch(e){}}} function start(){stop(); id=setInterval(check,HEARTBEAT_MS);} function stop(){if(id){clearInterval(id); id=null;}} function cleanup(){stop(); events.forEach(function(e){window.removeEventListener(e,mark,{passive:true});});} mark(); start(); window.addEventListener('beforeunload',cleanup); window.addEventListener('pagehide',cleanup);}catch(err){if(console&&console.warn)console.warn('Idle redirect script error:',err);}})();");
    wp_enqueue_script('bm-idle-redirect');
});

/* ========================================================================== */
/* ADMIN TOOLS: Elementor Pages & Templates Index                              */
/* Description:                                                               */
/* - Adds two admin tools under Tools:                                        */
/*   • Tools → Elementor Pages                                                 */
/*   • Tools → Elementor Templates                                             */
/* - Provides sortable, searchable lists with direct Elementor edit links.    */
/* - Supports reusable named editor window targeting.                          */
/* ========================================================================== */

/* ========================================================================== */
/* ADMIN TOOLS: Elementor Pages & Templates Index (ADMIN SAFE)                 */
/* ========================================================================== */

if ( is_admin() ) {

    add_filter('wp_targeted_link_rel', function ($rel, $link, $target) {
        // Allow named window reuse for Elementor editor links
        if ($target === 'elementor_editor') {
            return 'opener';
        }
        return $rel;
    }, 10, 3);

    add_action('admin_menu', function () {
        add_management_page(
            'Elementor Pages',
            'Elementor Pages',
            'manage_options',
            'uls-elementor-pages',
            'uls_render_elementor_pages'
        );

        add_management_page(
            'Elementor Templates',
            'Elementor Templates',
            'manage_options',
            'uls-elementor-templates',
            'uls_render_elementor_templates'
        );
    });

add_action('admin_init', function () {

    if (!current_user_can('manage_options')) {
        return;
    }

    // Only update preference when the checkbox form submits
    if (isset($_GET['uls_window_pref'])) {

        $reuse = isset($_GET['reuse_window']) ? 1 : 0;

        update_user_meta(
            get_current_user_id(),
            'uls_reuse_elementor_window',
            $reuse
        );
    }
});

    add_action('admin_init', function () {

        if ( ! class_exists('WP_List_Table') ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        if ( class_exists('WP_List_Table') && ! class_exists('ULS_Elementor_List_Table') ) {

            class ULS_Elementor_List_Table extends WP_List_Table {

                private $post_type;
                private $is_template;

                public function __construct($post_type, $is_template = false) {
                    $this->post_type   = $post_type;
                    $this->is_template = $is_template;
                    parent::__construct([
                        'singular' => 'elementor_item',
                        'plural'   => 'elementor_items',
                        'ajax'     => false,
                    ]);
                }

                public function get_columns() {
                    $cols = [
                        'title'      => 'Title',
                        'author'     => 'Author',
                        'modified'   => 'Last Updated',
                        'updated_by' => 'Updated By',
                    ];

                    if ($this->is_template) {
                        $cols['template_type'] = 'Template Type';
                    } else {
                        $cols['view'] = 'View';
                    }

                    $cols['edit'] = 'Edit in Elementor';
                    return $cols;
                }

                public function get_sortable_columns() {
                    return [
                        'title'    => ['title', true],
                        'author'   => ['author', false],
                        'modified' => ['modified', false],
                    ];
                }

                public function prepare_items() {

                    $columns  = $this->get_columns();
                    $hidden   = [];
                    $sortable = $this->get_sortable_columns();

                    $this->_column_headers = [$columns, $hidden, $sortable];

                    $args = [
                        'post_type'      => $this->post_type,
                        'posts_per_page' => 25,
                        'paged'          => $this->get_pagenum(),
                        's'              => $_REQUEST['s'] ?? '',
                        'orderby'        => $_REQUEST['orderby'] ?? 'title',
                        'order'          => $_REQUEST['order'] ?? 'asc',
                    ];

                    if (!$this->is_template) {
                        $args['meta_key']   = '_elementor_edit_mode';
                        $args['meta_value'] = 'builder';
                    }

                    $query = new WP_Query($args);

                    $this->items = $query->posts;

                    $this->set_pagination_args([
                        'total_items' => $query->found_posts,
                        'per_page'    => 25,
                    ]);
                }


                protected function column_default($item, $column_name) {

                    $target = isset($_GET['reuse_window']) ? 'elementor_editor' : '_blank';

                    switch ($column_name) {

                        case 'title':
                            return esc_html($item->post_title);

                        case 'author':
                            return esc_html(get_the_author_meta('display_name', $item->post_author));

                        case 'modified':
                            return esc_html(get_the_modified_date('Y-m-d H:i', $item));

                        case 'updated_by':
                            $uid = get_post_meta($item->ID, '_edit_last', true);
                            return $uid ? esc_html(get_the_author_meta('display_name', $uid)) : '—';

                        case 'template_type':
                            return esc_html(
                                ucfirst(get_post_meta($item->ID, '_elementor_template_type', true) ?: '—')
                            );

                        case 'view':
                            $url = esc_url(get_permalink($item->ID));
                            $reuse = get_user_meta(
                                get_current_user_id(),
                                'uls_reuse_elementor_window',
                                true
                            );

                            if ($reuse) {

                                return '<a href="' . $url . '" onclick="window.open(this.href, \'elementor_editor\'); return false;">View</a>';
                            }
                            return '<a href="' . $url . '" target="_blank">View</a>';

                        case 'edit':
                            $url = esc_url(admin_url('post.php?post=' . $item->ID . '&action=elementor'));
                            $reuse = get_user_meta(
                                get_current_user_id(),
                                'uls_reuse_elementor_window',
                                true
                            );

                            if ($reuse) {

                                return '<a href="' . $url . '" onclick="window.open(this.href, \'elementor_editor\'); return false;">Edit</a>';
                            }
                            return '<a href="' . $url . '" target="_blank">Edit</a>';
                    }

                    return '';
                }
            }
        }
    });

    function uls_render_elementor_pages() {
        uls_render_elementor_list('page', false, 'Elementor Pages');
    }

    function uls_render_elementor_templates() {
        uls_render_elementor_list('elementor_library', true, 'Elementor Templates');
    }

    function uls_render_elementor_list($post_type, $is_template, $title) {

        if (!current_user_can('manage_options')) {
            return;
        }

        $table = new ULS_Elementor_List_Table($post_type, $is_template);
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($title) . '</h1>';

        echo '<form method="get">';

        echo '<input type="hidden" name="page" value="' . esc_attr($_GET['page']) . '" />';
        echo '<input type="hidden" name="uls_window_pref" value="1" />';

        echo '<p>
            <label>
                <input type="checkbox"
                    name="reuse_window"
                    value="1"
                    onchange="this.form.submit();" ' .
                checked(
                    get_user_meta(
                        get_current_user_id(),
                        'uls_reuse_elementor_window',
                        true
                    ),
                    1,
                    false
                ) . '>
                Reuse same Editor-View window
            </label>
        </p>';
        $table->search_box('Search', 'uls-elementor-search');
        $table->display();

        echo '</form></div>';
    }
}
/* --------------------------------------------------------------------------
 * Event Logger Table Creation on Plugin Activation
 * - Creates a custom table 'uls_user_event_log' with indexes for efficient querying.
 * -------------------------------------------------------------------------- */

register_activation_hook( __FILE__, function () {

    global $wpdb;

    $table_name = 'uls_user_event_log';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_email VARCHAR(190) NOT NULL,
        event_key VARCHAR(190) NOT NULL,
        event_value TEXT NULL,
        last_updated DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY user_event_key (user_email, event_key),
        KEY event_key (event_key),
        KEY user_email (user_email)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

});

add_action( 'wp_ajax_uls_record_event', 'uls_record_event' );

// AJAX handler to record user events in the custom table. Expects POST with 'event_key' and 'event_value'.

function uls_record_event() {


    check_ajax_referer( 'erp_nonce', 'nonce' ); // reuse your existing nonce

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error();
    }

    $event_key   = sanitize_text_field( $_POST['event_key'] ?? '' );
    $event_value = sanitize_text_field( $_POST['event_value'] ?? '' );

    if ( ! $event_key ) {
        wp_send_json_error();
    }

    $user  = wp_get_current_user();
    $email = $user->user_email;

    global $wpdb;
    $table = 'uls_user_event_log';

    // Try update first
    $updated = $wpdb->update(
        $table,
        [
            'event_value'  => $event_value,
            'last_updated' => current_time( 'mysql' )
        ],
        [
            'user_email' => $email,
            'event_key'  => $event_key
        ],
        [ '%s', '%s' ],
        [ '%s', '%s' ]
    );

    // If no row updated, insert
    if ( $updated === 0 ) {
        $wpdb->insert(
            $table,
            [
                'user_email'  => $email,
                'event_key'   => $event_key,
                'event_value' => $event_value,
                'last_updated'=> current_time( 'mysql' )
            ],
            [ '%s', '%s', '%s', '%s' ]
        );
    }

    wp_send_json_success([
        'status' => $updated === 0 ? 'inserted' : 'updated'
    ]);
}

/* --------------------------------------------------------------------------
 * Activation/Deactivation: ensure cron is scheduled and unscheduled properly.
 * -------------------------------------------------------------------------- */
register_activation_hook(ULS_CUSTOM_FILE, function() {
    if (!wp_next_scheduled('recreate_dynamic_view_event')) {
        wp_schedule_event(time(), 'hourly', 'recreate_dynamic_view_event');
    }
});
register_deactivation_hook(ULS_CUSTOM_FILE, function() {
    $timestamp = wp_next_scheduled('recreate_dynamic_view_event');
    if ($timestamp) { wp_unschedule_event($timestamp, 'recreate_dynamic_view_event'); }
});

/* --------------------------------------------------------------------------
 * Stops woocommerce customer data from syncing to CRM if 'email_optin' field is not truthy.
 * -------------------------------------------------------------------------- */
function do_not_sync_unconfirmed_customers( $customer_data ) {
    if ( empty( $customer_data['email_optin'] ) ) {
        return false; // Cancels sync to CRM for this checkout
    }
    return $customer_data;
}
add_filter( 'wpf_woocommerce_customer_data', 'do_not_sync_unconfirmed_customers' );

/**
 * Breathermae Lazy Popup (Elementor on-demand render)
 * - Shortcode: [bm_lazy_popup id="1234" label="Open" params="field,slug" field="col7" slug="my-slug"]
 *   - id: Elementor template ID to render (required)
 *   - label: Button label (default: "Open")
 *   - class: Extra CSS class on the button (optional)
 *   - params: Comma/space separated list of param names to pass (e.g., "field, slug, foo")
 *   - For each name listed in `params`, provide an attribute with the same name to set its value.
 *
 * The shortcode renders a button with data attributes. On click, JS calls admin-ajax.php
 * (action=bm_load_popup) with nonce + template_id + your params. The server renders the template
 * via Elementor and returns just the HTML, which is then injected into a modal shell.
 */

/**
 * Breathermae Lazy Popup (Elementor on-demand render)
 * Shortcode: [bm_lazy_popup id="1234" label="Open" params="field,slug" field="col7" slug="my-slug"]
 */
if (!defined('ABSPATH')) { exit; }

class BM_Lazy_Popup {
    private static $modal_printed = false;
    private static $shortcode_used = false;

    public static function init() {
        add_shortcode('bm_lazy_popup', [__CLASS__, 'shortcode']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);

        // AJAX endpoints (public + logged in)
        add_action('wp_ajax_nopriv_bm_load_popup', [__CLASS__, 'ajax']);
        add_action('wp_ajax_bm_load_popup', [__CLASS__, 'ajax']);

        // Print the modal once per page
        add_action('wp_footer', [__CLASS__, 'print_modal'], 50);
    }

    public static function enqueue() {
        // Virtual handle for inline script
        wp_register_script('bm-lazy-popup', false, [], null, true);
        wp_enqueue_script('bm-lazy-popup');

        $nonce = wp_create_nonce('bm_popup_nonce');
        $ajax  = admin_url('admin-ajax.php');

        // --- Fix B is inside this inline JS (ensureModal()) ---
        $inline = <<<JS
window.BMLazyPopup = { ajaxUrl: "{$ajax}", nonce: "{$nonce}" };

(function(){
  if (window.__bmLazyBound) return;
  window.__bmLazyBound = true;

  // ===== FIX B: ensure the modal shell exists (fallback for missing wp_footer or edge cases) =====
  function ensureModal() {
    if (!document.getElementById('bm-modal')) {
      const style = document.createElement('style');
      style.textContent =
        '#bm-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);align-items:center;justify-content:center;z-index:999999;}' +
        '#bm-modal-content{background:#fff;max-width:720px;width:90%;max-height:85vh;overflow:auto;border-radius:12px;padding:20px;position:relative;box-shadow:0 10px 30px rgba(0,0,0,.2);}' +
        '#bm-close{position:absolute;top:10px;right:10px;background:transparent;border:0;font-size:20px;cursor:pointer;line-height:1;}';
      document.head.appendChild(style);

      const wrapper = document.createElement('div');
      wrapper.id = 'bm-modal';
      wrapper.setAttribute('role','dialog');
      wrapper.setAttribute('aria-modal','true');
      wrapper.setAttribute('aria-label','Popup');
      wrapper.innerHTML =
        '<div id="bm-modal-content">' +
          '<button id="bm-close" aria-label="Close">✕</button>' +
          '<div id="bm-modal-body"></div>' +
        '</div>';
      document.body.appendChild(wrapper);
    }
  }
  // ==============================================================================================

  const modal = () => document.getElementById('bm-modal');
  const body  = () => document.getElementById('bm-modal-body');

  const cache = {}; // cache by (template_id + params)

  function openModal() {
    const m = modal(); if (!m) return;
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    const m = modal(); if (!m) return;
    m.style.display = 'none';
    document.body.style.overflow = '';
  }

  function cacheKey(id, params) {
    const keys = Object.keys(params).sort();
    return id + '|' + keys.map(k => k + '=' + params[k]).join('&');
  }

  function reexecuteScripts(container) {
    const scripts = container.querySelectorAll('script');
    scripts.forEach(s => {
      const ns = document.createElement('script');
      for (let i = 0; i < s.attributes.length; i++) {
        const a = s.attributes[i];
        ns.setAttribute(a.name, a.value);
      }
      if (!s.src) ns.textContent = s.textContent;
      document.head.appendChild(ns);
      setTimeout(() => { try { document.head.removeChild(ns); } catch(e){} }, 0);
    });
  }

  // Global click handler for [data-bm-lazy-popup] triggers
  document.addEventListener('click', async (e) => {
    const el = e.target.closest('[data-bm-lazy-popup]');
    if (!el) return;

    e.preventDefault();

    // Ensure modal exists before we try to inject
    ensureModal();

    const templateId = el.getAttribute('data-template-id');
    if (!templateId) return;

    // Collect params from data-param-*
    const params = {};
    Array.from(el.attributes).forEach(attr => {
      if (attr.name.startsWith('data-param-')) {
        const key = attr.name.replace('data-param-', '').trim();
        if (key) params[key] = attr.value;
      }
    });

    const key = cacheKey(templateId, params);
    if (cache[key]) {
      const container = body();
      if (!container) { console.warn('bm-modal-body missing'); return; }
      container.innerHTML = cache[key];
      openModal();
      return;
    }

    const qs = new URLSearchParams({
      action: 'bm_load_popup',
      template_id: templateId,
      nonce: (window.BMLazyPopup && window.BMLazyPopup.nonce) || ''
    });

    for (const k in params) {
      if (Object.prototype.hasOwnProperty.call(params, k)) {
        qs.append(k, params[k]);
      }
    }

    try {
      const res  = await fetch(((window.BMLazyPopup && window.BMLazyPopup.ajaxUrl) || '') + '?' + qs.toString(), {
        credentials: 'same-origin'
      });
      const json = await res.json();
      if (!json || !json.success) throw new Error((json && json.data && json.data.message) || 'Failed to load popup');

      const container = body();
      if (!container) { console.warn('bm-modal-body missing'); return; } // defensive

      container.innerHTML = json.data.html;
      cache[key] = json.data.html;

      reexecuteScripts(container);
      openModal();
    } catch (err) {
      console.error(err);
      alert('Sorry, the popup failed to load.');
    }
  });

  // Close behaviors
  document.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'bm-close') { e.preventDefault(); closeModal(); }
    const m = modal();
    if (m && e.target === m) closeModal();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
})();
JS;

        wp_add_inline_script('bm-lazy-popup', $inline);
    }

    public static function shortcode($atts) {
        self::$shortcode_used = true;

        $atts = shortcode_atts([
            'id'     => '',      // Elementor template ID (required)
            'label'  => 'Open',  // button label
            'class'  => '',      // extra CSS classes
            'params' => '',      // comma/space separated param names to pass
        ], $atts, 'bm_lazy_popup');

        $template_id = intval($atts['id']);
        if (!$template_id) {
            return '<!-- bm_lazy_popup: missing id -->';
        }

        $label = esc_html($atts['label']);
        $class = sanitize_html_class($atts['class']);

        // Parse param keys and sanitize
        $param_keys = preg_split('/[\s,]+/', (string)$atts['params'], -1, PREG_SPLIT_NO_EMPTY);
        $param_keys = array_map(function($k){
            return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $k));
        }, $param_keys);
        $param_keys = array_filter(array_unique($param_keys));

        // Build data attributes
        $data_attrs = [
            'data-bm-lazy-popup' => '1',
            'data-template-id'   => (string) $template_id,
        ];

        foreach ($param_keys as $key) {
            if (isset($atts[$key]) && $atts[$key] !== '') {
                $val = is_scalar($atts[$key]) ? (string)$atts[$key] : '';
                $val = sanitize_text_field($val);
                $data_attrs["data-param-{$key}"] = $val;
            }
        }

        // Render trigger button
        $attr_html = '';
        foreach ($data_attrs as $k => $v) {
            $attr_html .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
        }
        $class_attr = 'bm-lazy-popup-trigger' . ($class ? ' ' . $class : '');

        return '<button type="button" class="' . esc_attr($class_attr) . '"' . $attr_html . '>' . $label . '</button>';
    }

    public static function print_modal() {
        // ===== Fix A: print the shell unconditionally (once) =====
        if (self::$modal_printed) return;
        self::$modal_printed = true;
        ?>
<style>
#bm-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);align-items:center;justify-content:center;z-index:999999;}
#bm-modal-content{background:#fff;max-width:720px;width:90%;max-height:85vh;overflow:auto;border-radius:12px;padding:20px;position:relative;box-shadow:0 10px 30px rgba(0,0,0,.2);}
#bm-close{position:absolute;top:10px;right:10px;background:transparent;border:0;font-size:20px;cursor:pointer;line-height:1;}
</style>
<div id="bm-modal" role="dialog" aria-modal="true" aria-label="Popup">
  <div id="bm-modal-content">
    <button id="bm-close" aria-label="Close">✕</button>
    <div id="bm-modal-body"></div>
  </div>
</div>
        <?php
    }

    public static function ajax() {
        // Security: nonce
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'bm_popup_nonce')) {
            wp_send_json_error(['message' => 'Invalid request (nonce).'], 400);
        }

        $template_id = isset($_GET['template_id']) ? intval($_GET['template_id']) : 0;
        if (!$template_id) {
            wp_send_json_error(['message' => 'Missing template_id.'], 400);
        }

        if (!did_action('elementor/loaded')) {
            wp_send_json_error(['message' => 'Elementor not available.'], 500);
        }

        // Collect all query params except reserved ones; sanitize text
        $reserved = ['action','nonce','template_id'];
        $extra_get = [];
        foreach ($_GET as $k => $v) {
            if (in_array($k, $reserved, true)) continue;
            $key = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string)$k));
            if ($key === '') continue;

            if (is_array($v)) {
                $extra_get[$key] = array_map(function($item){
                    return sanitize_text_field(is_scalar($item) ? (string)$item : '');
                }, $v);
            } else {
                $extra_get[$key] = sanitize_text_field((string)wp_unslash($v));
            }
        }

        // Temporarily augment $_GET so shortcodes can read params
        $old_get = $_GET;
        $_GET = array_merge($_GET, $extra_get);

        // Render Elementor template (dynamic content enabled)
        $content = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($template_id, true);

        // Restore original $_GET
        $_GET = $old_get;

        wp_send_json_success(['html' => $content]);
    }
}
add_action('init', ['BM_Lazy_Popup', 'init']);


?>
