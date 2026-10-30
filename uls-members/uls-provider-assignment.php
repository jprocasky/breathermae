<?php
/**
 * ULS Member Provider Assignment
 *
 * Persistent assignment of a specific provider user to a member for a given context
 * (e.g. "doctor", future "coach"). Used by files/notes private access and the
 * members table.
 *
 * Table: uls_member_provider_assignments (no WP prefix)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'ULS_Member_Provider_Assignment' ) ) :

class ULS_Member_Provider_Assignment {

    const DB_VERSION = '1.0.0';
    private static $instance = null;
    private $table = 'uls_member_provider_assignments';

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'maybe_upgrade_schema' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_inline_editor' ] );
        add_shortcode( 'uls_assign_provider', [ $this, 'shortcode_assign_provider' ] );

        add_action( 'wp_ajax_uls_get_provider_assignment',   [ $this, 'ajax_get_assignment' ] );
        add_action( 'wp_ajax_uls_list_eligible_providers',   [ $this, 'ajax_list_eligible' ] );
        add_action( 'wp_ajax_uls_save_provider_assignment',  [ $this, 'ajax_save_assignment' ] );
    }

    public function maybe_upgrade_schema() {
        $opt = 'uls_member_provider_assignment_db_version';
        if ( get_option( $opt ) === self::DB_VERSION ) return;

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $this->table;

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `member_email`     VARCHAR(191) NOT NULL,
            `member_user_id`   BIGINT UNSIGNED NULL,
            `context`          VARCHAR(50) NOT NULL,
            `provider_user_id` BIGINT UNSIGNED NOT NULL,
            `assigned_by`      BIGINT UNSIGNED NOT NULL,
            `assigned_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_by`       BIGINT UNSIGNED NULL,
            `updated_at`       DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_member_context` (`member_email`, `context`),
            KEY `idx_member_user_id` (`member_user_id`),
            KEY `idx_provider_user_id` (`provider_user_id`),
            KEY `idx_context` (`context`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( $opt, self::DB_VERSION );
    }

    /* ------------------------------------------------------------------
     * Public helpers (used by files / notes / members table)
     * ----------------------------------------------------------------*/

    /**
     * Return the assigned provider user ID for a member + context, or 0.
     */
    public function get_assigned_provider_id( $member_email, $context ) {
        $context = sanitize_key( (string) $context );
        if ( $context === '' || ! is_email( $member_email ) ) return 0;

        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT provider_user_id FROM `{$this->table}`
             WHERE member_email = %s AND context = %s LIMIT 1",
            $member_email,
            $context
        ) );
        return $id ? (int) $id : 0;
    }

    /**
     * True if the current user is the assigned provider for this member + context.
     */
    public function is_assigned_provider( $current_user_id, $member_email, $context ) {
        $pid = $this->get_assigned_provider_id( $member_email, $context );
        return $pid > 0 && (int) $current_user_id === $pid;
    }

    /**
     * True if the user has the given WPF tag (provider admin check).
     */
    public function user_has_wpf_tag( $user_id, $tag ) {
        $tag = trim( (string) $tag );
        if ( $tag === '' ) return false;

        // Prefer WP Fusion has-tag helper when available
        if ( function_exists( 'wpf_has_tag' ) ) {
            return (bool) wpf_has_tag( $tag, (int) $user_id );
        }

        // Fall back to labels (wpf_get_tags returns IDs; labels come from wpf_get_tag_label)
        if ( ! function_exists( 'wpf_get_tags' ) ) return false;
        $ids = wpf_get_tags( (int) $user_id );
        if ( ! is_array( $ids ) ) return false;
        foreach ( $ids as $id ) {
            $label = function_exists( 'wpf_get_tag_label' ) ? wpf_get_tag_label( $id ) : (string) $id;
            if ( is_string( $label ) && strcasecmp( $label, $tag ) === 0 ) return true;
            if ( is_string( $id ) && strcasecmp( $id, $tag ) === 0 ) return true;
        }
        return false;
    }

    /**
     * Can the current user change the assignment?
     * - Currently assigned provider, or
     * - Has the provider_admin tag.
     * If no assignment exists yet, anyone with page access (caller decides) may set it;
     * the shortcode gates that separately.
     */
    public function can_change_assignment( $current_user_id, $member_email, $context, $provider_admin_tag ) {
        if ( $this->is_assigned_provider( $current_user_id, $member_email, $context ) ) return true;
        if ( $provider_admin_tag !== '' && $this->user_has_wpf_tag( $current_user_id, $provider_admin_tag ) ) return true;
        // No assignment yet → allow if they have the admin tag, or if caller treats empty as open
        $existing = $this->get_assigned_provider_id( $member_email, $context );
        if ( $existing === 0 && $provider_admin_tag !== '' && $this->user_has_wpf_tag( $current_user_id, $provider_admin_tag ) ) {
            return true;
        }
        return false;
    }

    /* ------------------------------------------------------------------
     * Shortcode
     * ----------------------------------------------------------------*/

    /**
     * [uls_assign_provider context="doctor" patterns="DOCTOR" provider_admin="P_ADMIN"]
     *
     * Uses the currently selected member (uls:selected-member / session) when no email attr is given.
     */

    public function enqueue_inline_editor() {
        wp_register_script( 'uls-provider-inline', false, [ 'jquery' ], self::DB_VERSION, true );
        wp_enqueue_script( 'uls-provider-inline' );
        $js = <<<'JS'
(function($){
  function openEditor($td) {
    if ($td.data('editing')) return;
    var assigned = String($td.data('assigned') || '0') === '1';
    var isAdmin = !!(window.ULS_PA && window.ULS_PA.isAdmin);
    if (assigned && !isAdmin) {
      return;
    }
    var $table = $td.closest('table');
    var email = String($td.data('email') || $td.closest('tr').data('email') || '');
    var context = String($table.data('provider-context') || '');
    var patterns = String($table.data('provider-patterns') || 'DOCTOR');
    var exclude = String($table.data('provider-exclude') || '');
    var admin = String($table.data('provider-admin') || 'P_ADMIN');
    if (!email || !context) return;
    var original = $td.text();
    $td.data('editing', 1);
    var $sel = $('<select class="uls-pa-inline" style="font-size:12px;max-width:100%;"></select>');
    $sel.append($('<option>', {value:'', text:'Loading…'}));
    $td.empty().append($sel);
    $.post(ULS_MEMBERS.ajaxurl, {
      action: 'uls_list_eligible_providers',
      nonce: (window.ULS_PA_NONCE || ''),
      patterns: patterns,
      exclude_patterns: exclude,
      email: email,
      context: context
    }).done(function(r){
      $sel.empty().append($('<option>', {value:'', text:'— Select —'}));
      if (r && r.success) {
        (r.data.providers || []).forEach(function(p){
          $sel.append($('<option>', {value:p.id, text:p.name}));
        });
        if (r.data.assigned_id) $sel.val(String(r.data.assigned_id));
      }
    });
    $sel.on('change', function(){
      var pid = $sel.val();
      if (!pid) return;
      $.post(ULS_MEMBERS.ajaxurl, {
        action: 'uls_save_provider_assignment',
        nonce: (window.ULS_PA_NONCE || ''),
        email: email,
        context: context,
        provider_user_id: pid,
        provider_admin: admin
      }).done(function(r){
        if (r && r.success) {
          $td.text(r.data.name || '—').data('editing', 0);
        } else {
          alert((r && r.data && r.data.message) || 'Could not save');
          $td.text(original).data('editing', 0);
        }
      }).fail(function(){
        alert('Save failed');
        $td.text(original).data('editing', 0);
      });
    });
    $sel.on('blur', function(){
      setTimeout(function(){
        if ($td.data('editing')) $td.text(original).data('editing', 0);
      }, 200);
    });
  }
  $(document).on('dblclick', 'td[data-col="assigned_provider"]', function(e){
    e.stopPropagation();
    openEditor($(this));
  });
})(jQuery);
JS;
        wp_add_inline_script( 'uls-provider-inline', $js );
        // Nonce for the assignment endpoints (members nonce is a different action)
        $is_admin = false;
        if ( is_user_logged_in() ) {
            $admin_tag = apply_filters( 'uls_provider_admin_tag', 'P_ADMIN' );
            $is_admin = $this->user_has_wpf_tag( get_current_user_id(), $admin_tag );
        }
        wp_add_inline_script(
            'uls-provider-inline',
            'window.ULS_PA_NONCE = ' . wp_json_encode( wp_create_nonce( 'uls_provider_assignment' ) ) . ';'
            . 'window.ULS_PA = ' . wp_json_encode( [ 'isAdmin' => (bool) $is_admin ] ) . ';',
            'before'
        );
    }

    public function shortcode_assign_provider( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p>Please log in.</p>';
        }

        $atts = shortcode_atts( [
            'context'          => 'doctor',
            'patterns'         => 'DOCTOR',
            'exclude_patterns' => '',
            'provider_admin'   => 'P_ADMIN',
            'email'            => '',   // optional override; otherwise selected member
            'label'            => 'Assigned Provider',
        ], $atts, 'uls_assign_provider' );

        $context = sanitize_key( $atts['context'] );
        if ( $context === '' ) return '<p>Missing context.</p>';

        $email = sanitize_email( $atts['email'] );
        if ( $email === '' ) {
            // Selected member is stored in user meta by the members table
            $email = sanitize_email( (string) get_user_meta( get_current_user_id(), 'uls_selected_email', true ) );
        }
        if ( ! is_email( $email ) ) {
            return '<p>Select a member first.</p>';
        }

        $current_id = get_current_user_id();
        $assigned_id = $this->get_assigned_provider_id( $email, $context );
        // No assignment yet → anyone who can see this shortcode (page is WPF-gated) may set it.
        // After that, only the assigned provider or a provider admin can change it.
        if ( $assigned_id === 0 ) {
            $can_change = true;
        } else {
            $can_change = $this->can_change_assignment( $current_id, $email, $context, $atts['provider_admin'] );
        }

        $assigned_id   = $this->get_assigned_provider_id( $email, $context );
        $assigned_name = $assigned_id ? $this->user_display( $assigned_id ) : '— Unassigned —';

        $nonce = wp_create_nonce( 'uls_provider_assignment' );
        $uid   = 'uls-pa-' . wp_rand( 1000, 9999 );

        ob_start();
        ?>
        <div class="uls-assign-provider" id="<?php echo esc_attr( $uid ); ?>"
             data-email="<?php echo esc_attr( $email ); ?>"
             data-context="<?php echo esc_attr( $context ); ?>"
             data-patterns="<?php echo esc_attr( $atts['patterns'] ); ?>"
             data-exclude="<?php echo esc_attr( $atts['exclude_patterns'] ); ?>"
             data-admin="<?php echo esc_attr( $atts['provider_admin'] ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             style="font-size:13px;line-height:1.35;color:#222;">
            <label style="font-weight:600;font-size:13px;display:block;margin-bottom:4px;"><?php echo esc_html( $atts['label'] ); ?></label>
            <div class="uls-pa-current" style="margin-bottom:6px;font-size:13px;">
                Current: <strong class="uls-pa-name"><?php echo esc_html( $assigned_name ); ?></strong>
            </div>
            <?php if ( $can_change ) : ?>
                <select class="uls-pa-select" style="min-width:220px;font-size:13px;padding:4px 6px;">
                    <option value="">— Select provider —</option>
                </select>
                <button type="button" class="uls-pa-save" style="margin-left:6px;background:#6EC1E4;color:#fff;border:1px solid #6EC1E4;padding:5px 12px;font-size:13px;border-radius:4px;cursor:pointer;">Save</button>
                <span class="uls-pa-status" style="margin-left:8px;font-size:12px;"></span>
            <?php else : ?>
                <p style="font-size:12px;color:#666;margin:4px 0 0;">Only the assigned provider or a provider admin can change this.</p>
            <?php endif; ?>
        </div>
        <script>
        (function($){
            var $root = $('#<?php echo esc_js( $uid ); ?>');
            if (!$root.length) return;
            var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce   = $root.data('nonce');

            function load() {
                $.post(ajaxurl, {
                    action: 'uls_list_eligible_providers',
                    nonce: nonce,
                    patterns: $root.data('patterns'),
                    exclude_patterns: $root.data('exclude') || '',
                    email: $root.data('email'),
                    context: $root.data('context')
                }).done(function(r){
                    if (!r || !r.success) return;
                    var $sel = $root.find('.uls-pa-select');
                    $sel.find('option:not(:first)').remove();
                    (r.data.providers || []).forEach(function(p){
                        $sel.append($('<option>', {value: p.id, text: p.name}));
                    });
                    if (r.data.assigned_id) $sel.val(String(r.data.assigned_id));
                });
            }
            load();

            $root.on('click', '.uls-pa-save', function(){
                var $st = $root.find('.uls-pa-status');
                $st.text('Saving…');
                $.post(ajaxurl, {
                    action: 'uls_save_provider_assignment',
                    nonce: nonce,
                    email: $root.data('email'),
                    context: $root.data('context'),
                    provider_user_id: $root.find('.uls-pa-select').val(),
                    provider_admin: $root.data('admin')
                }).done(function(r){
                    if (r && r.success) {
                        $root.find('.uls-pa-name').text(r.data.name || '—');
                        $st.text('Saved').css('color','green');
                    } else {
                        $st.text((r && r.data && r.data.message) || 'Error').css('color','red');
                    }
                }).fail(function(){ $st.text('Request failed').css('color','red'); });
            });
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ----------------------------------------------------------------*/

    public function ajax_get_assignment() {
        check_ajax_referer( 'uls_provider_assignment', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );

        $email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $context = sanitize_key( $_POST['context'] ?? '' );
        if ( ! is_email( $email ) || $context === '' ) wp_send_json_error( [ 'message' => 'Bad request' ], 400 );

        $pid = $this->get_assigned_provider_id( $email, $context );
        wp_send_json_success( [
            'provider_user_id' => $pid,
            'name'             => $pid ? $this->user_display( $pid ) : '',
        ] );
    }

    public function ajax_list_eligible() {
        check_ajax_referer( 'uls_provider_assignment', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );

        $patterns = sanitize_text_field( wp_unslash( $_POST['patterns'] ?? '' ) );
        $exclude  = sanitize_text_field( wp_unslash( $_POST['exclude_patterns'] ?? '' ) );
        $email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $context  = sanitize_key( $_POST['context'] ?? '' );

        $providers = $this->find_users_by_tag_patterns( $patterns, $exclude );
        $assigned  = $this->get_assigned_provider_id( $email, $context );

        wp_send_json_success( [
            'providers'   => $providers,
            'assigned_id' => $assigned,
        ] );
    }

    public function ajax_save_assignment() {
        check_ajax_referer( 'uls_provider_assignment', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );

        $email     = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $context   = sanitize_key( $_POST['context'] ?? '' );
        $provider  = (int) ( $_POST['provider_user_id'] ?? 0 );
        $admin_tag = sanitize_text_field( wp_unslash( $_POST['provider_admin'] ?? '' ) );

        if ( ! is_email( $email ) || $context === '' || $provider <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid parameters' ], 400 );
        }

        $current = get_current_user_id();
        $existing_pid = $this->get_assigned_provider_id( $email, $context );
        if ( $existing_pid !== 0 && ! $this->can_change_assignment( $current, $email, $context, $admin_tag ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        global $wpdb;
        $member_uid = 0;
        $u = get_user_by( 'email', $email );
        if ( $u ) $member_uid = (int) $u->ID;

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$this->table}` WHERE member_email = %s AND context = %s",
            $email, $context
        ) );

        if ( $existing ) {
            $ok = $wpdb->update(
                $this->table,
                [
                    'provider_user_id' => $provider,
                    'updated_by'       => $current,
                    'updated_at'       => current_time( 'mysql' ),
                ],
                [ 'id' => (int) $existing ],
                [ '%d', '%d', '%s' ],
                [ '%d' ]
            );
        } else {
            $ok = $wpdb->insert(
                $this->table,
                [
                    'member_email'     => $email,
                    'member_user_id'   => $member_uid ?: null,
                    'context'          => $context,
                    'provider_user_id' => $provider,
                    'assigned_by'      => $current,
                    'assigned_at'      => current_time( 'mysql' ),
                ],
                [ '%s', '%d', '%s', '%d', '%d', '%s' ]
            );
        }

        if ( $ok === false ) wp_send_json_error( [ 'message' => 'DB error' ], 500 );

        wp_send_json_success( [
            'provider_user_id' => $provider,
            'name'             => $this->user_display( $provider ),
        ] );
    }

    /* ------------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------*/

    private function user_display( $user_id ) {
        $u = get_user_by( 'id', (int) $user_id );
        if ( ! $u ) return '';
        return $u->display_name ?: $u->user_login;
    }

    /**
     * Find users who have any of the comma-separated WPF tag patterns.
     * Exact tag match for now (patterns attribute is a list of tags).
     */

    private function user_matches_exclude( $user_id, array $exclude ) {
        if ( empty( $exclude ) ) return false;
        foreach ( $exclude as $tag ) {
            if ( $this->user_has_wpf_tag( $user_id, $tag ) ) return true;
        }
        return false;
    }

    private function find_users_by_tag_patterns( $patterns_csv, $exclude_csv = '' ) {
        $patterns = array_filter( array_map( 'trim', explode( ',', (string) $patterns_csv ) ) );
        $exclude  = array_filter( array_map( 'trim', explode( ',', (string) $exclude_csv ) ) );
        if ( empty( $patterns ) ) return [];

        // Prefer WP Fusion helper if available
        $out = [];
        if ( function_exists( 'wpf_get_users_with_tag' ) ) {
            $seen = [];
            foreach ( $patterns as $tag ) {
                $ids = wpf_get_users_with_tag( $tag );
                if ( ! is_array( $ids ) ) continue;
                foreach ( $ids as $uid ) {
                    $uid = (int) $uid;
                    if ( isset( $seen[ $uid ] ) ) continue;
                    if ( $this->user_matches_exclude( $uid, $exclude ) ) continue;
                    $seen[ $uid ] = true;
                    $out[] = [ 'id' => $uid, 'name' => $this->user_display( $uid ) ];
                }
            }
            usort( $out, function( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
            return $out;
        }

        // Fallback: scan users (acceptable for small provider sets)
        $users = get_users( [ 'fields' => [ 'ID', 'display_name' ], 'number' => 500 ] );
        foreach ( $users as $u ) {
            foreach ( $patterns as $tag ) {
                if ( $this->user_has_wpf_tag( $u->ID, $tag ) ) {
                    if ( $this->user_matches_exclude( $u->ID, $exclude ) ) break;
                    $out[] = [ 'id' => (int) $u->ID, 'name' => $u->display_name ];
                    break;
                }
            }
        }
        usort( $out, function( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        return $out;
    }
}

ULS_Member_Provider_Assignment::instance();

endif;
