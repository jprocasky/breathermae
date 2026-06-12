<?php
if (!defined('ABSPATH')) exit;

/**
 * BSI Scorer (F1..F9) – open-row lifecycle
 *
 * - Computes per-form (pillar) score for BSI forms (1..9) using the 30/30/40 rule:
 *     the section with the highest section_id -> 40%, the other two -> 30% each.
 * - Delegates writing & lifecycle (open row / finalize) to BMF_BSI_Saver:
 *     ensures a single OPEN row per user, writes Fx, and finalizes when all 9 present.
 */
class BMF_BSI_Scorer {

  /**
   * Compute and persist the per-form (pillar) score for this response if form_id <= 9.
   * @param int $response_id
   * @return array ['ok'=>bool, 'updated'=>bool, 'skipped_reason'=>string|null, 'error'=>string|null]
   */
  public static function update_form_overall_score($response_id) {
    global $wpdb;
    $p   = $wpdb->prefix;
    $usr = $wpdb->users;

    bm_log(__METHOD__ . ' ENTER | response_id=' . (int)$response_id);

    // 1) Load response + user + form
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT r.id AS response_id, r.user_id, r.form_id, r.submitted_at, u.user_email
       FROM {$p}bm_responses r
       LEFT JOIN {$usr} u ON u.ID = r.user_id
       WHERE r.id = %d
       LIMIT 1",
      $response_id
    ));

    if ( ! $row ) {
    bm_log(__METHOD__ . ' ABORT | response not found | response_id=' . (int)$response_id);
      return ['ok'=>false,'updated'=>false,'skipped_reason'=>'response_not_found','error'=>null];
    }

    if ( empty($row->submitted_at) ) {
      bm_log(__METHOD__ . ' SKIP | response not submitted | response_id=' . (int)$response_id);
      return ['ok'=>true,'updated'=>false,'skipped_reason'=>'not_submitted','error'=>null];
    }

    $form_id = (int) $row->form_id;
    if ($form_id < 1 || $form_id > 9) {
      bm_log(__METHOD__ . ' SKIP | form_id out of range | response_id=' . (int)$response_id . ' | form_id=' . $form_id);
      return ['ok'=>true, 'updated'=>false, 'skipped_reason'=>'form_id_out_of_range', 'error'=>null];
    }

    // 2) Fetch section scores for this response
    $scores = $wpdb->get_results($wpdb->prepare(
      "SELECT ss.section_id, ss.score
       FROM {$p}bm_section_scores ss
       WHERE ss.response_id = %d
       ORDER BY ss.section_id ASC",
      $response_id
    ));

    if ( ! $scores || count($scores) < 3 ) {
      bm_log(__METHOD__ .
          ' SKIP | insufficient section scores | count=' .
          (is_array($scores) ? count($scores) : 0)
      );
      return ['ok'=>true,'updated'=>false,'skipped_reason'=>'insufficient_section_scores','error'=>null];
    }

    // 3) 30/30/40 weighting (highest section_id -> 40%; two smallest ids -> 30% each)
    $section_ids = array_map(function($r){ return (int)$r->section_id; }, $scores);
    $max_sid     = max($section_ids);
    $map         = [];
    foreach ($scores as $r) { $map[(int)$r->section_id] = (float)$r->score; }
    $others = array_values(array_diff($section_ids, [$max_sid]));
    sort($others, SORT_NUMERIC);
    if (count($others) < 2) {
      return ['ok'=>true, 'updated'=>false, 'skipped_reason'=>'insufficient_other_sections', 'error'=>null];
    }
    $sid_a   = $others[0];
    $sid_b   = $others[1];
    $s_max   = isset($map[$max_sid]) ? (float)$map[$max_sid] : 0.0;
    $s_a     = isset($map[$sid_a])   ? (float)$map[$sid_a]   : 0.0;
    $s_b     = isset($map[$sid_b])   ? (float)$map[$sid_b]   : 0.0;
    $overall = (0.30 * $s_a) + (0.30 * $s_b) + (0.40 * $s_max); // 0..1 (or 0..100 if your sections are 0..100)

    bm_log(__METHOD__ .
        ' CALL SAVER | user_id=' . (int)$row->user_id .
        ' | form_id=' . $form_id .
        ' | computed_overall=' . $overall
    );

    // 4) Delegate to Saver -> open row + write pillar + finalize on completion
    if ( ! class_exists('BMF_BSI_Saver') ) {
        bm_log(__METHOD__ . ' ABORT | BMF_BSI_Saver missing');
        return [
            'ok' => true,
            'updated' => false,
            'skipped_reason' => 'saver_unavailable',
            'error' => null
        ];
    }

    // This will create or update the OPEN row for this user/form, write the F1..F9 column, and finalize if all 9 present.
    BMF_BSI_Saver::save_pillar(
        (int) $row->user_id,
        (int) $form_id,
        (float) $overall
    );


  }
}