<?php
if (!defined('ABSPATH')) { exit; }

class BMSE_Admin {
    private $nonce_action = 'bmse_nonce';

    public function __construct(){
        add_action('admin_menu',            [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);

        add_action('wp_ajax_bmse_sql_run',     [$this, 'ajax_run']);
        add_action('wp_ajax_bmse_tables',      [$this, 'ajax_tables']);
        add_action('wp_ajax_bmse_history',     [$this, 'ajax_history']);
        add_action('wp_ajax_bmse_update_cell', [$this, 'ajax_update_cell']);
        add_action('wp_ajax_bmse_pk_info',     [$this, 'ajax_pk_info']);
    }

    public function menu(){
        add_management_page(
            __('Breathermae SQL Edit','bmse'),
            __('Breathermae SQL Edit','bmse'),
            'manage_options',
            'bmse-sql',
            [$this,'render']
        );
    }

    public function enqueue($hook){
        if ($hook !== 'tools_page_bmse-sql') return;

        wp_enqueue_style('bmse-css', BMSE_URL.'assets/css/bmse.css', [], BMSE_VERSION);
        wp_enqueue_script('bmse-js',  BMSE_URL.'assets/js/bmse.js',  ['jquery'], BMSE_VERSION, true);

        // Pull saved defaults (or builtin defaults) from settings
        $defaults = get_option(BMSE_Settings::OPTION, BMSE_Settings::defaults());

        wp_localize_script('bmse-js', 'BMSE', [
            'ajax'    => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce($this->nonce_action),
            'enabled' => (bool) BMSE_ENABLED,
            'defaults'=> [
                'allow_write'      => !empty($defaults['allow_write']),
                'append_limit'     => !empty($defaults['append_limit']),
                'edit_mode'        => !empty($defaults['edit_mode']),
                'auto_run'         => !empty($defaults['auto_run']),
                'auto_add_pk'      => !empty($defaults['auto_add_pk']),
                'auto_run_history' => !empty($defaults['auto_run_history']),
            ],
            'strings' => [
                'disabled'     => __('The SQL Edit tool is disabled. Define BMSE_ENABLED=true in wp-config.php to enable.', 'bmse'),
                'confirmWrite' => __('This will run a write query. Proceed?', 'bmse'),
                'editHint'     => __('Double-click to edit', 'bmse'),
            ],
        ]);
    }

    public function render(){
        if (!current_user_can('manage_options')) { wp_die(__('Insufficient permissions.','bmse')); }

        echo '<div class="wrap bmse-wrap">';
        echo '<h1>'.esc_html__('Breathermae SQL Edit','bmse').'</h1>';
        if (!BMSE_ENABLED) {
            echo '<div class="notice notice-error"><p>'.
                 esc_html__('The tool is disabled. Define BMSE_ENABLED as true in wp-config.php to enable.','bmse').
                 '</p></div>';
        }
        ?>
        <div class="bmse-grid">
            <div class="bmse-main">
                <div class="bmse-editor">
                    <textarea id="bmse-sql" placeholder="-- Type SQL here. Ctrl/Cmd+Enter to run." spellcheck="false"></textarea>
                </div>
                <div class="bmse-controls">
                    <button id="bmse-run" class="button button-primary" <?php disabled(!BMSE_ENABLED); ?>>
                        <?php _e('Run (Ctrl+Enter)','bmse'); ?>
                    </button>
                    <label><input type="checkbox" id="bmse-allow-write"> <?php _e('Allow write queries','bmse'); ?></label>
                    <label><?php _e('Row limit','bmse'); ?> <input type="number" id="bmse-row-limit" min="1" max="5000" value="200"></label>
                    <label><input type="checkbox" id="bmse-append-limit" checked> <?php _e('Append LIMIT when missing','bmse'); ?></label>
                    <span class="bmse-divider"></span>
                    <label><input type="checkbox" id="bmse-edit-mode"> <?php _e('Edit mode (beta)','bmse'); ?></label>
                    <label><input type="checkbox" id="bmse-auto-run"> <?php _e('Auto-run updates','bmse'); ?></label>
                    <label><input type="checkbox" id="bmse-auto-add-pk"> <?php _e('Auto-add PK for edits','bmse'); ?></label>
                </div>
                <div class="bmse-results" id="bmse-results"></div>
            </div>

            <div class="bmse-side">
                <div class="bmse-panel">
                    <div class="bmse-panel-title"><?php _e('Tables Used (recent)','bmse'); ?></div>
                    <div class="bmse-panel-body" id="bmse-recent"></div>
                </div>
                <div class="bmse-panel">
                    <div class="bmse-panel-title"><?php _e('All WP Tables','bmse'); ?></div>
                    <div class="bmse-panel-body" id="bmse-tables"></div>
                </div>
            </div>
        </div>

        <div class="bmse-history">
            <div class="bmse-panel-title"><?php _e('History','bmse'); ?></div>
            <div class="bmse-panel-body" id="bmse-history-list"></div>
        </div>
        <?php
        echo '</div>';
    }

    public function ajax_tables(){
        if (!current_user_can('manage_options')) { wp_send_json_error('forbidden',403); }
        check_ajax_referer($this->nonce_action,'nonce');

        global $wpdb;
        $like   = $wpdb->esc_like($wpdb->prefix) . '%';
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));

        $hist   = $wpdb->prefix.'bmse_sql_history';
        $recent = []; $seen = [];

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($hist)))){
            $rows = $wpdb->get_results("SELECT tables_json FROM {$hist} ORDER BY created_at DESC, id DESC LIMIT 500");
            foreach ((array)$rows as $r){
                $arr = $r->tables_json ? json_decode($r->tables_json,true) : [];
                if (is_array($arr)){
                    foreach ($arr as $t){
                        $t = trim($t);
                        if ($t !== '' && empty($seen[$t])) {
                            $recent[] = $t;
                            $seen[$t]  = 1;
                        }
                    }
                }
            }
        }

        wp_send_json_success(['tables'=>$tables,'recent'=>$recent]);
    }

    public function ajax_history(){
        if (!current_user_can('manage_options')) { wp_send_json_error('forbidden',403); }
        check_ajax_referer($this->nonce_action,'nonce');

        global $wpdb;
        $hist = $wpdb->prefix.'bmse_sql_history';

        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($hist)))){
            wp_send_json_success(['items'=>[]]);
        }

        $items = $wpdb->get_results(
            "SELECT id, query_text, is_select, affected_rows, runtime_ms, error_message, created_at
             FROM {$hist}
             ORDER BY created_at DESC, id DESC
             LIMIT 200",
            ARRAY_A
        );

        wp_send_json_success(['items'=>$items]);
    }

    public function ajax_run(){
        if (!current_user_can('manage_options')) { wp_send_json_error('forbidden',403); }
        check_ajax_referer($this->nonce_action,'nonce');
        if (!BMSE_ENABLED) { wp_send_json_error(['message'=>'disabled'],400); }

        global $wpdb;

        $sql          = isset($_POST['sql']) ? trim(wp_unslash($_POST['sql'])) : '';
        $allow_write  = !empty($_POST['allow_write']);
        $row_limit    = max(1, min(5000, intval($_POST['row_limit'] ?? 200)));
        $append_limit = !empty($_POST['append_limit']);
        $edit_mode    = !empty($_POST['edit_mode']);
        $auto_add_pk  = !empty($_POST['auto_add_pk']);

        if ($sql === '') { wp_send_json_error(['message'=>__('Empty SQL.','bmse')]); }

        // single-statement guard (allow one trailing ;)
        $stmt = rtrim($sql);
        if (substr($stmt, -1) === ';') { $stmt = rtrim(substr($stmt, 0, -1)); }
        if (strpos($stmt, ';') !== false) {
            wp_send_json_error(['message'=>__('Multiple statements detected.','bmse')]);
        }

        $is_select    = (bool) preg_match('/^\s*SELECT\b/i', $stmt);
        $is_resultset = $is_select || (bool) preg_match('/^\s*(DESCRIBE|DESC|SHOW|EXPLAIN)\b/i', $stmt);
        $write_pat    = '/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|RENAME|GRANT|REVOKE|LOCK|UNLOCK|SET|CALL)\b/i';

        if (!$allow_write && preg_match($write_pat, $stmt)){
            wp_send_json_error(['message'=>__('Write/DDL blocked.','bmse')]);
        }

        // Only auto-append LIMIT on pure SELECTs
        if ($is_select && $append_limit && stripos($stmt, 'limit') === false) {
            $stmt .= ' LIMIT '.intval($row_limit);
        }

        // Extract tables
        $tables = $this->extract_tables($stmt);

        // Edit meta: always compute base table + PK for single-table SELECTs
        $base_table = null;
        $pk_col     = null;
        $hidden_pk  = null;

        if ($is_select && count($tables) === 1) {
            $base_table = $tables[0];
            $pk_col     = $this->get_pk_column($base_table); // always detect

            // Inject hidden PK alias if requested and PK exists
            if ($edit_mode && $auto_add_pk && $pk_col) {
                if (preg_match('/\bFROM\b/i', $stmt, $m, PREG_OFFSET_CAPTURE)) {
                    $pos  = $m[0][1];
                    $stmt = substr($stmt, 0, $pos)
                          . ', `'.$base_table.'`.`'.$pk_col.'` AS __bmse_pk__ '
                          . substr($stmt, $pos);
                    $hidden_pk = '__bmse_pk__';
                }
            }
        }

        // Execute
        $start    = microtime(true);
        $error    = null;
        $results  = null;
        $columns  = [];
        $affected = null;

        if ($is_resultset){
            $results = $wpdb->get_results($stmt, ARRAY_A);
            if ($wpdb->last_error) { $error = $wpdb->last_error; }
            $affected = is_array($results) ? count($results) : 0;
            if (is_array($results) && !empty($results)) { $columns = array_keys($results[0]); }
        } else {
            $affected = $wpdb->query($stmt);
            if ($wpdb->last_error) { $error = $wpdb->last_error; }
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        // Log
        $hist = $wpdb->prefix.'bmse_sql_history';
        $wpdb->insert($hist, [
            'user_id'       => get_current_user_id(),
            'query_text'    => $stmt,
            'is_select'     => $is_resultset ? 1 : 0,
            'affected_rows' => is_numeric($affected) ? intval($affected) : null,
            'runtime_ms'    => $ms,
            'error_message' => $error,
            'tables_json'   => wp_json_encode(array_values(array_unique($tables))),
            'created_at'    => current_time('mysql'),
        ]);

        if ($error) {
            wp_send_json_error(['message'=>$error, 'runtime_ms'=>$ms]);
        }

        if ($is_resultset){
            wp_send_json_success([
                'type'        => 'select',
                'columns'     => $columns,
                'rows'        => $results,
                'runtime_ms'  => $ms,
                'edit_meta'   => [
                    'eligible_single_table' => ($is_select && count($tables) === 1),
                    'base_table'            => $base_table,
                    'pk_column'             => $pk_col,       // always set for single-table SELECT
                    'hidden_pk_alias'       => $hidden_pk,    // only when injected
                ],
            ]);
        } else {
            wp_send_json_success([
                'type'          => 'write',
                'affected_rows' => intval($affected),
                'runtime_ms'    => $ms,
            ]);
        }
    }

    public function ajax_pk_info(){
        if (!current_user_can('manage_options')) { wp_send_json_error('forbidden',403); }
        check_ajax_referer($this->nonce_action,'nonce');

        $table = sanitize_text_field($_POST['table'] ?? '');
        if (!$table) { wp_send_json_error(['message'=>'missing table']); }

        $pk = $this->get_pk_column($table);
        wp_send_json_success(['pk'=>$pk]);
    }

    public function ajax_update_cell(){
        if (!current_user_can('manage_options')) { wp_send_json_error('forbidden',403); }
        check_ajax_referer($this->nonce_action,'nonce');
        if (!BMSE_ENABLED) { wp_send_json_error(['message'=>'disabled'],400); }

        global $wpdb;

        $table       = sanitize_text_field($_POST['table']  ?? '');
        $column      = sanitize_text_field($_POST['column'] ?? '');
        $pk_col      = sanitize_text_field($_POST['pk_col'] ?? '');
        $pk_val      = isset($_POST['pk_val'])  ? wp_unslash($_POST['pk_val'])  : null;
        $new_val     = isset($_POST['new_val']) ? wp_unslash($_POST['new_val']) : null;
        $allow_write = !empty($_POST['allow_write']);

        if (!$allow_write) { wp_send_json_error(['message'=>__('Write query blocked. Check "Allow write queries".','bmse')]); }
        if (!$table || !$column || !$pk_col) { wp_send_json_error(['message'=>'Missing parameters']); }

        $data   = [ $column => $new_val ];
        $where  = [ $pk_col => $pk_val ];
        $result = $wpdb->update($table, $data, $where);
        $error  = $wpdb->last_error ?: null;

        // Build preview for history (informational)
        $val_sql = is_numeric($new_val) ? $new_val : "'" . esc_sql($new_val) . "'";
        $pk_sql  = is_numeric($pk_val)  ? $pk_val  : "'" . esc_sql($pk_val)  . "'";
        $preview = "UPDATE `{$table}` SET `{$column}` = {$val_sql} WHERE `{$pk_col}` = {$pk_sql}";

        $this->log_history_write($preview, $table, $error, $result);

        if ($error) { wp_send_json_error(['message'=>$error]); }

        wp_send_json_success(['affected_rows'=> intval($result)]);
    }

    private function log_history_write($sql_preview, $table, $error, $affected){
        global $wpdb;
        $hist = $wpdb->prefix.'bmse_sql_history';
        $wpdb->insert($hist, [
            'user_id'       => get_current_user_id(),
            'query_text'    => $sql_preview,
            'is_select'     => 0,
            'affected_rows' => is_numeric($affected) ? intval($affected) : null,
            'runtime_ms'    => null,
            'error_message' => $error,
            'tables_json'   => wp_json_encode([$table]),
            'created_at'    => current_time('mysql'),
        ]);
    }

    private function extract_tables($sql){
        $tables  = [];
        $pattern = '/\b(?:FROM|JOIN|UPDATE|INTO|DELETE\s+FROM|TABLE)\s+[`"]?([A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)?)\b/iu';
        if (preg_match_all($pattern, $sql, $m)){
            foreach ($m[1] as $t){
                $t = preg_replace('/^[^\.]+\./','', $t); // strip db qualifier
                $tables[] = $t;
            }
        }
        // DESCRIBE / DESC table_name
        if (preg_match('/^\s*(?:DESCRIBE|DESC)\s+[`"]?([A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)?)/i', $sql, $m)) {
            $t = preg_replace('/^[^\.]+\./','', $m[1]);
            $tables[] = $t;
        }
        return array_values(array_unique($tables));
    }

    private function get_pk_column($table){
        // keep identifiers sane
        $safe = preg_replace('/[^A-Za-z0-9_\-\.]/','', $table);
        if ($safe === '') { return null; }

        global $wpdb;
        $row = $wpdb->get_row("SHOW KEYS FROM `{$safe}` WHERE Key_name='PRIMARY'");
        if ($wpdb->last_error) { return null; }

        return ($row && isset($row->Column_name)) ? $row->Column_name : null;
    }
}
