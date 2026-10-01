<?php
/**
 * Breathermae Key Essentials – Results Shortcode
 *
 * [bmf_key_essentials] — dark results card with the latest score for each of the
 * seven Key Essentials assessments (from uls_key_essentials).
 *
 * [bmf_key_essentials_trend_chart height="360"] — 7-series Chart.js line trend
 * (one line per Key Essential). High-better 1–5 scale. No RSI-style groupings.
 *
 * [bmf_key_essentials_delta form_id="27"] — current vs previous average for one
 * Key Essential. form_id may be BMF id 27–33, legacy key_*_form, or slug/alias.
 * form_id="overall" (or 0 / all) = mean of available Keys as-of the selected
 * date vs the previous assessment date. High-better: up = green, down = red.
 *
 * [bmf_key_essentials_history_select] — date dropdown; sets ?keys_date=YYYY-MM-DD
 * (same pattern as [bmf_rsi_history_select] / ?rsi_date=). Per-form and overall
 * deltas plus [bmf_key_essentials] honor that date. Trend chart stays full history.
 *
 * Reads uls_key_essentials. Rows are written on BMF submit
 * (bmf_response_submitted) and historically by the Elementor
 * elementor_pro/forms/new_record hook in uls-custom.
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
	 * @param string $as_of optional Y-m-d or datetime; rows after this day are ignored
	 * @return array form_id => [ 'average_score'=>float, 'total_score'=>float, 'datetime'=>string, 'id'=>int ]
	 */
	public static function get_latest_by_form( $email, $as_of = '' ) {
		global $wpdb;

		$email = trim( (string) $email );
		if ( $email === '' ) {
			return [];
		}

		$cutoff = self::end_of_day( $as_of );

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
			$dt = (string) ( $row['datetime'] ?? '' );
			if ( $cutoff !== '' && $dt !== '' && strcmp( $dt, $cutoff ) > 0 ) {
				continue;
			}
			$latest[ $fid ] = [
				'id'            => (int) $row['id'],
				'average_score' => isset( $row['average_score'] ) ? (float) $row['average_score'] : null,
				'total_score'   => isset( $row['total_score'] ) ? (float) $row['total_score'] : null,
				'datetime'      => $dt,
			];
		}

		return $latest;
	}

	/**
	 * Distinct assessment days (Y-m-d), newest first.
	 *
	 * @return string[]
	 */
	public static function get_assessment_dates( $email ) {
		$history = self::get_history_by_form( $email );
		$seen    = [];
		foreach ( $history as $pts ) {
			foreach ( $pts as $pt ) {
				$d = $pt['date'] ?? '';
				if ( $d !== '' ) {
					$seen[ $d ] = true;
				}
			}
		}
		$dates = array_keys( $seen );
		rsort( $dates );
		return $dates;
	}

	/** ?keys_date=YYYY-MM-DD from the history select, or empty. */
	public static function request_as_of_date() {
		if ( ! isset( $_GET['keys_date'] ) ) {
			return '';
		}
		$raw = sanitize_text_field( wp_unslash( $_GET['keys_date'] ) );
		$ts  = strtotime( $raw );
		return $ts ? date( 'Y-m-d', $ts ) : '';
	}

	/** Inclusive end-of-day mysql datetime, or '' if $as_of empty/invalid. */
	public static function end_of_day( $as_of ) {
		$as_of = trim( (string) $as_of );
		if ( $as_of === '' ) {
			return '';
		}
		$ts = strtotime( $as_of );
		return $ts ? date( 'Y-m-d 23:59:59', $ts ) : '';
	}

	/**
	 * Mean of available Key scores as-of a day vs the previous assessment day.
	 * Carry-forward: a form completed earlier still counts until it is retaken.
	 *
	 * @return array{current:?float,previous:?float,current_date:string,previous_date:string,n_current:int,n_previous:int}
	 */
	public static function get_overall_current_and_previous( $email, $as_of = '' ) {
		$empty = [
			'current'       => null,
			'previous'      => null,
			'current_date'  => '',
			'previous_date' => '',
			'n_current'     => 0,
			'n_previous'    => 0,
		];
		$dates = self::get_assessment_dates( $email );
		if ( empty( $dates ) ) {
			return $empty;
		}

		$as_of = trim( (string) $as_of );
		if ( $as_of !== '' && in_array( $as_of, $dates, true ) ) {
			$current_date = $as_of;
		} else {
			$current_date = $dates[0]; // newest
		}

		$previous_date = '';
		foreach ( $dates as $d ) {
			if ( $d < $current_date ) {
				$previous_date = $d;
				break;
			}
		}

		$cur_avg = self::overall_as_of( $email, $current_date );
		$prv_avg = $previous_date !== '' ? self::overall_as_of( $email, $previous_date ) : [ 'average' => null, 'n' => 0 ];

		return [
			'current'       => $cur_avg['average'],
			'previous'      => $prv_avg['average'],
			'current_date'  => $current_date,
			'previous_date' => $previous_date,
			'n_current'     => $cur_avg['n'],
			'n_previous'    => $prv_avg['n'],
		];
	}

	/**
	 * Mean of latest-per-form scores on or before $as_of.
	 *
	 * @return array{average:?float,n:int}
	 */
	public static function overall_as_of( $email, $as_of ) {
		$latest = self::get_latest_by_form( $email, $as_of );
		$vals   = [];
		foreach ( self::form_catalog() as $fid => $_label ) {
			$row = $latest[ $fid ] ?? null;
			if ( $row && $row['average_score'] !== null ) {
				$vals[] = (float) $row['average_score'];
			}
		}
		$n = count( $vals );
		return [
			'average' => $n > 0 ? round( array_sum( $vals ) / $n, 2 ) : null,
			'n'       => $n,
		];
	}

	public static function is_overall_form_attr( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		return in_array( $raw, [ 'overall', 'all', '0', 'avg', 'average' ], true );
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

	/**
	 * BMF slug / tag / legacy Elementor form_name → uls_key_essentials.form_id.
	 */
	public static function legacy_form_id_map() {
		return [
			'key-fluid-hydration'    => 'key_fluid_form',
			'keyfluid'               => 'key_fluid_form',
			'key_fluid_form'         => 'key_fluid_form',
			'key-food-nutrition'     => 'key_food_form',
			'keyfood'                => 'key_food_form',
			'key_food_form'          => 'key_food_form',
			'key-breath-environment' => 'key_breath_form',
			'keybreath'              => 'key_breath_form',
			'key_breath_form'        => 'key_breath_form',
			'key-movement'           => 'key_movement_form',
			'keymovement'            => 'key_movement_form',
			'key_movement_form'      => 'key_movement_form',
			'key-mind-balance'       => 'key_mind_form',
			'keymind'                => 'key_mind_form',
			'key_mind_form'          => 'key_mind_form',
			'key-sleep-recovery'     => 'key_sleep_form',
			'keysleep'               => 'key_sleep_form',
			'key_sleep_form'         => 'key_sleep_form',
			'key-nature-connection'  => 'key_nature_form',
			'keynature'              => 'key_nature_form',
			'key_nature_form'        => 'key_nature_form',
		];
	}

	public static function resolve_legacy_form_id( $slug = '', $form_tag = '' ) {
		$map  = self::legacy_form_id_map();
		$slug = strtolower( trim( (string) $slug ) );
		$tag  = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', (string) $form_tag ) );
		if ( $slug !== '' && isset( $map[ $slug ] ) ) {
			return $map[ $slug ];
		}
		if ( $tag !== '' && isset( $map[ $tag ] ) ) {
			return $map[ $tag ];
		}
		$slug_us = str_replace( '-', '_', $slug );
		if ( $slug_us !== '' && isset( $map[ $slug_us ] ) ) {
			return $map[ $slug_us ];
		}
		return '';
	}

	/**
	 * BMF numeric form ids 27–33 → uls_key_essentials.form_id.
	 * Live slug lookup is preferred when BMF_Repository is available.
	 */
	public static function numeric_form_map() {
		return [
			27 => 'key_fluid_form',
			28 => 'key_food_form',
			29 => 'key_breath_form',
			30 => 'key_movement_form',
			31 => 'key_mind_form',
			32 => 'key_sleep_form',
			33 => 'key_nature_form',
		];
	}

	/**
	 * Line colors for the 7-series trend (distinct, dark-theme).
	 */
	public static function series_colors() {
		return [
			'key_fluid_form'    => '#38bdf8',
			'key_food_form'     => '#34d399',
			'key_breath_form'   => '#22d3ee',
			'key_movement_form' => '#fbbf24',
			'key_mind_form'     => '#a78bfa',
			'key_sleep_form'    => '#818cf8',
			'key_nature_form'   => '#4ade80',
		];
	}

	/**
	 * Resolve any form attr (27, key_fluid_form, key-fluid-hydration, fluid)
	 * to a catalog key used in uls_key_essentials.form_id.
	 */
	public static function resolve_catalog_form_id( $raw ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return '';
		}

		$catalog = self::form_catalog();
		if ( isset( $catalog[ $raw ] ) ) {
			return $raw;
		}

		$legacy = self::resolve_legacy_form_id( $raw, $raw );
		if ( $legacy !== '' && isset( $catalog[ $legacy ] ) ) {
			return $legacy;
		}

		if ( ctype_digit( $raw ) ) {
			$n   = (int) $raw;
			$map = self::numeric_form_map();
			if ( isset( $map[ $n ] ) ) {
				return $map[ $n ];
			}
			if ( class_exists( 'BMF_Repository' ) && method_exists( 'BMF_Repository', 'get_form' ) ) {
				$form = BMF_Repository::get_form( $n );
				if ( $form ) {
					$slug = is_object( $form ) ? ( $form->slug ?? '' ) : ( $form['slug'] ?? '' );
					$tag  = is_object( $form ) ? ( $form->form_tag ?? '' ) : ( $form['form_tag'] ?? '' );
					$hit  = self::resolve_legacy_form_id( $slug, $tag );
					if ( $hit !== '' && isset( $catalog[ $hit ] ) ) {
						return $hit;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Full history per catalog form_id, oldest first.
	 * Same-day duplicates keep the latest id.
	 *
	 * @return array form_id => [ ['date'=>'Y-m-d','datetime'=>...,'average_score'=>float], ... ]
	 */
	public static function get_history_by_form( $email ) {
		global $wpdb;

		$email = trim( (string) $email );
		if ( $email === '' ) {
			return [];
		}

		$table = 'uls_key_essentials';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, form_id, datetime, average_score
				 FROM {$table}
				 WHERE user_email = %s
				 ORDER BY datetime ASC, id ASC",
				$email
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return [];
		}

		$catalog = self::form_catalog();
		$by_form = [];
		foreach ( $rows as $row ) {
			$fid = (string) ( $row['form_id'] ?? '' );
			if ( $fid === '' || ! isset( $catalog[ $fid ] ) ) {
				continue;
			}
			$dt = (string) ( $row['datetime'] ?? '' );
			$ts = $dt ? strtotime( $dt ) : false;
			if ( ! $ts ) {
				continue;
			}
			$day = date( 'Y-m-d', $ts );
			if ( ! isset( $by_form[ $fid ] ) ) {
				$by_form[ $fid ] = [];
			}
			// Collapse same calendar day to the latest row.
			$by_form[ $fid ][ $day ] = [
				'date'          => $day,
				'datetime'      => $dt,
				'average_score' => isset( $row['average_score'] ) ? (float) $row['average_score'] : null,
			];
		}

		$out = [];
		foreach ( $catalog as $fid => $_label ) {
			if ( empty( $by_form[ $fid ] ) ) {
				$out[ $fid ] = [];
				continue;
			}
			ksort( $by_form[ $fid ] );
			$out[ $fid ] = array_values( $by_form[ $fid ] );
		}
		return $out;
	}

	/**
	 * Current + previous average_score for one form.
	 * $as_of is Y-m-d or mysql datetime; empty = latest.
	 *
	 * @return array{current:?float,previous:?float,current_date:string,previous_date:string}
	 */
	public static function get_current_and_previous( $email, $legacy_form_id, $as_of = '' ) {
		$empty = [
			'current'       => null,
			'previous'      => null,
			'current_date'  => '',
			'previous_date' => '',
		];
		$email          = trim( (string) $email );
		$legacy_form_id = (string) $legacy_form_id;
		if ( $email === '' || $legacy_form_id === '' ) {
			return $empty;
		}

		global $wpdb;
		$table = 'uls_key_essentials';

		$as_of = trim( (string) $as_of );
		$as_of_sql = '';
		if ( $as_of !== '' ) {
			$ts = strtotime( $as_of );
			if ( $ts ) {
				// Inclusive end of the given day.
				$as_of_sql = date( 'Y-m-d 23:59:59', $ts );
			}
		}

		if ( $as_of_sql !== '' ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT datetime, average_score
					 FROM {$table}
					 WHERE user_email = %s AND form_id = %s AND datetime <= %s
					 ORDER BY datetime DESC, id DESC
					 LIMIT 2",
					$email,
					$legacy_form_id,
					$as_of_sql
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT datetime, average_score
					 FROM {$table}
					 WHERE user_email = %s AND form_id = %s
					 ORDER BY datetime DESC, id DESC
					 LIMIT 2",
					$email,
					$legacy_form_id
				),
				ARRAY_A
			);
		}

		if ( empty( $rows ) ) {
			return $empty;
		}

		$cur = $rows[0];
		$prv = $rows[1] ?? null;
		return [
			'current'       => isset( $cur['average_score'] ) && $cur['average_score'] !== '' ? (float) $cur['average_score'] : null,
			'previous'      => ( $prv && isset( $prv['average_score'] ) && $prv['average_score'] !== '' ) ? (float) $prv['average_score'] : null,
			'current_date'  => (string) ( $cur['datetime'] ?? '' ),
			'previous_date' => $prv ? (string) ( $prv['datetime'] ?? '' ) : '',
		];
	}
}

/**
 * Write uls_key_essentials when a BMF Key Essentials form is submitted.
 * Replaces the Elementor-only path in uls-custom.
 */
class BMF_Key_Essentials_Saver {

	public static function init() {
		add_action( 'bmf_response_submitted', [ __CLASS__, 'on_response_submitted' ], 30, 1 );
	}

	public static function on_response_submitted( $response_id ) {
		$response_id = (int) $response_id;
		if ( $response_id <= 0 ) {
			return;
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.id, r.user_id, r.form_id, r.submitted_at, f.slug, f.form_tag
				 FROM {$wpdb->prefix}bm_responses r
				 INNER JOIN {$wpdb->prefix}bm_forms f ON f.id = r.form_id
				 WHERE r.id = %d
				 LIMIT 1",
				$response_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return;
		}

		$legacy_id = BMF_Key_Essentials_Service::resolve_legacy_form_id(
			(string) ( $row['slug'] ?? '' ),
			(string) ( $row['form_tag'] ?? '' )
		);
		if ( $legacy_id === '' ) {
			return;
		}

		$user_id = (int) ( $row['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}
		$email = BMF_Key_Essentials_Service::resolve_email( $user_id );
		if ( $email === '' ) {
			return;
		}

		$scores = self::score_response( $response_id );
		if ( $scores['count'] <= 0 ) {
			if ( function_exists( 'bm_log' ) ) {
				bm_log( 'KEY ESSENTIALS SAVE SKIP | no numeric answers | response_id=' . $response_id );
			}
			return;
		}

		$datetime = ! empty( $row['submitted_at'] ) ? (string) $row['submitted_at'] : current_time( 'mysql' );

		$ok = $wpdb->insert(
			'uls_key_essentials',
			[
				'unique_id'     => uniqid( 'survey_', true ),
				'form_id'       => $legacy_id,
				'datetime'      => $datetime,
				'user_email'    => $email,
				'total_score'   => $scores['total'],
				'average_score' => $scores['average'],
			],
			[ '%s', '%s', '%s', '%s', '%f', '%f' ]
		);

		if ( function_exists( 'bm_log' ) ) {
			bm_log(
				'KEY ESSENTIALS SAVE | response_id=' . $response_id
				. ' | form=' . $legacy_id
				. ' | email=' . $email
				. ' | n=' . $scores['count']
				. ' | total=' . $scores['total']
				. ' | avg=' . $scores['average']
				. ' | insert=' . ( $ok ? 'ok' : ( $wpdb->last_error ?: 'fail' ) )
			);
		}
	}

	/**
	 * Sum / average of numeric choice_value on the response (1–5 Likert).
	 * Strips the "value|{json}" payload BMF sometimes stores.
	 */
	public static function score_response( $response_id ) {
		global $wpdb;
		$vals = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT choice_value FROM {$wpdb->prefix}bm_response_items WHERE response_id = %d",
				(int) $response_id
			)
		);

		$total = 0.0;
		$count = 0;
		if ( is_array( $vals ) ) {
			foreach ( $vals as $raw ) {
				$n = self::numeric_choice( $raw );
				if ( $n === null ) {
					continue;
				}
				$total += $n;
				$count++;
			}
		}

		return [
			'total'   => $total,
			'count'   => $count,
			'average' => $count > 0 ? round( $total / $count, 2 ) : 0.0,
		];
	}

	private static function numeric_choice( $raw ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return null;
		}
		if ( strpos( $raw, '|' ) !== false ) {
			$raw = trim( explode( '|', $raw, 2 )[0] );
		}
		if ( class_exists( 'BMF_Repository' ) && method_exists( 'BMF_Repository', 'resolve_numeric_choice' ) ) {
			$n = BMF_Repository::resolve_numeric_choice( $raw );
			return $n !== null ? (float) $n : null;
		}
		return is_numeric( $raw ) ? (float) $raw : null;
	}
}

/**
 * Shortcodes.
 */
class BMF_Key_Essentials_Shortcodes {

	public static function init() {
		add_shortcode( 'bmf_key_essentials',               [ __CLASS__, 'render' ] );
		add_shortcode( 'bmf_key_essentials_trend_chart',   [ __CLASS__, 'shortcode_trend_chart' ] );
		add_shortcode( 'bmf_key_essentials_delta',         [ __CLASS__, 'shortcode_delta' ] );
		add_shortcode( 'bmf_key_essentials_history_select',[ __CLASS__, 'shortcode_history_select' ] );
		BMF_Key_Essentials_Saver::init();
	}

	private static function should_bail_for_editor(): bool {
		$disable = apply_filters( 'bmf/shortcodes/disable_in_elementor', true );
		if ( ! $disable ) {
			return false;
		}
		return function_exists( 'bmf_in_elementor_editor' ) && bmf_in_elementor_editor();
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
		$as_of   = BMF_Key_Essentials_Service::request_as_of_date();
		$latest  = BMF_Key_Essentials_Service::get_latest_by_form( $email, $as_of );

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
	 * [bmf_key_essentials_history_select user_id="" email=""]
	 *
	 * Dropdown of distinct Key Essentials days. On change, sets ?keys_date=
	 * and reloads — same mechanism as [bmf_rsi_history_select] / rsi_date.
	 */
	public static function shortcode_history_select( $atts ) {
		if ( self::should_bail_for_editor() ) {
			return '';
		}

		$atts = shortcode_atts(
			[
				'user_id' => get_current_user_id(),
				'email'   => '',
			],
			$atts,
			'bmf_key_essentials_history_select'
		);

		if ( ! is_user_logged_in() && empty( $atts['email'] ) && empty( $atts['user_id'] ) ) {
			return '';
		}

		$email = BMF_Key_Essentials_Service::resolve_email( $atts['user_id'], $atts['email'] );
		if ( $email === '' ) {
			return '';
		}

		$dates = BMF_Key_Essentials_Service::get_assessment_dates( $email );
		if ( empty( $dates ) ) {
			return '';
		}

		$selected = BMF_Key_Essentials_Service::request_as_of_date();
		if ( $selected === '' || ! in_array( $selected, $dates, true ) ) {
			$selected = $dates[0];
		}

		$uid = 'bmf_ke_date_select_' . wp_unique_id();

		ob_start();
		?>
		<div class="bmf-ke-history-select" style="margin-bottom:10px; font-size:0.9rem; color:#001d50; display:flex; align-items:center; gap:8px;">
			<b style="white-space:nowrap;">Assessment Date:</b>
			<select id="<?php echo esc_attr( $uid ); ?>" style="padding:4px 8px; font-size:0.9rem; border:1px solid #001d50; border-radius:4px; width:150px;">
				<?php foreach ( $dates as $d ) : ?>
					<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $selected, $d ); ?>>
						<?php echo esc_html( date( 'M j, Y', strtotime( $d ) ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var el = document.getElementById(<?php echo wp_json_encode( $uid ); ?>);
			if (!el) return;
			el.addEventListener('change', function() {
				var selected = this.value;
				var url = new URL(window.location.href);
				if (selected) {
					url.searchParams.set('keys_date', selected);
				} else {
					url.searchParams.delete('keys_date');
				}
				window.location.href = url.toString();
			});
		});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * [bmf_key_essentials_trend_chart height="360" user_id="" email=""]
	 *
	 * Seven independent lines (no driver/mediator/outcome rollup).
	 * Y = average_score 1–5, high better. X = calendar dates of submissions.
	 */
	public static function shortcode_trend_chart( $atts ) {
		if ( self::should_bail_for_editor() ) {
			return '<div class="bmf-ke-trend-empty">Key Essentials trend preview</div>';
		}

		$atts = shortcode_atts(
			[
				'user_id' => get_current_user_id(),
				'email'   => '',
				'height'  => '360',
			],
			$atts,
			'bmf_key_essentials_trend_chart'
		);

		if ( ! is_user_logged_in() && empty( $atts['email'] ) && empty( $atts['user_id'] ) ) {
			return '<p class="bmf-ke-login">Please log in to view your Key Essentials trend.</p>';
		}

		$email = BMF_Key_Essentials_Service::resolve_email( $atts['user_id'], $atts['email'] );
		if ( $email === '' ) {
			return '<p class="bmf-ke-empty">Unable to resolve user for Key Essentials trend.</p>';
		}

		$catalog = BMF_Key_Essentials_Service::form_catalog();
		$colors  = BMF_Key_Essentials_Service::series_colors();
		$history = BMF_Key_Essentials_Service::get_history_by_form( $email );

		$series = [];
		$latest = [];
		$min_ts = null;
		$max_ts = null;
		$n_pts  = 0;
		foreach ( $catalog as $fid => $label ) {
			$series[ $fid ] = [];
			$latest[ $fid ] = null;
			foreach ( $history[ $fid ] ?? [] as $pt ) {
				if ( $pt['average_score'] === null ) {
					continue;
				}
				$ts = strtotime( $pt['date'] . ' 00:00:00' );
				if ( ! $ts ) {
					continue;
				}
				$series[ $fid ][] = [ 'x' => $ts * 1000, 'y' => round( (float) $pt['average_score'], 2 ) ];
				$latest[ $fid ]   = round( (float) $pt['average_score'], 2 );
				$n_pts++;
				if ( $min_ts === null || $ts < $min_ts ) {
					$min_ts = $ts;
				}
				if ( $max_ts === null || $ts > $max_ts ) {
					$max_ts = $ts;
				}
			}
		}

		$theme = class_exists( 'BMF_Wellbeing_Theme' ) ? BMF_Wellbeing_Theme::get() : 'dark';

		if ( $n_pts === 0 ) {
			return '<div class="bmf-ke-trend-empty" data-bmf-theme="' . esc_attr( $theme ) . '">No historical Key Essentials data</div>';
		}

		// Pad the window; keep at least ~90 days so a couple of points are readable.
		$pad     = 7 * DAY_IN_SECONDS;
		$x_min   = ( $min_ts - $pad ) * 1000;
		$span    = max( 1, $max_ts - $min_ts );
		$min_win = 90 * DAY_IN_SECONDS;
		if ( $span < $min_win ) {
			$x_max = ( $min_ts + $min_win + $pad ) * 1000;
		} else {
			$x_max = ( $max_ts + $pad ) * 1000;
		}

		$height       = max( 240, (int) $atts['height'] );
		$point_radius = ( $n_pts > 48 ) ? 2.5 : ( ( $n_pts > 21 ) ? 3.5 : 5 );
		$point_hover  = $point_radius + 2;
		$uid          = 'bmf_ke_trend_' . (int) $atts['user_id'] . '_' . wp_unique_id();

		$datasets = [];
		foreach ( $catalog as $fid => $label ) {
			$c = $colors[ $fid ] ?? '#94a3b8';
			$datasets[] = [
				'key'   => $fid,
				'label' => $label,
				'color' => $c,
				'data'  => $series[ $fid ],
			];
		}

		ob_start();
		?>
<style>
.bmf-ke-trend-wrap,.bmf-ke-trend-empty{
  --ke-text:#e2e8f0;--ke-muted:#94a3b8;--ke-label:#38bdf8;--ke-title:#f8fafc;--ke-bg:#0b1220;
  font-family:system-ui,-apple-system,sans-serif;color:var(--ke-text);border-radius:16px;
}
.bmf-ke-trend-wrap[data-bmf-theme="light"],.bmf-ke-trend-empty[data-bmf-theme="light"]{
  --ke-text:#122033;--ke-muted:#5b6b82;--ke-label:#0284c7;--ke-title:#0b1b3a;--ke-bg:#f4f7fb;
}
.bmf-ke-trend-wrap{background:transparent;padding:4px 0 0}
.bmf-ke-trend-empty{padding:24px;text-align:center;color:var(--ke-muted);background:var(--ke-bg)}
.bmf-ke-trend-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.bmf-ke-trend-kicker{font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ke-label)}
.bmf-ke-trend-title{font-size:1.05rem;font-weight:650;color:var(--ke-title)}
.bmf-ke-trend-hint{font-size:.78rem;color:var(--ke-muted)}
.bmf-ke-trend-legend{display:flex;flex-wrap:wrap;gap:12px 16px;justify-content:center;margin-top:14px;font-size:.8rem;color:var(--ke-text)}
</style>
<div class="bmf-ke-trend-wrap" data-bmf-theme="<?php echo esc_attr( $theme ); ?>">
  <div class="bmf-ke-trend-head">
    <div>
      <div class="bmf-ke-trend-kicker">Key Essentials</div>
      <div class="bmf-ke-trend-title">History trend</div>
    </div>
    <div class="bmf-ke-trend-hint">1–5 scale · higher is better · click a name to hide</div>
  </div>
  <div style="position:relative;height:<?php echo (int) $height; ?>px;">
    <canvas id="<?php echo esc_attr( $uid ); ?>"></canvas>
  </div>
  <div class="bmf-ke-trend-legend">
    <?php foreach ( $datasets as $ds ) : ?>
    <span style="display:inline-flex;align-items:center;gap:6px;">
      <span style="width:12px;height:3px;background:<?php echo esc_attr( $ds['color'] ); ?>;border-radius:2px;display:inline-block;"></span>
      <?php echo esc_html( $ds['label'] ); ?>
      <?php if ( $latest[ $ds['key'] ] !== null ) : ?>
        <strong style="color:<?php echo esc_attr( $ds['color'] ); ?>;"><?php echo esc_html( number_format( $latest[ $ds['key'] ], 2 ) ); ?></strong>
      <?php endif; ?>
    </span>
    <?php endforeach; ?>
  </div>
</div>
<script>
(function(){
  var canvasId = <?php echo wp_json_encode( $uid ); ?>;
  var datasetsIn = <?php echo wp_json_encode( $datasets ); ?>;
  var xMin = <?php echo (int) $x_min; ?>;
  var xMax = <?php echo (int) $x_max; ?>;
  var ptRadius = <?php echo (float) $point_radius; ?>;
  var ptHover = <?php echo (float) $point_hover; ?>;

  function loadScript(src, cb) {
    if (document.querySelector('script[src="'+src+'"]')) { cb(); return; }
    var s = document.createElement('script');
    s.src = src; s.onload = cb; document.head.appendChild(s);
  }

  function closestTheme(el) {
    var host = document.querySelector('.bmf-wb-panel[data-bmf-theme], .bmf-wb-wrap[data-bmf-theme]');
    if (host) {
      var ht = host.getAttribute('data-bmf-theme');
      if (ht === 'light' || ht === 'dark') return ht;
    }
    var n = el;
    while (n && n.getAttribute) {
      var t = n.getAttribute('data-bmf-theme');
      if (t === 'light' || t === 'dark') return t;
      n = n.parentElement;
    }
    return 'dark';
  }

  function chartSkin(theme) {
    if (theme === 'light') {
      return {
        grid: 'rgba(213,222,235,.95)',
        tick: '#5b6b82',
        legend: '#1e3a5f',
        point: '#fff',
        tipBg: '#fff',
        tipTitle: '#122033',
        tipBody: '#334155',
        tipBorder: '#d5deeb',
        zones: [
          { from: 4.5, to: 5.0, color: 'rgba(16,185,129,0.12)' },
          { from: 3.5, to: 4.5, color: 'rgba(8,145,178,0.10)' },
          { from: 2.5, to: 3.5, color: 'rgba(37,99,235,0.08)' },
          { from: 1.0, to: 2.5, color: 'rgba(217,119,6,0.10)' }
        ]
      };
    }
    return {
      grid: 'rgba(30,42,68,0.8)',
      tick: '#94a3b8',
      legend: '#cbd5e1',
      point: '#0b1220',
      tipBg: '#121a2b',
      tipTitle: '#e2e8f0',
      tipBody: '#cbd5e1',
      tipBorder: '#1e2a44',
      zones: [
        { from: 4.5, to: 5.0, color: 'rgba(52,211,153,0.12)' },
        { from: 3.5, to: 4.5, color: 'rgba(34,211,238,0.10)' },
        { from: 2.5, to: 3.5, color: 'rgba(96,165,250,0.08)' },
        { from: 1.0, to: 2.5, color: 'rgba(251,191,36,0.10)' }
      ]
    };
  }

  function boot() {
    loadScript('https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', function(){
      loadScript('https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js', function(){
        render();
      });
    });
  }

  function render() {
    var ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') return;
    var wrap = ctx.closest('.bmf-ke-trend-wrap');
    var theme = closestTheme(wrap || ctx);
    if (wrap) wrap.setAttribute('data-bmf-theme', theme);
    var skin = chartSkin(theme);
    if (ctx._keChart) {
      try { ctx._keChart.destroy(); } catch (e) {}
      ctx._keChart = null;
    }

    var zonePlugin = {
      id: 'bmfKeZones',
      beforeDraw: function(chart) {
        var y = chart.scales.y;
        var x = chart.scales.x;
        var areas = skin.zones;
        var ctx2 = chart.ctx;
        areas.forEach(function(a){
          var y1 = y.getPixelForValue(a.to);
          var y2 = y.getPixelForValue(a.from);
          ctx2.fillStyle = a.color;
          ctx2.fillRect(x.left, y1, x.right - x.left, y2 - y1);
        });
      }
    };

    var glowPlugin = {
      id: 'bmfKeGlow',
      beforeDatasetDraw: function(chart, args) {
        var ctx2 = chart.ctx;
        var ds   = chart.data.datasets[args.index];
        if (!ds) return;
        ctx2.save();
        ctx2.shadowColor   = ds.borderColor || 'rgba(255,255,255,0.4)';
        ctx2.shadowBlur    = 6;
        ctx2.shadowOffsetX = 0;
        ctx2.shadowOffsetY = 3;
      },
      afterDatasetDraw: function(chart) {
        chart.ctx.restore();
      }
    };

    var datasets = datasetsIn.map(function(ds){
      return {
        label: ds.label,
        data: ds.data,
        borderColor: ds.color,
        backgroundColor: ds.color,
        borderWidth: 2.25,
        pointRadius: ptRadius,
        pointHoverRadius: ptHover,
        pointBackgroundColor: skin.point,
        pointBorderColor: ds.color,
        pointBorderWidth: 2,
        tension: 0.35,
        spanGaps: true
      };
    });

    ctx._keChart = new Chart(ctx, {
      type: 'line',
      data: { datasets: datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'nearest', intersect: false },
        plugins: {
          legend: {
            display: true,
            labels: { color: skin.legend, boxWidth: 12, font: { size: 11 }, padding: 12 }
          },
          tooltip: {
            backgroundColor: skin.tipBg,
            titleColor: skin.tipTitle,
            bodyColor: skin.tipBody,
            borderColor: skin.tipBorder,
            borderWidth: 1,
            callbacks: {
              title: function(items) {
                if (!items.length) return '';
                var d = new Date(items[0].parsed.x);
                return d.toLocaleDateString(undefined, { year:'numeric', month:'short', day:'numeric' });
              },
              label: function(item) {
                var v = item.parsed.y;
                return item.dataset.label + ': ' + (v == null ? '—' : Number(v).toFixed(2));
              }
            }
          }
        },
        scales: {
          x: {
            type: 'time',
            min: xMin,
            max: xMax,
            time: { unit: 'month', displayFormats: { month: 'MMM yyyy' } },
            grid: { color: skin.grid, drawBorder: false },
            ticks: { color: skin.tick, maxRotation: 0, autoSkip: true, maxTicksLimit: 6 }
          },
          y: {
            min: 1,
            max: 5,
            grid: { color: skin.grid, drawBorder: false },
            ticks: {
              stepSize: 1,
              color: function(ctx) {
                var v = ctx.tick && ctx.tick.value;
                if (v === 5) return '#34d399';
                if (v === 4) return '#22d3ee';
                if (v === 3) return '#60a5fa';
                if (v === 2) return '#fbbf24';
                if (v === 1) return '#f59e0b';
                return skin.tick;
              },
              callback: function(v) {
                if (v === 5) return '5  EXCELLENT';
                if (v === 4) return '4  STRONG';
                if (v === 3) return '3  BUILDING';
                if (v === 2) return '2  EARLY';
                if (v === 1) return '1  FOCUS';
                return v;
              }
            }
          }
        }
      },
      plugins: [ zonePlugin, glowPlugin ]
    });
  }

  document.addEventListener('click', function (ev) {
    if (!ev.target || !ev.target.closest || !ev.target.closest('.bmf-wb-theme-btn')) return;
    setTimeout(function () { if (typeof Chart !== 'undefined') render(); }, 30);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * [bmf_key_essentials_delta form_id="27" user_id="" decimals="2" show_arrow="1" show_sign="1" colorize="1"]
	 *
	 * Current vs previous average_score for one Key Essential.
	 * High-better: increase = green ↑, decrease = red ↓.
	 * form_id accepts 27–33, key_fluid_form, key-fluid-hydration, fluid, etc.
	 * Optional ?keys_date=YYYY-MM-DD pins "current" to that date.
	 */
	public static function shortcode_delta( $atts ) {
		if ( self::should_bail_for_editor() ) {
			return '';
		}

		$atts = shortcode_atts(
			[
				'form_id'    => '',
				'form'       => '',
				'user_id'    => get_current_user_id(),
				'email'      => '',
				'decimals'   => '2',
				'show_arrow' => '1',
				'show_sign'  => '1',
				'colorize'   => '1',
			],
			$atts,
			'bmf_key_essentials_delta'
		);

		$form_raw = trim( (string) $atts['form_id'] );
		if ( $form_raw === '' ) {
			$form_raw = trim( (string) $atts['form'] );
		}

		$email = BMF_Key_Essentials_Service::resolve_email( $atts['user_id'], $atts['email'] );
		if ( $email === '' ) {
			return '';
		}

		$as_of = BMF_Key_Essentials_Service::request_as_of_date();
		$is_overall = BMF_Key_Essentials_Service::is_overall_form_attr( $form_raw );

		if ( $is_overall ) {
			$pair = BMF_Key_Essentials_Service::get_overall_current_and_previous( $email, $as_of );
		} else {
			$legacy = BMF_Key_Essentials_Service::resolve_catalog_form_id( $form_raw );
			if ( $legacy === '' ) {
				return '';
			}
			$pair = BMF_Key_Essentials_Service::get_current_and_previous( $email, $legacy, $as_of );
		}
		if ( $pair['current'] === null ) {
			return '0';
		}
		if ( $pair['previous'] === null ) {
			$out = '0';
			if ( (int) $atts['colorize'] === 1 ) {
				$out = '<span style="color:#888888;">' . $out . '</span>';
			}
			return $out;
		}

		$delta = (float) $pair['current'] - (float) $pair['previous'];
		$dec   = max( 0, (int) $atts['decimals'] );
		$num   = number_format( abs( $delta ), $dec, '.', ',' );

		$sign = '';
		if ( (int) $atts['show_sign'] === 1 ) {
			if ( $delta > 0 ) {
				$sign = '+';
			} elseif ( $delta < 0 ) {
				$sign = '−';
			}
		}
		$arrow = '';
		if ( (int) $atts['show_arrow'] === 1 ) {
			if ( $delta > 0 ) {
				$arrow = ' ↑';
			} elseif ( $delta < 0 ) {
				$arrow = ' ↓';
			}
		}
		$out = $sign . $num . $arrow;

		// High-better: up is good.
		if ( (int) $atts['colorize'] === 1 ) {
			if ( $delta > 0 ) {
				$color = '#44dd30';
			} elseif ( $delta < 0 ) {
				$color = '#c62828';
			} else {
				$color = '#888888';
			}
			$out = '<span style="color:' . esc_attr( $color ) . ';">' . $out . '</span>';
		}
		return $out;
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
