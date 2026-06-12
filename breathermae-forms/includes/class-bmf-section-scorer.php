<?php
if (!defined('ABSPATH')) exit;

/**
 * Future-proof section scorer for "new" forms (form_id > 9).
 * - Reads answers from {prefix}bm_response_items.
 * - Reads section list and optional 'formula' from {prefix}bm_form_sections.
 * - Computes 0..1 section scores and upserts into {prefix}bm_section_scores.
 *
 * Behavior:
 * - Only runs for form_id > 9 (BSI 1..9 remains untouched).
 * - If a section has a non-empty 'formula', we parse & evaluate it safely.
 *   Supported tokens: Q1..Q20, numbers, + - * /, whitespace, parentheses.
 *   Qn resolves to the numeric choice_value for question_code=n (0..4).
 *   You can normalize inside the formula (/4) as you've done in CSV.
 * - If 'formula' is empty, we fallback to RSI default:
 *   0.3*(Q1/4) + 0.3*(Q2/4) + 0.4*(Q3/4)
 */
class BMF_Section_Scorer {

  public static function init() {
    // Attach to the hook fired by ajax_submit() right before calling scorers.
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
            WHERE r.id = %d
            LIMIT 1",
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

    protected static function upsert_r12s6_score($response_id, $form_id, $user_id, $pct)
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $t_responses = $p . 'bm_responses';
        $t_rsi = $p . 'bm_rsi_results';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.submitted_at, u.user_email
            FROM {$t_responses} r
            LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
            WHERE r.id = %d
            LIMIT 1",
            (int) $response_id
        ));
        if (!$row || empty($row->submitted_at) || empty($row->user_email)) return;

        $date  = substr($row->submitted_at, 0, 10);
        $email = trim((string) $row->user_email);

        // Prefer updating the *open row* if you're using the open-row lifecycle,
        // but this mirrors the existing readiness upsert-by-date pattern:
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

    bm_log(__METHOD__ .
        ' ENTER | response_id=' . (int)$response_id .
        ' | form_id=' . (int)$form_id .
        ' | user_id=' . (int)$user_id
    );    

    $is_bsi = ($form_id >= 1 && $form_id <= 9);

    // Only for new forms (>9). Keep legacy BSI pipeline intact.
/*     $form_id = (int) $form_id;
    if ($form_id <= 9) {
      return false;
    } */

    $response_id = (int) $response_id;
    $user_id     = (int) $user_id;
    if ($response_id <= 0 || $user_id <= 0) {
        bm_log(__METHOD__ .
            ' ABORT | invalid ids | response_id=' . $response_id .
            ' | user_id=' . $user_id
        );
        return false;
    }

    $t_sects = $p . 'bm_form_sections';
    $t_qs    = $p . 'bm_questions';
    $t_items = $p . 'bm_response_items';
    $t_ss    = $p . 'bm_section_scores';

    // 1) Fetch sections (ordered) with optional formula text
    $sections = $wpdb->get_results($wpdb->prepare(
      "SELECT s.id AS section_id, s.order_index, s.formula, s.formula_meta
       FROM {$t_sects} s
       WHERE s.form_id = %d
       ORDER BY s.order_index, s.id", $form_id
    ), ARRAY_A);

    bm_log(__METHOD__ .
        ' SECTIONS FETCH | form_id=' . $form_id .
        ' | section_count=' . (is_array($sections) ? count($sections) : 0)
    );

    if (!$sections) {
        bm_log(__METHOD__ . ' ABORT | no sections found');
        return false;
    }

    // 2) Fetch all answers for this response, join to get section + code (Qn)
    $answers = $wpdb->get_results($wpdb->prepare(
      "SELECT q.section_id, q.code, ri.choice_value
       FROM {$t_items} ri
       JOIN {$t_qs} q ON q.id = ri.question_id
       WHERE ri.response_id = %d", $response_id
    ), ARRAY_A);

    bm_log(__METHOD__ .
        ' ANSWERS FETCH | response_id=' . $response_id .
        ' | answer_rows=' . (is_array($answers) ? count($answers) : 0)
    );    
    
    // Map answers by section: code ('1','2',...) => numeric value (0..4)
    $by_section = [];

    foreach ($answers as $r) {
        $answer_section_id = (int) $r['section_id'];
        $code_raw = trim((string) $r['code']);

        // Determine question index based on form type
        if ($is_bsi) {
            // BSI format: 5_1_2 -> Q2
            if (preg_match('/^\d+_\d+_(\d+)$/', $code_raw, $m)) {
                $qIndex = (int) $m[1];
            } else {
                continue;
            }
        } else {
            // RSI format: 1, 2, 3
            // ✅ Allow codes like P1, M2, E3, etc.
            if (preg_match('/(\d+)$/', $code_raw, $m)) {
                $qIndex = (int) $m[1];
            } else {
                continue;
            }
        }

        $val = is_numeric($r['choice_value']) ? (float) $r['choice_value'] : null;
        if ($val === null) continue;

        if (!isset($by_section[$answer_section_id])) {
            $by_section[$answer_section_id] = [];
        }

        $by_section[$answer_section_id]['Q' . $qIndex] = $val;
    }


      bm_log(__METHOD__ . ' BY_SECTION KEYS = ' . implode(',', array_keys($by_section)));

    // 3) Evaluate per-section score and upsert
    foreach ($sections as $s) {
        $section_id      = (int) ($s['section_id'] ?? 0);
        $formula  = trim((string) ($s['formula'] ?? ''));
        $metaRaw  = (string) ($s['formula_meta'] ?? '');
        $meta     = json_decode($metaRaw, true);
        $role     = is_array($meta) && isset($meta['rsi_role']) ? strtolower((string)$meta['rsi_role']) : '';

          bm_log(__METHOD__ .
              ' SECTION START | section_id=' . $section_id .
              ' | has_answers=' . (isset($by_section[$section_id]) ? 'yes' : 'no') .
              ' | formula=' . ($formula !== '' ? 'yes' : 'no')
          );    

        // Build variables: Qn => value (raw 0..4 normally; readiness uses 0..10 in Q1)
      $vars = [];
      if (isset($by_section[$section_id])) {
          for ($n = 1; $n <= 20; $n++) {
              $qKey = 'Q' . $n;
              if (array_key_exists($qKey, $by_section[$section_id])) {
                  $vars[$qKey] = (float) $by_section[$section_id][$qKey];
              }
          }
      }

    // 🔹 Readiness section: do NOT write bm_section_scores; copy Q1 (0..10) to rsi_results.readiness_score
    if ($role === 'readiness') {
        $readiness = isset($vars['Q1']) ? (int) round($vars['Q1']) : null;
        if ($readiness !== null) {
        self::upsert_readiness_score($response_id, $form_id, $user_id, $readiness);
        }
        continue; // skip section scoring
    }

    // 🔹 Form 12 Section 6: write section score to rsi_results.R12_S6 (normalized 0..100)
    if ($role === 'r12_s6') {
        // Compute the section score first (like normal), then save normalized % to results.
        $score = null;
        if ($formula !== '') {
            $score = self::evaluate_formula_0to1($formula, $vars);
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
        // Also keep the per-section row in bm_section_scores (so history/debug stays intact)
        // Fall through to the normal insert/update path by not continuing here.
    }    

    // Normal sections: compute score (0..1)
    $score = null;
    if ($formula !== '') {

        // ✅ NEW: handle avg() syntax
        if (preg_match('/^avg\\(([^)]+)\\)$/i', $formula, $m)) {

            $tokens = explode(',', $m[1]);
            $values = [];

            foreach ($tokens as $t) {
                $t = trim($t);

                if (isset($vars[$t]) && is_numeric($vars[$t])) {
                    $values[] = (float)$vars[$t];
                }
            }

            if (!empty($values)) {
                // ✅ normalize by max scale (assume 0–4 unless larger found)
                $max = max($values);
                $scale = ($max > 4) ? $max : 4;

                $score = (array_sum($values) / count($values)) / $scale;
            } else {
                $score = null;
            }

            bm_log(__METHOD__ .
                ' SCORE FROM AVG | section_id=' . $section_id .
                ' | formula=' . $formula .
                ' | values=' . wp_json_encode($values) .
                ' | score=' . var_export($score, true)
            );

        } else {

            // ✅ EXISTING behavior untouched
            $score = self::evaluate_formula_0to1($formula, $vars);

            bm_log(__METHOD__ .
                ' SCORE FROM FORMULA | section_id=' . $section_id .
                ' | formula=' . $formula .
                ' | vars=' . wp_json_encode($vars) .
                ' | raw_score=' . var_export($score, true)
            );
        }
    
        
    } else {
        $q1 = isset($vars['Q1']) ? $vars['Q1'] : 0.0;
        $q2 = isset($vars['Q2']) ? $vars['Q2'] : 0.0;
        $q3 = isset($vars['Q3']) ? $vars['Q3'] : 0.0;
        $score = 0.3 * ($q1 / 4.0) + 0.3 * ($q2 / 4.0) + 0.4 * ($q3 / 4.0);

          bm_log(__METHOD__ .
              ' SCORE FALLBACK | section_id=' . $section_id .
              ' | q1=' . $q1 .
              ' | q2=' . $q2 .
              ' | q3=' . $q3 .
              ' | raw_score=' . $score
          );

    }

      if (!is_numeric($score)) {
          bm_log(__METHOD__ .
              ' SECTION SCORE INVALID | section_id=' . $section_id .
              ' | formula_raw=' . $formula .
              ' | vars=' . wp_json_encode($vars)
          );
          continue;
      }
    $score = max(0.0, min(1.0, (float) $score));


      bm_log(__METHOD__ .
          ' SCORE ACCEPTED | section_id=' . $section_id .
          ' | normalized_score=' . $score
      );


    // Upsert section score (let MySQL set created_at)
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
        $wpdb->update(
        $t_ss,
        [
            'score'        => $score,
            'method'       => 'formula',
            'details_json' => wp_json_encode($details),
        ],
        ['id' => $existing_id],
        ['%f', '%s', '%s'],
        ['%d']
        );
        if ($wpdb->last_error) {
            bm_log(__METHOD__ .
                ' SECTION SCORE WRITE FAILED | section_id=' . $section_id .
                ' | mysql_error=' . $wpdb->last_error
            );
        }

    } else {
        $wpdb->insert(
        $t_ss,
        [
            'response_id'  => $response_id,
            'section_id'   => $section_id,
            'score'        => $score,
            'method'       => 'formula',
            'details_json' => wp_json_encode($details),
        ],
        ['%d', '%d', '%f', '%s', '%s']
        );
        if ($wpdb->last_error) {
            bm_log(__METHOD__ .
                ' SECTION SCORE WRITE FAILED | section_id=' . $section_id .
                ' | mysql_error=' . $wpdb->last_error
            );
        }        
    }
    }

      bm_log(__METHOD__ .
          ' EXIT SUCCESS | response_id=' . $response_id .
          ' | form_id=' . $form_id
      );
      // --- 3) Run BSI scorer (F1..F9 via open-row Saver) ---
      // ✅ Trigger BSI AFTER section scores exist (BSI forms only)
      if ((int)$form_id >= 1 && (int)$form_id <= 9 && class_exists('BMF_BSI_Scorer')) {
          bm_log(__METHOD__ . 
              ' TRIGGER BSI | response_id=' . (int)$response_id .
              ' | form_id=' . (int)$form_id
          );
          BMF_BSI_Scorer::update_form_overall_score($response_id);
      }

    return true;
  }

  /**
   * Safe, tiny expression evaluator for arithmetic + Qn variables.
   * - Replaces Qn with numeric values (raw 0..4).
   * - Allows digits, dot, + - * /, parentheses, spaces.
   * - Evaluates with a shunting-yard implementation (no eval), returns 0..1 float.
   */
  protected static function evaluate_formula_0to1(string $expr, array $vars): ?float {
    // Replace Qn tokens with numeric values (defaults to 0.0 if missing)
    $expanded = preg_replace_callback('/\\bQ([1-9][0-9]?)\\b/', function($m) use ($vars) {
      $k = 'Q' . $m[1];
      $v = isset($vars[$k]) && is_numeric($vars[$k]) ? (float)$vars[$k] : 0.0;
      return (string)$v;
    }, $expr);

    // Remove allowed whitespace
    $s = preg_replace('/\\s+/', '', $expanded);

    // Validate characters (digits, dot, ops, parentheses)
    if (preg_match('/[^0-9\\.\\+\\-\\*\\/\\(\\)]/', $s)) {
      return null;
    }

    // Convert infix -> RPN (shunting-yard)
    $output = [];
    $ops    = [];
    $prec   = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];

    $i = 0; $len = strlen($s);
    while ($i < $len) {
      $ch = $s[$i];

      if (ctype_digit($ch) || $ch === '.') {
        // number token
        $start = $i;
        $i++;
        while ($i < $len && (ctype_digit($s[$i]) || $s[$i] === '.')) $i++;
        $output[] = substr($s, $start, $i - $start);
        continue;
      }

      if ($ch === '(') {
        $ops[] = $ch; $i++; continue;
      }

      if ($ch === ')') {
        while (!empty($ops) && end($ops) !== '(') {
          $output[] = array_pop($ops);
        }
        if (empty($ops)) return null; // mismatched
        array_pop($ops); // remove '('
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

      // Unknown token
      return null;
    }
    while (!empty($ops)) {
      $top = array_pop($ops);
      if ($top === '(' || $top === ')') return null;
      $output[] = $top;
    }

    // Evaluate RPN
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
        // number
        $stack[] = (float)$tok;
      }
    }
    if (count($stack) !== 1) return null;
    $val = (float)$stack[0];

    // Clamp to 0..1 for section score semantics
    if (!is_finite($val)) return null;
    return max(0.0, min(1.0, $val));
  }
}

BMF_Section_Scorer::init();