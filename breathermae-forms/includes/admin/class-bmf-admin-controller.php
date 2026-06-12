<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class BMF_Admin_Controller {

    protected string $page_slug;
    protected string $capability = 'read';

    /**
     * Register hooks for this controller
     */
    public function register(): void {
        add_action( 'admin_menu', [ $this, 'register_page' ] );
        add_action( 'admin_post_' . $this->page_slug . '_save', [ $this, 'handle_save' ] );
    }

    /**
     * Add submenu page (child classes define placement)
     */
    abstract public function register_page(): void;

    /**
     * Render admin page
     */
    abstract public function render_page(): void;

    /**
     * Validate and save data
     */
    abstract protected function save(): void;

    /**
     * POST handler wrapper
     */
    public function handle_save() {



        if ( ! current_user_can( $this->capability ) ) {
            wp_die( 'Unauthorized', 403 );
        }

        check_admin_referer( $this->page_slug . '_save', '_wpnonce', false );

        try {
            $this->save();

            wp_redirect(
                add_query_arg(
                    [
                        'page'       => $this->page_slug,
                        'form_id'    => absint( $_POST['form_id'] ?? 0 ),
                        'section_id' => absint( $_POST['section_id'] ?? 0 ),
                    ],
                    admin_url( 'admin.php' )
                )
            );
            exit;

        } catch ( Throwable $e ) {

            wp_die(
                '<pre>' . esc_html( $e->getMessage() ?: 'Empty exception message' )
                . "\n\n" . esc_html( $e->getFile() )
                . ':' . esc_html( $e->getLine() )
                . '</pre>',
                'Save failed'
            );
        }
    }


    /**
     * Redirect helpers
     */
    protected function redirect_success(): void {
        wp_safe_redirect(
            add_query_arg(
                [ 'message' => 'saved' ],
                wp_get_referer()
            )
        );
        exit;
    }

    protected function redirect_error( string $error ): void {
        wp_safe_redirect(
            add_query_arg(
                [
                    'message' => 'error',
                    'bmf_error' => rawurlencode( $error )
                ],
                wp_get_referer()
            )
        );
        exit;
    }

    /**
     * Notice renderer (call from render_page)
     */
    protected function render_notices(): void {
        if ( empty( $_GET['message'] ) ) {
            return;
        }

        if ( $_GET['message'] === 'saved' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
        }

        if ( $_GET['message'] === 'error' && ! empty( $_GET['bmf_error'] ) ) {
            echo '<div class="notice notice-error"><p>' .
                 esc_html( wp_unslash( $_GET['bmf_error'] ) ) .
                 '</p></div>';
        }
    }
}