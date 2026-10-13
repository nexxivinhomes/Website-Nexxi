<?php
/**
 * NEXXI product data model: global attributes, colour swatches, size chart,
 * variation payloads for the Stitch storefront and wishlist sync.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nexxi_Kids_Catalog {
	const SCHEMA = '2';

	/** Global attributes used by filters, product pages and variations. */
	public static function attributes() {
		return array(
			'size'      => array( 'name' => 'Size', 'orderby' => 'menu_order' ),
			'mau-sac'   => array( 'name' => 'Màu sắc', 'orderby' => 'menu_order' ),
			'chat-lieu' => array( 'name' => 'Chất liệu', 'orderby' => 'name' ),
			'do-tuoi'   => array( 'name' => 'Độ tuổi', 'orderby' => 'menu_order' ),
			'kieu-dang' => array( 'name' => 'Kiểu dáng', 'orderby' => 'name' ),
		);
	}

	/** Reference chart: size => [min kg, max kg, height cm]. */
	public static function size_chart() {
		return array(
			1 => array( 8, 10, 75 ), 2 => array( 10, 12, 87 ), 3 => array( 12, 14, 96 ), 4 => array( 14, 16, 104 ),
			5 => array( 16, 18, 110 ), 6 => array( 18, 20, 116 ), 7 => array( 20, 22, 122 ), 8 => array( 22, 24, 128 ),
			9 => array( 24, 27, 134 ), 10 => array( 27, 30, 140 ), 11 => array( 30, 33, 146 ), 12 => array( 33, 36, 152 ),
			13 => array( 36, 40, 156 ), 14 => array( 40, 45, 160 ), 15 => array( 45, 50, 165 ), 16 => array( 50, 55, 170 ),
		);
	}

	public function __construct() {
		add_action( 'init', array( $this, 'maybe_install' ), 20 );
		add_action( 'pa_mau-sac_add_form_fields', array( $this, 'color_add_field' ) );
		add_action( 'pa_mau-sac_edit_form_fields', array( $this, 'color_edit_field' ) );
		add_action( 'created_pa_mau-sac', array( $this, 'color_save' ) );
		add_action( 'edited_pa_mau-sac', array( $this, 'color_save' ) );
		add_action( 'rest_api_init', array( $this, 'rest_routes' ) );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'cart_fragments' ) );
	}

	/** Create the global attributes and default size terms once per schema version. */
	public function maybe_install() {
		if ( self::SCHEMA === get_option( 'nexxi_catalog_schema' ) || ! function_exists( 'wc_create_attribute' ) ) { return; }
		foreach ( self::attributes() as $slug => $attribute ) {
			if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
				$result = wc_create_attribute( array( 'name' => $attribute['name'], 'slug' => $slug, 'type' => 'select', 'order_by' => $attribute['orderby'], 'has_archives' => false ) );
				if ( is_wp_error( $result ) ) { continue; }
			}
			$taxonomy = wc_attribute_taxonomy_name( $slug );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ) );
			}
		}
		foreach ( self::size_chart() as $size => $row ) {
			$term = term_exists( 'size-' . $size, 'pa_size' );
			if ( ! $term ) { $term = wp_insert_term( 'Size ' . $size, 'pa_size', array( 'slug' => 'size-' . $size ) ); }
			if ( ! is_wp_error( $term ) ) { update_term_meta( (int) $term['term_id'], 'order', $size ); }
		}
		delete_transient( 'wc_attribute_taxonomies' );
		update_option( 'nexxi_catalog_schema', self::SCHEMA );
	}

	public function color_add_field() {
		echo '<div class="form-field"><label for="nexxi-color-hex">' . esc_html__( 'Mã màu hiển thị', 'nexxi-kids-commerce' ) . '</label><input type="color" id="nexxi-color-hex" name="nexxi_color_hex" value="#f6edd9"><p>' . esc_html__( 'Dùng cho chấm màu trên trang sản phẩm và bộ lọc.', 'nexxi-kids-commerce' ) . '</p></div>';
	}

	public function color_edit_field( $term ) {
		$value = self::color_hex( $term );
		echo '<tr class="form-field"><th scope="row"><label for="nexxi-color-hex">' . esc_html__( 'Mã màu hiển thị', 'nexxi-kids-commerce' ) . '</label></th><td><input type="color" id="nexxi-color-hex" name="nexxi_color_hex" value="' . esc_attr( $value ) . '"></td></tr>';
	}

	public function color_save( $term_id ) {
		if ( ! current_user_can( 'manage_product_terms' ) || ! isset( $_POST['nexxi_color_hex'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies the term form nonce.
		$hex = sanitize_hex_color( wp_unslash( $_POST['nexxi_color_hex'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $hex ) { update_term_meta( $term_id, 'nexxi_color_hex', $hex ); }
	}

	/** Swatch colour for a pa_mau-sac term (falls back to a soft neutral). */
	public static function color_hex( $term ) {
		$term = is_numeric( $term ) ? get_term( (int) $term ) : $term;
		$hex = $term instanceof WP_Term ? get_term_meta( $term->term_id, 'nexxi_color_hex', true ) : '';
		return $hex ? $hex : '#e4e2de';
	}

	/** Size number from a term slug/name such as "size-4" or "Size 4". */
	public static function size_number( $value ) {
		return preg_match( '~(\d+)~', (string) $value, $m ) ? (int) $m[1] : 0;
	}

	/** Weight label for a size, e.g. "14 - 16kg". */
	public static function size_weight( $size ) {
		$chart = self::size_chart();
		return isset( $chart[ $size ] ) ? $chart[ $size ][0] . ' - ' . $chart[ $size ][1] . 'kg' : '';
	}

	/** Ordered term objects of an attribute that the product actually uses. */
	public static function product_terms( WC_Product $product, $slug ) {
		$taxonomy = wc_attribute_taxonomy_name( $slug );
		$terms = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'all' ) );
		if ( 'size' === $slug ) {
			usort( $terms, static function ( $a, $b ) { return self::size_number( $a->slug ) <=> self::size_number( $b->slug ); } );
		}
		return is_array( $terms ) ? $terms : array();
	}

	/** Compact variation payload consumed by the storefront scripts. */
	public static function variation_payload( WC_Product $product ) {
		static $cache = array();
		if ( ! $product->is_type( 'variable' ) ) { return array(); }
		if ( isset( $cache[ $product->get_id() ] ) ) { return $cache[ $product->get_id() ]; }
		$rows = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation || ! $variation->exists() || 'publish' !== $variation->get_status() || ! $variation->is_purchasable() ) { continue; }
			$attributes = $variation->get_attributes();
			$image = $variation->get_image_id() ? wp_get_attachment_image_url( $variation->get_image_id(), 'large' ) : '';
			$rows[] = array(
				'id'      => $variation->get_id(),
				'size'    => isset( $attributes['pa_size'] ) ? $attributes['pa_size'] : '',
				'color'   => isset( $attributes['pa_mau-sac'] ) ? $attributes['pa_mau-sac'] : '',
				'price'   => (float) wc_get_price_to_display( $variation ),
				'regular' => (float) wc_get_price_to_display( $variation, array( 'price' => $variation->get_regular_price() ) ),
				'stock'   => $variation->managing_stock() ? (int) $variation->get_stock_quantity() : null,
				'inStock' => $variation->is_in_stock(),
				'max'     => $variation->get_max_purchase_quantity(),
				'image'   => $image ? $image : '',
			);
		}
		$cache[ $product->get_id() ] = $rows;
		return $rows;
	}

	/** Total units left (sum of managed variation stock), or null when stock is not tracked. */
	public static function stock_left( WC_Product $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$total = null;
			foreach ( self::variation_payload( $product ) as $row ) {
				if ( null !== $row['stock'] ) { $total = (int) $total + max( 0, $row['stock'] ); }
			}
			return $total;
		}
		return $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
	}

	/** Largest discount percentage across the product (0 when not on sale). */
	public static function discount_percent( WC_Product $product ) {
		if ( ! $product->is_on_sale() ) { return 0; }
		$best = 0;
		$items = $product->is_type( 'variable' ) ? self::variation_payload( $product ) : array( array( 'price' => (float) $product->get_price(), 'regular' => (float) $product->get_regular_price() ) );
		foreach ( $items as $row ) {
			if ( $row['regular'] > 0 && $row['price'] < $row['regular'] ) { $best = max( $best, (int) round( ( 1 - $row['price'] / $row['regular'] ) * 100 ) ); }
		}
		return $best;
	}

	public function cart_fragments( $fragments ) {
		$fragments['nexxi-cart-count'] = (string) ( WC()->cart ? WC()->cart->get_cart_contents_count() : 0 );
		return $fragments;
	}

	public function rest_routes() {
		register_rest_route( 'nexxi/v1', '/wishlist', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'wishlist_get' ),
				'permission_callback' => 'is_user_logged_in',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'wishlist_save' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array( 'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'required' => true ) ),
			),
		) );
	}

	public function wishlist_get() {
		return rest_ensure_response( array( 'ids' => self::wishlist_ids( get_current_user_id() ) ) );
	}

	public function wishlist_save( WP_REST_Request $request ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ) );
		$ids = array_slice( array_filter( $ids, static function ( $id ) { return 'product' === get_post_type( $id ); } ), 0, 200 );
		update_user_meta( get_current_user_id(), '_nexxi_wishlist', $ids );
		return rest_ensure_response( array( 'ids' => $ids ) );
	}

	public static function wishlist_ids( $user_id ) {
		$ids = get_user_meta( $user_id, '_nexxi_wishlist', true );
		return is_array( $ids ) ? array_values( array_map( 'absint', $ids ) ) : array();
	}
}
