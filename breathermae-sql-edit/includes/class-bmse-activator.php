<?php
if (!defined('ABSPATH')) { exit; }
class BMSE_Activator {
    public static function activate(){
        global $wpdb; $charset = $wpdb->get_charset_collate();
        $table = $wpdb->prefix.'bmse_sql_history';
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
".
               " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
".
               " user_id BIGINT UNSIGNED NULL,
".
               " query_text LONGTEXT NOT NULL,
".
               " is_select TINYINT(1) NOT NULL DEFAULT 0,
".
               " affected_rows INT NULL,
".
               " runtime_ms INT NULL,
".
               " error_message TEXT NULL,
".
               " tables_json TEXT NULL,
".
               " created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
".
               " PRIMARY KEY (id),
".
               " KEY created_at (created_at)
".
               ") {$charset};";
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
