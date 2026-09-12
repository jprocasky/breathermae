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
			'bmf-wellbeing',
			BMF_WELLBEING_URL . 'assets/js/wellbeing.js',
			[],
			file_exists( $js ) ? (string) filemtime( $js ) : BMF_WELLBEING_VERSION,
			true
		);
	}

	private static function enqueue( array $cfg ) {
		wp_enqueue_style( 'bmf-wellbeing' );
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
		$html .= '<p class="bmf-wb-sub">Pulse = RSI · Cycle = Pillars · State = Key Essentials. Map ' . esc_html( $brief['map_version'] ?? 'v1' ) . '.</p>';

		$html .= '<div class="bmf-wb-grid">';
		foreach ( [ 'rsi', 'pillars', 'keys', 'bsi', 'biovoice', 'fitbit' ] as $k ) {
			if ( isset( $brief['sources'][ $k ] ) ) {
				$html .= self::source_chip( $brief['sources'][ $k ] );
			}
		}
		$html .= '</div>';

		if ( ! empty( $brief['patterns'] ) ) {
			$html .= '<h5 class="bmf-wb-h">Themes</h5><ul class="bmf-wb-patterns">';
			foreach ( $brief['patterns'] as $p ) {
				$copy = $voice === 'provider' ? ( $p['provider'] ?? '' ) : ( $p['member'] ?? '' );
				$html .= '<li class="sev-' . esc_attr( $p['severity'] ?? 'info' ) . '"><strong>' . esc_html( $p['title'] ?? '' ) . '</strong> ' . esc_html( $copy ) . '</li>';
			}
			$html .= '</ul>';
		}

		$html .= self::score_block( 'RSI (pulse)', $brief['sources']['rsi']['scores'] ?? [], 'low_better' );
		$html .= self::score_block( '8 Pillars (cycle)', $brief['sources']['pillars']['scores'] ?? [], 'high_better', 'is-pillars' );
		if ( ! empty( $brief['sources']['pillars']['master'] ) ) {
			$html .= '<p class="bmf-wb-note">Pillars master score: ' . esc_html( (string) $brief['sources']['pillars']['master'] ) . '</p>';
		}
		$html .= self::score_block( 'Key Essentials (state)', $brief['sources']['keys']['scores'] ?? [], 'high_better' );

		if ( ! empty( $brief['highlights'] ) ) {
			$html .= '<h5 class="bmf-wb-h">Highlight items</h5><ul class="bmf-wb-hi">';
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

		$html .= '<p class="bmf-wb-disc">' . esc_html( $brief['disclaimer'] ?? '' ) . '</p>';
		$html .= '</div>';
		return $html;
	}

	private static function source_chip( array $src ): string {
		$status = $src['status'] ?? 'missing';
		$date   = $src['date'] ?? '';
		$age    = $src['age_days'];
		$meta   = '—';
		if ( $status === 'reserved' ) {
			$meta = 'Reserved';
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
