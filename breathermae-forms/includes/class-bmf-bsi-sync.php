<?php
// includes/class-bmf-bsi-sync.php
if (!defined('ABSPATH')) exit;

class BMF_BSI_Sync {

    /**
     * Seed a row into bm_bsi_results for first 9 forms when a submission is finalized.
     * ALSO computes the overall per-form score (F1..F9) using the 30/30/40 rule and
     * updates it in the same row (user_email, DATE(submitted_at)).
     *
     * Weight rule:
     *   - The section with the HIGHEST section_id => 40%;
     *   - The other two => 30% each.
     *
     * Assumes section scores are already normalized consistently (e.g., 0..1 or 0..100).
     *
     * @param int $response_id The just-submitted response ID.
     * @return array [
     *   'ok'              => bool,
     *   'inserted'        => bool,             // seed newly inserted (true) or already existed (false)
     *   'overall_updated' => bool,             // overall F* column updated/inserted
     *   'overall_value'   => float|null,       // the computed overall value if calculated
     *   'skipped_reason'  => string|null,
     *   'error'           => string|null
     * ]
     */
    public static function maybe_seed_for_submission($response_id) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $usr = $wpdb->users;

        // --- 1) Get response + form + user info (needs submitted_at already set) ---
        $sql = $wpdb->prepare("
            SELECT r.id AS response_id, r.user_id, r.form_id, r.submitted_at,
                   f.slug AS form_slug, u.user_email
              FROM {$p}bm_responses r
              JOIN {$p}bm_forms     f ON f.id = r.form_id
              LEFT JOIN {$usr}      u ON u.ID = r.user_id
             WHERE r.id = %d
             LIMIT 1
        ", $response_id);
        $row = $wpdb->get_row($sql);
        if (!$row) {
            return ['ok'=>false, 'inserted'=>false, 'overall_updated'=>false, 'overall_value'=>null, 'skipped_reason'=>'response_not_found', 'error'=>null];
        }

        // Only run after a proper submission (defensive)
        if (empty($row->submitted_at)) {
            return ['ok'=>true, 'inserted'=>false, 'overall_updated'=>false, 'overall_value'=>null, 'skipped_reason'=>'not_submitted', 'error'=>null];
        }

        // Only for the first 9 forms (by numeric ID as requested)
        $form_id = intval($row->form_id);
        if ($form_id > 9) {
            return ['ok'=>true, 'inserted'=>false, 'overall_updated'=>false, 'overall_value'=>null, 'skipped_reason'=>'form_id_gt_9', 'error'=>null];
        }

        // Get date portion of submitted_at (DB timezone – consistent with your views)
        $results_date = substr($row->submitted_at, 0, 10); // 'YYYY-MM-DD'

        // We require a user email to key the row. If empty, skip (or fetch from elsewhere if you store emails differently).
        $user_email = isset($row->user_email) ? trim($row->user_email) : '';
        if ($user_email === '') {
            return ['ok'=>true, 'inserted'=>false, 'overall_updated'=>false, 'overall_value'=>null, 'skipped_reason'=>'no_user_email', 'error'=>null];
        }

        // --- 2) Seed if missing ---
        $table = $p . 'bm_bsi_results';
        // (If UNIQUE(user_email, results_date) exists, this INSERT IGNORE is concurrency-safe)
        $insert_sql = $wpdb->prepare("
            INSERT IGNORE INTO {$table} (user_email, results_date)
            VALUES (%s, %s)
        ", $user_email, $results_date);
        $res = $wpdb->query($insert_sql);
        if ($res === false) {
            return ['ok'=>false, 'inserted'=>false, 'overall_updated'=>false, 'overall_value'=>null, 'skipped_reason'=>null, 'error'=>$wpdb->last_error];
        }
        // INSERT IGNORE returns 0 if duplicate existed, 1 if inserted
        $seed_inserted = ($res > 0);

        // --- 3) Compute overall per-form score using 30/30/40 rule ---
        // Fetch all section scores for this response
        $sec_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ss.section_id, ss.score
               FROM {$p}bm_section_scores ss
              WHERE ss.response_id = %d
              ORDER BY ss.section_id ASC",
            $response_id
        ));

        if (!$sec_rows || count($sec_rows) < 3) {
            // Not enough section scores yet; return after seed
            return [
                'ok'              => true,
                'inserted'        => $seed_inserted,
                'overall_updated' => false,
                'overall_value'   => null,
                'skipped_reason'  => 'insufficient_section_scores',
                'error'           => null
            ];
        }

        // Build arrays/maps for weighting
        $section_ids = array_map(function($r){ return (int)$r->section_id; }, $sec_rows);
        $max_sid     = max($section_ids);

        // Map: section_id => score
        $score_map = [];
        foreach ($sec_rows as $r) {
            $score_map[(int)$r->section_id] = (float)$r->score;
        }

        // Pick two other sections besides the max_sid. Use the two smallest IDs for determinism.
        $others = array_values(array_diff($section_ids, [$max_sid]));
        sort($others, SORT_NUMERIC);
        if (count($others) < 2) {
            // Edge: still not enough distinct sections
            return [
                'ok'              => true,
                'inserted'        => $seed_inserted,
                'overall_updated' => false,
                'overall_value'   => null,
                'skipped_reason'  => 'insufficient_other_sections',
                'error'           => null
            ];
        }

        $sid_a = $others[0];
        $sid_b = $others[1];

        $score_max = isset($score_map[$max_sid]) ? $score_map[$max_sid] : 0.0;
        $score_a   = isset($score_map[$sid_a])   ? $score_map[$sid_a]   : 0.0;
        $score_b   = isset($score_map[$sid_b])   ? $score_map[$sid_b]   : 0.0;

        // Weighted sum: 30% + 30% + 40%
        $overall = (0.30 * $score_a) + (0.30 * $score_b) + (0.40 * $score_max);

        // Column name F1..F9
        $col = 'F' . $form_id;

        // --- 4) Update (or insert) the overall column in bm_bsi_results for this user/day ---
        $updated = $wpdb->update(
            $table,
            [ $col => $overall ],
            [ 'user_email' => $user_email, 'results_date' => $results_date ],
            [ '%f' ],
            [ '%s', '%s' ]
        );
        if ($updated === false) {
            return [
                'ok'              => false,
                'inserted'        => $seed_inserted,
                'overall_updated' => false,
                'overall_value'   => null,
                'skipped_reason'  => null,
                'error'           => $wpdb->last_error
            ];
        }

        // If no row was affected (e.g., seed somehow not present), insert a new row carrying this F* value.
        if ($updated === 0) {
            $ins = $wpdb->insert(
                $table,
                [
                    'user_email'   => $user_email,
                    'results_date' => $results_date,
                    $col           => $overall
                ],
                [ '%s', '%s', '%f' ]
            );
            if ($ins === false) {
                return [
                    'ok'              => false,
                    'inserted'        => $seed_inserted,
                    'overall_updated' => false,
                    'overall_value'   => null,
                    'skipped_reason'  => null,
                    'error'           => $wpdb->last_error
                ];
            }
            return [
                'ok'              => true,
                'inserted'        => $seed_inserted,
                'overall_updated' => true,
                'overall_value'   => $overall,
                'skipped_reason'  => null,
                'error'           => null
            ];
        }

        // Normal path: updated an existing seeded row
        return [
            'ok'              => true,
            'inserted'        => $seed_inserted,
            'overall_updated' => true,
            'overall_value'   => $overall,
            'skipped_reason'  => null,
            'error'           => null
        ];
    }
}
