<?php
/**
 * Breathermae Forms – Generic Form Info Shortcode
 * - Reads Title/Description from {prefix}bm_forms (aka ULS_BM_FORMS)
 * - Resolves by ID or slug
 * - Safe in Elementor editor (bails out)
 */

if ( ! defined('ABSPATH') ) { exit; }

if ( ! class_exists('BMF_Form_Info_Shortcodes') ) {

    class BMF_Form_Info_Shortcodes {

        public static function init() {
            add_shortcode('bmf_form_info', [__CLASS__, 'shortcode_form_info']);
        }

        /**
         * Bail out helper for Elementor editor (uses global helper if present).
         */
        private static function should_bail_for_editor(): bool {
            // Allow global override: return false to keep shortcodes running in editor
            $disable = apply_filters('bmf/shortcodes/disable_in_elementor', true);
            if (!$disable) return false;

            return function_exists('bmf_in_elementor_editor') && bmf_in_elementor_editor();
        }

        /**
         * [bmf_form_info form="slug|id" field="Title|Description" default="" autop="0" max_chars="0"]
         */
        public static function shortcode_form_info($atts) {
            $atts = shortcode_atts([
                'form'      => '',
                'field'     => '',
                'default'   => '',
                'autop'     => '0',
                'max_chars' => '0',
            ], $atts, 'bmf_form_info');

            if (self::should_bail_for_editor()) {
                return ''; // keep Elementor fast
            }

            // NEW: resolve the target form from atts or querystring
            $formResolved = function_exists('bmf_resolve_form_from_atts_or_query')
                ? bmf_resolve_form_from_atts_or_query((string)$atts['form'])
                : trim((string)$atts['form']);

            $fieldRaw = trim((string)$atts['field']);
            if ($formResolved === '' || $fieldRaw === '') {
                return (string)$atts['default'];
            }

            // Normalize field name (Title/Description only)
            $fieldKey   = strtolower($fieldRaw);
            $wantedCols = [];
            switch ($fieldKey) {
                case 'title':       $wantedCols = ['Title', 'title']; break;
                case 'description': $wantedCols = ['Description', 'description']; break;
                default: return (string)$atts['default'];
            }

            global $wpdb;
            $table = $wpdb->prefix . 'bm_forms';

            // Resolve the DB row by ID (numeric) or slug (string)
            if (ctype_digit($formResolved)) {
                $row = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int)$formResolved),
                    ARRAY_A
                );
            } else {
                $slug = sanitize_title($formResolved);
                $row  = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug),
                    ARRAY_A
                );
            }

            if (!$row) {
                return (string)$atts['default'];
            }

            // Prefer the first existing matching column
            $value = '';
            foreach ($wantedCols as $col) {
                if (array_key_exists($col, $row) && $row[$col] !== null) {
                    $value = (string) $row[$col];
                    break;
                }
            }
            if ($value === '') {
                return (string)$atts['default'];
            }

            // Truncate (word-safe)
            $max = max(0, (int)$atts['max_chars']);
            if ($max > 0) {
                $value = self::truncate_word_safe($value, $max);
            }

            // autop or simple nl2br with escaping
            $out = nl2br(esc_html($value));
            if ((int)$atts['autop'] === 1) {
                $out = wpautop(esc_html($value));
            }
            return $out;
        }

        private static function truncate_word_safe(string $text, int $limit): string {
            if ($limit <= 0 || mb_strlen($text) <= $limit) {
                return $text;
            }
            $cut = mb_substr($text, 0, $limit);
            $space = mb_strrpos($cut, ' ');
            if ($space !== false && $space >= $limit - 20) {
                $cut = mb_substr($cut, 0, $space);
            }
            return rtrim($cut) . '…';
        }
    }

    BMF_Form_Info_Shortcodes::init();
}
