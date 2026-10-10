<?php
/**
 * Plugin Name: Breathermae – Oura Display
 * Description: Read-only member shortcodes for Oura readiness, sleep, and activity. Uses Wellbeing File theme tokens and Chart.js lines.
 * Version: 0.2.0-poc
 * Author: Breathermae, Inc.
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BM_Oura_Display {
	private static $instance = null;
	private static $assets   = false;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_bm_oura_ui_theme', array( $this, 'ajax_theme' ) );
		add_shortcode( 'bm_oura_readiness_card', array( $this, 'sc_readiness' ) );
		add_shortcode( 'bm_oura_sleep_card', array( $this, 'sc_sleep' ) );
		add_shortcode( 'bm_oura_activity_card', array( $this, 'sc_activity' ) );
		add_shortcode( 'bm_oura_dashboard', array( $this, 'sc_dashboard' ) );
		add_shortcode( 'bm_oura_readiness_latest', array( $this, 'sc_readiness_latest' ) );
		add_shortcode( 'bm_oura_sleep_hours', array( $this, 'sc_sleep_hours' ) );
		add_shortcode( 'bm_oura_steps_avg', array( $this, 'sc_steps_avg' ) );
	}

	public function register_assets() {
		wp_register_style( 'bm-oura-display', false, array(), '0.2.0' );
		wp_register_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
			array(),
			'4.4.1',
			true
		);
		wp_register_script(
			'bm-oura-display',
			plugins_url( 'assets/oura-display.js', __FILE__ ),
			array( 'chartjs' ),
			'0.2.0',
			true
		);
	}

	private function enqueue() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		wp_enqueue_style( 'bm-oura-display' );
		wp_add_inline_style( 'bm-oura-display', $this->css() );
		wp_enqueue_script( 'bm-oura-display' );
		wp_localize_script( 'bm-oura-display', 'bmOura', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'bm_oura_ui_theme' ),
		) );
	}

	public function ajax_theme() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Please log in.' ), 401 );
		}
		check_ajax_referer( 'bm_oura_ui_theme' );
		$theme = isset( $_POST['theme'] ) && $_POST['theme'] === 'light' ? 'light' : 'dark';
		update_user_meta( get_current_user_id(), 'bmf_ui_theme', $theme );
		wp_send_json_success( array( 'theme' => $theme ) );
	}

	private function theme() {
		$raw = get_user_meta( get_current_user_id(), 'bmf_ui_theme', true );
		return $raw === 'light' ? 'light' : 'dark';
	}

	private function resolve_user( $atts_user_id ) {
		$current = get_current_user_id();
		if ( ! $current ) {
			return 0;
		}
		$atts_user_id = absint( $atts_user_id );
		if ( $atts_user_id && current_user_can( 'list_users' ) ) {
			return $atts_user_id;
		}
		return $current;
	}

	private function rows( $table, $day_col, $cols, $uid, $days ) {
		global $wpdb;
		$start = wp_date( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', current_time( 'timestamp' ) ) );
		$sql   = "SELECT {$day_col} AS day, " . implode( ', ', $cols ) . " FROM {$table} WHERE wp_user_id = %d AND {$day_col} >= %s ORDER BY {$day_col} ASC";
		$found = $wpdb->get_results( $wpdb->prepare( $sql, $uid, $start ), ARRAY_A );
		return is_array( $found ) ? $found : array();
	}

	private function chart_points( $rows, $field, $divisor = 1 ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row[ $field ] ) || $row[ $field ] === null || $row[ $field ] === '' ) {
				continue;
			}
			$out[] = array(
				'x' => $row['day'],
				'y' => round( (float) $row[ $field ] / $divisor, 2 ),
			);
		}
		return $out;
	}

	private function latest( $rows ) {
		return $rows ? $rows[ count( $rows ) - 1 ] : array();
	}

	private function hm( $seconds ) {
		if ( $seconds === null || $seconds === '' ) {
			return '—';
		}
		$seconds = (int) $seconds;
		return sprintf( '%dh %02dm', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
	}

	private function card( $title, $chips, $points, $scale, $label, $empty ) {
		$this->enqueue();
		$html  = '<div class="bmf-wb-wrap" data-bmf-theme="' . esc_attr( $this->theme() ) . '"><section class="bmf-wb-card bm-oura-panel">';
		$html .= '<div class="bmf-wb-head"><h4>' . esc_html( $title ) . '</h4></div>';
		if ( ! $points ) {
			$html .= '<div class="bmf-wb-empty">' . esc_html( $empty ) . '</div></section></div>';
			return $html;
		}
		$html .= '<div class="bmf-wb-scores">';
		foreach ( $chips as $chip ) {
			$html .= '<div class="bmf-wb-score"><span class="lb">' . esc_html( $chip['label'] ) . '</span><span class="vl">' . esc_html( $chip['value'] ) . '</span></div>';
		}
		$html .= '</div>';
		$html .= '<div class="bmf-wb-hchart"><canvas class="bm-oura-canvas" data-oura-scale="' . esc_attr( $scale ) . '" data-oura-label="' . esc_attr( $label ) . '" data-oura-chart="' . esc_attr( wp_json_encode( $points ) ) . '"></canvas></div>';
		$html .= '</section></div>';
		return $html;
	}

	private function themebar() {
		$theme = $this->theme();
		return '<div class="bmf-wb-themebar" role="group" aria-label="Result theme">'
			. '<span class="bmf-wb-themebar-label">Theme</span>'
			. '<button type="button" class="bmf-wb-theme-btn" data-bmf-theme-set="light" aria-label="Switch to light theme" aria-pressed="' . ( $theme === 'light' ? 'true' : 'false' ) . '">☀</button>'
			. '<button type="button" class="bmf-wb-theme-btn" data-bmf-theme-set="dark" aria-label="Switch to dark theme" aria-pressed="' . ( $theme === 'dark' ? 'true' : 'false' ) . '">☾</button>'
			. '</div>';
	}

	public function sc_readiness( $atts ) {
		$a   = shortcode_atts( array( 'days' => '14', 'user_id' => '', 'title' => 'Oura readiness' ), $atts, 'bm_oura_readiness_card' );
		$uid = $this->resolve_user( $a['user_id'] );
		if ( ! $uid ) {
			return '<div class="bmf-wb-empty">Please log in to view Oura readiness.</div>';
		}
		$days = max( 2, min( 90, absint( $a['days'] ) ) );
		global $wpdb;
		$rows   = $this->rows( $wpdb->prefix . 'bm_oura_readiness', 'readiness_day', array( 'score', 'temperature_deviation', 'hrv_balance' ), $uid, $days );
		$latest = $this->latest( $rows );
		return $this->card(
			$a['title'],
			array(
				array( 'label' => 'Latest', 'value' => isset( $latest['score'] ) && $latest['score'] !== null ? (string) (int) $latest['score'] : '—' ),
				array( 'label' => 'Temp dev.', 'value' => isset( $latest['temperature_deviation'] ) && $latest['temperature_deviation'] !== null ? $latest['temperature_deviation'] . ' °C' : '—' ),
				array( 'label' => 'HRV balance', 'value' => isset( $latest['hrv_balance'] ) && $latest['hrv_balance'] !== null ? (string) (int) $latest['hrv_balance'] : '—' ),
			),
			$this->chart_points( $rows, 'score' ),
			'score',
			'Readiness',
			'No Oura readiness stored yet.'
		);
	}

	public function sc_sleep( $atts ) {
		$a   = shortcode_atts( array( 'days' => '14', 'user_id' => '', 'title' => 'Oura sleep' ), $atts, 'bm_oura_sleep_card' );
		$uid = $this->resolve_user( $a['user_id'] );
		if ( ! $uid ) {
			return '<div class="bmf-wb-empty">Please log in to view Oura sleep.</div>';
		}
		$days = max( 2, min( 90, absint( $a['days'] ) ) );
		global $wpdb;
		$rows   = $this->rows( $wpdb->prefix . 'bm_oura_sleep', 'sleep_day', array( 'score', 'total_sleep_sec', 'efficiency', 'avg_hrv' ), $uid, $days );
		$latest = $this->latest( $rows );
		return $this->card(
			$a['title'],
			array(
				array( 'label' => 'Score', 'value' => isset( $latest['score'] ) && $latest['score'] !== null ? (string) (int) $latest['score'] : '—' ),
				array( 'label' => 'Asleep', 'value' => $this->hm( $latest['total_sleep_sec'] ?? null ) ),
				array( 'label' => 'Efficiency', 'value' => isset( $latest['efficiency'] ) && $latest['efficiency'] !== null ? (int) $latest['efficiency'] . '%' : '—' ),
				array( 'label' => 'HRV', 'value' => isset( $latest['avg_hrv'] ) && $latest['avg_hrv'] !== null ? (string) (int) $latest['avg_hrv'] : '—' ),
			),
			$this->chart_points( $rows, 'score' ),
			'score',
			'Sleep score',
			'No Oura sleep stored yet.'
		);
	}

	public function sc_activity( $atts ) {
		$a   = shortcode_atts( array( 'days' => '14', 'user_id' => '', 'title' => 'Oura activity' ), $atts, 'bm_oura_activity_card' );
		$uid = $this->resolve_user( $a['user_id'] );
		if ( ! $uid ) {
			return '<div class="bmf-wb-empty">Please log in to view Oura activity.</div>';
		}
		$days = max( 2, min( 90, absint( $a['days'] ) ) );
		global $wpdb;
		$rows   = $this->rows( $wpdb->prefix . 'bm_oura_activity', 'activity_day', array( 'score', 'steps', 'active_calories' ), $uid, $days );
		$latest = $this->latest( $rows );
		return $this->card(
			$a['title'],
			array(
				array( 'label' => 'Score', 'value' => isset( $latest['score'] ) && $latest['score'] !== null ? (string) (int) $latest['score'] : '—' ),
				array( 'label' => 'Steps', 'value' => isset( $latest['steps'] ) && $latest['steps'] !== null ? number_format_i18n( (int) $latest['steps'] ) : '—' ),
				array( 'label' => 'Active kcal', 'value' => isset( $latest['active_calories'] ) && $latest['active_calories'] !== null ? number_format_i18n( (int) $latest['active_calories'] ) : '—' ),
			),
			$this->chart_points( $rows, 'score' ),
			'score',
			'Activity score',
			'No Oura activity stored yet.'
		);
	}

	public function sc_dashboard( $atts ) {
		$a   = shortcode_atts( array( 'days' => '14', 'user_id' => '' ), $atts, 'bm_oura_dashboard' );
		$uid = $this->resolve_user( $a['user_id'] );
		if ( ! $uid ) {
			return '<div class="bmf-wb-empty">Please log in to view Oura.</div>';
		}
		$this->enqueue();
		$days = max( 2, min( 90, absint( $a['days'] ) ) );
		$html  = '<div class="bmf-wb-wrap" data-bmf-theme="' . esc_attr( $this->theme() ) . '">';
		$html .= $this->themebar();
		$html .= '<div class="bm-oura-grid">';
		$html .= $this->sc_readiness( array( 'days' => $days, 'user_id' => $uid ) );
		$html .= $this->sc_sleep( array( 'days' => $days, 'user_id' => $uid ) );
		$html .= $this->sc_activity( array( 'days' => $days, 'user_id' => $uid ) );
		$html .= '</div><p class="bmf-wb-note">Lines are Oura scores, 0–100. Chips are the latest night or day. Theme is shared with Wellbeing File.</p></div>';
		return $html;
	}

	public function sc_readiness_latest( $atts ) {
		$a   = shortcode_atts( array( 'user_id' => '' ), $atts );
		$uid = $this->resolve_user( $a['user_id'] );
		if ( ! $uid ) {
			return '';
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bm_oura_readiness';
		$score = $wpdb->get_var( $wpdb->prepare( "SELECT score FROM {$table} WHERE wp_user_id = %d ORDER BY readiness_day DESC LIMIT 1", $uid ) );
		return $score !== null ? (string) (int) $score : '—';
	}

	public function sc_sleep_hours( $atts ) {
		$a   = shortcode_atts( array( 'days' => '7', 'user_id' => '' ), $atts );
		$uid = $this->resolve_user( $a['user_id'] );
		if ( ! $uid ) {
			return '';
		}
		$days = max( 1, min( 30, absint( $a['days'] ) ) );
		global $wpdb;
		$table = $wpdb->prefix . 'bm_oura_sleep';
		$start = wp_date( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', current_time( 'timestamp' ) ) );
		$avg   = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(total_sleep_sec) FROM {$table} WHERE wp_user_id = %d AND sleep_day >= %s", $uid, $start ) );
		return $avg !== null ? $this->hm( (int) round( $avg ) ) : '—';
	}

	public function sc_steps_avg( $atts ) {
		$a   = shortcode_atts( array( 'days' => '7', 'user_id' => '' ), $atts );
		$uid = $this->resolve_user( $a['user_id'] );
		if ( ! $uid ) {
			return '';
		}
		$days = max( 1, min( 30, absint( $a['days'] ) ) );
		global $wpdb;
		$table = $wpdb->prefix . 'bm_oura_activity';
		$start = wp_date( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', current_time( 'timestamp' ) ) );
		$avg   = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(steps) FROM {$table} WHERE wp_user_id = %d AND activity_day >= %s", $uid, $start ) );
		return $avg !== null ? number_format_i18n( (int) round( $avg ) ) . ' steps/day' : '—';
	}

	private function css() {
		return '
.bmf-wb-wrap{--bmf-bg:#0b1b3a;--bmf-chip:#102448;--bmf-inset:#021322;--bmf-sheen:linear-gradient(180deg,rgba(255,255,255,.07),rgba(255,255,255,0) 42%);--bmf-text:#e8eef8;--bmf-muted:#9db0d0;--bmf-heading:#cfe6ff;--bmf-strong:#fff;--bmf-border:#233b6d;--bmf-accent:#6ec1e4;--bmf-badge-fg:#0b1b3a}
.bmf-wb-wrap[data-bmf-theme="light"]{--bmf-bg:#f4f7fb;--bmf-chip:#fff;--bmf-inset:#fff;--bmf-sheen:linear-gradient(180deg,rgba(255,255,255,.95),rgba(226,232,240,.4));--bmf-text:#122033;--bmf-muted:#5b6b82;--bmf-heading:#1e3a5f;--bmf-strong:#0b1b3a;--bmf-border:#d5deeb;--bmf-accent:#0284c7;--bmf-badge-fg:#fff}
.bmf-wb-wrap,.bmf-wb-wrap p,.bmf-wb-wrap strong{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--bmf-text)}
.bm-oura-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
.bmf-wb-card{background:var(--bmf-bg);border:1px solid var(--bmf-border);border-radius:12px;padding:16px 18px;margin:0 0 16px}
.bmf-wb-head{display:flex;justify-content:space-between;gap:12px;align-items:baseline;flex-wrap:wrap}
.bmf-wb-head h4{margin:0 0 10px;color:var(--bmf-strong);font-size:1.05rem}
.bmf-wb-note{color:var(--bmf-muted);font-size:.82rem;margin:8px 0 0}
.bmf-wb-scores{display:grid;grid-template-columns:repeat(auto-fit,minmax(92px,1fr));gap:8px;margin:0 0 10px}
.bmf-wb-score{background:var(--bmf-chip);background-image:var(--bmf-sheen);border:1px solid var(--bmf-border);border-radius:8px;padding:8px 10px;display:flex;flex-direction:column;gap:2px}
.bmf-wb-score .lb{font-size:.72rem;color:var(--bmf-heading)}
.bmf-wb-score .vl{font-size:1rem;font-weight:600;color:var(--bmf-strong)}
.bmf-wb-hchart{position:relative;height:150px}
.bmf-wb-empty{padding:16px;border:1px dashed var(--bmf-border);border-radius:8px;color:var(--bmf-muted);background:var(--bmf-bg)}
.bmf-wb-themebar{display:flex;align-items:center;justify-content:flex-end;gap:6px;margin:0 0 8px}
.bmf-wb-themebar-label{font-size:.68rem;letter-spacing:.06em;text-transform:uppercase;color:var(--bmf-muted);font-weight:700}
.bmf-wb-theme-btn{appearance:none;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;padding:0;border-radius:8px;cursor:pointer;border:1px solid var(--bmf-border);background:var(--bmf-chip);color:var(--bmf-muted)}
.bmf-wb-theme-btn[aria-pressed="true"]{color:var(--bmf-strong);border-color:var(--bmf-accent);box-shadow:0 0 0 1px var(--bmf-accent)}
';
	}
}

BM_Oura_Display::instance();
