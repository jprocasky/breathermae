<?php
/**
 * Breathermae Wellness Index – member-home chip shortcode
 *
 * [bmf_wellness_index]
 *
 * 0–100 synthesis of latest finalized RSI, Pillars (prefer full series),
 * and Key Essentials overall, with a light BMI modifier from biodata.
 * Circular gauge + status word, vertically stacked for a square chip.
 * When there is not enough data, the title stays and the middle falls back
 * to the linked image from Member Home settings (spot_image / spot_link).
 *
 * Drop into: breathermae-forms/includes/bmf-wellness-index-shortcodes.php
 * Then require from the main plugin file (see bottom of this file).
 *
 * Sources:
 *   RSI     – {$prefix}bm_rsi_results (master_score preferred, is_final=1)
 *   Pillars – {$prefix}bm_pillars_results (master_score, prefer series=full)
 *   Keys    – uls_key_essentials.average_score (mean of available, 1–5)
 *   Bio     – uls_ULS_CF_BIO (height/weight for BMI light modifier)
 *
 * Weights (starting): Keys 35% / Pillars 35% / RSI 30%
 * Require ≥ 3 domains before showing a score.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Wellness_Index_Shortcodes {

	public static function init() {
		add_shortcode( 'bmf_wellness_index', [ __CLASS__, 'render' ] );
	}

	/**
	 * [bmf_wellness_index user_id="" email="" size="sm" class=""]
	 * size: sm (chip) | md
	 */
	public static function shortcode( $atts = [] ) {
		return self::render( $atts );
	}

	public static function render( $atts ) {
		if ( self::should_bail_for_editor() ) {
			return '';
		}

		$atts = shortcode_atts(
			[
				'user_id' => 0,
				'email'   => '',
				'size'    => 'sm',
				'class'   => '',
			],
			$atts,
			'bmf_wellness_index'
		);

		$email = self::resolve_email( $atts['user_id'], $atts['email'] );
		if ( $email === '' ) {
			return self::render_empty( 'Log in to see your Wellness Index.', $atts );
		}

		$result = self::compute( $email );

		self::print_styles();

		if ( empty( $result['ready'] ) ) {
			return self::render_empty( $result['message'] ?? 'Complete more assessments to see your Wellness Index.', $atts, $result );
		}

		$score  = (int) $result['score'];
		$status = (string) $result['status'];
		$zone   = (string) $result['zone'];
		$size   = in_array( $atts['size'], [ 'sm', 'md' ], true ) ? $atts['size'] : 'sm';
		$extra  = sanitize_html_class( $atts['class'] );

		ob_start();
		?>
		<div class="bmf-wi <?php echo esc_attr( $size ); ?> is-<?php echo esc_attr( $zone ); ?> <?php echo esc_attr( $extra ); ?>"
			data-bmf-theme="dark"
			title="Wellness Index based on your latest assessments">
			<div class="bmf-wi-label">Wellness Index</div>
			<div class="bmf-wi-gauge" style="--wi-pct: <?php echo esc_attr( $score ); ?>;">
				<svg viewBox="0 0 36 36" class="bmf-wi-svg" aria-hidden="true">
					<path class="bmf-wi-track"
						d="M18 2.0845
						   a 15.9155 15.9155 0 0 1 0 31.831
						   a 15.9155 15.9155 0 0 1 0 -31.831" />
					<path class="bmf-wi-fill"
						stroke-dasharray="<?php echo esc_attr( $score ); ?>, 100"
						d="M18 2.0845
						   a 15.9155 15.9155 0 0 1 0 31.831
						   a 15.9155 15.9155 0 0 1 0 -31.831" />
				</svg>
				<div class="bmf-wi-value"><?php echo esc_html( $score ); ?></div>
			</div>
			<div class="bmf-wi-status"><?php echo esc_html( $status ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_empty( $message, $atts, $result = [] ) {
		self::print_styles();
		$size  = in_array( $atts['size'] ?? 'sm', [ 'sm', 'md' ], true ) ? $atts['size'] : 'sm';
		$extra = sanitize_html_class( $atts['class'] ?? '' );

		$spot = self::member_home_spot();
		$has_image = ! empty( $spot['image'] );

		ob_start();
		?>
		<div class="bmf-wi bmf-wi-empty <?php echo esc_attr( $size ); ?> <?php echo esc_attr( $extra ); ?>">
			<div class="bmf-wi-label">Wellness Index</div>
			<?php if ( $has_image ) : ?>
				<div class="bmf-wi-fallback">
					<?php if ( ! empty( $spot['link'] ) ) : ?>
						<a href="<?php echo esc_url( $spot['link'] ); ?>">
							<img src="<?php echo esc_url( $spot['image'] ); ?>" alt="" />
						</a>
					<?php else : ?>
						<img src="<?php echo esc_url( $spot['image'] ); ?>" alt="" />
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div class="bmf-wi-empty-icon" aria-hidden="true">—</div>
				<div class="bmf-wi-empty-msg"><?php echo esc_html( $message ); ?></div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Linked image from Member Home settings (spot_image / spot_link).
	 * Used as the middle of the chip when the index cannot be computed.
	 *
	 * @return array{image:string,link:string}
	 */
	private static function member_home_spot(): array {
		$out = [ 'image' => '', 'link' => '' ];
		if ( ! class_exists( 'BMF_Member_Home_Settings' ) || ! method_exists( 'BMF_Member_Home_Settings', 'get' ) ) {
			return $out;
		}
		$settings = BMF_Member_Home_Settings::get();
		$out['image'] = isset( $settings['spot_image'] ) ? (string) $settings['spot_image'] : '';
		$out['link']  = isset( $settings['spot_link'] ) ? (string) $settings['spot_link'] : '';
		return $out;
	}

	/**
	 * Compute the index.
	 *
	 * @return array{
	 *   ready:bool,
	 *   score?:int,
	 *   status?:string,
	 *   zone?:string,
	 *   message?:string,
	 *   missing?:string[],
	 *   sources?:array
	 * }
	 */
	public static function compute( $email ) {
		$email = trim( (string) $email );
		if ( $email === '' ) {
			return [ 'ready' => false, 'message' => 'No user email.' ];
		}

		$rsi     = self::get_rsi_score( $email );
		$pillars = self::get_pillars_score( $email );
		$keys    = self::get_keys_score( $email );
		$bmi     = self::get_bmi( $email );

		$domains = [
			'rsi'     => $rsi,
			'pillars' => $pillars,
			'keys'    => $keys,
		];

		$available = [];
		$missing   = [];
		foreach ( $domains as $key => $val ) {
			if ( $val !== null ) {
				$available[ $key ] = $val;
			} else {
				$missing[] = ucfirst( $key );
			}
		}

		if ( count( $available ) < 3 ) {
			return [
				'ready'   => false,
				'message' => 'Complete more assessments to see your Wellness Index.',
				'missing' => $missing,
				'sources' => $domains,
			];
		}

		// Starting weights (only over available, but we require all 3).
		$weights = [
			'keys'    => 0.35,
			'pillars' => 0.35,
			'rsi'     => 0.30,
		];

		$weighted = 0.0;
		$w_sum    = 0.0;
		foreach ( $available as $key => $score ) {
			$w = $weights[ $key ] ?? 0;
			$weighted += $score * $w;
			$w_sum    += $w;
		}
		$base = $w_sum > 0 ? $weighted / $w_sum : 0;

		// Light BMI modifier (optional; never required).
		$mod = self::bmi_modifier( $bmi );
		$final = max( 0, min( 100, round( $base + $mod ) ) );

		$band = self::band_for_score( $final );

		return [
			'ready'   => true,
			'score'   => (int) $final,
			'status'  => $band['label'],
			'zone'    => $band['key'],
			'sources' => $domains,
			'bmi'     => $bmi,
			'mod'     => $mod,
		];
	}

	/* ---------------------------------------------------------------------
	 * Data fetchers
	 * ------------------------------------------------------------------ */

	private static function get_rsi_score( $email ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bm_rsi_results';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT master_score, readiness_score, R11, R12, results_date
				 FROM {$table}
				 WHERE user_email = %s AND is_final = 1
				 ORDER BY results_date DESC, id DESC
				 LIMIT 1",
				$email
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		if ( isset( $row['master_score'] ) && $row['master_score'] !== '' && is_numeric( $row['master_score'] ) ) {
			$v = (float) $row['master_score'];
			return $v <= 1.0 ? round( $v * 100, 1 ) : round( $v, 1 );
		}
		if ( isset( $row['readiness_score'] ) && $row['readiness_score'] !== '' && is_numeric( $row['readiness_score'] ) ) {
			return max( 0, min( 100, (float) $row['readiness_score'] ) );
		}
		// Fallback average of R11/R12 if present.
		$parts = [];
		foreach ( [ 'R11', 'R12' ] as $col ) {
			if ( isset( $row[ $col ] ) && $row[ $col ] !== '' && is_numeric( $row[ $col ] ) ) {
				$v = (float) $row[ $col ];
				$parts[] = $v <= 1.0 ? $v * 100 : $v;
			}
		}
		return $parts ? round( array_sum( $parts ) / count( $parts ), 1 ) : null;
	}

	private static function get_pillars_score( $email ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bm_pillars_results';

		// Prefer full series.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT master_score, series, results_date
				 FROM {$table}
				 WHERE user_email = %s AND is_final = 1 AND series = 'full'
				 ORDER BY results_date DESC, id DESC
				 LIMIT 1",
				$email
			),
			ARRAY_A
		);
		if ( ! $row ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT master_score, series, results_date
					 FROM {$table}
					 WHERE user_email = %s AND is_final = 1 AND series = 'rapid'
					 ORDER BY results_date DESC, id DESC
					 LIMIT 1",
					$email
				),
				ARRAY_A
			);
		}
		if ( ! $row || ! isset( $row['master_score'] ) || $row['master_score'] === '' || ! is_numeric( $row['master_score'] ) ) {
			return null;
		}
		$v = (float) $row['master_score'];
		return $v <= 1.0 ? round( $v * 100, 1 ) : round( $v, 1 );
	}

	private static function get_keys_score( $email ) {
		// Prefer existing service if loaded.
		if ( class_exists( 'BMF_Key_Essentials_Service' ) && method_exists( 'BMF_Key_Essentials_Service', 'overall_as_of' ) ) {
			$overall = BMF_Key_Essentials_Service::overall_as_of( $email, '' );
			if ( ! empty( $overall['average'] ) && $overall['average'] !== null ) {
				$avg = (float) $overall['average'];
				return round( ( ( $avg - 1 ) / 4 ) * 100, 1 );
			}
		}

		global $wpdb;
		$table = 'uls_key_essentials';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT form_id, average_score
				 FROM {$table}
				 WHERE user_email = %s
				 ORDER BY datetime DESC, id DESC",
				$email
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return null;
		}

		$seen = [];
		$vals = [];
		foreach ( $rows as $row ) {
			$fid = (string) ( $row['form_id'] ?? '' );
			if ( $fid === '' || isset( $seen[ $fid ] ) ) {
				continue;
			}
			$seen[ $fid ] = true;
			if ( isset( $row['average_score'] ) && is_numeric( $row['average_score'] ) ) {
				$vals[] = (float) $row['average_score'];
			}
		}
		if ( ! $vals ) {
			return null;
		}
		$avg = array_sum( $vals ) / count( $vals );
		return round( ( ( $avg - 1 ) / 4 ) * 100, 1 );
	}

	private static function get_bmi( $email ) {
		global $wpdb;
		$table = 'uls_ULS_CF_BIO';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT f_heightft, f_heightin, f_weight
				 FROM {$table}
				 WHERE email = %s
				 ORDER BY updated_at DESC, id DESC
				 LIMIT 1",
				$email
			),
			ARRAY_A
		);
		if ( ! $row ) {
			// Try user_id path if email column differs.
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT f_heightft, f_heightin, f_weight
						 FROM {$table}
						 WHERE user_id = %d
						 ORDER BY updated_at DESC, id DESC
						 LIMIT 1",
						$user->ID
					),
					ARRAY_A
				);
			}
		}
		if ( ! $row ) {
			return null;
		}

		$ft = isset( $row['f_heightft'] ) ? (int) $row['f_heightft'] : 0;
		$in = isset( $row['f_heightin'] ) ? (int) $row['f_heightin'] : 0;
		$lb = isset( $row['f_weight'] ) ? (float) $row['f_weight'] : 0;

		$total_in = ( $ft * 12 ) + $in;
		if ( $total_in <= 0 || $lb <= 0 ) {
			return null;
		}
		return round( ( $lb / ( $total_in * $total_in ) ) * 703, 1 );
	}

	private static function bmi_modifier( $bmi ) {
		if ( $bmi === null ) {
			return 0;
		}
		if ( $bmi >= 18.5 && $bmi <= 25.0 ) {
			return 2;
		}
		if ( $bmi >= 25.1 && $bmi <= 30.0 ) {
			return -1;
		}
		if ( $bmi > 30.0 || $bmi < 18.5 ) {
			return -3;
		}
		return 0;
	}

	private static function band_for_score( $score ) {
		$score = (int) $score;
		if ( $score >= 75 ) {
			return [ 'key' => 'strong', 'label' => 'Strong' ];
		}
		if ( $score >= 50 ) {
			return [ 'key' => 'building', 'label' => 'Building' ];
		}
		if ( $score >= 25 ) {
			return [ 'key' => 'opportunity', 'label' => 'Opportunity' ];
		}
		return [ 'key' => 'focus', 'label' => 'Focus' ];
	}

	private static function resolve_email( $user_id = 0, $email = '' ) {
		$email = trim( (string) $email );
		if ( $email !== '' && is_email( $email ) ) {
			return $email;
		}
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}
		// ULS selected-member override when present.
		if ( function_exists( 'uls_get_selected_member_email' ) ) {
			$sel = uls_get_selected_member_email();
			if ( $sel && is_email( $sel ) ) {
				return $sel;
			}
		}
		$user = get_userdata( $user_id );
		return ( $user && ! empty( $user->user_email ) ) ? $user->user_email : '';
	}

	private static function should_bail_for_editor() {
		if ( function_exists( 'bmf_in_elementor_editor' ) && bmf_in_elementor_editor() ) {
			return true;
		}
		return false;
	}

	private static function print_styles() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style id="bmf-wi-styles">
			.bmf-wi {
				display: flex;
				flex-direction: column;
				align-items: center;
				justify-content: center;
				gap: 0.45rem;
				width: 100%;
				height: 100%;
				min-height: 140px;
				text-align: center;
				font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
				color: #e2e8f0;
				line-height: 1.2;
				box-sizing: border-box;
				padding: 0.5rem;
			}
			.bmf-wi *, .bmf-wi *::before, .bmf-wi *::after { box-sizing: border-box; }
			.bmf-wi.sm .bmf-wi-gauge { width: 72px; height: 72px; }
			.bmf-wi.md .bmf-wi-gauge { width: 96px; height: 96px; }
			.bmf-wi-gauge {
				position: relative;
				flex-shrink: 0;
			}
			.bmf-wi-svg {
				width: 100%;
				height: 100%;
				transform: rotate(-90deg);
			}
			.bmf-wi-track {
				fill: none;
				stroke: rgba(30, 42, 68, 0.9);
				stroke-width: 3.2;
			}
			.bmf-wi-fill {
				fill: none;
				stroke: #60a5fa;
				stroke-width: 3.2;
				stroke-linecap: round;
				transition: stroke-dasharray 0.4s ease;
			}
			.bmf-wi.is-strong .bmf-wi-fill     { stroke: #34d399; }
			.bmf-wi.is-building .bmf-wi-fill   { stroke: #22d3ee; }
			.bmf-wi.is-opportunity .bmf-wi-fill{ stroke: #fbbf24; }
			.bmf-wi.is-focus .bmf-wi-fill      { stroke: #f59e0b; }
			.bmf-wi-value {
				position: absolute;
				inset: 0;
				display: flex;
				align-items: center;
				justify-content: center;
				font-size: 1.15rem;
				font-weight: 700;
				font-variant-numeric: tabular-nums;
				color: #f8fafc;
			}
			.bmf-wi.sm .bmf-wi-value { font-size: 1.05rem; }
			.bmf-wi-label {
				font-size: 0.68rem;
				font-weight: 650;
				letter-spacing: 0.06em;
				text-transform: uppercase;
				color: #94a3b8;
				order: -1;
			}
			.bmf-wi-status {
				font-size: 0.92rem;
				font-weight: 650;
			}
			.bmf-wi.is-strong .bmf-wi-status      { color: #6ee7b7; }
			.bmf-wi.is-building .bmf-wi-status    { color: #67e8f9; }
			.bmf-wi.is-opportunity .bmf-wi-status { color: #fde68a; }
			.bmf-wi.is-focus .bmf-wi-status       { color: #fcd34d; }
			.bmf-wi-empty {
				opacity: 0.95;
			}
			.bmf-wi-fallback {
				width: 72px;
				height: 72px;
				flex-shrink: 0;
			}
			.bmf-wi-fallback img,
			.bmf-wi-fallback a {
				display: block;
				width: 100%;
				height: 100%;
				object-fit: contain;
				border-radius: 8px;
			}
			.bmf-wi-empty-icon {
				width: 72px;
				height: 72px;
				border-radius: 50%;
				border: 2px dashed #334155;
				display: flex;
				align-items: center;
				justify-content: center;
				color: #64748b;
				font-size: 1.1rem;
				flex-shrink: 0;
			}
			.bmf-wi-empty-msg {
				font-size: 0.72rem;
				color: #94a3b8;
				max-width: 140px;
				margin-top: 0.15rem;
			}
			/* Light theme support when parent has data-bmf-theme="light" */
			[data-bmf-theme="light"] .bmf-wi,
			.bmf-wi[data-bmf-theme="light"] {
				color: #122033;
			}
			[data-bmf-theme="light"] .bmf-wi-track,
			.bmf-wi[data-bmf-theme="light"] .bmf-wi-track {
				stroke: #d5deeb;
			}
			[data-bmf-theme="light"] .bmf-wi-value,
			.bmf-wi[data-bmf-theme="light"] .bmf-wi-value {
				color: #122033;
			}
			[data-bmf-theme="light"] .bmf-wi-label,
			.bmf-wi[data-bmf-theme="light"] .bmf-wi-label {
				color: #5b6b82;
			}
		</style>
		<?php
	}
}

add_action( 'init', [ 'BMF_Wellness_Index_Shortcodes', 'init' ] );

/*
 * In breathermae-forms.php (inside the constructor, near the other shortcode requires):
 *
 * if ( file_exists( __DIR__ . '/includes/bmf-wellness-index-shortcodes.php' ) ) {
 *     require_once __DIR__ . '/includes/bmf-wellness-index-shortcodes.php';
 * }
 */
