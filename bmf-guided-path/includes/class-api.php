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
		// Placeholder for future hooks (e.g. on tag change, form submission).
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
