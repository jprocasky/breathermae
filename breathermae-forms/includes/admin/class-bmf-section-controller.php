<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BMF_Section_Controller extends BMF_Admin_Controller {

    protected string $page_slug = 'bmf-sections';

    protected BMF_Repository $repo;

    public function __construct( BMF_Repository $repo ) {
        $this->repo = $repo;
    }

    public function register_page(): void {
        // Menu handled centrally
    }

    public function render_page(): void {
        $this->render_notices();
        $repo = $this->repo;
        require __DIR__ . '/../views/sections-list.php';
    }

protected function save(): void
{
    // ============================================
    // Core Section Fields + Formula
    // ============================================
    $data = [
        'id'          => isset($_POST['id']) ? absint($_POST['id']) : null,
        'form_id'     => absint($_POST['form_id']),
        'title'       => sanitize_text_field($_POST['title']),
        'explanation' => sanitize_textarea_field($_POST['explanation'] ?? ''),
        'prompt'      => sanitize_textarea_field($_POST['prompt'] ?? ''),
        'order_index' => absint($_POST['order_index']),
        'formula'     => isset($_POST['formula'])
            ? sanitize_textarea_field(wp_unslash($_POST['formula']))
            : null,
    ];

    if (empty($data['title'])) {
        throw new Exception('Section title is required');
    }

    // ============================================
    // Choices (options_string + choices_json)
    // ============================================
    $options_string = null;
    $choices_json   = null;

    if (
        !empty($_POST['has_choices']) &&
        !empty($_POST['choice_label']) &&
        is_array($_POST['choice_label'])
    ) {
        $pairs = [];
        $json  = [];

        foreach ($_POST['choice_label'] as $i => $label) {
            $label = sanitize_text_field($label);
            $value = sanitize_key($_POST['choice_value'][$i] ?? '');

            if ($label === '' || $value === '') {
                continue;
            }

            $pairs[] = "{$label}|{$value}";
            $json[]  = [
                'label' => $label,
                'value' => $value,
            ];
        }

        if ($pairs) {
            $options_string = implode(',', $pairs);
            $choices_json   = wp_json_encode($json);
        }
    }

    $data['options_string'] = $options_string;
    $data['choices_json']   = $choices_json;

    // ============================================
    // JSON Merge System (formula_meta + meta_json)
    // ============================================
    // Load raw JSON from textareas
    $formula_meta_raw = wp_unslash($_POST['formula_meta_raw'] ?? '');
    $meta_json_raw    = wp_unslash($_POST['meta_json_raw'] ?? '');

    $formula_meta = json_decode($formula_meta_raw, true);
    $meta         = json_decode($meta_json_raw, true);

    // Validate JSON
    if ($formula_meta_raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON in Formula Meta');
    }
    if ($meta_json_raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON in Meta JSON');
    }

    if (!is_array($formula_meta)) $formula_meta = [];
    if (!is_array($meta))         $meta = [];

    // --- Branching rules (UI overrides raw JSON) ---
    if (!empty($_POST['branch_question_code'])) {
        $rules = [];

        if (!empty($_POST['branch_when']) && is_array($_POST['branch_when'])) {
            foreach ($_POST['branch_when'] as $i => $when_raw) {
                $when = array_filter(
                    array_map('sanitize_key', explode(',', $when_raw))
                );

                if (!$when) continue;

                $rules[] = [
                    'when'          => $when,
                    'show_sections' => array_filter(
                        array_map('absint', explode(',', $_POST['branch_show'][$i] ?? ''))
                    ),
                    'hide_sections' => array_filter(
                        array_map('absint', explode(',', $_POST['branch_hide'][$i] ?? ''))
                    ),
                ];
            }
        }

        if ($rules) {
            $formula_meta['branching'] = [
                'question_code' => sanitize_key($_POST['branch_question_code']),
                'rules'         => $rules,
            ];
        }
    }

    // --- Path redirects (UI overrides raw JSON) ---
    $redirects = [];

    if (!empty($_POST['redirect_key']) && is_array($_POST['redirect_key'])) {
        foreach ($_POST['redirect_key'] as $i => $key_raw) {
            $key = sanitize_key($key_raw);
            $url = esc_url_raw(trim($_POST['redirect_url'][$i] ?? ''));

            if ($key && $url) {
                $redirects[$key] = $url;
            }
        }
    }

    if (!empty($redirects)) {
        $meta['path_redirects'] = $redirects;
    }

    // Final JSON values
    $data['formula_meta'] = !empty($formula_meta) ? wp_json_encode($formula_meta) : null;
    $data['meta_json']    = !empty($meta)         ? wp_json_encode($meta)         : null;

    // ============================================
    // Persist to database
    // ============================================
    $this->repo->upsert_section($data);
}
}