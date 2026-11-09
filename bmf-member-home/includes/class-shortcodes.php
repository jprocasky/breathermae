<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Member_Home_Shortcodes {

	public static function init() {
		add_shortcode( 'bmf_member_home', array( __CLASS__, 'home' ) );
		add_shortcode( 'bmf_member_nav', array( __CLASS__, 'nav' ) );
		add_shortcode( 'bmf_member_today', array( __CLASS__, 'today' ) );
		add_shortcode( 'bmf_assessment_status', array( __CLASS__, 'assessment_status' ) );
		add_shortcode( 'bmf_assessment_timeline', array( __CLASS__, 'assessment_timeline' ) );
		add_action( 'wp_ajax_bmf_member_home_theme', array( __CLASS__, 'ajax_theme' ) );
	}

	public static function home( $atts ): string {
		unset( $atts );
		if ( ! is_user_logged_in() ) {
			return '<p class="bmf-home-login">Please log in.</p>';
		}
		self::enqueue();
		return '<div class="' . esc_attr( self::wrap_class() ) . '" data-bmf-theme="' . esc_attr( self::theme() ) . '">'
			. self::menu_button()
			. self::nav_markup( 'home' )
			. '<div class="bmf-home-main">' . self::today_markup() . '</div>'
			. '</div>';
	}

	public static function nav( $atts ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		self::enqueue();
		$atts = shortcode_atts( array( 'context' => 'home' ), $atts, 'bmf_member_nav' );
		return '<div class="' . esc_attr( self::wrap_class( true ) ) . '" data-bmf-theme="' . esc_attr( self::theme() ) . '" data-context="' . esc_attr( $atts['context'] ) . '">'
			. self::menu_button()
			. self::nav_markup( $atts['context'] )
			. '</div>';
	}

	public static function today( $atts ): string {
		unset( $atts );
		if ( ! is_user_logged_in() ) {
			return '<p class="bmf-home-login">Please log in.</p>';
		}
		self::enqueue();
		return '<div class="' . esc_attr( self::wrap_class( true ) ) . '" data-bmf-theme="' . esc_attr( self::theme() ) . '">'
			. self::today_markup()
			. '</div>';
	}

	private static function wrap_class( bool $solo = false ): string {
		return 'bmf-home' . ( $solo ? ' bmf-home--solo' : '' );
	}

	private static function theme(): string {
		$uid = get_current_user_id();
		$raw = $uid > 0 ? get_user_meta( $uid, 'bmf_ui_theme', true ) : '';
		return $raw === 'light' ? 'light' : 'dark';
	}

	public static function ajax_theme() {
		check_ajax_referer( 'bmf_member_home', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'login' ), 403 );
		}
		$theme = isset( $_POST['theme'] ) && sanitize_key( wp_unslash( $_POST['theme'] ) ) === 'light' ? 'light' : 'dark';
		update_user_meta( get_current_user_id(), 'bmf_ui_theme', $theme );
		wp_send_json_success( array( 'theme' => $theme ) );
	}

	private static function enqueue() {
		wp_enqueue_style(
			'bmf-member-home',
			BMF_MEMBER_HOME_URL . 'assets/css/home.css',
			array(),
			BMF_MEMBER_HOME_VERSION
		);
		if ( defined( 'BMF_WELLBEING_URL' ) ) {
			wp_enqueue_style(
				'bmf-wellbeing',
				BMF_WELLBEING_URL . 'assets/css/wellbeing.css',
				array(),
				defined( 'BMF_WELLBEING_VERSION' ) ? BMF_WELLBEING_VERSION : BMF_MEMBER_HOME_VERSION
			);
		}
		wp_enqueue_script(
			'bmf-member-home',
			BMF_MEMBER_HOME_URL . 'assets/js/home.js',
			array(),
			BMF_MEMBER_HOME_VERSION,
			true
		);
		wp_localize_script(
			'bmf-member-home',
			'bmfMemberHome',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'bmf_member_home' ),
			)
		);
	}

	private static function menu_button(): string {
		return '<button type="button" class="bmf-home-menu" aria-expanded="false" aria-controls="bmf-home-rail">'
			. '<span class="bmf-home-menu-bars" aria-hidden="true"></span><span>Menu</span></button>'
			. '<div class="bmf-home-backdrop" hidden></div>';
	}

	private static function nav_markup( string $context = 'home' ): string {
		$settings = BMF_Member_Home_Settings::get();
		$html     = '<aside id="bmf-home-rail" class="bmf-home-rail" aria-label="Site"' . self::help_attrs( 'rail' ) . '>';
		$html    .= '<button type="button" class="bmf-home-menu-close" aria-label="Close menu">Close</button>';
		$results  = self::link_list( $settings['result_links'] ?? array(), true );
		$site     = self::link_list( $settings['nav'] ?? array(), false );
		if ( $context === 'results' ) {
			$html .= '<p class="bmf-home-rail-label">Full results</p>' . $results;
			$html .= '<p class="bmf-home-rail-label bmf-home-rail-next">Site</p>' . $site;
		} else {
			$html .= '<p class="bmf-home-rail-label">Site</p>' . $site;
		}
		$html .= self::rail_graphic( $settings );
		$html .= '</aside>';
		return $html;
	}

	/**
	 * Info Panel Graphic under the rail links, when a path is set.
	 * Same spot_image / spot_link used in the info row. Width is the rail.
	 */
	private static function rail_graphic( array $settings ): string {
		$image = esc_url( $settings['spot_image'] ?? '' );
		if ( $image === '' ) {
			return '';
		}
		$link = esc_url( $settings['spot_link'] ?? '' );
		$img  = '<img src="' . $image . '" alt="" />';
		$inner = $link !== '' ? '<a href="' . $link . '">' . $img . '</a>' : $img;
		return '<div class="bmf-home-rail-graphic">' . $inner . '</div>';
	}

	private static function link_list( array $items, bool $with_dividers ): string {
		$current = (int) get_queried_object_id();
		$html    = '<nav><ul>';
		$last    = '';
		foreach ( $items as $item ) {
			if ( empty( $item['enabled'] ) ) {
				continue;
			}
			$group = (string) ( $item['group'] ?? '' );
			if ( $with_dividers && $group !== '' && $group !== $last && $last !== '' ) {
				$html .= '<li class="bmf-home-rail-rule" role="presentation"><span>' . esc_html( $group ) . '</span></li>';
			}
			if ( $group !== '' ) {
				$last = $group;
			}
			$url    = self::result_url( $item );
			$active = $url !== '' && (int) $item['page_id'] === $current;
			$html  .= '<li' . ( $active ? ' class="is-active"' : '' ) . '>';
			$icon   = self::icon( (string) $item['key'] );
			if ( $url === '' ) {
				$html .= '<span class="is-unset">' . $icon . '<span>' . esc_html( $item['label'] ) . '</span></span>';
			} else {
				$html .= '<a href="' . esc_url( $url ) . '">' . $icon . '<span>' . esc_html( $item['label'] ) . '</span></a>';
			}
			$html .= '</li>';
		}
		return $html . '</ul></nav>';
	}

	private static function result_url( array $item ): string {
		$url = BMF_Member_Home_Settings::url_for( $item );
		if ( $url !== '' ) {
			return $url;
		}
		$settings = BMF_Member_Home_Settings::get();
		foreach ( $settings['cards'] as $card ) {
			if ( ( $card['key'] ?? '' ) === ( $item['key'] ?? '' ) ) {
				return BMF_Member_Home_Settings::url_for( $card, 'open_page_id', 'open_anchor' );
			}
		}
		return '';
	}

	private static function today_markup(): string {
		$settings = BMF_Member_Home_Settings::get();
		$user     = wp_get_current_user();
		$name     = $user->first_name !== '' ? $user->first_name : $user->display_name;
		$html     = '<header class="bmf-home-top">';
		$html    .= '<div><p class="bmf-home-kicker">Today</p>';
		$html    .= '<h2 class="bmf-home-hello" data-bmf-greet="' . esc_attr( $name ) . '">Hello, <span>' . esc_html( $name ) . '</span></h2></div>';
		$html    .= '<div class="bmf-home-top-tools">';
		$html    .= '<span class="bmf-home-chip"' . self::help_attrs( 'membership' ) . '>' . esc_html( self::membership_label() ) . '</span>';
		$html    .= self::theme_toggle();
		$html .= '</div></header>';
		$html .= self::notice_markup( $settings );
		$html .= self::timeline_markup( $settings, $user->user_email );
		$html .= self::cards_markup( $settings, $user->user_email );
		$html .= '<div class="bmf-home-grid">';
		$html .= '<section class="bmf-home-card"' . self::help_attrs( 'agenda' ) . '><h3>Next 30 days</h3>';
		$html .= self::embed( '[bmf_my_appointments]', 'Appointments shortcode is not active.' );
		$html .= '</section>';
		$html .= '<section class="bmf-home-card"' . self::help_attrs( 'reminders' ) . '><h3>Reminders</h3>';
		$html .= self::embed( '[uls_member_appointments future="1"]', 'Reminders shortcode is not active.' );
		$html .= '<h3 class="bmf-home-subhead">Notes</h3>';
		$html .= self::embed( '[uls_member_notes max="2" format="html" show_dates="0"]', 'Notes shortcode is not active.' );
		$html .= '</section></div>';
		$html .= self::strip_markup( $settings );
		return $html;
	}


	public static function assessment_status( $atts = array() ): string {
		self::enqueue();
		$atts = shortcode_atts( array( 'bare' => '0' ), $atts, 'bmf_assessment_status' );
		$settings = BMF_Member_Home_Settings::get();
		$user = wp_get_current_user();
		$html = self::cards_markup( $settings, $user->user_email );
		if ( $atts['bare'] === '1' || $atts['bare'] === 'true' ) {
			// Strip the accordion chrome so the brief can embed just the grouped cards.
			if ( preg_match( '/<div class="bmf-home-due-body"[^>]*>(.*)<\/div><\/section>/s', $html, $m ) ) {
				return $m[1];
			}
		}
		return $html;
	}

	public static function cards_markup( array $settings, string $email ): string {
		$user = $email !== '' ? get_user_by( 'email', $email ) : false;
		$uid  = $user ? (int) $user->ID : 0;
		$cards = array();
		foreach ( $settings['cards'] as $card ) {
			if ( empty( $card['enabled'] ) ) {
				continue;
			}
			$due = BMF_Member_Home_Due::for_card( $card, $email );
			$take_ok = BMF_Member_Home_Due::tags_allow(
				(string) ( $card['take_tags_required'] ?? '' ),
				(string) ( $card['take_tags_missing'] ?? '' ),
				$uid
			);
			$results_ok = BMF_Member_Home_Due::tags_allow(
				(string) ( $card['results_tags_required'] ?? '' ),
				(string) ( $card['results_tags_missing'] ?? '' ),
				$uid
			);
			// Date says due/missing, but Take tags do not allow → treat as Current so badge and button stay quiet.
			if ( in_array( $due['status'], array( 'due', 'missing' ), true ) && ! $take_ok ) {
				$due['status'] = 'ok';
				$due['label']  = 'Current';
			}
			$card['_due']        = $due;
			$card['_take_ok']    = $take_ok;
			$card['_results_ok'] = $results_ok;
			$cards[] = $card;
		}

		// Group by the same tiers as the wellbeing file.
		$preferred = array( 'Complementary', 'Art of Wellness', '360 Health Intelligence' );
		$grouped   = array();
		foreach ( $cards as $card ) {
			$g = trim( (string) ( $card['group'] ?? 'Other' ) );
			if ( $g === '' ) {
				$g = 'Other';
			}
			$grouped[ $g ][] = $card;
		}
		$ordered = array();
		foreach ( $preferred as $name ) {
			if ( isset( $grouped[ $name ] ) ) {
				$ordered[ $name ] = $grouped[ $name ];
				unset( $grouped[ $name ] );
			}
		}
		foreach ( $grouped as $name => $list ) {
			$ordered[ $name ] = $list;
		}

		$html  = '<section class="bmf-home-due bmf-home-assess-panel" data-bmf-assess-panel' . self::help_attrs( 'assessments' ) . '>';
		$html .= '<div class="bmf-home-due-head">';
		$html .= '<button type="button" class="bmf-home-assess-toggle" aria-expanded="false" aria-controls="bmf-assess-body">';
		$html .= '<span class="bmf-home-assess-title">Assessment Status</span>';
		$html .= '<svg class="bmf-home-assess-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';
		$html .= '</button>';
		$html .= '<button type="button" class="bmf-home-assess-pin" title="Pin Assessment Status open" aria-label="Pin Assessment Status open" aria-pressed="false">';
		$html .= '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a1 1 0 00-1 1v1.382l-1.447.724A2 2 0 006 7.236V9H4a1 1 0 000 2h1v5a1 1 0 102 0v-5h6v5a1 1 0 102 0v-5h1a1 1 0 100-2h-2V7.236a2 2 0 00-1.553-1.93L11 4.382V3a1 1 0 00-1-1z"/></svg>';
		$html .= '</button>';
		$html .= '</div>';
		$html .= '<div class="bmf-home-due-body" id="bmf-assess-body" hidden>';

		if ( ! $cards ) {
			$html .= '<p class="bmf-home-empty">No assessment cards enabled.</p>';
		} else {
			$html .= '<div class="bmf-home-assess-tiers">';
			foreach ( $ordered as $group_name => $group_cards ) {
				$html .= '<div class="bmf-home-assess-tier">';
				$html .= '<div class="bmf-home-assess-tier-label">' . esc_html( $group_name ) . '</div>';
				$html .= '<div class="bmf-home-due-grid">';
				foreach ( $group_cards as $card ) {
					$due  = $card['_due'];
					$take = BMF_Member_Home_Settings::url_for( $card );
					$open = BMF_Member_Home_Settings::url_for( $card, 'open_page_id', 'open_anchor' );
					$html .= '<article class="bmf-home-assess is-' . esc_attr( $due['status'] ) . '">';
					$html .= '<div class="bmf-home-assess-top">' . self::icon( (string) $card['key'] );
					$html .= '<strong>' . esc_html( $card['label'] ) . '</strong>';
					$html .= '<span class="bmf-home-badge">' . esc_html( $due['label'] ) . '</span></div>';
					if ( $due['date'] !== '' ) {
						$utc = (string) ( $due['utc'] ?? $due['date'] );
						$html .= '<p class="bmf-home-meta">Last <span data-bmf-local-date="' . esc_attr( $utc ) . '">' . esc_html( date_i18n( 'M j', strtotime( $due['date'] ) ) ) . '</span>';
						if ( $due['age'] !== null ) {
							$html .= ' · <span data-bmf-local-age="' . esc_attr( $utc ) . '">' . esc_html( (string) $due['age'] ) . 'd</span>';
						}
						$html .= '</p>';
					}
					$html .= '<div class="bmf-home-actions">';
					if ( $take !== '' ) {
						$primary = ( $due['status'] !== 'ok' && $card['_take_ok'] ) ? ' is-primary' : '';
						$html .= '<a class="bmf-home-btn' . $primary . '" href="' . esc_url( $take ) . '">Take assessment</a>';
					}
					if ( $open !== '' ) {
						$html .= '<a class="bmf-home-btn" href="' . esc_url( $open ) . '">Open results</a>';
					}
					if ( $take === '' && $open === '' ) {
						$html .= '<span class="bmf-home-btn is-disabled">Set pages in settings</span>';
					}
					$html .= '</div></article>';
				}
				$html .= '</div></div>';
			}
			$html .= '</div>';
		}

		$html .= '<p class="bmf-home-lag">Statuses may take up to 1 hour for processing your results.</p>';
		$html .= '</div></section>';
		return $html;
	}

	/**
	 * Gantt-style assessment timeline panel.
	 * Lanes only for entitled assessments that already have at least one result.
	 * Origin for each lane is the first result date.
	 */
	public static function assessment_timeline( $atts = array() ): string {
		self::enqueue();
		$settings = BMF_Member_Home_Settings::get();
		$user     = wp_get_current_user();
		return self::timeline_markup( $settings, $user->user_email );
	}

	private static function timeline_markup( array $settings, string $email ): string {
		$user = $email !== '' ? get_user_by( 'email', $email ) : false;
		$uid  = $user ? (int) $user->ID : 0;
		// Cumulative membership: Complementary < Art of Wellness < 360 Health Intelligence.
		$has = static function ( $tag ) use ( $uid ) {
			if ( function_exists( 'wpf_has_tag' ) && $uid > 0 ) {
				return (bool) wpf_has_tag( $tag, $uid );
			}
			return false;
		};
		$is_s360 = $has( 'S360' );
		$is_art  = $is_s360 || $has( 'ART' );
		// Complementary is the base; everyone entitled to a higher tier also sees it.
		$allowed_groups = array( 'Complementary' );
		if ( $is_art ) {
			$allowed_groups[] = 'Art of Wellness';
		}
		if ( $is_s360 ) {
			$allowed_groups[] = '360 Health Intelligence';
		}
		$lanes = array();
		foreach ( $settings['cards'] as $card ) {
			if ( empty( $card['enabled'] ) ) {
				continue;
			}
			$group = trim( (string) ( $card['group'] ?? 'Other' ) );
			if ( $group === '' ) {
				$group = 'Other';
			}
			if ( ! in_array( $group, $allowed_groups, true ) ) {
				continue;
			}
			$source = (string) ( $card['source'] ?? '' );
			// v1: assessments only. BioVoice and wearables are a later slice.
			if ( ! in_array( $source, array( 'rsi', 'pillars_rapid', 'keys', 'pillars_full', 'bsi' ), true ) ) {
				continue;
			}
			$dates = BMF_Member_Home_Due::all_dates( $source, $email );
			$lanes[] = array(
				'key'     => (string) $card['key'],
				'label'   => (string) $card['label'],
				'group'   => $group,
				'cadence' => max( 1, (int) ( $card['cadence'] ?? 90 ) ),
				'first'   => $dates ? $dates[0] : '',
				'last'    => $dates ? $dates[ count( $dates ) - 1 ] : '',
				'actuals' => $dates,
				'url'     => BMF_Member_Home_Settings::url_for( $card, 'open_page_id', 'open_anchor' ),
			);
		}
		if ( ! $lanes ) {
			return '';
		}

		$preferred = array( 'Complementary', 'Art of Wellness', '360 Health Intelligence' );
		$grouped   = array();
		foreach ( $lanes as $lane ) {
			$g = trim( $lane['group'] ) !== '' ? $lane['group'] : 'Other';
			$grouped[ $g ][] = $lane;
		}
		$ordered = array();
		foreach ( $preferred as $name ) {
			if ( isset( $grouped[ $name ] ) ) {
				$ordered[ $name ] = $grouped[ $name ];
				unset( $grouped[ $name ] );
			}
		}
		foreach ( $grouped as $name => $list ) {
			$ordered[ $name ] = $list;
		}

		$payload = array(
			'groups' => $ordered,
		);
		$json = wp_json_encode( $payload );
		if ( ! $json ) {
			return '';
		}

		$html  = '<section class="bmf-home-due bmf-home-timeline" data-bmf-timeline' . self::help_attrs( 'timeline' ) . '>';
		$html .= '<div class="bmf-home-due-head">';
		$html .= '<button type="button" class="bmf-home-assess-toggle" aria-expanded="false" aria-controls="bmf-timeline-body">';
		$html .= '<span class="bmf-home-assess-title">Wellness Timeline</span>';
		$html .= '<svg class="bmf-home-assess-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';
		$html .= '</button>';
		$html .= '<button type="button" class="bmf-home-assess-pin" title="Pin Wellness Timeline open" aria-label="Pin Wellness Timeline open" aria-pressed="false">';
		$html .= '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a1 1 0 00-1 1v1.382l-1.447.724A2 2 0 006 7.236V9H4a1 1 0 000 2h1v5a1 1 0 102 0v-5h6v5a1 1 0 102 0v-5h1a1 1 0 100-2h-2V7.236a2 2 0 00-1.553-1.93L11 4.382V3a1 1 0 00-1-1z"/></svg>';
		$html .= '</button>';
		$html .= '</div>';
		$html .= '<div class="bmf-home-due-body" id="bmf-timeline-body" hidden>';
		$html .= '<div class="bmf-tl-toolbar">';
		$html .= '<div class="bmf-tl-ranges" role="group" aria-label="Date range">';
		$html .= '<button type="button" data-months="1">1 mo</button>';
		$html .= '<button type="button" data-months="3">3 mo</button>';
		$html .= '<button type="button" data-months="6" class="is-active">6 mo</button>';
		$html .= '<button type="button" data-months="9">9 mo</button>';
		$html .= '<button type="button" data-months="12">1 yr</button>';
		$html .= '</div>';
		$html .= '<p class="bmf-tl-legend"><span class="bmf-tl-key bmf-tl-key-actual"></span> Completed <span class="bmf-tl-key bmf-tl-key-next"></span> Next available <span class="bmf-tl-key bmf-tl-key-adj"></span> Scheduled</p>';
		$html .= '</div>';
		$html .= '<div class="bmf-tl-scroll"><div class="bmf-tl-canvas" data-timeline="' . esc_attr( $json ) . '"></div></div>';
		$html .= '<p class="bmf-home-lag">Completed results in green. The solid marker shows when the assessment is next expected to be available, based on the last result and the normal interval.</p>';
		$html .= '</div></section>';
		return $html;
	}

	private static function notice_markup( array $settings ): string {
		// Prefer guided-path next step when available.
		$guided = self::guided_path_markup();
		if ( $guided !== '' ) {
			$spot = self::spot_markup( $settings );
			if ( $spot === '' ) {
				return $guided;
			}
			return '<div class="bmf-home-info-row">' . $guided . $spot . '</div>';
		}

		$notice = self::matching_notice( $settings );
		$custom = trim( (string) get_user_meta( get_current_user_id(), 'bmf_home_notice', true ) );
		if ( $custom === '' && ! $notice ) {
			$spot = self::spot_markup( $settings );
			return $spot === '' ? '' : '<div class="bmf-home-info-row">' . $spot . '</div>';
		}
		$html = '<section class="bmf-home-notice"' . self::help_attrs( 'info' ) . '>';
		if ( $custom !== '' ) {
			$html .= '<div><p>' . nl2br( esc_html( $custom ) ) . '</p></div>';
		} else {
			$url = BMF_Member_Home_Settings::url_for( $notice );
			$html .= '<div><h3>' . esc_html( $notice['title'] ) . '</h3>';
			if ( $notice['body'] !== '' ) {
				$html .= '<p>' . esc_html( $notice['body'] ) . '</p>';
			}
			if ( $url !== '' ) {
				$html .= '<a class="bmf-home-btn is-primary" href="' . esc_url( $url ) . '">' . esc_html( $notice['link_label'] !== '' ? $notice['link_label'] : 'Continue' ) . '</a>';
			}
			$html .= '</div>';
		}
		$html .= '</section>';
		$spot = self::spot_markup( $settings );
		if ( $spot === '' ) {
			return $html;
		}
		return '<div class="bmf-home-info-row">' . $html . $spot . '</div>';
	}

	/**
	 * Center panel driven by bmf-guided-path when the plugin is active.
	 */
	private static function guided_path_markup(): string {
		if ( ! function_exists( 'bmf_guided_path_get_current_step' ) ) {
			return '';
		}
		$step = bmf_guided_path_get_current_step();
		if ( ! is_array( $step ) || empty( $step['title'] ) ) {
			return '';
		}
		$html  = '<section class="bmf-home-notice bmf-home-guided" data-step-id="' . esc_attr( (string) $step['id'] ) . '"' . self::help_attrs( 'info' ) . '>';
		$html .= '<div>';
		$html .= '<h3 class="bmf-home-guided-title">' . esc_html( $step['title'] ) . '</h3>';
		if ( ! empty( $step['description'] ) ) {
			$desc = wp_kses_post( $step['description'] );
			$desc = wpautop( $desc );
			$html .= '<div class="bmf-home-guided-desc">' . $desc . '</div>';
		}
		if ( ! empty( $step['url'] ) ) {
			$label = ! empty( $step['button_label'] ) ? $step['button_label'] : 'Continue';
			$html .= '<a class="bmf-home-btn is-primary" href="' . esc_url( $step['url'] ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</div>';
		$html .= '</section>';
		return $html;
	}

	private static function spot_markup( array $settings ): string {
		$image = esc_url( $settings['spot_image'] ?? '' );
		$code  = trim( (string) ( $settings['spot_shortcode'] ?? '' ) );
		$link  = esc_url( $settings['spot_link'] ?? '' );
		if ( $image === '' && $code === '' ) {
			return '';
		}
		$inner = '';
		if ( $code !== '' ) {
			$inner = do_shortcode( $code );
		} elseif ( $image !== '' ) {
			$img = '<img src="' . $image . '" alt="" />';
			$inner = $link !== '' ? '<a href="' . $link . '">' . $img . '</a>' : $img;
		}
		return '<aside class="bmf-home-spot"' . self::help_attrs( 'wellness' ) . '>' . $inner . '</aside>';
	}

	private static function matching_notice( array $settings ): ?array {
		if ( empty( $settings['notices'] ) || ! is_array( $settings['notices'] ) ) {
			return null;
		}
		$uid = get_current_user_id();
		$user = get_userdata( $uid );
		$days = 9999;
		if ( $user && ! empty( $user->user_registered ) ) {
			$days = (int) floor( ( time() - strtotime( $user->user_registered ) ) / DAY_IN_SECONDS );
		}
		foreach ( $settings['notices'] as $notice ) {
			if ( empty( $notice['enabled'] ) || ( $notice['title'] === '' && $notice['body'] === '' ) ) {
				continue;
			}
			$tag = trim( (string) $notice['tag'] );
			if ( $tag !== '' && function_exists( 'wpf_has_tag' ) && ! wpf_has_tag( $tag, $uid ) ) {
				continue;
			}
			if ( $tag !== '' && ! function_exists( 'wpf_has_tag' ) ) {
				continue;
			}
			$max = (int) $notice['max_days'];
			if ( $max > 0 && $days > $max ) {
				continue;
			}
			return $notice;
		}
		return null;
	}

	private static function strip_markup( array $settings ): string {
		$html = '<footer class="bmf-home-strip">';
		$any  = false;
		foreach ( $settings['strip'] as $item ) {
			$url = BMF_Member_Home_Settings::url_for( $item );
			if ( $url === '' ) {
				continue;
			}
			$any   = true;
			$html .= '<a href="' . esc_url( $url ) . '">' . esc_html( $item['label'] ) . '</a>';
		}
		if ( ! $any ) {
			$html .= '<span>Set status-strip pages under Settings → Member Home.</span>';
		}
		$html .= '</footer>';
		return $html;
	}

	private static function embed( string $shortcode, string $missing ): string {
		ob_start();
		$html = do_shortcode( $shortcode );
		$echoed = ob_get_clean();
		$html   = trim( (string) $html . (string) $echoed );
		if ( $html === '' || $html === $shortcode ) {
			return '<p class="bmf-home-empty">' . esc_html( $missing ) . '</p>';
		}
		return '<div class="bmf-home-embed">' . $html . '</div>';
	}

	private static function help_attrs( string $key ): string {
		$settings = BMF_Member_Home_Settings::get();
		if ( empty( $settings['tours'] ) || ! is_array( $settings['tours'] ) ) {
			return '';
		}
		foreach ( $settings['tours'] as $tour ) {
			if ( ( $tour['key'] ?? '' ) !== $key || empty( $tour['enabled'] ) || ( $tour['text'] ?? '' ) === '' ) {
				continue;
			}
			return ' bmf-help-step="' . esc_attr( (string) $tour['step'] ) . '"'
				. ' bmf-help-title="' . esc_attr( $tour['title'] ) . '"'
				. ' bmf-help-text="' . esc_attr( $tour['text'] ) . '"'
				. ' bmf-help-importance="info"';
		}
		return '';
	}

	private static function membership_label(): string {
		$uid = get_current_user_id();
		$has = static function ( $tag ) use ( $uid ) {
			if ( function_exists( 'wpf_has_tag' ) ) {
				return (bool) wpf_has_tag( $tag, $uid );
			}
			return false;
		};
		if ( $has( 'S360' ) ) {
			return '360° Health Intelligence';
		}
		if ( $has( 'ART' ) ) {
			return 'Art of Wellness';
		}
		return 'Complementary';
	}

	private static function theme_toggle(): string {
		$theme = self::theme();
		$sun   = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
		$moon  = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M21 14.5A8.5 8.5 0 0 1 9.5 3 7 7 0 1 0 21 14.5z"/></svg>';
		$html  = '<div class="bmf-home-theme" role="group" aria-label="Theme"' . self::help_attrs( 'theme' ) . '>';
		$html .= '<button type="button" data-bmf-theme-set="light" aria-pressed="' . ( $theme === 'light' ? 'true' : 'false' ) . '" title="Light" aria-label="Light">' . $sun . '</button>';
		$html .= '<button type="button" data-bmf-theme-set="dark" aria-pressed="' . ( $theme === 'dark' ? 'true' : 'false' ) . '" title="Dark" aria-label="Dark">' . $moon . '</button>';
		$html .= '</div>';
		return $html;
	}

	private static function icon( string $key ): string {
		$paths = array(
			'home'         => '<path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5z"/>',
			'results'      => '<path d="M4 19V5M4 19h16"/><path d="m7 14 3-4 3 3 4-6"/>',
			'assessments'  => '<rect x="6" y="3" width="12" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
			'appointments' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
			'profile'      => '<circle cx="12" cy="8" r="3"/><path d="M5 19c1.5-3 3.8-4.5 7-4.5S17.5 16 19 19"/>',
			'labs'         => '<path d="M9 3h6M10 3v6L5 19a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-10V3"/>',
			'settings'     => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4"/>',
			'education'    => '<path d="M4 6.5 12 3l8 3.5L12 10 4 6.5z"/><path d="M8 8.5v5c0 1.4 1.8 3 4 3s4-1.6 4-3v-5"/>',
			'records'      => '<path d="M6 4h8l4 4v12H6z"/><path d="M14 4v4h4M8 13h8M8 17h6"/>',
			'wearables'    => '<rect x="7" y="6" width="10" height="12" rx="3"/><path d="M9 6V4h6v2M9 18v2h6v-2"/>',
			'biovoice'     => '<path d="M4 12h2M8 8v8M12 5v14M16 8v8M20 12h0"/>',
			'fitbit'       => '<rect x="7" y="6" width="10" height="12" rx="3"/><path d="M9 6V4h6v2M9 18v2h6v-2"/>',
			'rsi'          => '<path d="M3 12h3l2-5 4 10 2-5h7"/>',
			'rapid'        => '<path d="M13 3 5 14h7l-1 7 8-11h-7l1-7z"/>',
			'keys'         => '<circle cx="8" cy="8" r="3"/><path d="M10.5 10.5 20 20M16 16l2 2M14 18l2 2"/>',
			'pillars'      => '<path d="M5 20V9M10 20V4M15 20v-7M20 20V7"/>',
			'bsi'          => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
		);
		$path = $paths[ $key ] ?? $paths['assessments'];
		return '<svg class="bmf-home-ico" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
	}
}