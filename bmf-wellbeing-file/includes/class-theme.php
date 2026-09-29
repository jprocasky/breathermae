<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Viewer appearance for BMF result chrome.
 * Stored on the logged-in viewer (not the subject member).
 * Key is shared so later plugins can honour the same preference.
 */
class BMF_Wellbeing_Theme {

	public const META_KEY = 'bmf_ui_theme';
	public const DEFAULT  = 'dark';

	public static function normalize( $value ): string {
		$v = strtolower( trim( (string) $value ) );
		return $v === 'light' ? 'light' : self::DEFAULT;
	}

	public static function get( $user_id = 0 ): string {
		$uid = (int) $user_id;
		if ( $uid <= 0 ) {
			$uid = get_current_user_id();
		}
		if ( $uid <= 0 ) {
			return self::DEFAULT;
		}
		$raw = get_user_meta( $uid, self::META_KEY, true );
		return self::normalize( $raw === '' || $raw === false || $raw === null ? self::DEFAULT : $raw );
	}

	public static function set( string $theme, $user_id = 0 ): string {
		$uid = (int) $user_id;
		if ( $uid <= 0 ) {
			$uid = get_current_user_id();
		}
		$theme = self::normalize( $theme );
		if ( $uid > 0 ) {
			update_user_meta( $uid, self::META_KEY, $theme );
		}
		return $theme;
	}
}
