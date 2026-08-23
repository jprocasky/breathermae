<?php
/**
 * License manager for BMF SQL Edit (Lite / Pro).
 *
 * Integrates with Software License for WooCommerce (NSP-CODE SRL)
 * using the status-check / activate / deactivate API methods.
 *
 * @package BMF_SQL_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BMSE_License
 */
class BMSE_License {

	/** Option key that stores the license key. */
	const OPTION_KEY = 'bmse_license_key';

	/** Option key that stores the last successful status payload. */
	const OPTION_STATUS = 'bmse_license_status';

	/** Transient that caches the last remote check. */
	const TRANSIENT_CHECK = 'bmse_license_last_check';

	/**
	 * Product Unique ID defined in the WooCommerce product License tab
	 * on blackmountainfactory.com.
	 *
	 * @var string
	 */
	const PRODUCT_UNIQUE_ID = 'BMF-SQL-EDIT';

	/**
	 * License server base URL (the site running Software License for WooCommerce).
	 *
	 * @var string
	 */
	const LICENSE_SERVER = 'https://blackmountainfactory.com';

	/** @var BMSE_License|null */
	private static $instance = null;

	/**
	 * Singleton.
	 *
	 * @return BMSE_License
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor – wire cron and admin notices.
	 */
	private function __construct() {
		add_action( 'bmse_daily_license_check', array( $this, 'poll_status' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_license_notice' ) );
	}

	/**
	 * Whether Pro features are currently unlocked.
	 *
	 * @return bool
	 */
	public function is_pro() {
		// Emergency local override.
		if ( defined( 'BMSE_FORCE_PRO' ) && BMSE_FORCE_PRO ) {
			return true;
		}

		// Hard disable still respected.
		if ( ! BMSE_ENABLED ) {
			return false;
		}

		$status = $this->get_cached_status();
		if ( empty( $status ) || empty( $status['status'] ) ) {
			return false;
		}

		// Accept only explicitly active / valid statuses.
		$valid = array( 'active', 'valid', 'success' );
		return in_array( strtolower( (string) $status['status'] ), $valid, true );
	}

	/**
	 * Retrieve the stored license key.
	 *
	 * @return string
	 */
	public function get_key() {
		return (string) get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Store a license key and clear cached status.
	 *
	 * @param string $key License key.
	 */
	public function set_key( $key ) {
		$key = sanitize_text_field( $key );
		update_option( self::OPTION_KEY, $key, false );
		delete_option( self::OPTION_STATUS );
		delete_transient( self::TRANSIENT_CHECK );
	}

	/**
	 * Clear key and all cached status data.
	 */
	public function clear_key() {
		delete_option( self::OPTION_KEY );
		delete_option( self::OPTION_STATUS );
		delete_transient( self::TRANSIENT_CHECK );
	}

	/**
	 * Return the last known status array (may be empty).
	 *
	 * @return array
	 */
	public function get_cached_status() {
		$status = get_option( self::OPTION_STATUS, array() );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * Perform a remote status-check (or activate) against the license server.
	 *
	 * @param bool $force Force a remote call even if the transient is still valid.
	 * @return array|WP_Error Status payload or error.
	 */
	public function poll_status( $force = false ) {
		$key = $this->get_key();
		if ( '' === $key ) {
			$this->store_status( array( 'status' => 'missing', 'message' => 'No license key entered.' ) );
			return $this->get_cached_status();
		}

		if ( ! $force && get_transient( self::TRANSIENT_CHECK ) ) {
			return $this->get_cached_status();
		}

		$domain = $this->get_site_domain();

		// First try a pure status-check.
		$response = $this->api_request( 'status-check', array(
			'licence_key'       => $key,
			'product_unique_id' => self::PRODUCT_UNIQUE_ID,
			'domain'            => $domain,
		) );

		if ( is_wp_error( $response ) ) {
			// Soft-fail: keep previous status if we have one, otherwise mark error.
			$prev = $this->get_cached_status();
			if ( empty( $prev ) ) {
				$this->store_status( array(
					'status'  => 'error',
					'message' => $response->get_error_message(),
				) );
			}
			return is_wp_error( $response ) ? $response : $this->get_cached_status();
		}

		// Normalise common response shapes from the NSP Software License API.
		$status = $this->normalise_response( $response );
		$this->store_status( $status );
		set_transient( self::TRANSIENT_CHECK, 1, 12 * HOUR_IN_SECONDS );

		return $status;
	}

	/**
	 * Attempt to activate the current key on this domain.
	 *
	 * @return array|WP_Error
	 */
	public function activate() {
		$key = $this->get_key();
		if ( '' === $key ) {
			return new WP_Error( 'bmse_no_key', __( 'Enter a license key first.', 'bmf-sql-edit' ) );
		}

		$response = $this->api_request( 'activate', array(
			'licence_key'       => $key,
			'product_unique_id' => self::PRODUCT_UNIQUE_ID,
			'domain'            => $this->get_site_domain(),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = $this->normalise_response( $response );
		$this->store_status( $status );
		delete_transient( self::TRANSIENT_CHECK );

		return $status;
	}

	/**
	 * Deactivate the key on this domain.
	 *
	 * @return array|WP_Error
	 */
	public function deactivate() {
		$key = $this->get_key();
		if ( '' === $key ) {
			return new WP_Error( 'bmse_no_key', __( 'No license key to deactivate.', 'bmf-sql-edit' ) );
		}

		$response = $this->api_request( 'deactivate', array(
			'licence_key'       => $key,
			'product_unique_id' => self::PRODUCT_UNIQUE_ID,
			'domain'            => $this->get_site_domain(),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->clear_key();
		return $this->normalise_response( $response );
	}

	/**
	 * Show a non-intrusive admin notice when running in Lite mode.
	 */
	public function maybe_show_license_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( false === strpos( $screen->id, 'bmse' ) && 'plugins' !== $screen->id ) ) {
			return;
		}

		if ( $this->is_pro() ) {
			return;
		}

		$settings_url = admin_url( 'tools.php?page=bmse-settings' );
		echo '<div class="notice notice-info is-dismissible"><p>';
		echo esc_html__( 'BMF SQL Edit is running in Lite mode (read-only).', 'bmf-sql-edit' );
		echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Enter a Pro license key', 'bmf-sql-edit' ) . '</a>';
		echo ' ' . esc_html__( 'to unlock write queries and inline editing.', 'bmf-sql-edit' );
		echo '</p></div>';
	}

	/**
	 * Low-level API call to the license server.
	 *
	 * @param string $action  woo_sl_action value (status-check, activate, deactivate).
	 * @param array  $params  Additional query parameters.
	 * @return array|WP_Error Decoded JSON or error.
	 */
	private function api_request( $action, array $params ) {
		$params['woo_sl_action'] = $action;

		$url = add_query_arg( $params, trailingslashit( self::LICENSE_SERVER ) );

		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Accept' => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'bmse_http', sprintf( 'HTTP %d from license server', $code ) );
		}

		$data = json_decode( $body, true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			// Some older endpoints return plain text; treat as error message.
			return new WP_Error( 'bmse_parse', $body ?: 'Empty response from license server' );
		}

		return is_array( $data ) ? $data : array( 'raw' => $body );
	}

	/**
	 * Normalise the various response shapes the NSP API can return
	 * into a consistent internal structure.
	 *
	 * @param array $raw Raw API response.
	 * @return array
	 */
	private function normalise_response( array $raw ) {
		$status  = '';
		$message = '';

		// Common patterns.
		if ( isset( $raw['status'] ) ) {
			$status = $raw['status'];
		} elseif ( isset( $raw['licence_status'] ) ) {
			$status = $raw['licence_status'];
		} elseif ( isset( $raw['license_status'] ) ) {
			$status = $raw['license_status'];
		} elseif ( isset( $raw['result'] ) ) {
			$status = $raw['result'];
		}

		if ( isset( $raw['message'] ) ) {
			$message = $raw['message'];
		} elseif ( isset( $raw['msg'] ) ) {
			$message = $raw['msg'];
		}

		// Some successful activations return "success" / "active".
		$status = strtolower( (string) $status );

		return array(
			'status'     => $status ?: 'unknown',
			'message'    => $message,
			'raw'        => $raw,
			'checked_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Persist status and clear the transient when we intentionally refresh.
	 *
	 * @param array $status Normalised status.
	 */
	private function store_status( array $status ) {
		update_option( self::OPTION_STATUS, $status, false );
	}

	/**
	 * Current site domain suitable for the license API.
	 *
	 * @return string
	 */
	private function get_site_domain() {
		$home = home_url();
		$host = wp_parse_url( $home, PHP_URL_HOST );
		return $host ? $host : $home;
	}
}
