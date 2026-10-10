<?php
/**
 * Plugin Name: Breathermae – Oura Integration
 * Description: Member Oura connect, token refresh, and nightly pull of readiness, sleep, and activity. First slice. Does not touch Fitbit.
 * Version: 0.1.1-poc
 * Author: Breathermae, Inc.
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BM_OURA_VERSION', '0.1.1-poc' );
define( 'BM_OURA_SCOPE', 'daily spo2' );
define( 'BM_OURA_TAG', 'OURA' );
define( 'BM_OURA_API', 'https://api.ouraring.com/v2/usercollection/' );
define( 'BM_OURA_AUTHORIZE', 'https://cloud.ouraring.com/oauth/authorize' );
define( 'BM_OURA_TOKEN', 'https://api.ouraring.com/oauth/token' );

register_activation_hook( __FILE__, 'bm_oura_activate' );
register_deactivation_hook( __FILE__, 'bm_oura_deactivate' );

function bm_oura_activate() {
	bm_oura_install_tables();
	if ( ! wp_next_scheduled( 'bm_oura_nightly_sync' ) ) {
		$tz    = wp_timezone();
		$start = new DateTime( 'tomorrow 08:00:00', $tz );
		wp_schedule_event( $start->getTimestamp(), 'daily', 'bm_oura_nightly_sync' );
	}
}

function bm_oura_deactivate() {
	wp_clear_scheduled_hook( 'bm_oura_nightly_sync' );
}

add_action( 'plugins_loaded', 'bm_oura_maybe_upgrade', 5 );
function bm_oura_maybe_upgrade() {
	if ( get_option( 'bm_oura_db_version' ) !== BM_OURA_VERSION ) {
		bm_oura_install_tables();
	}
}

function bm_oura_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$sleep   = $wpdb->prefix . 'bm_oura_sleep';
	$ready   = $wpdb->prefix . 'bm_oura_readiness';
	$act     = $wpdb->prefix . 'bm_oura_activity';
	$spo2    = $wpdb->prefix . 'bm_oura_spo2';

	$sql = "CREATE TABLE {$sleep} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		wp_user_id bigint(20) unsigned NOT NULL,
		sleep_day date NOT NULL,
		oura_id varchar(64) NOT NULL DEFAULT '',
		sleep_type varchar(32) NOT NULL DEFAULT '',
		score smallint(6) DEFAULT NULL,
		efficiency smallint(6) DEFAULT NULL,
		latency_sec int(11) DEFAULT NULL,
		total_sleep_sec int(11) DEFAULT NULL,
		deep_sec int(11) DEFAULT NULL,
		rem_sec int(11) DEFAULT NULL,
		light_sec int(11) DEFAULT NULL,
		awake_sec int(11) DEFAULT NULL,
		time_in_bed_sec int(11) DEFAULT NULL,
		avg_hr smallint(6) DEFAULT NULL,
		lowest_hr smallint(6) DEFAULT NULL,
		avg_hrv smallint(6) DEFAULT NULL,
		avg_breath decimal(5,2) DEFAULT NULL,
		bedtime_start datetime DEFAULT NULL,
		bedtime_end datetime DEFAULT NULL,
		contributors_json longtext NULL,
		raw_json longtext NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_day (wp_user_id, sleep_day)
	) {$charset};
	CREATE TABLE {$ready} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		wp_user_id bigint(20) unsigned NOT NULL,
		readiness_day date NOT NULL,
		oura_id varchar(64) NOT NULL DEFAULT '',
		score smallint(6) DEFAULT NULL,
		temperature_deviation decimal(6,3) DEFAULT NULL,
		temperature_trend_deviation decimal(6,3) DEFAULT NULL,
		hrv_balance smallint(6) DEFAULT NULL,
		resting_hr_contrib smallint(6) DEFAULT NULL,
		recovery_index smallint(6) DEFAULT NULL,
		sleep_balance smallint(6) DEFAULT NULL,
		activity_balance smallint(6) DEFAULT NULL,
		body_temperature smallint(6) DEFAULT NULL,
		sleep_regularity smallint(6) DEFAULT NULL,
		contributors_json longtext NULL,
		raw_json longtext NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_day (wp_user_id, readiness_day)
	) {$charset};
	CREATE TABLE {$act} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		wp_user_id bigint(20) unsigned NOT NULL,
		activity_day date NOT NULL,
		oura_id varchar(64) NOT NULL DEFAULT '',
		score smallint(6) DEFAULT NULL,
		steps int(11) DEFAULT NULL,
		active_calories int(11) DEFAULT NULL,
		total_calories int(11) DEFAULT NULL,
		equivalent_walking_m int(11) DEFAULT NULL,
		high_sec int(11) DEFAULT NULL,
		medium_sec int(11) DEFAULT NULL,
		low_sec int(11) DEFAULT NULL,
		sedentary_sec int(11) DEFAULT NULL,
		non_wear_sec int(11) DEFAULT NULL,
		raw_json longtext NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_day (wp_user_id, activity_day)
	) {$charset};
	CREATE TABLE {$spo2} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		wp_user_id bigint(20) unsigned NOT NULL,
		spo2_day date NOT NULL,
		oura_id varchar(64) NOT NULL DEFAULT '',
		avg_pct decimal(5,2) DEFAULT NULL,
		raw_json longtext NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_day (wp_user_id, spo2_day)
	) {$charset};";

	dbDelta( $sql );
	update_option( 'bm_oura_db_version', BM_OURA_VERSION );
}

function bm_oura_log( $message, $level = 'info' ) {
	if ( function_exists( 'bm_log' ) ) {
		bm_log( $message, array(), $level, 'oura-api' );
		return;
	}
	error_log( '[BM Oura] ' . $message );
}

/* ---------------------------------------------------------------------
 * Settings
 * ------------------------------------------------------------------- */

add_action( 'admin_init', function () {
	register_setting( 'bm_oura_settings', 'bm_oura_client_id', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'bm_oura_settings', 'bm_oura_client_secret', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'bm_oura_settings', 'bm_oura_redirect_uri', array(
		'type'              => 'string',
		'sanitize_callback' => 'esc_url_raw',
		'default'           => '',
	) );
} );

add_action( 'admin_menu', function () {
	add_options_page(
		'Breathermae → Oura',
		'Breathermae → Oura',
		'manage_options',
		'bm-oura-settings',
		'bm_oura_render_settings_page'
	);
} );

function bm_oura_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$suggested = home_url( '/oura-auth/' );
	?>
	<div class="wrap">
		<h1>Breathermae → Oura</h1>
		<p>OAuth app credentials from the Oura developer portal. Personal access tokens are not used.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'bm_oura_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bm_oura_client_id">Client ID</label></th>
					<td><input type="text" class="regular-text" id="bm_oura_client_id" name="bm_oura_client_id" value="<?php echo esc_attr( get_option( 'bm_oura_client_id' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="bm_oura_client_secret">Client Secret</label></th>
					<td><input type="text" class="regular-text" id="bm_oura_client_secret" name="bm_oura_client_secret" value="<?php echo esc_attr( get_option( 'bm_oura_client_secret' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="bm_oura_redirect_uri">Redirect URI</label></th>
					<td>
						<input type="url" class="regular-text code" id="bm_oura_redirect_uri" name="bm_oura_redirect_uri" value="<?php echo esc_attr( get_option( 'bm_oura_redirect_uri' ) ); ?>" placeholder="<?php echo esc_attr( $suggested ); ?>" />
						<p class="description">Must exactly match the Oura app callback. Recommended: a blank page with slug <code>oura-auth</code>, URL <code><?php echo esc_html( $suggested ); ?></code></p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save Oura Settings' ); ?>
		</form>
		<hr />
		<ul>
			<li>Scopes requested: <code><?php echo esc_html( BM_OURA_SCOPE ); ?></code></li>
			<li>Nightly cron hook <code>bm_oura_nightly_sync</code> at 08:00 site time. Oura cloud often lags a few hours after wake.</li>
			<li>WP Fusion tag applied on connect: <code><?php echo esc_html( BM_OURA_TAG ); ?></code></li>
		</ul>
	</div>
	<?php
}

function bm_oura_client_id() {
	return (string) get_option( 'bm_oura_client_id' );
}
function bm_oura_client_secret() {
	return (string) get_option( 'bm_oura_client_secret' );
}
function bm_oura_redirect_uri() {
	$uri = (string) get_option( 'bm_oura_redirect_uri' );
	return $uri !== '' ? $uri : home_url( '/oura-auth/' );
}

/* ---------------------------------------------------------------------
 * Tokens
 * ------------------------------------------------------------------- */

function bm_oura_is_connected( $user_id ) {
	return (string) get_user_meta( $user_id, 'bm_oura_access_token', true ) !== '';
}

function bm_oura_clear_tokens( $user_id ) {
	delete_user_meta( $user_id, 'bm_oura_access_token' );
	delete_user_meta( $user_id, 'bm_oura_refresh_token' );
	delete_user_meta( $user_id, 'bm_oura_token_expires_at' );
	delete_user_meta( $user_id, 'bm_oura_scope' );
	delete_user_meta( $user_id, 'bm_oura_oauth_state' );
}

function bm_oura_store_token_response( $user_id, $data ) {
	if ( empty( $data['access_token'] ) || empty( $data['refresh_token'] ) ) {
		return false;
	}
	update_user_meta( $user_id, 'bm_oura_access_token', $data['access_token'] );
	update_user_meta( $user_id, 'bm_oura_refresh_token', $data['refresh_token'] );
	$ttl = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 86400;
	update_user_meta( $user_id, 'bm_oura_token_expires_at', time() + max( 60, $ttl - 120 ) );
	if ( ! empty( $data['scope'] ) ) {
		update_user_meta( $user_id, 'bm_oura_scope', $data['scope'] );
	}
	return true;
}

function bm_oura_refresh_access_token( $user_id ) {
	$refresh = (string) get_user_meta( $user_id, 'bm_oura_refresh_token', true );
	if ( $refresh === '' || bm_oura_client_id() === '' || bm_oura_client_secret() === '' ) {
		bm_oura_log( 'Refresh skipped, missing token or client credentials | user ' . $user_id, 'error' );
		return false;
	}
	$response = wp_remote_post( BM_OURA_TOKEN, array(
		'timeout' => 20,
		'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
		'body'    => array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh,
			'client_id'     => bm_oura_client_id(),
			'client_secret' => bm_oura_client_secret(),
		),
	) );
	if ( is_wp_error( $response ) ) {
		bm_oura_log( 'Refresh transport error | user ' . $user_id . ' ' . $response->get_error_message(), 'error' );
		return false;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code !== 200 || ! bm_oura_store_token_response( $user_id, is_array( $body ) ? $body : array() ) ) {
		bm_oura_log( 'Refresh failed ' . $code . ' | user ' . $user_id, 'error' );
		if ( $code === 400 || $code === 401 ) {
			bm_oura_clear_tokens( $user_id );
		}
		return false;
	}
	bm_oura_log( 'Token refreshed | user ' . $user_id );
	return true;
}

function bm_oura_api_get( $user_id, $path, $query = array(), $retried = false ) {
	$token = (string) get_user_meta( $user_id, 'bm_oura_access_token', true );
	if ( $token === '' ) {
		return new WP_Error( 'no_token', 'Missing Oura access token' );
	}
	$expires = (int) get_user_meta( $user_id, 'bm_oura_token_expires_at', true );
	if ( ! $retried && $expires && $expires < time() ) {
		if ( ! bm_oura_refresh_access_token( $user_id ) ) {
			return new WP_Error( 'refresh_failed', 'Oura token refresh failed' );
		}
		return bm_oura_api_get( $user_id, $path, $query, true );
	}
	$url = BM_OURA_API . ltrim( $path, '/' );
	if ( $query ) {
		$url = add_query_arg( $query, $url );
	}
	$response = wp_remote_get( $url, array(
		'timeout' => 20,
		'headers' => array(
			'Authorization' => 'Bearer ' . (string) get_user_meta( $user_id, 'bm_oura_access_token', true ),
			'Accept'        => 'application/json',
		),
	) );
	if ( is_wp_error( $response ) ) {
		bm_oura_log( 'HTTP error ' . $response->get_error_message() . ' | user ' . $user_id, 'error' );
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code === 401 && ! $retried ) {
		if ( ! bm_oura_refresh_access_token( $user_id ) ) {
			return new WP_Error( 'refresh_failed', 'Oura token refresh failed' );
		}
		return bm_oura_api_get( $user_id, $path, $query, true );
	}
	if ( $code !== 200 || ! is_array( $body ) ) {
		bm_oura_log( 'Oura error ' . $code . ' on ' . $path . ' | user ' . $user_id, 'error' );
		return new WP_Error( 'oura_api_error', 'Oura API returned ' . $code, array( 'status' => $code ) );
	}
	return $body;
}

function bm_oura_collect( $user_id, $path, $start, $end ) {
	$rows  = array();
	$query = array(
		'start_date' => $start,
		'end_date'   => $end,
	);
	$guard = 0;
	do {
		$data = bm_oura_api_get( $user_id, $path, $query );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! empty( $data['data'] ) && is_array( $data['data'] ) ) {
			$rows = array_merge( $rows, $data['data'] );
		}
		$next = isset( $data['next_token'] ) ? (string) $data['next_token'] : '';
		$query = $next !== '' ? array( 'next_token' => $next ) : array();
		$guard++;
	} while ( $query && $guard < 10 );
	return $rows;
}

/* ---------------------------------------------------------------------
 * Sync
 * ------------------------------------------------------------------- */

function bm_oura_site_day( $modify = '' ) {
	$dt = new DateTime( 'now', wp_timezone() );
	if ( $modify !== '' ) {
		$dt->modify( $modify );
	}
	return $dt->format( 'Y-m-d' );
}

function bm_oura_sync_window( $user_id, $days = 7 ) {
	$end   = bm_oura_site_day();
	$start = bm_oura_site_day( '-' . max( 1, (int) $days - 1 ) . ' days' );
	global $wpdb;
	$table = $wpdb->prefix . 'bm_oura_readiness';
	$last  = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(readiness_day) FROM {$table} WHERE wp_user_id = %d", $user_id ) );
	if ( $last && $last < $end ) {
		$overlap = gmdate( 'Y-m-d', strtotime( $last . ' -1 day' ) );
		if ( $overlap > $start ) {
			$start = $overlap;
		}
	}
	return array( $start, $end );
}

function bm_oura_run_sync( $user_id, $days = 7 ) {
	$user_id = (int) $user_id;
	if ( ! bm_oura_is_connected( $user_id ) ) {
		return array( 'error' => 'not_connected' );
	}
	list( $start, $end ) = bm_oura_sync_window( $user_id, $days );
	$results = array(
		'window'    => $start . '..' . $end,
		'readiness' => bm_oura_pull_readiness( $user_id, $start, $end ),
		'sleep'     => bm_oura_pull_sleep( $user_id, $start, $end ),
		'activity'  => bm_oura_pull_activity( $user_id, $start, $end ),
		'spo2'      => bm_oura_pull_spo2( $user_id, $start, $end ),
	);
	update_user_meta( $user_id, 'bm_oura_last_sync', current_time( 'mysql' ) );
	bm_oura_log( 'Sync ' . wp_json_encode( $results ) . ' | user ' . $user_id );
	return $results;
}

function bm_oura_upsert( $table, $keys, $data ) {
	global $wpdb;
	$where = array();
	$args  = array();
	foreach ( $keys as $col => $val ) {
		$where[] = "`{$col}` = " . ( is_int( $val ) ? '%d' : '%s' );
		$args[]  = $val;
	}
	$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE " . implode( ' AND ', $where ) . ' LIMIT 1', $args ) );
	$data['updated_at'] = current_time( 'mysql' );
	if ( $exists ) {
		$wpdb->update( $table, $data, array( 'id' => (int) $exists ) );
		return 'updated';
	}
	$wpdb->insert( $table, array_merge( $keys, $data ) );
	return 'inserted';
}

function bm_oura_int_or_null( $row, $key ) {
	return isset( $row[ $key ] ) && $row[ $key ] !== null && $row[ $key ] !== '' ? (int) $row[ $key ] : null;
}
function bm_oura_num_or_null( $row, $key ) {
	return isset( $row[ $key ] ) && $row[ $key ] !== null && $row[ $key ] !== '' ? (float) $row[ $key ] : null;
}
function bm_oura_mysql_dt( $value ) {
	if ( ! $value ) {
		return null;
	}
	$ts = strtotime( $value );
	return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
}
function bm_oura_slim_json( $row, $drop ) {
	foreach ( $drop as $key ) {
		unset( $row[ $key ] );
	}
	return wp_json_encode( $row );
}

function bm_oura_pull_readiness( $user_id, $start, $end ) {
	global $wpdb;
	$rows = bm_oura_collect( $user_id, 'daily_readiness', $start, $end );
	if ( is_wp_error( $rows ) ) {
		return $rows->get_error_code();
	}
	$n = 0;
	foreach ( $rows as $row ) {
		if ( empty( $row['day'] ) ) {
			continue;
		}
		$c = isset( $row['contributors'] ) && is_array( $row['contributors'] ) ? $row['contributors'] : array();
		bm_oura_upsert(
			$wpdb->prefix . 'bm_oura_readiness',
			array( 'wp_user_id' => (int) $user_id, 'readiness_day' => $row['day'] ),
			array(
				'oura_id'                     => isset( $row['id'] ) ? substr( (string) $row['id'], 0, 64 ) : '',
				'score'                       => bm_oura_int_or_null( $row, 'score' ),
				'temperature_deviation'       => bm_oura_num_or_null( $row, 'temperature_deviation' ),
				'temperature_trend_deviation' => bm_oura_num_or_null( $row, 'temperature_trend_deviation' ),
				'hrv_balance'                 => bm_oura_int_or_null( $c, 'hrv_balance' ),
				'resting_hr_contrib'          => bm_oura_int_or_null( $c, 'resting_heart_rate' ),
				'recovery_index'              => bm_oura_int_or_null( $c, 'recovery_index' ),
				'sleep_balance'               => bm_oura_int_or_null( $c, 'sleep_balance' ),
				'activity_balance'            => bm_oura_int_or_null( $c, 'activity_balance' ),
				'body_temperature'            => bm_oura_int_or_null( $c, 'body_temperature' ),
				'sleep_regularity'            => bm_oura_int_or_null( $c, 'sleep_regularity' ),
				'contributors_json'           => wp_json_encode( $c ),
				'raw_json'                    => wp_json_encode( $row ),
			)
		);
		$n++;
	}
	return $n;
}

function bm_oura_pull_sleep( $user_id, $start, $end ) {
	global $wpdb;
	$periods = bm_oura_collect( $user_id, 'sleep', $start, $end );
	$daily   = bm_oura_collect( $user_id, 'daily_sleep', $start, $end );
	if ( is_wp_error( $periods ) ) {
		return $periods->get_error_code();
	}
	$scores = array();
	$contrib = array();
	if ( ! is_wp_error( $daily ) ) {
		foreach ( $daily as $row ) {
			if ( empty( $row['day'] ) ) {
				continue;
			}
			$scores[ $row['day'] ]  = bm_oura_int_or_null( $row, 'score' );
			$contrib[ $row['day'] ] = isset( $row['contributors'] ) ? $row['contributors'] : array();
		}
	}
	$best = array();
	if ( is_array( $periods ) ) {
		foreach ( $periods as $row ) {
			if ( empty( $row['day'] ) ) {
				continue;
			}
			$type = isset( $row['type'] ) ? (string) $row['type'] : '';
			if ( $type === 'deleted' ) {
				continue;
			}
			$dur = (int) ( $row['total_sleep_duration'] ?? 0 );
			$rank = ( $type === 'long_sleep' ? 100000000 : 0 ) + $dur;
			if ( ! isset( $best[ $row['day'] ] ) || $rank > $best[ $row['day'] ]['_rank'] ) {
				$row['_rank'] = $rank;
				$best[ $row['day'] ] = $row;
			}
		}
	}
	$days = array_unique( array_merge( array_keys( $best ), array_keys( $scores ) ) );
	$n = 0;
	foreach ( $days as $day ) {
		$row = isset( $best[ $day ] ) ? $best[ $day ] : array();
		bm_oura_upsert(
			$wpdb->prefix . 'bm_oura_sleep',
			array( 'wp_user_id' => (int) $user_id, 'sleep_day' => $day ),
			array(
				'oura_id'           => isset( $row['id'] ) ? substr( (string) $row['id'], 0, 64 ) : '',
				'sleep_type'        => isset( $row['type'] ) ? substr( (string) $row['type'], 0, 32 ) : '',
				'score'             => isset( $scores[ $day ] ) ? $scores[ $day ] : null,
				'efficiency'        => bm_oura_int_or_null( $row, 'efficiency' ),
				'latency_sec'       => bm_oura_int_or_null( $row, 'latency' ),
				'total_sleep_sec'   => bm_oura_int_or_null( $row, 'total_sleep_duration' ),
				'deep_sec'          => bm_oura_int_or_null( $row, 'deep_sleep_duration' ),
				'rem_sec'           => bm_oura_int_or_null( $row, 'rem_sleep_duration' ),
				'light_sec'         => bm_oura_int_or_null( $row, 'light_sleep_duration' ),
				'awake_sec'         => bm_oura_int_or_null( $row, 'awake_time' ),
				'time_in_bed_sec'   => bm_oura_int_or_null( $row, 'time_in_bed' ),
				'avg_hr'            => bm_oura_int_or_null( $row, 'average_heart_rate' ),
				'lowest_hr'         => bm_oura_int_or_null( $row, 'lowest_heart_rate' ),
				'avg_hrv'           => bm_oura_int_or_null( $row, 'average_hrv' ),
				'avg_breath'        => bm_oura_num_or_null( $row, 'average_breath' ),
				'bedtime_start'     => bm_oura_mysql_dt( $row['bedtime_start'] ?? '' ),
				'bedtime_end'       => bm_oura_mysql_dt( $row['bedtime_end'] ?? '' ),
				'contributors_json' => wp_json_encode( isset( $contrib[ $day ] ) ? $contrib[ $day ] : array() ),
				'raw_json'          => bm_oura_slim_json( $row, array( '_rank', 'heart_rate', 'hrv', 'movement_30_sec', 'sleep_phase_5_min', 'readiness' ) ),
			)
		);
		$n++;
	}
	return $n;
}

function bm_oura_pull_activity( $user_id, $start, $end ) {
	global $wpdb;
	$rows = bm_oura_collect( $user_id, 'daily_activity', $start, $end );
	if ( is_wp_error( $rows ) ) {
		return $rows->get_error_code();
	}
	$n = 0;
	foreach ( $rows as $row ) {
		if ( empty( $row['day'] ) ) {
			continue;
		}
		bm_oura_upsert(
			$wpdb->prefix . 'bm_oura_activity',
			array( 'wp_user_id' => (int) $user_id, 'activity_day' => $row['day'] ),
			array(
				'oura_id'               => isset( $row['id'] ) ? substr( (string) $row['id'], 0, 64 ) : '',
				'score'                 => bm_oura_int_or_null( $row, 'score' ),
				'steps'                 => bm_oura_int_or_null( $row, 'steps' ),
				'active_calories'       => bm_oura_int_or_null( $row, 'active_calories' ),
				'total_calories'        => bm_oura_int_or_null( $row, 'total_calories' ),
				'equivalent_walking_m'  => bm_oura_int_or_null( $row, 'equivalent_walking_distance' ),
				'high_sec'              => bm_oura_int_or_null( $row, 'high_activity_time' ),
				'medium_sec'            => bm_oura_int_or_null( $row, 'medium_activity_time' ),
				'low_sec'               => bm_oura_int_or_null( $row, 'low_activity_time' ),
				'sedentary_sec'         => bm_oura_int_or_null( $row, 'sedentary_time' ),
				'non_wear_sec'          => bm_oura_int_or_null( $row, 'non_wear_time' ),
				'raw_json'              => bm_oura_slim_json( $row, array( 'class_5_min', 'met' ) ),
			)
		);
		$n++;
	}
	return $n;
}

function bm_oura_pull_spo2( $user_id, $start, $end ) {
	global $wpdb;
	$rows = bm_oura_collect( $user_id, 'daily_spo2', $start, $end );
	if ( is_wp_error( $rows ) ) {
		return $rows->get_error_code();
	}
	$n = 0;
	foreach ( $rows as $row ) {
		if ( empty( $row['day'] ) ) {
			continue;
		}
		$avg = null;
		if ( isset( $row['spo2_percentage']['average'] ) ) {
			$avg = (float) $row['spo2_percentage']['average'];
		} elseif ( isset( $row['average'] ) ) {
			$avg = (float) $row['average'];
		}
		bm_oura_upsert(
			$wpdb->prefix . 'bm_oura_spo2',
			array( 'wp_user_id' => (int) $user_id, 'spo2_day' => $row['day'] ),
			array(
				'oura_id'  => isset( $row['id'] ) ? substr( (string) $row['id'], 0, 64 ) : '',
				'avg_pct'  => $avg,
				'raw_json' => bm_oura_slim_json( $row, array( 'spo2_samples' ) ),
			)
		);
		$n++;
	}
	return $n;
}

add_action( 'bm_oura_nightly_sync', function () {
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'bm_oura_access_token' AND meta_value != ''" );
	if ( ! $ids ) {
		return;
	}
	foreach ( $ids as $user_id ) {
		bm_oura_run_sync( (int) $user_id, 3 );
	}
} );

/* ---------------------------------------------------------------------
 * OAuth
 * ------------------------------------------------------------------- */

function bm_oura_build_authorize_url( $user_id ) {
	if ( bm_oura_client_id() === '' ) {
		return '';
	}
	$state = wp_generate_uuid4();
	update_user_meta( $user_id, 'bm_oura_oauth_state', $state );
	return BM_OURA_AUTHORIZE . '?' . http_build_query( array(
		'response_type' => 'code',
		'client_id'     => bm_oura_client_id(),
		'redirect_uri'  => bm_oura_redirect_uri(),
		'scope'         => BM_OURA_SCOPE,
		'state'         => $state,
	), '', '&', PHP_QUERY_RFC3986 );
}

function bm_oura_apply_tag( $user_id ) {
	if ( function_exists( 'wp_fusion' ) && wp_fusion()->user ) {
		wp_fusion()->user->apply_tags( array( BM_OURA_TAG ), $user_id );
	}
}
function bm_oura_remove_tag( $user_id ) {
	if ( function_exists( 'wp_fusion' ) && wp_fusion()->user ) {
		wp_fusion()->user->remove_tags( array( BM_OURA_TAG ), $user_id );
	}
}

function bm_oura_is_callback_request() {
	if ( is_page( 'oura-auth' ) ) {
		return true;
	}
	if ( isset( $_GET['bm_oura_oauth'] ) && $_GET['bm_oura_oauth'] === 'callback' ) {
		return true;
	}
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
	$path = trim( (string) $path, '/' );
	if ( $path === 'oura-auth' ) {
		return true;
	}
	$configured = wp_parse_url( bm_oura_redirect_uri(), PHP_URL_PATH );
	$configured = trim( (string) $configured, '/' );
	return $configured !== '' && $path === $configured;
}

add_action( 'template_redirect', function () {
	if ( ! bm_oura_is_callback_request() || empty( $_GET['code'] ) && empty( $_GET['error'] ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( bm_oura_redirect_uri() ) );
		exit;
	}
	$user_id = get_current_user_id();
	if ( ! empty( $_GET['error'] ) ) {
		wp_safe_redirect( home_url( '/oura/?oura=error' ) );
		exit;
	}
	$expected = (string) get_user_meta( $user_id, 'bm_oura_oauth_state', true );
	delete_user_meta( $user_id, 'bm_oura_oauth_state' );
	$state = isset( $_GET['state'] ) ? (string) wp_unslash( $_GET['state'] ) : '';
	if ( $expected === '' || ! hash_equals( $expected, $state ) ) {
		wp_safe_redirect( home_url( '/oura/?oura=state_error' ) );
		exit;
	}
	$response = wp_remote_post( BM_OURA_TOKEN, array(
		'timeout' => 20,
		'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
		'body'    => array(
			'grant_type'    => 'authorization_code',
			'code'          => sanitize_text_field( wp_unslash( $_GET['code'] ) ),
			'redirect_uri'  => bm_oura_redirect_uri(),
			'client_id'     => bm_oura_client_id(),
			'client_secret' => bm_oura_client_secret(),
		),
	) );
	$body = is_wp_error( $response ) ? array() : json_decode( wp_remote_retrieve_body( $response ), true );
	if ( is_wp_error( $response ) || ! bm_oura_store_token_response( $user_id, is_array( $body ) ? $body : array() ) ) {
		$status = is_wp_error( $response ) ? $response->get_error_message() : (int) wp_remote_retrieve_response_code( $response );
		bm_oura_log( 'Token exchange failed ' . $status . ' | user ' . $user_id, 'error' );
		wp_safe_redirect( home_url( '/oura/?oura=token_error' ) );
		exit;
	}
	bm_oura_apply_tag( $user_id );
	bm_oura_run_sync( $user_id, 7 );
	wp_safe_redirect( home_url( '/oura/?oura=connected' ) );
	exit;
} );

add_action( 'admin_post_bm_oura_disconnect', 'bm_oura_handle_disconnect' );
function bm_oura_handle_disconnect() {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url() );
		exit;
	}
	check_admin_referer( 'bm_oura_disconnect' );
	$user_id = get_current_user_id();
	bm_oura_clear_tokens( $user_id );
	bm_oura_remove_tag( $user_id );
	wp_safe_redirect( home_url( '/oura/?oura=disconnected' ) );
	exit;
}

add_shortcode( 'bm_oura_connect', function () {
	if ( ! is_user_logged_in() ) {
		return '<p>Please log in to connect Oura.</p>';
	}
	$user_id = get_current_user_id();
	if ( bm_oura_is_connected( $user_id ) ) {
		return '';
	}
	$url = bm_oura_build_authorize_url( $user_id );
	if ( $url === '' ) {
		return '<p>Oura app credentials are not configured.</p>';
	}
	return '<a class="button button-primary" href="' . esc_url( $url ) . '">Connect Oura</a>';
} );

add_shortcode( 'bm_oura_connected', function () {
	if ( ! is_user_logged_in() || ! bm_oura_is_connected( get_current_user_id() ) ) {
		return '';
	}
	return '<strong>Oura connected</strong>';
} );

add_shortcode( 'bm_oura_disconnect', function () {
	if ( ! is_user_logged_in() || ! bm_oura_is_connected( get_current_user_id() ) ) {
		return '';
	}
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=bm_oura_disconnect' ), 'bm_oura_disconnect' );
	return '<a class="button" href="' . esc_url( $url ) . '">Disconnect Oura</a>';
} );

add_shortcode( 'bm_oura_last_sync', function () {
	if ( ! is_user_logged_in() ) {
		return '';
	}
	$last = get_user_meta( get_current_user_id(), 'bm_oura_last_sync', true );
	if ( ! $last ) {
		return '<span>Oura has not synced yet.</span>';
	}
	return '<span>Last Oura sync: <strong>' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) ) . '</strong></span>';
} );

add_shortcode( 'bm_oura_sync_now', function () {
	if ( ! is_user_logged_in() ) {
		return '<p>Please log in to sync Oura.</p>';
	}
	$url = wp_nonce_url( add_query_arg( 'bm_oura_sync', '1', home_url( '/oura/' ) ), 'bm_oura_sync' );
	return '<a class="button" href="' . esc_url( $url ) . '">Sync Oura now</a>';
} );

add_action( 'template_redirect', function () {
	if ( empty( $_GET['bm_oura_sync'] ) || $_GET['bm_oura_sync'] !== '1' ) {
		return;
	}
	if ( ! is_user_logged_in() || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'bm_oura_sync' ) ) {
		wp_die( 'Invalid sync request', 403 );
	}
	bm_oura_run_sync( get_current_user_id(), 7 );
	wp_safe_redirect( home_url( '/oura/?oura=synced' ) );
	exit;
} );
