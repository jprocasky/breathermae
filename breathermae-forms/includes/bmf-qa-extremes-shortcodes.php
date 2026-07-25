<?php
/**
 * Breathermae Forms - Assessment Extremes Rollup
 *
 * [bmf_qa_extremes threshold="0.75" show_scores="0"]
 * [bmf_qa_extremes_link assessment="bsi"]BSI extremes[/bmf_qa_extremes_link]
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BMF_QA_Extremes_Shortcodes' ) ) {

	class BMF_QA_Extremes_Shortcodes {

		public static function init() {
			add_shortcode( 'bmf_qa_extremes', [ __CLASS__, 'shortcode_panel' ] );
			add_shortcode( 'bmf_qa_extremes_link', [ __CLASS__, 'shortcode_link' ] );
			add_action( 'wp_ajax_bmf_list_assessment_dates', [ __CLASS__, 'ajax_list_dates' ] );
			add_action( 'wp_ajax_bmf_get_assessment_extremes', [ __CLASS__, 'ajax_get_extremes' ] );
		}

		public static function assessments(): array {
			$defaults = [
				'bsi'     => [ 'label' => 'BSI', 'direction' => 'low_better', 'results_table' => 'bm_bsi_results', 'form_ids' => [ 1, 2, 3, 4, 5, 6, 7, 8, 9 ] ],
				'rsi'     => [ 'label' => 'RSI', 'direction' => 'low_better', 'results_table' => 'bm_rsi_results', 'form_ids' => [ 11, 12 ] ],
				'pillars' => [ 'label' => '8 Pillars', 'direction' => 'high_better', 'results_table' => 'bm_pillars_results', 'form_ids' => [ 18, 19, 20, 21, 22, 23, 24, 25 ], 'show_comparison' => true ],
			];
			return apply_filters( 'bmf_qa_assessments', $defaults );
		}

		private static function should_bail_for_editor(): bool {
			$disable = apply_filters( 'bmf/shortcodes/disable_in_elementor', true );
			if ( ! $disable ) {
				return false;
			}
			return function_exists( 'bmf_in_elementor_editor' ) && bmf_in_elementor_editor();
		}

		private static function normalize_direction( string $dir ): string {
			$dir = strtolower( trim( $dir ) );
			return in_array( $dir, [ 'low_better', 'high_better' ], true ) ? $dir : 'low_better';
		}

		private static function normalize_threshold( $raw ): float {
			$t = is_numeric( $raw ) ? (float) $raw : 0.75;
			if ( $t > 1 && $t <= 100 ) {
				$t = $t / 100;
			}
			return max( 0.5, min( 1.0, $t ) );
		}

		private static function normalize_date( $raw ): string {
			$raw = trim( (string) $raw );
			if ( $raw === '' ) {
				return '';
			}
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $raw, $m ) ) {
				return $m[1];
			}
			$ts = strtotime( $raw );
			return $ts ? gmdate( 'Y-m-d', $ts ) : '';
		}

		private static function can_view_subject( int $subject_user_id ): bool {
			if ( ! is_user_logged_in() || $subject_user_id <= 0 ) {
				return false;
			}
			$current_id  = get_current_user_id();
			$selected_id = (int) get_user_meta( $current_id, 'uls_selected_user_id', true );
			$allowed     = ( $current_id === $subject_user_id )
				|| current_user_can( 'manage_options' )
				|| ( $selected_id > 0 && $selected_id === $subject_user_id );
			$allowed = (bool) apply_filters( 'bmf_qa_can_view_subject', $allowed, $subject_user_id, $current_id );
			if ( ! $allowed && is_user_logged_in() ) {
				$allowed = (bool) apply_filters( 'bmf_qa_allow_any_logged_in', true, $subject_user_id, $current_id );
			}
			return $allowed;
		}

		private static function resolve_user_from_request(): array {
			$user_id = absint( $_POST['user_id'] ?? 0 );
			$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			if ( ! $user_id && $email && is_email( $email ) ) {
				$u = get_user_by( 'email', $email );
				if ( $u ) {
					$user_id = (int) $u->ID;
				}
			}
			$user  = $user_id ? get_userdata( $user_id ) : null;
			$label = $user ? ( $user->display_name ?: $user->user_email ) : '';
			$email = $user ? (string) $user->user_email : $email;
			return [ $user_id, $email, $label ];
		}

		public static function get_final_dates( string $assessment_key, string $email ): array {
			$map = self::assessments();
			if ( empty( $map[ $assessment_key ]['results_table'] ) || $email === '' ) {
				return [];
			}
			global $wpdb;
			$table = $wpdb->prefix . $map[ $assessment_key ]['results_table'];
			$rows  = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT results_date FROM {$table} WHERE user_email = %s AND is_final = 1 ORDER BY results_date DESC",
					$email
				)
			);
			if ( ! $rows ) {
				return [];
			}
			$out = [];
			foreach ( $rows as $raw ) {
				$d = self::normalize_date( $raw );
				if ( $d !== '' && ! in_array( $d, $out, true ) ) {
					$out[] = $d;
				}
			}
			return $out;
		}

		public static function closest_response_id( int $user_id, int $form_id, string $date ): int {
			$date = self::normalize_date( $date );
			if ( $user_id <= 0 || $form_id <= 0 || $date === '' ) {
				return 0;
			}
			global $wpdb;
			$t = $wpdb->prefix . 'bm_responses';
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$t} WHERE user_id = %d AND form_id = %d AND status = 'submitted' AND submitted_at IS NOT NULL ORDER BY ABS(DATEDIFF(DATE(submitted_at), %s)) ASC, submitted_at DESC LIMIT 1",
					$user_id, $form_id, $date
				)
			);
		}

		private static function is_extreme_row( $score, $scale_max, string $direction, float $threshold ): bool {
			if ( $score === null || $scale_max === null || (float) $scale_max <= 0 ) {
				return false;
			}
			$ratio = (float) $score / (float) $scale_max;
			return $direction === 'high_better' ? ( $ratio <= ( 1 - $threshold ) ) : ( $ratio >= $threshold );
		}

		public static function build_extremes( int $user_id, string $assessment_key, string $date, string $direction, float $threshold ): array {
			$map  = self::assessments();
			$date = self::normalize_date( $date );
			if ( empty( $map[ $assessment_key ] ) ) {
				return [ 'rows' => [], 'comparison' => null, 'label' => $assessment_key ];
			}
			$cfg      = $map[ $assessment_key ];
			$form_ids = $cfg['form_ids'] ?? [];
			$dir      = $direction ?: ( $cfg['direction'] ?? 'low_better' );
			$rows     = [];

			foreach ( $form_ids as $fid ) {
				$fid = (int) $fid;
				$rid = self::closest_response_id( $user_id, $fid, $date );
				if ( ! $rid ) {
					continue;
				}
				$qa = BMF_Repository::get_response_qa( $rid );
				if ( ! $qa ) {
					continue;
				}
				$form_title = $qa['form']['title'] ?? ( 'Form #' . $fid );
				foreach ( $qa['sections'] as $sec ) {
					foreach ( $sec['questions'] as $q ) {
						if ( ! self::is_extreme_row( $q['score'] ?? null, $q['scale_max'] ?? null, $dir, $threshold ) ) {
							continue;
						}
						$rows[] = [
							'form'         => $form_title,
							'form_id'      => $fid,
							'section'      => $sec['title'] ?? '',
							'order_index'  => $q['order_index'] ?? 0,
							'prompt'       => $q['prompt'] ?? '',
							'answer_label' => $q['answer_label'] ?? '',
							'score'        => $q['score'],
							'scale_max'    => $q['scale_max'],
						];
					}
				}
			}

			$comparison = ! empty( $cfg['show_comparison'] ) ? self::pillars_comparison( $user_id, $date ) : null;

			return [
				'rows'       => $rows,
				'comparison' => $comparison,
				'label'      => $cfg['label'] ?? $assessment_key,
				'direction'  => $dir,
				'assessment' => $assessment_key,
				'date'       => $date,
			];
		}

		public static function pillars_comparison( int $user_id, string $date ): ?array {
			$user = get_userdata( $user_id );
			if ( ! $user || empty( $user->user_email ) ) {
				return null;
			}
			$date = self::normalize_date( $date );
			global $wpdb;
			$t   = $wpdb->prefix . 'bm_pillars_results';
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$t} WHERE user_email = %s AND results_date = %s AND is_final = 1 ORDER BY id DESC LIMIT 1",
					$user->user_email, $date
				),
				ARRAY_A
			);
			if ( ! $row ) {
				return null;
			}

			$pillars = [
				'physical' => (float) ( $row['physical'] ?? 0 ), 'mental' => (float) ( $row['mental'] ?? 0 ),
				'emotional' => (float) ( $row['emotional'] ?? 0 ), 'financial' => (float) ( $row['financial'] ?? 0 ),
				'occupational' => (float) ( $row['occupational'] ?? 0 ), 'environmental' => (float) ( $row['environmental'] ?? 0 ),
				'spiritual' => (float) ( $row['spiritual'] ?? 0 ), 'social' => (float) ( $row['social'] ?? 0 ),
			];
			foreach ( $pillars as $k => $v ) {
				$pillars[ $k ] = ( $v <= 1.0 ) ? round( $v * 100, 1 ) : round( $v, 1 );
			}
			arsort( $pillars );
			$actual_labels = array_keys( $pillars );
			$actual_scores = array_values( $pillars );
			$rank_str  = $row['rank'] ?? '';
			$perceived = [];
			if ( $rank_str ) {
				$decoded   = urldecode( (string) $rank_str );
				$perceived = array_map( 'ucfirst', array_map( 'trim', explode( ',', $decoded ) ) );
			}
			$master = isset( $row['master_score'] ) ? round( (float) $row['master_score'], 1 ) : null;
			$items  = [];
			$max    = max( count( $perceived ), count( $actual_labels ) );
			for ( $i = 0; $i < $max; $i++ ) {
				$perc     = $perceived[ $i ] ?? '-';
				$act      = isset( $actual_labels[ $i ] ) ? ucfirst( $actual_labels[ $i ] ) : '-';
				$sc       = $actual_scores[ $i ] ?? null;
				$perc_pos = ( $act !== '-' ) ? array_search( strtolower( $act ), array_map( 'strtolower', $perceived ), true ) : false;
				$diff     = ( $perc_pos !== false ) ? ( (int) $perc_pos - $i ) : null;
				$items[]  = [ 'perceived' => $perc, 'actual' => $act, 'score' => $sc, 'diff' => $diff ];
			}
			return [ 'master' => $master, 'date' => self::normalize_date( $row['results_date'] ?? $date ), 'items' => $items ];
		}

		public static function shortcode_link( $atts, $content = null ) {
			if ( self::should_bail_for_editor() ) {
				return is_string( $content ) ? $content : '';
			}
			$atts = shortcode_atts( [ 'assessment' => '', 'direction' => '', 'class' => '' ], $atts, 'bmf_qa_extremes_link' );
			$key  = strtolower( trim( (string) $atts['assessment'] ) );
			if ( $key === '' ) {
				return is_string( $content ) ? $content : '';
			}
			$map   = self::assessments();
			$label = is_string( $content ) ? trim( $content ) : '';
			if ( $label === '' ) {
				$label = ( $map[ $key ]['label'] ?? $key ) . ' extremes';
			}
			$class = 'bmf-qa-extremes-link' . ( $atts['class'] !== '' ? ' ' . sanitize_html_class( $atts['class'] ) : '' );
			$dir_attr = '';
			if ( $atts['direction'] !== '' ) {
				$dir_attr = ' data-bmf-qa-direction="' . esc_attr( self::normalize_direction( $atts['direction'] ) ) . '"';
			} elseif ( ! empty( $map[ $key ]['direction'] ) ) {
				$dir_attr = ' data-bmf-qa-direction="' . esc_attr( self::normalize_direction( $map[ $key ]['direction'] ) ) . '"';
			}
			wp_enqueue_style( 'bmf-qa' );
			return sprintf(
				'<a href="#extremes" class="%s" data-bmf-qa-assessment="%s"%s role="button">%s</a>',
				esc_attr( $class ), esc_attr( $key ), $dir_attr, esc_html( $label )
			);
		}

		// TEMP_STUB - will be replaced
		public static function shortcode_panel( $atts ) {
			return '<div class="bmf-qa-empty">Extremes panel loading... please hard-refresh after latest deploy.</div>';
		}

		public static function ajax_list_dates() {
			check_ajax_referer( 'bmf_qa_nonce', 'nonce' );
			if ( ! is_user_logged_in() ) {
				wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
			}
			$assessment = strtolower( sanitize_key( $_POST['assessment'] ?? '' ) );
			$map        = self::assessments();
			if ( empty( $map[ $assessment ] ) ) {
				wp_send_json_error( [ 'message' => 'Unknown assessment: ' . $assessment ], 400 );
			}
			list( $user_id, $email, $label ) = self::resolve_user_from_request();
			if ( ! $user_id ) {
				wp_send_json_error( [ 'message' => 'No WordPress user found for that email.' ], 404 );
			}
			if ( ! self::can_view_subject( $user_id ) ) {
				wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
			}
			wp_send_json_success( [
				'user_id' => $user_id, 'member_label' => $label, 'assessment' => $assessment,
				'label' => $map[ $assessment ]['label'] ?? $assessment, 'dates' => self::get_final_dates( $assessment, $email ),
			] );
		}

		public static function ajax_get_extremes() {
			check_ajax_referer( 'bmf_qa_nonce', 'nonce' );
			if ( ! is_user_logged_in() ) {
				wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
			}
			$assessment = strtolower( sanitize_key( $_POST['assessment'] ?? '' ) );
			$date       = self::normalize_date( wp_unslash( $_POST['date'] ?? '' ) );
			$threshold  = self::normalize_threshold( $_POST['threshold'] ?? 0.75 );
			$direction  = isset( $_POST['direction'] ) ? self::normalize_direction( (string) $_POST['direction'] ) : '';
			$map        = self::assessments();
			if ( empty( $map[ $assessment ] ) ) {
				wp_send_json_error( [ 'message' => 'Unknown assessment: ' . $assessment ], 400 );
			}
			if ( $date === '' ) {
				wp_send_json_error( [ 'message' => 'Invalid date.' ], 400 );
			}
			if ( $direction === '' ) {
				$direction = self::normalize_direction( $map[ $assessment ]['direction'] ?? 'low_better' );
			}
			list( $user_id, $email, $label ) = self::resolve_user_from_request();
			if ( ! $user_id ) {
				wp_send_json_error( [ 'message' => 'No WordPress user found for that email.' ], 404 );
			}
			if ( ! self::can_view_subject( $user_id ) ) {
				wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
			}
			$data = self::build_extremes( $user_id, $assessment, $date, $direction, $threshold );
			$data['member_label'] = $label;
			$data['user_id']      = $user_id;
			wp_send_json_success( $data );
		}
	}

	BMF_QA_Extremes_Shortcodes::init();
}
