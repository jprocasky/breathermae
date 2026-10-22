<?php
/**
 * Record workflow history events.
 *
 * @package BMF_Guided_Path
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Guided_Path_History {

	/**
	 * Record an action for a user and step.
	 *
	 * @param int    $user_id
	 * @param int    $step_id
	 * @param string $action   presented|clicked|auto_redirected|dismissed
	 * @param array  $tags     Optional snapshot of relevant tags.
	 * @return int|false Insert ID or false.
	 */
	public static function record( $user_id, $step_id, $action = 'presented', $tags = array() ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bm_workflow_history';

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'       => $user_id,
				'step_id'       => $step_id ? (int) $step_id : null,
				'action'        => sanitize_key( $action ),
				'tags_snapshot' => ! empty( $tags ) ? wp_json_encode( $tags ) : null,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			// Touch last_action_at on the state row.
			$state_table = $wpdb->prefix . 'bm_workflow_user_state';
			$wpdb->update(
				$state_table,
				array( 'last_action_at' => current_time( 'mysql' ) ),
				array( 'user_id' => $user_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return $inserted ? $wpdb->insert_id : false;
	}
}
