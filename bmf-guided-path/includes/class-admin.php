<?php
/**
 * Admin UI for workflow steps.
 *
 * @package BMF_Guided_Path
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Guided_Path_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_bmf_gp_reorder', array( __CLASS__, 'ajax_reorder' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'scripts' ) );
	}

	public static function scripts( $hook ) {
		if ( $hook !== 'toplevel_page_bmf-guided-path' ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
	}

	public static function menu() {
		add_menu_page(
			'Guided Path',
			'Guided Path',
			'manage_options',
			'bmf-guided-path',
			array( __CLASS__, 'page' ),
			'dashicons-randomize',
			58
		);
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bm_workflow_steps';
		$steps = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC", ARRAY_A );

		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$editing = null;
		$insert_before = isset( $_GET['insert_before'] ) ? (int) $_GET['insert_before'] : 0;
		$insert_after  = isset( $_GET['insert_after'] ) ? (int) $_GET['insert_after'] : 0;
		if ( $edit_id ) {
			$editing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ), ARRAY_A );
		}

		echo '<div class="wrap">';
		echo '<h1>Guided Path Steps</h1>';
		echo '<p>Lowest position is evaluated first (first matching step wins). Use the arrows to reorder. Conditions are assembled from the All / Any / None fields.</p>';

		self::render_form( $editing, $insert_before, $insert_after );

		echo '<h2>Current steps</h2>';
		echo '<table class="widefat striped" id="bmf-gp-steps">';
		echo '<thead><tr><th style="width:60px">Move</th><th>Title</th><th>Destination</th><th>Enabled</th><th></th></tr></thead><tbody>';
		if ( empty( $steps ) ) {
			echo '<tr><td colspan="5">No steps yet.</td></tr>';
		}
		$count = count( $steps );
		foreach ( $steps as $i => $step ) {
			$edit_url = admin_url( 'admin.php?page=bmf-guided-path&edit=' . (int) $step['id'] );
			$insert_above = admin_url( 'admin.php?page=bmf-guided-path&insert_before=' . (int) $step['id'] );
			$insert_below = admin_url( 'admin.php?page=bmf-guided-path&insert_after=' . (int) $step['id'] );
			echo '<tr data-id="' . (int) $step['id'] . '">';
			echo '<td>';
			if ( $i > 0 ) {
				echo '<button type="button" class="button button-small bmf-gp-move" data-dir="up" data-id="' . (int) $step['id'] . '">↑</button> ';
			}
			if ( $i < $count - 1 ) {
				echo '<button type="button" class="button button-small bmf-gp-move" data-dir="down" data-id="' . (int) $step['id'] . '">↓</button>';
			}
			echo '</td>';
			echo '<td><strong>' . esc_html( $step['title'] ) . '</strong><br><span style="color:#666">' . esc_html( wp_trim_words( $step['description'], 12 ) ) . '</span></td>';
			echo '<td>' . esc_html( $step['destination'] ) . ( $step['anchor'] ? '#' . esc_html( $step['anchor'] ) : '' ) . '</td>';
			echo '<td>' . ( $step['enabled'] ? 'Yes' : 'No' ) . '</td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">Edit</a>';
			echo ' · <a href="' . esc_url( $insert_above ) . '">Insert above</a>';
			echo ' · <a href="' . esc_url( $insert_below ) . '">Insert below</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Inline reorder handler (no page reload).
		echo '<script>
		jQuery(function($){
			function refreshMoveButtons(){
				var rows = $("#bmf-gp-steps tbody tr");
				rows.each(function(i){
					var $td = $(this).find("td:first");
					var id = $(this).data("id");
					$td.empty();
					if (i > 0) $td.append("<button type=\\"button\\" class=\\"button button-small bmf-gp-move\\" data-dir=\\"up\\" data-id=\\"" + id + "\\">↑</button> ");
					if (i < rows.length - 1) $td.append("<button type=\\"button\\" class=\\"button button-small bmf-gp-move\\" data-dir=\\"down\\" data-id=\\"" + id + "\\">↓</button>");
				});
			}
			$("#bmf-gp-steps").on("click", ".bmf-gp-move", function(){
				var $btn = $(this);
				var $row = $btn.closest("tr");
				var dir = $btn.data("dir");
				var id = $btn.data("id");
				var $swap = (dir === "up") ? $row.prev() : $row.next();
				if (!$swap.length) return;
				if (dir === "up") $row.insertBefore($swap); else $row.insertAfter($swap);
				refreshMoveButtons();
				$.post(ajaxurl, {
					action: "bmf_gp_reorder",
					id: id,
					dir: dir,
					_ajax_nonce: "' . wp_create_nonce( 'bmf_gp_reorder' ) . '"
				});
			});
		});
		</script>';
		echo '</div>';
	}

	protected static function render_form( $step, $insert_before = 0, $insert_after = 0 ) {
		$is_edit = ! empty( $step['id'] );
		$action  = $is_edit ? 'update' : 'create';

		$conds = array();
		if ( ! empty( $step['conditions'] ) ) {
			$conds = json_decode( $step['conditions'], true ) ?: array();
		}
		$all  = implode( ', ', (array) ( $conds['all_present'] ?? array() ) );
		$any  = implode( ', ', (array) ( $conds['any_present'] ?? array() ) );
		$none = implode( ', ', (array) ( $conds['none_present'] ?? array() ) );
		$days_gt = isset( $conds['days_since_gt'] ) ? (int) $conds['days_since_gt'] : '';
		$days_lt = isset( $conds['days_since_lt'] ) ? (int) $conds['days_since_lt'] : '';
		$suppress_key  = (string) ( $conds['suppress_event_key'] ?? '' );
		$suppress_days = isset( $conds['suppress_if_seen_days'] ) ? (int) $conds['suppress_if_seen_days'] : '';

		$tags = self::available_tags();

		$title = $is_edit ? 'Edit step' : ( $insert_before || $insert_after ? 'Insert step' : 'Add step' );
		echo '<h2>' . $title . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'bmf_guided_path_step', 'bmf_guided_path_nonce' );
		echo '<input type="hidden" name="bmf_gp_action" value="' . esc_attr( $action ) . '">';
		if ( $is_edit ) {
			echo '<input type="hidden" name="step_id" value="' . (int) $step['id'] . '">';
		}
		if ( $insert_before ) {
			echo '<input type="hidden" name="insert_before" value="' . (int) $insert_before . '">';
		}
		if ( $insert_after ) {
			echo '<input type="hidden" name="insert_after" value="' . (int) $insert_after . '">';
		}
		echo '<table class="form-table">';
		self::field( 'title', 'Title', $step['title'] ?? '' );
		self::field( 'description', 'Description', $step['description'] ?? '', 'textarea' );

		echo '<tr><th>All present (every tag required)</th><td>';
		self::tag_input( 'all_present', $all, $tags );
		echo '<p class="description">Comma-separated. Leave blank to ignore.</p></td></tr>';

		echo '<tr><th>Any present (at least one)</th><td>';
		self::tag_input( 'any_present', $any, $tags );
		echo '<p class="description">Comma-separated. Leave blank to ignore.</p></td></tr>';

		echo '<tr><th>None present (none of these)</th><td>';
		self::tag_input( 'none_present', $none, $tags );
		echo '<p class="description">Comma-separated. Leave blank to ignore.</p></td></tr>';

		echo '<tr><th>Days since last visit ></th><td>';
		echo '<input type="number" min="0" class="small-text" name="days_since_gt" value="' . esc_attr( $days_gt ) . '">';
		echo '<p class="description">Match if more than this many days since last activity. Leave blank to ignore. Uses usermeta <code>_breathermae_last_active</code>.</p></td></tr>';

		echo '<tr><th>Days since last visit <</th><td>';
		echo '<input type="number" min="0" class="small-text" name="days_since_lt" value="' . esc_attr( $days_lt ) . '">';
		echo '<p class="description">Match if fewer than this many days since last activity. Leave blank to ignore.</p></td></tr>';

		echo '<tr><th>Suppress if event seen</th><td>';
		echo '<input type="text" class="regular-text" name="suppress_event_key" value="' . esc_attr( $suppress_key ) . '" placeholder="rsi_results_viewed"> ';
		echo '<input type="number" min="0" class="small-text" name="suppress_if_seen_days" value="' . esc_attr( $suppress_days ) . '" placeholder="days">';
		echo '<p class="description">Optional. Event key from <code>uls_user_event_log</code> and a day window. If a matching row exists and <code>last_updated</code> is within that many days, this step is skipped. Set days to <strong>0</strong> to suppress forever once the event exists (one-time notice). Leave the key blank to ignore.</p></td></tr>';

		self::field( 'destination', 'Destination (slug or path)', $step['destination'] ?? '' );
		self::field( 'anchor', 'Anchor', $step['anchor'] ?? '' );
		self::field( 'button_label', 'Button label', $step['button_label'] ?? '' );
		echo '<tr><td colspan="2"><p class="description">Optional. Leave blank to show “Continue”. The button sizes to the text.</p></td></tr>';
		self::field( 'enabled', 'Enabled', ! isset( $step['enabled'] ) || $step['enabled'] ? '1' : '0', 'checkbox' );
		self::field( 'notes', 'Internal notes', $step['notes'] ?? '', 'textarea' );
		echo '</table>';
		submit_button( $is_edit ? 'Update step' : 'Add step' );
		if ( $is_edit || $insert_before || $insert_after ) {
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=bmf-guided-path' ) ) . '">Cancel</a>';
		}
		echo '</form>';
	}

	protected static function tag_input( $name, $current, $tags ) {
		echo '<input type="text" class="large-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $current ) . '" list="bmf-gp-tags-' . esc_attr( $name ) . '">';
		if ( ! empty( $tags ) ) {
			echo '<datalist id="bmf-gp-tags-' . esc_attr( $name ) . '">';
			foreach ( $tags as $tag ) {
				echo '<option value="' . esc_attr( $tag ) . '">';
			}
			echo '</datalist>';
		}
	}

	protected static function available_tags() {
		if ( ! function_exists( 'wpf_get_option' ) ) {
			return array();
		}
		$raw = wpf_get_option( 'available_tags', array() );
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $id => $tag ) {
				if ( is_array( $tag ) && isset( $tag['label'] ) ) {
					$out[] = $tag['label'];
				} elseif ( is_string( $tag ) ) {
					$out[] = $tag;
				} else {
					$out[] = (string) $id;
				}
			}
		}
		sort( $out );
		return array_unique( $out );
	}

	protected static function field( $name, $label, $value, $type = 'text' ) {
		echo '<tr><th><label for="bmf_gp_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( $type === 'textarea' ) {
			echo '<textarea class="large-text" rows="3" id="bmf_gp_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
		} elseif ( $type === 'checkbox' ) {
			echo '<label><input type="checkbox" id="bmf_gp_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $value, '1', false ) . '> Enabled</label>';
		} else {
			echo '<input class="regular-text" type="' . esc_attr( $type ) . '" id="bmf_gp_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '</td></tr>';
	}

	public static function ajax_reorder() {
		check_ajax_referer( 'bmf_gp_reorder' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}
		self::reorder( (int) $_POST['id'], sanitize_key( $_POST['dir'] ?? '' ) );
		wp_die( 'ok' );
	}

	public static function handle() {
		if ( empty( $_POST['bmf_gp_action'] ) || empty( $_POST['bmf_guided_path_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bmf_guided_path_nonce'] ) ), 'bmf_guided_path_step' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bm_workflow_steps';

		$conditions = array();
		foreach ( array( 'all_present', 'any_present', 'none_present' ) as $key ) {
			$raw = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
			$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
			if ( ! empty( $parts ) ) {
				$conditions[ $key ] = array_values( $parts );
			}
		}
		if ( isset( $_POST['days_since_gt'] ) && $_POST['days_since_gt'] !== '' ) {
			$conditions['days_since_gt'] = (int) $_POST['days_since_gt'];
		}
		if ( isset( $_POST['days_since_lt'] ) && $_POST['days_since_lt'] !== '' ) {
			$conditions['days_since_lt'] = (int) $_POST['days_since_lt'];
		}
		$suppress_key = sanitize_text_field( wp_unslash( $_POST['suppress_event_key'] ?? '' ) );
		if ( $suppress_key !== '' ) {
			$conditions['suppress_event_key'] = $suppress_key;
		}
		if ( isset( $_POST['suppress_if_seen_days'] ) && $_POST['suppress_if_seen_days'] !== '' ) {
			$conditions['suppress_if_seen_days'] = (int) $_POST['suppress_if_seen_days'];
		}

		$data = array(
			'title'       => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'conditions'  => wp_json_encode( $conditions ),
			'destination' => sanitize_text_field( wp_unslash( $_POST['destination'] ?? '' ) ),
			'anchor'      => sanitize_text_field( wp_unslash( $_POST['anchor'] ?? '' ) ),
			'button_label'=> sanitize_text_field( wp_unslash( $_POST['button_label'] ?? '' ) ),
			'enabled'     => empty( $_POST['enabled'] ) ? 0 : 1,
			'notes'       => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
		);

		$action = sanitize_key( $_POST['bmf_gp_action'] );
		if ( $action === 'create' ) {
			$insert_before = isset( $_POST['insert_before'] ) ? (int) $_POST['insert_before'] : 0;
			$insert_after  = isset( $_POST['insert_after'] ) ? (int) $_POST['insert_after'] : 0;
			if ( $insert_before || $insert_after ) {
				self::insert_relative( $data, $insert_before, $insert_after );
			} else {
				$max = (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM {$table}" );
				$data['sort_order'] = $max + 10;
				$wpdb->insert( $table, $data );
			}
		} elseif ( $action === 'update' && ! empty( $_POST['step_id'] ) ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $_POST['step_id'] ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=bmf-guided-path&updated=1' ) );
		exit;
	}

	protected static function insert_relative( $data, $before_id, $after_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bm_workflow_steps';
		$steps = $wpdb->get_results( "SELECT id FROM {$table} ORDER BY sort_order ASC, id ASC", ARRAY_A );
		$ids   = array_map( 'intval', wp_list_pluck( $steps, 'id' ) );
		$target = $before_id ?: $after_id;
		$pos    = array_search( $target, $ids, true );
		if ( $pos === false ) {
			$max = (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM {$table}" );
			$data['sort_order'] = $max + 10;
			$wpdb->insert( $table, $data );
			return;
		}
		$wpdb->insert( $table, $data );
		$new_id = (int) $wpdb->insert_id;
		if ( $before_id ) {
			array_splice( $ids, $pos, 0, array( $new_id ) );
		} else {
			array_splice( $ids, $pos + 1, 0, array( $new_id ) );
		}
		foreach ( $ids as $i => $step_id ) {
			$wpdb->update( $table, array( 'sort_order' => ( $i + 1 ) * 10 ), array( 'id' => $step_id ) );
		}
	}

	protected static function reorder( $id, $dir ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bm_workflow_steps';
		$steps = $wpdb->get_results( "SELECT id, sort_order FROM {$table} ORDER BY sort_order ASC, id ASC", ARRAY_A );
		$ids   = array_map( 'intval', wp_list_pluck( $steps, 'id' ) );
		$pos   = array_search( $id, $ids, true );
		if ( $pos === false ) {
			return;
		}
		$swap = ( $dir === 'up' ) ? $pos - 1 : $pos + 1;
		if ( ! isset( $ids[ $swap ] ) ) {
			return;
		}
		// Swap positions in the id list, then renumber.
		$tmp = $ids[ $pos ];
		$ids[ $pos ] = $ids[ $swap ];
		$ids[ $swap ] = $tmp;
		foreach ( $ids as $i => $step_id ) {
			$wpdb->update( $table, array( 'sort_order' => ( $i + 1 ) * 10 ), array( 'id' => $step_id ) );
		}
	}
}
