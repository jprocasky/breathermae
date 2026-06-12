<?php
if (!defined('ABSPATH')) exit;

class BMF_Responses {
    public static function ensure_session($user_id, $form) {
        global $wpdb; $forms = $wpdb->prefix.'bm_forms'; $res = $wpdb->prefix.'bm_responses';
        if (is_numeric($form)){
            $form_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $forms WHERE id=%d", intval($form)));
        } else {
            $form_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $forms WHERE slug=%s", $form));
        }
        if (!$form_row) return null;
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $res WHERE user_id=%d AND form_id=%d AND status='in_progress' ORDER BY id DESC LIMIT 1", $user_id, $form_row->id));
        if ($existing) return $existing;
        $wpdb->insert($res, [
            'user_id'=> $user_id,
            'form_id'=> $form_row->id,
            'version'=> intval($form_row->version),
            'status'=> 'in_progress',
        ]);
        return (object) $wpdb->get_row($wpdb->prepare("SELECT * FROM $res WHERE id=%d", $wpdb->insert_id));
    }
}