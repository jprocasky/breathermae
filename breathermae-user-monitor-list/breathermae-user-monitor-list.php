<?php
/**
 * Plugin Name: BreatherMae User Monitor List
 * Plugin URI: https://github.com/jprocasky/breathermae
 * Description: Internal dashboard shortcode [user_monitor_list]. Shows registered users with last activity from persistent usermeta (no longer depends on wp_live_sessions). Supports dynamic WP Fusion status columns, exclude tags, IP/Geo, AJAX search/pagination, and CSV export. Works great with Elementor Pro and WP Fusion protected pages.
 * Version: 1.4.0
 * Author: Jeff Procasky / BreatherMae
 * Author URI: https://www.breathermae.com
 * License: GPL v2 or later
 * Text Domain: breathermae-user-monitor-list
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Usage example:
 * [user_monitor_list status_tags="RSI|RSI_COMPLETE, BSI|BSI_COMPLETE" exclude="TEST" show_ip="1" show_geo="1" per_page="50"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BreatherMae_User_Monitor_List {

    const VERSION = '1.4.0';
    const AJAX_ACTION = 'bmf_user_monitor_list';

    public function __construct() {
        add_shortcode( 'user_monitor_list', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_handler' ) );
        // Intentionally no nopriv — page is WP Fusion protected / staff only.
    }

    /**
     * Detect Elementor editor or preview so we can skip interactive JS.
     */
    private function is_elementor_edit_or_preview() {
        // Query-string flags (most reliable early).
        if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return true;
        }

        if ( ! defined( 'ELEMENTOR_VERSION' ) || ! class_exists( '\Elementor\Plugin' ) ) {
            return false;
        }

        $elementor = \Elementor\Plugin::$instance;

        if ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) && $elementor->editor->is_edit_mode() ) {
            return true;
        }

        if ( isset( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
            return true;
        }

        return false;
    }

    public function enqueue_assets() {
        // Always skip interactive JS inside Elementor editor / preview.
        // CSS is still loaded so the table looks correct in the canvas.
        $in_elementor = $this->is_elementor_edit_or_preview();

        // Only load when shortcode is present (cheap check via global post content).
        global $post;
        $needs = false;
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'user_monitor_list' ) ) {
            $needs = true;
        }
        // Elementor / builder fallback — shortcode often lives in _elementor_data.
        // Keep the fallback for frontend, but we already gated JS above for editor.
        if ( ! $needs && ( is_singular() || is_page() ) ) {
            $needs = true; // safe; CSS/JS are tiny on real frontend
        }
        if ( ! $needs ) {
            return;
        }

        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style(
            'breathermae-user-monitor-list',
            plugin_dir_url( __FILE__ ) . 'breathermae-user-monitor-list.css',
            array(),
            self::VERSION
        );

        // No JS (and therefore no AJAX refresh) while editing in Elementor.
        if ( $in_elementor ) {
            return;
        }

        wp_enqueue_script(
            'breathermae-user-monitor-list',
            plugin_dir_url( __FILE__ ) . 'breathermae-user-monitor-list.js',
            array( 'jquery' ),
            self::VERSION,
            true
        );

        wp_localize_script( 'breathermae-user-monitor-list', 'bmfUml', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'action'  => self::AJAX_ACTION,
            'nonce'   => wp_create_nonce( 'bmf_uml_nonce' ),
        ) );
    }

    /**
     * Shortcode entry point.
     */
    public function render_shortcode( $atts ) {
        $config = $this->parse_atts( $atts );

        // Initial server-side render (page 1, optional search from shortcode attr).
        $data = $this->query_users( $config );

        ob_start();
        ?>
        <div class="breathermae-user-monitor"
             data-status-tags="<?php echo esc_attr( $config['status_tags_raw'] ); ?>"
             data-exclude="<?php echo esc_attr( $config['exclude_raw'] ); ?>"
             data-show-ip="<?php echo $config['show_ip'] ? '1' : '0'; ?>"
             data-show-geo="<?php echo $config['show_geo'] ? '1' : '0'; ?>"
             data-per-page="<?php echo (int) $config['per_page']; ?>">

            <div class="monitor-header">
                <h2>User Monitor Dashboard</h2>
                <div class="monitor-controls">
                    <form class="monitor-search-form" onsubmit="return false;">
                        <input type="text" class="uml-search-input" value="<?php echo esc_attr( $config['search'] ); ?>"
                               placeholder="Search username or name..." style="min-width:220px;" />
                        <button type="button" class="button button-secondary uml-search-btn">Search</button>
                        <button type="button" class="button uml-clear-btn" style="<?php echo empty( $config['search'] ) ? 'display:none;' : ''; ?>">Clear</button>
                    </form>

                    <button type="button"
                            class="button button-primary uml-export-btn"
                            data-filename="breathermae-user-monitor-<?php echo date( 'Y-m-d' ); ?>.csv">
                        Export CSV
                    </button>
                </div>
            </div>

            <div class="uml-results">
                <?php echo $this->render_results_html( $data, $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>

            <p class="monitor-footer">
                <small>
                    Internal use only • Protected by WP Fusion •
                    Data source: Persistent usermeta (written by live-user-monitor) •
                    Default sort: Last Visit (newest first) • AJAX paging
                </small>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler — returns HTML fragment for .uml-results
     */
    public function ajax_handler() {
        check_ajax_referer( 'bmf_uml_nonce', 'nonce' );

        // Staff-only soft gate (page is already Fusion-protected).
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        $atts = array(
            'status_tags' => isset( $_POST['status_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['status_tags'] ) ) : '',
            'exclude'     => isset( $_POST['exclude'] )     ? sanitize_text_field( wp_unslash( $_POST['exclude'] ) )     : '',
            'show_ip'     => isset( $_POST['show_ip'] )     ? sanitize_text_field( wp_unslash( $_POST['show_ip'] ) )     : '0',
            'show_geo'    => isset( $_POST['show_geo'] )    ? sanitize_text_field( wp_unslash( $_POST['show_geo'] ) )    : '0',
            'per_page'    => isset( $_POST['per_page'] )    ? sanitize_text_field( wp_unslash( $_POST['per_page'] ) )    : '50',
            'search'      => isset( $_POST['search'] )      ? sanitize_text_field( wp_unslash( $_POST['search'] ) )      : '',
        );

        $config = $this->parse_atts( $atts );
        $config['paged'] = isset( $_POST['paged'] ) ? max( 1, intval( $_POST['paged'] ) ) : 1;

        $data = $this->query_users( $config );
        $html = $this->render_results_html( $data, $config );

        wp_send_json_success( array(
            'html'        => $html,
            'paged'       => $data['paged'],
            'total_pages' => $data['total_pages'],
            'total_users' => $data['total_users'],
        ) );
    }

    /**
     * Normalize shortcode / AJAX attributes.
     */
    private function parse_atts( $atts ) {
        $atts = shortcode_atts( array(
            'status_tags' => '',
            'exclude'     => '',
            'show_ip'     => '0',
            'show_geo'    => '0',
            'per_page'    => '50',
            'search'      => '',
        ), $atts, 'user_monitor_list' );

        $status_tags_raw = sanitize_text_field( $atts['status_tags'] );
        $exclude_raw     = sanitize_text_field( $atts['exclude'] );

        $exclude_tags = array();
        if ( ! empty( $exclude_raw ) ) {
            $exclude_tags = array_filter( array_map( 'trim', explode( ',', $exclude_raw ) ) );
        }

        $status_columns = array();
        if ( ! empty( $status_tags_raw ) ) {
            foreach ( explode( ',', $status_tags_raw ) as $pair ) {
                $parts = array_map( 'trim', explode( '|', $pair ) );
                if ( count( $parts ) === 2 && ! empty( $parts[0] ) && ! empty( $parts[1] ) ) {
                    $status_columns[] = array(
                        'label' => sanitize_text_field( $parts[0] ),
                        'tag'   => sanitize_text_field( $parts[1] ),
                    );
                }
            }
        }

        return array(
            'status_tags_raw' => $status_tags_raw,
            'exclude_raw'     => $exclude_raw,
            'exclude_tags'    => $exclude_tags,
            'status_columns'  => $status_columns,
            'show_ip'         => (bool) intval( $atts['show_ip'] ),
            'show_geo'        => (bool) intval( $atts['show_geo'] ),
            'per_page'        => max( 5, min( 200, intval( $atts['per_page'] ) ) ),
            'search'          => sanitize_text_field( $atts['search'] ),
            'paged'           => 1,
        );
    }

    /**
     * Core query: search → exclude filter → correct pagination in PHP.
     * Returns structured data used by both shortcode and AJAX.
     */
    private function query_users( $config ) {
        global $wpdb;

        $search   = $config['search'];
        $per_page = $config['per_page'];
        $paged    = max( 1, (int) $config['paged'] );

        $where = '1=1';
        $args  = array();

        if ( ! empty( $search ) ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= " AND ( u.user_login LIKE %s OR u.display_name LIKE %s OR fn.meta_value LIKE %s OR ln.meta_value LIKE %s )";
            $args  = array( $like, $like, $like, $like );
        }

        // No LIMIT — we need the full filtered set for accurate totals + even pages.
        $sql = "
            SELECT
                u.ID,
                u.user_login,
                u.user_email,
                u.display_name,
                fn.meta_value AS first_name,
                ln.meta_value AS last_name,
                last_active.meta_value   AS last_active,
                last_page.meta_value     AS last_page_url,
                last_ip.meta_value       AS last_ip,
                last_geo.meta_value      AS last_geo
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} fn          ON fn.user_id = u.ID AND fn.meta_key = 'first_name'
            LEFT JOIN {$wpdb->usermeta} ln          ON ln.user_id = u.ID AND ln.meta_key = 'last_name'
            LEFT JOIN {$wpdb->usermeta} last_active ON last_active.user_id = u.ID AND last_active.meta_key = '_breathermae_last_active'
            LEFT JOIN {$wpdb->usermeta} last_page   ON last_page.user_id   = u.ID AND last_page.meta_key   = '_breathermae_last_page_url'
            LEFT JOIN {$wpdb->usermeta} last_ip     ON last_ip.user_id     = u.ID AND last_ip.meta_key     = '_breathermae_last_ip'
            LEFT JOIN {$wpdb->usermeta} last_geo    ON last_geo.user_id    = u.ID AND last_geo.meta_key    = '_breathermae_last_geo'
            WHERE {$where}
            ORDER BY
                CASE WHEN last_active.meta_value IS NULL THEN 1 ELSE 0 END,
                last_active.meta_value DESC,
                u.user_registered DESC
        ";

        if ( ! empty( $args ) ) {
            $all_users = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
        } else {
            $all_users = $wpdb->get_results( $sql );
        }

        if ( $wpdb->last_error ) {
            return array(
                'error'       => $wpdb->last_error,
                'users'       => array(),
                'total_users' => 0,
                'total_pages' => 1,
                'paged'       => 1,
                'per_page'    => $per_page,
            );
        }

        $has_wpf = function_exists( 'wp_fusion' );

        // Exclude tags (post-query — required because tag storage is multi-source).
        if ( ! empty( $config['exclude_tags'] ) ) {
            $exclude_tags = $config['exclude_tags'];
            $all_users = array_values( array_filter( $all_users, function( $user ) use ( $exclude_tags, $has_wpf ) {
                foreach ( $exclude_tags as $ex_tag ) {
                    if ( $this->user_has_fusion_tag( $user->ID, $ex_tag, $has_wpf ) ) {
                        return false;
                    }
                }
                return true;
            } ) );
        }

        $total_users = count( $all_users );
        $total_pages = max( 1, (int) ceil( $total_users / $per_page ) );
        $paged       = min( $paged, $total_pages );

        $offset = ( $paged - 1 ) * $per_page;
        $users  = array_slice( $all_users, $offset, $per_page );

        return array(
            'users'       => $users,
            'total_users' => $total_users,
            'total_pages' => $total_pages,
            'paged'       => $paged,
            'per_page'    => $per_page,
            'error'       => null,
        );
    }

    /**
     * Render the results fragment (table + pagination or empty/error notice).
     * Used by both initial shortcode and AJAX.
     */
    private function render_results_html( $data, $config ) {
        if ( ! empty( $data['error'] ) ) {
            return '<p style="color:#dc2626;">Database error: ' . esc_html( $data['error'] ) . '</p>';
        }

        $users         = $data['users'];
        $total_users   = $data['total_users'];
        $total_pages   = $data['total_pages'];
        $paged         = $data['paged'];
        $per_page      = $data['per_page'];
        $show_ip       = $config['show_ip'];
        $show_geo      = $config['show_geo'];
        $status_columns = $config['status_columns'];
        $has_wpf       = function_exists( 'wp_fusion' );
        $search        = $config['search'];

        ob_start();

        if ( empty( $users ) ) {
            ?>
            <div class="notice notice-warning" style="padding:12px;">
                <p>No users found<?php echo ! empty( $search ) ? ' matching your search.' : '.'; ?></p>
            </div>
            <?php
            return ob_get_clean();
        }

        $from = ( ( $paged - 1 ) * $per_page ) + 1;
        $to   = min( $paged * $per_page, $total_users );
        ?>
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

                        $last_page_raw = $user->last_page_url ?: '';
                        if ( $last_page_raw ) {
                            $path = parse_url( $last_page_raw, PHP_URL_PATH );
                            $last_page = $path ? trim( basename( $path ), '/' ) : esc_html( $last_page_raw );
                        } else {
                            $last_page = '—';
                        }

                        $ip_display  = ( $show_ip && ! empty( $user->last_ip ) ) ? esc_html( $user->last_ip ) : '—';
                        $geo_display = ( $show_geo && ! empty( $user->last_geo ) ) ? esc_html( $user->last_geo ) : '—';
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
                                <td class="status-cell"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="tablenav" style="margin-top:12px;">
            <div class="tablenav-pages">
                <?php if ( $total_pages > 1 ) : ?>
                    <button type="button" class="button uml-page-btn" data-page="<?php echo max( 1, $paged - 1 ); ?>"
                            <?php disabled( $paged <= 1 ); ?>>
                        ‹ Prev
                    </button>

                    <?php
                    // Smart page number window (max ~7 visible).
                    $window = 2;
                    $start  = max( 1, $paged - $window );
                    $end    = min( $total_pages, $paged + $window );

                    if ( $start > 1 ) {
                        echo '<button type="button" class="button uml-page-btn" data-page="1">1</button>';
                        if ( $start > 2 ) {
                            echo '<span class="uml-ellipsis">…</span>';
                        }
                    }

                    for ( $i = $start; $i <= $end; $i++ ) {
                        $class = ( $i === $paged ) ? 'button button-primary current' : 'button uml-page-btn';
                        $attrs = ( $i === $paged ) ? '' : ' data-page="' . (int) $i . '"';
                        echo '<button type="button" class="' . esc_attr( $class ) . '"' . $attrs . ' style="min-width:32px; text-align:center;">' . (int) $i . '</button>';
                    }

                    if ( $end < $total_pages ) {
                        if ( $end < $total_pages - 1 ) {
                            echo '<span class="uml-ellipsis">…</span>';
                        }
                        echo '<button type="button" class="button uml-page-btn" data-page="' . (int) $total_pages . '">' . (int) $total_pages . '</button>';
                    }
                    ?>

                    <button type="button" class="button uml-page-btn" data-page="<?php echo min( $total_pages, $paged + 1 ); ?>"
                            <?php disabled( $paged >= $total_pages ); ?>>
                        Next ›
                    </button>
                <?php endif; ?>

                <span class="displaying-num">
                    Showing <?php echo number_format_i18n( $from ); ?>–<?php echo number_format_i18n( $to ); ?>
                    of <?php echo number_format_i18n( $total_users ); ?> users
                    <?php if ( $total_pages > 1 ) : ?>
                        · Page <?php echo (int) $paged; ?> of <?php echo (int) $total_pages; ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Robust WP Fusion tag check (supports zoho_tags + multi-tags)
     */
    private function user_has_fusion_tag( $user_id, $tag_to_check, $has_wpf ) {
        // 1. Official methods
        if ( $has_wpf && method_exists( wp_fusion()->user, 'has_tag' ) ) {
            if ( wp_fusion()->user->has_tag( $user_id, $tag_to_check ) ) {
                return true;
            }
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

        // 2. Zoho keys
        $zoho_tags = get_user_meta( $user_id, 'zoho_tags', true );
        if ( is_array( $zoho_tags ) ) {
            if ( in_array( $tag_to_check, $zoho_tags, true ) ||
                 in_array( strtolower( $tag_to_check ), array_map( 'strtolower', $zoho_tags ), true ) ) {
                return true;
            }
        } elseif ( is_string( $zoho_tags ) && stripos( $zoho_tags, $tag_to_check ) !== false ) {
            return true;
        }

        $multi_tags = get_user_meta( $user_id, 'multi-tags', true );
        if ( ! $multi_tags ) {
            $multi_tags = get_user_meta( $user_id, 'mulit-tags', true );
        }
        if ( is_array( $multi_tags ) ) {
            if ( in_array( $tag_to_check, $multi_tags, true ) ||
                 in_array( strtolower( $tag_to_check ), array_map( 'strtolower', $multi_tags ), true ) ) {
                return true;
            }
        } elseif ( is_string( $multi_tags ) && stripos( $multi_tags, $tag_to_check ) !== false ) {
            return true;
        }

        // 3. Default fallback
        $raw_tags = get_user_meta( $user_id, 'wpf_tags', true );
        if ( is_array( $raw_tags ) ) {
            if ( in_array( $tag_to_check, $raw_tags, true ) ||
                 in_array( strtolower( $tag_to_check ), array_map( 'strtolower', $raw_tags ), true ) ) {
                return true;
            }
        }

        return false;
    }
}

new BreatherMae_User_Monitor_List();
