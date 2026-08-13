<?php
/**
 * BMF / ULS Customs – WP Fusion Page Access Rules Shortcode
 *
 * Drop this file into your uls-customs plugin (or require it from the main plugin file).
 *
 * Shortcode: [bmf_wpf_access_rules]
 * Optional attributes:
 *   id="123"              – Force a specific post/page ID (default = current queried object)
 *   show_can_access="1"   – Show whether the current user can access the page (default 1)
 *   class="my-class"      – Extra CSS class on the wrapper
 *
 * Recommended usage:
 *   Place the shortcode in an Elementor container in the Footer (Theme Builder).
 *   Set that container’s WP Fusion visibility to require your ADMIN tag.
 *   Only you will ever see the panel.
 *
 * Version: 1.0.0
 * Author: Jeff Procasky / Breathermae
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'bmf_wpf_access_rules', 'bmf_wpf_access_rules_shortcode' );

/**
 * Render the access-rules diagnostic panel.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function bmf_wpf_access_rules_shortcode( $atts ) {
	$atts = shortcode_atts(
		[
			'id'              => 0,
			'show_can_access' => '1',
			'class'           => '',
		],
		$atts,
		'bmf_wpf_access_rules'
	);

	$post_id = absint( $atts['id'] );
	if ( ! $post_id ) {
		$post_id = get_queried_object_id();
	}
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}

	if ( ! $post_id ) {
		return '<!-- [bmf_wpf_access_rules] no post context -->';
	}

	// ---- Gather settings -------------------------------------------------
	$settings = [];

	if ( function_exists( 'wp_fusion' ) && is_object( wp_fusion() ) && isset( wp_fusion()->access ) && method_exists( wp_fusion()->access, 'get_post_access_meta' ) ) {
		$settings = wp_fusion()->access->get_post_access_meta( $post_id );
	} else {
		$raw = get_post_meta( $post_id, 'wpf-settings', true );
		$settings = is_array( $raw ) ? $raw : [];
	}

	$lock_content = ! empty( $settings['lock_content'] );
	$any_tags     = isset( $settings['allow_tags'] ) && is_array( $settings['allow_tags'] ) ? array_filter( $settings['allow_tags'] ) : [];
	$all_tags     = isset( $settings['allow_tags_all'] ) && is_array( $settings['allow_tags_all'] ) ? array_filter( $settings['allow_tags_all'] ) : [];
	$not_tags     = isset( $settings['allow_tags_not'] ) && is_array( $settings['allow_tags_not'] ) ? array_filter( $settings['allow_tags_not'] ) : [];

	$redirect_id  = ! empty( $settings['redirect'] ) ? (int) $settings['redirect'] : 0;
	$redirect_url = ! empty( $settings['redirect_url'] ) ? (string) $settings['redirect_url'] : '';

	$has_rules = $lock_content || ! empty( $any_tags ) || ! empty( $all_tags ) || ! empty( $not_tags );

	// ---- Tag label helper ------------------------------------------------
	$get_label = function ( $tag ) {
		if ( function_exists( 'wpf_get_tag_label' ) ) {
			$label = wpf_get_tag_label( $tag );
			if ( $label ) {
				return $label;
			}
		}
		if ( function_exists( 'wp_fusion' ) && is_object( wp_fusion() ) && isset( wp_fusion()->user ) && method_exists( wp_fusion()->user, 'get_tag_label' ) ) {
			$label = wp_fusion()->user->get_tag_label( $tag );
			if ( $label ) {
				return $label;
			}
		}
		return (string) $tag;
	};

	$format_tags = function ( array $tags ) use ( $get_label ) {
		if ( empty( $tags ) ) {
			return '<span class="bmf-wpf-none">— none —</span>';
		}
		$out = [];
		foreach ( $tags as $tag ) {
			$out[] = '<span class="bmf-wpf-tag">' . esc_html( $get_label( $tag ) ) . '</span>';
		}
		return implode( ' ', $out );
	};

	// ---- Can current user access? ----------------------------------------
	$can_access_html = '';
	if ( $atts['show_can_access'] === '1' || $atts['show_can_access'] === 'true' ) {
		$can = null;
		if ( function_exists( 'wpf_user_can_access' ) ) {
			$can = wpf_user_can_access( $post_id );
		} elseif ( function_exists( 'wp_fusion' ) && is_object( wp_fusion() ) && isset( wp_fusion()->access ) && method_exists( wp_fusion()->access, 'user_can_access' ) ) {
			$can = wp_fusion()->access->user_can_access( $post_id );
		}

		if ( $can === true ) {
			$can_access_html = '<div class="bmf-wpf-row bmf-wpf-can"><span class="bmf-wpf-label">You can access</span><span class="bmf-wpf-value bmf-wpf-yes">Yes</span></div>';
		} elseif ( $can === false ) {
			$can_access_html = '<div class="bmf-wpf-row bmf-wpf-can"><span class="bmf-wpf-label">You can access</span><span class="bmf-wpf-value bmf-wpf-no">No</span></div>';
		}
	}

	// ---- Redirect display ------------------------------------------------
	$redirect_html = '';
	if ( $redirect_url ) {
		$redirect_html = '<div class="bmf-wpf-row"><span class="bmf-wpf-label">Redirect URL</span><span class="bmf-wpf-value">' . esc_html( $redirect_url ) . '</span></div>';
	} elseif ( $redirect_id ) {
		$title = get_the_title( $redirect_id );
		$redirect_html = '<div class="bmf-wpf-row"><span class="bmf-wpf-label">Redirect to</span><span class="bmf-wpf-value">' . esc_html( $title ? $title : '#' . $redirect_id ) . ' <small>(ID ' . (int) $redirect_id . ')</small></span></div>';
	}

	// ---- Page context ----------------------------------------------------
	$page_title = get_the_title( $post_id );
	$page_label = $page_title ? $page_title : '(no title)';
	$page_label .= ' <small style="opacity:.7">#' . (int) $post_id . '</small>';

	// ---- Build output ----------------------------------------------------
	$extra_class = $atts['class'] ? ' ' . sanitize_html_class( $atts['class'] ) : '';

	ob_start();
	?>
	<div class="bmf-wpf-access-rules<?php echo esc_attr( $extra_class ); ?>" data-post-id="<?php echo (int) $post_id; ?>">
		<style>
			.bmf-wpf-access-rules {
				font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
				font-size: 13px;
				line-height: 1.45;
				background: #0f172a;
				color: #e2e8f0;
				border: 1px solid #334155;
				border-radius: 10px;
				padding: 0.85rem 1.1rem;
				margin: 0.75rem 0;
				max-width: 520px;
				box-shadow: 0 4px 12px rgba(0,0,0,.25);
			}
			.bmf-wpf-access-rules .bmf-wpf-header {
				display: flex;
				align-items: baseline;
				justify-content: space-between;
				gap: 0.75rem;
				margin: 0 0 0.65rem;
				padding-bottom: 0.5rem;
				border-bottom: 1px solid #334155;
			}
			.bmf-wpf-access-rules .bmf-wpf-title {
				font-weight: 600;
				font-size: 0.8rem;
				text-transform: uppercase;
				letter-spacing: 0.04em;
				color: #94a3b8;
				margin: 0;
			}
			.bmf-wpf-access-rules .bmf-wpf-page {
				font-size: 0.8rem;
				color: #cbd5e1;
				text-align: right;
			}
			.bmf-wpf-access-rules .bmf-wpf-row {
				display: flex;
				gap: 0.75rem;
				margin: 0.35rem 0;
				align-items: flex-start;
			}
			.bmf-wpf-access-rules .bmf-wpf-label {
				flex: 0 0 140px;
				color: #94a3b8;
				font-size: 0.78rem;
				padding-top: 1px;
			}
			.bmf-wpf-access-rules .bmf-wpf-value {
				flex: 1;
				color: #f1f5f9;
			}
			.bmf-wpf-access-rules .bmf-wpf-tag {
				display: inline-block;
				background: #1e293b;
				border: 1px solid #475569;
				color: #e2e8f0;
				padding: 1px 7px;
				border-radius: 4px;
				font-size: 0.75rem;
				margin: 0 3px 3px 0;
				white-space: nowrap;
			}
			.bmf-wpf-access-rules .bmf-wpf-none {
				color: #64748b;
				font-style: italic;
			}
			.bmf-wpf-access-rules .bmf-wpf-yes {
				color: #4ade80;
				font-weight: 600;
			}
			.bmf-wpf-access-rules .bmf-wpf-no {
				color: #f87171;
				font-weight: 600;
			}
			.bmf-wpf-access-rules .bmf-wpf-empty {
				color: #94a3b8;
				font-size: 0.85rem;
				margin: 0.4rem 0 0.15rem;
			}
			.bmf-wpf-access-rules .bmf-wpf-can {
				margin-top: 0.55rem;
				padding-top: 0.45rem;
				border-top: 1px solid #334155;
			}
			.bmf-wpf-access-rules .bmf-wpf-note {
				margin-top: 0.5rem;
				font-size: 0.72rem;
				color: #64748b;
			}
		</style>

		<div class="bmf-wpf-header">
			<p class="bmf-wpf-title">WP Fusion Access Rules</p>
			<div class="bmf-wpf-page"><?php echo $page_label; // already escaped pieces ?></div>
		</div>

		<?php if ( ! $has_rules ) : ?>
			<p class="bmf-wpf-empty">No WP Fusion access rules on this page.<br>
			<span style="opacity:.8">Public (or login-only if global “Restrict Content” + other settings apply).</span></p>
		<?php else : ?>

			<div class="bmf-wpf-row">
				<span class="bmf-wpf-label">Must be logged in</span>
				<span class="bmf-wpf-value"><?php echo $lock_content ? '<span class="bmf-wpf-yes">Yes</span>' : '<span class="bmf-wpf-none">No</span>'; ?></span>
			</div>

			<div class="bmf-wpf-row">
				<span class="bmf-wpf-label">Required (any)</span>
				<span class="bmf-wpf-value"><?php echo $format_tags( $any_tags ); ?></span>
			</div>

			<div class="bmf-wpf-row">
				<span class="bmf-wpf-label">Required (all)</span>
				<span class="bmf-wpf-value"><?php echo $format_tags( $all_tags ); ?></span>
			</div>

			<div class="bmf-wpf-row">
				<span class="bmf-wpf-label">Required (not)</span>
				<span class="bmf-wpf-value"><?php echo $format_tags( $not_tags ); ?></span>
			</div>

			<?php echo $redirect_html; ?>

		<?php endif; ?>

		<?php echo $can_access_html; ?>

		<div class="bmf-wpf-note">Visible only because this container is gated by your ADMIN tag.</div>
	</div>
	<?php
	return ob_get_clean();
}
