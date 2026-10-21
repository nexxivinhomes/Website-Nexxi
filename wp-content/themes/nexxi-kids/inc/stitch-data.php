<?php
/** Store settings, URLs and product view-models shared by every Stitch screen. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Store contact settings (Customizer → NEXXI – Thông tin cửa hàng). */
function nexxi_kids_setting( $key ) {
	$defaults = array(
		'nexxi_kids_hotline'   => '0942 958 091',
		'nexxi_kids_email'     => get_option( 'admin_email' ),
		'nexxi_kids_address'   => '',
		'nexxi_kids_hours'     => '8h - 17h',
		'nexxi_kids_chat_url'  => 'https://zalo.me/0942958091',
		'nexxi_kids_map_embed' => '',
	);
	$legacy = array(
		'nexxi_kids_hotline'  => array( '1900 8888', '1900 6886', '19008888', '19006886' ),
		'nexxi_kids_hours'    => array( '8:00 – 21:00 hằng ngày', '8:00 - 21:00 hằng ngày', '8h-22h' ),
	);
	$value = trim( (string) get_theme_mod( $key, '' ) );
	if ( isset( $legacy[ $key ] ) && in_array( $value, $legacy[ $key ], true ) ) {
		$value = '';
	}
	return '' !== $value ? $value : ( isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' );
}

function nexxi_kids_customize_store( $customizer ) {
	$customizer->add_section( 'nexxi_kids_store', array( 'title' => __( 'NEXXI – Thông tin cửa hàng', 'nexxi-kids' ), 'priority' => 30 ) );
	$fields = array(
		'nexxi_kids_hotline'   => array( __( 'Hotline', 'nexxi-kids' ), 'text', 'sanitize_text_field' ),
		'nexxi_kids_email'     => array( __( 'Email liên hệ', 'nexxi-kids' ), 'email', 'sanitize_email' ),
		'nexxi_kids_address'   => array( __( 'Địa chỉ cửa hàng', 'nexxi-kids' ), 'textarea', 'sanitize_textarea_field' ),
		'nexxi_kids_hours'     => array( __( 'Giờ mở cửa', 'nexxi-kids' ), 'text', 'sanitize_text_field' ),
		'nexxi_kids_chat_url'  => array( __( 'Link chat tư vấn (Zalo / Messenger)', 'nexxi-kids' ), 'url', 'esc_url_raw' ),
		'nexxi_kids_map_embed' => array( __( 'Link nhúng Google Maps (src của iframe)', 'nexxi-kids' ), 'url', 'esc_url_raw' ),
	);
	foreach ( $fields as $key => $field ) {
		$customizer->add_setting( $key, array( 'default' => '', 'sanitize_callback' => $field[2] ) );
		$customizer->add_control( $key, array( 'label' => $field[0], 'section' => 'nexxi_kids_store', 'type' => $field[1] ) );
	}
}
add_action( 'customize_register', 'nexxi_kids_customize_store', 20 );

/** Permalink of a page by slug with a pretty-URL fallback. */
function nexxi_kids_page_url( $slug ) {
	$page = get_page_by_path( $slug );
	return $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
}

function nexxi_kids_shop_url() {
	return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/cua-hang/' );
}

function nexxi_kids_flash_url() {
	return add_query_arg( 'on_sale', '1', nexxi_kids_shop_url() );
}

function nexxi_kids_category_url( $slug ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	$link = $term ? get_term_link( $term ) : '';
	return $link && ! is_wp_error( $link ) ? $link : nexxi_kids_shop_url();
}

function nexxi_kids_tel( $phone ) {
	return 'tel:' . preg_replace( '~[^0-9+]~', '', (string) $phone );
}

/**
 * Flash Sale end as a UNIX timestamp.
 * Returns a future timestamp ONLY when Flash Sale is enabled, there is at least
 * one product on sale, and a future end time has been configured. Otherwise returns 0
 * so the countdown clock stays at 00:00:00.
 */
function nexxi_kids_flash_end() {
	if ( '0' === (string) get_option( 'nexxi_flash_sale_enabled', '1' ) ) {
		return 0;
	}
	$sale_ids = nexxi_kids_flash_sale_parent_ids();
	if ( empty( $sale_ids ) ) {
		return 0;
	}
	$raw = trim( (string) get_option( 'nexxi_flash_sale_end', '' ) );
	if ( '' === $raw ) {
		$raw = trim( (string) get_theme_mod( 'nexxi_kids_flash_sale_end', '' ) );
	}
	if ( '' !== $raw ) {
		try {
			$dt = new DateTimeImmutable( $raw, wp_timezone() );
			$ts = $dt->getTimestamp();
		} catch ( Exception $e ) {
			$ts = strtotime( $raw );
		}
		return ( $ts && $ts > time() ) ? (int) $ts : 0;
	}
	// Check if any sale product has a scheduled WooCommerce sale end date in the future.
	foreach ( $sale_ids as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) { continue; }
		$date_to = $p->get_date_on_sale_to();
		if ( $date_to && $date_to->getTimestamp() > time() ) {
			return (int) $date_to->getTimestamp();
		}
	}
	return 0;
}

function nexxi_kids_money( $amount ) {
	return number_format( (float) $amount, 0, ',', '.' ) . '₫';
}

/** Compact sold counter: 1200 → "1.2k". */
function nexxi_kids_compact_number( $number ) {
	$number = (int) $number;
	return $number >= 1000 ? rtrim( rtrim( number_format( $number / 1000, 1, '.', '' ), '0' ), '.' ) . 'k' : (string) $number;
}

/** First category name for labels. */
function nexxi_kids_product_category( WC_Product $product ) {
	$terms = get_the_terms( $product->get_id(), 'product_cat' );
	return $terms && ! is_wp_error( $terms ) ? $terms[0] : null;
}

/** Comma separated attribute names (non-variation attributes such as material). */
function nexxi_kids_product_attr_names( WC_Product $product, $slug ) {
	if ( ! class_exists( 'Nexxi_Kids_Catalog' ) ) { return array(); }
	return wp_list_pluck( Nexxi_Kids_Catalog::product_terms( $product, $slug ), 'name' );
}

/**
 * Ensure all variations of a variable product share the sale price when the shop
 * owner has set a sale price on one/some variations (e.g. via WooCommerce's
 * paginated variation editor). Runs with a per-request static guard.
 */
function nexxi_kids_normalize_variable_sale( WC_Product $product ) {
	static $checked = array();
	$pid = $product->get_id();
	if ( isset( $checked[ $pid ] ) || ! $product->is_type( 'variable' ) ) {
		return;
	}
	$checked[ $pid ] = true;

	$children = $product->get_children();
	if ( empty( $children ) ) {
		return;
	}

	$sale_by_reg = array();
	$missing     = array();
	foreach ( $children as $child_id ) {
		$reg  = (float) get_post_meta( $child_id, '_regular_price', true );
		$sale = get_post_meta( $child_id, '_sale_price', true );
		if ( $reg <= 0 ) {
			continue;
		}
		$reg_key = (string) $reg;
		if ( '' !== $sale && (float) $sale > 0 && (float) $sale < $reg ) {
			$s = (float) $sale;
			if ( ! isset( $sale_by_reg[ $reg_key ] ) || $s < $sale_by_reg[ $reg_key ] ) {
				$sale_by_reg[ $reg_key ] = $s;
			}
		} else {
			$missing[ $child_id ] = $reg_key;
		}
	}

	if ( empty( $sale_by_reg ) || empty( $missing ) ) {
		return;
	}

	$updated = false;
	foreach ( $missing as $child_id => $reg_key ) {
		if ( ! isset( $sale_by_reg[ $reg_key ] ) ) {
			continue;
		}
		$target_sale = (string) $sale_by_reg[ $reg_key ];
		update_post_meta( $child_id, '_sale_price', $target_sale );
		update_post_meta( $child_id, '_price', $target_sale );
		if ( function_exists( 'wc_get_product' ) ) {
			$var_obj = wc_get_product( $child_id );
			if ( $var_obj ) {
				$var_obj->set_sale_price( $target_sale );
				$var_obj->set_price( $target_sale );
				$var_obj->save();
			}
		}
		$updated = true;
	}

	if ( $updated ) {
		if ( class_exists( 'WC_Product_Variable' ) ) {
			WC_Product_Variable::sync( $pid );
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}
		delete_transient( 'wc_products_onsale' );
	}
}

/** Lowest / highest display prices (regular prices for strike-through), read live from variations. */
function nexxi_kids_price_range( WC_Product $product ) {
	if ( $product->is_type( 'variable' ) ) {
		nexxi_kids_normalize_variable_sale( $product );
		$variations = class_exists( 'Nexxi_Kids_Catalog' ) ? Nexxi_Kids_Catalog::variation_payload( $product ) : array();
		if ( $variations ) {
			$min     = null;
			$max     = null;
			$regular = 0.0;
			foreach ( $variations as $row ) {
				$p = (float) $row['price'];
				$r = (float) $row['regular'];
				if ( $p <= 0 ) {
					continue;
				}
				if ( null === $min || $p < $min ) {
					$min     = $p;
					$regular = $r > 0 ? $r : $p;
				} elseif ( $p === $min && $r > $regular ) {
					$regular = $r;
				}
				if ( null === $max || $p > $max ) {
					$max = $p;
				}
			}
			if ( null !== $min ) {
				return array( 'min' => (float) $min, 'max' => (float) $max, 'regular' => (float) $regular );
			}
		}
		$prices  = $product->get_variation_prices( true );
		$min     = $prices['price'] ? (float) min( $prices['price'] ) : 0.0;
		$max     = $prices['price'] ? (float) max( $prices['price'] ) : 0.0;
		$regular = $prices['regular_price'] ? (float) max( $prices['regular_price'] ) : 0.0;
		return array( 'min' => $min, 'max' => $max, 'regular' => $regular );
	}
	$price   = (float) wc_get_price_to_display( $product );
	$regular = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
	return array( 'min' => $price, 'max' => $price, 'regular' => $regular );
}

/** Largest discount percentage across the product (0 when not on sale). */
function nexxi_kids_discount_percent( WC_Product $product ) {
	$range = nexxi_kids_price_range( $product );
	$best  = 0;
	if ( $range['regular'] > 0 && $range['min'] > 0 && $range['min'] < $range['regular'] ) {
		$best = (int) round( ( 1 - $range['min'] / $range['regular'] ) * 100 );
	}
	if ( class_exists( 'Nexxi_Kids_Catalog' ) ) {
		$best = max( $best, (int) Nexxi_Kids_Catalog::discount_percent( $product ) );
		if ( $product->is_type( 'variable' ) ) {
			foreach ( Nexxi_Kids_Catalog::variation_payload( $product ) as $row ) {
				if ( $row['regular'] > 0 && $row['price'] > 0 && $row['price'] < $row['regular'] ) {
					$best = max( $best, (int) round( ( 1 - $row['price'] / $row['regular'] ) * 100 ) );
				}
			}
		}
	}
	return $best;
}

/**
 * View-model for product cards. All values are plain data; markup lives in
 * stitch-components.php so every card stays identical to the Stitch design.
 */
function nexxi_kids_card_data( WC_Product $product ) {
	$catalog    = class_exists( 'Nexxi_Kids_Catalog' );
	$category   = nexxi_kids_product_category( $product );
	$range      = nexxi_kids_price_range( $product );
	$discount   = nexxi_kids_discount_percent( $product );
	$on_sale    = ( $discount > 0 || $product->is_on_sale() ) && $range['regular'] > $range['min'];
	$sizes      = array();
	if ( $catalog ) {
		foreach ( Nexxi_Kids_Catalog::product_terms( $product, 'size' ) as $term ) {
			$sizes[] = array( 'slug' => $term->slug, 'label' => Nexxi_Kids_Catalog::size_number( $term->slug ) . 'Y', 'name' => $term->name );
		}
	}
	$image      = wp_get_attachment_image_url( $product->get_image_id(), 'large' );
	$sold       = (int) $product->get_total_sales();
	$left       = $catalog ? Nexxi_Kids_Catalog::stock_left( $product ) : null;
	$variations = $catalog ? Nexxi_Kids_Catalog::variation_payload( $product ) : array();
	return array(
		'id'           => $product->get_id(),
		'type'         => $product->get_type(),
		'name'         => $product->get_name(),
		'url'          => $product->get_permalink(),
		'image'        => $image ? $image : wc_placeholder_img_src( 'large' ),
		'category'     => $category ? $category->name : 'NEXXI Kids',
		'categorySlug' => $category ? $category->slug : '',
		'material'     => implode( ', ', array_slice( nexxi_kids_product_attr_names( $product, 'chat-lieu' ), 0, 1 ) ),
		'price'        => $range['min'] < $range['max'] ? nexxi_kids_money( $range['min'] ) . ' – ' . nexxi_kids_money( $range['max'] ) : nexxi_kids_money( $range['min'] ),
		'priceFrom'    => nexxi_kids_money( $range['min'] ),
		'regular'      => $on_sale ? nexxi_kids_money( $range['regular'] ) : '',
		'saving'       => $on_sale ? nexxi_kids_money( $range['regular'] - $range['min'] ) : '',
		'discount'     => $discount,
		'rating'       => (float) $product->get_average_rating(),
		'reviews'      => (int) $product->get_review_count(),
		'sold'         => $sold,
		'left'         => $left,
		'soldPercent'  => null !== $left && ( $sold + $left ) > 0 ? (int) round( $sold / ( $sold + $left ) * 100 ) : 0,
		'inStock'      => $product->is_in_stock(),
		'sizes'        => $sizes,
		'variations'   => array_map( static function ( $row ) { return array( 'id' => $row['id'], 'size' => $row['size'], 'color' => $row['color'], 'inStock' => $row['inStock'], 'any' => ! empty( $row['any'] ) ); }, $variations ),
		'isNew'        => $product->get_date_created() ? ( time() - (int) $product->get_date_created()->getTimestamp() ) < 30 * DAY_IN_SECONDS : false,
	);
}

/** Card payload for scripts (adds to cart, wishlist) embedded on each card root. */
function nexxi_kids_card_attrs( array $card ) {
	$payload = array( 'id' => $card['id'], 'type' => $card['type'], 'url' => $card['url'], 'name' => $card['name'], 'variations' => $card['variations'] );
	return 'data-nexxi-card="' . esc_attr( wp_json_encode( $payload ) ) . '" data-category="' . esc_attr( $card['categorySlug'] ) . '"';
}

/** Products for a home/flash rail. */
function nexxi_kids_query_products( $args ) {
	if ( ! function_exists( 'wc_get_products' ) ) { return array(); }
	$args = wp_parse_args( $args, array( 'status' => 'publish', 'limit' => 4, 'visibility' => 'catalog' ) );
	return wc_get_products( $args );
}

function nexxi_kids_sale_products( $limit = 4 ) {
	$ids = nexxi_kids_flash_sale_parent_ids();
	if ( ! $ids ) { return array(); }
	return nexxi_kids_query_products( array( 'include' => $ids, 'limit' => $limit, 'orderby' => 'popularity' ) );
}

/**
 * Resolve parent product IDs that are on sale AND belong to Flash Sale
 * (excludes regular-only discounted products where _nexxi_is_flash_sale === '0').
 */
function nexxi_kids_flash_sale_parent_ids() {
	$all = nexxi_kids_all_sale_parent_ids();
	$out = array();
	foreach ( $all as $id ) {
		if ( '0' !== (string) get_post_meta( $id, '_nexxi_is_flash_sale', true ) ) {
			$out[] = $id;
		}
	}
	return $out;
}

/**
 * Resolve all parent product IDs that currently have an active sale price on
 * either the product itself or any of its variations, even if WooCommerce's
 * wc_products_onsale transient has not refreshed yet.
 */
function nexxi_kids_all_sale_parent_ids() {
	global $wpdb;
	$ids    = function_exists( 'wc_get_product_ids_on_sale' ) ? wc_get_product_ids_on_sale() : array();
	$direct = $wpdb->get_col(
		"SELECT DISTINCT CASE WHEN p.post_parent > 0 THEN p.post_parent ELSE p.ID END
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sale_price'
		 WHERE p.post_type IN ('product', 'product_variation')
		   AND p.post_status = 'publish'
		   AND pm.meta_value != ''
		   AND CAST(pm.meta_value AS DECIMAL(12,2)) > 0"
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return nexxi_kids_sale_parent_ids( array_merge( (array) $ids, (array) $direct ) );
}

/** Map product/variation IDs to unique published parent product IDs. */
function nexxi_kids_sale_parent_ids( $ids ) {
	$out = array();
	foreach ( (array) $ids as $id ) {
		$id = absint( $id );
		if ( ! $id ) { continue; }
		$type = get_post_type( $id );
		if ( 'product' === $type ) {
			$out[ $id ] = $id;
		} elseif ( 'product_variation' === $type ) {
			$parent = (int) wp_get_post_parent_id( $id );
			if ( $parent && 'product' === get_post_type( $parent ) ) {
				$out[ $parent ] = $parent;
			}
		}
	}
	return array_values( $out );
}
