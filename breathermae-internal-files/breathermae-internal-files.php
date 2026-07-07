<?php
/**
 * Plugin Name: Breathermae Internal Files
 * Plugin URI: https://github.com/jprocasky/breathermae
 * Description: Internal file/document library management for Breathermae with context-based organization, WP Fusion tag-controlled admin access, shortcodes for Elementor, multi-file entries (graphic, internal/sharable files & videos), modal descriptions. Follows clean shortcode-driven style of user-monitor-list and uls-files modules.
 * Version: 1.0.0
 * Author: Jeff Procasky / Breathermae
 * Text Domain: breathermae-internal-files
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'BreatherMae_Internal_Files' ) ) :

class BreatherMae_Internal_Files {

    const DB_VERSION = '1.0.0';
    private static $instance = null;
    private $table = 'breathermae_internal_files';
    private $nonce_action = 'breathermae_internal_files';

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'maybe_upgrade_schema' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX actions
        add_action( 'wp_ajax_bmif_list_files', [ $this, 'ajax_list_files' ] );
        add_action( 'wp_ajax_nopriv_bmif_list_files', [ $this, 'ajax_list_files' ] ); // allow if page gated
        add_action( 'wp_ajax_bmif_upload', [ $this, 'ajax_upload' ] );
        add_action( 'wp_ajax_bmif_update', [ $this, 'ajax_update' ] );
        add_action( 'wp_ajax_bmif_delete', [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_bmif_get_row', [ $this, 'ajax_get_row' ] );
        add_action( 'wp_ajax_bmif_download', [ $this, 'ajax_download' ] );

        // Shortcodes
        add_shortcode( 'breathermae_internal_files', [ $this, 'shortcode_list' ] );
        add_shortcode( 'breathermae_internal_file_form', [ $this, 'shortcode_form' ] );
    }

    /* ------------------------ Helpers ------------------------ */

    private function user_has_wpf_tag( $user_id, $tag ) {
        if ( ! $user_id || empty( $tag ) ) return false;
        if ( ! function_exists( 'wpf_get_tags' ) && ! function_exists( 'wp_fusion' ) ) {
            // Fallback: allow if no WPF (dev mode) or check usermeta zoho_tags if present
            $zoho = get_user_meta( $user_id, 'zoho_tags', true );
            if ( is_array( $zoho ) ) {
                return in_array( strtolower( $tag ), array_map( 'strtolower', $zoho ), true );
            }
            $multi = get_user_meta( $user_id, 'wpf_tags', true ); // common multi tag storage
            if ( is_array( $multi ) ) {
                return in_array( strtolower( $tag ), array_map( 'strtolower', $multi ), true );
            }
            return true; // dev fallback
        }
        $tags = [];
        if ( function_exists( 'wpf_get_tags' ) ) {
            $tags = wpf_get_tags( $user_id );
        } elseif ( function_exists( 'wp_fusion' ) && method_exists( wp_fusion()->user, 'get_tags' ) ) {
            $tags = wp_fusion()->user->get_tags( $user_id );
        }
        if ( ! is_array( $tags ) ) $tags = [];
        $tags = array_map( 'strtolower', $tags );
        return in_array( strtolower( trim( $tag ) ), $tags, true );
    }

    private function can_admin( $user_id, $admin_tag ) {
        if ( empty( $admin_tag ) ) return is_user_logged_in(); // if no tag specified, any logged in can admin
        return $this->user_has_wpf_tag( $user_id, $admin_tag );
    }

    private function can_view( $user_id, $context ) {
        // Default: logged in + page usually WPF gated by context tag. Extend via filter.
        $can = is_user_logged_in();
        return (bool) apply_filters( 'bmif_can_view', $can, $user_id, $context );
    }

    private function normalize_context( $ctx ) {
        $ctx = sanitize_key( (string) $ctx );
        return substr( $ctx, 0, 50 );
    }

    private function dtfmt() {
        return trim( sprintf( '%s %s', get_option( 'date_format', 'M j, Y' ), get_option( 'time_format', 'g:i a' ) ) );
    }

    private function get_file_ext( $attachment_id ) {
        if ( ! $attachment_id ) return '';
        $file = get_attached_file( $attachment_id );
        return $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
    }

    private function get_file_icon( $attachment_id, $is_video = false, $url = '' ) {
        if ( $is_video || ! empty( $url ) ) {
            return '<span class="bmif-icon bmif-video-icon" title="Video">🎥</span>';
        }
        if ( ! $attachment_id ) return '<span class="bmif-icon bmif-empty">—</span>';
        $ext = $this->get_file_ext( $attachment_id );
        $icons = [
            'pdf'  => '📕',
            'doc'  => '📘', 'docx' => '📘',
            'xls'  => '📗', 'xlsx' => '📗',
            'ppt'  => '📙', 'pptx' => '📙',
            'jpg'  => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'webp' => '🖼️',
            'txt'  => '📄', 'csv' => '📊',
        ];
        $icon = isset( $icons[$ext] ) ? $icons[$ext] : '📄';
        return '<span class="bmif-icon bmif-file-icon" data-ext="' . esc_attr( $ext ) . '" title="' . esc_attr( strtoupper( $ext ) ) . '">' . $icon . '</span>';
    }

    private function get_attachment_url( $id ) {
        return $id ? wp_get_attachment_url( $id ) : '';
    }

    private function create_attachment_from_upload( $file_arr, $title = '' ) {
        if ( empty( $file_arr ) || $file_arr['error'] !== UPLOAD_ERR_OK ) return 0;

        $max = (int) apply_filters( 'bmif_max_file_bytes', 25 * 1024 * 1024 ); // 25MB default for docs
        if ( $file_arr['size'] > $max ) return new WP_Error( 'too_large', 'File too large' );

        $tmp = $file_arr['tmp_name'];
        $name = sanitize_file_name( $file_arr['name'] );

        // Strict check
        $checked = wp_check_filetype_and_ext( $tmp, $name );
        if ( empty( $checked['ext'] ) ) {
            // fallback basic
            $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
            $allowed = apply_filters( 'bmif_allowed_exts', ['pdf','doc','docx','ppt','pptx','xls','xlsx','jpg','jpeg','png','webp'] );
            if ( ! in_array( $ext, $allowed, true ) ) return new WP_Error( 'invalid_type', 'File type not allowed' );
        }

        $filetype = wp_check_filetype( $name );
        $mime = ! empty( $checked['type'] ) ? $checked['type'] : ( $filetype['type'] ?: 'application/octet-stream' );

        $upload = wp_handle_upload( $file_arr, [ 'test_form' => false ] );
        if ( isset( $upload['error'] ) ) return new WP_Error( 'upload_error', $upload['error'] );

        $file_path = $upload['file'];
        $file_url  = $upload['url'];

        $attachment = [
            'post_mime_type' => $mime,
            'post_title'     => $title ?: pathinfo( $name, PATHINFO_FILENAME ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => $file_url,
        ];

        $attach_id = wp_insert_attachment( $attachment, $file_path );
        if ( is_wp_error( $attach_id ) ) return $attach_id;

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata( $attach_id, $file_path );
        wp_update_attachment_metadata( $attach_id, $meta );

        return (int) $attach_id;
    }

    /* ------------------------ Assets ------------------------ */

    public function enqueue_assets() {
        wp_register_style(
            'bmif-css',
            plugins_url( 'internal-files.css', __FILE__ ),
            [],
            self::DB_VERSION
        );
        wp_enqueue_style( 'bmif-css' );

        wp_register_script(
            'bmif-js',
            plugins_url( 'internal-files.js', __FILE__ ),
            [ 'jquery' ],
            self::DB_VERSION,
            true
        );

        wp_localize_script( 'bmif-js', 'BMIF', [
            'ajaxurl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( $this->nonce_action ),
            'listAction'   => 'bmif_list_files',
            'uploadAction' => 'bmif_upload',
            'updateAction' => 'bmif_update',
            'deleteAction' => 'bmif_delete',
            'getRowAction' => 'bmif_get_row',
            'downloadAction' => 'bmif_download',
            'maxGraphic'   => (int) apply_filters( 'bmif_max_graphic_bytes', 2 * 1024 * 1024 ),
            'maxDoc'       => (int) apply_filters( 'bmif_max_file_bytes', 25 * 1024 * 1024 ),
            'allowedGraphic' => apply_filters( 'bmif_allowed_graphic', ['jpg','jpeg','png','webp'] ),
            'allowedDocs'  => apply_filters( 'bmif_allowed_exts', ['pdf','doc','docx','ppt','pptx','xls','xlsx'] ),
        ] );

        wp_enqueue_script( 'bmif-js' );
    }

    /* ------------------------ DB ------------------------ */

    public function maybe_upgrade_schema() {
        $opt = 'bmif_db_version';
        $installed = get_option( $opt, '0.0.0' );
        if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) return;

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table = $this->table;

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `context` VARCHAR(50) NOT NULL DEFAULT '',
            `short_desc` VARCHAR(255) NOT NULL DEFAULT '',
            `long_desc` LONGTEXT NULL,
            `graphic_attachment_id` BIGINT UNSIGNED NULL,
            `internal_file_attachment_id` BIGINT UNSIGNED NULL,
            `internal_video_url` TEXT NULL,
            `sharable_file_attachment_id` BIGINT UNSIGNED NULL,
            `sharable_video_url` TEXT NULL,
            `uploaded_by` BIGINT UNSIGNED NOT NULL,
            `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_context` (`context`),
            KEY `idx_uploaded_at` (`uploaded_at`),
            KEY `idx_is_deleted` (`is_deleted`)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( $opt, self::DB_VERSION );
    }

    /* ------------------------ AJAX: List ------------------------ */

    public function ajax_list_files() {
        check_ajax_referer( $this->nonce_action, 'nonce' );

        $context = $this->normalize_context( $_POST['context'] ?? '' );
        $current_user_id = get_current_user_id();

        if ( ! $this->can_view( $current_user_id, $context ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        global $wpdb;
        $table = $this->table;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE `context` = %s AND `is_deleted` = 0 ORDER BY `updated_at` DESC, `uploaded_at` DESC LIMIT 500",
                $context
            ),
            ARRAY_A
        );

        $out = [];
        $fmt = $this->dtfmt();

        foreach ( (array) $rows as $r ) {
            $out[] = [
                'id' => (int) $r['id'],
                'context' => $r['context'],
                'short_desc' => $r['short_desc'],
                'long_desc' => $r['long_desc'],
                'graphic_url' => $this->get_attachment_url( $r['graphic_attachment_id'] ),
                'graphic_id' => (int) $r['graphic_attachment_id'],
                'internal_file_url' => $this->get_attachment_url( $r['internal_file_attachment_id'] ),
                'internal_file_id' => (int) $r['internal_file_attachment_id'],
                'internal_file_ext' => $this->get_file_ext( $r['internal_file_attachment_id'] ),
                'internal_video_url' => $r['internal_video_url'],
                'sharable_file_url' => $this->get_attachment_url( $r['sharable_file_attachment_id'] ),
                'sharable_file_id' => (int) $r['sharable_file_attachment_id'],
                'sharable_file_ext' => $this->get_file_ext( $r['sharable_file_attachment_id'] ),
                'sharable_video_url' => $r['sharable_video_url'],
                'uploaded_by' => (int) $r['uploaded_by'],
                'uploaded_at' => date_i18n( $fmt, strtotime( $r['uploaded_at'] ) ),
                'updated_at' => $r['updated_at'] ? date_i18n( $fmt, strtotime( $r['updated_at'] ) ) : '',
            ];
        }

        wp_send_json_success( [ 'files' => $out, 'context' => $context ] );
    }

    /* ------------------------ AJAX: Get single row for edit ------------------------ */

    public function ajax_get_row() {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );

        $id = (int) ( $_POST['id'] ?? 0 );
        $context = $this->normalize_context( $_POST['context'] ?? '' );
        $admin_tag = sanitize_text_field( wp_unslash( $_POST['admin_tag'] ?? '' ) );

        if ( ! $id ) wp_send_json_error( [ 'message' => 'Invalid ID' ], 400 );
        if ( ! $this->can_admin( get_current_user_id(), $admin_tag ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$this->table}` WHERE id = %d AND context = %s AND is_deleted = 0", $id, $context ),
            ARRAY_A
        );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Not found' ], 404 );

        wp_send_json_success( $row );
    }

    /* ------------------------ AJAX: Upload (new) ------------------------ */

    public function ajax_upload() {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );

        $context = $this->normalize_context( $_POST['context'] ?? '' );
        $admin_tag = sanitize_text_field( wp_unslash( $_POST['admin_tag'] ?? '' ) );
        $current_user_id = get_current_user_id();

        if ( ! $this->can_admin( $current_user_id, $admin_tag ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden - admin tag required' ], 403 );
        }

        $short_desc = sanitize_text_field( wp_unslash( $_POST['short_desc'] ?? '' ) );
        $long_desc  = wp_kses_post( wp_unslash( $_POST['long_desc'] ?? '' ) );
        $internal_video = esc_url_raw( wp_unslash( $_POST['internal_video'] ?? '' ) );
        $sharable_video = esc_url_raw( wp_unslash( $_POST['sharable_video'] ?? '' ) );

        if ( empty( $short_desc ) ) wp_send_json_error( [ 'message' => 'Short description is required' ], 400 );

        global $wpdb;
        $table = $this->table;

        // Handle file uploads
        $graphic_id = 0;
        if ( ! empty( $_FILES['graphic'] ) && $_FILES['graphic']['error'] === UPLOAD_ERR_OK ) {
            $res = $this->create_attachment_from_upload( $_FILES['graphic'], 'Internal Graphic - ' . $short_desc );
            if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ], 400 );
            $graphic_id = $res;
        }

        $internal_file_id = 0;
        if ( ! empty( $_FILES['internal_file'] ) && $_FILES['internal_file']['error'] === UPLOAD_ERR_OK ) {
            $res = $this->create_attachment_from_upload( $_FILES['internal_file'], 'Internal File - ' . $short_desc );
            if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ], 400 );
            $internal_file_id = $res;
        }

        $sharable_file_id = 0;
        if ( ! empty( $_FILES['sharable_file'] ) && $_FILES['sharable_file']['error'] === UPLOAD_ERR_OK ) {
            $res = $this->create_attachment_from_upload( $_FILES['sharable_file'], 'Sharable File - ' . $short_desc );
            if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ], 400 );
            $sharable_file_id = $res;
        }

        $wpdb->insert( $table, [
            'context' => $context,
            'short_desc' => $short_desc,
            'long_desc' => $long_desc,
            'graphic_attachment_id' => $graphic_id ?: null,
            'internal_file_attachment_id' => $internal_file_id ?: null,
            'internal_video_url' => $internal_video,
            'sharable_file_attachment_id' => $sharable_file_id ?: null,
            'sharable_video_url' => $sharable_video,
            'uploaded_by' => $current_user_id,
            'uploaded_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
            'is_deleted' => 0,
        ], [ '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%d' ] );

        $new_id = (int) $wpdb->insert_id;

        wp_send_json_success( [ 'id' => $new_id, 'message' => 'File entry created successfully' ] );
    }

    /* ------------------------ AJAX: Update ------------------------ */

    public function ajax_update() {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );

        $id = (int) ( $_POST['id'] ?? 0 );
        $context = $this->normalize_context( $_POST['context'] ?? '' );
        $admin_tag = sanitize_text_field( wp_unslash( $_POST['admin_tag'] ?? '' ) );
        $current_user_id = get_current_user_id();

        if ( ! $id ) wp_send_json_error( [ 'message' => 'Invalid ID' ], 400 );
        if ( ! $this->can_admin( $current_user_id, $admin_tag ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb;
        $table = $this->table;

        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND context = %s AND is_deleted = 0", $id, $context ), ARRAY_A );
        if ( ! $existing ) wp_send_json_error( [ 'message' => 'Entry not found' ], 404 );

        $short_desc = sanitize_text_field( wp_unslash( $_POST['short_desc'] ?? $existing['short_desc'] ) );
        $long_desc  = wp_kses_post( wp_unslash( $_POST['long_desc'] ?? $existing['long_desc'] ) );
        $internal_video = esc_url_raw( wp_unslash( $_POST['internal_video'] ?? $existing['internal_video_url'] ) );
        $sharable_video = esc_url_raw( wp_unslash( $_POST['sharable_video'] ?? $existing['sharable_video_url'] ) );

        // Handle replacements only if new file uploaded
        $graphic_id = (int) $existing['graphic_attachment_id'];
        if ( ! empty( $_FILES['graphic'] ) && $_FILES['graphic']['error'] === UPLOAD_ERR_OK ) {
            if ( $graphic_id ) wp_delete_attachment( $graphic_id, true );
            $res = $this->create_attachment_from_upload( $_FILES['graphic'], 'Internal Graphic - ' . $short_desc );
            if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ], 400 );
            $graphic_id = $res;
        }

        $internal_file_id = (int) $existing['internal_file_attachment_id'];
        if ( ! empty( $_FILES['internal_file'] ) && $_FILES['internal_file']['error'] === UPLOAD_ERR_OK ) {
            if ( $internal_file_id ) wp_delete_attachment( $internal_file_id, true );
            $res = $this->create_attachment_from_upload( $_FILES['internal_file'], 'Internal File - ' . $short_desc );
            if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ], 400 );
            $internal_file_id = $res;
        }

        $sharable_file_id = (int) $existing['sharable_file_attachment_id'];
        if ( ! empty( $_FILES['sharable_file'] ) && $_FILES['sharable_file']['error'] === UPLOAD_ERR_OK ) {
            if ( $sharable_file_id ) wp_delete_attachment( $sharable_file_id, true );
            $res = $this->create_attachment_from_upload( $_FILES['sharable_file'], 'Sharable File - ' . $short_desc );
            if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ], 400 );
            $sharable_file_id = $res;
        }

        $wpdb->update( $table, [
            'short_desc' => $short_desc,
            'long_desc' => $long_desc,
            'graphic_attachment_id' => $graphic_id ?: null,
            'internal_file_attachment_id' => $internal_file_id ?: null,
            'internal_video_url' => $internal_video,
            'sharable_file_attachment_id' => $sharable_file_id ?: null,
            'sharable_video_url' => $sharable_video,
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $id ], [ '%s','%s','%d','%d','%s','%d','%s','%s' ], [ '%d' ] );

        wp_send_json_success( [ 'id' => $id, 'message' => 'Entry updated successfully' ] );
    }

    /* ------------------------ AJAX: Delete ------------------------ */

    public function ajax_delete() {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );

        $id = (int) ( $_POST['id'] ?? 0 );
        $context = $this->normalize_context( $_POST['context'] ?? '' );
        $admin_tag = sanitize_text_field( wp_unslash( $_POST['admin_tag'] ?? '' ) );

        if ( ! $id ) wp_send_json_error( [ 'message' => 'Invalid ID' ], 400 );
        if ( ! $this->can_admin( get_current_user_id(), $admin_tag ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );

        global $wpdb;
        $table = $this->table;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND context = %s", $id, $context ), ARRAY_A );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Not found' ], 404 );

        // Soft delete + cleanup attachments
        $wpdb->update( $table, [ 'is_deleted' => 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );

        $attach_ids = array_filter( [
            $row['graphic_attachment_id'],
            $row['internal_file_attachment_id'],
            $row['sharable_file_attachment_id'],
        ] );
        foreach ( $attach_ids as $aid ) {
            if ( $aid ) wp_delete_attachment( (int) $aid, true );
        }

        wp_send_json_success( [ 'id' => $id, 'message' => 'Entry deleted' ] );
    }

    /* ------------------------ AJAX: Controlled Download (optional security) ------------------------ */

    public function ajax_download() {
        check_ajax_referer( $this->nonce_action, 'nonce' );
        if ( ! is_user_logged_in() ) wp_die( 'Unauthorized', 401 );

        $id = (int) ( $_GET['id'] ?? 0 );
        $type = sanitize_key( $_GET['type'] ?? '' ); // graphic, internal_file, sharable_file
        if ( ! $id || ! in_array( $type, [ 'graphic', 'internal_file', 'sharable_file' ], true ) ) wp_die( 'Bad request', 400 );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$this->table}` WHERE id = %d AND is_deleted = 0", $id ), ARRAY_A );
        if ( ! $row ) wp_die( 'Not found', 404 );

        $attach_id = 0;
        if ( $type === 'graphic' ) $attach_id = $row['graphic_attachment_id'];
        elseif ( $type === 'internal_file' ) $attach_id = $row['internal_file_attachment_id'];
        elseif ( $type === 'sharable_file' ) $attach_id = $row['sharable_file_attachment_id'];

        if ( ! $attach_id ) wp_die( 'No file', 404 );

        $path = get_attached_file( $attach_id );
        if ( ! $path || ! file_exists( $path ) ) wp_die( 'File missing', 404 );

        $mime = get_post_mime_type( $attach_id ) ?: 'application/octet-stream';
        $filename = basename( $path );

        nocache_headers();
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $path );
        exit;
    }

    /* ------------------------ Shortcodes ------------------------ */

    public function shortcode_list( $atts ) {
        if ( ! is_user_logged_in() ) return '<p>Please log in to view internal files.</p>';

        $atts = shortcode_atts( [
            'context'   => 'GENERAL',
            'admin_tag' => '', // if provided, enables edit/delete UI for users with this tag
        ], $atts, 'breathermae_internal_files' );

        $context = $this->normalize_context( $atts['context'] );
        $admin_tag = sanitize_text_field( $atts['admin_tag'] );

        $is_admin = $this->can_admin( get_current_user_id(), $admin_tag );

        ob_start();
        ?>
        <div class="bmif-container bmif-list-container" 
             data-context="<?php echo esc_attr( $context ); ?>"
             data-admin-tag="<?php echo esc_attr( $admin_tag ); ?>"
             data-is-admin="<?php echo $is_admin ? '1' : '0'; ?>">
            <div class="bmif-header">
                <h3>Internal Files — <?php echo esc_html( strtoupper( $context ) ); ?></h3>
                <p class="bmif-note">Files available based on your access level. Click description for details.</p>
            </div>
            <table class="bmif-table">
                <thead>
                    <tr class="bmif-major-header">
                        <th colspan="2">General</th>
                        <th colspan="2">Internal</th>
                        <th colspan="2">Sharable</th>
                        <?php if ( $is_admin ) : ?><th>Actions</th><?php endif; ?>
                    </tr>
                    <tr class="bmif-minor-header">
                        <th>Description</th>
                        <th>Graphic</th>
                        <th>Files</th>
                        <th>Videos</th>
                        <th>Files</th>
                        <th>Videos</th>
                        <?php if ( $is_admin ) : ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody class="bmif-tbody">
                    <!-- Populated via JS -->
                </tbody>
            </table>
            <div class="bmif-loading" style="display:none;">Loading files...</div>
            <div class="bmif-no-results" style="display:none;">No files found for this context.</div>
        </div>

        <!-- Modal for long description -->
        <div id="bmif-modal" class="bmif-modal" style="display:none;">
            <div class="bmif-modal-content">
                <span class="bmif-modal-close">&times;</span>
                <h4 id="bmif-modal-title"></h4>
                <div id="bmif-modal-body" class="bmif-modal-body"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function shortcode_form( $atts ) {
        $atts = shortcode_atts( [
            'context'   => 'GENERAL',
            'admin_tag' => '',
        ], $atts, 'breathermae_internal_file_form' );

        $context = $this->normalize_context( $atts['context'] );
        $admin_tag = sanitize_text_field( $atts['admin_tag'] );
        $current_user_id = get_current_user_id();

        if ( ! $this->can_admin( $current_user_id, $admin_tag ) ) {
            return ''; // Hidden if not admin (Elementor WPF should also control, but this enforces)
        }

        ob_start();
        ?>
        <div class="bmif-container bmif-form-container" 
             data-context="<?php echo esc_attr( $context ); ?>"
             data-admin-tag="<?php echo esc_attr( $admin_tag ); ?>">
            <h3 id="bmif-form-title">Add New Internal File Entry</h3>
            <form id="bmif-upload-form" class="bmif-form" enctype="multipart/form-data">
                <input type="hidden" name="context" value="<?php echo esc_attr( $context ); ?>">
                <input type="hidden" name="admin_tag" value="<?php echo esc_attr( $admin_tag ); ?>">
                <input type="hidden" name="edit_id" id="bmif-edit-id" value="">

                <div class="bmif-field">
                    <label for="bmif-short-desc">Short Description <span class="required">*</span></label>
                    <input type="text" id="bmif-short-desc" name="short_desc" required maxlength="255" placeholder="e.g. Q3 Sales Playbook">
                </div>

                <div class="bmif-field">
                    <label for="bmif-long-desc">Long Description (shown in modal)</label>
                    <textarea id="bmif-long-desc" name="long_desc" rows="4" placeholder="Detailed explanation, instructions, or notes..."></textarea>
                </div>

                <!-- Graphic on its own full-width row -->
                <div class="bmif-field bmif-graphic-row">
                    <label for="bmif-graphic">Graphic (jpg, png, webp) <small>Max ~2MB</small></label>
                    <input type="file" id="bmif-graphic" name="graphic" accept=".jpg,.jpeg,.png,.webp">
                    <div id="bmif-graphic-preview" class="bmif-preview"></div>
                </div>

                <!-- Two-column layout: Internal | Sharable -->
                <div class="bmif-two-col-grid">
                    <div class="bmif-col">
                        <div class="bmif-field">
                            <label for="bmif-internal-file">Internal File (Office, PDF) <small>Max ~25MB</small></label>
                            <input type="file" id="bmif-internal-file" name="internal_file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx">
                            <div id="bmif-internal-file-preview" class="bmif-preview"></div>
                        </div>
                        <div class="bmif-field">
                            <label for="bmif-internal-video">Internal Video URL (YouTube unlisted)</label>
                            <input type="url" id="bmif-internal-video" name="internal_video" placeholder="https://youtu.be/xxxx or https://youtube.com/watch?v=xxxx">
                        </div>
                    </div>

                    <div class="bmif-col">
                        <div class="bmif-field">
                            <label for="bmif-sharable-file">Sharable File (Office, PDF) <small>Max ~25MB</small></label>
                            <input type="file" id="bmif-sharable-file" name="sharable_file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx">
                            <div id="bmif-sharable-file-preview" class="bmif-preview"></div>
                        </div>
                        <div class="bmif-field">
                            <label for="bmif-sharable-video">Sharable Video URL (YouTube unlisted)</label>
                            <input type="url" id="bmif-sharable-video" name="sharable_video" placeholder="https://youtu.be/xxxx">
                        </div>
                    </div>
                </div>

                <div class="bmif-actions">
                    <button type="submit" id="bmif-submit-btn" class="button button-primary">Upload Entry</button>
                    <button type="button" id="bmif-cancel-edit" class="button" style="display:none;">Cancel Edit</button>
                    <span class="bmif-status"></span>
                </div>
                <p class="bmif-form-note"><small>Files are stored in the WordPress Media Library. Internal items are for authorized team use only.</small></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}

BreatherMae_Internal_Files::instance();

endif; // class_exists
