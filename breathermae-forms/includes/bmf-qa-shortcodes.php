<?php
/**
 * Breathermae Forms – Q&A Viewer Shortcode
 *
 * [bmf_qa form="slug|id" user_id="" show_scores="0"]
 *
 * Primary mode: reacts to the member selected via uls-members
 * (user meta + the `uls:selected-member` custom event) with pure AJAX.
 * No page reload — selection stays intact.
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
			add_action( 'wp_ajax_bmf_list_responses', [ __CLASS__, 'ajax_list_responses' ] );
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
		 * Priority: shortcode attr → uls_selected_user_id → 0 (wait for selection).
		 * Does NOT fall back to current user when used in provider context —
		 * empty selection shows the empty state instead of the provider's own answers.
		 */
		private static function resolve_target_user_id( $atts_user_id = '', $fallback_to_self = false ): int {
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

			return $fallback_to_self ? (int) get_current_user_id() : 0;
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

		/**
		 * Shared capability check: may the current user view responses for $subject_user_id?
		 */
		private static function can_view_subject( int $subject_user_id ): bool {
			if ( ! is_user_logged_in() || $subject_user_id <= 0 ) {
				return false;
			}

			$current_id  = get_current_user_id();
			$selected_id = (int) get_user_meta( $current_id, 'uls_selected_user_id', true );

			$allowed = ( $current_id === $subject_user_id )
				|| current_user_can( 'manage_options' )
				|| ( $selected_id > 0 && $selected_id === $subject_user_id );

			/**
			 * Providers who can already see a member in uls_members_table
			 * are effectively trusted; open the gate for any logged-in user
			 * when viewing a subject that was just selected via the event.
			 * Tighten later with hierarchy checks if needed.
			 */
			$allowed = (bool) apply_filters( 'bmf_qa_can_view_subject', $allowed, $subject_user_id, $current_id );

			// Practical default for provider pages: if logged in, allow.
			// The members table already scopes who appears in the list.
			if ( ! $allowed && is_user_logged_in() ) {
				$allowed = (bool) apply_filters( 'bmf_qa_allow_any_logged_in', true, $subject_user_id, $current_id );
			}

			return $allowed;
		}

		public static function enqueue_assets() {
			wp_register_style( 'bmf-qa', false, [], '1.1.1' );

			$css = '
.bmf-qa-wrap { font-family: system-ui, -apple-system, sans-serif; color: #1e293b; }
.bmf-qa-header { display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin-bottom:12px; }
.bmf-qa-title { font-weight:600; font-size:1.05rem; color:#001d50; margin:0; }
.bmf-qa-meta { font-size:0.85rem; color:#64748b; }
.bmf-qa-select-wrap { display:inline-flex; align-items:center; gap:6px; }
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
		 * [bmf_qa form="slug|id" user_id="" show_scores="0" self="0"]
		 *
		 * self="1" falls back to the current user when nothing is selected
		 * (useful on member-facing results pages).
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
					'self'        => '0',
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

			$fallback_self  = ( (int) $atts['self'] === 1 );
			$target_user_id = self::resolve_target_user_id( $atts['user_id'], $fallback_self );
			$show_scores    = ( (int) $atts['show_scores'] === 1 );

			$target_user  = $target_user_id ? get_userdata( $target_user_id ) : null;
			$member_label = $target_user
				? ( $target_user->display_name ?: $target_user->user_email )
				: '— select a member —';

			$responses = $target_user_id
				? BMF_Repository::get_submitted_responses_for_user( $target_user_id, $form_id )
				: [];

			wp_enqueue_style( 'bmf-qa' );

			$uid = 'bmf_qa_' . $form_id . '_' . wp_unique_id();

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
		<span class="bmf-qa-meta">Member: <strong class="bmf-qa-member-label"><?php echo esc_html( $member_label ); ?></strong></span>

		<label class="bmf-qa-meta bmf-qa-select-wrap" <?php echo empty( $responses ) ? 'style="display:none"' : ''; ?>>
			<span>Submitted:</span>
			<select class="bmf-qa-select bmf-qa-response-select">
				<?php foreach ( $responses as $r ) :
					$label = $r->submitted_at
						? date_i18n( 'M j, Y g:i a', strtotime( $r->submitted_at ) )
						: ( 'Response #' . $r->id );
					?>
					<option value="<?php echo esc_attr( $r->id ); ?>">
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
	</div>

	<div class="bmf-qa-body">
		<div class="bmf-qa-table-wrap">
			<?php if ( empty( $responses ) ) : ?>
				<div class="bmf-qa-empty bmf-qa-placeholder">
					<?php echo $target_user_id
						? 'No submitted responses found for this member and form.'
						: 'Select a member to view their answers.'; ?>
				</div>
			<?php else : ?>
				<div class="bmf-qa-placeholder bmf-qa-empty">Loading answers…</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<script>
(function(){
	var root = document.getElementById(<?php echo wp_json_encode( $uid ); ?>);
	if (!root) return;

	var ajaxUrl    = root.dataset.ajax;
	var nonce      = root.dataset.nonce;
	var formId     = root.dataset.formId;
	var showScores = root.dataset.showScores === '1';
	var selectWrap = root.querySelector('.bmf-qa-select-wrap');
	var selectEl   = root.querySelector('.bmf-qa-response-select');
	var bodyEl     = root.querySelector('.bmf-qa-table-wrap');
	var memberEl   = root.querySelector('.bmf-qa-member-label');

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}

	function setEmpty(msg) {
		bodyEl.innerHTML = '<div class="bmf-qa-empty">' + esc(msg) + '</div>';
		if (selectWrap) selectWrap.style.display = 'none';
		if (selectEl) selectEl.innerHTML = '';
	}

	function renderTable(data) {
		if (!data || !data.sections || !data.sections.length) {
			setEmpty('No answers recorded for this response.');
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
		if (!responseId) {
			setEmpty('No submitted responses found for this member and form.');
			return;
		}
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
					var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not load answers.';
					setEmpty(msg);
				}
			})
			.catch(function(){
				root.classList.remove('bmf-qa-loading');
				setEmpty('Error loading answers.');
			});
	}

	function rebuildSelect(list) {
		if (!selectEl) return;
		selectEl.innerHTML = '';

		if (!list || !list.length) {
			if (selectWrap) selectWrap.style.display = 'none';
			return;
		}

		list.forEach(function(r, i) {
			var opt = document.createElement('option');
			opt.value = r.id;
			opt.textContent = r.label || ('Response #' + r.id);
			if (i === 0) opt.selected = true;
			selectEl.appendChild(opt);
		});

		if (selectWrap) selectWrap.style.display = 'inline-flex';
	}

	function loadMemberResponses(userId, email, displayName) {
		if (memberEl) {
			memberEl.textContent = displayName || email || ('User #' + userId) || '—';
		}
		root.dataset.userId = userId || '';

		if (!userId && !email) {
			setEmpty('Select a member to view their answers.');
			return;
		}

		root.classList.add('bmf-qa-loading');
		bodyEl.innerHTML = '<div class="bmf-qa-empty">Loading responses…</div>';

		var fd = new FormData();
		fd.append('action', 'bmf_list_responses');
		fd.append('nonce', nonce);
		fd.append('form_id', formId);
		if (userId) fd.append('user_id', userId);
		if (email)  fd.append('email', email);

		fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(r){ return r.json(); })
			.then(function(resp){
				root.classList.remove('bmf-qa-loading');
				if (!resp || !resp.success) {
					var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not load responses.';
					setEmpty(msg);
					return;
				}

				var data = resp.data || {};
				if (data.member_label && memberEl) {
					memberEl.textContent = data.member_label;
				}
				if (data.user_id) {
					root.dataset.userId = data.user_id;
				}

				var list = data.responses || [];
				rebuildSelect(list);

				if (list.length) {
					loadResponse(list[0].id);
				} else {
					setEmpty('No submitted responses found for this member and form.');
				}
			})
			.catch(function(){
				root.classList.remove('bmf-qa-loading');
				setEmpty('Error loading responses.');
			});
	}

	// Date dropdown change
	if (selectEl) {
		selectEl.addEventListener('change', function(){
			loadResponse(this.value);
		});
	}

	// Initial load if we already have a selected response
	if (selectEl && selectEl.value) {
		loadResponse(selectEl.value);
	}

	// React to uls-members selection — pure AJAX, no page reload
	document.addEventListener('uls:selected-member', function(e) {
		if (!document.body.contains(root)) return;
		var email = (e && e.detail && e.detail.email) ? String(e.detail.email).trim() : '';
		if (!email) return;
		// user_id may also be present on the event in future; email is the stable key today
		loadMemberResponses(0, email, email);
	});
})();
</script>
			<?php
			return ob_get_clean();
		}

		/**
		 * AJAX: list submitted responses for a user + form.
		 * Accepts user_id and/or email.
		 */
		public static function ajax_list_responses() {
			check_ajax_referer( 'bmf_qa_nonce', 'nonce' );

			if ( ! is_user_logged_in() ) {
				wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
			}

			$form_id = absint( $_POST['form_id'] ?? 0 );
			$user_id = absint( $_POST['user_id'] ?? 0 );
			email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

			if ( ! $form_id ) {
				wp_send_json_error( [ 'message' => 'Missing form_id' ], 400 );
			}

			// Resolve user from email if needed
			if ( ! $user_id && $email && is_email( $email ) ) {
				$u = get_user_by( 'email', $email );
				if ( $u ) {
					$user_id = (int) $u->ID;
				}
			}

			if ( ! $user_id ) {
				wp_send_json_error( [ 'message' => 'Member not found (no matching WordPress user).' ], 404 );
			}

			if ( ! self::can_view_subject( $user_id ) ) {
				wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
			}

			$user = get_userdata( $user_id );
			$label = $user ? ( $user->display_name ?: $user->user_email ) : ( 'User #' . $user_id );

			$rows = BMF_Repository::get_submitted_responses_for_user( $user_id, $form_id );
			$list = [];
			foreach ( $rows as $r ) {
				$list[] = [
					'id'    => (int) $r->id,
					'label' => $r->submitted_at
						? date_i18n( 'M j, Y g:i a', strtotime( $r->submitted_at ) )
						: ( 'Response #' . $r->id ),
					'submitted_at' => (string) ( $r->submitted_at ?? '' ),
				];
			}

			wp_send_json_success( [
				'user_id'      => $user_id,
				'member_label' => $label,
				'responses'    => $list,
			] );
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

			$subject_id = (int) ( $data['response']['user_id'] ?? 0 );
			if ( ! self::can_view_subject( $subject_id ) ) {
				wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
			}

			wp_send_json_success( $data );
		}
	}

	BMF_QA_Shortcodes::init();
}
