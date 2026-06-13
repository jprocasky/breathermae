<?php
/**
 * ULS Member Files Module
 * - Admin/Coach upload/list/delete/toggle visibility (by note_name category)
 * - Member-facing shortcode [uls_member_files] (visible files only)
 * - Controlled downloads via admin-ajax (no direct URLs)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'ULS_Member_Files_Module' ) ) :



class ULS_Member_Files_Module {

    const DB_VERSION = '1.1.0';
    private static $instance = null;
    private $table = 'uls_member_files';

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init',                   [ $this, 'maybe_upgrade_schema' ] );
        add_action( 'wp_enqueue_scripts',     [ $this, 'enqueue_assets' ] );

        // Admin/Coach AJAX
        add_action( 'wp_ajax_uls_list_member_files',        [ $this, 'ajax_list_member_files' ] );
        add_action( 'wp_ajax_uls_list_all_member_files',    [ $this, 'ajax_list_all_member_files' ] );
        add_action( 'wp_ajax_uls_upload_member_file',       [ $this, 'ajax_upload_member_file' ] );
        add_action( 'wp_ajax_uls_toggle_member_visibility', [ $this, 'ajax_toggle_member_visibility' ] );
        add_action( 'wp_ajax_uls_delete_member_file',       [ $this, 'ajax_delete_member_file' ] );
        
        add_action('wp_ajax_uls_view_file', [$this, 'ajax_view_file']);
        // Controlled download (Admin + Member)
        add_action( 'wp_ajax_uls_download_member_file',     [ $this, 'ajax_download_member_file' ] );

        // Member-facing shortcode
        add_shortcode( 'uls_member_files', [ $this, 'shortcode_member_files' ] );
        add_shortcode(
            'uls_member_file_upload',
            [ $this, 'shortcode_member_file_upload' ]
        );
        add_action(
            'wp_ajax_uls_toggle_file_visibility_scope',
            [ $this, 'ajax_toggle_file_visibility_scope' ]
        );
    }

    /* ------------------------ Helpers ------------------------ */

    private function can_view( $current_user_id, $member_email, $note_name ) {
        // Default: if you can see the page (WPF-gated), you can view.
        $can = is_user_logged_in();
        return (bool) apply_filters( 'uls_member_files_can_view', $can, $current_user_id, $member_email, $note_name );
    }


    private function can_edit( $current_user_id, $member_email, $note_name ) {
        // Default: same as can_view. (You can still tighten this later via the filter.)
        $can = is_user_logged_in();
        return (bool) apply_filters( 'uls_member_files_can_edit', $can, $current_user_id, $member_email, $note_name );
    }


    private function can_download( $current_user_id, $member_email, $note_name, $row ) {
        // Default: any logged-in user can download if they’re allowed by the page or it’s their own file with is_member_visible=1.
        // We’ll harden this below for member-facing shortcode (own files only).
        $can = is_user_logged_in();
        return (bool) apply_filters( 'uls_member_files_can_download', $can, $current_user_id, $member_email, $note_name, $row );
    }

    private function normalize_note_name( $note ) {
        $note = sanitize_text_field( (string) $note );
        return substr( $note, 0, 100 );
    }

    private function member_user_id( $email ) {
        $u = get_user_by( 'email', $email );
        return $u ? (int) $u->ID : null;
    }

    private function dtfmt() {
        return trim( sprintf(
            '%s %s',
            (string) get_option( 'date_format', 'M j, Y' ),
            (string) get_option( 'time_format', 'g:i a' )
        ) );
    }

    private function author_name( $id ) {
        $u = get_user_by( 'id', (int) $id );
        return $u ? ( $u->display_name ?: $u->user_login ) : '';
    }

    private function sanitize_allowed_ext( $tmp, $name ) {
        // Your conservative server-side allow-list
        $allowed = (array) apply_filters(
            'uls_member_files_allowed_exts',
            [ 'pdf', 'jpg', 'jpeg', 'png', 'txt', 'csv' ]
        );

        // First pass: WordPress' stricter check (validates real MIME + extension)
        $checked = wp_check_filetype_and_ext( $tmp, $name );

        // Derive extension: prefer WP's result; else fall back to filename
        $ext = '';
        if ( ! empty( $checked['ext'] ) ) {
            $ext = strtolower( $checked['ext'] );
        } else {
            $ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
        }

        if ( $ext === '' || ! in_array( $ext, $allowed, true ) ) {
            return [ false, null, null ];
        }

        // Determine MIME: prefer WP's result; else try name-based; else safe fallback map
        $mime = '';
        if ( ! empty( $checked['type'] ) ) {
            $mime = $checked['type'];
        } else {
            // name-based guess
            $ft = wp_check_filetype( $name );
            if ( ! empty( $ft['type'] ) ) {
                $mime = $ft['type'];
            } else {
                // conservative fallbacks for our small allow-list
                $fallback = [
                    'pdf'  => 'application/pdf',
                    'png'  => 'image/png',
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'txt'  => 'text/plain',
                    'csv'  => 'text/csv',
                ];
                $mime = isset( $fallback[ $ext ] ) ? $fallback[ $ext ] : 'application/octet-stream';
            }
        }

        return [ true, $ext, $mime ];
    }


    private function build_member_dir( $email, $note ) {
        $uploads   = wp_upload_dir();
        $safeEmail = md5( strtolower( $email ) );
        $sub       = $uploads['basedir'] . '/uls-members/' . $safeEmail;
        if ( $note !== '' ) $sub .= '/' . sanitize_title( $note );
        wp_mkdir_p( $sub );
        $urlbase   = $uploads['baseurl'] . '/uls-members/' . $safeEmail . ( $note !== '' ? '/' . sanitize_title( $note ) : '' );
        return [ $sub, $urlbase ];
    }

    /* ------------------------ Assets ------------------------ */

    public function enqueue_assets() {
    
        wp_register_style(
            'uls-files-css',
            plugins_url( 'uls-files.css', __FILE__ ),
            [],
            self::DB_VERSION          // cache-bust with module version
        );
        wp_enqueue_style( 'uls-files-css' );
        
        wp_register_script(
            'uls-files-js',
            plugins_url( 'uls-files.js', __FILE__ ),
            [ 'jquery' ],
            self::DB_VERSION,
            true
        );
        $max_bytes = (int) apply_filters( 'uls_member_files_max_bytes', 10 * 1024 * 1024 ); // 10MB
        $allowed   = (array) apply_filters( 'uls_member_files_allowed_exts', [ 'pdf', 'jpg', 'jpeg', 'png', 'txt', 'csv' ] );


        global $wpdb;

        $document_types = $wpdb->get_results(
            "SELECT id, label
            FROM {$wpdb->prefix}ai_document_types
            WHERE is_active = 1
            ORDER BY sort_order",
            ARRAY_A
        );


        wp_localize_script( 'uls-files-js', 'ULS_FILES', [
            'ajaxurl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'uls_member_files' ),
            'listAction'      => 'uls_list_member_files',
            'listAllAction'   => 'uls_list_all_member_files',
            'uploadAction'    => 'uls_upload_member_file',
            'toggleVisAction' => 'uls_toggle_member_visibility',
            'deleteAction'    => 'uls_delete_member_file',
            'downloadAction'  => 'uls_download_member_file',
            'maxBytes'        => $max_bytes,
            'allowedExts'     => $allowed,
            'currentUserId' => get_current_user_id(),
            'canEdit'         => apply_filters( 'uls_member_files_can_edit_frontend', true ) ? 1 : 0,
            'documentTypes' => $document_types,
        ] );

        wp_enqueue_script( 'uls-files-js' );
    }

    /* ------------------------ DB Schema ------------------------ */

    public function maybe_upgrade_schema() {
        $opt = 'uls_member_files_db_version';
        $installed = get_option( $opt );
        if ( $installed === self::DB_VERSION ) return;

        global $wpdb; $charset = $wpdb->get_charset_collate(); $table = $this->table;
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `member_email` VARCHAR(191) NOT NULL,
            `member_user_id` BIGINT UNSIGNED NULL,
            `note_name` VARCHAR(100) NOT NULL DEFAULT '',
            `original_name` VARCHAR(255) NOT NULL DEFAULT '',
            `file_name` VARCHAR(255) NOT NULL,
            `file_path` TEXT NOT NULL,
            `mime_type` VARCHAR(100) NOT NULL DEFAULT '',
            `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `attachment_id` BIGINT UNSIGNED NULL,
            `is_member_visible` TINYINT(1) NOT NULL DEFAULT 0,

            -- ✅ NEW
            `visibility_scope` VARCHAR(20) NOT NULL DEFAULT 'shared',
            `author_context` VARCHAR(50) NOT NULL DEFAULT '',

            `uploaded_by` BIGINT UNSIGNED NOT NULL,
            `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            `deleted_by` BIGINT UNSIGNED NULL,
            `deleted_at` DATETIME NULL,

            PRIMARY KEY (`id`),
            KEY `idx_member_email` (`member_email`),
            KEY `idx_member_user_id` (`member_user_id`),
            KEY `idx_note_name` (`note_name`),
            KEY `idx_member_visible` (`is_member_visible`),

            -- ✅ NEW
            KEY `idx_visibility_scope` (`visibility_scope`),
            KEY `idx_author_context` (`author_context`),

            KEY `idx_uploaded_at` (`uploaded_at`)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

            $cols = $wpdb->get_col( "DESC `{$table}`", 0 );

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

    /* ------------------------ AJAX: List ------------------------ */

    public function ajax_list_member_files() {
        check_ajax_referer( 'uls_member_files', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );


        $current_user_id = get_current_user_id(); // ✅ REQUIRED
        $context = isset($_POST['context'])
            ? sanitize_key($_POST['context'])
            : '';

        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $note  = $this->normalize_note_name( $_POST['note_name'] ?? '' );
        $all   = isset( $_POST['all'] ) ? (int) $_POST['all'] : 0;

        if ( ! is_email( $email ) ) wp_send_json_error( [ 'message' => 'Invalid email' ], 400 );
        if ( ! $this->can_edit( get_current_user_id(), $email, $note ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        
        if ( ! $this->can_view( get_current_user_id(), $email, $note ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }


        global $wpdb; $table = $this->table;
        if ( $all ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `member_email` = %s AND `is_deleted` = 0 ORDER BY `uploaded_at` DESC LIMIT 500", $email ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `member_email` = %s AND `note_name` = %s AND `is_deleted` = 0 ORDER BY `uploaded_at` DESC LIMIT 200", $email, $note ),
                ARRAY_A
            );
        }

        
        $out = [];
        $fmt = $this->dtfmt();

        foreach ((array) $rows as $r) {

            $visibility = $r['visibility_scope'] ?? 'shared';

            if ($visibility === 'private') {

                $same_author  = (int) $r['uploaded_by'] === $current_user_id;
                $same_context = !empty($context) && $r['author_context'] === $context;

                if ( ! $same_author || ! $same_context ) {
                continue; // 🚫 hide completely
                }
            }
            
            // For files that are either shared or owned by the current user in the same context, we include them but also check for AI summary status (if context is provided and AI summary class exists).
            $context = isset($_POST['context']) ? sanitize_key($_POST['context']) : '';

            $ai_summary = null;
            $has_ai_summary = false;
            $ai_summary_stale = false;

            if ( $context && class_exists( 'ULS_AI_File_Summaries' ) ) {
                $ai = ULS_AI_File_Summaries::instance();

                // Cheap existence check
                $has_ai_summary = $ai->summary_exists( (int) $r['id'], $context );

                if ( $has_ai_summary ) {
                    // Optional but recommended: staleness detection
                    $current_file_hash = is_file( $r['file_path'] ?? '' )
                        ? md5_file( $r['file_path'] )
                        : '';

                    if ( $current_file_hash ) {
                        $ai_summary_stale = $ai->is_summary_stale(
                            (int) $r['id'],
                            $context,
                            $current_file_hash
                        );
                    }
                }
            }

            $summary = null;

            if ( $has_ai_summary ) {
                $summary = $ai->get_summary( (int) $r['id'], $context );
            }            

            $is_viewable = $this->is_viewable_mime( $r['mime_type'] );
            $view_type  = strtok( $r['mime_type'], '/' );

            $out[] = [
                'id'                 => (int) $r['id'],
                'note_name'          => (string) $r['note_name'],
                'original_name'      => (string) $r['original_name'],
                'file_name'          => (string) $r['file_name'],
                'mime_type'          => (string) $r['mime_type'],
                'file_size'          => (int) $r['file_size'],
                'is_member_visible'  => (int) $r['is_member_visible'],
                'visibility_scope'   => $visibility,
                'author_context'     => $r['author_context'],
                'uploaded_by'        => (int) $r['uploaded_by'],
                'uploaded_by_name'   => $this->author_name($r['uploaded_by']),
                'uploaded_at'        => date_i18n($fmt, strtotime($r['uploaded_at'])),
                'is_viewable'      => $is_viewable,
                'view_type'        => $view_type,
                'has_ai_summary' => $has_ai_summary,
                'ai_summary_stale' => $ai_summary_stale,
                'ai_document_type_id' => $summary
                    ? (int) $summary['document_type_id']
                    : 0,

            ];
        }

        wp_send_json_success( [ 'files' => $out ] );
    }

    public function ajax_list_all_member_files() {
        $_POST['all'] = 1;
        $this->ajax_list_member_files();
    }

    /* ------------------------ AJAX: Upload ------------------------ */

    public function ajax_upload_member_file() {
        check_ajax_referer( 'uls_member_files', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );

        $email           = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $note            = $this->normalize_note_name( $_POST['note_name'] ?? '' );
        $member_visible  = isset( $_POST['is_member_visible'] ) ? (int) $_POST['is_member_visible'] : 0;
        $current_user = wp_get_current_user();

        // ✅ If the uploader IS the member, force provider-only visibility
        if ( $current_user && is_email( $email ) ) {
            if ( strtolower( $current_user->user_email ) === strtolower( $email ) ) {
                $member_visible = 1;
            }
        }        
        $overwrite       = isset( $_POST['overwrite'] ) ? (int) $_POST['overwrite'] : 1;

        if ( ! is_email( $email ) ) wp_send_json_error( [ 'message' => 'Invalid email' ], 400 );
        if ( ! $this->can_edit( get_current_user_id(), $email, $note ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        if ( empty( $_FILES['file'] ) || $_FILES['file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => 'No file or upload error' ], 400 );
        }

        $max = (int) apply_filters( 'uls_member_files_max_bytes', 10 * 1024 * 1024 ); // 10MB
        if ( $_FILES['file']['size'] > $max ) {
            wp_send_json_error( [ 'message' => 'File too large (max 10MB)' ], 400 );
        }

        [ $ok, $ext, $mime ] = $this->sanitize_allowed_ext( $_FILES['file']['tmp_name'], $_FILES['file']['name'] );
        if ( ! $ok ) wp_send_json_error( [ 'message' => 'File type not allowed' ], 400 );

        [ $dir, $urlbase ] = $this->build_member_dir( $email, $note );
        $orig   = sanitize_file_name( $_FILES['file']['name'] );
        $dest   = $orig;
        $target = trailingslashit( $dir ) . $dest;

        if ( file_exists( $target ) ) {
            if ( $overwrite ) {
                @unlink( $target );
            } else {
                $dest   = wp_unique_filename( $dir, $dest );
                $target = trailingslashit( $dir ) . $dest;
            }
        }

        if ( ! @move_uploaded_file( $_FILES['file']['tmp_name'], $target ) ) {
            wp_send_json_error( [ 'message' => 'Move failed' ], 500 );
        }

        // Register in Media Library
        $attach_id = 0;
        $file_url  = trailingslashit( $urlbase ) . $dest;
        $attachment = [
            'guid'           => $file_url,
            'post_mime_type' => $mime ?: mime_content_type( $target ),
            'post_title'     => pathinfo( $dest, PATHINFO_FILENAME ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment( $attachment, $target );
        if ( $attach_id ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $meta = wp_generate_attachment_metadata( $attach_id, $target );
            wp_update_attachment_metadata( $attach_id, $meta );
        }

        // If overwriting by same original name, soft-delete prior rows for this member & category
        global $wpdb; $table = $this->table;
        if ( $overwrite ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE `{$table}` SET `is_deleted` = 1, `deleted_by` = %d, `deleted_at` = %s
                 WHERE `member_email` = %s AND `note_name` = %s AND `original_name` = %s AND `is_deleted` = 0",
                get_current_user_id(), current_time( 'mysql' ), $email, $note, $orig
            ) );
        }

        $author_context = isset($_POST['context'])
            ? sanitize_key($_POST['context'])
            : '';


        $wpdb->insert( $table, [
            'member_email'      => $email,
            'member_user_id'    => $this->member_user_id( $email ),
            'note_name'         => $note,
            'original_name'     => $orig,
            'file_name'         => $dest,
            'file_path'         => $target,
            'mime_type'         => $mime ?: '',
            'file_size'         => (int) filesize( $target ),
            'attachment_id'     => $attach_id ?: null,
            'is_member_visible' => $member_visible ? 1 : 0,
            'uploaded_by'       => get_current_user_id(),
            'uploaded_at'       => current_time( 'mysql' ),
            'is_deleted'        => 0,
            'author_context'   => $author_context,
            'visibility_scope' =>
                ( isset($_POST['visibility_scope']) && $_POST['visibility_scope'] === 'private' )
                    ? 'private'
                    : 'shared',            
        ], [ '%s','%d','%s','%s','%s','%s','%s','%d','%d','%d','%d','%s','%d' ] );

        $id  = (int) $wpdb->insert_id;
        $fmt = $this->dtfmt();

        wp_send_json_success( [
            'file' => [
                'id'                => $id,
                'note_name'         => $note,
                'original_name'     => $orig,
                'file_name'         => $dest,
                'mime_type'         => $mime,
                'file_size'         => (int) filesize( $target ),
                'is_member_visible' => $member_visible ? 1 : 0,
                'uploaded_by'       => get_current_user_id(),
                'uploaded_by_name'  => $this->author_name( get_current_user_id() ),
                'uploaded_at'       => date_i18n( $fmt, current_time( 'timestamp' ) ),
            ]
        ] );
    }

    /* ------------------------ AJAX: Toggle private ------------------------ */
    public function ajax_toggle_file_visibility_scope() {
        check_ajax_referer( 'uls_member_files', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( ['message' => 'Unauthorized'], 401 );
        }

        $id      = (int) ($_POST['id'] ?? 0);
        $scope   = sanitize_key($_POST['visibility_scope'] ?? 'shared');
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
    
    /* ------------------------ AJAX: Toggle visibility ------------------------ */

    public function ajax_toggle_member_visibility() {

        check_ajax_referer( 'uls_member_files', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
        }

        global $wpdb;
        $table = $this->table;

        $id    = (int) ( $_POST['id'] ?? 0 );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $note  = $this->normalize_note_name( $_POST['note_name'] ?? '' );

        if ( ! $id || ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Bad request' ], 400 );
        }

        if ( ! $this->can_edit( get_current_user_id(), $email, $note ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        // 🔎 Load the file row once
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, uploaded_by, is_member_visible
                FROM `{$table}`
                WHERE id = %d AND member_email = %s AND is_deleted = 0
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

        // ✅ Provider-facing privacy (OWNER ONLY)
        if ( isset( $_POST['visibility_scope'] ) ) {

            // Only the uploader may change provider privacy
            if ( (int) $row['uploaded_by'] !== $current_user_id ) {
                wp_send_json_error(
                    [ 'message' => 'Only the uploader can change file privacy' ],
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
            'id' => $id,
            'is_member_visible' => isset( $_POST['is_member_visible'] )
                ? (int) $_POST['is_member_visible']
                : (int) $row['is_member_visible'],
            'visibility_scope' => $_POST['visibility_scope'] ?? null,
        ] );
    }

    /* ------------------------ AJAX: Delete ------------------------ */

    public function ajax_delete_member_file() {
        check_ajax_referer( 'uls_member_files', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );

        $id    = (int) ( $_POST['id'] ?? 0 );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $note  = $this->normalize_note_name( $_POST['note_name'] ?? '' );

        if ( ! $id || ! is_email( $email ) ) wp_send_json_error( [ 'message' => 'Bad request' ], 400 );
        if ( ! $this->can_edit( get_current_user_id(), $email, $note ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb; $table = $this->table;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE `id` = %d AND `member_email` = %s AND `is_deleted` = 0 LIMIT 1", $id, $email
        ), ARRAY_A );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Not found' ], 404 );

        // Soft delete + remove physical file + Media Library cleanup
        $wpdb->update( $table, [
            'is_deleted' => 1,
            'deleted_by' => get_current_user_id(),
            'deleted_at' => current_time( 'mysql' ),
        ], [ 'id' => $id ], [ '%d', '%d', '%s' ], [ '%d' ] );

        if ( ! empty( $row['file_path'] ) && file_exists( $row['file_path'] ) ) @unlink( $row['file_path'] );
        if ( ! empty( $row['attachment_id'] ) ) wp_delete_attachment( (int) $row['attachment_id'], true );

        wp_send_json_success( [ 'id' => $id ] );
    }

    /* ------------------------ AJAX: Controlled Download ------------------------ */

    public function ajax_download_member_file() {
        check_ajax_referer( 'uls_member_files', 'nonce' );
        if ( ! is_user_logged_in() ) wp_die( 'Unauthorized', 'Unauthorized', [ 'response' => 401 ] );

        $id = (int) ( $_GET['id'] ?? 0 );
        if ( ! $id ) wp_die( 'Bad request', 'Bad request', [ 'response' => 400 ] );

        global $wpdb; $table = $this->table;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE `id` = %d AND `is_deleted` = 0 LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $row ) wp_die( 'Not found', 'Not found', [ 'response' => 404 ] );

        $member_email = $row['member_email'];
        $note         = $row['note_name'];

        // If the current user is the member, require is_member_visible=1
        $current = wp_get_current_user();
        if ( $current && strtolower( $current->user_email ) === strtolower( $member_email ) ) {
            if ( ! (int) $row['is_member_visible'] ) wp_die( 'Forbidden', 'Forbidden', [ 'response' => 403 ] );
        }

        if ( ! $this->can_download( get_current_user_id(), $member_email, $note, $row ) ) {
            wp_die( 'Forbidden', 'Forbidden', [ 'response' => 403 ] );
        }

        $path = $row['file_path'];
        if ( empty( $path ) || ! file_exists( $path ) ) wp_die( 'File missing', 'Not found', [ 'response' => 404 ] );

        $mime = $row['mime_type'] ?: 'application/octet-stream';
        @set_time_limit( 0 );
        nocache_headers();
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        header( 'Content-Disposition: attachment; filename="' . basename( $row['original_name'] ?: $row['file_name'] ) . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $path );
        exit;
    }

    private function is_viewable_mime($mime) {
        return in_array($mime, [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'text/plain',
            'text/csv',
        ], true);
    }

    public function ajax_view_file() {
        check_ajax_referer('uls_member_files', 'nonce');

        if (!is_user_logged_in()) {
            wp_die('Unauthorized', 401);
        }

        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            wp_die('Invalid file', 400);
        }

        global $wpdb;
        $table = $this->table;

        $file = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT file_path, mime_type, original_name
                FROM {$table}
                WHERE id = %d AND is_deleted = 0",
                $id
            ),
            ARRAY_A
        );

        if (!$file || !is_file($file['file_path'])) {
            wp_die('File not found', 404);
        }

        nocache_headers();
        header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($file['file_path']));
        header(
            'Content-Disposition: inline; filename="' .
            basename($file['original_name']) .
            '"'
        );

        readfile($file['file_path']);
        exit;
    }    

    

    /* ------------------------ Shortcode: Member View & Upload ------------------------ */
    public function shortcode_member_file_upload( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'note_name' => '',          // Optional category
                'label'     => 'Upload a file',
                'help'      => 'Files uploaded here are visible only to your provider.',
            ],
            $atts,
            'uls_member_file_upload'
        );

        $current_user = wp_get_current_user();
        if ( ! $current_user || ! is_email( $current_user->user_email ) ) {
            return '';
        }

        ob_start();
        ?>
        <div class="uls-member-upload"
            data-email="<?php echo esc_attr( $current_user->user_email ); ?>"
            data-note-name="<?php echo esc_attr( $atts['note_name'] ); ?>">

            <label class="uls-member-upload-label">
                <?php echo esc_html( $atts['label'] ); ?>
            </label>

            <input type="file" class="uls-file-input" />

            <button type="button" class="uls-file-upload">
                Upload
            </button>

            <div class="uls-upload-help">
                <?php echo esc_html( $atts['help'] ); ?>
            </div>

            <div class="uls-files-status"></div>
        </div>
        <?php
        return ob_get_clean();
    }
    public function shortcode_member_files( $atts ) {
        if ( ! is_user_logged_in() ) return '';
        $atts = shortcode_atts( [
            'note_name' => '',
            'all'       => 0,
            'max'       => 200,
            'show_note' => 0,
        ], $atts, 'uls_member_files' );

        $email = wp_get_current_user()->user_email;

        global $wpdb; $table = $this->table;
        $users_table = $wpdb->users;

        $sql = "
            SELECT
                f.id,
                f.note_name,
                f.original_name,
                f.mime_type,
                f.file_size,
                f.uploaded_at,
                f.uploaded_by,
                COALESCE(u.display_name, u.user_login) AS uploaded_by_name
            FROM `{$table}` f
            LEFT JOIN `{$users_table}` u
                ON u.ID = f.uploaded_by
            WHERE
                f.member_email = %s
                AND f.is_deleted = 0
                AND f.is_member_visible = 1
        ";
        $params = [ $email ];


        if ( ! (int) $atts['all'] && $atts['note_name'] !== '' ) {
            $sql    .= " AND `note_name` = %s";
            $params[] = $this->normalize_note_name( $atts['note_name'] );
        }

        $sql .= " ORDER BY `uploaded_at` DESC LIMIT " . (int) $atts['max'];
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        if ( empty( $rows ) ) return '<div class="uls-member-files">No files available.</div>';

        $fmt = $this->dtfmt();

        $ajax_base = admin_url( 'admin-ajax.php' );
        $nonce     = wp_create_nonce( 'uls_member_files' );

        ob_start(); ?>
<div class="uls-member-files">
  <table class="uls-files-table">
    <thead>
      <tr>
        <?php if ( (int) $atts['show_note'] ) : ?><th>Category</th><?php endif; ?>
        <th style="width: 40px">Actions</th>
        <th>File</th>
        <th>Uploaded By</th>
        <th>Type</th>
        <th>Size</th>
        <th>Uploaded</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ( $rows as $r ) : ?>
      <tr>
        <?php if ( (int) $atts['show_note'] ) : ?><td><?php echo esc_html( $r['note_name'] ); ?></td><?php endif; ?>
        <td  class="uls-file-actions">
        <a href="#"
            class="uls-file-view"
            data-id="<?php echo (int) $r['id']; ?>"
            title="View file">
            👁️
        </a>


        <a href="<?php echo esc_url(
            $ajax_base . '?action=uls_download_member_file'
            . '&nonce=' . $nonce
            . '&id=' . (int) $r['id']
        ); ?>"
            title="Download file">
            ⬇️
        </a>
        </td>

        <td>
        <a href="<?php echo esc_url(
            $ajax_base . '?action=uls_download_member_file'
            . '&nonce=' . $nonce
            . '&id=' . (int) $r['id']
        ); ?>">
            <?php echo esc_html( $r['original_name'] ); ?>
        </a>
        </td>
        <td>
        <?php
            echo esc_html(
                $r['uploaded_by_name'] !== ''
                    ? $r['uploaded_by_name']
                    : '—'
            );
        ?>
        </td>

        <td><?php echo esc_html( $r['mime_type'] ); ?></td>
        <td><?php echo esc_html( size_format( (int) $r['file_size'] ) ); ?></td>
        <td><?php echo esc_html( date_i18n( $fmt, strtotime( $r['uploaded_at'] ) ) ); ?></td>
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



    add_action(
    'wp_ajax_uls_toggle_file_visibility_scope',
    function () {
        ULS_Member_Files_Module::instance()
        ->ajax_toggle_file_visibility_scope();
    }



);


ULS_Member_Files_Module::instance();