<?php
/**
 * Provider designation logic.
 *
 * Supports two modes:
 * 1. WP Fusion tags (when active and configured)
 * 2. Native capability / explicit list (always available)
 *
 * @package BMF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Calendar_Provider {

	const CAP = 'bmf_calendar_provider';

	/**
	 * Register the capability so it appears in role editors.
	 * Only runs in admin / when needed — avoids touching roles on every front request.
	 */
	public static function register_capability() {
		if ( ! is_admin() ) {
			return;
		}
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP ) ) {
			$role->add_cap( self::CAP );
		}
	}

	/**
	 * Is the given user a Provider for BMF Calendar?
	 *
	 * @param int|null $user_id Defaults to current user.
	 * @return bool
	 */
	public static function is_provider( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$method = self::get_designation_method();

		if ( 'wpfusion' === $method && self::is_wp_fusion_active() ) {
			$has = self::has_provider_tag( $user_id );
			if ( $has ) {
				return true;
			}
			// Fall through to capability/manual as a safety net.
		}

		// Native fallback (also used when method = capability or manual).
		if ( user_can( $user_id, self::CAP ) ) {
			return true;
		}

		// Explicit list from settings.
		$manual = (array) get_option( 'bmf_calendar_manual_providers', array() );
		if ( empty( $manual ) ) {
			return false;
		}
		return in_array( $user_id, array_map( 'intval', $manual ), true );
	}

	/**
	 * Return list of Provider user IDs visible to the current context.
	 *
	 * @return int[]
	 */
	public static function get_provider_ids() {
		$method = self::get_designation_method();

		if ( 'wpfusion' === $method && self::is_wp_fusion_active() ) {
			$by_tag = self::get_providers_by_tag();
			if ( ! empty( $by_tag ) ) {
				return $by_tag;
			}
		}

		// Capability + manual list.
		$ids = array();

		$users = get_users(
			array(
				'capability' => self::CAP,
				'fields'     => 'ID',
			)
		);
		foreach ( (array) $users as $id ) {
			$ids[] = (int) $id;
		}

		$manual = (array) get_option( 'bmf_calendar_manual_providers', array() );
		foreach ( $manual as $id ) {
			$ids[] = (int) $id;
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Providers linked to a specific member (explicit table).
	 *
	 * @param int $member_id
	 * @return int[]
	 */
	public static function get_providers_for_member( $member_id ) {
		global $wpdb;
		$table = BMF_Calendar_DB::provider_member_table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT provider_id FROM {$table} WHERE member_id = %d",
				(int) $member_id
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Current designation method from settings.
	 *
	 * @return string 'wpfusion' | 'capability'
	 */
	public static function get_designation_method() {
		$method = get_option( 'bmf_calendar_provider_method', 'capability' );
		if ( 'wpfusion' === $method && ! self::is_wp_fusion_active() ) {
			return 'capability';
		}
		return in_array( $method, array( 'wpfusion', 'capability' ), true ) ? $method : 'capability';
	}

	public static function is_wp_fusion_active() {
		// Be conservative — only treat as active when the helper is actually callable.
		return function_exists( 'wp_fusion' );
	}

	/**
	 * Parse a shortcode exclude attribute into tokens.
	 * Example: exclude="TEST, QA"
	 *
	 * @param string $raw
	 * @return string[]
	 */
	public static function parse_exclude( $raw ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return array();
		}
		$parts = preg_split( '/\s*,\s*/', $raw );
		return array_values( array_filter( array_map( 'strval', $parts ) ) );
	}

	/**
	 * Should this provider be hidden from member-facing lists?
	 *
	 * WP Fusion mode: excluded if the user has any of the listed tags.
	 * Capability / manual mode: excluded if token matches user ID, login, or nicename.
	 *
	 * @param int      $user_id
	 * @param string[] $tokens
	 * @return bool
	 */
	public static function is_excluded( $user_id, $tokens ) {
		$user_id = (int) $user_id;
		$tokens  = array_filter( array_map( 'strval', (array) $tokens ) );
		if ( ! $user_id || empty( $tokens ) ) {
			return false;
		}

		if ( 'wpfusion' === self::get_designation_method() && self::is_wp_fusion_active() ) {
			try {
				$wpf = wp_fusion();
				if ( is_object( $wpf ) && isset( $wpf->user ) && method_exists( $wpf->user, 'get_tags' ) ) {
					$user_tags = $wpf->user->get_tags( $user_id );
					if ( is_array( $user_tags ) ) {
						$user_tags = array_map( 'strval', $user_tags );
						foreach ( $tokens as $tok ) {
							if ( in_array( $tok, $user_tags, true ) ) {
								return true;
							}
						}
					}
				}
			} catch ( Exception $e ) {
			} catch ( Error $e ) {
			}
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$hay = array(
			(string) $user->ID,
			strtolower( (string) $user->user_login ),
			strtolower( (string) $user->user_nicename ),
		);
		foreach ( $tokens as $tok ) {
			$t = strtolower( $tok );
			if ( in_array( $t, $hay, true ) || in_array( $tok, $hay, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether the user has any of the configured Provider tags.
	 * Fully defensive so a WP Fusion quirk never fatals the page or AJAX login.
	 */
	private static function has_provider_tag( $user_id ) {
		if ( ! function_exists( 'wp_fusion' ) ) {
			return false;
		}

		$tags = (array) get_option( 'bmf_calendar_provider_tags', array() );
		$tags = array_filter( array_map( 'strval', $tags ) );
		if ( empty( $tags ) ) {
			return false;
		}

		try {
			$wpf = wp_fusion();
			if ( ! is_object( $wpf ) || ! isset( $wpf->user ) || ! is_object( $wpf->user ) ) {
				return false;
			}
			if ( ! method_exists( $wpf->user, 'get_tags' ) ) {
				return false;
			}

			$user_tags = $wpf->user->get_tags( $user_id );
			if ( ! is_array( $user_tags ) ) {
				return false;
			}

			// Normalize both sides to strings for comparison (tag names or IDs).
			$user_tags_str = array_map( 'strval', $user_tags );
			foreach ( $tags as $tag ) {
				if ( in_array( (string) $tag, $user_tags_str, true ) ) {
					return true;
				}
			}
		} catch ( Exception $e ) {
			return false;
		} catch ( Error $e ) {
			return false;
		}

		return false;
	}

	/**
	 * Users who have any of the configured Provider tags.
	 */
	private static function get_providers_by_tag() {
		$ids  = array();
		$tags = (array) get_option( 'bmf_calendar_provider_tags', array() );
		$tags = array_filter( array_map( 'strval', $tags ) );
		if ( empty( $tags ) || ! function_exists( 'wp_fusion' ) ) {
			return $ids;
		}

		try {
			$wpf = wp_fusion();
			if ( is_object( $wpf ) && isset( $wpf->user ) && method_exists( $wpf->user, 'get_users_with_tag' ) ) {
				foreach ( $tags as $tag ) {
					$found = $wpf->user->get_users_with_tag( $tag );
					if ( is_array( $found ) ) {
						foreach ( $found as $uid ) {
							$ids[] = (int) $uid;
						}
					}
				}
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		} catch ( Error $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}
}
