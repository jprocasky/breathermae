<?php
/**
 * Breathermae Key Essentials – Results Shortcode
 *
 * [bmf_key_essentials] — dark results card with the latest score for each of the
 * seven Key Essentials assessments (from uls_key_essentials).
 *
 * Reads the same table written by the Elementor hook and the BMF bridge in
 * uls-custom. Latest row per form_id for the current user (by email).
 *
 * Attrs:
 *   user_id  – optional WP user ID (default: current user)
 *   email    – optional override email (admin / tooling)
 *   class    – extra CSS class on the root card
 *
 * Drop into: breathermae-forms/includes/bmf-key-essentials-shortcodes.php
 * Then require from the main plugin file:
 *
 *   if ( file_exists( __DIR__ . '/includes/bmf-key-essentials-shortcodes.php' ) ) {
 *       require_once __DIR__ . '/includes/bmf-key-essentials-shortcodes.php';
 *   }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for Key Essentials aggregate scores.
 */
class BMF_Key_Essentials_Service {

	/** Canonical form_id → display label (order = display order). */
	public static function form_catalog() {
		return [
			'key_fluid_form'    => 'Fluid & Hydration',
			'key_food_form'     => 'Food & Nutrition',
			'key_breath_form'   => 'Breath & Environment',
			'key_movement_form' => 'Movement',
			'key_mind_form'     => 'Mind Balance',
			'key_sleep_form'    => 'Sleep & Recovery',
			'key_nature_form'   => 'Nature & Connection',
		];
	}

	/**
	 * Latest row per form_id for a user email.
	 *
	 * @param string $email
	 * @return array form_id => [ 'average_score'=>float, 'total_score'=>float, 'datetime'=>string, 'id'=>int ]
	 */
	public static function get_latest_by_form( $email ) {
		global $wpdb;

		$email = trim( (string) $email );
		if ( $email === '' ) {
			return [];
		}

		// Table name matches the legacy insert path in uls-custom (no $wpdb->prefix).
		$table = 'uls_key_essentials';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, form_id, datetime, total_score, average_score
				 FROM {$table}
				 WHERE user_email = %s
				 ORDER BY datetime DESC, id DESC",
				$email
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		$latest = [];
		foreach ( $rows as $row ) {
			$fid = (string) ( $row['form_id'] ?? '' );
			if ( $fid === '' || isset( $latest[ $fid ] ) ) {
				continue; // already have a newer row for this form
			}
			$latest[ $fid ] = [
				'id'            => (int) $row['id'],
				'average_score' => isset( $row['average_score'] ) ? (float) $row['average_score'] : null,
				'total_score'   => isset( $row['total_score'] ) ? (float) $row['total_score'] : null,
				'datetime'      => (string) ( $row['datetime'] ?? '' ),
			];
		}

		return $latest;
	}

	/**
	 * Resolve email from user_id or explicit email attr.
	 */
	public static function resolve_email( $user_id = 0, $email = '' ) {
		$email = trim( (string) $email );
		if ( $email !== '' && is_email( $email ) ) {
			return $email;
		}
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return '';
		}
		return $user->user_email;
	}

	/**
	 * Band metadata for a 1–5 average score.
	 *
	 * @return array{key:string,label:string,class:string}
	 */
	public static function band_for_score( $score ) {
		$score = (float) $score;
		if ( $score >= 4.5 ) {
			return [ 'key' => 'excellent', 'label' => 'Excellent', 'class' => 'is-excellent' ];
		}
		if ( $score >= 3.5 ) {
			return [ 'key' => 'strong', 'label' => 'Strong', 'class' => 'is-strong' ];
		}
		if ( $score >= 2.5 ) {
			return [ 'key' => 'building', 'label' => 'Building', 'class' => 'is-building' ];
		}
		return [ 'key' => 'focus', 'label' => 'Needs focus', 'class' => 'is-focus' ];
	}

	/**
	 * Overall band copy for the hero.
	 */
	public static function overall_band_label( $score ) {
		$score = (float) $score;
		if ( $score >= 4.5 ) {
			return 'Excellent foundation';
		}
		if ( $score >= 3.5 ) {
			return 'Strong foundation';
		}
		if ( $score >= 2.5 ) {
			return 'Building foundation';
		}
		return 'Needs attention';
	}
}

/**
 * Shortcodes.
 */
class BMF_Key_Essentials_Shortcodes {

	public static function init() {
		add_shortcode( 'bmf_key_essentials', [ __CLASS__, 'render' ] );
	}

	/**
	 * [bmf_key_essentials user_id="" email="" class=""]
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			[
				'user_id' => 0,
				'email'   => '',
				'class'   => '',
			],
			$atts,
			'bmf_key_essentials'
		);

		if ( ! is_user_logged_in() && empty( $atts['email'] ) && empty( $atts['user_id'] ) ) {
			return '<p class="bmf-ke-login">Please log in to view your Key Essentials results.</p>';
		}

		$email = BMF_Key_Essentials_Service::resolve_email( $atts['user_id'], $atts['email'] );
		if ( $email === '' ) {
			return '<p class="bmf-ke-empty">Unable to resolve user for Key Essentials results.</p>';
		}

		$catalog = BMF_Key_Essentials_Service::form_catalog();
		$latest  = BMF_Key_Essentials_Service::get_latest_by_form( $email );

		$items        = [];
		$sum          = 0.0;
		$n            = 0;
		$latest_ts    = 0;
		$latest_mysql = '';

		foreach ( $catalog as $form_id => $label ) {
			$row   = $latest[ $form_id ] ?? null;
			$score = ( $row && $row['average_score'] !== null ) ? (float) $row['average_score'] : null;
			$band  = ( $score !== null ) ? BMF_Key_Essentials_Service::band_for_score( $score ) : null;

			if ( $score !== null ) {
				$sum += $score;
				$n++;
				if ( ! empty( $row['datetime'] ) ) {
					$ts = strtotime( $row['datetime'] );
					if ( $ts && $ts > $latest_ts ) {
						$latest_ts    = $ts;
						$latest_mysql = $row['datetime'];
					}
				}
			}

			$items[] = [
				'form_id' => $form_id,
				'label'   => $label,
				'score'   => $score,
				'band'    => $band,
				'datetime'=> $row['datetime'] ?? '',
			];
		}

		$complete = $n;
		$total    = count( $catalog );
		$overall  = $n > 0 ? round( $sum / $n, 1 ) : null;
		$ov_band  = ( $overall !== null ) ? BMF_Key_Essentials_Service::band_for_score( $overall ) : null;
		$ov_label = ( $overall !== null ) ? BMF_Key_Essentials_Service::overall_band_label( $overall ) : '';

		$date_display = '';
		if ( $latest_mysql ) {
			$date_display = mysql2date( get_option( 'date_format' ), $latest_mysql );
		}

		$root_class = 'bmf-ke-card';
		if ( ! empty( $atts['class'] ) ) {
			$root_class .= ' ' . sanitize_html_class( $atts['class'] );
		}
		if ( $ov_band ) {
			$root_class .= ' ' . $ov_band['class'];
		}

		// Scale max for bar width (scores are ~1–5).
		$scale_max = 5.0;

		ob_start();
		self::print_styles();
		?>
		<article class="<?php echo esc_attr( $root_class ); ?>" data-bmf-key-essentials>
			<header class="bmf-ke-head">
				<div class="bmf-ke-kicker">Key Essentials</div>
				<div class="bmf-ke-head-row">
					<h2 class="bmf-ke-title">Latest results</h2>
					<?php if ( $date_display ) : ?>
						<div class="bmf-ke-date"><?php echo esc_html( $date_display ); ?></div>
					<?php endif; ?>
				</div>
			</header>

			<?php if ( $n === 0 ) : ?>
				<p class="bmf-ke-empty-msg">No Key Essentials assessments completed yet.</p>
			<?php else : ?>

				<section class="bmf-ke-hero <?php echo $ov_band ? esc_attr( $ov_band['class'] ) : ''; ?>">
					<div class="bmf-ke-overall">
						<div class="bmf-ke-overall-value">
							<?php echo esc_html( number_format_i18n( $overall, 1 ) ); ?>
							<span class="bmf-ke-overall-max">/5</span>
						</div>
						<div class="bmf-ke-overall-band"><?php echo esc_html( $ov_label ); ?></div>
					</div>
					<div class="bmf-ke-complete">
						<span class="bmf-ke-complete-count"><?php echo (int) $complete; ?> of <?php echo (int) $total; ?></span>
						<span class="bmf-ke-complete-label">complete</span>
					</div>
				</section>

				<ul class="bmf-ke-bars">
					<?php foreach ( $items as $item ) :
						$has   = $item['score'] !== null;
						$score = $has ? (float) $item['score'] : 0;
						$pct   = $has ? max( 0, min( 100, ( $score / $scale_max ) * 100 ) ) : 0;
						$bclass = $has && $item['band'] ? $item['band']['class'] : 'is-missing';
						$blabel = $has && $item['band'] ? $item['band']['label'] : '—';
						?>
						<li class="bmf-ke-bar-row <?php echo esc_attr( $bclass ); ?>">
							<div class="bmf-ke-bar-meta">
								<span class="bmf-ke-bar-label"><?php echo esc_html( $item['label'] ); ?></span>
								<span class="bmf-ke-bar-val">
									<?php if ( $has ) : ?>
										<strong><?php echo esc_html( number_format_i18n( $score, 2 ) ); ?></strong>
										<span class="bmf-ke-tag"><?php echo esc_html( $blabel ); ?></span>
									<?php else : ?>
										<span class="bmf-ke-missing">Not completed</span>
									<?php endif; ?>
								</span>
							</div>
							<div class="bmf-ke-bar-track" aria-hidden="true">
								<span class="bmf-ke-bar-fill <?php echo esc_attr( $bclass ); ?>" style="width:<?php echo esc_attr( (string) round( $pct, 1 ) ); ?>%;"></span>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>

			<?php endif; ?>
		</article>
		<?php
		return ob_get_clean();
	}

	/**
	 * Inline styles once per request (matches BSI trend / compact card approach).
	 */
	private static function print_styles() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style id="bmf-ke-styles">
			.bmf-ke-card {
				--ke-bg: #0b1220;
				--ke-card: #121a2b;
				--ke-border: #1e2a44;
				--ke-text: #e2e8f0;
				--ke-muted: #94a3b8;
				--ke-accent: #38bdf8;
				--ke-excellent: #34d399;
				--ke-strong: #22d3ee;
				--ke-building: #60a5fa;
				--ke-focus: #fbbf24;
				max-width: 520px;
				width: 100%;
				margin: 0 auto;
				padding: 1.15rem 1.2rem 1.25rem;
				border-radius: 14px;
				background: var(--ke-bg);
				border: 1px solid var(--ke-border);
				color: var(--ke-text);
				font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
				box-sizing: border-box;
				line-height: 1.45;
			}
			.bmf-ke-card *, .bmf-ke-card *::before, .bmf-ke-card *::after { box-sizing: border-box; }
			.bmf-ke-kicker {
				font-size: 0.7rem;
				font-weight: 700;
				letter-spacing: 0.08em;
				text-transform: uppercase;
				color: var(--ke-accent);
				margin-bottom: 0.15rem;
			}
			.bmf-ke-head-row {
				display: flex;
				align-items: baseline;
				justify-content: space-between;
				gap: 0.75rem;
				flex-wrap: wrap;
			}
			.bmf-ke-title {
				margin: 0;
				font-size: 1.12rem;
				font-weight: 650;
				color: #f8fafc;
				line-height: 1.3;
			}
			.bmf-ke-date {
				font-size: 0.78rem;
				color: var(--ke-muted);
				white-space: nowrap;
			}
			.bmf-ke-empty-msg,
			.bmf-ke-login,
			.bmf-ke-empty {
				margin: 0.85rem 0 0;
				font-size: 0.9rem;
				color: var(--ke-muted);
			}
			.bmf-ke-hero {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 1rem;
				margin-top: 0.95rem;
				padding: 0.85rem 1rem;
				border-radius: 12px;
				background: var(--ke-card);
				border: 1px solid var(--ke-border);
			}
			.bmf-ke-hero.is-excellent { border-color: rgba(52, 211, 153, 0.45); }
			.bmf-ke-hero.is-strong    { border-color: rgba(34, 211, 238, 0.45); }
			.bmf-ke-hero.is-building  { border-color: rgba(96, 165, 250, 0.45); }
			.bmf-ke-hero.is-focus     { border-color: rgba(251, 191, 36, 0.5); }
			.bmf-ke-overall-value {
				font-size: 1.85rem;
				font-weight: 700;
				color: #f8fafc;
				font-variant-numeric: tabular-nums;
				line-height: 1.1;
			}
			.bmf-ke-overall-max {
				font-size: 0.9rem;
				font-weight: 500;
				color: var(--ke-muted);
				margin-left: 0.05rem;
			}
			.bmf-ke-overall-band {
				margin-top: 0.2rem;
				font-size: 0.82rem;
				font-weight: 600;
				color: #a5f3fc;
			}
			.bmf-ke-hero.is-excellent .bmf-ke-overall-band { color: #6ee7b7; }
			.bmf-ke-hero.is-strong    .bmf-ke-overall-band { color: #67e8f9; }
			.bmf-ke-hero.is-building  .bmf-ke-overall-band { color: #93c5fd; }
			.bmf-ke-hero.is-focus     .bmf-ke-overall-band { color: #fde68a; }
			.bmf-ke-complete {
				text-align: right;
				line-height: 1.25;
			}
			.bmf-ke-complete-count {
				display: block;
				font-size: 0.95rem;
				font-weight: 650;
				color: #f8fafc;
			}
			.bmf-ke-complete-label {
				font-size: 0.72rem;
				color: var(--ke-muted);
				text-transform: uppercase;
				letter-spacing: 0.04em;
			}
			.bmf-ke-bars {
				list-style: none;
				margin: 1rem 0 0;
				padding: 0;
				display: flex;
				flex-direction: column;
				gap: 0.65rem;
			}
			.bmf-ke-bar-meta {
				display: flex;
				align-items: baseline;
				justify-content: space-between;
				gap: 0.75rem;
				margin-bottom: 0.28rem;
			}
			.bmf-ke-bar-label {
				font-size: 0.84rem;
				font-weight: 550;
				color: #e2e8f0;
			}
			.bmf-ke-bar-val {
				font-size: 0.82rem;
				color: var(--ke-muted);
				font-variant-numeric: tabular-nums;
				white-space: nowrap;
			}
			.bmf-ke-bar-val strong {
				color: #f8fafc;
				font-weight: 650;
				margin-right: 0.35rem;
			}
			.bmf-ke-tag {
				display: inline-block;
				padding: 0.08rem 0.4rem;
				border-radius: 999px;
				font-size: 0.68rem;
				font-weight: 600;
				background: rgba(148, 163, 184, 0.12);
				color: var(--ke-muted);
				border: 1px solid rgba(148, 163, 184, 0.2);
			}
			.bmf-ke-bar-row.is-excellent .bmf-ke-tag {
				background: rgba(52, 211, 153, 0.12);
				color: #6ee7b7;
				border-color: rgba(52, 211, 153, 0.3);
			}
			.bmf-ke-bar-row.is-strong .bmf-ke-tag {
				background: rgba(34, 211, 238, 0.12);
				color: #67e8f9;
				border-color: rgba(34, 211, 238, 0.3);
			}
			.bmf-ke-bar-row.is-building .bmf-ke-tag {
				background: rgba(96, 165, 250, 0.12);
				color: #93c5fd;
				border-color: rgba(96, 165, 250, 0.3);
			}
			.bmf-ke-bar-row.is-focus .bmf-ke-tag {
				background: rgba(251, 191, 36, 0.12);
				color: #fde68a;
				border-color: rgba(251, 191, 36, 0.35);
			}
			.bmf-ke-missing {
				font-size: 0.78rem;
				color: #64748b;
				font-style: italic;
			}
			.bmf-ke-bar-track {
				height: 7px;
				border-radius: 999px;
				background: rgba(30, 42, 68, 0.9);
				overflow: hidden;
			}
			.bmf-ke-bar-fill {
				display: block;
				height: 100%;
				border-radius: 999px;
				background: #64748b;
				min-width: 0;
				transition: width 0.35s ease;
			}
			.bmf-ke-bar-fill.is-excellent { background: linear-gradient(90deg, #059669, #34d399); }
			.bmf-ke-bar-fill.is-strong    { background: linear-gradient(90deg, #0891b2, #22d3ee); }
			.bmf-ke-bar-fill.is-building  { background: linear-gradient(90deg, #2563eb, #60a5fa); }
			.bmf-ke-bar-fill.is-focus     { background: linear-gradient(90deg, #d97706, #fbbf24); }
			.bmf-ke-bar-fill.is-missing   { background: transparent; width: 0 !important; }
			@media (max-width: 420px) {
				.bmf-ke-card { padding: 1rem 0.9rem 1.05rem; }
				.bmf-ke-overall-value { font-size: 1.55rem; }
				.bmf-ke-bar-meta { flex-direction: column; align-items: flex-start; gap: 0.15rem; }
			}
		</style>
		<?php
	}
}

add_action( 'init', [ 'BMF_Key_Essentials_Shortcodes', 'init' ] );
