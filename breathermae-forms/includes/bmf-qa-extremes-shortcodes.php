<?php
/**
 * Breathermae Forms – Assessment Extremes Rollup
 *
 * [bmf_qa_extremes threshold="0.75" show_scores="0"]
 * [bmf_qa_extremes_link assessment="bsi"]BSI extremes[/bmf_qa_extremes_link]
 *
 * Panel: class bmf-qa-extremes-wrap + id="extremes" for anchor scroll.
 * AJAX nonce/url injected via window.bmfQaExtremesCfg (survives Elementor attr stripping).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BMF_QA_Extremes_Shortcodes' ) ) {

	class BMF_QA_Extremes_Shortcodes {

		public static function init() {
			add_shortcode( 'bmf_qa_extremes', [ __CLASS__, 'shortcode_panel' ] );
			add_shortcode( 'bmf_qa_extremes_link', [ __CLASS__, 'shortcode_link' ] );
			add_action( 'wp_ajax_bmf_list_assessment_dates', [ __CLASS__, 'ajax_list_dates' ] );
			add_action( 'wp_ajax_bmf_get_assessment_extremes', [ __CLASS__, 'ajax_get_extremes' ] );
		}

		public static function assessments(): array {
			$defaults = [
				'bsi' => [
					'label'         => 'Biological Strain Index',
					'direction'     => 'low_better',
					'results_table' => 'bm_bsi_results',
					'form_ids'      => [ 1, 2, 3, 4, 5, 6, 7, 8, 9 ],
				],
				'rsi' => [
					'label'         => 'Readiness Strain Index',
					'direction'     => 'low_better',
					'results_table' => 'bm_rsi_results',
					'form_ids'      => [ 11, 12 ],
				],
				'pillars' => [
					'label'           => '8 Pillars',
					'direction'       => 'high_better',
					'results_table'   => 'bm_pillars_results',
					'form_ids'        => [ 18, 19, 20, 21, 22, 23, 24, 25 ],
					'show_comparison' => true,
				],
			];
			return apply_filters( 'bmf_qa_assessments', $defaults );
		}

		private static function should_bail_for_editor(): bool {
			$disable = apply_filters( 'bmf/shortcodes/disable_in_elementor', true );
			if ( ! $disable ) {
				return false;
			}
			return function_exists( 'bmf_in_elementor_editor' ) && bmf_in_elementor_editor();
		}

		private static function normalize_direction( string $dir ): string {
			$dir = strtolower( trim( $dir ) );
			return in_array( $dir, [ 'low_better', 'high_better' ], true ) ? $dir : 'low_better';
		}

		private static function normalize_threshold( $raw ): float {
			$t = is_numeric( $raw ) ? (float) $raw : 0.75;
			if ( $t > 1 && $t <= 100 ) {
				$t = $t / 100;
			}
			return max( 0.5, min( 1.0, $t ) );
		}

		private static function can_view_subject( int $subject_user_id ): bool {
			if ( ! is_user_logged_in() || $subject_user_id <= 0 ) {
				return false;
			}
			$current_id  = get_current_user_id();
			$selected_id = (int) get_user_meta( $current_id, 'uls_selected_user_id', true );
			$allowed     = ( $current_id === $subject_user_id )
				|| current_user_can( 'manage_options' )
				|| ( $selected_id > 0 && $selected_id === $subject_user_id );
			$allowed = (bool) apply_filters( 'bmf_qa_can_view_subject', $allowed, $subject_user_id, $current_id );
			if ( ! $allowed && is_user_logged_in() ) {
				$allowed = (bool) apply_filters( 'bmf_qa_allow_any_logged_in', true, $subject_user_id, $current_id );
			}
			return $allowed;
		}

		private static function resolve_user_from_request(): array {
			$user_id = absint( $_POST['user_id'] ?? 0 );
			$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			if ( ! $user_id && $email && is_email( $email ) ) {
				$u = get_user_by( 'email', $email );
				if ( $u ) {
					$user_id = (int) $u->ID;
				}
			}
			$user  = $user_id ? get_userdata( $user_id ) : null;
			$label = $user ? ( $user->display_name ?: $user->user_email ) : '';
			$email = $user ? (string) $user->user_email : $email;
			return [ $user_id, $email, $label ];
		}

		public static function get_final_dates( string $assessment_key, string $email ): array {
			$map = self::assessments();
			if ( empty( $map[ $assessment_key ]['results_table'] ) || $email === '' ) {
				return [];
			}
			global $wpdb;
			$table = $wpdb->prefix . $map[ $assessment_key ]['results_table'];
			$rows  = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT results_date FROM {$table}
					 WHERE user_email = %s AND is_final = 1
					 ORDER BY results_date DESC",
					$email
				)
			);
			return $rows ?: [];
		}

		public static function closest_response_id( int $user_id, int $form_id, string $date ): int {
			if ( $user_id <= 0 || $form_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return 0;
			}
			global $wpdb;
			$t  = $wpdb->prefix . 'bm_responses';
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$t}
					 WHERE user_id = %d AND form_id = %d AND status = 'submitted'
					   AND submitted_at IS NOT NULL
					 ORDER BY ABS(DATEDIFF(DATE(submitted_at), %s)) ASC, submitted_at DESC
					 LIMIT 1",
					$user_id, $form_id, $date
				)
			);
			return $id;
		}

		private static function is_extreme_row( $score, $scale_max, string $direction, float $threshold ): bool {
			if ( $score === null || $scale_max === null || (float) $scale_max <= 0 ) {
				return false;
			}
			$ratio = (float) $score / (float) $scale_max;
			if ( $direction === 'high_better' ) {
				return $ratio <= ( 1 - $threshold );
			}
			return $ratio >= $threshold;
		}

		public static function build_extremes( int $user_id, string $assessment_key, string $date, string $direction, float $threshold ): array {
			$map = self::assessments();
			if ( empty( $map[ $assessment_key ] ) ) {
				return [ 'rows' => [], 'comparison' => null, 'label' => $assessment_key ];
			}
			$cfg      = $map[ $assessment_key ];
			$form_ids = $cfg['form_ids'] ?? [];
			$dir      = $direction ?: ( $cfg['direction'] ?? 'low_better' );
			$rows     = [];

			foreach ( $form_ids as $fid ) {
				$fid = (int) $fid;
				$rid = self::closest_response_id( $user_id, $fid, $date );
				if ( ! $rid ) {
					continue;
				}
				$qa = BMF_Repository::get_response_qa( $rid );
				if ( ! $qa ) {
					continue;
				}
				$form_title = $qa['form']['title'] ?? ( 'Form #' . $fid );
				foreach ( $qa['sections'] as $sec ) {
					foreach ( $sec['questions'] as $q ) {
						if ( ! self::is_extreme_row( $q['score'] ?? null, $q['scale_max'] ?? null, $dir, $threshold ) ) {
							continue;
						}
						$rows[] = [
							'form'         => $form_title,
							'form_id'      => $fid,
							'section'      => $sec['title'] ?? '',
							'order_index'  => $q['order_index'] ?? 0,
							'prompt'       => $q['prompt'] ?? '',
							'answer_label' => $q['answer_label'] ?? '',
							'score'        => $q['score'],
							'scale_max'    => $q['scale_max'],
						];
					}
				}
			}

			$comparison = null;
			if ( ! empty( $cfg['show_comparison'] ) ) {
				$comparison = self::pillars_comparison( $user_id, $date );
			}

			return [
				'rows'       => $rows,
				'comparison' => $comparison,
				'label'      => $cfg['label'] ?? $assessment_key,
				'direction'  => $dir,
				'assessment' => $assessment_key,
				'date'       => $date,
			];
		}

		public static function pillars_comparison( int $user_id, string $date ): ?array {
			$user = get_userdata( $user_id );
			if ( ! $user || empty( $user->user_email ) ) {
				return null;
			}
			global $wpdb;
			$t   = $wpdb->prefix . 'bm_pillars_results';
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$t}
					 WHERE user_email = %s AND results_date = %s AND is_final = 1
					 ORDER BY id DESC LIMIT 1",
					$user->user_email, $date
				),
				ARRAY_A
			);
			if ( ! $row ) {
				return null;
			}

			$pillars = [
				'physical'      => (float) ( $row['physical'] ?? 0 ),
				'mental'        => (float) ( $row['mental'] ?? 0 ),
				'emotional'     => (float) ( $row['emotional'] ?? 0 ),
				'financial'     => (float) ( $row['financial'] ?? 0 ),
				'occupational'  => (float) ( $row['occupational'] ?? 0 ),
				'environmental' => (float) ( $row['environmental'] ?? 0 ),
				'spiritual'     => (float) ( $row['spiritual'] ?? 0 ),
				'social'        => (float) ( $row['social'] ?? 0 ),
			];
			foreach ( $pillars as $k => $v ) {
				$pillars[ $k ] = ( $v <= 1.0 ) ? round( $v * 100, 1 ) : round( $v, 1 );
			}
			arsort( $pillars );
			$actual_labels = array_keys( $pillars );
			$actual_scores = array_values( $pillars );

			$rank_str  = $row['rank'] ?? '';
			$perceived = [];
			if ( $rank_str ) {
				$decoded   = urldecode( (string) $rank_str );
				$perceived = array_map( 'ucfirst', array_map( 'trim', explode( ',', $decoded ) ) );
			}

			$master = isset( $row['master_score'] ) ? round( (float) $row['master_score'], 1 ) : null;
			$items  = [];
			$max    = max( count( $perceived ), count( $actual_labels ) );
			for ( $i = 0; $i < $max; $i++ ) {
				$perc     = $perceived[ $i ] ?? '—';
				$act      = isset( $actual_labels[ $i ] ) ? ucfirst( $actual_labels[ $i ] ) : '—';
				$sc       = $actual_scores[ $i ] ?? null;
				$perc_pos = false;
				if ( $act !== '—' ) {
					$perc_pos = array_search( strtolower( $act ), array_map( 'strtolower', $perceived ), true );
				}
				$diff    = ( $perc_pos !== false ) ? ( (int) $perc_pos - $i ) : null;
				$items[] = [
					'perceived' => $perc,
					'actual'    => $act,
					'score'     => $sc,
					'diff'      => $diff,
				];
			}

			return [
				'master' => $master,
				'date'   => $row['results_date'] ?? $date,
				'items'  => $items,
			];
		}

		public static function shortcode_link( $atts, $content = null ) {
			if ( self::should_bail_for_editor() ) {
				return is_string( $content ) ? $content : '';
			}
			$atts = shortcode_atts(
				[
					'assessment' => '',
					'direction'  => '',
					'class'      => '',
				],
				$atts,
				'bmf_qa_extremes_link'
			);
			$key = strtolower( trim( (string) $atts['assessment'] ) );
			if ( $key === '' ) {
				return is_string( $content ) ? $content : '';
			}
			$map   = self::assessments();
			$label = is_string( $content ) ? trim( $content ) : '';
			if ( $label === '' ) {
				$label = $map[ $key ]['label'] ?? $key;
			}
			$class = 'bmf-qa-extremes-link';
			if ( $atts['class'] !== '' ) {
				$class .= ' ' . sanitize_html_class( $atts['class'] );
			}
			$dir_attr = '';
			if ( $atts['direction'] !== '' ) {
				$dir_attr = ' data-bmf-qa-direction="' . esc_attr( self::normalize_direction( $atts['direction'] ) ) . '"';
			} elseif ( ! empty( $map[ $key ]['direction'] ) ) {
				$dir_attr = ' data-bmf-qa-direction="' . esc_attr( self::normalize_direction( $map[ $key ]['direction'] ) ) . '"';
			}

			wp_enqueue_style( 'bmf-qa' );

			return sprintf(
				'<a href="#extremes" class="%s" data-bmf-qa-assessment="%s"%s role="button">%s</a>',
				esc_attr( $class ),
				esc_attr( $key ),
				$dir_attr,
				esc_html( $label )
			);
		}

		public static function shortcode_panel( $atts ) {
			if ( self::should_bail_for_editor() ) {
				return '';
			}
			if ( ! is_user_logged_in() ) {
				return '<div class="bmf-qa-empty">Please log in to view extremes.</div>';
			}

			$atts = shortcode_atts(
				[
					'assessment'  => '',
					'threshold'   => '0.75',
					'show_scores' => '0',
					'direction'   => '',
				],
				$atts,
				'bmf_qa_extremes'
			);

			$threshold   = self::normalize_threshold( $atts['threshold'] );
			$show_scores = ( (int) $atts['show_scores'] === 1 );
			$assessment  = strtolower( trim( (string) $atts['assessment'] ) );
			$direction   = $atts['direction'] !== ''
				? self::normalize_direction( (string) $atts['direction'] )
				: '';

			$nonce    = wp_create_nonce( 'bmf_qa_nonce' );
			$ajax_url = admin_url( 'admin-ajax.php' );

			wp_enqueue_style( 'bmf-qa' );

			ob_start();
			?>
<div class="bmf-qa-wrap bmf-qa-extremes-wrap"
	 id="extremes"
	 data-assessment="<?php echo esc_attr( $assessment ); ?>"
	 data-show-scores="<?php echo $show_scores ? '1' : '0'; ?>"
	 data-threshold="<?php echo esc_attr( (string) $threshold ); ?>"
	 data-direction="<?php echo esc_attr( $direction ); ?>">

	<div class="bmf-qa-header">
		<h4 class="bmf-qa-title bmf-qa-ext-title">— select an assessment —</h4>
		<span class="bmf-qa-meta">Member: <strong class="bmf-qa-member-label">Select a User</strong></span>
		<label class="bmf-qa-meta bmf-qa-select-wrap" style="display:none">
			<span>Cycle:</span>
			<select class="bmf-qa-select bmf-qa-date-select"></select>
		</label>
		<button type="button" class="bmf-qa-export" disabled>Export CSV</button>
	</div>

	<div class="bmf-qa-comparison" style="display:none"></div>

	<div class="bmf-qa-body">
		<div class="bmf-qa-table-wrap">
			<div class="bmf-qa-empty">Select a User, then click an assessment extremes link.</div>
		</div>
	</div>
</div>
<style>
.bmf-qa-extremes-link, [data-bmf-qa-assessment] {
	cursor: pointer; text-decoration: none; color: #001d50;
	border-bottom: 1px dashed transparent;
}
.bmf-qa-extremes-link:hover, [data-bmf-qa-assessment]:hover { border-bottom-color: #6ec1e4; color: #0b3a8a; }
.bmf-qa-extremes-link.is-active, [data-bmf-qa-assessment].is-active {
	font-weight: 600; background: rgba(110,193,228,.15); border-bottom-color: #6ec1e4;
	padding: 0 4px; border-radius: 3px;
}
.bmf-qa-comparison {
	margin: 0 0 14px; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 8px; background: #f8fafc;
}
.bmf-qa-comparison h5 { margin: 0 0 8px; color: #001d50; font-size: 0.95rem; }
.bmf-qa-cmp-grid {
	display: grid; grid-template-columns: 1fr auto 1fr; gap: 8px 12px; align-items: center; font-size: 0.9rem;
}
.bmf-qa-cmp-cell {
	background: #fff; border: 1px solid #233b6d; border-radius: 8px; padding: 6px 10px;
}
.bmf-qa-cmp-cell.right { text-align: right; }
.bmf-qa-cmp-ind { text-align: center; font-weight: 600; min-width: 48px; }
.bmf-qa-extremes-wrap { scroll-margin-top: 80px; }
</style>
<script>
window.bmfQaExtremesCfg = {
	ajax: <?php echo wp_json_encode( $ajax_url ); ?>,
	nonce: <?php echo wp_json_encode( $nonce ); ?>
};
(function(){
	var CFG = window.bmfQaExtremesCfg || {};
	var state = {
		assessment: '',
		userId: '',
		email: '',
		direction: '',
		threshold: 0.75,
		date: '',
		lastRows: null,
		lastLabel: '',
		lastMember: ''
	};

	/** Prefer the real panel class — #extremes may collide with an Elementor section. */
	function panel() {
		return document.querySelector('.bmf-qa-extremes-wrap');
	}

	function els() {
		var root = panel();
		if (!root) return null;
		return {
			root: root,
			bodyEl: root.querySelector('.bmf-qa-table-wrap'),
			memberEl: root.querySelector('.bmf-qa-member-label'),
			titleEl: root.querySelector('.bmf-qa-ext-title'),
			exportBtn: root.querySelector('.bmf-qa-export'),
			cmpEl: root.querySelector('.bmf-qa-comparison'),
			selectWrap: root.querySelector('.bmf-qa-select-wrap'),
			selectEl: root.querySelector('.bmf-qa-date-select'),
			ajaxUrl: CFG.ajax || '',
			nonce: CFG.nonce || '',
			showScores: root.getAttribute('data-show-scores') === '1'
		};
	}

	function initFromPanel() {
		var root = panel();
		if (!root) return;
		var a = root.getAttribute('data-assessment') || '';
		var d = root.getAttribute('data-direction') || '';
		var t = root.getAttribute('data-threshold') || '0.75';
		if (a) state.assessment = a;
		if (d) state.direction = d;
		state.threshold = parseFloat(t) || 0.75;
	}

	function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }

	function setEmpty(msg){
		var e = els();
		if (!e || !e.bodyEl) return;
		e.bodyEl.innerHTML = '<div class="bmf-qa-empty">'+esc(msg)+'</div>';
		if (e.selectWrap) e.selectWrap.style.display='none';
		if (e.cmpEl){ e.cmpEl.style.display='none'; e.cmpEl.innerHTML=''; }
		state.lastRows=null;
		if (e.exportBtn) e.exportBtn.disabled=true;
	}

	function discoverMemberEmail() {
		if (state.email) return state.email;
		var row = document.querySelector('.uls-members__row.is-selected[data-email], tr.is-selected[data-email], .is-selected[data-email]');
		if (row) {
			var em = (row.getAttribute('data-email') || '').trim();
			if (em) return em;
		}
		var qa = document.querySelector('.bmf-qa-wrap:not(.bmf-qa-extremes-wrap)[data-email]');
		if (qa) {
			var em2 = (qa.getAttribute('data-email') || '').trim();
			if (em2) return em2;
		}
		return '';
	}

	function ensureMember() {
		var email = discoverMemberEmail();
		if (email && email !== state.email) {
			applyMember(0, email, email);
			return true;
		}
		return !!(state.userId || state.email);
	}

	function applyMember(userId, email, displayName) {
		var e = els();
		state.userId = userId ? String(userId) : '';
		state.email = email || '';
		state.lastMember = displayName || email || '';
		if (e && e.memberEl) {
			e.memberEl.textContent = displayName || email || (userId ? ('User #' + userId) : 'Select a User');
		}
	}

	function renderComparison(cmp){
		var e = els(); if (!e || !e.cmpEl) return;
		if (!cmp || !cmp.items || !cmp.items.length){
			e.cmpEl.style.display='none'; e.cmpEl.innerHTML=''; return;
		}
		var html = '<h5>Perceived rank vs actual scores</h5>';
		if (cmp.master != null) html += '<p class="bmf-qa-meta" style="margin:0 0 8px">Overall average: <strong>'+esc(cmp.master)+'%</strong></p>';
		html += '<div class="bmf-qa-cmp-grid">';
		html += '<div class="bmf-qa-meta" style="font-weight:600">Perceived</div><div></div><div class="bmf-qa-meta" style="font-weight:600;text-align:right">Actual</div>';
		cmp.items.forEach(function(it){
			var icon=''; var color='#999';
			if (it.diff === 0){ icon='✓'; color='#22c55e'; }
			else if (it.diff > 0){ icon='↑ '+Math.abs(it.diff); color='#3b82f6'; }
			else if (it.diff < 0){ icon='↓ '+Math.abs(it.diff); color='#f97316'; }
			html += '<div class="bmf-qa-cmp-cell">'+esc(it.perceived)+'</div>';
			html += '<div class="bmf-qa-cmp-ind" style="color:'+color+'">'+esc(icon)+'</div>';
			html += '<div class="bmf-qa-cmp-cell right">'+esc(it.actual)+(it.score!=null?' <span style="color:#666">('+esc(it.score)+'%)</span>':'')+'</div>';
		});
		html += '</div>';
		e.cmpEl.innerHTML = html;
		e.cmpEl.style.display = 'block';
	}

	function renderRows(data){
		var e = els(); if (!e || !e.bodyEl) return;
		var rows = (data && data.rows) ? data.rows : [];
		state.lastRows = rows;
		state.lastLabel = (data && data.label) ? data.label : state.assessment;
		if (e.titleEl) e.titleEl.textContent = state.lastLabel + ' — extremes';
		renderComparison(data && data.comparison ? data.comparison : null);
		if (!rows.length){
			setEmpty('No extreme answers found for this cycle.');
			if (data && data.comparison) renderComparison(data.comparison);
			return;
		}
		if (e.exportBtn) e.exportBtn.disabled = false;
		var html = '<table class="bmf-qa-table"><thead><tr>';
		html += '<th>Form</th><th>Section</th><th class="bmf-qa-q-num">#</th><th>Question</th><th>Answer</th>';
		if (e.showScores) html += '<th class="bmf-qa-score">Score</th>';
		html += '</tr></thead><tbody>';
		rows.forEach(function(r){
			html += '<tr class="bmf-qa-extreme">';
			html += '<td>'+esc(r.form)+'</td><td>'+esc(r.section)+'</td>';
			html += '<td class="bmf-qa-q-num">'+esc(r.order_index)+'</td>';
			html += '<td>'+esc(r.prompt)+'</td>';
			html += '<td class="bmf-qa-answer">'+esc(r.answer_label||'—')+'</td>';
			if (e.showScores) html += '<td class="bmf-qa-score">'+(r.score!=null?esc(r.score):'—')+'</td>';
			html += '</tr>';
		});
		html += '</tbody></table>';
		e.bodyEl.innerHTML = html;
	}

	function csvEscape(v){
		var s = v==null ? '' : String(v);
		if (/[",\n\r]/.test(s)) return '"'+s.replace(/"/g,'""')+'"';
		return s;
	}
	function exportCsv(){
		if (!state.lastRows) return;
		var lines = [['Assessment','Date','Form','Section','#','Question','Answer','Score','Extreme'].map(csvEscape).join(',')];
		state.lastRows.forEach(function(r){
			lines.push([state.lastLabel, state.date, r.form, r.section, r.order_index, r.prompt, r.answer_label, r.score!=null?r.score:'', 'Yes'].map(csvEscape).join(','));
		});
		var blob = new Blob([lines.join('\r\n')], {type:'text/csv;charset=utf-8;'});
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		var member = (state.lastMember||state.email||'member').replace(/[^a-z0-9._-]+/gi,'_');
		var assess = (state.assessment||'assessment').replace(/[^a-z0-9._-]+/gi,'_');
		a.href=url; a.download='extremes-'+member+'-'+assess+'-'+(state.date||'')+'.csv';
		document.body.appendChild(a); a.click(); document.body.removeChild(a);
		URL.revokeObjectURL(url);
	}

	function loadExtremes(){
		var e = els(); if (!e) return;
		if (!CFG.nonce || !CFG.ajax) {
			setEmpty('Extremes config missing (nonce/ajax). Hard-refresh the page.');
			return;
		}
		if (!state.assessment){ setEmpty('Click an assessment extremes link (BSI, RSI, or Pillars).'); return; }
		if (!(state.userId || state.email)){ setEmpty('Select a User to view extremes.'); return; }
		if (!state.date){ setEmpty('No finalized cycles found for this assessment.'); return; }
		e.root.classList.add('bmf-qa-loading');
		if (e.bodyEl) e.bodyEl.innerHTML = '<div class="bmf-qa-empty">Loading extremes…</div>';
		var fd = new FormData();
		fd.append('action','bmf_get_assessment_extremes');
		fd.append('nonce', CFG.nonce);
		fd.append('assessment', state.assessment);
		fd.append('date', state.date);
		fd.append('threshold', String(state.threshold));
		if (state.direction) fd.append('direction', state.direction);
		if (state.userId) fd.append('user_id', state.userId);
		if (state.email) fd.append('email', state.email);
		fetch(CFG.ajax,{method:'POST',body:fd,credentials:'same-origin'})
			.then(function(r){return r.json();})
			.then(function(resp){
				var e2 = els(); if (e2) e2.root.classList.remove('bmf-qa-loading');
				if (!resp || !resp.success){
					setEmpty((resp&&resp.data&&resp.data.message)||'Could not load extremes.');
					return;
				}
				renderRows(resp.data||{});
			})
			.catch(function(){ var e2=els(); if(e2) e2.root.classList.remove('bmf-qa-loading'); setEmpty('Error loading extremes.'); });
	}

	function loadDates(thenLoad){
		var e = els(); if (!e) return;
		if (!CFG.nonce || !CFG.ajax) {
			setEmpty('Extremes config missing (nonce/ajax). Hard-refresh the page.');
			return;
		}
		if (!state.assessment){ setEmpty('Click an assessment extremes link (BSI, RSI, or Pillars).'); return; }
		if (!(state.userId || state.email)){
			if (!ensureMember()) {
				setEmpty('Select a User to view extremes.');
				return;
			}
		}
		e.root.classList.add('bmf-qa-loading');
		var fd = new FormData();
		fd.append('action','bmf_list_assessment_dates');
		fd.append('nonce', CFG.nonce);
		fd.append('assessment', state.assessment);
		if (state.userId) fd.append('user_id', state.userId);
		if (state.email) fd.append('email', state.email);
		fetch(CFG.ajax,{method:'POST',body:fd,credentials:'same-origin'})
			.then(function(r){return r.json();})
			.then(function(resp){
				var e2 = els();
				if (e2) e2.root.classList.remove('bmf-qa-loading');
				if (!resp || !resp.success){
					setEmpty((resp&&resp.data&&resp.data.message)||'Could not load dates.');
					return;
				}
				var data = resp.data || {};
				if (data.member_label){
					state.lastMember = data.member_label;
					if (e2 && e2.memberEl) e2.memberEl.textContent = data.member_label;
				}
				if (data.user_id){ state.userId = String(data.user_id); }
				if (data.label && e2 && e2.titleEl) e2.titleEl.textContent = data.label + ' — extremes';
				var dates = data.dates || [];
				if (!e2 || !e2.selectEl) return;
				e2.selectEl.innerHTML = '';
				if (!dates.length){
					state.date = '';
					if (e2.selectWrap) e2.selectWrap.style.display='none';
					setEmpty('No finalized cycles found for this member and assessment.');
					return;
				}
				dates.forEach(function(d,i){
					var opt=document.createElement('option');
					opt.value=d; opt.textContent=d;
					if(i===0) opt.selected=true;
					e2.selectEl.appendChild(opt);
				});
				if (e2.selectWrap) e2.selectWrap.style.display='inline-flex';
				state.date = dates[0];
				if (thenLoad !== false) loadExtremes();
			})
			.catch(function(){ var e2=els(); if(e2) e2.root.classList.remove('bmf-qa-loading'); setEmpty('Error loading dates.'); });
	}

	function setAssessment(key, label, direction){
		key = (key||'').toString().trim().toLowerCase();
		if (!key) return;
		state.assessment = key;
		if (direction) state.direction = direction;
		var e = els();
		if (label && e && e.titleEl) e.titleEl.textContent = label + ' — extremes';
		ensureMember();
		loadDates(true);
	}

	function scrollToExtremes(){
		var el = panel();
		if (!el) return;
		if (history.replaceState) history.replaceState(null, '', '#extremes');
		else location.hash = 'extremes';
		el.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	if (!window.__bmfQaExtremesBound) {
		window.__bmfQaExtremesBound = true;

		document.addEventListener('uls:selected-member', function(ev){
			var email = (ev && ev.detail && ev.detail.email) ? String(ev.detail.email).trim() : '';
			if (!email) return;
			applyMember(0, email, email);
			if (state.assessment) loadDates(true);
			else setEmpty('Select a User, then click an assessment extremes link.');
		});

		document.addEventListener('click', function(ev){
			var el = ev.target.closest('[data-bmf-qa-assessment]');
			if (!el) return;
			var a = (el.getAttribute('data-bmf-qa-assessment') || '').trim();
			if (!a) return;
			ev.preventDefault();

			document.querySelectorAll('[data-bmf-qa-assessment].is-active').forEach(function(n){ n.classList.remove('is-active'); });
			el.classList.add('is-active');

			scrollToExtremes();

			var dir = (el.getAttribute('data-bmf-qa-direction') || '').trim();
			var label = (el.textContent || '').trim();
			setAssessment(a, label, dir || null);
		});

		document.addEventListener('change', function(ev){
			var root = panel();
			if (!root || !ev.target) return;
			if (ev.target.classList && ev.target.classList.contains('bmf-qa-date-select') && root.contains(ev.target)) {
				state.date = ev.target.value;
				loadExtremes();
			}
		});

		document.addEventListener('click', function(ev){
			var root = panel();
			if (!root) return;
			var btn = ev.target.closest('.bmf-qa-export');
			if (btn && root.contains(btn)) exportCsv();
		});
	}

	initFromPanel();
})();
</script>
			<?php
			return ob_get_clean();
		}

		public static function ajax_list_dates() {
			check_ajax_referer( 'bmf_qa_nonce', 'nonce' );

			if ( ! is_user_logged_in() ) {
				wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
			}

			$assessment = strtolower( sanitize_key( $_POST['assessment'] ?? '' ) );
			$map        = self::assessments();
			if ( empty( $map[ $assessment ] ) ) {
				wp_send_json_error( [ 'message' => 'Unknown assessment.' ], 400 );
			}

			list( $user_id, $email, $label ) = self::resolve_user_from_request();
			if ( ! $user_id ) {
				wp_send_json_error( [ 'message' => 'No WordPress user found for that email.' ], 404 );
			}
			if ( ! self::can_view_subject( $user_id ) ) {
				wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
			}

			$dates = self::get_final_dates( $assessment, $email );
			wp_send_json_success( [
				'user_id'      => $user_id,
				'member_label' => $label,
				'assessment'   => $assessment,
				'label'        => $map[ $assessment ]['label'] ?? $assessment,
				'dates'        => $dates,
			] );
		}

		public static function ajax_get_extremes() {
			check_ajax_referer( 'bmf_qa_nonce', 'nonce' );

			if ( ! is_user_logged_in() ) {
				wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
			}

			$assessment = strtolower( sanitize_key( $_POST['assessment'] ?? '' ) );
			$date       = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
			$threshold  = self::normalize_threshold( $_POST['threshold'] ?? 0.75 );
			$direction  = isset( $_POST['direction'] ) ? self::normalize_direction( (string) $_POST['direction'] ) : '';
			$map        = self::assessments();

			if ( empty( $map[ $assessment ] ) ) {
				wp_send_json_error( [ 'message' => 'Unknown assessment.' ], 400 );
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				wp_send_json_error( [ 'message' => 'Invalid date.' ], 400 );
			}
			if ( $direction === '' ) {
				$direction = self::normalize_direction( $map[ $assessment ]['direction'] ?? 'low_better' );
			}

			list( $user_id, $email, $label ) = self::resolve_user_from_request();
			if ( ! $user_id ) {
				wp_send_json_error( [ 'message' => 'No WordPress user found for that email.' ], 404 );
			}
			if ( ! self::can_view_subject( $user_id ) ) {
				wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
			}

			$data = self::build_extremes( $user_id, $assessment, $date, $direction, $threshold );
			$data['member_label'] = $label;
			$data['user_id']      = $user_id;
			wp_send_json_success( $data );
		}
	}

	BMF_QA_Extremes_Shortcodes::init();
}
