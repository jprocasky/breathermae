<?php
/**
 * Breathermae BSI – Section Score Shortcodes
 * Date-aware: respects ?bsi_date=YYYY-MM-DD from the history dropdown
 * (same pattern as RSI section scores + BSI form shortcodes)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

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

class BMF_BSI_Section_Service {

    /**
     * Normalize a date string to YYYY-MM-DD (mirrors form service).
     */
    public static function normalize_date_str( $date_str ) {
        if ( empty( $date_str ) ) return null;
        if ( class_exists( 'BMF_BSI_Form_Service' ) && method_exists( 'BMF_BSI_Form_Service', 'normalize_date_str' ) ) {
            return BMF_BSI_Form_Service::normalize_date_str( $date_str );
        }
        $date_str = sanitize_text_field( $date_str );
        if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $date_str, $m ) ) {
            return $m[1];
        }
        $ts = strtotime( $date_str );
        return ( $ts !== false ) ? date( 'Y-m-d', $ts ) : null;
    }

    /**
     * Section score for a user, optionally pinned to a specific assessment date.
     *
     * When $date_str is provided (or ?bsi_date is present), returns the most recent
     * section score whose response was submitted on or before that date ("as of").
     * Falls back to the absolute latest score when no date is given.
     *
     * @return array{score_percent:float,updated_at:?string,raw_score:float}|null
     */
    public static function get_user_section_score( $user_id, $section_id, $date_str = null ) {
        $db   = BMF_BSI_DBX::$db;
        $t_sc = BMF_BSI_DBX::t( 'bm_section_scores' );
        $t_rs = BMF_BSI_DBX::t( 'bm_responses' );

        $user_id    = (int) $user_id;
        $section_id = (int) $section_id;
        if ( ! $user_id || ! $section_id ) return null;

        // Prefer explicit arg; otherwise read the history dropdown param
        if ( $date_str === null && isset( $_GET['bsi_date'] ) ) {
            $date_str = $_GET['bsi_date'];
        }
        $date_str = self::normalize_date_str( $date_str );

        $row = null;

        if ( $date_str ) {
            // "As of" the selected assessment date:
            // BSI forms are completed over multiple days; results_date is set only when
            // all 9 pillars finalize. Exact DATE equality almost never matches, so we take
            // the most recent section score whose response was submitted on or before
            // the selected bsi_date (historical snapshot as of that day).
            $sql = $db->prepare(
                "SELECT s.score, s.created_at, r.submitted_at
                 FROM {$t_sc} s
                 JOIN {$t_rs} r ON r.id = s.response_id
                 WHERE r.user_id = %d
                   AND s.section_id = %d
                   AND r.submitted_at IS NOT NULL
                   AND DATE(r.submitted_at) <= %s
                 ORDER BY r.submitted_at DESC, s.id DESC
                 LIMIT 1",
                $user_id, $section_id, $date_str
            );
            $row = $db->get_row( $sql, ARRAY_A );
        }

        // No date (or nothing on/before that date): most recent score for this section
        if ( ! $row ) {
            $sql = $db->prepare(
                "SELECT s.score, s.created_at, r.submitted_at
                 FROM {$t_sc} s
                 JOIN {$t_rs} r ON r.id = s.response_id
                 WHERE r.user_id = %d
                   AND s.section_id = %d
                 ORDER BY r.submitted_at DESC, s.created_at DESC, s.id DESC
                 LIMIT 1",
                $user_id, $section_id
            );
            $row = $db->get_row( $sql, ARRAY_A );
        }

        if ( ! $row ) return null;

        $raw = (float) $row['score'];
        $pct = ( $raw <= 1.0 ) ? round( $raw * 100, 2 ) : round( $raw, 2 );
        $updated = ! empty( $row['submitted_at'] ) ? $row['submitted_at'] : ( $row['created_at'] ?? null );

        return [
            'score_percent' => $pct,
            'updated_at'    => $updated,
            'raw_score'     => $raw,
        ];
    }

    /** Back-compat alias */
    public static function get_latest_user_section_score( $user_id, $section_id ) {
        return self::get_user_section_score( $user_id, $section_id, null );
    }

    public static function resolve_section_lookup( $section_id, $score_percent ) {
        $db   = BMF_BSI_DBX::$db;
        $t_lu = BMF_BSI_DBX::t( 'bm_bsi_section_lookup' );

        $sql = $db->prepare(
            "SELECT section_title, section_text, section_color,
                    l1, l2, l3
             FROM {$t_lu}
             WHERE section_id = %d
               AND %f >= low_value
               AND %f <  high_value
             ORDER BY id ASC
             LIMIT 1",
            $section_id, $score_percent, $score_percent
        );

        $row = $db->get_row( $sql, ARRAY_A );
        return $row ?: null;
    }
}

class BMF_BSI_Section_Shortcodes {

    public static function init() {
        add_shortcode( 'bmf_bsi_section',           [ __CLASS__, 'shortcode_section' ] );
        add_shortcode( 'bmf_bsi_has_section_score', [ __CLASS__, 'shortcode_has_score' ] );
        add_shortcode( 'bmf_bsi_section_wrapper',   [ __CLASS__, 'shortcode_section_wrapper' ] );
        add_shortcode( 'bmf_bsi_section_icon',      [ __CLASS__, 'shortcode_section_icon' ] );
        add_shortcode( 'bmf_bsi_section_gauge',     [ __CLASS__, 'shortcode_section_gauge' ] );
        add_action( 'bmf_bsi_section_scores_updated', [ __CLASS__, 'invalidate_cache' ], 10, 2 );
    }

    private static function should_bail_for_editor(): bool {
        $disable = apply_filters( 'bmf/shortcodes/disable_in_elementor', true );
        if ( ! $disable ) return false;
        return function_exists( 'bmf_in_elementor_editor' ) && bmf_in_elementor_editor();
    }

    public static function shortcode_section_wrapper( $atts, $content = null ) {
        if ( self::should_bail_for_editor() ) return '';
        $atts = shortcode_atts( [ 'section' => '', 'user_id' => get_current_user_id(), 'class' => '' ], $atts, 'bmf_bsi_section_wrapper' );
        if ( ! is_numeric( $atts['section'] ) ) return do_shortcode( $content );
        $section_id = (int) $atts['section'];
        $user_id    = (int) $atts['user_id'];
        $color = do_shortcode( sprintf( '[bmf_bsi_section section="%d" field="section_color" user_id="%d"]', $section_id, $user_id ) );
        if ( empty( $color ) ) $color = '#cccccc';
        $class = 'bmf-bsi-section-card' . ( $atts['class'] ? ' ' . sanitize_html_class( $atts['class'] ) : '' );
        return sprintf( '<div class="%s" style="--bsi-section-color:%s;">%s</div>', esc_attr( $class ), esc_attr( $color ), do_shortcode( $content ) );
    }

    public static function invalidate_cache( $user_id, $section_id ) {
        // Wipe all date variants for this user/section (latest + any historical keys)
        $user_id    = (int) $user_id;
        $section_id = (int) $section_id;
        delete_transient( self::cache_key( $user_id, $section_id, 'latest' ) );
        // Also try common historical keys if we know the date; otherwise the TTL will expire them
        if ( isset( $_GET['bsi_date'] ) ) {
            $d = BMF_BSI_Section_Service::normalize_date_str( $_GET['bsi_date'] );
            if ( $d ) delete_transient( self::cache_key( $user_id, $section_id, $d ) );
        }
    }

    private static function cache_key( $user_id, $section_id, $date_key = 'latest' ) {
        return "bmf_bsi_section_{$user_id}_{$section_id}_{$date_key}";
    }

    public static function shortcode_section( $atts ) {
        if ( self::should_bail_for_editor() ) return '';

        $atts = shortcode_atts( [
            'section'   => '',
            'field'     => 'score',
            'user_id'   => get_current_user_id(),
            'cache_ttl' => 600,
            'date'      => '', // optional explicit date; otherwise uses ?bsi_date
        ], $atts, 'bmf_bsi_section' );

        $user_id    = (int) $atts['user_id'];
        $section_id = is_numeric( $atts['section'] ) ? (int) $atts['section'] : 0;
        $cache_ttl  = max( 0, (int) $atts['cache_ttl'] );
        if ( ! $user_id || ! $section_id ) return '';

        // Resolve date: explicit attr → querystring → null (latest)
        $date_str = '';
        if ( ! empty( $atts['date'] ) ) {
            $date_str = BMF_BSI_Section_Service::normalize_date_str( $atts['date'] );
        } elseif ( isset( $_GET['bsi_date'] ) ) {
            $date_str = BMF_BSI_Section_Service::normalize_date_str( $_GET['bsi_date'] );
        }
        $date_key = $date_str ?: 'latest';

        $ckey = self::cache_key( $user_id, $section_id, $date_key );
        $data = get_transient( $ckey );

        if ( ! is_array( $data ) ) {
            $scoreRow = BMF_BSI_Section_Service::get_user_section_score( $user_id, $section_id, $date_str ?: null );
            if ( ! $scoreRow ) {
                $data = [
                    'score'         => '',
                    'section_title' => '',
                    'section_text'  => '',
                    'section_color' => '',
                    'l1'            => '',
                    'l2'            => '',
                    'l3'            => '',
                    'updated_at'    => '',
                ];
            } else {
                $score_pct = $scoreRow['score_percent'];
                $lookup    = BMF_BSI_Section_Service::resolve_section_lookup( $section_id, $score_pct ) ?: [];
                $data = [
                    'score'         => (string) $score_pct,
                    'section_title' => $lookup['section_title'] ?? '',
                    'section_text'  => $lookup['section_text']  ?? '',
                    'section_color' => $lookup['section_color'] ?? '',
                    'l1'            => $lookup['l1']            ?? '',
                    'l2'            => $lookup['l2']            ?? '',
                    'l3'            => $lookup['l3']            ?? '',
                    'updated_at'    => ! empty( $scoreRow['updated_at'] ) ? substr( $scoreRow['updated_at'], 0, 10 ) : '',
                ];
            }
            if ( $cache_ttl > 0 ) set_transient( $ckey, $data, $cache_ttl );
        }

        $field = sanitize_key( $atts['field'] );
        $val   = isset( $data[ $field ] ) ? $data[ $field ] : '';
        return esc_html( (string) $val );
    }

    public static function shortcode_has_score( $atts ) {
        if ( self::should_bail_for_editor() ) return '';
        $atts = shortcode_atts( [ 'section' => '', 'user_id' => get_current_user_id() ], $atts, 'bmf_bsi_has_section_score' );
        $user_id    = (int) $atts['user_id'];
        $section_id = is_numeric( $atts['section'] ) ? (int) $atts['section'] : 0;
        if ( ! $user_id || ! $section_id ) return '0';

        $date_str = isset( $_GET['bsi_date'] ) ? BMF_BSI_Section_Service::normalize_date_str( $_GET['bsi_date'] ) : null;
        $row = BMF_BSI_Section_Service::get_user_section_score( $user_id, $section_id, $date_str );
        return $row ? '1' : '0';
    }

    /**
     * SECTION ICON with outline + anti-clip padding
     * Score/color come from [bmf_bsi_section] which is now date-aware via ?bsi_date.
     */
    public static function shortcode_section_icon( $atts ) {
        if ( self::should_bail_for_editor() ) return '';

        $atts = shortcode_atts( [
            'section'         => '',
            'shape'           => 'circle',
            'size'            => '24',
            'stroke_width'    => '2',
            'user_id'         => get_current_user_id(),
            'class'           => '',
            'title'           => '',
            'outline_color'   => '#000000',
            'outline_width'   => '0',
            'show_value'      => '0',
            'value_font_size' => '11',
            'value_color'     => '#FFFFFF',
            'value_weight'    => '600',
            'value_offset_y'  => '0',
        ], $atts, 'bmf_bsi_section_icon' );

        $section_id = is_numeric( $atts['section'] ) ? (int) $atts['section'] : 0;
        $user_id    = (int) $atts['user_id'];
        if ( ! $section_id || ! $user_id ) return '';

        $size          = max( 8, (int) $atts['size'] );
        $stroke_w      = max( 1, (int) $atts['stroke_width'] );
        $shape         = strtolower( trim( (string) $atts['shape'] ) );
        $class         = trim( (string) $atts['class'] );
        $title         = trim( (string) $atts['title'] );
        $outline_color = trim( (string) $atts['outline_color'] );
        $outline_w     = max( 0, (int) $atts['outline_width'] );

        // Nested shortcode picks up ?bsi_date automatically
        $color = do_shortcode( sprintf(
            '[bmf_bsi_section section="%d" field="section_color" user_id="%d"]',
            $section_id, $user_id
        ) );
        if ( empty( $color ) ) $color = '#cccccc';
        $color = esc_attr( $color );

        $score_str = do_shortcode( sprintf(
            '[bmf_bsi_section section="%d" field="score" user_id="%d"]',
            $section_id, $user_id
        ) );
        $score_val = is_numeric( $score_str ) ? max( 0, min( 100, (float) $score_str ) ) : null;
        $label     = ( $score_val === null ) ? '' : number_format( (float) round( $score_val ), 0, '.', '' );

        $classes  = 'bmf-bsi-section-icon' . ( $class ? ' ' . sanitize_html_class( $class ) : '' );
        $half     = $size / 2;
        $svg_open = sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-hidden="%s" xmlns="http://www.w3.org/2000/svg">',
            esc_attr( $classes ), $size, $size, $size, $size, $title ? 'false' : 'true'
        );
        $svg_title = $title ? '<title>' . esc_html( $title ) . '</title>' : '';

        $pad_edge   = ( $shape === 'ring' ) ? $stroke_w : $outline_w;
        $shape_html = '';

        switch ( $shape ) {
            case 'ring': {
                $r = max( 1, $half - $stroke_w );
                $r = max( 1, $r - ( $pad_edge / 2 ) );
                $shape_html = sprintf(
                    '<circle cx="%1$d" cy="%1$d" r="%2$d" fill="none" stroke="%3$s" stroke-width="%4$d" />',
                    $half, (int) $r, $color, $stroke_w
                );
                break;
            }
            case 'square': {
                $rx = max( 0, (int) round( $size * 0.12 ) );
                $x  = $pad_edge;
                $y  = $pad_edge;
                $w  = max( 1, $size - 2 * $pad_edge );
                if ( $outline_w > 0 ) {
                    $shape_html = sprintf(
                        '<rect x="%1$d" y="%2$d" width="%3$d" height="%3$d" rx="%4$d" ry="%4$d" fill="%5$s" stroke="%6$s" stroke-width="%7$d" />',
                        (int) $x, (int) $y, (int) $w, (int) $rx, $color, esc_attr( $outline_color ), $outline_w
                    );
                } else {
                    $shape_html = sprintf(
                        '<rect x="%1$d" y="%2$d" width="%3$d" height="%3$d" rx="%4$d" ry="%4$d" fill="%5$s" />',
                        (int) $x, (int) $y, (int) $w, (int) $rx, $color
                    );
                }
                break;
            }
            case 'circle': {
                $r = max( 1, $half - $pad_edge );
                if ( $outline_w > 0 ) {
                    $shape_html = sprintf(
                        '<circle cx="%1$d" cy="%1$d" r="%2$d" fill="%3$s" stroke="%4$s" stroke-width="%5$d" />',
                        $half, (int) $r, $color, esc_attr( $outline_color ), $outline_w
                    );
                } else {
                    $shape_html = sprintf(
                        '<circle cx="%1$d" cy="%1$d" r="%2$d" fill="%3$s" />',
                        $half, (int) $r, $color
                    );
                }
                break;
            }
            case 'diamond':
            default: {
                $inner = max( (int) $pad_edge, (int) round( $size * 0.1 ) );
                $x1 = $half;          $y1 = $inner;
                $x2 = $size - $inner; $y2 = $half;
                $x3 = $half;          $y3 = $size - $inner;
                $x4 = $inner;         $y4 = $half;
                if ( $outline_w > 0 ) {
                    $shape_html = sprintf(
                        '<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" stroke="%10$s" stroke-width="%11$d" />',
                        (int) $x1, (int) $y1, (int) $x2, (int) $y2, (int) $x3, (int) $y3, (int) $x4, (int) $y4,
                        $color, esc_attr( $outline_color ), $outline_w
                    );
                } else {
                    $shape_html = sprintf(
                        '<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" />',
                        (int) $x1, (int) $y1, (int) $x2, (int) $y2, (int) $x3, (int) $y3, (int) $x4, (int) $y4,
                        $color
                    );
                }
                break;
            }
        }

        $svg_label = '';
        if ( (int) $atts['show_value'] === 1 && $label !== '' ) {
            $fs   = max( 6, (int) $atts['value_font_size'] );
            $fclr = trim( (string) $atts['value_color'] );
            $fw   = preg_match( '/^(100|200|300|400|500|600|700|800|900|bold|normal)$/', (string) $atts['value_weight'] )
                  ? (string) $atts['value_weight'] : '600';
            $offY = (int) $atts['value_offset_y'];
            $tx   = (int) round( $half );
            $ty   = (int) round( $half + $offY );
            $svg_label = sprintf(
                '<text x="%d" y="%d" text-anchor="middle" dominant-baseline="middle" font-size="%d" font-weight="%s" fill="%s">%s</text>',
                $tx, $ty, $fs, esc_attr( $fw ), esc_attr( $fclr ), esc_html( $label )
            );
        }

        return $svg_open . $svg_title . $shape_html . $svg_label . '</svg>';
    }

    /**
     * SECTION GAUGE – score/color from date-aware [bmf_bsi_section].
     */
    public static function shortcode_section_gauge( $atts ) {
        if ( self::should_bail_for_editor() ) return '';

        $atts = shortcode_atts( [
            'section'              => '',
            'user_id'              => get_current_user_id(),
            'width'                => '280',
            'height'               => '24',
            'thickness'            => '6',
            'radius'               => '3',
            'bg'                   => '#E6E9EF',
            'fill_bg'              => '#CBD2E1',
            'marker'               => 'diamond',
            'marker_size'          => '12',
            'stroke_width'         => '2',
            'marker_outline_color' => '#000000',
            'marker_outline_width' => '0',
            'class'                => '',
            'show_value'           => '0',
            'title'                => '',
            'value_font_size'      => '11',
            'value_offset_y'       => '',
            'value_offset_x'       => '',
        ], $atts, 'bmf_bsi_section_gauge' );

        $section_id = is_numeric( $atts['section'] ) ? (int) $atts['section'] : 0;
        $user_id    = (int) $atts['user_id'];
        if ( ! $section_id || ! $user_id ) return '';

        $width     = max( 120, (int) $atts['width'] );
        $height    = max( 16,  (int) $atts['height'] );
        $thickness = max( 2,   (int) $atts['thickness'] );
        $radius    = max( 0,   (int) $atts['radius'] );
        $bg        = trim( (string) $atts['bg'] );
        $fill_bg   = trim( (string) $atts['fill_bg'] );
        $marker    = strtolower( trim( (string) $atts['marker'] ) );
        $marker_sz = max( 6, (int) $atts['marker_size'] );
        $class     = trim( (string) $atts['class'] );
        $show_val  = ( (int) $atts['show_value'] === 1 );
        $title     = trim( (string) $atts['title'] );

        $marker_outline_color = trim( (string) $atts['marker_outline_color'] );
        $marker_outline_w     = max( 0, (int) $atts['marker_outline_width'] );

        // Nested shortcodes are date-aware via ?bsi_date
        $score_str = do_shortcode( sprintf( '[bmf_bsi_section section="%d" field="score" user_id="%d"]', $section_id, $user_id ) );
        $color     = do_shortcode( sprintf( '[bmf_bsi_section section="%d" field="section_color" user_id="%d"]', $section_id, $user_id ) );
        $score     = is_numeric( $score_str ) ? (float) $score_str : null;
        if ( $score === null ) return '';
        $score = max( 0, min( 100, $score ) );
        if ( empty( $color ) ) $color = '#cccccc';

        $padding_y = max( (int) floor( ( $height - $thickness ) / 2 ), 0 );
        $bar_y     = $padding_y;
        $bar_h     = $thickness;
        $pad_x     = max( 6, (int) ceil( $marker_sz / 2 ) );
        $bar_x     = $pad_x;
        $bar_w     = max( 10, $width - 2 * $pad_x );
        $t         = $score / 100.0;
        $mx        = $bar_x + $t * $bar_w;
        $my        = (int) floor( $height / 2 );
        $classes   = 'bmf-bsi-section-gauge';
        if ( $class ) $classes .= ' ' . sanitize_html_class( $class );

        $svg = sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-hidden="%s" xmlns="http://www.w3.org/2000/svg">',
            esc_attr( $classes ), $width, $height, $width, $height, $title ? 'false' : 'true'
        );
        if ( $title ) $svg .= '<title>' . esc_html( $title ) . '</title>';

        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d" fill="%s" />',
            $bar_x, $bar_y, $bar_w, $bar_h, $radius, $radius, esc_attr( $bg )
        );
        if ( $fill_bg !== '' ) {
            $fill_w = max( 0, (int) round( ( $mx - $bar_x ) ) );
            if ( $fill_w > 0 ) {
                $svg .= sprintf(
                    '<rect x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d" fill="%s" />',
                    $bar_x, $bar_y, $fill_w, $bar_h, $radius, $radius, esc_attr( $fill_bg )
                );
            }
        }

        switch ( $marker ) {
            case 'triangle':
                $h  = $marker_sz;
                $hh = max( 3, (int) round( $marker_sz * 0.58 ) );
                $x1 = (int) round( $mx );       $y1 = $my - (int) round( $h / 2 );
                $x2 = (int) round( $mx - $hh ); $y2 = $my + (int) round( $h / 2 );
                $x3 = (int) round( $mx + $hh ); $y3 = $my + (int) round( $h / 2 );
                if ( $marker_outline_w > 0 ) {
                    $svg .= sprintf(
                        '<polygon points="%d,%d %d,%d %d,%d" fill="%s" stroke="%s" stroke-width="%d" />',
                        $x1, $y1, $x2, $y2, $x3, $y3, esc_attr( $color ), esc_attr( $marker_outline_color ), $marker_outline_w
                    );
                } else {
                    $svg .= sprintf(
                        '<polygon points="%d,%d %d,%d %d,%d" fill="%s" />',
                        $x1, $y1, $x2, $y2, $x3, $y3, esc_attr( $color )
                    );
                }
                break;

            case 'none':
                break;

            case 'diamond':
            default:
                $pad = max( 0, (int) round( $marker_sz * 0.1 ) );
                $x1 = (int) round( $mx );                        $y1 = $my - $marker_sz / 2 + $pad;
                $x2 = (int) round( $mx + $marker_sz / 2 - $pad ); $y2 = $my;
                $x3 = (int) round( $mx );                        $y3 = $my + $marker_sz / 2 - $pad;
                $x4 = (int) round( $mx - $marker_sz / 2 + $pad ); $y4 = $my;
                if ( $marker_outline_w > 0 ) {
                    $svg .= sprintf(
                        '<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" stroke="%10$s" stroke-width="%11$d" />',
                        $x1, (int) $y1, $x2, (int) $y2, $x3, (int) $y3, $x4, (int) $y4,
                        esc_attr( $color ), esc_attr( $marker_outline_color ), $marker_outline_w
                    );
                } else {
                    $svg .= sprintf(
                        '<polygon points="%1$d,%2$d %3$d,%4$d %5$d,%6$d %7$d,%8$d" fill="%9$s" />',
                        $x1, (int) $y1, $x2, (int) $y2, $x3, (int) $y3, $x4, (int) $y4,
                        esc_attr( $color )
                    );
                }
                break;
        }

        if ( $show_val ) {
            $label = number_format( (float) round( $score ), 0, '.', '' );
            $val_fs        = max( 6, (int) $atts['value_font_size'] );
            $default_off_y = -(int) round( $marker_sz * 0.9 );
            $off_y_raw     = trim( (string) $atts['value_offset_y'] );
            $off_y         = ( $off_y_raw === '' || ! is_numeric( $off_y_raw ) ) ? $default_off_y : (int) $off_y_raw;
            $off_x_raw     = trim( (string) $atts['value_offset_x'] );
            $off_x         = ( $off_x_raw === '' || ! is_numeric( $off_x_raw ) )
                ? (int) max( 6, round( $marker_sz * 0.9 ) )
                : (int) $off_x_raw;

            if ( $t < 0.5 ) {
                $anchor = 'start';
                $tx     = (int) round( $mx + $off_x );
            } else {
                $anchor = 'end';
                $tx     = (int) round( $mx - $off_x );
            }
            $tx = (int) max( 2, min( $width - 2, $tx ) );
            $ty = (int) round( $my + $off_y );
            $ty = (int) max( 2, min( $height - 2, $ty ) );

            $svg .= sprintf(
                '<text x="%d" y="%d" text-anchor="%s" dominant-baseline="middle" font-size="%d" fill="#333">%s</text>',
                $tx, $ty, esc_attr( $anchor ), $val_fs, esc_html( $label )
            );
        }

        $svg .= '</svg>';
        return $svg;
    }
}
BMF_BSI_Section_Shortcodes::init();
