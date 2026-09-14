<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Wellbeing_Shortcodes {

	public static function init() {
		add_shortcode( 'bmf_wellbeing_status', [ __CLASS__, 'shortcode_status' ] );
		add_shortcode( 'bmf_wellbeing_brief', [ __CLASS__, 'shortcode_brief' ] );
		add_action( 'wp_ajax_bmf_wellbeing_brief', [ __CLASS__, 'ajax_brief' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
	}

	public static function register_assets() {
		$css = BMF_WELLBEING_PATH . 'assets/css/wellbeing.css';
		$js  = BMF_WELLBEING_PATH . 'assets/js/wellbeing.js';
		wp_register_style(
			'bmf-wellbeing',
			BMF_WELLBEING_URL . 'assets/css/wellbeing.css',
			[],
			file_exists( $css ) ? (string) filemtime( $css ) : BMF_WELLBEING_VERSION
		);
		wp_register_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
			[],
			'4.4.1',
			true
		);
		wp_register_script(
			'bmf-wellbeing',
			BMF_WELLBEING_URL . 'assets/js/wellbeing.js',
			[ 'chartjs' ],
			file_exists( $js ) ? (string) filemtime( $js ) : BMF_WELLBEING_VERSION,
			true
		);
	}

	private static function enqueue( array $cfg ) {
		wp_enqueue_style( 'bmf-wellbeing' );
		wp_enqueue_script( 'chartjs' );
		wp_enqueue_script( 'bmf-wellbeing' );
		wp_localize_script( 'bmf-wellbeing', 'bmfWellbeingCfg', $cfg );
	}

	public static function shortcode_status( $atts ) {
		return self::render( $atts, 'status' );
	}

	public static function shortcode_brief( $atts ) {
		return self::render( $atts, 'brief' );
	}

	private static function render( $atts, string $mode ) {
		if ( bmf_wellbeing_in_elementor_editor() ) {
			return '<div class="bmf-wb-editor">Wellbeing ' . esc_html( $mode ) . ' (editor)</div>';
		}
		if ( ! is_user_logged_in() ) {
			return '<div class="bmf-wb-empty">Please log in to view the wellbeing file.</div>';
		}

		$atts = shortcode_atts(
			[
				'user_id'  => '',
				'email'    => '',
				'voice'    => 'member',
				'fixture'  => '0',
				'admin'    => '0',
				'self'     => '0',
			],
			$atts,
			$mode === 'status' ? 'bmf_wellbeing_status' : 'bmf_wellbeing_brief'
		);

		$use_fixture = in_array( strtolower( (string) $atts['fixture'] ), [ '1', 'true', 'yes' ], true );
		$admin       = in_array( strtolower( (string) $atts['admin'] ), [ '1', 'true', 'yes' ], true );
		$voice       = $admin ? 'provider' : ( in_array( $atts['voice'], [ 'member', 'provider' ], true ) ? $atts['voice'] : 'member' );

		if ( $use_fixture ) {
			$brief = BMF_Wellbeing_Assembler::load_fixture();
			if ( ! $brief ) {
				return '<div class="bmf-wb-empty">Fixture brief missing.</div>';
			}
			$brief['voice']      = $voice;
			$brief['is_fixture'] = true;
		} else {
			list( $user_id, $email, $label ) = BMF_Wellbeing_Access::resolve_subject( $atts['user_id'], $atts['email'] );
			if ( empty( $atts['user_id'] ) && empty( $atts['email'] ) && $atts['self'] !== '1' && $admin ) {
				// Provider panel waits for uls:selected-member.
				$user_id = 0;
				$email   = '';
				$label   = '';
			}
			if ( $user_id && ! BMF_Wellbeing_Access::can_view_subject( $user_id ) ) {
				return '<div class="bmf-wb-empty">You do not have access to this file.</div>';
			}
			$brief = $user_id
				? BMF_Wellbeing_Assembler::assemble( $user_id, $email, $label, $voice )
				: null;
		}

		self::enqueue( [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'bmf_wellbeing' ),
			'voice' => $voice,
			'admin' => $admin ? 1 : 0,
			'mode'  => $mode,
		] );

		ob_start();
		echo '<div class="bmf-wb-wrap" data-mode="' . esc_attr( $mode ) . '" data-voice="' . esc_attr( $voice ) . '" data-admin="' . ( $admin ? '1' : '0' ) . '">';
		if ( $brief ) {
			echo $mode === 'status' ? self::markup_status( $brief ) : self::markup_brief( $brief );
		} else {
			echo '<div class="bmf-wb-empty">Select a member to load the wellbeing file.</div>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	public static function ajax_brief() {
		check_ajax_referer( 'bmf_wellbeing', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
		}
		$voice = isset( $_POST['voice'] ) && $_POST['voice'] === 'provider' ? 'provider' : 'member';
		$mode  = isset( $_POST['mode'] ) && $_POST['mode'] === 'brief' ? 'brief' : 'status';
		list( $user_id, $email, $label ) = BMF_Wellbeing_Access::resolve_subject(
			$_POST['user_id'] ?? 0,
			isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : ''
		);
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => 'No user found.' ], 404 );
		}
		if ( ! BMF_Wellbeing_Access::can_view_subject( $user_id ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$brief = BMF_Wellbeing_Assembler::assemble( $user_id, $email, $label, $voice );
		$html  = $mode === 'brief' ? self::markup_brief( $brief ) : self::markup_status( $brief );
		wp_send_json_success( [ 'html' => $html, 'user_id' => $user_id, 'label' => $label ] );
	}

	public static function markup_status( array $brief ): string {
		$label = esc_html( $brief['user']['label'] ?? 'Member' );
		$fix   = ! empty( $brief['is_fixture'] ) ? '<span class="bmf-wb-badge">Sample</span>' : '';
		$html  = '<div class="bmf-wb-card bmf-wb-status">';
		$html .= '<div class="bmf-wb-head"><h4>Wellbeing file</h4><span class="bmf-wb-member">' . $label . ' ' . $fix . '</span></div>';
		$html .= '<div class="bmf-wb-grid">';
		foreach ( $brief['sources'] ?? [] as $src ) {
			$html .= self::source_chip( $src );
		}
		$html .= '</div></div>';
		return $html;
	}

	public static function markup_brief( array $brief ): string {
		$voice = $brief['voice'] ?? 'member';
		$label = esc_html( $brief['user']['label'] ?? 'Member' );
		$fix   = ! empty( $brief['is_fixture'] ) ? '<span class="bmf-wb-badge">Sample</span>' : '';
		$html  = '<div class="bmf-wb-card bmf-wb-brief" data-voice="' . esc_attr( $voice ) . '">';
		$html .= '<div class="bmf-wb-head"><h4>Systems snapshot</h4><span class="bmf-wb-member">' . $label . ' ' . $fix . '</span></div>';
		$html .= '<p class="bmf-wb-sub">Pulse = RSI · Cycle = Pillars + BSI · State = Keys + BioVoice + Fitbit. Map ' . esc_html( $brief['map_version'] ?? 'v1' ) . '.</p>';

		$html .= '<div class="bmf-wb-grid">';
		foreach ( [ 'rsi', 'pillars', 'keys', 'bsi', 'biovoice', 'fitbit', 'profile' ] as $k ) {
			if ( isset( $brief['sources'][ $k ] ) ) {
				$html .= self::source_chip( $brief['sources'][ $k ] );
			}
		}
		$html .= '</div>';
		$strip = trim( (string) ( $brief['sources']['profile']['strip'] ?? '' ) );
		if ( $strip !== '' ) {
			$html .= '<p class="bmf-wb-profile">' . esc_html( $strip ) . '</p>';
		}
		$html .= self::markup_history( $brief );

		if ( ! empty( $brief['patterns'] ) ) {
			$html .= '<h5 class="bmf-wb-h">Themes</h5><ul class="bmf-wb-patterns">';
			foreach ( $brief['patterns'] as $p ) {
				$copy = $voice === 'provider' ? ( $p['provider'] ?? '' ) : ( $p['member'] ?? '' );
				$title = trim( (string) ( $p['title'] ?? '' ) );
				if ( $title !== '' && ! preg_match( '/[.!?:;—–-]$/u', $title ) ) {
					$title .= ' —';
				}
				$html .= '<li class="sev-' . esc_attr( $p['severity'] ?? 'info' ) . '"><strong>' . esc_html( $title ) . '</strong> ' . esc_html( $copy ) . '</li>';
			}
			$html .= '</ul>';
		}

		if ( ! empty( $brief['highlights'] ) ) {
			$html .= '<h5 class="bmf-wb-h">Highlighted items</h5><ul class="bmf-wb-hi">';
			foreach ( array_slice( $brief['highlights'], 0, 12 ) as $h ) {
				$html .= '<li><span class="src">' . esc_html( strtoupper( $h['source'] ?? '' ) ) . '</span> ';
				$html .= esc_html( $h['prompt'] ?? '' );
				if ( ! empty( $h['answer_label'] ) ) {
					$html .= ' — <em>' . esc_html( $h['answer_label'] ) . '</em>';
				}
				$html .= '</li>';
			}
			$html .= '</ul>';
		}

		$html .= self::score_block( 'RSI (pulse)', $brief['sources']['rsi']['scores'] ?? [], 'low_better' );
		$html .= self::score_block( '8 Pillars (cycle)', $brief['sources']['pillars']['scores'] ?? [], 'high_better', 'is-pillars' );
		if ( ! empty( $brief['sources']['pillars']['master'] ) ) {
			$html .= '<p class="bmf-wb-note">Pillars master score: ' . esc_html( (string) $brief['sources']['pillars']['master'] ) . '</p>';
		}
		$html .= self::score_block( 'Key Essentials (state)', $brief['sources']['keys']['scores'] ?? [], 'high_better' );
		$html .= self::score_block( 'BSI composites (cycle)', $brief['sources']['bsi']['scores'] ?? [], 'low_better' );
		$html .= self::score_block( 'BSI F1–F9 (cycle)', $brief['sources']['bsi']['forms'] ?? [], 'low_better', 'is-bsi' );
		$html .= self::score_block( 'BioVoicePrint (state)', $brief['sources']['biovoice']['scores'] ?? [], 'low_better' );
		$bv = $brief['sources']['biovoice'] ?? [];
		if ( ! empty( $bv['summary'] ) ) {
			$html .= '<p class="bmf-wb-note">' . esc_html( $bv['summary'] ) . '</p>';
		}
		if ( ! empty( $bv['progress'] ) && ( $bv['progress']['baseline_final'] || $bv['progress']['comparison_final'] ) ) {
			$html .= '<p class="bmf-wb-note">Groups: baseline ' . (int) $bv['progress']['baseline_final'] . ' · comparison ' . (int) $bv['progress']['comparison_final'] . '</p>';
		}
		$html .= self::score_block( 'Fitbit nights (state)', $brief['sources']['fitbit']['scores'] ?? [], 'high_better' );
		$fb = $brief['sources']['fitbit'] ?? [];
		if ( ! empty( $fb['present'] ) ) {
			$html .= '<p class="bmf-wb-note">Last 7 nights with data: ' . (int) ( $fb['nights_7d'] ?? 0 ) . ' · under 6 hours: ' . (int) ( $fb['short_nights'] ?? 0 ) . '</p>';
		}

		$html .= '<p class="bmf-wb-disc">' . esc_html( $brief['disclaimer'] ?? '' ) . '</p>';
		$html .= '</div>';
		return $html;
	}

	private static function markup_history( array $brief ): string {
		$panels = [];

		$rsi = $brief['sources']['rsi']['history'] ?? [];
		if ( count( $rsi ) >= 2 ) {
			$panels[] = [
				'title'   => 'RSI pulse',
				'sub'     => 'Core · Performance  (lower is better)',
				'series'  => [
					[ 'label' => 'Core', 'color' => '#22d3ee', 'points' => self::hist_xy( $rsi, 'core' ) ],
					[ 'label' => 'Performance', 'color' => '#e91e8c', 'points' => self::hist_xy( $rsi, 'performance' ) ],
				],
			];
		}

		$pil = $brief['sources']['pillars']['history'] ?? [];
		if ( count( $pil ) >= 2 ) {
			$panels[] = [
				'title'  => 'Pillars cycle',
				'sub'    => 'Master score  (higher is better)',
				'series' => [
					[ 'label' => 'Master', 'color' => '#6ec1e4', 'points' => self::hist_xy( $pil, 'master' ) ],
				],
			];
		}

		$keys = $brief['sources']['keys']['history'] ?? [];
		if ( count( $keys ) >= 2 ) {
			$panels[] = [
				'title'  => 'Keys state',
				'sub'    => 'Overall essentials  (higher is better)',
				'series' => [
					[ 'label' => 'Overall', 'color' => '#4ade80', 'points' => self::hist_xy( $keys, 'overall' ) ],
				],
			];
		}

		$bsi = $brief['sources']['bsi']['history'] ?? [];
		if ( count( $bsi ) >= 2 ) {
			$panels[] = [
				'title'  => 'BSI cycle',
				'sub'    => 'Drivers · Mediators · Outcomes  (lower is better)',
				'series' => [
					[ 'label' => 'Drivers', 'color' => '#e91e8c', 'points' => self::hist_xy( $bsi, 'drivers' ) ],
					[ 'label' => 'Mediators', 'color' => '#3b82f6', 'points' => self::hist_xy( $bsi, 'mediators' ) ],
					[ 'label' => 'Outcomes', 'color' => '#22d3ee', 'points' => self::hist_xy( $bsi, 'outcomes' ) ],
				],
			];
		}

		$bv_hist = $brief['sources']['biovoice']['history'] ?? [];
		if ( count( $bv_hist ) >= 2 ) {
			$panels[] = [
				'title'  => 'BioVoice state',
				'sub'    => 'RDI vs personal baseline  (lower is closer)',
				'series' => [
					[ 'label' => 'RDI', 'color' => '#a78bfa', 'points' => self::hist_xy( $bv_hist, 'rdi' ) ],
				],
			];
		}

		$fb_hist = $brief['sources']['fitbit']['history'] ?? [];
		if ( count( $fb_hist ) >= 2 ) {
			$panels[] = [
				'title'  => 'Fitbit nights',
				'sub'    => 'Sleep vs 8h · efficiency  (higher is better)',
				'series' => [
					[ 'label' => 'Sleep / 8h', 'color' => '#38bdf8', 'points' => self::hist_xy( $fb_hist, 'sleep_pct' ) ],
					[ 'label' => 'Efficiency', 'color' => '#34d399', 'points' => self::hist_xy( $fb_hist, 'efficiency' ) ],
				],
			];
		}

		if ( ! $panels ) {
			return '';
		}

		$html = '<h5 class="bmf-wb-h">History</h5><div class="bmf-wb-history">';
		foreach ( $panels as $p ) {
			$html .= '<div class="bmf-wb-hpanel">';
			$html .= '<div class="bmf-wb-hpanel-t">' . esc_html( $p['title'] ) . '</div>';
			$html .= '<div class="bmf-wb-hpanel-s">' . esc_html( $p['sub'] ) . '</div>';
			$html .= '<div class="bmf-wb-hchart"><canvas class="bmf-wb-canvas" data-wb-chart="' . esc_attr( wp_json_encode( $p['series'] ) ) . '"></canvas></div>';
			$html .= '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	private static function hist_xy( array $rows, string $field ): array {
		$out = [];
		foreach ( $rows as $r ) {
			if ( ! isset( $r[ $field ] ) || $r[ $field ] === null || empty( $r['date'] ) ) {
				continue;
			}
			$out[] = [ 'x' => $r['date'], 'y' => (float) $r[ $field ] ];
		}
		return $out;
	}

	private static function source_chip( array $src ): string {
		$status = $src['status'] ?? 'missing';
		$date   = $src['date'] ?? '';
		$age    = $src['age_days'];
		$meta   = '—';
		if ( $status === 'reserved' ) {
			$meta = 'Reserved';
		} elseif ( ( $src['clock'] ?? '' ) === 'context' ) {
			$meta = $status === 'present' ? 'Context' : ( $status === 'incomplete' ? 'Incomplete' : 'No profile row' );
		} elseif ( $date !== '' ) {
			$meta = $date . ( $age !== null ? ' · ' . (int) $age . 'd' : '' );
		} elseif ( empty( $src['enabled'] ) ) {
			$meta = 'Not in this tier map';
		} else {
			$meta = 'No final row';
		}
		return '<div class="bmf-wb-chip st-' . esc_attr( $status ) . '">'
			. '<span class="nm">' . esc_html( $src['label'] ?? $src['key'] ) . '</span>'
			. '<span class="st">' . esc_html( $status ) . '</span>'
			. '<span class="dt">' . esc_html( $meta ) . '</span>'
			. '</div>';
	}

	private static function score_block( string $title, array $scores, string $direction, string $extra_class = '' ): string {
		if ( ! $scores ) {
			return '';
		}
		$class = 'bmf-wb-scores' . ( $extra_class !== '' ? ' ' . sanitize_html_class( $extra_class ) : '' );
		$html  = '<h5 class="bmf-wb-h">' . esc_html( $title ) . '</h5><div class="' . esc_attr( $class ) . '">';
		foreach ( $scores as $s ) {
			$val = $s['value'];
			$txt = $val === null ? '—' : ( isset( $s['percent'] ) ? esc_html( $s['value'] ) . '/5' : esc_html( (string) $val ) );
			$band = $s['band']['key'] ?? 'none';
			$html .= '<div class="bmf-wb-score bd-' . esc_attr( $band ) . '">';
			$html .= '<span class="lb">' . esc_html( $s['label'] ?? $s['code'] ) . '</span>';
			$html .= '<span class="vl">' . $txt . '</span>';
			$html .= '<span class="bd">' . esc_html( $s['band']['label'] ?? '' ) . '</span>';
			$html .= '</div>';
		}
		$html .= '</div>';
		return $html;
	}
}
