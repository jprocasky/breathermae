<?php
/*
Plugin Name: WP Attribution Tracker Pro
Version: 4.0
Author: Jeff Procasky
*/

if (!defined('ABSPATH')) exit;

/**
 * ✅ TABLES
 */
register_activation_hook(__FILE__, function () {
    global $wpdb;

    $charset = $wpdb->get_charset_collate();

    $log = $wpdb->prefix . 'attribution_log';
    $links = $wpdb->prefix . 'attribution_links';

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    dbDelta("CREATE TABLE $log (
        id BIGINT UNSIGNED AUTO_INCREMENT,
        user_id BIGINT UNSIGNED,
        rep_id BIGINT UNSIGNED,
        utm_source VARCHAR(100),
        utm_medium VARCHAR(100),
        utm_content VARCHAR(255),
        wpf_tag VARCHAR(50),
        campaign_key VARCHAR(64),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id)
    ) $charset;");

    dbDelta("CREATE TABLE $links (
        id BIGINT UNSIGNED AUTO_INCREMENT,
        short_code VARCHAR(10) NOT NULL,
        user_id BIGINT UNSIGNED,
        campaign_name VARCHAR(255),
        utm_source VARCHAR(100),
        utm_medium VARCHAR(100),
        utm_content VARCHAR(255),
        rep_id BIGINT UNSIGNED,
        wpf_tag VARCHAR(50),
        exp DATETIME NULL,
        ttl INT DEFAULT 0,
        click_count INT DEFAULT 0,
        full_url TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        UNIQUE KEY short_code (short_code),
        KEY user_id (user_id)
    ) $charset;");
});

/**
 * ✅ SHORT CODE GENERATOR
 */
function wpat_generate_code($wpdb) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $table = $wpdb->prefix . 'attribution_links';

    do {
        $code = '';
        for ($i=0;$i<5;$i++) {
            $code .= $chars[random_int(0, strlen($chars)-1)];
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE short_code=%s",
            $code
        ));

    } while ($exists);

    return $code;
}

/**
 * ✅ BUILDER SHORTCODE
 */
add_shortcode('wpat_builder', function() {

    if (!is_user_logged_in()) return 'Login required';

    global $wpdb;

    $base = home_url('/');
    $full = '';
    $short = '';
    $qr = '';

    if ($_POST['wpat_builder_form'] ?? false) {

        $user = get_current_user_id();

        $params = [
            'utm_source' => sanitize_text_field($_POST['utm_source']),
            'utm_medium' => sanitize_text_field($_POST['utm_medium']),
            'utm_content'=> sanitize_text_field($_POST['utm_content']),
            'rep_id'     => $user
        ];

        if ($_POST['wpf_tag']) {
            $params['wpf_tag'] = sanitize_text_field($_POST['wpf_tag']);
        }

        if ($_POST['exp']) $params['exp'] = $_POST['exp'];
        if ($_POST['ttl']) $params['ttl'] = intval($_POST['ttl']);

        $query = [];
        foreach ($params as $k=>$v) {
            if (!$v) continue;
            $query[] = urlencode($k).'='.urlencode($v);
        }

        $full = $base.'?'.implode('&',$query);

        $code = wpat_generate_code($wpdb);

        $wpdb->insert($wpdb->prefix.'attribution_links', [
            'short_code'=>$code,
            'user_id'=>$user,
            'campaign_name'=>sanitize_text_field($_POST['campaign_name']),
            'utm_source'=>$params['utm_source'],
            'utm_medium'=>$params['utm_medium'],
            'utm_content'=>$params['utm_content'],
            'rep_id'=>$params['rep_id'],
            'wpf_tag'=>$params['wpf_tag'] ?? '',
            'exp'=>$params['exp'] ?? null,
            'ttl'=>$params['ttl'] ?? 0,
            'full_url'=>$full
        ]);

        $short = home_url('/go/'.$code);

        $qr = 'https://quickchart.io/qr?size=300&text='.urlencode($short);
    }

ob_start();
?>
<div class="wpat-builder">

<form method="post">
<input type="hidden" name="wpat_builder_form" value="1">
<label>Campaign Name *</label><br>
<input name="campaign_name" placeholder="Campaign Name *" required size="30"><br>
<label>UTM Source *</label><br>
<input name="utm_source" placeholder="facebook / instagram / presentation" required size="30"><br>
<label>UTM Medium *</label><br>
<input name="utm_medium" placeholder="social / qr / trade show" required size="30"><br>
<label>UTM Content *</label><br>
<input name="utm_content" placeholder="group name or other identifier" required size="30"><br>
<label>Ambassador Code</label><br>
<input name="wpf_tag" placeholder="SA000-1" size="30"><br>
<label>Expiration Date <span style="font-size: 0.8rem;">(optional - when the campaign expires)</span></label><br>
<input type="date" name="exp" style="width: 200px;"><br>
<label>Time to Live <span style="font-size: 0.8rem;">(optional - How many days to keep the link active - blank is infinite)</span></label><br>
<input type="number" name="ttl" placeholder="TTL" style="width: 100px;"><br>
<button class="button button-primary">Generate Link and QR Code</button>
</form>

<?php if($full): ?>
<br><br>
<h4>Short URL</h4>
<textarea id="short" cols="50" rows="1"><?php echo esc_html($short); ?></textarea><br>
<button class="button" onclick="copy('short',this)">Copy Short URL</button>

<h4>Full URL</h4>
<textarea id="full" cols="50" rows="3"><?php echo esc_html($full); ?></textarea><br>
<button class="button" onclick="copy('full',this)">Copy Full URL</button>

<h4>QR</h4>
<img id="qr" src="<?php echo esc_url($qr); ?>" width="200">

<button class="button" onclick="downloadQR()">Download QR</button>


<?php endif; ?>
</div>

<style>
.wpat-builder .button {
    background-color: #3db7ff !important;
    color: #ffffff !important;
    padding: 6px 12px !important;
    font-size: 13px !important;
    border: none !important;
    border-radius: 3px;
    line-height: 1.2;
    cursor: pointer;
}

.wpat-builder .button:hover {
    background-color: #FD5A38 !important;
}

.wpat-builder textarea {
    font-size: 13px;
}
</style>


<script>
function copy(id,btn){
 let el=document.getElementById(id);
 el.select();
 document.execCommand('copy');
 btn.innerText='Copied';
 setTimeout(()=>btn.innerText='Copy',1500);
}

function downloadQR(){
 fetch(document.getElementById('qr').src)
 .then(r=>r.blob())
 .then(b=>{
   const a=document.createElement('a');
   a.href=URL.createObjectURL(b);
   a.download='qr.png';
   a.click();
 });
}
</script>

<?php
return ob_get_clean();
});

/**
 * ✅ MY LINKS SHORTCODE
 */
add_shortcode('wpat_my_links', function($atts){

    if(!is_user_logged_in()) return '';

    global $wpdb;

    $atts = shortcode_atts([
        'display_fields'=>'*'
    ], $atts);

    $fields = $atts['display_fields'] === '*'
        ? ['campaign_name','short_url','click_count','utm_source','utm_medium','utm_content','created_at']
        : explode(',', $atts['display_fields']);

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}attribution_links WHERE user_id=%d ORDER BY created_at DESC",
            get_current_user_id()
        ),
        ARRAY_A
    );

    ob_start();

    echo '<table border="1" style="border-collapse: collapse; width: 100%;  margin: 5px auto;"><tr>';

    foreach($fields as $f){
        echo '<th style="border: 1px solid #ccc; padding: 8px; text-align: center;">'.esc_html($f).'</th>';
    }

    echo '</tr>';

    foreach($rows as $r){
        echo '<tr>';
        foreach($fields as $f){

            if($f=='short_url'){
                echo '<td><a href="'.home_url('/go/'.$r['short_code']).'">'.$r['short_code'].'</a></td>';
            } else {
                echo '<td>'.esc_html($r[$f] ?? '').'</td>';
            }

        }
        echo '</tr>';
    }

    echo '</table>';

    return ob_get_clean();
});

/**
 * ✅ SHORT LINK ROUTE + CLICK TRACK
 */
add_action('init', function(){
    add_rewrite_rule('^go/([A-Z0-9]+)/?$', 'index.php?wpat=$matches[1]', 'top');
});

add_filter('query_vars', function($vars){
    $vars[]='wpat';
    return $vars;
});

add_action('template_redirect', function(){
    global $wpdb;

    $code = get_query_var('wpat');
    if(!$code) return;

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}attribution_links WHERE short_code=%s",
        $code
    ));

    if($row){

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}attribution_links SET click_count=click_count+1 WHERE id=%d",
            $row->id
        ));

        wp_redirect($row->full_url,302);
        exit;
    }
});