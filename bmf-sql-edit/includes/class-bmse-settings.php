<?php
/**
 * Settings pages for BMF SQL Edit (toolbar defaults + license).
 *
 * @package BMF_SQL_Edit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BMSE_Settings
 */
class BMSE_Settings {

	const OPTION = 'bmse_defaults';

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_bmse_save_license', array( __CLASS__, 'handle_license_form' ) );
	}

	/**
	 * Register the Settings submenu under Tools.
	 */
	public static function menu() {
		add_submenu_page(
			'tools.php',
			__( 'BMF SQL Edit Settings', 'bmf-sql-edit' ),
			__( 'BMF SQL Edit', 'bmf-sql-edit' ),
			'manage_options',
			'bmse-settings',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register toolbar-default settings.
	 */
	public static function register() {
		register_setting( 'bmse_settings', self::OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			'default'           => self::defaults(),
		) );

		add_settings_section(
			'bmse_section_main',
			__( 'Toolbar Defaults', 'bmf-sql-edit' ),
			function () {
				echo '<p>' . esc_html__(
					'Choose the default state of toolbar toggles when the SQL page loads. Users can still change them per session. Write / Edit options are only available with a valid Pro license.',
					'bmf-sql-edit'
				) . '</p>';
			},
			'bmse_settings'
		);

		self::checkbox( 'allow_write', __( 'Allow write queries (default)', 'bmf-sql-edit' ) );
		self::checkbox( 'append_limit', __( 'Append LIMIT when missing (default)', 'bmf-sql-edit' ) );
		self::checkbox( 'edit_mode', __( 'Edit mode (default)', 'bmf-sql-edit' ) );
		self::checkbox( 'auto_run', __( 'Auto-run updates (default)', 'bmf-sql-edit' ) );
		self::checkbox( 'auto_add_pk', __( 'Auto-add PK for edits (default)', 'bmf-sql-edit' ) );
		self::checkbox( 'auto_run_history', __( 'Auto-run history SELECT (default)', 'bmf-sql-edit' ) );
	}

	/**
	 * Helper to register a checkbox field.
	 *
	 * @param string $key   Option key.
	 * @param string $label Field label.
	 */
	private static function checkbox( $key, $label ) {
		add_settings_field(
			'bmse_' . $key,
			$label,
			function () use ( $key ) {
				$opts    = get_option( self::OPTION, self::defaults() );
				$checked = ! empty( $opts[ $key ] ) ? 'checked' : '';
				echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']" value="1" ' . $checked . '> ';
				echo '</label>';
			},
			'bmse_settings',
			'bmse_section_main'
		);
	}

	/**
	 * Sanitize toolbar defaults.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$out = self::defaults();
		foreach ( $out as $k => $v ) {
			$out[ $k ] = ! empty( $input[ $k ] ) ? 1 : 0;
		}
		return $out;
	}

	/**
	 * Default toolbar values (conservative).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'allow_write'      => 0,
			'append_limit'     => 1,
			'edit_mode'        => 0,
			'auto_run'         => 0,
			'auto_add_pk'      => 1,
			'auto_run_history' => 0,
		);
	}

	/**
	 * Handle license key save / activate / deactivate / refresh.
	 */
	public static function handle_license_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bmf-sql-edit' ) );
		}

		check_admin_referer( 'bmse_license_action', 'bmse_license_nonce' );

		$license = BMSE_License::instance();
		$action  = isset( $_POST['bmse_license_action'] ) ? sanitize_key( $_POST['bmse_license_action'] ) : 'save';

		if ( 'save' === $action || 'activate' === $action ) {
			$key = isset( $_POST['bmse_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bmse_license_key'] ) ) : '';
			$license->set_key( $key );
		}

		$result = null;
		if ( 'activate' === $action ) {
			$result = $license->activate();
		} elseif ( 'deactivate' === $action ) {
			$result = $license->deactivate();
		} elseif ( 'refresh' === $action ) {
			$result = $license->poll_status( true );
		}

		$redirect = admin_url( 'tools.php?page=bmse-settings' );
		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'bmse_msg', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg( 'bmse_msg', 'ok', $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the full settings screen (license + toolbar defaults).
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bmf-sql-edit' ) );
		}

		$license = BMSE_License::instance();
		$key     = $license->get_key();
		$status  = $license->get_cached_status();
		$is_pro  = $license->is_pro();

		// Soft-refresh status when viewing the page (uses transient).
		if ( $key ) {
			$license->poll_status( false );
			$status = $license->get_cached_status();
			$is_pro = $license->is_pro();
		}

		$msg = isset( $_GET['bmse_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bmse_msg'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BMF SQL Edit — Settings', 'bmf-sql-edit' ); ?></h1>

			<?php if ( $msg && 'ok' !== $msg ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php elseif ( 'ok' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License action completed.', 'bmf-sql-edit' ); ?></p></div>
			<?php endif; ?>

			<!-- License section -->
			<h2><?php esc_html_e( 'Pro License', 'bmf-sql-edit' ); ?></h2>
			<p>
				<?php
				if ( $is_pro ) {
					echo '<span style="color:#15803d;font-weight:600;">' . esc_html__( 'Pro features are active.', 'bmf-sql-edit' ) . '</span>';
				} else {
					echo '<span style="color:#b45309;font-weight:600;">' . esc_html__( 'Running in Lite mode (read-only).', 'bmf-sql-edit' ) . '</span>';
				}
				?>
			</p>

			<?php if ( ! empty( $status['message'] ) ) : ?>
				<p><em><?php echo esc_html( $status['message'] ); ?></em></p>
			<?php endif; ?>
			<?php if ( ! empty( $status['checked_at'] ) ) : ?>
				<p class="description"><?php printf( esc_html__( 'Last checked: %s', 'bmf-sql-edit' ), esc_html( $status['checked_at'] ) ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:2em;">
				<input type="hidden" name="action" value="bmse_save_license" />
				<?php wp_nonce_field( 'bmse_license_action', 'bmse_license_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bmse_license_key"><?php esc_html_e( 'License Key', 'bmf-sql-edit' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="bmse_license_key" name="bmse_license_key"
								value="<?php echo esc_attr( $key ); ?>"
								placeholder="<?php esc_attr_e( 'Paste your Pro license key here', 'bmf-sql-edit' ); ?>"
								autocomplete="off" />
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="bmse_license_action" value="save" class="button"><?php esc_html_e( 'Save Key', 'bmf-sql-edit' ); ?></button>
					<button type="submit" name="bmse_license_action" value="activate" class="button button-primary"><?php esc_html_e( 'Activate', 'bmf-sql-edit' ); ?></button>
					<button type="submit" name="bmse_license_action" value="refresh" class="button"><?php esc_html_e( 'Refresh Status', 'bmf-sql-edit' ); ?></button>
					<button type="submit" name="bmse_license_action" value="deactivate" class="button" onclick="return confirm('<?php echo esc_js( __( 'Deactivate this license on the current site?', 'bmf-sql-edit' ) ); ?>');">
						<?php esc_html_e( 'Deactivate', 'bmf-sql-edit' ); ?>
					</button>
				</p>
			</form>

			<hr />

			<!-- Toolbar defaults -->
			<form method="post" action="options.php">
				<?php
				settings_fields( 'bmse_settings' );
				do_settings_sections( 'bmse_settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
