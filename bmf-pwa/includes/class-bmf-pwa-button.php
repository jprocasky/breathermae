<?php
/**
 * Install button shortcode + front-end JS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_PWA_Button {

	/** @var BMF_PWA_Button */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'bmf_pwa_install_button', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets() {
		// Only load on the front end when the shortcode might be used.
		// We always enqueue the small JS – it’s tiny and only activates when the button exists.
		wp_enqueue_style(
			'bmf-pwa-button',
			BMF_PWA_PLUGIN_URL . 'assets/css/pwa-button.css',
			array(),
			BMF_PWA_VERSION
		);

		wp_enqueue_script(
			'bmf-pwa-install',
			BMF_PWA_PLUGIN_URL . 'assets/js/pwa-install.js',
			array(),
			BMF_PWA_VERSION,
			true
		);

		// Pass the service worker URL so the JS can register it.
		wp_localize_script( 'bmf-pwa-install', 'bmfPwa', array(
			'swUrl' => home_url( '/bmf-pwa-sw.js' ),
		) );
	}

	/**
	 * Shortcode: [bmf_pwa_install_button] or [bmf_pwa_install_button text="..." class="..."]
	 */
	public function render_shortcode( $atts ) {
		$settings = BMF_PWA_Settings::get_settings();

		$atts = shortcode_atts( array(
			'text'  => $settings['button_text'],
			'class' => $settings['button_class'],
		), $atts, 'bmf_pwa_install_button' );

		$classes = array( 'bmf-pwa-install-btn' );
		if ( ! empty( $atts['class'] ) ) {
			$classes[] = sanitize_html_class( $atts['class'] );
		}

		ob_start();
		?>
		<button type="button"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			style="display:none;"
			aria-label="<?php echo esc_attr( $atts['text'] ); ?>">
			<span class="bmf-pwa-install-btn__icon" aria-hidden="true">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
					<polyline points="7 10 12 15 17 10"/>
					<line x1="12" y1="15" x2="12" y2="3"/>
				</svg>
			</span>
			<span class="bmf-pwa-install-btn__text"><?php echo esc_html( $atts['text'] ); ?></span>
		</button>
		<?php
		return ob_get_clean();
	}
}
