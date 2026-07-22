<?php
/**
 * Breathermae Forms – Q&A Viewer Shortcode
 *
 * [bmf_qa form="slug|id" user_id="" show_scores="0"]
 *
 * Primary mode: reacts to the member selected via uls-members
 * (user meta uls_selected_user_id / uls_selected_email, plus the
 *  `uls:selected-member` custom event).
 *
 * Falls back to current user when no selection exists (self-view).
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

if ( ! class_exists( 'BMF_QA_Shortcodes' ) ) {

        class BMF_QA_Shortcodes {

                public static function init() {
                        add_shortcode( 'bmf_qa', [ __CLASS__, 'shortcode_qa' ] );

                        add_action( 'wp_ajax_bmf_get_response_qa', [ __CLASS__, 'ajax_get_response_qa' ] );
                        // Intentionally no nopriv – providers only.

                        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
                }

                private static function should_bail_for_editor(): bool {
                        $disable = apply_filters( 'bmf/shortcodes/disable_in_elementor', true );
                        if ( ! $disable ) {
                                return false;
                        }
                        return function_exists( 'bmf_in_elementor_editor' ) && bmf_in_elementor_editor();
                }

                /**
                 * Resolve target member user_id.
                 * Priority: shortcode attr → uls_selected_user_id → current user.
                 */
                private static function resolve_target_user_id( $atts_user_id = '' ): int {
                        $attr = absint( $atts_user_id );
                        if ( $attr > 0 ) {
                                return $attr;
                        }

                        if ( is_user_logged_in() ) {
                                $selected = (int) get_user_meta( get_current_user_id(), 'uls_selected_user_id', true );
                                if ( $selected > 0 ) {
                                        return $selected;
                                }
                        }

                        return (int) get_current_user_id();
                }

                /**
                 * Resolve form_id from slug or numeric id.
                 */
                private static function resolve_form_id( string $form_attr ): int {
                        $form_attr = trim( $form_attr );
                        if ( $form_attr === '' ) {
                                return 0;
                        }
                        if ( ctype_digit( $form_attr ) ) {
                                return (int) $form_attr;
                        }
                        $row = BMF_Repository::get_form_by_slug( sanitize_title( $form_attr ) );
                        return $row ? (int) $row->id : 0;
                }

                public static function enqueue_assets() {
                        // Lightweight – only register; shortcode will enqueue when used.
                        wp_register_style(
                                'bmf-qa',
                                false, // inline only
                                [],
                                '1.0.0'
                        );

                        $css = '
.bmf-qa-wrap { font-family: system-ui, -apple-system, sans-serif; color: #1e293b; }
.bmf-qa-header { display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin-bottom:12px; }
.bmf-qa-title { font-weight:600; font-size:1.05rem; color:#001d50; margin:0; }
.bmf-qa-meta { font-size:0.85rem; color:#64748b; }
.bmf-qa-select { padding:6px 10px; border:1px solid #001d50; border-radius:4px; font-size:0.9rem; min-width:180px; }
.bmf-qa-empty { padding:16px; text-align:center; color:#64748b; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px; }
.bmf-qa-table { width:100%; border-collapse:collapse; font-size:0.9rem; margin-top:8px; }
.bmf-qa-table th, .bmf-qa-table td { padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; }
.bmf-qa-table thead th { background:#6ec1e4; color:#001d50; font-weight:600; }
.bmf-qa-section-row td { background:#f1f5f9; font-weight:600; color:#001d50; }
.bmf-qa-q-num { width:36px; text-align:center; color:#64748b; }
.bmf-qa-answer { font-weight:500; color:#0f172a; }
.bmf-qa-loading { opacity:0.55; pointer-events:none; }
.bmf-qa-score { white-space:nowrap; color:#475569; }
';
                        wp_add_inline_style( 'bmf-qa', $css );
                }

                /**
                 * [bmf_qa form="slug|id" user_id="" show_scores="0"]
                 */
                public static function shortcode_qa( $atts ) {
                        if ( self::should_bail_for_editor() ) {
                                return '';
                        }

                        if ( ! is_user_logged_in() ) {
                                return '<div class="bmf-qa-empty">Please log in to view responses.</div>';
                        }

                        $atts = shortcode_atts(
                                [
                                        'form'        => '',
                                        'user_id'     => '',
                                        'show_scores' => '0',
                                ],
                                $atts,
                                'bmf_qa'
                        );

                        $form_id = self::resolve_form_id( (string) $atts['form'] );
                        if ( ! $form_id ) {
                                return '<div class="bmf-qa-empty">Form not found. Provide a valid form slug or id.</div>';
                        }

                        $form = BMF_Repository::get_form( $form_id );
                        if ( ! $form ) {
                                return '<div class="bmf-qa-empty">Form not found.</div>';
                        }

                        $target_user_id = self::resolve_target_user_id( $atts['user_id'] );
                        $show_scores    = ( (int) $atts['show_scores'] === 1 );

                        $target_user = $target_user_id ? get_userdata( $target_user_id ) : null;
                        $member_label = $target_user
                                ? ( $target_user->display_name ?: $target_user->user_email )
                                : '—';

                        $responses = $target_user_id
                                ? BMF_Repository::get_submitted_responses_for_user( $target_user_id, $form_id )
                                : [];

                        wp_enqueue_style( 'bmf-qa' );

                        $uid = 'bmf_qa_' . $form_id . '_' . wp_unique_id();

                        // Initial response (latest)
                        $initial_id = ! empty( $responses ) ? (int) $responses[0]->id : 0;

                        ob_start();
                        ?>
<div class="bmf-qa-wrap"
         id="<?php echo esc_attr( $uid ); ?>"
         data-form-id="<?php echo esc_attr( $form_id ); ?>"
         data-user-id="<?php echo esc_attr( $target_user_id ); ?>"
         data-show-scores="<?php echo $show_scores ? '1' : '0'; ?>"
         data-nonce="<?php echo esc_attr( wp_create_nonce( 'bmf_qa_nonce' ) ); ?>"
         data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">

        <div class="bmf-qa-header">
                <h4 class="bmf-qa-title"><?php echo esc_html( $form->title ); ?></h4>
                <span class="bmf-qa-meta member-label">Member: <strong><?php echo esc_html( $member_label ); ?></strong></span>

                <?php if ( ! empty( $responses ) ) : ?>
                <label class="bmf-qa-meta" style="display:inline-flex;align-items:center;gap:6px;">
                        <span>Submitted:</span>
                        <select class="bmf-qa-select bmf-qa-response-select">
                                <?php foreach ( $responses as $r ) :
                                        $label = $r->submitted_at
                                                ? date_i18n( 'M j, Y g:i a', strtotime( $r->submitted_at ) )
                                                : ( 'Response #' . $r->id );
                                        ?>
                                        <option value="<?php echo esc_attr( $r->id ); ?>" <?php selected( $initial_id, (int) $r->id ); ?>>
                                                <?php echo esc_html( $label ); ?>
                                        </option>
                                <?php endforeach; ?>
                        </select>
                </label>
                <?php endif; ?>
        </div>

        <div class="bmf-qa-body">
                <?php if ( empty( $responses ) ) : ?>
                        <div class="bmf-qa-empty">
                                <?php echo $target_user_id
                                        ? 'No submitted responses found for this member and form.'
                                        : 'Select a member first (or pass user_id).'; ?>
                        </div>
                <?php else : ?>
                        <div class="bmf-qa-table-wrap">
                                <!-- Populated by AJAX / initial render -->
                                <div class="bmf-qa-placeholder bmf-qa-empty">Loading answers…</div>
                        </div>
                <?php endif; ?>
        </div>
</div>

<script>
(function(){
        var root = document.getElementById(<?php echo wp_json_encode( $uid ); ?>);
        if (!root) return;

        var ajaxUrl   = root.dataset.ajax;
        var nonce     = root.dataset.nonce;
        var formId    = root.dataset.formId;
        var userId    = root.dataset.userId;
        var showScores= root.dataset.showScores === '1';
        var selectEl  = root.querySelector('.bmf-qa-response-select');
        var bodyEl    = root.querySelector('.bmf-qa-table-wrap') || root.querySelector('.bmf-qa-body');

        function esc(s) {
                var d = document.createElement('div');
                d.textContent = s == null ? '' : String(s);
                return d.innerHTML;
        }

        function renderTable(data) {
                if (!data || !data.sections || !data.sections.length) {
                        bodyEl.innerHTML = '<div class="bmf-qa-empty">No answers recorded for this response.</div>';
                        return;
                }

                var html = '<table class="bmf-qa-table"><thead><tr>';
                html += '<th class="bmf-qa-q-num">#</th>';
                html += '<th>Question</th>';
                html += '<th>Answer</th>';
                if (showScores) html += '<th class="bmf-qa-score">Score</th>';
                html += '</tr></thead><tbody>';

                data.sections.forEach(function(sec) {
                        if (!sec.questions || !sec.questions.length) return;

                        html += '<tr class="bmf-qa-section-row"><td colspan="' + (showScores ? 4 : 3) + '">' +
                                esc(sec.title || ('Section ' + sec.order_index)) + '</td></tr>';

                        sec.questions.forEach(function(q, idx) {
                                var num = q.order_index || (idx + 1);
                                var scoreCell = '';
                                if (showScores) {
                                        scoreCell = '<td class="bmf-qa-score">' +
                                                (q.score !== null && q.score !== undefined ? esc(q.score) : '—') +
                                                '</td>';
                                }
                                html += '<tr>' +
                                        '<td class="bmf-qa-q-num">' + esc(num) + '</td>' +
                                        '<td>' + esc(q.prompt) + '</td>' +
                                        '<td class="bmf-qa-answer">' + esc(q.answer_label || '—') + '</td>' +
                                        scoreCell +
                                        '</tr>';
                        });
                });

                html += '</tbody></table>';
                bodyEl.innerHTML = html;
        }

        function loadResponse(responseId) {
                if (!responseId) return;
                root.classList.add('bmf-qa-loading');

                var fd = new FormData();
                fd.append('action', 'bmf_get_response_qa');
                fd.append('nonce', nonce);
                fd.append('response_id', responseId);

                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function(r){ return r.json(); })
                        .then(function(resp){
                                root.classList.remove('bmf-qa-loading');
                                if (resp && resp.success && resp.data) {
                                        renderTable(resp.data);
                                } else {
                                        bodyEl.innerHTML = '<div class="bmf-qa-empty">Could not load answers.</div>';
                                }
                        })
                        .catch(function(){
                                root.classList.remove('bmf-qa-loading');
                                bodyEl.innerHTML = '<div class="bmf-qa-empty">Error loading answers.</div>';
                        });
        }

        // Initial load
        if (selectEl && selectEl.value) {
                loadResponse(selectEl.value);
                selectEl.addEventListener('change', function(){
                        loadResponse(this.value);
                });
        }

        // React to uls-members selection changes without full page reload
        document.addEventListener('uls:selected-member', function(e) {
                // Soft approach: reload the page so PHP re-resolves the selected user
                // and rebuilds the response list. Keeps logic simple and consistent.
                // (Providers already expect a selection change to refresh context.)
                if (e && e.detail && e.detail.email) {
                        // Only reload if this shortcode is visible / in DOM
                        if (document.body.contains(root)) {
                                window.location.reload();
                        }
                }
        });
})();
</script>
                        <?php
                        return ob_get_clean();
                }

                /**
                 * AJAX: return full Q&A for a response_id.
                 */
                public static function ajax_get_response_qa() {
                        check_ajax_referer( 'bmf_qa_nonce', 'nonce' );

                        if ( ! is_user_logged_in() ) {
                                wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
                        }

                        $response_id = absint( $_POST['response_id'] ?? 0 );
                        if ( ! $response_id ) {
                                wp_send_json_error( [ 'message' => 'Missing response_id' ], 400 );
                        }

                        $data = BMF_Repository::get_response_qa( $response_id );
                        if ( ! $data ) {
                                wp_send_json_error( [ 'message' => 'Response not found' ], 404 );
                        }

                        // Basic ownership / visibility gate:
                        // Allow if current user is the subject, or has manage_options,
                        // or (common case) is a provider with a selected member matching this response.
                        $current_id  = get_current_user_id();
                        $subject_id  = (int) ( $data['response']['user_id'] ?? 0 );
                        $selected_id = (int) get_user_meta( $current_id, 'uls_selected_user_id', true );

                        $allowed = ( $current_id === $subject_id )
                                || current_user_can( 'manage_options' )
                                || ( $selected_id > 0 && $selected_id === $subject_id );

                        /**
                         * Filter whether the current user may view this response Q&A.
                         * @param bool  $allowed
                         * @param array $data
                         * @param int   $current_id
                         */
                        $allowed = (bool) apply_filters( 'bmf_qa_can_view_response', $allowed, $data, $current_id );

                        if ( ! $allowed ) {
                                wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
                        }

                        wp_send_json_success( $data );
                }
        }

        BMF_QA_Shortcodes::init();
}
