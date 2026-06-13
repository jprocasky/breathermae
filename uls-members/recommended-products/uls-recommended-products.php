<?php
/**
 * ULS Recommended Products Module
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ULS_Recommended_Products {

    const DB_VERSION = '1.0.0';
    private static $instance = null;
    private $table = 'uls_member_recommended_products';

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'maybe_upgrade_schema' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_uls_get_recommendable_products', [ $this, 'ajax_get_products' ] );
        add_action( 'wp_ajax_uls_get_assigned_products', [ $this, 'ajax_get_assigned_products' ] );
        add_action( 'wp_ajax_uls_toggle_recommended_product', [ $this, 'ajax_toggle_product' ] );

        add_shortcode( 'uls_recommended_products_picker', [ $this, 'shortcode_picker' ] );
        add_shortcode( 'uls_member_recommended_products', [ $this, 'shortcode_member_view' ] );
        add_shortcode(
            'uls_selected_recommended_products',
            [ $this, 'shortcode_selected_products' ]
        );        
        add_action(
            'wp_ajax_uls_render_selected_recommended_products',
            [ $this, 'ajax_render_selected_products' ]
        );

    }

    public function ajax_render_selected_products() {

        check_ajax_referer( 'uls_recommended_products', 'nonce' );

        $email = sanitize_email( $_POST['email'] ?? '' );
        $thumb_max = max( 8, (int) ( $_POST['thumb_max'] ?? 32 ) );

        if ( ! is_email( $email ) ) {
            wp_send_json_error();
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id
                FROM `{$this->table}`
                WHERE member_email = %s
                AND deleted = 0
                ORDER BY created_at DESC",
                $email
            )
        );

        if ( empty( $rows ) ) {
            wp_send_json_success( '<span style="text-align: center;"><em style="color: red; font-size: 0.5em;">No products selected.</em></span>' );
        }

        ob_start(); ?>
        <table class="uls-selected-products-table">
            <tbody>
            <?php foreach ( $rows as $r ) :
                $p = wc_get_product( (int) $r->product_id );
                if ( ! $p ) continue;
                $img = wp_get_attachment_image_src( $p->get_image_id(), 'thumbnail' );
            ?>
                <tr>
                    <td class="uls-thumb">
                        <?php if ( $img ) : ?>
                            <img
                                src="<?php echo esc_url( $img[0] ); ?>"
                                style="max-width:<?php echo esc_attr( $thumb_max ); ?>px;
                                    max-height:<?php echo esc_attr( $thumb_max ); ?>px;
                                    object-fit:contain;"
                                alt=""
                            />
                        <?php endif; ?>
                    </td>

                    <td class="uls-title">
                        <a href="<?php echo esc_url( get_permalink( $p->get_id() ) ); ?>"
                        target="_blank"
                        rel="noopener noreferrer">
                            <?php echo esc_html( $p->get_name() ); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php

        wp_send_json_success( ob_get_clean() );
    }

    public function shortcode_selected_products( $atts ) {

    $atts = shortcode_atts(
        [
            'thumb_max' => 32,
        ],
        $atts,
        'uls_selected_recommended_products'
    );

    $thumb_max = max( 8, (int) $atts['thumb_max'] );

    ob_start(); ?>
    <div class="uls-selected-products-wrapper"
         data-thumb-max="<?php echo esc_attr( $thumb_max ); ?>">
        <span style="text-align: center;"><em style="color: red; font-size: 0.5em;">Select a member to view recommended products.</em></span>
    </div>
    <?php
    return ob_get_clean();
    }

    public function enqueue_assets() {
        wp_register_style(
            'uls-recommended-products-css',
            plugins_url( 'uls-recommended-products.css', __FILE__ ),
            [],
            self::DB_VERSION
        );
        wp_enqueue_style( 'uls-recommended-products-css' );

        wp_register_script(
            'uls-recommended-products-js',
            plugins_url( 'uls-recommended-products.js', __FILE__ ),
            [ 'jquery' ],
            self::DB_VERSION,
            true
        );

        wp_localize_script( 'uls-recommended-products-js', 'ULS_REC_PRODUCTS', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'uls_recommended_products' ),
        ] );

        wp_enqueue_script( 'uls-recommended-products-js' );
    }

    public function maybe_upgrade_schema() {
        $opt = 'uls_recommended_products_db_version';
        $installed = get_option( $opt );
        if ( $installed === self::DB_VERSION ) return;

        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$this->table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_email VARCHAR(191) NOT NULL,
            member_user_id BIGINT UNSIGNED NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            is_member_visible TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_member_product (member_email, product_id),
            KEY idx_member_email (member_email),
            KEY idx_product_id (product_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( $opt, self::DB_VERSION );
    }

    /* ───────────────── AJAX ───────────────── */

    public function ajax_get_products() {
        check_ajax_referer( 'uls_recommended_products', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error();

        $category = sanitize_text_field( $_POST['category'] ?? '' );
        $page     = max( 1, intval( $_POST['page'] ?? 1 ) );
        $per_page = max( 1, intval( $_POST['per_page'] ?? 10 ) );

        $q = new WC_Product_Query( [
            'status'   => 'publish',
            'limit'    => $per_page,
            'page'     => $page,
            'category' => array_filter( array_map( 'trim', explode( ',', $category ) ) ),
            'orderby'  => 'menu_order',
            'order'    => 'ASC',
            'return'   => 'objects',
        ] );

        $items = [];
        foreach ( $q->get_products() as $p ) {
            $img = wp_get_attachment_image_src( $p->get_image_id(), 'thumbnail' );
            $items[] = [
                'id'    => $p->get_id(),
                'title' => $p->get_name(),
                'img'   => $img ? $img[0] : '',
                'link'  => get_permalink( $p->get_id() ),
            ];
        }

        wp_send_json_success( [
            'products' => $items,
            'page'     => $page,
            'has_more' => count( $items ) === $per_page
        ] );
    }

    public function ajax_get_assigned_products() {
        check_ajax_referer( 'uls_recommended_products', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error();

        global $wpdb;
        $email = sanitize_email( $_POST['email'] ?? '' );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id FROM `{$this->table}`
                 WHERE member_email=%s AND deleted=0",
                $email
            )
        );

        wp_send_json_success( wp_list_pluck( $rows, 'product_id' ) );
    }

    public function ajax_toggle_product() {
        check_ajax_referer( 'uls_recommended_products', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error();

        global $wpdb;
        $email = sanitize_email( $_POST['email'] ?? '' );
        $product_id = intval( $_POST['product_id'] ?? 0 );
        $checked = intval( $_POST['checked'] ?? 0 );

        if ( ! $email || ! $product_id ) wp_send_json_error();

        if ( $checked ) {
            $wpdb->replace( $this->table, [
                'member_email'    => $email,
                'member_user_id' => ( get_user_by( 'email', $email )->ID ?? null ),
                'product_id'     => $product_id,
                'is_member_visible' => 1,
                'created_by'     => get_current_user_id(),
                'deleted'        => 0,
            ] );
        } else {
            $wpdb->update(
                $this->table,
                [ 'deleted' => 1 ],
                [ 'member_email' => $email, 'product_id' => $product_id ]
            );
        }

        wp_send_json_success();
    }

    /* ───────────────── SHORTCODES ───────────────── */

    public function shortcode_picker( $atts ) {
        $a = shortcode_atts( [
            'category'    => '',
            'per_page'    => 10,
            'thumb_max'   => 32,
            'show_selected' => 1
        ], $atts );

        ob_start(); ?>
        <div class="uls-rec-wrapper"
            data-category="<?php echo esc_attr( $a['category'] ); ?>"
            data-per-page="<?php echo intval( $a['per_page'] ); ?>"
            data-thumb-max="<?php echo intval( $a['thumb_max'] ); ?>">

            <div class="uls-rec-picker"></div>

            <?php if ( intval( $a['show_selected'] ) ) : ?>
                <div class="uls-rec-selected"></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function shortcode_member_view() {
        if ( ! is_user_logged_in() ) return '';

        global $wpdb;
        $email = wp_get_current_user()->user_email;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id FROM `{$this->table}`
                 WHERE member_email=%s AND deleted=0 AND is_member_visible=1",
                $email
            )
        );

        if ( ! $rows ) return '<div style="text-align: center; color: red; font-size: 0.5em;">No recommended products.</div>';

        ob_start(); ?>
        <table class="uls-member-rec-products">
            <tbody>
            <?php foreach ( $rows as $r ) :
                $p = wc_get_product( $r->product_id );
                if ( ! $p ) continue;
                $img = wp_get_attachment_image_src( $p->get_image_id(), 'thumbnail' );
            ?>
                <tr>
                    <td><img src="<?php echo esc_url( $img[0] ?? '' ); ?>" /></td>
                    <td>
                        <a href="<?php echo esc_url( get_permalink( $p->get_id() ) ); ?>" target="_blank">
                            <?php echo esc_html( $p->get_name() ); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }
}

ULS_Recommended_Products::instance();