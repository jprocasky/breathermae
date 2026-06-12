<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BMF_Question_Controller extends BMF_Admin_Controller {

    protected string $page_slug = 'bmf-questions';
    protected BMF_Repository $repo;

    public function __construct( BMF_Repository $repo ) {
        $this->repo = $repo;
    }

    public function register_page(): void {
        // menu handled centrally
    }

    public function render_page(): void {
        $this->render_notices();
        $repo = $this->repo;

        require __DIR__ . '/../views/questions-list.php';
    }

    protected function save(): void {
        $data = [
            'id'           => isset($_POST['id']) ? absint($_POST['id']) : null,
            'section_id'   => absint($_POST['section_id']),
            'question_code'=> sanitize_text_field($_POST['question_code']),
            'prompt'       => sanitize_textarea_field($_POST['prompt']),
            'question_type'=> sanitize_key($_POST['question_type']),
            'required'     => ! empty($_POST['required']) ? 1 : 0,
            'order_index'  => absint($_POST['order_index']),
        ];

        if ( empty($data['question_code']) ) {
            throw new Exception('Question code is required.');
        }

        if ( empty($data['prompt']) ) {
            throw new Exception('Question prompt is required.');
        }

        // ----------------------------
        // Question choices (override section defaults)
        // ----------------------------
        $options_string = null;
        $choices_json   = null;

        if ( ! empty( $_POST['choice_label'] ) && is_array( $_POST['choice_label'] ) ) {
            $pairs = [];
            $json  = [];

            foreach ( $_POST['choice_label'] as $i => $label ) {

                $label = sanitize_text_field( $label );
                $value = sanitize_key( $_POST['choice_value'][ $i ] ?? '' );

                // ✅ NEW: capture meta JSON
                $meta_raw = $_POST['choice_meta'][ $i ] ?? '';
                $meta_raw = trim( wp_unslash( $meta_raw ) );

                if ( $label === '' || $value === '' ) {
                    continue;
                }

                // -----------------------------------------
                // ✅ Build options_string (NOW SUPPORTS META)
                // -----------------------------------------
                $pair = "{$label}|{$value}";

                if ( ! empty( $meta_raw ) ) {
                    $pair .= "|" . $meta_raw;
                }

                $pairs[] = $pair;

                // -----------------------------------------
                // ✅ Build choices_json (optional enrichment)
                // -----------------------------------------
                $json_item = [
                    'label' => $label,
                    'value' => $value,
                ];

                // Try to decode meta into structured JSON
                if ( ! empty( $meta_raw ) ) {
                    $meta_decoded = json_decode( $meta_raw, true );

                    if ( is_array( $meta_decoded ) ) {
                        $json_item = array_merge( $json_item, $meta_decoded );
                    }
                }

                $json[] = $json_item;
            }


            if ( $pairs ) {
                // ✅ CORRECT separator
                $options_string = implode( ',', $pairs );
                $choices_json   = wp_json_encode( $json );
            }
        }

        $data['options_string'] = $options_string;
        $data['choices_json']   = $choices_json;

        $this->repo->upsert_question( $data );
    }
}