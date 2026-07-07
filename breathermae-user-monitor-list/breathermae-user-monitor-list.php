<?php
/**
 * Plugin Name: Breathermae User Monitor List
 * Plugin URI: https://github.com/jprocasky/breathermae
 * Description: Internal dashboard shortcode [user_monitor_list] for registered users. Shows Username, First/Last Name, Last Visit Date, Last Page Visited (excluding session-expired), switchable IP/Geo, and dynamic WP Fusion status tag columns. Supports Zoho tags (zoho_tags + multi-tags) and exclude tags via shortcode. Prefers persistent usermeta. Works on WP Fusion-protected pages + Elementor Pro.
 * Version: 1.2.0
 * Author: Jeff Procasky / BreathemMae
 * Author URI: https://www.breathermae.com
 * License: GPL v2 or later
 * Text Domain: breathermae-user-monitor-list
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Usage:
 * [user_monitor_list status_tags="RSI|RSI_COMPLETE, BSI|BSI_COMPLETE" exclude="TEST,DEMO" show_ip="1" show_geo="1" per_page="50"]
 *
 * - status_tags: "Display Label|WP_Fusion_Tag_Slug" (comma separated)
 * - exclude: Comma-separated list of WP Fusion tag slugs to exclude (e.g. test users)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BreatherMae_User_Monitor_List {

    public function __construct() {
        add_shortcode( 'user_monitor_list', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets() {
        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style(
            'breathermae-user-monitor-list',
            plugin_dir_url( __FILE__ ) . 'breathermae-user-monitor-list.css',
            array(),
            '1.2.0'
        );
    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'status_tags' => '',
            'exclude'     => '',
            'show_ip'     => '0',
            'show_geo'    => '0',
            'per_page'    => '50',
            'search'      => '',
        ), $atts, 'user_monitor_list' );

        $status_tags_str = sanitize_text_field( $atts['status_tags'] );
        $exclude_str     = sanitize_text_field( $atts['exclude'] );
        $show_ip         = (bool) intval( $atts['show_ip'] );
        $show_geo        = (bool) intval( $atts['show_geo'] );
        $per_page        = max( 5, min( 200, intval( $atts['per_page'] ) ) );

        // Parse exclude tags (comma separated)
        $exclude_tags = array();
        if ( ! empty( $exclude_str ) ) {
            $exclude_tags = array_map( 'trim', explode( ',', $exclude_str ) );
            $exclude_tags = array_filter( $exclude_tags );
        }

        // Parse status tag columns
        $status_columns = array();
        if ( ! empty( $status_tags_str ) ) {
            $pairs = explode( ',', $status_tags_str );
            foreach ( $pairs as $pair ) {
                $parts = array_map( 'trim', explode( '|', $pair ) );
                if ( count( $parts ) === 2 && ! empty( $parts[0] ) && ! empty( $parts[1] ) ) {
                    $status_columns[] = array(
                        'label' => sanitize_text_field( $parts[0] ),
                        'tag'   => sanitize_text_field( $parts[1] ),
                    );
                }
            }
        }

        // URL-driven interactivity
        $paged  = isset( $_GET['um_paged'] ) ? max( 1, intval( $_GET['um_paged'] ) ) : 1;
        $search = isset( $_GET['um_search'] )
            ? sanitize_text_field( wp_unslash( $_GET['um_search'] ) )
            : sanitize_text_field( $atts['search'] );

        $offset = ( $paged - 1 ) * $per_page;

        global $wpdb;

        // Build search
        $where      = '1=1';
        $where_args = array();

        if ( ! empty( $search ) ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= " AND ( u.user_login LIKE %s OR u.display_name LIKE %s OR fn.meta_value LIKE %s OR ln.meta_value LIKE %s )";
            $where_args = array( $like, $like, $like, $like );
        }

        $live_table = $wpdb->prefix . 'live_sessions';

        $sql = "
            SELECT 
                u.ID,
                u.user_login,
                u.user_email,
                u.display_name,
                fn.meta_value AS first_name,
                ln.meta_value AS last_name,
                COALESCE( um_last.meta_value, l.last_active ) AS last_active,
                COALESCE( um_page.meta_value, l.page_url )     AS page_url,
                COALESCE( um_ip.meta_value,   l.ip_address )   AS ip_address,
                COALESCE( um_geo.meta_value,  l.geo_location ) AS geo_location
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} fn  ON fn.user_id = u.ID AND fn.meta_key = 'first_name'
            LEFT JOIN {$wpdb->usermeta} ln  ON ln.user_id = u.ID AND ln.meta_key = 'last_name'
            LEFT JOIN {$wpdb->usermeta} um_last ON um_last.user_id = u.ID AND um_last.meta_key = '_breathermae_last_active'
            LEFT JOIN {$wpdb->usermeta} um_page ON um_page.user_id = u.ID AND um_page.meta_key = '_breathermae_last_page_url'
            LEFT JOIN {$wpdb->usermeta} um_ip   ON um_ip.user_id = u.ID AND um_ip.meta_key = '_breathermae_last_ip'
            LEFT JOIN {$wpdb->usermeta} um_geo  ON um_geo.user_id = u.ID AND um_geo.meta_key = '_breathermae_last_geo'
            LEFT JOIN (
                SELECT l1.user_id, l1.page_url, l1.last_active, l1.ip_address, l1.geo_location
                FROM {$live_table} l1
                INNER JOIN (
                    SELECT user_id, MAX(last_active) AS max_ts
                    FROM {$live_table}
                    WHERE page_url NOT LIKE '%%session-expired%%'
                    GROUP BY user_id
                ) lmax ON l1.user_id = lmax.user_id AND l1.last_active = lmax.max_ts
            ) l ON l.user_id = u.ID
            WHERE {$where}
            ORDER BY 
                CASE WHEN COALESCE( um_last.meta_value, l.last_active ) IS NULL THEN 1 ELSE 0 END,
                COALESCE( um_last.meta_value, l.last_active ) DESC,
                u.user_registered DESC
            LIMIT %d, %d
        ";

        $query_args = array_merge( $where_args, array( $offset, $per_page ) );
        $users = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ) );

        if ( $wpdb->last_error ) {
            return '<p style="color:#dc2626;">Database error: ' . esc_html( $wpdb->last_error ) . '</p>';
        }

        // Apply exclude tags filter
        $has_wpf = function_exists( 'wp_fusion' );

        if ( ! empty( $exclude_tags ) ) {
            $users = array_values( array_filter( $users, function( $user ) use ( $exclude_tags, $has_wpf ) {
                foreach ( $exclude_tags as $ex_tag ) {
                    if ( $this->user_has_fusion_tag( $user->ID, $ex_tag, $has_wpf ) ) {
                        return false;
                    }
                }
                return true;
            }));
        }

        // Total count (recalculate after exclusion for accurate pagination display)
        $total_users = count( $users );
        $total_pages = max( 1, ceil( $total_users / $per_page ) );

        ob_start();
        ?>
        <div class="breathermae-user-monitor">
            <div class="monitor-header">
                <h2>User Monitor Dashboard</h2>
                <div class="monitor-controls">
                    <form method="get" action="" class="monitor-search-form">
                        <input type="text" name="um_search" value="<?php echo esc_attr( $search ); ?>" 
                               placeholder="Search username or name..." style="min-width:220px;" />
                        <button type="submit" class="button button-secondary">Search</button>
                        <?php if ( ! empty( $search ) ) : ?>
                            <a href="<?php echo esc_url( remove_query_arg( array( 'um_search', 'um_paged' ) ) ); ?>" class="button">Clear</a>
                        <?php endif; ?>
                    </form>

                    <button type="button" 
                            onclick="exportTableToCSV('breathermae-user-monitor-table', 'breathermae-user-monitor-<?php echo date('Y-m-d'); ?>.csv')" 
                            class="button button-primary">
                        Export CSV
                    </button>
                </div>
            </div>

            <?php if ( empty( $users ) ) : ?>
                <div class="notice notice-warning" style="padding:12px;">
                    <p>No users found<?php echo !empty($search) ? ' matching your search.' : '.'; ?></p>
                </div>
            <?php else : ?>

                <div class="table-responsive">
                    <table id="breathermae-user-monitor-table" class="breathermae-user-monitor-table wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>First Name</th>
                                <th>Last Name</th>
                                <th>Last Visit Date</th>
                                <th>Last Page Visited</th>
                                <?php if ( $show_ip ) : ?><th>IP Address</th><?php endif; ?>
                                <?php if ( $show_geo ) : ?><th>Geo Location</th><?php endif; ?>
                                <?php foreach ( $status_columns as $col ) : ?>
                                    <th class="status-col"><?php echo esc_html( $col['label'] ); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $users as $user ) :
                                $first_name = $user->first_name ?: '';
                                $last_name  = $user->last_name  ?: '';

                                $last_visit = ! empty( $user->last_active )
                                    ? esc_html( date_i18n( 'Y-m-d H:i', strtotime( $user->last_active ) ) )
                                    : '—';

                                $last_page_raw = $user->page_url ?: '';
                                if ( $last_page_raw ) {
                                    $path = parse_url( $last_page_raw, PHP_URL_PATH );
                                    $last_page = $path ? trim( basename( $path ), '/' ) : esc_html( $last_page_raw );
                                } else {
                                    $last_page = '—';
                                }

                                $ip_display  = ( $show_ip && ! empty( $user->ip_address ) ) ? esc_html( $user->ip_address ) : '—';
                                $geo_display = ( $show_geo && ! empty( $user->geo_location ) ) ? esc_html( $user->geo_location ) : '—';
                            ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $user->user_login ); ?></strong></td>
                                    <td><?php echo esc_html( $first_name ); ?></td>
                                    <td><?php echo esc_html( $last_name ); ?></td>
                                    <td><?php echo $last_visit; ?></td>
                                    <td><?php echo esc_html( $last_page ); ?></td>

                                    <?php if ( $show_ip ) : ?><td><?php echo $ip_display; ?></td><?php endif; ?>
                                    <?php if ( $show_geo ) : ?><td><?php echo $geo_display; ?></td><?php endif; ?>

                                    <?php foreach ( $status_columns as $col ) :
                                        $has_tag = $this->user_has_fusion_tag( $user->ID, $col['tag'], $has_wpf );
                                        $icon = $has_tag
                                            ? '<span class="dashicons dashicons-yes" style="color:#16a34a; font-size:24px; line-height:1;"></span>'
                                            : '<span class="dashicons dashicons-minus" style="color:#9ca3af; font-size:24px; line-height:1;"></span>';
                                    ?>
                                        <td class="status-cell"><?php echo $icon; ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="tablenav" style="margin-top:12px;">
                        <div class="tablenav-pages">
                            <?php
                            $base = remove_query_arg( 'um_paged' );
                            for ( $i = 1; $i <= $total_pages; $i++ ) {
                                $url   = add_query_arg( 'um_paged', $i, $base );
                                $class = ( $i === $paged ) ? 'current button-primary' : 'button';
                                echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '" style="margin-right:4px; min-width:32px; text-align:center;">' . $i . '</a>';
                            }
                            ?>
                            <span class="displaying-num" style="margin-left:12px; color:#6b7280;">
                                <?php echo number_format_i18n( $total_users ); ?> users total
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

            <p class="monitor-footer">
                <small>
                    Internal use only • Protected by WP Fusion • 
                    Data source: Persistent usermeta (incl. Zoho tags) + live-user-monitor • 
                    Default sort: Last Visit (newest first)
                </small>
            </p>
        </div>

        <script>
        function exportTableToCSV(tableId, filename) {
            const table = document.getElementById(tableId);
            if (!table) { alert('Table not found'); return; }

            const rows = table.querySelectorAll('tr');
            const csv = [];
            for (let i = 0; i < rows.length; i++) {
                const cols = rows[i].querySelectorAll('td, th');
                const row = [];
                for (let j = 0; j < cols.length; j++) {
                    let text = cols[j].innerText.trim().replace(/"/g, '""');
                    if (cols[j].querySelector('.dashicons-yes')) text = '✓';
                    else if (cols[j].querySelector('.dashicons-minus')) text = '☐';
                    row.push('"' + text + '"');
                }
                csv.push(row.join(','));
            }
            const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Robust check if a user has a specific WP Fusion tag.
     */
    private function user_has_fusion_tag( $user_id, $tag_to_check, $has_wpf ) {
        if ( $has_wpf && method_exists( wp_fusion()->user, 'has_tag' ) ) {
            if ( wp_fusion()->user->has_tag( $user_id, $tag_to_check ) ) return true;
        }

        if ( $has_wpf && method_exists( wp_fusion()->user, 'get_tags' ) ) {
            $user_tags = wp_fusion()->user->get_tags( $user_id );
            if ( is_array( $user_tags ) ) {
                if ( in_array( $tag_to_check, $user_tags, true ) ||
                     in_array( strtolower( $tag_to_check ), array_map( 'strtolower', $user_tags ), true ) ) {
                    return true;
                }
            }
        }

        // Zoho keys
        $zoho_tags = get_user_meta( $user_id, 'zoho_tags', true );
        if ( is_array( $zoho_tags ) ) {
            if ( in_array( $tag_to_check, $zoho_tags, true ) ||
                 in_array( strtolower( $tag_to_check ), array_map( 'strtolower', $zoho_tags ), true ) ) return true;
        } elseif ( is_string( $zoho_tags ) && stripos( $zoho_tags, $tag_to_check ) !== false ) {
            return true;
        }

        $multi_tags = get_user_meta( $user_id, 'multi-tags', true ) ?: get_user_meta( $user_id, 'mulit-tags', true );
        if ( is_array( $multi_tags ) ) {
            if ( in_array( $tag_to_check, $multi_tags, true ) ||
                 in_array( strtolower( $tag_to_check ), array_map( 'strtolower', $multi_tags ), true ) ) return true;
        } elseif ( is_string( $multi_tags ) && stripos( $multi_tags, $tag_to_check ) !== false ) {
            return true;
        }

        // Final fallback
        $raw_tags = get_user_meta( $user_id, 'wpf_tags', true );
        if ( is_array( $raw_tags ) ) {
            if ( in_array( $tag_to_check, $raw_tags, true ) ||
                 in_array( strtolower( $tag_to_check ), array_map( 'strtolower', $raw_tags ), true ) ) return true;
        }

        return false;
    }
}

new BreatherMae_User_Monitor_List();