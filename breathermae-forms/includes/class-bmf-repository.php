<?php
if (!defined('ABSPATH')) exit;

class BMF_Repository {
    public static function install_tables() {
        global $wpdb; $charset = $wpdb->get_charset_collate();
        $forms = $wpdb->prefix . 'bm_forms';
        $secs  = $wpdb->prefix . 'bm_form_sections';
        $qs    = $wpdb->prefix . 'bm_questions';
        $res   = $wpdb->prefix . 'bm_responses';
        $items = $wpdb->prefix . 'bm_response_items';
        $scores= $wpdb->prefix . 'bm_section_scores';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';


        dbDelta("CREATE TABLE $forms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(100) UNIQUE,
            form_tag VARCHAR(191) NULL,    /* <-- renamed from form_number, now varchar */
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) DEFAULT 'draft',
            version INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $charset;");


        dbDelta("CREATE TABLE $secs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            explanation TEXT NULL,
            prompt TEXT NULL,
            order_index INT DEFAULT 0,
            options_string LONGTEXT NULL,
            choices_json LONGTEXT NULL,
            formula TEXT NULL,
            formula_meta LONGTEXT NULL,
            meta_json LONGTEXT NULL,
            FOREIGN KEY (form_id) REFERENCES $forms(id) ON DELETE CASCADE
        ) $charset;");

        dbDelta("CREATE TABLE $qs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            form_id INT NOT NULL,
            section_id INT NOT NULL,
            code VARCHAR(50) NULL,
            prompt TEXT NOT NULL,
            type VARCHAR(20) NOT NULL,
            required TINYINT(1) DEFAULT 1,
            order_index INT DEFAULT 0,
            options_string TEXT NULL,
            choices_json LONGTEXT NULL,
            meta_json LONGTEXT NULL,
            FOREIGN KEY (form_id) REFERENCES $forms(id) ON DELETE CASCADE,
            FOREIGN KEY (section_id) REFERENCES $secs(id) ON DELETE CASCADE
        ) $charset;");


        dbDelta("CREATE TABLE $res (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NULL,
            form_id INT NOT NULL,
            version INT NOT NULL,
            status VARCHAR(20) DEFAULT 'in_progress',
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            submitted_at DATETIME NULL,
            INDEX(user_id),
            FOREIGN KEY (form_id) REFERENCES $forms(id) ON DELETE CASCADE
        ) $charset;");

        dbDelta("CREATE TABLE $items (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            response_id BIGINT NOT NULL,
            question_id INT NOT NULL,
            choice_value VARCHAR(100) NULL,
            free_text TEXT NULL,
            score DECIMAL(8,3) NULL,
            answered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (response_id) REFERENCES $res(id) ON DELETE CASCADE,
            FOREIGN KEY (question_id) REFERENCES $qs(id) ON DELETE CASCADE,
            INDEX(response_id), INDEX(question_id), INDEX(choice_value)
        ) $charset;");

        dbDelta("CREATE TABLE $scores (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            response_id BIGINT NOT NULL,
            section_id INT NOT NULL,
            score DECIMAL(10,4) NOT NULL,
            method VARCHAR(50) NULL,
            details_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_response_section (response_id, section_id),
            FOREIGN KEY (response_id) REFERENCES $res(id) ON DELETE CASCADE,
            FOREIGN KEY (section_id) REFERENCES $secs(id) ON DELETE CASCADE
        ) $charset;");
        // --- RSI results (parallel to BSI results) ---
        $rsi_res = $wpdb->prefix . 'bm_rsi_results';
        $rsi_open = $wpdb->prefix . 'bm_rsi_open';

        // RSI results table with final fields and engagement descriptors
        dbDelta("CREATE TABLE $rsi_res (
            id BIGINT NOT NULL AUTO_INCREMENT,
            user_email VARCHAR(191) COLLATE utf8mb4_unicode_520_ci NOT NULL,
            current_flag TINYINT(1) DEFAULT '0',
            is_final TINYINT(1) DEFAULT '0',
            results_date DATE NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            R11 DECIMAL(10,4) DEFAULT NULL,
            R12 DECIMAL(10,4) DEFAULT NULL,
            R12_S6 DECIMAL(6,2) DEFAULT NULL,

            R11_final DECIMAL(10,4) DEFAULT NULL,
            R12_final DECIMAL(10,4) DEFAULT NULL,

            R11_engagement VARCHAR(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
            R12_engagement VARCHAR(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,

            R11_Notes TEXT COLLATE utf8mb4_unicode_520_ci,
            R12_Notes TEXT COLLATE utf8mb4_unicode_520_ci,

            readiness_score TINYINT DEFAULT NULL,
            master_score DECIMAL(10,4) DEFAULT NULL,
            mode VARCHAR(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
            user_notes LONGTEXT COLLATE utf8mb4_unicode_520_ci,
            details_json LONGTEXT COLLATE utf8mb4_unicode_520_ci,

            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_date (user_email, results_date)
            ) $charset;");

        dbDelta("CREATE TABLE $rsi_open (
            user_email VARCHAR(191) PRIMARY KEY,
            row_id BIGINT NOT NULL,
            UNIQUE KEY uniq_row (row_id),
            FOREIGN KEY (row_id) REFERENCES $rsi_res(id) ON DELETE CASCADE
        ) $charset;");   
        
        
        // ============================================================
        // PILLARS RESULTS TABLES (added 2026-06-30)
        // Separate dbDelta calls to avoid parser pollution on existing tables
        // ============================================================
        $pillars_res  = $wpdb->prefix . 'bm_pillars_results';
        $pillars_open = $wpdb->prefix . 'bm_pillars_open';

        dbDelta("CREATE TABLE {$pillars_res} (
            id BIGINT NOT NULL AUTO_INCREMENT,
            user_email VARCHAR(191) COLLATE utf8mb4_unicode_520_ci NOT NULL,
            current_flag TINYINT(1) DEFAULT '0',
            is_final TINYINT(1) DEFAULT '0',
            results_date DATE NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            occupational DECIMAL(10,4) DEFAULT NULL,
            social DECIMAL(10,4) DEFAULT NULL,
            spiritual DECIMAL(10,4) DEFAULT NULL,
            mental DECIMAL(10,4) DEFAULT NULL,
            financial DECIMAL(10,4) DEFAULT NULL,
            environmental DECIMAL(10,4) DEFAULT NULL,
            physical DECIMAL(10,4) DEFAULT NULL,
            emotional DECIMAL(10,4) DEFAULT NULL,

            `rank` VARCHAR(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
            master_score DECIMAL(10,4) DEFAULT NULL,
            notes TEXT COLLATE utf8mb4_unicode_520_ci,

            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_date (user_email, results_date)
        ) $charset;");

        dbDelta("CREATE TABLE {$pillars_open} (
            user_email VARCHAR(191) PRIMARY KEY,
            row_id BIGINT NOT NULL,
            UNIQUE KEY uniq_row (row_id),
            FOREIGN KEY (row_id) REFERENCES {$pillars_res}(id) ON DELETE CASCADE
        ) $charset;");        

    }

    public static function get_rsi_result_dates($user_email) {
        global $wpdb;
        $table = $wpdb->prefix . 'bm_rsi_results';

        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT results_date 
                FROM $table
                WHERE user_email = %s
                AND is_final = 1
                ORDER BY results_date DESC",
                $user_email
            )
        );
    }

    /**
     * Available finalized BSI result dates for a user (newest first).
     * Used by [bmf_bsi_history_select].
     */
    public static function get_bsi_result_dates($user_email) {
        global $wpdb;
        $table = $wpdb->prefix . 'bm_bsi_results';

        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT results_date 
                FROM $table
                WHERE user_email = %s
                AND is_final = 1
                ORDER BY results_date DESC",
                $user_email
            )
        );
    }

    public static function get_form_by_slug($slug){
        global $wpdb; $t = $wpdb->prefix . 'bm_forms';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE slug=%s", $slug));
    }

    public static function get_form( int $id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'bm_forms';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $id
            )
        );
    }

    public static function get_all_forms(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'bm_forms';

        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY updated_at DESC"
        ) ?: [];
    }
    
    public static function get_sections_by_form( int $form_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'bm_form_sections';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE form_id = %d ORDER BY order_index ASC",
                $form_id
            )
        ) ?: [];
    }

    public static function get_questions_by_section( int $section_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'bm_questions';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE section_id = %d ORDER BY order_index ASC",
                $section_id
            )
        ) ?: [];
    }


    public static function upsert_form($slug, $data) {
        global $wpdb; 
        $t = $wpdb->prefix . 'bm_forms';
        $existing = self::get_form_by_slug($slug);

        // Expect $data['form_tag'] (string or null)
        $payload = [
            'form_tag'    => isset($data['form_tag']) ? $data['form_tag'] : null,
            'title'       => $data['title'] ?? '',
            'description' => $data['description'] ?? null,
            'status'      => $data['status'] ?? 'published',
        ];

        if ($existing) {
            $payload['version'] = intval($existing->version) + 1;
            $wpdb->update($t, $payload, ['id' => $existing->id]);
            return (int) $existing->id;
        } else {
            $payload['slug']    = $slug;
            $payload['version'] = 1;
            $wpdb->insert($t, $payload);
            return (int) $wpdb->insert_id;
        }
    }


    public static function clear_sections_questions($form_id){
        global $wpdb; $secs = $wpdb->prefix.'bm_form_sections'; $qs = $wpdb->prefix.'bm_questions';
        $wpdb->query($wpdb->prepare("DELETE q FROM $qs q INNER JOIN $secs s ON q.section_id=s.id WHERE s.form_id=%d", $form_id));
        $wpdb->delete($secs, ['form_id' => $form_id]);
    }

    public static function insert_section($form_id, $payload){
        global $wpdb; $t = $wpdb->prefix.'bm_form_sections';
        $wpdb->insert($t, [
            'form_id'=> $form_id,
            'title'=> $payload['title'],
            'explanation'=> $payload['explanation'] ?? null,
            'prompt'=> $payload['prompt'] ?? null,
            'order_index'=> $payload['order_index'],
            'options_string'=> $payload['options_string'] ?? null,
            'choices_json'=> $payload['choices_json'] ?? null,
            'formula'=> $payload['formula'] ?? null,
            'formula_meta'=> $payload['formula_meta'] ?? null,
            'meta_json'=> $payload['meta_json'] ?? null,
        ]);
        return (int)$wpdb->insert_id;
    }

    public static function upsert_section( array $data ): int {
        global $wpdb;

        $table = $wpdb->prefix . 'bm_form_sections';

        $payload = [
                'form_id'        => $data['form_id'],
                'title'          => $data['title'],
                'prompt'         => $data['prompt'] ?? null,
                'explanation'    => $data['explanation'] ?? null,
                'order_index'    => $data['order_index'] ?? 0,
                'options_string' => $data['options_string'] ?? null,
                'choices_json'   => $data['choices_json'] ?? null,
                'formula'        => $data['formula'] ?? null,           
                'formula_meta'   => $data['formula_meta'] ?? null,
                'meta_json'      => $data['meta_json'] ?? null,         
            ];

        if ( ! empty( $data['id'] ) ) {
            // ✅ UPDATE existing section
            $wpdb->update(
                $table,
                $payload,
                [ 'id' => absint( $data['id'] ) ]
            );

            return (int) $data['id'];
        }

        // ✅ INSERT new section
        $wpdb->insert( $table, $payload );

        return (int) $wpdb->insert_id;
    }

    public static function insert_question($form_id, $section_id, $payload){
        global $wpdb; 
        $t = $wpdb->prefix.'bm_questions';

        $wpdb->insert($t, [
            'form_id'        => $form_id,
            'section_id'     => $section_id,
            'code'           => $payload['code'] ?? null,
            'prompt'         => $payload['prompt'],
            'type'           => $payload['type'],
            'required'       => !empty($payload['required']) ? 1 : 0,
            'order_index'    => $payload['order_index'],
            'options_string' => $payload['options_string'] ?? null,
            'choices_json'   => $payload['choices_json'] ?? null,
            'meta_json'      => $payload['meta_json'] ?? null,
        ]);

        return (int) $wpdb->insert_id;
    }

    public function delete_question(int $question_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bm_questions';

        $deleted = $wpdb->delete(
            $table,
            ['id' => absint($question_id)],
            ['%d']
        );

        return $deleted !== false;
    }

    public static function upsert_question( array $data ): int {
        global $wpdb;

        $table = $wpdb->prefix . 'bm_questions';

        $payload = [
                'form_id'       => $data['form_id'] ?? 0,                    // ← ADD THIS
                'section_id'    => $data['section_id'],
                'code'          => $data['question_code'],
                'prompt'        => $data['prompt'],
                'type'          => $data['question_type'],
                'required'      => $data['required'] ?? 0,
                'order_index'   => $data['order_index'] ?? 0,
                'options_string'=> $data['options_string'] ?? null,
                'choices_json'  => $data['choices_json'] ?? null,
                'meta_json'     => $data['meta_json'] ?? null,               // fixed from formula_meta
            ];

        if ( ! empty( $data['id'] ) ) {
            // UPDATE
            $wpdb->update(
                $table,
                $payload,
                [ 'id' => absint( $data['id'] ) ]
            );

            return (int) $data['id'];
        }

        // INSERT
        $wpdb->insert( $table, $payload );
        return (int) $wpdb->insert_id;
    }

    // =========================================================================
    // Q&A viewer helpers (used by [bmf_qa])
    // =========================================================================

    /**
     * Resolve a human-readable option label from a stored choice_value.
     * Handles the "value|{json-meta}" format written by ajax_save_answer.
     */
    public static function resolve_choice_label( $choice_value, $choices_json = null, $options_string = null ): string {
        $raw = (string) $choice_value;

        // Strip meta payload if present: "value|{json}"
        if ( strpos( $raw, '|' ) !== false ) {
            $parts = explode( '|', $raw, 2 );
            $raw   = $parts[0];
        }
        $raw = trim( $raw );

        if ( $raw === '' ) {
            return '—';
        }

        // Prefer choices_json (array of {value, label})
        if ( ! empty( $choices_json ) ) {
            $choices = is_string( $choices_json ) ? json_decode( $choices_json, true ) : $choices_json;
            if ( is_array( $choices ) ) {
                foreach ( $choices as $c ) {
                    if ( ! is_array( $c ) ) {
                        continue;
                    }
                    $cval = isset( $c['value'] ) ? (string) $c['value'] : '';
                    // Also compare the part before | on the stored choice side
                    if ( $cval === $raw || (string) ( $c['value'] ?? '' ) === (string) $choice_value ) {
                        return (string) ( $c['label'] ?? $raw );
                    }
                }
            }
        }

        // Fallback: options_string patterns like "0=Never|1=Rarely|2=Sometimes"
        if ( ! empty( $options_string ) && is_string( $options_string ) ) {
            $pairs = preg_split( '/\s*[|,]\s*/', $options_string );
            foreach ( $pairs as $pair ) {
                if ( strpos( $pair, '=' ) !== false ) {
                    list( $k, $v ) = array_map( 'trim', explode( '=', $pair, 2 ) );
                    if ( (string) $k === $raw ) {
                        return $v !== '' ? $v : $raw;
                    }
                }
            }
        }

        return $raw;
    }

    /**
     * Submitted responses for a user + form (newest first).
     * Returns array of objects: id, submitted_at, status, version.
     */
    public static function get_submitted_responses_for_user( int $user_id, int $form_id ): array {
        if ( $user_id <= 0 || $form_id <= 0 ) {
            return [];
        }

        global $wpdb;
        $t = $wpdb->prefix . 'bm_responses';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, submitted_at, status, version
                 FROM {$t}
                 WHERE user_id = %d
                   AND form_id = %d
                   AND status = 'submitted'
                 ORDER BY submitted_at DESC, id DESC",
                $user_id,
                $form_id
            )
        );

        return $rows ?: [];
    }

    /**
     * Full Q&A payload for one response, grouped by section (order_index).
     *
     * Returns:
     * [
     *   'response' => {id, submitted_at, ...},
     *   'form'     => {id, title, slug},
     *   'sections' => [
     *     [
     *       'id', 'title', 'order_index',
     *       'questions' => [
     *         ['id','code','prompt','type','order_index',
     *          'choice_value','answer_label','free_text','score']
     *       ]
     *     ], ...
     *   ]
     * ]
     */
    public static function get_response_qa( int $response_id ): ?array {
        if ( $response_id <= 0 ) {
            return null;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $response = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}bm_responses WHERE id = %d LIMIT 1",
                $response_id
            )
        );
        if ( ! $response ) {
            return null;
        }

        $form = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, title, slug FROM {$p}bm_forms WHERE id = %d LIMIT 1",
                (int) $response->form_id
            )
        );

        $sections = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, order_index, choices_json, options_string
                 FROM {$p}bm_form_sections
                 WHERE form_id = %d
                 ORDER BY order_index ASC",
                (int) $response->form_id
            )
        ) ?: [];

        // All answers for this response, keyed by question_id
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT question_id, choice_value, free_text, score
                 FROM {$p}bm_response_items
                 WHERE response_id = %d",
                $response_id
            )
        ) ?: [];

        $answers = [];
        foreach ( $items as $it ) {
            $answers[ (int) $it->question_id ] = $it;
        }

        $out_sections = [];

        foreach ( $sections as $sec ) {
            $questions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, code, prompt, type, order_index, choices_json, options_string
                     FROM {$p}bm_questions
                     WHERE section_id = %d
                     ORDER BY order_index ASC",
                    (int) $sec->id
                )
            ) ?: [];

            $q_out = [];
            foreach ( $questions as $q ) {
                $ans = $answers[ (int) $q->id ] ?? null;

                $choice_value = $ans ? (string) $ans->choice_value : '';
                $free_text    = $ans ? (string) $ans->free_text : '';
                $score        = ( $ans && $ans->score !== null ) ? (float) $ans->score : null;

                // Prefer question-level choices, fall back to section-level
                $choices_src = ! empty( $q->choices_json ) ? $q->choices_json : $sec->choices_json;
                $options_src = ! empty( $q->options_string ) ? $q->options_string : $sec->options_string;

                $label = self::resolve_choice_label( $choice_value, $choices_src, $options_src );

                // For rank / multi-value answers, try to expand each token
                if ( $q->type === 'rank' || strpos( $choice_value, ',' ) !== false ) {
                    $tokens = array_filter( array_map( 'trim', explode( ',', $choice_value ) ) );
                    if ( count( $tokens ) > 1 ) {
                        $labels = [];
                        foreach ( $tokens as $tok ) {
                            $labels[] = self::resolve_choice_label( $tok, $choices_src, $options_src );
                        }
                        $label = implode( ' → ', $labels );
                    }
                }

                // Prefer free_text when present and choice is empty
                if ( $free_text !== '' && ( $choice_value === '' || $label === '—' ) ) {
                    $label = $free_text;
                } elseif ( $free_text !== '' && $label !== $free_text ) {
                    // Show both when useful
                    $label = $label . ( $label !== '—' ? ' — ' : '' ) . $free_text;
                }

                $q_out[] = [
                    'id'            => (int) $q->id,
                    'code'          => (string) ( $q->code ?? '' ),
                    'prompt'        => (string) $q->prompt,
                    'type'          => (string) $q->type,
                    'order_index'   => (int) $q->order_index,
                    'choice_value'  => $choice_value,
                    'answer_label'  => $label,
                    'free_text'     => $free_text,
                    'score'         => $score,
                ];
            }

            $out_sections[] = [
                'id'          => (int) $sec->id,
                'title'       => (string) $sec->title,
                'order_index' => (int) $sec->order_index,
                'questions'   => $q_out,
            ];
        }

        return [
            'response' => [
                'id'           => (int) $response->id,
                'user_id'      => (int) $response->user_id,
                'form_id'      => (int) $response->form_id,
                'status'       => (string) $response->status,
                'submitted_at' => (string) ( $response->submitted_at ?? '' ),
                'version'      => (int) $response->version,
            ],
            'form' => [
                'id'    => $form ? (int) $form->id : 0,
                'title' => $form ? (string) $form->title : '',
                'slug'  => $form ? (string) $form->slug : '',
            ],
            'sections' => $out_sections,
        ];
    }

}
