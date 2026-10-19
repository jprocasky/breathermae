<?php
/**
 * ULS Sales Commission Report
 *
 * Shortcode: [uls_sales_commission]
 *
 * Expandable report of sales persons (SA###) → related users → completed
 * WooCommerce line items, with period filters and full-detail CSV export.
 *
 * Drop into the uls-members plugin folder and require it from the main plugin
 * file (see instructions at the bottom of this file / in the chat).
 *
 * @package ULS_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ULS_Sales_Commission {

    const VIEW_ORDERS = 'vw_wc_orders_full';
    const TABLE_REL   = 'uls_parent_child_tags';

    public static function init() {
        $self = new self();
        add_shortcode( 'uls_sales_commission', [ $self, 'shortcode' ] );
        add_action( 'init', [ $self, 'maybe_export_csv' ], 5 );
    }

    /* ---------------------------------------------------------------------
     * Shortcode
     * ------------------------------------------------------------------- */

    public function shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p>Please log in to view the sales commission report.</p>';
        }

        $atts = shortcode_atts( [
            'patterns'         => 'SA###',
            'exclude_patterns' => 'TEST',
        ], $atts, 'uls_sales_commission' );

        try {
            $period = $this->get_period_from_request();
            $data   = $this->build_report_data( $atts['patterns'], $atts['exclude_patterns'], $period );

            ob_start();
            $this->render_form( $period );
            $this->render_table( $data, $period );
            $this->render_styles_and_script();
            return ob_get_clean();
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                return '<div style="border:1px solid #b91c1c;padding:12px;background:#fef2f2;color:#7f1d1d;">'
                    . '<strong>Sales commission report error:</strong> '
                    . esc_html( $e->getMessage() )
                    . ' <em>(' . esc_html( basename( $e->getFile() ) ) . ':' . (int) $e->getLine() . ')</em>'
                    . '</div>';
            }
            return '<p>The sales commission report could not be generated. Please check the error log.</p>';
        }
    }

    /* ---------------------------------------------------------------------
     * Period handling
     * ------------------------------------------------------------------- */

    private function get_period_from_request() {
        $mode      = isset( $_GET['uls_sc_mode'] ) ? sanitize_key( $_GET['uls_sc_mode'] ) : 'all';
        $year      = isset( $_GET['uls_sc_year'] ) ? absint( $_GET['uls_sc_year'] ) : (int) date( 'Y' );
        $month     = isset( $_GET['uls_sc_month'] ) ? absint( $_GET['uls_sc_month'] ) : 0;
        $hide_zero = ! empty( $_GET['uls_sc_hide_zero'] );

        if ( ! in_array( $mode, [ 'all', 'year', 'month' ], true ) ) {
            $mode = 'all';
        }
        if ( $year < 2000 || $year > 2100 ) {
            $year = (int) date( 'Y' );
        }
        if ( $month < 1 || $month > 12 ) {
            $month = 0;
            if ( $mode === 'month' ) {
                $mode = 'year';
            }
        }

        return [
            'mode'      => $mode,
            'year'      => $year,
            'month'     => $month,
            'hide_zero' => $hide_zero,
        ];
    }

    private function period_sql_and_params( $period ) {
        if ( $period['mode'] === 'all' ) {
            return [ '', [] ];
        }

        if ( $period['mode'] === 'year' ) {
            return [
                ' AND YEAR(order_date) = %d ',
                [ $period['year'] ],
            ];
        }

        // month
        return [
            ' AND YEAR(order_date) = %d AND MONTH(order_date) = %d ',
            [ $period['year'], $period['month'] ],
        ];
    }

    private function period_label( $period ) {
        if ( $period['mode'] === 'all' ) {
            return 'All time';
        }
        if ( $period['mode'] === 'year' ) {
            return (string) $period['year'];
        }
        return date( 'F Y', mktime( 0, 0, 0, $period['month'], 1, $period['year'] ) );
    }

    /* ---------------------------------------------------------------------
     * Data building
     * ------------------------------------------------------------------- */

    private function build_report_data( $patterns, $exclude_patterns, $period ) {
        $sales_persons = $this->find_sales_persons( $patterns, $exclude_patterns );

        // Map customer_id → sales person. Strict convention: SA130 owns only SA130-# tags.
        $customer_to_sales = [];
        $conflicts         = [];

        foreach ( $sales_persons as &$sp ) {
            $code = $sp['sales_code']; // e.g. SA130

            // Prefer relation-table patterns, but only those that are specific to this code.
            // Always include the canonical SA130-# pattern.
            $child_patterns = $this->get_child_patterns_for_parents( [ $code ] );
            $strict         = [];

            foreach ( $child_patterns as $p ) {
                $p = trim( $p );
                // Keep only patterns that clearly belong to this sales code
                // (start with the code followed by a non-digit or end, or contain the code-#)
                if ( preg_match( '/^' . preg_quote( $code, '/' ) . '(?:-|#|\*|$)/i', $p ) ) {
                    $strict[] = $p;
                }
            }

            // Canonical pattern that matches SA130-1, SA130-2, …
            $canonical = $code . '-#';
            if ( ! in_array( $canonical, $strict, true ) ) {
                $strict[] = $canonical;
            }

            // Also accept exact SA130-* if someone stored a looser but still prefixed pattern
            $star = $code . '-*';
            if ( ! in_array( $star, $strict, true ) ) {
                $strict[] = $star;
            }

            $exclude_list = array_filter( array_map( 'trim', explode( ',', (string) $exclude_patterns ) ) );
            $related = $this->find_users_matching_patterns( $strict, $exclude_list );

            $sp['related_ids']    = [];
            $sp['child_patterns'] = $strict; // for debugging / display if needed

            foreach ( $related as $u ) {
                $cid = (int) $u['ID'];

                // Extra safety: the user must actually have a tag that starts with this exact code + '-'
                $has_exact = false;
                foreach ( $u['matched_tags'] as $tag ) {
                    if ( preg_match( '/^' . preg_quote( $code, '/' ) . '-\d+/i', $tag ) ) {
                        $has_exact = true;
                        break;
                    }
                }
                if ( ! $has_exact ) {
                    continue;
                }

                if ( isset( $customer_to_sales[ $cid ] ) && $customer_to_sales[ $cid ] !== $sp['ID'] ) {
                    $conflicts[ $cid ] = true;
                    // Do not reassign; leave with the first strict match
                    continue;
                }
                $customer_to_sales[ $cid ]       = $sp['ID'];
                $sp['related_ids'][]             = $cid;
                $sp['related_users'][ $cid ]     = $u;
            }
        }
        unset( $sp );

        $all_customer_ids = array_keys( $customer_to_sales );
        $lines            = $this->fetch_line_items( $all_customer_ids, $period );

        // --- Unassigned purchasers (completed orders, no matching SA###-# link) ---
        $unassigned = $this->build_unassigned( $customer_to_sales, $period, $exclude_patterns );

        // Group lines under sales persons → users
        $by_sales = [];
        foreach ( $sales_persons as $sp ) {
            $by_sales[ $sp['ID'] ] = [
                'info'      => $sp,
                'users'     => [],
                'gross'     => 0.0,
                'item_count'=> 0,
            ];
        }

        foreach ( $lines as $line ) {
            $cid = (int) $line['customer_id'];
            if ( ! isset( $customer_to_sales[ $cid ] ) ) {
                continue;
            }
            $spid = $customer_to_sales[ $cid ];
            if ( ! isset( $by_sales[ $spid ]['users'][ $cid ] ) ) {
                $u = $by_sales[ $spid ]['info']['related_users'][ $cid ] ?? [
                    'ID'           => $cid,
                    'user_email'   => '',
                    'display_name' => '',
                    'first_name'   => '',
                    'last_name'    => '',
                ];
                $by_sales[ $spid ]['users'][ $cid ] = [
                    'info'  => $u,
                    'lines' => [],
                    'gross' => 0.0,
                ];
            }
            $by_sales[ $spid ]['users'][ $cid ]['lines'][] = $line;
            $by_sales[ $spid ]['users'][ $cid ]['gross']  += (float) $line['line_total'];
            $by_sales[ $spid ]['gross']                   += (float) $line['line_total'];
            $by_sales[ $spid ]['item_count']++;
        }

        // Totals (assigned only — unassigned is shown separately)
        $totals = [ 'gross' => 0.0, 'customers' => 0, 'items' => 0 ];
        foreach ( $by_sales as $sp ) {
            $totals['gross']     += $sp['gross'];
            $totals['customers'] += count( $sp['users'] );
            $totals['items']     += $sp['item_count'];
        }

        return [
            'sales'      => $by_sales,
            'unassigned' => $unassigned,
            'period'     => $period,
            'totals'     => $totals,
            'conflicts'  => array_keys( $conflicts ),
        ];
    }

    /**
     * Customers with completed orders in the period who are not linked to any sales person.
     */
    private function build_unassigned( array $customer_to_sales, $period, $exclude_patterns ) {
        global $wpdb;

        list( $date_sql, $date_params ) = $this->period_sql_and_params( $period );

        $sql = "
            SELECT DISTINCT customer_id
            FROM `" . self::VIEW_ORDERS . "`
            WHERE order_status = 'wc-completed'
              AND customer_id > 0
              $date_sql
        ";

        $rows = $date_params
            ? $wpdb->get_col( $wpdb->prepare( $sql, $date_params ) )
            : $wpdb->get_col( $sql );

        $unassigned_ids = [];
        foreach ( (array) $rows as $cid ) {
            $cid = (int) $cid;
            if ( $cid > 0 && ! isset( $customer_to_sales[ $cid ] ) ) {
                $unassigned_ids[] = $cid;
            }
        }

        if ( empty( $unassigned_ids ) ) {
            return [
                'users'      => [],
                'gross'      => 0.0,
                'item_count' => 0,
            ];
        }

        // Exclude patterns (e.g. TEST) — drop users who match them
        $exclude_list = array_filter( array_map( 'trim', explode( ',', (string) $exclude_patterns ) ) );
        $exclude_rx   = array_map( [ $this, 'wildcard_to_regex' ], $exclude_list );

        $users = [];
        foreach ( $unassigned_ids as $cid ) {
            $labels = $this->get_user_tag_labels( $cid );
            $skip   = false;
            foreach ( $labels as $label ) {
                foreach ( $exclude_rx as $rx ) {
                    if ( preg_match( $rx, $label ) ) {
                        $skip = true;
                        break 2;
                    }
                }
            }
            if ( $skip ) {
                continue;
            }

            $user = get_userdata( $cid );
            if ( ! $user ) {
                continue;
            }
            $users[ $cid ] = [
                'info' => [
                    'ID'           => $cid,
                    'user_email'   => $user->user_email,
                    'display_name' => $user->display_name,
                    'first_name'   => (string) get_user_meta( $cid, 'first_name', true ),
                    'last_name'    => (string) get_user_meta( $cid, 'last_name', true ),
                ],
                'lines' => [],
                'gross' => 0.0,
            ];
        }

        if ( empty( $users ) ) {
            return [
                'users'      => [],
                'gross'      => 0.0,
                'item_count' => 0,
            ];
        }

        $lines = $this->fetch_line_items( array_keys( $users ), $period );
        $gross = 0.0;
        $items = 0;
        foreach ( $lines as $line ) {
            $cid = (int) $line['customer_id'];
            if ( ! isset( $users[ $cid ] ) ) {
                continue;
            }
            $users[ $cid ]['lines'][] = $line;
            $users[ $cid ]['gross']  += (float) $line['line_total'];
            $gross += (float) $line['line_total'];
            $items++;
        }

        // Drop users who ended up with no lines (shouldn't happen, but safe)
        foreach ( $users as $cid => $u ) {
            if ( empty( $u['lines'] ) ) {
                unset( $users[ $cid ] );
            }
        }

        return [
            'users'      => $users,
            'gross'      => $gross,
            'item_count' => $items,
        ];
    }

    private function find_sales_persons( $patterns, $exclude_patterns ) {
        $pattern_list = array_filter( array_map( 'trim', explode( ',', (string) $patterns ) ) );
        if ( empty( $pattern_list ) ) {
            $pattern_list = [ 'SA###' ];
        }

        $exclude_list = array_filter( array_map( 'trim', explode( ',', (string) $exclude_patterns ) ) );

        $users = $this->find_users_matching_patterns( $pattern_list, $exclude_list );

        $sales = [];
        foreach ( $users as $u ) {
            $code = '';
            foreach ( $u['matched_tags'] as $tag ) {
                if ( preg_match( '/^SA\d{3}$/i', $tag ) ) {
                    $code = strtoupper( $tag );
                    break;
                }
            }
            if ( $code === '' ) {
                continue;
            }
            $sales[] = [
                'ID'           => (int) $u['ID'],
                'user_email'   => $u['user_email'],
                'display_name' => $u['display_name'],
                'first_name'   => $u['first_name'],
                'last_name'    => $u['last_name'],
                'sales_code'   => $code,
                'related_users'=> [],
                'related_ids'  => [],
            ];
        }

        usort( $sales, function ( $a, $b ) {
            return strcasecmp( $a['sales_code'], $b['sales_code'] );
        } );

        return $sales;
    }

    /**
     * Find users whose WPF tags match any of the given wildcard patterns.
     * Patterns support * and # the same way the rest of uls-members does.
     */
    private function find_users_matching_patterns( array $include_patterns, $exclude_patterns = [] ) {
        if ( empty( $include_patterns ) ) {
            return [];
        }

        // Accept either array or comma-separated string
        if ( is_string( $exclude_patterns ) ) {
            $exclude_patterns = array_filter( array_map( 'trim', explode( ',', $exclude_patterns ) ) );
        }
        if ( ! is_array( $exclude_patterns ) ) {
            $exclude_patterns = [];
        }

        $include_rx = array_map( [ $this, 'wildcard_to_regex' ], $include_patterns );
        $exclude_rx = array_map( [ $this, 'wildcard_to_regex' ], $exclude_patterns );

        $meta_query = [];
        if ( defined( 'WPF_TAGS_META_KEY' ) ) {
            $meta_query[] = [ 'key' => WPF_TAGS_META_KEY, 'compare' => 'EXISTS' ];
        }

        $query = new WP_User_Query( [
            'fields'     => [ 'ID', 'user_email', 'display_name' ],
            'meta_query' => $meta_query,
            'number'     => 5000,
        ] );

        $matched = [];
        foreach ( $query->get_results() as $u ) {
            $labels = $this->get_user_tag_labels( $u->ID );
            if ( empty( $labels ) ) {
                continue;
            }

            $hits = [];
            foreach ( $labels as $label ) {
                foreach ( $include_rx as $rx ) {
                    if ( preg_match( $rx, $label ) ) {
                        $hits[] = $label;
                        break;
                    }
                }
            }
            if ( empty( $hits ) ) {
                continue;
            }

            $excluded = false;
            foreach ( $labels as $label ) {
                foreach ( $exclude_rx as $rx ) {
                    if ( preg_match( $rx, $label ) ) {
                        $excluded = true;
                        break 2;
                    }
                }
            }
            if ( $excluded ) {
                continue;
            }

            $matched[] = [
                'ID'           => (int) $u->ID,
                'user_email'   => $u->user_email,
                'display_name' => $u->display_name,
                'first_name'   => (string) get_user_meta( $u->ID, 'first_name', true ),
                'last_name'    => (string) get_user_meta( $u->ID, 'last_name', true ),
                'matched_tags' => array_values( array_unique( $hits ) ),
            ];
        }

        return $matched;
    }

    private function get_user_tag_labels( $user_id ) {
        $labels = [];
        if ( function_exists( 'wpf_get_tags' ) ) {
            $tags = wpf_get_tags( $user_id );
            if ( is_array( $tags ) ) {
                foreach ( $tags as $tag ) {
                    if ( function_exists( 'wpf_get_tag_label' ) ) {
                        $label = wpf_get_tag_label( $tag );
                        if ( is_string( $label ) && $label !== '' ) {
                            $labels[] = $label;
                        }
                    } else {
                        $labels[] = is_string( $tag ) ? $tag : (string) $tag;
                    }
                }
            }
        }

        if ( empty( $labels ) ) {
            $meta = get_user_meta( $user_id, 'zoho_tags', true );
            if ( empty( $meta ) ) {
                $meta = get_user_meta( $user_id, 'multi_tags', true );
            }
            if ( is_array( $meta ) ) {
                $labels = $meta;
            } elseif ( is_string( $meta ) && $meta !== '' ) {
                $labels = array_map( 'trim', explode( ',', $meta ) );
            }
        }

        return array_values( array_unique( array_filter( array_map( 'trim', $labels ) ) ) );
    }

    private function get_child_patterns_for_parents( array $parent_tags ) {
        global $wpdb;
        if ( empty( $parent_tags ) ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $parent_tags ), '%s' ) );
        $sql          = "SELECT child_pattern FROM `" . self::TABLE_REL . "` WHERE parent_tag IN ($placeholders)";
        $results      = $wpdb->get_col( $wpdb->prepare( $sql, $parent_tags ) );
        return array_unique( array_map( 'trim', (array) $results ) );
    }

    private function wildcard_to_regex( $pattern ) {
        if ( $pattern === '' ) {
            return '/^$/';
        }
        $regex = preg_quote( $pattern, '/' );
        $regex = str_replace( '\\*', '.*', $regex );
        $regex = str_replace( '\\#', '\\d', $regex );
        return '/^' . $regex . '$/i';
    }

    private function fetch_line_items( array $customer_ids, $period ) {
        global $wpdb;
        if ( empty( $customer_ids ) ) {
            return [];
        }

        $ids_placeholders = implode( ',', array_fill( 0, count( $customer_ids ), '%d' ) );
        list( $date_sql, $date_params ) = $this->period_sql_and_params( $period );

        $sql = "
            SELECT
                customer_id,
                order_id,
                order_date,
                order_item_id,
                CAST(product_id AS UNSIGNED) AS product_id,
                product_name,
                CAST(quantity AS DECIMAL(10,2)) AS quantity,
                CAST(line_total AS DECIMAL(12,2)) AS line_total
            FROM `" . self::VIEW_ORDERS . "`
            WHERE order_status = 'wc-completed'
              AND customer_id IN ($ids_placeholders)
              AND customer_id > 0
              $date_sql
            ORDER BY order_date DESC, order_id DESC, order_item_id ASC
        ";

        $params = array_merge( $customer_ids, $date_params );
        $rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------- */

    private function render_form( $period ) {
        $current_year = (int) date( 'Y' );
        $years        = range( $current_year, $current_year - 6 );
        $months       = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];

        $export_url = add_query_arg( [
            'uls_sales_export' => '1',
            'uls_sc_mode'      => $period['mode'],
            'uls_sc_year'      => $period['year'],
            'uls_sc_month'     => $period['month'],
            'uls_sc_hide_zero' => $period['hide_zero'] ? '1' : '0',
        ] );
        ?>
        <form method="get" class="uls-sc-filters">
            <?php
            // Preserve other query args (page, etc.)
            foreach ( $_GET as $k => $v ) {
                if ( in_array( $k, [ 'uls_sc_mode', 'uls_sc_year', 'uls_sc_month', 'uls_sc_hide_zero', 'uls_sales_export' ], true ) ) {
                    continue;
                }
                if ( is_array( $v ) ) {
                    continue;
                }
                echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
            }
            ?>
            <div class="uls-sc-filters-left">
                <label>
                    Period<br>
                    <select name="uls_sc_mode" id="uls_sc_mode">
                        <option value="all"   <?php selected( $period['mode'], 'all' ); ?>>All time</option>
                        <option value="year"  <?php selected( $period['mode'], 'year' ); ?>>Year</option>
                        <option value="month" <?php selected( $period['mode'], 'month' ); ?>>Month</option>
                    </select>
                </label>
                <label id="uls_sc_year_wrap">
                    Year<br>
                    <select name="uls_sc_year">
                        <?php foreach ( $years as $y ) : ?>
                            <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $period['year'], $y ); ?>><?php echo esc_html( $y ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label id="uls_sc_month_wrap">
                    Month<br>
                    <select name="uls_sc_month">
                        <option value="0">—</option>
                        <?php foreach ( $months as $num => $name ) : ?>
                            <option value="<?php echo esc_attr( $num ); ?>" <?php selected( $period['month'], $num ); ?>><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="uls-sc-check">
                    <input type="checkbox" name="uls_sc_hide_zero" value="1" <?php checked( ! empty( $period['hide_zero'] ) ); ?>>
                    Hide $0
                </label>
                <button type="submit" class="uls-sc-btn uls-sc-btn-primary">Apply</button>
                <a href="<?php echo esc_url( strtok( $_SERVER['REQUEST_URI'], '?' ) ); ?>" class="uls-sc-btn uls-sc-btn-secondary">Clear</a>
            </div>
            <div class="uls-sc-filters-right">
                <a href="<?php echo esc_url( $export_url ); ?>" class="uls-sc-btn uls-sc-btn-secondary">Export CSV</a>
            </div>
        </form>
        <p style="margin-top:-8px; color:#64748b; font-size:0.9em;">
            Showing: <strong><?php echo esc_html( $this->period_label( $period ) ); ?></strong>
            · Completed orders only · Gross = sum of line totals (post-discount, before tax)
            <?php if ( ! empty( $period['hide_zero'] ) ) : ?>
                · <em>$0 sales persons and customers hidden</em>
            <?php endif; ?>
        </p>
        <?php
    }

    private function render_table( $data, $period ) {
        $sales     = $data['sales'];
        $totals    = $data['totals'];
        $hide_zero = ! empty( $period['hide_zero'] );

        // Apply $0 filter for display
        if ( $hide_zero ) {
            foreach ( $sales as $spid => $sp ) {
                $kept_users = [];
                $kept_items = 0;
                foreach ( $sp['users'] as $cid => $user ) {
                    if ( (float) $user['gross'] > 0 ) {
                        $kept_users[ $cid ] = $user;
                        $kept_items += count( $user['lines'] );
                    }
                }
                if ( empty( $kept_users ) || (float) $sp['gross'] <= 0 ) {
                    unset( $sales[ $spid ] );
                    continue;
                }
                $sales[ $spid ]['users']      = $kept_users;
                $sales[ $spid ]['item_count'] = $kept_items;
            }
            if ( ! empty( $data['unassigned']['users'] ) ) {
                $kept_users = [];
                $kept_items = 0;
                $kept_gross = 0.0;
                foreach ( $data['unassigned']['users'] as $cid => $user ) {
                    if ( (float) $user['gross'] > 0 ) {
                        $kept_users[ $cid ] = $user;
                        $kept_items += count( $user['lines'] );
                        $kept_gross += (float) $user['gross'];
                    }
                }
                $data['unassigned']['users']      = $kept_users;
                $data['unassigned']['item_count'] = $kept_items;
                $data['unassigned']['gross']      = $kept_gross;
            }
        }

        if ( empty( $sales ) && empty( $data['unassigned']['users'] ) ) {
            echo '<p>No sales persons or unassigned purchasers found for this period'
                . ( $hide_zero ? ' (after hiding $0)' : '' ) . '.</p>';
            return;
        }

        if ( ! empty( $data['conflicts'] ) ) {
            echo '<p style="color:#b45309;">Note: ' . count( $data['conflicts'] ) . ' customer(s) had tags matching more than one sales code; left with the first strict match. This should be rare with SA###-# tagging.</p>';
        }
        ?>
        <table class="uls-sc-table widefat" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="width:30px;"></th>
                    <th>Sales Code</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Email</th>
                    <th style="text-align:right;">Gross Sales</th>
                    <th style="text-align:right;">Customers</th>
                    <th style="text-align:right;">Line Items</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $sales as $spid => $sp ) :
                $info = $sp['info'];
                $has_users = ! empty( $sp['users'] );
                ?>
                <tr class="uls-sc-sales-row" data-spid="<?php echo esc_attr( $spid ); ?>">
                    <td>
                        <?php if ( $has_users ) : ?>
                            <button type="button" class="uls-sc-toggle" data-target="users-<?php echo esc_attr( $spid ); ?>" aria-expanded="false">▸</button>
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo esc_html( $info['sales_code'] ); ?></strong></td>
                    <td><?php echo esc_html( $info['first_name'] ); ?></td>
                    <td><?php echo esc_html( $info['last_name'] ); ?></td>
                    <td><?php echo esc_html( $info['user_email'] ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( $this->money( $sp['gross'] ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( count( $sp['users'] ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( $sp['item_count'] ); ?></td>
                </tr>
                <?php if ( $has_users ) : ?>
                <tr class="uls-sc-child-row" id="users-<?php echo esc_attr( $spid ); ?>" style="display:none;">
                    <td colspan="8" style="padding:0 0 12px 28px; background:#f8fafc;">
                        <table class="uls-sc-users" style="width:100%; border-collapse:collapse; margin:6px 0;">
                            <thead>
                                <tr>
                                    <th style="width:30px;"></th>
                                    <th>Customer</th>
                                    <th>Email</th>
                                    <th style="text-align:right;">Gross</th>
                                    <th style="text-align:right;">Items</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $sp['users'] as $cid => $user ) :
                                $uinfo = $user['info'];
                                $name  = trim( $uinfo['first_name'] . ' ' . $uinfo['last_name'] );
                                if ( $name === '' ) {
                                    $name = $uinfo['display_name'] ?: ( 'User #' . $cid );
                                }
                                ?>
                                <tr>
                                    <td>
                                        <button type="button" class="uls-sc-toggle" data-target="items-<?php echo esc_attr( $spid . '-' . $cid ); ?>" aria-expanded="false">▸</button>
                                    </td>
                                    <td><?php echo esc_html( $name ); ?></td>
                                    <td><?php echo esc_html( $uinfo['user_email'] ); ?></td>
                                    <td style="text-align:right;"><?php echo esc_html( $this->money( $user['gross'] ) ); ?></td>
                                    <td style="text-align:right;"><?php echo esc_html( count( $user['lines'] ) ); ?></td>
                                </tr>
                                <tr class="uls-sc-child-row" id="items-<?php echo esc_attr( $spid . '-' . $cid ); ?>" style="display:none;">
                                    <td colspan="5" style="padding:4px 0 8px 28px;">
                                        <table class="uls-sc-items" style="width:100%; font-size:0.92em; border-collapse:collapse;">
                                            <thead>
                                                <tr>
                                                    <th>Order Date</th>
                                                    <th>Order</th>
                                                    <th>Product ID</th>
                                                    <th>Product</th>
                                                    <th style="text-align:right;">Qty</th>
                                                    <th style="text-align:right;">Line Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ( $user['lines'] as $line ) : ?>
                                                <tr>
                                                    <td><?php echo esc_html( $line['order_date'] ); ?></td>
                                                    <td>#<?php echo esc_html( $line['order_id'] ); ?></td>
                                                    <td><?php echo esc_html( $line['product_id'] ); ?></td>
                                                    <td><?php echo esc_html( $line['product_name'] ); ?></td>
                                                    <td style="text-align:right;"><?php echo esc_html( $line['quantity'] ); ?></td>
                                                    <td style="text-align:right;"><?php echo esc_html( $this->money( $line['line_total'] ) ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php
            // Unassigned row (always shown if there are any)
            $unassigned = $data['unassigned'] ?? [ 'users' => [], 'gross' => 0, 'item_count' => 0 ];
            if ( ! empty( $unassigned['users'] ) ) :
                $uid = 'unassigned';
                ?>
                <tr class="uls-sc-sales-row" style="background:#fffbeb;">
                    <td>
                        <button type="button" class="uls-sc-toggle" data-target="users-<?php echo esc_attr( $uid ); ?>" aria-expanded="false">▸</button>
                    </td>
                    <td><strong>UNASSIGNED</strong></td>
                    <td colspan="3" style="color:#92400e;">Needs review — no SA###-# sales link</td>
                    <td style="text-align:right;"><?php echo esc_html( $this->money( $unassigned['gross'] ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( count( $unassigned['users'] ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( $unassigned['item_count'] ); ?></td>
                </tr>
                <tr class="uls-sc-child-row" id="users-<?php echo esc_attr( $uid ); ?>" style="display:none;">
                    <td colspan="8" style="padding:0 0 12px 28px; background:#fffbeb;">
                        <table class="uls-sc-users" style="width:100%; border-collapse:collapse; margin:6px 0;">
                            <thead>
                                <tr>
                                    <th style="width:30px;"></th>
                                    <th>Customer</th>
                                    <th>Email</th>
                                    <th style="text-align:right;">Gross</th>
                                    <th style="text-align:right;">Items</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $unassigned['users'] as $cid => $user ) :
                                $uinfo = $user['info'];
                                $name  = trim( $uinfo['first_name'] . ' ' . $uinfo['last_name'] );
                                if ( $name === '' ) {
                                    $name = $uinfo['display_name'] ?: ( 'User #' . $cid );
                                }
                                ?>
                                <tr>
                                    <td>
                                        <button type="button" class="uls-sc-toggle" data-target="items-<?php echo esc_attr( $uid . '-' . $cid ); ?>" aria-expanded="false">▸</button>
                                    </td>
                                    <td><?php echo esc_html( $name ); ?></td>
                                    <td><?php echo esc_html( $uinfo['user_email'] ); ?></td>
                                    <td style="text-align:right;"><?php echo esc_html( $this->money( $user['gross'] ) ); ?></td>
                                    <td style="text-align:right;"><?php echo esc_html( count( $user['lines'] ) ); ?></td>
                                </tr>
                                <tr class="uls-sc-child-row" id="items-<?php echo esc_attr( $uid . '-' . $cid ); ?>" style="display:none;">
                                    <td colspan="5" style="padding:4px 0 8px 28px;">
                                        <table class="uls-sc-items" style="width:100%; font-size:0.92em; border-collapse:collapse;">
                                            <thead>
                                                <tr>
                                                    <th>Order Date</th>
                                                    <th>Order</th>
                                                    <th>Product ID</th>
                                                    <th>Product</th>
                                                    <th style="text-align:right;">Qty</th>
                                                    <th style="text-align:right;">Line Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ( $user['lines'] as $line ) : ?>
                                                <tr>
                                                    <td><?php echo esc_html( $line['order_date'] ); ?></td>
                                                    <td>#<?php echo esc_html( $line['order_id'] ); ?></td>
                                                    <td><?php echo esc_html( $line['product_id'] ); ?></td>
                                                    <td><?php echo esc_html( $line['product_name'] ); ?></td>
                                                    <td style="text-align:right;"><?php echo esc_html( $line['quantity'] ); ?></td>
                                                    <td style="text-align:right;"><?php echo esc_html( $this->money( $line['line_total'] ) ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
            <?php endif; ?>

            </tbody>
            <tfoot>
                <tr style="font-weight:bold; background:#f1f5f9;">
                    <td></td>
                    <td colspan="4">Total (<?php echo esc_html( $this->period_label( $period ) ); ?>)</td>
                    <td style="text-align:right;"><?php echo esc_html( $this->money( $totals['gross'] ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( $totals['customers'] ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( $totals['items'] ); ?></td>
                </tr>
            </tfoot>
        </table>
        <?php
    }

    private function money( $amount ) {
        return '$' . number_format( (float) $amount, 2 );
    }

    private function render_styles_and_script() {
        ?>
        <style>
            .uls-sc-filters {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-between;
                align-items: flex-end;
                gap: 12px;
                margin-bottom: 12px;
            }
            .uls-sc-filters-left,
            .uls-sc-filters-right {
                display: flex;
                flex-wrap: wrap;
                align-items: flex-end;
                gap: 10px;
            }
            .uls-sc-filters label {
                font-size: 12px;
                color: #475569;
                line-height: 1.2;
            }
            .uls-sc-filters select {
                display: block;
                margin-top: 2px;
                padding: 4px 8px;
                font-size: 13px;
                border: 1px solid #cbd5e1;
                border-radius: 4px;
                background: #fff;
                min-height: 30px;
            }
            .uls-sc-check {
                display: flex;
                align-items: center;
                gap: 6px;
                margin-bottom: 4px;
                font-size: 13px;
                color: #334155;
            }
            .uls-sc-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 5px 12px;
                font-size: 13px;
                line-height: 1.2;
                border-radius: 4px;
                border: 1px solid transparent;
                text-decoration: none;
                cursor: pointer;
                min-height: 30px;
                box-sizing: border-box;
            }
            .uls-sc-btn-primary {
                background: #1e293b;
                color: #fff;
                border-color: #1e293b;
            }
            .uls-sc-btn-primary:hover { background: #334155; color: #fff; }
            .uls-sc-btn-secondary {
                background: #fff;
                color: #334155;
                border-color: #cbd5e1;
            }
            .uls-sc-btn-secondary:hover { background: #f8fafc; color: #0f172a; }
            .uls-sc-table th, .uls-sc-table td,
            .uls-sc-users th, .uls-sc-users td,
            .uls-sc-items th, .uls-sc-items td {
                padding: 6px 8px;
                border-bottom: 1px solid #e2e8f0;
                text-align: left;
            }
            .uls-sc-toggle {
                background: none;
                border: 1px solid #cbd5e1;
                border-radius: 3px;
                cursor: pointer;
                width: 22px;
                height: 22px;
                line-height: 1;
                font-size: 12px;
            }
            .uls-sc-toggle[aria-expanded="true"] { background: #e2e8f0; }
            .uls-sc-sales-row:hover { background: #f8fafc; }
        </style>
        <script>
        (function(){
            document.addEventListener('click', function(e){
                var btn = e.target.closest('.uls-sc-toggle');
                if (!btn) return;
                var id = btn.getAttribute('data-target');
                var row = document.getElementById(id);
                if (!row) return;
                var open = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', open ? 'false' : 'true');
                btn.textContent = open ? '▸' : '▾';
                row.style.display = open ? 'none' : 'table-row';
            });

            var mode = document.getElementById('uls_sc_mode');
            function sync(){
                if (!mode) return;
                var y = document.getElementById('uls_sc_year_wrap');
                var m = document.getElementById('uls_sc_month_wrap');
                if (y) y.style.display = (mode.value === 'all') ? 'none' : '';
                if (m) m.style.display = (mode.value === 'month') ? '' : 'none';
            }
            if (mode) {
                mode.addEventListener('change', sync);
                sync();
            }
        })();
        </script>
        <?php
    }

    /* ---------------------------------------------------------------------
     * CSV export (full line-item detail)
     * ------------------------------------------------------------------- */

    public function maybe_export_csv() {
        if ( empty( $_GET['uls_sales_export'] ) || $_GET['uls_sales_export'] !== '1' ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_die( 'Unauthorized' );
        }

        // Stop the rest of WP from rendering
        remove_all_actions( 'wp_head' );
        remove_all_actions( 'wp_footer' );

        $period = $this->get_period_from_request();
        // Use the same defaults the shortcode uses; allow override via query if needed later
        $patterns         = isset( $_GET['uls_sc_patterns'] ) ? sanitize_text_field( wp_unslash( $_GET['uls_sc_patterns'] ) ) : 'SA###';
        $exclude_patterns = isset( $_GET['uls_sc_exclude'] ) ? sanitize_text_field( wp_unslash( $_GET['uls_sc_exclude'] ) ) : 'TEST';

        $data = $this->build_report_data( $patterns, $exclude_patterns, $period );

        $filename = 'sales-commission-' . sanitize_file_name( $this->period_label( $period ) ) . '-' . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [
            'Sales Code',
            'Sales First Name',
            'Sales Last Name',
            'Sales Email',
            'Customer ID',
            'Customer First Name',
            'Customer Last Name',
            'Customer Email',
            'Order ID',
            'Order Date',
            'Product ID',
            'Product Name',
            'Quantity',
            'Line Total',
            'Period',
        ] );

        $period_label = $this->period_label( $period );
        $hide_zero    = ! empty( $period['hide_zero'] );

        foreach ( $data['sales'] as $sp ) {
            if ( $hide_zero && (float) $sp['gross'] <= 0 ) {
                continue;
            }
            $info = $sp['info'];
            foreach ( $sp['users'] as $cid => $user ) {
                if ( $hide_zero && (float) $user['gross'] <= 0 ) {
                    continue;
                }
                $u = $user['info'];
                foreach ( $user['lines'] as $line ) {
                    fputcsv( $out, [
                        $info['sales_code'],
                        $info['first_name'],
                        $info['last_name'],
                        $info['user_email'],
                        $cid,
                        $u['first_name'],
                        $u['last_name'],
                        $u['user_email'],
                        $line['order_id'],
                        $line['order_date'],
                        $line['product_id'],
                        $line['product_name'],
                        $line['quantity'],
                        number_format( (float) $line['line_total'], 2, '.', '' ),
                        $period_label,
                    ] );
                }
            }
        }

        // Unassigned rows
        if ( ! empty( $data['unassigned']['users'] ) ) {
            foreach ( $data['unassigned']['users'] as $cid => $user ) {
                if ( $hide_zero && (float) $user['gross'] <= 0 ) {
                    continue;
                }
                $u = $user['info'];
                foreach ( $user['lines'] as $line ) {
                    fputcsv( $out, [
                        'UNASSIGNED',
                        '',
                        '',
                        '',
                        $cid,
                        $u['first_name'],
                        $u['last_name'],
                        $u['user_email'],
                        $line['order_id'],
                        $line['order_date'],
                        $line['product_id'],
                        $line['product_name'],
                        $line['quantity'],
                        number_format( (float) $line['line_total'], 2, '.', '' ),
                        $period_label,
                    ] );
                }
            }
        }

        fclose( $out );
        exit;
    }
}

ULS_Sales_Commission::init();
