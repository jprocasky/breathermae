<?php
if (!defined('ABSPATH')) exit;

class BMF_Importer {

    public static function render_admin(){
        if (!current_user_can('manage_options')) return;

        if (!empty($_POST['bmf_import_nonce']) && wp_verify_nonce($_POST['bmf_import_nonce'], 'bmf_import')) {
            self::handle_import();
        }

        echo '<div class="wrap"><h1>CSV Import (Overwrite by Slug)</h1>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('bmf_import','bmf_import_nonce');
        echo '<p><input type="file" name="bmf_csv" accept=".csv,.txt" required></p>';
        echo '<p><label><input type="checkbox" name="bmf_overwrite" value="1" checked> Overwrite existing forms (bump version and replace sections/questions)</label></p>';
        echo '<p><button class="button button-primary">Import</button></p>';
        echo '</form></div>';
    }

        private static function parse_options_string($str){
            if (!$str) return [];

            $out = [];

            // ✅ Split ONLY on comma followed by a word and NOT inside JSON
            // This uses a safe regex to split ONLY between options
            $options = preg_split('/,(?=[^,]*?\\|)/', $str);

            foreach ($options as $opt){

                $opt = trim($opt);

                // ✅ Split into 3 parts max
                $parts = explode('|', $opt, 3);

                if (count($parts) < 2) continue;

                $label = trim($parts[0]);
                $value = trim($parts[1]);
                $meta  = [];

                if (isset($parts[2])) {
                    $json = trim($parts[2]);

                    $decoded = json_decode($json, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        $meta = $decoded;
                    } else {
                        bm_log('JSON DECODE FAILED | ' . $json);
                    }
                }

                $out[] = array_merge([
                    'label' => $label,
                    'value' => $value,
                ], $meta);
            }

            return $out;
        }

    public static function handle_import(){
        if (empty($_FILES['bmf_csv']['tmp_name'])) {
            echo '<div class="error"><p>No file uploaded.</p></div>';
            return;
        }

        $fh = fopen($_FILES['bmf_csv']['tmp_name'], 'r');
        if (!$fh){
            echo '<div class="error"><p>Unable to read file.</p></div>';
            return;
        }

        $header = fgetcsv($fh);
        $map = array_flip($header);

        $required = [
            'form_slug','form_title',
            'section_order','section_title',
            'question_order','question_prompt',
            'question_required','question_type'
        ];

        foreach ($required as $r){
            if (!isset($map[$r])) {
                echo '<div class="error"><p>Missing column: '.esc_html($r).'</p></div>';
                fclose($fh);
                return;
            }
        }

        $rows = [];
        while (($row = fgetcsv($fh)) !== false){
            if (!is_array($row) || count($row) < count($map)) continue; // ✔ skip blank/malformed
            $rows[] = $row;
        }
        fclose($fh);

        $ctx = [
            'form_tag' => null,
            'form_slug'=> null,
            'form_title'=> null,
            'section_order'=> null,
            'section_title'=> null,
            'section_explanation'=> null,
            'section_prompt'=> null,
            'section_options_string'=> null,
            'section_formula'=> null,
            'section_formula_meta_json'=> null
        ];

        $by_form = [];

        foreach ($rows as $r){

            foreach ($ctx as $k => $v){
                if (isset($map[$k]) && isset($r[$map[$k]])) {
                    $val = trim((string)$r[$map[$k]]);
                    if ($val !== '') $ctx[$k] = $val;
                }
            }

            if (empty($ctx['form_slug']) || empty($ctx['form_title'])) continue;
            if (empty($ctx['section_order']) || empty($ctx['section_title'])) continue;
            if (empty($r[$map['question_order']]) || empty($r[$map['question_prompt']])) continue;

            $slug   = sanitize_title($ctx['form_slug']);
            $sorder = intval($ctx['section_order']);

            if (!isset($by_form[$slug])) {
                $by_form[$slug] = [];
            }

            if (!isset($by_form[$slug][$sorder])) {

                $section_options = $ctx['section_options_string'] ?? '';
                $section_choices = self::parse_options_string($section_options);

                $by_form[$slug][$sorder] = [
                    'form_tag'   => trim($ctx['form_tag']),
                    'form_title' => $ctx['form_title'],
                    'section' => [
                        'title'        => $ctx['section_title'],
                        'explanation'  => $ctx['section_explanation'] ?? null,
                        'prompt'       => $ctx['section_prompt'] ?? null,
                        'order_index'  => $sorder,
                        'options_string'=> $section_options,
                        'choices_json' => wp_json_encode($section_choices),
                        'formula'      => $ctx['section_formula'] ?? null,
                        'formula_meta' => $ctx['section_formula_meta_json'] ?? null,
                    ],
                    'questions' => []
                ];
            }

            // ✅ QUESTION‑LEVEL OPTIONS RESOLUTION
            $question_options = '';
            if (isset($map['question_options_string']) && isset($r[$map['question_options_string']])) {
                $question_options = trim((string)$r[$map['question_options_string']]);
            }

            if ($question_options !== '') {
                $resolved_options = $question_options;
            } else {
                $resolved_options = $by_form[$slug][$sorder]['section']['options_string'] ?? '';
            }

            $choices = self::parse_options_string($resolved_options);

            $by_form[$slug][$sorder]['questions'][] = [
                'order_index'    => intval($r[$map['question_order']]),
                'code'           => isset($map['question_code']) && $r[$map['question_code']] !== '' ? $r[$map['question_code']] : null,
                'prompt'         => $r[$map['question_prompt']],
                'required'       => intval($r[$map['question_required']]) === 1,
                'type'           => $r[$map['question_type']],
                'options_string' => $resolved_options ?: null,
                'choices_json'   => wp_json_encode($choices),
                'meta_json'      => null
            ];
        }

        $sections = 0;
        $questions = 0;

        foreach ($by_form as $slug => $sections_map){

            $first = reset($sections_map);
            $form_id = BMF_Repository::upsert_form($slug, [
                'form_tag' => $first['form_tag'],
                'title'    => $first['form_title'],
                'status'   => 'published'
            ]);

            BMF_Repository::clear_sections_questions($form_id);

            ksort($sections_map);

            foreach ($sections_map as $bundle){
                $sec_id = BMF_Repository::insert_section($form_id, $bundle['section']);
                $sections++;

                usort($bundle['questions'], fn($a,$b) => $a['order_index'] <=> $b['order_index']);

                foreach ($bundle['questions'] as $q){
                    BMF_Repository::insert_question($form_id, $sec_id, $q);
                    $questions++;
                }
            }
        }

        echo '<div class="updated"><p>Import complete. Forms: '.count($by_form).', Sections: '.$sections.', Questions: '.$questions.'.</p></div>';
    }
}