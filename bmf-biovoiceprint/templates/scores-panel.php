<?php
/**
 * Scores dashboard markup. Variables set by BMF_BioVoice_Shortcodes_Scores::shortcode_scores().
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<article class="<?php echo esc_attr( $class ); ?>" data-bmf-biovoice-scores data-source="<?php echo esc_attr( $meta['source'] ); ?>">
	<header class="bmf-bv-scores-head">
		<div class="bmf-bv-scores-kicker">BioVoicePrint</div>
		<h2 class="bmf-bv-scores-title">Score dashboard</h2>
		<?php if ( ! empty( $meta['is_fixture'] ) ) : ?>
			<div class="bmf-bv-scores-fixture-badge">Sample preview</div>
		<?php elseif ( ! empty( $meta['analyzed_at'] ) ) : ?>
			<div class="bmf-bv-scores-date"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $meta['analyzed_at'] ) ); ?></div>
		<?php endif; ?>
	</header>

	<section class="bmf-bv-scores-hero <?php echo esc_attr( $rdi_color ); ?>">
		<div class="bmf-bv-scores-rdi">
			<?php if ( $rdi_score !== null ) : ?>
				<div class="bmf-bv-scores-rdi-value"><?php echo esc_html( number_format_i18n( $rdi_score, 1 ) ); ?><span class="bmf-bv-scores-rdi-max">/100</span></div>
			<?php endif; ?>
			<?php if ( $rdi_band ) : ?>
				<div class="bmf-bv-scores-rdi-band"><?php echo esc_html( $rdi_band ); ?></div>
			<?php endif; ?>
			<?php if ( ! empty( $rdi['direction'] ) ) : ?>
				<div class="bmf-bv-scores-rdi-note"><?php echo esc_html( $rdi['direction'] ); ?></div>
			<?php endif; ?>
		</div>
		<div class="bmf-bv-scores-hero-text">
			<?php if ( ! empty( $customer['plain_language_interpretation'] ) ) : ?>
				<p class="bmf-bv-scores-lead"><?php echo esc_html( $customer['plain_language_interpretation'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $customer['trend_direction'] ) ) : ?>
				<p class="bmf-bv-scores-muted">Trend: <?php echo esc_html( $customer['trend_direction'] ); ?></p>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( $has_customer ) : ?>
		<section class="bmf-bv-scores-section">
			<h3 class="bmf-bv-scores-h">Member scores</h3>
			<p class="bmf-bv-scores-muted bmf-bv-scores-hint">Higher scores indicate closer alignment to your personal baseline.</p>
			<ul class="bmf-bv-scores-bars">
				<?php foreach ( $customer_score_keys as $ck => $clabel ) :
					if ( empty( $customer[ $ck ] ) || ! is_array( $customer[ $ck ] ) ) {
						continue;
					}
					$s     = $customer[ $ck ];
					$score = isset( $s['score'] ) ? (float) $s['score'] : 0;
					$pct   = max( 0, min( 100, $score ) );
					$label = isset( $s['label'] ) ? (string) $s['label'] : '';
					$tag   = strtolower( $label ) === 'watch' ? 'is-watch' : 'is-stable';
					?>
					<li class="bmf-bv-scores-bar-row">
						<div class="bmf-bv-scores-bar-meta">
							<span class="bmf-bv-scores-bar-label"><?php echo esc_html( $clabel ); ?></span>
							<span class="bmf-bv-scores-bar-val">
								<strong><?php echo esc_html( number_format_i18n( $score, 1 ) ); ?></strong>
								<?php if ( $label ) : ?>
									<span class="bmf-bv-scores-tag <?php echo esc_attr( $tag ); ?>"><?php echo esc_html( $label ); ?></span>
								<?php endif; ?>
							</span>
						</div>
						<div class="bmf-bv-scores-bar-track" aria-hidden="true">
							<span class="bmf-bv-scores-bar-fill <?php echo esc_attr( $tag ); ?>" style="width:<?php echo esc_attr( (string) round( $pct, 1 ) ); ?>%;"></span>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<?php if ( $radar_labels ) : ?>
		<section class="bmf-bv-scores-section">
			<h3 class="bmf-bv-scores-h">Pattern framework</h3>
			<p class="bmf-bv-scores-muted bmf-bv-scores-hint">Higher values indicate greater shift from your personal baseline on that dimension.</p>
			<div class="bmf-bv-scores-radar-wrap">
				<canvas id="<?php echo esc_attr( $chart_id ); ?>" class="bmf-bv-scores-radar" width="320" height="280" aria-label="BioVoicePrint framework radar chart"></canvas>
			</div>
			<ul class="bmf-bv-scores-framework-list">
				<?php foreach ( $framework_keys as $fk => $flabel ) :
					if ( empty( $stage5[ $fk ] ) || ! is_array( $stage5[ $fk ] ) ) {
						continue;
					}
					$f      = $stage5[ $fk ];
					$fscore = isset( $f['score'] ) ? (float) $f['score'] : 0;
					$fband  = isset( $f['band'] ) ? (string) $f['band'] : '';
					$fcolor = BMF_BioVoice_Results_Service::color_class( (string) ( $f['color'] ?? '' ) );
					?>
					<li class="bmf-bv-scores-framework-item <?php echo esc_attr( $fcolor ); ?>">
						<span class="bmf-bv-scores-framework-name"><?php echo esc_html( $flabel ); ?></span>
						<span class="bmf-bv-scores-framework-val">
							<strong><?php echo esc_html( number_format_i18n( $fscore, 1 ) ); ?></strong>
							<?php if ( $fband ) : ?>
								<span class="bmf-bv-scores-framework-band"><?php echo esc_html( $fband ); ?></span>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<footer class="bmf-bv-scores-disclaimer">
		Scores describe baseline-relative voice-pattern shifts only. They do not diagnose medical or mental health conditions and should be interpreted alongside other wellness inputs.
	</footer>
</article>
