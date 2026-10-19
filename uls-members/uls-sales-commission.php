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

        $period = $this->get_period_from_request();
        $data   = $this->build_report_data( $atts['patterns'], $atts['exclude_patterns'], $period );

        ob_start();
        $this->render_form( $period );
        $this->render_table( $data, $period );
        $this->render_styles_and_script();
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------
     * Period handling
     * ------------------------------------------------------------------- */

    private function get_period_from_request() {
        $mode  = isset( $_GET['uls_sc_mode'] ) ? sanitize_key( $_GET['uls_sc_mode'] ) : 'all';
        $year  = isset( $_GET['uls_sc_year'] ) ? absint( $_GET['uls_sc_year'] ) : (int) date( 'Y' );
        $month = isset( $_GET['uls_sc_month'] ) ? absint( $_GET['uls_sc_month'] ) : 0;

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
            'mode'  => $mode,
            'year'  => $year,
            'month' => $month,
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

        if ( empty( $sales_persons ) ) {
            return [
                'sales'   => [],
                'period'  => $period,
                'totals'  => [ 'gross' => 0, 'customers' => 0, 'items' => 0 ],
            ];
        }

        // Map customer_id → sales person (first match wins; flag conflicts)
        $customer_to_sales = [];
        $conflicts         = [];

        foreach ( $sales_persons as &$sp ) {
            $child_patterns = $this->get_child_patterns_for_parents( [ $sp['sales_code'] ] );
            // Fallback if relation table has nothing: SA100 → SA100-*
            if ( empty( $child_patterns ) ) {
                $child_patterns = [ $sp['sales_code'] . '-#' ];
            }

            $related = $this->find_users_matching_patterns( $child_patterns, $exclude_patterns );
            $sp['related_ids'] = [];

            foreach ( $related as $u ) {
                $cid = (int) $u['ID'];
                if ( isset( $customer_to_sales[ $cid ] ) && $customer_to_sales[ $cid ] !== $sp['ID'] ) {
                    $conflicts[ $cid ] = true;
                    continue; // keep first assignment
                }
                $customer_to_sales[ $cid ] = $sp['ID'];
                $sp['related_ids'][]       = $cid;
                $sp['related_users'][ $cid ] = $u;
            }
        }
        unset( $sp );

        $all_customer_ids = array_keys( $customer_to_sales );
        $lines            = $this->fetch_line_items( $all_customer_ids, $period );

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

        // Totals
        $totals = [ 'gross' => 0.0, 'customers' => 0, 'items' => 0 ];
        foreach ( $by_sales as $sp ) {
            $totals['gross']     += $sp['gross'];
            $totals['customers'] += count( $sp['users'] );
            $totals['items']     += $sp['item_count'];
        }

        return [
            'sales'     => $by_sales,
            'period'    => $period,
            'totals'    => $totals,
            'conflicts' => array_keys( $conflicts ),
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
    private function find_users_matching_patterns( array $include_patterns, array $exclude_patterns = [] ) {
        if ( empty( $include_patterns ) ) {
            return [];
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
        ] );
        ?>
        <form method="get" class="uls-sc-filters" style="margin-bottom:16px; display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
            <?php
            // Preserve other query args (page, etc.)
            foreach ( $_GET as $k => $v ) {
                if ( in_array( $k, [ 'uls_sc_mode', 'uls_sc_year', 'uls_sc_month', 'uls_sales_export' ], true ) ) {
                    continue;
                }
                if ( is_array( $v ) ) {
                    continue;
                }
                echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
            }
            ?>
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
            <button type="submit" class="button">Apply</button>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button">Export CSV (full detail)</a>
        </form>
        <p style="margin-top:-8px; color:#64748b; font-size:0.9em;">
            Showing: <strong><?php echo esc_html( $this->period_label( $period ) ); ?></strong>
            · Completed orders only · Gross = sum of line totals (post-discount, before tax)
        </p>
        <?php
    }

    private function render_table( $data, $period ) {
        $sales  = $data['sales'];
        $totals = $data['totals'];

        if ( empty( $sales ) ) {
            echo '<p>No sales persons found matching the configured patterns.</p>';
            return;
        }

        if ( ! empty( $data['conflicts'] ) ) {
            echo '<p style="color:#b45309;">Note: ' . count( $data['conflicts'] ) . ' customer(s) matched more than one sales person; assigned to the first match only.</p>';
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

        foreach ( $data['sales'] as $sp ) {
            $info = $sp['info'];
            foreach ( $sp['users'] as $cid => $user ) {
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

        fclose( $out );
        exit;
    }
}

ULS_Sales_Commission::init();
