<?php
/**
 * Microsoft Graph / Outlook write-back.
 *
 * Phase 1: per-Provider OAuth + create event on confirm + delete on cancel.
 *
 * @package BMF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Calendar_Outlook {

	const SCOPE          = 'offline_access User.Read Calendars.ReadWrite';
	const META_ACCESS    = 'bmf_cal_outlook_access';
	const META_REFRESH   = 'bmf_cal_outlook_refresh';
	const META_EXPIRES   = 'bmf_cal_outlook_expires';
	const TRANSIENT_PREF = 'bmf_cal_ol_state_';

	/** @var BMF_Calendar_Outlook|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_bmf_cal_outlook_start', array( $this, 'start_oauth' ) );
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_pretty_callback' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		add_action( 'wp_ajax_bmf_cal_outlook_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_bmf_cal_outlook_status', array( $this, 'ajax_status' ) );
	}

	public function add_rewrite() {
		add_rewrite_rule( '^bmf-calendar/outlook-callback/?$', 'index.php?bmf_cal_outlook=1', 'top' );
		add_rewrite_rule( '^bmf-calendar/outlook-connect/?$', 'index.php?bmf_cal_outlook_start=1', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = 'bmf_cal_outlook';
		$vars[] = 'bmf_cal_outlook_start';
		return $vars;
	}

	public function maybe_handle_pretty_callback() {
		if ( (int) get_query_var( 'bmf_cal_outlook_start' ) === 1 ) {
			$this->start_oauth();
			exit;
		}
		if ( (int) get_query_var( 'bmf_cal_outlook' ) !== 1 ) {
			return;
		}
		$this->handle_callback();
	}

	public static function connect_url() {
		return home_url( '/bmf-calendar/outlook-connect/' );
	}

	public function register_rest() {
		register_rest_route(
			'bmf-calendar/v1',
			'/outlook/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function configured() {
		return (bool) get_option( 'bmf_calendar_ms_client_id', '' )
			&& (bool) get_option( 'bmf_calendar_ms_client_secret', '' );
	}

	public static function redirect_uri() {
		// Azure rejects redirect URIs that contain a query string.
		return home_url( '/bmf-calendar/outlook-callback/' );
	}

	public static function is_connected( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		global $wpdb;
		$table  = BMF_Calendar_DB::outlook_table();
		$status = $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$table} WHERE user_id = %d", $user_id )
		);
		if ( 'connected' !== $status ) {
			return false;
		}
		// Personal Microsoft accounts sometimes omit a refresh token.
		return (bool) get_user_meta( $user_id, self::META_REFRESH, true )
			|| (bool) get_user_meta( $user_id, self::META_ACCESS, true );
	}

	/**
	 * Token is dead and cannot be refreshed — Provider must Connect again.
	 */
	public static function needs_reconnect( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! self::is_connected( $user_id ) ) {
			return false;
		}
		$expires = (int) get_user_meta( $user_id, self::META_EXPIRES, true );
		$refresh = get_user_meta( $user_id, self::META_REFRESH, true );
		if ( $expires && $expires <= time() && ! $refresh ) {
			return true;
		}
		$err = (string) get_user_meta( $user_id, 'bmf_cal_outlook_last_error', true );
		return ( false !== stripos( $err, 'expired' ) || false !== stripos( $err, 'reconnect' ) );
	}

	public static function connection_info( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		global $wpdb;
		$table = BMF_Calendar_DB::outlook_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ),
			ARRAY_A
		);
		return $row ? $row : array();
	}

	public function start_oauth() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::connect_url() ) );
			exit;
		}
		if ( ! BMF_Calendar_Provider::is_provider() && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Provider access required.', 'bmf-calendar' ) );
		}
		if ( ! self::configured() ) {
			wp_die( esc_html__( 'Outlook is not configured. Add Azure credentials under Settings → BMF Calendar.', 'bmf-calendar' ) );
		}

		$state = wp_generate_password( 24, false );
		set_transient(
			self::TRANSIENT_PREF . $state,
			array(
				'user_id' => get_current_user_id(),
				'return'  => wp_get_referer() ? wp_get_referer() : home_url(),
			),
			15 * MINUTE_IN_SECONDS
		);

		$tenant = get_option( 'bmf_calendar_ms_tenant', 'common' );
		if ( ! $tenant ) {
			$tenant = 'common';
		}

		$url = add_query_arg(
			array(
				'client_id'     => get_option( 'bmf_calendar_ms_client_id' ),
				'response_type' => 'code',
				'redirect_uri'  => self::redirect_uri(),
				'response_mode' => 'query',
				'scope'         => self::SCOPE,
				'state'         => $state,
				'prompt'        => 'consent',
			),
			'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/authorize'
		);

		wp_redirect( $url );
		exit;
	}

	public function handle_callback( $request = null ) {
		$get = array();
		if ( $request instanceof WP_REST_Request ) {
			$get = $request->get_query_params();
		} else {
			$get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$state = isset( $get['state'] ) ? sanitize_text_field( wp_unslash( $get['state'] ) ) : '';
		$code  = isset( $get['code'] ) ? sanitize_text_field( wp_unslash( $get['code'] ) ) : '';
		$error = isset( $get['error'] ) ? sanitize_text_field( wp_unslash( $get['error'] ) ) : '';

		$payload = $state ? get_transient( self::TRANSIENT_PREF . $state ) : false;
		delete_transient( self::TRANSIENT_PREF . $state );

		$return = ( is_array( $payload ) && ! empty( $payload['return'] ) ) ? $payload['return'] : home_url();
		$user_id = ( is_array( $payload ) && ! empty( $payload['user_id'] ) ) ? (int) $payload['user_id'] : 0;

		if ( $error || ! $code || ! $user_id ) {
			$return = add_query_arg( 'bmf_ol', $error ? 'denied' : 'error', $return );
			wp_safe_redirect( $return );
			exit;
		}

		$tokens = $this->exchange_code( $code );
		if ( is_wp_error( $tokens ) ) {
			$return = add_query_arg( 'bmf_ol', 'token_error', $return );
			wp_safe_redirect( $return );
			exit;
		}

		$this->store_tokens( $user_id, $tokens );
		$me = $this->graph_get( $user_id, '/me' );
		$name  = '';
		$email = '';
		$ms_id = '';
		if ( ! is_wp_error( $me ) && is_array( $me ) ) {
			$name  = isset( $me['displayName'] ) ? $me['displayName'] : '';
			$email = isset( $me['mail'] ) && $me['mail'] ? $me['mail'] : ( isset( $me['userPrincipalName'] ) ? $me['userPrincipalName'] : '' );
			$ms_id = isset( $me['id'] ) ? $me['id'] : '';
		}

		$this->upsert_connection(
			$user_id,
			array(
				'ms_account_id' => $ms_id,
				'display_name'  => $name,
				'email'         => $email,
				'status'        => 'connected',
			)
		);

		$return = add_query_arg( 'bmf_ol', 'connected', $return );
		wp_safe_redirect( $return );
		exit;
	}

	public function ajax_disconnect() {
		check_ajax_referer( 'bmf_calendar', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
		}
		$user_id = get_current_user_id();
		delete_user_meta( $user_id, self::META_ACCESS );
		delete_user_meta( $user_id, self::META_REFRESH );
		delete_user_meta( $user_id, self::META_EXPIRES );

		global $wpdb;
		$wpdb->delete( BMF_Calendar_DB::outlook_table(), array( 'user_id' => $user_id ), array( '%d' ) );

		wp_send_json_success( array( 'connected' => false ) );
	}

	public function ajax_status() {
		check_ajax_referer( 'bmf_calendar', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
		}
		$info = self::connection_info();
		wp_send_json_success(
			array(
				'configured'   => self::configured(),
				'connected'    => self::is_connected(),
				'display_name' => isset( $info['display_name'] ) ? $info['display_name'] : '',
				'email'        => isset( $info['email'] ) ? $info['email'] : '',
				'connect_url'  => self::connect_url(),
				'last_error'       => (string) get_user_meta( get_current_user_id(), 'bmf_cal_outlook_last_error', true ),
				'needs_reconnect'  => self::needs_reconnect(),
			)
		);
	}

	/**
	 * Create an Outlook event when an appointment is confirmed.
	 * Never blocks the WordPress save if Graph fails.
	 */
	public static function on_confirmed( $appointment ) {
		if ( empty( $appointment['provider_id'] ) ) {
			self::set_last_error( 0, 'No provider on the appointment, so nothing to write to Outlook.' );
			return;
		}
		if ( ! empty( $appointment['outlook_event_id'] ) ) {
			return;
		}
		$provider_id = (int) $appointment['provider_id'];
		if ( ! self::is_connected( $provider_id ) ) {
			self::set_last_error( $provider_id, 'Provider is not connected to Outlook.' );
			return;
		}

		$self = self::instance();
		$id   = $self->create_event( $appointment );
		if ( is_wp_error( $id ) || ! $id ) {
			$msg = is_wp_error( $id ) ? $id->get_error_message() : 'Graph returned no event id.';
			self::set_last_error( $provider_id, $msg );
			return;
		}
		self::set_last_error( $provider_id, '' );

		global $wpdb;
		$wpdb->update(
			BMF_Calendar_DB::appointments_table(),
			array( 'outlook_event_id' => $id ),
			array( 'id' => (int) $appointment['id'] ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Remove the Outlook event when an appointment is cancelled or deleted.
	 */
	public static function on_cancelled( $appointment ) {
		if ( empty( $appointment['outlook_event_id'] ) || empty( $appointment['provider_id'] ) ) {
			return;
		}
		if ( ! self::is_connected( (int) $appointment['provider_id'] ) ) {
			return;
		}
		self::instance()->delete_event( (int) $appointment['provider_id'], $appointment['outlook_event_id'] );

		global $wpdb;
		$wpdb->update(
			BMF_Calendar_DB::appointments_table(),
			array( 'outlook_event_id' => null ),
			array( 'id' => (int) $appointment['id'] ),
			array( '%s' ),
			array( '%d' )
		);
	}

	private function create_event( $appt ) {
		$member  = '';
		if ( ! empty( $appt['member_id'] ) ) {
			$u = get_userdata( (int) $appt['member_id'] );
			$member = $u ? ( $u->display_name ? $u->display_name : $u->user_email ) : '';
		}
		if ( ! $member && ! empty( $appt['member_email'] ) ) {
			$member = $appt['member_email'];
		}

		$subject = ! empty( $appt['subject'] ) ? $appt['subject'] : __( 'Appointment', 'bmf-calendar' );
		if ( $member ) {
			$subject .= ' — ' . $member;
		}

		$body_lines = array();
		if ( $member ) {
			$body_lines[] = 'Member: ' . $member;
		}
		if ( ! empty( $appt['description'] ) ) {
			$body_lines[] = wp_strip_all_tags( $appt['description'] );
		}
		$body_lines[] = 'Created from BMF Calendar.';

		$start_utc = $this->graph_datetime_utc( $appt['start_at'] );
		$end_src   = ! empty( $appt['end_at'] ) ? $appt['end_at'] : $appt['start_at'];
		$end_utc   = $this->graph_datetime_utc( $end_src );
		if ( $end_utc === $start_utc ) {
			$end_dt = new DateTime( $end_utc, new DateTimeZone( 'UTC' ) );
			$end_dt->modify( '+30 minutes' );
			$end_utc = $end_dt->format( 'Y-m-d\TH:i:s' );
		}

		$payload = array(
			'subject' => $subject,
			'body'    => array(
				'contentType' => 'text',
				'content'     => implode( "\n", $body_lines ),
			),
			'start'   => array(
				'dateTime' => $start_utc,
				'timeZone' => 'UTC',
			),
			'end'     => array(
				'dateTime' => $end_utc,
				'timeZone' => 'UTC',
			),
		);
		if ( ! empty( $appt['location'] ) ) {
			$payload['location'] = array( 'displayName' => $appt['location'] );
		}

		$res = $this->graph_request( (int) $appt['provider_id'], 'POST', '/me/calendar/events', $payload );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['id'] ) ? $res['id'] : '';
	}

	private function delete_event( $user_id, $event_id ) {
		$event_id = rawurlencode( $event_id );
		$this->graph_request( $user_id, 'DELETE', '/me/events/' . $event_id );
	}

	private function graph_datetime_utc( $mysql ) {
		$mysql = substr( (string) $mysql, 0, 19 );
		$dt    = DateTime::createFromFormat( 'Y-m-d H:i:s', $mysql, wp_timezone() );
		if ( ! $dt ) {
			try {
				$dt = new DateTime( $mysql, wp_timezone() );
			} catch ( Exception $e ) {
				return str_replace( ' ', 'T', $mysql );
			}
		}
		$dt->setTimezone( new DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d\TH:i:s' );
	}

	private static function set_last_error( $user_id, $message ) {
		if ( $user_id ) {
			if ( $message ) {
				update_user_meta( $user_id, 'bmf_cal_outlook_last_error', $message );
			} else {
				delete_user_meta( $user_id, 'bmf_cal_outlook_last_error' );
			}
		}
		if ( $message ) {
			error_log( 'BMF Calendar Outlook: ' . $message );
		}
	}

	private function exchange_code( $code ) {
		return $this->token_request(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => self::redirect_uri(),
			)
		);
	}

	private function refresh_tokens( $user_id ) {
		$refresh = $this->decrypt( get_user_meta( $user_id, self::META_REFRESH, true ) );
		if ( ! $refresh ) {
			return new WP_Error( 'no_refresh', 'No refresh token' );
		}
		$tokens = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			)
		);
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		$this->store_tokens( $user_id, $tokens );
		return $tokens;
	}

	private function token_request( $extra ) {
		$tenant = get_option( 'bmf_calendar_ms_tenant', 'common' );
		if ( ! $tenant ) {
			$tenant = 'common';
		}
		$body = array_merge(
			array(
				'client_id'     => get_option( 'bmf_calendar_ms_client_id' ),
				'client_secret' => get_option( 'bmf_calendar_ms_client_secret' ),
				'scope'         => self::SCOPE,
			),
			$extra
		);

		$res = wp_remote_post(
			'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token',
			array(
				'timeout' => 20,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['access_token'] ) ) {
			$msg = isset( $data['error_description'] ) ? $data['error_description'] : 'Token request failed';
			return new WP_Error( 'token', $msg );
		}
		return $data;
	}

	private function store_tokens( $user_id, $tokens ) {
		update_user_meta( $user_id, self::META_ACCESS, $this->encrypt( $tokens['access_token'] ) );
		if ( ! empty( $tokens['refresh_token'] ) ) {
			update_user_meta( $user_id, self::META_REFRESH, $this->encrypt( $tokens['refresh_token'] ) );
		}
		$expires = time() + ( isset( $tokens['expires_in'] ) ? (int) $tokens['expires_in'] : 3600 ) - 60;
		update_user_meta( $user_id, self::META_EXPIRES, $expires );
	}

	private function access_token( $user_id ) {
		$expires = (int) get_user_meta( $user_id, self::META_EXPIRES, true );
		if ( $expires && $expires <= time() ) {
			$has_refresh = (bool) get_user_meta( $user_id, self::META_REFRESH, true );
			if ( ! $has_refresh ) {
				return new WP_Error(
					'expired',
					'Outlook session expired. Disconnect and Connect Outlook again (Hotmail often needs a fresh sign-in after about an hour).'
				);
			}
			$refreshed = $this->refresh_tokens( $user_id );
			if ( is_wp_error( $refreshed ) ) {
				return new WP_Error(
					'expired',
					'Outlook session expired. Disconnect and Connect Outlook again. ' . $refreshed->get_error_message()
				);
			}
		}
		$token = $this->decrypt( get_user_meta( $user_id, self::META_ACCESS, true ) );
		if ( ! $token ) {
			return new WP_Error( 'no_token', 'Not connected' );
		}
		return $token;
	}

	private function graph_get( $user_id, $path ) {
		return $this->graph_request( $user_id, 'GET', $path );
	}

	private function graph_request( $user_id, $method, $path, $body = null ) {
		$token = $this->access_token( $user_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$res  = wp_remote_request( 'https://graph.microsoft.com/v1.0' . $path, $args );
		$code = wp_remote_retrieve_response_code( $res );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $code >= 400 ) {
			$raw  = wp_remote_retrieve_body( $res );
			$data = json_decode( $raw, true );
			$msg  = 'Graph error ' . $code;
			if ( is_array( $data ) && ! empty( $data['error']['message'] ) ) {
				$msg .= ': ' . $data['error']['message'];
			} elseif ( $raw ) {
				$msg .= ': ' . substr( wp_strip_all_tags( $raw ), 0, 300 );
			}
			return new WP_Error( 'graph', $msg );
		}
		$raw = wp_remote_retrieve_body( $res );
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function upsert_connection( $user_id, $fields ) {
		global $wpdb;
		$table = BMF_Calendar_DB::outlook_table();
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id ) );
		$fields['user_id'] = $user_id;
		if ( $exists ) {
			$wpdb->update( $table, $fields, array( 'user_id' => $user_id ) );
		} else {
			$wpdb->insert( $table, $fields );
		}
	}

	private function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( $plain === '' ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $enc ) {
			return '';
		}
		return base64_encode( $iv . $enc );
	}

	private function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( $stored === '' ) {
			return '';
		}
		$raw = base64_decode( $stored, true );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = substr( $raw, 0, 16 );
		$enc = substr( $raw, 16 );
		$out = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false === $out ? '' : $out;
	}
}
