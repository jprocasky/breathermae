<?php
// includes/class-bmf-views.php
if (!defined('ABSPATH')) exit;

class BMF_Views {

    /**
     * Return list of form slugs by status (default: published).
     */
    public static function get_form_slugs_by_status($status = 'published') {
        global $wpdb;
        $p = $wpdb->prefix;
        $sql = $wpdb->prepare("SELECT slug FROM {$p}bm_forms WHERE status = %s ORDER BY slug", $status);
        return $wpdb->get_col($sql) ?: [];
    }

    /**
     * Create/replace the long (tidy) view:
     *   {$prefix}vw_bm_section_scores_long
     * Columns:
     *   form_slug, user_email, response_id, submitted_date, section_id, section_title,
     *   section_order, section_score, score_created_at
     */
    public static function create_long_view() {
        global $wpdb;
        $p   = $wpdb->prefix;
        $usr = $wpdb->users;

        $sql = "
            CREATE OR REPLACE VIEW `{$p}vw_bm_section_scores_long` AS
            SELECT
              f.slug               AS form_slug,
              u.user_email         AS user_email,
              r.id                 AS response_id,
              DATE(r.submitted_at) AS submitted_date,
              s.id                 AS section_id,
              s.title              AS section_title,
              s.order_index        AS section_order,
              ss.score             AS section_score,
              ss.created_at        AS score_created_at
            FROM `{$p}bm_section_scores` ss
            JOIN `{$p}bm_responses`      r ON r.id = ss.response_id
            JOIN `{$p}bm_form_sections`  s ON s.id = ss.section_id
            JOIN `{$p}bm_forms`          f ON f.id = r.form_id
            LEFT JOIN `{$usr}`           u ON u.ID = r.user_id
            WHERE r.submitted_at IS NOT NULL
        ";

        $ok = $wpdb->query($sql);
        return [
            'ok'    => $ok !== false,
            'view'  => "{$p}vw_bm_section_scores_long",
            'error' => $ok === false ? $wpdb->last_error : null,
        ];
    }

    /**
     * Create/replace a pivot view for one form (one row per user/submission).
     * View name: {$prefix}vw_bm_{slug}_pivot
     * Columns: form_slug, user_email, response_id, submitted_date, s{section_id}...
     */
    /**
     * Create/replace a pivot view for one form (one row per user/submission).
     * View name: {$prefix}vw_bm_{slug}_pivot
     * Columns: form_slug, user_email, response_id, submitted_date, s{section_id}..., total, average
     */
    public static function create_pivot_view_for_form($form_slug) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $usr = $wpdb->users;

        // Get section IDs in display order for stable column ordering
        $sections = $wpdb->get_col($wpdb->prepare(
            "SELECT s.id
            FROM {$p}bm_form_sections s
            JOIN {$p}bm_forms f ON f.id = s.form_id
            WHERE f.slug = %s
            ORDER BY s.order_index, s.id",
            $form_slug
        ));

        if (empty($sections)) {
            return [
                'ok'    => false,
                'view'  => null,
                'error' => 'No sections found for form slug: ' . $form_slug,
            ];
        }

        // Build CASE columns for sections
        $cols = [];
        foreach ($sections as $sid) {
            $sid  = intval($sid);
            $cols[] = "MAX(CASE WHEN s.id = {$sid} THEN ss.score END) AS `s{$sid}`";
        }

        // Add TOTAL column (numeric-only sum from choice_value)
        $cols[] = "MAX(rt.total_score) AS total";

        // Add AVERAGE column: arithmetic mean of all section scores (s{ID} columns)
        // Uses fixed denominator = number of sections in the form for consistency.
        // Missing section scores are treated as 0.
        if (!empty($sections)) {
            $section_sum_parts = [];
            foreach ($sections as $sid) {
                $sid = intval($sid);
                $section_sum_parts[] = "COALESCE(MAX(CASE WHEN s.id = {$sid} THEN ss.score END), 0)";
            }
            $section_count = count($sections);
            $cols[] = "ROUND( (" . implode(' + ', $section_sum_parts) . ") / {$section_count} , 2) AS average";
        } else {
            $cols[] = "NULL AS average";
        }

        $col_sql = implode(",\n              ", $cols);

        // Safe pieces
        $safe_slug_for_name = preg_replace('/[^a-z0-9_]/i', '_', $form_slug);
        $view_name = "{$p}vw_bm_{$safe_slug_for_name}_pivot";
        $slug_sql  = esc_sql($form_slug);

        // Full SQL with pre-aggregated totals
        $sql = "
            CREATE OR REPLACE VIEW `{$view_name}` AS
            SELECT
            f.slug               AS form_slug,
            u.user_email         AS user_email,
            r.id                 AS response_id,
            DATE(r.submitted_at) AS submitted_date,
            {$col_sql}
            FROM `{$p}bm_section_scores` ss
            JOIN `{$p}bm_responses`      r  ON r.id = ss.response_id
            JOIN `{$p}bm_form_sections`  s  ON s.id = ss.section_id
            JOIN `{$p}bm_forms`          f  ON f.id = r.form_id
            LEFT JOIN `{$usr}`           u  ON u.ID = r.user_id

            -- Pre-aggregated numeric total from choice_value
            LEFT JOIN (
                SELECT 
                    response_id,
                    SUM(
                        CASE 
                            WHEN choice_value REGEXP '^-?[0-9]+(\\\.[0-9]+)?$' 
                            THEN CAST(choice_value AS DECIMAL(10,4))
                            ELSE 0
                        END
                    ) AS total_score
                FROM {$p}bm_response_items
                GROUP BY response_id
            ) rt ON rt.response_id = r.id

            WHERE r.submitted_at IS NOT NULL
            AND f.slug = '{$slug_sql}'

            GROUP BY f.slug, u.user_email, r.id, DATE(r.submitted_at)
        ";

        $ok = $wpdb->query($sql);

        return [
            'ok'    => $ok !== false,
            'view'  => $view_name,
            'error' => $ok === false ? $wpdb->last_error : null,
        ];
    }
    
    /**
     * Create all pivot views for published forms.
     */
    public static function create_all_pivot_views($status = 'published') {
        $slugs = self::get_form_slugs_by_status($status);
        $results = [];
        foreach ($slugs as $slug) {
            $results[$slug] = self::create_pivot_view_for_form($slug);
        }
        return $results;
    }

    /**
     * Convenience: build long view + all pivot views.
     */
    public static function create_all_views($status = 'published') {
        $long   = self::create_long_view();
        $pivots = self::create_all_pivot_views($status);
        return ['long' => $long, 'pivots' => $pivots];
    }
}
