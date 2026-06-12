<?php
if (!defined('ABSPATH')) exit;

/**
 * RSI Scorer (R11 / R12)
 *
 * - Computes the domain score for RSI forms (11, 12) as the mean of section scores (0..1) * 100.
 * - Delegates writing and lifecycle (open row / finalize) to BMF_RSI_Saver:
 *     - ensure a single OPEN row per user,
 *     - write R11 or R12 into that row,
 *     - finalize (is_final=1,current_flag=0) when both R11 and R12 are present.
 *
 * Fallback:
 * - If BMF_RSI_Saver is not available, the scorer falls back to the legacy date-based upsert
 *   using (user_email, results_date) and will self-finalize when both domains are present.
 */
class BMF_RSI_Scorer {

  /**
   * @param int $response_id The just-submitted response ID.
   * @return array ['ok'=>bool,'updated'=>bool,'skipped_reason'=>string|null,'error'=>string|null]
   */
  public static function update_form_domain_score($response_id) {
    global $wpdb;
    $p   = $wpdb->prefix;
    $usr = $wpdb->users;

    // --- Load response context ---
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT r.id AS response_id, r.user_id, r.form_id, r.submitted_at, u.user_email
       FROM {$p}bm_responses r
       LEFT JOIN {$usr} u ON u.ID = r.user_id
       WHERE r.id = %d
       LIMIT 1",
      $response_id
    ));
    if (!$row) {
      return ['ok'=>false, 'updated'=>false, 'skipped_reason'=>'response_not_found', 'error'=>null];
    }
    if (empty($row->submitted_at)) {
      return ['ok'=>true, 'updated'=>false, 'skipped_reason'=>'not_submitted', 'error'=>null];
    }

    $fid = (int) $row->form_id;
    if (!in_array($fid, [11, 12], true)) {
      return ['ok'=>true, 'updated'=>false, 'skipped_reason'=>'form_not_rsi', 'error'=>null];
    }

    // --- Pull section scores (0..1) for this response ---
    if ($fid === 12) {
        // Exclude Section 6 (role r12_s6) from the R12 aggregate
        $scores = $wpdb->get_col($wpdb->prepare(
            "SELECT ss.score
            FROM {$p}bm_section_scores ss
            JOIN {$p}bm_form_sections s ON s.id = ss.section_id
            WHERE ss.response_id = %d
              AND s.form_id = %d
              AND (s.formula_meta IS NULL
                    OR s.formula_meta = ''
                    OR JSON_EXTRACT(s.formula_meta, '$.rsi_role') IS NULL
                    OR JSON_EXTRACT(s.formula_meta, '$.rsi_role') <> 'r12_s6')",
            $response_id, $fid
        ));
    } else {
        $scores = $wpdb->get_col($wpdb->prepare(
            "SELECT ss.score
            FROM {$p}bm_section_scores ss
            JOIN {$p}bm_form_sections s ON s.id = ss.section_id
            WHERE ss.response_id = %d
              AND s.form_id = %d",
            $response_id, $fid
        ));
    }


    // Compute 0..100 domain score
    if (empty($scores) || !is_array($scores)) {
        return [
            'ok' => true,
            'updated' => false,
            'skipped_reason' => 'no_scores',
            'error' => null
        ];
    }

    $avg  = array_sum(array_map('floatval', $scores)) / count($scores);

    $norm = round($avg * 100, 2);

    // ----------------------------
    // Preferred path: use RSI Saver
    // ----------------------------
    if (class_exists('BMF_RSI_Saver')) {
      $domain = ($fid === 11) ? 'R11' : 'R12';
      // Saver manages: single open row -> write domain -> finalize when both present
      BMF_RSI_Saver::save_domain((int) $row->user_id, $domain, $norm); // flips flags when both exist [2](https://breathermae-my.sharepoint.com/personal/jeff_breathermae_com/Documents/Microsoft%20Copilot%20Chat%20Files/class-bmf-section-scorer.php)
      return ['ok'=>true, 'updated'=>true, 'skipped_reason'=>null, 'error'=>null];
    }

    // --------------------------------------------------------------------
    // Fallback path (if Saver is unavailable): date-based upsert + finalize
    // --------------------------------------------------------------------
    $email = isset($row->user_email) ? trim($row->user_email) : '';
    if ($email === '') {
      return ['ok'=>true, 'updated'=>false, 'skipped_reason'=>'no_user_email', 'error'=>null];
    }
    $results_date = substr($row->submitted_at, 0, 10);

    $table = $p . 'bm_rsi_results';
    $col   = ($fid === 11) ? 'R11' : 'R12';

    // Update, else insert
    $updated = $wpdb->update(
      $table,
      [ $col => $norm, 'updated_at' => current_time('mysql', 1) ],
      [ 'user_email' => $email, 'results_date' => $results_date ],
      [ '%f', '%s' ], [ '%s', '%s' ]
    );
    if ($updated === false) {
      return ['ok'=>false, 'updated'=>false, 'skipped_reason'=>null, 'error'=>$wpdb->last_error];
    }
    if ($updated === 0) {
      $ins = $wpdb->insert(
        $table,
        [
          'user_email'  => $email,
          'current_flag'=> 1,
          'is_final'    => 0,
          'results_date'=> $results_date,
          'updated_at'  => current_time('mysql', 1),
          $col          => $norm,
        ],
        ['%s','%d','%d','%s','%s','%f']
      );
      if ($ins === false) {
        return ['ok'=>false, 'updated'=>false, 'skipped_reason'=>null, 'error'=>$wpdb->last_error];
      }
    }

    // Finalize when both domains present (fallback mode)
    $row2 = $wpdb->get_row($wpdb->prepare(
      "SELECT R11, R12
       FROM {$table}
       WHERE user_email = %s AND results_date = %s
       LIMIT 1",
      $email, $results_date
    ), ARRAY_A);
    if ($row2 && $row2['R11'] !== null && $row2['R12'] !== null && $row2['R11'] !== '' && $row2['R12'] !== '') {
      $wpdb->update(
        $table,
        [ 'current_flag' => 0, 'updated_at' => current_time('mysql', 1) ],
        [ 'user_email' => $email, 'results_date' => $results_date ],
        [ '%d','%d','%s' ],
        [ '%s','%s' ]
      );
      do_action('bmf_rsi_results_finalized', (int) $row->user_id);
    }

    return ['ok'=>true, 'updated'=>true, 'skipped_reason'=>null, 'error'=>null];
  }
}