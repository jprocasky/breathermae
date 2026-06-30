<?php
/**
 * Plugin Name: Breathermae Forms
 * Description: Dynamic multi-step forms with section-level choices, CSV importer, autosave, and scoring.
 * Version: 1.1.0
 * Author: Jeff Procasky - Breathermae
 */
if (!defined('ABSPATH')) exit;

final class BMF_Plugin {
    private static $instance = null;
    const VERSION = '0.1.0';

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }


private function __construct() {
    register_activation_hook(__FILE__, [$this, 'activate']);
    add_action('init', [$this, 'init']);
    add_action('admin_menu', [$this, 'admin_menu'], 20);

    add_action('admin_init', function () {

        if (!isset($_GET['action']) || $_GET['action'] !== 'export') {
            return;
        }

        if (!isset($_GET['form'])) {
            return;
        }

        $form_id = intval($_GET['form']);


        BMF_Exporter::export_forms_csv([$form_id]);
    });


        add_action('bmf_response_submitted', function( $response_id ) {

            $response_id = (int) $response_id;
            $user_id = get_current_user_id();

            if ( class_exists('BMF_Path_Resolver') ) {

                bm_log('PATH RESOLVER HOOK ENTER | response_id=' . $response_id);

                $result = BMF_Path_Resolver::determine_path($response_id);

                // ✅ ✅ ADD THIS LINE
                $GLOBALS['bmf_last_path_result'] = $result;

                if ( !empty($result['path']) ) {

                    bm_log(
                        'PATH RESULT | response_id=' . $response_id .
                        ' | path=' . $result['path'] .
                        ' | weights=' . json_encode($result['weights'])
                    );

                } else {

                    bm_log('PATH RESULT EMPTY | response_id=' . $response_id);

                }
            }

            $interp = BMF_Interpreter::interpret($response_id);

            $GLOBALS['bmf_last_interpretation'] = $interp;

            update_user_meta($user_id, 'bmf_interpretation', wp_json_encode($interp));

            bm_log('INTERPRETATION RESULT | ' . json_encode($interp));            

        }, 25);

    // -----------------------------------------
    // ✅ Path-based redirect override
    add_filter('bmf_submit_redirect', function($redirect, $response_id, $form_id, $user_id) {

        global $wpdb;

        $sections = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT formula_meta FROM {$wpdb->prefix}bm_form_sections WHERE form_id = %d ORDER BY order_index ASC",
                $form_id
            )
        );

        $path_redirects = [];

        foreach ($sections as $s) {
            if (empty($s->formula_meta)) continue;

            $meta = json_decode($s->formula_meta, true);

            if (!empty($meta['path_redirects'])) {
                $path_redirects = $meta['path_redirects'];
                break;
            }
        }

        if (empty($path_redirects)) {
            return $redirect;
        }

        if (!class_exists('BMF_Path_Resolver')) {
            return $redirect;
        }

        $result = $GLOBALS['bmf_last_path_result']
            ?? BMF_Path_Resolver::determine_path($response_id);

        if (empty($result['path'])) {
            return $redirect;
        }

        $path = $result['path'];

        // ✅ ✅ FORCE OVERRIDE
        if (!empty($path_redirects[$path])) {

            $new_redirect = $path_redirects[$path];

            bm_log(
                'REDIRECT OVERRIDE | path=' . $path .
                ' | old=' . ($redirect ?: 'null') .
                ' | new=' . $new_redirect
            );

            return $new_redirect; // ✅ THIS MUST WIN
        }

        return $redirect;

    }, 10, 4);

    add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
        $links[] = '<a href="' . admin_url('admin.php?page=bmf-import') . '">CSV Import</a>';
        return $links;
    });

    add_action('wp_enqueue_scripts', [$this, 'enqueue']);

    require_once __DIR__ . '/includes/class-bmf-resolver.php';

    require_once __DIR__ . '/includes/class-bmf-repository.php';
    require_once __DIR__ . '/includes/class-bmf-responses.php';
    require_once __DIR__ . '/includes/class-bmf-importer.php';
    require_once __DIR__ . '/includes/class-bmf-shortcodes.php';
    require_once __DIR__ . '/includes/class-bmf-rest.php';

    require_once __DIR__ . '/includes/class-bmf-views.php';
    require_once __DIR__ . '/includes/class-bmf-views-admin.php';
    
    require_once __DIR__ . '/includes/class-bmf-bsi-scorer.php';
    require_once __DIR__ . '/includes/class-bmf-bsi-saver.php';
    require_once __DIR__ . '/includes/class-bmf-section-scorer.php';
    require_once __DIR__ . '/includes/class-bmf-interpreter.php';
    require_once __DIR__ . '/includes/class-bmf-exporter.php';
    require_once __DIR__ . '/includes/class-bmf-pillars-saver.php';

    if ( file_exists( __DIR__ . '/includes/bmf-bsi-sections-shortcodes.php' ) ) {
        require_once __DIR__ . '/includes/bmf-bsi-sections-shortcodes.php';
    }

    if ( file_exists( __DIR__ . '/includes/bmf-bsi-forms-shortcodes.php' ) ) {
        require_once __DIR__ . '/includes/bmf-bsi-forms-shortcodes.php';
    }

    if ( file_exists( __DIR__ . '/includes/bmf-form-info-shortcodes.php' ) ) {
        require_once __DIR__ . '/includes/bmf-form-info-shortcodes.php';
    }

    // RSI
    if ( file_exists(__DIR__ . '/includes/class-bmf-rsi-scorer.php') ) {
        require_once __DIR__ . '/includes/class-bmf-rsi-scorer.php';
    }

    if ( file_exists(__DIR__ . '/includes/class-bmf-rsi-saver.php') ) {
        require_once __DIR__ . '/includes/class-bmf-rsi-saver.php';
    }

    if ( file_exists( __DIR__ . '/includes/bmf-rsi-forms-shortcodes.php' ) ) {
        require_once __DIR__ . '/includes/bmf-rsi-forms-shortcodes.php';
    }

    // ✅ ADMIN CONTROLLERS
    if ( is_admin() ) {

        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

        require_once __DIR__ . '/includes/admin/class-bmf-admin-controller.php';
        require_once __DIR__ . '/includes/admin/class-bmf-form-controller.php';
        require_once __DIR__ . '/includes/admin/class-bmf-forms-table.php';
        require_once __DIR__ . '/includes/admin/class-bmf-section-controller.php';
        require_once __DIR__ . '/includes/admin/class-bmf-question-controller.php';

        $repo = new BMF_Repository();

        $form_controller = new BMF_Form_Controller( $repo );
        $form_controller->register();

        $section_controller = new BMF_Section_Controller( $repo );
        $section_controller->register();

        $question_controller = new BMF_Question_Controller( $repo );
        $question_controller->register();
    }
}


    public function activate() {
        BMF_Repository::install_tables();
    }

    public function init() {
        BMF_Shortcodes::register();
    }

    public function admin_menu() {
        add_menu_page(
            __('Breathermae Forms','bmf'), __('Breathermae Forms','bmf'),
            'manage_options','bmf', [$this,'render_admin'],'dashicons-feedback', 26
        );


        add_submenu_page(
            'bmf',
            __('Forms','bmf'),
            __('Forms','bmf'),
            'manage_options',
            'bmf-forms',
            [ $this, 'render_forms_admin' ]
        );
        add_submenu_page(
            'bmf',
            __('Sections','bmf'),
            __('Sections','bmf'),
            'manage_options',
            'bmf-sections',
            [ $this, 'render_sections_admin' ]
        );

        add_submenu_page(
            'bmf',
            __('Questions','bmf'),
            __('Questions','bmf'),
            'manage_options',
            'bmf-questions',
            [ $this, 'render_questions_admin' ]
        );


        add_submenu_page('bmf', __('CSV Import','bmf'), __('CSV Import','bmf'),
            'manage_options', 'bmf-import', ['BMF_Importer','render_admin']);
        add_submenu_page('bmf', __('Analysis Views','bmf'), __('Analysis Views','bmf'), 
            'manage_options', 'bmf-views', ['BMF_Views_Admin','render_admin']);            
    }

    public function render_admin() {
        echo '<div class="wrap"><h1>Breathermae Forms</h1><p>Use the CSV Import screen to load or overwrite forms by slug.</p></div>';
    }

    public function render_forms_admin() {
        $controller = new BMF_Form_Controller( new BMF_Repository() );
        $controller->render_page();
    }
    
    public function render_sections_admin() {
        $repo = new BMF_Repository();

        // No form selected → show selector
        if ( empty( $_GET['form_id'] ) ) {
            $forms = BMF_Repository::get_all_forms();

            ?>
            <div class="wrap">
                <h1>Sections</h1>

                <form method="get">
                    <input type="hidden" name="page" value="bmf-sections">

                    <label for="form_id"><strong>Select a form</strong></label><br><br>

                    <select name="form_id" id="form_id">
                        <option value="">— Select —</option>
                        <?php foreach ( $forms as $form ) : ?>
                            <option value="<?php echo esc_attr( $form->id ); ?>">
                                <?php echo esc_html( $form->title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button class="button button-primary">Load Sections</button>
                </form>
            </div>
            <?php
            return;
        }

        // Form selected → render controller
        $controller = new BMF_Section_Controller( $repo );
        $controller->render_page();
    }

    public function render_questions_admin() {
        $repo = new BMF_Repository();

        // Step 1: no form selected
        if ( empty( $_GET['form_id'] ) ) {
            $forms = BMF_Repository::get_all_forms();
            ?>
            <div class="wrap">
                <h1>Questions</h1>

                <form method="get">
                    <input type="hidden" name="page" value="bmf-questions">

                    <label><strong>Select a form</strong></label><br><br>

                    <select name="form_id">
                        <option value="">— Select —</option>
                        <?php foreach ( $forms as $form ) : ?>
                            <option value="<?php echo esc_attr( $form->id ); ?>">
                                <?php echo esc_html( $form->title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button class="button button-primary">Continue</button>
                </form>
            </div>
            <?php
            return;
        }

            $form_id = absint( $_GET['form_id'] );
            $form = null;

            foreach ( BMF_Repository::get_all_forms() as $f ) {
                if ( (int) $f->id === $form_id ) {
                    $form = $f;
                    break;
                }
            }

        // Step 2: form selected, no section yet
        if ( empty( $_GET['section_id'] ) ) {
            $form_id  = absint( $_GET['form_id'] );
            $sections = BMF_Repository::get_sections_by_form( $form_id );


            echo '<div class="wrap">';
            echo '<h1>Questions</h1>';

            echo '<div style="margin:10px 0 20px; padding:10px 12px; background:#f6f7f7; border-left:4px solid #2271b1;">';
            echo '<p style="margin:0;"><strong>Form:</strong> ' . esc_html( $form->title ) . '</p>';
            echo '</div>';

            ?>
            <div class="wrap">
                

                <form method="get">
                    <input type="hidden" name="page" value="bmf-questions">
                    <input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">

                    <label><strong>Select a section</strong></label><br><br>

                    <select name="section_id">
                        <option value="">— Select —</option>
                        <?php foreach ( $sections as $section ) : ?>
                            <option value="<?php echo esc_attr( $section->id ); ?>">
                                <?php echo esc_html( $section->order_index . '. ' . $section->title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button class="button button-primary">Continue</button>
                </form>
            </div>
            <?php
            return;
        }

        // Step 3: form + section selected → questions UI
        $controller = new BMF_Question_Controller( $repo );
        $controller->render_page();
    }


    public function enqueue() {
        $css_path = plugin_dir_path(__FILE__) . 'assets/css/bmf.css';
        $js_path  = plugin_dir_path(__FILE__) . 'assets/js/bmf.js';
        $css_ver  = @filemtime($css_path) ?: self::VERSION;
        $js_ver   = @filemtime($js_path) ?: self::VERSION;

        wp_register_style('bmf', plugins_url('assets/css/bmf.css', __FILE__), [], $css_ver);
        wp_register_script('bmf', plugins_url('assets/js/bmf.js', __FILE__), ['jquery'], $js_ver, true);

        wp_localize_script('bmf', 'bmfAjax', [
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bmf_nonce'),
        ]);
        
        wp_localize_script('bmf', 'bmfVars', [
            'is_logged_in' => is_user_logged_in(),
        ]);

    }

}
function BMF(){ return BMF_Plugin::instance(); }
BMF();

add_action( 'bmf_response_submitted', 'bmf_apply_wp_fusion_tags', 20 );

/**
 * Apply WP Fusion tags when a BMF response is fully submitted.
 * This runs after REST submit completion via do_action('bmf_response_submitted').
 */

function bmf_apply_wp_fusion_tags( int $response_id ) {

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return;
    }

    global $wpdb;

    // Resolve form_id from response
    $form_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT form_id FROM {$wpdb->prefix}bm_responses WHERE id = %d",
            $response_id
        )
    );
    if ( ! $form_id ) {
        return;
    }

    // Fetch form_tag
    $form_tag = trim( (string) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT form_tag FROM {$wpdb->prefix}bm_forms WHERE id = %d",
            $form_id
        )
    ) );
    if ( $form_tag === '' ) {
        return;
    }

    // Support comma-separated tags
    $tags = array_values(
        array_filter( array_map( 'trim', explode( ',', $form_tag ) ) )
    );
    if ( empty( $tags ) ) {
        return;
    }

    // Apply via WP Fusion
    if ( function_exists( 'wp_fusion' ) && wp_fusion()->user ) {
        wp_fusion()->user->apply_tags( $tags, $user_id );
        do_action( 'wpf_apply_tags', $tags, $user_id );
        
    }
}

// -- Helper: detect Elementor editor/preview context --
if (!function_exists('bmf_in_elementor_editor')) {
    function bmf_in_elementor_editor(): bool {
        // Quick URL hints used by Elementor editor/preview
        if (isset($_GET['action']) && $_GET['action'] === 'elementor') return true;
        if (isset($_GET['elementor-preview'])) return true;

        // If Elementor is loaded, use its API (gracefully)
        if (did_action('elementor/loaded')) {
            try {
                $inst = \Elementor\Plugin::$instance;
                if (isset($inst->editor) && method_exists($inst->editor, 'is_edit_mode') && $inst->editor->is_edit_mode()) {
                    return true;
                }
                if (isset($inst->preview) && method_exists($inst->preview, 'is_preview_mode') && $inst->preview->is_preview_mode()) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Swallow any issues—treat as not in editor
            }
        }

        // Classic admin screen heuristic
        if (is_admin()) {
            // Elementor sets 'action=elementor' above; if not present assume not editing.
        }

        return false;
    }
}

// -- Helper: resolve form target from shortcode atts or querystring --
// Priority: shortcode 'form' > ?form > ?form_id > ?form_slug
if (!function_exists('bmf_resolve_form_from_atts_or_query')) {
    /**
     * @param string $formAttr Raw 'form' attribute value (may be blank)
     * @return string Non-empty normalized identifier: a numeric ID ("4") or a sanitized slug ("biological-strain"), or '' if not found.
     */
    function bmf_resolve_form_from_atts_or_query(string $formAttr): string {
        $formAttr = trim($formAttr);

        // 1) If shortcode provided, use it as-is (numeric or slug).
        if ($formAttr !== '') {
            return $formAttr;
        }

        // 2) Look for ?form=... in the URL (accepts slug or numeric)
        if (isset($_GET['form'])) {
            $raw = trim((string) $_GET['form']);
            if ($raw !== '') {
                if (ctype_digit($raw)) {
                    return (string) ((int) $raw); // normalize numeric
                }
                return sanitize_title($raw); // normalize slug-ish
            }
        }

        // 3) Look for explicit ?form_id=... or ?form_slug=...
        if (isset($_GET['form_id'])) {
            $id = (int) $_GET['form_id'];
            if ($id > 0) {
                return (string) $id;
            }
        }
        if (isset($_GET['form_slug'])) {
            $slug = sanitize_title((string) $_GET['form_slug']);
            if ($slug !== '') {
                return $slug;
            }
        }

        return '';
    }
}

/**
 * Optional: let site owners force-enable/disable the bail-out.
 * Default is true (disable shortcodes in Elementor).
 */
add_filter('bmf/shortcodes/disable_in_elementor', '__return_true');