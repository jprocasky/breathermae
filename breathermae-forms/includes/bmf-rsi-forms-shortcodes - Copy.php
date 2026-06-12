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
        $row = self::get_results_row_for_user( $user_id );
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
        $row = self::get_results_row_for_user( $user_id );
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
}

/** Shortcodes */
class BMF_RSI_Form_Shortcodes {

    public static function init() {
        add_shortcode( 'bmf_rsi_form',          [ __CLASS__, 'shortcode_form' ] );
        add_shortcode( 'bmf_rsi_form_icon',     [ __CLASS__, 'shortcode_form_icon' ] );
        add_shortcode( 'bmf_rsi_form_gauge',    [ __CLASS__, 'shortcode_form_gauge' ] );
        add_shortcode( 'bmf_rsi_results_field', [ __CLASS__, 'shortcode_results_field' ] );
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
            'mode'          => 'snapshot',
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

        $ckey = self::cache_key( $user_id, $form_id, $mode, $overall_field . '_v4' ); // version suffix to force refresh on logic changes
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
            'mode' => 'snapshot',
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
            'mode'=>'snapshot',
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
     * [bmf_rsi_results_field field="R11|R12|R12_S6|master_score|readiness_score|R11_Notes|details_json|updated_at"
     *    user_id="" date="" format="text|number|date|json|html|raw" format_date="Y-m-d" decimals="2" autop="0" max_chars="0" mode="snapshot|latest"]
     */
    public static function shortcode_results_field( $atts ) {
        if (self::should_bail_for_editor()) return '';

        $atts = shortcode_atts( [
            'field' => '', 'user_id'=>get_current_user_id(), 'date'=>'',
            'format'=>'text', 'format_date'=>'Y-m-d', 'decimals'=>'2', 'autop'=>'0', 'max_chars'=>'0',
            'mode'=>'snapshot'
        ], $atts, 'bmf_rsi_results_field' );

        $user_id = (int)$atts['user_id']; if (!$user_id) return '';
        $field = trim( (string)$atts['field'] ); if ($field === '') return '';

        $cols = BMF_RSI_Form_Service::get_results_table_columns(); if ( empty( $cols[ $field ] ) ) return '';

        $mode = strtolower((string)$atts['mode']); if ($mode!=='latest') $mode='snapshot';

        if ( $mode === 'snapshot' ) {
            // Rolling latest-non-empty across all rows
            $db  = BMF_RSI_DBX::$db; $t_r = BMF_RSI_DBX::t('bm_rsi_results');
            $user = get_userdata($user_id); if ( ! $user || empty($user->user_email) ) return '';
            $email = $user->user_email;

            $rows = $db->get_results( $db->prepare("SELECT * FROM {$t_r} WHERE user_email = %s AND is_final = 1 ORDER BY results_date DESC, id DESC", $email), ARRAY_A );
            if ( ! $rows ) return '';

            $row = null; $value = '';
            foreach ($rows as $r) {
                if ( array_key_exists($field,$r) && $r[$field] !== null && $r[$field] !== '' ) { $row=$r; $value=$r[$field]; break; }
            }
            if ( ! $row ) return '';
        } else {
            $row = BMF_RSI_Form_Service::get_results_row_for_user( $user_id, $atts['date'] ?: null ); if ( ! $row ) return '';
            $value = array_key_exists($field,$row) && $row[$field] !== null ? $row[$field] : '';
        }

        $format   = strtolower( (string) $atts['format'] );
        $autop    = ((int)$atts['autop'] === 1);
        $max      = max( 0, (int)$atts['max_chars'] );
        $truncate = function( $text, $limit ) {
            $text = (string) $text;
            if ( $limit <= 0 || mb_strlen($text) <= $limit ) return $text;
            $cut = mb_substr($text, 0, $limit);
            $space = mb_strrpos($cut, ' ');
            if ( $space !== false && $space >= $limit - 20 ) $cut = mb_substr($cut, 0, $space);
            return rtrim($cut) . '…';
        };

        switch ($format) {
            case 'raw':
                $out = (string)$value; break;

            case 'html':
                $out = (string)$value; if ($autop) $out = wpautop($out); break;

            case 'number':
                if ($value === '') return '';
                $dec = max(0, (int)$atts['decimals']);
                $num = is_numeric($value) ? (float)$value : null; if ($num === null) return '';
                $out = number_format($num, $dec, '.', ','); break;

            case 'date':
                if (empty($value)) return '';
                $ts = strtotime((string)$value);
                $out = ($ts === false) ? esc_html((string)$value) : esc_html( date($atts['format_date'] ?: 'Y-m-d', $ts) );
                break;

            case 'json':
                if ($value === '') return '';
                $decoded = is_array($value) || is_object($value) ? $value : json_decode((string)$value, true);
                $out = ($decoded === null)
                    ? esc_html((string)$value)
                    : '<pre class="bmf-rsi-json">' . esc_html( json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ) . '</pre>';
                break;

            case 'text': default:
                $txt = (string)$value;
                if ($max > 0) $txt = $truncate($txt, $max);
                $out = nl2br( esc_html($txt) );
                if ($autop) { $raw = (string)$value; if ($max>0) $raw = $truncate($raw, $max); $out = wpautop( esc_html($raw) ); }
                break;
        }
        return $out;
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
     * Get section score (0–100) for the user's latest RSI response
     */
    public static function get_section_score( $user_id, $form_id, $section_id ) {
        global $wpdb;

        // Basic validation
        $user = get_userdata($user_id);
        if (!$user || empty($user->user_email)) return null;

        // 1️⃣ Find the most recent response for THIS FORM
        $response_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                FROM {$wpdb->prefix}bm_responses
                WHERE user_id = %d
                AND form_id = %d
                AND submitted_at IS NOT NULL
                ORDER BY submitted_at DESC
                LIMIT 1",
                $user_id,
                $form_id
            )
        );
        if (!$response_id) return null;

        // 2️⃣ Fetch section score for that response
        $score = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT score
                FROM {$wpdb->prefix}bm_section_scores
                WHERE response_id = %d
                AND section_id = %d
                LIMIT 1",
                $response_id,
                $section_id
            )
        );
        if ($score === null) return null;

        // 3️⃣ Normalize to 0–100
        return round((float)$score * 100, 2);
    }
}