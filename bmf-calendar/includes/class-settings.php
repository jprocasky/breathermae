<?php
/**
 * Admin settings page (Settings → BMF Calendar).
 *
 * @package BMF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Calendar_Settings {

	/** @var BMF_Calendar_Settings|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'BMF Calendar', 'bmf-calendar' ),
			__( 'BMF Calendar', 'bmf-calendar' ),
			'manage_options',
			'bmf-calendar',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( 'bmf_calendar_settings', 'bmf_calendar_provider_method', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_method' ),
			'default'           => 'capability',
		) );

		register_setting( 'bmf_calendar_settings', 'bmf_calendar_provider_tags', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_tags' ),
			'default'           => array(),
		) );

		register_setting( 'bmf_calendar_settings', 'bmf_calendar_manual_providers', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_ids' ),
			'default'           => array(),
		) );

		// Outlook app credentials (placeholders for Phase 1)
		register_setting( 'bmf_calendar_settings', 'bmf_calendar_ms_client_id', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'bmf_calendar_settings', 'bmf_calendar_ms_client_secret', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'bmf_calendar_settings', 'bmf_calendar_ms_tenant', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'common',
		) );

		register_setting( 'bmf_calendar_settings', 'bmf_calendar_unassigned_email', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_emails' ),
			'default'           => '',
		) );
		register_setting( 'bmf_calendar_settings', 'bmf_calendar_email_subject_prefix', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'bmf_calendar_settings', 'bmf_calendar_provider_inbox_url', array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );
		register_setting( 'bmf_calendar_settings', 'bmf_calendar_member_appointments_url', array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );
		register_setting( 'bmf_calendar_settings', 'bmf_calendar_uls_reminders', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_flag' ),
			'default'           => '1',
		) );
	}

	public function sanitize_flag( $value ) {
		return ( '1' === (string) $value ) ? '1' : '0';
	}

	/**
	 * One or more emails, comma-separated.
	 */
	public function sanitize_emails( $value ) {
		$parts = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
		$out   = array();
		foreach ( $parts as $part ) {
			$email = sanitize_email( $part );
			if ( $email ) {
				$out[] = $email;
			}
		}
		return implode( ', ', $out );
	}

	public function sanitize_method( $value ) {
		$value = sanitize_text_field( $value );
		return in_array( $value, array( 'capability', 'wpfusion' ), true ) ? $value : 'capability';
	}

	public function sanitize_tags( $value ) {
		if ( ! is_array( $value ) ) {
			$value = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
		}
		return array_map( 'sanitize_text_field', $value );
	}

	public function sanitize_ids( $value ) {
		if ( ! is_array( $value ) ) {
			$value = array_filter( array_map( 'intval', explode( ',', (string) $value ) ) );
		}
		return array_map( 'intval', $value );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$method   = get_option( 'bmf_calendar_provider_method', 'capability' );
		$tags     = (array) get_option( 'bmf_calendar_provider_tags', array() );
		$manual   = (array) get_option( 'bmf_calendar_manual_providers', array() );
		$wpf_active = BMF_Calendar_Provider::is_wp_fusion_active();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BMF Calendar', 'bmf-calendar' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Provider–Member scheduling. WP Fusion is optional.', 'bmf-calendar' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'bmf_calendar_settings' ); ?>

				<h2 class="title"><?php esc_html_e( 'Provider Designation', 'bmf-calendar' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Method', 'bmf-calendar' ); ?></th>
						<td>
							<label>
								<input type="radio" name="bmf_calendar_provider_method" value="capability" <?php checked( $method, 'capability' ); ?> />
								<?php esc_html_e( 'WordPress capability / manual list (works everywhere)', 'bmf-calendar' ); ?>
							</label>
							<br /><br />
							<label>
								<input type="radio" name="bmf_calendar_provider_method" value="wpfusion" <?php checked( $method, 'wpfusion' ); ?> <?php disabled( ! $wpf_active ); ?> />
								<?php esc_html_e( 'WP Fusion tags', 'bmf-calendar' ); ?>
								<?php if ( ! $wpf_active ) : ?>
									<em>(<?php esc_html_e( 'WP Fusion not detected', 'bmf-calendar' ); ?>)</em>
								<?php endif; ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Provider tags (WP Fusion)', 'bmf-calendar' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="bmf_calendar_provider_tags" value="<?php echo esc_attr( implode( ', ', $tags ) ); ?>" placeholder="DOCTOR, COACH" />
							<p class="description"><?php esc_html_e( 'Comma-separated tag names or IDs that designate a user as a Provider.', 'bmf-calendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Manual Provider user IDs', 'bmf-calendar' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="bmf_calendar_manual_providers" value="<?php echo esc_attr( implode( ', ', $manual ) ); ?>" placeholder="12, 45, 78" />
							<p class="description"><?php esc_html_e( 'Optional extra user IDs treated as Providers (in addition to the capability).', 'bmf-calendar' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Notifications', 'bmf-calendar' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Email subject prefix', 'bmf-calendar' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="bmf_calendar_email_subject_prefix" value="<?php echo esc_attr( get_option( 'bmf_calendar_email_subject_prefix', '' ) ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Shown in brackets at the start of every calendar email. Leave blank to use the WordPress site title.', 'bmf-calendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Provider requests page URL', 'bmf-calendar' ); ?></th>
						<td>
							<input type="url" class="regular-text" name="bmf_calendar_provider_inbox_url" value="<?php echo esc_attr( get_option( 'bmf_calendar_provider_inbox_url', '' ) ); ?>" placeholder="https://" />
							<p class="description"><?php esc_html_e( 'Page that contains [bmf_open_requests]. Included as a link in the Provider request email.', 'bmf-calendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Member appointments page URL', 'bmf-calendar' ); ?></th>
						<td>
							<input type="url" class="regular-text" name="bmf_calendar_member_appointments_url" value="<?php echo esc_attr( get_option( 'bmf_calendar_member_appointments_url', '' ) ); ?>" placeholder="https://" />
							<p class="description"><?php esc_html_e( 'Page that contains [bmf_my_appointments] and/or [bmf_request_appointment]. Included in member confirm and decline emails.', 'bmf-calendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'ULS dashboard reminders', 'bmf-calendar' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="bmf_calendar_uls_reminders" value="0" />
								<input type="checkbox" name="bmf_calendar_uls_reminders" value="1" <?php checked( get_option( 'bmf_calendar_uls_reminders', '1' ), '1' ); ?> />
								<?php esc_html_e( 'On confirm, add an uls-members reminder for the member and the provider (if that plugin is present).', 'bmf-calendar' ); ?>
							</label>
							<p class="description">
								<?php
								if ( class_exists( 'BMF_Calendar_ULS_Bridge' ) && BMF_Calendar_ULS_Bridge::is_available() ) {
									esc_html_e( 'uls-members appointments table detected.', 'bmf-calendar' );
								} else {
									esc_html_e( 'uls-members appointments table not detected on this site. The checkbox is safe to leave on.', 'bmf-calendar' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Unassigned request email', 'bmf-calendar' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="bmf_calendar_unassigned_email" value="<?php echo esc_attr( get_option( 'bmf_calendar_unassigned_email', '' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Where to send requests that have no specific provider. Comma-separated if you want more than one inbox (e.g. support@yoursite.com). Leave blank to use the WordPress admin email.', 'bmf-calendar' ); ?>
								<?php esc_html_e( 'Assigned requests still go only to that provider.', 'bmf-calendar' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Microsoft / Outlook (Phase 1)', 'bmf-calendar' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Register an app in Azure AD (Microsoft Entra). Add a Web redirect URI that matches this exactly:', 'bmf-calendar' ); ?></p>
				<p><code><?php echo esc_html( BMF_Calendar_Outlook::redirect_uri() ); ?></code></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Application (client) ID', 'bmf-calendar' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="bmf_calendar_ms_client_id" value="<?php echo esc_attr( get_option( 'bmf_calendar_ms_client_id', '' ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Client secret', 'bmf-calendar' ); ?></th>
						<td>
							<input type="password" class="regular-text" name="bmf_calendar_ms_client_secret" value="<?php echo esc_attr( get_option( 'bmf_calendar_ms_client_secret', '' ) ); ?>" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tenant', 'bmf-calendar' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="bmf_calendar_ms_tenant" value="<?php echo esc_attr( get_option( 'bmf_calendar_ms_tenant', 'common' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Use "common" for multi-tenant, or a specific tenant ID.', 'bmf-calendar' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Shortcodes (scaffold)', 'bmf-calendar' ); ?></h2>
			<ul>
				<li><code>[bmf_my_appointments]</code> – Member’s own appointments</li>
				<li><code>[bmf_member_appointments]</code> – Provider view for a member (respects selected member when ULS present)</li>
				<li><code>[bmf_provider_calendar]</code> – Provider calendar + availability management</li>
				<li><code>[bmf_request_appointment]</code> – Member request flow (specific or general). Optional <code>exclude="TEST"</code></li>
				<li><code>[bmf_booked_calendar]</code> – All confirmed appointments across Providers</li>
				<li><code>[bmf_coverage_calendar]</code> – Hours vs booked (coverage gaps). Optional exclude="TEST"</li>
				<li><code>[bmf_provider_agenda]</code> – Read-only upcoming confirmed appointments for the logged-in Provider</li>
				<li><code>[bmf_open_requests]</code> – Provider inbox of pending requests (assigned + unassigned)</li>
			</ul>
		</div>
		<?php
	}
}
