<?php
/**
 * Plugin Name: Breathermae – Fitbit Integration (v2 HR Intraday Test)
 * Description: Clean-slate integration test. Pulls Fitbit intraday HR for yesterday only.
 * Version: 0.1.0
 */


if (!defined('ABSPATH')) exit;

if (!function_exists('bm2_fitbit_debug_log')) {
    function bm2_fitbit_debug_log($message) {
        $file = WP_CONTENT_DIR . '/bm-fitbit-debug.log';
        $timestamp = gmdate('Y-m-d H:i:s');
        error_log("[{$timestamp}] {$message}\n", 3, $file);
    }
}

/////////////////////////////
// Schedule nightly Fitbit sync on activation
register_activation_hook(__FILE__, function () {

    if (!wp_next_scheduled('bm2_fitbit_nightly_sync')) {
        wp_schedule_event(
            strtotime('tomorrow 3am'),
            'daily',
            'bm2_fitbit_nightly_sync'
        );
    }
});

// Clean up on deactivation
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('bm2_fitbit_nightly_sync');
});

add_action('bm2_fitbit_nightly_sync', function () {

    global $wpdb;

    // Find all users who have connected Fitbit
    $user_ids = $wpdb->get_col(
        "
        SELECT DISTINCT user_id
        FROM {$wpdb->usermeta}
        WHERE meta_key = 'bm_fitbit_access_token'
          AND meta_value != ''
        "
    );

    if (empty($user_ids)) {
        return;
    }

    foreach ($user_ids as $user_id) {

        $user_id = (int) $user_id;

        // Safety check (handles edge cases)
        if (!bm2_fitbit_is_connected($user_id)) {
            continue;
        }

        // Run the unified v2 sync
        bm2_fitbit_run_full_sync($user_id);

        // Optional throttle to avoid burst rate limits
        sleep(1);
    }
});


add_action('template_redirect', function () {

    if (empty($_GET['bm2_test_cron'])) return;

    if (!current_user_can('manage_options')) {
        wp_die('Admins only');
    }

    do_action('bm2_fitbit_nightly_sync');

    wp_safe_redirect(home_url('/fitbit?cron=ran'));
    exit;
});

//////////////////////////////



//Admin Settings

add_action('admin_init', function () {

    register_setting(
        'bm_fitbit_settings_group',
        'bm_fitbit_client_id',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        )
    );

    register_setting(
        'bm_fitbit_settings_group',
        'bm_fitbit_client_secret',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        )
    );

    register_setting(
        'bm_fitbit_settings_group',
        'bm_fitbit_redirect_uri',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        )
    );
});

//Admin Menu

add_action('admin_menu', function () {

    add_options_page(
        'Breathermae → Fitbit',
        'Breathermae → Fitbit',
        'manage_options',
        'bm-fitbit-settings',
        'bm2_render_fitbit_settings_page'
    );
});


function bm2_render_fitbit_settings_page() {

    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>Breathermae → Fitbit</h1>

        <p>
            Enter your Fitbit OAuth app credentials below.
            These are required for users to connect their Fitbit accounts.
        </p>

        options.php
            <?php settings_fields('bm_fitbit_settings_group'); ?>

            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row">
                        <label for="bm_fitbit_client_id">Client ID</label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="bm_fitbit_client_id"
                            name="bm_fitbit_client_id"
                            class="regular-text"
                            value="<?php echo esc_attr(get_option('bm_fitbit_client_id')); ?>"
                        />
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="bm_fitbit_client_secret">Client Secret</label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="bm_fitbit_client_secret"
                            name="bm_fitbit_client_secret"
                            class="regular-text"
                            value="<?php echo esc_attr(get_option('bm_fitbit_client_secret')); ?>"
                        />
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="bm_fitbit_redirect_uri">Redirect URI</label>
                    </th>
                    <td>
                        <input
                            type="url"
                            id="bm_fitbit_redirect_uri"
                            name="bm_fitbit_redirect_uri"
                            class="regular-text code"
                            placeholder="https://yourdomain.com/fitbit-auth"
                            value="<?php echo esc_attr(get_option('bm_fitbit_redirect_uri')); ?>"
                        />
                        <p class="description">
                            This must exactly match the Callback URL set in your Fitbit developer app.
                        </p>
                    </td>
                </tr>

            </table>

            <?php submit_button('Save Fitbit Settings'); ?>
        </form>

        <hr />

        <h2>Fitbit App Notes</h2>
        <ul>
            <li>App type should be <strong>Personal</strong></li>
            <li>Required scopes:
                <code>activity heartrate sleep oxygen_saturation</code>
            </li>
            <li>Intraday heart rate is not available to third‑party apps</li>
        </ul>
    </div>
    <?php
}




/* -------------------------------------------------------------------------- */
/* OAuth-dependent API helper (copied from working code)                       */
/* -------------------------------------------------------------------------- */

function bm2_fitbit_api_request($user_id, $method, $url) {

    $access_token  = bm2_fitbit_get_access_token($user_id);
    $refresh_token = bm2_fitbit_get_refresh_token($user_id);

    if (empty($access_token)) {
        bm_log('[BM2 API] No access token | User ID: ' . $user_id, [], 'error', 'fitbit-api');
        return new WP_Error('no_token', 'Missing access token');
    }

    $response = wp_remote_request($url, array(
        'method'  => strtoupper($method),
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
            'Accept'        => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        bm_log('[BM2 API] HTTP transport error' . ' | User ID: ' . $user_id, ['error' => $response->get_error_message()], 'error', 'fitbit-api');
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);

    // ✅ Success
    if ($code === 200) {
        return $json;
    }

    // 🔁 Token expired → attempt refresh ONCE
    if ($code === 401 && !empty($refresh_token)) {
        bm_log('[BM2 API] Access token expired, refreshing', [], 'info', 'fitbit-api');

        $refresh = bm2_fitbit_refresh_access_token($user_id);
        if ($refresh) {
            // Retry once after refresh
            return bm2_fitbit_api_request($user_id, $method, $url);
        }

        bm_log('[BM2 API] Token refresh failed | User ID: ' . $user_id, [], 'error', 'fitbit-api');
        return new WP_Error('refresh_failed', 'Token refresh failed');
    }

    // 🚨 Log real Fitbit failure
    bm_log('[BM2 API] Fitbit error ' . $code . ' → ' . $body . ' | User ID: ' . $user_id, [], 'error', 'fitbit-api');

    return new WP_Error(
        'fitbit_api_error',
        'Fitbit API returned error ' . $code,
        array('status' => $code, 'body' => $body)
    );
}

function bm2_fitbit_refresh_access_token($user_id) {

    $refresh_token = bm2_fitbit_get_refresh_token($user_id);
    if (empty($refresh_token)) {
        bm_log('[BM2 REFRESH] No refresh token available | User ID: ' . $user_id, [], 'error', 'fitbit-api');
        return false;
    }

    $response = wp_remote_post(
        'https://api.fitbit.com/oauth2/token',
        array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode(
                    bm2_fitbit_client_id() . ':' . bm2_fitbit_client_secret()
                ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
            ),
        )
    );

    if (is_wp_error($response)) {
        bm_log('[BM2 REFRESH] HTTP error during token refresh | User ID: ' . $user_id, ['error' => $response->get_error_message()], 'error', 'fitbit-api');
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code !== 200 || empty($data['access_token'])) {
        bm_log('[BM2 REFRESH] Failed: ' . $body . ' | User ID: ' . $user_id, [], 'error', 'fitbit-api');
        return false;
    }

    // ✅ Store new tokens (Fitbit ROTATES refresh tokens)
    update_user_meta($user_id, 'bm_fitbit_access_token', $data['access_token']);
    update_user_meta(
        $user_id,
        'bm_fitbit_refresh_token',
        $data['refresh_token'] ?? $refresh_token
    );
    update_user_meta(
        $user_id,
        'bm_fitbit_token_type',
        $data['token_type'] ?? 'Bearer'
    );

    // ✅ Normalize scopes
    $scopes = array();
    if (!empty($data['scope'])) {
        $scopes = explode(' ', $data['scope']);
        update_user_meta($user_id, 'bm_fitbit_scope', $scopes);
    }

    // ✅ REQUIRED scopes for this plugin
    $required_scopes = array(
        'activity',
        'sleep',
        'heartrate',
    );

    // ✅ Verify required scopes were actually granted
    $missing_scopes = array_diff($required_scopes, $scopes);

    if (!empty($missing_scopes)) {

        bm_log(
            '[BM2 REFRESH] Missing required scopes: ' . implode(', ', $missing_scopes) . ' | User ID: ' . $user_id,
            [],
            'error',
            'fitbit-api'
        );

        // Clear tokens so UI no longer claims "Connected"
        bm2_fitbit_clear_tokens($user_id);

        return false; // Forces re-auth on next attempt
    }

    bm_log('[BM2 REFRESH] Token refreshed and scopes verified | User ID: ' . $user_id, [], 'info', 'fitbit-api');

    return true;
}

/**
 * Apply FITBIT tag to the currently logged-in user via WP Fusion
 */
function bm_fitbit_apply_wp_fusion_tag() {

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return;
    }

    // Static tag(s) to apply
    $tags = array( 'FITBIT' );

    // Apply via WP Fusion
    if ( function_exists( 'wp_fusion' ) && wp_fusion()->user ) {
        wp_fusion()->user->apply_tags( $tags, $user_id );

        // Optional: maintain WPF consistency / logging / automations
        do_action( 'wpf_apply_tags', $tags, $user_id );
    }
}


/* -------------------------------------------------------------------------- */
/* HR Intraday – Yesterday ONLY                                                */
/* -------------------------------------------------------------------------- */

function bm2_fitbit_pull_hr_intraday_yesterday($user_id) {

    global $wpdb;

    $table = $wpdb->prefix . 'bm_fitbit_hr_intraday';

    $date = date('Y-m-d', strtotime('-1 day'));

    $url = "https://api.fitbit.com/1/user/-/activities/heart/date/{$date}/1d/1min.json";


    $data = bm2_fitbit_api_request($user_id, 'GET', $url);

    if (is_wp_error($data)) {
        bm_log('[BM2] API failed | User ID: ' . $user_id, [], 'error', 'fitbit-api');
        return false;
    }

    if (
        empty($data['activities-heart-intraday']) ||
        empty($data['activities-heart-intraday']['dataset'])
    ) {
        bm_log('[BM2] No intraday dataset returned by Fitbit | User ID: ' . $user_id, [], 'info', 'fitbit-api');
        return false;
    }

    $rows = $data['activities-heart-intraday']['dataset'];
    $tz   = wp_timezone_string() ?: 'UTC';

    $inserted = 0;

    foreach ($rows as $r) {

        if (!isset($r['time'], $r['value'])) continue;

        $timestamp = $date . ' ' . $r['time'];

        $wpdb->query(
            $wpdb->prepare(
                "
                INSERT INTO {$table}
                (wp_user_id, sample_time, bpm, timezone)
                VALUES (%d, %s, %d, %s)
                ON DUPLICATE KEY UPDATE bpm = VALUES(bpm)
                ",
                $user_id,
                $timestamp,
                (int)$r['value'],
                $tz
            )
        );

        $inserted++;
    }

    return true;
}

/* -------------------------------------------------------------------------- */
/* Front-end test trigger                                                      */
/* -------------------------------------------------------------------------- */

add_action('template_redirect', function () {

    if (empty($_GET['bm2_test_hr'])) {
        return;
    }

    if (!is_user_logged_in()) {
        wp_die('Not logged in');
    }

    if (
        empty($_GET['_wpnonce']) ||
        !wp_verify_nonce($_GET['_wpnonce'], 'bm2_test_hr')
    ) {
        wp_die('Bad nonce');
    }

    $user_id = get_current_user_id();

    bm2_fitbit_pull_hr_intraday_yesterday($user_id);

    wp_safe_redirect(home_url('/fitbit?bm2_test_hr=done'));
    exit;
});




/**
 * Pull Fitbit HRV for last completed night (yesterday)
 * Stores daily_rmssd_ms and deep_rmssd_ms correctly.
 */
function bm2_fitbit_pull_hrv_yesterday($user_id) {

    global $wpdb;

    $table = $wpdb->prefix . 'bm_fitbit_hrv_summary';

    // HRV is sleep-based → use yesterday
    $date = date('Y-m-d', strtotime('-1 day'));

    $url = "https://api.fitbit.com/1/user/-/hrv/date/{$date}.json";


    $data = bm2_fitbit_api_request($user_id, 'GET', $url);

    if (is_wp_error($data)) {
        bm_log('[BM2 HRV] API error | User ID: ' . $user_id, [], 'error', 'fitbit-api');
        return false;
    }


    if (
        empty($data['hrv']) ||
        !is_array($data['hrv']) ||
        empty($data['hrv'][0]['value'])
    ) {
        bm_log('[BM2 HRV] HRV array or value block missing | User ID: ' . $user_id, [], 'info', 'fitbit-api');
        return false;
    }

    $value = $data['hrv'][0]['value'];

    $daily = $value['dailyRmssd'] ?? null;
    $deep  = $value['deepRmssd']  ?? null;

    if ($daily === null && $deep === null) {
        bm_log('[BM2 HRV] HRV RMSSD values missing | User ID: ' . $user_id, [], 'info', 'fitbit-api');
        return false;
    }

    $wpdb->query(
        $wpdb->prepare(
            "
            INSERT INTO {$table}
                (wp_user_id, hrv_date, daily_rmssd_ms, deep_rmssd_ms, raw_json)
            VALUES
                (%d, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                daily_rmssd_ms = VALUES(daily_rmssd_ms),
                deep_rmssd_ms  = VALUES(deep_rmssd_ms),
                raw_json       = VALUES(raw_json)
            ",
            $user_id,
            $date,
            $daily,
            $deep,
            wp_json_encode($data)
        )
    );

    return true;
}



////////////////////////////////////////
add_shortcode('bm2_test_hr_button', function () {

    if (!is_user_logged_in()) {
        return '<em>Please log in</em>';
    }

    $url = add_query_arg(array(
        'bm2_test_hr' => 1,
        '_wpnonce'    => wp_create_nonce('bm2_test_hr'),
    ), home_url('/fitbit'));

    return '<a class="button button-primary" href="'.esc_url($url).'">
        Run HR Intraday Test (Yesterday)
    </a>';
});


add_shortcode('bm2_test_hrv_button', function () {

    if (!is_user_logged_in()) {
        return '<em>Please log in</em>';
    }

    $url = add_query_arg(array(
        'bm2_test_hrv' => 1,
        '_wpnonce'     => wp_create_nonce('bm2_test_hrv'),
    ), home_url('/fitbit'));

    return '<a class="button" href="'.esc_url($url).'">Run HRV Test (Yesterday)</a>';
});


add_action('template_redirect', function () {

    if (empty($_GET['bm2_test_hrv'])) return;

    if (!is_user_logged_in()) wp_die('Not logged in');
    if (!wp_verify_nonce($_GET['_wpnonce'], 'bm2_test_hrv')) wp_die('Bad nonce');

    bm2_fitbit_pull_hrv_yesterday(get_current_user_id());

    wp_safe_redirect(home_url('/fitbit?bm2_test_hrv=done'));
    exit;
});


/**
 * Pull Fitbit sleep summary for last completed night (yesterday)
 */
function bm2_fitbit_pull_sleep_yesterday($user_id) {

    global $wpdb;

    $table = $wpdb->prefix . 'bm_fitbit_sleep_summary';

    // Sleep is night-based → yesterday
    $date = date('Y-m-d', strtotime('-1 day'));

    $url = "https://api.fitbit.com/1.2/user/-/sleep/date/{$date}.json";


    $data = bm2_fitbit_api_request($user_id, 'GET', $url);

    if (is_wp_error($data)) {
        bm_log('[BM2 SLEEP] API error | User ID: ' . $user_id, [], 'error', 'fitbit-api');
        return false;
    }


    if (empty($data['sleep']) || !is_array($data['sleep'])) {
        bm_log('[BM2 SLEEP] No sleep array returned | User ID: ' . $user_id, [], 'info', 'fitbit-api');
        return false;
    }

    // Prefer the main sleep
    $sleep = null;
    foreach ($data['sleep'] as $s) {
        if (!empty($s['isMainSleep'])) {
            $sleep = $s;
            break;
        }
    }

    // Fallback to first sleep if main not flagged
    if (!$sleep) {
        $sleep = $data['sleep'][0];
    }

    if (empty($sleep['levels']['summary'])) {
        bm_log('[BM2 SLEEP] Sleep levels summary missing | User ID: ' . $user_id, [], 'info', 'fitbit-api');
        return false;
    }

    $summary = $sleep['levels']['summary'];

    $minutes_deep  = $summary['deep']['minutes']  ?? 0;
    $minutes_light = $summary['light']['minutes'] ?? 0;
    $minutes_rem   = $summary['rem']['minutes']   ?? 0;
    $minutes_awake = $summary['wake']['minutes']  ?? 0;

    $minutes_asleep = $sleep['minutesAsleep'] ?? ($minutes_deep + $minutes_light + $minutes_rem);
    $efficiency     = $sleep['efficiency']    ?? null;

    // Convert duration to minutes
    $duration_minutes = !empty($sleep['duration'])
        ? round($sleep['duration'] / 60000)
        : null;

    $wpdb->query(
        $wpdb->prepare(
            "
            INSERT INTO {$table}
                (
                    wp_user_id,
                    date_of_sleep,
                    duration_ms,
                    efficiency,
                    minutes_asleep,
                    minutes_awake,
                    minutes_deep,
                    minutes_light,
                    minutes_rem,
                    raw_json
                )
            VALUES
                (%d, %s, %d, %d, %d, %d, %d, %d, %d, %s)
            ON DUPLICATE KEY UPDATE
                duration_ms     = VALUES(duration_ms),
                efficiency      = VALUES(efficiency),
                minutes_asleep  = VALUES(minutes_asleep),
                minutes_awake   = VALUES(minutes_awake),
                minutes_deep    = VALUES(minutes_deep),
                minutes_light   = VALUES(minutes_light),
                minutes_rem     = VALUES(minutes_rem),
                raw_json        = VALUES(raw_json)
            ",
            $user_id,
            $date,
            $sleep['duration'] ?? null,
            $efficiency,
            $minutes_asleep,
            $minutes_awake,
            $minutes_deep,
            $minutes_light,
            $minutes_rem,
            wp_json_encode($sleep)
        )
    );


    return true;
}


add_action('template_redirect', function () {

    if (empty($_GET['bm2_test_sleep'])) {
        return;
    }

    if (!is_user_logged_in()) {
        wp_die('Not logged in');
    }

    if (
        empty($_GET['_wpnonce']) ||
        !wp_verify_nonce($_GET['_wpnonce'], 'bm2_test_sleep')
    ) {
        wp_die('Bad nonce', 403);
    }

    bm2_fitbit_pull_sleep_yesterday(get_current_user_id());

    wp_safe_redirect(home_url('/fitbit?bm2_test_sleep=done'));
    exit;
});


add_shortcode('bm2_test_sleep_button', function () {

    if (!is_user_logged_in()) {
        return '<em>Please log in</em>';
    }

    $url = add_query_arg(
        array(
            'bm2_test_sleep' => 1,
            '_wpnonce'       => wp_create_nonce('bm2_test_sleep'),
        ),
        home_url('/fitbit')
    );

    return '<a class="button button-primary" href="'.esc_url($url).'">
        Run Sleep Summary Test (Yesterday)
    </a>';
});


/**
 * Pull Fitbit activity summary for yesterday (day-based)
 */
function bm2_fitbit_pull_activity_yesterday($user_id) {

    global $wpdb;

    $table = $wpdb->prefix . 'bm_fitbit_activity_summary';

    // Activity is day-based → yesterday
    $date = date('Y-m-d', strtotime('-1 day'));

    $url = "https://api.fitbit.com/1/user/-/activities/date/{$date}.json";

    $data = bm2_fitbit_api_request($user_id, 'GET', $url);

    if (is_wp_error($data)) {
        bm_log('[BM2 ACTIVITY] API error | User ID: ' . $user_id, [], 'error', 'fitbit-api');
        return false;
    }

    

    if (empty($data['summary'])) {
        bm_log('[BM2 ACTIVITY] Summary block missing | User ID: ' . $user_id, [], 'info', 'fitbit-api');
        return false;
    }

    $s = $data['summary'];

    $steps    = $s['steps']         ?? 0;
    $calories = $s['caloriesOut']   ?? null;
    $floors   = $s['floors']        ?? null;

    $sedentary = $s['sedentaryMinutes']      ?? 0;
    $light     = $s['lightlyActiveMinutes']  ?? 0;
    $fair      = $s['fairlyActiveMinutes']   ?? 0;
    $very      = $s['veryActiveMinutes']     ?? 0;

    // Distance can appear as aggregated list
    $distance_km = 0.0;
    if (!empty($s['distances']) && is_array($s['distances'])) {
        foreach ($s['distances'] as $d) {
            if (($d['activity'] ?? '') === 'total') {
                $distance_km = (float)($d['distance'] ?? 0);
                break;
            }
        }
    }

    $wpdb->query(
        $wpdb->prepare(
            "
            INSERT INTO {$table}
                (
                    wp_user_id,
                    activity_date,
                    steps,
                    calories,
                    distance_km,
                    sedentary_min,
                    lightly_active_min,
                    fairly_active_min,
                    very_active_min,
                    floors,
                    raw_json
                )
            VALUES
                (%d, %s, %d, %d, %f, %d, %d, %d, %d, %d, %s)
            ON DUPLICATE KEY UPDATE
                steps               = VALUES(steps),
                calories            = VALUES(calories),
                distance_km         = VALUES(distance_km),
                sedentary_min       = VALUES(sedentary_min),
                lightly_active_min  = VALUES(lightly_active_min),
                fairly_active_min   = VALUES(fairly_active_min),
                very_active_min     = VALUES(very_active_min),
                floors              = VALUES(floors),
                raw_json            = VALUES(raw_json)
            ",
            $user_id,
            $date,
            $steps,
            $calories,
            $distance_km,
            $sedentary,
            $light,
            $fair,
            $very,
            $floors,
            wp_json_encode($data)
        )
    );

    
    // Persist Resting Heart Rate to legacy table
    bm2_fitbit_store_rhr_from_activity($user_id, $date, $data);


    return true;
}


/**
 * Persist daily Resting Heart Rate (RHR) from Activity Summary v2
 * into bm_fitbit_daily_rhr (for legacy display compatibility)
 */
function bm2_fitbit_store_rhr_from_activity($user_id, $date, $activity_data) {

    global $wpdb;

    $table = $wpdb->prefix . 'bm_fitbit_daily_rhr';

    // Ensure RHR exists in activity summary
    if (empty($activity_data['summary']['restingHeartRate'])) {
        bm_log("[BM2 RHR] No restingHeartRate for {$date}", [], 'info', 'fitbit-api');
        return false;
    }

    $rhr = (int) $activity_data['summary']['restingHeartRate'];

    $wpdb->query(
        $wpdb->prepare(
            "
            INSERT INTO {$table}
                (wp_user_id, rhr_date, resting_bpm, raw_json, updated_at)
            VALUES
                (%d, %s, %d, %s, %s)
            ON DUPLICATE KEY UPDATE
                resting_bpm = VALUES(resting_bpm),
                raw_json    = VALUES(raw_json),
                updated_at  = VALUES(updated_at)
            ",
            $user_id,
            $date,
            $rhr,
            wp_json_encode($activity_data['summary']),
            current_time('mysql')
        )
    );

    //error_log("[BM2 RHR] Stored RHR={$rhr} for {$date}");

    return true;
}


add_action('template_redirect', function () {

    if (empty($_GET['bm2_test_activity'])) {
        return;
    }

    if (!is_user_logged_in()) {
        wp_die('Not logged in');
    }

    if (
        empty($_GET['_wpnonce']) ||
        !wp_verify_nonce($_GET['_wpnonce'], 'bm2_test_activity')
    ) {
        wp_die('Bad nonce', 403);
    }

    bm2_fitbit_pull_activity_yesterday(get_current_user_id());

    wp_safe_redirect(home_url('/fitbit?bm2_test_activity=done'));
    exit;
});


add_shortcode('bm2_test_activity_button', function () {

    if (!is_user_logged_in()) {
        return '<em>Please log in</em>';
    }

    $url = add_query_arg(
        array(
            'bm2_test_activity' => 1,
            '_wpnonce'          => wp_create_nonce('bm2_test_activity'),
        ),
        home_url('/fitbit')
    );

    return '<a class="button button-primary" href="'.esc_url($url).'">
        Run Activity Summary Test (Yesterday)
    </a>';
});



/**
 * Run full Fitbit sync (v2) for a user
 * Calls only verified endpoints
 */
function bm2_fitbit_run_full_sync($user_id) {

    $results = array();

    // -----------------------------
    // Activity (daily)
    // -----------------------------
    if (function_exists('bm2_fitbit_pull_activity_yesterday')) {
        $ok = bm2_fitbit_pull_activity_yesterday($user_id);
        $results['activity'] = $ok ? 'ok' : 'no_data';
    }

    // -----------------------------
    // Sleep (nightly)
    // -----------------------------
    if (function_exists('bm2_fitbit_pull_sleep_yesterday')) {
        $ok = bm2_fitbit_pull_sleep_yesterday($user_id);
        $results['sleep'] = $ok ? 'ok' : 'no_data';
    }

    // -----------------------------
    // HRV (nightly)
    // -----------------------------
    if (function_exists('bm2_fitbit_pull_hrv_yesterday')) {
        $ok = bm2_fitbit_pull_hrv_yesterday($user_id);
        $results['hrv'] = $ok ? 'ok' : 'no_data';
    }

    
    update_user_meta(
        $user_id,
        'bm_fitbit_last_sync',
        current_time('mysql')
    );

    bm_log('[BM2 Full Sync] Completed: '. $user_id);
    return $results;

}



add_action('template_redirect', function () {

/*     bm2_fitbit_debug_log(
    '[BM2 DEBUG] template_redirect hit. GET = ' . wp_json_encode($_GET)
    ); */

    // We only care about starting the sync
    if (!isset($_GET['bm2_sync']) || $_GET['bm2_sync'] !== '1') {
        return;
    }

    if (!is_user_logged_in()) {
        wp_die('Not logged in');
    }

    // ✅ Only validate nonce for the sync trigger
    if (
        empty($_GET['_wpnonce']) ||
        !wp_verify_nonce($_GET['_wpnonce'], 'bm2_sync')
    ) {
        wp_die('Invalid sync request', 403);
    }

    // Run unified v2 sync
    bm2_fitbit_run_full_sync(get_current_user_id());

    // Redirect WITHOUT nonce
    wp_safe_redirect(
        add_query_arg('bm2_sync', 'done', home_url('/fitbit'))
    );
    exit;
});



add_shortcode('bm2_sync_button', function () {

    if (!is_user_logged_in()) {
        return '<em>Please log in to sync.</em>';
    }

    $nonce = wp_create_nonce('bm2_sync');

    return '/fitbit?bm2_sync=1&_wpnonce=' . $nonce  ;
    });


/////////////////////////////////
//Account and Authorization
////////////////////////////////


function bm2_fitbit_client_id() {
    return get_option('bm_fitbit_client_id');
}

function bm2_fitbit_client_secret() {
    return get_option('bm_fitbit_client_secret');
}

function bm2_fitbit_redirect_uri() {
    return get_option('bm_fitbit_redirect_uri');
}

//Token Helpers

/**
 * Get Fitbit access token for user
 */
function bm2_fitbit_get_access_token($user_id) {
    return get_user_meta($user_id, 'bm_fitbit_access_token', true);
}

/**
 * Get Fitbit refresh token for user
 */
function bm2_fitbit_get_refresh_token($user_id) {
    return get_user_meta($user_id, 'bm_fitbit_refresh_token', true);
}

/**
 * Get Fitbit token type
 */
function bm2_fitbit_get_token_type($user_id) {
    return get_user_meta($user_id, 'bm_fitbit_token_type', true);
}

/**
 * Get Fitbit granted scopes
 */
function bm2_fitbit_get_scope($user_id) {
    return get_user_meta($user_id, 'bm_fitbit_scope', true);
}

/**
 * Check if user has an active Fitbit connection
 */
function bm2_fitbit_is_connected($user_id) {
    return (bool) bm2_fitbit_get_access_token($user_id);
}

/**
 * Remove all Fitbit tokens for a user
 */
function bm2_fitbit_clear_tokens($user_id) {

    delete_user_meta($user_id, 'bm_fitbit_access_token');
    delete_user_meta($user_id, 'bm_fitbit_refresh_token');
    delete_user_meta($user_id, 'bm_fitbit_token_type');
    delete_user_meta($user_id, 'bm_fitbit_scope');

    // Optional: clear OAuth state if present
    delete_user_meta($user_id, 'bm_fitbit_oauth_state');
}



//OAuth URL Builder (Connect)

function bm2_fitbit_build_authorize_url($user_id) {

    $state = wp_generate_uuid4();
    update_user_meta($user_id, 'bm_fitbit_oauth_state', $state);

    $params = array(
        'response_type' => 'code',
        'client_id'     => bm2_fitbit_client_id(),
        'redirect_uri'  => bm2_fitbit_redirect_uri(),
        'scope'         => 'activity heartrate sleep oxygen_saturation',
        'state'         => $state,
        'expires_in'    => '604800',
    );

    return 'https://www.fitbit.com/oauth2/authorize?' . http_build_query($params);
}

//OAuth Callback Handler (/fitbit-auth)

add_action('template_redirect', function () {

    if (!is_page('fitbit-auth')) {
        return;
    }

    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url(home_url('/fitbit')));
        exit;
    }

    $user_id = get_current_user_id();

    if (!empty($_GET['error'])) {
        wp_safe_redirect(home_url('/fitbit?fitbit=error'));
        exit;
    }

    if (empty($_GET['code']) || empty($_GET['state'])) {
        wp_safe_redirect(home_url('/fitbit?fitbit=error'));
        exit;
    }

    $expected_state = get_user_meta($user_id, 'bm_fitbit_oauth_state', true);
    delete_user_meta($user_id, 'bm_fitbit_oauth_state');

    if (!$expected_state || !hash_equals($expected_state, $_GET['state'])) {
        wp_safe_redirect(home_url('/fitbit?fitbit=state_error'));
        exit;
    }

    $token_response = wp_remote_post(
        'https://api.fitbit.com/oauth2/token',
        array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode(
                    bm2_fitbit_client_id() . ':' . bm2_fitbit_client_secret()
                ),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => http_build_query(array(
                'grant_type'   => 'authorization_code',
                'code'         => sanitize_text_field($_GET['code']),
                'redirect_uri' => bm2_fitbit_redirect_uri(),
            )),
            'timeout' => 20,
        )
    );

    if (is_wp_error($token_response)) {
        wp_safe_redirect(home_url('/fitbit?fitbit=token_error'));
        exit;
    }

    $data = json_decode(wp_remote_retrieve_body($token_response), true);

    if (empty($data['access_token']) || empty($data['refresh_token'])) {
        wp_safe_redirect(home_url('/fitbit?fitbit=token_error'));
        exit;
    }

    update_user_meta($user_id, 'bm_fitbit_access_token', $data['access_token']);
    update_user_meta($user_id, 'bm_fitbit_refresh_token', $data['refresh_token']);
    update_user_meta($user_id, 'bm_fitbit_token_type', $data['token_type'] ?? '');
    update_user_meta($user_id, 'bm_fitbit_scope', $data['scope'] ?? '');

    
        // ✅ Apply WP Fusion tag now that connection is confirmed
        bm_fitbit_apply_wp_fusion_tag( $user_id );


    wp_safe_redirect(home_url('/fitbit?fitbit=connected'));
    exit;
});

//Disconnect

function bm2_fitbit_disconnect($user_id) {

    delete_user_meta($user_id, 'bm_fitbit_access_token');
    delete_user_meta($user_id, 'bm_fitbit_refresh_token');
    delete_user_meta($user_id, 'bm_fitbit_token_type');
    delete_user_meta($user_id, 'bm_fitbit_scope');
}

add_action('template_redirect', function () {

    if (empty($_GET['bm2_fitbit_disconnect'])) {
        return;
    }

    if (!is_user_logged_in()) {
        wp_die('Not logged in');
    }

    if (
        empty($_GET['_wpnonce']) ||
        !wp_verify_nonce($_GET['_wpnonce'], 'bm2_fitbit_disconnect')
    ) {
        wp_die('Bad nonce', 403);
    }

    bm2_fitbit_disconnect(get_current_user_id());

    wp_safe_redirect(home_url('/fitbit?fitbit=disconnected'));
    exit;
});

//Shortcodes

add_shortcode('bm_fitbit_connect', function () {

    if (!is_user_logged_in()) {
        return '<em>Please log in to connect Fitbit.</em>';
    }

    $user_id = get_current_user_id();

    if (bm2_fitbit_is_connected($user_id)) {
        return '';
    }

    $url = bm2_fitbit_build_authorize_url($user_id);

    return '<a class="button button-primary" href="'.esc_url($url).'">Connect Fitbit</a>';
});


add_shortcode('bm_fitbit_connected', function () {

    if (!is_user_logged_in()) {
        return '';
    }

    return bm2_fitbit_is_connected(get_current_user_id())
        ? '<strong style="color:green;">Fitbit Connected</strong>'
        : '';
});

add_shortcode('bm_fitbit_disconnect', function () {

    if (!is_user_logged_in()) {
        return '';
    }

    if (!bm2_fitbit_is_connected(get_current_user_id())) {
        return '';
    }

    $url = add_query_arg(
        array(
            'bm2_fitbit_disconnect' => 1,
            '_wpnonce'              => wp_create_nonce('bm2_fitbit_disconnect'),
        ),
        home_url('/fitbit')
    );

    return '<a class="button" href="'.esc_url($url).'">Disconnect Fitbit</a>';
});


add_shortcode('bm_fitbit_last_sync', function () {

    if (!is_user_logged_in()) {
        return '';
    }

    $last = get_user_meta(get_current_user_id(), 'bm_fitbit_last_sync', true);

    if (!$last) {
        return '<span class="bmfd-muted">Fitbit data has not been synced yet.</span>';
    }

    $time = strtotime($last);

    return sprintf(
        '<span class="bmfd-muted">Last Fitbit sync: <strong>%s</strong> UTC</span>',
        esc_html(
            date_i18n(
                get_option('date_format') . ' ' . get_option('time_format'),
                $time
            )
        )
    );
});
