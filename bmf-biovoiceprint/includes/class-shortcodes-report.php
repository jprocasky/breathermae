<?php
/**
 * BioVoicePrint – Plain-language report shortcode.
 * Loaded from main plugin; keeps report UI out of the large shortcodes class file.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Shortcodes_Report {

	public static function init() {
		add_shortcode( 'bmf_biovoice_report', [ __CLASS__, 'shortcode_report' ] );
	}

	/**
	 * [bmf_biovoice_report fixture="1"]
	 * Plain-language stage-7 report. fixture=1 uses bundled sample; otherwise latest DB result.
	 */
	public static function shortcode_report( $atts ) {
		if ( bmf_biovoice_in_elementor_editor() ) {
			return '<div class="bmf-biovoice-placeholder" style="padding:1.25rem;border:1px dashed #64748b;border-radius:10px;text-align:center;color:#94a3b8;background:#0f172a;">BioVoicePrint Report (preview)</div>';
		}
		if ( ! is_user_logged_in() ) {
			return '<p class="bmf-biovoice-login-required">Please log in to view your BioVoicePrint report.</p>';
		}

		$atts = shortcode_atts( [
			'fixture' => '0',
			'class'   => '',
		], $atts, 'bmf_biovoice_report' );

		$use_fixture = in_array( strtolower( (string) $atts['fixture'] ), [ '1', 'true', 'yes' ], true );
		$resolved    = BMF_BioVoice_Results_Service::resolve_plain_report( get_current_user_id(), $use_fixture );

		if ( ! $resolved ) {
			return '<div class="bmf-biovoice-report bmf-biovoice-report--empty">'
				. '<p class="bmf-bv-empty">No analysis report yet. Complete comparison sessions, or use <code>fixture="1"</code> to preview the sample report.</p>'
				. '</div>';
		}

		$report = $resolved['report'];
		$meta   = $resolved['meta'];
		$overall = isset( $report['overall_result'] ) && is_array( $report['overall_result'] ) ? $report['overall_result'] : [];
		$findings = isset( $report['key_findings'] ) && is_array( $report['key_findings'] ) ? $report['key_findings'] : [];
		$tips = isset( $report['before_you_speak_or_present'] ) && is_array( $report['before_you_speak_or_present'] ) ? $report['before_you_speak_or_present'] : [];
		$watch = isset( $report['what_to_watch_over_time'] ) && is_array( $report['what_to_watch_over_time'] ) ? $report['what_to_watch_over_time'] : [];
		$preventive = isset( $report['preventive_wellness_insight'] ) && is_array( $report['preventive_wellness_insight'] ) ? $report['preventive_wellness_insight'] : [];
		$scores = isset( $report['customer_facing_scores'] ) && is_array( $report['customer_facing_scores'] ) ? $report['customer_facing_scores'] : [];

		$rdi_score = isset( $overall['rdi_score_0_100'] ) ? (float) $overall['rdi_score_0_100'] : null;
		$rdi_band  = isset( $overall['rdi_band'] ) ? (string) $overall['rdi_band'] : '';
		$rdi_color = isset( $overall['rdi_color'] ) ? (string) $overall['rdi_color'] : '';
		$color_mod = BMF_BioVoice_Results_Service::color_class( $rdi_color );

		wp_enqueue_style( 'bmf-biovoice-recorder' );

		$class = 'bmf-biovoice-report' . ( $atts['class'] ? ' ' . sanitize_html_class( $atts['class'] ) : '' );

		ob_start();
		?>
		<article class="<?php echo esc_attr( $class ); ?>" data-bmf-biovoice-report data-source="<?php echo esc_attr( $meta['source'] ); ?>">
			<header class="bmf-bv-report-head">
				<div class="bmf-bv-report-kicker">BioVoicePrint</div>
				<h2 class="bmf-bv-report-title"><?php echo esc_html( $report['report_title'] ?? 'Performance and Wellness Insights' ); ?></h2>
				<?php if ( ! empty( $meta['is_fixture'] ) ) : ?>
					<div class="bmf-bv-report-fixture-badge">Sample preview</div>
				<?php elseif ( ! empty( $meta['analyzed_at'] ) ) : ?>
					<div class="bmf-bv-report-date"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $meta['analyzed_at'] ) ); ?></div>
				<?php endif; ?>
			</header>

			<section class="bmf-bv-report-hero <?php echo esc_attr( $color_mod ); ?>">
				<div class="bmf-bv-report-rdi">
					<?php if ( $rdi_score !== null ) : ?>
						<div class="bmf-bv-report-rdi-value"><?php echo esc_html( number_format_i18n( $rdi_score, 1 ) ); ?><span class="bmf-bv-report-rdi-max">/100</span></div>
					<?php endif; ?>
					<?php if ( $rdi_band ) : ?>
						<div class="bmf-bv-report-rdi-band"><?php echo esc_html( $rdi_band ); ?></div>
					<?php endif; ?>
					<?php if ( ! empty( $overall['score_direction'] ) ) : ?>
						<div class="bmf-bv-report-rdi-note"><?php echo esc_html( $overall['score_direction'] ); ?></div>
					<?php endif; ?>
				</div>
				<div class="bmf-bv-report-hero-text">
					<?php if ( ! empty( $overall['plain_language_summary'] ) ) : ?>
						<p class="bmf-bv-report-lead"><?php echo esc_html( $overall['plain_language_summary'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $report['overall_biovoiceprint_summary'] ) ) : ?>
						<p><?php echo esc_html( $report['overall_biovoiceprint_summary'] ); ?></p>
					<?php endif; ?>
				</div>
			</section>

			<?php if ( ! empty( $report['vocal_readiness_and_performance'] ) || ! empty( $report['performance_readiness_summary'] ) ) : ?>
				<section class="bmf-bv-report-section">
					<h3 class="bmf-bv-report-h">Vocal readiness</h3>
					<?php if ( ! empty( $report['vocal_readiness_and_performance'] ) ) : ?>
						<p><?php echo esc_html( $report['vocal_readiness_and_performance'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $report['performance_readiness_summary'] ) ) : ?>
						<p class="bmf-bv-report-emphasis"><?php echo esc_html( $report['performance_readiness_summary'] ); ?></p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php if ( $findings ) : ?>
				<section class="bmf-bv-report-section">
					<h3 class="bmf-bv-report-h">Key findings</h3>
					<?php if ( ! empty( $findings['strongest_pattern_area'] ) ) : ?>
						<p><?php echo esc_html( $findings['strongest_pattern_area'] ); ?></p>
					<?php endif; ?>
					<ul class="bmf-bv-report-chips" aria-label="Marker shift counts">
						<?php
						$chip_map = [
							'baseline_consistent_markers' => 'Baseline consistent',
							'minimal_shift_markers' => 'Minimal shift',
							'mild_shift_markers' => 'Mild shift',
							'moderate_shift_markers' => 'Moderate shift',
							'significant_shift_markers' => 'Significant shift',
							'extreme_shift_markers' => 'Extreme shift',
							'outside_personal_range_markers' => 'Outside personal range',
						];
						foreach ( $chip_map as $key => $label ) :
							if ( ! isset( $findings[ $key ] ) ) { continue; }
							$n = (int) $findings[ $key ];
							?>
							<li class="bmf-bv-report-chip"><span class="bmf-bv-report-chip-n"><?php echo $n; ?></span> <?php echo esc_html( $label ); ?></li>
						<?php endforeach; ?>
					</ul>
					<?php if ( ! empty( $findings['most_shifted_markers'] ) && is_array( $findings['most_shifted_markers'] ) ) : ?>
						<div class="bmf-bv-report-markers">
							<?php foreach ( array_slice( $findings['most_shifted_markers'], 0, 5 ) as $m ) :
								if ( ! is_array( $m ) ) { continue; }
								$m_color = BMF_BioVoice_Results_Service::color_class( (string) ( $m['color'] ?? '' ) );
								?>
								<div class="bmf-bv-report-marker <?php echo esc_attr( $m_color ); ?>">
									<div class="bmf-bv-report-marker-top">
										<span class="bmf-bv-report-marker-name"><?php echo esc_html( $m['display_name'] ?? $m['feature_name'] ?? 'Marker' ); ?></span>
										<?php if ( isset( $m['score_0_100'] ) ) : ?>
											<span class="bmf-bv-report-marker-score"><?php echo esc_html( number_format_i18n( (float) $m['score_0_100'], 1 ) ); ?></span>
										<?php endif; ?>
									</div>
									<?php if ( ! empty( $m['band'] ) ) : ?>
										<div class="bmf-bv-report-marker-band"><?php echo esc_html( $m['band'] ); ?><?php echo ! empty( $m['direction'] ) ? ' · ' . esc_html( $m['direction'] ) : ''; ?></div>
									<?php endif; ?>
									<?php if ( ! empty( $m['common_language_meaning'] ) ) : ?>
										<p class="bmf-bv-report-marker-meaning"><?php echo esc_html( $m['common_language_meaning'] ); ?></p>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php if ( $scores ) : ?>
				<section class="bmf-bv-report-section">
					<h3 class="bmf-bv-report-h">Score snapshot</h3>
					<?php if ( ! empty( $scores['plain_language_interpretation'] ) ) : ?>
						<p><?php echo esc_html( $scores['plain_language_interpretation'] ); ?></p>
					<?php endif; ?>
					<ul class="bmf-bv-report-score-list">
						<?php
						$score_keys = [
							'regulation_stability' => 'Regulation stability',
							'adaptive_capacity' => 'Adaptive capacity',
							'recovery_consistency' => 'Recovery consistency',
							'voice_pattern_stability' => 'Voice pattern stability',
							'baseline_alignment' => 'Baseline alignment',
						];
						foreach ( $score_keys as $sk => $slabel ) :
							if ( empty( $scores[ $sk ] ) || ! is_array( $scores[ $sk ] ) ) { continue; }
							$s = $scores[ $sk ];
							?>
							<li>
								<span class="bmf-bv-report-score-label"><?php echo esc_html( $slabel ); ?></span>
								<span class="bmf-bv-report-score-val">
									<?php if ( isset( $s['score'] ) ) : ?><strong><?php echo esc_html( number_format_i18n( (float) $s['score'], 1 ) ); ?></strong><?php endif; ?>
									<?php if ( ! empty( $s['label'] ) ) : ?><span class="bmf-bv-report-score-tag"><?php echo esc_html( $s['label'] ); ?></span><?php endif; ?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( ! empty( $scores['trend_direction'] ) ) : ?>
						<p class="bmf-bv-report-muted">Trend: <?php echo esc_html( $scores['trend_direction'] ); ?></p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php if ( $tips ) : ?>
				<section class="bmf-bv-report-section">
					<h3 class="bmf-bv-report-h">Before you speak or present</h3>
					<ul class="bmf-bv-report-tips">
						<?php foreach ( $tips as $tip ) :
							if ( ! is_array( $tip ) ) { continue; }
							?>
							<li>
								<strong><?php echo esc_html( $tip['title'] ?? '' ); ?></strong>
								<?php if ( ! empty( $tip['explanation'] ) ) : ?><span><?php echo esc_html( $tip['explanation'] ); ?></span><?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( $preventive || $watch ) : ?>
				<section class="bmf-bv-report-section">
					<h3 class="bmf-bv-report-h">What to watch over time</h3>
					<?php if ( ! empty( $preventive['summary'] ) ) : ?><p><?php echo esc_html( $preventive['summary'] ); ?></p><?php endif; ?>
					<?php if ( ! empty( $preventive['context'] ) ) : ?><p class="bmf-bv-report-muted"><?php echo esc_html( $preventive['context'] ); ?></p><?php endif; ?>
					<?php if ( ! empty( $watch['summary'] ) ) : ?><p><?php echo esc_html( $watch['summary'] ); ?></p><?php endif; ?>
					<?php if ( ! empty( $watch['tracking_recommendations'] ) && is_array( $watch['tracking_recommendations'] ) ) : ?>
						<ul class="bmf-bv-report-bullets">
							<?php foreach ( $watch['tracking_recommendations'] as $rec ) : ?>
								<li><?php echo esc_html( (string) $rec ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $report['safe_disclaimer'] ) ) : ?>
				<footer class="bmf-bv-report-disclaimer"><?php echo esc_html( $report['safe_disclaimer'] ); ?></footer>
			<?php endif; ?>
		</article>
		<?php
		return ob_get_clean();
	}
}
