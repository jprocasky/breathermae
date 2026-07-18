<?php
/**
 * Plugin Name: Breathermae – Fitbit Display
 * Description: Read-only presentation layer for Fitbit data stored by the integration plugin. Elementor-friendly shortcodes.
 * Author: Breathermae, Inc.
 * Version: 1.2.3
 * Requires at least: 6.0
 * Requires PHP: 7.0
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;



class BM_Fitbit_Display {
    private static $instance = null;

    private $tables = array(
        'cursors'    => null,
        'sleep'      => null,
        'activity'   => null,
        'hr'         => null,
        'rhr'        => null,
        'spo2_sum'   => null,
        'spo2_intra' => null,
        'hrv'        => null,
    );

    public static function instance(){
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct(){
        add_action('plugins_loaded', array($this,'bootstrap'), 20);
        add_action('wp_enqueue_scripts', array($this,'enqueue_assets'));
    }

    public function bootstrap(){
        $this->resolve_table_names();
        add_shortcode('bm_fitbit_sleep_card',    array($this,'sc_sleep_card'));
        add_shortcode('bm_fitbit_activity_card', array($this,'sc_activity_card'));
        add_shortcode('bm_fitbit_hr_card',       array($this,'sc_hr_card'));
        add_shortcode('bm_fitbit_rhr_card',      array($this,'sc_rhr_card'));
        add_shortcode('bm_fitbit_spo2_card',     array($this,'sc_spo2_card'));
        add_shortcode('bm_fitbit_hrv_card',      array($this,'sc_hrv_card'));
        add_shortcode('bm_fitbit_dashboard',     array($this,'sc_dashboard'));
        add_shortcode('bm_fitbit_sync_now',      array($this,'sc_sync_now'));
        add_action('wp_ajax_bm_fitbit_sync_now',        array($this,'ajax_sync_now'));
        add_action('wp_ajax_nopriv_bm_fitbit_sync_now', array($this,'ajax_sync_now_denied'));
        add_action('admin_post_bm_fitbit_sync_now_post',        array($this,'handle_sync_now_post'));
        add_action('admin_post_nopriv_bm_fitbit_sync_now_post', array($this,'handle_sync_now_post_denied'));
    }

    private function resolve_table_names(){
        global $wpdb;
        if (function_exists('bm_fitbit_table_names')){
            $tn = bm_fitbit_table_names();
            $this->tables['cursors']    = isset($tn['cursors'])    ? $tn['cursors']    : $wpdb->prefix.'bm_fitbit_cursors';
            $this->tables['sleep']      = isset($tn['sleep'])      ? $tn['sleep']      : $wpdb->prefix.'bm_fitbit_sleep_summary';
            $this->tables['activity']   = isset($tn['activity'])   ? $tn['activity']   : $wpdb->prefix.'bm_fitbit_activity_summary';
            $this->tables['hr']         = isset($tn['hr'])         ? $tn['hr']         : $wpdb->prefix.'bm_fitbit_hr_intraday';
            $this->tables['rhr']        = isset($tn['rhr'])        ? $tn['rhr']        : $wpdb->prefix.'bm_fitbit_daily_rhr';
            $this->tables['spo2_sum']   = isset($tn['spo2_sum'])   ? $tn['spo2_sum']   : $wpdb->prefix.'bm_fitbit_spo2_summary';
            $this->tables['spo2_intra'] = isset($tn['spo2_intra']) ? $tn['spo2_intra'] : $wpdb->prefix.'bm_fitbit_spo2_intraday';
            $this->tables['hrv']        = isset($tn['hrv'])        ? $tn['hrv']        : $wpdb->prefix.'bm_fitbit_hrv_summary';
        } else {
            $p = $wpdb->prefix;
            $this->tables['cursors']    = $p.'bm_fitbit_cursors';
            $this->tables['sleep']      = $p.'bm_fitbit_sleep_summary';
            $this->tables['activity']   = $p.'bm_fitbit_activity_summary';
            $this->tables['hr']         = $p.'bm_fitbit_hr_intraday';
            $this->tables['rhr']        = $p.'bm_fitbit_daily_rhr';
            $this->tables['spo2_sum']   = $p.'bm_fitbit_spo2_summary';
            $this->tables['spo2_intra'] = $p.'bm_fitbit_spo2_intraday';
            $this->tables['hrv']        = $p.'bm_fitbit_hrv_summary';
        }
    }

    public function enqueue_assets(){
        $h='bm-fitbit-display';
        $css  = '.bmfd-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}';
        $css .= '.bmfd-card{background:#fff;border:1px solid #e6e6e6;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.06)}';
        $css .= '.bmfd-card h3{margin:0 0 8px;font-size:1.1rem}';
        $css .= '.bmfd-kpis{display:flex;gap:12px;flex-wrap:wrap;margin:8px 0 6px}';
        $css .= '.bmfd-kpi{background:#f8fafc;border-radius:8px;padding:8px 10px;font-size:.95rem}';
        $css .= '.bmfd-muted{color:#6b7280;font-size:.9rem}';
        $css .= '.bmfd-mini-bars,.bmfd-sparkline{width:100%;height:60px;display:block}';
        $css .= '.bmfd-bars rect{fill:#10b981}';
        $css .= '.bmfd-right{text-align:right}';
        $css .= '.bmfd-warn{background:#fff7ed;border:1px solid #fed7aa;color:#7c2d12;padding:10px;border-radius:8px}';
        $css .= '.bmfd-note{color:#4b5563;font-size:.85rem}';
        /* table styling */
        $css .= '.bmfd-card table{width:100%;border-collapse:separate;border-spacing:0;border:0;margin-top:8px}';
        $css .= '.bmfd-card thead th{font-weight:600;color:#374151;background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:10px 12px;text-align:left}';
        $css .= '.bmfd-card tbody td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top}';
        $css .= '.bmfd-card tbody tr:nth-child(odd){background:#fcfcfd}';
        $css .= '.bmfd-card .bmfd-table-wrap{width:100%;overflow-x:auto}';
        $css .= '.bmfd-card table{border-radius:8px;overflow:hidden}';
        wp_register_style($h,false,array(),'1.2.3');
        wp_add_inline_style($h,$css);
        wp_enqueue_style($h);
    }

    private function resolve_user($atts_user_id){
        $current_id = get_current_user_id();
        if (!$current_id) return 0;
        $atts_user_id = absint($atts_user_id);
        if ($atts_user_id && current_user_can('list_users')) return $atts_user_id;
        return $current_id;
    }

    private function fmt_minutes_hm($mins){
        if ($mins === null) return '—';
        $h = floor($mins/60);
        $m = $mins % 60;
        return sprintf('%dh %02dm',$h,$m);
    }

    private function avg($arr){
        $n = is_array($arr) ? count($arr) : 0;
        if (!$n) return null;
        return array_sum($arr)/$n;
    }

    /* ================= SLEEP ================= */
    public function sc_sleep_card($atts){
        $a = shortcode_atts(array('days'=>'7','user_id'=>'','title'=>'Sleep (last {days} days)'), $atts, 'bm_fitbit_sleep_card');
        $uid = $this->resolve_user($a['user_id']); if(!$uid) return '<div class="bmfd-warn">Please log in to view sleep.</div>';
        $days = max(1, min(90, absint($a['days'])));
        $title = str_replace('{days}', $days, sanitize_text_field($a['title']));
        global $wpdb; $t=$this->tables['sleep'];
        $start = date('Y-m-d', strtotime(current_time('Y-m-d').' -'.($days-1).' days'));
        $sql = $wpdb->prepare("SELECT date_of_sleep, minutes_asleep, efficiency, minutes_deep, minutes_light, minutes_rem FROM {$t} WHERE wp_user_id=%d AND date_of_sleep BETWEEN %s AND %s ORDER BY date_of_sleep DESC", $uid, $start, current_time('Y-m-d'));
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) return '<div class="bmfd-card"><h3>'.esc_html($title).'</h3><div class="bmfd-muted">No Fitbit sleep found for the last '.$days.' days.</div></div>';
        $mins = array(); $effs = array();
        foreach($rows as $r){ $mins[]=(int)$r['minutes_asleep']; $effs[]=(int)$r['efficiency']; }
        $avg_asleep = round($this->avg($mins));
        $avg_eff    = round($this->avg($effs));
        $max_val = max($mins); if(!$max_val) $max_val=1; $bar_w = 100/max(1,count($rows));
        ob_start(); ?>
        <div class="bmfd-card">
          <h3><?php echo esc_html($title); ?></h3>
          <div class="bmfd-kpis">
            <div class="bmfd-kpi"><strong>Avg Asleep:</strong> <?php echo esc_html($this->fmt_minutes_hm($avg_asleep)); ?></div>
            <div class="bmfd-kpi"><strong>Avg Efficiency:</strong> <?php echo esc_html($avg_eff); ?>%</div>
          </div>
          <svg class="bmfd-mini-bars" viewBox="0 0 100 60" preserveAspectRatio="none" aria-label="Minutes asleep by day">
            <g class="bmfd-bars">
              <?php $i=0; foreach($rows as $r){ $v=(int)$r['minutes_asleep']; $h=$max_val?(($v/$max_val)*58):0; $x=$i*$bar_w+1; $y=59-$h; $w=max(0.5,$bar_w-2); printf('<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f"></rect>', $x,$y,$w,$h); $i++; } ?>
            </g>
          </svg>
          <div class="bmfd-table-wrap">
            <table class="bmfd-table" role="table" aria-label="Sleep detail">
              <thead><tr><th>Date</th><th class="bmfd-right">Asleep</th><th class="bmfd-right">Eff.</th><th class="bmfd-right">Deep</th><th class="bmfd-right">Light</th><th class="bmfd-right">REM</th></tr></thead>
              <tbody>
                <?php foreach($rows as $r): ?>
                  <tr>
                    <td><?php echo esc_html($r['date_of_sleep']); ?></td>
                    <td class="bmfd-right"><?php echo esc_html($this->fmt_minutes_hm((int)$r['minutes_asleep'])); ?></td>
                    <td class="bmfd-right"><?php echo esc_html((int)$r['efficiency']); ?>%</td>
                    <td class="bmfd-right"><?php echo esc_html($this->fmt_minutes_hm((int)$r['minutes_deep'])); ?></td>
                    <td class="bmfd-right"><?php echo esc_html($this->fmt_minutes_hm((int)$r['minutes_light'])); ?></td>
                    <td class="bmfd-right"><?php echo esc_html($this->fmt_minutes_hm((int)$r['minutes_rem'])); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="bmfd-muted">Source: Fitbit sleep summary.</div>
        </div>
        <?php return ob_get_clean(); }

    /* ================= ACTIVITY ================= */
    public function sc_activity_card($atts){
        $a = shortcode_atts(array('days'=>'7','user_id'=>'','title'=>'Activity (last {days} days)'), $atts, 'bm_fitbit_activity_card');
        $uid = $this->resolve_user($a['user_id']); if(!$uid) return '<div class="bmfd-warn">Please log in to view activity.</div>';
        $days = max(1, min(90, absint($a['days'])));
        $title = str_replace('{days}', $days, sanitize_text_field($a['title']));
        global $wpdb; $t=$this->tables['activity'];
        $start = date('Y-m-d', strtotime(current_time('Y-m-d').' -'.($days-1).' days'));
        $sql = $wpdb->prepare("SELECT activity_date, steps, calories, distance_km, sedentary_min, lightly_active_min, fairly_active_min, very_active_min, floors FROM {$t} WHERE wp_user_id=%d AND activity_date BETWEEN %s AND %s ORDER BY activity_date DESC", $uid, $start, current_time('Y-m-d'));
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) return '<div class="bmfd-card"><h3>'.esc_html($title).'</h3><div class="bmfd-muted">No Fitbit activity found for the last '.$days.' days.</div></div>';
        $steps = array(); $distance_total = 0.0; $light=0; $fair=0; $very=0;
        foreach($rows as $r){
            $steps[] = (int)$r['steps'];
            $distance_total += (float)$r['distance_km'];
            $light += (int)$r['lightly_active_min'];
            $fair  += (int)$r['fairly_active_min'];
            $very  += (int)$r['very_active_min'];
        }
        $avg_steps   = round($this->avg($steps));
        $total_steps = array_sum($steps);
        $max_val = max($steps); if(!$max_val) $max_val=1; $bar_w = 100/max(1,count($rows));
        ob_start(); ?>
        <div class="bmfd-card">
          <h3><?php echo esc_html($title); ?></h3>
          <div class="bmfd-kpis">
            <div class="bmfd-kpi"><strong>Total Steps:</strong> <?php echo number_format_i18n($total_steps); ?></div>
            <div class="bmfd-kpi"><strong>Avg Steps/Day:</strong> <?php echo number_format_i18n($avg_steps); ?></div>
            <div class="bmfd-kpi"><strong>Distance:</strong> <?php echo number_format_i18n($distance_total, 2); ?> km</div>
          </div>
          <svg class="bmfd-mini-bars" viewBox="0 0 100 60" preserveAspectRatio="none" aria-label="Steps by day">
            <g class="bmfd-bars">
              <?php $i=0; foreach($rows as $r){ $v=(int)$r['steps']; $h=$max_val?(($v/$max_val)*58):0; $x=$i*$bar_w+1; $y=59-$h; $w=max(0.5,$bar_w-2); printf('<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f"></rect>', $x,$y,$w,$h); $i++; } ?>
            </g>
          </svg>
          <div class="bmfd-table-wrap">
            <table class="bmfd-table" role="table" aria-label="Activity detail">
              <thead><tr><th>Date</th><th class="bmfd-right">Steps</th><th class="bmfd-right">Calories</th><th class="bmfd-right">Sedentary</th><th class="bmfd-right">Light</th><th class="bmfd-right">Fair</th><th class="bmfd-right">Very</th><th class="bmfd-right">Floors</th></tr></thead>
              <tbody>
                <?php foreach($rows as $r): ?>
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
          </div>
          <div class="bmfd-muted">Totals (<?php echo esc_html($days); ?>d): Light <?php echo esc_html($light); ?>m, Fair <?php echo esc_html($fair); ?>m, Very <?php echo esc_html($very); ?>m.</div>
        </div>
        <?php return ob_get_clean(); }

    /* ================= HR (intraday) ================= */
    public function sc_hr_card($atts){
        $a = shortcode_atts(array('date'=>'today','user_id'=>'','title'=>'Heart Rate ({date})'), $atts, 'bm_fitbit_hr_card');
        $uid = $this->resolve_user($a['user_id']); if(!$uid) return '<div class="bmfd-warn">Please log in to view heart rate.</div>';
        
        $is_today = strtolower(trim($a['date'])) === 'today';

        // Intraday HR is only available for completed days → use yesterday
        if ($is_today) {
            $date = date('Y-m-d', strtotime('-2 day', current_time('timestamp')));
        } else {
            $date = sanitize_text_field($a['date']);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = date('Y-m-d', strtotime('-2 day', current_time('timestamp')));
            }
        }

        global $wpdb; $t=$this->tables['hr'];
        $start = $date.' 00:00:00'; $end = $is_today ? current_time('mysql') : ($date.' 23:59:59');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT sample_time, bpm FROM {$t} WHERE wp_user_id=%d AND sample_time BETWEEN %s AND %s ORDER BY sample_time ASC", $uid, $start, $end), ARRAY_A);
        $note=''; $title=str_replace('{date}', $date, sanitize_text_field($a['title']));
        if (!$rows && $is_today){
            $y = date('Y-m-d', strtotime(current_time('Y-m-d').' -1 day'));
            $rows = $wpdb->get_results($wpdb->prepare("SELECT sample_time, bpm FROM {$t} WHERE wp_user_id=%d AND sample_time BETWEEN %s AND %s ORDER BY sample_time ASC", $uid, $y.' 00:00:00', $y.' 23:59:59'), ARRAY_A);
            if ($rows){ $date=$y; $title=str_replace('{date}', $date, sanitize_text_field($a['title'])); $note=' <span class="bmfd-note">(showing Yesterday while Today\'s data updates)</span>'; }
        }
        if (!$rows) return '<div class="bmfd-card"><h3>'.esc_html($title).'</h3><div class="bmfd-muted">No intraday heart rate found for this date.</div></div>';
        $vals=array(); foreach($rows as $r){ $vals[]=(int)$r['bpm']; }
        $min=min($vals); $max=max($vals); $avg=round(array_sum($vals)/count($vals));
        $ymin=$min; $ymax=max($max, $min+1); $n=count($vals); $pts=array();
        for($i=0;$i<$n;$i++){
            $b=$vals[$i]; $x=($n>1)?($i/($n-1))*100:0; $y=58 - (($b-$ymin)/($ymax-$ymin))*58; $pts[]=sprintf('%.2f,%.2f',$x,$y);
        }
        $points = implode(' ',$pts);
        ob_start(); ?>
        <div class="bmfd-card">
          <h3><?php echo esc_html($title); ?><?php echo $note; ?></h3>
          <div class="bmfd-kpis">
            <div class="bmfd-kpi"><strong>Min:</strong> <?php echo esc_html($min); ?> bpm</div>
            <div class="bmfd-kpi"><strong>Avg:</strong> <?php echo esc_html($avg); ?> bpm</div>
            <div class="bmfd-kpi"><strong>Max:</strong> <?php echo esc_html($max); ?> bpm</div>
          </div>
          <svg class="bmfd-sparkline" viewBox="0 0 100 60" preserveAspectRatio="none" aria-label="Heart rate sparkline">
            <polyline fill="none" stroke="#3b82f6" stroke-width="1.5" points="<?php echo esc_attr($points); ?>" />
          </svg>
          <div class="bmfd-muted">Source: Fitbit intraday heart rate.</div>
        </div>
        <?php return ob_get_clean(); }

    /* ================= RHR (daily) ================= */
    public function sc_rhr_card($atts){
        $a = shortcode_atts(array('days'=>'30','user_id'=>'','title'=>'Resting Heart Rate (last {days} days)'), $atts, 'bm_fitbit_rhr_card');
        $uid=$this->resolve_user($a['user_id']); if(!$uid) return '<div class="bmfd-warn">Please log in to view resting heart rate.</div>';
        $days=max(1, min(180, absint($a['days'])));
        $title=str_replace('{days}', $days, sanitize_text_field($a['title']));
        global $wpdb; $t=$this->tables['rhr'];
        $start=date('Y-m-d', strtotime(current_time('Y-m-d').' -'.($days-1).' days'));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT rhr_date, resting_bpm FROM {$t} WHERE wp_user_id=%d AND rhr_date BETWEEN %s AND %s ORDER BY rhr_date DESC", $uid,$start,current_time('Y-m-d')), ARRAY_A);
        if(!$rows) return '<div class="bmfd-card"><h3>'.esc_html($title).'</h3><div class="bmfd-muted">No Resting Heart Rate values found for the last '.$days.' days.</div></div>';
        $vals=array(); foreach($rows as $r){ $vals[]=(int)$r['resting_bpm']; }
        $min=min($vals); $max=max($vals); $avg=round(array_sum($vals)/count($vals));
        $mx=$max?$max:1; $bar_w=100/max(1,count($rows));
        ob_start(); ?>
        <div class="bmfd-card">
          <h3><?php echo esc_html($title); ?></h3>
          <div class="bmfd-kpis">
            <div class="bmfd-kpi"><strong>Min:</strong> <?php echo esc_html($min); ?> bpm</div>
            <div class="bmfd-kpi"><strong>Avg:</strong> <?php echo esc_html($avg); ?> bpm</div>
            <div class="bmfd-kpi"><strong>Max:</strong> <?php echo esc_html($max); ?> bpm</div>
          </div>
          <svg class="bmfd-mini-bars" viewBox="0 0 100 60" preserveAspectRatio="none" aria-label="Resting HR by day">
            <g class="bmfd-bars">
              <?php $i=0; foreach($rows as $r){ $v=(int)$r['resting_bpm']; $h=$mx?(($v/$mx)*58):0; $x=$i*$bar_w+1; $y=59-$h; $w=max(0.5,$bar_w-2); printf('<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f"></rect>',$x,$y,$w,$h); $i++; } ?>
            </g>
          </svg>
          <div class="bmfd-table-wrap">
            <table class="bmfd-table" role="table"><thead><tr><th>Date</th><th class="bmfd-right">RHR (bpm)</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo esc_html($r['rhr_date']); ?></td><td class="bmfd-right"><?php echo esc_html((int)$r['resting_bpm']); ?></td></tr><?php endforeach; ?></tbody></table>
          </div>
          <div class="bmfd-muted">Source: Fitbit heart rate time series (daily Resting Heart Rate).</div>
        </div>
        <?php return ob_get_clean(); }

    /* ================= SpO2 (nightly) ================= */
    public function sc_spo2_card($atts){
        $a = shortcode_atts(array('days'=>'30','user_id'=>'','title'=>'Blood Oxygen (SpO2, last {days} nights)'), $atts, 'bm_fitbit_spo2_card');
        $uid=$this->resolve_user($a['user_id']); if(!$uid) return '<div class="bmfd-warn">Please log in to view SpO2.</div>';
        $days=max(1, min(180, absint($a['days'])));
        $title=str_replace('{days}', $days, sanitize_text_field($a['title']));
        global $wpdb; $t=$this->tables['spo2_sum'];
        $start=date('Y-m-d', strtotime(current_time('Y-m-d').' -'.($days-1).' days'));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT spo2_date, avg_pct, min_pct, max_pct FROM {$t} WHERE wp_user_id=%d AND spo2_date BETWEEN %s AND %s ORDER BY spo2_date DESC", $uid,$start,current_time('Y-m-d')), ARRAY_A);
        if(!$rows) return '<div class="bmfd-card"><h3>'.esc_html($title).'</h3><div class="bmfd-muted">No SpO2 values found for the last '.$days.' nights.</div></div>';
        $avgs=array(); $mins=array(); $mxs=array();
        foreach($rows as $r){ $avgs[]=(float)$r['avg_pct']; $mins[]=(float)$r['min_pct']; $mxs[]=(float)$r['max_pct']; }
        $avg = round(array_sum($avgs)/max(1,count($avgs)),1);
        $min = min($mins); $max = max($mxs);
        $mx = $max?$max:100; $bar_w=100/max(1,count($rows));
        ob_start(); ?>
        <div class="bmfd-card">
          <h3><?php echo esc_html($title); ?></h3>
          <div class="bmfd-kpis">
            <div class="bmfd-kpi"><strong>Avg:</strong> <?php echo esc_html(number_format_i18n($avg,1)); ?>%</div>
            <div class="bmfd-kpi"><strong>Best:</strong> <?php echo esc_html(number_format_i18n($max,1)); ?>%</div>
            <div class="bmfd-kpi"><strong>Lowest:</strong> <?php echo esc_html(number_format_i18n($min,1)); ?>%</div>
          </div>
          <svg class="bmfd-mini-bars" viewBox="0 0 100 60" preserveAspectRatio="none" aria-label="Nightly SpO2 avg by day">
            <g class="bmfd-bars">
              <?php $i=0; foreach($rows as $r){ $v=(float)$r['avg_pct']; $h=$mx?(($v/$mx)*58):0; $x=$i*$bar_w+1; $y=59-$h; $w=max(0.5,$bar_w-2); printf('<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f"></rect>',$x,$y,$w,$h); $i++; } ?>
            </g>
          </svg>
          <div class="bmfd-table-wrap">
            <table class="bmfd-table" role="table"><thead><tr><th>Date</th><th class="bmfd-right">Avg %</th><th class="bmfd-right">Min %</th><th class="bmfd-right">Max %</th></tr></thead>
              <tbody>
                <?php foreach($rows as $r): ?>
                  <tr>
                    <td><?php echo esc_html($r['spo2_date']); ?></td>
                    <td class="bmfd-right"><?php echo esc_html(number_format_i18n((float)$r['avg_pct'],1)); ?></td>
                    <td class="bmfd-right"><?php echo esc_html(number_format_i18n((float)$r['min_pct'],1)); ?></td>
                    <td class="bmfd-right"><?php echo esc_html(number_format_i18n((float)$r['max_pct'],1)); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="bmfd-muted">Source: Fitbit SpO2 nightly summary.</div>
        </div>
        <?php return ob_get_clean(); }

    /* ================= HRV (nightly) ================= */
    public function sc_hrv_card($atts){
        $a = shortcode_atts(array('days'=>'30','user_id'=>'','title'=>'Heart Rate Variability (last {days} nights)'), $atts, 'bm_fitbit_hrv_card');
        $uid=$this->resolve_user($a['user_id']); if(!$uid) return '<div class="bmfd-warn">Please log in to view HRV.</div>';
        $days=max(1, min(180, absint($a['days'])));
        $title=str_replace('{days}', $days, sanitize_text_field($a['title']));
        global $wpdb; $t=$this->tables['hrv'];
        $start=date('Y-m-d', strtotime(current_time('Y-m-d').' -'.($days-1).' days'));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT hrv_date, daily_rmssd_ms, deep_rmssd_ms FROM {$t} WHERE wp_user_id=%d AND hrv_date BETWEEN %s AND %s ORDER BY hrv_date DESC", $uid,$start,current_time('Y-m-d')), ARRAY_A);
        if(!$rows) return '<div class="bmfd-card"><h3>'.esc_html($title).'</h3><div class="bmfd-muted">No HRV values found for the last '.$days.' nights.</div></div>';
        $dvals=array(); foreach($rows as $r){ $dvals[]=(float)$r['daily_rmssd_ms']; }
        $avg=round(array_sum($dvals)/max(1,count($dvals)),1);
        $mx = max($dvals); if(!$mx) $mx=1; $bar_w=100/max(1,count($rows));
        ob_start(); ?>
        <div class="bmfd-card">
          <h3><?php echo esc_html($title); ?></h3>
          <div class="bmfd-kpis"><div class="bmfd-kpi"><strong>Avg RMSSD:</strong> <?php echo esc_html(number_format_i18n($avg,1)); ?> ms</div></div>
          <svg class="bmfd-mini-bars" viewBox="0 0 100 60" preserveAspectRatio="none" aria-label="HRV RMSSD by night">
            <g class="bmfd-bars">
              <?php $i=0; foreach($rows as $r){ $v=(float)$r['daily_rmssd_ms']; $h=$mx?(($v/$mx)*58):0; $x=$i*$bar_w+1; $y=59-$h; $w=max(0.5,$bar_w-2); printf('<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f"></rect>',$x,$y,$w,$h); $i++; } ?>
            </g>
          </svg>
          <div class="bmfd-table-wrap">
            <table class="bmfd-table" role="table"><thead><tr><th>Date</th><th class="bmfd-right">Daily RMSSD (ms)</th><th class="bmfd-right">Deep RMSSD (ms)</th></tr></thead>
              <tbody>
                <?php foreach($rows as $r): ?>
                  <tr><td><?php echo esc_html($r['hrv_date']); ?></td><td class="bmfd-right"><?php echo esc_html(number_format_i18n((float)$r['daily_rmssd_ms'],1)); ?></td><td class="bmfd-right"><?php echo esc_html(number_format_i18n((float)$r['deep_rmssd_ms'],1)); ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="bmfd-muted">Source: Fitbit HRV summary (main sleep).</div>
        </div>
        <?php return ob_get_clean(); }

    /* ================= Dashboard ================= */
    public function sc_dashboard($atts){
        $a = shortcode_atts(array('days'=>'7','date'=>'today','user_id'=>'','title'=>''), $atts, 'bm_fitbit_dashboard');
        $uid=$this->resolve_user($a['user_id']);
        ob_start();
        echo '<div class="bmfd-grid">';
        echo do_shortcode('[bm_fitbit_sleep_card days="'.esc_attr($a['days']).'" user_id="'.esc_attr($uid).'"]');
        echo do_shortcode('[bm_fitbit_activity_card days="'.esc_attr($a['days']).'" user_id="'.esc_attr($uid).'"]');
        echo do_shortcode('[bm_fitbit_rhr_card days="30" user_id="'.esc_attr($uid).'"]');
        echo do_shortcode('[bm_fitbit_spo2_card days="30" user_id="'.esc_attr($uid).'"]');
        echo do_shortcode('[bm_fitbit_hrv_card days="30" user_id="'.esc_attr($uid).'"]');
        echo do_shortcode('[bm_fitbit_hr_card date="'.esc_attr($a['date']).'" user_id="'.esc_attr($uid).'"]');
        echo '</div>';
        return ob_get_clean();
    }

    /* ================= Sync Now ================= */
    public function sc_sync_now($atts){
        $a = shortcode_atts(array('metrics'=>'hr,rhr,spo2,hrv,sleep,activity','user_id'=>'','label'=>'Sync now'), $atts, 'bm_fitbit_sync_now');
        
     
        $viewer = get_current_user_id(); if(!$viewer) return '<div class="bmfd-warn">Please log in to sync.</div>';
        $target = absint($a['user_id']); if(!($target && current_user_can('list_users'))) $target=$viewer;
        $metrics = array_map('strtolower', array_map('trim', explode(',', $a['metrics'])));
        $metrics = array_values(array_intersect($metrics, array('hr','rhr','spo2','hrv','sleep','activity')));
        if (empty($metrics)) $metrics=array('hr');

        $handle='bm-fitbit-sync-now';
        wp_register_script($handle, false, array('jquery'), '1.2.3', true);
        $ajax_url = admin_url('admin-ajax.php');
        $nonce    = wp_create_nonce('bm_fitbit_sync_now');
        $target_i = (int)$target;
        $metrics_json = wp_json_encode($metrics);

        $js = <<<'JS'
jQuery(function($){
  $(document).on('click','.bmfd-sync-btn',function(e){
    e.preventDefault();
    var $b=$(this);
    if($b.prop('disabled')) return;
    $b.prop('disabled',true).addClass('is-loading');

    var w=$b.closest('.bmfd-sync-wrap');
    w.find('.bmfd-sync-msg').text('Starting sync...');

    $.post('__AJAX_URL__',{
      action:'bm_fitbit_sync_now',
      _wpnonce:'__NONCE__',
      user_id: __TARGET__,
      metrics: __METRICS__
    }).done(function(r){
      if(r && r.success){
        var m=[];
        if(r.data && r.data.results){
          for(var k in r.data.results){ m.push(k+': '+r.data.results[k]); }
        }
        w.find('.bmfd-sync-msg').text('Sync complete. '+m.join(' - '));
      } else {
        var msg=(r && r.data && r.data.message)?r.data.message:'Unexpected error.';
        w.find('.bmfd-sync-msg').text('Sync failed: '+msg);
      }
    }).fail(function(){
      w.find('.bmfd-sync-msg').text('Network error while syncing.');
    }).always(function(){
      $b.prop('disabled',false).removeClass('is-loading');
    });
  });
});
JS;
        $js = str_replace(
          array('__AJAX_URL__','__NONCE__','__TARGET__','__METRICS__'),
          array(esc_js($ajax_url), esc_js($nonce), $target_i, $metrics_json),
          $js
        );

        wp_add_inline_script($handle, $js);
        wp_enqueue_script($handle);

        $css = '.bmfd-sync-wrap{display:flex;align-items:center;gap:10px;flex-wrap:wrap}' .
               '.bmfd-sync-btn{background:#111827;color:#fff;border:none;border-radius:8px;padding:10px 14px;cursor:pointer}' .
               '.bmfd-sync-btn.is-loading{opacity:.7;cursor:wait}' .
               '.bmfd-sync-msg{color:#374151;font-size:.95rem}';
        wp_add_inline_style('bm-fitbit-display', $css);

        $scheme = is_ssl() ? 'https://' : 'http://';
        $current_url = $scheme . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $redirect = esc_url( remove_query_arg(array('bm_fitbit_sync','hr','rhr','spo2','hrv','sleep','activity'), $current_url) );
        $nonce_post = wp_create_nonce('bm_fitbit_sync_now_post');

        $status_msg='';
        if (isset($_GET['bm_fitbit_sync'])){
            $s = sanitize_text_field($_GET['bm_fitbit_sync']);
            if ($s==='done'){
                $bits=array(); foreach(array('hr','rhr','spo2','hrv','sleep','activity') as $k){ if(isset($_GET[$k])) $bits[]=$k.': '.esc_html(sanitize_text_field($_GET[$k])); }
                $status_msg = '<span class="bmfd-sync-msg">Sync complete. '.implode(' - ', $bits).'</span>';
            } elseif ($s==='rate_limited'){
                $status_msg = '<span class="bmfd-sync-msg">Please wait a minute before syncing again.</span>';
            } elseif ($s==='error'){
                $reason = isset($_GET['reason'])? esc_html(sanitize_text_field($_GET['reason'])) : 'unknown';
                $status_msg = '<span class="bmfd-sync-msg">Sync failed: '.$reason.'</span>';
            }
        }

        ob_start(); ?>
        <div class="bmfd-sync-wrap" data-user="<?php echo esc_attr($target); ?>">
            <button class="bmfd-sync-btn"><?php echo esc_html($a['label']); ?></button>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php?action=bm_fitbit_sync_now_post') ); ?>" style="display:inline;margin:0;">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_post); ?>" />
                <input type="hidden" name="_redirect" value="<?php echo esc_attr($redirect); ?>" />
                <input type="hidden" name="user_id" value="<?php echo esc_attr($target); ?>" />
                <?php foreach($metrics as $m): ?><input type="hidden" name="metrics[]" value="<?php echo esc_attr($m); ?>" /><?php endforeach; ?>
                <button class="bmfd-sync-btn" type="submit">Sync now (fallback)</button>
            </form>
            <?php echo $status_msg ? $status_msg : '<span class="bmfd-sync-msg"></span>'; ?>
        </div>
        <?php return ob_get_clean(); }

    public function handle_sync_now_post_denied(){ $redirect = isset($_POST['_redirect']) ? esc_url_raw($_POST['_redirect']) : home_url('/'); wp_safe_redirect( wp_login_url($redirect) ); exit; }

    public function handle_sync_now_post(){
      error_log('[BM FITBIT] POST entry point: raw POST = ' . substr(print_r($_POST, true), 0, 500));  
        if (!is_user_logged_in()) $this->handle_sync_now_post_denied();
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'bm_fitbit_sync_now_post')) wp_die('Bad nonce', 403);
        $viewer = get_current_user_id();
        $target = isset($_POST['user_id'])? absint($_POST['user_id']) : $viewer;
        if ($target && !current_user_can('list_users') && $target!==$viewer) $target=$viewer;


        // Debug
        error_log('[BM FITBIT] POST handler start.');

        $metrics = (isset($_POST['metrics']) && is_array($_POST['metrics']))
            ? $_POST['metrics']
            : array('hr');


        $metrics = array_values(array_intersect(array_map('strtolower',$metrics), array('hr','rhr','spo2','hrv','sleep','activity')));
        if (empty($metrics)) $metrics=array('hr');
       
        if (!function_exists('bm_fitbit_sync_hr_window') || !function_exists('bm_fitbit_sync_sleep_window') || !function_exists('bm_fitbit_sync_activity_window')){
            $dest = add_query_arg(array('bm_fitbit_sync'=>'error','reason'=>'integration_missing'), isset($_POST['_redirect'])? $_POST['_redirect'] : home_url('/'));
            wp_safe_redirect($dest); exit;
        }
        $key='bm_fitbit_sync_throttle_'.$target; if(get_transient($key)){ $dest=add_query_arg(array('bm_fitbit_sync'=>'rate_limited'), isset($_POST['_redirect'])? $_POST['_redirect'] : home_url('/')); wp_safe_redirect($dest); exit; }
        set_transient($key,1,60);
        $res=array(); foreach($metrics as $m){
error_log('[BM FITBIT] Post '.$m);            
            switch($m){
                case 'hr':      $ok = bm_fitbit_sync_hr_window((int)$target);        $res['hr']  = $ok?'ok':'no_update'; break;
                case 'rhr':     $ok = function_exists('bm_fitbit_sync_rhr_window')? bm_fitbit_sync_rhr_window((int)$target) : false; $res['rhr'] = $ok?'ok':'no_update'; break;
                case 'spo2':    $ok = function_exists('bm_fitbit_sync_spo2_window')?bm_fitbit_sync_spo2_window((int)$target): false; $res['spo2']= $ok?'ok':'no_update'; break;
                case 'hrv':     $ok = function_exists('bm_fitbit_sync_hrv_window')? bm_fitbit_sync_hrv_window((int)$target) : false; $res['hrv'] = $ok?'ok':'no_update'; break;
                case 'sleep':   $ok = bm_fitbit_sync_sleep_window((int)$target);     $res['sleep']= $ok?'ok':'no_update'; break;
                case 'activity':$ok = bm_fitbit_sync_activity_window((int)$target);  $res['activity']=$ok?'ok':'no_update'; break;
            }
        }
        $dest = add_query_arg(array(
            'bm_fitbit_sync'=>'done',
            'hr'      => isset($res['hr'])?$res['hr']:'',
            'rhr'     => isset($res['rhr'])?$res['rhr']:'',
            'spo2'    => isset($res['spo2'])?$res['spo2']:'',
            'hrv'     => isset($res['hrv'])?$res['hrv']:'',
            'sleep'   => isset($res['sleep'])?$res['sleep']:'',
            'activity'=> isset($res['activity'])?$res['activity']:'',
        ), isset($_POST['_redirect'])? $_POST['_redirect'] : home_url('/'));
        wp_safe_redirect($dest); exit;
    }

    public function ajax_sync_now_denied(){ wp_send_json_error(array('message'=>'Please log in.'), 401); }

    public function ajax_sync_now(){
    error_log('[BM FITBIT] Ajax entry point: raw POST = ' . substr(print_r($_POST, true), 0, 500));    
        if (!is_user_logged_in() || !check_ajax_referer('bm_fitbit_sync_now', '_wpnonce', false)) wp_send_json_error(array('message'=>'Unauthorized.'), 403);
        $viewer=get_current_user_id();
        $target = isset($_POST['user_id'])? absint($_POST['user_id']) : $viewer;
        if ($target && !current_user_can('list_users') && $target!==$viewer) $target=$viewer;
        if (!$target) wp_send_json_error(array('message'=>'Missing user.'),400);
        $key='bm_fitbit_sync_throttle_'.$target; if(get_transient($key)) wp_send_json_error(array('message'=>'Please wait a minute before syncing again.'),429); set_transient($key,1,60);

        $metrics = array('hr');


        // --- Debug: see what arrived
        error_log('[BM FITBIT] Ajax handler start. raw metrics=' . (isset($_POST['metrics']) ? substr(print_r($_POST['metrics'], true), 0, 300) : 'NONE'));

        // Normalize metrics from JSON string OR array
        $metrics = array('hr');

        if (isset($_POST['metrics'])) {
            if (is_string($_POST['metrics'])) {
                $decoded = json_decode(wp_unslash($_POST['metrics']), true);
                if (is_array($decoded)) {
                    $metrics = $decoded;
                }
            } elseif (is_array($_POST['metrics'])) {
                $metrics = $_POST['metrics'];
            }
        }

        $metrics = array_values(array_intersect(
            array_map('strtolower', $metrics),
            array('hr','rhr','spo2','hrv','sleep','activity')
        ));
        if (empty($metrics)) $metrics = array('hr');
        
        if (!function_exists('bm_fitbit_sync_hr_window') || !function_exists('bm_fitbit_sync_sleep_window') || !function_exists('bm_fitbit_sync_activity_window')) wp_send_json_error(array('message'=>'Fitbit integration not available.'), 500);
        $res=array(); foreach($metrics as $m){
error_log('[BM FITBIT] Ajax '.$m);
            switch($m){
                case 'hr':      $ok = bm_fitbit_sync_hr_window((int)$target);        $res['hr']  = $ok?'OK':'no update'; break;
                case 'rhr':     $ok = function_exists('bm_fitbit_sync_rhr_window')? bm_fitbit_sync_rhr_window((int)$target) : false; $res['rhr'] = $ok?'OK':'no update'; break;
                case 'spo2':    $ok = function_exists('bm_fitbit_sync_spo2_window')?bm_fitbit_sync_spo2_window((int)$target): false; $res['spo2']= $ok?'OK':'no update'; break;
                case 'hrv':     $ok = function_exists('bm_fitbit_sync_hrv_window')? bm_fitbit_sync_hrv_window((int)$target) : false; $res['hrv'] = $ok?'OK':'no update'; break;
                case 'sleep':   $ok = bm_fitbit_sync_sleep_window((int)$target);     $res['sleep']= $ok?'OK':'no update'; break;
                case 'activity':$ok = bm_fitbit_sync_activity_window((int)$target);  $res['activity']=$ok?'OK':'no update'; break;
            }
        }
        wp_send_json_success(array('results'=>$res));
    }
}



/**
 * Front-end TEST sync trigger
 * Visit: /fitbit/?bm_fitbit_test_sync=1&_wpnonce=XXXX
 */
add_action('template_redirect', function () {

    // Only run when explicitly requested
    if (empty($_GET['bm_fitbit_test_sync'])) {
        return;
    }

    // Must be logged in
    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url(home_url('/fitbit')));
        exit;
    }

    // Nonce check
    $nonce = $_GET['_wpnonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'bm_fitbit_test_sync')) {
        wp_die('Invalid sync nonce', 403);
    }

    $user_id = get_current_user_id();

    error_log('[BM FITBIT TEST] Front-end sync started for user '.$user_id);
    if (function_exists('bm_fitbit_log_token_state')) {
      bm_fitbit_log_token_state('front-end test sync');
    }

    // ---- HARD CALLS (no switches, no Ajax, no indirection) ----
    if (function_exists('bm_fitbit_sync_hr_window')) {
        error_log('[BM FITBIT TEST] Calling HR intraday');
        bm_fitbit_sync_hr_window($user_id);
    }

    if (function_exists('bm_fitbit_sync_rhr_window')) {
        error_log('[BM FITBIT TEST] Calling RHR');
        bm_fitbit_sync_rhr_window($user_id);
    }

    if (function_exists('bm_fitbit_sync_spo2_window')) {
        error_log('[BM FITBIT TEST] Calling SpO2');
        bm_fitbit_sync_spo2_window($user_id);
    }

    if (function_exists('bm_fitbit_sync_hrv_window')) {
        error_log('[BM FITBIT TEST] Calling HRV');
        bm_fitbit_sync_hrv_window($user_id);
    }

    if (function_exists('bm_fitbit_sync_sleep_window')) {
        error_log('[BM FITBIT TEST] Calling Sleep');
        bm_fitbit_sync_sleep_window($user_id);
    }

    if (function_exists('bm_fitbit_sync_activity_window')) {
        error_log('[BM FITBIT TEST] Calling Activity');
        bm_fitbit_sync_activity_window($user_id);
    }

    error_log('[BM FITBIT TEST] Front-end sync finished for user '.$user_id);

    // Redirect back to Fitbit page with a flag
    wp_safe_redirect(add_query_arg('bm_fitbit_test_sync', 'done', home_url('/fitbit')));
    exit;
});



/**
 * [bm_fitbit_test_sync_button]
 */
add_shortcode('bm_fitbit_test_sync_button', function () {

    if (!is_user_logged_in()) {
        return '<em>Please log in to run sync.</em>';
    }

    $url = add_query_arg(
        array(
            'bm_fitbit_test_sync' => 1,
            '_wpnonce'            => wp_create_nonce('bm_fitbit_test_sync'),
        ),
        home_url('/fitbit')
    );

    return '<a class="button button-primary" href="'.esc_url($url).'">Run Fitbit Test Sync (Front‑end)</a>';
});


BM_Fitbit_Display::instance();