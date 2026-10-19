<?php
/**
 * Plugin Name: Breathermae – Fitbit Display
 * Description: Read-only Fitbit shortcodes. Dashboard and cards use Wellbeing File theme tokens and Chart.js lines.
 * Version: 1.3.0-poc
 * Author: Breathermae, Inc.
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BM_Fitbit_Display {
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
		add_action( 'wp_ajax_bm_fitbit_ui_theme', array( $this, 'ajax_theme' ) );
		add_shortcode( 'bm_fitbit_dashboard', array( $this, 'sc_dashboard' ) );
		add_shortcode( 'bm_fitbit_sleep_card', array( $this, 'sc_sleep' ) );
		add_shortcode( 'bm_fitbit_activity_card', array( $this, 'sc_activity' ) );
		add_shortcode( 'bm_fitbit_rhr_card', array( $this, 'sc_rhr' ) );
		add_shortcode( 'bm_fitbit_hrv_card', array( $this, 'sc_hrv' ) );
		add_shortcode( 'bm_fitbit_sleep_avg_asleep', array( $this, 'sc_sleep_avg' ) );
		add_shortcode( 'bm_fitbit_sleep_avg_efficiency', array( $this, 'sc_eff_avg' ) );
		add_shortcode( 'bm_fitbit_rhr_avg', array( $this, 'sc_rhr_avg' ) );
		add_shortcode( 'bm_fitbit_steps_avg', array( $this, 'sc_steps_avg' ) );
	}

	public function register_assets() {
		wp_register_style( 'bm-fitbit-display', false, array(), '1.3.0' );
		wp_register_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
			array(),
			'4.4.1',
			true
		);
		wp_register_script(
			'bm-fitbit-display',
			plugins_url( 'assets/fitbit-display.js', __FILE__ ),
			array( 'chartjs' ),
			'1.3.0',
			true
		);
	}

	private function enqueue() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		wp_enqueue_style( 'bm-fitbit-display' );
		wp_add_inline_style( 'bm-fitbit-display', $this->css() );
		wp_enqueue_script( 'bm-fitbit-display' );
		wp_localize_script( 'bm-fitbit-display', 'bmFitbit', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'bm_fitbit_ui_theme' ),
		) );
	}

	public function ajax_theme() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Please log in.' ), 401 );
		}
		check_ajax_referer( 'bm_fitbit_ui_theme' );
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

	private function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	private function rows( $table, $day_col, $cols, $uid, $days ) {
		global $wpdb;
		$start = wp_date( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', current_time( 'timestamp' ) ) );
		$sql   = "SELECT {$day_col} AS day, " . implode( ', ', $cols ) . " FROM {$table} WHERE wp_user_id = %d AND {$day_col} >= %s ORDER BY {$day_col} ASC";
		$found = $wpdb->get_results( $wpdb->prepare( $sql, $uid, $start ), ARRAY_A );
		return is_array( $found ) ? $found : array();
	}

	private function points( $rows, $field, $divisor = 1 ) {
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

	private function hm_minutes( $mins ) {
		if ( $mins === null || $mins === '' ) {
			return '—';
		}
		$mins = (int) $mins;
		return sprintf( '%dh %02dm', intdiv( $mins, 60 ), $mins % 60 );
	}

	private function card( $title, $chips, $points, $scale, $label, $empty ) {
		$this->enqueue();
		$html  = '<div class="bmf-wb-wrap" data-bmf-theme="' . esc_attr( $this->theme() ) . '"><section class="bmf-wb-card bm-fitbit-panel">';
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
		$html .= '<div class="bmf-wb-hchart"><canvas class="bm-fitbit-canvas" data-fitbit-scale="' . esc_attr( $scale ) . '" data-fitbit-label="' . esc_attr( $label ) . '" data-fitbit-chart="' . esc_attr( wp_json_encode( $points ) ) . '"></canvas></div>';
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

	public function sc_sleep( $atts ) {
		$a   = shortcode_atts( array( 'days' => '7', 'user_id' => '', 'title' => 'Fitbit sleep' ), $atts, 'bm_fitbit_sleep_card' );
		$uid = $this->resolve_user( $a['user_id'] );
		if ( ! $uid ) {
			return '<div class="bmf-wb-empty">Please log in to view Fitbit sleep.</div>';
		}
		$days   = max( 2, min( 90, absint( $a['days'] ) ) );
		$rows   = $this->rows( $this->table( 'bm_fitbit_sleep_summary' ), 'date_of_sleep', array( 'minutes_asleep', 'efficiency', 'minutes_deep', 'minutes_rem' ), $uid, $days );
		$latest = $this->latest( $rows );
		return $this->card(
			$a['title'],
			array(
				array( 'label' => 'Asleep', 'value' => $this->hm_minutes( $latest['minutes_asleep'] ?? null ) ),
				array( 'label' => 'Efficiency', 'value' => isset( $latest['efficiency'] ) && $latest['efficiency'] !== null ? (int) $latest['efficiency'] . '%' : '—' ),
				array( 'label' => 'Deep', 'value' => $this->hm_minutes( $latest['minutes_deep'] ?? null ) ),
				array( 'label' => 'REM', 'value' => $this->hm_minutes( $latest['minutes_rem'] ?? null ) ),
			),
			$this->points( $rows, 'minutes_asleep', 60 ),
			'hours',
			'Sleep hours',
			'No Fitbit sleep stored yet.'
		);
	}

	public function sc_activity( $atts ) {
		$a   = shortcode_atts( array( 'days' => '7', 'user_id' => '', 'title' => 'Fitbit activity' ), $atts, 'bm_fitbit_activity_card' );
		$uid = $this->resolve_user( $a['user_id'] );
		if ( ! $uid ) {
			return '<div class="bmf-wb-empty">Please log in to view Fitbit activity.</div>';
		}
		$days   = max( 2, min( 90, absint( $a['days'] ) ) );
		$rows   = $this->rows( $this->table( 'bm_fitbit_activity_summary' ), 'activity_date', array( 'steps', 'distance_km', 'lightly_active_min', 'fairly_active_min', 'very_active_min' ), $uid, $days );
		$latest = $this->latest( $rows );
		$active = null;
		if ( $latest ) {
			$active = (int) ( $latest['lightly_active_min'] ?? 0 ) + (int) ( $latest['fairly_active_min'] ?? 0 ) + (int) ( $latest['very_active_min'] ?? 0 );
		}
		$miles = isset( $latest['distance_km'] ) && $latest['distance_km'] !== null ? round( (float) $latest['distance_km'] * 0.621371, 1 ) . ' mi' : '—';
		return $this->card(
			$a['title'],
			array(
				array( 'label' => 'Steps', 'value' => isset( $latest['steps'] ) && $latest['steps'] !== null ? number_format_i18n( (int) $latest['steps'] ) : '—' ),
				array( 'label' => 'Distance', 'value' => $miles ),
				array( 'label' => 'Active', 'value' => $active !== null ? $this->hm_minutes( $active ) : '—' ),
			),
			$this->points( $rows, 'steps' ),
			'steps',
			'Steps',
			'No Fitbit activity stored yet.'
		);
	}

	public function sc_rhr( $atts ) {
		$a   = shortcode_atts( array( 'days' => '7', 'user_id' => '', 'title' => 'Resting heart rate' ), $atts, 'bm_fitbit_rhr_card' );
		$uid = $this->resolve_user( $a['user_id'] );
		if ( ! $uid ) {
			return '<div class="bmf-wb-empty">Please log in to view resting heart rate.</div>';
		}
		$days   = max( 2, min( 90, absint( $a['days'] ) ) );
		$rows   = $this->rows( $this->table( 'bm_fitbit_daily_rhr' ), 'rhr_date', array( 'resting_bpm' ), $uid, $days );
		$latest = $this->latest( $rows );
		$vals   = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['resting_bpm'] ) && $row['resting_bpm'] !== null ) {
				$vals[] = (float) $row['resting_bpm'];
			}
		}
		$avg = $vals ? (int) round( array_sum( $vals ) / count( $vals ) ) : null;
		$low = $vals ? (int) min( $vals ) : null;
		return $this->card(
			$a['title'],
			array(
				array( 'label' => 'Latest', 'value' => isset( $latest['resting_bpm'] ) && $latest['resting_bpm'] !== null ? (int) $latest['resting_bpm'] . ' bpm' : '—' ),
				array( 'label' => 'Average', 'value' => $avg !== null ? $avg . ' bpm' : '—' ),
				array( 'label' => 'Low', 'value' => $low !== null ? $low . ' bpm' : '—' ),
			),
			$this->points( $rows, 'resting_bpm' ),
			'bpm',
			'Resting HR',
			'No resting heart rate stored yet.'
		);
	}

	public function sc_hrv( $atts ) {
		$a   = shortcode_atts( array( 'days' => '7', 'user_id' => '', 'title' => 'Heart rate variability' ), $atts, 'bm_fitbit_hrv_card' );
		$uid = $this->resolve_user( $a['user_id'] );
		if ( ! $uid ) {
			return '<div class="bmf-wb-empty">Please log in to view heart rate variability.</div>';
		}
		$days   = max( 2, min( 90, absint( $a['days'] ) ) );
		$rows   = $this->rows( $this->table( 'bm_fitbit_hrv_summary' ), 'hrv_date', array( 'daily_rmssd_ms', 'deep_rmssd_ms' ), $uid, $days );
		$latest = $this->latest( $rows );
		return $this->card(
			$a['title'],
			array(
				array( 'label' => 'Daily', 'value' => isset( $latest['daily_rmssd_ms'] ) && $latest['daily_rmssd_ms'] !== null ? (int) $latest['daily_rmssd_ms'] . ' ms' : '—' ),
				array( 'label' => 'Deep', 'value' => isset( $latest['deep_rmssd_ms'] ) && $latest['deep_rmssd_ms'] !== null ? (int) $latest['deep_rmssd_ms'] . ' ms' : '—' ),
			),
			$this->points( $rows, 'daily_rmssd_ms' ),
			'ms',
			'Daily RMSSD',
			'No heart rate variability stored yet.'
		);
	}

	public function sc_dashboard( $atts ) {
		$a   = shortcode_atts( array( 'days' => '7', 'user_id' => '' ), $atts, 'bm_fitbit_dashboard' );
		$uid = $this->resolve_user( $a['user_id'] );
		if ( ! $uid ) {
			return '<div class="bmf-wb-empty">Please log in to view Fitbit.</div>';
		}
		$this->enqueue();
		$days = max( 2, min( 90, absint( $a['days'] ) ) );
		$html  = '<div class="bmf-wb-wrap" data-bmf-theme="' . esc_attr( $this->theme() ) . '">';
		$html .= $this->themebar();
		$html .= '<div class="bm-fitbit-grid">';
		$html .= $this->sc_sleep( array( 'days' => $days, 'user_id' => $uid ) );
		$html .= $this->sc_activity( array( 'days' => $days, 'user_id' => $uid ) );
		$html .= $this->sc_rhr( array( 'days' => $days, 'user_id' => $uid ) );
		$html .= $this->sc_hrv( array( 'days' => $days, 'user_id' => $uid ) );
		$html .= '</div><p class="bmf-wb-note">Lines are the nightly series. Chips are the latest stored day. Theme is shared with Wellbeing File.</p></div>';
		return $html;
	}

	private function scalar_user( $atts ) {
		$uid = isset( $atts['user_id'] ) && $atts['user_id'] !== '' ? absint( $atts['user_id'] ) : get_current_user_id();
		return $uid ?: 0;
	}

	public function sc_sleep_avg( $atts ) {
		$a   = shortcode_atts( array( 'days' => '7', 'user_id' => '' ), $atts );
		$uid = $this->scalar_user( $a );
		if ( ! $uid ) {
			return '';
		}
		global $wpdb;
		$days  = max( 1, absint( $a['days'] ) );
		$start = wp_date( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', current_time( 'timestamp' ) ) );
		$avg   = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(minutes_asleep) FROM {$this->table( 'bm_fitbit_sleep_summary' )} WHERE wp_user_id = %d AND date_of_sleep >= %s", $uid, $start ) );
		return $avg !== null ? $this->hm_minutes( (int) round( $avg ) ) : '—';
	}

	public function sc_eff_avg( $atts ) {
		$a   = shortcode_atts( array( 'days' => '7', 'user_id' => '' ), $atts );
		$uid = $this->scalar_user( $a );
		if ( ! $uid ) {
			return '';
		}
		global $wpdb;
		$days  = max( 1, absint( $a['days'] ) );
		$start = wp_date( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', current_time( 'timestamp' ) ) );
		$avg   = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(efficiency) FROM {$this->table( 'bm_fitbit_sleep_summary' )} WHERE wp_user_id = %d AND date_of_sleep >= %s", $uid, $start ) );
		return $avg !== null ? (int) round( $avg ) . '%' : '—';
	}

	public function sc_rhr_avg( $atts ) {
		$a   = shortcode_atts( array( 'days' => '7', 'user_id' => '' ), $atts );
		$uid = $this->scalar_user( $a );
		if ( ! $uid ) {
			return '';
		}
		global $wpdb;
		$days  = max( 1, absint( $a['days'] ) );
		$start = wp_date( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', current_time( 'timestamp' ) ) );
		$avg   = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(resting_bpm) FROM {$this->table( 'bm_fitbit_daily_rhr' )} WHERE wp_user_id = %d AND rhr_date >= %s", $uid, $start ) );
		return $avg !== null ? (int) round( $avg ) . ' bpm' : '—';
	}

	public function sc_steps_avg( $atts ) {
		$a   = shortcode_atts( array( 'days' => '7', 'user_id' => '' ), $atts );
		$uid = $this->scalar_user( $a );
		if ( ! $uid ) {
			return '';
		}
		global $wpdb;
		$days  = max( 1, absint( $a['days'] ) );
		$start = wp_date( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', current_time( 'timestamp' ) ) );
		$avg   = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(steps) FROM {$this->table( 'bm_fitbit_activity_summary' )} WHERE wp_user_id = %d AND activity_date >= %s", $uid, $start ) );
		return $avg !== null ? number_format_i18n( (int) round( $avg ) ) . ' steps/day' : '—';
	}

	private function css() {
		return '
.bmf-wb-wrap{--bmf-bg:#0b1b3a;--bmf-chip:#102448;--bmf-inset:#021322;--bmf-sheen:linear-gradient(180deg,rgba(255,255,255,.07),rgba(255,255,255,0) 42%);--bmf-text:#e8eef8;--bmf-muted:#9db0d0;--bmf-heading:#cfe6ff;--bmf-strong:#fff;--bmf-border:#233b6d;--bmf-accent:#6ec1e4;--bmf-badge-fg:#0b1b3a}
.bmf-wb-wrap[data-bmf-theme="light"]{--bmf-bg:#f4f7fb;--bmf-chip:#fff;--bmf-inset:#fff;--bmf-sheen:linear-gradient(180deg,rgba(255,255,255,.95),rgba(226,232,240,.4));--bmf-text:#122033;--bmf-muted:#5b6b82;--bmf-heading:#1e3a5f;--bmf-strong:#0b1b3a;--bmf-border:#d5deeb;--bmf-accent:#0284c7;--bmf-badge-fg:#fff}
.bmf-wb-wrap,.bmf-wb-wrap p,.bmf-wb-wrap strong{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--bmf-text)}
.bm-fitbit-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
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

BM_Fitbit_Display::instance();
