<?php
/**
 * BioVoicePrint – Admin: Scripts (Settings → BioVoicePrint Scripts).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_BioVoice_Admin_Scripts {

	const SLUG = 'bmf-biovoice-scripts';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_post' ] );
	}

	public static function register_menu() {
		add_options_page(
			'BioVoicePrint Scripts',
			'BioVoicePrint Scripts',
			'manage_options',
			self::SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function handle_post() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST['bmf_bv_scripts_action'] ) ) {
			return;
		}
		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bmf_bv_scripts' ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['bmf_bv_scripts_action'] ) );

		if ( $action === 'save' ) {
			self::handle_save();
		} elseif ( $action === 'toggle' ) {
			self::handle_toggle();
		}
	}

	private static function handle_save() {
		$id = isset( $_POST['script_id'] ) ? absint( $_POST['script_id'] ) : 0;

		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : 'general';
		$language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : 'en';
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$desc     = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$body     = isset( $_POST['body_text'] ) ? wp_unslash( $_POST['body_text'] ) : '';
		$body     = is_string( $body ) ? trim( $body ) : '';
		$est      = isset( $_POST['estimated_seconds'] ) ? absint( $_POST['estimated_seconds'] ) : 60;
		$sort     = isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0;
		$active   = ! empty( $_POST['is_active'] ) ? 1 : 0;
		$version  = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '1.0';

		if ( $title === '' || $body === '' ) {
			self::redirect( [ 'error' => 'title_body' ] );
		}

		if ( $id > 0 ) {
			$existing = BMF_BioVoice_Repository::get_script( $id );
			if ( ! $existing ) {
				self::redirect( [ 'error' => 'not_found' ] );
			}
			$ok = BMF_BioVoice_Repository::update_script( $id, [
				'category'          => $category,
				'language'          => $language,
				'title'             => $title,
				'description'       => $desc,
				'body_text'         => $body,
				'estimated_seconds' => max( 5, min( 600, $est ) ),
				'sort_order'        => $sort,
				'is_active'         => $active,
				'version'           => $version ?: '1.0',
			] );
			self::redirect( $ok ? [ 'updated' => $id ] : [ 'error' => 'save' ] );
		}

		// New script.
		$code = isset( $_POST['script_code'] ) ? sanitize_key( wp_unslash( $_POST['script_code'] ) ) : '';
		if ( $code === '' ) {
			$code = substr( $category . '_' . $language . '_v' . time(), 0, 60 );
		}
		if ( BMF_BioVoice_Repository::get_script_by_code( $code ) ) {
			self::redirect( [ 'error' => 'code_exists' ] );
		}

		$new_id = BMF_BioVoice_Repository::insert_script( [
			'script_code'       => $code,
			'category'          => $category,
			'language'          => $language,
			'title'             => $title,
			'description'       => $desc,
			'body_text'         => $body,
			'estimated_seconds' => max( 5, min( 600, $est ) ),
			'sort_order'        => $sort,
			'is_active'         => $active,
			'version'           => $version ?: '1.0',
		] );

		self::redirect( $new_id ? [ 'created' => (int) $new_id ] : [ 'error' => 'save' ] );
	}

	private static function handle_toggle() {
		$id = isset( $_POST['script_id'] ) ? absint( $_POST['script_id'] ) : 0;
		if ( $id < 1 ) {
			self::redirect( [ 'error' => 'not_found' ] );
		}
		$script = BMF_BioVoice_Repository::get_script( $id );
		if ( ! $script ) {
			self::redirect( [ 'error' => 'not_found' ] );
		}
		$next = empty( $script['is_active'] ) ? true : false;
		$ok   = BMF_BioVoice_Repository::set_script_active( $id, $next );
		self::redirect( $ok ? [ 'toggled' => $id, 'active' => $next ? 1 : 0 ] : [ 'error' => 'save' ] );
	}

	private static function redirect( array $args ) {
		$url = add_query_arg(
			array_merge( [ 'page' => self::SLUG ], $args ),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing = $edit_id ? BMF_BioVoice_Repository::get_script( $edit_id ) : null;

		$scripts = BMF_BioVoice_Repository::get_all_scripts();

		self::print_notices();
		?>
		<div class="wrap">
			<h1>BioVoicePrint Scripts</h1>
			<p>Manage reading passages shown in the first-session picker. Users lock one script for their baseline series. Disabling hides a script from new choices; existing locks are kept.</p>

			<?php if ( $editing ) : ?>
				<?php self::render_form( $editing ); ?>
			<?php else : ?>
				<?php self::render_form( null ); ?>
			<?php endif; ?>

			<hr style="margin:2rem 0;">

			<h2>All scripts</h2>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th>Code</th>
						<th>Language</th>
						<th>Category</th>
						<th>Title</th>
						<th>Seconds</th>
						<th>Sort</th>
						<th>Active</th>
						<th>Locks</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $scripts ) ) : ?>
					<tr><td colspan="9">No scripts yet. Add one above (or re-load the plugin so seeds run).</td></tr>
				<?php else : ?>
					<?php foreach ( $scripts as $row ) :
						$locks = BMF_BioVoice_Repository::count_locks_for_script( (int) $row['id'] );
						?>
						<tr>
							<td><code><?php echo esc_html( $row['script_code'] ); ?></code></td>
							<td><?php echo esc_html( $row['language'] ); ?></td>
							<td><?php echo esc_html( $row['category'] ); ?></td>
							<td><?php echo esc_html( $row['title'] ); ?></td>
							<td><?php echo (int) $row['estimated_seconds']; ?></td>
							<td><?php echo (int) $row['sort_order']; ?></td>
							<td><?php echo (int) $row['is_active'] ? 'Yes' : 'No'; ?></td>
							<td><?php echo (int) $locks; ?></td>
							<td style="white-space:nowrap;">
								<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::SLUG . '&edit=' . (int) $row['id'] ) ); ?>">Edit</a>
								|
								<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo (int) $row['is_active'] ? 'Disable this script for new users?' : 'Enable this script?'; ?>');">
									<?php wp_nonce_field( 'bmf_bv_scripts' ); ?>
									<input type="hidden" name="bmf_bv_scripts_action" value="toggle">
									<input type="hidden" name="script_id" value="<?php echo (int) $row['id']; ?>">
									<button type="submit" class="button-link"><?php echo (int) $row['is_active'] ? 'Disable' : 'Enable'; ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param array|null $row Existing script or null for create.
	 */
	private static function render_form( $row ) {
		$is_edit = is_array( $row );
		$id      = $is_edit ? (int) $row['id'] : 0;
		?>
		<h2><?php echo $is_edit ? 'Edit script' : 'Add script'; ?></h2>
		<?php if ( $is_edit ) : ?>
			<p>
				<code><?php echo esc_html( $row['script_code'] ); ?></code>
				— script code cannot be changed after create.
				<?php
				$locks = BMF_BioVoice_Repository::count_locks_for_script( $id );
				if ( $locks > 0 ) {
					echo ' <strong style="color:#b45309;">' . (int) $locks . ' user lock(s)</strong> use this script. Changing the body text will affect their future recordings.';
				}
				?>
			</p>
		<?php endif; ?>

		<form method="post" style="max-width:720px;">
			<?php wp_nonce_field( 'bmf_bv_scripts' ); ?>
			<input type="hidden" name="bmf_bv_scripts_action" value="save">
			<input type="hidden" name="script_id" value="<?php echo (int) $id; ?>">

			<table class="form-table" role="presentation">
				<?php if ( ! $is_edit ) : ?>
				<tr>
					<th scope="row"><label for="bmf_script_code">Script code</label></th>
					<td>
						<input name="script_code" id="bmf_script_code" type="text" class="regular-text" pattern="[a-z0-9_\-]+" placeholder="e.g. tech_en_v2">
						<p class="description">Stable machine key (lowercase, numbers, underscore). Leave blank to auto-generate.</p>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="bmf_language">Language</label></th>
					<td>
						<select name="language" id="bmf_language">
							<?php
							$langs = [ 'en' => 'English (en)', 'es' => 'Español (es)' ];
							$cur   = $is_edit ? $row['language'] : 'en';
							foreach ( $langs as $code => $label ) {
								printf(
									'<option value="%s"%s>%s</option>',
									esc_attr( $code ),
									selected( $cur, $code, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bmf_category">Category</label></th>
					<td>
						<select name="category" id="bmf_category">
							<?php
							$cats = [
								'general'         => 'General / Original',
								'technical'       => 'Technical',
								'motivational'    => 'Motivational',
								'public_speaking' => 'Public speaking',
								'conversational'  => 'Conversational',
							];
							$cur = $is_edit ? $row['category'] : 'general';
							foreach ( $cats as $code => $label ) {
								printf(
									'<option value="%s"%s>%s</option>',
									esc_attr( $code ),
									selected( $cur, $code, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bmf_title">Title</label></th>
					<td>
						<input name="title" id="bmf_title" type="text" class="regular-text" required
							value="<?php echo $is_edit ? esc_attr( $row['title'] ) : ''; ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bmf_description">Description</label></th>
					<td>
						<textarea name="description" id="bmf_description" class="large-text" rows="2"><?php
							echo $is_edit ? esc_textarea( $row['description'] ) : '';
						?></textarea>
						<p class="description">Short blurb on the picker cards.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bmf_body_text">Passage text</label></th>
					<td>
						<textarea name="body_text" id="bmf_body_text" class="large-text" rows="8" required><?php
							echo $is_edit ? esc_textarea( $row['body_text'] ) : '';
						?></textarea>
						<p class="description">The text the user reads aloud.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bmf_estimated_seconds">Estimated seconds</label></th>
					<td>
						<input name="estimated_seconds" id="bmf_estimated_seconds" type="number" min="5" max="600" class="small-text"
							value="<?php echo $is_edit ? (int) $row['estimated_seconds'] : 40; ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bmf_sort_order">Sort order</label></th>
					<td>
						<input name="sort_order" id="bmf_sort_order" type="number" class="small-text"
							value="<?php echo $is_edit ? (int) $row['sort_order'] : 0; ?>">
						<p class="description">Lower numbers appear first in the picker.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bmf_version">Version</label></th>
					<td>
						<input name="version" id="bmf_version" type="text" class="small-text"
							value="<?php echo $is_edit ? esc_attr( $row['version'] ) : '1.0'; ?>">
					</td>
				</tr>
				<tr>
					<th scope="row">Active</th>
					<td>
						<label>
							<input type="checkbox" name="is_active" value="1" <?php checked( $is_edit ? (int) $row['is_active'] : 1, 1 ); ?>>
							Show in the first-session picker
						</label>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo $is_edit ? 'Update script' : 'Add script'; ?></button>
				<?php if ( $is_edit ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ); ?>">Cancel</a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	private static function print_notices() {
		if ( ! empty( $_GET['created'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Script created.</p></div>';
		}
		if ( ! empty( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Script updated.</p></div>';
		}
		if ( isset( $_GET['toggled'] ) ) {
			$on = ! empty( $_GET['active'] );
			echo '<div class="notice notice-success is-dismissible"><p>Script ' . ( $on ? 'enabled' : 'disabled' ) . '.</p></div>';
		}
		if ( ! empty( $_GET['error'] ) ) {
			$map = [
				'title_body'  => 'Title and passage text are required.',
				'not_found'   => 'Script not found.',
				'code_exists' => 'That script code already exists.',
				'save'        => 'Could not save. Check the database and try again.',
			];
			$key = sanitize_key( wp_unslash( $_GET['error'] ) );
			$msg = isset( $map[ $key ] ) ? $map[ $key ] : 'Something went wrong.';
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}
}
