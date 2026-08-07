<?php
/**
 * Admin settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_PWA_Settings {

	/** @var BMF_PWA_Settings */
	private static $instance = null;

	const OPTION_KEY = 'bmf_pwa_settings';

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
			__( 'BMF PWA Settings', 'bmf-pwa' ),
			__( 'BMF PWA', 'bmf-pwa' ),
			'manage_options',
			'bmf-pwa',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( 'bmf_pwa_group', self::OPTION_KEY, array( $this, 'sanitize' ) );
	}

	public static function get_settings() {
		$defaults = array(
			'name'            => get_bloginfo( 'name' ),
			'short_name'      => 'Breathermae',
			'description'     => get_bloginfo( 'description' ),
			'theme_color'     => '#0f172a',
			'background_color'=> '#0f172a',
			'start_url'       => '/',
			'display'         => 'standalone',
			'icon_192'        => '',
			'icon_512'        => '',
			'button_text'     => 'Install App',
			'button_class'    => '',
		);

		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, $defaults );
	}

	public function sanitize( $input ) {
		$output = array();

		$output['name']             = sanitize_text_field( $input['name'] ?? '' );
		$output['short_name']       = sanitize_text_field( $input['short_name'] ?? '' );
		$output['description']      = sanitize_textarea_field( $input['description'] ?? '' );
		$output['theme_color']      = sanitize_hex_color( $input['theme_color'] ?? '#0f172a' ) ?: '#0f172a';
		$output['background_color'] = sanitize_hex_color( $input['background_color'] ?? '#0f172a' ) ?: '#0f172a';
		$output['start_url']        = esc_url_raw( $input['start_url'] ?? '/' ) ?: '/';
		$output['display']          = in_array( $input['display'] ?? '', array( 'standalone', 'fullscreen', 'minimal-ui', 'browser' ), true )
			? $input['display'] : 'standalone';
		$output['icon_192']         = esc_url_raw( $input['icon_192'] ?? '' );
		$output['icon_512']         = esc_url_raw( $input['icon_512'] ?? '' );
		$output['button_text']      = sanitize_text_field( $input['button_text'] ?? 'Install App' );
		$output['button_class']     = sanitize_html_class( $input['button_class'] ?? '' );

		return $output;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BMF PWA Settings', 'bmf-pwa' ); ?></h1>
			<p>Configure the Progressive Web App so users can install Breathermae as a desktop / mobile app.</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'bmf_pwa_group' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bmf_pwa_name">App Name</label></th>
						<td>
							<input type="text" id="bmf_pwa_name" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[name]"
								value="<?php echo esc_attr( $settings['name'] ); ?>" class="regular-text" />
							<p class="description">Full name shown during install (e.g. “Breathermae – Proactive Health”).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmf_pwa_short_name">Short Name</label></th>
						<td>
							<input type="text" id="bmf_pwa_short_name" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[short_name]"
								value="<?php echo esc_attr( $settings['short_name'] ); ?>" class="regular-text" />
							<p class="description">Short name under the icon (max ~12 characters recommended).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmf_pwa_description">Description</label></th>
						<td>
							<textarea id="bmf_pwa_description" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[description]"
								rows="3" class="large-text"><?php echo esc_textarea( $settings['description'] ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmf_pwa_theme_color">Theme Color</label></th>
						<td>
							<input type="color" id="bmf_pwa_theme_color" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[theme_color]"
								value="<?php echo esc_attr( $settings['theme_color'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmf_pwa_background_color">Background Color</label></th>
						<td>
							<input type="color" id="bmf_pwa_background_color" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[background_color]"
								value="<?php echo esc_attr( $settings['background_color'] ); ?>" />
							<p class="description">Used for the splash screen.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmf_pwa_start_url">Start URL</label></th>
						<td>
							<input type="text" id="bmf_pwa_start_url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[start_url]"
								value="<?php echo esc_attr( $settings['start_url'] ); ?>" class="regular-text" />
							<p class="description">Usually <code>/</code> or a specific member dashboard path.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmf_pwa_display">Display Mode</label></th>
						<td>
							<select id="bmf_pwa_display" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[display]">
								<option value="standalone" <?php selected( $settings['display'], 'standalone' ); ?>>standalone (recommended – looks like a real app)</option>
								<option value="fullscreen" <?php selected( $settings['display'], 'fullscreen' ); ?>>fullscreen</option>
								<option value="minimal-ui" <?php selected( $settings['display'], 'minimal-ui' ); ?>>minimal-ui</option>
								<option value="browser" <?php selected( $settings['display'], 'browser' ); ?>>browser</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmf_pwa_icon_192">Icon 192×192</label></th>
						<td>
							<input type="url" id="bmf_pwa_icon_192" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[icon_192]"
								value="<?php echo esc_attr( $settings['icon_192'] ); ?>" class="large-text" />
							<p class="description">Full URL to a 192×192 PNG (required for installability).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmf_pwa_icon_512">Icon 512×512</label></th>
						<td>
							<input type="url" id="bmf_pwa_icon_512" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[icon_512]"
								value="<?php echo esc_attr( $settings['icon_512'] ); ?>" class="large-text" />
							<p class="description">Full URL to a 512×512 PNG (required for installability).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmf_pwa_button_text">Button Text</label></th>
						<td>
							<input type="text" id="bmf_pwa_button_text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[button_text]"
								value="<?php echo esc_attr( $settings['button_text'] ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmf_pwa_button_class">Extra Button CSS Class</label></th>
						<td>
							<input type="text" id="bmf_pwa_button_class" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[button_class]"
								value="<?php echo esc_attr( $settings['button_class'] ); ?>" class="regular-text" />
							<p class="description">Optional – useful if you want to style it with Elementor or custom CSS.</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2>How to use the Install button</h2>
			<p>Add this shortcode anywhere (Elementor HTML widget, text widget, page content, etc.):</p>
			<code style="background:#f0f0f1;padding:8px 12px;display:inline-block;">[bmf_pwa_install_button]</code>

			<p style="margin-top:1em;">Optional attributes:</p>
			<ul>
				<li><code>[bmf_pwa_install_button text="Install Breathermae"]</code></li>
				<li><code>[bmf_pwa_install_button class="my-custom-class"]</code></li>
			</ul>

			<p><strong>Note:</strong> The button only appears when the browser supports installation <em>and</em> the site is not already installed. On iOS Safari the install flow is different (Share → Add to Home Screen).</p>
		</div>
		<?php
	}
}
