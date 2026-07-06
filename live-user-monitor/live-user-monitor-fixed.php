<?php
/*
Plugin Name: Live User Monitor
Description: Tracks anonymous and logged-in users in real time and displays them via shortcode. Includes cleanup, user details, page name formatting, duplicate prevention, IP display, Last Seen timestamp in local timezone, simplified device info with device type, geolocation, and auto-refresh gauge chart.
Version: 3.0
Author: Jeff Procasky
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class LiveUserMonitorFixed {
    private $db_version = '3.0';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'install']);
        add_action('plugins_loaded', [$this, 'check_db_upgrade']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_update_session', [$this, 'update_session']);
        add_action('wp_ajax_nopriv_update_session', [$this, 'update_session']);
        add_action('wp_ajax_get_sessions', [$this, 'get_sessions']);
        add_action('wp_ajax_nopriv_get_sessions', [$this, 'get_sessions']);
        add_action('wp_ajax_get_active_count', [$this, 'get_active_count']);
        add_action('wp_ajax_nopriv_get_active_count', [$this, 'get_active_count']);
        add_shortcode('live_user_table', [$this, 'render_table']);
        add_shortcode('active_sessions_gauge', [$this, 'render_gauge']);
    }

    public function install() {
        $this->create_table();
        add_option('lum_db_version', $this->db_version);
    }

    public function check_db_upgrade() {
        $installed_version = get_option('lum_db_version');
        if ($installed_version !== $this->db_version) {
            $this->create_table();
            update_option('lum_db_version', $this->db_version);
        }
    }

    public function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'live_sessions';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            session_id varchar(64) NOT NULL,
            user_id bigint(20) NOT NULL,
            page_url varchar(255) NOT NULL,
            ip_address varchar(45) NOT NULL,
            device_info varchar(255) NOT NULL,
            geo_location varchar(255) NOT NULL,
            last_active datetime NOT NULL,
            last_seen datetime NOT NULL,
            is_logged_in tinyint(1) NOT NULL,
            PRIMARY KEY (session_id),
            UNIQUE KEY user_session (user_id, session_id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function enqueue_scripts() {
        // Always enqueue jQuery
        wp_enqueue_script('jquery');

        // Detect shortcodes on singular content
        $needs_ui = false;
        if (!is_admin() && is_singular()) {
            global $post;
            if ($post instanceof WP_Post) {
                $content = $post->post_content ?: '';
                if (has_shortcode($content, 'active_sessions_gauge') || has_shortcode($content, 'live_user_table')) {
                    $needs_ui = true;
                }
            }
        }

        // Enqueue CSS (safe site-wide; tiny file)
        wp_enqueue_style(
            'live-user-monitor-css',
            plugin_dir_url(__FILE__) . 'live-user-monitor.css',
            [],
            '1.0.1'
        );

        if ($needs_ui) {
            // Load Plotly only when needed
            wp_enqueue_script('plotly', 'https://cdn.plot.ly/plotly-latest.min.js', [], null, true);

            // Your full UI script (table + gauge + heartbeat)
            wp_enqueue_script(
                'live-user-monitor-fixed',
                plugin_dir_url(__FILE__) . 'live-user-monitor-fixed.js',
                ['jquery', 'plotly'],
                '2.6',
                true
            );
        } else {
            // If you want a tiny site-wide heartbeat JS instead, you could create a small file
            // that only calls update_session and enqueue it here. If you keep using the same
            // file, it's fine because the new guards prevent UI work on other pages.
            wp_enqueue_script(
                'live-user-monitor-fixed',
                plugin_dir_url(__FILE__) . 'live-user-monitor-fixed.js',
                ['jquery'],
                '2.6',
                true
            );
        }

        wp_localize_script('live-user-monitor-fixed', 'lum_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
        ]);
    }


    private function parse_device_info($user_agent) {
        $browser = 'Unknown Browser';
        $os = 'Unknown OS';
        $device_type = 'Desktop';

        if (strpos($user_agent, 'Edg') !== false) {
            $browser = 'Edge';
        } elseif (strpos($user_agent, 'Chrome') !== false) {
            $browser = 'Chrome';
        } elseif (strpos($user_agent, 'Safari') !== false && strpos($user_agent, 'Chrome') === false) {
            $browser = 'Safari';
        } elseif (strpos($user_agent, 'Firefox') !== false) {
            $browser = 'Firefox';
        }

        if (strpos($user_agent, 'Windows NT 10.0') !== false) {
            $os = 'Windows 10';
        } elseif (strpos($user_agent, 'Windows NT 11.0') !== false) {
            $os = 'Windows 11';
        } elseif (strpos($user_agent, 'Mac OS X') !== false) {
            $os = 'macOS';
        } elseif (strpos($user_agent, 'Linux') !== false) {
            $os = 'Linux';
        } elseif (strpos($user_agent, 'Android') !== false) {
            $os = 'Android';
        } elseif (strpos($user_agent, 'iPhone') !== false || strpos($user_agent, 'iPad') !== false) {
            $os = 'iOS';
        }

if (strpos($user_agent, 'iPad') !== false) {
    $device_type = 'Tablet';
} elseif (strpos($user_agent, 'iPhone') !== false) {
    $device_type = 'Phone';
} elseif (strpos($user_agent, 'Android') !== false) {
    if (strpos($user_agent, 'Mobile') !== false) {
        $device_type = 'Phone';
    } else {
        $device_type = 'Tablet';
    }
        }

        return "$browser | $os | $device_type";
    }

    private function get_geo_location($ip_address) {
        // Safety: normalize IP
        $ip_address = trim($ip_address);

        if (empty($ip_address) || $ip_address === 'unknown') {
            return 'Unknown';
        }

        // Cache key (hashed to be safe)
        $cache_key = 'lum_geo_' . md5($ip_address);

        // 1️⃣ Check cache first
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // 2️⃣ Not cached → call API
        $geo_location = 'Unknown';

        $response = wp_remote_get(
            "http://ip-api.com/json/{$ip_address}",
            [ 'timeout' => 3 ]
        );

        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response));

            if (isset($data->status) && $data->status === 'success') {
                $city       = $data->city ?? '';
                $state      = $data->regionName ?? '';
                $country    = $data->country ?? '';
                $countryCode = $data->countryCode ?? '';

                if ($countryCode === 'US' && $state) {
                    $geo_location = trim("$city, $state, $country", ', ');
                } else {
                    $geo_location = trim("$city, $country", ', ');
                }
            }
        }

        // 3️⃣ Cache result (24 hours)
        set_transient($cache_key, $geo_location, DAY_IN_SECONDS);

        return $geo_location;
    }


    public function update_session() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'live_sessions';

        $session_id = isset($_COOKIE['lum_session']) ? sanitize_text_field($_COOKIE['lum_session']) : wp_generate_uuid4();
        setcookie('lum_session', $session_id, time() + 3600, COOKIEPATH, COOKIE_DOMAIN);

        $user_id = get_current_user_id();
        $full_url = sanitize_text_field($_POST['page_url']);
        $parsed = wp_parse_url($full_url);
        $page_url = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
        $page_url = $page_url ?: 'home';
        $is_logged_in = is_user_logged_in() ? 1 : 0;

        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
        $raw_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : 'unknown';
        $device_info = $this->parse_device_info($raw_agent);
        $geo_location = $this->get_geo_location($ip_address);

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT page_url FROM $table_name WHERE " . ($is_logged_in ? "user_id = %d" : "session_id = %s"),
            $is_logged_in ? $user_id : $session_id
        ));

        $last_seen = current_time('mysql', true);
        if ($existing) {
            $wpdb->update($table_name, [
                'last_active' => $last_seen,
                'last_seen' => $last_seen,
                'page_url' => $page_url,
                'is_logged_in' => $is_logged_in,
                'user_id' => $user_id,
                'device_info' => $device_info,
                'geo_location' => $geo_location
            ], [
                $is_logged_in ? 'user_id' : 'session_id' => $is_logged_in ? $user_id : $session_id
            ]);
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    "
                    INSERT INTO $table_name
                    (session_id, user_id, page_url, ip_address, device_info, geo_location, last_active, last_seen, is_logged_in)
                    VALUES (%s, %d, %s, %s, %s, %s, %s, %s, %d)
                    ON DUPLICATE KEY UPDATE
                        user_id = VALUES(user_id),
                        page_url = VALUES(page_url),
                        ip_address = VALUES(ip_address),
                        device_info = VALUES(device_info),
                        geo_location = VALUES(geo_location),
                        last_active = VALUES(last_active),
                        last_seen = VALUES(last_seen),
                        is_logged_in = VALUES(is_logged_in)
                    ",
                    $session_id,
                    $user_id,
                    $page_url,
                    $ip_address,
                    $device_info,
                    $geo_location,
                    $last_seen,
                    $last_seen,
                    $is_logged_in
                )
            );
        }

        wp_send_json_success(['message' => 'Session updated']);
    }

    public function get_sessions() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'live_sessions';

        $wpdb->query("DELETE FROM $table_name WHERE last_active < (NOW() - INTERVAL 10 MINUTE)");

        $results = $wpdb->get_results("SELECT * FROM $table_name WHERE last_active > (NOW() - INTERVAL 5 MINUTE)");

        foreach ($results as &$row) {
            if ($row->is_logged_in && $row->user_id > 0) {
                $user_info = get_userdata($row->user_id);
                $row->username = $user_info ? $user_info->user_login : 'Unknown';
                $row->email = $user_info ? $user_info->user_email : '';
            } else {
                $row->username = 'Guest';
                $row->email = '';
            }

            $row->last_seen = wp_date('M j, g:i A', strtotime(get_date_from_gmt($row->last_seen)), wp_timezone());
        }

        wp_send_json_success($results);
    }

    public function get_active_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'live_sessions';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE last_active > (NOW() - INTERVAL 5 MINUTE)");
        wp_send_json_success(['count' => intval($count)]);
    }

    public function render_table() {
        return '<div id="live-user-table">Loading active users...</div>';
    }

    public function render_gauge() {
        return '<div id="active-sessions-gauge" style="width:300px;height:225px;"></div>';
    }
}

new LiveUserMonitorFixed();
?>
