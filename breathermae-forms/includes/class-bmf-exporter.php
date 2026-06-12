<?php
if (!defined('ABSPATH')) exit;

class BMF_Exporter {

    public static function export_forms_csv(array $form_ids = []) {

        // Output headers for download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=bmf-export-' . date('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');

        // ✅ MATCH IMPORTER HEADER EXACTLY
        $header = [

            'form_tag',
            'form_slug',
            'form_title',

            'section_order',
            'section_title',
            'section_explanation',
            'section_prompt',
            'section_options_string',
            'section_formula',
            'section_formula_meta_json',

            'question_order',
            'question_code',
            'question_prompt',
            'question_required',
            'question_type',
            'question_options_string'
        ];

        fputcsv($out, $header);

        // ✅ Get forms
        $forms = empty($form_ids)
            ? BMF_Repository::get_all_forms()
            : array_map(fn($id) => BMF_Repository::get_form($id), $form_ids);

        foreach ($forms as $form) {

            $sections = BMF_Repository::get_sections_by_form($form->id);

            foreach ($sections as $section) {

                $questions = BMF_Repository::get_questions_by_section($section->id);

                foreach ($questions as $q) {

                    fputcsv($out, [

                        // FORM
                        $form->form_tag ?? '',
                        $form->slug,
                        $form->title,
                        

                        // SECTION
                        $section->order_index,
                        $section->title,
                        $section->explanation ?? '',
                        $section->prompt ?? '',
                        $section->options_string ?? '',
                        $section->formula ?? '',
                        $section->formula_meta ?? '',

                        // QUESTION
                        $q->order_index,
                        $q->code ?? '',
                        $q->prompt,
                        $q->required ? 1 : 0,
                        $q->type,
                        $q->options_string ?? ''
                    ]);
                }
            }
        }

        fclose($out);
        exit;
    }
}