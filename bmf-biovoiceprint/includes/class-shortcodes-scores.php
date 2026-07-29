<?php
/**
 * BioVoicePrint - Scores / pattern dashboard shortcode.
 *
 * [bmf_biovoice_scores fixture="1"]
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Shortcodes_Scores {

	private static $customer_score_keys = [
		'regulation_stability'    => 'Regulation stability',
		'adaptive_capacity'       => 'Adaptive capacity',
		'recovery_consistency'    => 'Recovery consistency',
		'voice_pattern_stability' => 'Voice pattern stability',
		'baseline_alignment'      => 'Baseline alignment',
	];

	private static $framework_keys = [
		'activation_pattern_variability'    => 'Activation',
		'regulation_consistency_shift'      => 'Regulation',
		'flexibility_range_shift'           => 'Flexibility',
		'return_to_baseline_window_shift'   => 'Return to baseline',
		'adaptive_transition_pattern_shift' => 'Adaptive transition',
		'overall_voice_pattern_shift'       => 'Overall pattern',
	];

	public static function init() {
		add_shortcode( 'bmf_biovoice_scores', [ __CLASS__, 'shortcode_scores' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
	}

	public static function register_assets() {
		wp_register_style(
			'bmf-biovoice-scores',
			BMF_BIOVOICE_URL . 'assets/css/scores.css',
			[],
			BMF_BIOVOICE_VERSION
		);
		wp_register_script(
			'bmf-biovoice-chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
			[],
			'4.4.1',
			true
		);
		wp_register_script(
			'bmf-biovoice-scores',
			BMF_BIOVOICE_URL . 'assets/js/scores.js',
			[ 'bmf-biovoice-chartjs' ],
			BMF_BIOVOICE_VERSION,
			true
		);
	}

	public static function shortcode_scores( $atts ) {
		if ( bmf_biovoice_in_elementor_editor() ) {
			return '<div class="bmf-biovoice-placeholder" style="padding:1.25rem;border:1px dashed #64748b;border-radius:10px;text-align:center;color:#94a3b8;background:#0f172a;">BioVoicePrint Scores (preview)</div>';
		}
		if ( ! is_user_logged_in() ) {
			return '<p class="bmf-biovoice-login-required">Please log in to view BioVoicePrint scores.</p>';
		}

		$atts = shortcode_atts( [
			'fixture' => '0',
			'class'   => '',
		], $atts, 'bmf_biovoice_scores' );

		$use_fixture = in_array( strtolower( (string) $atts['fixture'] ), [ '1', 'true', 'yes' ], true );
		$resolved    = BMF_BioVoice_Results_Service::resolve_pattern_payload( get_current_user_id(), $use_fixture );

		if ( ! $resolved ) {
			return '<div class="bmf-biovoice-scores bmf-biovoice-scores--empty">'
				. '<p class="bmf-bv-empty">No score data yet. Complete comparison analysis, or use <code>fixture="1"</code> to preview the sample dashboard.</p>'
				. '</div>';
		}

		$payload  = $resolved['payload'];
		$meta     = $resolved['meta'];
		$br       = isset( $payload['baseline_relative_pattern_comparison'] ) && is_array( $payload['baseline_relative_pattern_comparison'] )
			? $payload['baseline_relative_pattern_comparison'] : [];
		$rdi      = isset( $br['rdi_score'] ) && is_array( $br['rdi_score'] ) ? $br['rdi_score'] : [];
		$stage5   = isset( $br['stage5_bsi_framework_scores'] ) && is_array( $br['stage5_bsi_framework_scores'] )
			? $br['stage5_bsi_framework_scores'] : [];
		$customer = isset( $payload['customer_facing_biovoiceprint'] ) && is_array( $payload['customer_facing_biovoiceprint'] )
			? $payload['customer_facing_biovoiceprint'] : [];

		$rdi_score = isset( $rdi['score'] ) ? (float) $rdi['score'] : null;
		$rdi_band  = isset( $rdi['band'] ) ? (string) $rdi['band'] : '';
		$rdi_color = BMF_BioVoice_Results_Service::color_class( (string) ( $rdi['color'] ?? '' ) );

		$radar_labels = [];
		$radar_values = [];
		$radar_colors = [];
		foreach ( self::$framework_keys as $key => $label ) {
			if ( empty( $stage5[ $key ] ) || ! is_array( $stage5[ $key ] ) ) {
				continue;
			}
			$radar_labels[] = $label;
			$radar_values[] = isset( $stage5[ $key ]['score'] ) ? round( (float) $stage5[ $key ]['score'], 1 ) : 0;
			$radar_colors[] = (string) ( $stage5[ $key ]['color'] ?? 'gray' );
		}

		$chart_id = 'bmf-bv-radar-' . wp_unique_id();

		wp_enqueue_style( 'bmf-biovoice-scores' );
		wp_enqueue_script( 'bmf-biovoice-chartjs' );
		wp_enqueue_script( 'bmf-biovoice-scores' );
		wp_localize_script( 'bmf-biovoice-scores', 'bmfBioVoiceScores', [
			'charts' => [
				$chart_id => [
					'labels' => $radar_labels,
					'values' => $radar_values,
					'colors' => $radar_colors,
				],
			],
		] );

		$class = 'bmf-biovoice-scores' . ( $atts['class'] ? ' ' . sanitize_html_class( $atts['class'] ) : '' );

		$customer_score_keys = self::$customer_score_keys;
		$framework_keys      = self::$framework_keys;
		$has_customer        = false;
		foreach ( $customer_score_keys as $ck => $_ ) {
			if ( ! empty( $customer[ $ck ] ) && is_array( $customer[ $ck ] ) ) {
				$has_customer = true;
				break;
			}
		}

		ob_start();
		include BMF_BIOVOICE_PATH . 'templates/scores-panel.php';
		return ob_get_clean();
	}
}
