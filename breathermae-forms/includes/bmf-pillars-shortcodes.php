<?php
/**
 * Breathermae Pillars – Comparison Shortcode
 *
 * Provides [bmf_pillars_comparison] for the extended 8-pillar results.
 * Reads uls_bm_pillars_results (is_final=1).
 * Supports ?pillars_date=YYYY-MM-DD query param (like RSI).
 *
 * Compares perceived rank (from 'rank' column) vs actual scores.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** DB accessor */
if ( ! class_exists( 'BMF_Pillars_DBX' ) ) {
    class BMF_Pillars_DBX {
        public static $db;
        public static function init() { global $wpdb; self::$db = $wpdb; }
        public static function t( $suffix ) { return self::$db->prefix . $suffix; }
    }
    BMF_Pillars_DBX::init();
} else {
    if ( empty( BMF_Pillars_DBX::$db ) ) { BMF_Pillars_DBX::init(); }
}

/**
 * Data service for pillars results.
 */
class BMF_Pillars_Service {

    /** Get the relevant finalized row (date-aware). */
    public static function get_results_row_for_user( $user_id, $date_str = null ) {
        $db  = BMF_Pillars_DBX::$db;
        $t_r = BMF_Pillars_DBX::t('bm_pillars_results');

        $user = get_userdata($user_id);
        if ( ! $user || empty($user->user_email) ) return null;
        $email = $user->user_email;

        // Exact date match
        if ( $date_str ) {
            $sql = $db->prepare(
                "SELECT * FROM {$t_r}
                 WHERE user_email = %s
                   AND results_date = %s
                   AND is_final = 1
                 ORDER BY id DESC LIMIT 1",
                $email, $date_str
            );
            $row = $db->get_row($sql, ARRAY_A);
            if ($row) return $row;

            // Same-day fallback
            if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str) ) {
                $sql = $db->prepare(
                    "SELECT * FROM {$t_r}
                     WHERE user_email = %s
                       AND DATE(results_date) = %s
                       AND is_final = 1
                     ORDER BY results_date DESC, id DESC LIMIT 1",
                    $email, $date_str
                );
                $row = $db->get_row($sql, ARRAY_A);
                if ($row) return $row;
            }
        }

        // Latest finalized
        $sql = $db->prepare(
            "SELECT * FROM {$t_r}
             WHERE user_email = %s AND is_final = 1
             ORDER BY results_date DESC, id DESC LIMIT 1",
            $email
        );
        return $db->get_row($sql, ARRAY_A) ?: null;
    }

    /** Get list of available dates for history selector. */
    public static function get_result_dates( $user_email ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bm_pillars_results';
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT results_date FROM {$table}
             WHERE user_email = %s AND is_final = 1
             ORDER BY results_date DESC",
            $user_email
        ) );
    }
}

/**
 * Shortcodes for pillars.
 */
class BMF_Pillars_Shortcodes {

    public static function init() {
        add_shortcode( 'bmf_pillars_comparison', [ __CLASS__, 'shortcode_comparison' ] );
        add_shortcode( 'bmf_pillars_history_select', [ __CLASS__, 'shortcode_history_select' ] );
    }

    /**
     * Main comparison view shortcode.
     * [bmf_pillars_comparison user_id="..." show_date_picker="1"]
     */
    public static function shortcode_comparison( $atts ) {
        if ( function_exists('bmf_in_elementor_editor') && bmf_in_elementor_editor() ) {
            return '<div style="padding:20px; background:#f0f0f0;">Pillars Comparison Preview (Editor Mode)</div>';
        }

        $atts = shortcode_atts( [
            'user_id'         => get_current_user_id(),
            'show_date_picker'=> '1',
            'class'           => 'bmf-pillars-comparison',
        ], $atts );

        $user_id = (int) $atts['user_id'];
        if ( ! $user_id || ! is_user_logged_in() || $user_id !== get_current_user_id() ) {
            return '<p>Please log in to view your results.</p>';
        }

        $date_str = isset( $_GET['pillars_date'] ) ? sanitize_text_field( $_GET['pillars_date'] ) : null;
        $row = BMF_Pillars_Service::get_results_row_for_user( $user_id, $date_str );

        if ( ! $row ) {
            return '<p>No finalized pillars assessment found.</p>';
        }

        // Pillar columns
        $pillars = [
            'physical'      => (float) ($row['physical'] ?? 0),
            'mental'        => (float) ($row['mental'] ?? 0),
            'emotional'     => (float) ($row['emotional'] ?? 0),
            'financial'     => (float) ($row['financial'] ?? 0),
            'occupational'  => (float) ($row['occupational'] ?? 0),
            'environmental' => (float) ($row['environmental'] ?? 0),
            'spiritual'     => (float) ($row['spiritual'] ?? 0),
            'social'        => (float) ($row['social'] ?? 0),
        ];

        // Normalize to percent
        foreach ( $pillars as $k => $v ) {
            $pillars[$k] = ( $v <= 1.0 ) ? round( $v * 100, 1 ) : round( $v, 1 );
        }

        // Actual ranking (sorted by score desc)
        arsort( $pillars ); // maintains keys
        $actual_labels = array_keys( $pillars );
        $actual_scores = array_values( $pillars );

        // Perceived rank from 'rank' column
        $rank_str = $row['rank'] ?? '';
        $perceived = [];
        if ( $rank_str ) {
            $decoded = urldecode( $rank_str );
            $perceived = array_map( 'ucfirst', array_map( 'trim', explode( ',', $decoded ) ) );
        }

        // Master score
        $master = isset( $row['master_score'] ) ? round( (float)$row['master_score'], 1 ) : null;

        ob_start();
        ?>
        <div class="<?php echo esc_attr( $atts['class'] ); ?>" style="max-width:800px; margin:0 auto;">
            <?php if ( $atts['show_date_picker'] && function_exists('bmf_pillars_history_select') ) : ?>
                <div style="text-align:right; margin-bottom:15px;">
                    <?php echo do_shortcode( '[bmf_pillars_history_select user_id="' . $user_id . '"]' ); ?>
                </div>
            <?php endif; ?>

            
            <?php if ( !empty( $row['results_date'] ) ) : ?>
                <p><strong>Date:</strong> <?php echo esc_html( $row['results_date'] ); ?></p>
            <?php endif; ?>

            <?php if ( $master !== null ) : ?>
                <p><strong>Overall Average Score:</strong> <?php echo esc_html( $master ); ?>%</p>
            <?php endif; ?>

            
            <div style="display: grid; grid-template-columns: 1fr auto 1fr; gap: 12px; align-items: center; font-size: 15px;">
                <?php
                $max_items = max( count( $perceived ), count( $actual_labels ) );
                for ( $i = 0; $i < $max_items; $i++ ) {
                    $perc_label = $perceived[ $i ] ?? '—';
                    $act_label  = ucfirst( $actual_labels[ $i ] ?? '—' );
                    $act_score  = $actual_scores[ $i ] ?? 0;

                    // Find position diff
                    $perc_pos = array_search( strtolower( $act_label ), array_map( 'strtolower', $perceived ) );
                    $diff = ( $perc_pos !== false ) ? ( $perc_pos - $i ) : 0;

                    $icon = '';
                    $color = '#999';
                    if ( $diff === 0 ) {
                        $icon = '✓';
                        $color = '#22c55e';
                    } elseif ( $diff < 0 ) {
                        $icon = '↑ ' . abs( $diff );
                        $color = '#3b82f6';
                    } elseif ( $diff > 0 ) {
                        $icon = '↓ ' . $diff;
                        $color = '#f97316';
                    }
                ?>
                    <!-- Perceived -->
                    <div style="background:#fff; border:1px solid #233b6d; border-radius:8px; padding:8px 12px; text-align:left;">
                        <?php echo esc_html( $perc_label ); ?>
                    </div>

                    <!-- Indicator -->
                    <div style="text-align:center; font-weight:600; color:<?php echo $color; ?>; min-width:60px;">
                        <?php echo esc_html( $icon ); ?>
                    </div>

                    <!-- Actual -->
                    <div style="background:#fff; border:1px solid #233b6d; border-radius:8px; padding:8px 12px; text-align:right;">
                        <?php echo esc_html( $act_label ); ?> <span style="font-size:0.9em; color:#666;">(<?php echo $act_score; ?>%)</span>
                    </div>
                <?php } ?>
            </div>

            <?php if ( !empty( $row['notes'] ) ) : ?>
                <h4>Notes</h4>
                <p><?php echo esc_html( $row['notes'] ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * History date selector.
     */
    public static function shortcode_history_select( $atts ) {
        $atts = shortcode_atts( [
            'user_id' => get_current_user_id(),
        ], $atts );

        $user = get_userdata( (int)$atts['user_id'] );
        if ( ! $user ) return '';

        $dates = BMF_Pillars_Service::get_result_dates( $user->user_email );
        if ( empty( $dates ) ) return '';

        $current = isset( $_GET['pillars_date'] ) ? sanitize_text_field( $_GET['pillars_date'] ) : ($dates[0] ?? '');

        ob_start();
        ?>
        <form method="get" style="display:inline;">
            <label for="pillars_date">Assessment Date: </label>
            <select name="pillars_date" onchange="this.form.submit();">
                <?php foreach ( $dates as $d ) : ?>
                    <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $current, $d ); ?>>
                        <?php echo esc_html( $d ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php
        return ob_get_clean();
    }
}

// Auto-init
add_action( 'init', [ 'BMF_Pillars_Shortcodes', 'init' ] );