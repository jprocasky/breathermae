<?php
/**
 * ULS Member Notes Module (append-only notes + audit trail + member visibility + member shortcode)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'ULS_Member_Notes_Module' ) ) :
class ULS_Member_Notes_Module {

    const DB_VERSION = '1.3.0'; // bumped for is_member_visible + shortcode
    private static $instance = null;
    private $table = 'uls_member_notes';

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'maybe_upgrade_schema' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Admin/Coach AJAX
        add_action( 'wp_ajax_uls_get_member_notes', [ $this, 'ajax_get_member_notes' ] );
        add_action( 'wp_ajax_uls_save_member_note', [ $this, 'ajax_save_member_note' ] );
        add_action( 'wp_ajax_uls_toggle_member_note_visibility', [ $this, 'ajax_toggle_member_note_visibility' ] );
        add_action(
            'wp_ajax_uls_delete_member_note',
            [ $this, 'ajax_delete_member_note' ]
            );

        // Member-facing shortcode
        add_shortcode( 'uls_member_notes', [ $this, 'shortcode_member_notes' ] );
        add_action( 'wp_enqueue_scripts', function () {
            if ( ! is_user_logged_in() ) return;

            // Loads TinyMCE + dependencies
            wp_enqueue_editor();
        } );
        add_shortcode( 'uls_member_notes_add', [ $this, 'shortcode_member_notes_add' ] );
    }

    public function enqueue_assets() {
        wp_register_style( 'uls-notes-css', plugins_url( 'uls-notes.css', __FILE__ ), [], self::DB_VERSION );
        wp_enqueue_style( 'uls-notes-css' );

        wp_register_script( 'uls-notes-js', plugins_url( 'uls-notes.js', __FILE__ ), [ 'jquery' ], self::DB_VERSION, true );

        // Internal editor remains unrestricted per your workflow (WPF controls page access)
        $can_edit = true;

        wp_localize_script( 'uls-notes-js', 'ULS_NOTES', [
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
            'getAction'   => 'uls_get_member_notes',
            'saveAction'  => 'uls_save_member_note',
            'toggleVisAction' => 'uls_toggle_member_note_visibility',
            'nonce'       => wp_create_nonce( 'uls_member_notes' ),
            'currentUserId' => get_current_user_id(),
            'canEdit'     => $can_edit ? 1 : 0,
            'currentUserEmail' => wp_get_current_user()->user_email,
        ] );
        wp_enqueue_script( 'uls-notes-js' );
    }

    public function maybe_upgrade_schema() {
        $opt = 'uls_member_notes_db_version';
        $installed = get_option( $opt );
        if ( $installed === self::DB_VERSION ) return;

        global $wpdb; $charset_collate = $wpdb->get_charset_collate(); $table = $this->table;

        // Add is_member_visible similar in spirit to Files' is_member_visible flag
        // (keeps append-only notes but allows member visibility control)
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `member_email` VARCHAR(191) NOT NULL,
            `member_user_id` BIGINT UNSIGNED NULL,
            `note_name` VARCHAR(100) NOT NULL DEFAULT '',
            `note_text` LONGTEXT NOT NULL,
            `is_member_visible` TINYINT(1) NOT NULL DEFAULT 0,

            -- ✅ NEW
            `visibility_scope` VARCHAR(20) NOT NULL DEFAULT 'shared',
            `author_context` VARCHAR(50) NOT NULL DEFAULT '',

            `created_by` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_by` BIGINT UNSIGNED NULL,
            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,

            PRIMARY KEY (`id`),
            KEY `idx_member_email` (`member_email`),
            KEY `idx_member_user_id` (`member_user_id`),
            KEY `idx_note_name` (`note_name`),
            KEY `idx_member_visible` (`is_member_visible`),

            -- ✅ NEW
            KEY `idx_visibility_scope` (`visibility_scope`),
            KEY `idx_author_context` (`author_context`)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // In case the table already existed without the column, try to add it
        $cols = $wpdb->get_col( "DESC `{$table}`", 0 );
        if ( is_array( $cols ) && ! in_array( 'is_member_visible', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD `is_member_visible` TINYINT(1) NOT NULL DEFAULT 0 AFTER `note_text`" );
            $wpdb->query( "CREATE INDEX `idx_member_visible` ON `{$table}` (`is_member_visible`)" );
        }


        if ( is_array( $cols ) ) {

            if ( ! in_array( 'visibility_scope', $cols, true ) ) {
                $wpdb->query(
                "ALTER TABLE `{$table}`
                ADD `visibility_scope` VARCHAR(20) NOT NULL DEFAULT 'shared' AFTER `is_member_visible`"
                );
                $wpdb->query(
                "CREATE INDEX `idx_visibility_scope`
                ON `{$table}` (`visibility_scope`)"
                );
            }

            if ( ! in_array( 'author_context', $cols, true ) ) {
                $wpdb->query(
                "ALTER TABLE `{$table}`
                ADD `author_context` VARCHAR(50) NOT NULL DEFAULT '' AFTER `visibility_scope`"
                );
                $wpdb->query(
                "CREATE INDEX `idx_author_context`
                ON `{$table}` (`author_context`)"
                );
            }
        }
        update_option( $opt, self::DB_VERSION );
    }

    private function fmt_dt( $mysql_dt ) {
        if ( empty( $mysql_dt ) ) return '';
        $ts = strtotime( $mysql_dt ); if ( ! $ts ) return $mysql_dt;
        $fmt = trim( sprintf( '%s %s',
            (string) get_option( 'date_format', 'M j, Y' ),
            (string) get_option( 'time_format', 'g:i a' )
        ) );
        return date_i18n( $fmt, $ts );
    }

    private function author_name( $user_id ) {
        $user_id = intval( $user_id );
        if ( $user_id <= 0 ) return '';
        $u = get_user_by( 'id', $user_id );
        return $u ? ( $u->display_name ?: $u->user_login ) : '';
    }

    private function normalize_note_name( $note ) {
        $note = sanitize_text_field( (string) $note );
        return substr( $note, 0, 100 );
    }

    private function member_user_id( $email ) {
        $u = get_user_by( 'email', $email );
        return $u ? (int) $u->ID : null;
    }

    public function ajax_delete_member_note() {

        check_ajax_referer( 'uls_member_notes', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
        }

        global $wpdb;
        $table = $this->table;

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Bad request' ], 400 );
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, created_by FROM `{$table}` WHERE id = %d AND is_deleted = 0",
                $id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'Not found' ], 404 );
        }

        // ✅ Author-only delete (recommended)
        if ( (int) $row['created_by'] !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        $wpdb->update(
            $table,
            [ 'is_deleted' => 1 ],
            [ 'id' => $id ],
            [ '%d' ],
            [ '%d' ]
        );

        wp_send_json_success( [ 'id' => $id ] );
    }

    /** ----------------------- AJAX: List Notes ----------------------- */
    public function ajax_get_member_notes() {
        check_ajax_referer( 'uls_member_notes', 'nonce' );
        if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 ); }

        $email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $note_name = isset( $_POST['note_name'] ) ? $this->normalize_note_name( wp_unslash( $_POST['note_name'] ) ) : '';
        $current_user_id = get_current_user_id();
        $context = isset($_POST['context'])
            ? sanitize_key($_POST['context'])
            : '';        

        if ( ! is_email( $email ) ) { wp_send_json_error( [ 'message' => 'Invalid email' ], 400 ); }

        global $wpdb; $table = $this->table;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` 
             WHERE `member_email` = %s AND `note_name` = %s AND `is_deleted` = 0 
             ORDER BY `id` DESC LIMIT 200",
            $email, $note_name
        ), ARRAY_A );

        $notes = [];
        if ( is_array( $rows ) ) {

            foreach ((array) $rows as $r) {

                $visibility = $r['visibility_scope'] ?? 'shared';

                // 🔐 Private note?
                if ($visibility === 'private') {

                    // Must be same author AND same context
                    $same_author  = (int) $r['created_by'] === $current_user_id;
                    $same_context = !empty($context) && $r['author_context'] === $context;

                    if ( ! $same_author || ! $same_context ) {
                    continue; // 🚫 hide completely
                    }
                }

                $notes[] = [
                    'id'                 => (int) $r['id'],
                    'note_name'          => (string) $r['note_name'],
                    'note_text' => wp_kses_post( (string) $r['note_text'] ),
                    'is_member_visible'  => (int) $r['is_member_visible'],
                    'visibility_scope'   => $visibility,
                    'author_context'     => $r['author_context'],
                    'created_by'         => (int) $r['created_by'],
                    'created_by_name'    => $this->author_name($r['created_by']),
                    'created_at'         => $this->fmt_dt($r['created_at']),
                    'updated_by'         => $r['updated_by'] ? (int) $r['updated_by'] : null,
                    'updated_at'         => $this->fmt_dt($r['updated_at']),
                ];
            }

        }
        wp_send_json_success( [ 'notes' => $notes, 'note_name' => $note_name ] );
    }

    /** ----------------------- AJAX: Save Note ----------------------- */
    public function ajax_save_member_note() {

        check_ajax_referer( 'uls_member_notes', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
        }

        global $wpdb;
        $table = $this->table;

        // ---------- Input ----------
        $id    = (int) ( $_POST['id'] ?? 0 );
        $email = isset( $_POST['email'] )
            ? sanitize_email( wp_unslash( $_POST['email'] ) )
            : '';

        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Invalid email' ], 400 );
        }

        $note_text = trim( (string) ( $_POST['note_text'] ?? '' ) );
        if ( $note_text === '' ) {
            wp_send_json_error( [ 'message' => 'Note is empty' ], 400 );
        }

        $note_name = isset( $_POST['note_name'] )
            ? $this->normalize_note_name( wp_unslash( $_POST['note_name'] ) )
            : '';

        $author_context = isset( $_POST['context'] )
            ? sanitize_key( $_POST['context'] )
            : '';

        // ✅ Default visibility: member-created notes are visible to the member
        if ( $author_context === 'member' ) {
            $is_member_visible = 1;
        } else {
            $is_member_visible = isset( $_POST['is_member_visible'] )
                ? (int) $_POST['is_member_visible']
                : 0;
        }

        $visibility_scope =
            ( isset( $_POST['visibility_scope'] ) && $_POST['visibility_scope'] === 'private' )
                ? 'private'
                : 'shared';

        // ✅ Default visibility: member-created notes are visible to the member
        $current_user_id = get_current_user_id();

        $member_user = get_user_by( 'email', $email );
        $member_user_id = $member_user ? (int) $member_user->ID : null;

        if ( $member_user_id && $member_user_id === $current_user_id ) {
            // Member saving their own note
            $is_member_visible = 1;
            $author_context = 'member';
        } else {
            // Provider or other context
            $author_context = isset( $_POST['context'] )
                ? sanitize_key( $_POST['context'] )
                : '';

            $is_member_visible = isset( $_POST['is_member_visible'] )
                ? (int) $_POST['is_member_visible']
                : 0;
        }

        // ---------- UPDATE existing note ----------
        if ( $id > 0 ) {

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, created_by 
                    FROM `{$table}`
                    WHERE id = %d AND member_email = %s AND is_deleted = 0
                    LIMIT 1",
                    $id,
                    $email
                ),
                ARRAY_A
            );

            if ( ! $row ) {
                wp_send_json_error( [ 'message' => 'Note not found' ], 404 );
            }

            // ✅ Author-only edit
            if ( (int) $row['created_by'] !== $current_user_id ) {
                wp_send_json_error(
                    [ 'message' => 'Only the author can edit this note' ],
                    403
                );
            }

            $ok = $wpdb->update(
                $table,
                [
                    'note_text'         => $note_text,
                    'is_member_visible' => $is_member_visible ? 1 : 0,
                    'visibility_scope'  => $visibility_scope,
                    'updated_by'        => $current_user_id,
                    'updated_at'        => current_time( 'mysql' ),
                ],
                [ 'id' => $id ],
                [ '%s', '%d', '%s', '%d', '%s' ],
                [ '%d' ]
            );

            if ( $ok === false ) {
                wp_send_json_error( [ 'message' => 'DB update failed' ], 500 );
            }

            wp_send_json_success( [
                'note' => [
                    'id'                 => $id,
                    'note_name'          => $note_name,
                    'note_text'          => $note_text,
                    'is_member_visible'  => $is_member_visible ? 1 : 0,
                    'visibility_scope'   => $visibility_scope,
                    'author_context'     => $author_context,
                    'created_by'         => $row['created_by'],
                    'created_by_name'    => $this->author_name( $row['created_by'] ),
                    'created_at'         => null, // unchanged on edit
                    'updated_by'         => $current_user_id,
                    'updated_by_name'    => $this->author_name( $current_user_id ),
                    'updated_at'         => $this->fmt_dt( current_time( 'mysql' ) ),
                    'is_deleted'         => 0,
                ]
            ] );
        }

        // ---------- INSERT new note ----------
        $ok = $wpdb->insert(
            $table,
            [
                'member_email'      => $email,
                'member_user_id'    => $member_user_id,
                'note_name'         => $note_name,
                'note_text'         => $note_text,
                'is_member_visible' => $is_member_visible ? 1 : 0,
                'visibility_scope'  => $visibility_scope,
                'author_context'    => $author_context,
                'created_by'        => $current_user_id,
                'created_at'        => current_time( 'mysql' ),
                'updated_by'        => null,
                'updated_at'        => null,
                'is_deleted'        => 0,
            ],
            [ '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d' ]
        );

        if ( ! $ok ) {
            wp_send_json_error( [ 'message' => 'DB insert failed' ], 500 );
        }

        $new_id = (int) $wpdb->insert_id;

        wp_send_json_success( [
            'note' => [
                'id'                 => $new_id,
                'note_name'          => $note_name,
                'note_text'          => $note_text,
                'is_member_visible'  => $is_member_visible ? 1 : 0,
                'visibility_scope'   => $visibility_scope,
                'author_context'     => $author_context,
                'created_by'         => $current_user_id,
                'created_by_name'    => $this->author_name( $current_user_id ),
                'created_at'         => $this->fmt_dt( current_time( 'mysql' ) ),
                'updated_by'         => null,
                'updated_by_name'    => null,
                'updated_at'         => '',
                'is_deleted'         => 0,
            ]
        ] );
    }
    /** --------- AJAX: Toggle visibility (checkbox in internal list) --------- */
    public function ajax_toggle_member_note_visibility() {

        check_ajax_referer( 'uls_member_notes', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
        }

        global $wpdb;
        $table = $this->table;

        $id    = (int) ( $_POST['id'] ?? 0 );
        $email = isset( $_POST['email'] )
            ? sanitize_email( wp_unslash( $_POST['email'] ) )
            : '';

        if ( ! $id || ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Bad request' ], 400 );
        }

        // 🔎 Load the note row once
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, created_by, is_member_visible
                FROM `{$table}`
                WHERE id = %d
                AND member_email = %s
                AND is_deleted = 0
                LIMIT 1",
                $id,
                $email
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'Not found' ], 404 );
        }

        $current_user_id = get_current_user_id();

        // ✅ Member-facing visibility (independent)
        if ( isset( $_POST['is_member_visible'] ) ) {

            $visible = (int) $_POST['is_member_visible'];

            $wpdb->update(
                $table,
                [ 'is_member_visible' => $visible ? 1 : 0 ],
                [ 'id' => $id ],
                [ '%d' ],
                [ '%d' ]
            );
        }

        // ✅ Provider-facing privacy (AUTHOR ONLY)
        if ( isset( $_POST['visibility_scope'] ) ) {

            // Only the author may change provider privacy
            if ( (int) $row['created_by'] !== $current_user_id ) {
                wp_send_json_error(
                    [ 'message' => 'Only the author can change note privacy' ],
                    403
                );
            }

            $scope = sanitize_key( $_POST['visibility_scope'] );

            if ( in_array( $scope, [ 'shared', 'private' ], true ) ) {
                $wpdb->update(
                    $table,
                    [ 'visibility_scope' => $scope ],
                    [ 'id' => $id ],
                    [ '%s' ],
                    [ '%d' ]
                );
            }
        }

        wp_send_json_success( [
            'id'                => $id,
            'is_member_visible' => isset( $_POST['is_member_visible'] )
                ? (int) $_POST['is_member_visible']
                : (int) $row['is_member_visible'],
            'visibility_scope'  => $_POST['visibility_scope'] ?? null,
        ] );
    }
    
    public function shortcode_member_notes_add( $atts ) {
        if ( ! is_user_logged_in() ) return '';

        $atts = shortcode_atts(
            [
                'note_name' => '',
            ],
            $atts,
            'uls_member_notes_add'
        );

        if ( $atts['note_name'] === '' ) {
            return '<div class="uls-notes-error">Missing note_name.</div>';
        }

        ob_start();
        ?>
        <div
            class="uls-notes-panel"
            data-note-name="<?php echo esc_attr( $this->normalize_note_name( $atts['note_name'] ) ); ?>"
            data-allow-add="1"
            data-context="member"
        >
            <div class="uls-notes-editor">
                <textarea
                    id="uls-notes-text-editor"
                    class="uls-notes-text"
                    rows="6"
                ></textarea>

                <button type="button" class="uls-notes-save">Save Note</button>
                <div class="uls-notes-status"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }    
    /** ---------------- Shortcode: Member‑visible notes ----------------
     * [uls_member_notes note_name="" all="0" max="200" show_note="0" show_author="1" show_dates="1" format="text"]
     * - Shows only notes for the CURRENT LOGGED‑IN USER where is_member_visible=1 (like Files).
     * - If all=1, ignore note_name filter.
     */
    public function shortcode_member_notes( $atts ) {
        if ( ! is_user_logged_in() ) return '';

        $atts = shortcode_atts( [
            'note_name'   => '',
            'all'         => 0,
            'max'         => 200,
            'show_note'   => 0, // show Category column
            'show_author' => 1,
            'show_dates'  => 1,
            'format'      => 'html', // 'text' or 'html' rendering of note_text
            'allow_add' => '0', // ✅ NEW: allow member add 
        ], $atts, 'uls_member_notes' );

        $email = wp_get_current_user()->user_email;

        global $wpdb; $table = $this->table;

        $sql = "SELECT id, note_name, note_text, is_member_visible, created_by, created_at, updated_by, updated_at
                  FROM `{$table}`
                 WHERE `member_email` = %s AND `is_deleted` = 0 AND `is_member_visible` = 1";
        $params = [ $email ];

        if ( ! (int) $atts['all'] && $atts['note_name'] !== '' ) {
            $sql .= " AND `note_name` = %s";
            $params[] = $this->normalize_note_name( $atts['note_name'] );
        }

        $sql .= " ORDER BY `id` DESC LIMIT " . (int) $atts['max'];

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        if ( empty( $rows ) ) {
            return '<div class="uls-member-notes">No notes available.</div>';
        }

        $fmt = trim( sprintf( '%s %s',
            (string) get_option( 'date_format', 'M j, Y' ),
            (string) get_option( 'time_format', 'g:i a' )
        ) );

        ob_start(); ?>
<div class="uls-member-notes">
  <table class="uls-notes-table">
    <thead>
      <tr>
        <?php if ( (int) $atts['show_note'] ) : ?><th>Category</th><?php endif; ?>
        <th>Note</th>
        <?php if ( (int) $atts['show_author'] ) : ?><th>Author</th><?php endif; ?>
        <?php if ( (int) $atts['show_dates'] ) : ?><th>Created</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ( $rows as $r ) : ?>
        <tr>
          <?php if ( (int) $atts['show_note'] ) : ?>
            <td><?php echo esc_html( $r['note_name'] ); ?></td>
          <?php endif; ?>
          <td>
            <?php
              if ( strtolower( $atts['format'] ) === 'html' ) {
                  // Render as-is but sanitize for safe HTML (allow basic formatting)
                  echo wp_kses_post( $r['note_text'] );
              } else {
                  echo nl2br( esc_html( $r['note_text'] ) );
              }
            ?>
          </td>
          <?php if ( (int) $atts['show_author'] ) : ?>
            <td><?php echo esc_html( $this->author_name( $r['created_by'] ) ); ?></td>
          <?php endif; ?>
          <?php if ( (int) $atts['show_dates'] ) : ?>
            <td><?php echo esc_html( date_i18n( $fmt, strtotime( $r['created_at'] ) ) ); ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
        return ob_get_clean();
    }
}
endif;

ULS_Member_Notes_Module::instance();