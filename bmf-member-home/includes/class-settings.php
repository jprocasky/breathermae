<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Member_Home_Settings {

	public const OPTION = 'bmf_member_home_settings';

	public static function admin_menu() {
		add_options_page(
			'Member Home',
			'Member Home',
			'manage_options',
			'bmf-member-home',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		register_setting(
			'bmf_member_home',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$merged = self::merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		return apply_filters( 'bmf_member_home_settings', $merged );
	}

	public static function defaults(): array {
		return array(
			'chip'  => '360 Health Intelligence',
			'spot_image' => '',
			'spot_link' => '',
			'spot_shortcode' => '',
			'nav'   => array(
				array( 'key' => 'home', 'label' => 'Home', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'results', 'label' => 'Your Results', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'assessments', 'label' => 'Assessments', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'appointments', 'label' => 'Appointments', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'profile', 'label' => 'Health Profile', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'labs', 'label' => 'Lab Tests', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'education', 'label' => 'Educational Tools', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'records', 'label' => 'Electronic Records', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'wearables', 'label' => 'Wearable Devices', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'settings', 'label' => 'User Settings', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
			),
			'result_links' => array(
				array( 'key' => 'rsi', 'label' => 'Rapid Systems Intelligence', 'group' => 'Complementary', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'rapid', 'label' => 'Rapid 8 Pillars', 'group' => 'Complementary', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'keys', 'label' => 'Key Life Essentials', 'group' => 'Art of Wellness', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'pillars', 'label' => '8 Pillars of Wellness', 'group' => 'Art of Wellness', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'bsi', 'label' => 'Breathermae Systems Intelligence', 'group' => '360 Health Intelligence', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'biovoice', 'label' => 'BioVoicePrint', 'group' => '360 Health Intelligence', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
				array( 'key' => 'fitbit', 'label' => 'Fitbit / Wearables', 'group' => '360 Health Intelligence', 'page_id' => 0, 'anchor' => '', 'enabled' => 1 ),
			),
			'cards' => array(
				array(
					'key'     => 'rsi',
					'label'   => 'RSI',
					'blurb'   => 'Recovery Stress Index. Two forms.',
					'page_id' => 0,
					'anchor'  => '',
					'open_page_id' => 0,
					'open_anchor'  => '',
					'cadence' => 7,
					'source'  => 'rsi',
					'enabled' => 1,
				),
				array(
					'key'     => 'rapid',
					'label'   => 'Rapid 8 Pillars',
					'blurb'   => 'Weekly snapshot. One form.',
					'page_id' => 0,
					'anchor'  => '',
					'open_page_id' => 0,
					'open_anchor'  => '',
					'cadence' => 7,
					'source'  => 'pillars_rapid',
					'enabled' => 1,
				),
				array(
					'key'     => 'keys',
					'label'   => 'Key Life Essentials',
					'blurb'   => 'Seven personal-choice forms.',
					'page_id' => 0,
					'anchor'  => '',
					'open_page_id' => 0,
					'open_anchor'  => '',
					'cadence' => 14,
					'source'  => 'keys',
					'enabled' => 1,
				),
				array(
					'key'     => 'pillars',
					'label'   => 'Complete 8 Pillars',
					'blurb'   => 'Full lifestyle cycle. Eight forms.',
					'page_id' => 0,
					'anchor'  => '',
					'open_page_id' => 0,
					'open_anchor'  => '',
					'cadence' => 90,
					'source'  => 'pillars_full',
					'enabled' => 1,
				),
				array(
					'key'     => 'bsi',
					'label'   => 'BSI',
					'blurb'   => 'Body Systems Inventory. Nine forms.',
					'page_id' => 0,
					'anchor'  => '',
					'open_page_id' => 0,
					'open_anchor'  => '',
					'cadence' => 90,
					'source'  => 'bsi',
					'enabled' => 1,
				),
				array(
					'key'     => 'biovoice',
					'label'   => 'BioVoicePrint',
					'blurb'   => 'Voice regulation sessions. Ongoing after baseline.',
					'page_id' => 0,
					'anchor'  => '',
					'open_page_id' => 0,
					'open_anchor'  => '',
					'cadence' => 30,
					'source'  => 'biovoice',
					'enabled' => 1,
				),
			),
			'strip' => array(
				array( 'key' => 'results', 'label' => 'Your Results', 'page_id' => 0, 'anchor' => '' ),
				array( 'key' => 'appointments', 'label' => 'Appointments', 'page_id' => 0, 'anchor' => '' ),
				array( 'key' => 'feedback', 'label' => 'Share feedback', 'page_id' => 0, 'anchor' => '' ),
			),
			'tours' => array(
				array( 'key' => 'rail', 'step' => 1, 'enabled' => 1, 'title' => 'Move around', 'text' => 'These links open the rest of your membership. Results and assessments stay one click away.' ),
				array( 'key' => 'membership', 'step' => 2, 'enabled' => 1, 'title' => 'Your level', 'text' => 'This is your membership: Complementary, Art of Wellness, or 360 Health Intelligence.' ),
				array( 'key' => 'theme', 'step' => 3, 'enabled' => 1, 'title' => 'Light or dark', 'text' => 'Switch the page between light and dark. The choice is saved for this account.' ),
				array( 'key' => 'assessments', 'step' => 4, 'enabled' => 1, 'title' => 'Assessments', 'text' => 'Available means it is time to take it. Current means the last result is still in window. Open results goes to the detail page.' ),
				array( 'key' => 'wellness', 'step' => 5, 'enabled' => 1, 'title' => 'Wellness Index', 'text' => 'A single reading from the assessments you have finished. It appears once enough results are in.' ),
				array( 'key' => 'agenda', 'step' => 6, 'enabled' => 1, 'title' => 'Next 30 days', 'text' => 'Provider appointments. Reminders on the right are separate.' ),
				array( 'key' => 'reminders', 'step' => 7, 'enabled' => 1, 'title' => 'Reminders and notes', 'text' => 'Add to Calendar downloads a file for that reminder. Notes are your own.' ),
			),
			'notices' => array(
				array( 'key' => 'welcome', 'enabled' => 0, 'tag' => '', 'max_days' => 14, 'rev' => '1', 'title' => 'Start here', 'body' => 'Take RSI and Rapid 8 Pillars first. Results show up after you submit.', 'link_label' => 'Continue', 'page_id' => 0, 'anchor' => '' ),
				array( 'key' => 'art', 'enabled' => 0, 'tag' => 'ART', 'max_days' => 0, 'rev' => '1', 'title' => 'Art of Wellness', 'body' => 'Key Life Essentials and Complete 8 Pillars are open on your assessments page.', 'link_label' => 'Continue', 'page_id' => 0, 'anchor' => '' ),
				array( 'key' => 's360', 'enabled' => 0, 'tag' => 'S360', 'max_days' => 0, 'rev' => '1', 'title' => '360 Health Intelligence', 'body' => 'BSI and BioVoicePrint sit with your other results.', 'link_label' => 'Continue', 'page_id' => 0, 'anchor' => '' ),
			),
		);
	}

	private static function merge( array $defaults, array $saved ): array {
		$out = $defaults;
		if ( isset( $saved['chip'] ) ) {
			$out['chip'] = (string) $saved['chip'];
		}
		if ( isset( $saved['spot_image'] ) ) {
			$out['spot_image'] = (string) $saved['spot_image'];
		}
		if ( isset( $saved['spot_link'] ) ) {
			$out['spot_link'] = (string) $saved['spot_link'];
		}
		if ( isset( $saved['spot_shortcode'] ) ) {
			$out['spot_shortcode'] = (string) $saved['spot_shortcode'];
		}
		foreach ( array( 'nav', 'cards', 'strip', 'notices', 'tours', 'result_links' ) as $group ) {
			if ( empty( $saved[ $group ] ) || ! is_array( $saved[ $group ] ) ) {
				continue;
			}
			$by_key = array();
			foreach ( $saved[ $group ] as $row ) {
				if ( is_array( $row ) && ! empty( $row['key'] ) ) {
					$by_key[ (string) $row['key'] ] = $row;
				}
			}
			foreach ( $out[ $group ] as $i => $row ) {
				$key = $row['key'];
				if ( ! isset( $by_key[ $key ] ) ) {
					continue;
				}
				$out[ $group ][ $i ] = array_merge( $row, $by_key[ $key ] );
				$out[ $group ][ $i ]['key'] = $key;
			}
		}
		return $out;
	}

	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = $defaults;
		$clean['chip'] = sanitize_text_field( $input['chip'] ?? $defaults['chip'] );
		$clean['spot_image'] = esc_url_raw( $input['spot_image'] ?? '' );
		$clean['spot_link'] = esc_url_raw( $input['spot_link'] ?? '' );
		$clean['spot_shortcode'] = sanitize_text_field( $input['spot_shortcode'] ?? '' );

		foreach ( array( 'nav', 'cards', 'strip', 'notices', 'tours', 'result_links' ) as $group ) {
			$rows = isset( $input[ $group ] ) && is_array( $input[ $group ] ) ? $input[ $group ] : array();
			foreach ( $clean[ $group ] as $i => $row ) {
				$key = $row['key'];
				$src = self::row_from_input( $rows, $key );
				if ( ! $src ) {
					continue;
				}
				if ( isset( $row['label'] ) ) {
					$clean[ $group ][ $i ]['label'] = sanitize_text_field( $src['label'] ?? $row['label'] );
				}
				$clean[ $group ][ $i ]['page_id'] = absint( $src['page_id'] ?? 0 );
				$clean[ $group ][ $i ]['anchor']  = self::sanitize_anchor( $src['anchor'] ?? '' );
				if ( isset( $row['open_page_id'] ) ) {
					$clean[ $group ][ $i ]['open_page_id'] = absint( $src['open_page_id'] ?? 0 );
					$clean[ $group ][ $i ]['open_anchor']  = self::sanitize_anchor( $src['open_anchor'] ?? '' );
				}
				if ( isset( $row['tag'] ) ) {
					$clean[ $group ][ $i ]['tag'] = sanitize_text_field( $src['tag'] ?? '' );
				}
				if ( isset( $row['max_days'] ) ) {
					$clean[ $group ][ $i ]['max_days'] = max( 0, absint( $src['max_days'] ?? 0 ) );
				}
				if ( isset( $row['rev'] ) ) {
					$clean[ $group ][ $i ]['rev'] = sanitize_text_field( $src['rev'] ?? $row['rev'] );
				}
				if ( isset( $row['link_label'] ) ) {
					$clean[ $group ][ $i ]['link_label'] = sanitize_text_field( $src['link_label'] ?? $row['link_label'] );
				}
				if ( isset( $row['group'] ) ) {
					$clean[ $group ][ $i ]['group'] = sanitize_text_field( $src['group'] ?? $row['group'] );
				}
				if ( isset( $row['step'] ) ) {
					$clean[ $group ][ $i ]['step'] = max( 1, absint( $src['step'] ?? $row['step'] ) );
				}
				if ( isset( $row['text'] ) ) {
					$clean[ $group ][ $i ]['text'] = sanitize_textarea_field( $src['text'] ?? $row['text'] );
				}
				if ( isset( $row['title'] ) ) {
					$clean[ $group ][ $i ]['title'] = sanitize_text_field( $src['title'] ?? '' );
				}
				if ( isset( $row['body'] ) ) {
					$clean[ $group ][ $i ]['body'] = sanitize_textarea_field( $src['body'] ?? '' );
				}
				if ( isset( $row['enabled'] ) ) {
					$clean[ $group ][ $i ]['enabled'] = empty( $src['enabled'] ) ? 0 : 1;
				}
				if ( isset( $row['blurb'] ) ) {
					$clean[ $group ][ $i ]['blurb'] = sanitize_text_field( $src['blurb'] ?? $row['blurb'] );
				}
				if ( isset( $row['cadence'] ) ) {
					$clean[ $group ][ $i ]['cadence'] = max( 1, absint( $src['cadence'] ?? $row['cadence'] ) );
				}
				if ( isset( $row['source'] ) ) {
					$clean[ $group ][ $i ]['source'] = sanitize_key( $src['source'] ?? $row['source'] );
				}
			}
		}
		return $clean;
	}

	/**
	 * Settings API sanitizes twice: once from the form (keyed by slug)
	 * and once from the stored list (numeric, with a key field).
	 * The second pass used to miss every row and save page_id 0.
	 */
	private static function row_from_input( array $rows, string $key ): array {
		if ( isset( $rows[ $key ] ) && is_array( $rows[ $key ] ) ) {
			return $rows[ $key ];
		}
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['key'] ) && (string) $row['key'] === $key ) {
				return $row;
			}
		}
		return array();
	}

	public static function sanitize_anchor( $raw ): string {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return '';
		}
		$raw = ltrim( $raw, '#' );
		$raw = sanitize_title( $raw );
		return $raw === '' ? '' : '#' . $raw;
	}

	public static function url_for( array $row, string $page_key = 'page_id', string $anchor_key = 'anchor' ): string {
		$page_id = (int) ( $row[ $page_key ] ?? 0 );
		if ( $page_id <= 0 ) {
			return '';
		}
		$url = get_permalink( $page_id );
		if ( ! $url ) {
			return '';
		}
		$anchor = (string) ( $row[ $anchor_key ] ?? '' );
		return $anchor !== '' ? $url . $anchor : $url;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get();
		$pages    = get_pages( array( 'sort_column' => 'post_title', 'sort_order' => 'ASC' ) );
		?>
		<div class="wrap">
			<h1>Member Home</h1>
			<p>Take assessment is the surveys page plus an anchor. Open results is the detail page, the same kind of link as the Results rail. Notices are the first matching row: tag blank means anyone, max days 0 means no age limit. Days count from registration.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'bmf_member_home' ); ?>
				<h2>Header</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bmf-home-chip">Membership chip</label></th>
						<td>
							<input type="text" class="regular-text" id="bmf-home-chip" name="<?php echo esc_attr( self::OPTION ); ?>[chip]" value="<?php echo esc_attr( $settings['chip'] ); ?>" />
							<p class="description">Unused. The chip is S360, else ART, else Complementary.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Info panel graphic</th>
						<td>
							<input type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[spot_image]" value="<?php echo esc_attr( $settings['spot_image'] ?? '' ); ?>" placeholder="https:// media image URL" />
							<p class="description">Left 20% of the info row. Paste a Media Library URL.</p>
							<input type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[spot_link]" value="<?php echo esc_attr( $settings['spot_link'] ?? '' ); ?>" placeholder="Optional link" />
							<p class="description">Optional. Wraps the image.</p>
							<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[spot_shortcode]" value="<?php echo esc_attr( $settings['spot_shortcode'] ?? '' ); ?>" placeholder="[some_shortcode]" />
							<p class="description">If set, this replaces the image. Leave both blank to hide the slot.</p>
						</td>
					</tr>
				</table>
				<?php self::render_table( 'Result links', 'result_links', $settings['result_links'], $pages, true ); ?>
				<?php self::render_table( 'Site rail', 'nav', $settings['nav'], $pages, true ); ?>
				<?php self::render_table( 'Assessment cards', 'cards', $settings['cards'], $pages, true, true ); ?>
				<?php self::render_notices( $settings['notices'], $pages ); ?>
				<?php self::render_tours( isset( $settings['tours'] ) ? $settings['tours'] : array() ); ?>
				<?php self::render_table( 'Status strip', 'strip', $settings['strip'], $pages, false ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function render_table( string $title, string $group, array $rows, array $pages, bool $with_enabled, bool $with_card_fields = false ) {
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Key</th><th>Label</th><th>Take page</th><th>Take anchor</th>';
		if ( $with_card_fields ) {
			echo '<th>Results page</th><th>Results anchor</th><th>Cadence</th>';
		}
		if ( $with_enabled ) {
			echo '<th>Show</th>';
		}
		if ( $this_has_group = ! empty( $rows[0]['group'] ) ) {
			echo '<th>Group</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$key  = $row['key'];
			$base = self::OPTION . '[' . $group . '][' . $key . ']';
			echo '<tr>';
			echo '<td><code>' . esc_html( $key ) . '</code></td>';
			echo '<td><input type="text" name="' . esc_attr( $base ) . '[label]" value="' . esc_attr( $row['label'] ) . '" /></td>';
			echo '<td><select name="' . esc_attr( $base ) . '[page_id]"><option value="0">— Select —</option>';
			foreach ( $pages as $page ) {
				printf(
					'<option value="%d" %s>%s</option>',
					(int) $page->ID,
					selected( (int) $row['page_id'], (int) $page->ID, false ),
					esc_html( $page->post_title )
				);
			}
			echo '</select></td>';
			echo '<td><input type="text" name="' . esc_attr( $base ) . '[anchor]" value="' . esc_attr( $row['anchor'] ) . '" placeholder="#rsi" /></td>';
			if ( $with_card_fields ) {
				echo '<td>' . self::page_select( $base . '[open_page_id]', (int) ( $row['open_page_id'] ?? 0 ), $pages ) . '</td>';
				echo '<td><input type="text" name="' . esc_attr( $base ) . '[open_anchor]" value="' . esc_attr( $row['open_anchor'] ?? '' ) . '" placeholder="" /></td>';
				echo '<td><input type="number" min="1" style="width:70px" name="' . esc_attr( $base ) . '[cadence]" value="' . esc_attr( (string) $row['cadence'] ) . '" /></td>';
			}
			if ( $with_enabled ) {
				echo '<td>';
				echo '<input type="hidden" name="' . esc_attr( $base ) . '[enabled]" value="0" />';
				echo '<input type="checkbox" name="' . esc_attr( $base ) . '[enabled]" value="1" ' . checked( ! empty( $row['enabled'] ), true, false ) . ' />';
				echo '</td>';
			}
			if ( isset( $row['group'] ) ) {
				echo '<td><input type="text" name="' . esc_attr( $base ) . '[group]" value="' . esc_attr( $row['group'] ) . '" /></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_notices( array $rows, array $pages ) {
		echo '<h2>Info panel</h2>';
		echo '<p>First matching row is the default. A usermeta value bmf_home_notice replaces it for that member. Blank meta uses this default. There is no dismiss.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Key</th><th>Show</th><th>Tag</th><th>Max days</th><th>Rev</th><th>Title</th><th>Body</th><th>Button</th><th>Link page</th><th>Anchor</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$key  = $row['key'];
			$base = self::OPTION . '[notices][' . $key . ']';
			echo '<tr>';
			echo '<td><code>' . esc_html( $key ) . '</code></td>';
			echo '<td><input type="hidden" name="' . esc_attr( $base ) . '[enabled]" value="0" />';
			echo '<input type="checkbox" name="' . esc_attr( $base ) . '[enabled]" value="1" ' . checked( ! empty( $row['enabled'] ), true, false ) . ' /></td>';
			echo '<td><input type="text" name="' . esc_attr( $base ) . '[tag]" value="' . esc_attr( $row['tag'] ) . '" placeholder="ART" /></td>';
			echo '<td><input type="number" min="0" style="width:70px" name="' . esc_attr( $base ) . '[max_days]" value="' . esc_attr( (string) $row['max_days'] ) . '" /></td>';
			echo '<td><input type="text" style="width:60px" name="' . esc_attr( $base ) . '[rev]" value="' . esc_attr( $row['rev'] ) . '" /></td>';
			echo '<td><input type="text" name="' . esc_attr( $base ) . '[title]" value="' . esc_attr( $row['title'] ) . '" /></td>';
			echo '<td><textarea name="' . esc_attr( $base ) . '[body]" rows="2">' . esc_textarea( $row['body'] ) . '</textarea></td>';
			echo '<td><input type="text" name="' . esc_attr( $base ) . '[link_label]" value="' . esc_attr( $row['link_label'] ) . '" placeholder="Continue" /></td>';
			echo '<td>' . self::page_select( $base . '[page_id]', (int) $row['page_id'], $pages ) . '</td>';
			echo '<td><input type="text" name="' . esc_attr( $base ) . '[anchor]" value="' . esc_attr( $row['anchor'] ) . '" /></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_tours( array $rows ) {
		echo '<h2>Help tour</h2>';
		echo '<p>Same tour as the rest of the site. Steps are attributes on this shortcode, so you do not rebuild the page in Elementor. Restart with [bmf_help_tour_restart].</p>';
		echo '<table class="widefat striped"><thead><tr><th>Region</th><th>Show</th><th>Step</th><th>Title</th><th>Text</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$base = self::OPTION . '[tours][' . $row['key'] . ']';
			echo '<tr><td><code>' . esc_html( $row['key'] ) . '</code></td>';
			echo '<td><input type="hidden" name="' . esc_attr( $base ) . '[enabled]" value="0" />';
			echo '<input type="checkbox" name="' . esc_attr( $base ) . '[enabled]" value="1" ' . checked( ! empty( $row['enabled'] ), true, false ) . ' /></td>';
			echo '<td><input type="number" min="1" style="width:70px" name="' . esc_attr( $base ) . '[step]" value="' . esc_attr( (string) $row['step'] ) . '" /></td>';
			echo '<td><input type="text" name="' . esc_attr( $base ) . '[title]" value="' . esc_attr( $row['title'] ) . '" /></td>';
			echo '<td><textarea name="' . esc_attr( $base ) . '[text]" rows="2">' . esc_textarea( $row['text'] ) . '</textarea></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function page_select( string $name, int $selected_id, array $pages ): string {
		$html = '<select name="' . esc_attr( $name ) . '"><option value="0">— Select —</option>';
		foreach ( $pages as $page ) {
			$html .= sprintf(
				'<option value="%d" %s>%s</option>',
				(int) $page->ID,
				selected( $selected_id, (int) $page->ID, false ),
				esc_html( $page->post_title )
			);
		}
		return $html . '</select>';
	}
}
