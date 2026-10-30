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
 * Inspect the configured Flash Sale timer state.
 *
 * @return array{enabled:bool,raw:string,ts:int,is_live:bool,is_expired:bool}
 */
function nexxi_kids_flash_timer_state() {
	if ( isset( $GLOBALS['nexxi_cached_timer_state'] ) && is_array( $GLOBALS['nexxi_cached_timer_state'] ) ) {
		return $GLOBALS['nexxi_cached_timer_state'];
	}
	$enabled = '0' !== (string) get_option( 'nexxi_flash_sale_enabled', '1' );
	$raw     = trim( (string) get_option( 'nexxi_flash_sale_end', '' ) );
	if ( '' === $raw ) {
		$raw = trim( (string) get_theme_mod( 'nexxi_kids_flash_sale_end', '' ) );
	}
	$ts = 0;
	if ( '' !== $raw ) {
		try {
			$dt = new DateTimeImmutable( $raw, wp_timezone() );
			$ts = $dt->getTimestamp();
		} catch ( Exception $e ) {
			$ts = (int) strtotime( $raw );
		}
	}
	$now        = time();
	$is_live    = $enabled && $ts > $now;
	$is_expired = ( '' !== $raw && $ts > 0 && $ts <= $now );
	$GLOBALS['nexxi_cached_timer_state'] = array(
		'enabled'    => $enabled,
		'raw'        => $raw,
		'ts'         => $ts,
		'is_live'    => $is_live,
		'is_expired' => $is_expired,
	);
	return $GLOBALS['nexxi_cached_timer_state'];
}

/**
 * Whether the Flash Sale countdown timer is currently active and in the future.
 */
function nexxi_kids_is_flash_sale_live() {
	$state = nexxi_kids_flash_timer_state();
	return (bool) $state['is_live'];
}

/**
 * Flash Sale end as a UNIX timestamp.
 * Returns a future timestamp ONLY when Flash Sale is enabled, a future end time
 * has been configured, and there is at least one product in the Flash Sale program.
 * Otherwise returns 0 so the countdown clock stays at 00:00:00.
 */
function nexxi_kids_flash_end() {
	$state = nexxi_kids_flash_timer_state();
	if ( ! $state['is_live'] ) {
		return 0;
	}
	$sale_ids = nexxi_kids_flash_sale_parent_ids( true );
	if ( empty( $sale_ids ) ) {
		return 0;
	}
	return (int) $state['ts'];
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

/** Reset per-request runtime caches for product prices and sale parent IDs after an admin update. */
function nexxi_kids_reset_sale_runtime_cache( $product_id = 0 ) {
	$pid = (int) $product_id;
	if ( $pid > 0 && isset( $GLOBALS['nexxi_price_cache'][ $pid ] ) ) {
		unset( $GLOBALS['nexxi_price_cache'][ $pid ] );
	} else {
		$GLOBALS['nexxi_price_cache'] = array();
	}
	$GLOBALS['nexxi_cached_timer_state'] = null;
	$GLOBALS['nexxi_cached_fs_ids']      = null;
	$GLOBALS['nexxi_cached_reg_ids']     = null;
	$GLOBALS['nexxi_cached_all_ids']     = null;
}

/** Lowest / highest display prices (regular prices for strike-through), with fast per-request cache. */
function nexxi_kids_price_range( WC_Product $product ) {
	if ( ! isset( $GLOBALS['nexxi_price_cache'] ) || ! is_array( $GLOBALS['nexxi_price_cache'] ) ) {
		$GLOBALS['nexxi_price_cache'] = array();
	}
	$pid = $product->get_id();
	if ( isset( $GLOBALS['nexxi_price_cache'][ $pid ] ) ) {
		return $GLOBALS['nexxi_price_cache'][ $pid ];
	}

	if ( $product->is_type( 'variable' ) ) {
		$prices = $product->get_variation_prices( true );
		if ( ! empty( $prices['price'] ) && is_array( $prices['price'] ) ) {
			$min     = null;
			$max     = null;
			$regular = 0.0;
			foreach ( $prices['price'] as $vid => $raw_p ) {
				$p = (float) $raw_p;
				$r = isset( $prices['regular_price'][ $vid ] ) ? (float) $prices['regular_price'][ $vid ] : 0.0;
				if ( $p <= 0 && $r > 0 ) {
					$p = $r;
				}
				if ( $p <= 0 ) {
					continue;
				}
				if ( $r <= 0 ) {
					$r = $p;
				}
				if ( null === $min || $p < $min ) {
					$min     = $p;
					$regular = $r;
				} elseif ( $p === $min && $r > $regular ) {
					$regular = $r;
				}
				if ( null === $max || $p > $max ) {
					$max = $p;
				}
			}
			if ( null !== $min && $min > 0 ) {
				$GLOBALS['nexxi_price_cache'][ $pid ] = array(
					'min'     => (float) $min,
					'max'     => (float) $max,
					'regular' => $regular > 0 ? (float) $regular : (float) $min,
				);
				return $GLOBALS['nexxi_price_cache'][ $pid ];
			}
		}
		$fast_var   = nexxi_kids_fast_card_variations( $product );
		$variations = isset( $fast_var['variations'] ) && is_array( $fast_var['variations'] ) ? $fast_var['variations'] : array();
		if ( $variations ) {
			$min     = null;
			$max     = null;
			$regular = 0.0;
			foreach ( $variations as $row ) {
				$p = isset( $row['price'] ) ? (float) $row['price'] : 0.0;
				$r = isset( $row['regular'] ) ? (float) $row['regular'] : 0.0;
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
				$GLOBALS['nexxi_price_cache'][ $pid ] = array( 'min' => (float) $min, 'max' => (float) $max, 'regular' => (float) $regular );
				return $GLOBALS['nexxi_price_cache'][ $pid ];
			}
		}
		// Final fallback: parent _regular_price / _price or Pancake catalog min_price if variations had no price meta yet
		$parent_reg = (float) get_post_meta( $pid, '_regular_price', true );
		$parent_p   = (float) get_post_meta( $pid, '_price', true );
		if ( $parent_p <= 0 && $parent_reg > 0 ) {
			$parent_p = $parent_reg;
		}
		if ( $parent_p > 0 ) {
			$GLOBALS['nexxi_price_cache'][ $pid ] = array(
				'min'     => $parent_p,
				'max'     => $parent_p,
				'regular' => $parent_reg > 0 ? $parent_reg : $parent_p,
			);
			return $GLOBALS['nexxi_price_cache'][ $pid ];
		}
		$GLOBALS['nexxi_price_cache'][ $pid ] = array( 'min' => 0.0, 'max' => 0.0, 'regular' => 0.0 );
		return $GLOBALS['nexxi_price_cache'][ $pid ];
	}
	$price   = (float) wc_get_price_to_display( $product );
	$regular = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
	if ( $price <= 0 ) {
		$price = (float) get_post_meta( $pid, '_price', true );
	}
	if ( $regular <= 0 ) {
		$regular = (float) get_post_meta( $pid, '_regular_price', true );
	}
	$GLOBALS['nexxi_price_cache'][ $pid ] = array( 'min' => $price, 'max' => $price, 'regular' => $regular > 0 ? $regular : $price );
	return $GLOBALS['nexxi_price_cache'][ $pid ];
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
			$fast_var = nexxi_kids_fast_card_variations( $product );
			$v_rows   = isset( $fast_var['variations'] ) && is_array( $fast_var['variations'] ) ? $fast_var['variations'] : array();
			foreach ( $v_rows as $row ) {
				$r_reg = isset( $row['regular'] ) ? (float) $row['regular'] : 0.0;
				$r_pri = isset( $row['price'] ) ? (float) $row['price'] : 0.0;
				if ( $r_reg > 0 && $r_pri > 0 && $r_pri < $r_reg ) {
					$best = max( $best, (int) round( ( 1 - $r_pri / $r_reg ) * 100 ) );
				}
			}
		}
	}
	return $best;
}

/**
 * Get the active discount state of a product for a specific program or combined frontend display:
 *  - 'regular_admin' : Chỉ lấy chương trình Giảm Giá Bán Sản Phẩm (🏷️ Giảm Giá SP) cho bảng Admin
 *  - 'flash_admin'   : Chỉ lấy chương trình Flash Sale (⚡ Flash Sale) cho bảng Admin
 *  - 'combined'      : Cộng dồn cả 2 chương trình (nếu sản phẩm được bật ở cả 2 bên) cho hiển thị ngoài Web
 */
function nexxi_kids_get_program_sale( WC_Product $product, $program = 'combined' ) {
	$pid   = $product->get_id();
	$range = nexxi_kids_price_range( $product );
	$reg   = (float) $range['regular'];
	if ( $reg <= 0 ) {
		$reg = (float) $range['min'];
	}

	// 1. Check Regular Product Discount (🏷️ Giảm Giá SP)
	$has_prod   = '1' === (string) get_post_meta( $pid, '_nexxi_has_prod_sale', true );
	$prod_price = $has_prod ? (float) get_post_meta( $pid, '_nexxi_prod_sale_price', true ) : 0.0;
	$prod_pct   = $has_prod ? (int) get_post_meta( $pid, '_nexxi_prod_sale_pct', true ) : 0;
	if ( $has_prod && $prod_price <= 0 && $prod_pct > 0 && $prod_pct <= 95 && $reg > 0 ) {
		$prod_price = round( $reg * ( 100 - $prod_pct ) / 100, -3 );
		if ( $prod_price <= 0 || $prod_price >= $reg ) {
			$prod_price = max( 1000, round( $reg * ( 100 - $prod_pct ) / 100 ) );
		}
	}
	if ( $has_prod && $prod_price > 0 && $prod_price < $reg ) {
		if ( $prod_pct <= 0 ) {
			$prod_pct = (int) round( ( 1 - $prod_price / $reg ) * 100 );
		}
	} else {
		$has_prod   = false;
		$prod_price = 0.0;
		$prod_pct   = 0;
	}

	// 2. Check Flash Sale Discount (⚡ Flash Sale) — ONLY active when explicitly enabled on the product (_nexxi_is_flash_sale === '1')
	$is_fs     = (string) get_post_meta( $pid, '_nexxi_is_flash_sale', true );
	$has_flash = ( '1' === $is_fs );
	$fs_price  = $has_flash ? (float) get_post_meta( $pid, '_nexxi_fs_sale_price', true ) : 0.0;
	$fs_pct    = $has_flash ? (int) get_post_meta( $pid, '_nexxi_fs_sale_pct', true ) : 0;
	if ( $has_flash && $fs_price <= 0 && $fs_pct > 0 && $fs_pct <= 95 && $reg > 0 ) {
		$fs_price = round( $reg * ( 100 - $fs_pct ) / 100, -3 );
		if ( $fs_price <= 0 || $fs_price >= $reg ) {
			$fs_price = max( 1000, round( $reg * ( 100 - $fs_pct ) / 100 ) );
		}
	}
	if ( $has_flash && $fs_price > 0 && $fs_price < $reg ) {
		if ( $fs_pct <= 0 ) {
			$fs_pct = (int) round( ( 1 - $fs_price / $reg ) * 100 );
		}
	} else {
		$has_flash = false;
		$fs_price  = 0.0;
		$fs_pct    = 0;
	}

	// Admin-specific isolated views
	if ( 'regular_admin' === $program ) {
		$fs_active_now = $has_flash && nexxi_kids_is_flash_sale_live();
		return array(
			'on_sale'       => $has_prod,
			'regular'       => $reg,
			'sale'          => $has_prod ? $prod_price : $reg,
			'pct'           => $has_prod ? $prod_pct : 0,
			'other_active'  => $fs_active_now,
			'other_pct'     => $fs_pct,
			'other_sale'    => $fs_price,
			'combined_sale' => ( $has_prod && $fs_active_now ) ? max( 1000, $reg - ( $reg - $prod_price ) - ( $reg - $fs_price ) ) : ( $has_prod ? $prod_price : $reg ),
			'combined_pct'  => ( $has_prod && $fs_active_now ) ? min( 95, $prod_pct + $fs_pct ) : $prod_pct,
		);
	}
	if ( 'flash_admin' === $program ) {
		return array(
			'on_sale'       => $has_flash,
			'regular'       => $reg,
			'sale'          => $has_flash ? $fs_price : $reg,
			'pct'           => $has_flash ? $fs_pct : 0,
			'other_active'  => $has_prod,
			'other_pct'     => $prod_pct,
			'other_sale'    => $prod_price,
			'combined_sale' => ( $has_prod && $has_flash ) ? max( 1000, $reg - ( $reg - $prod_price ) - ( $reg - $fs_price ) ) : ( $has_flash ? $fs_price : $reg ),
			'combined_pct'  => ( $has_prod && $has_flash ) ? min( 95, $prod_pct + $fs_pct ) : $fs_pct,
		);
	}

	// Frontend display ('combined'): Flash Sale discount only applies when the Flash Sale timer is actively running!
	if ( ! nexxi_kids_is_flash_sale_live() ) {
		$has_flash = false;
		$fs_price  = 0.0;
		$fs_pct    = 0;
	}

	// Cumulative / stacked when BOTH programs are active!
	if ( $has_prod && $has_flash ) {
		$disc_prod     = max( 0, $reg - $prod_price );
		$disc_fs       = max( 0, $reg - $fs_price );
		$combined_sale = max( 1000, $reg - $disc_prod - $disc_fs );
		$combined_pct  = min( 95, $prod_pct + $fs_pct );
		return array(
			'on_sale'       => true,
			'regular'       => $reg,
			'sale'          => $combined_sale,
			'pct'           => $combined_pct,
			'stacked'       => true,
			'prod_on_sale'  => true,
			'prod_sale'     => $prod_price,
			'prod_pct'      => $prod_pct,
			'flash_on_sale' => true,
			'flash_sale'    => $fs_price,
			'flash_pct'     => $fs_pct,
		);
	}
	if ( $has_flash ) {
		return array(
			'on_sale'       => true,
			'regular'       => $reg,
			'sale'          => $fs_price,
			'pct'           => $fs_pct,
			'stacked'       => false,
			'prod_on_sale'  => false,
			'prod_sale'     => $reg,
			'prod_pct'      => 0,
			'flash_on_sale' => true,
			'flash_sale'    => $fs_price,
			'flash_pct'     => $fs_pct,
		);
	}
	if ( $has_prod ) {
		return array(
			'on_sale'       => true,
			'regular'       => $reg,
			'sale'          => $prod_price,
			'pct'           => $prod_pct,
			'stacked'       => false,
			'prod_on_sale'  => true,
			'prod_sale'     => $prod_price,
			'prod_pct'      => $prod_pct,
			'flash_on_sale' => false,
			'flash_sale'    => $reg,
			'flash_pct'     => 0,
		);
	}
	return array(
		'on_sale'       => false,
		'regular'       => $reg,
		'sale'          => $reg,
		'pct'           => 0,
		'stacked'       => false,
		'prod_on_sale'  => false,
		'prod_sale'     => $reg,
		'prod_pct'      => 0,
		'flash_on_sale' => false,
		'flash_sale'    => $reg,
		'flash_pct'     => 0,
	);
}

/**
 * Compute the exact effective selling price of a Cart item (or WC_Product / WC_Product_Variation)
 * AFTER applying Product Discount (🏷️ Giảm Giá SP) + Flash Sale (⚡ Flash Sale, if live).
 *
 * Guarantees that Cart, Checkout, and Combo Promotion ALWAYS calculate on top of the
 * post-sale price (giá sau giảm của sản phẩm + flash sale), even if a variation's raw _price
 * in wp_postmeta was not yet synced.
 *
 * @param array|WC_Product $cart_item_or_product Cart item array or WC_Product instance.
 * @return array{
 *   regular_price:float,
 *   effective_price:float,
 *   on_sale:bool,
 *   pct:int,
 *   has_prod_sale:bool,
 *   prod_pct:int,
 *   prod_sale:float,
 *   has_flash_sale:bool,
 *   flash_pct:int,
 *   flash_sale:float
 * }
 */
function nexxi_kids_get_cart_item_effective_prices( $cart_item_or_product ) {
	$product   = null;
	$parent_id = 0;
	if ( is_array( $cart_item_or_product ) ) {
		$product   = isset( $cart_item_or_product['data'] ) && $cart_item_or_product['data'] instanceof WC_Product ? $cart_item_or_product['data'] : null;
		$parent_id = ! empty( $cart_item_or_product['product_id'] ) ? (int) $cart_item_or_product['product_id'] : 0;
	} elseif ( $cart_item_or_product instanceof WC_Product ) {
		$product = $cart_item_or_product;
	}

	if ( ! $product ) {
		return array(
			'regular_price'   => 0.0,
			'effective_price' => 0.0,
			'on_sale'         => false,
			'pct'             => 0,
			'has_prod_sale'   => false,
			'prod_pct'        => 0,
			'prod_sale'       => 0.0,
			'has_flash_sale'  => false,
			'flash_pct'       => 0,
			'flash_sale'      => 0.0,
		);
	}

	if ( $parent_id <= 0 ) {
		$parent_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
	}
	$parent_product = ( $parent_id > 0 && $parent_id !== (int) $product->get_id() )
		? ( function_exists( 'wc_get_product' ) ? wc_get_product( $parent_id ) : null )
		: $product;
	if ( ! $parent_product ) {
		$parent_product = $product;
	}

	$prog = nexxi_kids_get_program_sale( $parent_product, 'combined' );

	$var_id   = (int) $product->get_id();
	$var_reg  = (float) get_post_meta( $var_id, '_regular_price', true );
	if ( $var_reg <= 0 ) {
		$var_reg = (float) $product->get_regular_price();
	}
	$var_sale = (float) get_post_meta( $var_id, '_sale_price', true );
	if ( $var_sale <= 0 ) {
		$var_sale = (float) $product->get_sale_price();
	}

	$reg_price       = (float) $prog['regular'] > 0 ? (float) $prog['regular'] : $var_reg;
	$effective_price = (float) $prog['sale'];
	$on_sale         = ! empty( $prog['on_sale'] ) && $effective_price > 0 && $effective_price < $reg_price;
	$pct             = $on_sale ? (int) $prog['pct'] : 0;

	// Fallback if product has direct variation sale_price in WooCommerce without program meta
	if ( ! $on_sale && $var_sale > 0 && $var_reg > 0 && $var_sale < $var_reg ) {
		$reg_price       = $var_reg;
		$effective_price = $var_sale;
		$on_sale         = true;
		$pct             = (int) round( ( 1 - $var_sale / $var_reg ) * 100 );
	}

	if ( $effective_price <= 0 ) {
		$raw_p = (float) $product->get_price();
		if ( $raw_p <= 0 ) {
			$raw_p = (float) get_post_meta( $var_id, '_price', true );
		}
		$effective_price = $raw_p > 0 ? $raw_p : $reg_price;
	}
	if ( $reg_price <= 0 || $reg_price < $effective_price ) {
		$reg_price = $effective_price;
	}

	return array(
		'regular_price'   => max( 0.0, (float) $reg_price ),
		'effective_price' => max( 0.0, (float) $effective_price ),
		'on_sale'         => (bool) ( $effective_price > 0 && $effective_price < $reg_price ),
		'pct'             => (int) $pct,
		'has_prod_sale'   => ! empty( $prog['prod_on_sale'] ),
		'prod_pct'        => isset( $prog['prod_pct'] ) ? (int) $prog['prod_pct'] : 0,
		'prod_sale'       => isset( $prog['prod_sale'] ) ? (float) $prog['prod_sale'] : (float) $reg_price,
		'has_flash_sale'  => ! empty( $prog['flash_on_sale'] ),
		'flash_pct'       => isset( $prog['flash_pct'] ) ? (int) $prog['flash_pct'] : 0,
		'flash_sale'      => isset( $prog['flash_sale'] ) ? (float) $prog['flash_sale'] : (float) $reg_price,
	);
}

/**
 * Sync the active post-sale price (after Giảm Giá SP + Flash Sale) onto the WC_Product in the cart item.
 */
function nexxi_kids_sync_cart_item_program_price( $cart_item ) {
	if ( empty( $cart_item['data'] ) || ! ( $cart_item['data'] instanceof WC_Product ) ) {
		return $cart_item;
	}
	$eff = nexxi_kids_get_cart_item_effective_prices( $cart_item );
	if ( $eff['effective_price'] > 0 ) {
		$cart_item['data']->set_regular_price( (string) round( $eff['regular_price'] ) );
		if ( $eff['on_sale'] ) {
			$cart_item['data']->set_sale_price( (string) round( $eff['effective_price'] ) );
		} else {
			$cart_item['data']->set_sale_price( '' );
		}
		$cart_item['data']->set_price( (string) round( $eff['effective_price'] ) );
	}
	return $cart_item;
}
add_filter( 'woocommerce_add_cart_item', 'nexxi_kids_sync_cart_item_program_price', 20, 1 );
add_filter( 'woocommerce_get_cart_item_from_session', 'nexxi_kids_sync_cart_item_program_price', 20, 1 );

add_action(
	'woocommerce_before_calculate_totals',
	static function ( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $cart || ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			nexxi_kids_sync_cart_item_program_price( $cart_item );
		}
	},
	10,
	1
);

/**
 * Batch-prime postmeta, taxonomy terms, variation postmeta, and featured image attachments
 * for a list of WC_Product objects in 1 batch so rendering 25-50 product cards executes 0 N+1 SQL queries.
 */
function nexxi_kids_prime_products_cache( array $products ) {
	if ( empty( $products ) ) {
		return;
	}
	$pids         = array();
	$child_ids    = array();
	$thumb_ids    = array();
	foreach ( $products as $p ) {
		if ( ! $p instanceof WC_Product ) {
			continue;
		}
		$pid          = (int) $p->get_id();
		$pids[ $pid ] = $pid;
		$tid          = (int) $p->get_image_id();
		if ( $tid > 0 ) {
			$thumb_ids[ $tid ] = $tid;
		}
		if ( $p->is_type( 'variable' ) ) {
			foreach ( (array) $p->get_children() as $cid ) {
				$cid = absint( $cid );
				if ( $cid > 0 ) {
					$child_ids[ $cid ] = $cid;
				}
			}
		}
	}
	if ( ! empty( $pids ) ) {
		update_meta_cache( 'post', array_values( $pids ) );
		update_object_term_cache( array_values( $pids ), 'product' );
	}
	if ( ! empty( $child_ids ) ) {
		update_meta_cache( 'post', array_values( $child_ids ) );
	}
	if ( ! empty( $thumb_ids ) ) {
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_values( $thumb_ids ), false, true );
		} else {
			update_meta_cache( 'post', array_values( $thumb_ids ) );
		}
	}
}

/**
 * Ultra-fast variation & stock summary for storefront product cards (0 variation image lookups, 0 N+1 queries).
 *
 * @return array{variations:array,stock_left:int|null,sizes:array}
 */
function nexxi_kids_fast_card_variations( WC_Product $product ) {
	static $cache = array();
	$pid          = $product->get_id();
	if ( isset( $cache[ $pid ] ) ) {
		return $cache[ $pid ];
	}

	if ( ! $product->is_type( 'variable' ) ) {
		$left = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
		$sizes = array();
		$terms = get_the_terms( $pid, 'pa_size' );
		if ( is_array( $terms ) ) {
			usort( $terms, static function ( $a, $b ) {
				$na = preg_match( '~(\d+)~', (string) $a->slug, $ma ) ? (int) $ma[1] : 0;
				$nb = preg_match( '~(\d+)~', (string) $b->slug, $mb ) ? (int) $mb[1] : 0;
				return $na <=> $nb;
			} );
			foreach ( $terms as $t ) {
				$num = preg_match( '~(\d+)~', (string) $t->slug, $m ) ? (int) $m[1] : 0;
				$sizes[] = array( 'slug' => $t->slug, 'label' => $num . 'Y', 'name' => $t->name );
			}
		}
		$cache[ $pid ] = array( 'variations' => array(), 'stock_left' => $left, 'sizes' => $sizes );
		return $cache[ $pid ];
	}

	$children = array_values( array_filter( array_map( 'absint', (array) $product->get_children() ) ) );
	if ( empty( $children ) ) {
		$cache[ $pid ] = array( 'variations' => array(), 'stock_left' => null, 'sizes' => array() );
		return $cache[ $pid ];
	}

	update_meta_cache( 'post', $children );

	// Identify size and color attribute keys & options without wc_get_product_terms SQL queries
	$defs  = array();
	$taken = array();
	$sizes = array();
	foreach ( $product->get_attributes() as $attribute ) {
		if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
			continue;
		}
		$name     = $attribute->get_name();
		$label    = wc_attribute_label( $name, $product );
		$haystack = '-' . sanitize_title( remove_accents( $name . ' ' . $label ) ) . '-';
		$role     = '';
		if ( preg_match( '~(size|kich-co|kich-thuoc|-co-)~', $haystack ) ) {
			$role = 'size';
		} elseif ( preg_match( '~(mau|color|colour)~', $haystack ) ) {
			$role = 'color';
		}
		if ( $role && isset( $taken[ $role ] ) ) {
			$role = '';
		}
		if ( $role ) {
			$taken[ $role ] = true;
		}
		$is_tax = $attribute->is_taxonomy();
		$defs[] = array(
			'key'      => sanitize_title( $name ),
			'role'     => $role,
			'taxonomy' => $is_tax,
		);
		if ( 'size' === $role ) {
			if ( $is_tax ) {
				$terms = get_the_terms( $pid, $name );
				if ( is_array( $terms ) ) {
					foreach ( $terms as $t ) {
						$num     = preg_match( '~(\d+)~', (string) $t->slug, $m ) ? (int) $m[1] : 0;
						$sizes[] = array( 'slug' => $t->slug, 'label' => $num . 'Y', 'name' => $t->name, '_n' => $num );
					}
				}
			} else {
				foreach ( $attribute->get_options() as $opt ) {
					$slug    = sanitize_title( (string) $opt );
					$num     = preg_match( '~(\d+)~', (string) $opt, $m ) ? (int) $m[1] : 0;
					$sizes[] = array( 'slug' => $slug, 'label' => $num . 'Y', 'name' => (string) $opt, '_n' => $num );
				}
			}
			if ( ! empty( $sizes ) ) {
				usort( $sizes, static function ( $a, $b ) { return $a['_n'] <=> $b['_n']; } );
				foreach ( $sizes as &$s_item ) {
					unset( $s_item['_n'] );
				}
				unset( $s_item );
			}
		}
	}

	$variations = array();
	$total_left = null;

	foreach ( $children as $child_id ) {
		$price   = (float) get_post_meta( $child_id, '_price', true );
		$regular = (float) get_post_meta( $child_id, '_regular_price', true );
		if ( $price <= 0 && $regular > 0 ) {
			$price = $regular;
		}
		if ( $price <= 0 ) {
			continue;
		}
		$roles = array( 'size' => '', 'color' => '' );
		$any   = false;
		foreach ( $defs as $def ) {
			$meta_key = 'attribute_' . $def['key'];
			$raw      = (string) get_post_meta( $child_id, $meta_key, true );
			if ( '' === $raw && 0 !== strpos( $def['key'], 'pa_' ) ) {
				$raw = (string) get_post_meta( $child_id, 'attribute_pa_' . $def['key'], true );
			}
			$value = '' === $raw ? '' : ( $def['taxonomy'] ? $raw : sanitize_title( $raw ) );
			if ( '' === $value ) {
				$any = true;
			}
			if ( $def['role'] ) {
				$roles[ $def['role'] ] = $value;
			}
		}

		$manage_stock = 'yes' === (string) get_post_meta( $child_id, '_manage_stock', true );
		$stock_qty    = $manage_stock ? (int) get_post_meta( $child_id, '_stock', true ) : null;
		$stock_status = (string) get_post_meta( $child_id, '_stock_status', true );
		$in_stock     = $manage_stock ? ( $stock_qty > 0 || 'notify' === get_post_meta( $child_id, '_backorders', true ) || 'yes' === get_post_meta( $child_id, '_backorders', true ) ) : ( 'outofstock' !== $stock_status );

		if ( null !== $stock_qty ) {
			$total_left = (int) $total_left + max( 0, $stock_qty );
		}

		$variations[] = array(
			'id'      => $child_id,
			'size'    => $roles['size'],
			'color'   => $roles['color'],
			'price'   => $price,
			'regular' => $regular > 0 ? $regular : $price,
			'inStock' => $in_stock,
			'any'     => $any,
		);
	}

	$cache[ $pid ] = array(
		'variations' => $variations,
		'stock_left' => $total_left,
		'sizes'      => $sizes,
	);
	return $cache[ $pid ];
}

/**
 * View-model for product cards. All values are plain data; markup lives in
 * stitch-components.php so every card stays identical to the Stitch design.
 */
function nexxi_kids_card_data( WC_Product $product, $context = 'regular' ) {
	$category   = nexxi_kids_product_category( $product );
	$prog       = nexxi_kids_get_program_sale( $product, 'combined' );
	$on_sale    = $prog['on_sale'];
	$discount   = $prog['pct'];
	$sale_price = $prog['sale'];
	$reg_price  = $prog['regular'];
	$fast_var   = nexxi_kids_fast_card_variations( $product );
	$sizes      = $fast_var['sizes'];
	// Thẻ SP hiển thị ô vuông ~250-360px: dùng cỡ woocommerce_thumbnail (480px, cắt vuông) thay vì 'large'/ảnh gốc.
	$image      = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
	$sold       = (int) $product->get_total_sales();
	$left       = $fast_var['stock_left'];
	$variations = $fast_var['variations'];
	if ( $sale_price > 0 && ! empty( $variations ) ) {
		foreach ( $variations as &$v_row ) {
			$v_row['regular'] = (float) $reg_price;
			$v_row['price']   = (float) $sale_price;
		}
		unset( $v_row );
	}
	$url        = 'flash' === $context ? add_query_arg( 'flash_sale', '1', $product->get_permalink() ) : $product->get_permalink();
	$mat_terms  = get_the_terms( $product->get_id(), 'pa_chat-lieu' );
	$material   = ( is_array( $mat_terms ) && ! empty( $mat_terms[0]->name ) ) ? $mat_terms[0]->name : '';
	return array(
		'id'           => $product->get_id(),
		'type'         => $product->get_type(),
		'name'         => $product->get_name(),
		'url'          => $url,
		'image'        => $image ? $image : wc_placeholder_img_src( 'large' ),
		'category'     => $category ? $category->name : 'NEXXI Kids',
		'categorySlug' => $category ? $category->slug : '',
		'material'     => $material,
		'price'        => nexxi_kids_money( $sale_price ),
		'priceFrom'    => nexxi_kids_money( $sale_price ),
		'regular'      => $on_sale ? nexxi_kids_money( $reg_price ) : '',
		'saving'       => $on_sale ? nexxi_kids_money( $reg_price - $sale_price ) : '',
		'discount'     => $discount,
		'rating'       => (float) $product->get_average_rating(),
		'reviews'      => (int) $product->get_review_count(),
		'sold'         => $sold,
		'left'         => $left,
		'soldPercent'  => null !== $left && ( $sold + $left ) > 0 ? (int) round( $sold / ( $sold + $left ) * 100 ) : 0,
		'inStock'      => $product->is_in_stock(),
		'sizes'        => $sizes,
		'variations'   => $variations,
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
	$args     = wp_parse_args( $args, array( 'status' => 'publish', 'limit' => 4, 'visibility' => 'catalog' ) );
	$products = wc_get_products( $args );
	if ( is_array( $products ) && ! empty( $products ) ) {
		nexxi_kids_prime_products_cache( $products );
	}
	return $products;
}

function nexxi_kids_sale_products( $limit = 4 ) {
	$ids = nexxi_kids_flash_sale_parent_ids();
	if ( ! $ids ) { return array(); }
	return nexxi_kids_query_products( array( 'include' => $ids, 'limit' => $limit, 'orderby' => 'popularity' ) );
}

/**
 * Resolve parent product IDs that belong to the Flash Sale program.
 *
 * @param bool $include_inactive_timer When false (default, used on Frontend), returns empty array if Flash Sale timer is not running.
 */
function nexxi_kids_flash_sale_parent_ids( $include_inactive_timer = false ) {
	if ( ! $include_inactive_timer && ! nexxi_kids_is_flash_sale_live() ) {
		return array();
	}
	if ( isset( $GLOBALS['nexxi_cached_fs_ids'] ) && is_array( $GLOBALS['nexxi_cached_fs_ids'] ) ) {
		return $GLOBALS['nexxi_cached_fs_ids'];
	}
	global $wpdb;
	$fs_meta_ids = $wpdb->get_col(
		"SELECT p.ID FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_nexxi_is_flash_sale'
		 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pm.meta_value = '1'"
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$GLOBALS['nexxi_cached_fs_ids'] = array_values( array_unique( array_filter( array_map( 'absint', (array) $fs_meta_ids ) ) ) );
	return $GLOBALS['nexxi_cached_fs_ids'];
}

/**
 * Resolve parent product IDs that belong to the Regular Product Discount program (🏷️ Giảm Giá SP).
 */
function nexxi_kids_regular_sale_parent_ids() {
	if ( isset( $GLOBALS['nexxi_cached_reg_ids'] ) && is_array( $GLOBALS['nexxi_cached_reg_ids'] ) ) {
		return $GLOBALS['nexxi_cached_reg_ids'];
	}
	global $wpdb;
	$meta_ids = $wpdb->get_col(
		"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_nexxi_has_prod_sale' AND pm.meta_value = '1'
		 LEFT JOIN {$wpdb->postmeta} pm_sp ON p.ID = pm_sp.post_id AND pm_sp.meta_key = '_nexxi_prod_sale_price'
		 LEFT JOIN {$wpdb->postmeta} pm_pct ON p.ID = pm_pct.post_id AND pm_pct.meta_key = '_nexxi_prod_sale_pct'
		 WHERE p.post_type = 'product'
		   AND p.post_status = 'publish'
		   AND ( CAST(COALESCE(pm_sp.meta_value, '0') AS DECIMAL(12,2)) > 0 OR CAST(COALESCE(pm_pct.meta_value, '0') AS UNSIGNED) > 0 )"
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$GLOBALS['nexxi_cached_reg_ids'] = array_values( array_unique( array_filter( array_map( 'absint', (array) $meta_ids ) ) ) );
	return $GLOBALS['nexxi_cached_reg_ids'];
}

/**
 * Resolve all parent product IDs that currently have an active sale price on
 * either the product itself or any of its variations.
 */
function nexxi_kids_all_sale_parent_ids() {
	if ( isset( $GLOBALS['nexxi_cached_all_ids'] ) && is_array( $GLOBALS['nexxi_cached_all_ids'] ) ) {
		return $GLOBALS['nexxi_cached_all_ids'];
	}
	$fs_ids  = nexxi_kids_flash_sale_parent_ids( true );
	$reg_ids = nexxi_kids_regular_sale_parent_ids();
	$wc_ids  = function_exists( 'wc_get_product_ids_on_sale' ) ? wc_get_product_ids_on_sale() : array();
	$GLOBALS['nexxi_cached_all_ids'] = nexxi_kids_sale_parent_ids( array_merge( (array) $fs_ids, (array) $reg_ids, (array) $wc_ids ) );
	return $GLOBALS['nexxi_cached_all_ids'];
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
