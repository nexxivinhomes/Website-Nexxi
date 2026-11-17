<?php
/**
 * NEXXI product data model: global attributes, colour swatches, size chart,
 * variation payloads for the Stitch storefront and wishlist sync.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nexxi_Kids_Catalog {
	const SCHEMA                          = '2';
	const POS_APP_API_KEY                 = 'nxp_v1_8f4c9a2e7b1d6f3a5c8e0b2d4f6a8c1e3b5d7f9a2c4e6b8d0f1a3c5e7b9d2f4a';
	const POS_MAX_SNAPSHOT_BYTES          = 26214400;
	const POS_MAX_SNAPSHOT_FILES_PER_SHOP = 15;
	const POS_MAX_FUTURE_SKEW_SECONDS     = 300;
	const POS_SNAPSHOT_TTL_KEEP           = 1209600;

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
		add_filter( 'rest_pre_serve_request', array( $this, 'pos_app_cors_headers' ), 15, 4 );
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
		return $hex ? $hex : self::color_guess( $term instanceof WP_Term ? $term->name : '' );
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

	/**
	 * Ordered term objects of an attribute that the product actually uses.
	 * 'size' and 'mau-sac' resolve to whichever variation attribute plays that
	 * role (e.g. pa_kich-co, a custom "SIZE" attribute, pa_mau...).
	 */
	public static function product_terms( WC_Product $product, $slug ) {
		if ( in_array( $slug, array( 'size', 'mau-sac' ), true ) && $product->is_type( 'variable' ) ) {
			$role = 'size' === $slug ? 'size' : 'color';
			foreach ( self::variation_attributes( $product ) as $attribute ) {
				if ( $role !== $attribute['role'] ) { continue; }
				return array_map( static function ( $option ) {
					return (object) array( 'slug' => $option['value'], 'name' => $option['name'], 'term_id' => $option['term_id'] );
				}, $attribute['options'] );
			}
		}
		$taxonomy = wc_attribute_taxonomy_name( $slug );
		$terms = get_the_terms( $product->get_id(), $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		if ( 'size' === $slug ) {
			usort( $terms, static function ( $a, $b ) { return self::size_number( $a->slug ) <=> self::size_number( $b->slug ); } );
		}
		return $terms;
	}

	/** 'size', 'color' or '' guessed from an attribute name / label (Vietnamese or English). */
	public static function attribute_role( $name, $label = '' ) {
		$haystack = '-' . sanitize_title( remove_accents( $name . ' ' . $label ) ) . '-';
		if ( preg_match( '~(size|kich-co|kich-thuoc|-co-)~', $haystack ) ) { return 'size'; }
		if ( preg_match( '~(mau|color|colour)~', $haystack ) ) { return 'color'; }
		return '';
	}

	/** Normalised value used to compare parent options with variation values. */
	public static function attribute_value( $value, $is_taxonomy ) {
		return $is_taxonomy ? (string) $value : sanitize_title( (string) $value );
	}

	/**
	 * Every attribute used for variations, in the order set in the product editor.
	 * Each entry: key (variation attribute key), label, role, taxonomy flag and options.
	 */
	public static function variation_attributes( WC_Product $product ) {
		static $cache = array();
		if ( ! $product->is_type( 'variable' ) ) { return array(); }
		if ( isset( $cache[ $product->get_id() ] ) ) { return $cache[ $product->get_id() ]; }
		$list = array();
		$taken = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) { continue; }
			$name = $attribute->get_name();
			$label = wc_attribute_label( $name, $product );
			$role = self::attribute_role( $name, $label );
			if ( $role && isset( $taken[ $role ] ) ) { $role = ''; }
			if ( $role ) { $taken[ $role ] = true; }
			$options = array();
			if ( $attribute->is_taxonomy() ) {
				$cached_terms = get_the_terms( $product->get_id(), $name );
				if ( is_array( $cached_terms ) ) {
					foreach ( $cached_terms as $term ) {
						$options[] = array( 'value' => $term->slug, 'name' => $term->name, 'term_id' => $term->term_id, 'hex' => 'color' === $role ? self::color_hex( $term ) : '' );
					}
				}
			} else {
				foreach ( $attribute->get_options() as $option ) {
					$options[] = array( 'value' => self::attribute_value( $option, false ), 'name' => $option, 'term_id' => 0, 'hex' => 'color' === $role ? self::color_guess( $option ) : '' );
				}
			}
			if ( 'size' === $role ) {
				$numeric = array_filter( $options, static function ( $o ) { return self::size_number( $o['name'] ) > 0; } );
				if ( count( $numeric ) === count( $options ) ) {
					usort( $options, static function ( $a, $b ) { return self::size_number( $a['name'] ) <=> self::size_number( $b['name'] ); } );
				}
			}
			$list[] = array(
				'key'      => sanitize_title( $name ),
				'label'    => $label,
				'role'     => $role,
				'taxonomy' => $attribute->is_taxonomy(),
				'options'  => $options,
			);
		}
		$cache[ $product->get_id() ] = $list;
		return $list;
	}

	/** Swatch colour guessed from a Vietnamese/English colour name such as "Hồng", "TRANG". */
	public static function color_guess( $name ) {
		$map = array(
			'trang' => '#ffffff', 'white' => '#ffffff', 'den' => '#1b1c1a', 'black' => '#1b1c1a', 'do' => '#d93a3a', 'red' => '#d93a3a',
			'hong' => '#f6b8c8', 'pink' => '#f6b8c8', 'xanh-duong' => '#4a7bd0', 'xanh-la' => '#5bab6c', 'xanh-navy' => '#1b2a59', 'navy' => '#1b2a59',
			'xanh' => '#7fb3d5', 'blue' => '#7fb3d5', 'vang' => '#f5d36b', 'yellow' => '#f5d36b', 'cam' => '#f39a4c', 'tim' => '#a77bc9',
			'nau' => '#8a5a3c', 'be' => '#e9dcc3', 'kem' => '#f6edd9', 'xam' => '#a3a3a3', 'ghi' => '#a3a3a3', 'grey' => '#a3a3a3', 'gray' => '#a3a3a3',
		);
		$slug = sanitize_title( remove_accents( (string) $name ) );
		foreach ( $map as $key => $hex ) {
			if ( $slug === $key || 0 === strpos( $slug, $key . '-' ) ) { return $hex; }
		}
		return '#e4e2de';
	}

	/** Compact variation payload consumed by the storefront scripts (fast postmeta batch read, 0 WC_Product_Variation instantiations). */
	public static function variation_payload( WC_Product $product, $include_images = true ) {
		static $cache_full  = array();
		static $cache_light = array();
		if ( ! $product->is_type( 'variable' ) ) { return array(); }
		$pid = $product->get_id();
		if ( isset( $cache_full[ $pid ] ) ) { return $cache_full[ $pid ]; }
		if ( ! $include_images && isset( $cache_light[ $pid ] ) ) { return $cache_light[ $pid ]; }

		$children = array_values( array_filter( array_map( 'absint', (array) $product->get_children() ) ) );
		if ( empty( $children ) ) {
			$cache_full[ $pid ]  = array();
			$cache_light[ $pid ] = array();
			return array();
		}

		// Prime postmeta cache for all variations of this product in 1 single SQL query
		update_meta_cache( 'post', $children );

		$defs = self::variation_attributes( $product );
		$rows = array();

		// Only prime variation thumbnail attachment meta when full images are requested (Single Product Page)
		if ( $include_images ) {
			$thumb_ids = array();
			foreach ( $children as $child_id ) {
				$tid = (int) get_post_meta( $child_id, '_thumbnail_id', true );
				if ( $tid > 0 ) {
					$thumb_ids[ $tid ] = $tid;
				}
			}
			if ( ! empty( $thumb_ids ) ) {
				update_meta_cache( 'post', array_values( $thumb_ids ) );
			}
		}

		foreach ( $children as $child_id ) {
			$price   = (float) get_post_meta( $child_id, '_price', true );
			$regular = (float) get_post_meta( $child_id, '_regular_price', true );
			if ( $price <= 0 && $regular > 0 ) {
				$price = $regular;
			}
			if ( $price <= 0 ) {
				continue;
			}
			if ( $regular <= 0 ) {
				$regular = $price;
			}

			$attrs = array();
			$roles = array( 'size' => '', 'color' => '' );
			$any   = false;
			foreach ( $defs as $def ) {
				$meta_key = 'attribute_' . $def['key'];
				$raw      = (string) get_post_meta( $child_id, $meta_key, true );
				if ( '' === $raw && 0 !== strpos( $def['key'], 'pa_' ) ) {
					$raw = (string) get_post_meta( $child_id, 'attribute_pa_' . $def['key'], true );
				}
				$value = '' === $raw ? '' : self::attribute_value( $raw, $def['taxonomy'] );
				if ( '' === $value ) {
					$any = true;
				}
				$attrs[ $def['key'] ] = $value;
				if ( $def['role'] ) {
					$roles[ $def['role'] ] = $value;
				}
			}

			$manage_stock = 'yes' === (string) get_post_meta( $child_id, '_manage_stock', true );
			$stock_qty    = $manage_stock ? (int) get_post_meta( $child_id, '_stock', true ) : null;
			$stock_status = (string) get_post_meta( $child_id, '_stock_status', true );
			$in_stock     = $manage_stock ? ( $stock_qty > 0 || 'notify' === get_post_meta( $child_id, '_backorders', true ) || 'yes' === get_post_meta( $child_id, '_backorders', true ) ) : ( 'outofstock' !== $stock_status );

			$image = '';
			if ( $include_images ) {
				$tid   = (int) get_post_meta( $child_id, '_thumbnail_id', true );
				$image = $tid > 0 ? wp_get_attachment_image_url( $tid, 'large' ) : '';
			}

			$rows[] = array(
				'id'      => $child_id,
				'attrs'   => (object) $attrs,
				'any'     => $any,
				'size'    => $roles['size'],
				'color'   => $roles['color'],
				'price'   => $price,
				'regular' => $regular,
				'stock'   => $stock_qty,
				'inStock' => $in_stock,
				'max'     => $manage_stock && $stock_qty > 0 ? $stock_qty : -1,
				'image'   => $image ? $image : '',
			);
		}
		if ( $include_images ) {
			$cache_full[ $pid ] = $rows;
		} else {
			$cache_light[ $pid ] = $rows;
		}
		return $rows;
	}

	/** Total units left (sum of managed variation stock), or null when stock is not tracked. */
	public static function stock_left( WC_Product $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$total = null;
			foreach ( self::variation_payload( $product, false ) as $row ) {
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
		$items = $product->is_type( 'variable' ) ? self::variation_payload( $product, false ) : array( array( 'price' => (float) $product->get_price(), 'regular' => (float) $product->get_regular_price() ) );
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

		register_rest_route( 'nexxi/v1', '/pos-app-state', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'pos_app_state_get' ),
				'permission_callback' => array( $this, 'verify_pos_app_api_key' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'pos_app_state_post' ),
				'permission_callback' => array( $this, 'verify_pos_app_api_key' ),
			),
			array(
				'methods'             => 'OPTIONS',
				'callback'            => array( $this, 'pos_app_state_options' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( 'nexxi/v1', '/pos-app-snapshot', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'pos_app_snapshot_get' ),
				'permission_callback' => array( $this, 'verify_pos_app_api_key' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'pos_app_snapshot_post' ),
				'permission_callback' => array( $this, 'verify_pos_app_api_key' ),
			),
			array(
				'methods'             => 'OPTIONS',
				'callback'            => array( $this, 'pos_app_state_options' ),
				'permission_callback' => '__return_true',
			),
		) );
	}

	/**
	 * Verify required X-Nexxi-Pos-Key header on POS storage REST routes.
	 *
	 * @param WP_REST_Request|null $request REST request.
	 * @return true|WP_Error
	 */
	public function verify_pos_app_api_key( $request = null ) {
		$provided = '';
		if ( $request instanceof WP_REST_Request ) {
			$provided = trim( (string) $request->get_header( 'x_nexxi_pos_key' ) );
		}
		if ( '' === $provided && isset( $_SERVER['HTTP_X_NEXXI_POS_KEY'] ) ) {
			$provided = trim( (string) wp_unslash( $_SERVER['HTTP_X_NEXXI_POS_KEY'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		if ( '' === $provided && function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $header_name => $header_value ) {
					if ( 'x-nexxi-pos-key' === strtolower( (string) $header_name ) ) {
						$provided = trim( (string) $header_value );
						break;
					}
				}
			}
		}
		if ( '' === $provided || ! hash_equals( self::POS_APP_API_KEY, $provided ) ) {
			return new WP_Error( 'unauthorized_pos_key', 'Unauthorized POS storage access.', array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Send explicit CORS and no-store headers for POS App shared state & snapshot requests.
	 */
	public function pos_app_cors_headers( $served, $result, $request, $server ) {
		unset( $result, $server );
		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( 0 !== strpos( $route, '/nexxi/v1/pos-app-' ) ) {
			return $served;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) ) : '';
		$allowed = '' !== $origin && (
			1 === preg_match( '#^https://([a-z0-9-]+\.)?posapp\.pke\.gg$#i', $origin ) ||
			1 === preg_match( '#^https://([a-z0-9-]+\.)?pancake\.vn$#i', $origin ) ||
			1 === preg_match( '#^https://([a-z0-9-]+\.)?pages\.fm$#i', $origin ) ||
			1 === preg_match( '#^http://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin )
		);
		if ( $allowed ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Vary: Origin' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type, Accept, X-Nexxi-Pos-Key' );
		} else {
			if ( function_exists( 'header_remove' ) ) {
				@header_remove( 'Access-Control-Allow-Origin' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@header_remove( 'Access-Control-Allow-Credentials' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			if ( '' !== $origin ) {
				status_header( 403 );
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
				header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
				echo wp_json_encode(
					array(
						'success' => false,
						'message' => 'Origin not allowed.',
					)
				);
				exit;
			}
		}
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		return $served;
	}

	/**
	 * Handle CORS preflight OPTIONS for POS App shared state endpoint.
	 */
	public function pos_app_state_options() {
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Read shared Nexxi POS App configuration for a shop.
	 */
	public function pos_app_state_get( WP_REST_Request $request ) {
		$shop_id = preg_replace( '/\D+/', '', (string) $request->get_param( 'shop_id' ) );
		if ( '' === $shop_id || strlen( $shop_id ) > 20 ) {
			return new WP_Error( 'invalid_shop_id', 'Missing or invalid shop_id.', array( 'status' => 400 ) );
		}
		$stored = get_option( 'nexxi_pos_app_shared_state_' . $shop_id, null );
		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = json_decode( $stored, true );
			$stored  = is_array( $decoded ) ? $decoded : null;
		}
		return rest_ensure_response(
			array(
				'success' => true,
				'state'   => is_array( $stored ) ? $this->normalize_state_for_json( $stored ) : null,
			)
		);
	}

	/**
	 * Ensure associative maps serialize as JSON objects `{}` even when empty.
	 *
	 * @param array $state Shared state array.
	 * @return array
	 */
	private function normalize_state_for_json( $state ) {
		$products = isset( $state['productSettings'] ) && is_array( $state['productSettings'] ) ? $state['productSettings'] : array();
		$skus     = isset( $state['skuSettings'] ) && is_array( $state['skuSettings'] ) ? $state['skuSettings'] : array();
		$state['productSettings'] = empty( $products ) ? new stdClass() : $products;
		$state['skuSettings']     = empty( $skus ) ? new stdClass() : $skus;
		return $state;
	}

	/**
	 * Save and timestamp-merge shared Nexxi POS App configuration for a shop.
	 */
	public function pos_app_state_post( WP_REST_Request $request ) {
		$raw_body = (string) $request->get_body();
		if ( strlen( $raw_body ) > 524288 ) {
			return new WP_Error( 'payload_too_large', 'Shared state payload exceeds 512 KB limit.', array( 'status' => 413 ) );
		}
		$incoming = json_decode( $raw_body, true );
		if ( ! is_array( $incoming ) || 1 !== (int) ( $incoming['schemaVersion'] ?? 0 ) ) {
			return new WP_Error( 'invalid_payload', 'Invalid POS App shared config payload.', array( 'status' => 400 ) );
		}
		$shop_id = preg_replace( '/\D+/', '', (string) ( $incoming['shopId'] ?? $request->get_param( 'shop_id' ) ) );
		if ( '' === $shop_id || strlen( $shop_id ) > 20 ) {
			return new WP_Error( 'invalid_shop_id', 'Missing or invalid shopId.', array( 'status' => 400 ) );
		}
		if ( ! isset( $incoming['defaults'] ) || ! is_array( $incoming['defaults'] ) ) {
			return new WP_Error( 'invalid_payload', 'Missing defaults object.', array( 'status' => 400 ) );
		}

		$option_key = 'nexxi_pos_app_shared_state_' . $shop_id;
		$existing   = get_option( $option_key, null );
		if ( is_string( $existing ) && '' !== $existing ) {
			$decoded  = json_decode( $existing, true );
			$existing = is_array( $decoded ) ? $decoded : null;
		}
		if ( ! is_array( $existing ) || 1 !== (int) ( $existing['schemaVersion'] ?? 0 ) ) {
			$existing = null;
		}

		$merged = $this->merge_pos_app_shared_states( $shop_id, $existing, $incoming );
		update_option( $option_key, wp_json_encode( $merged ), false );

		return rest_ensure_response(
			array(
				'success' => true,
				'state'   => $this->normalize_state_for_json( $merged ),
			)
		);
	}

	/**
	 * Clamp client-provided ISO timestamps so future dates cannot poison LWW merges.
	 *
	 * @param mixed  $raw      Raw timestamp.
	 * @param string $fallback Fallback if empty.
	 * @return string
	 */
	private function clamp_pos_iso_timestamp( $raw, $fallback = '' ) {
		$clean = sanitize_text_field( (string) $raw );
		if ( '' === $clean ) {
			return $fallback;
		}
		$ts = @strtotime( $clean ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ts || $ts > ( time() + self::POS_MAX_FUTURE_SKEW_SECONDS ) ) {
			return gmdate( 'c' );
		}
		return $clean;
	}

	/**
	 * Merge existing and incoming shop shared configuration by ISO updatedAt timestamps.
	 *
	 * @param string     $shop_id  Shop ID.
	 * @param array|null $existing Stored server state.
	 * @param array      $incoming Incoming client state.
	 * @return array
	 */
	private function merge_pos_app_shared_states( $shop_id, $existing, $incoming ) {
		$incoming_products = isset( $incoming['productSettings'] ) && is_array( $incoming['productSettings'] ) ? $incoming['productSettings'] : array();
		$incoming_skus     = isset( $incoming['skuSettings'] ) && is_array( $incoming['skuSettings'] ) ? $incoming['skuSettings'] : array();

		$sanitized_products = array();
		foreach ( $incoming_products as $pid => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( isset( $entry['updatedAt'] ) ) {
				$entry['updatedAt'] = $this->clamp_pos_iso_timestamp( $entry['updatedAt'], gmdate( 'c' ) );
			}
			$sanitized_products[ $pid ] = $entry;
		}

		$sanitized_skus = array();
		foreach ( $incoming_skus as $vid => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( isset( $entry['updatedAt'] ) ) {
				$entry['updatedAt'] = $this->clamp_pos_iso_timestamp( $entry['updatedAt'], gmdate( 'c' ) );
			}
			$sanitized_skus[ $vid ] = $entry;
		}

		$incoming_exp    = $this->clamp_pos_iso_timestamp( $incoming['exportedAt'] ?? '', gmdate( 'c' ) );
		$incoming_def_ts = $this->clamp_pos_iso_timestamp( $incoming['defaultsUpdatedAt'] ?? '', '' );

		if ( ! is_array( $existing ) ) {
			return array(
				'schemaVersion'     => 1,
				'shopId'            => (string) $shop_id,
				'exportedAt'        => $incoming_exp,
				'exportedBy'        => sanitize_text_field( (string) ( $incoming['exportedBy'] ?? '' ) ),
				'defaults'          => $incoming['defaults'],
				'defaultsUpdatedAt' => $incoming_def_ts,
				'defaultsUpdatedBy' => sanitize_text_field( (string) ( $incoming['defaultsUpdatedBy'] ?? '' ) ),
				'productSettings'   => $sanitized_products,
				'skuSettings'       => $sanitized_skus,
			);
		}

		$existing_def_ts  = (string) ( $existing['defaultsUpdatedAt'] ?? '' );
		$use_incoming_def = '' !== $incoming_def_ts && ( '' === $existing_def_ts || strcmp( $incoming_def_ts, $existing_def_ts ) > 0 );

		$defaults            = $use_incoming_def ? $incoming['defaults'] : ( $existing['defaults'] ?? $incoming['defaults'] );
		$defaults_updated_at = $use_incoming_def ? $incoming_def_ts : $existing_def_ts;
		$defaults_updated_by = $use_incoming_def
			? sanitize_text_field( (string) ( $incoming['defaultsUpdatedBy'] ?? '' ) )
			: (string) ( $existing['defaultsUpdatedBy'] ?? '' );

		$merged_products = isset( $existing['productSettings'] ) && is_array( $existing['productSettings'] ) ? $existing['productSettings'] : array();
		foreach ( $sanitized_products as $pid => $entry ) {
			$prev_ts = isset( $merged_products[ $pid ]['updatedAt'] ) ? (string) $merged_products[ $pid ]['updatedAt'] : '';
			$next_ts = isset( $entry['updatedAt'] ) ? (string) $entry['updatedAt'] : '';
			if ( ! isset( $merged_products[ $pid ] ) || ( '' !== $next_ts && strcmp( $next_ts, $prev_ts ) > 0 ) ) {
				$merged_products[ $pid ] = $entry;
			}
		}

		$merged_skus = isset( $existing['skuSettings'] ) && is_array( $existing['skuSettings'] ) ? $existing['skuSettings'] : array();
		foreach ( $sanitized_skus as $vid => $entry ) {
			$prev_ts = isset( $merged_skus[ $vid ]['updatedAt'] ) ? (string) $merged_skus[ $vid ]['updatedAt'] : '';
			$next_ts = isset( $entry['updatedAt'] ) ? (string) $entry['updatedAt'] : '';
			if ( ! isset( $merged_skus[ $vid ] ) || ( '' !== $next_ts && strcmp( $next_ts, $prev_ts ) > 0 ) ) {
				$merged_skus[ $vid ] = $entry;
			}
		}

		$existing_exp      = (string) ( $existing['exportedAt'] ?? '' );
		$take_incoming_exp = strcmp( $incoming_exp, $existing_exp ) >= 0;

		return array(
			'schemaVersion'     => 1,
			'shopId'            => (string) $shop_id,
			'exportedAt'        => $take_incoming_exp ? $incoming_exp : $existing_exp,
			'exportedBy'        => sanitize_text_field( (string) ( $take_incoming_exp ? ( $incoming['exportedBy'] ?? '' ) : ( $existing['exportedBy'] ?? '' ) ) ),
			'defaults'          => $defaults,
			'defaultsUpdatedAt' => $defaults_updated_at,
			'defaultsUpdatedBy' => $defaults_updated_by,
			'productSettings'   => $merged_products,
			'skuSettings'       => $merged_skus,
		);
	}

	/**
	 * Ensure the protected nexxi-pos-cache directory exists inside wp-content/uploads.
	 *
	 * @return string|WP_Error Directory path without trailing slash.
	 */
	private function ensure_pos_snapshot_cache_dir() {
		$uploads  = wp_upload_dir();
		$base_dir = isset( $uploads['basedir'] ) ? rtrim( (string) $uploads['basedir'], '/\\' ) : '';
		if ( '' === $base_dir ) {
			$base_dir = rtrim( WP_CONTENT_DIR, '/\\' ) . DIRECTORY_SEPARATOR . 'uploads';
		}
		$dir = $base_dir . DIRECTORY_SEPARATOR . 'nexxi-pos-cache';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'cache_dir_failed', 'Could not create nexxi-pos-cache directory.', array( 'status' => 500 ) );
		}
		$htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$index_php = $dir . DIRECTORY_SEPARATOR . 'index.php';
		if ( ! file_exists( $index_php ) ) {
			@file_put_contents( $index_php, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $dir;
	}

	/**
	 * Read cached Nexxi POS App SyncSnapshot JSON file for a shop and slice key.
	 */
	public function pos_app_snapshot_get( WP_REST_Request $request ) {
		$shop_id   = preg_replace( '/\D+/', '', (string) $request->get_param( 'shop_id' ) );
		$slice_key = trim( (string) $request->get_param( 'slice_key' ) );
		if ( '' === $shop_id || strlen( $shop_id ) > 20 || '' === $slice_key || strlen( $slice_key ) > 500 ) {
			return new WP_Error( 'invalid_params', 'Missing or invalid shop_id / slice_key.', array( 'status' => 400 ) );
		}

		$dir = $this->ensure_pos_snapshot_cache_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$file_path = $dir . DIRECTORY_SEPARATOR . 'snapshot-' . $shop_id . '-' . md5( $slice_key ) . '.json';
		if ( ! is_readable( $file_path ) ) {
			return rest_ensure_response(
				array(
					'success'  => true,
					'snapshot' => null,
					'meta'     => null,
				)
			);
		}

		$raw    = @file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		$record = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $record ) || ! isset( $record['snapshot'] ) || ! is_array( $record['snapshot'] ) ) {
			return rest_ensure_response(
				array(
					'success'  => true,
					'snapshot' => null,
					'meta'     => null,
				)
			);
		}

		$meta              = isset( $record['meta'] ) && is_array( $record['meta'] ) ? $record['meta'] : null;
		$prefer_compressed = ! empty( $request->get_param( 'compressed' ) );
		if ( $prefer_compressed && function_exists( 'gzencode' ) ) {
			$snap_json = wp_json_encode( $record['snapshot'] );
			if ( is_string( $snap_json ) ) {
				$gz = @gzencode( $snap_json, 6 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_string( $gz ) && '' !== $gz ) {
					return rest_ensure_response(
						array(
							'success'          => true,
							'format'           => 'nexxi-gzip-v1',
							'meta'             => $meta,
							'compressedBase64' => base64_encode( $gz ),
						)
					);
				}
			}
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'meta'     => $meta,
				'snapshot' => $record['snapshot'],
			)
		);
	}

	/**
	 * Store compressed or JSON Nexxi POS App SyncSnapshot file on disk.
	 */
	public function pos_app_snapshot_post( WP_REST_Request $request ) {
		$raw_body = (string) $request->get_body();
		if ( strlen( $raw_body ) > self::POS_MAX_SNAPSHOT_BYTES ) {
			return new WP_Error( 'payload_too_large', 'Snapshot payload exceeds 25 MB limit.', array( 'status' => 413 ) );
		}
		$body = json_decode( $raw_body, true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_payload', 'Invalid snapshot JSON payload.', array( 'status' => 400 ) );
		}

		$shop_id   = preg_replace( '/\D+/', '', (string) ( $body['shopId'] ?? $request->get_param( 'shop_id' ) ) );
		$slice_key = trim( (string) ( $body['sliceKey'] ?? '' ) );
		if ( '' === $shop_id || strlen( $shop_id ) > 20 || '' === $slice_key || strlen( $slice_key ) > 500 ) {
			return new WP_Error( 'invalid_params', 'Missing or invalid shopId / sliceKey.', array( 'status' => 400 ) );
		}

		$snapshot = null;
		if ( isset( $body['format'] ) && 'nexxi-gzip-v1' === $body['format'] ) {
			if ( ! function_exists( 'gzdecode' ) ) {
				return new WP_Error( 'zlib_unavailable', 'Server does not support gzip decoding.', array( 'status' => 500 ) );
			}
			$bin = base64_decode( (string) ( $body['compressedBase64'] ?? '' ), true );
			if ( false === $bin ) {
				return new WP_Error( 'invalid_gzip', 'Invalid base64 gzip snapshot payload.', array( 'status' => 400 ) );
			}
			$inflated = @gzdecode( $bin, self::POS_MAX_SNAPSHOT_BYTES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_string( $inflated ) || '' === $inflated ) {
				return new WP_Error( 'invalid_gzip', 'Failed to gunzip snapshot payload or decoded size exceeds limit.', array( 'status' => 400 ) );
			}
			$decoded  = json_decode( $inflated, true );
			$snapshot = is_array( $decoded ) ? $decoded : null;
		} elseif ( isset( $body['snapshot'] ) && is_array( $body['snapshot'] ) ) {
			$snapshot = $body['snapshot'];
		}

		if (
			! is_array( $snapshot ) ||
			empty( $snapshot['syncedAtIso'] ) ||
			! isset( $snapshot['variations'] ) || ! is_array( $snapshot['variations'] ) ||
			! isset( $snapshot['products'] ) || ! is_array( $snapshot['products'] )
		) {
			return new WP_Error( 'invalid_snapshot', 'Missing required snapshot fields.', array( 'status' => 400 ) );
		}

		$dir = $this->ensure_pos_snapshot_cache_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$file_path = $dir . DIRECTORY_SEPARATOR . 'snapshot-' . $shop_id . '-' . md5( $slice_key ) . '.json';
		$existing  = null;
		if ( is_readable( $file_path ) ) {
			$old_raw  = @file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
			$existing = is_string( $old_raw ) && '' !== $old_raw ? json_decode( $old_raw, true ) : null;
		}

		$force                   = ! empty( $body['force'] );
		$new_sync                = $this->clamp_pos_iso_timestamp( $snapshot['syncedAtIso'], gmdate( 'c' ) );
		$snapshot['syncedAtIso'] = $new_sync;
		$old_sync                = is_array( $existing ) && isset( $existing['meta']['syncedAtIso'] ) ? (string) $existing['meta']['syncedAtIso'] : '';

		if ( ! $force && '' !== $old_sync && strcmp( $old_sync, $new_sync ) > 0 ) {
			return rest_ensure_response(
				array(
					'success'   => true,
					'keptNewer' => true,
					'meta'      => $existing['meta'],
				)
			);
		}

		$meta = array(
			'shopId'       => (string) $shop_id,
			'sliceKey'     => $slice_key,
			'syncedAtIso'  => $new_sync,
			'savedAtIso'   => gmdate( 'c' ),
			'savedBy'      => sanitize_text_field( (string) ( $body['savedBy'] ?? '' ) ),
			'productCount' => count( $snapshot['products'] ),
			'skuCount'     => count( $snapshot['variations'] ),
		);

		$encoded = wp_json_encode(
			array(
				'meta'     => $meta,
				'snapshot' => $snapshot,
			)
		);
		if ( false === $encoded ) {
			return new WP_Error( 'encode_failed', 'Failed to encode snapshot JSON.', array( 'status' => 500 ) );
		}

		$tmp = $file_path . '.tmp.' . uniqid( '', true );
		$ok  = @file_put_contents( $tmp, $encoded, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ok ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'write_failed', 'Failed to write snapshot cache file.', array( 'status' => 500 ) );
		}
		if ( ! @rename( $tmp, $file_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$fallback = @file_put_contents( $file_path, $encoded, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $fallback ) {
				return new WP_Error( 'write_failed', 'Failed to persist snapshot cache file.', array( 'status' => 500 ) );
			}
		}

		$this->cleanup_old_pos_snapshots( $dir, $shop_id, $file_path );

		return rest_ensure_response(
			array(
				'success' => true,
				'meta'    => $meta,
			)
		);
	}

	/**
	 * Remove stale snapshot files (>14 days) and cap total snapshot files per shop.
	 *
	 * @param string $dir            Cache directory.
	 * @param string $shop_id        Shop ID.
	 * @param string $keep_file_path Current file path to retain on tie.
	 */
	private function cleanup_old_pos_snapshots( $dir, $shop_id, $keep_file_path = '' ) {
		$pattern = $dir . DIRECTORY_SEPARATOR . 'snapshot-' . $shop_id . '-*.json';
		$files   = glob( $pattern );
		if ( ! is_array( $files ) ) {
			return;
		}
		$cutoff    = time() - self::POS_SNAPSHOT_TTL_KEEP;
		$remaining = array();
		foreach ( $files as $file ) {
			$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $mtime && $mtime < $cutoff && $file !== $keep_file_path ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				continue;
			}
			$remaining[] = array(
				'path'  => $file,
				'mtime' => false !== $mtime ? (int) $mtime : 0,
			);
		}
		if ( count( $remaining ) > self::POS_MAX_SNAPSHOT_FILES_PER_SHOP ) {
			usort(
				$remaining,
				static function ( $a, $b ) use ( $keep_file_path ) {
					if ( $a['path'] === $keep_file_path && $b['path'] !== $keep_file_path ) {
						return -1;
					}
					if ( $b['path'] === $keep_file_path && $a['path'] !== $keep_file_path ) {
						return 1;
					}
					if ( $a['mtime'] === $b['mtime'] ) {
						return strcmp( $b['path'], $a['path'] );
					}
					return $b['mtime'] <=> $a['mtime'];
				}
			);
			foreach ( array_slice( $remaining, self::POS_MAX_SNAPSHOT_FILES_PER_SHOP ) as $stale ) {
				if ( $stale['path'] !== $keep_file_path ) {
					@unlink( $stale['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
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
