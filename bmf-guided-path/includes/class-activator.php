<?php
/**
 * Activation: create tables and seed initial steps.
 *
 * @package BMF_Guided_Path
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Guided_Path_Activator {

	const DB_VERSION = '1.1.0';

	public static function activate() {
		self::create_tables();
		self::seed_initial_steps();
		update_option( 'bmf_guided_path_db_version', self::DB_VERSION );
	}

	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Step definitions (admin-editable).
		$table = $wpdb->prefix . 'bm_workflow_steps';
		$sql   = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			sort_order INT NOT NULL DEFAULT 100,
			title VARCHAR(255) NOT NULL DEFAULT '',
			description TEXT NULL,
			conditions LONGTEXT NULL,
			destination VARCHAR(255) NOT NULL DEFAULT '',
			anchor VARCHAR(100) NULL,
			button_label VARCHAR(100) NULL,
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			auto_redirect TINYINT(1) NOT NULL DEFAULT 0,
			notes TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_sort (sort_order),
			KEY idx_enabled (enabled)
		) {$charset};";
		dbDelta( $sql );

		// History of presented / clicked steps.
		$table = $wpdb->prefix . 'bm_workflow_history';
		$sql   = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			step_id BIGINT UNSIGNED NULL,
			action VARCHAR(32) NOT NULL DEFAULT 'presented',
			tags_snapshot LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user (user_id),
			KEY idx_step (step_id),
			KEY idx_created (created_at)
		) {$charset};";
		dbDelta( $sql );

		// Cached current state per user.
		$table = $wpdb->prefix . 'bm_workflow_user_state';
		$sql   = "CREATE TABLE {$table} (
			user_id BIGINT UNSIGNED NOT NULL,
			current_step_id BIGINT UNSIGNED NULL,
			last_evaluated DATETIME NULL,
			last_action_at DATETIME NULL,
			PRIMARY KEY  (user_id),
			KEY idx_step (current_step_id)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Seed (or replace) the workflow steps.
	 * On first activation seeds if empty. Call with $force = true to replace all rows.
	 */
	public static function seed_initial_steps( $force = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bm_workflow_steps';

		if ( ! $force ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			if ( $count > 0 ) {
				return;
			}
		} else {
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}

		$steps = self::get_seed_steps();

		foreach ( $steps as $step ) {
			$wpdb->insert(
				$table,
				array(
					'sort_order'  => $step['sort_order'],
					'title'       => $step['title'],
					'description' => $step['description'],
					'conditions'  => $step['conditions'],
					'destination' => $step['destination'],
					'anchor'      => $step['anchor'],
					'enabled'     => 1,
					'notes'       => $step['notes'],
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
		}
	}

	/**
	 * Expanded step definitions using live tag names.
	 * Priority: lowest sort_order evaluated first (first match wins).
	 */
	protected static function get_seed_steps() {
		return array(
			// --- Results ready (high priority so new results surface first) ---
			array(
				'sort_order'  => 10,
				'title'       => 'View your RSI results',
				'description' => 'Your RSI results are ready. See your full narrative and scores.',
				'conditions'  => wp_json_encode( array(
					'all_present' => array( 'RSI_COMPLETE', 'RSI11', 'RSI12' ),
				) ),
				'destination' => '/rsi-results',
				'anchor'      => 'narrative',
				'notes'       => 'RSI complete and both child tags still present (results available).',
			),
			array(
				'sort_order'  => 15,
				'title'       => 'View your Rapid 8 Pillars results',
				'description' => 'Your Rapid 8 Pillars assessment is complete. Review your results.',
				'conditions'  => wp_json_encode( array(
					'all_present' => array( 'RAPID8' ),
				) ),
				'destination' => '/pillars-start',
				'anchor'      => '',
				'notes'       => 'Immediate feedback path for Rapid 8.',
			),
			array(
				'sort_order'  => 20,
				'title'       => 'View your Key Life Essentials',
				'description' => 'Your Key Life Essentials are complete. Review your personal choices.',
				'conditions'  => wp_json_encode( array(
					'all_present' => array( 'KEY_COMPLETE' ),
				) ),
				'destination' => '/key-essentials',
				'anchor'      => '',
				'notes'       => 'Adjust destination to the actual Key results page.',
			),
			array(
				'sort_order'  => 25,
				'title'       => 'View your Complete 8 Pillars results',
				'description' => 'Your full 8 Pillars assessment is complete. See your detailed results.',
				'conditions'  => wp_json_encode( array(
					'all_present' => array( '9_COMPLETE' ),
				) ),
				'destination' => '/8-pillars-results',
				'anchor'      => '',
				'notes'       => 'Full (paid) 8 Pillars.',
			),
			array(
				'sort_order'  => 30,
				'title'       => 'View your BSI results',
				'description' => 'Your BSI results are ready. Review your biological strain indicators.',
				'conditions'  => wp_json_encode( array(
					'all_present' => array( 'BSI_COMPLETE' ),
				) ),
				'destination' => '/bsi-results',
				'anchor'      => '',
				'notes'       => 'BSI complete.',
			),
			array(
				'sort_order'  => 35,
				'title'       => 'View your BioVoicePrint results',
				'description' => 'New BioVoicePrint results are available.',
				'conditions'  => wp_json_encode( array(
					'all_present' => array( 'BVP_READY' ),
				) ),
				'destination' => '/biovoice-results',
				'anchor'      => '',
				'notes'       => 'Ongoing; no permanent complete tag.',
			),

			// --- Due / available assessments (membership-gated where needed) ---
			array(
				'sort_order'  => 40,
				'title'       => 'Complete Rapid 8 Pillars',
				'description' => 'Rapid 8 Pillars is available. Finish it for immediate lifestyle insights.',
				'conditions'  => wp_json_encode( array(
					'none_present' => array( 'RAPID8' ),
					'any_present'  => array( 'WELLNESS', 'REGISTERED', 'ART', 'S360' ),
				) ),
				'destination' => '/welcomesurveys',
				'anchor'      => '',
				'notes'       => 'Due when RAPID8 is absent (first time or reset).',
			),
			array(
				'sort_order'  => 45,
				'title'       => 'Complete RSI',
				'description' => 'RSI is available. Complete both sections for your readiness results.',
				'conditions'  => wp_json_encode( array(
					'none_present' => array( 'RSI_COMPLETE' ),
					'any_present'  => array( 'WELLNESS', 'REGISTERED', 'ART', 'S360' ),
				) ),
				'destination' => '/welcomesurveys',
				'anchor'      => 'rsi',
				'notes'       => 'Not yet completed (or fully reset). Partial handled below.',
			),
			array(
				'sort_order'  => 50,
				'title'       => 'Finish your RSI',
				'description' => 'You have started RSI. Complete the remaining section.',
				'conditions'  => wp_json_encode( array(
					'any_present'  => array( 'RSI11', 'RSI12' ),
					'none_present' => array( 'RSI_COMPLETE' ),
				) ),
				'destination' => '/welcomesurveys',
				'anchor'      => 'rsi',
				'notes'       => 'Partial RSI.',
			),
			array(
				'sort_order'  => 55,
				'title'       => 'Complete Key Life Essentials',
				'description' => 'Key Life Essentials are available for Art of Wellness and 360 members.',
				'conditions'  => wp_json_encode( array(
					'any_present'  => array( 'ART', 'S360' ),
					'none_present' => array( 'KEY_COMPLETE' ),
				) ),
				'destination' => '/key-essentials',
				'anchor'      => '',
				'notes'       => 'Due for ART or S360 when KEY_COMPLETE is absent.',
			),
			array(
				'sort_order'  => 60,
				'title'       => 'Complete the full 8 Pillars',
				'description' => 'Your complete 8 Pillars assessment is available.',
				'conditions'  => wp_json_encode( array(
					'any_present'  => array( 'ART', 'S360' ),
					'none_present' => array( '9_COMPLETE' ),
				) ),
				'destination' => '/8-pillars',
				'anchor'      => '',
				'notes'       => 'Full pillars for ART/S360.',
			),
			array(
				'sort_order'  => 65,
				'title'       => 'Complete BSI',
				'description' => 'BSI is available for 360 Health Intelligence members.',
				'conditions'  => wp_json_encode( array(
					'all_present'  => array( 'S360' ),
					'none_present' => array( 'BSI_COMPLETE' ),
				) ),
				'destination' => '/bsi',
				'anchor'      => '',
				'notes'       => 'BSI only for S360.',
			),
			array(
				'sort_order'  => 70,
				'title'       => 'Start or continue BioVoicePrint',
				'description' => 'BioVoicePrint is available. Record a session to build your voice baseline.',
				'conditions'  => wp_json_encode( array(
					'all_present'  => array( 'S360' ),
					'none_present' => array( 'BVP_READY' ),
				) ),
				'destination' => '/biovoice',
				'anchor'      => '',
				'notes'       => 'Ongoing; surfaces when no ready results yet.',
			),

			// --- Membership / orientation ---
			array(
				'sort_order'  => 80,
				'title'       => 'Explore Art of Wellness',
				'description' => 'Unlock Key Life Essentials and the full 8 Pillars with Art of Wellness.',
				'conditions'  => wp_json_encode( array(
					'all_present'  => array( 'RAPID8', 'RSI_COMPLETE' ),
					'none_present' => array( 'ART', 'S360' ),
				) ),
				'destination' => '/memberships',
				'anchor'      => '',
				'notes'       => 'Upgrade nudge after complimentary assessments.',
			),
			array(
				'sort_order'  => 85,
				'title'       => 'Explore your member home',
				'description' => 'You have completed the available assessments for your membership. Review results and stay current.',
				'conditions'  => wp_json_encode( array(
					'any_present' => array( 'REGISTERED', 'WELLNESS', 'ART', 'S360' ),
				) ),
				'destination' => '/member-home',
				'anchor'      => '',
				'notes'       => 'Low-priority catch-all once specific steps no longer match.',
			),
			array(
				'sort_order'  => 90,
				'title'       => 'Complete your wellness profile',
				'description' => 'Answer a few quick questions so we can guide you to the right starting assessment.',
				'conditions'  => wp_json_encode( array(
					'none_present' => array( 'WELLNESS' ),
					'any_present'  => array( 'REGISTERED' ),
				) ),
				'destination' => '/wellness-profile',
				'anchor'      => '',
				'notes'       => 'Normal registration that has not done soft flow.',
			),
		);
	}
}
