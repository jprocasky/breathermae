<?php
/**
 * Breathermae – Fitbit Auth Shortcodes
 *
 * Provides:
 *   [bm_fitbit_connect]
 *   [bm_fitbit_connected]
 *   [bm_fitbit_disconnect]
 *
 * Includes:
 *   - Auth URL builder with required scopes
 *   - Robust disconnect handler (POST + GET via admin-post.php)
 *   - Optional token revocation against Fitbit /oauth2/revoke
 *
 * This file is intended to be included from the main Fitbit integration plugin.
 */

if (!defined('ABSPATH')) exit;

/**
 * Helpers expected from main plugin (gracefully degrade if missing):
 *   bm_fitbit_set_access_token($user_id, $token)
 *   bm_fitbit_set_refresh_token($user_id, $token)
 *   bm_fitbit_client_id(), bm_fitbit_client_secret()
 *   bm_fitbit_table_names()
 */

/* -----------------------------------------------------------
 *  Authorize URL (Updated Scopes)
 * --------------------------------------------------------- */

/**
 * Build Fitbit OAuth2 authorize URL with required scopes.
 * Scopes used:
 *   - activity (daily summary)
 *   - heartrate (RHR, intraday HR, HRV)
 *   - sleep (sleep stages summary)
 *   - oxygen_saturation (SpO2)
 */
if (!function_exists('bm_fitbit_build_authorize_url')) {
    function bm_fitbit_build_authorize_url() {
        $client_id   = get_option('bm_fitbit_client_id');
        $redirect_uri = get_option('bm_fitbit_redirect_uri'); // your registered callback
        if (!$client_id || !$redirect_uri) return '';

        // Required scopes for the endpoints we call
        $scopes = array('activity', 'heartrate', 'sleep', 'oxygen_saturation');

        // Per-user state to mitigate CSRF
        $state = wp_generate_uuid4();
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), 'bm_fitbit_oauth_state', $state);
        }
bm2_fitbit_debug_log('[BM Fitbit] id='.get_option('bm_fitbit_client_id').' redirect='.get_option('bm_fitbit_redirect_uri'));
        $params = array(
            'response_type' => 'code',
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'scope'         => implode(' ', $scopes),
            'state'         => $state,
            'prompt'        => 'consent',
        );

        $authorize_base = 'https://www.fitbit.com/oauth2/authorize';
        return $authorize_base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

/* -----------------------------------------------------------
 *  Connect Button
 * --------------------------------------------------------- */

/**
 * [bm_fitbit_connect label="Connect Fitbit"]
 * Shows a link to Fitbit authorization if not connected; otherwise a message.
 */


// [bm_fitbit_connect label="Connect Fitbit"]
if (!function_exists('bm_fitbit_sc_connect')) {
    function bm_fitbit_sc_connect($atts = array()) {

        if (!is_user_logged_in()) {
            return '<p>Please log in to connect your Fitbit account.</p>';
        }

        $a = shortcode_atts(array(
            'label' => 'Connect Fitbit',
        ), $atts, 'bm_fitbit_connect');

        $user_id = get_current_user_id();
        $access  = get_user_meta($user_id, 'bm_fitbit_access_token', true);

        // Already connected
        if (!empty($access)) {
            return '<p>Your Fitbit account is already connected.</p>';
        }

        // Build auth URL
        $auth_url = bm_fitbit_build_authorize_url();

        if (empty($auth_url)) {
            return '<p>Fitbit app credentials are missing or invalid.</p>';
        }

        return sprintf(
            '<a class="button button-primary" href="%s">%s</a>',
            esc_url($auth_url),
            esc_html($a['label'])
        );

    }
}

// Register shortcode
add_shortcode('bm_fitbit_connect', 'bm_fitbit_sc_connect');


/* -----------------------------------------------------------
 *  Connected Indicator
 * --------------------------------------------------------- */

/**
 * [bm_fitbit_connected show_avatar="yes" show_name="yes"]
 * Shows connected status, optional avatar and display name (if previously stored).
 */
if (!function_exists('bm_fitbit_sc_connected')) {
    function bm_fitbit_sc_connected($atts = array()) {
        if (!is_user_logged_in()) return '<p>Please log in to view your Fitbit connection.</p>';

        $a = shortcode_atts(array(
            'show_avatar' => 'yes',
            'show_name'   => 'yes',
        ), $atts, 'bm_fitbit_connected');

        $user_id = get_current_user_id();
        $access  = get_user_meta($user_id, 'bm_fitbit_access_token', true);
        if (!$access) {
            return '<p>No Fitbit account connected yet.</p>';
        }

        $avatar = get_user_meta($user_id, 'bm_fitbit_avatar_url', true);
        $name   = get_user_meta($user_id, 'bm_fitbit_display_name', true);

        $html  = '<div class="bm-fitbit-connected" style="display:flex;align-items:center;gap:10px;">';
        if ($a['show_avatar'] === 'yes' && $avatar) {
            $html .= '<img src="'.esc_url($avatar).'" alt="Fitbit avatar" style="width:32px;height:32px;border-radius:50%;">';
        }
        $label = 'Fitbit account connected';
        if ($a['show_name'] === 'yes' && $name) {
            $label .= ' as ' . esc_html($name);
        }
        $html .= '<span>'.$label.'</span>';
        $html .= '</div>';

        return $html;
    }
}
add_shortcode('bm_fitbit_connected', 'bm_fitbit_sc_connected');

/* -----------------------------------------------------------
 *  Optional Token Revocation Helper
 * --------------------------------------------------------- */

/**
 * Attempt to revoke access/refresh tokens at Fitbit.
 * Safe to call even if client credentials are not present (no-op).
 */
if (!function_exists('bm_fitbit_revoke_tokens')) {
    function bm_fitbit_revoke_tokens($user_id) {
        $access  = get_user_meta($user_id, 'bm_fitbit_access_token', true);
        $refresh = get_user_meta($user_id, 'bm_fitbit_refresh_token', true);
        $cid = get_option('bm_fitbit_client_id');
        $sec = get_option('bm_fitbit_client_secret');
        if (!$cid || !$sec) return; // cannot revoke without client creds

        $auth = base64_encode($cid . ':' . $sec);
        $endpoint = 'https://api.fitbit.com/oauth2/revoke';

        foreach (array_filter([$access, $refresh]) as $tok) {
            $res = wp_remote_post($endpoint, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . $auth,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ),
                'body' => http_build_query(array('token' => $tok), '', '&'),
                'timeout' => 20,
            ));
            // Fitbit returns 200 for both revoked and already-invalid tokens.
            // We intentionally ignore the response here.
        }
    }
}

/* -----------------------------------------------------------
 *  Disconnect Handler (admin-post)
 * --------------------------------------------------------- */

/**
 * Handle disconnect via POST or GET (admin-post.php).
 * Clears tokens, cached fields, and (optionally) cursors for a fresh backfill next time.
 * Redirects back to given URL or referer with ?bm_fitbit_status=disconnected.
 */
if (!function_exists('bm_fitbit_handle_disconnect')) {
    function bm_fitbit_handle_disconnect() {
        
bm2_fitbit_debug_log('[BM Fitbit] Disconnect called. user=' . get_current_user_id() . ' GET=' . json_encode($_GET) . ' POST=' . json_encode($_POST));       
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/')));
            exit;
        }

     

        $is_get  = (isset($_GET['action']) && $_GET['action'] === 'bm_fitbit_disconnect');
        $is_post = (isset($_POST['action']) && $_POST['action'] === 'bm_fitbit_disconnect');

        $nonce_ok = false;
        if ($is_post && isset($_POST['_wpnonce'])) {
            $nonce_ok = wp_verify_nonce($_POST['_wpnonce'], 'bm_fitbit_disconnect');
        } elseif ($is_get && isset($_GET['_wpnonce'])) {
            $nonce_ok = wp_verify_nonce($_GET['_wpnonce'], 'bm_fitbit_disconnect');
        }
        if (!$nonce_ok) {
            wp_die('Invalid request (nonce).', 403);
        }

        $user_id = get_current_user_id();

        // Revoke with Fitbit (best-effort)
        if (function_exists('bm_fitbit_revoke_tokens')) {
            bm_fitbit_revoke_tokens($user_id);
        }

        // Clear tokens (use helpers if provided by main plugin)
        if (function_exists('bm_fitbit_set_access_token')) {
            bm_fitbit_set_access_token($user_id, '');
        } else {
            delete_user_meta($user_id, 'bm_fitbit_access_token');
        }
        if (function_exists('bm_fitbit_set_refresh_token')) {
            bm_fitbit_set_refresh_token($user_id, '');
        } else {
            delete_user_meta($user_id, 'bm_fitbit_refresh_token');
        }

        // Clear cached profile/info
        delete_user_meta($user_id, 'bm_fitbit_avatar_url');
        delete_user_meta($user_id, 'bm_fitbit_display_name');
        delete_user_meta($user_id, 'bm_fitbit_oauth_state');

        // Optional: reset cursors for a fresh backfill on next connect
        if (function_exists('bm_fitbit_table_names')) {
            global $wpdb; $t = bm_fitbit_table_names();
            if (!empty($t['cursors'])) {
                $wpdb->delete($t['cursors'], array('wp_user_id' => $user_id));
            }
        }


        // Figure out redirect target
        $back = '';
        if ($is_post && isset($_POST['_redirect'])) $back = $_POST['_redirect']; // raw value
        if ($is_get  && isset($_GET['_redirect']))  $back = $_GET['_redirect'];  // raw value

        // Decode if it was rawurlencoded
        if (!empty($back)) {
            $back = rawurldecode($back);
            // Sanitize after decode
            $back = esc_url_raw($back);
        }

        if (!$back) $back = wp_get_referer();
        if (!$back) $back = home_url('/');

        $back = add_query_arg(array('bm_fitbit_status' => 'disconnected'), $back);
        wp_safe_redirect($back);
        exit;

    }
}
add_action('admin_post_bm_fitbit_disconnect',        'bm_fitbit_handle_disconnect');
add_action('admin_post_nopriv_bm_fitbit_disconnect', 'bm_fitbit_handle_disconnect'); // will bounce to login if not logged in

/* -----------------------------------------------------------
 *  Disconnect Shortcode (POST + GET fallback)
 * --------------------------------------------------------- */

/**
 * [bm_fitbit_disconnect label="Disconnect Fitbit"]
 * Renders:
 *   - A POST form button to admin-post.php (nonce-protected)
 *   - A GET fallback link to admin-post.php with action + nonce
 */

// [bm_fitbit_disconnect label="Disconnect Fitbit"]
// [bm_fitbit_disconnect label="Disconnect Fitbit"]
if (!function_exists('bm_fitbit_sc_disconnect')) {
    function bm_fitbit_sc_disconnect($atts = array()) {
        if (!is_user_logged_in()) {
            return '<p>Please log in to disconnect your Fitbit account.</p>';
        }

        $a = shortcode_atts(array(
            'label' => 'Disconnect Fitbit',
        ), $atts, 'bm_fitbit_disconnect');

        $user_id = get_current_user_id();
        $access  = get_user_meta($user_id, 'bm_fitbit_access_token', true);
        if (!$access) {
            return '<p>No Fitbit account is currently connected.</p>';
        }

        $nonce    = wp_create_nonce('bm_fitbit_disconnect');
        $redirect = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

        // GET fallback link to admin-post.php

        $get_url = add_query_arg(array(
            'bm_fitbit_disconnect' => 1,
            '_wpnonce'             => $nonce,
            '_redirect'            => rawurlencode($redirect),
        ), $redirect);


        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;margin-right:12px;">
        <input type="hidden" name="action" value="bm_fitbit_disconnect" />
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
        <input type="hidden" name="_redirect" value="<?php echo esc_attr($redirect); ?>" />
        <button type="submit" class="button button-secondary"><?php echo esc_html($a['label']); ?></button>
        </form>
        <a href="<?php echo esc_url($get_url); ?>">Use fallback link</a>
        <?php
        return ob_get_clean();
    }
}



add_shortcode('bm_fitbit_disconnect', 'bm_fitbit_sc_disconnect');


// --- Front-end disconnect fallback via template_redirect (?bm_fitbit_disconnect=1)
if (!function_exists('bm_fitbit_front_disconnect')) {
    function bm_fitbit_front_disconnect() {
        // Only handle our query param
        if (empty($_GET['bm_fitbit_disconnect'])) return;

        // We want this to run on the front-end template load
        if (is_admin()) return;

        // Must be logged in
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/')));
            exit;
        }

        // Nonce check
        $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'bm_fitbit_disconnect')) {
            wp_die('Invalid request (nonce).', 403);
        }

        // Perform the same clearing work as admin-post handler
        $user_id = get_current_user_id();

        if (function_exists('bm_fitbit_revoke_tokens')) {
            bm_fitbit_revoke_tokens($user_id);
        }

        if (function_exists('bm_fitbit_set_access_token')) {
            bm_fitbit_set_access_token($user_id, '');
        } else {
            delete_user_meta($user_id, 'bm_fitbit_access_token');
        }
        if (function_exists('bm_fitbit_set_refresh_token')) {
            bm_fitbit_set_refresh_token($user_id, '');
        } else {
            delete_user_meta($user_id, 'bm_fitbit_refresh_token');
        }

        delete_user_meta($user_id, 'bm_fitbit_avatar_url');
        delete_user_meta($user_id, 'bm_fitbit_display_name');
        delete_user_meta($user_id, 'bm_fitbit_oauth_state');

        if (function_exists('bm_fitbit_table_names')) {
            global $wpdb; $t = bm_fitbit_table_names();
            if (!empty($t['cursors'])) {
                $wpdb->delete($t['cursors'], array('wp_user_id' => $user_id));
            }
        }

        // Determine redirect back target
        $back = '';
        if (isset($_GET['_redirect'])) $back = $_GET['_redirect']; // raw
        if ($back) {
            $back = rawurldecode($back);
            $back = esc_url_raw($back);
        }
        if (!$back) $back = wp_get_referer();
        if (!$back) $back = home_url('/');

        $back = add_query_arg(array('bm_fitbit_status' => 'disconnected'), $back);
        wp_safe_redirect($back);
        exit;
    }
}
add_action('template_redirect', 'bm_fitbit_front_disconnect');

// -----------------------------------------------------------------------------
// DEBUG: Token state logger (safe, read-only)
// -----------------------------------------------------------------------------
if (!function_exists('bm_fitbit_log_token_state')) {
    function bm_fitbit_log_token_state($context = 'unknown') {

        $user_id = get_current_user_id();
        if (!$user_id) {
            bm2_fitbit_debug_log('[BM FITBIT TOKEN] No user context during ' . $context);
            return;
        }

        $access_token  = get_user_meta($user_id, 'bm_fitbit_access_token', true);
        $refresh_token = get_user_meta($user_id, 'bm_fitbit_refresh_token', true);
        $expires_at    = get_user_meta($user_id, 'bm_fitbit_expires_at', true);
        $scopes        = get_user_meta($user_id, 'bm_fitbit_scopes', true);

        bm2_fitbit_debug_log('[BM FITBIT TOKEN] Context: ' . $context);
        bm2_fitbit_debug_log('[BM FITBIT TOKEN] Access token present: ' . (!empty($access_token) ? 'YES' : 'NO'));
        bm2_fitbit_debug_log('[BM FITBIT TOKEN] Refresh token present: ' . (!empty($refresh_token) ? 'YES' : 'NO'));
        bm2_fitbit_debug_log('[BM FITBIT TOKEN] Expires at (UTC): ' . ($expires_at ?: 'MISSING'));
        bm2_fitbit_debug_log('[BM FITBIT TOKEN] Scopes stored: ' . (is_array($scopes) ? implode(',', $scopes) : 'NONE'));
    }
}
