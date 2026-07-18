<?php
/**
 * Plugin Name: ULS Members (Parent→Child Tag Relations)
 * Description: Displays a "members" table filtered by WP Fusion tag relations (parent→child wildcard). Includes per-row selection, multi-table AJAX details, and selected-user persistence. Values shown in <span class="uls-member-field"> are colorized client-side (0–100) with configurable thresholds via data-low/data-high.
 * Version: 1.6.2
 * Author: Jeff Procasky
 * License: GPLv2 or later
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define('ULS_DEBUG', true);

class ULS_Members_Plugin {
    private static $instance = null;

    // DB objects / constants
    private $table_rel   = 'uls_parent_child_tags';   // custom relation table (no WP prefix)
    private $view_visits = 'user_page_visits_view';   // custom view (no WP prefix)

    private $ajax_action_details = 'uls_get_member_details'; // AJAX: fetch multi-source details
    private $ajax_action_select  = 'uls_set_selected_user';  // AJAX: persist selected user/email

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }



    private function __construct() {
        // Activation: create relation table
        register_activation_hook( __FILE__, [ $this, 'on_activate' ] );
        
        // Load AI summary class (for file summary AJAX endpoint)
        require_once plugin_dir_path( __FILE__ ) . 'uls-ai-summary.php';

        // Shortcodes
        add_shortcode( 'uls_members_table', [ $this, 'shortcode_members_table' ] );
        add_shortcode( 'uls_selected_user', [ $this, 'shortcode_selected_user' ] );

        // Front-end assets
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX (logged in only)
        add_action( 'wp_ajax_' . $this->ajax_action_details, [ $this, 'ajax_get_member_details' ] );
        add_action( 'wp_ajax_' . $this->ajax_action_select,  [ $this, 'ajax_set_selected_user' ] );
        // If you want guests to access these endpoints, uncomment the nopriv lines:
        // add_action( 'wp_ajax_nopriv_' . $this->ajax_action_details, [ $this, 'ajax_get_member_details' ] );
        // add_action( 'wp_ajax_nopriv_' . $this->ajax_action_select,  [ $this, 'ajax_set_selected_user' ] );
        add_action(
        'wp_ajax_uls_toggle_file_visibility_scope',
        [ $this, 'ajax_toggle_file_visibility_scope' ]
        );   
        add_action( 'wp_ajax_uls_update_user_tags', [ $this, 'ajax_update_user_tags' ] );

        add_action( 'init', [ $this, 'handle_csv_export' ] );        
        
        add_action( 'wp_ajax_uls_get_scoped_impersonation_url', function () {

            // ✅ MUST MATCH wp_create_nonce('uls_members_nonce')
            check_ajax_referer( 'uls_members_nonce' );

            if ( ! is_user_logged_in() ) {
                wp_send_json_error( 'not_logged_in', 403 );
            }

            $member_id = (int) ( $_POST['member_id'] ?? 0 );
            $page_slug = sanitize_title( $_POST['page'] ?? '' );

            if ( ! $member_id || ! $page_slug ) {
                wp_send_json_error( 'invalid_params', 400 );
            }

            $page = get_page_by_path( $page_slug );
            if ( ! $page ) {
                wp_send_json_error( 'page_not_found', 404 );
            }

            $provider_id = get_current_user_id();

            $token = wp_create_nonce(
                'uls_scoped_impersonate_' . $provider_id . '_' . $member_id
            );

            $url = add_query_arg(
                [
                    'uls_view_as' => $member_id,
                    'uls_token'   => $token,
                ],
                get_permalink( $page )
            );

            wp_send_json_success( [ 'url' => $url ] );
        });        

        add_action('init', function () {
            add_rewrite_rule(
                '^uls-ocr-file/?$',
                'index.php?uls_ocr_file=1',
                'top'
            );
        });        
        add_filter('query_vars', function ($vars) {
            $vars[] = 'uls_ocr_file';
            return $vars;
        });       
        add_action('template_redirect', function () {

            if (empty($_GET['uls_ocr_file'])) {
                return;
            }

            $file_id = (int) ($_GET['id'] ?? 0);
            $expires = (int) ($_GET['expires'] ?? 0);
            $sig     = $_GET['sig'] ?? '';

            if (!$file_id || !$expires || !$sig) {
                wp_die('Invalid request', 400);
            }

            if ($expires < time()) {
                wp_die('URL expired', 403);
            }

            // Verify signature
            $expected = hash_hmac(
                'sha256',
                "{$file_id}|{$expires}",
                AUTH_SALT
            );

            if (!hash_equals($expected, $sig)) {
                wp_die('Invalid signature', 403);
            }

            // Load file from your existing table
            global $wpdb;
            $table = $wpdb->prefix . 'member_files';

            $file = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT file_path, mime_type, original_name
                    FROM {$table}
                    WHERE id = %d AND is_deleted = 0",
                    $file_id
                ),
                ARRAY_A
            );

            if (!$file || !file_exists($file['file_path'])) {
                wp_die('File not found', 404);
            }

            // Stream file to Azure
            nocache_headers();
            header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
            header('Content-Length: ' . filesize($file['file_path']));
            header(
                'Content-Disposition: inline; filename="' .
                basename($file['original_name'] ?: $file['file_name']) . '"'
            );

            readfile($file['file_path']);
            exit;
        });         


    }
    
    public function ajax_toggle_file_visibility_scope() {

        check_ajax_referer( 'uls_member_files', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( ['message' => 'Unauthorized'], 401 );
        }

        $id = (int) ($_POST['id'] ?? 0);
        $scope = sanitize_key($_POST['visibility_scope'] ?? 'shared');
        $context = sanitize_key($_POST['context'] ?? '');

        if ( ! $id || ! in_array($scope, ['shared','private'], true) ) {
            wp_send_json_error(['message'=>'Invalid request'], 400);
        }

        global $wpdb;
        $table = $this->table;
        $current_user_id = get_current_user_id();

        $row = $wpdb->get_row(
            $wpdb->prepare(
            "SELECT uploaded_by, author_context
            FROM `{$table}`
            WHERE id = %d AND is_deleted = 0",
            $id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            wp_send_json_error(['message'=>'Not found'], 404);
        }

        // ✅ Enforce author + context
        if (
            (int)$row['uploaded_by'] !== $current_user_id ||
            $row['author_context'] !== $context
        ) {
            wp_send_json_error(['message'=>'Forbidden'], 403);
        }

        $wpdb->update(
            $table,
            [ 'visibility_scope' => $scope ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );

        wp_send_json_success([
            'id' => $id,
            'visibility_scope' => $scope
        ]);
    }

    /** Create the uls_parent_child_tags table (no WP prefix). */
    public function on_activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->table_rel}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `parent_tag`    VARCHAR(191) NOT NULL,
            `child_pattern` VARCHAR(191) NOT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_parent_tag` (`parent_tag`),
            KEY `idx_child_pattern` (`child_pattern`)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /** Front-end assets (CSS+JS). */
    public function enqueue_assets() {
        // Basic styles for the table
        wp_register_style( 'uls-members-css', plugins_url( 'uls-members.css', __FILE__ ), [], '1.6.2' );
        wp_enqueue_style( 'uls-members-css' );

        // JS for row selection + AJAX + pagination
        wp_register_script( 'uls-members-js', plugins_url( 'uls-members.js', __FILE__ ), [ 'jquery' ], '1.6.2', true );
        wp_localize_script( 'uls-members-js', 'ULS_MEMBERS', [
            'ajaxurl'           => admin_url( 'admin-ajax.php' ),
            'detailsAction'     => $this->ajax_action_details,
            'setSelectedAction' => $this->ajax_action_select,
            'nonce'             => wp_create_nonce( 'uls_members_nonce' ),
        ] );
        wp_enqueue_script( 'uls-members-js' );

        wp_register_script(
            'uls-ai-modal-js',
            plugins_url('uls-ai-modal.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script(
            'uls-ai-modal-js',
            'ULS_AI',
            [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('uls_member_files'),
            ]
        );

        wp_enqueue_script('uls-ai-modal-js');        

    }


    /** Shortcode: [uls_members_table per_page="10" fields="email,first_name,last_name,display_name"] */
    /** Shortcode: [uls_members_table per_page="10" fields="..." patterns="..." exclude_patterns="..." export="no"] */
    /** Shortcode: [uls_members_table per_page="10" fields="email,first_name,last_name,display_name,all_tags" metakeys="reward_points_balance,user_id" ...] */
    
    public function shortcode_members_table( $atts ) {

        $atts = shortcode_atts(
            [
                'per_page'         => 10,
                'fields'           => '',
                'headers'          => '',
                'patterns'         => '',
                'exclude_patterns' => '',
                'export'           => 'no',
                'parent_pattern'   => '',
                'metakeys'         => '',
            ],
            $atts,
            'uls_members_table'
        );

        $allow_export = ( $atts['export'] === 'yes' );

        if ( ! is_user_logged_in() ) {
            return '<p>Please log in to view related members.</p>';
        }

        // Allowed + default fields
        $allowed_fields = [ 'email', 'display_name', 'first_name', 'last_name', 'first_visit', 'last_visit', 'all_tags', 'matched_tags', 'rewards_points' ];
        $default_fields = [ 'email', 'display_name', 'first_name', 'last_name', 'first_visit', 'last_visit' ];

        $requested = array_filter( array_map( 'trim', explode( ',', (string) $atts['fields'] ) ) );
        $fields    = ! empty( $requested )
            ? array_values( array_intersect( $requested, $allowed_fields ) )
            : $default_fields;

        if ( empty( $fields ) ) {
            $fields = $default_fields;
        }

        // Parse metakeys (supports reward_points_balance or [reward_points_balance])
        $meta_keys = array_filter( array_map( 'trim', explode( ',', (string) $atts['metakeys'] ) ) );
        $meta_keys = array_map( function( $k ) {
            return trim( $k, '[] ' );
        }, $meta_keys );

        // Final list of columns to display (built-ins first, then meta)
        $display_fields = array_merge( $fields, $meta_keys );

        // Labels for built-in fields
        $labels_map = [
            'email'          => 'Email',
            'display_name'   => 'Name',
            'first_name'     => 'First Name',
            'last_name'      => 'Last Name',
            'first_visit'    => 'First Visit',
            'last_visit'     => 'Last Visit',
            'all_tags'       => 'All Tags',
            'matched_tags'   => 'Matched Tags',
            'rewards_points' => 'Reward Points',
        ];

        // Build headers (hierarchy column first)
        $headers = [];
        $headers[] = ''; // hierarchy icon column

        $custom_headers = [];
        if ( (string) $atts['headers'] !== '' ) {
            $custom_headers = array_map( 'trim', explode( ',', (string) $atts['headers'] ) );
        }

        foreach ( $display_fields as $i => $f ) {
            if ( isset( $custom_headers[ $i ] ) && $custom_headers[ $i ] !== '' ) {
                $headers[] = $custom_headers[ $i ];
            } elseif ( isset( $labels_map[ $f ] ) ) {
                $headers[] = $labels_map[ $f ];
            } else {
                // Humanize meta key: reward_points_balance → Reward Points Balance
                $headers[] = ucwords( str_replace( [ '_', '-' ], ' ', $f ) );
            }
        }

        // Date/time format used for both visits and meta timestamps
        $dt_format = trim( sprintf(
            '%s %s',
            (string) get_option( 'date_format', 'M j, Y' ),
            (string) get_option( 'time_format', 'g:i a' )
        ) );

        // Patterns
        $override_patterns = array_filter( array_map( 'trim', explode( ',', (string) $atts['patterns'] ) ) );
        $exclude_patterns  = array_filter( array_map( 'trim', explode( ',', (string) $atts['exclude_patterns'] ) ) );

        $parent_pattern_input = trim( (string) $atts['parent_pattern'] );

        $current_user_id = get_current_user_id();

        if ( ! empty( $override_patterns ) ) {
            $child_patterns = $override_patterns;
        } elseif ( ! empty( $parent_pattern_input ) ) {
            $child_patterns = $this->get_patterns_for_parent_input( $parent_pattern_input );
        } else {
            $current_tag_labels = $this->get_user_wpf_tag_labels( $current_user_id );
            $child_patterns     = $this->get_child_patterns_for_parents( $current_tag_labels );
        }

        bm_log( print_r( [ 
            'user_id'       => $current_user_id, 
            'parent_input'  => $parent_pattern_input, 
            'child_patterns'=> $child_patterns 
        ], true ) );

        if ( empty( $child_patterns ) ) {
            return '<div style="text-align: center; color: red; font-size: 0.5em;">No matching members found for the specified parent pattern.</div>';
        }

        // FIRST LEVEL
        $matched_users = $this->find_users_matching_child_patterns( $child_patterns, $exclude_patterns ?? [] );

        if ( empty( $matched_users ) ) {
            return '<p>No matching members were found.</p>';
        }

        // SECOND LEVEL - Insert directly after parent
        // Only claim a child if one of its tags starts with this parent's SA code
        // (prevents SA000 from incorrectly claiming SA160-1 via a broad pattern)
        $all_matched = [];
        $seen_ids = wp_list_pluck( $matched_users, 'ID' );

        foreach ( $matched_users as $first_level ) {
            $all_matched[] = $first_level;

            $parent_tags = $this->get_user_wpf_tag_labels( $first_level['ID'] );
            $parent_sa_tags = array_filter( $parent_tags, function( $t ) {
                return preg_match( '/^SA\d+/i', trim( $t ) );
            } );

            if ( empty( $parent_sa_tags ) ) {
                continue;
            }

            $sub_patterns = $this->get_child_patterns_for_single_user( $first_level['ID'], $parent_pattern_input );

            if ( ! empty( $sub_patterns ) ) {
                $sub_users = $this->find_users_matching_child_patterns( $sub_patterns, $exclude_patterns ?? [] );
                foreach ( $sub_users as $sub ) {
                    if ( in_array( $sub['ID'], $seen_ids, true ) ) {
                        continue;
                    }

                    // Strict hierarchical check: child tag must start with one of this parent's SA codes
                    $child_tags = $sub['matched_tags'] ?? [];
                    $belongs = false;
                    foreach ( $parent_sa_tags as $ptag ) {
                        $ptag = trim( $ptag );
                        foreach ( $child_tags as $ctag ) {
                            $ctag = trim( $ctag );
                            if ( $ctag !== '' && stripos( $ctag, $ptag ) === 0 ) {
                                $belongs = true;
                                break 2;
                            }
                        }
                    }

                    if ( $belongs ) {
                        $sub['hierarchy_level'] = 2;
                        $sub['parent_id']       = $first_level['ID'];
                        $all_matched[]          = $sub;
                        $seen_ids[]             = $sub['ID'];
                    }
                }
            }
        }

        // Ensure children are always immediately after their parent
        // (fixes the “parent is last row → child appears above it” case)
        $grouped       = [];
        $children_map  = [];
        foreach ( $all_matched as $item ) {
            $level = (int) ( $item['hierarchy_level'] ?? 1 );
            if ( $level === 2 ) {
                $pid = (int) ( $item['parent_id'] ?? 0 );
                if ( ! isset( $children_map[ $pid ] ) ) {
                    $children_map[ $pid ] = [];
                }
                $children_map[ $pid ][] = $item;
            } else {
                $grouped[] = $item; // preserve original parent order
            }
        }
        $all_matched = [];
        foreach ( $grouped as $p ) {
            $all_matched[] = $p;
            $pid = (int) $p['ID'];
            if ( ! empty( $children_map[ $pid ] ) ) {
                foreach ( $children_map[ $pid ] as $c ) {
                    $all_matched[] = $c;
                }
            }
        }

        // Attach visits + extra data
        $rows = $this->attach_visits_from_view( $all_matched );

        // Re-apply hierarchy data (it gets lost in attach_visits_from_view)
        $hierarchy_data = [];
        foreach ($all_matched as $item) {
            if (isset($item['ID'])) {
                $hierarchy_data[$item['ID']] = [
                    'hierarchy_level' => $item['hierarchy_level'] ?? 1,
                    'parent_id'       => $item['parent_id']       ?? 0,
                ];
            }
        }

        foreach ($rows as &$r) {
            $r['rewards_points'] = (int) get_user_meta( $r['ID'], 'reward_points_balance', true );
            $r['first_name']     = (string) get_user_meta( $r['ID'], 'first_name', true );
            $r['last_name']      = (string) get_user_meta( $r['ID'], 'last_name', true );
            $r['all_tags']       = $this->get_user_wpf_tag_labels( $r['ID'] );

            // Pull requested usermeta + auto-format Unix timestamps
            foreach ( $meta_keys as $mk ) {
                if ( in_array( strtolower( $mk ), [ 'user_id', 'id' ], true ) ) {
                    $r[ $mk ] = (int) $r['ID'];
                } else {
                    $val = get_user_meta( $r['ID'], $mk, true );

                    // Convert Unix timestamps (roughly year 2000–2100) to readable datetime
                    if ( is_numeric( $val ) && (int) $val > 946684800 && (int) $val < 4102444800 ) {
                        $val = date_i18n( $dt_format, (int) $val );
                    } elseif ( is_array( $val ) ) {
                        $val = implode( ', ', $val );
                    }

                    $r[ $mk ] = $val;
                }
            }

            // Restore hierarchy info
            if (isset($hierarchy_data[$r['ID']])) {
                $r['hierarchy_level'] = $hierarchy_data[$r['ID']]['hierarchy_level'];
                $r['parent_id']       = $hierarchy_data[$r['ID']]['parent_id'];
            } else {
                $r['hierarchy_level'] = 1;
            }
        }
        unset($r);

        // Mark which parents actually have children (for the ▼ icon) - robust parent_id based
        $has_child_map = [];
        foreach ( $rows as $r ) {
            if ( (int) ( $r['hierarchy_level'] ?? 1 ) === 2 && ! empty( $r['parent_id'] ) ) {
                $has_child_map[ (int) $r['parent_id'] ] = true;
            }
        }

        foreach ($rows as &$r) {
            $r['has_children'] = isset($has_child_map[$r['ID']]);
        }
        unset($r);

        bm_log( 'Has child map (after fix): ' . print_r( $has_child_map, true ) );

        // === RENDER STARTS HERE ===
        $per_page = intval( $atts['per_page'] );
        if ( $per_page <= 0 ) { $per_page = 10; }

        ob_start(); ?>
        
        
        <div class="uls-members" data-per-page="<?php echo esc_attr( $per_page ); ?>">

            <?php if ( $allow_export ): ?>
                <?php $export_url = add_query_arg( 'uls_export', '1' ); ?>
                <div style="margin-bottom:10px;">
                    <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary">
                        Export CSV
                    </a>
                </div>
            <?php endif; ?>

            <div class="uls-members__search">
                <input type="text" class="uls-members__search-input" placeholder="Search members…" autocomplete="off" />
                <button type="button" class="uls-members__search-clear">&times;</button>
            </div>

            <table class="uls-members__table">
                <thead>
                    <tr>
                        <?php foreach ( $headers as $h ): ?>
                            <th><?php echo esc_html( $h ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>

                <tbody class="uls-members__tbody">
                    <?php foreach ( $rows as $r ): 
                        $level = (int) ($r['hierarchy_level'] ?? 1);
                        $is_sub = ($level === 2);
                        $has_children = !empty($r['has_children']);
                    ?>
                        <tr class="uls-members__row <?php echo $is_sub ? 'uls-sub-level' : 'uls-parent-level'; ?>" 
                            data-email="<?php echo esc_attr( $r['user_email'] ?? '' ); ?>"
                            data-level="<?php echo esc_attr( $level ); ?>"
                            data-parent-id="<?php echo esc_attr( $r['parent_id'] ?? 0 ); ?>"
                            data-user-id="<?php echo esc_attr( $r['ID'] ); ?>">
                            
                            <!-- Hierarchy Column -->
                            <td class="uls-hierarchy-col" style="width: 40px; text-align: center; vertical-align: middle;">
                                <?php if ( !$is_sub && $has_children ): ?>
                                    <span class="toggle-downline" 
                                          style="cursor: pointer; color: #FD5A38; font-size: 1.4em; font-weight: bold; display: inline-block; width: 20px;">▼</span>
                                <?php elseif ( $is_sub ): ?>
                                    <span style="color: #FD5A38; margin-left: 18px; font-size: 1.2em;">↳</span>
                                <?php endif; ?>
                            </td>

                            <?php foreach ( $display_fields as $f ): ?>
                                <?php
                                $key = ($f === 'email') ? 'user_email' : $f;
                                $td_attr = '';

                                if ( $f === 'all_tags' ) {
                                    $tags = (array) ($r['all_tags'] ?? []);
                                    sort( $tags, SORT_STRING | SORT_FLAG_CASE );
                                    $cell = implode( ', ', $tags );
                                    $td_attr = ' data-col="all_tags" data-user-id="' . esc_attr( $r['ID'] ) . '"';
                                } elseif ( $f === 'matched_tags' ) {
                                    $tags = (array) ($r['matched_tags'] ?? []);
                                    sort( $tags, SORT_STRING | SORT_FLAG_CASE );
                                    $cell = implode( ', ', $tags );
                                } elseif ( $f === 'rewards_points' ) {
                                    $cell = number_format( (int) ($r['rewards_points'] ?? 0) );
                                } else {
                                    $cell = $r[$key] ?? '';
                                    if ( $f === 'email' ) {
                                        $td_attr = ' data-col="email"';
                                    }
                                }
                                ?>

                                <td<?php echo $td_attr; ?> style="<?php echo $is_sub ? 'background-color:#f8f9fa; font-style:italic;' : ''; ?>">
                                    <?php echo esc_html( $cell ); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>

                
            </table>

            <div class="uls-members__pager">
                <button type="button" class="uls-pager__prev">Prev</button>
                <span class="page-info">
                    <span class="uls-pager__current">1</span> of <span class="uls-pager__total">1</span>
                </span>
                <button type="button" class="uls-pager__next">Next</button>
            </div>

        </div>
        <?php

        return ob_get_clean();
    }


    /** Get a user's WP Fusion tag labels (translate IDs → labels). */
    private function get_user_wpf_tag_labels( $user_id ) {
        $tags = function_exists( 'wpf_get_tags' ) ? wpf_get_tags( $user_id ) : [];
        $labels = [];
        if ( ! empty( $tags ) ) {
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
        return array_unique( $labels );
    }

    /**
     * Get current user's parent patterns with multi-level support.
     * Returns self + direct children + grandchildren (sub-sales) etc.
     */
    private function get_current_parent_patterns() {
        $current_user_id = get_current_user_id();
        if ( ! $current_user_id ) {
            return [];
        }

        $tags = $this->get_user_wpf_tag_labels( $current_user_id );

        // Fallback to raw meta
        if ( empty( $tags ) || ! is_array( $tags ) ) {
            $tags = get_user_meta( $current_user_id, 'zoho_tags', true );
            if ( empty( $tags ) || ! is_array( $tags ) ) {
                $tags = get_user_meta( $current_user_id, 'multi_tags', true );
            }
        }
        if ( ! is_array( $tags ) ) {
            $tags = [];
        }

        if ( empty( $tags ) ) {
            return [];
        }

        // Start with direct
        $patterns = $this->get_child_patterns_for_parents( $tags );
        $patterns = array_merge( $patterns, $tags ); // include self

        // Add one extra level for sub-sales (Susan under John)
        $sub_patterns = $this->get_child_patterns_for_parents( $patterns );
        $patterns = array_merge( $patterns, $sub_patterns );

        return array_unique( array_filter( array_map( 'trim', $patterns ) ) );
    }

    /** Fetch child patterns for any matched parent_tag. */
    private function get_child_patterns_for_parents( array $parent_labels ) {
        global $wpdb;
        if ( empty( $parent_labels ) ) { return []; }
        $placeholders = implode( ',', array_fill( 0, count( $parent_labels ), '%s' ) );
        $sql = "SELECT child_pattern FROM `{$this->table_rel}` WHERE parent_tag IN ($placeholders)";
        $results = $wpdb->get_col( $wpdb->prepare( $sql, $parent_labels ) );
        return array_unique( array_map( 'trim', (array) $results ) );
    }

    /**
     * Get patterns for a specific parent_pattern input (multi-level support).
     * If empty, falls back to single-level current user behavior.
     */
    private function get_patterns_for_parent( $parent_pattern_input = '' ) {
        if ( empty( $parent_pattern_input ) ) {
            // Legacy single-level behavior
            $current_user_id = get_current_user_id();
            $tags = $this->get_user_wpf_tag_labels( $current_user_id );
            return $this->get_child_patterns_for_parents( $tags );
        }

        // Normalize input (support comma-separated or single)
        $parent_patterns = array_filter( array_map( 'trim', explode( ',', (string) $parent_pattern_input ) ) );

        global $wpdb;
        $all_patterns = $parent_patterns; // include the parent itself

        // Get direct children from relation table
        $direct_children = $this->get_child_patterns_for_parents( $parent_patterns );
        $all_patterns = array_merge( $all_patterns, $direct_children );

        // Add one extra level for sub-sales / multi-level
        if ( ! empty( $direct_children ) ) {
            $sub_children = $this->get_child_patterns_for_parents( $direct_children );
            $all_patterns = array_merge( $all_patterns, $sub_children );
        }

        return array_unique( array_filter( array_map( 'trim', $all_patterns ) ) );
    }

    /**
     * Auto-detect sales hierarchy for the current logged-in user (multi-level).
     * Used on shared sales portal page.
     */
    private function get_sales_hierarchy_patterns() {
        $current_user_id = get_current_user_id();
        if ( ! $current_user_id ) {
            return [];
        }

        // Get all tags (prefer WP Fusion helper)
        $tags = $this->get_user_wpf_tag_labels( $current_user_id );

        if ( empty( $tags ) || ! is_array( $tags ) ) {
            $tags = get_user_meta( $current_user_id, 'zoho_tags', true );
            if ( empty( $tags ) || ! is_array( $tags ) ) {
                $tags = get_user_meta( $current_user_id, 'multi_tags', true );
            }
        }
        if ( ! is_array( $tags ) ) {
            $tags = [];
        }

        // Filter to primary sales codes only (SA###)
        $sales_codes = array_filter( $tags, function( $tag ) {
            return preg_match( '/^SA\d+/i', trim( $tag ) );
        });

        if ( empty( $sales_codes ) ) {
            return [];
        }

        // Build hierarchy
        $all_patterns = $sales_codes;                    // include self

        // Direct downline (SA200*)
        $direct = $this->get_child_patterns_for_parents( $sales_codes );
        $all_patterns = array_merge( $all_patterns, $direct );

        // Sub-sales downline (Tom's SA160* when John is viewing)
        if ( ! empty( $direct ) ) {
            $sub = $this->get_child_patterns_for_parents( $direct );
            $all_patterns = array_merge( $all_patterns, $sub );
        }

        return array_unique( array_filter( array_map( 'trim', $all_patterns ) ) );
    }

    /**
     * Get child patterns for a specific user (for second-level drill-down).
     */
    private function get_child_patterns_for_single_user( $user_id, $parent_pattern_input ) {
        $user_tags = $this->get_user_wpf_tag_labels( $user_id );
        if ( empty( $user_tags ) || ! is_array( $user_tags ) ) {
            $user_tags = get_user_meta( $user_id, 'zoho_tags', true ) ?: get_user_meta( $user_id, 'multi_tags', true ) ?: [];
        }
        if ( ! is_array( $user_tags ) ) $user_tags = [];

        $input_patterns = array_filter( array_map( 'trim', explode( ',', (string) $parent_pattern_input ) ) );
        $matching = [];

        foreach ( $user_tags as $tag ) {
            $tag = trim( $tag );
            foreach ( $input_patterns as $p ) {
                $p = trim( $p );
                if ( stripos( $p, 'SA' ) === 0 && preg_match( '/^SA[0-9]/i', $tag ) ) {
                    $matching[] = $tag;
                    break;
                }
            }
        }

        return $this->get_child_patterns_for_parents( $matching );
    }



    /**
     * Get hierarchy patterns based on a shortcode parent_pattern (e.g. "SA###" or "SA200").
     * Matches against current user's tags, then follows parent/child table.
     */
    /**
     * Get hierarchy patterns based on shortcode parent_pattern (e.g. "SA###").
     */
    private function get_patterns_for_parent_input( $parent_pattern_input ) {
        if ( empty( $parent_pattern_input ) ) {
            $tags = $this->get_user_wpf_tag_labels( get_current_user_id() );
            return $this->get_child_patterns_for_parents( $tags );
        }

        $current_user_id = get_current_user_id();
        bm_log( '=== START for user ' . $current_user_id . ' parent_pattern=' . $parent_pattern_input . ' ===' );

        // Get current user's sales codes (this is the key filter)
        $user_tags = $this->get_user_wpf_tag_labels( $current_user_id );
        if ( empty( $user_tags ) || ! is_array( $user_tags ) ) {
            $user_tags = get_user_meta( $current_user_id, 'zoho_tags', true ) ?: get_user_meta( $current_user_id, 'multi_tags', true ) ?: [];
        }
        if ( ! is_array( $user_tags ) ) $user_tags = [];

        bm_log( 'User tags: ' . print_r( $user_tags, true ) );

        // Filter user's tags to only those matching the shortcode parent_pattern (e.g. SA###)
        $input_patterns = array_filter( array_map( 'trim', explode( ',', (string) $parent_pattern_input ) ) );
        $matching_user_parents = [];

        foreach ( $user_tags as $tag ) {
            $tag = trim( $tag );
            if ( empty( $tag ) ) continue;
            foreach ( $input_patterns as $p ) {
                $p = trim( $p );
                if ( stripos( $p, 'SA' ) === 0 && preg_match( '/^SA[0-9]/i', $tag ) ) {
                    $matching_user_parents[] = $tag;
                    break;
                }
            }
        }

        bm_log( 'Matching parents for this user: ' . print_r( $matching_user_parents, true ) );

        if ( empty( $matching_user_parents ) ) {
            return [];
        }

        // Now build hierarchy ONLY from this user's matching parents
        $all_patterns = $matching_user_parents;

        $direct = $this->get_child_patterns_for_parents( $matching_user_parents );
        $all_patterns = array_merge( $all_patterns, $direct );

        if ( ! empty( $direct ) ) {
            $sub = $this->get_child_patterns_for_parents( $direct );
            $all_patterns = array_merge( $all_patterns, $sub );
        }

        bm_log( 'Final child_patterns: ' . print_r( $all_patterns, true ) );

        return array_unique( array_filter( array_map( 'trim', $all_patterns ) ) );
    }


    /**
     * Resolve a color from the BSI lookup table for a percent value.
     * Uses form_id = 0 (overall).
     */
    private function get_bsi_color_for_percent( float $percent ): string {
        if ( ! is_numeric( $percent ) ) {
            return '';
        }

        global $wpdb;
        $table = 'uls_bm_bsi_form_lookup';

        $sql = "
            SELECT form_color
            FROM {$table}
            WHERE form_id = 0
            AND %f >= low_value
            AND %f < high_value
            LIMIT 1
        ";

        return (string) $wpdb->get_var(
            $wpdb->prepare( $sql, $percent, $percent )
        );
    }
    
    private function get_bsi_colors_for_row( array $row ): array {
        $colors = [];

        if ( empty( $row ) ) {
            return $colors;
        }

        foreach ( $row as $key => $value ) {
            if ( ! is_numeric( $value ) ) {
                continue;
            }

            $num = (float) $value;

            // Normalize fractional scores (0–1) to percent
            if ( $num > 0 && $num <= 1 ) {
                $num *= 100;
            }

            $color = $this->get_bsi_color_for_percent( $num );

            if ( $color ) {
                $colors[ $key ] = $color;
            }
        }

        return $colors;
    }

    /** Find users where ANY of their tag labels match ANY child wildcard pattern, AND none match exclude patterns. */
    private function find_users_matching_child_patterns( array $child_patterns, array $exclude_patterns = [] ) {
        $include_regexes = array_map( [ $this, 'wildcard_to_regex' ], $child_patterns );
        $exclude_regexes = array_map( [ $this, 'wildcard_to_regex' ], $exclude_patterns );

        $meta_query = [];
        if ( defined( 'WPF_TAGS_META_KEY' ) ) {
            $meta_query[] = [ 'key' => WPF_TAGS_META_KEY, 'compare' => 'EXISTS' ];
        }

        $user_query = new WP_User_Query( [
            'fields'     => [ 'ID', 'user_email', 'display_name', 'user_registered' ],
            'meta_query' => $meta_query,
            'number'     => 5000,
        ] );

        $matched = [];
        $users   = $user_query->get_results();

        foreach ( $users as $u ) {
            $labels = $this->get_user_wpf_tag_labels( $u->ID );
            if ( empty( $labels ) ) { continue; }

            // Check inclusion
            $hits = [];
            foreach ( $labels as $label ) {
                foreach ( $include_regexes as $rx ) {
                    if ( preg_match( $rx, $label ) ) {
                        $hits[] = $label;
                        break;
                    }
                }
            }
            if ( empty( $hits ) ) {
                continue; // must match at least one include
            }

            // NEW: Check exclusion
            $excluded = false;
            if ( ! empty( $exclude_regexes ) ) {
                foreach ( $labels as $label ) {
                    foreach ( $exclude_regexes as $rx ) {
                        if ( preg_match( $rx, $label ) ) {
                            $excluded = true;
                            break 2;
                        }
                    }
                }
            }
            if ( $excluded ) {
                continue;
            }

            $matched[] = [
                'ID'              => $u->ID,
                'user_email'      => $u->user_email,
                'display_name'    => $u->display_name,
                'user_registered' => $u->user_registered,
                'matched_tags'    => array_values( array_unique( $hits ) ),
            ];
        }
        return $matched;
    }

    /**
     * Convert wildcard pattern to regex.
     * Supports:
     *   *  → any characters (.*)
     *   #  → a single digit (\d)
     *
     * Examples:
     *   SA###     → /^SA\d\d\d$/i     (exact 3-digit sales codes)
     *   SA*       → /^SA.*$/i         (anything starting with SA)
     *   SA###-*   → /^SA\d\d\d-.*$/i  (customers under a 3-digit rep)
     */
    private function wildcard_to_regex( $pattern ) {
        if ( empty( $pattern ) ) {
            return '/^$/';
        }

        // Escape regex special chars first
        $regex = preg_quote( $pattern, '/' );

        // Restore our wildcards
        $regex = str_replace( '\\*', '.*', $regex );  // * → any sequence
        $regex = str_replace( '\\#', '\\d', $regex );  // # → one digit

        return '/^' . $regex . '$/i';  // full-string, case-insensitive
    }


    /** Helper: normalize any cell into a UNIX timestamp (supports "count|timestamp", single values, and CRLF/newline). */
    private function parse_visit_ts( $val ) {
        if ( $val === null ) return null;

        // Numeric: treat as UNIX epoch
        if ( is_numeric( $val ) ) {
            $ts = (int) $val; return $ts > 0 ? $ts : null;
        }

        if ( is_string( $val ) ) {
            $v = trim( $val ); if ( $v === '' ) return null;
            // Prefer pipe first, then newline split
            $tokens = explode( '|', $v );
            if ( count( $tokens ) < 2 ) {
                $tokens = preg_split( "/\r\n|\n|\r/", $v );
            }
            // Choose the second token if present (expected 'count|timestamp'); else first
            $candidate = trim( isset( $tokens[1] ) ? $tokens[1] : $tokens[0] );
            if ( $candidate === '' ) return null;

            if ( ctype_digit( $candidate ) ) {
                $ts = (int) $candidate; return $ts > 0 ? $ts : null;
            }
            $ts = strtotime( $candidate );
            return $ts ?: null;
        }
        return null;
    }

    /** Attach First/Last visit derived from user_page_visits_view by email. (pipe-delimited support) */
    private function attach_visits_from_view( array $users ) {
        global $wpdb;
        $out = [];
        foreach ( $users as $row ) {
            $first = null; $last = null;
            $visits = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$this->view_visits}` WHERE `user_email` = %s LIMIT 1",
                    $row['user_email']
                ),
                ARRAY_A
            );
            if ( $visits && is_array( $visits ) ) {
                // Prefer dedicated columns if present
                foreach ( [ 'first_visit', 'last_visit' ] as $special ) {
                    if ( array_key_exists( $special, $visits ) && $visits[ $special ] !== '' ) {
                        $ts = $this->parse_visit_ts( $visits[ $special ] );
                        if ( $ts ) {
                            if ( $special === 'first_visit' ) {
                                $first = ( $first === null ) ? $ts : min( $first, $ts );
                            } else {
                                $last  = ( $last  === null ) ? $ts : max( $last, $ts );
                            }
                        }
                    }
                }
                // Scan fld_* columns (legacy format: count|timestamp)
                foreach ( $visits as $col => $val ) {
                    if ( $col === 'user_email' ) { continue; }
                    if ( strpos( $col, 'fld_' ) !== 0 ) { continue; }
                    if ( ! is_string( $val ) || $val === '' ) { continue; }
                    $ts = $this->parse_visit_ts( $val );
                    if ( $ts ) {
                        $first = ( $first === null ) ? $ts : min( $first, $ts );
                        $last  = ( $last  === null ) ? $ts : max( $last, $ts );
                    }
                }
            }

            // Build a user-friendly combined format from site settings
            $dt_format = trim( sprintf(
                '%s %s',
                (string) get_option( 'date_format', 'M j, Y' ),
                (string) get_option( 'time_format', 'g:i a' )
            ) );
            
            
            // Optional fallback to registration date
            if ( $first === null && $last === null && ! empty( $row['user_registered'] ) ) {
                $reg = strtotime( $row['user_registered'] );
                if ( $reg ) { $first = $reg; $last = $reg; }
            }
            $out[] = [
                'ID'           => $row['ID'],
                'user_email'   => $row['user_email'],
                'display_name' => $row['display_name'],
                'matched_tags' => $row['matched_tags'],
                'first_visit'   => $first ? date_i18n( $dt_format, $first ) : null,
                'last_visit'    => $last  ? date_i18n( $dt_format, $last  ) : null,
            ];
        }
        return $out;
    }

    /**
     * Get the latest BSI results row for a user by email.
     * Table: uls_bm_bsi_results
     * Returns associative array (column => value) or null if none.
     */
    private function get_latest_bsi_by_email( $email ) {
        if ( empty( $email ) || ! is_email( $email ) ) {
            return null;
        }

        global $wpdb;

        // Pull the row with the maximum results_date for this email.
        // Uses the composite unique (user_email, results_date) for a deterministic single row.
        $sql = "
            SELECT r.*
            FROM `uls_bm_bsi_results` r
            INNER JOIN (
                SELECT `user_email`, MAX(`results_date`) AS max_dt
                FROM `uls_bm_bsi_results`
                WHERE `user_email` = %s
                GROUP BY `user_email`
            ) mx
                ON mx.user_email = r.user_email
            AND mx.max_dt = r.results_date
            LIMIT 1
        ";

        $row = $wpdb->get_row( $wpdb->prepare( $sql, $email ), ARRAY_A );

        return $row ?: null;
    }

    /**
     * Get the latest FINAL RSI results row for a user by email.
     * Table: {prefix}bm_rsi_results
     * Criteria:
     *  - user_email match
     *  - is_final = 1
     *  - most recent results_date
     */
    private function get_latest_rsi_by_email( $email ) {
        if ( empty( $email ) || ! is_email( $email ) ) {
            return null;
        }

        global $wpdb;
        $t = $wpdb->prefix . 'bm_rsi_results';

        $sql = "
            SELECT r.*
            FROM {$t} r
            WHERE r.user_email = %s
            AND r.is_final = 1
            ORDER BY r.results_date DESC, r.id DESC
            LIMIT 1
        ";

        $row = $wpdb->get_row(
            $wpdb->prepare( $sql, $email ),
            ARRAY_A
        );

        return $row ?: null;
    }    

    /**
     * AJAX: get details for a selected member by email from:
     * - uls_wptm_tbl_4 (col2 = Email)
     * - uls_ULS_CF_BIO (Email = Email)
     * - vw_wc_orders_full (billing_email = Email) → list of rows with order_date, product_id, product_name
     * - uls_key_essentials ("User Email" = Email) → list with Datetime, Form Id (transformed), Average Score (0–100%)
     */
    /**
     * AJAX: get details for a selected member by email
     */
    /**
     * AJAX: get details for a selected member by email
     */
    public function ajax_get_member_details() {
        check_ajax_referer( 'uls_members_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
        }

        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Invalid email' ], 400 );
        }

        global $wpdb;

        // Date format (unchanged)
        $dt_format = trim( sprintf(
            '%s %s',
            (string) get_option( 'date_format', 'M j, Y' ),
            (string) get_option( 'time_format', 'g:i a' )
        ) );

        // Core queries (unchanged)
        $row_wptm = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `uls_wptm_tbl_4` WHERE `col2` = %s LIMIT 1", $email
        ), ARRAY_A );

        $row_profile = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `uls_ULS_CF_BIO` WHERE `email` = %s LIMIT 1", $email
        ), ARRAY_A );

        $member_user = get_user_by( 'email', $email );
        $member_user_id = $member_user ? (int) $member_user->ID : 0;

        $latest_rsi = $this->get_latest_rsi_by_email( $email );
        $latest_bsi = $this->get_latest_bsi_by_email( $email );

        $bsi_colors = $latest_bsi ? $this->get_bsi_colors_for_row( $latest_bsi ) : [];
        $rsi_colors = $latest_rsi ? $this->get_bsi_colors_for_row( $latest_rsi ) : [];

        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT `order_date`, `product_id`, `product_name`, `line_total`
            FROM `vw_wc_orders_full`
            WHERE `billing_email` = %s
            ORDER BY `order_date` DESC
            LIMIT 200", $email
        ), ARRAY_A );

        // Format order dates (unchanged)
        if ( is_array( $orders ) ) {
            foreach ( $orders as &$o ) {
                if ( ! empty( $o['order_date'] ) ) {
                    $ts = is_numeric( $o['order_date'] ) ? (int) $o['order_date'] : strtotime( (string) $o['order_date'] );
                    if ( $ts ) {
                        $o['order_date'] = date_i18n( $dt_format, $ts );
                    }
                }
            }
            unset( $o );
        }

        $raw_keys = $wpdb->get_results( $wpdb->prepare(
            "SELECT `Datetime`, `Form_Id`, `Average_Score`
            FROM `uls_key_essentials`
            WHERE `User_Email` = %s
            ORDER BY `Datetime` DESC
            LIMIT 200", $email
        ), ARRAY_A );

        $keys = is_array( $raw_keys ) ? $raw_keys : [];

        // === EXISTING REWARDS ===
        $uls_rewards = [
            'reward_points_balance' => $member_user_id
                ? (int) get_user_meta( $member_user_id, 'reward_points_balance', true )
                : 0
        ];

        // === NEW: Generic usermeta support ===
        $usermeta = [];
        if ( $member_user_id ) {
            // Optional: fetch ALL usermeta (heavier but very flexible)
            $usermeta = array_map( function($v){ return maybe_unserialize($v[0] ?? $v); }, get_user_meta( $member_user_id ) );
        }

        // Profile fallback (unchanged)
        if ( is_array( $row_profile ) ) {
            if ( empty( $row_profile['user_id'] ) || (int) $row_profile['user_id'] === 0 ) {
                $row_profile['user_id'] = $member_user_id;
            }
        } else {
            $row_profile = [
                'user_id' => $member_user_id,
                'email'   => $email
            ];
        }

        wp_send_json_success( [
            'uls_wptm_tbl_4'            => $row_wptm ?: [],
            'uls_uls_cf_bio'            => $row_profile,
            'vw_wc_orders_full'         => is_array( $orders ) ? $orders : [],
            'uls_key_essentials'        => $keys,
            'uls_bm_rsi_results_latest' => $latest_rsi ?: [],
            'uls_bm_rsi_colors'         => $rsi_colors,
            'uls_bm_bsi_results_latest' => $latest_bsi ?: [],
            'uls_bm_bsi_colors'         => $bsi_colors,
            'uls_rewards'               => $uls_rewards,
            'usermeta'                  => $usermeta,   // ← NEW
        ] );
    }

    
    /**
     * AJAX: Update WP Fusion tags for a member (add/remove)
     */

    public function ajax_update_user_tags() {
        check_ajax_referer( 'uls_members_nonce', 'nonce' );

        $user_id      = (int) ( $_POST['user_id'] ?? 0 );
        $new_tags_raw = sanitize_text_field( wp_unslash( $_POST['tags'] ?? '' ) );

        if ( ! $user_id || ! function_exists( 'wp_fusion' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid request' ], 400 );
        }

        $new_tags = array_filter( array_map( 'trim', explode( ',', $new_tags_raw ) ) );

        // Remove old tags + apply new ones (simple & safe for this use case)
        $current_tag_ids = wpf_get_tags( $user_id ) ?: [];
        if ( ! empty( $current_tag_ids ) ) {
            wp_fusion()->user->remove_tags( $current_tag_ids, $user_id );
        }

        if ( ! empty( $new_tags ) ) {
            wp_fusion()->user->apply_tags( $new_tags, $user_id );
            do_action( 'wpf_apply_tags', $new_tags, $user_id );
        }

        bm_log( "Tags updated for user {$user_id}: " . implode(', ', $new_tags) );

        wp_send_json_success( [ 'user_id' => $user_id, 'tags' => $new_tags ] );
    }

    /**
     * AJAX: persist the selected user (by email) for the current logged-in user.
     * Stores:
     * - uls_selected_email
     * - uls_selected_user_id (if email belongs to a WP user)
     */
    public function ajax_set_selected_user() {
        check_ajax_referer( 'uls_members_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
        }
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Invalid email' ], 400 );
        }
        $user_id = get_current_user_id();
        // Store selected email
        update_user_meta( $user_id, 'uls_selected_email', $email );
        // If email corresponds to a WP user, store that ID too
        $selected_wp_user = get_user_by( 'email', $email );
        if ( $selected_wp_user ) {
            update_user_meta( $user_id, 'uls_selected_user_id', $selected_wp_user->ID );
        } else {
            delete_user_meta( $user_id, 'uls_selected_user_id' );
        }
        wp_send_json_success( [ 'message' => 'Selection saved', 'email' => $email ] );
    }

    /**
     * Shortcode: [uls_selected_user]
     * Usage examples:
     * - [uls_selected_user field="email"]
     * - [uls_selected_user field="display_name" fallback="(unknown)"]
     * - [uls_selected_user field="meta" src="uls_ULS_CF_BIO" key="Sex"]
     * - [uls_selected_user field="meta" src="uls_wptm_tbl_4" key="col7"]
     */
    public function shortcode_selected_user( $atts ) {
        if ( ! is_user_logged_in() ) { return ''; }
        $atts = shortcode_atts( [
            'field'    => 'email', // email | id | first_name | last_name | display_name | meta
            'key'      => '',      // used when field="meta"
            'src'      => '',      // 'uls_wptm_tbl_4' or 'uls_ULS_CF_BIO' when field="meta"
            'fallback' => '',      // optional fallback text if empty
        ], $atts, 'uls_selected_user' );

        $current_user_id   = get_current_user_id();
        $selected_email    = get_user_meta( $current_user_id, 'uls_selected_email', true );
        $selected_user_id  = get_user_meta( $current_user_id, 'uls_selected_user_id', true );

        // If no selection yet, honor fallback or empty
        if ( empty( $selected_email ) ) {
            return $atts['fallback'];
        }

        switch ( strtolower( $atts['field'] ) ) {
            case 'email':
                return esc_html( $selected_email );
            case 'id':
                return esc_html( (string) $selected_user_id );
            case 'first_name':
            case 'last_name':
            case 'display_name':
                if ( $selected_user_id ) {
                    $u = get_user_by( 'id', $selected_user_id );
                    if ( $u ) {
                        if ( $atts['field'] === 'first_name' ) {
                            return esc_html( get_user_meta( $u->ID, 'first_name', true ) );
                        } elseif ( $atts['field'] === 'last_name' ) {
                            return esc_html( get_user_meta( $u->ID, 'last_name', true ) );
                        } else {
                            return esc_html( $u->display_name );
                        }
                    }
                }
                // Fallback: show email if no WP user exists for it
                return esc_html( $selected_email );
            case 'meta':
                $key = trim( (string) $atts['key'] );
                $src = trim( (string) $atts['src'] );
                if ( $key === '' || $src === '' ) { return $atts['fallback']; }
                global $wpdb;
                $row = null;
                if ( $src === 'uls_wptm_tbl_4' ) {
                    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `uls_wptm_tbl_4` WHERE `col2` = %s LIMIT 1", $selected_email ), ARRAY_A );
                } elseif ( $src === 'uls_ULS_CF_BIO' ) {
                    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `uls_ULS_CF_BIO` WHERE `email` = %s LIMIT 1", $selected_email ), ARRAY_A );

                } elseif ( $src === 'uls_bm_bsi_results' ) {
                    // Use the latest row by results_date for this email
                    $row = $this->get_latest_bsi_by_email( $selected_email );
                }elseif ( $src === 'uls_bm_rsi_results' ) {
                    $row = $this->get_latest_rsi_by_email( $selected_email );
                }
                if ( $row && array_key_exists( $key, $row ) ) {
                    return esc_html( (string) $row[ $key ] );
                }
                // Case-insensitive fallback
                if ( $row ) {
                    $kl = strtolower( $key );
                    foreach ( $row as $rk => $rv ) {
                        if ( strtolower( $rk ) === $kl ) {
                            return esc_html( (string) $rv );
                        }
                    }
                }
                return $atts['fallback'];
            default:
                return $atts['fallback'];
        }
    }

    public function log_debug(string $message, $data = null): void
    {
        // Optional runtime gate
        if (!defined('ULS_DEBUG') || !ULS_DEBUG) {
            return;
        }

        $dir  = plugin_dir_path(__FILE__) . 'logs/';
        $file = $dir . 'ai-debug.log';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message;

        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $entry .= ' | ' . json_encode($data, JSON_PRETTY_PRINT);
            } else {
                $entry .= ' | ' . (string) $data;
            }
        }

        file_put_contents($file, $entry . PHP_EOL, FILE_APPEND);
    }    

    public function handle_csv_export() {

        if ( empty($_GET['uls_export']) || $_GET['uls_export'] != '1' ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            wp_die('Unauthorized');
        }

        // ✅ Send headers BEFORE anything else
        $filename = 'uls-members-' . date('Y-m-d_H-i') . '.csv';

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // ✅ Get plugin instance
        $plugin = self::instance();
        global $wpdb;

        // ✅ Recreate minimal pipeline (same logic you already use)

        $current_user_id = get_current_user_id();
        $current_tag_labels = $plugin->get_user_wpf_tag_labels( $current_user_id );

        if ( empty( $current_tag_labels ) ) {
            exit;
        }

        $child_patterns = $plugin->get_child_patterns_for_parents( $current_tag_labels );
        $matched_users  = $plugin->find_users_matching_child_patterns( $child_patterns );
        $rows           = $plugin->attach_visits_from_view( $matched_users );

        // ✅ Add rewards
        foreach ( $rows as &$r ) {
            $r['rewards_points'] = isset($r['ID'])
                ? (int) get_user_meta($r['ID'], 'reward_points_balance', true)
                : 0;
        }
        unset($r);

        // ✅ Define fields (match your default OR pass via URL later)
        $fields = [ 'email', 'display_name', 'first_visit', 'last_visit', 'rewards_points' ];

        $headers = [ 'Email', 'Name', 'First Visit', 'Last Visit', 'Reward Points' ];

        // ✅ Write CSV
        $output = fopen('php://output', 'w');

        fputcsv($output, $headers);

        foreach ( $rows as $r ) {

            fputcsv($output, [
                $r['user_email'] ?? '',
                $r['display_name'] ?? '',
                $r['first_visit'] ?? '',
                $r['last_visit'] ?? '',
                $r['rewards_points'] ?? 0,
            ]);
        }

        fclose($output);
        exit;
    }
    

}

// Bootstrap the plugin
ULS_Members_Plugin::instance();

/* -------- Inline CSS (shared styles for members, orders, and keys tables) -------- */
add_action( 'wp_head', function() {
    ?>
    <style id="uls-members-inline-css">
    /* Shared table styles for members, orders, keys */
    .uls-members__table,
    .uls-orders__table,
    .uls-keys__table { width:100%; border-collapse: collapse; }
    .uls-members__table th, .uls-members__table td,
    .uls-orders__table  th, .uls-orders__table  td,
    .uls-keys__table    th, .uls-keys__table    td { padding:8px 10px; border-bottom:1px solid #6ec1e4; text-align:center; }
    .uls-members__table thead th,
    .uls-orders__table  thead th,
    .uls-keys__table    thead th { background:#6ec1e4; font-weight:600; }
    /* Members row hover/selection */
    .uls-members__row { cursor:pointer; }
    .uls-members__row:hover { background:#f8f8f8; }
    .uls-members__row.is-selected { outline:2px solid #3b82f6; }
    /* Compact pager */
    .uls-members__pager { display:inline-flex; gap:.5rem; align-items:center; margin:.5rem 0; font-size:0.875rem; }
    .uls-members__pager button { padding:.25rem .5rem; border:1px solid #d1d5db; background:#fff; font-size:0.8rem; cursor:pointer; }
    .uls-members__pager button[disabled] { opacity:.5; cursor:not-allowed; }
    .uls-pager__status { white-space:nowrap; }
    /* Details area (if you use it elsewhere) */
    .uls-members__details { margin-top:.75rem; padding:.75rem; background:#f9fafb; border:1px solid #e5e7eb; }
    .uls-member-field { font-size:0.95rem; }
    
    .uls-member-field.uls-empty {
        font-style: italic;
        font-weight: normal;
        font-size: 0.7em;
        color: #777;
    }
    .uls-members__controls {
        margin-bottom: 0.75rem;
        display: flex;
        justify-content: flex-start;
    }

    .uls-members__search-input {
        max-width: 280px;
        padding: 6px 8px;
    }
    .uls-members__search {
        position: relative;
        display: inline-block;
    }

    .uls-members__search-input {
        padding-right: 28px; /* room for the × */
    }

    .uls-members__search-clear {
        position: absolute;
        right: 6px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: 0;
        font-size: 18px;
        line-height: 1;
        cursor: pointer;
        color: #888;
        display: none;
    }

    .uls-members__search-clear:hover {
        color: #000;
    }    

    </style>
    <?php
});


    // Load the Member Notes and Files module (AJAX + assets + schema)
    require_once __DIR__ . '/uls-notes.php';
    require_once __DIR__ . '/uls-files.php';

    // Appointments module (scheduling)
    require_once plugin_dir_path(__FILE__) . 'uls-appointments.php';
    require_once plugin_dir_path( __FILE__ ) . 'recommended-products/uls-recommended-products.php';

        add_action('init', function () {

            if ( empty($_GET['uls_export']) || $_GET['uls_export'] != '1' ) {
                return;
            }

            // Optional: require login
            if ( ! is_user_logged_in() ) {
                wp_die('Unauthorized');
            }

            // ✅ VERY IMPORTANT: stop WP from rendering anything else
            remove_all_actions( 'wp_head' );
            remove_all_actions( 'wp_footer' );

            // Set headers EARLY (now this will work)
            $filename = 'uls-members-' . date('Y-m-d_H-i') . '.csv';

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            // ✅ We need access to your data logic…
            // But we cannot use shortcode variables directly

            // So: store rows temporarily in a transient/session (next step)

        });
