<?php
/**
 * BioVoicePrint – Shortcodes.
 *
 * [bmf_biovoice_record]   – Single-step recorder (testing / admin)
 * [bmf_biovoice_sessions] – List of past recordings with playback
 * [bmf_biovoice_session]  – Guided wizard (wellness → steps → complete)
 * [bmf_biovoice_status]   – Compact progress panel (baseline / comparison / ongoing)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Shortcodes {

	public static function init() {
		add_shortcode( 'bmf_biovoice_record', [ __CLASS__, 'shortcode_record' ] );
		add_shortcode( 'bmf_biovoice_sessions', [ __CLASS__, 'shortcode_sessions' ] );
		add_shortcode( 'bmf_biovoice_session', [ __CLASS__, 'shortcode_session' ] );
		add_shortcode( 'bmf_biovoice_status', [ __CLASS__, 'shortcode_status' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
	}

	public static function register_assets() {
		wp_register_style( 'bmf-biovoice-recorder', BMF_BIOVOICE_URL . 'assets/css/recorder.css', [], BMF_BIOVOICE_VERSION );
		wp_register_script( 'bmf-biovoice-recorder', BMF_BIOVOICE_URL . 'assets/js/recorder.js', [], BMF_BIOVOICE_VERSION, true );
		wp_register_script( 'bmf-biovoice-session-wizard', BMF_BIOVOICE_URL . 'assets/js/session-wizard.js', [ 'bmf-biovoice-recorder' ], BMF_BIOVOICE_VERSION, true );
		wp_register_script( 'bmf-biovoice-sessions-admin', BMF_BIOVOICE_URL . 'assets/js/sessions-admin.js', [], BMF_BIOVOICE_VERSION, true );
	}

	public static function shortcode_record( $atts ) {
		if ( bmf_biovoice_in_elementor_editor() ) {
			return '<div class="bmf-biovoice-placeholder" style="padding:1.5rem;border:1px dashed #94a3b8;border-radius:8px;text-align:center;color:#64748b;">BioVoicePrint Recorder (preview)</div>';
		}
		if ( ! is_user_logged_in() ) {
			return '<p class="bmf-biovoice-login-required">Please log in to record.</p>';
		}
		$atts = shortcode_atts( [
			'session_type' => 'comparison', 'task' => '', 'session_group_id' => '',
			'min_seconds' => '', 'max_seconds' => '', 'class' => '',
		], $atts, 'bmf_biovoice_record' );
		$task = sanitize_key( $atts['task'] );
		$min = $atts['min_seconds'] !== '' ? (float) $atts['min_seconds'] : null;
		$max = $atts['max_seconds'] !== '' ? (float) $atts['max_seconds'] : null;
		$step_title = ''; $step_dirs = ''; $step_prompt = ''; $is_silence = ( strpos( $task, 'silence' ) !== false );
		if ( $task ) {
			$payload = BMF_BioVoice_Protocol_Service::get_active_payload( 'baseline' );
			if ( ! empty( $payload['steps'] ) ) {
				foreach ( $payload['steps'] as $s ) {
					if ( $s['task_code'] === $task ) {
						if ( $min === null ) { $min = $s['min_seconds']; }
						if ( $max === null ) { $max = $s['max_seconds']; }
						$step_title = $s['title']; $step_dirs = $s['directions'];
						$step_prompt = isset( $s['prompt_text'] ) ? $s['prompt_text'] : '';
						if ( ! empty( $s['is_silence'] ) ) { $is_silence = true; }
						break;
					}
				}
			}
		}
		wp_enqueue_style( 'bmf-biovoice-recorder' );
		wp_enqueue_script( 'bmf-biovoice-recorder' );
		wp_localize_script( 'bmf-biovoice-recorder', 'bmfBioVoice', [
			'restUrl' => esc_url_raw( rest_url( 'bmf-biovoice/v1/sessions' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'sessionType' => sanitize_key( $atts['session_type'] ),
			'userId' => get_current_user_id(),
			'taskCode' => $task,
			'sessionGroupId' => absint( $atts['session_group_id'] ) ? absint( $atts['session_group_id'] ) : 0,
			'minSeconds' => $min !== null ? $min : 0,
			'maxSeconds' => $max !== null ? $max : 0,
		] );
		$class = 'bmf-biovoice-recorder' . ( $atts['class'] ? ' ' . sanitize_html_class( $atts['class'] ) : '' );
		ob_start();
		?>
		<div class="<?php echo esc_attr( $class ); ?>" data-bmf-biovoice-recorder
			data-task="<?php echo esc_attr( $task ); ?>"
			data-min="<?php echo esc_attr( (string) ( $min !== null ? $min : 0 ) ); ?>"
			data-max="<?php echo esc_attr( (string) ( $max !== null ? $max : 0 ) ); ?>"
			data-group="<?php echo esc_attr( (string) absint( $atts['session_group_id'] ) ); ?>"
			<?php echo $is_silence ? ' data-silence="1"' : ''; ?>>
			<?php if ( $step_title ) : ?><div class="bmf-bv-step-title"><?php echo esc_html( $step_title ); ?></div><?php endif; ?>
			<?php if ( $step_dirs ) : ?><p class="bmf-bv-step-directions"><?php echo esc_html( $step_dirs ); ?></p><?php endif; ?>
			<?php if ( $step_prompt ) : ?><blockquote class="bmf-bv-step-prompt"><?php echo esc_html( $step_prompt ); ?></blockquote><?php endif; ?>
			<div class="bmf-bv-status" data-status>Ready to record</div>
			<div class="bmf-bv-device-wrap" data-device-wrap hidden>
				<label class="bmf-bv-device-label" for="bmf-bv-device">Microphone</label>
				<select class="bmf-bv-device" data-device id="bmf-bv-device"></select>
			</div>
			<div class="bmf-bv-meter" data-meter aria-hidden="true"><div class="bmf-bv-meter-bar" data-meter-bar></div></div>
			<div class="bmf-bv-controls">
				<button type="button" class="bmf-bv-btn bmf-bv-btn-record" data-action="start"><span class="bmf-bv-dot"></span> Start Recording</button>
				<button type="button" class="bmf-bv-btn bmf-bv-btn-stop" data-action="stop" disabled>Stop</button>
			</div>
			<div class="bmf-bv-timer" data-timer>00:00</div>
			<?php if ( $min || $max ) : ?>
				<p class="bmf-bv-limits"><?php if ( $min ) : ?>Min <?php echo esc_html( (string) $min ); ?>s<?php endif; ?><?php if ( $min && $max ) : ?> · <?php endif; ?><?php if ( $max ) : ?>Max <?php echo esc_html( (string) $max ); ?>s<?php endif; ?></p>
			<?php endif; ?>
			<div class="bmf-bv-preview" data-preview hidden>
				<p class="bmf-bv-preview-label">Last take</p>
				<audio controls data-player></audio>
				<div class="bmf-bv-preview-actions">
					<button type="button" class="bmf-bv-btn bmf-bv-btn-save" data-action="save">Save Recording</button>
					<button type="button" class="bmf-bv-btn bmf-bv-btn-discard" data-action="discard">Discard</button>
				</div>
			</div>
			<div class="bmf-bv-message" data-message hidden></div>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function shortcode_sessions( $atts ) {
		if ( bmf_biovoice_in_elementor_editor() ) {
			return '<div class="bmf-biovoice-placeholder" style="padding:1.5rem;border:1px dashed #94a3b8;border-radius:8px;text-align:center;color:#64748b;">BioVoicePrint Sessions (preview)</div>';
		}
		if ( ! is_user_logged_in() ) {
			return '<p class="bmf-biovoice-login-required">Please log in to view your recordings.</p>';
		}
		$atts = shortcode_atts( [ 'limit' => 20, 'class' => '', 'admin' => '0' ], $atts, 'bmf_biovoice_sessions' );
		if ( in_array( strtolower( (string) $atts['admin'] ), [ '1', 'true', 'yes' ], true ) ) {
			return self::render_admin_sessions_panel( $atts );
		}
		wp_enqueue_style( 'bmf-biovoice-recorder' );
		$user_id = get_current_user_id();
		$sessions = BMF_BioVoice_Session_Service::get_user_sessions( $user_id, [ 'limit' => absint( $atts['limit'] ) ] );
		$class = 'bmf-biovoice-sessions' . ( $atts['class'] ? ' ' . sanitize_html_class( $atts['class'] ) : '' );
		ob_start();
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<?php if ( empty( $sessions ) ) : ?>
				<p class="bmf-bv-empty">No recordings yet.</p>
			<?php else : ?>
				<ul class="bmf-bv-session-list">
					<?php foreach ( $sessions as $s ) :
						$play_url = BMF_BioVoice_Play::url( (int) $s['id'] );
						$date = $s['created_at'] ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $s['created_at'] ) : '';
						$dur = $s['duration_sec'] !== null ? number_format( (float) $s['duration_sec'], 1 ) . 's' : '—';
						?>
						<li class="bmf-bv-session-item" data-session-id="<?php echo (int) $s['id']; ?>">
							<div class="bmf-bv-session-meta">
								<span class="bmf-bv-session-date"><?php echo esc_html( $date ); ?></span>
								<?php if ( ! empty( $s['task_code'] ) ) : ?><span class="bmf-bv-session-task"><?php echo esc_html( $s['task_code'] ); ?></span><?php endif; ?>
								<?php if ( ! empty( $s['session_type'] ) ) : ?><span class="bmf-bv-session-type"><?php echo esc_html( $s['session_type'] ); ?></span><?php endif; ?>
								<?php if ( ! empty( $s['status'] ) && $s['status'] !== 'recorded' ) : ?><span class="bmf-bv-session-status"><?php echo esc_html( $s['status'] ); ?></span><?php endif; ?>
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

	public static function shortcode_session( $atts ) {
		if ( bmf_biovoice_in_elementor_editor() ) {
			return '<div class="bmf-biovoice-placeholder" style="padding:1.5rem;border:1px dashed #94a3b8;border-radius:8px;text-align:center;color:#64748b;">BioVoicePrint Guided Session (preview)</div>';
		}
		if ( ! is_user_logged_in() ) {
			return '<p class="bmf-biovoice-login-required">Please log in to start a BioVoicePrint session.</p>';
		}
		$atts = shortcode_atts( [ 'purpose' => 'baseline', 'class' => '' ], $atts, 'bmf_biovoice_session' );
		$purpose = sanitize_key( $atts['purpose'] ) ?: 'baseline';
		wp_enqueue_style( 'bmf-biovoice-recorder' );
		wp_enqueue_script( 'bmf-biovoice-recorder' );
		wp_enqueue_script( 'bmf-biovoice-session-wizard' );
		wp_localize_script( 'bmf-biovoice-session-wizard', 'bmfBioVoiceSession', [
			'restBase' => esc_url_raw( rest_url( 'bmf-biovoice/v1' ) ),
			'sessionsUrl' => esc_url_raw( rest_url( 'bmf-biovoice/v1/sessions' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'purpose' => $purpose,
			'userId' => get_current_user_id(),
		] );
		$class = 'bmf-biovoice-session' . ( $atts['class'] ? ' ' . sanitize_html_class( $atts['class'] ) : '' );
		return '<div class="' . esc_attr( $class ) . '" data-bmf-biovoice-session data-purpose="' . esc_attr( $purpose ) . '"><div data-wizard-panel><p class="bmf-bv-empty">Loading session…</p></div></div>';
	}

	/** [bmf_biovoice_status comparison_target="6"] */
	public static function shortcode_status( $atts ) {
		if ( bmf_biovoice_in_elementor_editor() ) {
			return '<div class="bmf-biovoice-placeholder" style="padding:1.25rem;border:1px dashed #64748b;border-radius:10px;text-align:center;color:#94a3b8;background:#0f172a;">BioVoicePrint Status (preview)</div>';
		}
		if ( ! is_user_logged_in() ) {
			return '<p class="bmf-biovoice-login-required">Please log in to view BioVoicePrint progress.</p>';
		}
		$atts = shortcode_atts( [
			'comparison_target' => (string) BMF_BioVoice_Session_Service::COMPARISON_TARGET_DEFAULT,
			'class' => '',
		], $atts, 'bmf_biovoice_status' );
		$target = max( 1, absint( $atts['comparison_target'] ) );
		$summary = BMF_BioVoice_Session_Service::get_status_summary( get_current_user_id(), $target );
		wp_enqueue_style( 'bmf-biovoice-recorder' );
		$class = 'bmf-biovoice-status' . ( $atts['class'] ? ' ' . sanitize_html_class( $atts['class'] ) : '' );
		$phase = $summary['phase'];
		ob_start();
		?>
		<div class="<?php echo esc_attr( $class ); ?>" data-bmf-biovoice-status data-phase="<?php echo esc_attr( $phase ); ?>">
			<div class="bmf-bv-status-head">
				<div class="bmf-bv-status-kicker">BioVoicePrint</div>
				<div class="bmf-bv-status-headline"><?php echo esc_html( $summary['headline'] ); ?></div>
				<?php if ( ! empty( $summary['next_label'] ) ) : ?>
					<div class="bmf-bv-status-next"><?php echo esc_html( $summary['next_label'] ); ?></div>
				<?php endif; ?>
			</div>
			<div class="bmf-bv-status-phase <?php echo $phase === 'baseline' ? 'is-active' : ( $summary['baseline']['complete'] ? 'is-done' : '' ); ?>">
				<div class="bmf-bv-status-phase-label"><span>Baseline</span><span class="bmf-bv-status-count"><?php echo (int) $summary['baseline']['done']; ?> / <?php echo (int) $summary['baseline']['required']; ?></span></div>
				<div class="bmf-bv-status-nodes" aria-hidden="true"><?php
					$req = (int) $summary['baseline']['required']; $done = (int) $summary['baseline']['done'];
					for ( $i = 1; $i <= $req; $i++ ) {
						$state = $i <= $done ? 'is-filled' : ( $phase === 'baseline' && $i === $done + 1 ? 'is-current' : '' );
						echo '<span class="bmf-bv-node ' . esc_attr( $state ) . '"></span>';
					}
				?></div>
				<div class="bmf-bv-status-bar"><span style="width:<?php echo (int) $summary['baseline']['pct']; ?>%;"></span></div>
			</div>
			<div class="bmf-bv-status-phase <?php echo $phase === 'comparison' ? 'is-active' : ( $summary['comparison']['complete'] ? 'is-done' : ( $summary['baseline']['complete'] ? '' : 'is-locked' ) ); ?>">
				<div class="bmf-bv-status-phase-label"><span>Comparison</span><span class="bmf-bv-status-count"><?php echo (int) $summary['comparison']['done']; ?> / <?php echo (int) $summary['comparison']['target']; ?></span></div>
				<div class="bmf-bv-status-nodes" aria-hidden="true"><?php
					$req = (int) $summary['comparison']['target']; $done = (int) $summary['comparison']['done'];
					for ( $i = 1; $i <= $req; $i++ ) {
						$state = $i <= $done ? 'is-filled' : ( $phase === 'comparison' && $i === $done + 1 ? 'is-current' : '' );
						echo '<span class="bmf-bv-node ' . esc_attr( $state ) . '"></span>';
					}
				?></div>
				<div class="bmf-bv-status-bar"><span style="width:<?php echo (int) $summary['comparison']['pct']; ?>%;"></span></div>
			</div>
			<div class="bmf-bv-status-phase <?php echo $phase === 'ongoing' ? 'is-active' : ( $summary['comparison']['complete'] ? '' : 'is-locked' ); ?>">
				<div class="bmf-bv-status-phase-label"><span>Ongoing</span><span class="bmf-bv-status-count"><?php echo (int) $summary['ongoing']['done']; ?> session<?php echo (int) $summary['ongoing']['done'] === 1 ? '' : 's'; ?></span></div>
				<p class="bmf-bv-status-ongoing-note">Available after the comparison series. Count continues without a fixed target.</p>
			</div>
			<?php if ( ! empty( $summary['device_mismatch'] ) ) : ?>
				<div class="bmf-bv-status-notice" role="status">
					<span class="bmf-bv-status-notice-icon" aria-hidden="true">◐</span>
					<span>Different device or microphone detected on <?php echo (int) $summary['device_mismatch_n']; ?> session group<?php echo (int) $summary['device_mismatch_n'] === 1 ? '' : 's'; ?>. Preferred, not required — consistency helps analysis.</span>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_admin_sessions_panel( array $atts ): string {
		if ( ! BMF_BioVoice_Session_Service::can_inspect_member_sessions() ) {
			return '<p class="bmf-biovoice-login-required">You do not have permission to inspect member recordings.</p>';
		}
		wp_enqueue_style( 'bmf-biovoice-recorder' );
		wp_enqueue_script( 'bmf-biovoice-sessions-admin' );
		wp_localize_script( 'bmf-biovoice-sessions-admin', 'bmfBioVoiceSessionsAdmin', [
			'restUrl' => esc_url_raw( rest_url( 'bmf-biovoice/v1/sessions' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'limit' => absint( $atts['limit'] ) ? absint( $atts['limit'] ) : 50,
		] );
		$class = 'bmf-biovoice-sessions bmf-biovoice-sessions--admin' . ( $atts['class'] ? ' ' . sanitize_html_class( $atts['class'] ) : '' );
		return '<div class="' . esc_attr( $class ) . '" data-bmf-biovoice-sessions-admin><p class="bmf-bv-empty">Select a member to view recordings.</p></div>';
	}
}
