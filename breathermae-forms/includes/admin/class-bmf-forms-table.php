<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BMF_Forms_Table extends WP_List_Table {

    protected BMF_Repository $repo;

    public function __construct( BMF_Repository $repo ) {
        parent::__construct( [
            'singular' => 'form',
            'plural'   => 'forms',
            'ajax'     => false,
        ] );

        $this->repo = $repo;
    }

    public function get_columns(): array {
        return [
            'title'     => 'Title',
            'slug'      => 'Slug',
            'form_tag'  => 'Form Tag',
            'version'   => 'Version',
            'status'    => 'Status',
            'updated'   => 'Updated',
        ];
    }


    public function prepare_items(): void {
        $forms = BMF_Repository::get_all_forms();

        $this->items = $forms;

        $this->_column_headers = [
            $this->get_columns(),
            [],
            [],
        ];
    }

    protected function column_default( $item, $column_name ) {
        return esc_html( $item->{$column_name} ?? '' );
    }

    protected function column_title( $item ): string {

        $edit_url = add_query_arg([
            'page' => 'bmf-forms',
            'edit' => $item->id,
        ], admin_url('admin.php'));

        $export_url = add_query_arg([
            'page'   => 'bmf-forms',
            'action' => 'export',
            'form'   => $item->id,
        ], admin_url('admin.php'));

        $actions = [
            'edit' => sprintf('<a href="%s">Edit</a>', esc_url($edit_url)),
            'export' => sprintf('<a href="%s">Export</a>', esc_url($export_url)),
        ];

        return sprintf(
            '<strong>%s</strong> %s',
            esc_html($item->title),
            $this->row_actions($actions)
        );
    }

}