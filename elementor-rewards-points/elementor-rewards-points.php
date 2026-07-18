<?php
/*
Plugin Name: Elementor Section Rewards Points
Description: Adds reward points once per day based on Elementor container/element attributes.
Version: 1.1
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ERP_META_KEY', 'reward_points_balance' );

add_action( 'wp_enqueue_scripts', function () {
    if ( is_user_logged_in() ) {
        wp_enqueue_script(
            'erp-section-rewards',
            plugin_dir_url( __FILE__ ) . 'section-rewards.js',
            [],
            '1.1',
            true
        );

        wp_localize_script( 'erp-section-rewards', 'ERP', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'erp_nonce' ),
        ] );
    }
} );

add_action( 'wp_ajax_erp_award_points', function () {

    check_ajax_referer( 'erp_nonce', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error();
    }

    $reward_key = sanitize_text_field( $_POST['reward_key'] ?? '' );
    $points     = intval( $_POST['points'] ?? 0 );

    if ( ! $reward_key || $points <= 0 ) {
        wp_send_json_error();
    }

    /*
     * Reusable per-section meta key
     * Stores the LAST awarded timestamp
     */
    $meta_key = 'erp_' . $reward_key;

    $last_awarded = (int) get_user_meta( $user_id, $meta_key, true );

    $today    = wp_date( 'Y-m-d' );
    $last_day = $last_awarded
        ? wp_date( 'Y-m-d', $last_awarded )
        : null;

    // Already awarded today → block
    if ( $last_day === $today ) {
        wp_send_json_success( [ 'status' => 'duplicate' ] );
    }

    // Increment balance
    $balance = (int) get_user_meta( $user_id, ERP_META_KEY, true );
    update_user_meta( $user_id, ERP_META_KEY, $balance + $points );

    // Update reusable award timestamp
    update_user_meta( $user_id, $meta_key, time() );

    wp_send_json_success( [ 'status' => 'awarded' ] );
});