<?php
    if ( ! defined( 'ABSPATH' ) ) {
        exit;
    }

class ULS_AI_File_Summaries {

        const DB_VERSION = '1.2.1';
        const OCR_DB_VERSION = '1.0.0';
        const OCR_MIN_TEXT_LENGTH = 250;
        const DOC_TYPE_DB_VERSION = '1.0.0';

        private static $instance = null;
        private $table;
        private $ocr_table;
        private $doc_type_table;

        public static function instance() {
            if ( self::$instance === null ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            global $wpdb;
            $this->table = $wpdb->prefix . 'ai_file_summaries';
            $this->ocr_table = $wpdb->prefix . 'ai_ocr_text';
            $this->doc_type_table = $wpdb->prefix . 'ai_document_types';

            add_action( 'init', [ $this, 'maybe_upgrade_schema' ] );
            add_action( 'wp_ajax_uls_get_ai_file_summary', [ $this, 'ajax_get_ai_file_summary' ] );
            add_action( 'wp_ajax_uls_generate_ai_file_summary', [ $this, 'ajax_generate_ai_file_summary' ] );
            add_action('init', [$this, 'maybe_upgrade_ocr_schema']);
            add_action('init', [$this, 'maybe_upgrade_doc_type_schema']);

        }

        public function maybe_upgrade_schema() {

            $opt = 'uls_ai_file_summaries_db_version';
            $installed = get_option( $opt );

            if ( $installed === self::DB_VERSION ) {
                return;
            }

            global $wpdb;
            $charset = $wpdb->get_charset_collate();
            $table = $this->table;

            $sql = "
            CREATE TABLE IF NOT EXISTS `$table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `file_id` BIGINT UNSIGNED NOT NULL,
                `document_type_id` BIGINT UNSIGNED NOT NULL,
                `context` VARCHAR(50) NOT NULL,
                `summary_text` LONGTEXT NOT NULL,
                `summary_hash` CHAR(32) NOT NULL,
                `file_hash` CHAR(32) NOT NULL,
                `model_used` VARCHAR(50) NOT NULL DEFAULT 'gpt-4o',
                `prompt_version` VARCHAR(20) NOT NULL DEFAULT 'v1',
                `created_by` BIGINT UNSIGNED NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_file_context` (`file_id`, `context`),
                KEY `idx_file` (`file_id`),
                KEY `idx_context` (`context`)
            ) $charset;
            ";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );

            update_option( $opt, self::DB_VERSION );
        }        

        public function maybe_upgrade_ocr_schema() {
        $option = 'uls_ai_ocr_text_db_version';
        $installed = get_option($option);

        if ($installed === self::OCR_DB_VERSION) {
            return;
        }

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table = $this->ocr_table;

        $sql = "
            CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            file_id BIGINT UNSIGNED NOT NULL,
            file_hash CHAR(32) NOT NULL,
            ocr_text LONGTEXT NOT NULL,
            engine VARCHAR(50) NOT NULL DEFAULT 'azure-read',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY uniq_file_hash (file_id, file_hash),
            KEY idx_file_id (file_id),
            KEY idx_created_at (created_at)
            ) {$charset};
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option($option, self::OCR_DB_VERSION);
        }        
        
        public function maybe_upgrade_doc_type_schema() {
            $option = 'uls_ai_doc_type_db_version';
            $installed = get_option($option);

            if ($installed === self::DOC_TYPE_DB_VERSION) {
                return;
            }

            global $wpdb;
            $table = $this->doc_type_table;
            $charset = $wpdb->get_charset_collate();

            $sql = "
            CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(50) NOT NULL,
                label VARCHAR(100) NOT NULL,
                description TEXT NULL,
                prompt_template LONGTEXT NOT NULL,
                model VARCHAR(50) NOT NULL DEFAULT 'gpt-4o',
                temperature DECIMAL(3,2) NOT NULL DEFAULT 0.20,
                max_tokens INT NOT NULL DEFAULT 800,
                is_medical TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 10,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_slug (slug),
                KEY idx_active (is_active),
                KEY idx_sort (sort_order)
            ) {$charset};
            ";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);

            update_option($option, self::DOC_TYPE_DB_VERSION);

            $this->seed_default_document_types();
        }


        protected function seed_default_document_types(): void {
            global $wpdb;

            $table = $this->doc_type_table;

            $exists = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            if ($exists > 0) {
                return;
            }

            $wpdb->insert($table, [
                'slug' => 'medical_clinical',
                'label' => 'Medical – Clinical Data',
                'description' => 'Medical documents such as lab reports and clinical records. No interpretation.',
                'prompt_template' =>
        "You are analyzing a MEDICAL DOCUMENT.

        IMPORTANT SAFETY RULES (MANDATORY):
        - Do NOT provide medical advice, conclusions, diagnoses, or opinions.
        - Do NOT interpret lab values or trends.
        - Do NOT determine whether values are normal, abnormal, high, or low.
        - Quote all numeric values exactly as written, including units.
        - If ambiguity exists, explicitly state it rather than guessing.

        ROLE CONTEXT:
        You are summarizing this document for a provider acting in the role of: {{context}}.

        OUTPUT FORMAT (STRICT):
        1) Bullet Point Summary
        2) Narrative Summary

        DOCUMENT START
        {{document_text}}
        DOCUMENT END",
                'model' => 'gpt-4o',
                'temperature' => 0.20,
                'max_tokens' => 800,
                'is_medical' => 1,
                'sort_order' => 1,
            ]);

            $wpdb->insert($table, [
                'slug' => 'general',
                'label' => 'General Document',
                'description' => 'Non-medical or administrative documents.',
                'prompt_template' =>
        "You are summarizing a GENERAL DOCUMENT.

        ROLE CONTEXT:
        You are summarizing this document for a provider acting in the role of: {{context}}.

        OUTPUT FORMAT:
        1) Bullet Point Summary
        2) Narrative Summary

        DOCUMENT START
        {{document_text}}
        DOCUMENT END",
                'model' => 'gpt-4o',
                'temperature' => 0.30,
                'max_tokens' => 800,
                'is_medical' => 0,
                'sort_order' => 10,
            ]);
        }

        /* Helper functions for managing summaries */
        public function get_summary( int $file_id, string $context ) {
            global $wpdb;

            $context = sanitize_key( $context );

            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT *
                    FROM {$this->table}
                    WHERE file_id = %d
                    AND context = %s
                    LIMIT 1",
                    $file_id,
                    $context
                ),
                ARRAY_A
            );
        }

        public function summary_exists( int $file_id, string $context ): bool {
            global $wpdb;

            $context = sanitize_key( $context );

            $found = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT 1
                    FROM {$this->table}
                    WHERE file_id = %d
                    AND context = %s
                    LIMIT 1",
                    $file_id,
                    $context
                )
            );

            return (bool) $found;
        }

        public function is_summary_stale(
            int $file_id,
            string $context,
            string $current_file_hash
        ): bool {
            global $wpdb;

            $context = sanitize_key( $context );

            $stored_hash = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT file_hash
                    FROM {$this->table}
                    WHERE file_id = %d
                    AND context = %s
                    LIMIT 1",
                    $file_id,
                    $context
                )
            );

            if ( ! $stored_hash ) {
                // No summary exists → effectively stale
                return true;
            }

            return hash_equals( $stored_hash, $current_file_hash ) === false;
        }

        public function get_summary_status(
            int $file_id,
            string $context,
            string $current_file_hash
        ): array {

            $summary = $this->get_summary( $file_id, $context );

            if ( ! $summary ) {
                return [
                    'exists' => false,
                    'stale'  => true,
                ];
            }

            return [
                'exists' => true,
                'stale'  => ! hash_equals( $summary['file_hash'], $current_file_hash ),
            ];
        }

        public function save_summary( array $args ): int {

            $defaults = [
                'file_id'       => 0,
                'context'       => '',
                'summary_text'  => '',
                'file_hash'     => '',
                'summary_hash'  => '',
                'model_used'    => 'gpt-4o',
                'prompt_version'=> 'v1',
                'created_by'    => 0,
            ];

            $data = wp_parse_args( $args, $defaults );

            if (
                ! $data['file_id'] ||
                ! $data['context'] ||
                ! $data['summary_text'] ||
                ! $data['file_hash'] ||
                ! $data['created_by']
            ) {
                throw new InvalidArgumentException( 'Missing required summary fields.' );
            }

            $data['context'] = sanitize_key( $data['context'] );
            $data['summary_hash'] = $data['summary_hash'] ?: md5( $data['summary_text'] );

            if ( $this->summary_exists( $data['file_id'], $data['context'] ) ) {
                return $this->update_summary( $data );
            }

            return $this->insert_summary( $data );
        }

        protected function insert_summary( array $data ): int {
            global $wpdb;

            $wpdb->insert(
                $this->table,
                [
                    'file_id'        => (int) $data['file_id'],
                    'document_type_id' => (int) $data['document_type_id'],
                    'context'        => $data['context'],
                    'summary_text'   => $data['summary_text'],
                    'summary_hash'   => $data['summary_hash'],
                    'file_hash'      => $data['file_hash'],
                    'model_used'     => $data['model_used'],
                    'prompt_version' => $data['prompt_version'],
                    'created_by'     => (int) $data['created_by'],
                    'created_at'     => current_time( 'mysql' ),
                ],

                [
                    '%d', // file_id
                    '%d', // document_type_id
                    '%s', // context
                    '%s', // summary_text
                    '%s', // summary_hash
                    '%s', // file_hash
                    '%s', // model_used
                    '%s', // prompt_version
                    '%d', // created_by
                    '%s', // created_at
                ]

            );

            if ( ! $wpdb->insert_id ) {
                throw new RuntimeException( 'Failed to insert AI summary.' );
            }

            return (int) $wpdb->insert_id;
        }

        protected function update_summary( array $data ): int {
            global $wpdb;

            $result = $wpdb->update(
                $this->table,
                [
                    'document_type_id' => (int) $data['document_type_id'],    
                    'summary_text'   => $data['summary_text'],
                    'summary_hash'   => $data['summary_hash'],
                    'file_hash'      => $data['file_hash'],
                    'model_used'     => $data['model_used'],
                    'prompt_version' => $data['prompt_version'],
                    'updated_at'     => current_time( 'mysql' ),
                ],
                [
                    'file_id' => (int) $data['file_id'],
                    'context' => $data['context'],
                ],
                [
                    '%s', '%s', '%s', '%s', '%s', '%s'
                ],
                [
                    '%d', '%s'
                ]
            );

            if ( $result === false ) {
                throw new RuntimeException( 'Failed to update AI summary.' );
            }

            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table} WHERE file_id = %d AND context = %s",
                    $data['file_id'],
                    $data['context']
                )
            );
        }

        /* AJAX handler for fetching AI summary data for a file + context */
        public function ajax_get_ai_file_summary() {
            global $wpdb;
            check_ajax_referer( 'uls_member_files', 'nonce' );

            if ( ! is_user_logged_in() ) {
                wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
            }

            $file_id = isset( $_POST['file_id'] ) ? (int) $_POST['file_id'] : 0;
            $context = isset( $_POST['context'] ) ? sanitize_key( $_POST['context'] ) : '';

            if ( ! $file_id || ! $context ) {
                wp_send_json_error( [ 'message' => 'Missing file_id or context' ], 400 );
            }

            // 🔐 Permission check: reuse the files module’s rules
            // We DO NOT re‑implement access logic here.
            $can_view = apply_filters(
                'uls_member_files_can_view_ai_summary',
                true,
                get_current_user_id(),
                $file_id,
                $context
            );

            if ( ! $can_view ) {
                wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
            }

            $summary = $this->get_summary( $file_id, $context );

            if ( ! $summary ) {
                wp_send_json_error(
                    [ 'message' => 'Summary not found' ],
                    404
                );
            }

            $doc_type_label = '';

            if ( ! empty( $summary['document_type_id'] ) ) {
                global $wpdb;
                $doc_type_label = (string) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT label
                        FROM {$this->doc_type_table}
                        WHERE id = %d
                        LIMIT 1",
                        (int) $summary['document_type_id']
                    )
                );
            }            

            wp_send_json_success( [
                'file_id'        => (int) $summary['file_id'],
                'context'        => $summary['context'],
                'summary_text'   => $summary['summary_text'],
                'model_used'     => $summary['model_used'],
                'prompt_version' => $summary['prompt_version'],
                'document_type_label' => $doc_type_label,
                'created_by'     => (int) $summary['created_by'],
                'created_at'     => $summary['created_at'],
                'updated_at'     => $summary['updated_at'],
            ] );
        }

        public function ajax_generate_ai_file_summary() {

            check_ajax_referer( 'uls_member_files', 'nonce' );

            if ( ! is_user_logged_in() ) {
                wp_send_json_error(['message'=>'Unauthorized'],401);
            }

ULS_Members_Plugin::instance()->log_debug('AI STEP 1: generate endpoint entered');            
            $file_id = (int)($_POST['file_id'] ?? 0);
            $context = sanitize_key($_POST['context'] ?? '');
            $document_type_id = (int)($_POST['document_type_id'] ?? 0);

            if (!$file_id || !$context) {
                wp_send_json_error(['message'=>'Missing parameters'],400);
            }

            // 🔐 permissions
            if ( ! apply_filters(
                'uls_member_files_can_generate_ai_summary',
                true,
                get_current_user_id(),
                $file_id,
                $context
            )) {
                wp_send_json_error(['message'=>'Forbidden'],403);
            }

            global $wpdb;
            
            // Validate document type and fall back to 'general' if invalid
            $doc_type = $this->get_document_type_by_id($document_type_id);

            if (!$doc_type) {
                
                $doc_type = $wpdb->get_row(
                    "SELECT *
                    FROM {$this->doc_type_table}
                    WHERE slug = 'general'
                    LIMIT 1",
                    ARRAY_A
                );
            }

            if (!$doc_type) {
                throw new RuntimeException('Document type configuration not found.');
            }

            $files_table = $wpdb->prefix . 'member_files';

            $file = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT file_path, mime_type FROM {$files_table}
                    WHERE id = %d AND is_deleted = 0",
                    $file_id
                ),
                ARRAY_A
            );

            if (!$file || !file_exists($file['file_path'])) {
                wp_send_json_error(['message'=>'File not found'],404);
            }

            try {

                $file_hash = md5_file( $file['file_path'] );
ULS_Members_Plugin::instance()->log_debug('AI STEP 2: file hash computed');                
                $text = $this->extract_file_text_with_ocr(
                    $file_id,
                    $file['file_path'],
                    $file['mime_type']
                );
ULS_Members_Plugin::instance()->log_debug('AI STEP 3: text extracted, length=' . strlen($text));
/*                 $prompt = $this->build_prompt( $context, $text );
                $summary = $this->call_azure_openai( $prompt ); */

                $prompt = str_replace(
                    ['{{context}}', '{{document_text}}'],
                    [ucfirst($context), $text],
                    $doc_type['prompt_template']
                );

                $summary = $this->call_azure_openai($prompt, [
                    'model' => $doc_type['model'],
                    'temperature' => (float) $doc_type['temperature'],
                    'max_tokens' => (int) $doc_type['max_tokens'],
                ]);

ULS_Members_Plugin::instance()->log_debug('AI STEP 5: OpenAI call succeeded');


                $this->save_summary([
                    'file_id'      => $file_id,
                    'document_type_id' => $document_type_id,
                    'context'      => $context,
                    'summary_text' => $summary,
                    'file_hash'    => $file_hash,
                    'created_by'   => get_current_user_id(),
                ]);
ULS_Members_Plugin::instance()->log_debug('AI STEP 6: summary saved');
                wp_send_json_success([
                    'message' => 'AI summary generated successfully'
                ]);

            } 
            catch ( Throwable $e ) {

                ULS_Members_Plugin::instance()->log_debug('AI SUMMARY ERROR');
                ULS_Members_Plugin::instance()->log_debug('Message: ' . $e->getMessage());
                ULS_Members_Plugin::instance()->log_debug('File: ' . $e->getFile());
                ULS_Members_Plugin::instance()->log_debug('Line: ' . $e->getLine());
                ULS_Members_Plugin::instance()->log_debug('Trace: ' . $e->getTraceAsString());

                wp_send_json_error([
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ], 500);
            }        }


        protected function extract_file_text_with_ocr(
            int $file_id,
            string $file_path,
            string $mime
            ): string {

            $text = $this->extract_native_text($file_path, $mime);

            if (strlen(trim($text)) >= self::OCR_MIN_TEXT_LENGTH) {
                return $text;
            }

            if (!$this->should_attempt_ocr($mime)) {
                throw new RuntimeException(
                'This document does not contain machine-readable text.'
                );
            }

            $file_hash = md5_file($file_path);

            // ✅ OCR cache check
            $cached = $this->get_cached_ocr_text($file_id, $file_hash);
            if ($cached && strlen(trim($cached)) >= self::OCR_MIN_TEXT_LENGTH) {
                return $cached;
            }

            // ✅ OCR execution
            $ocr_text = $this->run_ocr($file_id, $file_path);

            if (strlen(trim($ocr_text)) < self::OCR_MIN_TEXT_LENGTH) {
                throw new RuntimeException(
                'OCR did not yield readable text.'
                );
            }

            // ✅ Cache OCR result
            $this->store_cached_ocr_text($file_id, $file_hash, $ocr_text);

            return $ocr_text;
        }


        // 🚧 Placeholder prompt construction – to be iterated on with actual AI integration
        protected function build_prompt( string $context, string $document_text ): string {

            return sprintf(
                "You are generating a summary for a provider acting in the role of: %s.\n\n".
                "Summarize the document below.\n\n".
                "Return two sections:\n".
                "1. Bullet Points (concise, factual)\n".
                "2. Narrative Summary (short paragraphs)\n\n".
                "Rules:\n".
                "- Do not add medical advice.\n".
                "- Do not speculate beyond the document.\n".
                "- Reflect only what is present.\n\n".
                "DOCUMENT START\n%s\nDOCUMENT END",
                ucfirst($context),
                $document_text
            );
        }

        protected function call_azure_openai(string $prompt, array $config = []): string {

            $endpoint = rtrim(AZURE_OPENAI_ENDPOINT, '/') .
                '/openai/deployments/' . AZURE_OPENAI_DEPLOYMENT .
                '/chat/completions?api-version=' . AZURE_OPENAI_API_VERSION;

            $body = [
                'messages' => [
                    [ 'role' => 'system', 'content' => 'You summarize documents.' ],
                    [ 'role' => 'user', 'content' => $prompt ],
                ],
                'temperature' => $config['temperature'] ?? 0.2,
                'max_tokens' => $config['max_tokens'] ?? 800,
            ];

            $response = wp_remote_post( $endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'api-key'      => AZURE_OPENAI_API_KEY,
                ],
                'body'    => json_encode( $body ),
                'timeout' => 90,
            ]);

            if ( is_wp_error( $response ) ) {
                throw new RuntimeException( $response->get_error_message() );
            }

            $json = json_decode( wp_remote_retrieve_body( $response ), true );

            return $json['choices'][0]['message']['content'] ?? '';
        }
        
        // 🚧 Future enhancement: OCR text caching for scanned PDFs (not currently used in summary generation)
        protected function get_cached_ocr_text(int $file_id, string $file_hash): ?string {
            global $wpdb;
            $table = $wpdb->prefix . 'ai_ocr_text';

            return $wpdb->get_var(
                $wpdb->prepare(
                "SELECT ocr_text
                FROM {$table}
                WHERE file_id = %d AND file_hash = %s
                LIMIT 1",
                $file_id,
                $file_hash
                )
            );
        }
        // 🚧 Future enhancement: OCR text caching for scanned PDFs (not currently used in summary generation)
        protected function store_cached_ocr_text(
            int $file_id,
            string $file_hash,
            string $ocr_text,
            string $engine = 'azure-read'
            ): void {
            global $wpdb;
            $table = $wpdb->prefix . 'ai_ocr_text';

            $wpdb->insert(
                $table,
                [
                'file_id'   => $file_id,
                'file_hash' => $file_hash,
                'ocr_text'  => $ocr_text,
                'engine'    => $engine,
                ],
                ['%d', '%s', '%s', '%s'] 
            );
        }
        // 🚧 Future enhancement: OCR text caching for scanned PDFs (not currently used in summary generation)
        protected function should_attempt_ocr(string $mime): bool {
            return in_array($mime, [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'image/jpeg',
                'image/png',
                'image/tiff',
            ], true);
        }

        // OCR text caching for scanned PDFs (not currently used in summary generation)
        protected function run_ocr(int $file_id, string $file_path): string {

            $endpoint = rtrim(AZURE_DOCINT_ENDPOINT, '/')
                . '/documentintelligence/documentModels/prebuilt-read:analyze'
                . '?api-version=' . AZURE_DOCINT_API_VERSION;

            // STEP 1: Submit OCR job
            $submit = wp_remote_post($endpoint, [
                'headers' => [
                    'Ocp-Apim-Subscription-Key' => AZURE_DOCINT_API_KEY,
                    'Content-Type'              => 'application/json',
                ],

                'body' => json_encode([
                'urlSource' => $this->get_ocr_url($file_id),
                ]),

                'timeout' => 60,
            ]);

            if (is_wp_error($submit)) {
                throw new RuntimeException($submit->get_error_message());
            }

            $operationUrl = wp_remote_retrieve_header($submit, 'operation-location');

            if (!$operationUrl) {
                throw new RuntimeException('OCR submission failed (missing operation-location header).');
            }

            // STEP 2: Poll for result
            for ($i = 0; $i < 20; $i++) {
                sleep(1);

                $poll = wp_remote_get($operationUrl, [
                    'headers' => [
                        'Ocp-Apim-Subscription-Key' => AZURE_DOCINT_API_KEY,
                    ],
                    'timeout' => 60,
                ]);

                if (is_wp_error($poll)) {
                    throw new RuntimeException($poll->get_error_message());
                }

                $json = json_decode(wp_remote_retrieve_body($poll), true);

                if (($json['status'] ?? '') === 'succeeded') {
                    //return trim($json['analyzeResult']['content'] ?? '');
                    // ✅ Filter out rotated / vertical text before returning OCR result
                    return trim(
                        $this->filter_rotated_ocr_text($json['analyzeResult'] ?? [])
                    );
                }

                if (($json['status'] ?? '') === 'failed') {
                    throw new RuntimeException('OCR processing failed.');
                }
            }

            throw new RuntimeException('OCR timed out waiting for completion.');
        }

        protected function filter_rotated_ocr_text(array $analyzeResult): string
        {
            $output = [];

            foreach ($analyzeResult['pages'] ?? [] as $page) {
                foreach ($page['lines'] ?? [] as $line) {

                    $text = trim($line['content'] ?? '');
                    $poly = $line['boundingPolygon'] ?? [];

                    if ($text === '' || $this->is_rotated_polygon($poly)) {
                        // ✅ Skip rotated or vertical headers entirely
                        continue;
                    }

                    $output[] = $text;
                }
            }

            return implode("\n", $output);
        }

        protected function is_rotated_polygon(array $polygon): bool
        {
            if (count($polygon) !== 4) {
                return false; // non‑rectangular, assume normal
            }

            // Typical polygon:
            // [
            //   ['x'=>..., 'y'=>...],
            //   ['x'=>..., 'y'=>...],
            //   ['x'=>..., 'y'=>...],
            //   ['x'=>..., 'y'=>...],
            // ]

            $xValues = array_column($polygon, 'x');
            $yValues = array_column($polygon, 'y');

            $width  = max($xValues) - min($xValues);
            $height = max($yValues) - min($yValues);

            // ✅ Heuristic:
            // If height significantly exceeds width → vertical / rotated text
            return $height > ($width * 1.8);
        }

        protected function get_file_download_url(int $file_id): string {
            return add_query_arg(
                [
                'action' => 'uls_download_member_file',
                'nonce'  => wp_create_nonce('uls_member_files'),
                'id'     => $file_id,
                ],
                admin_url('admin-ajax.php')
            );
        }

        protected function get_ocr_url(int $file_id): string {

            $expires = time() + 60; // valid for 60 seconds

            $sig = hash_hmac(
                'sha256',
                "{$file_id}|{$expires}",
                AUTH_SALT
            );

            return add_query_arg(
                [
                    'uls_ocr_file' => 1,
                    'id'           => $file_id,
                    'expires'      => $expires,
                    'sig'          => $sig,
                ],
                site_url('/')
            );
        }        

        protected function extract_native_text(string $file_path, string $mime): string
            {
            if (!file_exists($file_path)) {
                throw new RuntimeException('File does not exist.');
            }

            switch ($mime) {

                case 'text/plain':
                case 'text/csv':
                    return (string) file_get_contents($file_path);

                case 'application/pdf':
                    // Try system pdftotext if available
                    if (function_exists('shell_exec')) {
                        $cmd = 'pdftotext ' . escapeshellarg($file_path) . ' -';
                        $output = shell_exec($cmd);
                        if (is_string($output)) {
                            return trim($output);
                        }
                    }
                    return '';

                case 'application/msword':
                case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                    // DOC/DOCX native extraction not implemented yet
                    // OCR fallback will handle scanned docs
                    return '';

                default:
                    return '';
            }
        }
        
        protected function get_document_type_by_id(int $id): ?array {
            global $wpdb;

            if ($id <= 0) {
                return null;
            }

            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT *
                    FROM {$this->doc_type_table}
                    WHERE id = %d AND is_active = 1
                    LIMIT 1",
                    $id
                ),
                ARRAY_A
            );
        }        

}
    


ULS_AI_File_Summaries::instance();