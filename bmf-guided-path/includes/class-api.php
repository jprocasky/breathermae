<?php
/**
 * Public API for other plugins (primarily member-home).
 *
 * @package BMF_Guided_Path
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Guided_Path_API {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_record_seen' ) );
	}

	/**
	 * If the button URL carried a seen token, record the event and clean the URL.
	 */
	public static function maybe_record_seen() {
		if ( empty( $_GET['bmf_gp_seen'] ) || ! is_user_logged_in() ) {
			return;
		}
		$step_id = (int) $_GET['bmf_gp_seen'];
		$nonce   = sanitize_text_field( wp_unslash( $_GET['bmf_gp_nonce'] ?? '' ) );
		if ( ! $step_id || ! wp_verify_nonce( $nonce, 'bmf_gp_seen_' . $step_id ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bm_workflow_steps';
		$step  = $wpdb->get_row( $wpdb->prepare( "SELECT conditions FROM {$table} WHERE id = %d", $step_id ), ARRAY_A );
		if ( $step ) {
			$conds = json_decode( $step['conditions'] ?? '{}', true );
			$key   = trim( (string) ( $conds['suppress_event_key'] ?? '' ) );
			if ( $key !== '' ) {
				BMF_Guided_Path_Evaluator::record_event( get_current_user_id(), $key );
			}
		}
		$clean = remove_query_arg( array( 'bmf_gp_seen', 'bmf_gp_nonce' ) );
		wp_safe_redirect( $clean );
		exit;
	}

	/**
	 * Get the current matching step for a user, ready for display.
	 *
	 * Returns null if no step matches or user is not logged in.
	 *
	 * @param int $user_id
	 * @return array|null {
	 *     @type int    $id
	 *     @type string $title
	 *     @type string $description
	 *     @type string $url          Full URL including anchor if present.
	 *     @type string $destination
	 *     @type string $anchor
	 * }
	 */
	public static function get_current_step_for_display( $user_id = 0 ) {
		$step = BMF_Guided_Path_Evaluator::get_current_step( $user_id );
		if ( ! $step ) {
			return null;
		}

		$url = self::build_url( $step['destination'], $step['anchor'] );

		$conds = json_decode( $step['conditions'] ?? '{}', true );
		$key   = is_array( $conds ) ? trim( (string) ( $conds['suppress_event_key'] ?? '' ) ) : '';
		if ( $key !== '' ) {
			$url = add_query_arg(
				array(
					'bmf_gp_seen'  => (int) $step['id'],
					'bmf_gp_nonce' => wp_create_nonce( 'bmf_gp_seen_' . (int) $step['id'] ),
				),
				$url
			);
		}

		// Record that the step was presented (lightweight; caller may also record click).
		BMF_Guided_Path_History::record(
			$user_id ? (int) $user_id : get_current_user_id(),
			(int) $step['id'],
			'presented'
		);

		return array(
			'id'           => (int) $step['id'],
			'title'        => $step['title'],
			'description'  => $step['description'],
			'url'          => $url,
			'destination'  => $step['destination'],
			'anchor'       => $step['anchor'],
			'button_label' => isset( $step['button_label'] ) ? (string) $step['button_label'] : '',
		);
	}

	/**
	 * Record that the user clicked the next-step button.
	 *
	 * @param int $user_id
	 * @param int $step_id
	 */
	public static function record_click( $user_id, $step_id ) {
		BMF_Guided_Path_History::record( $user_id, $step_id, 'clicked' );
	}

	/**
	 * Build a full URL from destination + optional anchor.
	 *
	 * @param string $destination
	 * @param string $anchor
	 * @return string
	 */
	protected static function build_url( $destination, $anchor = '' ) {
		$destination = trim( (string) $destination );
		if ( $destination === '' ) {
			return home_url( '/' );
		}

		if ( strpos( $destination, 'http' ) === 0 ) {
			$url = $destination;
		} else {
			$url = home_url( '/' . ltrim( $destination, '/' ) );
		}

		$anchor = trim( (string) $anchor );
		if ( $anchor !== '' ) {
			$url .= '#' . ltrim( $anchor, '#' );
		}

		return $url;
	}
}

/**
 * Convenience function for other plugins.
 *
 * @param int $user_id
 * @return array|null
 */
function bmf_guided_path_get_current_step( $user_id = 0 ) {
	if ( ! class_exists( 'BMF_Guided_Path_API' ) ) {
		return null;
	}
	return BMF_Guided_Path_API::get_current_step_for_display( $user_id );
}
