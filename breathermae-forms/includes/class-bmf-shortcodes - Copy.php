<?php
if (!defined('ABSPATH')) exit;

class BMF_Shortcodes {

    public static function register(){
        add_shortcode('bm_form', [__CLASS__,'render_form']);
        add_shortcode('bm_form_results', [__CLASS__,'render_results']);

        // Autosave + Submit endpoints
        add_action('wp_ajax_bmf_save_answer', [__CLASS__,'ajax_save_answer']);
        add_action('wp_ajax_nopriv_bmf_save_answer', [__CLASS__, 'ajax_save_answer']);

        add_action('wp_ajax_bmf_submit', [__CLASS__,'ajax_submit']);
        add_action('wp_ajax_nopriv_bmf_submit', [__CLASS__,'ajax_need_login']);

        add_action('wp_ajax_nopriv_bmf_auth', [__CLASS__, 'bmf_auth_handler']);
        add_action('wp_ajax_bmf_auth', [__CLASS__, 'bmf_auth_handler']);

        add_shortcode('bmf_interpretation', function () {

            $raw = get_user_meta(get_current_user_id(), 'bmf_interpretation', true);
            $interp = $raw ? json_decode($raw, true) : null;

            if (!$interp) {
                return '<div style="color: #dc3545; font-size: 0.8rem;">No interpretation available.</div>';
            }

                // ✅ Handle pillars interpretation format (if summary exists at top level, it's the old format)
                if (!empty($interp) && isset($interp['type']) && $interp['type'] === 'pillars') {
                    ob_start();
                    echo '<div class="bmf-results">';
                    echo '<h3>Your Insights</h3>';
                    echo '<p>' . esc_html($interp['summary']) . '</p>';

                    if (!empty($interp['alignment'])) {

                        echo '<h4>Alignment Overview</h4>';

                        $labels = [
                            'strong'   => 'Strong Alignment',
                            'moderate' => 'Moderate Alignment',
                            'low'      => 'Significant Reordering'
                        ];

                        $label = $labels[$interp['alignment']] ?? ucfirst($interp['alignment']);

                        echo '<p style="font-weight:bold; font-size:15px;">' . esc_html($label) . '</p>';
                    }

                    if (!empty($interp['insights'])) {

                        echo '<h4>Key Observations</h4>';
                        echo '<ul>';

                        foreach ($interp['insights'] as $ins) {
                            echo '<li>' . esc_html($ins) . '</li>';
                        }

                        echo '</ul>';
                    }

                    if (!empty($interp['distribution'])) {

                        echo '<h4>Your Awareness Profile</h4>';
                        echo '<p style="font-weight:bold; font-size:15px;">' . esc_html($interp['distribution']) . '</p>';
                    }

                    if (!empty($interp['pillars'])) {

                        echo '<h4>Your Wellness Areas</h4>';
                        echo '<ul>';

                        foreach ($interp['pillars'] as $p) {

                            echo '<li>'
                                . esc_html($p['label'])
                                . ' — '
                                . esc_html(round($p['percent'])) . '% '
                                . '(' . esc_html(ucfirst($p['level'])) . ')'
                                . '</li>';
                        }

                        echo '</ul>';
                    }

                    if (!empty($interp['perceived_ranking']) && !empty($interp['pillars'])) {

                        echo '<h4>Perception vs Assessment</h4>';

                        echo '<div style="max-width:400px; margin:0 auto;">';

                        $count = max(count($interp['perceived_ranking']), count($interp['pillars']));

                        for ($i = 0; $i < $count; $i++) {

                            $perceived = ucfirst($interp['perceived_ranking'][$i] ?? '');
                            $actual    = $interp['pillars'][$i]['label'] ?? '';

                            // ✅ Find this item's comparison row (for diff)
                            $diff = 0;
                            if (!empty($interp['comparison'])) {
                                foreach ($interp['comparison'] as $c) {
                                    if (strcasecmp($c['label'], $actual) === 0) {
                                        $diff = $c['difference'];
                                        break;
                                    }
                                }
                            }

                            // ✅ Indicator logic
                            $icon  = '';
                            $color = '#999';

                            if ($diff === 0) {
                                $icon  = '✓';
                                $color = '#22c55e'; // green
                            } elseif ($diff < 0) {
                                $icon  = '↑ ' . abs($diff);
                                $color = '#3b82f6'; // blue
                            } elseif ($diff > 0) {
                                $icon  = '↓ ' . $diff;
                                $color = '#f97316'; // orange
                            }

                            echo '<div style="
                                display:flex;
                                align-items:center;
                                justify-content:center;
                                margin:6px 0;
                                font-size:15px;
                            ">';

                            // ✅ LEFT (perceived card)
                            echo '<div style="
                                flex:0 0 42%;
                                text-align:left;
                                font-weight:500;
                                padding:4px 10px;
                                border:1px solid #233b6d;
                                border-radius:10px;
                                background:#ffffff;
                                box-shadow:0 2px 6px rgba(0,0,0,0.10);
                                display:flex;
                                align-items:center;
                                min-height:28px;
                            ">'
                                . esc_html($perceived) .
                            '</div>';

                            // ✅ CENTER (indicator)
                            echo '<div style="
                                width:75px;
                                text-align:center;
                                font-weight:600;
                                color:' . $color . ';
                                margin:0 6px;
                            ">'
                                . esc_html($icon) .
                            '</div>';

                            // ✅ RIGHT (actual card)
                            echo '<div style="
                                flex:0 0 42%;
                                text-align:right;
                                font-weight:500;
                                padding:4px 10px;
                                border:1px solid #233b6d;
                                border-radius:10px;
                                background:#ffffff;
                                box-shadow:0 2px 6px rgba(0,0,0,0.10);
                                display:flex;
                                align-items:center;
                                justify-content:flex-end;
                                min-height:28px;
                            ">'
                                . esc_html($actual) .
                            '</div>';

                            echo '</div>';
                        }

                        echo '</div>';
                    }              
                    
                    echo '</div>';
                    return ob_get_clean();
                }            
            
            

            // ✅ New format: fully pre-rendered HTML from the server, just output it safely
            ob_start();

            echo '<div class="bmf-results">';

            // ✅ Summary (already human)
            echo '<h3>Your Insights</h3>';
            echo '<p>' . esc_html($interp['summary']) . '</p>';

            // ✅ Supporting text (optional, static)
            echo '<p>These areas are currently influencing your overall balance and performance the most.</p>';

            // ✅ Actions (already human)
            echo '<h4>Recommended Next Steps</h4>';
            echo '<ul>';
            foreach ($interp['actions'] as $a) {
                echo '<li>' . esc_html($a) . '</li>';
            }
            echo '</ul>';

            echo '</div>';

            return ob_get_clean();
        });

    }

    public static function ajax_need_login(){ wp_send_json_error(['message'=>'login_required'], 401); }

    /**
     * AUTOSAVE (radio on change)
     */
    public static function ajax_save_answer() {

        // ✅ Graceful nonce check (NO die)
        if (
            ! isset($_POST['_ajax_nonce']) ||
            ! wp_verify_nonce($_POST['_ajax_nonce'], 'bmf_nonce')
        ) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        // ✅ DO NOT require login here

        $response_id = absint($_POST['response_id'] ?? 0);
        $question_id = absint($_POST['question_id'] ?? 0);
        $value       = sanitize_text_field($_POST['value'] ?? '');

        // save logic here...

        wp_send_json_success();
    }

    /**
     * Ensure $tag is present in both usermeta arrays: 'zoho_tags' and 'mult_tags'.
     * - Reads existing arrays (or initializes empty).
     * - Uppercases and trims strings to match your stored convention.
     * - Appends $tag if missing, then updates both meta keys.
     *
     * @param int    $user_id
     * @param string $tag
     */

    private static function debug_log( $message ) {

        $log_file = WP_CONTENT_DIR . '/bmf-debug.log';

        try {
            if ( is_array( $message ) || is_object( $message ) ) {
                $message = wp_json_encode( $message );
            }

            $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
            @file_put_contents( $log_file, $line, FILE_APPEND );
        } catch ( \Throwable $e ) {
            // Swallow all errors to avoid fatal interruption
        }
    }



    private static function ensure_tag_in_usermeta_arrays( $user_id, $tag ) {
        $user_id = (int) $user_id;
        $tag     = strtoupper( trim( (string) $tag ) );
        if ( $user_id <= 0 || $tag === '' ) {
            return;
        }

        $meta_keys = [ 'zoho_tags', 'mult_tags' ];

        foreach ( $meta_keys as $key ) {
            $val = get_user_meta( $user_id, $key, true );

            // Normalize to array of strings
            if ( ! is_array( $val ) ) {
                // Handle empty string, null, or legacy serialized string:
                // get_user_meta(..., true) already unserializes; if it's still not array, start fresh.
                $val = [];
            }

            // Uppercase + trim all entries and dedupe
            $normalized = [];
            foreach ( $val as $v ) {
                $vv = strtoupper( trim( (string) $v ) );
                if ( $vv !== '' ) {
                    $normalized[$vv] = true; // use keys to dedupe
                }
            }

            // Add the requested tag
            $normalized[$tag] = true;

            // Rebuild as a simple numeric array
            $final = array_keys( $normalized );
            sort( $final ); // optional: keep deterministic order

            update_user_meta( $user_id, $key, $final );
        }
    }

    /**
     * SERVER-SIDE SUBMIT + REQUIRED VALIDATION + SCORING
     */
    public static function ajax_submit(){

        file_put_contents(
            WP_CONTENT_DIR . '/bmf-submit-hit.txt',
            'HIT ' . date('Y-m-d H:i:s') . PHP_EOL,
            FILE_APPEND
        );

        check_ajax_referer('bmf_nonce');
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'login_required'], 401);

        $response_id = intval($_POST['response_id'] ?? 0);
        $form_id     = intval($_POST['form_id'] ?? 0);
        if (!$response_id || !$form_id) wp_send_json_error(['message'=>'missing parameters'], 400);

        global $wpdb;
        $secs   = $wpdb->prefix.'bm_form_sections';
        $qs     = $wpdb->prefix.'bm_questions';
        $items  = $wpdb->prefix.'bm_response_items';
        $scores = $wpdb->prefix.'bm_section_scores';
        $res    = $wpdb->prefix.'bm_responses';

        // Pull all sections for this form
        $sections = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $secs WHERE form_id=%d ORDER BY order_index ASC", $form_id
        ));
        if (!$sections) wp_send_json_error(['message'=>'no sections found'], 400);

        /**
         * REQUIRED VALIDATION (authoritative)
         *  - For each section: collect required question IDs.
         *  - Verify an answer exists in bm_response_items for each required ID.
         *  - If any missing => error with a concise summary.
         */
        $missing_list = [];

        foreach ($sections as $s){
            $qrows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, required FROM $qs WHERE section_id=%d ORDER BY order_index ASC", $s->id
            ));
            if (!$qrows) continue;

            $required_ids = [];
            foreach ($qrows as $qr){
                if (intval($qr->required) === 1) $required_ids[] = intval($qr->id);
            }
            if (empty($required_ids)) continue;

            $placeholders = implode(',', array_fill(0, count($required_ids), '%d'));
            $params = array_merge([ $response_id ], $required_ids);
            $answered_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT question_id FROM $items WHERE response_id=%d AND question_id IN ($placeholders)",
                $params
            ));
            $answered_ids = array_map(function($r){ return intval($r->question_id); }, $answered_rows);

            $missing_ids = array_values(array_diff($required_ids, $answered_ids));
            if (!empty($missing_ids)){
                $missing_list[] = [
                    'section_id'    => intval($s->id),
                    'section_order' => intval($s->order_index),
                    'count'         => count($missing_ids),
                ];
            }
        }

        if (!empty($missing_list)){
            // Message like: "Section 1: 2 missing | Section 3: 1 missing"
            $parts = array_map(function($m){
                return 'Section '.$m['section_order'].': '.$m['count'].' missing';
            }, $missing_list);
            $msg = 'Please complete all required questions before submitting. '.implode(' | ', $parts);
            wp_send_json_error(['message' => $msg], 400);
        }

        /**
         * SCORING
         *  - Supports: SUM(values)/N, AVG(values), AVG(values)/4
         */
        foreach ($sections as $s){
            $qrows = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM $qs WHERE section_id=%d ORDER BY order_index ASC", $s->id
            ));
            if (!$qrows) continue;

            $qids = array_map(function($r){ return intval($r->id); }, $qrows);
            $placeholders = implode(',', array_fill(0, count($qids), '%d'));
            $params = array_merge([ $response_id ], $qids);
            $ans = $wpdb->get_results($wpdb->prepare(
                "SELECT question_id, choice_value FROM $items WHERE response_id=%d AND question_id IN ($placeholders)",
                $params
            ));

            // Collect numeric answers only (ignore blanks/strings like "NS")
            $vals = [];
            foreach ($ans as $a){
                if ($a->choice_value === '' || !is_numeric($a->choice_value)) continue;
                $vals[] = floatval($a->choice_value);
            }

            $formula = trim((string)$s->formula);
            $method  = 'custom';
            $score   = 0.0;

            if ($formula === '' || stripos($formula, 'avg(values)') === 0){
                // AVG(values) or AVG(values)/4
                if (count($vals) > 0){
                    $avg = array_sum($vals) / count($vals);
                    $score = $avg;
                    if (preg_match('~avg\\(values\\)\\s*/\\s*(\\d+(?:\\.\\d+)?)~i', $formula, $m)){
                        $d = floatval($m[1]); if ($d != 0.0) $score = $score / $d;
                    }
                    $method = (stripos($formula,'/4')!==false) ? 'AVG/4' : 'AVG';
                }
            } elseif (preg_match('~^sum\\(values\\)\\s*/\\s*(\\d+(?:\\.\\d+)?)$~i', $formula, $m)) {
                // Sum(values)/N
                $sum = array_sum($vals);
                $den = floatval($m[1]);
                $score = ($den != 0.0) ? ($sum / $den) : 0.0;
                $method = 'SUM/N';
            } else {
                // Fallback: AVG/4 keeps a sensible 0..1 value
                if (count($vals) > 0){
                    $avg = array_sum($vals) / count($vals);
                    $score = $avg / 4.0;
                    $method = 'fallback_AVG/4';
                }
            }

            // Upsert section score
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $scores WHERE response_id=%d AND section_id=%d",
                $response_id, $s->id
            ));
            $payload = [
                'response_id' => $response_id,
                'section_id'  => intval($s->id),
                'score'       => $score,
                'method'      => $method,
                'details_json'=> wp_json_encode(['count'=>count($vals)]),
            ];
            if ($existing){
                $wpdb->update($scores, $payload, ['id'=>intval($existing)]);
            } else {
                $wpdb->insert($scores, $payload);
            }
        }


        
        // Mark the response submitted
        $wpdb->update($res, [
            'status'       => 'submitted',
            'submitted_at' => current_time('mysql')
        ], ['id' => $response_id]);



        
        $redirect_fallback = wp_get_referer();
        if (empty($redirect_fallback)) {
            $redirect_fallback = home_url('/');
        }
        wp_send_json_success([
            'ok'       => true,
            'redirect' => $redirect_fallback,
        ]);

    }


    /**
     * Optional: AJAX handler for login/registration from auth section.
     * Expects 'email' and 'password' in POST. If user exists, attempts login; if not, creates account and logs in.
     * Returns JSON with 'status' => 'logged_in' or 'registered' on success, or error message on failure.
     */

        public static function bmf_auth_handler() {
            
            //bm_log('Authenticating via breathermae-forms');

            if (
                ! isset($_POST['_ajax_nonce']) ||
                ! wp_verify_nonce($_POST['_ajax_nonce'], 'bmf_nonce')
            ) {
                wp_send_json_error([
                    'message' => 'Security check failed. Please refresh the page and try again.'
                ], 401);
            }

            $email    = sanitize_email($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
bm_log('Auth attempt for email: ' . $email);            

            if (!$email || !$password) {
                wp_send_json_error(['message' => 'Email and password are required.']);
            }

            if (!is_email($email)) {
                wp_send_json_error(['message' => 'Please enter a valid email address.']);
            }

            // -----------------------------------------------------
            // EXISTING USER → LOGIN
            // -----------------------------------------------------
            $user = get_user_by('email', $email);
            bm_log('Checking if user exists for email: ' . $email);

            if ($user) {
bm_log('User found with ID: ' . $user->ID);
                $creds = [
                    'user_login'    => $user->user_login,
                    'user_password' => $password,
                    'remember'      => true,
                ];

                $signon = wp_signon($creds, false);

                if (is_wp_error($signon)) {
                    bm_log('LOGIN FAILED: ' . $signon->get_error_message());
                    wp_send_json_error(['message' => 'Incorrect password.']);
                }

                // ✅ success log
                bm_log('LOGIN SUCCESS: user id ' . $signon->ID);

                // ✅ ✅ CRITICAL FIX (missing piece)
                wp_set_current_user($signon->ID);
                wp_set_auth_cookie($signon->ID, true);

                wp_send_json_success([
                    'status' => 'logged_in'
                ]);
            }

            // -----------------------------------------------------
            // NEW USER → REGISTER + LOGIN
            // -----------------------------------------------------
            // ✅ Base username from email
            $base = sanitize_user(current(explode('@', $email)), true);

            $username = $base;
            $i = 1;

            // ✅ Ensure uniqueness (clean incrementing)
            while (username_exists($username)) {
                $username = $base . $i;
                $i++;
            }

            // ✅ Create user
            $user_id = wp_create_user($username, $password, $email);

            bm_log('Soft registration: Attempting to create user with username: ' . $username . ' and email: ' . $email);

            if (is_wp_error($user_id)) {
                wp_send_json_error(['message' => 'Could not create account.']);
                bm_log('User creation failed: ' . $user_id->get_error_message());
            }

            // ✅ Set display name (important UX improvement)
            wp_update_user([
                'ID' => $user_id,
                'display_name' => ucfirst($base)
            ]);

            // ✅ Log them in
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);

            wp_send_json_success([
                'status' => 'registered'
            ]);
        }
    /**
     * Fetch the form_tag value (renamed from form_number) from wp_bm_forms.
     * Returns '' if not found.
     */
    private static function get_form_tag($form_id){
        global $wpdb;
        $forms = $wpdb->prefix . 'bm_forms';

        // IMPORTANT: you've renamed the column to form_tag (VARCHAR)
        $tag = $wpdb->get_var($wpdb->prepare("SELECT form_tag FROM $forms WHERE id=%d", intval($form_id)));
        $tag = is_string($tag) ? trim($tag) : '';
        return $tag;
    }


    /**
     * Prefill helper: fetch saved answers for a set of questions in this response.
     */
    private static function get_saved_answers_map($response_id, $question_ids){
        if (empty($question_ids)) return [];
        global $wpdb;
        $items = $wpdb->prefix.'bm_response_items';
        $placeholders = implode(',', array_fill(0, count($question_ids), '%d'));
        $sql = "SELECT question_id, choice_value FROM $items WHERE response_id=%d AND question_id IN ($placeholders)";
        $params = array_merge([intval($response_id)], array_map('intval', $question_ids));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
        $map = [];
        foreach ($rows as $r) $map[intval($r->question_id)] = (string)$r->choice_value;
        return $map;
    }

        /**
         * RENDER FORM with prefill + required markup + submit button
         */
        public static function render_form($atts){

            $atts = shortcode_atts([
                'form'          => '',
                'section'       => '',
                'redirect'      => '',
                'require_login' => true, // ✅ DEFAULT: login is required
            ], $atts);

//bm_log('Rendering form with attributes: ' . print_r($atts, true));            
            if (empty($atts['form'])) {
                return '<div class="bmf-error">Form slug missing.</div>';
            }

            // ✅ Default behavior preserved
            if ($atts['require_login'] && !is_user_logged_in()) {
                return '<div class="bmf-error">Please log in to continue.</div>';
            }

            // Assets
            wp_enqueue_style('bmf');
            wp_enqueue_script('bmf');
            wp_enqueue_script('jquery-ui-sortable');

            global $wpdb;
            $forms = $wpdb->prefix.'bm_forms';
            $secs  = $wpdb->prefix.'bm_form_sections';
            $qs    = $wpdb->prefix.'bm_questions';

            // Resolve form
            $form = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $forms WHERE slug=%s", $atts['form'])
            );
            if (!$form) {
                return '<div class="bmf-error">Form not found.</div>';
            }

            // Ensure an in-progress response session
            $response = BMF_Responses::ensure_session(get_current_user_id(), $form->slug);
            if (!$response) {
                return '<div class="bmf-error">Unable to start session.</div>';
            }

            // Resolve redirect target
            $server_ref = wp_get_referer();
            // ✅ Only use shortcode redirect if explicitly provided
            $redirect_target = '';

            if (!empty($atts['redirect'])) {
                $redirect_target = $atts['redirect'];
            }


            // Optional section filter
            $where_section = '';
            $params = [];
            if (!empty($atts['section'])){
                $where_section = ' AND s.order_index = %d';
                $params[] = intval($atts['section']);
            }
            array_unshift($params, $form->id);

            // Load sections
            $sections = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT s.* FROM $secs s
                    WHERE s.form_id=%d $where_section
                    ORDER BY s.order_index ASC",
                    $params
                )
            );
            if (!$sections) {
                return '<div class="bmf-error">No sections.</div>';
            }

            $total_sections = count($sections);

            ob_start();

            echo '<div class="bmf-form"'
                . ' data-form-slug="'.esc_attr($atts['form']).'"'
                . ' data-total-sections="'.intval($total_sections).'"'
                . ' data-redirect="'.esc_attr($redirect_target).'"'
                . '>';

            // Progress bar
            if (empty($atts['section']) && $total_sections > 1){
                echo '<div class="bmf-progress" role="region" aria-label="Form progress" style="margin-bottom:12px">';
                echo '  <div class="bmf-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">';
                echo '    <div class="bmf-progress-fill" style="width:0%"></div>';
                echo '  </div>';
                echo '  <div class="bmf-progress-meta">';
                echo '    <span class="bmf-progress-label">Section <span class="bmf-progress-current">1</span> of '.intval($total_sections).'</span>';
                echo '    <span class="bmf-progress-percent">0%</span>';
                echo '  </div>';
                echo '</div>';
            }

            $panel_index = 0;

            foreach ($sections as $s){

                $section_meta = isset($s->formula_meta) ? $s->formula_meta : '';

                //bm_log('Section meta raw: ' . print_r($s, true));

                echo '<div class="bmf-section bmf-section-panel"'
                    . ' data-section-index="'.intval($panel_index).'"'
                    . ' data-section-order="'.intval($s->order_index).'"'
                    . ' data-section-meta="'.esc_attr($section_meta).'"'
                    . '>';

                echo '<h3 class="bmf-section-title">'.esc_html($s->title).'</h3>';

                if (!empty($s->explanation)) {
                    echo '<div class="bmf-section-expl">'.esc_html($s->explanation).'</div>';
                }
                if (!empty($s->prompt)) {
                    echo '<div class="bmf-section-prompt">'.esc_html($s->prompt).'</div>';
                }

                // ✅ Section-level choices (default only)
                $section_choices = json_decode($s->choices_json, true) ?: [];

                // Load questions
                $questions = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM $qs WHERE section_id=%d ORDER BY order_index ASC",
                        $s->id
                    )
                );

                // Prefill saved answers
                $question_ids = array_map(fn($q) => intval($q->id), $questions);
                $saved = self::get_saved_answers_map(intval($response->id), $question_ids);

                echo '<div class="bmf-questions" data-section-order="'.intval($s->order_index).'">';

                foreach ($questions as $q){

                    $name        = 'q_'.$q->id;
                    $saved_val   = isset($saved[$q->id]) ? (string)$saved[$q->id] : '';
                    $is_required = intval($q->required) === 1 ? '1' : '0';

                    // ✅ Resolve per-question choices FIRST, then fallback to section
                    $q_choices = [];
                    if (!empty($q->choices_json)) {
                        $decoded = json_decode($q->choices_json, true);
                        if (is_array($decoded)) {
                            $q_choices = $decoded;
                        }
                    }
                    if (!$q_choices) {
                        $q_choices = $section_choices;
                    }

                    echo '<div class="bmf-q" data-question-id="'.intval($q->id).'" data-required="'.$is_required.'">';
                    echo '<div class="bmf-q-prompt">'.esc_html($q->prompt);
                    if ($is_required === '1') {
                        echo ' <span class="bmf-required" style="color:#b00020">*</span>';
                    }
                    echo '</div>';

                    echo '<div class="bmf-q-choices">';

                    // ---------------------------
                    // TEXTUAL INPUTS
                    // ---------------------------
                    if (in_array($q->type, ['text','email','password'], true)) {

                        $input_type = ($q->type === 'password')
                            ? 'password'
                            : (($q->type === 'email') ? 'email' : 'text');

                        echo '<input type="' . esc_attr($input_type) . '" '
                            . 'name="' . esc_attr($name) . '" '
                            . 'value="' . esc_attr($saved_val) . '" '
                            . 'data-question-id="' . intval($q->id) . '" '
                            . 'data-response-id="' . intval($response->id) . '" '
                            . 'class="bmf-input-text" />';

                    // ---------------------------
                    // SELECT DROPDOWN
                    // ---------------------------
                    } elseif ($q->type === 'select') {

                        echo '<select name="' . esc_attr($name) . '" '
                            . 'data-question-id="' . intval($q->id) . '" '
                            . 'data-response-id="' . intval($response->id) . '">';

                        foreach ($q_choices as $c) {
                            $selected = ((string)$c['value'] === (string)$saved_val) ? ' selected' : '';
                            echo '<option value="' . esc_attr($c['value']) . '"' . $selected . '>';
                            echo esc_html($c['label']);
                            echo '</option>';
                        }

                        echo '</select>';

                    // ---------------------------
                    // CHECKBOXES
                    // ---------------------------
                    } elseif ($q->type === 'checkbox') {

                        $saved_values = is_array($saved_val)
                            ? $saved_val
                            : array_filter(explode(',', (string)$saved_val));

                        foreach ($q_choices as $c) {
                            $checked = in_array((string)$c['value'], $saved_values, true) ? ' checked' : '';
                            echo '<label class="bmf-choice">';
                            echo '<input type="checkbox" '
                                . 'name="' . esc_attr($name) . '[]" '
                                . 'value="' . esc_attr($c['value']) . '" '
                                . 'data-question-id="' . intval($q->id) . '" '
                                . 'data-response-id="' . intval($response->id) . '"' . $checked . '> ';
                            echo esc_html($c['label']);
                            echo '</label>';
                        }

                // ---------------------------
                // DRAG & DROP RANKING
                // ---------------------------
                } elseif ($q->type === 'rank') {

                    // Convert saved value into array
                    $saved_order = array_filter(explode(',', (string)$saved_val));

                    // Reorder choices if saved exists
                    if (!empty($saved_order)) {
                        usort($q_choices, function ($a, $b) use ($saved_order) {

                            $valA = explode('|', (string)$a['value'])[0];
                            $valB = explode('|', (string)$b['value'])[0];

                            $posA = array_search($valA, $saved_order);
                            $posB = array_search($valB, $saved_order);

                            $posA = ($posA !== false) ? $posA : 999;
                            $posB = ($posB !== false) ? $posB : 999;

                            return $posA - $posB;
                        });
                    }

                    echo '<ul class="bmf-rank-list" '
                        . 'data-question-id="' . intval($q->id) . '" '
                        . 'data-response-id="' . intval($response->id) . '" '
                        . 'style="list-style:none;padding:0;margin:0;">';

                    foreach ($q_choices as $c) {
                        echo '<li class="bmf-rank-item" '
                            . 'data-value="' . esc_attr($c['value']) . '" '
                            . 'style="padding:8px;border:1px solid #ccc;margin-bottom:6px;background:#fff;cursor:move;">';
                        echo esc_html($c['label']);
                        echo '</li>';
                    }

                    echo '</ul>';

                    // Hidden input to store result
                    echo '<input type="hidden" '
                        . 'name="' . esc_attr($name) . '" '
                        . 'value="' . esc_attr($saved_val) . '" '
                        . 'data-question-id="' . intval($q->id) . '" '
                        . 'data-response-id="' . intval($response->id) . '" '
                        . 'class="bmf-rank-output">';


                    // ---------------------------
                    // RADIO BUTTONS (enhanced with JSON payload)
                    // ---------------------------
                } else {

                    foreach ($q_choices as $c) {

                        $checked = ((string)$c['value'] === (string)$saved_val) ? ' checked' : '';

                        echo '<label class="bmf-choice">';

                        echo '<input type="radio" '
                            . 'name="' . esc_attr($name) . '" '
                            . 'value="' . esc_attr($c['value']) . '" '
                            . 'data-question-id="' . intval($q->id) . '" '
                            . 'data-response-id="' . intval($response->id) . '"'
                            . $checked . '> ';

                        echo esc_html($c['label']);
                        echo '</label>';
                    }
                }

                    echo '</div>';
                    echo '<div class="bmf-q-error" style="display:none;color:#b00020;margin-top:4px;">This question is required.</div>';
                    echo '</div>';
                }

                echo '</div>';

                if (empty($atts['section']) && $total_sections > 1){
                    echo '<div class="bmf-panel-nav" style="margin-top:12px;display:flex;gap:8px;">';
                    if ($panel_index > 0){
                        echo '<button type="button" class="button bmf-prev-section">Previous</button>';
                    }
                    if ($panel_index < $total_sections - 1){
                        echo '<button type="button" class="button button-primary bmf-next-section">Next</button>';
                    }
                    echo '</div>';
                }

                echo '</div>';
                $panel_index++;
            }

            echo '<div class="bmf-submit-wrap" style="margin-top:16px">';
            echo '<button type="button" class="button button-primary bmf-submit" '
                . 'data-response-id="'.intval($response->id).'" '
                . 'data-form-id="'.intval($form->id).'">Submit</button>';
            echo '<span class="bmf-submit-msg" style="margin-left:10px;color:#555;"></span>';
            echo '</div>';

            echo '</div>';

            return ob_get_clean();
        }

    /**
     * Minimal results view (most recent submitted response)
     */
    public static function render_results($atts){
        $atts = shortcode_atts(['form'=>'','user'=>'current'], $atts);
        if (empty($atts['form'])) return '';
        if (!is_user_logged_in()) return '<div class="bmf-error">Please log in to continue.</div>';

        global $wpdb;
        $forms  = $wpdb->prefix.'bm_forms';
        $secs   = $wpdb->prefix.'bm_form_sections';
        $res    = $wpdb->prefix.'bm_responses';
        $scores = $wpdb->prefix.'bm_section_scores';

        $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM $forms WHERE slug=%s", $atts['form']));
        if (!$form) return '<div class="bmf-error">Form not found.</div>';

        $response = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $res WHERE user_id=%d AND form_id=%d AND status='submitted' ORDER BY submitted_at DESC LIMIT 1",
            get_current_user_id(), $form->id
        ));
        if (!$response) return '<div class="bmf-results">No submitted results found for this form.</div>';

        $sections = $wpdb->get_results($wpdb->prepare("SELECT * FROM $secs WHERE form_id=%d ORDER BY order_index ASC", $form->id));
        if (!$sections) return '<div class="bmf-results">No sections.</div>';

        $out = '<div class="bmf-results">';
        $out .= '<div class="bmf-results-meta">Submitted: '.esc_html($response->submitted_at).'</div>';

        foreach ($sections as $s){
            $score = $wpdb->get_row($wpdb->prepare("SELECT * FROM $scores WHERE response_id=%d AND section_id=%d", $response->id, $s->id));
            $val = $score ? floatval($score->score) : 0.0;
            $pct = round($val * 100);
            $out .= '<div class="bmf-section-score" style="margin:10px 0;padding:10px;border:1px solid #eee;border-radius:6px">';
            $out .= '<div class="bmf-section-title"><strong>'.esc_html($s->title).'</strong></div>';
            if (!empty($s->explanation)) $out .= '<div class="bmf-section-expl" style="color:#666">'.esc_html($s->explanation).'</div>';
            $out .= '<div class="bmf-section-score-val" style="margin-top:6px">Score: <strong>'.$pct.'%</strong></div>';
            $out .= '</div>';
        }

        $out .= '</div>';
        return $out;
    }
}
