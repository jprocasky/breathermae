<?php
if (!defined('ABSPATH')) exit;

/**
 * Future-proof section scorer for "new" forms (form_id > 9).
 * Enhanced with range support (Q1:Q10) and sum/Total helpers.
 * Legacy forms (form_id 1-9) will continue with old logic unless formula is defined.
 */
class BMF_Section_Scorer {

  public static function init() {
    add_action('bmf_compute_section_scores', [__CLASS__, 'compute_for_response'], 10, 3);
  }

  protected static function upsert_readiness_score($response_id, $form_id, $user_id, $readiness) {
    global $wpdb;
    $p = $wpdb->prefix;
    $t_responses = $p . 'bm_responses';
    $t_rsi       = $p . 'bm_rsi_results';

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT r.submitted_at, u.user_email
       FROM {$t_responses} r
       LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
       WHERE r.id = %d LIMIT 1",
      (int) $response_id
    ));
    if (!$row || empty($row->submitted_at) || empty($row->user_email)) return;

    $date  = substr($row->submitted_at, 0, 10);
    $email = trim((string) $row->user_email);

    $updated = $wpdb->update(
      $t_rsi,
      ['readiness_score' => (int)$readiness, 'updated_at' => current_time('mysql', 1)],
      ['user_email' => $email, 'results_date' => $date],
      ['%d', '%s'],
      ['%s', '%s']
    );
    if ($updated === 0) {
      $wpdb->insert(
        $t_rsi,
        [
          'user_email'      => $email,
          'current_flag'    => 1,
          'is_final'        => 0,
          'results_date'    => $date,
          'updated_at'      => current_time('mysql', 1),
          'readiness_score' => (int) $readiness,
        ],
        ['%s','%d','%d','%s','%s','%d']
      );
    }
  }

  protected static function upsert_r12s6_score($response_id, $form_id, $user_id, $pct) {
    // ... (unchanged from original)
    global $wpdb;
    $p = $wpdb->prefix;
    $t_responses = $p . 'bm_responses';
    $t_rsi = $p . 'bm_rsi_results';

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT r.submitted_at, u.user_email
       FROM {$t_responses} r
       LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
       WHERE r.id = %d LIMIT 1",
      (int) $response_id
    ));
    if (!$row || empty($row->submitted_at) || empty($row->user_email)) return;

    $date  = substr($row->submitted_at, 0, 10);
    $email = trim((string) $row->user_email);

    $updated = $wpdb->update(
      $t_rsi,
      ['R12_S6' => (float)$pct, 'updated_at' => current_time('mysql', 1)],
      ['user_email' => $email, 'results_date' => $date],
      ['%f', '%s'],
      ['%s', '%s']
    );
    if ($updated === 0) {
      $wpdb->insert(
        $t_rsi,
        [
          'user_email'  => $email,
          'current_flag'=> 1,
          'is_final'    => 0,
          'results_date'=> $date,
          'updated_at'  => current_time('mysql', 1),
          'R12_S6'      => (float)$pct,
        ],
        ['%s','%d','%d','%s','%s','%f']
      );
    }
  }

  public static function compute_for_response( $response_id, $form_id, $user_id ) {
    global $wpdb;
    $p = $wpdb->prefix;

    bm_log(__METHOD__ . ' ENTER | response_id=' . (int)$response_id . ' | form_id=' . (int)$form_id . ' | user_id=' . (int)$user_id);

    $is_bsi = ($form_id >= 1 && $form_id <= 9);

    $response_id = (int) $response_id;
    $user_id     = (int) $user_id;
    if ($response_id <= 0 || $user_id <= 0) {
      bm_log(__METHOD__ . ' ABORT | invalid ids');
      return false;
    }

    $t_sects = $p . 'bm_form_sections';
    $t_qs    = $p . 'bm_questions';
    $t_items = $p . 'bm_response_items';
    $t_ss    = $p . 'bm_section_scores';

    // Fetch sections
    $sections = $wpdb->get_results($wpdb->prepare(
      "SELECT s.id AS section_id, s.order_index, s.formula, s.formula_meta
       FROM {$t_sects} s WHERE s.form_id = %d ORDER BY s.order_index, s.id",
      $form_id
    ), ARRAY_A);

    if (!$sections) {
      bm_log(__METHOD__ . ' ABORT | no sections found');
      return false;
    }

    // Fetch answers
    $answers = $wpdb->get_results($wpdb->prepare(
      "SELECT q.section_id, q.code, ri.choice_value
       FROM {$t_items} ri JOIN {$t_qs} q ON q.id = ri.question_id
       WHERE ri.response_id = %d", $response_id
    ), ARRAY_A);

    // Build by_section map
    $by_section = [];
    foreach ($answers as $r) {
      $answer_section_id = (int) $r['section_id'];
      $code_raw = trim((string) $r['code']);

      if ($is_bsi) {
        if (preg_match('/^\d+_\d+_(\d+)$/', $code_raw, $m)) {
          $qIndex = (int) $m[1];
        } else continue;
      } else {
        if (preg_match('/(\d+)$/', $code_raw, $m)) {
          $qIndex = (int) $m[1];
        } else continue;
      }

      $val = is_numeric($r['choice_value']) ? (float) $r['choice_value'] : null;
      if ($val === null) continue;

      if (!isset($by_section[$answer_section_id])) {
        $by_section[$answer_section_id] = [];
      }
      $by_section[$answer_section_id]['Q' . $qIndex] = $val;
    }

    // Process each section
    foreach ($sections as $s) {
      $section_id = (int) ($s['section_id'] ?? 0);
      $formula    = trim((string) ($s['formula'] ?? ''));
      $metaRaw    = (string) ($s['formula_meta'] ?? '');
      $meta       = json_decode($metaRaw, true);
      $role       = is_array($meta) && isset($meta['rsi_role']) ? strtolower((string)$meta['rsi_role']) : '';

      bm_log(__METHOD__ . ' SECTION START | section_id=' . $section_id . ' | formula=' . ($formula !== '' ? 'yes' : 'no'));

      // Build vars
      $vars = [];
      if (isset($by_section[$section_id])) {
        for ($n = 1; $n <= 20; $n++) {
          $qKey = 'Q' . $n;
          if (array_key_exists($qKey, $by_section[$section_id])) {
            $vars[$qKey] = (float) $by_section[$section_id][$qKey];
          }
        }
      }

      if ($role === 'readiness') {
        $readiness = isset($vars['Q1']) ? (int) round($vars['Q1']) : null;
        if ($readiness !== null) self::upsert_readiness_score($response_id, $form_id, $user_id, $readiness);
        continue;
      }

      if ($role === 'r12_s6') {
        $score = null;
        if ($formula !== '') {
          $score = self::evaluate_formula_0to1($formula, $vars); // will use new sum/Total logic
        } else {
          $q1 = isset($vars['Q1']) ? $vars['Q1'] : 0.0;
          $q2 = isset($vars['Q2']) ? $vars['Q2'] : 0.0;
          $q3 = isset($vars['Q3']) ? $vars['Q3'] : 0.0;
          $score = 0.3 * ($q1 / 4.0) + 0.3 * ($q2 / 4.0) + 0.4 * ($q3 / 4.0);
        }
        if (is_numeric($score)) {
          $score = max(0.0, min(1.0, (float) $score));
          BMF_RSI_Saver::save_extra($user_id, 'R12_S6', $score * 100);
        }
      }

      // Normal scoring with enhanced formula support
      $score = null;
      if ($formula !== '') {
        if (preg_match('/^avg\\(([^)]+)\\)$/i', $formula, $m)) {
          $inner = trim($m[1]);
          $values = [];

          // NEW: Range support Q1:Q5
          if (preg_match('/^Q(\d+):Q(\d+)$/i', $inner, $range)) {
            $start = (int)$range[1];
            $end   = (int)$range[2];
            if ($start > $end) { [$start, $end] = [$end, $start]; }
            for ($n = $start; $n <= $end; $n++) {
              $qKey = 'Q' . $n;
              if (isset($vars[$qKey]) && is_numeric($vars[$qKey])) {
                $values[] = (float)$vars[$qKey];
              }
            }
          } else {
            // Original comma list
            $tokens = explode(',', $inner);
            foreach ($tokens as $t) {
              $t = trim($t);
              if (isset($vars[$t]) && is_numeric($vars[$t])) {
                $values[] = (float)$vars[$t];
              }
            }
          }

          if (!empty($values)) {
            $max = max($values) ?: 4.0;
            $scale = ($max > 4) ? $max : 4;
            $score = (array_sum($values) / count($values)) / $scale;
          } else {
            $score = null;
          }

          bm_log(__METHOD__ . ' SCORE FROM AVG | section_id=' . $section_id . ' | formula=' . $formula . ' | values=' . count($values));
        } else {
          // Enhanced general formula with sum() and Total
          $processed = self::preprocess_formula($formula, $vars);
          $score = self::evaluate_formula_0to1($processed, $vars);

          bm_log(__METHOD__ . ' SCORE FROM FORMULA | section_id=' . $section_id . ' | original=' . $formula . ' | processed=' . $processed);
        }
      } else {
        // Fallback (unchanged)
        $q1 = isset($vars['Q1']) ? $vars['Q1'] : 0.0;
        $q2 = isset($vars['Q2']) ? $vars['Q2'] : 0.0;
        $q3 = isset($vars['Q3']) ? $vars['Q3'] : 0.0;
        $score = 0.3 * ($q1 / 4.0) + 0.3 * ($q2 / 4.0) + 0.4 * ($q3 / 4.0);
      }

      if (!is_numeric($score)) {
        bm_log(__METHOD__ . ' SECTION SCORE INVALID | section_id=' . $section_id);
        continue;
      }

      $score = max(0.0, min(1.0, (float) $score));

      // Upsert to bm_section_scores (unchanged)
      $existing_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$t_ss} WHERE response_id = %d AND section_id = %d LIMIT 1",
        $response_id, $section_id
      ));

      $details = [
        'method'  => ($formula !== '' ? 'formula' : 'fallback_rsi_0.3/0.3/0.4'),
        'formula' => ($formula !== '' ? $formula : '0.3*(Q1/4) + 0.3*(Q2/4) + 0.4*(Q3/4)'),
        'vars'    => $vars,
      ];

      if ($existing_id > 0) {
        $wpdb->update($t_ss, ['score' => $score, 'method' => 'formula', 'details_json' => wp_json_encode($details)], ['id' => $existing_id], ['%f', '%s', '%s'], ['%d']);
      } else {
        $wpdb->insert($t_ss, [
          'response_id'  => $response_id,
          'section_id'   => $section_id,
          'score'        => $score,
          'method'       => 'formula',
          'details_json' => wp_json_encode($details),
        ], ['%d', '%d', '%f', '%s', '%s']);
      }
    }

    bm_log(__METHOD__ . ' EXIT SUCCESS | response_id=' . $response_id);

    if ($is_bsi && class_exists('BMF_BSI_Scorer')) {
      BMF_BSI_Scorer::update_form_overall_score($response_id);
    }

        // === 8 Pillars handling (forms 18-25) ===
        if (class_exists('BMF_Pillars_Saver') && isset(BMF_Pillars_Saver::$form_to_pillar[$form_id])) {
            // Get the pillar average from the existing pivot view (recommended)
            $pillar_slug = BMF_Pillars_Saver::$form_to_pillar[$form_id];
            $view_table  = $wpdb->prefix . 'vw_bm_' . $pillar_slug . '_pivot';

            // Adjust the query below to match how your pivot views expose the latest average for a user
            $average = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT average 
                 FROM {$view_table} 
                 WHERE user_id = %d 
                 ORDER BY submitted_at DESC 
                 LIMIT 1",
                $user_id
            ));

            if ($average > 0) {
                BMF_Pillars_Saver::save_pillar($user_id, $form_id, $average);
            }
        }    

        // === 8 Pillars Rank form (form_id 26) ===
        if ($form_id === 26 && class_exists('BMF_Pillars_Saver')) {
            // Pull the rank string from the single question (question_id=1314)
            $rank = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(free_text, choice_value) 
                 FROM {$wpdb->prefix}bm_response_items 
                 WHERE response_id = %d 
                   AND question_id = 1314 
                 LIMIT 1",
                $response_id
            ));

            if (!empty($rank)) {
                BMF_Pillars_Saver::save_rank($user_id, $rank);
            }
        }        

    return true;
  }

  /**
   * NEW: Preprocess formula for sum() and Total
   */
  protected static function preprocess_formula(string $formula, array $vars): string {
    // Replace sum(...) calls
    $formula = preg_replace_callback('/\bsum\\(([^)]+)\\)/i', function($m) use ($vars) {
      $inner = trim($m[1]);
      $parts = preg_split('/\s*,\s*/', $inner);
      $all_values = [];
      foreach ($parts as $part) {
        $all_values = array_merge($all_values, self::get_values_from_token($part, $vars));
      }
      return (string)(!empty($all_values) ? array_sum($all_values) : 0);
    }, $formula);

    // Replace Total keyword with sum of all Qs
    $total = 0.0;
    foreach ($vars as $v) {
      if (is_numeric($v)) $total += (float)$v;
    }
    $formula = str_ireplace('Total', (string)$total, $formula);

    return $formula;
  }

  /**
   * Helper for ranges and single Qs
   */
  protected static function get_values_from_token(string $token, array $vars): array {
    $values = [];
    $token = trim($token);

    if (preg_match('/^Q(\d+):Q(\d+)$/i', $token, $m)) {
      $start = (int)$m[1];
      $end   = (int)$m[2];
      if ($start > $end) { [$start, $end] = [$end, $start]; }
      for ($n = $start; $n <= $end; $n++) {
        $qKey = 'Q' . $n;
        if (isset($vars[$qKey]) && is_numeric($vars[$qKey])) {
          $values[] = (float)$vars[$qKey];
        }
      }
    } elseif (preg_match('/^Q(\d+)$/i', $token, $m)) {
      $qKey = 'Q' . $m[1];
      if (isset($vars[$qKey]) && is_numeric($vars[$qKey])) {
        $values[] = (float)$vars[$qKey];
      }
    }

    return $values;
  }

  /**
   * Original safe evaluator (unchanged)
   */
  protected static function evaluate_formula_0to1(string $expr, array $vars): ?float {
    // Replace Qn tokens...
    $expanded = preg_replace_callback('/\\bQ([1-9][0-9]?)\\b/', function($m) use ($vars) {
      $k = 'Q' . $m[1];
      $v = isset($vars[$k]) && is_numeric($vars[$k]) ? (float)$vars[$k] : 0.0;
      return (string)$v;
    }, $expr);

    $s = preg_replace('/\\s+/', '', $expanded);

    if (preg_match('/[^0-9\\.\\+\\-\\*\\/\\(\\)]/', $s)) {
      return null;
    }

    // Shunting-yard + RPN evaluation (exact same as original)
    $output = [];
    $ops    = [];
    $prec   = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];

    $i = 0; $len = strlen($s);
    while ($i < $len) {
      $ch = $s[$i];

      if (ctype_digit($ch) || $ch === '.') {
        $start = $i;
        $i++;
        while ($i < $len && (ctype_digit($s[$i]) || $s[$i] === '.')) $i++;
        $output[] = substr($s, $start, $i - $start);
        continue;
      }

      if ($ch === '(') { $ops[] = $ch; $i++; continue; }
      if ($ch === ')') {
        while (!empty($ops) && end($ops) !== '(') $output[] = array_pop($ops);
        if (empty($ops)) return null;
        array_pop($ops);
        $i++; continue;
      }

      if (isset($prec[$ch])) {
        while (!empty($ops)) {
          $top = end($ops);
          if ($top === '(') break;
          if ($prec[$top] >= $prec[$ch]) {
            $output[] = array_pop($ops);
          } else break;
        }
        $ops[] = $ch; $i++; continue;
      }

      return null;
    }
    while (!empty($ops)) {
      $top = array_pop($ops);
      if ($top === '(' || $top === ')') return null;
      $output[] = $top;
    }

    // RPN evaluation
    $stack = [];
    foreach ($output as $tok) {
      if (isset($prec[$tok])) {
        if (count($stack) < 2) return null;
        $b = (float) array_pop($stack);
        $a = (float) array_pop($stack);
        switch ($tok) {
          case '+': $stack[] = $a + $b; break;
          case '-': $stack[] = $a - $b; break;
          case '*': $stack[] = $a * $b; break;
          case '/': $stack[] = ($b == 0.0 ? 0.0 : $a / $b); break;
        }
      } else {
        $stack[] = (float)$tok;
      }
    }

    if (count($stack) !== 1) return null;
    $val = (float)$stack[0];
    if (!is_finite($val)) return null;
    return max(0.0, min(1.0, $val));
  }
}

BMF_Section_Scorer::init();