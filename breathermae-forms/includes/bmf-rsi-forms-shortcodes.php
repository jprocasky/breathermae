<?php
/**
 * Breathermae RSI – Form Score Shortcodes (reads {prefix}bm_rsi_results)
 *
 * Parallels the BSI implementation but targets RSI tables:
 *  - {prefix}bm_rsi_results
 *  - {prefix}bm_rsi_form_lookup
 *
 * Shortcodes:
 *  [bmf_rsi_form ...]          → returns score and/or lookup meta based on the user's latest/snapshot RSI result
 *  [bmf_rsi_form_icon ...]     → small SVG icon filled with the form's color (optional numeric label)
 *  [bmf_rsi_form_gauge ...]    → horizontal gauge with marker at the user's score
 *  [bmf_rsi_results_field ...] → flexible passthrough for any field in bm_rsi_results (text/number/date/json/html/raw)
 *
 * Snapshot logic matches BSI: walking all rows (latest→oldest) to find the last non-empty numeric value; zeros treated as empty.
 * "Latest" logic: the single most recent row (by results_date DESC, id DESC), with optional exact/same-day date matching.
 *
 * Color/title/text/suggestions are resolved from {prefix}bm_rsi_form_lookup by (form_id, low_value<=score<high_value).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** DB accessor (prefix-aware) */
if ( ! class_exists( 'BMF_RSI_DBX' ) ) {
    class BMF_RSI_DBX {
        /** @var wpdb */
        public static $db;
        public static function init() { global $wpdb; self::$db = $wpdb; }
        public static function t( $suffix ) { return self::$db->prefix . $suffix; }
    }
    BMF_RSI_DBX::init();
} else {
    if ( empty( BMF_RSI_DBX::$db ) ) { BMF_RSI_DBX::init(); }
}

/**
 * Resolve "form" → the RSI result column and the numeric form_id for lookups.
 *
 * Accepts:
 *  - Numeric IDs (e.g., "11", "12")
 *  - Slugs/labels resolved via {prefix}bm_forms (same as BSI style)
 *  - Direct column-ish inputs "R11", "r11", "R12", etc.
 *
 * Mapping defaults (current schema): id 11 → 'R11', id 12 → 'R12'.
 */
class BMF_RSI_FormId_Resolver {
    /** Resolve a form attribute (id/slug/name/Rxx) to a form_id (int) if available; otherwise null. */
    public static function resolve_form_id( $form ) {
        $f = trim( (string) $form );
        if ($f === '') return null;

        // 1) Explicit Rxx pattern (e.g., "R11", "r12")
        if ( preg_match('/^[Rr](\d{2})$/', $f, $m) ) {
            return (int)$m[1]; // "R11" → 11
        }

        // 2) Numeric id?
        if ( is_numeric( $f ) ) {
            return (int) $f;
        }

        // 3) Resolve via bm_forms (slug → id, then name → id), same pattern used in BSI file
        $db  = BMF_RSI_DBX::$db;
        $t_f = BMF_RSI_DBX::t('bm_forms');

        $row = $db->get_row( $db->prepare("SELECT id FROM {$t_f} WHERE slug = %s LIMIT 1", $f), ARRAY_A );
        if ( $row && ! empty( $row['id'] ) ) return (int) $row['id'];

        $row = $db->get_row( $db->prepare("SELECT id FROM {$t_f} WHERE name = %s LIMIT 1", $f), ARRAY_A );
        if ( $row && ! empty( $row['id'] ) ) return (int) $row['id'];

        return null;
    }

    /** Map form_id → RSI result column (e.g., 11 → "R11", 12 → "R12"). */
    public static function form_id_to_result_col( $form_id ) {
        $form_id = (int) $form_id;
        // Current explicit mappings; extend here if you add more RSI forms later.
        if ($form_id === 11) return 'R11_final';
        if ($form_id === 12) return 'R12_final';
        // Fallback: for any id >= 10, try "R{$id}" if that column exists in bm_rsi_results
        $col = 'R' . $form_id . '_final';
        $cols = BMF_RSI_Form_Service::get_results_table_columns();
        return isset($cols[$col]) ? $col : null;
    }
}

/** Data access + resolution for RSI form scores and lookup. */
class BMF_RSI_Form_Service {

    /** Latest FINALIZED results row for a user (optionally filtered by date). */
    public static function get_results_row_for_user( $user_id, $date_str = null ) {
        $db  = BMF_RSI_DBX::$db;
        $t_r = BMF_RSI_DBX::t('bm_rsi_results');

        $user = get_userdata($user_id);
        if ( ! $user || empty($user->user_email) ) return null;

        $email = $user->user_email;

        // --- 1. Exact date match, FINAL only ---
        if ( $date_str ) {
            $sql = $db->prepare(
                "SELECT *
                FROM {$t_r}
                WHERE user_email = %s
                AND results_date = %s
                AND is_final = 1
                ORDER BY id DESC
                LIMIT 1",
                $email,
                $date_str
            );
            $row = $db->get_row($sql, ARRAY_A);
            if ($row) return $row;

            // --- 2. Same-day FINAL (YYYY-MM-DD) ---
            if ( preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $date_str) ) {
                $sql = $db->prepare(
                    "SELECT *
                    FROM {$t_r}
                    WHERE user_email = %s
                    AND DATE(results_date) = %s
                    AND is_final = 1
                    ORDER BY results_date DESC, id DESC
                    LIMIT 1",
                    $email,
                    $date_str
                );
                $row = $db->get_row($sql, ARRAY_A);
                if ($row) return $row;
            }
        }

        // --- 3. Most recent FINAL result (default) ---
        $sql = $db->prepare(
            "SELECT *
            FROM {$t_r}
            WHERE user_email = %s
            AND is_final = 1
            ORDER BY results_date DESC, id DESC
            LIMIT 1",
            $email
        );

        $row = $db->get_row($sql, ARRAY_A);
        return $row ?: null;
    }

    /** Latest single-row RSI form score (normalized to percent 0..100). */
    public static function get_latest_user_form_score( $user_id, $form_id ) {
        $col = BMF_RSI_FormId_Resolver::form_id_to_result_col( $form_id );
        if (!$col) return null;
        $date_str = isset($_GET['rsi_date'])
            ? sanitize_text_field($_GET['rsi_date']) 
            : null;

        $row = self::get_results_row_for_user( $user_id, $date_str );
        if ( ! $row || ! array_key_exists($col, $row) || $row[$col] === null || $row[$col] === '' ) return null;

        $val = (float) $row[$col];
        $pct = ($val <= 1.0) ? round($val * 100, 2) : round($val, 2);
        return [ 'score_percent' => (float)$pct, 'updated_at' => !empty($row['results_date']) ? $row['results_date'] : null ];
    }

    /** Rolling latest-non-empty RSI form score across ALL rows; normalized 0..100. */
    public static function get_user_form_score_from_snapshot( $user_id, $form_id ) {
        $col = BMF_RSI_FormId_Resolver::form_id_to_result_col( $form_id );
        if (!$col) return null;

        $db  = BMF_RSI_DBX::$db; $t_r = BMF_RSI_DBX::t('bm_rsi_results');
        $user = get_userdata($user_id); if ( ! $user || empty($user->user_email) ) return null;
        $email = $user->user_email;

        $rows = $db->get_results( $db->prepare("SELECT * FROM {$t_r} WHERE user_email = %s AND is_final = 1 ORDER BY results_date DESC, id DESC", $email), ARRAY_A );
        if ( ! $rows ) return null;

        $updated = null;
        foreach ($rows as $r) {
            if ( array_key_exists($col, $r) && $r[$col] !== null && $r[$col] !== '' && is_numeric($r[$col]) ) {
                $val = (float) $r[$col];
                if ( $val <= 0 ) continue; // treat 0/negatives as empty
                $pct = ($val <= 1.0) ? round($val * 100, 2) : round($val, 2);
                $updated = !empty($r['results_date']) ? $r['results_date'] : $updated;
                return [ 'score_percent' => (float)$pct, 'updated_at' => $updated ];
            }
        }
        return null;
    }

    /**
     * "Overall" RSI helper.
     * Defaults to 'master_score' (decimal up to 1.0 or already %), but you can pass 'readiness_score' if preferred.
     */
    public static function get_overall_score_latest( $user_id, $overall_field = 'master_score' ) {
        $date_str = isset($_GET['rsi_date'])
            ? sanitize_text_field($_GET['rsi_date']) 
            : null;

        $row = self::get_results_row_for_user( $user_id, $date_str );
        if ( ! $row || ! array_key_exists($overall_field, $row) || $row[$overall_field] === null || $row[$overall_field] === '' ) return null;

        $val = (float) $row[$overall_field];
        // readiness_score is tinyint 0..100; master_score may be 0..1 or already %.
        $pct = ($overall_field === 'readiness_score') ? max(0, min(100, round($val, 2))) : (($val <= 1.0) ? round($val*100,2) : round($val,2));
        return [ 'score_percent' => (float)$pct, 'updated_at' => !empty($row['results_date']) ? $row['results_date'] : null ];
    }

    public static function get_overall_score_snapshot( $user_id, $overall_field = 'master_score' ) {
        $db  = BMF_RSI_DBX::$db; $t_r = BMF_RSI_DBX::t('bm_rsi_results');
        $user = get_userdata($user_id); if ( ! $user || empty($user->user_email) ) return null;
        $email = $user->user_email;

        $rows = $db->get_results( $db->prepare("SELECT * FROM {$t_r} WHERE user_email = %s AND is_final = 1 ORDER BY results_date DESC, id DESC", $email), ARRAY_A );
        if ( ! $rows ) return null;

        $updated = null;
        foreach ($rows as $r) {
            if ( array_key_exists($overall_field,$r) && $r[$overall_field] !== null && $r[$overall_field] !== '' && is_numeric($r[$overall_field]) ) {
                $val = (float) $r[$overall_field];
                if ($overall_field === 'master_score') {
                    if ($val <= 0) continue;
                    $pct = ($val <= 1.0) ? round($val*100,2) : round($val,2);
                } else { // readiness_score
                    if ($val <= 0) continue;
                    $pct = max(0, min(100, round($val,2)));
                }
                $updated = !empty($r['results_date']) ? $r['results_date'] : $updated;
                return [ 'score_percent' => (float)$pct, 'updated_at' => $updated ];
            }
        }
        return null;
    }

    /** Resolve form-level metadata by score range from bm_rsi_form_lookup. */
    public static function resolve_form_lookup( $form_id, $score_percent ) {
        $db   = BMF_RSI_DBX::$db;
        $t_lu = BMF_RSI_DBX::t('bm_rsi_form_lookup');
        $lookup_score = round((float)$score_percent);
        $sql  = $db->prepare(
            "SELECT form_title, form_text, form_focus, icon_url, form_color, Suggestions AS suggestions, Recommendations AS recommendations
             FROM {$t_lu}
             WHERE form_id = %d AND %f >= low_value AND %f < high_value
             ORDER BY id ASC LIMIT 1",
            $form_id, $lookup_score, $lookup_score
        );
        $row = $db->get_row($sql, ARRAY_A);
        return $row ?: null;
    }

    /** Cache: list of columns in bm_rsi_results (cached 12h). */
    protected static function results_columns_cache_key() { return 'bmf_rsi_results_cols_cache'; }

    public static function get_results_table_columns() {
        $cache_key = self::results_columns_cache_key();
        $cols = get_transient( $cache_key );
        if ( is_array($cols) ) return $cols;

        $db   = BMF_RSI_DBX::$db;
        $tbl  = BMF_RSI_DBX::t('bm_rsi_results');
        $rows = $db->get_results( "SHOW COLUMNS FROM {$tbl}", ARRAY_A );
        $cols = [];
        if ($rows) { foreach ($rows as $r) { if (!empty($r['Field'])) $cols[$r['Field']] = true; } }
        set_transient( $cache_key, $cols, 12 * HOUR_IN_SECONDS );
        return $cols;
    }

    public static function normalize_date_str( $date_str ) {
        if ( empty( $date_str ) ) return null;
        $date_str = sanitize_text_field( $date_str );
        if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $date_str, $m ) ) {
            return $m[1];
        }
        $ts = strtotime( $date_str );
        return ( $ts !== false ) ? date( 'Y-m-d', $ts ) : null;
    }

    /**
     * Normalize raw RSI numeric values to 0–100 percent.
     * readiness_score is already 0–100; other fields may be 0–1 or already %.
     */
    public static function to_percent( $raw, $field = '' ) {
        if ( $raw === null || $raw === '' || ! is_numeric( $raw ) ) return null;
        $val = (float) $raw;
        if ( $field === 'readiness_score' ) {
            return max( 0, min( 100, round( $val, 2 ) ) );
        }
        return ( $val <= 1.0 ) ? round( $val * 100, 2 ) : round( $val, 2 );
    }

    /**
     * Previous finalized RSI results row before $date_str (or before current latest).
     */
    public static function get_previous_results_row_for_user( $user_id, $date_str = null ) {
        $user_id  = (int) $user_id;
        $date_str = self::normalize_date_str( $date_str );

        $db   = BMF_RSI_DBX::$db;
        $t_r  = BMF_RSI_DBX::t( 'bm_rsi_results' );
        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) return null;
        $email = $user->user_email;

        if ( ! $date_str ) {
            $current = self::get_results_row_for_user( $user_id, null );
            if ( ! $current || empty( $current['results_date'] ) ) return null;
            $date_str = self::normalize_date_str( $current['results_date'] );
        }
        if ( ! $date_str ) return null;

        $row = $db->get_row(
            $db->prepare(
                "SELECT * FROM {$t_r}
                 WHERE user_email = %s AND is_final = 1 AND results_date < %s
                 ORDER BY results_date DESC, id DESC LIMIT 1",
                $email, $date_str
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Trend series for [bmf_rsi_trend_chart].
     *
     * Core RSI       = R11_final
     * Performance RSI = R12_final
     * Calibration    = R12_S6
     *
     * X-axis relative to first is_final=1 record (Baseline).
     *
     * @return array{baseline_date:string,points:array}|null
     */
    public static function get_trend_series_for_user( $user_id ) {
        $user_id = (int) $user_id;
        $db   = BMF_RSI_DBX::$db;
        $t_r  = BMF_RSI_DBX::t( 'bm_rsi_results' );
        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) return null;
        $email = $user->user_email;

        $rows = $db->get_results(
            $db->prepare(
                "SELECT results_date, R11_final, R12_final, R12_S6
                 FROM {$t_r}
                 WHERE user_email = %s AND is_final = 1
                 ORDER BY results_date ASC, id ASC",
                $email
            ),
            ARRAY_A
        );
        if ( empty( $rows ) ) return null;

        $baseline_date = self::normalize_date_str( $rows[0]['results_date'] );
        if ( ! $baseline_date ) return null;

        $points = [];
        foreach ( $rows as $r ) {
            $d = self::normalize_date_str( $r['results_date'] );
            if ( ! $d ) continue;
            $points[] = [
                'date'         => $d,
                'core'         => self::to_percent( $r['R11_final'] ?? null ),
                'performance'  => self::to_percent( $r['R12_final'] ?? null ),
                'calibration'  => self::to_percent( $r['R12_S6'] ?? null ),
            ];
        }

        return [
            'baseline_date' => $baseline_date,
            'points'        => $points,
        ];
    }
}

/** Shortcodes */
class BMF_RSI_Form_Shortcodes {

    public static function init() {
        add_shortcode( 'bmf_rsi_form',           [ __CLASS__, 'shortcode_form' ] );
        add_shortcode( 'bmf_rsi_form_icon',      [ __CLASS__, 'shortcode_form_icon' ] );
        add_shortcode( 'bmf_rsi_form_gauge',     [ __CLASS__, 'shortcode_form_gauge' ] );
        add_shortcode( 'bmf_rsi_results_field',  [ __CLASS__, 'shortcode_results_field' ] );
        add_shortcode( 'bmf_rsi_history_select', [ __CLASS__, 'shortcode_history_select' ] );
        add_shortcode( 'bmf_rsi_results_delta',  [ __CLASS__, 'shortcode_results_delta' ] );
        add_shortcode( 'bmf_rsi_trend_chart',    [ __CLASS__, 'shortcode_trend_chart' ] );
        add_shortcode( 'bmf_rsi_section_delta',  [ __CLASS__, 'shortcode_section_delta' ] );
        add_shortcode( 'bmf_rsi_dimension_avg',  [ __CLASS__, 'shortcode_dimension_avg' ] );
        add_shortcode( 'bmf_rsi_history_report', [ __CLASS__, 'shortcode_history_report' ] );
        add_shortcode('bmf_rsi_section_icon', function ($atts) {

            if (function_exists('bmf_in_elementor_editor') && bmf_in_elementor_editor()) {
                return '';
            }

            $atts = shortcode_atts([
                'section_id'       => '',
                'user_id'          => get_current_user_id(),
                'form_id'    => '',
                'size'             => '36',
                'outline_color'    => '#000000',
                'outline_width'    => '1',
                'show_value'       => '1',
                'value_font_size'  => '14',
                'value_weight'     => '600',
                'value_color'      => '#FFFFFF',
                'value_offset_y'   => '0',
                'color'            => '#6EC1E4',
            ], $atts);


            $form_id    = (int)$atts['form_id'];
            $section_id = (int)$atts['section_id'];
            $user_id = (int)$atts['user_id'];

            if (!$form_id || !$section_id || !$user_id) return '';

            $score = BMF_RSI_Section_Service::get_section_score($user_id, $form_id, $section_id);
            $lookup = BMF_RSI_Form_Service::resolve_form_lookup($form_id, $score);
            $color  = $lookup['form_color'] ?? '#6EC1E4';            


            if ($score === null) {
                return '';
            }

            return BMF_RSI_Form_Shortcodes::render_basic_icon([
                'score'           => $score,
                'size'            => $atts['size'],
                'outline_color'   => $atts['outline_color'],
                'outline_width'   => $atts['outline_width'],
                'show_value'      => (int)$atts['show_value'] === 1,
                'value_font_size' => $atts['value_font_size'],
                'value_weight'    => $atts['value_weight'],
                'value_color'     => $atts['value_color'],
                'value_offset_y'  => $atts['value_offset_y'],
                'color' => $color,
            ]);
        });      
        add_shortcode('bmf_rsi_section_gauge', function($atts){
            if (function_exists('bmf_in_elementor_editor') && bmf_in_elementor_editor()) return '';

            $atts = shortcode_atts([
                'section_id' => '',
                'form_id'    => '',
                'user_id'    => get_current_user_id(),
                'width'      => '320',
                'height'     => '44',
                'marker'     => 'triangle',
                'marker_size'=> '18',
            ], $atts);


            $user_id    = (int)$atts['user_id'];
            $form_id    = (int)$atts['form_id'];
            $section_id = (int)$atts['section_id'];

            if (!$form_id || !$section_id || !$user_id) return '';

            $score = BMF_RSI_Section_Service::get_section_score($user_id, $form_id, $section_id);
            $lookup = BMF_RSI_Form_Service::resolve_form_lookup($form_id, $score);
            $color  = $lookup['form_color'] ?? '#6EC1E4';            


            // Delegate rendering to the standard gauge
            return do_shortcode(sprintf(
                '[bmf_rsi_form_gauge form="overall" user_id="%d" width="%s" height="%s" marker="%s" marker_size="%s"]',
                $user_id,
                esc_attr($atts['width']),
                esc_attr($atts['height']),
                esc_attr($atts['marker']),
                esc_attr($atts['marker_size'])
            ));
        });
        // Optional: allow external hooks to invalidate this shortcode cache after saving RSI results
        add_action( 'bmf_rsi_results_updated', [ __CLASS__, 'invalidate_cache' ], 10, 2 );
    }

        public static function shortcode_history_select($atts) {

            if (!is_user_logged_in()) return '';

            $user = wp_get_current_user();
            $email = $user->user_email ?? '';

            if (!$email) return '';

            // Get available dates
            $dates = BMF_Repository::get_rsi_result_dates($email);

            if (empty($dates)) return '';

            $selected = isset($_GET['rsi_date'])
                ? sanitize_text_field($_GET['rsi_date'])
                : '';

            ob_start();
            ?>

            <div class="bmf-rsi-history-select" style="margin-bottom:10px; font-size:0.9rem; color:#001d50; display:flex; align-items:center; gap:8px;">

                <b style="white-space:nowrap;">Assessment Date:</b>

                <select id="bmf_rsi_date_select" style="padding:4px 8px; font-size:0.9rem; border:1px solid #001d50; border-radius:4px; width:150px;">
                    <?php foreach ($dates as $d): ?>
                        <option value="<?php echo esc_attr($d); ?>" <?php selected($selected, $d); ?>>
                            <?php echo esc_html(date('M j, Y', strtotime($d))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            </div>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var el = document.getElementById('bmf_rsi_date_select');
                if (!el) return;

                el.addEventListener('change', function() {
                    var selected = this.value;
                    var url = new URL(window.location.href);

                    if (selected) {
                        url.searchParams.set('rsi_date', selected);
                    } else {
                        url.searchParams.delete('rsi_date');
                    }

                    window.location.href = url.toString();
                });
            });
            </script>

            <?php

            return ob_get_clean();
        }    

    public static function render_basic_icon(array $args): string {
        $size = max(8, (int)$args['size']);
        $score = max(0, min(100, (float)$args['score']));
        $color = $args['color'] ?: '#cccccc';

        $half = $size / 2;
        $r = (int)($half - ($args['outline_width'] ?? 0));

        $label = $args['show_value']
            ? '<text x="'.$half.'" y="'.($half + ($args['value_offset_y'] ?? 0)).'"
                text-anchor="middle" dominant-baseline="middle"
                font-size="'.$args['value_font_size'].'"
                font-weight="'.$args['value_weight'].'"
                fill="'.$args['value_color'].'">'.round($score).'</text>'
            : '';

        return sprintf(
            '<svg width="%d" height="%d" viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg">
                <circle cx="%d" cy="%d" r="%d"
                    fill="%s"
                    stroke="%s"
                    stroke-width="%d" />
                %s
            </svg>',
            $size, $size, $size, $size,
            $half, $half, $r,
            esc_attr($color),
            esc_attr($args['outline_color']),
            (int)$args['outline_width'],
            $label
        );
    }

    private static function should_bail_for_editor(): bool {
        // Same bail-out convention as BSI file
        $disable = apply_filters('bmf/shortcodes/disable_in_elementor', true);
        if (!$disable) return false;
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

    private static function cache_key( $user_id, $form_id, $mode = 'snapshot', $overall_field = 'master_score' ) {
        $mode = $mode ? strtolower((string)$mode) : 'snapshot';
        $overall_field = sanitize_key( $overall_field ?: 'master_score' );
        return "bmf_rsi_form_{$user_id}_{$form_id}_{$mode}_{$overall_field}";
    }

    /**
     * [bmf_rsi_form
     *     form="R11|11|slug|overall|0"
     *     field="score|form_title|form_text|form_focus|icon_url|form_color|updated_at|suggestions"
     *     user_id=""
     *     cache_ttl="600"
     *     colorize="0"
     *     mode="snapshot|latest"
     *     overall_field="master_score|readiness_score"
     * ]
     */
    public static function shortcode_form( $atts ) {
        if (self::should_bail_for_editor()) return '';

        $atts = shortcode_atts( [
            'form'          => '',
            'field'         => 'score',
            'user_id'       => get_current_user_id(),
            'cache_ttl'     => 600,
            'colorize'      => '0',
            'mode'          => 'latest',
            'overall_field' => 'master_score',
        ], $atts, 'bmf_rsi_form' );

        $user_id = (int) $atts['user_id']; if (!$user_id) return '';

                // Resolve form from attr or querystring (reuse global helper if available)
        $raw_attr = (string) $atts['form'];
        $form_raw = function_exists('bmf_resolve_form_from_atts_or_query')
                    ? bmf_resolve_form_from_atts_or_query($raw_attr)
                    : trim($raw_attr);

        $form_lower = strtolower( (string) $form_raw );
        $is_overall = ( $form_lower === 'overall' ) || ( is_numeric($form_raw) && (int)$form_raw === 0 );

        $mode = strtolower( (string) $atts['mode'] );
        if ($mode !== 'latest') $mode = 'snapshot';

        $overall_field = sanitize_key( $atts['overall_field'] ?: 'master_score' );
        $form_id = $is_overall ? 0 : BMF_RSI_FormId_Resolver::resolve_form_id( $form_raw );
        if ( !$is_overall && $form_id === null ) return '';

        $date_str = isset($_GET['rsi_date'])
            ? sanitize_text_field($_GET['rsi_date']) 
            : '';

        $ckey = self::cache_key(
            $user_id,
            $form_id,
            $mode . '_' . ($date_str ?: 'latest'),
            $overall_field . '_v5' // include overall_field in cache key since it affects the score and lookup results
        );
        $ttl  = max(0, (int) $atts['cache_ttl'] );
        $data = get_transient( $ckey );

        if ( ! is_array($data) ) {
            if ( $is_overall ) {
                $res = ($mode === 'latest')
                    ? BMF_RSI_Form_Service::get_overall_score_latest($user_id, $overall_field)
                    : BMF_RSI_Form_Service::get_overall_score_snapshot($user_id, $overall_field);

                if ( ! $res ) {
                    $data = [
                        'score'=>'', 'form_title'=>'', 'form_text'=>'', 'form_focus'=>'',
                        'icon_url'=>'', 'form_color'=>'', 'suggestions'=>'','recommendations'=>'', 'updated_at'=>'',
                    ];
                } else {


                    // For overall we look up form_id = 0 ranges if you choose to add those
                    $raw_score = $res['score_percent'];

                    $is_readiness = ($overall_field === 'readiness_score');

                    $score_display = $is_readiness
                        ? (is_numeric($raw_score) ? (float)$raw_score : '')
                        : $raw_score;

                    $score_lookup = $is_readiness && is_numeric($raw_score)
                        ? $raw_score * 10
                        : $raw_score;

                    $meta = BMF_RSI_Form_Service::resolve_form_lookup( 0, (float)$score_lookup ) ?: [];
                    $data  = [
                        'score'      => is_numeric($score_display) ? (float)$score_display : '',
                        'form_title' => $meta['form_title'] ?? '',
                        'form_text'  => $meta['form_text']  ?? '',
                        'form_focus' => $meta['form_focus'] ?? '',
                        'icon_url'   => $meta['icon_url']   ?? '',
                        'form_color' => ($overall_field === 'readiness_score' && is_numeric($score_lookup))
                            ? self::resolve_color_from_score( (float)$score_lookup )
                            : ($meta['form_color'] ?? ''),
                        'suggestions'=> $meta['suggestions']?? '',
                        'recommendations'=> $meta['recommendations']?? '',
                        'updated_at' => !empty($res['updated_at']) ? substr($res['updated_at'],0,10) : '',
                    ];

                }
            } else {
                $res = ($mode === 'latest')
                    ? BMF_RSI_Form_Service::get_latest_user_form_score( $user_id, $form_id )
                    : BMF_RSI_Form_Service::get_user_form_score_from_snapshot( $user_id, $form_id );

                if ( ! $res ) {
                    $data = [
                        'score'=>'', 'form_title'=>'', 'form_text'=>'', 'form_focus'=>'',
                        'icon_url'=>'', 'form_color'=>'', 'suggestions'=>'', 'updated_at'=>'',
                    ];
                } else {
                    $score = $res['score_percent'];
                    $meta  = BMF_RSI_Form_Service::resolve_form_lookup( $form_id, (float)$score ) ?: [];
                    $data  = [
                        'score'      => is_numeric($score) ? (float)$score : '',
                        'form_title' => $meta['form_title'] ?? '',
                        'form_text'  => $meta['form_text']  ?? '',
                        'form_focus' => $meta['form_focus'] ?? '',
                        'icon_url'   => $meta['icon_url']   ?? '',
                        'form_color' => $meta['form_color'] ?? '',
                        'suggestions'=> $meta['suggestions']?? '',
                        'recommendations'=> $meta['recommendations']?? '',
                        'updated_at' => !empty($res['updated_at']) ? substr($res['updated_at'],0,10) : '',
                    ];
                }
            }
            if ( $ttl > 0 ) set_transient( $ckey, $data, $ttl );
        }

        $field = sanitize_key( $atts['field'] );
        $val   = isset($data[$field]) ? $data[$field] : '';

        // Optional: colorize the title
        if ( $field === 'form_title' && (int)$atts['colorize'] === 1 ) {
            $col = $data['form_color'] ?? '';
            if ( $col !== '' && $val !== '' ) {
                return '<span style="color:' . esc_attr($col) . '">' . esc_html((string)$val) . '</span>';
            }
        }

        // Suggestions/Recommendations can include limited HTML
        if ( in_array( $field, ['suggestions','recommendations'], true ) ) {
            $allowed = [
                'a'   => [ 'href'=>true, 'target'=>true, 'rel'=>true, 'class'=>true ],
                'img' => [ 'src'=>true, 'alt'=>true, 'width'=>true, 'height'=>true, 'loading'=>true, 'decoding'=>true, 'referrerpolicy'=>true, 'sizes'=>true, 'srcset'=>true, 'class'=>true, 'style'=>true ],
                'br'  => [], 'p'=>['class'=>true,'style'=>true], 'ul'=>['class'=>true], 'ol'=>['class'=>true], 'li'=>['class'=>true],
                'strong'=>[], 'em'=>[], 'b'=>[], 'i'=>[], 'span'=>['class'=>true,'style'=>true],
            ];
            return wp_kses( (string)$val, $allowed );
        }

        return esc_html( (string) $val );
    }


    /** Icon (mirrors BSI variant) */
    public static function shortcode_form_icon( $atts ) {
        if (self::should_bail_for_editor()) return '';

        $atts = shortcode_atts( [
            'form' => '',
            'user_id' => get_current_user_id(),
            'size' => '24', 'shape' => 'diamond', 'stroke_width'=>'2', 'class'=>'', 'title'=>'',
            'outline_color'=>'#000000', 'outline_width'=>'0',
            'show_value'=>'0', 'value_font_size'=>'11', 'value_color'=>'#FFFFFF', 'value_weight'=>'600', 'value_offset_y'=>'0',
            'mode' => 'latest',
            'overall_field' => 'master_score',
        ], $atts, 'bmf_rsi_form_icon' );

        $user_id = (int) $atts['user_id']; if (!$user_id) return '';
        $mode = strtolower((string)$atts['mode']); if ($mode!=='latest') $mode='snapshot';

        $raw_attr = (string) $atts['form'];
        $form_raw = function_exists('bmf_resolve_form_from_atts_or_query') ? bmf_resolve_form_from_atts_or_query($raw_attr) : trim($raw_attr);
        $form_lower = strtolower(trim($form_raw));
        $is_overall = ( $form_lower === 'overall' ) || ( is_numeric($form_raw) && (int)$form_raw === 0 );

        $overall_field = sanitize_key( $atts['overall_field'] ?: 'master_score' );

        // Pull score + color via nested RSI form shortcode to keep logic in one place
        $score_str = do_shortcode( sprintf(
            '[bmf_rsi_form form="%s" field="score" user_id="%d" mode="%s" overall_field="%s"]',
            esc_attr($form_raw), $user_id, esc_attr($mode), esc_attr($overall_field)
        ) );
        $color = trim( do_shortcode( sprintf(
            '[bmf_rsi_form form="%s" field="form_color" user_id="%d" mode="%s" overall_field="%s"]',
            esc_attr($form_raw), $user_id, esc_attr($mode), esc_attr($overall_field)
        ) ) );

        if ($color === '') {
            $color = '#cccccc';
        }

        if ($score_str === '') {
            $size = max(8, (int)$atts['size']);
            return sprintf(
                '<span class="bmf-rsi-form-icon-na" style="display:inline-block;width:%dpx;height:%dpx;line-height:%dpx;text-align:center;color:#808080;"><strong>N/A</strong></span>',
                $size,
                $size,
                $size
            );
        }
        //if (empty($color)) $color = '#cccccc';

        $size = max(8, (int)$atts['size']);
        $shape = is_string($atts['shape'])
            ? strtolower( trim( $atts['shape'] ) )
            : 'diamond';

        $stroke_w = max(1,(int)$atts['stroke_width']);
        $class = trim((string)$atts['class']);
        $title = trim((string)$atts['title']);
        $classes = 'bmf-rsi-form-icon' . ( $class ? ' ' . sanitize_html_class($class) : '' );
        $outline_color = trim((string)$atts['outline_color']);
        $outline_w = max(0, (int)$atts['outline_width']);

        $half = $size/2;
        $svg_open = sprintf('<svg class="%s" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-hidden="%s" xmlns="http://www.w3.org/2000/svg">',
            esc_attr($classes), $size, $size, $size, $size, $title?'false':'true'
        );
        $svg_title = $title ? '<title>' . esc_html($title) . '</title>' : '';
        $pad_edge = ($shape==='ring') ? $stroke_w : $outline_w;
        $shape_html='';

        switch($shape){
            case 'ring':{
                $r = max(1, $half - $stroke_w); $r = max(1, $r - ($pad_edge/2));
                $shape_html = sprintf('<circle cx="%1$d" cy="%1$d" r="%2$d" fill="none" stroke="%3$s" stroke-width="%4$d" />',
                    $half,(int)$r,esc_attr($color),$stroke_w);
                break;
            }
            case 'square':{
                $rx=max(0,(int)round($size*0.12)); $x=$pad_edge; $y=$pad_edge; $w=max(1,$size-2*$pad_edge);
                if($outline_w>0){
                    $shape_html=sprintf('<rect x="%1$d" y="%2$d" width="%3$d" height="%3$d" rx="%4$d" ry="%4$d" fill="%5$s" stroke="%6$s" stroke-width="%7$d" />',
                        (int)$x,(int)$y,(int)$w,(int)$rx,esc_attr($color),esc_attr($outline_color),$outline_w);
                } else {
                    $shape_html=sprintf('<rect x="%1$d" y="%2$d" width="%3$d" height="%3$d" rx="%4$d" ry="%4$d" fill="%5$s" />',
                        (int)$x,(int)$y,(int)$w,(int)$rx,esc_attr($color));
                }
                break;
            }
            case 'circle':{
                $r=max(1,$half-$pad_edge);
                if($outline_w>0){
                    $shape_html=sprintf('<circle cx="%1$d" cy="%1$d" r="%2$d" fill="%3$s" stroke="%4$s" stroke-width="%5$d" />',
                        $half,(int)$r,esc_attr($color),esc_attr($outline_color),$outline_w);
                } else {
                    $shape_html=sprintf('<circle cx="%1$d" cy="%1$d" r="%2$d" fill="%3$s" />',
                        $half,(int)$r,esc_attr($color));
                }
                break;
            }
            case 'diamond': default:{
                $inner=max((int)$pad_edge,(int)round($size*0.1));
                $x1=$half; $y1=$inner; $x2=$size-$inner; $y2=$half; $x3=$half; $y3=$size-$inner; $x4=$inner; $y4=$half;
                if($outline_w>0){
                    $shape_html=sprintf('<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" stroke="%10$s" stroke-width="%11$d" />',
                        (int)$x1,(int)$y1,(int)$x2,(int)$y2,(int)$x3,(int)$y3,(int)$x4,(int)$y4,esc_attr($color),esc_attr($outline_color),$outline_w);
                } else {
                    $shape_html=sprintf('<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" />',
                        (int)$x1,(int)$y1,(int)$x2,(int)$y2,(int)$x3,(int)$y3,(int)$x4,(int)$y4,esc_attr($color));
                }
                break;
            }
        }

        $score_val = is_numeric($score_str) ? max(0,min(100,(float)$score_str)) : null;
        $label = ($score_val===null)?'':number_format((float)round($score_val),0,'.','');
        $svg_label='';
        if( (int)$atts['show_value']===1 && $label!=='' ){
            $fs=max(6,(int)$atts['value_font_size']); $fclr=trim((string)$atts['value_color']);
            $fw=preg_match('/^(100|200|300|400|500|600|700|800|900|bold|normal)$/',(string)$atts['value_weight'])?(string)$atts['value_weight']:'600';
            $offY=(int)$atts['value_offset_y']; $tx=(int)round($half); $ty=(int)round($half+$offY);
            $svg_label=sprintf('<text x="%d" y="%d" text-anchor="middle" dominant-baseline="middle" font-size="%d" font-weight="%s" fill="%s">%s</text>',
                $tx,$ty,$fs,esc_attr($fw),esc_attr($fclr),esc_html($label));
        }

        return $svg_open . $svg_title . $shape_html . $svg_label . '</svg>';
    }

    /** Gauge (mirrors BSI variant) */
    public static function shortcode_form_gauge( $atts ) {
        if (self::should_bail_for_editor()) return '';

        $atts = shortcode_atts( [
            'form'  => '', 'metric'=>'', 'user_id'=>get_current_user_id(),
            // layout
            'width'=>'280','height'=>'24','thickness'=>'6','radius'=>'3',
            // colors
            'bg'=>'#E6E9EF','fill_bg'=>'#CBD2E1',
            // marker
            'marker'=>'diamond','marker_size'=>'12','stroke_width'=>'2',
            'marker_outline_color'=>'#000000','marker_outline_width'=>'0',
            // misc
            'class'=>'','show_value'=>'0','title'=>'','value_font_size'=>'11','value_offset_y'=>'','value_offset_x'=>'',
            'mode'=>'latest',
            'overall_field'=>'master_score',
        ], $atts, 'bmf_rsi_form_gauge' );

        $user_id = (int)$atts['user_id']; if (!$user_id) return '';
        $mode = strtolower((string)$atts['mode']); if($mode!=='latest') $mode='snapshot';
        $raw_attr = (string)$atts['form'];
        $form_raw = function_exists('bmf_resolve_form_from_atts_or_query') ? bmf_resolve_form_from_atts_or_query($raw_attr) : trim($raw_attr);
        $form_lower = strtolower(trim($form_raw));
        $is_overall = ( $form_lower==='overall' ) || ( is_numeric($form_raw) && (int)$form_raw===0 );
        $overall_field = sanitize_key( $atts['overall_field'] ?: 'master_score' );

        // Determine score
        $score = null;
        if ( !$is_overall ) {
            $form_id = BMF_RSI_FormId_Resolver::resolve_form_id( $form_raw ); if ( ! $form_id ) return '';
            $res = ($mode==='latest')
                ? BMF_RSI_Form_Service::get_latest_user_form_score($user_id,$form_id)
                : BMF_RSI_Form_Service::get_user_form_score_from_snapshot($user_id,$form_id);
            if ($res) $score = $res['score_percent'];
        } else {
            $res = ($mode==='latest')
                ? BMF_RSI_Form_Service::get_overall_score_latest($user_id, $overall_field)
                : BMF_RSI_Form_Service::get_overall_score_snapshot($user_id, $overall_field);
            if ($res) $score = $res['score_percent'];
        }
        if ($score === null) return '';

        // Determine color via nested RSI form shortcode
        $color = do_shortcode( sprintf(
            '[bmf_rsi_form form="%s" field="form_color" user_id="%d" mode="%s" overall_field="%s"]',
            esc_attr($form_raw), $user_id, esc_attr($mode), esc_attr($overall_field)
        ) );
        if (empty($color)) $color = '#cccccc';

        // Geometry + styling (same as BSI gauge)
        $width_attr = trim((string)$atts['width']);
        $is_percent_width = preg_match('/^\d+(\.\d+)?%$/', $width_attr);
        // Geometry width (used internally for math)
        $width = $is_percent_width
            ? 420   // default internal width for layout math
            : max(120, (int)$width_attr);
        $height=max(16,(int)$atts['height']); $thickness=max(2,(int)$atts['thickness']); $radius=max(0,(int)$atts['radius']);
        $bg=trim((string)$atts['bg']); $fill_bg=trim((string)$atts['fill_bg']);
        $marker=strtolower(trim((string)$atts['marker'])); $marker_sz=max(6,(int)$atts['marker_size']); $stroke_w=max(1,(int)$atts['stroke_width']);
        $class=trim((string)$atts['class']); $show_val=((int)$atts['show_value']===1); $title=trim((string)$atts['title']);

        $score=max(0,min(100,(float)$score));
        $marker_outline_color=trim((string)$atts['marker_outline_color']); $marker_outline_w=max(0,(int)$atts['marker_outline_width']);
        $padding_y=max((int)floor(($height-$thickness)/2),0); $bar_y=$padding_y; $bar_h=$thickness;
        $pad_x=max(6,(int)ceil($marker_sz/2)); $bar_x=$pad_x; $bar_w=max(10,$width-2*$pad_x);
        $t=$score/100.0; $mx=$bar_x+$t*$bar_w; $my=(int)floor($height/2);
        $classes='bmf-rsi-form-gauge'; if($class) $classes.=' '.sanitize_html_class($class);

        $svg_width_attr = $is_percent_width
            ? esc_attr($width_attr)
            : esc_attr($width);

        $svg = sprintf(
            '<svg class="%s" width="%s" height="%d" viewBox="0 0 %d %d" role="img" aria-hidden="%s" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">',
            esc_attr($classes),
            $svg_width_attr,
            $height,
            $width,
            $height,
            $title ? 'false' : 'true'
        );


        if($title) $svg.='<title>'.esc_html($title).'</title>';

        $svg .= sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d" fill="%s" />', $bar_x,$bar_y,$bar_w,$bar_h,$radius,$radius,esc_attr($bg));
        if($fill_bg!==''){
            $fill_w=max(0,(int)round(($mx-$bar_x)));
            if($fill_w>0){
                $svg .= sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d" fill="%s" />', $bar_x,$bar_y,$fill_w,$bar_h,$radius,$radius,esc_attr($fill_bg));
            }
        }

        switch($marker){
            case 'triangle':{
                $h=$marker_sz; $hh=max(3,(int)round($marker_sz*0.58));
                $x1=(int)round($mx); $y1=$my-(int)round($h/2);
                $x2=(int)round($mx-$hh); $y2=$my+(int)round($h/2);
                $x3=(int)round($mx+$hh); $y3=$my+(int)round($h/2);
                if($marker_outline_w>0){
                    $svg .= sprintf('<polygon points="%d,%d %d,%d %d,%d" fill="%s" stroke="%s" stroke-width="%d" />',
                        $x1,$y1,$x2,$y2,$x3,$y3,esc_attr($color),esc_attr($marker_outline_color),$marker_outline_w);
                } else {
                    $svg .= sprintf('<polygon points="%d,%d %d,%d %d,%d" fill="%s" />',
                        $x1,$y1,$x2,$y2,$x3,$y3,esc_attr($color));
                }
                break;
            }
            case 'none': break;
            case 'diamond': default:{
                $pad=max(0,(int)round($marker_sz*0.1));
                $x1=(int)round($mx); $y1=$my-$marker_sz/2+$pad;
                $x2=(int)round($mx+$marker_sz/2-$pad); $y2=$my;
                $x3=(int)round($mx); $y3=$my+$marker_sz/2-$pad;
                $x4=(int)round($mx-$marker_sz/2+$pad); $y4=$my;
                if($marker_outline_w>0){
                    $svg .= sprintf('<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" stroke="%10$s" stroke-width="%11$d" />',
                        $x1,(int)$y1,$x2,(int)$y2,$x3,(int)$y3,$x4,(int)$y4,esc_attr($color),esc_attr($marker_outline_color),$marker_outline_w);
                } else {
                    $svg .= sprintf('<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" />',
                        $x1,(int)$y1,$x2,(int)$y2,$x3,(int)$y3,$x4,(int)$y4,esc_attr($color));
                }
                break;
            }
        }

        if ( $show_val ) {
            $label = rtrim(rtrim(number_format((float)$score, 2, '.', ''), '0'), '.');
            $val_fs=max(6,(int)$atts['value_font_size']); $default_off_y=-(int)round($marker_sz*0.9);
            $off_y_raw=trim((string)$atts['value_offset_y']);
            $off_y=($off_y_raw===''||!is_numeric($off_y_raw))?$default_off_y:(int)$off_y_raw;
            $off_x_raw=trim((string)$atts['value_offset_x']);
            $off_x=($off_x_raw===''||!is_numeric($off_x_raw))?(int)max(6,round($marker_sz*0.9)):(int)$off_x_raw;

            if($t<0.5){ $anchor='start'; $tx=(int)round($mx+$off_x); } else { $anchor='end'; $tx=(int)round($mx-$off_x); }
            $tx=(int)max(2,min($width-2,$tx)); $ty=(int)round($my+$off_y); $ty=(int)max(2,min($height-2,$ty));
            $svg .= sprintf('<text x="%d" y="%d" text-anchor="%s" dominant-baseline="middle" font-size="%d" fill="#333">%s</text>',
                $tx,$ty,esc_attr($anchor),$val_fs,esc_html($label));
        }

        $svg.='</svg>';
        return $svg;
    }

    /**
     * Flexible field passthrough for bm_rsi_results
     * [bmf_rsi_results_field field="R11_final" format="number" decimals="0" colorize="1"]
     *
     * Same options as [bmf_bsi_results_field]:
     *   format="text|number|date|json|html|raw"
     *   decimals="0"   (used with format=number; 0–1 values auto-scaled to %)
     *   colorize="1"   (lookup color, then strain-zone fallback; lower = greener)
     */
    public static function shortcode_results_field( $atts ) {
        if ( self::should_bail_for_editor() ) return '';

        $atts = shortcode_atts( [
            'field'       => '',
            'user_id'     => get_current_user_id(),
            'date'        => '',
            'format'      => 'text',
            'format_date' => 'Y-m-d',
            'decimals'    => '2',
            'autop'       => '0',
            'max_chars'   => '0',
            'mode'        => 'latest',
            'colorize'    => '0',
        ], $atts, 'bmf_rsi_results_field' );

        $user_id = (int) $atts['user_id'];
        if ( ! $user_id ) return '';
        $field = trim( (string) $atts['field'] );
        if ( $field === '' ) return '';

        $cols = BMF_RSI_Form_Service::get_results_table_columns();
        if ( empty( $cols[ $field ] ) ) return '';

        $mode = strtolower( (string) $atts['mode'] );
        if ( $mode !== 'snapshot' ) $mode = 'latest';

        if ( $mode === 'snapshot' ) {
            $db  = BMF_RSI_DBX::$db;
            $t_r = BMF_RSI_DBX::t( 'bm_rsi_results' );
            $user = get_userdata( $user_id );
            if ( ! $user || empty( $user->user_email ) ) return '';
            $email = $user->user_email;

            $rows = $db->get_results(
                $db->prepare(
                    "SELECT * FROM {$t_r} WHERE user_email = %s AND is_final = 1 ORDER BY results_date DESC, id DESC",
                    $email
                ),
                ARRAY_A
            );
            if ( ! $rows ) return '';

            $row   = null;
            $value = '';
            foreach ( $rows as $r ) {
                if ( array_key_exists( $field, $r ) && $r[ $field ] !== null && $r[ $field ] !== '' ) {
                    $row   = $r;
                    $value = $r[ $field ];
                    break;
                }
            }
            if ( ! $row ) return '';
        } else {
            $date_str = isset( $_GET['rsi_date'] )
                ? sanitize_text_field( $_GET['rsi_date'] )
                : ( $atts['date'] ?: null );

            $row = BMF_RSI_Form_Service::get_results_row_for_user( $user_id, $date_str );
            if ( ! $row ) return '';
            $value = array_key_exists( $field, $row ) && $row[ $field ] !== null ? $row[ $field ] : '';
        }

        $format   = strtolower( (string) $atts['format'] );
        $autop    = ( (int) $atts['autop'] === 1 );
        $max      = max( 0, (int) $atts['max_chars'] );
        $colorize = ( (int) $atts['colorize'] === 1 );

        $truncate = function( $text, $limit ) {
            $text = (string) $text;
            if ( $limit <= 0 || mb_strlen( $text ) <= $limit ) return $text;
            $cut   = mb_substr( $text, 0, $limit );
            $space = mb_strrpos( $cut, ' ' );
            if ( $space !== false && $space >= $limit - 20 ) $cut = mb_substr( $cut, 0, $space );
            return rtrim( $cut ) . '…';
        };

        $numeric_for_color = null;

        switch ( $format ) {
            case 'raw':
                $out = (string) $value;
                break;

            case 'html':
                $out = (string) $value;
                if ( $autop ) $out = wpautop( $out );
                break;

            case 'number':
                if ( $value === '' ) return '';
                $dec = max( 0, (int) $atts['decimals'] );
                $num = is_numeric( $value ) ? (float) $value : null;
                if ( $num === null ) return '';

                // Match BSI: 0–1 domain values → percent. readiness_score is already 0–100.
                if ( $field !== 'readiness_score' && $num <= 1.0 ) {
                    $num = $num * 100;
                }
                $numeric_for_color = $num;
                $out = number_format( $num, $dec, '.', ',' );
                break;

            case 'date':
                if ( empty( $value ) ) return '';
                $ts  = strtotime( (string) $value );
                $out = ( $ts === false )
                    ? esc_html( (string) $value )
                    : esc_html( date( $atts['format_date'] ?: 'Y-m-d', $ts ) );
                break;

            case 'json':
                if ( $value === '' ) return '';
                $decoded = is_array( $value ) || is_object( $value )
                    ? $value
                    : json_decode( (string) $value, true );
                $out = ( $decoded === null )
                    ? esc_html( (string) $value )
                    : '<pre class="bmf-rsi-json">' . esc_html( json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
                break;

            case 'text':
            default:
                $txt = (string) $value;
                if ( $max > 0 ) $txt = $truncate( $txt, $max );
                $out = nl2br( esc_html( $txt ) );
                if ( $autop ) {
                    $raw = (string) $value;
                    if ( $max > 0 ) $raw = $truncate( $raw, $max );
                    $out = wpautop( esc_html( $raw ) );
                }
                break;
        }

        if ( $colorize && $numeric_for_color !== null ) {
            // Prefer form-specific lookup (R11→11, R12→12); else overall (0); else strain zones.
            $lookup_form_id = 0;
            if ( preg_match( '/^R11/i', $field ) ) {
                $lookup_form_id = 11;
            } elseif ( preg_match( '/^R12/i', $field ) && stripos( $field, 'S6' ) === false ) {
                $lookup_form_id = 12;
            }

            $color = '';
            $meta  = BMF_RSI_Form_Service::resolve_form_lookup( $lookup_form_id, (float) $numeric_for_color );
            if ( ! empty( $meta['form_color'] ) ) {
                $color = $meta['form_color'];
            }
            if ( $color === '' ) {
                // Strain bands — lower is better (green → yellow → orange → red)
                $color = self::strain_color( (float) $numeric_for_color );
            }
            if ( $color !== '' ) {
                $out = '<span style="color:' . esc_attr( $color ) . ';">' . $out . '</span>';
            }
        }

        return $out;
    }

    /**
     * [bmf_rsi_results_delta field="R11_final|R12_final|R12_S6|readiness_score|master_score" ...]
     * Lower-is-better: decrease = green, increase = red.
     */
    public static function shortcode_results_delta( $atts ) {
        if ( self::should_bail_for_editor() ) return '';
        $atts = shortcode_atts( [
            'field'      => '',
            'user_id'    => get_current_user_id(),
            'decimals'   => '0',
            'show_arrow' => '1',
            'show_sign'  => '1',
            'colorize'   => '1',
        ], $atts, 'bmf_rsi_results_delta' );

        $user_id = (int) $atts['user_id'];
        $field   = trim( (string) $atts['field'] );
        if ( ! $user_id || $field === '' ) return '';

        $cols = BMF_RSI_Form_Service::get_results_table_columns();
        if ( empty( $cols[ $field ] ) ) return '';

        $date_str = isset( $_GET['rsi_date'] )
            ? BMF_RSI_Form_Service::normalize_date_str( $_GET['rsi_date'] )
            : null;

        $current = BMF_RSI_Form_Service::get_results_row_for_user( $user_id, $date_str );
        if ( ! $current || ! array_key_exists( $field, $current ) || $current[ $field ] === null || $current[ $field ] === '' ) {
            return '0';
        }

        $prev = BMF_RSI_Form_Service::get_previous_results_row_for_user( $user_id, $date_str );
        if ( ! $prev || ! array_key_exists( $field, $prev ) || $prev[ $field ] === null || $prev[ $field ] === '' ) {
            $out = '0';
            if ( (int) $atts['colorize'] === 1 ) {
                $out = '<span style="color:#888888;">' . $out . '</span>';
            }
            return $out;
        }

        $cur_pct  = BMF_RSI_Form_Service::to_percent( $current[ $field ], $field );
        $prev_pct = BMF_RSI_Form_Service::to_percent( $prev[ $field ], $field );
        if ( $cur_pct === null || $prev_pct === null ) return '0';

        $delta = $cur_pct - $prev_pct;
        $dec   = max( 0, (int) $atts['decimals'] );
        $abs   = abs( $delta );
        $num   = number_format( $abs, $dec, '.', ',' );

        $sign = '';
        if ( (int) $atts['show_sign'] === 1 ) {
            if ( $delta > 0 )      $sign = '+';
            elseif ( $delta < 0 )  $sign = '−';
        }
        $arrow = '';
        if ( (int) $atts['show_arrow'] === 1 ) {
            if ( $delta > 0 )      $arrow = ' ↑';
            elseif ( $delta < 0 )  $arrow = ' ↓';
        }
        $out = $sign . $num . $arrow;

        // Lower is better for all RSI metrics
        if ( (int) $atts['colorize'] === 1 ) {
            if ( $delta < 0 ) {
                $color = '#44dd30';
            } elseif ( $delta > 0 ) {
                $color = '#c62828';
            } else {
                $color = '#888888';
            }
            $out = '<span style="color:' . esc_attr( $color ) . ';">' . $out . '</span>';
        }
        return $out;
    }

    /**
     * [bmf_rsi_section_delta section_id="48" form_id="11" ...]
     * Delta of a single R11 section score vs previous assessment. Lower is better.
     */
    public static function shortcode_section_delta( $atts ) {
        if ( self::should_bail_for_editor() ) return '';
        $atts = shortcode_atts( [
            'section_id' => '',
            'form_id'    => '11',
            'user_id'    => get_current_user_id(),
            'decimals'   => '0',
            'show_arrow' => '1',
            'show_sign'  => '1',
            'colorize'   => '1',
        ], $atts, 'bmf_rsi_section_delta' );

        $user_id    = (int) $atts['user_id'];
        $form_id    = (int) $atts['form_id'] ?: 11;
        $section_id = (int) $atts['section_id'];
        if ( ! $user_id || ! $section_id ) return '';

        $date_str = isset( $_GET['rsi_date'] )
            ? BMF_RSI_Form_Service::normalize_date_str( $_GET['rsi_date'] )
            : null;

        $cur  = BMF_RSI_Section_Service::get_section_score( $user_id, $form_id, $section_id, $date_str );
        $prev = BMF_RSI_Section_Service::get_previous_section_score( $user_id, $form_id, $section_id, $date_str );

        if ( $cur === null ) return '0';
        if ( $prev === null ) {
            $out = '0';
            if ( (int) $atts['colorize'] === 1 ) {
                $out = '<span style="color:#888888;">' . $out . '</span>';
            }
            return $out;
        }

        $delta = (float) $cur - (float) $prev;
        $dec   = max( 0, (int) $atts['decimals'] );
        $num   = number_format( abs( $delta ), $dec, '.', ',' );

        $sign = '';
        if ( (int) $atts['show_sign'] === 1 ) {
            if ( $delta > 0 )      $sign = '+';
            elseif ( $delta < 0 )  $sign = '−';
        }
        $arrow = '';
        if ( (int) $atts['show_arrow'] === 1 ) {
            if ( $delta > 0 )      $arrow = ' ↑';
            elseif ( $delta < 0 )  $arrow = ' ↓';
        }
        $out = $sign . $num . $arrow;

        if ( (int) $atts['colorize'] === 1 ) {
            if ( $delta < 0 ) {
                $color = '#44dd30';
            } elseif ( $delta > 0 ) {
                $color = '#c62828';
            } else {
                $color = '#888888';
            }
            $out = '<span style="color:' . esc_attr( $color ) . ';">' . $out . '</span>';
        }
        return $out;
    }

    /**
     * [bmf_rsi_dimension_avg group="drivers|mediators|outcomes" field="score|delta" ...]
     * Category average of the 3 section scores in that group (form 11).
     */
    public static function shortcode_dimension_avg( $atts ) {
        if ( self::should_bail_for_editor() ) return '';
        $atts = shortcode_atts( [
            'group'      => '',
            'field'      => 'score', // score | delta
            'form_id'    => '11',
            'user_id'    => get_current_user_id(),
            'decimals'   => '0',
            'show_arrow' => '1',
            'show_sign'  => '1',
            'colorize'   => '1',
        ], $atts, 'bmf_rsi_dimension_avg' );

        $user_id = (int) $atts['user_id'];
        $form_id = (int) $atts['form_id'] ?: 11;
        $group   = strtolower( trim( (string) $atts['group'] ) );
        $field   = strtolower( trim( (string) $atts['field'] ) );
        if ( ! $user_id || ! in_array( $group, [ 'drivers', 'mediators', 'outcomes' ], true ) ) return '';

        $map = BMF_RSI_Section_Service::dimension_section_ids();
        $section_ids = $map[ $group ] ?? [];
        if ( empty( $section_ids ) ) return '';

        $date_str = isset( $_GET['rsi_date'] )
            ? BMF_RSI_Form_Service::normalize_date_str( $_GET['rsi_date'] )
            : null;

        $cur_vals = [];
        $prev_vals = [];
        foreach ( $section_ids as $sid ) {
            $c = BMF_RSI_Section_Service::get_section_score( $user_id, $form_id, $sid, $date_str );
            if ( $c !== null ) $cur_vals[] = (float) $c;
            $p = BMF_RSI_Section_Service::get_previous_section_score( $user_id, $form_id, $sid, $date_str );
            if ( $p !== null ) $prev_vals[] = (float) $p;
        }

        if ( empty( $cur_vals ) ) return '';

        $cur_avg = round( array_sum( $cur_vals ) / count( $cur_vals ), 2 );

        if ( $field !== 'delta' ) {
            $dec = max( 0, (int) $atts['decimals'] );
            return esc_html( number_format( $cur_avg, $dec, '.', ',' ) );
        }

        if ( empty( $prev_vals ) ) {
            $out = '0';
            if ( (int) $atts['colorize'] === 1 ) {
                $out = '<span style="color:#888888;">' . $out . '</span>';
            }
            return $out;
        }

        $prev_avg = round( array_sum( $prev_vals ) / count( $prev_vals ), 2 );
        $delta    = $cur_avg - $prev_avg;
        $dec      = max( 0, (int) $atts['decimals'] );
        $num      = number_format( abs( $delta ), $dec, '.', ',' );

        $sign = '';
        if ( (int) $atts['show_sign'] === 1 ) {
            if ( $delta > 0 )      $sign = '+';
            elseif ( $delta < 0 )  $sign = '−';
        }
        $arrow = '';
        if ( (int) $atts['show_arrow'] === 1 ) {
            if ( $delta > 0 )      $arrow = ' ↑';
            elseif ( $delta < 0 )  $arrow = ' ↓';
        }
        $out = $sign . $num . $arrow;

        if ( (int) $atts['colorize'] === 1 ) {
            if ( $delta < 0 ) {
                $color = '#44dd30';
            } elseif ( $delta > 0 ) {
                $color = '#c62828';
            } else {
                $color = '#888888';
            }
            $out = '<span style="color:' . esc_attr( $color ) . ';">' . $out . '</span>';
        }
        return $out;
    }

    /**
     * [bmf_rsi_trend_chart height="360" user_id=""]
     *
     * Multi-series line chart (baseline-relative, ~1 year window):
     *   Core RSI        = R11_final
     *   Performance RSI = R12_final
     *   Calibration     = R12_S6
     *
     * Phase markers at Baseline, +90d, +180d, +270d.
     * Lower is better (same strain-style Y zones as BSI).
     */
    public static function shortcode_trend_chart( $atts ) {
        if ( self::should_bail_for_editor() ) return '';

        $atts = shortcode_atts( [
            'user_id' => get_current_user_id(),
            'height'  => '360',
        ], $atts, 'bmf_rsi_trend_chart' );

        $user_id = (int) $atts['user_id'];
        if ( ! $user_id ) return '';

        $data = BMF_RSI_Form_Service::get_trend_series_for_user( $user_id );
        if ( ! $data || empty( $data['points'] ) ) {
            return '<div class="bmf-rsi-trend-empty" style="padding:24px;text-align:center;color:#8892a4;background:#0b1220;border-radius:12px;">No historical RSI data</div>';
        }

        $baseline = $data['baseline_date'];
        $points   = $data['points'];
        $height   = max( 240, (int) $atts['height'] );
        $n_points = count( $points );

        // Adaptive point size for denser RSI series (~50/yr)
        $point_radius = ( $n_points > 24 ) ? 2.5 : ( ( $n_points > 12 ) ? 3.5 : 5 );
        $point_hover  = $point_radius + 2;

        $phases = [
            [ 'key' => 'baseline',  'label' => 'Baseline',           'sub' => 'Assessment',      'offset' => 0   ],
            [ 'key' => 'early',     'label' => 'Early Alignment',    'sub' => '+90 days',         'offset' => 90  ],
            [ 'key' => 'system',    'label' => 'System Response',    'sub' => '+180 days',        'offset' => 180 ],
            [ 'key' => 'adaptive',  'label' => 'Adaptive Stability', 'sub' => '+270 days',        'offset' => 270 ],
        ];

        $base_ts = strtotime( $baseline . ' 00:00:00' );
        foreach ( $phases as &$ph ) {
            $ph['date'] = date( 'Y-m-d', strtotime( '+' . $ph['offset'] . ' days', $base_ts ) );
            $ph['ts']   = strtotime( $ph['date'] . ' 00:00:00' ) * 1000;
        }
        unset( $ph );

        $x_min_ts = ( $base_ts - 10 * DAY_IN_SECONDS ) * 1000;
        $x_max_ts = ( $base_ts + 365 * DAY_IN_SECONDS ) * 1000;

        $series = [ 'core' => [], 'performance' => [], 'calibration' => [] ];
        $latest = [ 'core' => null, 'performance' => null, 'calibration' => null ];
        foreach ( $points as $pt ) {
            $ts = strtotime( $pt['date'] . ' 00:00:00' ) * 1000;
            foreach ( [ 'core', 'performance', 'calibration' ] as $key ) {
                if ( $pt[ $key ] !== null ) {
                    $series[ $key ][] = [ 'x' => $ts, 'y' => $pt[ $key ] ];
                    $latest[ $key ] = $pt[ $key ];
                }
            }
        }

        $uid = 'bmf_rsi_trend_' . $user_id . '_' . wp_unique_id();
        $json_core         = wp_json_encode( $series['core'] );
        $json_performance  = wp_json_encode( $series['performance'] );
        $json_calibration  = wp_json_encode( $series['calibration'] );
        $json_phases       = wp_json_encode( $phases );

        // Distinct palette (still dark-theme friendly)
        $c_core        = '#e91e8c'; // magenta
        $c_performance = '#3b82f6'; // blue
        $c_calibration = '#22d3ee'; // cyan

        ob_start();
        ?>
<div class="bmf-rsi-trend-wrap" style="background:#0b1220;border-radius:16px;padding:20px 16px 16px;font-family:system-ui,-apple-system,sans-serif;color:#e2e8f0;">
  <div class="bmf-rsi-trend-phases" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px;">
    <?php foreach ( $phases as $i => $ph ):
        $icons = [ '📋', '🎯', '📈', '🛡️' ];
        $icon  = $icons[ $i ] ?? '●';
    ?>
    <div style="background:#121a2b;border:1px solid #1e2a44;border-radius:10px;padding:10px 8px;text-align:center;">
      <div style="font-size:1.25rem;margin-bottom:4px;"><?php echo $icon; ?></div>
      <div style="font-size:0.72rem;font-weight:700;letter-spacing:0.02em;color:#93c5fd;"><?php echo esc_html( $ph['label'] ); ?></div>
      <div style="font-size:0.65rem;color:#64748b;margin-top:2px;"><?php echo esc_html( date( 'M j, Y', $ph['ts'] / 1000 ) ); ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="position:relative;height:<?php echo (int) $height; ?>px;">
    <canvas id="<?php echo esc_attr( $uid ); ?>"></canvas>
  </div>

  <div style="display:flex;flex-wrap:wrap;gap:16px;justify-content:center;margin-top:14px;font-size:0.85rem;">
    <span style="display:inline-flex;align-items:center;gap:6px;">
      <span style="width:12px;height:3px;background:<?php echo $c_core; ?>;border-radius:2px;display:inline-block;"></span>
      Core RSI
      <?php if ( $latest['core'] !== null ): ?>
        <strong style="color:<?php echo $c_core; ?>;"><?php echo esc_html( number_format( $latest['core'], 0 ) ); ?></strong>
      <?php endif; ?>
    </span>
    <span style="display:inline-flex;align-items:center;gap:6px;">
      <span style="width:12px;height:3px;background:<?php echo $c_performance; ?>;border-radius:2px;display:inline-block;"></span>
      Performance RSI
      <?php if ( $latest['performance'] !== null ): ?>
        <strong style="color:<?php echo $c_performance; ?>;"><?php echo esc_html( number_format( $latest['performance'], 0 ) ); ?></strong>
      <?php endif; ?>
    </span>
    <span style="display:inline-flex;align-items:center;gap:6px;">
      <span style="width:12px;height:3px;background:<?php echo $c_calibration; ?>;border-radius:2px;display:inline-block;"></span>
      Calibration
      <?php if ( $latest['calibration'] !== null ): ?>
        <strong style="color:<?php echo $c_calibration; ?>;"><?php echo esc_html( number_format( $latest['calibration'], 0 ) ); ?></strong>
      <?php endif; ?>
    </span>
  </div>
</div>

<script>
(function(){
  var canvasId = <?php echo wp_json_encode( $uid ); ?>;
  var core         = <?php echo $json_core; ?>;
  var performance  = <?php echo $json_performance; ?>;
  var calibration  = <?php echo $json_calibration; ?>;
  var phases       = <?php echo $json_phases; ?>;
  var xMin         = <?php echo (int) $x_min_ts; ?>;
  var xMax         = <?php echo (int) $x_max_ts; ?>;
  var cCore        = <?php echo wp_json_encode( $c_core ); ?>;
  var cPerformance = <?php echo wp_json_encode( $c_performance ); ?>;
  var cCalibration = <?php echo wp_json_encode( $c_calibration ); ?>;
  var ptRadius     = <?php echo (float) $point_radius; ?>;
  var ptHover      = <?php echo (float) $point_hover; ?>;

  function loadScript(src, cb) {
    if (document.querySelector('script[src="'+src+'"]')) { cb(); return; }
    var s = document.createElement('script');
    s.src = src; s.onload = cb; document.head.appendChild(s);
  }

  function boot() {
    loadScript('https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', function(){
      loadScript('https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js', function(){
        render();
      });
    });
  }

  function render() {
    var ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') return;

    var zonePlugin = {
      id: 'bmfRsiZones',
      beforeDraw: function(chart) {
        var y = chart.scales.y;
        var x = chart.scales.x;
        var areas = [
          { from: 75, to: 100, color: 'rgba(198,40,40,0.12)' },
          { from: 50, to: 75,  color: 'rgba(234,88,12,0.10)' },
          { from: 25, to: 50,  color: 'rgba(234,179,8,0.08)' },
          { from: 0,  to: 25,  color: 'rgba(34,197,94,0.08)' }
        ];
        var ctx2 = chart.ctx;
        areas.forEach(function(a){
          var y1 = y.getPixelForValue(a.to);
          var y2 = y.getPixelForValue(a.from);
          ctx2.fillStyle = a.color;
          ctx2.fillRect(x.left, y1, x.right - x.left, y2 - y1);
        });
      }
    };

    var phasePlugin = {
      id: 'bmfRsiPhases',
      afterDraw: function(chart) {
        var x = chart.scales.x;
        var y = chart.scales.y;
        var ctx2 = chart.ctx;
        phases.forEach(function(ph){
          var px = x.getPixelForValue(ph.ts);
          if (px < x.left || px > x.right) return;
          ctx2.save();
          ctx2.beginPath();
          ctx2.setLineDash([4, 4]);
          ctx2.strokeStyle = 'rgba(148,163,184,0.45)';
          ctx2.lineWidth = 1;
          ctx2.moveTo(px, y.top);
          ctx2.lineTo(px, y.bottom);
          ctx2.stroke();
          ctx2.restore();
        });
      }
    };

    // Soft glow under each line (matches BSI bmfGlow plugin)
    // Tweak shadowBlur (e.g. 8–20) to adjust strength
    var glowPlugin = {
      id: 'bmfRsiGlow',
      beforeDatasetDraw: function(chart, args) {
        var ctx2 = chart.ctx;
        var ds   = chart.data.datasets[args.index];
        if (!ds) return;
        ctx2.save();
        ctx2.shadowColor   = ds.borderColor || 'rgba(255,255,255,0.4)';
        ctx2.shadowBlur    = 8;
        ctx2.shadowOffsetX = 0;
        ctx2.shadowOffsetY = 4;
      },
      afterDatasetDraw: function(chart) {
        chart.ctx.restore();
      }
    };

    new Chart(ctx, {
      type: 'line',
      data: {
        datasets: [
          {
            label: 'Core RSI',
            data: core,
            borderColor: cCore,
            backgroundColor: cCore,
            borderWidth: 2.5,
            pointRadius: ptRadius,
            pointHoverRadius: ptHover,
            pointBackgroundColor: '#0b1220',
            pointBorderColor: cCore,
            pointBorderWidth: 2,
            tension: 0.35,
            spanGaps: true
          },
          {
            label: 'Performance RSI',
            data: performance,
            borderColor: cPerformance,
            backgroundColor: cPerformance,
            borderWidth: 2.5,
            pointRadius: ptRadius,
            pointHoverRadius: ptHover,
            pointBackgroundColor: '#0b1220',
            pointBorderColor: cPerformance,
            pointBorderWidth: 2,
            tension: 0.35,
            spanGaps: true
          },
          {
            label: 'Calibration',
            data: calibration,
            borderColor: cCalibration,
            backgroundColor: cCalibration,
            borderWidth: 2.5,
            pointRadius: ptRadius,
            pointHoverRadius: ptHover,
            pointBackgroundColor: '#0b1220',
            pointBorderColor: cCalibration,
            pointBorderWidth: 2,
            tension: 0.35,
            spanGaps: true
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'nearest', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#121a2b',
            titleColor: '#e2e8f0',
            bodyColor: '#cbd5e1',
            borderColor: '#1e2a44',
            borderWidth: 1,
            callbacks: {
              title: function(items) {
                if (!items.length) return '';
                var d = new Date(items[0].parsed.x);
                return d.toLocaleDateString(undefined, { year:'numeric', month:'short', day:'numeric' });
              }
            }
          }
        },
        scales: {
          x: {
            type: 'time',
            min: xMin,
            max: xMax,
            time: { unit: 'month', displayFormats: { month: 'MMM yyyy' } },
            grid: { color: 'rgba(30,42,68,0.8)', drawBorder: false },
            ticks: { color: '#94a3b8', maxRotation: 0, autoSkip: true, maxTicksLimit: 6 }
          },
          y: {
            min: 0,
            max: 100,
            grid: { color: 'rgba(30,42,68,0.6)', drawBorder: false },
            ticks: {
              stepSize: 25,
              color: function(ctx) {
                var v = ctx.tick && ctx.tick.value;
                if (v === 0)  return '#00da17';
                if (v === 25) return '#eff012';
                if (v === 50) return '#ff6600';
                if (v === 75) return '#d60008';
                return '#94a3b8';
              },
              callback: function(v) {
                if (v === 100) return '100';
                if (v === 75)  return '75  HIGH STRAIN';
                if (v === 50)  return '50  ELEVATED';
                if (v === 25)  return '25  MODERATE';
                if (v === 0)   return '0   OPTIMAL';
                return v;
              }
            }
          }
        }
      },
      plugins: [ zonePlugin, phasePlugin, glowPlugin ]
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
        <?php
        return ob_get_clean();
    }

    /**
     * Strain-style color (lower is better / greener).
     */
    public static function strain_color( $score ): string {
        if ( $score === null || $score === '' || ! is_numeric( $score ) ) return '#64748b';
        $s = (float) $score;
        if ( $s < 25 )  return '#22c55e'; // Optimal
        if ( $s < 50 )  return '#eab308'; // Moderate
        if ( $s < 75 )  return '#f97316'; // Elevated
        return '#ef4444';                 // High Strain
    }

    /**
     * Format a signed delta HTML span (lower-is-better colors).
     */
    private static function format_delta_html( $delta, $decimals = 0 ): string {
        if ( $delta === null ) {
            return '<span style="color:#64748b;">—</span>';
        }
        $delta = (float) $delta;
        $num   = number_format( abs( $delta ), max( 0, (int) $decimals ), '.', ',' );
        if ( $delta < 0 ) {
            return '<span style="color:#44dd30;font-weight:600;">−' . $num . ' ↓</span>';
        }
        if ( $delta > 0 ) {
            return '<span style="color:#c62828;font-weight:600;">+' . $num . ' ↑</span>';
        }
        return '<span style="color:#888888;">0</span>';
    }

    /**
     * [bmf_rsi_history_report height="360" user_id="" show_date_picker="1"]
     *
     * All-in-one dark RSI history view:
     *  - assessment date picker
     *  - KPI strip (Core / Performance / Calibration / Readiness / Master) with deltas
     *  - baseline-relative trend chart
     *  - RSI Dimensions panel (Drivers / Mediators / Outcomes + 9 section scores & deltas)
     */
    public static function shortcode_history_report( $atts ) {
        if ( self::should_bail_for_editor() ) {
            return '<div style="padding:24px;background:#0b1220;border-radius:12px;color:#8892a4;text-align:center;">RSI History Report (editor preview)</div>';
        }

        $atts = shortcode_atts( [
            'user_id'          => get_current_user_id(),
            'height'           => '340',
            'show_date_picker' => '1',
        ], $atts, 'bmf_rsi_history_report' );

        $user_id = (int) $atts['user_id'];
        if ( ! $user_id ) return '';

        $date_str = isset( $_GET['rsi_date'] )
            ? BMF_RSI_Form_Service::normalize_date_str( $_GET['rsi_date'] )
            : null;

        $row = BMF_RSI_Form_Service::get_results_row_for_user( $user_id, $date_str );
        if ( ! $row ) {
            return '<div class="bmf-rsi-history-empty" style="padding:28px;text-align:center;color:#8892a4;background:#0b1220;border-radius:16px;font-family:system-ui,-apple-system,sans-serif;">No finalized RSI assessments found.</div>';
        }

        $prev = BMF_RSI_Form_Service::get_previous_results_row_for_user( $user_id, $date_str );

        $metrics = [
            'core' => [
                'label' => 'Core RSI',
                'field' => 'R11_final',
                'color' => '#e91e8c',
            ],
            'performance' => [
                'label' => 'Performance RSI',
                'field' => 'R12_final',
                'color' => '#3b82f6',
            ],
            'calibration' => [
                'label' => 'Calibration',
                'field' => 'R12_S6',
                'color' => '#22d3ee',
            ],
            'readiness' => [
                'label' => 'Readiness',
                'field' => 'readiness_score',
                'color' => '#a78bfa',
            ],
            'master' => [
                'label' => 'Master Score',
                'field' => 'master_score',
                'color' => '#fbbf24',
            ],
        ];

        $kpi = [];
        foreach ( $metrics as $key => $meta ) {
            $field = $meta['field'];
            $cur   = BMF_RSI_Form_Service::to_percent( $row[ $field ] ?? null, $field );
            $prv   = null;
            if ( $prev && isset( $prev[ $field ] ) && $prev[ $field ] !== null && $prev[ $field ] !== '' ) {
                $prv = BMF_RSI_Form_Service::to_percent( $prev[ $field ], $field );
            }
            $kpi[ $key ] = [
                'label' => $meta['label'],
                'color' => $meta['color'],
                'score' => $cur,
                'delta' => ( $cur !== null && $prv !== null ) ? round( $cur - $prv, 1 ) : null,
            ];
        }

        $dims = BMF_RSI_Section_Service::get_dimensions_snapshot( $user_id, 11, $date_str );
        $group_labels = BMF_RSI_Section_Service::dimension_group_labels();
        $section_map  = BMF_RSI_Section_Service::dimension_section_ids();

        $results_date = BMF_RSI_Form_Service::normalize_date_str( $row['results_date'] ?? '' );
        $date_display = $results_date ? date( 'M j, Y', strtotime( $results_date ) ) : '—';

        $chart_html = self::shortcode_trend_chart( [
            'user_id' => $user_id,
            'height'  => $atts['height'],
        ] );

        // Dark-theme date picker (inline, not the light default)
        $picker_html = '';
        if ( (int) $atts['show_date_picker'] === 1 ) {
            $user = get_userdata( $user_id );
            $dates = ( $user && ! empty( $user->user_email ) && class_exists( 'BMF_Repository' ) )
                ? BMF_Repository::get_rsi_result_dates( $user->user_email )
                : [];
            if ( ! empty( $dates ) ) {
                $selected = $date_str ?: ( $dates[0] ?? '' );
                ob_start();
                ?>
                <div class="bmf-rsi-history-select" style="display:flex;align-items:center;gap:8px;font-size:0.85rem;color:#94a3b8;">
                  <span style="white-space:nowrap;font-weight:600;color:#cbd5e1;">Assessment</span>
                  <select id="bmf_rsi_report_date_select" style="padding:6px 10px;font-size:0.85rem;border:1px solid #1e2a44;border-radius:8px;background:#121a2b;color:#e2e8f0;min-width:140px;">
                    <?php foreach ( $dates as $d ): ?>
                      <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $selected, $d ); ?>>
                        <?php echo esc_html( date( 'M j, Y', strtotime( $d ) ) ); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <script>
                (function(){
                  var el = document.getElementById('bmf_rsi_report_date_select');
                  if (!el) return;
                  el.addEventListener('change', function(){
                    var url = new URL(window.location.href);
                    if (this.value) url.searchParams.set('rsi_date', this.value);
                    else url.searchParams.delete('rsi_date');
                    window.location.href = url.toString();
                  });
                })();
                </script>
                <?php
                $picker_html = ob_get_clean();
            }
        }

        ob_start();
        ?>
<div class="bmf-rsi-history-report" style="background:#0b1220;border-radius:16px;padding:20px 18px 22px;font-family:system-ui,-apple-system,sans-serif;color:#e2e8f0;">

  <!-- Header -->
  <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;">
    <div>
      <div style="font-size:1.05rem;font-weight:700;letter-spacing:0.01em;color:#f1f5f9;">RSI History</div>
      <div style="font-size:0.78rem;color:#64748b;margin-top:3px;">Viewing <?php echo esc_html( $date_display ); ?><?php echo $prev ? ' · vs prior assessment' : ''; ?></div>
    </div>
    <?php echo $picker_html; ?>
  </div>

  <!-- KPI strip -->
  <div class="bmf-rsi-kpi-strip" style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:18px;">
    <?php foreach ( $kpi as $k => $item ):
        $sc = $item['score'];
        $sc_color = self::strain_color( $sc );
    ?>
    <div style="background:#121a2b;border:1px solid #1e2a44;border-radius:12px;padding:12px 10px;text-align:center;">
      <div style="font-size:0.68rem;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:#94a3b8;margin-bottom:6px;"><?php echo esc_html( $item['label'] ); ?></div>
      <div style="font-size:1.45rem;font-weight:700;line-height:1.1;color:<?php echo esc_attr( $sc_color ); ?>;">
        <?php echo $sc !== null ? esc_html( number_format( $sc, 0 ) ) : '—'; ?>
      </div>
      <div style="font-size:0.78rem;margin-top:6px;"><?php echo self::format_delta_html( $item['delta'], 0 ); ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Chart + Dimensions -->
  <div class="bmf-rsi-report-body" style="display:grid;grid-template-columns:minmax(0,1.55fr) minmax(260px,0.9fr);gap:16px;align-items:start;">

    <!-- Trend -->
    <div style="min-width:0;">
      <?php echo $chart_html; // already dark-themed ?>
    </div>

    <!-- Dimensions panel -->
    <div style="background:#121a2b;border:1px solid #1e2a44;border-radius:14px;padding:14px 14px 10px;">
      <div style="font-size:0.9rem;font-weight:700;color:#e2e8f0;margin-bottom:12px;">RSI Dimensions</div>

      <?php foreach ( $section_map as $group_key => $section_ids ):
          $g = $dims['groups'][ $group_key ] ?? [ 'score' => null, 'delta' => null ];
          $g_label = $group_labels[ $group_key ] ?? ucfirst( $group_key );
          $g_color = self::strain_color( $g['score'] );
      ?>
      <div style="margin-bottom:14px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid #1e2a44;">
          <span style="font-size:0.78rem;font-weight:700;letter-spacing:0.03em;text-transform:uppercase;color:#93c5fd;"><?php echo esc_html( $g_label ); ?></span>
          <span style="display:inline-flex;align-items:center;gap:8px;font-size:0.82rem;">
            <strong style="color:<?php echo esc_attr( $g_color ); ?>;">
              <?php echo $g['score'] !== null ? esc_html( number_format( $g['score'], 0 ) ) : '—'; ?>
            </strong>
            <?php echo self::format_delta_html( $g['delta'] ?? null, 0 ); ?>
          </span>
        </div>

        <?php foreach ( $section_ids as $sid ):
            $s = $dims['sections'][ $sid ] ?? null;
            if ( ! $s ) continue;
            $s_color = self::strain_color( $s['score'] );
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:5px 0;">
          <span style="font-size:0.8rem;color:#cbd5e1;"><?php echo esc_html( $s['label'] ); ?></span>
          <span style="display:inline-flex;align-items:center;gap:10px;font-size:0.8rem;white-space:nowrap;">
            <strong style="color:<?php echo esc_attr( $s_color ); ?>;min-width:28px;text-align:right;">
              <?php echo $s['score'] !== null ? esc_html( number_format( $s['score'], 0 ) ) : '—'; ?>
            </strong>
            <span style="min-width:52px;text-align:right;"><?php echo self::format_delta_html( $s['delta'], 0 ); ?></span>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <style>
    @media (max-width: 960px) {
      .bmf-rsi-history-report .bmf-rsi-report-body {
        grid-template-columns: 1fr !important;
      }
      .bmf-rsi-history-report .bmf-rsi-kpi-strip {
        grid-template-columns: repeat(2, minmax(0,1fr)) !important;
      }
    }
    @media (max-width: 520px) {
      .bmf-rsi-history-report .bmf-rsi-kpi-strip {
        grid-template-columns: 1fr !important;
      }
    }
  </style>
</div>
        <?php
        return ob_get_clean();
    }

    public static function resolve_color_from_score( float $score ): string {
        $score = max(0, min(100, $score));

        if ($score >= 80) return '#92996c'; // green
        if ($score >= 60) return '#f2c94c'; // yellow
        if ($score >= 40) return '#e97132'; // orange
        return '#cc5854';                  // red
    }    
}
BMF_RSI_Form_Shortcodes::init();

class BMF_RSI_Section_Service {

    /**
     * R11 (form_id=11) section_ids grouped like BSI Drivers / Mediators / Outcomes.
     *
     * Drivers:   Digestive(51), Environmental(54), Lifestyle(55)
     * Mediators: Metabolic(49), Inflammatory(50), Emotional(52)
     * Outcomes:  Biological Strain(48), Recovery(53), Adaptive Capacity(56)
     */
    public static function dimension_section_ids(): array {
        return [
            'drivers'   => [ 51, 54, 55 ],
            'mediators' => [ 49, 50, 52 ],
            'outcomes'  => [ 48, 53, 56 ],
        ];
    }

    /** Human labels for R11 sections (form_id=11). */
    public static function section_labels(): array {
        return [
            48 => 'Biological Strain',
            49 => 'Metabolic',
            50 => 'Inflammatory',
            51 => 'Digestive',
            52 => 'Emotional',
            53 => 'Recovery',
            54 => 'Environmental',
            55 => 'Lifestyle',
            56 => 'Adaptive Capacity',
        ];
    }

    public static function dimension_group_labels(): array {
        return [
            'drivers'   => 'Drivers',
            'mediators' => 'Mediators',
            'outcomes'  => 'Outcomes',
        ];
    }

    /**
     * Batch current + previous section scores for all dimension sections.
     * Returns [ section_id => ['score'=>?float,'prev'=>?float,'delta'=>?float], ... ]
     * plus group averages under keys drivers/mediators/outcomes.
     */
    public static function get_dimensions_snapshot( $user_id, $form_id = 11, $date_str = null ): array {
        $map    = self::dimension_section_ids();
        $labels = self::section_labels();
        $out    = [ 'sections' => [], 'groups' => [] ];

        foreach ( $map as $group => $ids ) {
            $cur_vals  = [];
            $prev_vals = [];
            foreach ( $ids as $sid ) {
                $cur  = self::get_section_score( $user_id, $form_id, $sid, $date_str );
                $prev = self::get_previous_section_score( $user_id, $form_id, $sid, $date_str );
                $delta = ( $cur !== null && $prev !== null ) ? round( (float) $cur - (float) $prev, 2 ) : null;
                $out['sections'][ $sid ] = [
                    'id'    => $sid,
                    'label' => $labels[ $sid ] ?? ( 'Section ' . $sid ),
                    'group' => $group,
                    'score' => $cur,
                    'prev'  => $prev,
                    'delta' => $delta,
                ];
                if ( $cur !== null )  $cur_vals[]  = (float) $cur;
                if ( $prev !== null ) $prev_vals[] = (float) $prev;
            }
            $cur_avg  = ! empty( $cur_vals )  ? round( array_sum( $cur_vals )  / count( $cur_vals ),  1 ) : null;
            $prev_avg = ! empty( $prev_vals ) ? round( array_sum( $prev_vals ) / count( $prev_vals ), 1 ) : null;
            $out['groups'][ $group ] = [
                'score' => $cur_avg,
                'prev'  => $prev_avg,
                'delta' => ( $cur_avg !== null && $prev_avg !== null ) ? round( $cur_avg - $prev_avg, 1 ) : null,
            ];
        }
        return $out;
    }

    /**
     * Resolve form_id=11 response id for a user at/near a given results date.
     *
     * @param string|null $date_str YYYY-MM-DD; null = latest submitted
     * @param bool        $on_or_before when true and date given, prefer DATE(submitted_at) <= date
     */
    public static function resolve_response_id( $user_id, $form_id, $date_str = null, $on_or_before = false ) {
        global $wpdb;
        $user_id = (int) $user_id;
        $form_id = (int) $form_id;
        if ( ! $user_id || ! $form_id ) return 0;

        if ( $date_str === null && isset( $_GET['rsi_date'] ) ) {
            $date_str = $_GET['rsi_date'];
        }
        $date_str = BMF_RSI_Form_Service::normalize_date_str( $date_str );

        if ( $date_str ) {
            // Exact same-day match first
            $response_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}bm_responses
                     WHERE user_id = %d AND form_id = %d
                       AND submitted_at IS NOT NULL
                       AND DATE(submitted_at) = %s
                     ORDER BY submitted_at DESC LIMIT 1",
                    $user_id, $form_id, $date_str
                )
            );
            if ( $response_id ) return $response_id;

            if ( $on_or_before ) {
                $response_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}bm_responses
                         WHERE user_id = %d AND form_id = %d
                           AND submitted_at IS NOT NULL
                           AND DATE(submitted_at) <= %s
                         ORDER BY submitted_at DESC LIMIT 1",
                        $user_id, $form_id, $date_str
                    )
                );
                if ( $response_id ) return $response_id;
            }
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}bm_responses
                 WHERE user_id = %d AND form_id = %d
                   AND submitted_at IS NOT NULL
                 ORDER BY submitted_at DESC LIMIT 1",
                $user_id, $form_id
            )
        );
    }

    /**
     * Get section score (0–100) for the user's RSI response.
     * $date_str optional; falls back to ?rsi_date= then latest.
     */
    public static function get_section_score( $user_id, $form_id, $section_id, $date_str = null ) {
        global $wpdb;

        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) return null;

        $section_id  = (int) $section_id;
        $response_id = self::resolve_response_id( $user_id, $form_id, $date_str, false );
        if ( ! $response_id ) return null;

        $score = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT score FROM {$wpdb->prefix}bm_section_scores
                 WHERE response_id = %d AND section_id = %d LIMIT 1",
                $response_id, $section_id
            )
        );
        if ( $score === null ) return null;

        return round( (float) $score * 100, 2 );
    }

    /**
     * Section score from the previous final RSI assessment (before $date_str / current).
     */
    public static function get_previous_section_score( $user_id, $form_id, $section_id, $date_str = null ) {
        $prev_row = BMF_RSI_Form_Service::get_previous_results_row_for_user( $user_id, $date_str );
        if ( ! $prev_row || empty( $prev_row['results_date'] ) ) return null;

        $prev_date = BMF_RSI_Form_Service::normalize_date_str( $prev_row['results_date'] );
        if ( ! $prev_date ) return null;

        // Prefer exact date; fall back to on-or-before so sparse R11 submissions still resolve
        $score = self::get_section_score( $user_id, $form_id, $section_id, $prev_date );
        if ( $score !== null ) return $score;

        global $wpdb;
        $response_id = self::resolve_response_id( $user_id, $form_id, $prev_date, true );
        if ( ! $response_id ) return null;

        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT score FROM {$wpdb->prefix}bm_section_scores
                 WHERE response_id = %d AND section_id = %d LIMIT 1",
                $response_id, (int) $section_id
            )
        );
        if ( $raw === null ) return null;
        return round( (float) $raw * 100, 2 );
    }
}