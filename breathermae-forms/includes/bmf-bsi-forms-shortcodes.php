<?php
/**
 * Breathermae BSI – Form Score Shortcodes (reads final_sci_score in {prefix}bm_bsi_results)
 *
 * Resolves latest per-form user score, matches form lookup range,
 * and exposes Elementor-friendly shortcodes for form cards.
 *
 * Tables (prefix-aware):
 * {prefix}bm_bsi_results : id, user_email, results_date, F1..F9, final_sci_score (+ mscore, dscore, gscore, oscore)
 * {prefix}bm_bsi_form_lookup : form ranges + metadata to display on cards
 * {prefix}bm_forms : (for resolving form by id\slug\name)
 *
 * History support (mirrors RSI):
 *  - [bmf_bsi_history_select] renders a date dropdown
 *  - ?bsi_date=YYYY-MM-DD switches all “latest” queries to that finalized row
 *  - Snapshot mode continues to ignore the date param (rolling non-empty)
 *
 * Cache invalidation hook (fire this right after saving/updating results):
 * do_action('bmf_bsi_results_updated', $user_id, $form_id);
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Reuse DB accessor if already declared; else define here. */
if ( ! class_exists( 'BMF_BSI_DBX' ) ) {
    class BMF_BSI_DBX {
        /** @var wpdb */
        public static $db;
        public static function init() { global $wpdb; self::$db = $wpdb; }
        public static function t( $suffix ) { return self::$db->prefix . $suffix; }
    }
    BMF_BSI_DBX::init();
} else {
    if ( empty( BMF_BSI_DBX::$db ) ) { BMF_BSI_DBX::init(); }
}

/** Resolve form_id by id\slug\name. */
class BMF_BSI_FormId_Resolver {
    public static function resolve( $form ) {
        if ( is_numeric( $form ) ) { return (int) $form; }
        $db = BMF_BSI_DBX::$db; $t_f = BMF_BSI_DBX::t('bm_forms'); $f = trim( (string) $form );
        // Try slug
        $row = $db->get_row( $db->prepare("SELECT id FROM {$t_f} WHERE slug = %s LIMIT 1", $f), ARRAY_A );
        if ( $row && ! empty( $row['id'] ) ) return (int) $row['id'];
        // Exact title
        $row = $db->get_row( $db->prepare("SELECT id FROM {$t_f} WHERE title = %s LIMIT 1", $f), ARRAY_A );
        if ( $row && ! empty( $row['id'] ) ) return (int) $row['id'];
        return null;
    }
    /** Map a form to its F-index (1..9) for selecting F1..F9 in results. */
    public static function get_form_index( $form_id ) {
        $form_id = (int) $form_id; if ( $form_id >= 1 && $form_id <= 9 ) { return $form_id; }
        $db = BMF_BSI_DBX::$db; $t_f = BMF_BSI_DBX::t('bm_forms');
        $row = $db->get_row( $db->prepare("SELECT name, slug FROM {$t_f} WHERE id = %d LIMIT 1", $form_id), ARRAY_A );
        if ( ! $row ) return null;
        $name = isset($row['name']) ? strtolower( trim( (string)$row['name'] ) ) : '';
        $slug = isset($row['slug']) ? strtolower( trim( (string)$row['slug'] ) ) : '';
        $name_index = [
            'biological strain' => 1,
            'metabolic flexibility' => 2,
            'inflammatory load' => 3,
            'digestion & assimilation efficiency' => 4,
            'digestion and assimilation efficiency' => 4,
            'neural-emotional load' => 5,
            'neural emotional load' => 5,
            'recovery & resilience capacity' => 6,
            'recovery and resilience capacity' => 6,
            'environmental load' => 7,
            'lifestyle alignment' => 8,
            'adaptive capacity' => 9,
        ];
        $slug_index = [
            'biological-strain' => 1,
            'metabolic-flexibility' => 2,
            'inflammatory-load' => 3,
            'digestion-assimilation-efficiency' => 4,
            'neural-emotional-load' => 5,
            'recovery-resilience-capacity' => 6,
            'environmental-load' => 7,
            'lifestyle-alignment' => 8,
            'adaptive-capacity' => 9,
        ];
        if ( $slug && isset( $slug_index[$slug] ) ) return (int) $slug_index[$slug];
        if ( $name && isset( $name_index[$name] ) ) return (int) $name_index[$name];
        return null;
    }
}

/** Data access + resolution for form scores and form lookup. */
class BMF_BSI_Form_Service {
    /**
     * Normalize any incoming date string (with or without time) to pure Y-m-d.
     * Handles values coming from MySQL DATE/DATETIME columns and URL encoding.
     */
    public static function normalize_date_str( $date_str ) {
        if ( empty( $date_str ) ) return null;
        $date_str = sanitize_text_field( $date_str );
        // Strip time portion if present (e.g. "2026-05-17 00:00:00" or "2026-05-17+00:00:00")
        if ( preg_match('/^(\d{4}-\d{2}-\d{2})/', $date_str, $m) ) {
            return $m[1];
        }
        // Fallback – try strtotime
        $ts = strtotime( $date_str );
        return ( $ts !== false ) ? date( 'Y-m-d', $ts ) : null;
    }

    /**
     * Results row for a user.
     * - When $date_str is provided: exact match or same-day, always is_final = 1
     * - When $date_str is null: most recent is_final = 1 row
     */
    public static function get_results_row_for_user( $user_id, $date_str = null ) {
        $db = BMF_BSI_DBX::$db; $t_r = BMF_BSI_DBX::t('bm_bsi_results');
        $user = get_userdata( $user_id ); if ( ! $user || empty( $user->user_email ) ) return null;
        $email = $user->user_email;

        // Always normalize so "2026-05-17 00:00:00" becomes "2026-05-17"
        $date_str = self::normalize_date_str( $date_str );

        // --- 1. Exact date match, FINAL only ---
        if ( $date_str ) {
            $sql = $db->prepare(
                "SELECT * FROM {$t_r}
                 WHERE user_email = %s
                   AND results_date = %s
                   AND is_final = 1
                 ORDER BY id DESC
                 LIMIT 1",
                $email, $date_str
            );
            $row = $db->get_row( $sql, ARRAY_A );
            if ( $row ) return $row;

            // --- 2. Same-day FINAL (always safe after normalization) ---
            $sql = $db->prepare(
                "SELECT * FROM {$t_r}
                 WHERE user_email = %s
                   AND DATE(results_date) = %s
                   AND is_final = 1
                 ORDER BY results_date DESC, id DESC
                 LIMIT 1",
                $email, $date_str
            );
            $row = $db->get_row( $sql, ARRAY_A );
            if ( $row ) return $row;
        }

        // --- 3. Most recent FINAL result (default) ---
        $sql = $db->prepare(
            "SELECT * FROM {$t_r}
             WHERE user_email = %s
               AND is_final = 1
             ORDER BY results_date DESC, id DESC
             LIMIT 1",
            $email
        );
        $row = $db->get_row( $sql, ARRAY_A );
        return $row ?: null;
    }

    /**
     * Convenience wrapper used by all “latest” score methods.
     * Honors ?bsi_date=YYYY-MM-DD (or with time component) when present.
     */
    public static function get_latest_results_row_for_user( $user_id ) {
        $date_str = isset($_GET['bsi_date'])
            ? sanitize_text_field($_GET['bsi_date'])
            : null;
        return self::get_results_row_for_user( $user_id, $date_str );
    }

    /** Latest per-form score for a user (F1..F9, fallback final_sci_score). */
    public static function get_latest_user_form_score( $user_id, $form_id ) {
        $row = self::get_latest_results_row_for_user( $user_id ); if ( ! $row ) return null;
        $index = BMF_BSI_FormId_Resolver::get_form_index( $form_id ); $score = null;
        if ( $index !== null && $index >= 1 && $index <= 9 ) {
            $col = 'F' . $index; if ( array_key_exists( $col, $row ) && $row[$col] !== null ) { $score = (float) $row[$col]; }
        }
        if ( $score === null && isset($row['final_sci_score']) && $row['final_sci_score'] !== null ) { $score = (float) $row['final_sci_score']; }
        if ( $score === null ) return null;
        $pct = ($score <= 1.0) ? round($score * 100, 2) : round($score, 2);
        return [ 'score_percent' => (float) $pct, 'updated_at' => ! empty($row['results_date']) ? $row['results_date'] : null ];
    }

    /** Overall (final_sci_score) helper. */
    public static function get_latest_overall_score_for_user( $user_id ) {
        $row = self::get_latest_results_row_for_user( $user_id );
        if ( ! $row || ! array_key_exists('final_sci_score', $row) || $row['final_sci_score'] === null ) { return null; }
        $score = (float) $row['final_sci_score'];
        $pct = ($score <= 1.0) ? round($score * 100, 2) : round($score, 2);
        return [ 'score_percent' => (float) $pct, 'updated_at' => ! empty($row['results_date']) ? $row['results_date'] : null ];
    }

    /** Metric helper for mscore/dscore/gscore/oscore. */
    public static function get_latest_metric_score_for_user( $user_id, $metric_field ) {
        $metric = sanitize_key( $metric_field ); if ( $metric === '' ) return null;
        $cols = self::get_results_table_columns(); if ( empty( $cols[ $metric ] ) ) return null;
        $row = self::get_latest_results_row_for_user( $user_id );
        if ( ! $row || ! array_key_exists( $metric, $row ) || $row[$metric] === null ) { return null; }
        $score = (float) $row[$metric];
        $pct = ($score <= 1.0) ? round($score * 100, 2) : round($score, 2);
        return [ 'score_percent' => (float) $pct, 'updated_at' => ! empty($row['results_date']) ? $row['results_date'] : null ];
    }

    /** Metric snapshot: latest non-empty metric across all rows; normalized 0..100. */
    public static function get_metric_score_from_snapshot( $user_id, $metric_field ) {
        $metric = sanitize_key( $metric_field ); if ( $metric === '' ) return null;
        $db = BMF_BSI_DBX::$db; $t_r = BMF_BSI_DBX::t('bm_bsi_results');
        $user = get_userdata( $user_id ); if ( ! $user || empty( $user->user_email ) ) return null;
        $email = $user->user_email;
        $rows = $db->get_results( $db->prepare("SELECT * FROM {$t_r} WHERE user_email = %s AND is_final = 1 ORDER BY results_date DESC, id DESC", $email), ARRAY_A );
        if ( ! $rows ) return null;
        $updated = null;
        foreach ( $rows as $r ) {
            if ( array_key_exists($metric, $r) && $r[$metric] !== null && $r[$metric] !== '' && is_numeric($r[$metric]) ) {
                $val = (float) $r[$metric];
                if ( $val <= 0 ) continue; // treat 0/negatives as empty signal for snapshot
                $pct = ($val <= 1.0) ? round($val * 100, 2) : round($val, 2);
                $updated = ! empty($r['results_date']) ? $r['results_date'] : $updated;
                return [ 'score_percent' => (float)$pct, 'updated_at' => $updated ];
            }
        }
        return null;
    }

    /** Resolve form-level metadata by score range. */
    public static function resolve_form_lookup( $form_id, $score_percent ) {
        $db = BMF_BSI_DBX::$db; $t_lu = BMF_BSI_DBX::t('bm_bsi_form_lookup');
        $sql = $db->prepare(
            "SELECT form_title, form_text, form_focus, icon_url, form_color, Suggestions AS suggestions
             FROM {$t_lu}
             WHERE form_id = %d AND %f >= low_value AND %f < high_value
             ORDER BY id ASC LIMIT 1",
            $form_id, $score_percent, $score_percent
        );
        $row = $db->get_row( $sql, ARRAY_A );
        return $row ?: null;
    }

    /** Cache key for list of columns in {prefix}bm_bsi_results. */
    protected static function results_columns_cache_key() { return 'bmf_bsi_results_cols_cache'; }

    /** Returns associative array of valid column names (cached 12h). */
    public static function get_results_table_columns() {
        $cache_key = self::results_columns_cache_key();
        $cols = get_transient( $cache_key ); if ( is_array($cols) ) return $cols;
        $db = BMF_BSI_DBX::$db; $tbl = BMF_BSI_DBX::t('bm_bsi_results');
        $rows = $db->get_results( "SHOW COLUMNS FROM {$tbl}", ARRAY_A );
        $cols = []; if ( $rows ) { foreach ( $rows as $r ) { if ( ! empty( $r['Field'] ) ) $cols[ $r['Field'] ] = true; } }
        set_transient( $cache_key, $cols, 12 * HOUR_IN_SECONDS );
        return $cols;
    }

    /** Rolling latest-non-null snapshot across ALL rows for a user. */
    public static function get_latest_snapshot_for_user( $user_id ) {
        $db = BMF_BSI_DBX::$db; $t_r = BMF_BSI_DBX::t('bm_bsi_results');
        $user = get_userdata( $user_id ); if ( ! $user || empty( $user->user_email ) ) return null;
        $email = $user->user_email;
        $rows = $db->get_results( $db->prepare("SELECT * FROM {$t_r} WHERE user_email = %s AND is_final = 1 ORDER BY results_date DESC, id DESC", $email), ARRAY_A );
        if ( ! $rows ) return null;
        $snapshot = [ 'F1'=>null,'F2'=>null,'F3'=>null,'F4'=>null,'F5'=>null,'F6'=>null,'F7'=>null,'F8'=>null,'F9'=>null,
                      'updated_at'=>null, 'final_sci_score'=>null, 'have_count'=>0 ];
        $fill = 0; $maxDate = null;
        foreach ( $rows as $r ) {
            if ( ! empty($r['results_date']) ) { $d = $r['results_date']; if ( $maxDate === null || strcmp($d,$maxDate) > 0 ) $maxDate = $d; }
            for ( $i=1; $i<=9; $i++ ) {
                $col = 'F'.$i;
                if ( $snapshot[$col] !== null ) continue;
                if ( ! array_key_exists($col, $r) ) continue;
                $raw = $r[$col];
                if ( $raw === '' || $raw === null || ! is_numeric($raw) ) continue;
                $val = (float) $raw;
                if ( $val <= 0 ) continue;
                $normalized = ( $val <= 1.0 ) ? round($val * 100, 2) : round($val, 2);
                $snapshot[$col] = $normalized;
                $fill++;
            }
            if ( $fill >= 9 ) break;
        }
        $snapshot['have_count'] = $fill; $snapshot['updated_at'] = $maxDate;
        $all=[]; for($i=1;$i<=9;$i++){ if($snapshot['F'.$i]!==null){ $all[]=(float)$snapshot['F'.$i]; } }
        if ( ! empty($all) ) {
            if ( class_exists('BMF_BSI_Scorer') && method_exists('BMF_BSI_Scorer','compute_overall_from_pillars') ) {
                $snapshot['final_sci_score'] = (float) BMF_BSI_Scorer::compute_overall_from_pillars($snapshot);
            } else {
                $snapshot['final_sci_score'] = array_sum($all) / count($all);
            }
        }
        return $snapshot;
    }

    /** Latest per-form score using snapshot. */
    public static function get_user_form_score_from_snapshot( $user_id, $form_id ) {
        $snap = self::get_latest_snapshot_for_user( $user_id ); if ( ! $snap ) return null;
        $index = BMF_BSI_FormId_Resolver::get_form_index( $form_id ); if ( $index === null ) return null;
        $col = 'F'.$index; if ( ! array_key_exists($col,$snap) || $snap[$col]===null ) return null;
        return [ 'score_percent'=>(float)$snap[$col], 'updated_at'=>$snap['updated_at'] ];
    }

    /** Latest overall score using snapshot. */
    public static function get_overall_score_from_snapshot( $user_id ) {
        $snap = self::get_latest_snapshot_for_user( $user_id );
        if ( ! $snap || $snap['final_sci_score'] === null ) return null;
        return [ 'score_percent'=>(float)$snap['final_sci_score'], 'updated_at'=>$snap['updated_at'], 'have_count'=>(int)$snap['have_count'] ];
    }
}

/** Form shortcodes for Elementor cards. */
class BMF_BSI_Form_Shortcodes {
    public static function init() {
        add_shortcode( 'bmf_bsi_form',          [ __CLASS__, 'shortcode_form' ] );
        add_shortcode( 'bmf_bsi_form_icon',     [ __CLASS__, 'shortcode_form_icon' ] );
        add_shortcode( 'bmf_bsi_form_gauge',    [ __CLASS__, 'shortcode_form_gauge' ] );
        add_shortcode( 'bmf_bsi_results_field', [ __CLASS__, 'shortcode_results_field' ] );
        add_shortcode( 'bmf_bsi_history_select',[ __CLASS__, 'shortcode_history_select' ] );
        add_action( 'bmf_bsi_results_updated', [ __CLASS__, 'invalidate_cache' ], 10, 2 );
    }

    /**
     * [bmf_bsi_history_select]
     * Renders a dropdown of finalized BSI assessment dates.
     * Selecting a date reloads the page with ?bsi_date=YYYY-MM-DD
     */
    public static function shortcode_history_select($atts) {
        if (!is_user_logged_in()) return '';

        $user  = wp_get_current_user();
        $email = $user->user_email ?? '';
        if (!$email) return '';

        $dates = BMF_Repository::get_bsi_result_dates($email);
        if (empty($dates)) return '';

        // Normalize selected value so it matches the clean option values
        $selected = isset($_GET['bsi_date'])
            ? BMF_BSI_Form_Service::normalize_date_str( $_GET['bsi_date'] )
            : '';

        ob_start();
        ?>
        <div class="bmf-bsi-history-select" style="margin-bottom:10px; font-size:0.9rem; color:#001d50; display:flex; align-items:center; gap:8px;">
            <b style="white-space:nowrap;">Assessment Date:</b>
            <select id="bmf_bsi_date_select" style="padding:4px 8px; font-size:0.9rem; border:1px solid #001d50; border-radius:4px; width:150px;">
                <?php foreach ($dates as $d):
                    // Always emit a pure Y-m-d value in the option
                    $clean = BMF_BSI_Form_Service::normalize_date_str( $d );
                    if ( ! $clean ) continue;
                ?>
                    <option value="<?php echo esc_attr($clean); ?>" <?php selected($selected, $clean); ?>>
                        <?php echo esc_html(date('M j, Y', strtotime($clean))); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var el = document.getElementById('bmf_bsi_date_select');
            if (!el) return;

            el.addEventListener('change', function() {
                var selected = this.value;
                var url = new URL(window.location.href);

                if (selected) {
                    url.searchParams.set('bsi_date', selected);
                } else {
                    url.searchParams.delete('bsi_date');
                }

                window.location.href = url.toString();
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    private static function should_bail_for_editor(): bool {
        $disable = apply_filters('bmf/shortcodes/disable_in_elementor', true); if (!$disable) return false;
        return function_exists('bmf_in_elementor_editor') && bmf_in_elementor_editor();
    }
    public static function invalidate_cache( $user_id, $form_id = null ) {
        $user_id = (int) $user_id;
        delete_transient( self::cache_key($user_id, 0, 'snapshot') );
        delete_transient( self::cache_key($user_id, 0, 'latest') );
        if ( $form_id ) {
            delete_transient( self::cache_key($user_id, (int)$form_id, 'snapshot') );
            delete_transient( self::cache_key($user_id, (int)$form_id, 'latest') );
        }
    }
    private static function cache_key( $user_id, $form_id, $mode = 'snapshot' ) {
        $mode = $mode ? strtolower((string)$mode) : 'snapshot';
        return "bmf_bsi_form_{$user_id}_{$form_id}_{$mode}";
    }

    /**
     * [bmf_bsi_form form="biological-strain|1|overall|0" field="score|form_title|form_text|form_focus|icon_url|form_color|updated_at|suggestions"
     *  user_id="" cache_ttl="600" colorize="0" mode="snapshot|latest"]
     */
    public static function shortcode_form( $atts ) {
        if (self::should_bail_for_editor()) return '';
        $atts = shortcode_atts( [
            'form'      => '',
            'field'     => 'score',
            'user_id'   => get_current_user_id(),
            'cache_ttl' => 600,
            'colorize'  => '0',
            'mode'      => 'latest',
        ], $atts, 'bmf_bsi_form' );
        $user_id = (int) $atts['user_id']; if ( ! $user_id ) return '';
        // Resolve form from attr or querystring
        $raw_attr = (string) $atts['form'];
        $form_raw = function_exists('bmf_resolve_form_from_atts_or_query') ? bmf_resolve_form_from_atts_or_query($raw_attr) : trim($raw_attr);
        $form_lower = strtolower( $form_raw );
        $is_overall = ( $form_lower === 'overall' ) || ( is_numeric($form_raw) && (int)$form_raw === 0 );
        $mode = strtolower( (string)$atts['mode'] ); if ($mode !== 'latest') $mode = 'latest';
        $form_id = $is_overall ? 0 : BMF_BSI_FormId_Resolver::resolve( $form_raw ); if ( $form_id === null ) return '';

        // Include bsi_date in cache key so historical views do not collide with “latest”
        $date_str = isset($_GET['bsi_date'])
            ? BMF_BSI_Form_Service::normalize_date_str( $_GET['bsi_date'] )
            : '';
        $ckey = self::cache_key( $user_id, $form_id, $mode . '_' . ($date_str ?: 'latest') );
        $ttl  = max(0, (int)$atts['cache_ttl']);
        $data = get_transient( $ckey );
        if ( ! is_array( $data ) ) {
            if ( $is_overall ) {
                $res = ($mode === 'latest') ? BMF_BSI_Form_Service::get_latest_overall_score_for_user( $user_id )
                                            : BMF_BSI_Form_Service::get_overall_score_from_snapshot( $user_id );
                if ( ! $res ) {
                    $data = [ 'score'=>'', 'form_title'=>'', 'form_text'=>'', 'form_focus'=>'', 'icon_url'=>'', 'form_color'=>'', 'updated_at'=>'', 'suggestions'=>'' ];
                } else {
                    $score = $res['score_percent'];
                    $meta  = BMF_BSI_Form_Service::resolve_form_lookup( 0, (float)$score ) ?: [];
                    $data = [
                        'score'       => is_numeric($score) ? (float)$score : '',
                        'form_title'  => $meta['form_title']  ?? '',
                        'form_text'   => $meta['form_text']   ?? '',
                        'form_focus'  => $meta['form_focus']  ?? '',
                        'icon_url'    => $meta['icon_url']    ?? '',
                        'form_color'  => $meta['form_color']  ?? '',
                        'suggestions' => $meta['suggestions'] ?? '',
                        'updated_at'  => ! empty($res['updated_at']) ? substr($res['updated_at'], 0, 10) : '',
                    ];
                }
            } else {
                $res = ($mode === 'latest') ? BMF_BSI_Form_Service::get_latest_user_form_score( $user_id, $form_id )
                                            : BMF_BSI_Form_Service::get_user_form_score_from_snapshot( $user_id, $form_id );
                if ( ! $res ) {
                    $data = [ 'score'=>'', 'form_title'=>'', 'form_text'=>'', 'form_focus'=>'', 'icon_url'=>'', 'form_color'=>'', 'updated_at'=>'', 'suggestions'=>'' ];
                } else {
                    $score = $res['score_percent'];
                    $meta  = BMF_BSI_Form_Service::resolve_form_lookup( $form_id, (float)$score ) ?: [];
                    $data = [
                        'score'       => is_numeric($score) ? (float)$score : '',
                        'form_title'  => $meta['form_title']  ?? '',
                        'form_text'   => $meta['form_text']   ?? '',
                        'form_focus'  => $meta['form_focus']  ?? '',
                        'icon_url'    => $meta['icon_url']    ?? '',
                        'form_color'  => $meta['form_color']  ?? '',
                        'suggestions' => $meta['suggestions'] ?? '',
                        'updated_at'  => ! empty($res['updated_at']) ? substr($res['updated_at'], 0, 10) : '',
                    ];
                }
            }
            if ( $ttl > 0 ) set_transient( $ckey, $data, $ttl );
        }

        $field = sanitize_key( $atts['field'] ); $val = isset( $data[$field] ) ? $data[$field] : '';
        if ( $field === 'form_title' && (int)$atts['colorize'] === 1 ) {
            $col = $data['form_color'] ?? ''; if ( $col !== '' && $val !== '' ) { return '<span style="color:' . esc_attr($col) . '">' . esc_html((string)$val) . '</span>'; }
        }
        if ( $field === 'suggestions' ) {
            $allowed = [
                'a'   => [ 'href'=>true, 'target'=>true, 'rel'=>true, 'class'=>true ],
                'img' => [ 'src'=>true, 'alt'=>true, 'width'=>true, 'height'=>true, 'loading'=>true, 'decoding'=>true, 'referrerpolicy'=>true, 'sizes'=>true, 'srcset'=>true, 'class'=>true, 'style'=>true ],
                'br'  => [], 'p'=>['class'=>true,'style'=>true], 'ul'=>['class'=>true], 'ol'=>['class'=>true], 'li'=>['class'=>true],
                'strong'=>[], 'em'=>[], 'b'=>[], 'i'=>[], 'span'=>['class'=>true,'style'=>true],
            ];
            return wp_kses( (string) $val, $allowed );
        }
        return esc_html( (string) $val );
    }

    /** Icon */
    public static function shortcode_form_icon( $atts ) {
        if (self::should_bail_for_editor()) return '';
        $atts = shortcode_atts( [
            'form'         => '',
            'user_id'      => get_current_user_id(),
            'size'         => '24', 'shape' => 'diamond', 'stroke_width'=>'2', 'class'=>'', 'title'=>'',
            'outline_color'=>'#000000', 'outline_width'=>'0',
            // value on icon
            'show_value'=>'0', 'value_font_size'=>'11', 'value_color'=>'#FFFFFF', 'value_weight'=>'600', 'value_offset_y'=>'0',
            // snapshot vs latest
            'mode' => 'latest',
        ], $atts, 'bmf_bsi_form_icon' );
        $user_id = (int) $atts['user_id']; if ( ! $user_id ) return '';
        $mode = strtolower((string)$atts['mode']); if ($mode!=='latest') $mode='latest';
        $raw_attr = (string) $atts['form'];
        $form_raw = function_exists('bmf_resolve_form_from_atts_or_query') ? bmf_resolve_form_from_atts_or_query($raw_attr) : trim($raw_attr);
        $form_lower = strtolower( trim($form_raw) );
        $is_overall = ( $form_lower === 'overall' ) || ( is_numeric($form_raw) && (int)$form_raw === 0 );

        // Pull score + color via nested form shortcode (pass mode)
        $score_str = do_shortcode( sprintf('[bmf_bsi_form form="%s" field="score" user_id="%d" mode="%s"]', esc_attr($form_raw), $user_id, esc_attr($mode)) );
        $color     = do_shortcode( sprintf('[bmf_bsi_form form="%s" field="form_color" user_id="%d" mode="%s"]', esc_attr($form_raw), $user_id, esc_attr($mode)) );
if ($score_str === '') {
    $size = max(8, (int)$atts['size']);
    return sprintf(
        '<span class="bmf-bsi-form-icon-na" style="display:inline-block;width:%dpx;height:%dpx;line-height:%dpx;text-align:center;color:#808080;"><strong>N/A</strong></span>',
        $size,
        $size,
        $size
    );
}
        if ( empty($color) ) $color = '#cccccc';

        $size = max(8, (int)$atts['size']); $shape = strtolower($atts['shape']); $stroke_w = max(1,(int)$atts['stroke_width']);
        $class = trim((string)$atts['class']); $title = trim((string)$atts['title']);
        $classes = 'bmf-bsi-form-icon' . ( $class ? ' ' . sanitize_html_class($class) : '' );
        $outline_color = trim((string)$atts['outline_color']); $outline_w = max(0, (int)$atts['outline_width']);
        $half = $size/2; $svg_open = sprintf('<svg class="%s" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-hidden="%s" xmlns="http://www.w3.org/2000/svg">', esc_attr($classes), $size,$size,$size,$size, $title?'false':'true');
        $svg_title = $title ? '<title>' . esc_html($title) . '</title>' : '';
        $pad_edge = ($shape==='ring') ? $stroke_w : $outline_w; $shape_html='';
        switch($shape){
            case 'ring':{
                $r = max(1, $half - $stroke_w); $r = max(1, $r - ($pad_edge/2));
                $shape_html = sprintf('<circle cx="%1$d" cy="%1$d" r="%2$d" fill="none" stroke="%3$s" stroke-width="%4$d" />', $half,(int)$r,esc_attr($color),$stroke_w);
                break;
            }
            case 'square':{
                $rx=max(0,(int)round($size*0.12)); $x=$pad_edge; $y=$pad_edge; $w=max(1,$size-2*$pad_edge);
                if($outline_w>0){ $shape_html=sprintf('<rect x="%1$d" y="%2$d" width="%3$d" height="%3$d" rx="%4$d" ry="%4$d" fill="%5$s" stroke="%6$s" stroke-width="%7$d" />',(int)$x,(int)$y,(int)$w,(int)$rx,esc_attr($color),esc_attr($outline_color),$outline_w); }
                else { $shape_html=sprintf('<rect x="%1$d" y="%2$d" width="%3$d" height="%3$d" rx="%4$d" ry="%4$d" fill="%5$s" />',(int)$x,(int)$y,(int)$w,(int)$rx,esc_attr($color)); }
                break;
            }
            case 'circle':{
                $r=max(1,$half-$pad_edge);
                if($outline_w>0){ $shape_html=sprintf('<circle cx="%1$d" cy="%1$d" r="%2$d" fill="%3$s" stroke="%4$s" stroke-width="%5$d" />',$half,(int)$r,esc_attr($color),esc_attr($outline_color),$outline_w); }
                else { $shape_html=sprintf('<circle cx="%1$d" cy="%1$d" r="%2$d" fill="%3$s" />',$half,(int)$r,esc_attr($color)); }
                break;
            }
            case 'diamond': default:{
                $inner=max((int)$pad_edge,(int)round($size*0.1)); $x1=$half; $y1=$inner; $x2=$size-$inner; $y2=$half; $x3=$half; $y3=$size-$inner; $x4=$inner; $y4=$half;
                if($outline_w>0){ $shape_html=sprintf('<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" stroke="%10$s" stroke-width="%11$d" />',(int)$x1,(int)$y1,(int)$x2,(int)$y2,(int)$x3,(int)$y3,(int)$x4,(int)$y4,esc_attr($color),esc_attr($outline_color),$outline_w); }
                else { $shape_html=sprintf('<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" />',(int)$x1,(int)$y1,(int)$x2,(int)$y2,(int)$x3,(int)$y3,(int)$x4,(int)$y4,esc_attr($color)); }
                break; }
        }
        // Optional centered label
        $score_val = is_numeric($score_str) ? max(0,min(100,(float)$score_str)) : null;
        $label = ($score_val===null)?'':number_format((float)round($score_val),0,'.','');
        $svg_label='';
        if( (int)$atts['show_value']===1 && $label!==''){
            $fs=max(6,(int)$atts['value_font_size']); $fclr=trim((string)$atts['value_color']);
            $fw=preg_match('/^(100|200|300|400|500|600|700|800|900|bold|normal)$/',(string)$atts['value_weight'])?(string)$atts['value_weight']:'600';
            $offY=(int)$atts['value_offset_y']; $tx=(int)round($half); $ty=(int)round($half+$offY);
            $svg_label=sprintf('<text x="%d" y="%d" text-anchor="middle" dominant-baseline="middle" font-size="%d" font-weight="%s" fill="%s">%s</text>', $tx,$ty,$fs,esc_attr($fw),esc_attr($fclr),esc_html($label));
        }
        return $svg_open . $svg_title . $shape_html . $svg_label . '</svg>';
    }

    /** Gauge */
    public static function shortcode_form_gauge( $atts ) {
        if (self::should_bail_for_editor()) return '';
        $atts = shortcode_atts( [
            'form' => '', 'metric'=>'', 'user_id'=>get_current_user_id(),
            // layout
            'width'=>'280','height'=>'24','thickness'=>'6','radius'=>'3',
            // colors
            'bg'=>'#E6E9EF','fill_bg'=>'#CBD2E1',
            // marker
            'marker'=>'diamond','marker_size'=>'12','stroke_width'=>'2',
            'marker_outline_color'=>'#000000','marker_outline_width'=>'0',
            // misc
            'class'=>'','show_value'=>'0','title'=>'','value_font_size'=>'11','value_offset_y'=>'','value_offset_x'=>'',
            // snapshot vs latest
            'mode'=>'latest',
        ], $atts, 'bmf_bsi_form_gauge' );
        $user_id = (int)$atts['user_id']; if ( ! $user_id ) return '';
        $metric = strtolower( trim((string)$atts['metric']) );
        $mode = strtolower((string)$atts['mode']); if($mode!=='latest') $mode='latest';
        $raw_attr = (string)$atts['form'];
        $form_raw = function_exists('bmf_resolve_form_from_atts_or_query') ? bmf_resolve_form_from_atts_or_query($raw_attr) : trim($raw_attr);
        $form_lower = strtolower(trim($form_raw)); $is_overall = ( $form_lower==='overall' ) || ( is_numeric($form_raw) && (int)$form_raw===0 );

        // Determine score
        $score = null;
        if ( in_array($metric, ['mscore','dscore','gscore','oscore'], true) ) {
            $res = ($mode==='latest') ? BMF_BSI_Form_Service::get_latest_metric_score_for_user( $user_id, $metric )
                                      : BMF_BSI_Form_Service::get_metric_score_from_snapshot( $user_id, $metric );
            if ( $res ) $score = $res['score_percent'];
        } else {
            if ( ! $is_overall ) {
                $form_id = BMF_BSI_FormId_Resolver::resolve( $form_raw ); if ( ! $form_id ) return '';
                $res = ($mode==='latest') ? BMF_BSI_Form_Service::get_latest_user_form_score($user_id,$form_id)
                                          : BMF_BSI_Form_Service::get_user_form_score_from_snapshot($user_id,$form_id);
                if ( $res ) $score = $res['score_percent'];
            } else {
                $res = ($mode==='latest') ? BMF_BSI_Form_Service::get_latest_overall_score_for_user($user_id)
                                          : BMF_BSI_Form_Service::get_overall_score_from_snapshot($user_id);
                if ( $res ) $score = $res['score_percent'];
            }
        }
        if ( $score === null ) return '';

        // Determine color
        if ( in_array($metric, ['mscore','dscore','gscore','oscore'], true) ) {
            $color = do_shortcode( sprintf('[bmf_bsi_form form="overall" field="form_color" user_id="%d" mode="%s"]', $user_id, esc_attr($mode)) );
        } else {
            $color = do_shortcode( sprintf('[bmf_bsi_form form="%s" field="form_color" user_id="%d" mode="%s"]', esc_attr($form_raw), $user_id, esc_attr($mode)) );
        }
        if ( empty($color) ) $color = '#cccccc';

        // Geometry + styling
        $width=max(120,(int)$atts['width']); $height=max(16,(int)$atts['height']); $thickness=max(2,(int)$atts['thickness']); $radius=max(0,(int)$atts['radius']);
        $bg=trim((string)$atts['bg']); $fill_bg=trim((string)$atts['fill_bg']); $marker=strtolower(trim((string)$atts['marker'])); $marker_sz=max(6,(int)$atts['marker_size']); $stroke_w=max(1,(int)$atts['stroke_width']);
        $class=trim((string)$atts['class']); $show_val=((int)$atts['show_value']===1); $title=trim((string)$atts['title']);
        $score=max(0,min(100,(float)$score)); $marker_outline_color=trim((string)$atts['marker_outline_color']); $marker_outline_w=max(0,(int)$atts['marker_outline_width']);
        $padding_y=max((int)floor(($height-$thickness)/2),0); $bar_y=$padding_y; $bar_h=$thickness; $pad_x=max(6,(int)ceil($marker_sz/2)); $bar_x=$pad_x; $bar_w=max(10,$width-2*$pad_x);
        $t=$score/100.0; $mx=$bar_x+$t*$bar_w; $my=(int)floor($height/2);
        $classes='bmf-bsi-form-gauge'; if($class) $classes.=' '.sanitize_html_class($class);
        $svg=sprintf('<svg class="%s" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-hidden="%s" xmlns="http://www.w3.org/2000/svg">', esc_attr($classes),$width,$height,$width,$height,$title?'false':'true'); if($title) $svg.='<title>'.esc_html($title).'</title>';
        $svg .= sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d" fill="%s" />', $bar_x,$bar_y,$bar_w,$bar_h,$radius,$radius,esc_attr($bg));
        if($fill_bg!==''){ $fill_w=max(0,(int)round(($mx-$bar_x))); if($fill_w>0){ $svg .= sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d" fill="%s" />',$bar_x,$bar_y,$fill_w,$bar_h,$radius,$radius,esc_attr($fill_bg)); } }
        switch($marker){
            case 'triangle':{
                $h=$marker_sz; $hh=max(3,(int)round($marker_sz*0.58)); $x1=(int)round($mx); $y1=$my-(int)round($h/2); $x2=(int)round($mx-$hh); $y2=$my+(int)round($h/2); $x3=(int)round($mx+$hh); $y3=$my+(int)round($h/2);
                if($marker_outline_w>0){ $svg .= sprintf('<polygon points="%d,%d %d,%d %d,%d" fill="%s" stroke="%s" stroke-width="%d" />',$x1,$y1,$x2,$y2,$x3,$y3,esc_attr($color),esc_attr($marker_outline_color),$marker_outline_w); }
                else { $svg .= sprintf('<polygon points="%d,%d %d,%d %d,%d" fill="%s" />',$x1,$y1,$x2,$y2,$x3,$y3,esc_attr($color)); }
                break; }
            case 'none': break;
            case 'diamond': default:{
                $pad=max(0,(int)round($marker_sz*0.1)); $x1=(int)round($mx); $y1=$my-$marker_sz/2+$pad; $x2=(int)round($mx+$marker_sz/2-$pad); $y2=$my; $x3=(int)round($mx); $y3=$my+$marker_sz/2-$pad; $x4=(int)round($mx-$marker_sz/2+$pad); $y4=$my;
                if($marker_outline_w>0){ $svg .= sprintf('<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" stroke="%10$s" stroke-width="%11$d" />',$x1,(int)$y1,$x2,(int)$y2,$x3,(int)$y3,$x4,(int)$y4,esc_attr($color),esc_attr($marker_outline_color),$marker_outline_w); }
                else { $svg .= sprintf('<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" />',$x1,(int)$y1,$x2,(int)$y2,$x3,(int)$y3,$x4,(int)$y4,esc_attr($color)); }
                break; }
        }
        if ( $show_val ) {
            $label = rtrim(rtrim(number_format((float)$score, 2, '.', ''), '0'), '.');
            $val_fs=max(6,(int)$atts['value_font_size']); $default_off_y=-(int)round($marker_sz*0.9); $off_y_raw=trim((string)$atts['value_offset_y']); $off_y=($off_y_raw===''||!is_numeric($off_y_raw))?$default_off_y:(int)$off_y_raw; $off_x_raw=trim((string)$atts['value_offset_x']); $off_x=($off_x_raw===''||!is_numeric($off_x_raw))?(int)max(6,round($marker_sz*0.9)):(int)$off_x_raw;
            if($t<0.5){ $anchor='start'; $tx=(int)round($mx+$off_x); } else { $anchor='end'; $tx=(int)round($mx-$off_x); }
            $tx=(int)max(2,min($width-2,$tx)); $ty=(int)round($my+$off_y); $ty=(int)max(2,min($height-2,$ty));
            $svg .= sprintf('<text x="%d" y="%d" text-anchor="%s" dominant-baseline="middle" font-size="%d" fill="#333">%s</text>', $tx,$ty,esc_attr($anchor),$val_fs,esc_html($label));
        }
        $svg.='</svg>';
        return $svg;
    }

    /** Results field passthrough (now supports mode="snapshot|latest" + ?bsi_date) */
    public static function shortcode_results_field( $atts ) {
        if (self::should_bail_for_editor()) return '';
        $atts = shortcode_atts( [ 'field'=>'', 'user_id'=>get_current_user_id(), 'date'=>'', 'format'=>'text', 'format_date'=>'Y-m-d', 'decimals'=>'2', 'autop'=>'0', 'max_chars'=>'0', 'mode'=>'snapshot' ], $atts, 'bmf_bsi_results_field' );
        $user_id = (int)$atts['user_id']; if ( ! $user_id ) return '';
        $field = trim( (string) $atts['field'] ); if ( $field === '' ) return '';

        $cols = BMF_BSI_Form_Service::get_results_table_columns(); if ( empty( $cols[ $field ] ) ) return '';

        $mode = strtolower((string)$atts['mode']); if ($mode!=='snapshot') $mode='latest';

        if ( $mode === 'snapshot' ) {
            // Rolling latest-non-empty across all rows for this field (ignores 'date' and ?bsi_date)
            $db  = BMF_BSI_DBX::$db; $t_r = BMF_BSI_DBX::t('bm_bsi_results');
            $user = get_userdata( $user_id ); if ( ! $user || empty( $user->user_email ) ) return '';
            $email = $user->user_email;
            $rows = $db->get_results( $db->prepare("SELECT * FROM {$t_r} WHERE user_email = %s AND is_final = 1 ORDER BY results_date DESC, id DESC", $email), ARRAY_A );
            if ( ! $rows ) return '';
            $row = null; $value = '';
            foreach ( $rows as $r ) {
                if ( array_key_exists($field, $r) && $r[$field] !== null && $r[$field] !== '' ) { $row = $r; $value = $r[$field]; break; }
            }
            if ( ! $row ) return '';
        } else {
            // Latest single row (honors exact/same-day 'date' attr or ?bsi_date)
            $date_str = ! empty( $atts['date'] )
                ? $atts['date']
                : ( isset($_GET['bsi_date']) ? $_GET['bsi_date'] : null );
            // normalize_date_str is called inside get_results_row_for_user
            $row = BMF_BSI_Form_Service::get_results_row_for_user( $user_id, $date_str );
            if ( ! $row ) return '';
            $value = array_key_exists( $field, $row ) && $row[ $field ] !== null ? $row[ $field ] : '';
        }

        $format = strtolower( (string) $atts['format'] );
        $autop  = ((int)$atts['autop'] === 1);
        $max    = max( 0, (int)$atts['max_chars'] );

        $truncate = function( $text, $limit ) {
            $text = (string) $text;
            if ( $limit <= 0 || mb_strlen( $text ) <= $limit ) return $text;
            $cut = mb_substr( $text, 0, $limit );
            $space = mb_strrpos( $cut, ' ' );
            if ( $space !== false && $space >= $limit - 20 ) $cut = mb_substr( $cut, 0, $space );
            return rtrim( $cut ) . '…';
        };

        switch ( $format ) {
            case 'raw':
                $out = (string) $value; break;
            case 'html':
                $out = (string) $value; if ( $autop ) $out = wpautop( $out ); break;
            case 'number':
                if ( $value === '' ) return '';
                $dec = max( 0, (int)$atts['decimals'] );
                $num = is_numeric( $value ) ? (float)$value : null; if ( $num === null ) return '';
                $out = number_format( $num, $dec, '.', ',' ); break;
            case 'date':
                if ( empty( $value ) ) return '';
                $ts = strtotime( (string)$value );
                $out = ($ts === false) ? esc_html( (string)$value ) : esc_html( date( $atts['format_date'] ?: 'Y-m-d', $ts ) );
                break;
            case 'json':
                if ( $value === '' ) return '';
                $decoded = is_array($value) || is_object($value) ? $value : json_decode( (string)$value, true );
                $out = ($decoded === null)
                    ? esc_html( (string)$value )
                    : '<pre class="bmf-bsi-json">' . esc_html( json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
                break;
            case 'text': default:
                $txt = (string) $value;
                if ( $max > 0 ) $txt = $truncate( $txt, $max );
                $out = nl2br( esc_html( $txt ) );
                if ( $autop ) { $raw = (string) $value; if ( $max > 0 ) $raw = $truncate( $raw, $max ); $out = wpautop( esc_html( $raw ) ); }
                break;
        }
        return $out;
    }
}
BMF_BSI_Form_Shortcodes::init();
