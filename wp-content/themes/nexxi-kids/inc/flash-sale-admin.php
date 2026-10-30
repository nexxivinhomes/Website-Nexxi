<?php
/**
 * NEXXI Flash Sale & Product Discount Manager (WP Admin).
 *
 * Solves two problems for the shop owner:
 *  1. Easy visual setup of the Flash Sale countdown timer (00:00:00 when inactive).
 *  2. 1-click discount / sale price setup for Variable Products (which otherwise
 *     hide sale price fields inside dozens of individual size/color variations).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Register Flash Sale under the unified "Khuyến mãi shop" menu + Products submenu. */
function nexxi_kids_flash_sale_admin_menu() {
	if ( ! class_exists( 'WooCommerce' ) ) { return; }
	add_submenu_page(
		'nexxi-bulk-discount',
		'NEXXI Flash Sale & Giảm Giá',
		'⚡ Flash Sale & Sale',
		'manage_woocommerce',
		'nexxi-flash-sale',
		'nexxi_kids_flash_sale_admin_page'
	);
	add_submenu_page(
		'edit.php?post_type=product',
		'NEXXI Flash Sale & Giảm Giá',
		'⚡ Flash Sale & Giảm Giá',
		'manage_woocommerce',
		'nexxi-flash-sale',
		'nexxi_kids_flash_sale_admin_page'
	);
}
add_action( 'admin_menu', 'nexxi_kids_flash_sale_admin_menu', 21 );

/**
 * Apply a discount (% or fixed sale price or amount_off or clear) to a product
 * for a specific program:
 *  - $flash_flag = '1' or 'flash'   -> Chương trình Flash Sale (⚡ Flash Sale)
 *  - $flash_flag = '0' or 'regular' -> Chương trình Giảm Giá Bán Sản Phẩm (🏷️ Giảm Giá SP)
 */
function nexxi_kids_apply_product_discount( $product_id, $mode, $amount = 0, $regular_override = 0, $flash_flag = '1' ) {
	$pid = absint( $product_id );
	if ( ! $pid ) { return false; }
	if ( function_exists( 'nexxi_kids_reset_sale_runtime_cache' ) ) {
		nexxi_kids_reset_sale_runtime_cache( $pid );
	}
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
	if ( ! $product ) { return false; }

	$is_flash = ( '1' === (string) $flash_flag || 'flash' === (string) $flash_flag );

	// Determine base regular price (and optionally update it across variations)
	$range = nexxi_kids_price_range( $product );
	$reg   = (float) $range['regular'];
	if ( $reg <= 0 ) {
		$reg = (float) $range['min'];
	}
	if ( $reg <= 0 ) {
		$reg = (float) get_post_meta( $pid, '_regular_price', true );
	}
	if ( $reg <= 0 ) {
		$reg = (float) get_post_meta( $pid, '_price', true );
	}
	if ( $regular_override > 0 ) {
		$reg = (float) $regular_override;
	}

	// Calculate target sale price for this program
	$target_sale = 0.0;
	if ( 'clear' !== $mode && $amount > 0 && $reg > 0 ) {
		if ( 'percent' === $mode ) {
			$pct         = min( 95, max( 1, (float) $amount ) );
			$target_sale = round( $reg * ( 100 - $pct ) / 100, -3 ); // Round to nearest 1.000₫
			if ( $target_sale <= 0 || $target_sale >= $reg ) {
				$target_sale = max( 1000, round( $reg * ( 100 - $pct ) / 100 ) );
			}
		} elseif ( 'amount_off' === $mode ) {
			$target_sale = max( 1000, $reg - (float) $amount );
		} else {
			$target_sale = (float) $amount;
		}
		if ( $target_sale >= $reg ) {
			$target_sale = 0.0;
		}
	}

	// Save program-specific meta without overwriting the other program!
	if ( $is_flash ) {
		if ( $target_sale > 0 ) {
			$applied_pct = ( 'percent' === $mode && $amount >= 1 && $amount <= 95 )
				? (int) round( (float) $amount )
				: (int) round( ( 1 - $target_sale / $reg ) * 100 );
			update_post_meta( $pid, '_nexxi_is_flash_sale', '1' );
			update_post_meta( $pid, '_nexxi_fs_sale_price', (string) round( $target_sale ) );
			update_post_meta( $pid, '_nexxi_fs_sale_pct', (string) $applied_pct );
		} else {
			update_post_meta( $pid, '_nexxi_is_flash_sale', '0' );
			delete_post_meta( $pid, '_nexxi_fs_sale_price' );
			delete_post_meta( $pid, '_nexxi_fs_sale_pct' );
		}
	} else {
		// Regular Product Discount program (🏷️ Giảm Giá SP)
		if ( $target_sale > 0 ) {
			$applied_pct = ( 'percent' === $mode && $amount >= 1 && $amount <= 95 )
				? (int) round( (float) $amount )
				: (int) round( ( 1 - $target_sale / $reg ) * 100 );
			update_post_meta( $pid, '_nexxi_has_prod_sale', '1' );
			update_post_meta( $pid, '_nexxi_prod_sale_price', (string) round( $target_sale ) );
			update_post_meta( $pid, '_nexxi_prod_sale_pct', (string) $applied_pct );
		} else {
			update_post_meta( $pid, '_nexxi_has_prod_sale', '0' );
			delete_post_meta( $pid, '_nexxi_prod_sale_price' );
			delete_post_meta( $pid, '_nexxi_prod_sale_pct' );
		}
	}

	if ( function_exists( 'nexxi_kids_reset_sale_runtime_cache' ) ) {
		nexxi_kids_reset_sale_runtime_cache( $pid );
	}

	// Determine effective WooCommerce _sale_price to sync onto variations (cumulative/stacked if both programs are active)
	$combined     = nexxi_kids_get_program_sale( $product, 'combined' );
	$wc_sale      = $combined['on_sale'] ? (float) $combined['sale'] : 0.0;
	$combined_pct = ! empty( $combined['pct'] ) ? (float) $combined['pct'] : 0.0;

	$prev_bulk = ! empty( $GLOBALS['nexxi_bulk_syncing'] );
	$GLOBALS['nexxi_bulk_syncing'] = true;

	if ( $product->is_type( 'variable' ) ) {
		$min_active_price = null;
		foreach ( $product->get_children() as $child_id ) {
			$child_id = (int) $child_id;
			$item_reg = (float) get_post_meta( $child_id, '_regular_price', true );
			if ( $item_reg <= 0 ) {
				$item_reg = (float) get_post_meta( $child_id, '_price', true );
				if ( $item_reg <= 0 ) {
					$item_reg = $reg;
				}
				if ( $item_reg > 0 ) {
					update_post_meta( $child_id, '_regular_price', (string) round( $item_reg ) );
				}
			}
			if ( $regular_override > 0 ) {
				$item_reg = (float) $regular_override;
				update_post_meta( $child_id, '_regular_price', (string) round( $item_reg ) );
			}
			$var_sale = $wc_sale;
			if ( $var_sale > 0 && $item_reg > 0 && $var_sale >= $item_reg && $combined_pct > 0 ) {
				$var_sale = round( $item_reg * ( 100 - $combined_pct ) / 100, -3 );
				if ( $var_sale <= 0 || $var_sale >= $item_reg ) {
					$var_sale = max( 1000, round( $item_reg * ( 100 - $combined_pct ) / 100 ) );
				}
			}
			if ( $var_sale > 0 && $item_reg > 0 && $var_sale < $item_reg ) {
				$sale_str = (string) round( $var_sale );
				update_post_meta( $child_id, '_sale_price', $sale_str );
				update_post_meta( $child_id, '_price', $sale_str );
				$active_p = (float) round( $var_sale );
			} else {
				update_post_meta( $child_id, '_sale_price', '' );
				delete_post_meta( $child_id, '_sale_price_dates_from' );
				delete_post_meta( $child_id, '_sale_price_dates_to' );
				$active_p = $item_reg > 0 ? (float) round( $item_reg ) : 0.0;
				if ( $item_reg > 0 ) {
					update_post_meta( $child_id, '_price', (string) round( $item_reg ) );
				}
			}
			if ( $active_p > 0 && ( null === $min_active_price || $active_p < $min_active_price ) ) {
				$min_active_price = $active_p;
			}
		}
		if ( null !== $min_active_price ) {
			update_post_meta( $pid, '_price', (string) round( $min_active_price ) );
		}
		update_post_meta( $pid, '_sale_price', $wc_sale > 0 ? (string) round( $wc_sale ) : '' );
	} else {
		$item_reg = (float) get_post_meta( $pid, '_regular_price', true );
		if ( $item_reg <= 0 ) {
			$item_reg = (float) get_post_meta( $pid, '_price', true );
			if ( $item_reg <= 0 ) {
				$item_reg = $reg;
			}
			if ( $item_reg > 0 ) {
				update_post_meta( $pid, '_regular_price', (string) round( $item_reg ) );
			}
		}
		if ( $regular_override > 0 ) {
			$item_reg = (float) $regular_override;
			update_post_meta( $pid, '_regular_price', (string) round( $item_reg ) );
		}
		if ( $wc_sale > 0 && $item_reg > 0 && $wc_sale < $item_reg ) {
			$sale_str = (string) round( $wc_sale );
			update_post_meta( $pid, '_sale_price', $sale_str );
			update_post_meta( $pid, '_price', $sale_str );
		} else {
			update_post_meta( $pid, '_sale_price', '' );
			delete_post_meta( $pid, '_sale_price_dates_from' );
			delete_post_meta( $pid, '_sale_price_dates_to' );
			if ( $item_reg > 0 ) {
				update_post_meta( $pid, '_price', (string) round( $item_reg ) );
			}
		}
	}

	$GLOBALS['nexxi_bulk_syncing'] = $prev_bulk;
	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients( $pid );
	}
	clean_post_cache( $pid );
	if ( function_exists( 'nexxi_kids_reset_sale_runtime_cache' ) ) {
		nexxi_kids_reset_sale_runtime_cache( $pid );
	}
	return true;
}

/** Flush WooCommerce on-sale cache and page caches once at the end of the request so bulk operations never stall. */
function nexxi_kids_flush_sale_cache() {
	static $scheduled = false;
	if ( $scheduled ) {
		return;
	}
	$scheduled = true;
	add_action(
		'shutdown',
		static function () {
			delete_transient( 'wc_products_onsale' );
			if ( class_exists( 'WC_Cache_Helper' ) ) {
				WC_Cache_Helper::get_transient_version( 'product', true );
			}
			if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) {
				LiteSpeed_Cache_API::purge_all();
			}
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		},
		99
	);
}

/** When any variation or variable product is saved manually in WooCommerce, sync sale price across variations and clear caches. */
function nexxi_kids_on_variation_saved( $variation_id ) {
	if ( ! empty( $GLOBALS['nexxi_bulk_syncing'] ) ) {
		return;
	}
	$parent_id = wp_get_post_parent_id( $variation_id );
	if ( ! $parent_id ) { return; }
	$parent = function_exists( 'wc_get_product' ) ? wc_get_product( $parent_id ) : null;
	if ( $parent && $parent->is_type( 'variable' ) ) {
		nexxi_kids_normalize_variable_sale( $parent );
	}
	nexxi_kids_flush_sale_cache();
}
add_action( 'woocommerce_save_product_variation', 'nexxi_kids_on_variation_saved', 99, 1 );
add_action( 'woocommerce_update_product', 'nexxi_kids_flush_sale_cache_on_product_update', 99 );

/**
 * Khi lưu sản phẩm: xóa cache toàn trang tối đa 1 lần / 60 giây.
 * Đồng bộ / sửa hàng loạt hàng trăm SP trước đây xóa toàn bộ cache LiteSpeed sau MỖI SP (làm chậm cả admin lẫn web).
 * Các lần lưu trong 60 giây đó được gộp thành 1 lần xóa cache chạy ngầm ngay sau đó.
 */
function nexxi_kids_flush_sale_cache_on_product_update() {
	delete_transient( 'wc_products_onsale' );
	if ( function_exists( 'nexxi_kids_reset_sale_runtime_cache' ) ) {
		nexxi_kids_reset_sale_runtime_cache();
	}
	if ( get_transient( 'nexxi_sale_purge_lock' ) ) {
		if ( ! wp_next_scheduled( 'nexxi_deferred_sale_purge' ) ) {
			wp_schedule_single_event( time() + 60, 'nexxi_deferred_sale_purge' );
		}
		return;
	}
	set_transient( 'nexxi_sale_purge_lock', 1, 60 );
	nexxi_kids_flush_sale_cache();
}
add_action( 'nexxi_deferred_sale_purge', 'nexxi_kids_flush_sale_cache' );
/**
 * Clear all Flash Sale discounts across every product, preserving Regular Product Discounts (🏷️ Giảm Giá SP).
 */
function nexxi_kids_clear_all_flash_sale_discounts() {
	global $wpdb;
	$fs_meta_ids = $wpdb->get_col(
		"SELECT p.ID FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_nexxi_is_flash_sale'
		 WHERE p.post_type = 'product' AND pm.meta_value = '1'"
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$parent_ids = function_exists( 'nexxi_kids_flash_sale_parent_ids' ) ? nexxi_kids_flash_sale_parent_ids( true ) : array();
	$all_ids    = array_values( array_unique( array_filter( array_map( 'absint', array_merge( (array) $fs_meta_ids, (array) $parent_ids ) ) ) ) );

	$cleared = 0;
	foreach ( $all_ids as $pid ) {
		if ( nexxi_kids_apply_product_discount( $pid, 'clear', 0, 0, '1' ) ) {
			$cleared++;
		}
	}
	if ( function_exists( 'nexxi_kids_reset_sale_runtime_cache' ) ) {
		nexxi_kids_reset_sale_runtime_cache();
	}
	return $cleared;
}

/**
 * Fast, hook-safe re-sync of WooCommerce variation _sale_price / _price for Flash Sale products
 * (used when the Flash Sale timer starts or stops). Never calls heavy $v->save() loops!
 */
function nexxi_kids_sync_all_flash_sale_products() {
	if ( function_exists( 'nexxi_kids_reset_sale_runtime_cache' ) ) {
		nexxi_kids_reset_sale_runtime_cache();
	}
	$fs_ids  = function_exists( 'nexxi_kids_flash_sale_parent_ids' ) ? nexxi_kids_flash_sale_parent_ids( true ) : array();
	$all_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $fs_ids ) ) ) );
	if ( empty( $all_ids ) ) {
		return;
	}

	$prev_bulk = ! empty( $GLOBALS['nexxi_bulk_syncing'] );
	$GLOBALS['nexxi_bulk_syncing'] = true;

	foreach ( $all_ids as $sid ) {
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $sid ) : null;
		if ( ! $p ) { continue; }
		$comb         = nexxi_kids_get_program_sale( $p, 'combined' );
		$wc_sale      = $comb['on_sale'] ? (float) $comb['sale'] : 0.0;
		$combined_pct = ! empty( $comb['pct'] ) ? (float) $comb['pct'] : 0.0;
		$reg          = (float) $comb['regular'];
		if ( $p->is_type( 'variable' ) ) {
			$min_active_price = null;
			foreach ( $p->get_children() as $cid ) {
				$cid   = (int) $cid;
				$v_reg = (float) get_post_meta( $cid, '_regular_price', true );
				if ( $v_reg <= 0 ) {
					$v_reg = (float) get_post_meta( $cid, '_price', true );
					if ( $v_reg <= 0 ) { $v_reg = $reg; }
				}
				$var_sale = $wc_sale;
				if ( $var_sale > 0 && $v_reg > 0 && $var_sale >= $v_reg && $combined_pct > 0 ) {
					$var_sale = round( $v_reg * ( 100 - $combined_pct ) / 100, -3 );
					if ( $var_sale <= 0 || $var_sale >= $v_reg ) {
						$var_sale = max( 1000, round( $v_reg * ( 100 - $combined_pct ) / 100 ) );
					}
				}
				if ( $var_sale > 0 && $v_reg > 0 && $var_sale < $v_reg ) {
					$sale_str = (string) round( $var_sale );
					update_post_meta( $cid, '_sale_price', $sale_str );
					update_post_meta( $cid, '_price', $sale_str );
					$active_p = (float) round( $var_sale );
				} else {
					update_post_meta( $cid, '_sale_price', '' );
					$active_p = $v_reg > 0 ? (float) round( $v_reg ) : 0.0;
					if ( $v_reg > 0 ) {
						update_post_meta( $cid, '_price', (string) round( $v_reg ) );
					}
				}
				if ( $active_p > 0 && ( null === $min_active_price || $active_p < $min_active_price ) ) {
					$min_active_price = $active_p;
				}
			}
			if ( null !== $min_active_price ) {
				update_post_meta( $sid, '_price', (string) round( $min_active_price ) );
			}
			update_post_meta( $sid, '_sale_price', $wc_sale > 0 ? (string) round( $wc_sale ) : '' );
		} else {
			if ( $wc_sale > 0 && $reg > 0 && $wc_sale < $reg ) {
				$sale_str = (string) round( $wc_sale );
				update_post_meta( $sid, '_sale_price', $sale_str );
				update_post_meta( $sid, '_price', $sale_str );
			} else {
				update_post_meta( $sid, '_sale_price', '' );
				if ( $reg > 0 ) {
					update_post_meta( $sid, '_price', (string) round( $reg ) );
				}
			}
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $sid );
		}
		clean_post_cache( $sid );
	}

	$GLOBALS['nexxi_bulk_syncing'] = $prev_bulk;
	if ( function_exists( 'nexxi_kids_reset_sale_runtime_cache' ) ) {
		nexxi_kids_reset_sale_runtime_cache();
	}
}

/**
 * Automatically expire and clean up Flash Sale when the countdown time ends.
 * Runs on every request (both Frontend and WP Admin) before queries execute.
 */
function nexxi_kids_maybe_expire_flash_sale() {
	if ( ! function_exists( 'nexxi_kids_flash_timer_state' ) ) {
		return;
	}
	$timer = nexxi_kids_flash_timer_state();

	// If a configured Flash Sale end time has passed, automatically turn off & clear all Flash Sale discounts!
	if ( $timer['is_expired'] ) {
		$expired_label = wp_date( 'H:i - d/m/Y', $timer['ts'] );
		update_option( 'nexxi_flash_sale_enabled', '0' );
		update_option( 'nexxi_flash_sale_end', '' );
		set_theme_mod( 'nexxi_kids_flash_sale_end', '' );
		update_option( 'nexxi_flash_sale_last_expired', $expired_label );
		nexxi_kids_clear_all_flash_sale_discounts();
		nexxi_kids_flush_sale_cache();
	}
}
add_action( 'init', 'nexxi_kids_maybe_expire_flash_sale', 10 );

/** Parse a raw discount input string (e.g. "15", "-15%", "255.000", "0") into [mode, value]. */
function nexxi_kids_parse_flash_discount_input( $raw_input, $explicit_mode = '' ) {
	$str = trim( (string) $raw_input );
	if ( '' === $str || in_array( strtolower( $str ), array( 'clear', 'off', 'xoa', 'none', '0', '0%' ), true ) ) {
		return array( 'mode' => 'clear', 'val' => 0.0 );
	}
	$has_pct = ( false !== strpos( $str, '%' ) );
	$clean   = preg_replace( '~[^\d.,-]~', '', $str );
	$clean   = ltrim( $clean, '-' );
	if ( preg_match( '~^\d{1,3}([.,]\d{3})+$~', $clean ) ) {
		$clean = str_replace( array( '.', ',' ), '', $clean );
	} else {
		$clean = str_replace( ',', '.', $clean );
	}
	$num = is_numeric( $clean ) ? (float) $clean : 0.0;
	if ( $num <= 0 ) {
		return array( 'mode' => 'clear', 'val' => 0.0 );
	}
	if ( 'clear' === $explicit_mode ) {
		return array( 'mode' => 'clear', 'val' => 0.0 );
	}
	if ( 'percent' === $explicit_mode || 'fixed' === $explicit_mode || 'amount_off' === $explicit_mode ) {
		if ( 'percent' === $explicit_mode && $num > 95 ) {
			return array( 'mode' => 'fixed', 'val' => $num );
		}
		return array( 'mode' => $explicit_mode, 'val' => $num );
	}
	$mode = ( $has_pct || $num <= 95 ) ? 'percent' : 'fixed';
	return array( 'mode' => $mode, 'val' => $num );
}

/** Handle POST actions from the Flash Sale Admin page. */
function nexxi_kids_handle_flash_sale_admin_post() {
	if ( empty( $_POST['nexxi_fs_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	check_admin_referer( 'nexxi_flash_sale_admin', 'nexxi_fs_nonce' );
	$action = sanitize_key( wp_unslash( $_POST['nexxi_fs_action'] ) );

	// Support native single-row Save button inside the master form as well as JS single_discount
	if ( ! empty( $_POST['single_save_pid'] ) ) {
		$action = 'single_row_native';
	}

	if ( 'save_timer' === $action ) {
		$enabled = ! empty( $_POST['nexxi_flash_sale_enabled'] ) ? '1' : '0';
		$end_raw = isset( $_POST['nexxi_flash_sale_end'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['nexxi_flash_sale_end'] ) ) ) : '';
		$end_ts  = 0;
		if ( '' !== $end_raw ) {
			try {
				$dt     = new DateTimeImmutable( $end_raw, wp_timezone() );
				$end_ts = $dt->getTimestamp();
			} catch ( Exception $e ) {
				$end_ts = (int) strtotime( $end_raw );
			}
		}

		// If timer is turned off, cleared to 00:00:00, or set to a past time -> turn off and clear all Flash Sale discounts!
		if ( '0' === $enabled || '' === $end_raw || $end_ts <= time() ) {
			update_option( 'nexxi_flash_sale_enabled', '0' );
			update_option( 'nexxi_flash_sale_end', '' );
			set_theme_mod( 'nexxi_kids_flash_sale_end', '' );
			$cleared = nexxi_kids_clear_all_flash_sale_discounts();
			nexxi_kids_flush_sale_cache();
			add_settings_error(
				'nexxi_fs',
				'timer_stopped',
				'Đã tắt đồng hồ Flash Sale và tự động gỡ khuyến mãi Flash Sale' . ( $cleared > 0 ? ' cho ' . $cleared . ' sản phẩm' : '' ) . ' (chỉ giữ lại Giảm Giá Sản Phẩm)!',
				'updated'
			);
		} else {
			update_option( 'nexxi_flash_sale_enabled', '1' );
			update_option( 'nexxi_flash_sale_end', $end_raw );
			set_theme_mod( 'nexxi_kids_flash_sale_end', $end_raw );
			delete_option( 'nexxi_flash_sale_last_expired' );
			nexxi_kids_sync_all_flash_sale_products();
			nexxi_kids_flush_sale_cache();
			add_settings_error( 'nexxi_fs', 'timer_saved', 'Đã bật & lưu thời gian kết thúc Flash Sale!', 'updated' );
		}
	} elseif ( 'single_discount' === $action || 'single_row_native' === $action ) {
		if ( 'single_row_native' === $action ) {
			$pid     = absint( $_POST['single_save_pid'] );
			$row_map = isset( $_POST['row_discount_val'] ) && is_array( $_POST['row_discount_val'] ) ? wp_unslash( $_POST['row_discount_val'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_val = isset( $row_map[ $pid ] ) ? $row_map[ $pid ] : '';
			$parsed  = nexxi_kids_parse_flash_discount_input( $raw_val );
		} else {
			$pid      = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$exp_mode = isset( $_POST['discount_mode'] ) ? sanitize_key( wp_unslash( $_POST['discount_mode'] ) ) : '';
			$raw_val  = isset( $_POST['discount_value'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_value'] ) ) : '';
			$parsed   = nexxi_kids_parse_flash_discount_input( $raw_val, $exp_mode );
		}
		$mode = $parsed['mode'];
		$val  = $parsed['val'];
		$auto_started_msg = '';
		if ( 'clear' !== $mode && $val > 0 && ! nexxi_kids_is_flash_sale_live() ) {
			$auto_end = wp_date( 'Y-m-d\TH:i', time() + DAY_IN_SECONDS );
			update_option( 'nexxi_flash_sale_enabled', '1' );
			update_option( 'nexxi_flash_sale_end', $auto_end );
			set_theme_mod( 'nexxi_kids_flash_sale_end', $auto_end );
			delete_option( 'nexxi_flash_sale_last_expired' );
			nexxi_kids_sync_all_flash_sale_products();
			$auto_started_msg = ' (Đã tự động bật đồng hồ Flash Sale 24h để áp dụng ngay giá giảm!)';
		}
		if ( $pid && nexxi_kids_apply_product_discount( $pid, $mode, $val, 0, '1' ) ) {
			nexxi_kids_flush_sale_cache();
			add_settings_error( 'nexxi_fs', 'single_saved', 'Đã cập nhật giá Flash Sale cho sản phẩm #' . $pid . '!' . $auto_started_msg, 'updated' );
		}
	} elseif ( 'bulk_discount' === $action ) {
		$pids     = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['product_ids'] ) ) : array();
		$exp_mode = isset( $_POST['bulk_mode'] ) ? sanitize_key( wp_unslash( $_POST['bulk_mode'] ) ) : 'percent';
		$raw_val  = isset( $_POST['bulk_value'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_value'] ) ) : '';
		$parsed   = nexxi_kids_parse_flash_discount_input( $raw_val, $exp_mode );
		$mode     = $parsed['mode'];
		$val      = $parsed['val'];
		$auto_started_msg = '';
		if ( ! empty( $pids ) && 'clear' !== $mode && $val > 0 && ! nexxi_kids_is_flash_sale_live() ) {
			$auto_end = wp_date( 'Y-m-d\TH:i', time() + DAY_IN_SECONDS );
			update_option( 'nexxi_flash_sale_enabled', '1' );
			update_option( 'nexxi_flash_sale_end', $auto_end );
			set_theme_mod( 'nexxi_kids_flash_sale_end', $auto_end );
			delete_option( 'nexxi_flash_sale_last_expired' );
			nexxi_kids_sync_all_flash_sale_products();
			$auto_started_msg = ' (Đã tự động bật đồng hồ Flash Sale 24h để áp dụng ngay giá giảm!)';
		}
		$count    = 0;
		foreach ( $pids as $pid ) {
			if ( $pid && nexxi_kids_apply_product_discount( $pid, $mode, $val, 0, '1' ) ) {
				$count++;
			}
		}
		if ( $count > 0 ) {
			nexxi_kids_flush_sale_cache();
			add_settings_error( 'nexxi_fs', 'bulk_saved', 'Đã áp dụng Flash Sale cho ' . $count . ' sản phẩm!' . $auto_started_msg, 'updated' );
		} else {
			add_settings_error( 'nexxi_fs', 'bulk_empty', 'Vui lòng tích chọn ít nhất 1 sản phẩm trong bảng bên dưới (hoặc bấm nút Lưu trên từng dòng sản phẩm).', 'error' );
		}
	}
}
add_action( 'admin_init', 'nexxi_kids_handle_flash_sale_admin_post' );

/** Render the Flash Sale & Product Discount Admin screen. */
function nexxi_kids_flash_sale_admin_page() {
	$enabled = '0' !== (string) get_option( 'nexxi_flash_sale_enabled', '1' );
	$end_raw = trim( (string) get_option( 'nexxi_flash_sale_end', get_theme_mod( 'nexxi_kids_flash_sale_end', '' ) ) );
	$end_input_val = '';
	if ( '' !== $end_raw ) {
		try {
			$dt = new DateTimeImmutable( $end_raw, wp_timezone() );
			$end_input_val = $dt->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
		} catch ( Exception $e ) {
			$ts = strtotime( $end_raw );
			if ( $ts ) { $end_input_val = wp_date( 'Y-m-d\TH:i', $ts ); }
		}
	}

	$sale_parent_ids = function_exists( 'nexxi_kids_flash_sale_parent_ids' ) ? nexxi_kids_flash_sale_parent_ids( true ) : array();
	$active_ts = nexxi_kids_flash_end();
	$last_expired = (string) get_option( 'nexxi_flash_sale_last_expired', '' );

	// Filters for product table
	$search = isset( $_GET['s_prod'] ) ? sanitize_text_field( wp_unslash( $_GET['s_prod'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$cat    = isset( $_GET['cat_slug'] ) ? sanitize_title( wp_unslash( $_GET['cat_slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$status = isset( $_GET['sale_filter'] ) ? sanitize_key( wp_unslash( $_GET['sale_filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$per_page = 25;

	$query_args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	$filter_post_in     = null;
	$filter_post_not_in = array();

	if ( '' !== $search ) {
		if ( function_exists( 'nexxi_kids_search_product_ids' ) ) {
			$matched_ids    = nexxi_kids_search_product_ids( $search );
			$filter_post_in = ! empty( $matched_ids ) ? $matched_ids : array( 0 );
		} else {
			$query_args['s'] = $search;
		}
	}
	if ( '' !== $cat ) {
		$query_args['tax_query'] = array(
			array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => $cat,
			),
		);
	}
	if ( 'onsale' === $status ) {
		$sale_set       = $sale_parent_ids ? $sale_parent_ids : array( 0 );
		$filter_post_in = ( null !== $filter_post_in ) ? array_values( array_intersect( $filter_post_in, $sale_set ) ) : $sale_set;
		if ( empty( $filter_post_in ) ) {
			$filter_post_in = array( 0 );
		}
	} elseif ( 'regular' === $status && $sale_parent_ids ) {
		if ( null !== $filter_post_in ) {
			$filter_post_in = array_values( array_diff( $filter_post_in, $sale_parent_ids ) );
			if ( empty( $filter_post_in ) ) {
				$filter_post_in = array( 0 );
			}
		} else {
			$filter_post_not_in = $sale_parent_ids;
		}
	}

	if ( null !== $filter_post_in ) {
		$query_args['post__in'] = $filter_post_in;
	} elseif ( ! empty( $filter_post_not_in ) ) {
		$query_args['post__not_in'] = $filter_post_not_in;
	}

	$wp_q = new WP_Query( $query_args );
	// Nạp sẵn meta biến thể & ảnh của cả trang trong vài truy vấn (tránh mỗi dòng SP phát sinh nhiều truy vấn).
	if ( function_exists( 'nexxi_kids_prime_products_cache' ) && ! empty( $wp_q->posts ) ) {
		nexxi_kids_prime_products_cache( array_filter( array_map( 'wc_get_product', wp_list_pluck( $wp_q->posts, 'ID' ) ) ) );
	}
	$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	?>
	<div class="wrap" style="max-width:1280px;margin-top:18px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
		<!-- Unified "Khuyến mãi shop" Tab Bar -->
		<div style="display:flex;gap:8px;margin-bottom:16px;border-bottom:2px solid #e4e2de;padding-bottom:10px;flex-wrap:wrap;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-bulk-discount' ) ); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;background:#fff;color:#031444;border:1px solid #d5d3ce;font-weight:700;font-size:13.5px;text-decoration:none;">
				🏷️ Giảm Giá Bán Sản Phẩm (Hàng Loạt / SKU)
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-flash-sale' ) ); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;background:#fd6a49;color:#fff;font-weight:700;font-size:13.5px;text-decoration:none;box-shadow:0 2px 8px rgba(253,106,73,0.25);">
				⚡ Chiến Dịch Flash Sale &amp; Đếm Ngược
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-combo-promo' ) ); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;background:#fff;color:#031444;border:1px solid #d5d3ce;font-weight:700;font-size:13.5px;text-decoration:none;">
				🎁 Combo Khuyến Mãi (Theo Số Lượng Bill)
			</a>
		</div>
		<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
			<div>
				<h1 style="margin:0;font-size:24px;font-weight:800;color:#031444;display:flex;align-items:center;gap:8px;">
					<span>⚡ Quản Lý Flash Sale &amp; Giảm Giá Sản Phẩm NEXXI</span>
				</h1>
				<p style="margin:4px 0 0;color:#555;font-size:13.5px;">
					Thiết lập đồng hồ đếm ngược Flash Sale và chỉnh giá khuyến mãi nhanh cho cả sản phẩm đơn lẫn sản phẩm có nhiều biến thể (Màu / Size).
				</p>
			</div>
			<div style="display:flex;gap:8px;">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-bulk-discount' ) ); ?>" style="font-weight:700;color:#031444;border-color:#031444;">🏷️ Mở Công Cụ Giảm Giá Bán Sản Phẩm (Hàng Loạt / SKU) →</a>
				<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank">Xem Trang Chủ ↗</a>
				<a class="button button-primary" href="<?php echo esc_url( nexxi_kids_flash_url() ); ?>" target="_blank" style="background:#fd6a49;border-color:#fd6a49;">Xem Trang Flash Sale ↗</a>
			</div>
		</div>

		<?php settings_errors( 'nexxi_fs' ); ?>

		<!-- CARD 1: FLASH SALE COUNTDOWN TIMER SETUP -->
		<div style="background:#fff;border:1px solid #e4e2de;border-radius:16px;padding:22px 24px;margin-bottom:22px;box-shadow:0 4px 16px rgba(3,20,68,0.04);">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #f0eee9;">
				<div>
					<h2 style="margin:0;font-size:17px;font-weight:700;color:#031444;">1. Bộ Đếm Giờ Flash Sale (Trang Chủ &amp; Trang Flash Sale)</h2>
					<p style="margin:4px 0 0;color:#666;font-size:13px;">
						Khi <b>hết thời gian Flash Sale</b> (đồng hồ về <code>00 : 00 : 00</code>), hệ thống sẽ <b>tự động tắt &amp; xóa khuyến mãi Flash Sale</b> của các sản phẩm bên dưới và <b>chỉ giữ lại Giảm Giá Sản Phẩm thường</b>.
					</p>
				</div>
				<div>
					<?php if ( $active_ts > time() ) : ?>
						<span style="display:inline-flex;align-items:center;gap:6px;background:#e6f9ed;color:#0b6b2f;font-weight:700;font-size:12.5px;padding:6px 14px;border-radius:999px;border:1px solid #b6ecc8;">
							● ĐANG CHẠY ĐẾM NGƯỢC (Kết thúc: <?php echo esc_html( wp_date( 'H:i - d/m/Y', $active_ts ) ); ?> · <?php echo count( $sale_parent_ids ); ?> sản phẩm đang sale)
						</span>
					<?php else : ?>
						<span style="display:inline-flex;align-items:center;gap:6px;background:#fff4f1;color:#ae3115;font-weight:700;font-size:12.5px;padding:6px 14px;border-radius:999px;border:1px solid #ffdad2;">
							○ ĐỒNG HỒ ĐANG Ở 00:00:00 (<?php echo $last_expired ? 'Đã tự động kết thúc phiên ' . esc_html( $last_expired ) : ( empty( $sale_parent_ids ) ? 'Chưa có sản phẩm nào giảm giá Flash Sale' : 'Chưa đặt giờ kết thúc hoặc đã hết giờ' ); ?>)
						</span>
					<?php endif; ?>
				</div>
			</div>

			<form method="post" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:18px;">
				<?php wp_nonce_field( 'nexxi_flash_sale_admin', 'nexxi_fs_nonce' ); ?>
				<input type="hidden" name="nexxi_fs_action" value="save_timer">

				<label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#031444;padding:8px 14px;background:#f5f3ef;border-radius:10px;cursor:pointer;">
					<input type="checkbox" id="nexxi-fs-enabled-cb" name="nexxi_flash_sale_enabled" value="1" <?php checked( $enabled ); ?>>
					<span>Bật chạy đồng hồ Flash Sale</span>
				</label>

				<div>
					<label style="display:block;font-size:12.5px;font-weight:700;color:#031444;margin-bottom:5px;">Thời gian kết thúc Flash Sale (Giờ Việt Nam):</label>
					<input type="datetime-local" id="nexxi-fs-end-input" name="nexxi_flash_sale_end" value="<?php echo esc_attr( $end_input_val ); ?>" onchange="if(this.value){document.getElementById('nexxi-fs-enabled-cb').checked=true;}" style="min-height:38px;padding:4px 12px;border-radius:8px;border:1px solid #c6c6d0;font-size:14px;font-weight:600;color:#031444;">
				</div>

				<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
					<span style="font-size:12px;color:#666;margin-right:2px;">Chọn nhanh:</span>
					<button type="button" class="button" onclick="nexxiSetQuickEnd(3)">+3 Giờ</button>
					<button type="button" class="button" onclick="nexxiSetQuickEnd(6)">+6 Giờ</button>
					<button type="button" class="button" onclick="nexxiSetQuickEnd(12)">+12 Giờ</button>
					<button type="button" class="button" onclick="nexxiSetQuickEnd(24)">+24 Giờ</button>
					<button type="button" class="button" onclick="nexxiSetQuickEnd('eod')">Cuối ngày hôm nay (23:59)</button>
					<button type="button" class="button" onclick="document.getElementById('nexxi-fs-end-input').value='';document.getElementById('nexxi-fs-enabled-cb').checked=false;" style="color:#ae3115;">Về 00:00:00 (Tắt Flash Sale)</button>
				</div>

				<button type="submit" class="button button-primary" style="min-height:38px;padding:0 20px;border-radius:8px;font-weight:700;background:#031444;border-color:#031444;">
					💾 Lưu Thời Gian Flash Sale
				</button>
			</form>
		</div>

		<!-- CARD 2: PRODUCT DISCOUNT & FLASH SALE TABLE -->
		<div style="background:#fff;border:1px solid #e4e2de;border-radius:16px;padding:22px 24px;box-shadow:0 4px 16px rgba(3,20,68,0.04);">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
				<div>
					<h2 style="margin:0;font-size:17px;font-weight:700;color:#031444;">
						2. Thiết Lập Giảm Giá Sản Phẩm &amp; Đưa Lên Kệ Flash Sale
						<span style="background:#ffdad2;color:#3d0600;font-size:12px;padding:3px 10px;border-radius:999px;margin-left:6px;">Đang có <?php echo count( $sale_parent_ids ); ?> mẫu giảm giá</span>
					</h2>
					<p style="margin:4px 0 0;color:#666;font-size:13px;">
						💡 <b>Mẹo:</b> Bất kỳ sản phẩm nào được bật giảm giá tại đây sẽ <b>tự động hiển thị ở mục Flash Sale Trang Chủ</b>, <b>Trang Flash Sale</b> và có <b>nhãn -XX% SALE</b> ở Cửa hàng. Hỗ trợ tự động áp dụng cho toàn bộ biến thể Màu / Size!
					</p>
				</div>
			</div>

			<!-- Filter & Search Bar -->
			<form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#f5f3ef;padding:12px 14px;border-radius:12px;margin-bottom:14px;">
				<input type="hidden" name="page" value="nexxi-flash-sale">
				<input type="search" name="s_prod" value="<?php echo esc_attr( $search ); ?>" placeholder="Tìm tên hoặc mã sản phẩm..." style="min-width:220px;min-height:34px;border-radius:8px;border:1px solid #d5d3ce;padding:0 10px;">
				<select name="cat_slug" style="min-height:34px;border-radius:8px;border:1px solid #d5d3ce;">
					<option value="">— Tất cả danh mục —</option>
					<?php if ( ! is_wp_error( $categories ) ) : foreach ( $categories as $c_term ) : ?>
						<option value="<?php echo esc_attr( $c_term->slug ); ?>" <?php selected( $cat, $c_term->slug ); ?>><?php echo esc_html( $c_term->name ); ?> (<?php echo (int) $c_term->count; ?>)</option>
					<?php endforeach; endif; ?>
				</select>
				<select name="sale_filter" style="min-height:34px;border-radius:8px;border:1px solid #d5d3ce;">
					<option value="all" <?php selected( $status, 'all' ); ?>>Tất cả trạng thái</option>
					<option value="onsale" <?php selected( $status, 'onsale' ); ?>>🔥 Đang giảm giá (Flash Sale) (<?php echo count( $sale_parent_ids ); ?>)</option>
					<option value="regular" <?php selected( $status, 'regular' ); ?>>Chưa giảm giá</option>
				</select>
				<button type="submit" class="button" style="min-height:34px;">Lọc sản phẩm</button>
				<?php if ( '' !== $search || '' !== $cat || 'all' !== $status ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-flash-sale' ) ); ?>" style="min-height:34px;">Xóa lọc</a>
				<?php endif; ?>
			</form>

			<!-- Bulk Discount Bar -->
			<form id="nexxi-bulk-sale-form" method="post">
				<?php wp_nonce_field( 'nexxi_flash_sale_admin', 'nexxi_fs_nonce' ); ?>
				<input type="hidden" name="nexxi_fs_action" value="bulk_discount">
				<div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:10px 14px;background:#fff8f6;border:1px solid #ffdad2;border-radius:12px;margin-bottom:14px;">
					<strong style="color:#ae3115;font-size:13px;">⚡ Giảm giá hàng loạt (cho các mẫu đã tích chọn):</strong>
					<select name="bulk_mode" id="nexxi-bulk-mode" style="min-height:34px;border-radius:8px;border:1px solid #d5d3ce;">
						<option value="percent">Giảm theo % (so với giá gốc)</option>
						<option value="fixed">Đặt đồng giá khuyến mãi (₫)</option>
						<option value="clear">Tắt giảm giá (Đưa về giá gốc)</option>
					</select>
					<input type="number" name="bulk_value" id="nexxi-bulk-val" value="25" min="0" step="1" placeholder="Nhập % hoặc số tiền" style="width:120px;min-height:34px;border-radius:8px;border:1px solid #d5d3ce;padding:0 10px;font-weight:700;">
					<div style="display:flex;gap:4px;">
						<button type="button" class="button" onclick="document.getElementById('nexxi-bulk-mode').value='percent';document.getElementById('nexxi-bulk-val').value='25';">-25%</button>
						<button type="button" class="button" onclick="document.getElementById('nexxi-bulk-mode').value='percent';document.getElementById('nexxi-bulk-val').value='40';">-40%</button>
						<button type="button" class="button" onclick="document.getElementById('nexxi-bulk-mode').value='percent';document.getElementById('nexxi-bulk-val').value='50';">-50%</button>
					</div>
					<button type="submit" class="button button-primary" style="min-height:34px;background:#fd6a49;border-color:#fd6a49;font-weight:700;">
						Áp dụng cho các sản phẩm đã chọn
					</button>
				</div>

				<table class="wp-list-table widefat fixed striped" style="border-radius:10px;overflow:hidden;border:1px solid #e4e2de;">
					<thead>
						<tr>
							<td id="cb" class="manage-column column-cb check-column" style="width:36px;"><input type="checkbox" onclick="document.querySelectorAll('.nexxi-prod-cb').forEach(c=>c.checked=this.checked)"></td>
							<th style="width:60px;">Ảnh</th>
							<th>Sản phẩm</th>
							<th style="width:150px;">Giá gốc</th>
							<th style="width:170px;">Giá đang bán</th>
							<th style="width:380px;">Chỉnh nhanh Giảm Giá / Flash Sale</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $wp_q->have_posts() ) : ?>
							<tr><td colspan="6" style="padding:24px;text-align:center;color:#666;">Không tìm thấy sản phẩm nào phù hợp.</td></tr>
						<?php else : foreach ( $wp_q->posts as $post_obj ) :
							$prod = wc_get_product( $post_obj->ID );
							if ( ! $prod ) { continue; }
							$pid = $prod->get_id();
							$prog = nexxi_kids_get_program_sale( $prod, 'flash_admin' );
							$on_sale = $prog['on_sale'];
							$discount_pct = $prog['pct'];
							$thumb = wp_get_attachment_image_url( $prod->get_image_id(), 'thumbnail' );
							$cat_term = nexxi_kids_product_category( $prod );
							$children_count = $prod->is_type( 'variable' ) ? count( $prod->get_children() ) : 0;
						?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" class="nexxi-prod-cb" name="product_ids[]" value="<?php echo (int) $pid; ?>">
								</th>
								<td>
									<?php if ( $thumb ) : ?>
										<img src="<?php echo esc_url( $thumb ); ?>" alt="" style="width:46px;height:46px;object-fit:cover;border-radius:8px;border:1px solid #eee;">
									<?php else : ?>
										<div style="width:46px;height:46px;border-radius:8px;background:#f5f3ef;"></div>
									<?php endif; ?>
								</td>
								<td>
									<strong style="font-size:14px;"><a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" style="color:#031444;text-decoration:none;"><?php echo esc_html( $prod->get_name() ); ?></a></strong>
									<div style="font-size:12px;color:#767680;margin-top:2px;">
										<?php echo $cat_term ? esc_html( $cat_term->name ) . ' · ' : ''; ?>
										<?php echo $children_count ? 'Có ' . (int) $children_count . ' biến thể (Size/Màu)' : 'Sản phẩm đơn'; ?>
										· <a href="<?php echo esc_url( $prod->get_permalink() ); ?>" target="_blank">Xem ngoài web ↗</a>
									</div>
								</td>
								<td style="font-weight:600;color:#45464f;">
									<?php echo $prog['regular'] > 0 ? esc_html( nexxi_kids_money( $prog['regular'] ) ) : '<span style="color:#ba1a1a;">Chưa có giá gốc</span>'; ?>
								</td>
								<td>
									<?php if ( $on_sale ) : ?>
										<span style="font-weight:800;color:#ae3115;font-size:14px;"><?php echo esc_html( nexxi_kids_money( $prog['sale'] ) ); ?></span>
										<span style="display:inline-block;background:#fd6a49;color:#fff;font-size:11px;font-weight:700;padding:1px 7px;border-radius:999px;margin-left:4px;">-<?php echo (int) $discount_pct; ?>%</span>
										<?php if ( ! empty( $prog['other_active'] ) ) : ?>
											<div style="font-size:11px;color:#0b6b2f;background:#e6f4ea;padding:2px 6px;border-radius:4px;margin-top:4px;font-weight:700;">
												🔥 Cộng dồn Giảm SP (-<?php echo (int) $prog['other_pct']; ?>%): <?php echo esc_html( nexxi_kids_money( $prog['combined_sale'] ) ); ?> (-<?php echo (int) $prog['combined_pct']; ?>%)
											</div>
										<?php endif; ?>
									<?php else : ?>
										<span style="color:#767680;">Chưa bật Flash Sale</span>
										<?php if ( ! empty( $prog['other_active'] ) ) : ?>
											<div style="font-size:11px;color:#031444;background:#eef2ff;padding:2px 6px;border-radius:4px;margin-top:4px;font-weight:600;">
												🏷️ Đang giữ Giảm Giá SP: <?php echo esc_html( nexxi_kids_money( $prog['other_sale'] ) ); ?> (-<?php echo (int) $prog['other_pct']; ?>%)
											</div>
										<?php endif; ?>
									<?php endif; ?>
								</td>
								<td>
									<div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
										<button type="button" class="button button-small" onclick="nexxiQuickRowSale(<?php echo (int) $pid; ?>,'percent',25)">-25%</button>
										<button type="button" class="button button-small" onclick="nexxiQuickRowSale(<?php echo (int) $pid; ?>,'percent',40)">-40%</button>
										<button type="button" class="button button-small" onclick="nexxiQuickRowSale(<?php echo (int) $pid; ?>,'percent',50)">-50%</button>
										<input type="text" inputmode="numeric" id="nx-val-<?php echo (int) $pid; ?>" name="row_discount_val[<?php echo (int) $pid; ?>]" placeholder="% hoặc Giá ₫" value="<?php echo ( $on_sale && $discount_pct > 0 ) ? (int) $discount_pct : ''; ?>" onkeydown="if(event.key==='Enter'){event.preventDefault();nexxiSubmitRowInput(<?php echo (int) $pid; ?>);}" style="width:94px;min-height:28px;font-size:12px;font-weight:700;color:#031444;padding:0 6px;border-radius:6px;border:1px solid #c6c6d0;">
										<button type="submit" name="single_save_pid" value="<?php echo (int) $pid; ?>" class="button button-small button-primary" style="background:#031444;border-color:#031444;" onclick="nexxiSubmitRowInput(<?php echo (int) $pid; ?>);return false;">Lưu</button>
										<?php if ( $on_sale ) : ?>
											<button type="button" class="button button-small" style="color:#ba1a1a;border-color:#ffdad6;" onclick="nexxiQuickRowSale(<?php echo (int) $pid; ?>,'clear',0)">Tắt sale</button>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</form>

			<!-- Hidden single-product form -->
			<form id="nexxi-single-sale-form" method="post" style="display:none;">
				<?php wp_nonce_field( 'nexxi_flash_sale_admin', 'nexxi_fs_nonce' ); ?>
				<input type="hidden" name="nexxi_fs_action" value="single_discount">
				<input type="hidden" name="product_id" id="nx-single-pid" value="">
				<input type="hidden" name="discount_mode" id="nx-single-mode" value="">
				<input type="hidden" name="discount_value" id="nx-single-val" value="">
			</form>

			<?php if ( $wp_q->max_num_pages > 1 ) : ?>
				<div style="margin-top:16px;display:flex;align-items:center;justify-content:space-between;">
					<span style="color:#666;font-size:13px;">Trang <?php echo (int) $paged; ?> / <?php echo (int) $wp_q->max_num_pages; ?> (Tổng <?php echo (int) $wp_q->found_posts; ?> sản phẩm)</span>
					<div style="display:flex;gap:6px;">
						<?php for ( $i = 1; $i <= $wp_q->max_num_pages; $i++ ) :
							$link = add_query_arg( 'paged', $i ); ?>
							<a class="button <?php echo $i === $paged ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $link ); ?>"><?php echo (int) $i; ?></a>
						<?php endfor; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<script>
	function nexxiSetQuickEnd(hours) {
		var now = new Date();
		if (hours === 'eod') {
			now.setHours(23, 59, 0, 0);
		} else {
			now.setTime(now.getTime() + hours * 3600 * 1000);
		}
		var pad = function(n) { return String(n).padStart(2, '0'); };
		var formatted = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
		document.getElementById('nexxi-fs-end-input').value = formatted;
		var cb = document.getElementById('nexxi-fs-enabled-cb');
		if (cb) cb.checked = true;
	}
	function nexxiQuickRowSale(pid, mode, val) {
		document.getElementById('nx-single-pid').value = pid;
		document.getElementById('nx-single-mode').value = mode || '';
		document.getElementById('nx-single-val').value = val;
		document.getElementById('nexxi-single-sale-form').submit();
	}
	function nexxiSubmitRowInput(pid) {
		var el = document.getElementById('nx-val-' + pid);
		var raw = el ? (el.value || '').trim() : '';
		if (!raw || raw === '0' || raw === '0%') {
			nexxiQuickRowSale(pid, 'clear', '0');
			return;
		}
		// Pass raw input to PHP parser (handles "15", "-15", "15%", "255000", "255.000", etc.)
		nexxiQuickRowSale(pid, '', raw);
	}
	</script>
	<?php
}

/** Add a Quick Discount Metabox directly on the WooCommerce Product Edit screen. */
function nexxi_kids_add_product_discount_metabox() {
	add_meta_box(
		'nexxi_quick_discount_box',
		'⚡ NEXXI – Giảm Giá & Flash Sale Nhanh',
		'nexxi_kids_render_product_discount_metabox',
		'product',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'nexxi_kids_add_product_discount_metabox' );

function nexxi_kids_render_product_discount_metabox( WP_Post $post ) {
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
	$range = $product ? nexxi_kids_price_range( $product ) : array( 'min' => 0, 'max' => 0, 'regular' => 0 );
	$on_sale = $product && $product->is_on_sale() && $range['regular'] > $range['min'];
	$pct = $on_sale && $range['regular'] > 0 ? round( ( 1 - $range['min'] / $range['regular'] ) * 100 ) : 0;
	wp_nonce_field( 'nexxi_product_quick_sale', 'nexxi_product_quick_sale_nonce' );
	?>
	<div style="font-size:12.5px;line-height:1.5;">
		<?php if ( $range['regular'] > 0 ) : ?>
			<p style="margin:0 0 8px;">
				Giá gốc hiện tại: <strong><?php echo esc_html( nexxi_kids_money( $range['regular'] ) ); ?></strong><br>
				<?php if ( $on_sale ) : ?>
					Đang khuyến mãi: <strong style="color:#ae3115;"><?php echo esc_html( nexxi_kids_money( $range['min'] ) ); ?> (-<?php echo (int) $pct; ?>%)</strong>
				<?php else : ?>
					Trạng thái: <span style="color:#666;">Chưa giảm giá</span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<p style="margin:0 0 6px;color:#444;">Áp dụng đồng loạt cho <b>tất cả biến thể (Size/Màu)</b> của sản phẩm này khi bấm <b>Cập nhật</b>:</p>
		<label style="display:block;margin-bottom:8px;">
			<span style="font-weight:600;display:block;margin-bottom:2px;">Đặt lại Giá gốc chung (₫) (tùy chọn):</span>
			<input type="number" name="nexxi_quick_regular_price" value="" placeholder="Để trống = giữ nguyên giá gốc" style="width:100%;" min="0" step="1000">
		</label>
		<label style="display:block;margin-bottom:8px;">
			<span style="font-weight:600;display:block;margin-bottom:2px;">Giảm giá (% hoặc Giá khuyến mãi ₫):</span>
			<input type="number" id="nexxi-mb-sale-val" name="nexxi_quick_sale_val" value="" placeholder="VD: 25 (giảm 25%) hoặc 120000" style="width:100%;" min="0">
		</label>
		<div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px;">
			<button type="button" class="button button-small" onclick="document.getElementById('nexxi-mb-sale-val').value='15'">-15%</button>
			<button type="button" class="button button-small" onclick="document.getElementById('nexxi-mb-sale-val').value='20'">-20%</button>
			<button type="button" class="button button-small" onclick="document.getElementById('nexxi-mb-sale-val').value='30'">-30%</button>
			<button type="button" class="button button-small" style="color:#ba1a1a;" onclick="document.getElementById('nexxi-mb-sale-val').value='0'">Tắt sale</button>
		</div>
		<label style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
			<input type="checkbox" name="nexxi_quick_is_flash" value="1" <?php checked( '1' === (string) get_post_meta( $post->ID, '_nexxi_is_flash_sale', true ) ); ?>>
			<span>Đưa vào kệ Flash Sale (bỏ tích = chỉ giảm giá bán sản phẩm thường)</span>
		</label>
		<p style="margin:8px 0 0;padding-top:8px;border-top:1px solid #eee;display:flex;flex-direction:column;gap:4px;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-bulk-discount' ) ); ?>">🏷️ Công cụ Giảm Giá SP Hàng Loạt / Upload SKU →</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-flash-sale' ) ); ?>">⚡ Quản lý chiến dịch Flash Sale →</a>
		</p>
	</div>
	<?php
}

function nexxi_kids_save_product_discount_metabox( $post_id ) {
	if ( empty( $_POST['nexxi_product_quick_sale_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nexxi_product_quick_sale_nonce'] ) ), 'nexxi_product_quick_sale' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

	$reg_raw    = isset( $_POST['nexxi_quick_regular_price'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['nexxi_quick_regular_price'] ) ) ) : '';
	$sale_raw   = isset( $_POST['nexxi_quick_sale_val'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['nexxi_quick_sale_val'] ) ) ) : '';
	$flash_flag = ! empty( $_POST['nexxi_quick_is_flash'] ) ? '1' : '0';
	if ( '' === $reg_raw && '' === $sale_raw ) {
		return;
	}
	$reg = '' !== $reg_raw ? (float) $reg_raw : 0;
	if ( '0' === $sale_raw ) {
		nexxi_kids_apply_product_discount( $post_id, 'clear', 0, $reg, '0' );
		nexxi_kids_flush_sale_cache();
	} elseif ( '' !== $sale_raw ) {
		$num = (float) $sale_raw;
		$mode = $num <= 95 ? 'percent' : 'fixed';
		nexxi_kids_apply_product_discount( $post_id, $mode, $num, $reg, $flash_flag );
		nexxi_kids_flush_sale_cache();
	} elseif ( $reg > 0 ) {
		nexxi_kids_apply_product_discount( $post_id, 'clear', 0, $reg, '0' );
	}
}
add_action( 'woocommerce_process_product_meta', 'nexxi_kids_save_product_discount_metabox', 99 );
