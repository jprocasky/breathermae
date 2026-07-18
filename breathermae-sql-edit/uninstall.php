<?php
// Keep history table by default. Uncomment to drop on uninstall.
/*
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
global $wpdb; $table=$wpdb->prefix.'bmse_sql_history';
$wpdb->query("DROP TABLE IF EXISTS {$table}");
*/
