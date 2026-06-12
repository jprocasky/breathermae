<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BMF_Form_Controller extends BMF_Admin_Controller {

    protected string $page_slug = 'bmf_forms';

    protected BMF_Repository $repo;

    public function __construct( BMF_Repository $repo ) {
        $this->repo = $repo;
    }

    public function register_page(): void {
        // Menu registration is handled centrally in breathermae-forms.php
    }

    public function render_page(): void {
        $this->render_notices();

        require __DIR__ . '/../views/forms-list.php';
    }

    protected function save(): void {
        $data = [
            'id'          => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : null,
            'title'       => sanitize_text_field( $_POST['title'] ?? '' ),
            'slug'        => sanitize_title( $_POST['slug'] ?? '' ),
            'form_tag'    => sanitize_key( $_POST['form_tag'] ?? '' ),
            'description' => sanitize_textarea_field( $_POST['description'] ?? '' ),
            'status'      => sanitize_key( $_POST['status'] ?? 'draft' ),
        ];

        if ( empty( $data['title'] ) ) {
            throw new Exception( 'Form title is required.' );
        }

        $slug = $data['slug'] ?? '';

        if ( empty( $slug ) ) {
            throw new Exception( 'Form slug is required.' );
        }

        BMF_Repository::upsert_form( $slug, $data );
    }
}