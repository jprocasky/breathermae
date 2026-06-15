<?php
/**
 * Plugin Name: ULS Members Impersonation
 * Description: Companion plugin that adds impersonation shortcodes, banner, and AJAX actions, reusing the selected-user meta set by ULS Members. Clears impersonation cookies on logout/login.
 * Version: 1.2.0
 * Author: Jeff Procasky
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ULS_Members_Impersonation {
    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Shortcodes
        add_shortcode( 'uls_members_impersonate', [ $this, 'shortcode_members_impersonate' ] );
        add_shortcode( 'uls_members_end_impersonate', [ $this, 'shortcode_members_end_impersonate' ] );
        add_shortcode( 'uls_impersonation_banner', [ $this, 'shortcode_impersonation_banner' ] );

        // AJAX
        add_action( 'wp_ajax_uls_impersonate', [ $this, 'ajax_impersonate' ] );
        add_action( 'wp_ajax_uls_end_impersonation', [ $this, 'ajax_end_impersonation' ] );
        add_action( 'template_redirect', [ $this, 'maybe_scoped_impersonation' ], 1 );
        add_action( 'shutdown', [ $this, 'end_scoped_impersonation' ] );
        add_action( 'wp_body_open', [ $this, 'uls_maybe_render_scoped_impersonation_banner' ] );


        // Minimal CSS for buttons (optional)
        add_action( 'wp_head', function() {
            echo '<style>.uls-btn{display:inline-block;padding:6px 10px;border-radius:4px;text-decoration:none}.uls-btn-primary{background:#0073aa;color:#fff}.uls-btn-secondary{background:#555;color:#fff}</style>';
        } );

        // Clear cookies at lifecycle boundaries
        add_action( 'wp_logout', [ $this, 'on_logout_clear_impersonation' ] );
        add_action( 'wp_login',  [ $this, 'on_login_clear_impersonation' ], 10, 2 );
        add_action( 'init',      [ $this, 'maybe_clear_when_guest' ] ); // optional safeguard
    }

    /** Helper: get tag labels for current user via WP Fusion */
    private function get_current_user_wpf_tag_labels() {
        $uid = get_current_user_id();
        $tags = function_exists( 'wpf_get_tags' ) ? wpf_get_tags( $uid ) : [];
        $labels = [];
        foreach ( (array) $tags as $tag ) {
            if ( function_exists( 'wpf_get_tag_label' ) ) {
                $label = wpf_get_tag_label( $tag );
                if ( is_string( $label ) && $label !== '' ) { $labels[] = $label; }
            } else {
                $labels[] = is_string( $tag ) ? $tag : (string) $tag;
            }
        }
        return array_unique( $labels );
    }

public function uls_maybe_render_scoped_impersonation_banner() {

    // Only logged-in users
    if ( ! is_user_logged_in() ) {
        return;
    }

    // Detect scoped impersonation (request-based)
    if (
        empty( $_GET['uls_view_as'] ) ||
        empty( $_GET['uls_token'] )
    ) {
        return;
    }

    // Do NOT show during full impersonation (cookie-based)
    if ( ! empty( $_COOKIE['uls_impersonating'] ) ) {
        return;
    }

    $member_id = (int) $_GET['uls_view_as'];
    if ( ! $member_id ) {
        return;
    }

    $member = get_user_by( 'id', $member_id );
    if ( ! $member ) {
        return;
    }

    $member_name = $member->display_name;

    bm_log('Impersonating in-memory as ' . $member_name, [
        'member_id' => $member_id,
        'member_name' => $member_name,
    ], 'info', 'uls-impersonate' );

    // ----- Inline styles (match existing banner pattern) -----

    $style = implode( ';', [
        'display:block',
        'width:100%',
        'box-sizing:border-box',
        'background:#fff3cd',            // soft yellow
        'color:#664d03',                 // dark amber text
        'font-size:12px',
        'line-height:1.4',
        'padding:6px 10px',
        'text-align:center',
        'border-bottom:1px solid #ffecb5',
        'z-index:9999'
    ] );

    $btn_style = implode( ';', [
        'margin-left:12px',
        'background:#ffc107',
        'color:#000',
        'border:1px solid #e0a800',
        'padding:4px 10px',
        'border-radius:4px',
        'cursor:pointer',
        'font-size:12px',
        'font-weight:600'
    ] );

    ?>
    <div
        class="uls-scoped-impersonation-banner"
        style="<?php echo esc_attr( $style ); ?>"
    >
        <strong>Viewing results as:</strong>
        <?php echo esc_html( $member_name ); ?>

        <button
            type="button"
            style="<?php echo esc_attr( $btn_style ); ?>"
            onclick="window.history.back();"
            title="Return to the previous page"
        >
            ← Return
        </button>
    </div>
    <?php
}
    

        
    public function maybe_scoped_impersonation() {

        if ( ! is_user_logged_in() ) {
            return;
        }

        if (
            empty( $_GET['uls_view_as'] ) ||
            empty( $_GET['uls_token'] )
        ) {
            return;
        }

        $member_id   = (int) $_GET['uls_view_as'];
        $token       = sanitize_text_field( $_GET['uls_token'] );
        $provider_id = get_current_user_id();

        $expected = 'uls_scoped_impersonate_' . $provider_id . '_' . $member_id;

        if ( ! wp_verify_nonce( $token, $expected ) ) {
            return;
        }

        // Prevent overlap with full impersonation
        if ( $this->is_impersonating() ) {
            return;
        }

        // Save original user for this request only
        $GLOBALS['uls_scoped_orig_uid'] = $provider_id;

        // Impersonate ONLY in-memory
        wp_set_current_user( $member_id );
    }

    public function end_scoped_impersonation() {

        if ( isset( $GLOBALS['uls_scoped_orig_uid'] ) ) {
            wp_set_current_user( (int) $GLOBALS['uls_scoped_orig_uid'] );
            unset( $GLOBALS['uls_scoped_orig_uid'] );
        }
    }

    /** Helper: detect active impersonation via cookies. */
    private function is_impersonating() : bool {
        return isset( $_COOKIE['uls_impersonating'], $_COOKIE['uls_orig_uid'] )
            && $_COOKIE['uls_impersonating'] === '1'
            && ctype_digit( (string) $_COOKIE['uls_orig_uid'] )
            && (int) $_COOKIE['uls_orig_uid'] > 0
            && is_user_logged_in()
            && (int) $_COOKIE['uls_orig_uid'] !== get_current_user_id();
    }

    /** Helper: set cookie with Lax SameSite, secure/httponly. */
    private function set_cookie( $name, $value, $expires ) {
        $secure = is_ssl(); $domain = COOKIE_DOMAIN ? COOKIE_DOMAIN : ''; $path = '/';
        @setcookie( $name, $value, [
            'expires'  => $expires,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ] );
    }

    /** Force-clear impersonation cookies (helper). */
    private function clear_impersonation_cookies() {
        $past = time() - HOUR_IN_SECONDS;
        $this->set_cookie( 'uls_impersonating', '', $past );
        $this->set_cookie( 'uls_orig_uid', '', $past );
    }

    /** Logout hook: clear impersonation cookies. */
    public function on_logout_clear_impersonation() {
        $this->clear_impersonation_cookies();
    }

    /** Login hook: clear stale impersonation cookies on new login. */
    public function on_login_clear_impersonation( $user_login, $user ) {
        if ( isset( $_COOKIE['uls_impersonating'] ) || isset( $_COOKIE['uls_orig_uid'] ) ) {
            $this->clear_impersonation_cookies();
        }
    }

    /** Optional safeguard: if no one is logged in, clear any lingering cookies. */
    public function maybe_clear_when_guest() {
        if ( ! is_user_logged_in() ) {
            if ( isset( $_COOKIE['uls_impersonating'] ) || isset( $_COOKIE['uls_orig_uid'] ) ) {
                $this->clear_impersonation_cookies();
            }
        }
    }

    /** Shortcode: [uls_members_impersonate admin="ADMIN" label="Impersonate" class="" redirect=""] */
    public function shortcode_members_impersonate( $atts ) {
        if ( ! is_user_logged_in() ) { return ''; }
        $atts = shortcode_atts( [
            'admin'    => 'ADMIN',
            'label'    => 'Impersonate',
            'class'    => 'uls-btn uls-btn-primary',
            'redirect' => '',
        ], $atts, 'uls_members_impersonate' );

        $labels   = $this->get_current_user_wpf_tag_labels();
        $required = (string) $atts['admin'];
        if ( empty( $required ) || ! in_array( $required, $labels, true ) ) { return ''; }

        $uid  = get_current_user_id();
        $sid  = (int) get_user_meta( $uid, 'uls_selected_user_id', true );
        $sem  = (string) get_user_meta( $uid, 'uls_selected_email', true );

        $nonce    = wp_create_nonce( 'uls_impersonate' );
        $redirect = $atts['redirect'] !== '' ? $atts['redirect'] : ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $url = add_query_arg( [
            'action'   => 'uls_impersonate',
            'nonce'    => $nonce,
            'redirect' => rawurlencode( $redirect ),
            'require'  => rawurlencode( $required ),
        ], admin_url( 'admin-ajax.php' ) );

        if ( $sid <= 0 || empty( $sem ) ) {
            return sprintf('<a href="#" class="%s" aria-disabled="true" onclick="return false;" title="Select a member above first">%s</a>', esc_attr( $atts['class'] ), esc_html( $atts['label'] ) );
        }
        return sprintf('<a href="%s" class="%s" title="Impersonate %s">%s</a>', esc_url( $url ), esc_attr( $atts['class'] ), esc_attr( $sem ), esc_html( $atts['label'] ) );
    }

    /** Shortcode: [uls_members_end_impersonate label="Return to my account" class="" redirect=""] */
    public function shortcode_members_end_impersonate( $atts ) {
        if ( ! is_user_logged_in() ) { return ''; }
        $atts = shortcode_atts( [
            'label'    => 'Return to my account',
            'class'    => 'uls-btn uls-btn-secondary',
            'redirect' => '',
        ], $atts, 'uls_members_end_impersonate' );

        if ( ! $this->is_impersonating() ) { return ''; }
        $nonce    = wp_create_nonce( 'uls_impersonate' );
        $redirect = $atts['redirect'] !== '' ? $atts['redirect'] : ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $url = add_query_arg( [
            'action'   => 'uls_end_impersonation',
            'nonce'    => $nonce,
            'redirect' => rawurlencode( $redirect ),
        ], admin_url( 'admin-ajax.php' ) );

        return sprintf( '<a href="%s" class="%s">%s</a>', esc_url( $url ), esc_attr( $atts['class'] ), esc_html( $atts['label'] ) );
    }

    /** Shortcode: [uls_impersonation_banner bg="#d93025" color="#ffffff" text_size="11px" position="static" show_when="impersonating" padding="4px" class=""] */
    public function shortcode_impersonation_banner( $atts ) {

    if ( ! is_user_logged_in() ) {
        return '';
    }

    $atts = shortcode_atts( [
        'bg'        => '#d93025',  // Red
        'color'     => '#ffffff',  // White
        'text_size' => '11px',
        'position'  => 'static',   // or 'fixed'
        'show_when' => 'impersonating', // or 'always'
        'padding'   => '3px',
        'class'     => '',
        'button_bg' => '#ffffff',
        'button_color' => '#d93025',
    ], $atts, 'uls_impersonation_banner' );

    $imp = $this->is_impersonating();

    if ( $atts['show_when'] === 'impersonating' && ! $imp ) {
        return '';
    }

    $current      = wp_get_current_user();
    $current_name = $current && $current->exists() ? $current->display_name : '';

    $impersonated_name = '';

    if ( $imp ) {
        // Current user IS the impersonated identity
        $impersonated_name = $current_name;

        if ( isset( $_COOKIE['uls_orig_uid'] ) && ctype_digit( (string) $_COOKIE['uls_orig_uid'] ) ) {
            $orig      = get_user_by( 'id', (int) $_COOKIE['uls_orig_uid'] );
            $orig_name = $orig ? $orig->display_name : '';
            // Show "Original as Impersonated"
            $current_name = $orig_name;
        }
    }

    if ( ! $imp ) {
        $impersonated_name = '';
    }

    $label = trim(
        $imp
            ? ( $current_name . ' as ' . $impersonated_name )
            : $current_name
    );

    if ( $label === '' ) {
        return '';
    }

    $style = sprintf(
        'display:block;width:100%%;box-sizing:border-box;background:%s;color:%s;font-size:%s;line-height:1.4;padding:%s;text-align:center;%s',
        esc_attr( $atts['bg'] ),
        esc_attr( $atts['color'] ),
        esc_attr( $atts['text_size'] ),
        esc_attr( $atts['padding'] ),
        ( strtolower( $atts['position'] ) === 'fixed'
            ? 'position:fixed;top:0;left:0;right:0;z-index:9999;'
            : '' )
    );

        bm_log('Impersonating: ' . $label, [
            'label' => $label,
        ], 'info', 'uls-impersonate' );

    $class = $atts['class'] !== '' ? ' ' . esc_attr( $atts['class'] ) : '';

    $output  = '<div class="uls-impersonation-banner' . $class . '" style="' . $style . '">';
    $output .= '<span style="opacity:.95;">' . esc_html( $label ) . '</span>';

    // ✅ End‑impersonation control
    if ( $imp ) {
        $nonce = wp_create_nonce( 'uls_impersonate' );
        $url   = add_query_arg(
            [
                'action' => 'uls_end_impersonation',
                'nonce'  => $nonce,
            ],
            admin_url( 'admin-ajax.php' )
        );

        $output .= sprintf(
            ' <a href="%s" style="margin-left:12px;background:%s;color:%s;padding:2px 8px;border-radius:3px;text-decoration:none;font-weight:600;">Return to my account</a>',
            esc_url( $url ),
            esc_attr( $atts['button_bg'] ),
            esc_attr( $atts['button_color'] )
        );
    }

    $output .= '</div>';

    return $output;
}


    /** AJAX: start impersonation of the selected user. */
    public function ajax_impersonate() {
        check_ajax_referer( 'uls_impersonate', 'nonce' );
        if ( ! is_user_logged_in() ) { wp_die( 'Unauthorized', '', [ 'response' => 401 ] ); }

        $current_id   = get_current_user_id();
        $required_tag = isset( $_GET['require'] ) ? sanitize_text_field( wp_unslash( $_GET['require'] ) ) : 'ADMIN';
        $labels       = $this->get_current_user_wpf_tag_labels();
        if ( empty( $required_tag ) || ! in_array( $required_tag, $labels, true ) ) { wp_die( 'Forbidden', '', [ 'response' => 403 ] ); }

        $selected_id   = (int) get_user_meta( $current_id, 'uls_selected_user_id', true );
        $selected_user = $selected_id > 0 ? get_user_by( 'id', $selected_id ) : false;
        if ( ! $selected_user ) { wp_die( 'No selected user', '', [ 'response' => 400 ] ); }
        if ( $selected_id === $current_id ) { wp_die( 'Cannot impersonate yourself', '', [ 'response' => 400 ] ); }
        if ( in_array( 'administrator', (array) $selected_user->roles, true ) ) { wp_die( 'Impersonation blocked for administrators', '', [ 'response' => 403 ] ); }

        $expiry = time() + HOUR_IN_SECONDS;
        $this->set_cookie( 'uls_impersonating', '1', $expiry );
        $this->set_cookie( 'uls_orig_uid', (string) $current_id, $expiry );

        wp_set_current_user( $selected_id );
        wp_set_auth_cookie( $selected_id, true );

        $redirect = isset( $_GET['redirect'] ) ? wp_unslash( $_GET['redirect'] ) : home_url( '/' );
        wp_safe_redirect( esc_url_raw( $redirect ) );
        exit;
    }

    /** AJAX: end impersonation (returns to the original user stored in cookie). */
    public function ajax_end_impersonation() {
        check_ajax_referer( 'uls_impersonate', 'nonce' );
        if ( ! is_user_logged_in() ) { wp_die( 'Unauthorized', '', [ 'response' => 401 ] ); }
        if ( ! $this->is_impersonating() ) { wp_die( 'No active impersonation', '', [ 'response' => 400 ] ); }

        $orig_uid = (int) $_COOKIE['uls_orig_uid'];
        $orig     = $orig_uid > 0 ? get_user_by( 'id', $orig_uid ) : false;
        if ( ! $orig ) { wp_die( 'Original user not found', '', [ 'response' => 400 ] ); }

        $past = time() - HOUR_IN_SECONDS;
        $this->set_cookie( 'uls_impersonating', '', $past );
        $this->set_cookie( 'uls_orig_uid', '', $past );

        wp_set_current_user( $orig_uid );
        wp_set_auth_cookie( $orig_uid, true );

        $redirect = isset( $_GET['redirect'] ) ? wp_unslash( $_GET['redirect'] ) : home_url( '/' );
        wp_safe_redirect( esc_url_raw( $redirect ) );
        exit;
    }
}

ULS_Members_Impersonation::instance();
?>
