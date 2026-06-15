<?php
/*
Plugin Name: WP Attribution Tracker Pro
Description: Tracks campaign attribution, applies WP Fusion tags, credits reps, and logs events to database.
Version: 2.0
Author: You
*/

if (!defined('ABSPATH')) exit;

/**
 * ✅ CREATE DATABASE TABLE
 */
register_activation_hook(__FILE__, 'wpat_create_table');

function wpat_create_table() {
    global $wpdb;

    $table = $wpdb->prefix . 'attribution_log';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        rep_id BIGINT UNSIGNED DEFAULT 0,
        utm_source VARCHAR(100),
        utm_medium VARCHAR(100),
        utm_content VARCHAR(255),
        wpf_tag VARCHAR(50),
        campaign_key VARCHAR(64),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY rep_id (rep_id),
        KEY campaign_key (campaign_key)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

    add_shortcode('wpat_builder', function() {

        ob_start();

        if (!is_user_logged_in()) {
            return '<p>Please log in to use this tool.</p>';
        }

        $base_url = home_url('/');
        $generated_url = '';
        $qr_url = '';

        $current_user = wp_get_current_user();
        $current_rep_id = $current_user->ID;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wpat_builder_form'])) {

            $params = [];

            // Required
            $params['utm_source']  = sanitize_text_field($_POST['utm_source'] ?? '');
            $params['utm_medium']  = sanitize_text_field($_POST['utm_medium'] ?? '');
            $params['utm_content'] = sanitize_text_field($_POST['utm_content'] ?? '');

            // Auto rep
            $params['rep_id'] = $current_rep_id;

            // Optional
            if (!empty($_POST['wpf_tag'])) {
                $params['wpf_tag'] = sanitize_text_field($_POST['wpf_tag']);
            }

            if (!empty($_POST['exp'])) {
                $params['exp'] = sanitize_text_field($_POST['exp']);
            }

            if (!empty($_POST['ttl'])) {
                $params['ttl'] = intval($_POST['ttl']);
            }

            // ✅ Build URL correctly
            $query = [];
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) continue;
                $query[] = urlencode($key) . '=' . urlencode($value);
            }

            $generated_url = $base_url . '?' . implode('&', $query);

            // ✅ QR
            $qr_url = 'https://quickchart.io/qr?size=300'
                . '&text=' . urlencode($generated_url)
                . '&errorCorrectionLevel=H';
        }
    ?>

    <div class="wpat-builder">

        <form method="post">

            <input type="hidden" name="wpat_builder_form" value="1">

            <h5>Required Fields</h5>

            <p>
                <label>UTM Source*</label><br>
                <input type="text" name="utm_source" required placeholder="facebook / instagram / presentation">
            </p>

            <p>
                <label>UTM Medium*</label><br>
                <input type="text" name="utm_medium" required placeholder="social / qr / trade show">
            </p>

            <p>
                <label>UTM Content*</label><br>
                <input type="text" name="utm_content" required placeholder="campaign name or group name">
            </p>

            <h5>Optional</h5>

            <p>
                <label>Sales Code (ex: SA000-1)</label><br>
                <input type="text" name="wpf_tag" placeholder="SA000-1">
            </p>

            <p>
                <label>Campaign Expiration</label><br>
                <input type="date" name="exp">
            </p>

            <p>
                <label>TTL (days) - How long the campaign will be active</label><br>
                <input type="number" name="ttl">
            </p>

            <button type="submit" class="button">Generate</button>

        </form>

    <?php if ($generated_url): ?>

        <hr>

        <h5>Generated URL</h5>

        <textarea id="wpat-url" style="width:100%;height:80px;"><?php echo esc_html($generated_url); ?></textarea>

        <p>
            <button type="button" id="copy-url-btn" class="button">Copy URL</button>
        </p>

        <h5>QR Code</h5>

        <img id="wpat-qr" src="<?php echo esc_url($qr_url); ?>" style="max-width:300px;">

        <p>
            <button type="button" id="download-qr-btn" class="button">Download QR Code</button>
        </p>

    <?php endif; ?>

    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {

        // ✅ Copy button
        const copyBtn = document.getElementById('copy-url-btn');
        const urlField = document.getElementById('wpat-url');

        if (copyBtn && urlField) {
            copyBtn.addEventListener('click', function () {

                urlField.select();
                urlField.setSelectionRange(0, 99999);

                navigator.clipboard.writeText(urlField.value).then(() => {
                    copyBtn.innerText = 'Copied!';
                    setTimeout(() => copyBtn.innerText = 'Copy URL', 2000);
                });

            });
        }

        // ✅ Download QR (proper way)
        const dlBtn = document.getElementById('download-qr-btn');
        const qrImg = document.getElementById('wpat-qr');

        if (dlBtn && qrImg) {
            dlBtn.addEventListener('click', function () {

                fetch(qrImg.src)
                    .then(res => res.blob())
                    .then(blob => {

                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');

                        a.href = url;
                        a.download = 'qr-code.png';

                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                    });

            });
        }

    });
    </script>

    <?php

        return ob_get_clean();
    });

/**
 * ✅ CAPTURE ATTRIBUTION (FIRST TOUCH)
 */
add_action('template_redirect', function () {



    if (empty($_GET)) {
        return;
    }


//bm_log('Checking for attribution parameters...');
    if (!empty($_COOKIE['traffic_attribution'])) {
        $existing = json_decode(stripslashes($_COOKIE['traffic_attribution']), true);

        if (!empty($existing['utm_source']) || !empty($existing['utm_medium']) || !empty($existing['utm_content'])) {
            return; // ✅ only block if cookie has real attribution
        }
    }
//bm_log('No existing attribution cookie found.');
    if (
        empty($_GET['utm_source']) &&
        empty($_GET['utm_medium']) &&
        empty($_GET['utm_content'])
    ) {
        return;
    }
bm_log('Attribution parameters detected in URL.');

    // ✅ Expiration check
    $exp = $_GET['exp'] ?? null;
    $exp_ts = null;
bm_log('Expiration parameter: ' . ($exp ?? 'none'));
    if ($exp) {
        $exp_ts = is_numeric($exp) ? (int)$exp : strtotime($exp);
        if ($exp_ts && time() > $exp_ts) return;
    }
bm_log('Expiration timestamp: ' . ($exp_ts ?? 'none'));
    $data = [
        'utm_source'  => sanitize_text_field($_GET['utm_source'] ?? ''),
        'utm_medium'  => sanitize_text_field($_GET['utm_medium'] ?? ''),
        'utm_content' => sanitize_text_field($_GET['utm_content'] ?? ''),
        'rep_id'      => intval($_GET['rep_id'] ?? 0),
        'wpf_tag'     => sanitize_text_field($_GET['wpf_tag'] ?? ''),
        'exp'         => $exp_ts,
        'ttl'         => intval($_GET['ttl'] ?? 0),
        'ts'          => time()
    ];
bm_log('Attribution data to store: ' . print_r($data, true));
    setcookie(
        'traffic_attribution',
        json_encode($data),
        time() + (60 * 60 * 24 * 60),
        '/'
    );
bm_log('Attribution cookie set.');
    // ✅ If already logged in → apply immediately
    if (is_user_logged_in()) {
        wpat_apply_attribution(get_current_user_id());
    }
bm_log('Template redirect processing complete.');
});


/**
 * ✅ MAIN ATTRIBUTION FUNCTION
 */
function wpat_apply_attribution($user_id) {

    if (!$user_id) return;
    if (empty($_COOKIE['traffic_attribution'])) return;

    $data = json_decode(stripslashes($_COOKIE['traffic_attribution']), true);
    if (!$data) return;

    // ✅ Expiration enforcement
    if (!empty($data['exp']) && time() > $data['exp']) return;

    // ✅ TTL enforcement
    if (!empty($data['ttl'])) {
        $expires_at = $data['ts'] + ($data['ttl'] * 86400);
        if (time() > $expires_at) return;
    }

    // ✅ Build campaign key (DEDUP ENGINE)
    $campaign_key = md5(
        ($data['rep_id'] ?? '') . '|' .
        ($data['wpf_tag'] ?? '') . '|' .
        ($data['utm_content'] ?? '')
    );

bm_log('Cookie Debug: ' . print_r($data, true));

    // ✅ Prevent duplicate execution PER campaign
    if (get_user_meta($user_id, 'attr_' . $campaign_key, true)) {
        return;
    }

    /**
     * ✅ APPLY WP FUSION TAG (IF AVAILABLE)
     */
    if (function_exists('wp_fusion')) {

        if (!empty($data['wpf_tag'])) {

            $tag = strtoupper(trim($data['wpf_tag']));
            wp_fusion()->user->apply_tags([$tag], $user_id);

            bm_log('Applying WP Fusion tag: ' . print_r($data['wpf_tag'], true));
        }
    }

    /**
     * ✅ STORE USER META
     */
    update_user_meta($user_id, 'sales_rep_id', $data['rep_id']);
    update_user_meta($user_id, 'utm_source', $data['utm_source']);
    update_user_meta($user_id, 'utm_medium', $data['utm_medium']);
    update_user_meta($user_id, 'utm_content', $data['utm_content']);

    /**
     * ✅ CREDIT SALES REP
     */
    if (!empty($data['rep_id'])) {

        $rep_id = (int)$data['rep_id'];

        $count = (int) get_user_meta($rep_id, 'referral_count', true);
        update_user_meta($rep_id, 'referral_count', $count + 1);


    }

    /**
     * ✅ LOG TO DATABASE
     */
    global $wpdb;
    $table = $wpdb->prefix . 'attribution_log';

    $wpdb->insert($table, [
        'user_id'      => $user_id,
        'rep_id'       => $data['rep_id'],
        'utm_source'   => $data['utm_source'],
        'utm_medium'   => $data['utm_medium'],
        'utm_content'  => $data['utm_content'],
        'wpf_tag'      => $data['wpf_tag'],
        'campaign_key' => $campaign_key,
        'created_at'   => current_time('mysql')
    ]);

    /**
     * ✅ MARK CAMPAIGN AS APPLIED
     */
    update_user_meta($user_id, 'attr_' . $campaign_key, 1);
}


/**
 * ✅ APPLY ON REGISTRATION
 */
add_action('user_register', function ($user_id) {
    wpat_apply_attribution($user_id);
});


/**
 * ✅ APPLY ON LOGIN
 */
add_action('wp_login', function ($login, $user) {
    wpat_apply_attribution($user->ID);
}, 10, 2);

add_action('admin_menu', function () {
    add_menu_page(
        'Attribution Builder',
        'Attribution Builder',
        'manage_options',
        'wpat-builder',
        'wpat_builder_page',
        'dashicons-chart-line',
        25
    );
});

function wpat_builder_page() {

    $base_url = home_url('/');
    $generated_url = '';
    $qr_url = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $params = [];

        // ✅ Required fields
        $params['utm_source']  = sanitize_text_field(html_entity_decode($_POST['utm_source'] ?? '', ENT_QUOTES));
        $params['utm_medium']  = sanitize_text_field(html_entity_decode($_POST['utm_medium'] ?? '', ENT_QUOTES));
        $params['utm_content'] = sanitize_text_field(html_entity_decode($_POST['utm_content'] ?? '', ENT_QUOTES));


        // ✅ Optional fields
        if (!empty($_POST['rep_id'])) {
            $params['rep_id'] = intval($_POST['rep_id']);
        }

        if (!empty($_POST['wpf_tag'])) {
            $params['wpf_tag'] = sanitize_text_field(html_entity_decode($_POST['wpf_tag'], ENT_QUOTES));
        }

        if (!empty($_POST['exp'])) {
            $params['exp'] = sanitize_text_field(html_entity_decode($_POST['exp'], ENT_QUOTES));
        }

        if (!empty($_POST['ttl'])) {
            $params['ttl'] = intval($_POST['ttl']);
        }

        // ✅ Build URL
        $query = [];

        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) continue;

            // ✅ Decode any accidental encoding
            $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

            $query[] = urlencode($key) . '=' . urlencode($value);
        }

        $generated_url = $base_url . '?' . implode('&', $query);

        $logo_url = 'https://upload.wikimedia.org/wikipedia/commons/3/3f/Logo.png'; // or your custom logo URL

        $qr_url = 'https://quickchart.io/qr?size=300'
            . '&text=' . urlencode($generated_url)
/*             . '&centerImageUrl=' . urlencode($logo_url)
            . '&centerImageSizeRatio=0.25'
            . '&errorCorrectionLevel=H' */
            ;
            }

            echo '<pre>' . var_dump($qr_url) . '</pre>';

       

    ?>

    <div class="wrap">
        <h2>Attribution Link + QR Generator</h2>

        <form method="post" style="max-width:600px;">

            <h3>Required Fields</h3>

            <p>
                <label>UTM Source*</label><br>
                <input type="text" name="utm_source" required placeholder="facebook / presentation">
            </p>

            <p>
                <label>UTM Medium*</label><br>
                <input type="text" name="utm_medium" required placeholder="social / qr / paid_social">
            </p>

            <p>
                <label>UTM Content*</label><br>
                <input type="text" name="utm_content" required placeholder="campaign name or group">
            </p>

            <h3>Optional Fields</h3>

            <p>
                <label>Rep ID (WordPress User ID)</label><br>
                <input type="number" name="rep_id">
            </p>

            <p>
                <label>WP Fusion Tag ID (sales code)</label><br>
                <input type="text" name="wpf_tag">
            </p>

            <p>
                <label>Expiration Date</label><br>
                <input type="date" name="exp">
            </p>

            <p>
                <label>TTL (days)</label><br>
                <input type="number" name="ttl">
            </p>

            <p>
                <button class="button button-primary">Generate</button>
            </p>
        </form>

        <?php if ($generated_url): ?>

            <hr>

            <h3>Generated URL</h3>
            <textarea style="width:100%;height:80px;"><?php echo esc_html($generated_url); ?></textarea>

            <p>
                <button type="button" id="copy-url-btn" class="button">Copy URL link</button>
            </p>


            <h3>QR Code</h3>

            <img id="wpat-qr" src="<?php echo esc_url($qr_url); ?>" alt="QR Code" style="max-width:300px;">

            <p>
                <a id="download-qr-btn" href="<?php echo esc_url($qr_url); ?>" download="qr-code.png" class="button">
                    Download QR Code
                </a>
            </p>


        <?php endif; ?>

    </div>

    <?php
}