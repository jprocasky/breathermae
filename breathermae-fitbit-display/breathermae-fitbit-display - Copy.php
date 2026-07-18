
<?php
/**
 * Plugin Name: Breathermae – Fitbit Display
 * Description: Read-only presentation layer for Fitbit data already stored by the integration plugin. Provides Elementor-ready shortcodes for users and providers.
 * Author: Breathermae, Inc.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class BM_Fitbit_Display {

    private static $instance = null;

    /** Table names (resolved at runtime) */
    private $tables = [
        'cursors'  => null,
        'sleep'    => null,
        'activity' => null,
        'hr'       => null,
    ];

    /** Entry point (singleton) */
    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'bootstrap'], 20);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /** One and only bootstrap() — registers all shortcodes and AJAX hooks */
    public function bootstrap() {
        $this->resolve_table_names();

        // Display shortcodes
        add_shortcode('bm_fitbit_sleep_card',    [$this, 'sc_sleep_card']);
        add_shortcode('bm_fitbit_activity_card', [$this, 'sc_activity_card']);
        add_shortcode('bm_fitbit_hr_card',       [$this, 'sc_hr_card']);
        add_shortcode('bm_fitbit_dashboard',     [$this, 'sc_dashboard']);

        // Sync Now shortcode
        add_shortcode('bm_fitbit_sync_now',      [$this, 'sc_sync_now']);

        // AJAX endpoints for Sync Now
        add_action('wp_ajax_bm_fitbit_sync_now',        [$this, 'ajax_sync_now']);
        add_action('wp_ajax_nopriv_bm_fitbit_sync_now', [$this, 'ajax_sync_now_denied']);
    }

    /** Resolve table names from integration plugin if available; otherwise safe fallbacks. */
    private function resolve_table_names() {
        global $wpdb;
        if (function_exists('bm_fitbit_table_names')) {
            // Use the integration's canonical names when available.
            $tn = bm_fitbit_table_names(); // array with keys cursors, sleep, activity, hr
            $this->tables['cursors']  = $tn['cursors']  ?? $wpdb->prefix . 'bm_fitbit_cursors';
            $this->tables['sleep']    = $tn['sleep']    ?? $wpdb->prefix . 'bm_fitbit_sleep_summary';
            $this->tables['activity'] = $tn['activity'] ?? $wpdb->prefix . 'bm_fitbit_activity_summary';
            $this->tables['hr']       = $tn['hr']       ?? $wpdb->prefix . 'bm_fitbit_hr_intraday';
        } else {
            // Fallback to your current naming convention.
            $this->tables['cursors']  = $wpdb->prefix . 'bm_fitbit_cursors';
            $this->tables['sleep']    = $wpdb->prefix . 'bm_fitbit_sleep_summary';
            $this->tables['activity'] = $wpdb->prefix . 'bm_fitbit_activity_summary';
            $this->tables['hr']       = $wpdb->prefix . 'bm_fitbit_hr_intraday';
        }
    }

    /** Basic CSS for cards + small charts. Keep it light so it plays well with Elementor. */
    public function enqueue_assets() {
        $handle = 'bm-fitbit-display';
        $css = "
        .bmfd-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(260px,1fr)); gap:16px; }
        .bmfd-card { background:#fff; border:1px solid #e6e6e6; border-radius:12px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .bmfd-card h3 { margin:0 0 8px; font-size:1.1rem; }
        .bmfd-kpis { display:flex; gap:12px; flex-wrap:wrap; margin:8px 0 6px; }
        .bmfd-kpi { background:#f8fafc; border-radius:8px; padding:8px 10px; font-size:.95rem; }
        .bmfd-muted { color:#6b7280; font-size:.9rem; }
        .bmfd-mini-bars, .bmfd-sparkline { width:100%; height:60px; display:block; }
        .bmfd-bars rect { fill:#10b981; }
        .bmfd-bars rect.bmfd-empty { fill:#f3f4f6; }
        .bmfd-table { width:100%; border-collapse:collapse; margin-top:10px; }
        .bmfd-table th, .bmfd-table td { padding:6px 8px; border-bottom:1px solid #eee; text-align:left; font-size:.92rem; }
        .bmfd-right { text-align:right; }
        .bmfd-warn { background:#fff7ed; border:1px solid #fed7aa; color:#7c2d12; padding:10px; border-radius:8px; }
        ";
        wp_register_style($handle, false, [], '1.0.1');
        wp_add_inline_style($handle, $css);
        wp_enqueue_style($handle);
    }

    /** Resolve effective user ID (provider can pass user_id if they have capability). */
    private function resolve_user($atts_user_id) {
        $current_id = get_current_user_id();
        if (!$current_id) return 0;

        $atts_user_id = absint($atts_user_id);
        if ($atts_user_id && current_user_can('list_users')) {
            return $atts_user_id; // Provider/admin
        }
        return $current_id; // Regular user
    }

    /** Utility: format minutes as Hh Mm */
    private function fmt_minutes_hm($mins) {
        if ($mins === null) return '—';
        $h = floor($mins / 60);
        $m = $mins % 60;
        return sprintf('%dh %02dm', $h, $m);
    }

    /** Utility: safe avg */
    private function avg($arr) {
        $n = count($arr);
        if (!$n) return null;
        return array_sum($arr) / $n;
    }

    /** ===== SLEEP CARD ==================================================== */
    public function sc_sleep_card($atts) {
        $a = shortcode_atts([
            'days'    => '7',
            'user_id' => '',
            'title'   => 'Sleep (last {days} days)',
        ], $atts, 'bm_fitbit_sleep_card');

        $user_id = $this->resolve_user($a['user_id']);
        if (!$user_id) return '<div class="bmfd-warn">Please log in to view sleep.</div>';

        $days = max(1, min(90, absint($a['days'])));
        $title = str_replace('{days}', $days, sanitize_text_field($a['title']));

        global $wpdb;
        $table = $this->tables['sleep'];
        $start = date('Y-m-d', strtotime(current_time('Y-m-d') . ' -' . ($days-1) . ' days'));
        $sql = $wpdb->prepare(
            "SELECT date_of_sleep, minutes_asleep, efficiency, minutes_deep, minutes_light, minutes_rem
             FROM {$table}
             WHERE wp_user_id=%d AND date_of_sleep BETWEEN %s AND %s
             ORDER BY date_of_sleep ASC",
            $user_id, $start, current_time('Y-m-d')
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (!$rows) {
            return '<div class="bmfd-card"><h3>'.esc_html($title).'</h3><div class="bmfd-muted">No Fitbit sleep found for the last '.$days.' days.</div></div>';
        }

        // KPIs
        $mins_asleep = array_map(static function($r){ return (int)($r['minutes_asleep'] ?? 0); }, $rows);
        $avg_asleep = round($this->avg($mins_asleep));
        $avg_eff    = round($this->avg(array_map(static function($r){ return (int)($r['efficiency'] ?? 0); }, $rows)));

        // Mini bar chart (minutes asleep)
        $max_val = max($mins_asleep) ?: 1;
        $bar_w = 100 / max(1, count($rows));
        ob_start(); ?>
        <div class="bmfd-card">
            <h3><?php echo esc_html($title); ?></h3>
            <div class="bmfd-kpis">
                <div class="bmfd-kpi"><strong>Avg Asleep:</strong> <?php echo esc_html($this->fmt_minutes_hm($avg_asleep)); ?></div>
                <div class="bmfd-kpi"><strong>Avg Efficiency:</strong> <?php echo esc_html($avg_eff); ?>%</div>
            </div>
            <svg class="bmfd-mini-bars" viewBox="0 0 100 60" preserveAspectRatio="none" aria-label="Minutes asleep by day">
                <g class="bmfd-bars">
                    <?php
                    $i = 0;
                    foreach ($rows as $r) {
                        $v = (int)($r['minutes_asleep'] ?? 0);
                        $h = $max_val ? ( ($v / $max_val) * 58 ) : 0; // leave 2 units padding
                        $x = $i * $bar_w + 1;             // +1 pad
                        $y = 59 - $h;
                        $w = max(0.5, $bar_w - 2);        // small gap
                        printf('<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f"></rect>', $x, $y, $w, $h);
                        $i++;
                    }
                    ?>
                </g>
            </svg>
            <table class="bmfd-table" role="table" aria-label="Sleep detail">
                <thead>
                    <tr><th>Date</th><th class="bmfd-right">Asleep</th><th class="bmfd-right">Eff.</th><th class="bmfd-right">Deep</th><th class="bmfd-right">Light</th><th class="bmfd-right">REM</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo esc_html($r['date_of_sleep']); ?></td>
                            <td class="bmfd-right"><?php echo esc_html($this->fmt_minutes_hm((int)$r['minutes_asleep'])); ?></td>
                            <td class="bmfd-right"><?php echo esc_html((int)$r['efficiency']); ?>%</td>
                            <td class="bmfd-right"><?php echo esc_html($this->fmt_minutes_hm((int)($r['minutes_deep'] ?? 0))); ?></td>
                            <td class="bmfd-right"><?php echo esc_html($this->fmt_minutes_hm((int)($r['minutes_light'] ?? 0))); ?></td>
                            <td class="bmfd-right"><?php echo esc_html($this->fmt_minutes_hm((int)($r['minutes_rem'] ?? 0))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="bmfd-muted">Source: Fitbit sleep summary.</div>
        </div>
        <?php
        return ob_get_clean();
    }

    /** ===== ACTIVITY CARD ================================================ */
    public function sc_activity_card($atts) {
        $a = shortcode_atts([
            'days'    => '7',
            'user_id' => '',
            'title'   => 'Activity (last {days} days)',
        ], $atts, 'bm_fitbit_activity_card');

        $user_id = $this->resolve_user($a['user_id']);
        if (!$user_id) return '<div class="bmfd-warn">Please log in to view activity.</div>';

        $days = max(1, min(90, absint($a['days'])));
        $title = str_replace('{days}', $days, sanitize_text_field($a['title']));

        global $wpdb;
        $table = $this->tables['activity'];
        $start = date('Y-m-d', strtotime(current_time('Y-m-d') . ' -' . ($days-1) . ' days'));
        $sql = $wpdb->prepare(
            "SELECT activity_date, steps, calories, distance_km, sedentary_min, lightly_active_min, fairly_active_min, very_active_min, floors
             FROM {$table}
             WHERE wp_user_id=%d AND activity_date BETWEEN %s AND %s
             ORDER BY activity_date DESC",
            $user_id, $start, current_time('Y-m-d')
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (!$rows) {
            return '<div class="bmfd-card"><h3>'.esc_html($title).'</h3><div class="bmfd-muted">No Fitbit activity found for the last '.$days.' days.</div></div>';
        }

        $steps = array_map(static function($r){ return (int)($r['steps'] ?? 0); }, $rows);
        $avg_steps = round($this->avg($steps));
        $total_steps = array_sum($steps);

        $max_val = max($steps) ?: 1;
        $bar_w = 100 / max(1, count($rows));

        $sum = function($key) use ($rows) {
            $s = 0; foreach ($rows as $r) { $s += (int)($r[$key] ?? 0); } return $s;
        };

        ob_start(); ?>
        <div class="bmfd-card">
            <h3><?php echo esc_html($title); ?></h3>
            <div class="bmfd-kpis">
                <div class="bmfd-kpi"><strong>Total Steps:</strong> <?php echo number_format_i18n($total_steps); ?></div>
                <div class="bmfd-kpi"><strong>Avg Steps/Day:</strong> <?php echo number_format_i18n($avg_steps); ?></div>
                <div class="bmfd-kpi"><strong>Distance:</strong> <?php echo number_format_i18n(array_sum(array_map(static function($r){ return (float)($r['distance_km'] ?? 0.0); }, $rows)), 2); ?> km</div>
            </div>
            <svg class="bmfd-mini-bars" viewBox="0 0 100 60" preserveAspectRatio="none" aria-label="Steps by day">
                <g class="bmfd-bars">
                    <?php
                    $i = 0;
                    foreach ($rows as $r) {
                        $v = (int)($r['steps'] ?? 0);
                        $h = $max_val ? ( ($v / $max_val) * 58 ) : 0;
                        $x = $i * $bar_w + 1;
                        $y = 59 - $h;
                        $w = max(0.5, $bar_w - 2);
                        printf('<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f"></rect>', $x, $y, $w, $h);
                        $i++;
                    }
                    ?>
                </g>
            </svg>
            <table class="bmfd-table" role="table" aria-label="Activity detail">
                <thead>
                    <tr><th>Date</th><th class="bmfd-right">Steps</th><th class="bmfd-right">Calories</th><th class="bmfd-right">Sedentary</th><th class="bmfd-right">Light</th><th class="bmfd-right">Fair</th><th class="bmfd-right">Very</th><th class="bmfd-right">Floors</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo esc_html($r['activity_date']); ?></td>
                            <td class="bmfd-right"><?php echo number_format_i18n((int)$r['steps']); ?></td>
                            <td class="bmfd-right"><?php echo number_format_i18n((int)$r['calories']); ?></td>
                            <td class="bmfd-right"><?php echo esc_html((int)$r['sedentary_min']); ?>m</td>
                            <td class="bmfd-right"><?php echo esc_html((int)$r['lightly_active_min']); ?>m</td>
                            <td class="bmfd-right"><?php echo esc_html((int)$r['fairly_active_min']); ?>m</td>
                            <td class="bmfd-right"><?php echo esc_html((int)$r['very_active_min']); ?>m</td>
                            <td class="bmfd-right"><?php echo esc_html((int)$r['floors']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="bmfd-muted">
                Totals (<?php echo esc_html($days); ?>d): Light <?php echo esc_html($sum('lightly_active_min')); ?>m,
                Fair <?php echo esc_html($sum('fairly_active_min')); ?>m, Very <?php echo esc_html($sum('very_active_min')); ?>m.
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /** ===== HR CARD ======================================================= */
    public function sc_hr_card($atts) {
        $a = shortcode_atts([
            'date'    => 'today',  // YYYY-MM-DD or 'today'
            'user_id' => '',
            'title'   => 'Heart Rate ({date})',
        ], $atts, 'bm_fitbit_hr_card');

        $user_id = $this->resolve_user($a['user_id']);
        if (!$user_id) return '<div class="bmfd-warn">Please log in to view heart rate.</div>';

        $date = strtolower(trim($a['date'])) === 'today' ? current_time('Y-m-d') : sanitize_text_field($a['date']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = current_time('Y-m-d');

        global $wpdb;
        $table = $this->tables['hr'];
        $sql = $wpdb->prepare(
            "SELECT sample_time, bpm
             FROM {$table}
             WHERE wp_user_id=%d AND DATE(sample_time)=%s
             ORDER BY sample_time ASC",
            $user_id, $date
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $title = str_replace('{date}', $date, sanitize_text_field($a['title']));

        if (!$rows) {
            return '<div class="bmfd-card"><h3>'.esc_html($title).'</h3><div class="bmfd-muted">No intraday heart rate found for this date.</div></div>';
        }

        $bpms = array_map(static function($r){ return (int)$r['bpm']; }, $rows);
        $min = min($bpms);
        $max = max($bpms);
        $avg = round(array_sum($bpms)/count($bpms));

        // Sparkline (scaled to 100x60)
        $ymin = $min;
        $ymax = max($max, $min+1);
        $n = count($bpms);
        $points = [];
        foreach ($bpms as $i => $b) {
            $x = $n > 1 ? ($i / ($n-1)) * 100 : 0;
            $y = 58 - (($b - $ymin) / ($ymax - $ymin)) * 58;
            $points[] = sprintf('%.2f,%.2f', $x, $y);
        }

        ob_start(); ?>
        <div class="bmfd-card">
            <h3><?php echo esc_html($title); ?></h3>
            <div class="bmfd-kpis">
                <div class="bmfd-kpi"><strong>Min:</strong> <?php echo esc_html($min); ?> bpm</div>
                <div class="bmfd-kpi"><strong>Avg:</strong> <?php echo esc_html($avg); ?> bpm</div>
                <div class="bmfd-kpi"><strong>Max:</strong> <?php echo esc_html($max); ?> bpm</div>
            </div>
            <svg class="bmfd-sparkline" viewBox="0 0 100 60" preserveAspectRatio="none" aria-label="Heart rate sparkline">
                <polyline fill="none" stroke="#3b82f6" stroke-width="1.5" points="<?php echo esc_attr(implode(' ', $points)); ?>" />
            </svg>
            <div class="bmfd-muted">Source: Fitbit intraday heart rate.</div>
        </div>
        <?php
        return ob_get_clean();
    }

    /** ===== DASHBOARD (composition) ====================================== */
    public function sc_dashboard($atts) {
        $a = shortcode_atts([
            'days'    => '7',
            'date'    => 'today',
            'user_id' => '',
            'title'   => '',
        ], $atts, 'bm_fitbit_dashboard');

        $user_id = $this->resolve_user($a['user_id']);

        ob_start();
        echo '<div class="bmfd-grid">';
        echo do_shortcode('[bm_fitbit_sleep_card days="'.esc_attr($a['days']).'" user_id="'.esc_attr($user_id).'" title="Sleep (last {days} days)"]');
        echo do_shortcode('[bm_fitbit_activity_card days="'.esc_attr($a['days']).'" user_id="'.esc_attr($user_id).'" title="Activity (last {days} days)"]');
        echo do_shortcode('[bm_fitbit_hr_card date="'.esc_attr($a['date']).'" user_id="'.esc_attr($user_id).'" title="Heart Rate ({date})"]');
        echo '</div>';
        return ob_get_clean();
    }

    /** ===== SYNC NOW shortcode + AJAX ==================================== */

    /** Shortcode: [bm_fitbit_sync_now metrics="hr,sleep,activity" user_id="" label="Sync now"] */
    public function sc_sync_now($atts) {
        $a = shortcode_atts([
            'metrics' => 'hr,sleep,activity',
            'user_id' => '',
            'label'   => 'Sync now',
        ], $atts, 'bm_fitbit_sync_now');

        $viewer_id = get_current_user_id();
        if (!$viewer_id) {
            return '<div class="bmfd-warn">Please log in to sync.</div>';
        }

        $target_user = absint($a['user_id']);
        if ($target_user && current_user_can('list_users')) {
            // provider/admin may specify a user
        } else {
            $target_user = $viewer_id;
        }

        $metrics = array_filter(array_map('trim', explode(',', strtolower($a['metrics']))));
        $metrics = array_values(array_intersect($metrics, ['hr','sleep','activity']));
        if (empty($metrics)) $metrics = ['hr'];

        // Enqueue JS and pass config
        $handle = 'bm-fitbit-sync-now';
        wp_register_script($handle, false, ['jquery'], '1.0.1', true);
        $js = "
        jQuery(function($){
            $(document).on('click', '.bmfd-sync-btn', function(e){
                e.preventDefault();
                var $btn = $(this);
                if ($btn.prop('disabled')) return;
                $btn.prop('disabled', true).addClass('is-loading');
                var wrap = $btn.closest('.bmfd-sync-wrap');
                wrap.find('.bmfd-sync-msg').text('Starting sync…');

                $.post('".admin_url('admin-ajax.php')."', {
                    action: 'bm_fitbit_sync_now',
                    _wpnonce: '".wp_create_nonce('bm_fitbit_sync_now')."',
                    user_id: ".$target_user.",
                    metrics: ".wp_json_encode($metrics)."
                }).done(function(resp){
                    if (resp && resp.success) {
                        var m = [];
                        if (resp.data && resp.data.results) {
                            for (var k in resp.data.results) {
                                m.push(k+': '+resp.data.results[k]);
                            }
                        }
                        wrap.find('.bmfd-sync-msg').text('Sync complete. '+m.join(' • '));
                    } else {
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Unexpected error.';
                        wrap.find('.bmfd-sync-msg').text('Sync failed: ' + msg);
                    }
                }).fail(function(){
                    wrap.find('.bmfd-sync-msg').text('Network error while syncing.');
                }).always(function(){
                    $btn.prop('disabled', false).removeClass('is-loading');
                });
            });
        });";
        wp_add_inline_script($handle, $js);
        wp_enqueue_script($handle);

        // Minimal button styles (re-use main style handle)
        $css = "
        .bmfd-sync-wrap { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .bmfd-sync-btn { background:#111827; color:#fff; border:none; border-radius:8px; padding:10px 14px; cursor:pointer; }
        .bmfd-sync-btn.is-loading { opacity:.7; cursor:wait; }
        .bmfd-sync-msg { color:#374151; font-size:.95rem; }
        ";
        wp_add_inline_style('bm-fitbit-display', $css);

        ob_start(); ?>
        <div class="bmfd-sync-wrap" data-user="<?php echo esc_attr($target_user); ?>">
            <button class="bmfd-sync-btn"><?php echo esc_html($a['label']); ?></button>
            <span class="bmfd-sync-msg"></span>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_sync_now_denied() {
        wp_send_json_error(['message' => 'Please log in.'], 401);
    }

    public function ajax_sync_now() {
        // Auth + nonce
        if (!is_user_logged_in() || !check_ajax_referer('bm_fitbit_sync_now', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Unauthorized.'], 403);
        }

        $viewer = get_current_user_id();
        $target_user = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        if ($target_user && current_user_can('list_users')) {
            // ok, provider/admin overriding target
        } else {
            $target_user = $viewer;
        }
        if (!$target_user) {
            wp_send_json_error(['message' => 'Missing user.'], 400);
        }

        // Throttle: 60s per user to avoid hammering the API
        $key = 'bm_fitbit_sync_throttle_' . $target_user;
        if (get_transient($key)) {
            wp_send_json_error(['message' => 'Please wait a minute before syncing again.'], 429);
        }
        set_transient($key, 1, 60);

        $metrics = isset($_POST['metrics']) ? (array) $_POST['metrics'] : ['hr'];
        $metrics = array_values(array_intersect(array_map('strtolower', $metrics), ['hr','sleep','activity']));
        if (empty($metrics)) $metrics = ['hr'];

        // Ensure the integration functions exist
        if (!function_exists('bm_fitbit_sync_hr_window')
            || !function_exists('bm_fitbit_sync_sleep_window')
            || !function_exists('bm_fitbit_sync_activity_window')) {
            wp_send_json_error(['message' => 'Fitbit integration not available.'], 500);
        }

        // Execute
        $results = [];
        foreach ($metrics as $m) {
            switch ($m) {
                case 'hr':
                    $ok = bm_fitbit_sync_hr_window((int)$target_user);     // pulls up through today, capped by max days
                    $results['hr'] = $ok ? 'OK' : 'no update';
                    break;
                case 'sleep':
                    $ok = bm_fitbit_sync_sleep_window((int)$target_user);  // pulls window (yesterday->today range)
                    $results['sleep'] = $ok ? 'OK' : 'no update';
                    break;
                case 'activity':
                    $ok = bm_fitbit_sync_activity_window((int)$target_user);
                    $results['activity'] = $ok ? 'OK' : 'no update';
                    break;
            }
        }

        wp_send_json_success(['results' => $results]);
    }
}

BM_Fitbit_Display::instance();
