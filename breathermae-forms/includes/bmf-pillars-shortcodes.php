<?php
/**
 * Breathermae Pillars – Comparison, Rapid history, interpretation, trend
 *
 * Full (paid) series: forms 18-25 + rank 26 → series='full'
 * Rapid (free) series: form 17 → series='rapid'
 *
 * [bmf_pillars_comparison]           full series only
 * [bmf_pillars_history_select]       full series only (?pillars_date=)
 * [bmf_interpretation]               Rapid by default; sticky from results row
 * [bmf_pillars_rapid_history_select] Rapid dates (?rapid_pillars_date=)
 * [bmf_pillars_rapid_trend]          Rapid master + 8-pillar trend chart
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

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

class BMF_Pillars_Service {

    public static function normalize_series( $series = 'full' ) {
        $series = strtolower( trim( (string) $series ) );
        return $series === 'rapid' ? 'rapid' : 'full';
    }

    public static function series_clause( $series = 'full', $column = 'series' ) {
        $series = self::normalize_series( $series );
        if ( $series === 'rapid' ) {
            return "{$column} = 'rapid'";
        }
        return class_exists( 'BMF_Pillars_Saver' )
            ? BMF_Pillars_Saver::series_is_full_sql( $column )
            : "( {$column} = 'full' OR {$column} IS NULL OR {$column} = '' )";
    }

    public static function get_results_row_for_user( $user_id, $date_str = null, $series = 'full' ) {
        $db  = BMF_Pillars_DBX::$db;
        $t_r = BMF_Pillars_DBX::t('bm_pillars_results');
        $series_sql = self::series_clause( $series );

        $user = get_userdata($user_id);
        if ( ! $user || empty($user->user_email) ) return null;
        $email = $user->user_email;

        if ( $date_str ) {
            $sql = $db->prepare(
                "SELECT * FROM {$t_r}
                 WHERE user_email = %s
                   AND results_date = %s
                   AND is_final = 1
                   AND {$series_sql}
                 ORDER BY id DESC LIMIT 1",
                $email, $date_str
            );
            $row = $db->get_row($sql, ARRAY_A);
            if ($row) return $row;

            if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str) ) {
                $sql = $db->prepare(
                    "SELECT * FROM {$t_r}
                     WHERE user_email = %s
                       AND DATE(results_date) = %s
                       AND is_final = 1
                       AND {$series_sql}
                     ORDER BY results_date DESC, id DESC LIMIT 1",
                    $email, $date_str
                );
                $row = $db->get_row($sql, ARRAY_A);
                if ($row) return $row;
            }
        }

        $sql = $db->prepare(
            "SELECT * FROM {$t_r}
             WHERE user_email = %s AND is_final = 1 AND {$series_sql}
             ORDER BY results_date DESC, id DESC LIMIT 1",
            $email
        );
        return $db->get_row($sql, ARRAY_A) ?: null;
    }

    public static function get_result_dates( $user_email, $series = 'full' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bm_pillars_results';
        $series_sql = self::series_clause( $series );
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT results_date FROM {$table}
             WHERE user_email = %s AND is_final = 1 AND {$series_sql}
             ORDER BY results_date DESC",
            $user_email
        ) );
    }

    public static function get_trend_rows( $user_email, $series = 'rapid' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bm_pillars_results';
        $series_sql = self::series_clause( $series );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE user_email = %s AND is_final = 1 AND {$series_sql}
             ORDER BY results_date ASC, id ASC",
            $user_email
        ), ARRAY_A );
        return $rows ?: [];
    }

    public static function latest_rapid_response_id( $user_id ) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return 0;
        }
        $ids = [ 17 ];
        if ( class_exists( 'BMF_Pillars_Saver' ) ) {
            $ids = [ BMF_Pillars_Saver::RAPID_FORM_ID ];
            if ( class_exists( 'BMF_Repository' ) ) {
                foreach ( BMF_Pillars_Saver::rapid_slugs() as $slug ) {
                    $row = BMF_Repository::get_form_by_slug( $slug );
                    if ( $row && ! empty( $row->id ) ) {
                        $ids[] = (int) $row->id;
                    }
                }
            }
        }
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        if ( ! $ids ) {
            return 0;
        }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $params = array_merge( [ $user_id ], $ids );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bm_responses
              WHERE user_id = %d
                AND form_id IN ({$placeholders})
                AND status = 'submitted'
              ORDER BY submitted_at DESC, id DESC
              LIMIT 1",
            $params
        ) );
    }

    public static function decode_interpretation( $row ) {
        if ( empty( $row['interpretation_json'] ) ) {
            return null;
        }
        $decoded = json_decode( $row['interpretation_json'], true );
        return is_array( $decoded ) ? $decoded : null;
    }

    public static function pillar_percents_from_row( $row ) {
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
        foreach ( $pillars as $k => $v ) {
            $pillars[$k] = ( $v <= 1.0 ) ? round( $v * 100, 1 ) : round( $v, 1 );
        }
        return $pillars;
    }
}

class BMF_Pillars_Shortcodes {

    public static function init() {
        add_shortcode( 'bmf_pillars_comparison', [ __CLASS__, 'shortcode_comparison' ] );
        add_shortcode( 'bmf_pillars_history_select', [ __CLASS__, 'shortcode_history_select' ] );
        add_shortcode( 'bmf_pillars_rapid_history_select', [ __CLASS__, 'shortcode_rapid_history_select' ] );
        add_shortcode( 'bmf_pillars_rapid_trend', [ __CLASS__, 'shortcode_rapid_trend' ] );
        add_shortcode( 'bmf_interpretation', [ __CLASS__, 'shortcode_interpretation' ] );
    }

    public static function shortcode_comparison( $atts ) {
        if ( function_exists('bmf_in_elementor_editor') && bmf_in_elementor_editor() ) {
            return '<div style="padding:20px; background:#f0f0f0;">Pillars Comparison Preview (Editor Mode)</div>';
        }

        $atts = shortcode_atts( [
            'user_id'         => get_current_user_id(),
            'show_date_picker'=> '1',
            'class'           => 'bmf-pillars-comparison',
            'series'          => 'full',
        ], $atts );

        $user_id = (int) $atts['user_id'];
        if ( ! $user_id || ! is_user_logged_in() || $user_id !== get_current_user_id() ) {
            return '<p>Please log in to view your results.</p>';
        }

        $series   = BMF_Pillars_Service::normalize_series( $atts['series'] );
        $date_key = $series === 'rapid' ? 'rapid_pillars_date' : 'pillars_date';
        $date_str = isset( $_GET[ $date_key ] ) ? sanitize_text_field( $_GET[ $date_key ] ) : null;
        $row = BMF_Pillars_Service::get_results_row_for_user( $user_id, $date_str, $series );

        if ( ! $row ) {
            return '<p>No finalized pillars assessment found.</p>';
        }

        $pillars = BMF_Pillars_Service::pillar_percents_from_row( $row );
        arsort( $pillars );
        $actual_labels = array_keys( $pillars );
        $actual_scores = array_values( $pillars );

        $rank_str = $row['rank'] ?? '';
        $perceived = [];
        if ( $rank_str ) {
            $decoded = urldecode( $rank_str );
            $perceived = array_map( 'ucfirst', array_map( 'trim', explode( ',', $decoded ) ) );
        }

        $master = isset( $row['master_score'] ) ? round( (float)$row['master_score'], 1 ) : null;

        ob_start();
        ?>
        <div class="<?php echo esc_attr( $atts['class'] ); ?>" style="max-width:800px; margin:0 auto;">
            <?php if ( $atts['show_date_picker'] ) : ?>
                <div style="text-align:right; margin-bottom:15px;">
                    <?php
                    $hist = $series === 'rapid' ? 'bmf_pillars_rapid_history_select' : 'bmf_pillars_history_select';
                    echo do_shortcode( '[' . $hist . ' user_id="' . $user_id . '"]' );
                    ?>
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

                    $perc_pos = array_search( strtolower( $act_label ), array_map( 'strtolower', $perceived ) );
                    $diff = ( $perc_pos !== false ) ? ( $perc_pos - $i ) : 0;

                    $icon = '';
                    $color = '#999';
                    if ( $diff === 0 ) {
                        $icon = '✓';
                        $color = '#22c55e';
                    } elseif ( $diff > 0 ) {
                        $icon = '↑ ' . abs( $diff );
                        $color = '#3b82f6';
                    } elseif ( $diff < 0 ) {
                        $icon = '↓ ' . $diff;
                        $color = '#f97316';
                    }
                ?>
                    <div style="background:#fff; border:1px solid #233b6d; border-radius:8px; padding:8px 12px; text-align:left;">
                        <?php echo esc_html( $perc_label ); ?>
                    </div>
                    <div style="text-align:center; font-weight:600; color:<?php echo $color; ?>; min-width:60px;">
                        <?php echo esc_html( $icon ); ?>
                    </div>
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

    public static function shortcode_history_select( $atts ) {
        $atts = shortcode_atts( [
            'user_id' => get_current_user_id(),
        ], $atts );

        $user = get_userdata( (int)$atts['user_id'] );
        if ( ! $user ) return '';

        $dates = BMF_Pillars_Service::get_result_dates( $user->user_email, 'full' );
        if ( empty( $dates ) ) return '';

        $current = isset( $_GET['pillars_date'] ) ? sanitize_text_field( $_GET['pillars_date'] ) : ($dates[0] ?? '');

        ob_start();
        ?>
        <form method="get" style="display:inline;">
            <label for="pillars_date">Assessment Date: </label>
            <select name="pillars_date" id="pillars_date" onchange="this.form.submit();">
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

    public static function shortcode_rapid_history_select( $atts ) {
        $atts = shortcode_atts( [
            'user_id' => get_current_user_id(),
        ], $atts );

        $user = get_userdata( (int)$atts['user_id'] );
        if ( ! $user ) return '';

        $dates = BMF_Pillars_Service::get_result_dates( $user->user_email, 'rapid' );
        if ( empty( $dates ) ) return '';

        $current = isset( $_GET['rapid_pillars_date'] ) ? sanitize_text_field( $_GET['rapid_pillars_date'] ) : ($dates[0] ?? '');

        ob_start();
        ?>
        <form method="get" style="display:inline;">
            <label for="rapid_pillars_date">Snapshot date: </label>
            <select name="rapid_pillars_date" id="rapid_pillars_date" onchange="this.form.submit();">
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

    public static function shortcode_interpretation( $atts ) {
        if ( function_exists('bmf_in_elementor_editor') && bmf_in_elementor_editor() ) {
            return '<div style="padding:20px; background:#f0f0f0;">Interpretation Preview (Editor Mode)</div>';
        }

        $atts = shortcode_atts( [
            'user_id'          => get_current_user_id(),
            'series'           => 'rapid',
            'show_date_picker' => '1',
        ], $atts, 'bmf_interpretation' );

        $user_id = (int) $atts['user_id'];
        if ( ! is_user_logged_in() ) {
            return '<div style="color:#dc3545; font-size:0.9rem;">Please log in to view your interpretation.</div>';
        }
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        if ( $user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            $selected = (int) get_user_meta( get_current_user_id(), 'uls_selected_user_id', true );
            if ( $selected !== $user_id ) {
                return '<div style="color:#dc3545; font-size:0.9rem;">Not authorized to view this interpretation.</div>';
            }
        }

        $series   = BMF_Pillars_Service::normalize_series( $atts['series'] );
        $date_key = $series === 'rapid' ? 'rapid_pillars_date' : 'pillars_date';
        $date_str = isset( $_GET[ $date_key ] ) ? sanitize_text_field( $_GET[ $date_key ] ) : null;

        $row    = BMF_Pillars_Service::get_results_row_for_user( $user_id, $date_str, $series );
        $interp = $row ? BMF_Pillars_Service::decode_interpretation( $row ) : null;

        if ( ( ! $interp || empty( $interp['summary'] ) ) && $row && ! empty( $row['source_response_id'] ) && class_exists( 'BMF_Interpreter' ) ) {
            $regen = BMF_Interpreter::interpret( (int) $row['source_response_id'] );
            if ( is_array( $regen ) && ! empty( $regen ) ) {
                $interp = $regen;
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'bm_pillars_results',
                    [ 'interpretation_json' => wp_json_encode( $regen ) ],
                    [ 'id' => (int) $row['id'] ],
                    [ '%s' ],
                    [ '%d' ]
                );
            }
        }

        if ( ( ! $interp || empty( $interp['summary'] ) ) && $series === 'rapid' ) {
            $rid = BMF_Pillars_Service::latest_rapid_response_id( $user_id );
            if ( $rid && class_exists( 'BMF_Interpreter' ) ) {
                $regen = BMF_Interpreter::interpret( $rid );
                if ( is_array( $regen ) && ! empty( $regen ) ) {
                    $interp = $regen;
                    if ( class_exists( 'BMF_Pillars_Saver' ) ) {
                        BMF_Pillars_Saver::save_rapid_from_response( $user_id, $rid, $regen );
                    }
                }
            }
        }

        if ( ! $interp || empty( $interp['summary'] ) ) {
            $meta_key = $series === 'rapid' ? 'bmf_interpretation_rapid' : 'bmf_interpretation';
            $raw = get_user_meta( $user_id, $meta_key, true );
            if ( ! $raw && $series === 'rapid' ) {
                $raw = get_user_meta( $user_id, 'bmf_interpretation', true );
            }
            $decoded = $raw ? json_decode( $raw, true ) : null;
            if ( is_array( $decoded ) && ! empty( $decoded['summary'] ) ) {
                $interp = $decoded;
            }
        }

        if ( ! $interp || empty( $interp['summary'] ) ) {
            return '<div style="color:#dc3545; font-size:0.8rem;">No interpretation available.</div>';
        }

        ob_start();
        if ( $atts['show_date_picker'] === '1' && $series === 'rapid' ) {
            echo '<div style="text-align:right;margin-bottom:12px;">';
            echo do_shortcode( '[bmf_pillars_rapid_history_select user_id="' . (int) $user_id . '"]' );
            echo '</div>';
        }
        echo self::render_interpretation_html( $interp, $row );
        return ob_get_clean();
    }

    public static function render_interpretation_html( $interp, $row = null ) {
        ob_start();

        if ( ! empty( $interp['type'] ) && $interp['type'] === 'pillars' ) {
            echo '<div class="bmf-results">';
            echo '<h3>Your Insights</h3>';
            if ( ! empty( $row['results_date'] ) ) {
                echo '<p style="color:#64748b;font-size:0.9rem;margin-top:-6px;">Snapshot date: ' . esc_html( $row['results_date'] ) . '</p>';
            }
            echo '<p>' . esc_html( $interp['summary'] ) . '</p>';

            if ( ! empty( $interp['alignment'] ) ) {
                echo '<h4>Alignment Overview</h4>';
                $labels = [
                    'strong'   => 'Strong Alignment',
                    'moderate' => 'Moderate Alignment',
                    'low'      => 'Significant Reordering',
                ];
                $label = $labels[ $interp['alignment'] ] ?? ucfirst( $interp['alignment'] );
                echo '<p style="font-weight:bold; font-size:15px;">' . esc_html( $label ) . '</p>';
            }

            if ( ! empty( $interp['insights'] ) ) {
                echo '<h4>Key Observations</h4><ul>';
                foreach ( $interp['insights'] as $ins ) {
                    echo '<li>' . esc_html( $ins ) . '</li>';
                }
                echo '</ul>';
            }

            if ( ! empty( $interp['distribution'] ) ) {
                echo '<h4>Your Awareness Profile</h4>';
                echo '<p style="font-weight:bold; font-size:15px;">' . esc_html( $interp['distribution'] ) . '</p>';
            }

            if ( ! empty( $interp['pillars'] ) ) {
                echo '<h4>Your Wellness Areas</h4><ul>';
                foreach ( $interp['pillars'] as $p ) {
                    echo '<li>'
                        . esc_html( $p['label'] )
                        . ' — '
                        . esc_html( round( $p['percent'] ) ) . '% '
                        . '(' . esc_html( ucfirst( $p['level'] ) ) . ')'
                        . '</li>';
                }
                echo '</ul>';
            }

            if ( ! empty( $interp['perceived_ranking'] ) && ! empty( $interp['pillars'] ) ) {
                echo '<h4>Perception vs Assessment</h4>';
                echo '<div style="max-width:400px; margin:0 auto;">';
                $count = max( count( $interp['perceived_ranking'] ), count( $interp['pillars'] ) );
                for ( $i = 0; $i < $count; $i++ ) {
                    $perceived = ucfirst( $interp['perceived_ranking'][ $i ] ?? '' );
                    $actual    = $interp['pillars'][ $i ]['label'] ?? '';
                    $diff = 0;
                    if ( ! empty( $interp['comparison'] ) ) {
                        foreach ( $interp['comparison'] as $c ) {
                            if ( strcasecmp( $c['label'], $actual ) === 0 ) {
                                $diff = $c['difference'];
                                break;
                            }
                        }
                    }
                    $icon  = '';
                    $color = '#999';
                    if ( $diff === 0 ) {
                        $icon  = '✓';
                        $color = '#22c55e';
                    } elseif ( $diff < 0 ) {
                        $icon  = '↑ ' . abs( $diff );
                        $color = '#3b82f6';
                    } elseif ( $diff > 0 ) {
                        $icon  = '↓ ' . $diff;
                        $color = '#f97316';
                    }
                    echo '<div style="display:flex;align-items:center;justify-content:center;margin:6px 0;font-size:15px;">';
                    echo '<div style="flex:0 0 42%;text-align:left;font-weight:500;padding:4px 10px;border:1px solid #233b6d;border-radius:10px;background:#ffffff;box-shadow:0 2px 6px rgba(0,0,0,0.10);display:flex;align-items:center;min-height:28px;">'
                        . esc_html( $perceived ) . '</div>';
                    echo '<div style="width:75px;text-align:center;font-weight:600;color:' . $color . ';margin:0 6px;">'
                        . esc_html( $icon ) . '</div>';
                    echo '<div style="flex:0 0 42%;text-align:right;font-weight:500;padding:4px 10px;border:1px solid #233b6d;border-radius:10px;background:#ffffff;box-shadow:0 2px 6px rgba(0,0,0,0.10);display:flex;align-items:center;justify-content:flex-end;min-height:28px;">'
                        . esc_html( $actual ) . '</div>';
                    echo '</div>';
                }
                echo '</div>';
            }

            echo '</div>';
            return ob_get_clean();
        }

        echo '<div class="bmf-results">';
        echo '<h3>Your Insights</h3>';
        echo '<p>' . esc_html( $interp['summary'] ?? '' ) . '</p>';
        echo '<p>These areas are currently influencing your overall balance and performance the most.</p>';
        if ( ! empty( $interp['actions'] ) && is_array( $interp['actions'] ) ) {
            echo '<h4>Recommended Next Steps</h4><ul>';
            foreach ( $interp['actions'] as $a ) {
                echo '<li>' . esc_html( $a ) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    public static function shortcode_rapid_trend( $atts ) {
        if ( function_exists('bmf_in_elementor_editor') && bmf_in_elementor_editor() ) {
            return '<div style="padding:20px; background:#0b1220; color:#94a3b8;">Rapid 8 Pillars trend preview</div>';
        }

        $atts = shortcode_atts( [
            'user_id' => get_current_user_id(),
            'height'  => '320',
        ], $atts, 'bmf_pillars_rapid_trend' );

        $user_id = (int) $atts['user_id'];
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        if ( ! is_user_logged_in() || ! $user_id ) {
            return '<p>Please log in to view your Rapid 8 Pillars trend.</p>';
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return '';
        }

        $rows = BMF_Pillars_Service::get_trend_rows( $user->user_email, 'rapid' );
        if ( count( $rows ) < 1 ) {
            return '<div class="bmf-pillars-trend-empty" style="padding:24px;text-align:center;color:#8892a4;background:#0b1220;border-radius:12px;">No Rapid 8 Pillars snapshots yet.</div>';
        }

        $cols = [
            'physical'      => '#38bdf8',
            'mental'        => '#a78bfa',
            'emotional'     => '#f472b6',
            'financial'     => '#34d399',
            'occupational'  => '#fbbf24',
            'environmental' => '#2dd4bf',
            'spiritual'     => '#818cf8',
            'social'        => '#fb7185',
        ];

        $master_pts = [];
        $series     = [];
        foreach ( array_keys( $cols ) as $k ) {
            $series[ $k ] = [];
        }
        $latest_master = null;
        foreach ( $rows as $r ) {
            $ts = strtotime( ( $r['results_date'] ?? '' ) . ' 00:00:00' );
            if ( ! $ts ) {
                continue;
            }
            $ms = $ts * 1000;
            $master = isset( $r['master_score'] ) ? (float) $r['master_score'] : null;
            if ( $master !== null && $master <= 1 ) {
                $master = $master * 100;
            }
            if ( $master !== null ) {
                $master_pts[]  = [ 'x' => $ms, 'y' => round( $master, 1 ) ];
                $latest_master = round( $master, 1 );
            }
            $pcts = BMF_Pillars_Service::pillar_percents_from_row( $r );
            foreach ( $pcts as $k => $v ) {
                $series[ $k ][] = [ 'x' => $ms, 'y' => $v ];
            }
        }

        $uid    = 'bmf_pillars_rapid_' . $user_id . '_' . wp_unique_id();
        $height = max( 220, (int) $atts['height'] );

        ob_start();
        ?>
<div class="bmf-pillars-rapid-trend" style="background:#0b1220;border-radius:16px;padding:18px 16px 14px;font-family:system-ui,-apple-system,sans-serif;color:#e2e8f0;">
  <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
    <div>
      <div style="font-size:0.7rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#38bdf8;">Rapid 8 Pillars</div>
      <div style="font-size:1.05rem;font-weight:650;color:#f8fafc;">Snapshot trend</div>
    </div>
    <div style="font-size:0.85rem;color:#94a3b8;">
      <?php echo (int) count( $rows ); ?> snapshot<?php echo count( $rows ) === 1 ? '' : 's'; ?>
      <?php if ( $latest_master !== null ) : ?>
        · latest avg <strong style="color:#f8fafc;"><?php echo esc_html( number_format( $latest_master, 1 ) ); ?>%</strong>
      <?php endif; ?>
    </div>
  </div>
  <div style="position:relative;height:<?php echo (int) $height; ?>px;">
    <canvas id="<?php echo esc_attr( $uid ); ?>"></canvas>
  </div>
</div>
<script>
(function(){
  var canvasId = <?php echo wp_json_encode( $uid ); ?>;
  var master = <?php echo wp_json_encode( $master_pts ); ?>;
  var pillars = <?php echo wp_json_encode( $series ); ?>;
  var colors = <?php echo wp_json_encode( $cols ); ?>;
  function loadScript(src, cb) {
    if (document.querySelector('script[src="'+src+'"]')) { cb(); return; }
    var s = document.createElement('script');
    s.src = src; s.onload = cb; document.head.appendChild(s);
  }
  function boot() {
    loadScript('https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', function(){
      loadScript('https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js', function(){
        var ctx = document.getElementById(canvasId);
        if (!ctx || typeof Chart === 'undefined') return;
        var datasets = [{
          label: 'Overall',
          data: master,
          borderColor: '#f8fafc',
          backgroundColor: 'rgba(248,250,252,0.08)',
          borderWidth: 3,
          tension: 0.25,
          pointRadius: 3
        }];
        Object.keys(colors).forEach(function(k){
          datasets.push({
            label: k.charAt(0).toUpperCase() + k.slice(1),
            data: pillars[k] || [],
            borderColor: colors[k],
            borderWidth: 1.5,
            tension: 0.25,
            pointRadius: 2,
            hidden: false
          });
        });
        new Chart(ctx, {
          type: 'line',
          data: { datasets: datasets },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'nearest', intersect: false },
            plugins: {
              legend: { labels: { color: '#cbd5e1', boxWidth: 12, font: { size: 11 } } }
            },
            scales: {
              x: {
                type: 'time',
                time: { unit: 'month', tooltipFormat: 'MMM d, yyyy' },
                ticks: { color: '#94a3b8' },
                grid: { color: 'rgba(148,163,184,0.12)' }
              },
              y: {
                min: 0, max: 100,
                ticks: { color: '#94a3b8' },
                grid: { color: 'rgba(148,163,184,0.12)' }
              }
            }
          }
        });
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
        <?php
        return ob_get_clean();
    }
}

add_action( 'init', [ 'BMF_Pillars_Shortcodes', 'init' ], 5 );
