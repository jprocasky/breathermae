<?php
/**
 * Breathermae Forms – Q&A Viewer Shortcode
 *
 * [bmf_qa form="slug|id" user_id="" show_scores="0" self="0"
 *         highlight="0" direction="low_better" threshold="0.75"]
 *
 * Driven by two selections (both pure AJAX, no page reload):
 *   1. Member  – uls-members (`uls:selected-member` / uls_selected_user_id meta)
 *   2. Form    – click on [data-bmf-qa-form="slug"] or `bmf:selected-form` event
 *
 * Highlighting (optional):
 *   highlight="1" enables extreme row tinting
 *   direction="low_better"  → high scores are concerning (BSI / RSI)
 *   direction="high_better" → low scores are concerning (8-Pillars)
 *   threshold="0.75"        → fraction of question scale_max (0–1)
 *
 * Form-link direction always overrides the panel default:
 *   [bmf_qa_form_link form="slug" direction="high_better"]Label[/bmf_qa_form_link]
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BMF_QA_Shortcodes' ) ) {

	class BMF_QA_Shortcodes {

		public static function init() {
			add_shortcode( 'bmf_qa', [ __CLASS__, 'shortcode_qa' ] );
			add_shortcode( 'bmf_qa_form_link', [ __CLASS__, 'shortcode_form_link' ] );

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

		/** Normalize direction attr. */
		private static function normalize_direction( string $dir ): string {
			$dir = strtolower( trim( $dir ) );
			return in_array( $dir, [ 'low_better', 'high_better' ], true ) ? $dir : 'low_better';
		}

		/** Clamp threshold to 0.5–1.0 (meaningful extreme band). */
		private static function normalize_threshold( $raw ): float {
			$t = is_numeric( $raw ) ? (float) $raw : 0.75;
			if ( $t > 1 && $t <= 100 ) {
				$t = $t / 100; // allow "75" as percent
			}
			return max( 0.5, min( 1.0, $t ) );
		}

		private static function can_view_subject( int $subject_user_id ): bool {
			if ( ! is_user_logged_in() || $subject_user_id <= 0 ) {
				return false;
			}

			$current_id  = get_current_user_id();
			$selected_id = (int) get_user_meta( $current_id, 'uls_selected_user_id', true );

			$allowed = ( $current_id === $subject_user_id )
				|| current_user_can( 'manage_options' )
				|| ( $selected_id > 0 && $selected_id === $subject_user_id );

			$allowed = (bool) apply_filters( 'bmf_qa_can_view_subject', $allowed, $subject_user_id, $current_id );

			if ( ! $allowed && is_user_logged_in() ) {
				$allowed = (bool) apply_filters( 'bmf_qa_allow_any_logged_in', true, $subject_user_id, $current_id );
			}

			return $allowed;
		}

		public static function enqueue_assets() {
			wp_register_style( 'bmf-qa', false, [], '1.3.0' );

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

/* Extreme row tint (full row) */
.bmf-qa-table tr.bmf-qa-extreme td {
	background: #fef2f2;
}
.bmf-qa-table tr.bmf-qa-extreme td.bmf-qa-answer {
	color: #991b1b;
	font-weight: 600;
}
.bmf-qa-table tr.bmf-qa-extreme:hover td {
	background: #fee2e2;
}

/* Export button */
.bmf-qa-export {
	margin-left: auto;
	padding: 6px 12px;
	border: 1px solid #001d50;
	border-radius: 4px;
	background: #fff;
	color: #001d50;
	font-size: 0.85rem;
	font-weight: 600;
	cursor: pointer;
	line-height: 1.2;
}
.bmf-qa-export:hover { background: #e0f2fe; }
.bmf-qa-export:disabled { opacity: 0.45; cursor: not-allowed; }

/* Form title triggers */
.bmf-qa-form-link,
[data-bmf-qa-form] {
	cursor: pointer;
	text-decoration: none;
	color: #001d50;
	border-bottom: 1px dashed transparent;
	transition: color .15s ease, border-color .15s ease, background .15s ease;
}
.bmf-qa-form-link:hover,
[data-bmf-qa-form]:hover {
	color: #0b3a8a;
	border-bottom-color: #6ec1e4;
}
.bmf-qa-form-link.is-active,
[data-bmf-qa-form].is-active {
	color: #0b3a8a;
	font-weight: 600;
	border-bottom-color: #6ec1e4;
	background: rgba(110, 193, 228, 0.15);
	padding: 0 4px;
	border-radius: 3px;
}
';
			wp_add_inline_style( 'bmf-qa', $css );
		}

		/**
		 * [bmf_qa_form_link form="slug" direction="" class=""]Label[/bmf_qa_form_link]
		 * direction (optional) overrides the panel default when this form is selected.
		 */
		public static function shortcode_form_link( $atts, $content = null ) {
			if ( self::should_bail_for_editor() ) {
				return is_string( $content ) ? $content : '';
			}

			$atts = shortcode_atts(
				[
					'form'      => '',
					'direction' => '',
					'class'     => '',
				],
				$atts,
				'bmf_qa_form_link'
			);

			$form = trim( (string) $atts['form'] );
			if ( $form === '' ) {
				return is_string( $content ) ? $content : '';
			}

			$label = is_string( $content ) ? trim( $content ) : '';
			if ( $label === '' ) {
				$form_id = self::resolve_form_id( $form );
				$row     = $form_id ? BMF_Repository::get_form( $form_id ) : null;
				$label   = $row ? (string) $row->title : $form;
			}

			$class = 'bmf-qa-form-link';
			if ( $atts['class'] !== '' ) {
				$class .= ' ' . sanitize_html_class( $atts['class'] );
			}

			$dir_attr = '';
			if ( $atts['direction'] !== '' ) {
				$dir_attr = ' data-bmf-qa-direction="' . esc_attr( self::normalize_direction( $atts['direction'] ) ) . '"';
			}

			wp_enqueue_style( 'bmf-qa' );

			return sprintf(
				'<a href="#" class="%s" data-bmf-qa-form="%s"%s role="button">%s</a>',
				esc_attr( $class ),
				esc_attr( $form ),
				$dir_attr,
				esc_html( $label )
			);
		}

		/**
		 * [bmf_qa form="" user_id="" show_scores="0" self="0"
		 *         highlight="0" direction="low_better" threshold="0.75"]
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
					'highlight'   => '0',
					'direction'   => 'low_better',
					'threshold'   => '0.75',
				],
				$atts,
				'bmf_qa'
			);

			$form_attr = trim( (string) $atts['form'] );
			$form_id   = self::resolve_form_id( $form_attr );

			if ( $form_attr !== '' && ! $form_id ) {
				return '<div class="bmf-qa-empty">Form not found. Provide a valid form slug or id.</div>';
			}

			$form = $form_id ? BMF_Repository::get_form( $form_id ) : null;

			$fallback_self  = ( (int) $atts['self'] === 1 );
			$target_user_id = self::resolve_target_user_id( $atts['user_id'], $fallback_self );
			$show_scores    = ( (int) $atts['show_scores'] === 1 );
			$highlight      = ( (int) $atts['highlight'] === 1 );
			$direction      = self::normalize_direction( (string) $atts['direction'] );
			$threshold      = self::normalize_threshold( $atts['threshold'] );

			$target_user  = $target_user_id ? get_userdata( $target_user_id ) : null;
			$member_label = $target_user
				? ( $target_user->display_name ?: $target_user->user_email )
				: '— select a member —';
			$member_email = $target_user ? (string) $target_user->user_email : '';

			$form_title = $form ? (string) $form->title : '— select a form —';
			$form_slug  = $form ? (string) $form->slug : $form_attr;

			$responses = ( $target_user_id && $form_id )
				? BMF_Repository::get_submitted_responses_for_user( $target_user_id, $form_id )
				: [];

			wp_enqueue_style( 'bmf-qa' );

			$uid = 'bmf_qa_' . ( $form_id ?: 'any' ) . '_' . wp_unique_id();

			if ( ! $form_id && ! $target_user_id ) {
				$empty_msg = 'Select a member, then click a form name to view answers.';
			} elseif ( ! $form_id ) {
				$empty_msg = 'Click a form name to view this member’s answers.';
			} elseif ( ! $target_user_id ) {
				$empty_msg = 'Select a member to view their answers.';
			} elseif ( empty( $responses ) ) {
				$empty_msg = 'No submitted responses found for this member and form.';
			} else {
				$empty_msg = 'Loading answers…';
			}

			ob_start();
			?>
<div class="bmf-qa-wrap"
	 id="<?php echo esc_attr( $uid ); ?>"
	 data-form-id="<?php echo esc_attr( $form_id ); ?>"
	 data-form-slug="<?php echo esc_attr( $form_slug ); ?>"
	 data-user-id="<?php echo esc_attr( $target_user_id ); ?>"
	 data-email="<?php echo esc_attr( $member_email ); ?>"
	 data-show-scores="<?php echo $show_scores ? '1' : '0'; ?>"
	 data-highlight="<?php echo $highlight ? '1' : '0'; ?>"
	 data-direction="<?php echo esc_attr( $direction ); ?>"
	 data-direction-default="<?php echo esc_attr( $direction ); ?>"
	 data-threshold="<?php echo esc_attr( (string) $threshold ); ?>"
	 data-nonce="<?php echo esc_attr( wp_create_nonce( 'bmf_qa_nonce' ) ); ?>"
	 data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">

	<div class="bmf-qa-header">
		<h4 class="bmf-qa-title"><?php echo esc_html( $form_title ); ?></h4>
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

		<button type="button" class="bmf-qa-export" disabled title="Export current Q&A to CSV">Export CSV</button>
	</div>

	<div class="bmf-qa-body">
		<div class="bmf-qa-table-wrap">
			<div class="bmf-qa-empty bmf-qa-placeholder"><?php echo esc_html( $empty_msg ); ?></div>
		</div>
	</div>
</div>

<script>
(function(){
	var root = document.getElementById(<?php echo wp_json_encode( $uid ); ?>);
	if (!root) return;

	var ajaxUrl    = root.dataset.ajax;
	var nonce      = root.dataset.nonce;
	var showScores = root.dataset.showScores === '1';
	var highlight  = root.dataset.highlight === '1';
	var selectWrap = root.querySelector('.bmf-qa-select-wrap');
	var selectEl   = root.querySelector('.bmf-qa-response-select');
	var bodyEl     = root.querySelector('.bmf-qa-table-wrap');
	var memberEl   = root.querySelector('.bmf-qa-member-label');
	var titleEl    = root.querySelector('.bmf-qa-title');
	var exportBtn  = root.querySelector('.bmf-qa-export');

	var state = {
		formId:            root.dataset.formId || '',
		formSlug:          root.dataset.formSlug || '',
		userId:            root.dataset.userId || '',
		email:             root.dataset.email || '',
		direction:         root.dataset.direction || 'low_better',
		directionDefault:  root.dataset.directionDefault || 'low_better',
		threshold:         parseFloat(root.dataset.threshold || '0.75') || 0.75,
		lastData:          null,
		lastMemberLabel:   '',
		lastFormTitle:     ''
	};

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}

	function setEmpty(msg) {
		bodyEl.innerHTML = '<div class="bmf-qa-empty">' + esc(msg) + '</div>';
		if (selectWrap) selectWrap.style.display = 'none';
		if (selectEl) selectEl.innerHTML = '';
		state.lastData = null;
		if (exportBtn) exportBtn.disabled = true;
	}

	/**
	 * Extreme = score is on the concerning end of the scale past threshold.
	 * low_better  → high scores bad  → score/max >= threshold
	 * high_better → low scores bad   → score/max <= (1 - threshold)
	 */
	function isExtreme(q) {
		if (!highlight) return false;
		if (q.score === null || q.score === undefined) return false;
		var max = (q.scale_max !== null && q.scale_max !== undefined) ? Number(q.scale_max) : null;
		if (!max || max <= 0) return false;
		var ratio = Number(q.score) / max;
		if (state.direction === 'high_better') {
			return ratio <= (1 - state.threshold);
		}
		// low_better (default)
		return ratio >= state.threshold;
	}

	function renderTable(data) {
		if (!data || !data.sections || !data.sections.length) {
			setEmpty('No answers recorded for this response.');
			return;
		}

		state.lastData = data;
		state.lastFormTitle = (data.form && data.form.title) ? data.form.title : (titleEl ? titleEl.textContent : '');
		if (exportBtn) exportBtn.disabled = false;

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
				var extreme = isExtreme(q);
				var scoreCell = '';
				if (showScores) {
					scoreCell = '<td class="bmf-qa-score">' +
						(q.score !== null && q.score !== undefined ? esc(q.score) : '—') +
						'</td>';
				}
				html += '<tr' + (extreme ? ' class="bmf-qa-extreme"' : '') + '>' +
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

	function csvEscape(v) {
		var s = (v == null) ? '' : String(v);
		if (/[",\n\r]/.test(s)) {
			return '"' + s.replace(/"/g, '""') + '"';
		}
		return s;
	}

	function exportCsv() {
		var data = state.lastData;
		if (!data || !data.sections) return;

		var rows = [];
		rows.push(['Form', 'Section', '#', 'Question', 'Answer', 'Score', 'Extreme'].map(csvEscape).join(','));

		var formTitle = state.lastFormTitle || (data.form && data.form.title) || '';

		data.sections.forEach(function(sec) {
			if (!sec.questions) return;
			sec.questions.forEach(function(q, idx) {
				var num = q.order_index || (idx + 1);
				var extreme = isExtreme(q) ? 'Yes' : 'No';
				var score = (q.score !== null && q.score !== undefined) ? q.score : '';
				rows.push([
					formTitle,
					sec.title || '',
					num,
					q.prompt || '',
					q.answer_label || '',
					score,
					extreme
				].map(csvEscape).join(','));
			});
		});

		var blob = new Blob([rows.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
		var url  = URL.createObjectURL(blob);
		var a    = document.createElement('a');
		var member = (state.lastMemberLabel || state.email || 'member').replace(/[^a-z0-9._-]+/gi, '_');
		var form  = (state.formSlug || formTitle || 'form').replace(/[^a-z0-9._-]+/gi, '_');
		var stamp = new Date().toISOString().slice(0, 10);
		a.href = url;
		a.download = 'qa-' + member + '-' + form + '-' + stamp + '.csv';
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(url);
	}

	if (exportBtn) {
		exportBtn.addEventListener('click', exportCsv);
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

	function refresh() {
		var hasForm   = !!(state.formId || state.formSlug);
		var hasMember = !!(state.userId || state.email);

		if (!hasForm && !hasMember) {
			setEmpty('Select a member, then click a form name to view answers.');
			return;
		}
		if (!hasForm) {
			setEmpty('Click a form name to view this member’s answers.');
			return;
		}
		if (!hasMember) {
			setEmpty('Select a member to view their answers.');
			return;
		}

		root.classList.add('bmf-qa-loading');
		bodyEl.innerHTML = '<div class="bmf-qa-empty">Loading responses…</div>';

		var fd = new FormData();
		fd.append('action', 'bmf_list_responses');
		fd.append('nonce', nonce);
		if (state.formId)   fd.append('form_id', state.formId);
		if (state.formSlug) fd.append('form', state.formSlug);
		if (state.userId)   fd.append('user_id', state.userId);
		if (state.email)    fd.append('email', state.email);

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
					state.lastMemberLabel = data.member_label;
				}
				if (data.user_id) {
					state.userId = String(data.user_id);
					root.dataset.userId = state.userId;
				}
				if (data.form_id) {
					state.formId = String(data.form_id);
					root.dataset.formId = state.formId;
				}
				if (data.form_slug) {
					state.formSlug = data.form_slug;
					root.dataset.formSlug = state.formSlug;
				}
				if (data.form_title && titleEl) {
					titleEl.textContent = data.form_title;
					state.lastFormTitle = data.form_title;
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

	function setMember(userId, email, displayName) {
		if (memberEl) {
			memberEl.textContent = displayName || email || (userId ? ('User #' + userId) : '— select a member —');
		}
		state.lastMemberLabel = displayName || email || '';
		state.userId = userId ? String(userId) : '';
		state.email  = email || '';
		root.dataset.userId = state.userId;
		root.dataset.email  = state.email;
		refresh();
	}

	function setForm(formKey, label, directionOverride) {
		formKey = (formKey || '').toString().trim();
		if (!formKey) return;

		if (/^\d+$/.test(formKey)) {
			state.formId   = formKey;
			state.formSlug = '';
		} else {
			state.formId   = '';
			state.formSlug = formKey;
		}
		root.dataset.formId   = state.formId;
		root.dataset.formSlug = state.formSlug;

		// Link direction always overrides panel default; clear override → restore default
		if (directionOverride) {
			state.direction = directionOverride;
		} else {
			state.direction = state.directionDefault;
		}
		root.dataset.direction = state.direction;

		if (label && titleEl) {
			titleEl.textContent = label;
			state.lastFormTitle = label;
		} else if (titleEl && state.formSlug) {
			titleEl.textContent = state.formSlug;
		}

		refresh();
	}

	if (selectEl) {
		selectEl.addEventListener('change', function(){
			loadResponse(this.value);
		});
	}

	if ((state.formId || state.formSlug) && (state.userId || state.email)) {
		refresh();
	} else if (selectEl && selectEl.value) {
		loadResponse(selectEl.value);
	}

	document.addEventListener('uls:selected-member', function(e) {
		if (!document.body.contains(root)) return;
		var email = (e && e.detail && e.detail.email) ? String(e.detail.email).trim() : '';
		if (!email) return;
		setMember(0, email, email);
	});

	document.addEventListener('bmf:selected-form', function(e) {
		if (!document.body.contains(root)) return;
		var form  = (e && e.detail && e.detail.form) ? String(e.detail.form).trim() : '';
		var label = (e && e.detail && e.detail.label) ? String(e.detail.label).trim() : '';
		var dir   = (e && e.detail && e.detail.direction) ? String(e.detail.direction).trim() : '';
		if (!form) return;
		setForm(form, label, dir || null);
	});

	// Delegated click on [data-bmf-qa-form] — once per page
	if (!window.__bmfQaFormClickBound) {
		window.__bmfQaFormClickBound = true;
		document.addEventListener('click', function(e) {
			var el = e.target.closest('[data-bmf-qa-form]');
			if (!el) return;
			var form = (el.getAttribute('data-bmf-qa-form') || '').trim();
			if (!form) return;
			e.preventDefault();

			document.querySelectorAll('[data-bmf-qa-form].is-active').forEach(function(n) {
				n.classList.remove('is-active');
			});
			el.classList.add('is-active');

			var dir = (el.getAttribute('data-bmf-qa-direction') || '').trim();

			document.dispatchEvent(new CustomEvent('bmf:selected-form', {
				detail: {
					form: form,
					label: (el.textContent || '').trim(),
					direction: dir || null
				}
			}));
		});
	}
})();
</script>
			<?php
			return ob_get_clean();
		}

		public static function ajax_list_responses() {
			check_ajax_referer( 'bmf_qa_nonce', 'nonce' );

			if ( ! is_user_logged_in() ) {
				wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
			}

			$form_id   = absint( $_POST['form_id'] ?? 0 );
			$form_attr = isset( $_POST['form'] ) ? sanitize_text_field( wp_unslash( $_POST['form'] ) ) : '';
			$user_id   = absint( $_POST['user_id'] ?? 0 );
			$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

			if ( ! $form_id && $form_attr !== '' ) {
				$form_id = self::resolve_form_id( $form_attr );
			}

			if ( ! $form_id ) {
				wp_send_json_error( [ 'message' => 'Form not found. Provide a valid form slug or id.' ], 400 );
			}

			$form = BMF_Repository::get_form( $form_id );
			if ( ! $form ) {
				wp_send_json_error( [ 'message' => 'Form not found.' ], 404 );
			}

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

			$user  = get_userdata( $user_id );
			$label = $user ? ( $user->display_name ?: $user->user_email ) : ( 'User #' . $user_id );

			$rows = BMF_Repository::get_submitted_responses_for_user( $user_id, $form_id );
			$list = [];
			foreach ( $rows as $r ) {
				$list[] = [
					'id'           => (int) $r->id,
					'label'        => $r->submitted_at
						? date_i18n( 'M j, Y g:i a', strtotime( $r->submitted_at ) )
						: ( 'Response #' . $r->id ),
					'submitted_at' => (string) ( $r->submitted_at ?? '' ),
				];
			}

			wp_send_json_success( [
				'user_id'      => $user_id,
				'member_label' => $label,
				'form_id'      => $form_id,
				'form_slug'    => (string) $form->slug,
				'form_title'   => (string) $form->title,
				'responses'    => $list,
			] );
		}

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
