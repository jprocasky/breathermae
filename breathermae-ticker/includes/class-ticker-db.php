<?php
/**
 * Database handling for BreatherMae Ticker
 */

if (!defined('ABSPATH')) {
    exit;
}

class BM_Ticker_DB {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'breathermae_ticker_items';
    }

    /**
     * Create the custom table on plugin activation
     */
    public static function create_table() {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            message text NOT NULL,
            type varchar(50) DEFAULT 'general',
            priority tinyint(2) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            start_date datetime DEFAULT NULL,
            end_date datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY is_active_type (is_active, type),
            KEY priority (priority)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Get a single tip based on rotation mode, type filter, etc.
     *
     * @param array $args
     * @return array|null ['id' => int, 'message' => string, 'type' => string]
     */
    public static function get_single_tip($args = []) {
        global $wpdb;

        $defaults = [
            'mode'        => 'daily',      // daily | visit | random
            'types'       => ['general'],  // array of types to include
            'only_active' => true,
        ];
        $args = wp_parse_args($args, $defaults);

        $table = self::get_table_name();

        // Build WHERE clause
        $where = [];
        $prepare_args = [];

        if ($args['only_active']) {
            $where[] = 'is_active = 1';
        }

        if (!empty($args['types'])) {
            $placeholders = implode(',', array_fill(0, count($args['types']), '%s'));
            $where[] = "type IN ($placeholders)";
            $prepare_args = array_merge($prepare_args, $args['types']);
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get total count first (for deterministic daily pick)
        $count_sql = "SELECT COUNT(*) FROM $table $where_sql";
        if (!empty($prepare_args)) {
            $count_sql = $wpdb->prepare($count_sql, $prepare_args);
        }
        $total = (int) $wpdb->get_var($count_sql);

        if ($total === 0) {
            return null;
        }

        $order_by = 'priority DESC, id ASC';
        $limit = 1;
        $offset = 0;

        // === Rotation Logic ===
        if ($args['mode'] === 'daily') {
            // Deterministic pick based on current date
            $day_seed = crc32(date('Y-m-d'));
            $offset = $day_seed % $total;

        } elseif ($args['mode'] === 'visit') {
            // Use cookie to remember last shown ID and advance
            $last_id = isset($_COOKIE['bm_ticker_last_id']) ? intval($_COOKIE['bm_ticker_last_id']) : 0;

            // Try to get the next one after last_id
            $next_sql = $wpdb->prepare(
                "SELECT id FROM $table $where_sql AND id > %d ORDER BY id ASC LIMIT 1",
                array_merge($prepare_args, [$last_id])
            );
            $next_id = $wpdb->get_var($next_sql);

            if ($next_id) {
                // Found next one
                $where[] = $wpdb->prepare('id = %d', $next_id);
                $where_sql = 'WHERE ' . implode(' AND ', $where);
            } else {
                // Wrap around to first
                $offset = 0;
            }

            // We will set the cookie after we know which ID we picked

        } elseif ($args['mode'] === 'random') {
            $order_by = 'RAND()';
        }

        // Final query
        $sql = "SELECT id, message, type FROM $table $where_sql ORDER BY $order_by LIMIT %d OFFSET %d";
        $prepare_args_final = array_merge($prepare_args, [$limit, $offset]);

        $sql = $wpdb->prepare($sql, $prepare_args_final);
        $row = $wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            return null;
        }

        // Set cookie for visit mode
        if ($args['mode'] === 'visit' && !empty($row['id'])) {
            $cookie_value = intval($row['id']);
            setcookie('bm_ticker_last_id', $cookie_value, time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
            // Also make it available immediately for this request
            $_COOKIE['bm_ticker_last_id'] = $cookie_value;
        }

        return $row;
    }
}
