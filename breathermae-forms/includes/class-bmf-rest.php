<?php
// includes/class-bmf-rest.php
if (!defined('ABSPATH')) exit;

class BMF_REST {

  public static function init() {
    // Autosave (radio change)
    add_action('wp_ajax_bmf_save_answer', [__CLASS__, 'ajax_save_answer']);
    // If you ever support guests, also register:
    // add_action('wp_ajax_nopriv_bmf_save_answer', [__CLASS__, 'ajax_save_answer']);

    // Final submit (compute + score + redirect)
    add_action('wp_ajax_bmf_submit', [__CLASS__, 'ajax_submit']);
    // If you ever support guests:
    // add_action('wp_ajax_nopriv_bmf_submit', [__CLASS__, 'ajax_submit']);

    // Optional: check email existence for login/registration flows
    add_action('wp_ajax_bmf_check_email', [__CLASS__, 'ajax_check_email']);
    add_action('wp_ajax_nopriv_bmf_check_email', [__CLASS__, 'ajax_check_email']);

  }


  /**
   * Check whether an email address already belongs to a WordPress user
   * Used for soft-auth branching (email -> login vs register)
   */
  public static function ajax_check_email() {
      check_ajax_referer('bmf_nonce', '_ajax_nonce');

      $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

      if (empty($email) || !is_email($email)) {
          wp_send_json_error([
              'message' => 'Invalid email address.'
          ], 400);
      }

      $user = get_user_by('email', $email);

      wp_send_json_success([
          'exists' => (bool) $user
      ]);
  }

  /**
   * Save/overwrite a single answer for an in-progress response.
   * Expects: response_id, question_id, value
   */
  public static function ajax_save_answer() {
    check_ajax_referer('bmf_nonce', '_ajax_nonce');

    $user_id     = get_current_user_id();
    $response_id = isset($_POST['response_id']) ? (int) $_POST['response_id'] : 0;
    $question_id = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;

    $value = isset($_POST['value']) ? (string) $_POST['value'] : '';

    global $wpdb;

    // ✅ Detect question type
    $question_type = $wpdb->get_var($wpdb->prepare(
        "SELECT type FROM {$wpdb->prefix}bm_questions WHERE id = %d",
        $question_id
    ));

    // ✅ Force correct email value
    if ($question_type === 'email') {

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $value = $user->user_email;
        } else {
            $value = sanitize_email($value);
        }
    }    

    // get the JSON for this option from DB
    $choice_json = $wpdb->get_var($wpdb->prepare(
        "SELECT choices_json FROM {$wpdb->prefix}bm_questions WHERE id = %d",
        $question_id
    ));

    $choices = json_decode($choice_json, true);

    $final_value = $value; // fallback

    if (!empty($choices)) {
        foreach ($choices as $c) {
            if ((string)$c['value'] === (string)$value) {

                $meta = [
                    'weights' => $c['weights'] ?? [],
                    'tags'    => [ (string)$c['value'] ],
                    'actions' => [ 'Improve ' . $c['value'] ]
                ];

                $final_value = $value . '|' . json_encode($meta);
                break;
            }
        }
    }

bm_log('SAVE FINAL VALUE | ' . $final_value);

    if (!$user_id || $response_id <= 0 || $question_id <= 0) {
      wp_send_json_error(['message' => 'Invalid request.'], 400);
    }

    global $wpdb;
    $p        = $wpdb->prefix;
    $t_res    = $p . 'bm_responses';
    $t_items  = $p . 'bm_response_items';

    // Ensure the response belongs to the current user and is still in progress
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, status FROM {$t_res} WHERE id = %d AND user_id = %d LIMIT 1",
      $response_id, $user_id
    ));
    if (!$row) {
      wp_send_json_error(['message' => 'Response not found.'], 404);
    }
    if ($row->status !== 'in_progress') {
      wp_send_json_error(['message' => 'Response already submitted.'], 409);
    }

    // For RSI/BSI radios: values are numeric (e.g., 0..4 or 0..10). Store as string.
    $choice_value = is_numeric($value)
    ? (string) (0 + $value)
    : (string) $final_value;

    // Upsert by (response_id, question_id)
    $existing_id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$t_items} WHERE response_id = %d AND question_id = %d LIMIT 1",
      $response_id, $question_id
    ));
    if ($existing_id > 0) {
      $wpdb->update(
        $t_items,
        [
          'choice_value' => $choice_value,
          'answered_at'  => current_time('mysql', 1),
        ],
        ['id' => $existing_id],
        ['%s','%s'],
        ['%d']
      );
    } else {
      $wpdb->insert(
        $t_items,
        [
          'response_id'  => $response_id,
          'question_id'  => $question_id,
          'choice_value' => $choice_value,
          'answered_at'  => current_time('mysql', 1),
        ],
        ['%d','%d','%s','%s']
      );
    }

    if ($wpdb->last_error) {
      wp_send_json_error(['message' => 'Could not save answer.'], 500);
    }
    wp_send_json_success(['ok' => true]);
  }

  /**
   * Final submit:
   * 1) Mark submitted (set submitted_at first so downstream steps can use it)
   * 2) Compute section scores (readiness needs submitted_at -> results_date)
   * 3) Run scorers (BSI then RSI)
   * 4) Fire generic hook
   * 5) Return optional redirect
   */
  public static function ajax_submit() {
    check_ajax_referer('bmf_nonce', '_ajax_nonce');

    bm_log(__METHOD__ . ' ENTER');

    $user_id     = get_current_user_id();
    $response_id = isset($_POST['response_id']) ? intval($_POST['response_id']) : 0;
    $form_id     = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;

    if (!$user_id || $response_id <= 0 || $form_id <= 0) {
      bm_log(__METHOD__ .
          ' ABORT | invalid request ' .
          json_encode(['user_id'=>$user_id,'response_id'=>$response_id,'form_id'=>$form_id])
      );
      wp_send_json_error(['message' => 'Invalid request.']);
    }

    global $wpdb;
    $p     = $wpdb->prefix;
    $t_res = $p . 'bm_responses';

    // Owns this response?
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t_res} WHERE id = %d AND user_id = %d LIMIT 1",
      $response_id, $user_id
    ));
    if ( ! $row ) {
      bm_log(__METHOD__ .
          ' ABORT | response not found or not owned | response_id=' . $response_id .
          ' | user_id=' . $user_id
      );
      wp_send_json_error(['message' => 'Response not found.']);
    }

    // --- 1) Mark submitted (set submitted_at first so downstream steps can use it) ---
    $now = current_time('mysql', 1); // GMT

    bm_log(__METHOD__ .
        ' MARK SUBMITTED | response_id=' . $response_id .
        ' | user_id=' . $user_id .
        ' | timestamp=' . $now
    );

    $ok  = $wpdb->update(
      $t_res,
      ['status' => 'submitted', 'submitted_at' => $now],
      ['id' => $response_id, 'user_id' => $user_id],
      ['%s','%s'],
      ['%d','%d']
    );

    if ($ok === false) {
      bm_log(__METHOD__ .
          ' ERROR | failed to mark submitted | mysql_error=' . $wpdb->last_error
      );
      wp_send_json_error(['message' => 'Unable to submit. Please try again.']);
    }


    // --- 2) Compute section scores (readiness uses submitted_at -> results_date) ---
    // This triggers BMF_Section_Scorer::compute_for_response() via the action hook.
    bm_log(__METHOD__ .
        ' COMPUTE SECTION SCORES | response_id=' . $response_id .
        ' | form_id=' . $form_id
    );    
    do_action('bmf_compute_section_scores', $response_id, $form_id, $user_id);

/*     // --- 3) Run BSI scorer (F1..F9 via open-row Saver) ---
    if ( class_exists('BMF_BSI_Scorer') ) {
      bm_log(__METHOD__ .
          ' CALL BSI SCORER | response_id=' . $response_id .
          ' | form_id=' . $form_id
      );
      BMF_BSI_Scorer::update_form_overall_score($response_id);
    } else {
      bm_log(__METHOD__ . ' SKIP BSI | scorer class missing');
    } */

    // --- 4) Run RSI scorer (R11/R12 via open-row Saver) ---
    $form_type = BMF_Interpreter::detect_form_type($response_id);

    if ($form_type !== 'pillars' && class_exists('BMF_RSI_Scorer')) {
      bm_log(__METHOD__ .
          ' CALL RSI SCORER | response_id=' . $response_id
      );
      BMF_RSI_Scorer::update_form_domain_score($response_id);
    }

    // --- 5) Generic hook for downstream observers ---
    bm_log(__METHOD__ .
        ' FIRE SUBMITTED HOOK | response_id=' . $response_id
    );    
    try {
        do_action('bmf_response_submitted', (int)$response_id);
    } catch (\Throwable $e) {
        bm_log('HOOK ERROR: ' . $e->getMessage());
    }


    // Optional: allow PHP to suggest a redirect; otherwise front-end may use data-redirect or stay put
    $redirect = apply_filters('bmf_submit_redirect', null, $response_id, $form_id, $user_id);

    bm_log(__METHOD__ .
        ' EXIT SUCCESS | response_id=' . $response_id .
        ' | redirect=' . (string)$redirect
    );    

    wp_send_json_success(['redirect' => $redirect]);
  }
}

BMF_REST::init();