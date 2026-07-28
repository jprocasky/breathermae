<?php
/**
 * Plugin Name:       Breathermae Active Coupons
 * Plugin URI:        https://breathermae.com
 * Description:       Frontend shortcode [bmf_active_coupons] that lists non-expired WooCommerce coupons in a clean, sortable & filterable table for internal team use. Protect the page with WP Fusion.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Breathermae
 * Text Domain:       bmf-active-coupons
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMF_ACTIVE_COUPONS_VERSION', '1.0.1' );
define( 'BMF_ACTIVE_COUPONS_FILE', __FILE__ );
define( 'BMF_ACTIVE_COUPONS_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMF_ACTIVE_COUPONS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main shortcode + assets.
 */
final class BMF_Active_Coupons {

	public static function init() {
		add_shortcode( 'bmf_active_coupons', array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Shortcode: [bmf_active_coupons]
	 */
	public static function render_shortcode( $atts = array() ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '<p class="bmf-ac-notice">WooCommerce is required for this shortcode.</p>';
		}

		// Enqueue here so it works reliably even when the shortcode lives
		// inside an Elementor widget (post_content may not contain the tag).
		self::enqueue_assets();

		$coupons = self::get_active_coupons();

		if ( empty( $coupons ) ) {
			return '<div class="bmf-ac-wrap"><p class="bmf-ac-empty">No active (non-expired) coupons found.</p></div>';
		}

		ob_start();
		?>
		<div class="bmf-ac-wrap">
			<div class="bmf-ac-header">
				<h3 class="bmf-ac-title">Active Coupons</h3>
				<span class="bmf-ac-count"><?php echo esc_html( count( $coupons ) ); ?> active</span>
			</div>
			<div class="bmf-ac-table-wrap">
				<table id="bmf-active-coupons-table" class="bmf-ac-table display" style="width:100%">
					<thead>
						<tr>
							<th>Code</th>
							<th>Discount</th>
							<th>Products</th>
							<th>Categories</th>
							<th>Expiry</th>
							<th>Usage</th>
							<th>Description</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $coupons as $row ) : ?>
							<tr>
								<td data-order="<?php echo esc_attr( $row['code'] ); ?>">
									<code
										class="bmf-ac-code"
										data-code="<?php echo esc_attr( $row['code'] ); ?>"
										title="Click to copy"
										role="button"
										tabindex="0"
										aria-label="Copy coupon code <?php echo esc_attr( $row['code'] ); ?>"
									><?php echo esc_html( $row['code'] ); ?></code>
								</td>
								<td data-order="<?php echo esc_attr( $row['discount_sort'] ); ?>">
									<?php echo esc_html( $row['discount'] ); ?>
								</td>
								<td><?php echo esc_html( $row['products'] ); ?></td>
								<td><?php echo esc_html( $row['categories'] ); ?></td>
								<td data-order="<?php echo esc_attr( $row['expiry_sort'] ); ?>">
									<?php echo esc_html( $row['expiry'] ); ?>
								</td>
								<td data-order="<?php echo esc_attr( $row['usage_sort'] ); ?>">
									<?php echo esc_html( $row['usage'] ); ?>
								</td>
								<td><?php echo esc_html( $row['description'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Fetch published, non-expired coupons and normalize rows.
	 *
	 * @return array[]
	 */
	private static function get_active_coupons() {
		$args = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$posts = get_posts( $args );
		$rows  = array();
		$now   = time();

		foreach ( $posts as $post ) {
			$coupon = new WC_Coupon( $post->ID );

			// Skip expired.
			$expires = $coupon->get_date_expires();
			if ( $expires && $expires->getTimestamp() < $now ) {
				continue;
			}

			$code = $coupon->get_code();
			if ( empty( $code ) ) {
				continue;
			}

			// Discount display + sort key.
			type   = $coupon->get_discount_type();
			$amount = $coupon->get_amount();
			$discount_label = self::format_discount( $type, $amount );
			$discount_sort  = ( 'percent' === $type ) ? (float) $amount : (float) $amount * 100; // rough numeric sort

			// Products.
			$product_ids = $coupon->get_product_ids();
			$products    = self::format_product_names( $product_ids );

			// Categories.
			$cat_ids     = $coupon->get_product_categories();
			$categories  = self::format_category_names( $cat_ids );

			// Expiry.
			if ( $expires ) {
				$expiry      = $expires->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
				$expiry_sort = $expires->getTimestamp();
			} else {
				$expiry      = 'Never';
				$expiry_sort = PHP_INT_MAX;
			}

			// Usage.
			$used  = (int) $coupon->get_usage_count();
			$limit = $coupon->get_usage_limit();
			if ( $limit > 0 ) {
				$usage      = $used . ' / ' . $limit;
				$usage_sort = $limit - $used; // remaining
			} else {
				$usage      = $used . ' / ∞';
				$usage_sort = 999999;
			}

			$desc = $coupon->get_description();
			if ( strlen( $desc ) > 120 ) {
				$desc = substr( $desc, 0, 117 ) . '…';
			}

			$rows[] = array(
				'code'          => $code,
				'discount'      => $discount_label,
				'discount_sort' => $discount_sort,
				'products'      => $products,
				'categories'    => $categories,
				'expiry'        => $expiry,
				'expiry_sort'   => $expiry_sort,
				'usage'         => $usage,
				'usage_sort'    => $usage_sort,
				'description'   => $desc ?: '—',
			);
		}

		return $rows;
	}

	/**
	 * Human-readable discount string (plain text, safe for esc_html).
	 */
	private static function format_discount( $type, $amount ) {
		$amount = wc_format_decimal( $amount, wc_get_price_decimals() );
		$symbol = get_woocommerce_currency_symbol();

		switch ( $type ) {
			case 'percent':
				return $amount . '% off';
			case 'fixed_cart':
				return $symbol . $amount . ' off cart';
			case 'fixed_product':
				return $symbol . $amount . ' off product';
			default:
				return $amount . ' (' . $type . ')';
		}
	}

	/**
	 * Resolve product IDs to names (or "Any product").
	 */
	private static function format_product_names( array $ids ) {
		if ( empty( $ids ) ) {
			return 'Any product';
		}

		$names = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$names[] = $product->get_name();
			} else {
				$names[] = '#' . $id;
			}
		}

		// Keep readable; truncate if very long.
		$text = implode( ', ', $names );
		if ( strlen( $text ) > 80 ) {
			$text = substr( $text, 0, 77 ) . '…';
		}
		return $text;
	}

	/**
	 * Resolve category IDs to names (or "Any category").
	 */
	private static function format_category_names( array $ids ) {
		if ( empty( $ids ) ) {
			return 'Any category';
		}

		$names = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			} else {
				$names[] = '#' . $id;
			}
		}

		$text = implode( ', ', $names );
		if ( strlen( $text ) > 80 ) {
			$text = substr( $text, 0, 77 ) . '…';
		}
		return $text;
	}

	/**
	 * Enqueue DataTables + our CSS/JS.
	 * Called from the shortcode so it works with Elementor widgets.
	 */
	private static function enqueue_assets() {
		// DataTables CSS + JS (CDN – lightweight & reliable).
		wp_enqueue_style(
			'datatables',
			'https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css',
			array(),
			'1.13.8'
		);
		wp_enqueue_script(
			'datatables',
			'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
			array( 'jquery' ),
			'1.13.8',
			true
		);

		wp_enqueue_style(
			'bmf-active-coupons',
			BMF_ACTIVE_COUPONS_URL . 'assets/bmf-active-coupons.css',
			array( 'datatables' ),
			BMF_ACTIVE_COUPONS_VERSION
		);

		wp_enqueue_script(
			'bmf-active-coupons',
			BMF_ACTIVE_COUPONS_URL . 'assets/bmf-active-coupons.js',
			array( 'jquery', 'datatables' ),
			BMF_ACTIVE_COUPONS_VERSION,
			true
		);
	}
}

BMF_Active_Coupons::init();
