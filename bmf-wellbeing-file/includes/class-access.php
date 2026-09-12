<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Wellbeing_Access {

	public static function can_view_subject( int $subject_user_id ): bool {
		if ( ! is_user_logged_in() || $subject_user_id <= 0 ) {
			return false;
		}
		$current_id  = get_current_user_id();
		$selected_id = (int) get_user_meta( $current_id, 'uls_selected_user_id', true );
		$allowed     = ( $current_id === $subject_user_id )
			|| current_user_can( 'manage_options' )
			|| ( $selected_id > 0 && $selected_id === $subject_user_id );
		$allowed = (bool) apply_filters( 'bmf_wellbeing_can_view_subject', $allowed, $subject_user_id, $current_id );
		if ( ! $allowed && is_user_logged_in() ) {
			$allowed = (bool) apply_filters( 'bmf_qa_allow_any_logged_in', false, $subject_user_id, $current_id );
		}
		return $allowed;
	}

	public static function resolve_subject( $user_id = 0, $email = '' ): array {
		$user_id = absint( $user_id );
		$email   = is_string( $email ) ? sanitize_email( $email ) : '';
		if ( ! $user_id && $email && is_email( $email ) ) {
			$u = get_user_by( 'email', $email );
			if ( $u ) {
				$user_id = (int) $u->ID;
			}
		}
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		$user  = $user_id ? get_userdata( $user_id ) : null;
		$label = $user ? ( $user->display_name ?: $user->user_email ) : '';
		$email = $user ? (string) $user->user_email : $email;
		return [ $user_id, $email, $label ];
	}
}
