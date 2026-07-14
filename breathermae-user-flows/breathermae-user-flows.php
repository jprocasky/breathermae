<?php
/*
Plugin Name: BreatherMae User Flows
Description: Displays recent user sessions and page flows from the history table with filters and search. Shareable with leadership.
Version: 1.1
Author: Jeff Procasky
Requires Plugins: live-user-monitor
*/

if (!defined('ABSPATH')) exit;

class BreatherMaeUserFlows {

    private $history_table;

    public function __construct() {
        global $wpdb;
        $this->history_table = $wpdb->prefix . 'lum_page_history';

        add_action('plugins_loaded', [$this, 'check_dependency']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_shortcode('breathermae_flow_list', [$this, 'render_flow_list']);
        add_action('wp_ajax_breathermae_get_flow_list', [$this, 'ajax_get_flow_list']);
        add_action('wp_ajax_nopriv_breathermae_get_flow_list', [$this, 'ajax_get_flow_list']);
        add_shortcode('breathermae_flow_viz', [$this, 'render_flow_viz']);
        add_action('wp_ajax_breathermae_get_session_flow', [$this, 'ajax_get_session_flow']);
        add_action('wp_ajax_nopriv_breathermae_get_session_flow', [$this, 'ajax_get_session_flow']); // tighten later with caps
    }

    public function check_dependency() {
        if (!is_plugin_active('live-user-monitor/live-user-monitor-fixed.php')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>BreatherMae User Flows</strong> requires the <strong>Live User Monitor</strong> plugin to be active.</p></div>';
            });
        }
    }

    public function enqueue_scripts() {
        wp_enqueue_script('jquery');

        // Viz assets (pre-load so they are ready when button is clicked)
        wp_enqueue_style(
            'breathermae-flow-viz',
            plugin_dir_url(__FILE__) . 'assets/css/breathermae-flow-viz.css',
            [],
            '1.0'
        );
        wp_enqueue_script(
            'breathermae-flow-viz',
            plugin_dir_url(__FILE__) . 'assets/js/breathermae-flow-viz.js',
            [],
            '1.0',
            true
        );

        // List assets
        wp_enqueue_style(
            'breathermae-user-flows',
            plugin_dir_url(__FILE__) . 'assets/css/breathermae-user-flows.css',
            [],
            '1.1'
        );
        wp_enqueue_script(
            'breathermae-user-flows',
            plugin_dir_url(__FILE__) . 'assets/js/breathermae-user-flows.js',
            ['jquery'],
            '1.1',
            true
        );



        // Correct PHP array syntax for localize_script
        wp_localize_script('breathermae-user-flows', 'breathermaeFlows', [
            'ajaxurl' => admin_url('admin-ajax.php')
        ]);

        wp_localize_script('breathermae-flow-viz', 'breathermaeFlowViz', [
            'ajaxurl' => admin_url('admin-ajax.php')
        ]);
    }

    public function render_flow_viz($atts = []) {
        $atts = shortcode_atts(['session_id' => ''], $atts);

        // Enqueue the dedicated viz assets (only when this shortcode is used)
        wp_enqueue_style(
            'breathermae-flow-viz',
            plugin_dir_url(__FILE__) . 'assets/css/breathermae-flow-viz.css',
            [],
            '1.0'
        );

        wp_enqueue_script(
            'breathermae-flow-viz',
            plugin_dir_url(__FILE__) . 'assets/js/breathermae-flow-viz.js',
            [],
            '1.0',
            true
        );

        wp_localize_script('breathermae-flow-viz', 'breathermaeFlowViz', [
            'ajaxurl' => admin_url('admin-ajax.php')
        ]);

        ob_start(); ?>
        <div class="breathermae-flow-viz">
            <div class="viz-controls">
                <button id="viz-play">▶ Play</button>
                <button id="viz-pause">⏸ Pause</button>
                <label>Speed: <input type="range" id="viz-speed" min="0.25" max="4" step="0.25" value="1"> <span id="speed-val">1x</span></label>
                <button id="viz-reset">Reset</button>
                <button id="viz-step">Step ▶</button>
            </div>

            <div id="viz-flow-container" 
                class="flow-container" 
                data-session-id="<?php echo esc_attr($atts['session_id']); ?>">
                <!-- JS will populate the blocks here -->
                <p class="loading">Select a session or pass session_id...</p>
            </div>

            <div id="viz-info"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_get_session_flow() {
        global $wpdb;
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');

        if (empty($session_id)) {
            wp_send_json_error('No session_id');
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lum_page_history 
            WHERE session_id = %s 
            ORDER BY viewed_at ASC",
            $session_id
        ) );

        // Compute dwell times client-side is fine, but we can pre-calc here too
        wp_send_json_success([
            'rows' => $rows,
            'user_id' => $rows[0]->user_id ?? 0
        ]);
    }

    public function render_flow_list() {
        ob_start();
        ?>
        <div class="breathermae-flow-list">
            <div class="breathermae-flow-filters">
                <div class="filter-group">
                    <label for="flow-filter-type"><strong>Show:</strong></label>
                    <select id="flow-filter-type">
                        <option value="all">All Users</option>
                        <option value="guests">Guests Only</option>
                        <option value="registered">Registered Users</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="flow-search"><strong>Search:</strong></label>
                    <input type="text" id="flow-search" placeholder="Search user, email, IP, or device...">
                </div>
            </div>

            <div id="breathermae-flow-table-container">
                <p class="loading">Loading recent sessions...</p>
            </div>

            <!-- Viz container (hidden until a flow is selected) -->
            <div id="flow-viz-area" style="display: none; margin-top: 30px;">
                <button id="back-to-list" style="margin-bottom: 12px;">← Back to List</button>
                <div id="viz-flow-container" class="flow-container" data-session-id=""></div>
                <div id="viz-info"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_get_flow_list() {
        global $wpdb;
        $table = $this->history_table;
        $users_table = $wpdb->users;

        $filter_type = sanitize_text_field($_POST['filter_type'] ?? 'all');
        $search = sanitize_text_field($_POST['search'] ?? '');

        $sql = "
            SELECT h1.* 
            FROM $table h1
            LEFT JOIN $users_table u ON h1.user_id = u.ID
            INNER JOIN (
                SELECT session_id, MAX(viewed_at) as max_viewed_at
                FROM $table
                GROUP BY session_id
            ) h2 ON h1.session_id = h2.session_id AND h1.viewed_at = h2.max_viewed_at
        ";

        $where = [];
        $params = [];

        if ($filter_type === 'guests') {
            $where[] = "h1.user_id = 0";
        } elseif ($filter_type === 'registered') {
            $where[] = "h1.user_id > 0";
        }

        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $search_conditions = [
                "h1.session_id LIKE %s",
                "h1.ip_address LIKE %s",
                "h1.device_info LIKE %s",
                "h1.geo_location LIKE %s",
                "u.user_login LIKE %s",
                "u.user_email LIKE %s",
                "u.display_name LIKE %s"
            ];
            $params = array_merge($params, array_fill(0, 7, $like));
            $where[] = "(" . implode(' OR ', $search_conditions) . ")";
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY h1.viewed_at DESC LIMIT 300";

        $results = !empty($params) 
            ? $wpdb->get_results($wpdb->prepare($sql, $params)) 
            : $wpdb->get_results($sql);

        ob_start();
        ?>
        <table class="breathermae-flow-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Last Page</th>
                    <th>IP</th>
                    <th>Device</th>
                    <th>Location</th>
                    <th>Last Visit</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($results)) : ?>
                <?php foreach ($results as $row) : ?>
                    <?php
                    $is_guest = $row->user_id == 0;
                    $user_data = $is_guest ? null : get_userdata($row->user_id);
                    $user_label = $is_guest ? 'Guest' : ($user_data->display_name ?: $user_data->user_login);
                    $email = $is_guest ? '' : ($user_data->user_email ?? '');
                    $last_seen = wp_date('M j, Y g:i A', strtotime($row->viewed_at));
                    ?>
                    <tr>
                        <td><?php echo esc_html($user_label); ?></td>
                        <td><?php echo esc_html($email); ?></td>
                        <td><code><?php echo esc_html($row->page_url); ?></code></td>
                        <td><?php echo esc_html($row->ip_address); ?></td>
                        <td><?php echo esc_html($row->device_info); ?></td>
                        <td><?php echo esc_html($row->geo_location); ?></td>
                        <td><?php echo esc_html($last_seen); ?></td>
                        <td>
                            <button class="view-flow-btn" data-session="<?php echo esc_attr($row->session_id); ?>">
                                View Flow
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr><td colspan="8">No sessions found matching your criteria.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }
}

new BreatherMaeUserFlows();