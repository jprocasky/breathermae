<?php
/**
 * BioVoicePrint – Shortcodes.
 *
 * [bmf_biovoice_record]   – Recorder UI for the logged-in user
 * [bmf_biovoice_sessions] – List of past recordings with playback
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Shortcodes {

	public static function init() {
		add_shortcode( 'bmf_biovoice_record', [ __CLASS__, 'shortcode_record' ] );
		add_shortcode( 'bmf_biovoice_sessions', [ __CLASS__, 'shortcode_sessions' ] );

		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
	}

	public static function register_assets() {
		wp_register_style(
			'bmf-biovoice-recorder',
			BMF_BIOVOICE_URL . 'assets/css/recorder.css',
			[],
			BMF_BIOVOICE_VERSION
		);
		wp_register_script(
			'bmf-biovoice-recorder',
			BMF_BIOVOICE_URL . 'assets/js/recorder.js',
			[],
			BMF_BIOVOICE_VERSION,
			true
		);
	}

	/**
	 * [bmf_biovoice_record session_type="comparison"]
	 */
	public static function shortcode_record( $atts ) {
		if ( bmf_biovoice_in_elementor_editor() ) {
			return '<div class="bmf-biovoice-placeholder" style="padding:1.5rem;border:1px dashed #94a3b8;border-radius:8px;text-align:center;color:#64748b;">BioVoicePrint Recorder (preview)</div>';
		}

		if ( ! is_user_logged_in() ) {
			return '<p class="bmf-biovoice-login-required">Please log in to record.</p>';
		}

		$atts = shortcode_atts( [
			'session_type' => 'comparison',
			'class'        => '',
		], $atts, 'bmf_biovoice_record' );

		wp_enqueue_style( 'bmf-biovoice-recorder' );
		wp_enqueue_script( 'bmf-biovoice-recorder' );

		$rest_url = esc_url_raw( rest_url( 'bmf-biovoice/v1/sessions' ) );
		$nonce    = wp_create_nonce( 'wp_rest' );

		wp_localize_script( 'bmf-biovoice-recorder', 'bmfBioVoice', [
			'restUrl'     => $rest_url,
			'nonce'       => $nonce,
			'sessionType' => sanitize_key( $atts['session_type'] ),
			'userId'      => get_current_user_id(),
		] );

		$class = 'bmf-biovoice-recorder' . ( $atts['class'] ? ' ' . sanitize_html_class( $atts['class'] ) : '' );

		obstart();
		?>
		<div class="<?php echo esc_attr( $class ); ?>" data-bmf-biovoice-recorder>
			<div class="bmf-bv-status" data-status>Ready to record</div>

			<div class="bmf-bv-controls">
				<button type="button" class="bmf-bv-btn bmf-bv-btn-record" data-action="start">
					<span class="bmf-bv-dot"></span> Start Recording
				</button>
				<button type="button" class="bmf-bv-btn bmf-bv-btn-stop" data-action="stop" disabled>
					Stop
				</button>
			</div>

			<div class="bmf-bv-timer" data-timer>00:00</div>

			<div class="bmf-bv-preview" data-preview hidden>
				<p class="bmf-bv-preview-label">Last take</p>
				<audio controls data-player></audio>
				<div class="bmf-bv-preview-actions">
					<button type="button" class="bmf-bv-btn bmf-bv-btn-save" data-action="save">
						Save Recording
					</button>
					<button type="button" class="bmf-bv-btn bmf-bv-btn-discard" data-action="discard">
						Discard
					</button>
				</div>
			</div>

			<div class="bmf-bv-message" data-message hidden></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [bmf_biovoice_sessions limit="20"]
	 */
	public static function shortcode_sessions( $atts ) {
		if ( bmf_biovoice_in_elementor_editor() ) {
			return '<div class="bmf-biovoice-placeholder" style="padding:1.5rem;border:1px dashed #94a3b8;border-radius:8px;text-align:center;color:#64748b;">BioVoicePrint Sessions (preview)</div>';
		}

		if ( ! is_user_logged_in() ) {
			return '<p class="bmf-biovoice-login-required">Please log in to view your recordings.</p>';
		}

		$atts = shortcode_atts( [
			'limit' => 20,
			'class' => '',
		], $atts, 'bmf_biovoice_sessions' );

		wp_enqueue_style( 'bmf-biovoice-recorder' );

		$user_id  = get_current_user_id();
		$sessions = BMF_BioVoice_Session_Service::get_user_sessions( $user_id, [
			'limit' => absint( $atts['limit'] ),
		] );

		$class = 'bmf-biovoice-sessions' . ( $atts['class'] ? ' ' . sanitize_html_class( $atts['class'] ) : '' );

		obstart();
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<?php if ( empty( $sessions ) ) : ?>
				<p class="bmf-bv-empty">No recordings yet.</p>
			<?php else : ?>
				<ul class="bmf-bv-session-list">
					<?php foreach ( $sessions as $s ) :
						$play_url = rest_url( 'bmf-biovoice/v1/sessions/' . (int) $s['id'] . '/play' );
						$date     = $s['created_at'] ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $s['created_at'] ) : '';
						$dur      = $s['duration_sec'] !== null ? number_format( (float) $s['duration_sec'], 1 ) . 's' : '—';
						?>
						<li class="bmf-bv-session-item" data-session-id="<?php echo (int) $s['id']; ?>">
							<div class="bmf-bv-session-meta">
								<span class="bmf-bv-session-date"><?php echo esc_html( $date ); ?></span>
								<span class="bmf-bv-session-type"><?php echo esc_html( $s['session_type'] ); ?></span>
								<span class="bmf-bv-session-status"><?php echo esc_html( $s['status'] ); ?></span>
								<span class="bmf-bv-session-dur"><?php echo esc_html( $dur ); ?></span>
							</div>
							<audio controls preload="none" src="<?php echo esc_url( $play_url ); ?>"></audio>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
