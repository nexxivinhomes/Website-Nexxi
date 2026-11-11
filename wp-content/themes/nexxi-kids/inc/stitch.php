<?php
/**
 * Render pipeline for the Stitch screens.
 *
 * Each screen loads its approved Stitch HTML shell and swaps sections through
 * stable data-nx hooks (see tools/add-stitch-hooks.ps1 and inc/stitch-dom.php).
 * Screen modules: stitch-home.php, stitch-shop.php, stitch-product.php,
 * stitch-pages.php and stitch-cart.php.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Prevent LiteSpeed Cache / Hostinger Optimizer from delaying, combining, or deferring
 * Stitch Tailwind CSS, Google Fonts (Material Symbols), and interactive scripts.
 */
add_filter( 'litespeed_optm_js_delay_inc', '__return_empty_array', 999 );
add_filter( 'litespeed_conf_optm-js_defer', '__return_zero', 999 );
add_filter( 'litespeed_conf_optm-js_comb', '__return_zero', 999 );
add_filter( 'litespeed_conf_optm-css_comb', '__return_zero', 999 );
add_filter( 'litespeed_conf_optm-css_async', '__return_zero', 999 );
add_filter( 'litespeed_optm_js_defer_exc', static function ( $list ) {
	$list   = is_array( $list ) ? $list : array();
	$list[] = 'tailwind-play.js';
	$list[] = 'tailwind.config';
	$list[] = 'stitch-interactions.js';
	$list[] = 'NexxiStitch';
	$list[] = 'fbevents.js';
	$list[] = 'connect.facebook.net';
	$list[] = '3331438223710770';
	return $list;
}, 999 );
add_filter( 'litespeed_optm_css_exc', static function ( $list ) {
	$list   = is_array( $list ) ? $list : array();
	$list[] = 'fonts.googleapis.com';
	$list[] = 'Material+Symbols+Outlined';
	$list[] = 'woocommerce-stitch.css';
	$list[] = 'stitch-tailwind.css';
	return $list;
}, 999 );

add_action( 'init', static function () {
	if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$purge_ver = '20261111_pancake_price_sync_v55';
	if ( get_option( 'nexxi_lscache_purge_ver' ) !== $purge_ver ) {
		update_option( 'nexxi_lscache_purge_ver', $purge_ver, true );
		delete_transient( 'nexxi_zalo_scan_running_lock' );
		update_option( 'nexxi_zalo_next_scan_ts', time() - 5, false );
		// Remove legacy multi-MB var_export PHP catalog file that caused PHP memory exhaustion during batch AJAX scans
		$up_dir = wp_upload_dir();
		if ( ! empty( $up_dir['basedir'] ) ) {
			$bad_php_cache = trailingslashit( $up_dir['basedir'] ) . 'nexxi-pancake-catalog-v4.php';
			if ( file_exists( $bad_php_cache ) ) {
				if ( function_exists( 'opcache_invalidate' ) ) {
					@opcache_invalidate( $bad_php_cache, true );
				}
				@unlink( $bad_php_cache );
			}
		}
		if ( function_exists( 'nexxi_kids_ensure_pages' ) ) {
			nexxi_kids_ensure_pages();
			$priv_page = get_page_by_path( 'chinh-sach-bao-mat' );
			if ( $priv_page instanceof WP_Post ) {
				update_option( 'wp_page_for_privacy_policy', (int) $priv_page->ID );
			}
			$terms_page = get_page_by_path( 'dieu-khoan-su-dung' );
			if ( $terms_page instanceof WP_Post && ! get_option( 'woocommerce_terms_page_id' ) ) {
				update_option( 'woocommerce_terms_page_id', (int) $terms_page->ID );
			}
			flush_rewrite_rules( false );
		}
		if ( class_exists( 'Nexxi_SKU_SEO_Sync' ) ) {
			Nexxi_SKU_SEO_Sync::maybe_auto_sync();
		}
		if ( function_exists( 'nexxi_kids_sync_all_flash_sale_products' ) ) {
			nexxi_kids_sync_all_flash_sale_products();
		}
		// One-time version-aware sync of plugin files on live server (never downgrade a newer plugin!)
		$read_sync_ver = static function ( $fpath ) {
			if ( ! $fpath || ! is_readable( $fpath ) ) {
				return '0.0.0';
			}
			$head = (string) @file_get_contents( $fpath, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
			return preg_match( "/const\s+VERSION\s*=\s*['\"]([^'\"]+)['\"]/", $head, $m ) ? trim( $m[1] ) : '0.0.0';
		};
		$theme_pancake_file = get_template_directory() . '/inc/pancake-sync-admin.php';
		if ( defined( 'WP_PLUGIN_DIR' ) && file_exists( $theme_pancake_file ) ) {
			$theme_p_ver  = $read_sync_ver( $theme_pancake_file );
			$sync_targets = array(
				WP_PLUGIN_DIR . '/Website-Nexxi/includes/class-pancake-sync.php',
				WP_PLUGIN_DIR . '/nexxi-kids-commerce/includes/class-pancake-sync.php',
				WP_PLUGIN_DIR . '/nexxi-pancake-manager/includes/class-pancake-sync.php',
			);
			foreach ( $sync_targets as $target_sync_file ) {
				if ( file_exists( $target_sync_file ) && is_writable( $target_sync_file ) ) {
					$plugin_p_ver = $read_sync_ver( $target_sync_file );
					if ( version_compare( $theme_p_ver, $plugin_p_ver, '>' ) ) {
						@copy( $theme_pancake_file, $target_sync_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						if ( function_exists( 'opcache_invalidate' ) ) {
							@opcache_invalidate( $target_sync_file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						}
					}
				}
			}
		}
		$legacy_mgr_main = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/nexxi-pancake-manager/nexxi-pancake-manager.php' : '';
		if ( $legacy_mgr_main && file_exists( $legacy_mgr_main ) && is_writable( $legacy_mgr_main ) ) {
			$m_src    = (string) @file_get_contents( $legacy_mgr_main ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
			$m_ver    = preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/mi', $m_src, $mv ) ? trim( $mv[1] ) : '0.0.0';
			$has_trap = ( false !== strpos( $m_src, "! file_exists( get_template_directory() . '/inc/pancake-sync-admin.php' )" ) );
			if ( $has_trap || ( version_compare( '1.5.8', $m_ver, '>' ) && false === strpos( $m_src, 'NEXXI_PANCAKE_SYNC_BOOTED_VER' ) ) ) {
				$smart_loader = "<?php\n"
					. "/**\n"
					. " * Plugin Name: NEXXI Pancake SKU & Image Manager\n"
					. " * Description: Quản lý sản phẩm đã đồng bộ với Pancake POS bằng mã SKU, tự động quét ảnh trên Pancake để đưa về Website và lấy ảnh theo phân loại (Màu sắc) trên Pancake để set ảnh phân loại trên Website.\n"
					. " * Version: 1.5.8\n"
					. " * Author: NEXXI Kids\n"
					. " * Requires at least: 6.0\n"
					. " * Requires PHP: 7.4\n"
					. " * Requires Plugins: woocommerce\n"
					. " * Text Domain: nexxi-pancake-manager\n"
					. " */\n"
					. "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n"
					. "if ( ! function_exists( 'nexxi_pancake_extract_sync_file_version' ) ) {\n"
					. "\tfunction nexxi_pancake_extract_sync_file_version( \$file ) {\n"
					. "\t\tif ( ! \$file || ! is_readable( \$file ) ) {\n\t\t\treturn '0.0.0';\n\t\t}\n"
					. "\t\t\$head = (string) @file_get_contents( \$file, false, null, 0, 8192 );\n"
					. "\t\tif ( preg_match( \"/const\\s+VERSION\\s*=\\s*['\\\"]([^'\\\"]+)['\\\"]/\", \$head, \$m ) ) {\n\t\t\treturn trim( \$m[1] );\n\t\t}\n"
					. "\t\treturn '0.0.0';\n\t}\n}\n\n"
					. "\$nexxi_pancake_manager_boot = static function () {\n"
					. "\tif ( defined( 'NEXXI_PANCAKE_SYNC_BOOTED_VER' ) ) {\n\t\treturn;\n\t}\n"
					. "\t\$plugin_file = __DIR__ . '/includes/class-pancake-sync.php';\n"
					. "\t\$theme_file  = function_exists( 'get_template_directory' ) ? ( get_template_directory() . '/inc/pancake-sync-admin.php' ) : '';\n"
					. "\t\$plugin_ver  = nexxi_pancake_extract_sync_file_version( \$plugin_file );\n"
					. "\t\$theme_ver   = \$theme_file ? nexxi_pancake_extract_sync_file_version( \$theme_file ) : '0.0.0';\n"
					. "\tif ( version_compare( \$plugin_ver, \$theme_ver, '>=' ) ) {\n"
					. "\t\tif ( \$theme_file && version_compare( \$plugin_ver, \$theme_ver, '>' ) && file_exists( \$theme_file ) && is_writable( \$theme_file ) ) {\n"
					. "\t\t\t@copy( \$plugin_file, \$theme_file );\n"
					. "\t\t\tif ( function_exists( 'opcache_invalidate' ) ) {\n\t\t\t\t@opcache_invalidate( \$theme_file, true );\n\t\t\t}\n\t\t}\n"
					. "\t\tif ( is_readable( \$plugin_file ) ) {\n\t\t\trequire_once \$plugin_file;\n\t\t}\n"
					. "\t} elseif ( \$theme_file && is_readable( \$theme_file ) ) {\n"
					. "\t\tif ( file_exists( \$plugin_file ) && is_writable( \$plugin_file ) ) {\n"
					. "\t\t\t@copy( \$theme_file, \$plugin_file );\n"
					. "\t\t\tif ( function_exists( 'opcache_invalidate' ) ) {\n\t\t\t\t@opcache_invalidate( \$plugin_file, true );\n\t\t\t}\n\t\t}\n"
					. "\t\trequire_once \$theme_file;\n\t}\n};\n\n"
					. "if ( function_exists( 'get_template_directory' ) ) {\n\t\$nexxi_pancake_manager_boot();\n} else {\n\tadd_action( 'plugins_loaded', \$nexxi_pancake_manager_boot, 1 );\n}\n\n"
					. "add_action(\n\t'plugins_loaded',\n\tstatic function () use ( \$nexxi_pancake_manager_boot ) {\n"
					. "\t\t\$nexxi_pancake_manager_boot();\n"
					. "\t\tforeach ( array( 'Nexxi_Pancake_Sync_Manager_V7', 'Nexxi_Pancake_Sync_Manager_V6', 'Nexxi_Pancake_Sync_Manager' ) as \$cls ) {\n"
					. "\t\t\tif ( class_exists( \$cls, false ) && method_exists( \$cls, 'init' ) ) {\n\t\t\t\t\$cls::init();\n\t\t\t\tbreak;\n\t\t\t}\n\t\t}\n\t},\n\t5\n);\n";
				@file_put_contents( $legacy_mgr_main, $smart_loader ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
				if ( function_exists( 'opcache_invalidate' ) ) {
					@opcache_invalidate( $legacy_mgr_main, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
		// One-time repair for any variable products whose variations had 0/empty price on Pancake POS (e.g. 2TLSFR2H01)
		global $wpdb;
		$var_rows = $wpdb->get_results(
			"SELECT v.ID AS var_id, v.post_parent AS parent_id,
			        pm_reg.meta_value AS reg_price, pm_pri.meta_value AS act_price
			 FROM {$wpdb->posts} v
			 LEFT JOIN {$wpdb->postmeta} pm_reg ON v.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price'
			 LEFT JOIN {$wpdb->postmeta} pm_pri ON v.ID = pm_pri.post_id AND pm_pri.meta_key = '_price'
			 WHERE v.post_type = 'product_variation' AND v.post_status IN ('publish', 'private')"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $var_rows ) {
			$by_parent = array();
			foreach ( $var_rows as $vr ) {
				$pid = (int) $vr->parent_id;
				if ( $pid <= 0 ) {
					continue;
				}
				$by_parent[ $pid ][] = array(
					'vid' => (int) $vr->var_id,
					'reg' => (float) $vr->reg_price,
					'pri' => (float) $vr->act_price,
				);
			}
			$p_maps = null;
			foreach ( $by_parent as $pid => $vlist ) {
				$min_valid_reg = 0.0;
				$missing_vids  = array();
				foreach ( $vlist as $vi ) {
					$r = $vi['reg'] > 0 ? $vi['reg'] : $vi['pri'];
					if ( $r > 0 ) {
						if ( $min_valid_reg <= 0 || $r < $min_valid_reg ) {
							$min_valid_reg = $r;
						}
					} else {
						$missing_vids[] = $vi['vid'];
					}
				}
				if ( empty( $missing_vids ) ) {
					continue;
				}
				$pancake_cls = '';
				foreach ( array( 'Nexxi_Pancake_Sync_Manager_V7', 'Nexxi_Pancake_Sync_Manager_V6', 'Nexxi_Pancake_Sync_Manager_V5', 'Nexxi_Pancake_Sync_Manager_V4' ) as $cand_p_cls ) {
					if ( class_exists( $cand_p_cls ) ) {
						$pancake_cls = $cand_p_cls;
						break;
					}
				}
				if ( $min_valid_reg <= 0 && $pancake_cls ) {
					if ( null === $p_maps ) {
						$p_cat  = $pancake_cls::get_pancake_catalog( false );
						$p_maps = $pancake_cls::build_lookup_maps( $p_cat );
					}
					$p_sku = strtoupper( trim( (string) ( get_post_meta( $pid, '_nexxi_pancake_sku', true ) ?: get_post_meta( $pid, '_sku', true ) ) ) );
					if ( '' !== $p_sku && isset( $p_maps['by_sku'][ $p_sku ]['min_price'] ) ) {
						$min_valid_reg = (float) $p_maps['by_sku'][ $p_sku ]['min_price'];
					}
				}
				if ( $min_valid_reg > 0 ) {
					$p_str = (string) round( $min_valid_reg );
					foreach ( $missing_vids as $mvid ) {
						update_post_meta( $mvid, '_regular_price', $p_str );
						update_post_meta( $mvid, '_price', $p_str );
					}
					if ( (float) get_post_meta( $pid, '_price', true ) <= 0 ) {
						update_post_meta( $pid, '_price', $p_str );
					}
					if ( function_exists( 'wc_delete_product_transients' ) ) {
						wc_delete_product_transients( $pid );
					}
					clean_post_cache( $pid );
				}
			}
		}
		// Clean up any product that has _nexxi_is_flash_sale = '1' without a valid _nexxi_fs_sale_price
		global $wpdb;
		$fs_pids = $wpdb->get_col(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_nexxi_is_flash_sale' AND meta_value = '1'"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $fs_pids as $fpid ) {
			$fpid = (int) $fpid;
			$fsp  = (float) get_post_meta( $fpid, '_nexxi_fs_sale_price', true );
			$fpct = (int) get_post_meta( $fpid, '_nexxi_fs_sale_pct', true );
			if ( $fsp <= 0 && $fpct <= 0 ) {
				update_post_meta( $fpid, '_nexxi_is_flash_sale', '0' );
			}
		}
		// Clean up any product that has _nexxi_has_prod_sale = '1' without a valid discount price/percent
		$ps_pids = $wpdb->get_col(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_nexxi_has_prod_sale' AND meta_value = '1'"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $ps_pids as $psid ) {
			$psid = (int) $psid;
			$psp  = (float) get_post_meta( $psid, '_nexxi_prod_sale_price', true );
			$ppct = (int) get_post_meta( $psid, '_nexxi_prod_sale_pct', true );
			if ( $psp <= 0 && $ppct <= 0 ) {
				update_post_meta( $psid, '_nexxi_has_prod_sale', '0' );
			}
		}
		// Clean up legacy 30-day, 299k & unverified claims in database pages and studio theme_mod.
		$saved = get_theme_mod( 'nexxi_studio', array() );
		if ( is_array( $saved ) && $saved ) {
			$changed_mod = false;
			foreach ( $saved as $k => $v ) {
				if ( preg_match( '~(_extra_links|_hide_\d+)$~', (string) $k ) ) {
					continue;
				}
				if ( is_scalar( $v ) && preg_match( '~(organic|hữu\s*cơ|sợi\s*tre|bamboo|oeko|30\s*ngày|kháng\s*khuẩn|tơ\s*tằm|lọt\s*lòng|kẹo\s*bông|50\.000đ|voucher\s*50k|nhận\s*quà\s*chào\s*đón\s*50k|kẹp\s*nơ|vớ\s*gấu|hỏa\s*tốc\s*2h|0\s*gây\s*ngứa|không\s*mẩn\s*ngứa|50\s*lần\s*giặt|mẹ\s*thảo\s*linh|chuẩn\s*style\s*nhật|10\.000\+\s*mẹ|100%\s*vải\s*lành|đơn\s*từ\s*199k|299k|299\.000|flash10|freeshipxinh|beyeu20|tặng\s*20k|bảo\s*hành\s*an\s*toàn)~iu', (string) $v ) ) {
					unset( $saved[ $k ] );
					$changed_mod = true;
				}
			}
			if ( $changed_mod ) {
				set_theme_mod( 'nexxi_studio', $saved );
			}
		}
		if ( function_exists( 'nexxi_kids_default_pages' ) ) {
			$defs = nexxi_kids_default_pages();
			foreach ( array( 'doi-tra', 'lien-he', 'van-chuyen' ) as $slug ) {
				if ( ! isset( $defs[ $slug ] ) ) {
					continue;
				}
				$page = get_page_by_path( $slug );
				if ( $page instanceof WP_Post && ( false !== stripos( $page->post_content, '30 ngày' ) || false !== stripos( $page->post_content, 'hộp quà' ) || false !== stripos( $page->post_content, '90 ngày' ) || false !== stripos( $page->post_content, '299' ) || false !== stripos( $page->post_content, 'Hà Nội' ) || false !== stripos( $page->post_content, '2–4 ngày' ) || false !== stripos( $page->post_content, '2-4 ngày' ) ) ) {
					wp_update_post( array(
						'ID'           => $page->ID,
						'post_title'   => $defs[ $slug ]['title'],
						'post_content' => $defs[ $slug ]['content'],
					) );
				}
			}
		}
		if ( function_exists( 'nexxi_seo_run_p0_migration' ) ) {
			nexxi_seo_run_p0_migration();
		}
		do_action( 'litespeed_purge_all' );
		if ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
			\LiteSpeed\Purge::purge_all();
		}
	}
}, 5 );

/**
 * One-time normalization of multi-price variable product discounts (e.g. 140k / 280k / 300k - 40% -> 84k / 168k / 180k).
 * Runs at init priority 20 after WooCommerce post types and product data stores are fully registered.
 */
add_action( 'init', static function () {
	if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return;
	}
	if ( ! is_admin() ) {
		return;
	}
	if ( '1' === (string) get_option( 'nexxi_sale_var_pct_fix_v1', '0' ) ) {
		return;
	}
	if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'nexxi_kids_resync_product_sale_prices' ) ) {
		return;
	}
	if ( get_transient( 'nexxi_sale_var_pct_fix_lock' ) ) {
		return;
	}
	set_transient( 'nexxi_sale_var_pct_fix_lock', 1, 15 );

	try {
		global $wpdb;
		$cursor_id  = (int) get_option( 'nexxi_sale_var_pct_fix_cursor', 0 );
		$batch_size = 35;
		$sale_pids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE p.post_type = 'product'
				   AND p.ID > %d
				   AND pm.meta_key IN ('_nexxi_has_prod_sale', '_nexxi_is_flash_sale')
				   AND pm.meta_value = '1'
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$cursor_id,
				$batch_size
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$sale_pids = array_values( array_unique( array_filter( array_map( 'absint', (array) $sale_pids ) ) ) );
		if ( empty( $sale_pids ) ) {
			update_option( 'nexxi_sale_var_pct_fix_v1', '1', true );
			delete_option( 'nexxi_sale_var_pct_fix_cursor' );
			return;
		}

		update_meta_cache( 'post', $sale_pids );
		$any_changed  = false;
		$processed    = 0;
		$last_done_id = $cursor_id;
		$t_start      = microtime( true );

		foreach ( $sale_pids as $spid ) {
			if ( nexxi_kids_resync_product_sale_prices( $spid ) ) {
				$any_changed = true;
			}
			$last_done_id = (int) $spid;
			$processed++;
			if ( ( microtime( true ) - $t_start ) >= 3.5 ) {
				break;
			}
		}

		if ( $processed === count( $sale_pids ) && count( $sale_pids ) < $batch_size ) {
			update_option( 'nexxi_sale_var_pct_fix_v1', '1', true );
			delete_option( 'nexxi_sale_var_pct_fix_cursor' );
		} else {
			update_option( 'nexxi_sale_var_pct_fix_cursor', $last_done_id, false );
		}

		if ( $any_changed ) {
			delete_transient( 'wc_products_onsale' );
			if ( function_exists( 'nexxi_kids_reset_sale_runtime_cache' ) ) {
				nexxi_kids_reset_sale_runtime_cache();
			}
			if ( function_exists( 'nexxi_kids_flush_sale_cache' ) ) {
				nexxi_kids_flush_sale_cache();
			}
			do_action( 'litespeed_purge_all' );
			if ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
				\LiteSpeed\Purge::purge_all();
			}
		}
	} finally {
		delete_transient( 'nexxi_sale_var_pct_fix_lock' );
	}
}, 20 );

/** Pre-compiled static Tailwind CSS tag inlined into <head> for zero-FOUC rendering. */
function nexxi_kids_stitch_compiled_tailwind_tag() {
	static $cached_tag = null;
	if ( null !== $cached_tag ) {
		return $cached_tag;
	}
	$file = get_template_directory() . '/assets/stitch/stitch-tailwind.css';
	if ( ! is_readable( $file ) ) {
		$cached_tag = '';
		return $cached_tag;
	}
	$css = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( function_exists( 'nexxi_studio_color_map' ) ) {
		$map = nexxi_studio_color_map();
		if ( $map ) {
			$css = str_ireplace( array_keys( $map ), array_values( $map ), $css );
		}
	}
	$cached_tag = '<style id="nexxi-stitch-tailwind-compiled" data-no-optimize="1">' . $css . '</style>';
	return $cached_tag;
}

function nexxi_kids_stitch_url( $name ) {
	switch ( $name ) {
		case 'index.html': return home_url( '/' );
		case 'shop.html': return nexxi_kids_shop_url();
		case 'flash-sale.html': return nexxi_kids_flash_url();
		case 'cart.html': return function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/gio-hang/' );
		case 'checkout.html': return function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/thanh-toan/' );
		case 'account.html':
		case 'login.html':
		case 'members.html': return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/tai-khoan/' );
		case 'product.html': return nexxi_kids_shop_url();
	}
	return home_url( '/' );
}

/** Kept for backwards compatibility with older helpers. */
function nexxi_kids_stitch_money( $amount ) {
	return nexxi_kids_money( $amount );
}

/** Lock multi-word navigation labels to the one-line Stitch header composition. */
function nexxi_kids_stitch_lock_header_labels( $html ) {
	$labels = array(
		'Trang Chủ'   => 'Trang&nbsp;Chủ',
		'Giới Thiệu'  => 'Giới&nbsp;Thiệu',
		'Sản Phẩm'    => 'Sản&nbsp;Phẩm',
		'Bé Yêu'      => 'Bé&nbsp;Yêu',
		'Flash Sale'  => 'Flash&nbsp;Sale',
		'Bảng Size'   => 'Bảng&nbsp;Size',
		'Liên Hệ'     => 'Liên&nbsp;Hệ',
	);
	$el = nexxi_dom_locate( $html, '<header' );
	if ( ! $el ) { return $html; }
	$header = substr( $html, $el['start'], $el['end'] - $el['start'] );
	return substr( $html, 0, $el['start'] ) . str_replace( array_keys( $labels ), array_values( $labels ), $header ) . substr( $html, $el['end'] );
}

/** The approved home header, reused verbatim on every Stitch screen. */
function nexxi_kids_stitch_shared_header() {
	static $header = null;
	if ( null !== $header ) { return $header; }
	$file = get_template_directory() . '/assets/stitch/pages/index.html';
	$source = is_readable( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$header = nexxi_dom_get_outer( $source, '<header' );
	if ( $header ) { $header = preg_replace( '/^<header/', '<header data-nexxi-shared-header="1"', $header, 1 ); }
	return $header;
}

/** Nav item that is active for a screen. */
function nexxi_kids_active_nav( $screen ) {
	if ( 'page' === $screen ) {
		$kind = nexxi_kids_page_kind();
		return 'about' === $kind ? 'gioi-thieu' : ( 'contact' === $kind ? 'lien-he' : '' );
	}
	$map = array( 'index' => 'trang-chu', 'shop' => 'san-pham', 'product' => 'san-pham', 'flash-sale' => 'flash-sale' );
	return isset( $map[ $screen ] ) ? $map[ $screen ] : '';
}

/** Wishlist size for the header badge (account value for members, JS updates guests). */
function nexxi_kids_wishlist_count() {
	return is_user_logged_in() && class_exists( 'Nexxi_Kids_Catalog' ) ? count( Nexxi_Kids_Catalog::wishlist_ids( get_current_user_id() ) ) : 0;
}

/** Wire the shared header: routes, active state, account/cart/wishlist and search. */
function nexxi_kids_stitch_header( $html, $screen ) {
	$el = nexxi_dom_locate( $html, '<header' );
	if ( ! $el ) { return $html; }
	$header = substr( $html, $el['start'], $el['end'] - $el['start'] );

	$routes = array(
		'trang-chu'           => home_url( '/' ),
		'gioi-thieu'          => nexxi_kids_page_url( 'gioi-thieu' ),
		'san-pham'            => nexxi_kids_shop_url(),
		'flash-sale'          => nexxi_kids_flash_url(),
		'bang-size-cho-be'    => home_url( '/#size-guide' ),
		'lien-he'             => nexxi_kids_page_url( 'lien-he' ),
		'danh-sach-yeu-thich' => nexxi_kids_page_url( 'yeu-thich' ),
		'gio-hang'            => nexxi_kids_stitch_url( 'cart.html' ),
	);
	$active_path = nexxi_kids_active_nav( $screen );

	// Navigation pills: same base classes everywhere, only the active state changes.
	$nav = nexxi_dom_locate( $header, '<nav' );
	if ( $nav ) {
		$nav_html = substr( $header, $nav['start'], $nav['end'] - $nav['start'] );
		$active = preg_match( '~data-active-classes="([^"]*)"~', $nav_html, $m ) ? preg_split( '~\s+~', trim( $m[1] ) ) : array();
		$idle = array( 'text-on-surface-variant', 'hover:text-on-surface', 'hover:bg-surface-container-highest' );
		$nav_html = preg_replace_callback( '~<a\b[^>]*\bdata-path="([^"]+)"[^>]*>~', static function ( $m ) use ( $routes, $active_path, $active, $idle ) {
			$open = $m[0];
			$path = $m[1];
			$open = preg_replace( '~\saria-current="[^"]*"~', '', $open );
			if ( isset( $routes[ $path ] ) && false === strpos( $open, 'data-href-fixed' ) ) {
				$open = nexxi_dom_tag_with_attr( $open, 'href', $routes[ $path ] );
				if ( false !== strpos( (string) $routes[ $path ], '?' ) ) {
					$open = nexxi_dom_tag_with_attr( $open, 'rel', 'nofollow' );
				}
			}
			if ( preg_match( '~\sclass="([^"]*)"~', $open, $c ) ) {
				$classes = array_diff( preg_split( '~\s+~', trim( $c[1] ) ), $active, $idle, array( 'font-label-lg', 'text-label-lg' ) );
				$classes = array_merge( $classes, array( 'font-label-lg', 'text-label-lg' ), $path === $active_path ? $active : $idle );
				$open = nexxi_dom_tag_with_attr( $open, 'class', implode( ' ', array_unique( $classes ) ) );
			}
			if ( $path === $active_path ) { $open = preg_replace( '~^<a\b~', '<a aria-current="page"', $open ); }
			return $open;
		}, $nav_html );
		$header = substr( $header, 0, $nav['start'] ) . $nav_html . substr( $header, $nav['end'] );
	}

	// Icon links (wishlist, cart) outside the nav.
	$counts = array( 'danh-sach-yeu-thich' => array( 'data-nexxi-wishlist-count', nexxi_kids_wishlist_count() ), 'gio-hang' => array( 'data-nexxi-cart-count', nexxi_kids_cart_count() ) );
	foreach ( $counts as $path => $badge ) {
		$header = nexxi_dom_each( $header, 'data-path="' . $path . '"', static function ( $outer ) use ( $routes, $path, $badge ) {
			$outer = preg_replace_callback( '~^<a\b[^>]*>~', static function ( $m ) use ( $routes, $path ) {
				$label = 'gio-hang' === $path ? 'Giỏ hàng' : 'Danh sách yêu thích';
				return nexxi_dom_tag_with_attr( nexxi_dom_tag_with_attr( nexxi_dom_tag_with_attr( $m[0], 'href', $routes[ $path ] ), 'aria-label', $label ), 'rel', 'nofollow' );
			}, $outer, 1 );
			return preg_replace( '~(<span\b[^>]*class="[^"]*rounded-full[^"]*")([^>]*>)\s*\d*\s*(</span>)~', '${1} ' . $badge[0] . '${2}' . (int) $badge[1] . '${3}', $outer, 1 );
		} );
	}

	// Account avatar → account link.
	$avatar = nexxi_dom_locate( $header, '<div class="w-8 h-8 rounded-full bg-primary' );
	if ( $avatar ) {
		$open = substr( $header, $avatar['start'], $avatar['open_end'] - $avatar['start'] );
		$open = preg_replace( '~^<div\b~', '<a', substr( $open, 0, -1 ) ) . ' href="' . esc_url( nexxi_kids_stitch_url( 'account.html' ) ) . '" rel="nofollow" aria-label="' . esc_attr( is_user_logged_in() ? 'Tài khoản của mẹ' : 'Đăng nhập / Đăng ký' ) . '">';
		$inner = substr( $header, $avatar['inner_start'], $avatar['inner_end'] - $avatar['inner_start'] );
		$header = substr( $header, 0, $avatar['start'] ) . $open . $inner . '</a>' . substr( $header, $avatar['end'] );
	}

	// Logo → home.
	$logo = nexxi_dom_locate( $header, '<img' );
	if ( $logo ) {
		$img = substr( $header, $logo['start'], $logo['end'] - $logo['start'] );
		$img = nexxi_dom_tag_with_attr( $img, 'alt', 'NEXXI Kids' );
		$header = substr( $header, 0, $logo['start'] ) . '<a class="shrink-0" href="' . esc_url( home_url( '/' ) ) . '" aria-label="NEXXI Kids – Trang chủ">' . $img . '</a>' . substr( $header, $logo['end'] );
	}

	// Search box.
	$header = nexxi_dom_each( $header, '<input', static function ( $outer ) {
		$outer = nexxi_dom_tag_with_attr( $outer, 'type', 'search' );
		$outer = nexxi_dom_tag_with_attr( $outer, 'value', get_search_query() );
		$outer = nexxi_dom_tag_with_attr( $outer, 'aria-label', 'Tìm sản phẩm' );
		return preg_replace( '~^<input\b~', '<input data-nexxi-search', $outer, 1 );
	} );
	$search = nexxi_dom_locate( $header, '<button' );
	if ( $search ) {
		$open = substr( $header, $search['start'], $search['open_end'] - $search['start'] );
		$open = preg_replace( '~^<button\b~', '<button data-nexxi-search-btn aria-label="Tìm kiếm"', $open, 1 );
		$header = substr( $header, 0, $search['start'] ) . $open . substr( $header, $search['open_end'] );
	}

	// Inject Mobile Search Toggle + Hamburger Menu Button into the right header actions bar.
	$mobile_btns = '<button type="button" class="nexxi-mob-icon-btn md:hidden w-9 h-9 rounded-full bg-surface-container-low hover:bg-surface-container flex items-center justify-center text-on-surface-variant hover:text-secondary transition-all shrink-0" data-nexxi-mob-search-open aria-label="Tìm kiếm sản phẩm"><span class="material-symbols-outlined text-[20px]">search</span></button>'
		. '<button type="button" class="nexxi-mob-icon-btn lg:hidden w-9 h-9 rounded-full bg-primary text-on-primary flex items-center justify-center shadow-sm transition-all shrink-0" data-nexxi-mob-menu-open aria-label="Mở menu điều hướng" aria-expanded="false"><span class="material-symbols-outlined text-[20px]">menu</span></button>';

	$actions_pos = strpos( $header, '<div class="flex items-center gap-space-sm' );
	if ( false !== $actions_pos ) {
		$actions_el = nexxi_dom_locate( $header, '<div class="flex items-center gap-space-sm' );
		if ( $actions_el ) {
			$header = substr( $header, 0, $actions_el['inner_end'] ) . $mobile_btns . substr( $header, $actions_el['inner_end'] );
		}
	}

	return substr( $html, 0, $el['start'] ) . $header . substr( $html, $el['end'] );
}

/** Mobile Drawer (Search + Navigation + Contact) and Sticky Bottom Tab Bar for phones/tablets. */
function nexxi_kids_mobile_shell_ui( $screen ) {
	if ( 'login' === $screen ) {
		return '';
	}
	$active_path = nexxi_kids_active_nav( $screen );
	$home_url    = home_url( '/' );
	$shop_url    = nexxi_kids_shop_url();
	$flash_url   = nexxi_kids_flash_url();
	$about_url   = nexxi_kids_page_url( 'gioi-thieu' );
	$size_url    = home_url( '/#size-guide' );
	$contact_url = nexxi_kids_page_url( 'lien-he' );
	$wish_url    = nexxi_kids_page_url( 'yeu-thich' );
	$cart_url    = nexxi_kids_stitch_url( 'cart.html' );
	$acct_url    = nexxi_kids_stitch_url( 'account.html' );
	$hotline     = nexxi_kids_setting( 'nexxi_kids_hotline' );
	$chat_url    = nexxi_kids_setting( 'nexxi_kids_chat_url' );
	$cart_cnt    = nexxi_kids_cart_count();
	$wish_cnt    = nexxi_kids_wishlist_count();
	$logo_url    = get_template_directory_uri() . '/assets/stitch/images/logo.jpg';

	ob_start();
	?>
	<!-- NEXXI MOBILE NAVIGATION & SEARCH DRAWER -->
	<div id="nexxi-mobile-drawer" class="nexxi-mobile-drawer" aria-hidden="true">
		<div class="nexxi-mobile-drawer__backdrop" data-nexxi-mob-close></div>
		<div class="nexxi-mobile-drawer__panel" role="dialog" aria-label="Menu điều hướng NEXXI Kids">
			<div class="nexxi-mobile-drawer__head">
				<a href="<?php echo esc_url( $home_url ); ?>" class="flex items-center gap-2">
					<img src="<?php echo esc_url( $logo_url ); ?>" alt="NEXXI Kids" style="height:28px;width:auto;object-fit:contain;">
					<span class="font-label-sm text-secondary bg-secondary-fixed px-2 py-0.5 rounded-full uppercase" style="font-size:10px;font-weight:700;">Kids FASHION</span>
				</a>
				<button type="button" class="nexxi-mobile-drawer__close" data-nexxi-mob-close aria-label="Đóng menu">
					<span class="material-symbols-outlined">close</span>
				</button>
			</div>

			<div class="nexxi-mobile-drawer__search">
				<div class="nexxi-mobile-drawer__search-box">
					<input type="search" data-nexxi-search data-nexxi-mob-search-input value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="Mẹ muốn tìm đồ xinh cho bé..." aria-label="Tìm sản phẩm">
					<button type="button" data-nexxi-search-btn aria-label="Tìm kiếm">
						<span class="material-symbols-outlined text-[18px]">search</span>
					</button>
				</div>
			</div>

			<nav class="nexxi-mobile-drawer__nav" aria-label="Menu di động">
				<a href="<?php echo esc_url( $home_url ); ?>" class="nexxi-mobile-drawer__link <?php echo 'trang-chu' === $active_path ? 'is-active' : ''; ?>">
					<span class="material-symbols-outlined">home</span>
					<span>Trang Chủ</span>
				</a>
				<a href="<?php echo esc_url( $shop_url ); ?>" class="nexxi-mobile-drawer__link <?php echo 'san-pham' === $active_path ? 'is-active' : ''; ?>">
					<span class="material-symbols-outlined">checkroom</span>
					<span>Sản Phẩm Bé Yêu</span>
					<span class="nexxi-mobile-drawer__badge bg-tertiary-fixed text-on-tertiary-fixed">Mới</span>
				</a>
				<a href="<?php echo esc_url( $flash_url ); ?>" rel="nofollow" class="nexxi-mobile-drawer__link <?php echo 'flash-sale' === $active_path ? 'is-active' : ''; ?>">
					<span class="material-symbols-outlined text-secondary">bolt</span>
					<span>Flash Sale Giờ Vàng</span>
					<span class="nexxi-mobile-drawer__badge bg-secondary text-on-secondary">⚡ Hot</span>
				</a>
				<a href="<?php echo esc_url( $about_url ); ?>" class="nexxi-mobile-drawer__link <?php echo 'gioi-thieu' === $active_path ? 'is-active' : ''; ?>">
					<span class="material-symbols-outlined">auto_awesome</span>
					<span>Giới Thiệu NEXXI</span>
				</a>
				<a href="<?php echo esc_url( $size_url ); ?>" class="nexxi-mobile-drawer__link" data-nexxi-mob-close>
					<span class="material-symbols-outlined">straighten</span>
					<span>Bảng Size Cho Bé (12–45kg)</span>
				</a>
				<a href="<?php echo esc_url( $contact_url ); ?>" class="nexxi-mobile-drawer__link <?php echo 'lien-he' === $active_path ? 'is-active' : ''; ?>">
					<span class="material-symbols-outlined">support_agent</span>
					<span>Liên Hệ &amp; Hỗ Trợ</span>
				</a>
				<div class="nexxi-mobile-drawer__divider"></div>
				<a href="<?php echo esc_url( $wish_url ); ?>" rel="nofollow" class="nexxi-mobile-drawer__link">
					<span class="material-symbols-outlined">favorite</span>
					<span>Danh Sách Yêu Thích</span>
					<span class="nexxi-mobile-drawer__badge bg-secondary-fixed text-on-secondary-fixed" data-nexxi-wishlist-count><?php echo (int) $wish_cnt; ?></span>
				</a>
				<a href="<?php echo esc_url( $cart_url ); ?>" rel="nofollow" class="nexxi-mobile-drawer__link">
					<span class="material-symbols-outlined">shopping_bag</span>
					<span>Giỏ Hàng Của Mẹ</span>
					<span class="nexxi-mobile-drawer__badge bg-primary text-on-primary" data-nexxi-cart-count><?php echo (int) $cart_cnt; ?></span>
				</a>
				<a href="<?php echo esc_url( $acct_url ); ?>" rel="nofollow" class="nexxi-mobile-drawer__link">
					<span class="material-symbols-outlined">person</span>
					<span><?php echo esc_html( is_user_logged_in() ? 'Tài Khoản Của Mẹ' : 'Đăng Nhập / Đăng Ký' ); ?></span>
				</a>
			</nav>

			<div class="nexxi-mobile-drawer__foot">
				<?php if ( $chat_url ) : ?>
					<a href="<?php echo esc_url( $chat_url ); ?>" target="_blank" rel="noopener" class="nexxi-mobile-drawer__cta nexxi-mobile-drawer__cta--zalo">
						<span class="material-symbols-outlined text-[18px]">forum</span>
						<span>Nhắn Zalo Tư Vấn Size</span>
					</a>
				<?php endif; ?>
				<?php if ( $hotline ) : ?>
					<a href="<?php echo esc_url( nexxi_kids_tel( $hotline ) ); ?>" class="nexxi-mobile-drawer__cta nexxi-mobile-drawer__cta--call">
						<span class="material-symbols-outlined text-[18px]">call</span>
						<span>Hotline: <?php echo esc_html( $hotline ); ?></span>
					</a>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/** Footer links mapped by their visible label (the footer copy itself stays Stitch). */
function nexxi_kids_stitch_footer( $html ) {
	$el = nexxi_dom_locate( $html, '<footer' );
	if ( ! $el ) { return $html; }
	$footer = substr( $html, $el['start'], $el['end'] - $el['start'] );
	$hotline = nexxi_kids_setting( 'nexxi_kids_hotline' );
	$hours   = nexxi_kids_setting( 'nexxi_kids_hours' );
	$chat    = nexxi_kids_setting( 'nexxi_kids_chat_url' );
	$orders = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : nexxi_kids_stitch_url( 'account.html' );
	$rules = array(
		array( 'bé gái', add_query_arg( 'cat_group', 'dam-vay', nexxi_kids_shop_url() ) ),
		array( 'bé trai', add_query_arg( 'cat_group', 'ao-thoi-trang', nexxi_kids_shop_url() ) ),
		array( 'set phối', add_query_arg( 'cat_group', 'set-bo', nexxi_kids_shop_url() ) ),
		array( 'sơ sinh', add_query_arg( 'cat_group', 'set-bo', nexxi_kids_shop_url() ) ),
		array( 'flash sale', nexxi_kids_flash_url() ),
		array( 'size', home_url( '/#size-guide' ) ),
		array( 'đổi trả', nexxi_kids_page_url( 'doi-tra' ) ),
		array( 'đổi hàng', nexxi_kids_page_url( 'doi-tra' ) ),
		array( 'bảo hành', nexxi_kids_page_url( 'doi-tra' ) . '#bao-hanh' ),
		array( 'bảo mật', nexxi_kids_page_url( 'chinh-sach-bao-mat' ) ),
		array( 'điều khoản', nexxi_kids_page_url( 'dieu-khoan-su-dung' ) ),
		array( 'quy chế', nexxi_kids_page_url( 'dieu-khoan-su-dung' ) ),
		array( 'tra cứu', $orders ),
		array( 'faq', nexxi_kids_page_url( 'lien-he' ) . '#faq' ),
		array( 'vận chuyển', nexxi_kids_page_url( 'van-chuyen' ) ),
		array( 'giao hàng', nexxi_kids_page_url( 'van-chuyen' ) ),
		array( 'liên hệ', nexxi_kids_page_url( 'lien-he' ) ),
		array( 'giới thiệu', nexxi_kids_page_url( 'gioi-thieu' ) ),
	);
	$footer = preg_replace_callback( '~(<a\b[^>]*>)(.*?)(</a>)~s', static function ( $m ) use ( $rules, $hotline, $chat ) {
		if ( false !== strpos( $m[1], 'data-nxs-custom-link' ) || false !== strpos( $m[1], 'data-href-fixed' ) ) {
			return $m[0];
		}
		$label = function_exists( 'mb_strtolower' ) ? mb_strtolower( wp_strip_all_tags( $m[2] ) ) : strtolower( wp_strip_all_tags( $m[2] ) );
		if ( false !== strpos( $label, 'hotline' ) ) {
			return nexxi_dom_tag_with_attr( $m[1], 'href', nexxi_kids_tel( $hotline ) ) . 'Hotline: ' . esc_html( $hotline ) . $m[3];
		}
		if ( false !== strpos( $label, 'zalo' ) && $chat ) {
			$open = nexxi_dom_tag_with_attr( $m[1], 'href', $chat );
			return $open . 'Nhắn tin Zalo: ' . esc_html( $hotline ) . $m[3];
		}
		foreach ( $rules as $rule ) {
			if ( false !== strpos( $label, $rule[0] ) ) { $open = nexxi_dom_tag_with_attr( $m[1], 'href', $rule[1] ); if ( false !== strpos( (string) $rule[1], '?' ) || 'tra cứu' === $rule[0] ) { $open = nexxi_dom_tag_with_attr( $open, 'rel', 'nofollow' ); } return $open . $m[2] . $m[3]; }
		}
		return $m[0];
	}, $footer );
	if ( $hours ) {
		$footer = preg_replace( '~(<li[^>]*data-nexxi-footer-hours[^>]*>).*?(</li>)~s', '$1Giờ mở cửa: ' . esc_html( $hours ) . '$2', $footer );
	}
	$footer = preg_replace( '~©\s*20\d\d~u', '© ' . wp_date( 'Y' ), $footer );
	return substr( $html, 0, $el['start'] ) . $footer . substr( $html, $el['end'] );
}

function nexxi_kids_stitch_login_forms( $html ) {
	$prefill_email = isset( $_GET['nexxi_login_email'] ) ? sanitize_email( wp_unslash( $_GET['nexxi_login_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$html = preg_replace_callback( '~<form\b[^>]*onsubmit="handleAuthSubmit\(event, \'(login|register)\'\)"[^>]*>.*?</form>~s', static function ( $match ) use ( $prefill_email ) {
		$kind = $match[1];
		$form = $match[0];
		if ( 'login' === $kind ) {
			$form = str_replace( 'onsubmit="handleAuthSubmit(event, \'login\')"', 'method="post"', $form );
			$val_attr = $prefill_email ? ' value="' . esc_attr( $prefill_email ) . '"' : '';
			$form = str_replace( 'id="login-account"', 'id="login-account" name="username" autocomplete="username"' . $val_attr, $form );
			$form = str_replace( 'id="login-password"', 'id="login-password" name="password" autocomplete="current-password"', $form );
			$form = str_replace( 'type="checkbox"', 'type="checkbox" name="rememberme" value="forever"', $form );
			$form = str_replace( 'type="submit"', 'type="submit" name="login" value="Đăng nhập"', $form );
			$form = str_replace( 'href="#"', 'href="' . esc_url( wp_lostpassword_url() ) . '"', $form );
			$nonce = wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce', true, false );
		} else {
			$form = str_replace( 'onsubmit="handleAuthSubmit(event, \'register\')"', 'method="post"', $form );
			$form = str_replace( 'id="reg-email"', 'id="reg-email" name="email" autocomplete="email"', $form );
			$form = str_replace( 'id="reg-password"', 'id="reg-password" name="password" autocomplete="new-password"', $form );
			$form = str_replace( 'id="reg-phone"', 'id="reg-phone" name="billing_phone" autocomplete="tel"', $form );
			$form = str_replace( 'id="reg-fullname"', 'id="reg-fullname" name="billing_first_name" autocomplete="name"', $form );
			$form = str_replace( 'type="submit"', 'type="submit" name="register" value="Đăng ký"', $form );
			$nonce = wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce', true, false );
		}
		return str_replace( '</form>', $nonce . '</form>', $form );
	}, $html );

	if ( function_exists( 'wc_print_notices' ) ) {
		ob_start();
		wc_print_notices();
		$notices_html = trim( ob_get_clean() );
		if ( $prefill_email ) {
			$notices_html .= '<div class="mb-4 p-3.5 rounded-xl bg-secondary-fixed/60 border border-secondary text-primary font-body-sm text-body-sm">Vui lòng đăng nhập tài khoản <strong>' . esc_html( $prefill_email ) . '</strong> (Mật khẩu mặc định là <strong>Số điện thoại đặt hàng</strong> nếu tài khoản được tạo tự động) để kiểm tra điểm thành viên NEXXI và ưu đãi của bạn.</div>';
		}
		if ( '' !== $notices_html ) {
			$html = str_replace(
				'<div class="space-y-space-md" id="login-form-container">',
				'<div class="nexxi-login-notices mb-space-md">' . $notices_html . '</div><div class="space-y-space-md" id="login-form-container">',
				$html
			);
		}
	}

	$html = preg_replace( '~(<a\b[^>]*href=")#("[^>]*>\s*<span[^>]*>arrow_back</span>)~s', '$1' . esc_url( home_url( '/' ) ) . '$2', $html, 1 );
	$hotline = nexxi_kids_setting( 'nexxi_kids_hotline' );
	$hours   = nexxi_kids_setting( 'nexxi_kids_hours' );
	$chat    = nexxi_kids_setting( 'nexxi_kids_chat_url' );
	if ( $hotline ) {
		$label = 'Hotline: ' . esc_html( $hotline ) . ( $hours ? ' (' . esc_html( $hours ) . ')' : '' );
		$html = preg_replace( '~(<a\b[^>]*data-nexxi-login-hotline[^>]*>).*?(</a>)~s', '<a class="hidden sm:inline-flex items-center gap-1 px-3 py-1 rounded-full bg-surface-container-highest text-on-surface-variant hover:text-secondary transition-colors font-label-sm text-label-sm" data-nexxi-login-hotline href="' . esc_url( nexxi_kids_tel( $hotline ) ) . '"><span class="material-symbols-outlined text-sm">support_agent</span> ' . $label . '</a>', $html );
	}
	if ( $chat ) {
		$html = preg_replace( '~(<a\b[^>]*data-nexxi-login-zalo[^>]*href=")[^"]*(")~s', '$1' . esc_url( $chat ) . '$2', $html );
	}
	$html = preg_replace( '~(<a\b[^>]*data-nexxi-terms-link[^>]*href=")[^"]*(")~s', '$1' . esc_url( nexxi_kids_page_url( 'dieu-khoan-su-dung' ) ) . '$2', $html );
	$html = preg_replace( '~(<a\b[^>]*data-nexxi-privacy-link[^>]*href=")[^"]*(")~s', '$1' . esc_url( nexxi_kids_page_url( 'chinh-sach-bao-mat' ) ) . '$2', $html );
	return $html;
}

/** Default-check the WooCommerce Terms & Conditions checkbox on Checkout (and preserve manual user toggle across AJAX refreshes). */
add_filter( 'woocommerce_terms_is_checked_default', static function () {
	if ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$parsed_post = array();
		parse_str( wp_unslash( $_POST['post_data'] ), $parsed_post ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $parsed_post['terms-field'] ) ) {
			return ! empty( $parsed_post['terms'] );
		}
	}
	return true;
}, 999 );

/** Ensure Terms checkbox stays checked by default on Checkout load & after WooCommerce updated_checkout AJAX unless manually unchecked. */
add_action( 'wp_footer', static function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) ) {
		return;
	}
	?>
	<script data-no-optimize="1" data-no-defer="1">
	(function(){
		var userToggledTerms = false;
		function ensureTermsChecked() {
			if (userToggledTerms) return;
			var boxes = document.querySelectorAll('form.checkout input#terms, form.checkout input[name="terms"]');
			for (var i = 0; i < boxes.length; i++) {
				if (!boxes[i].checked) {
					boxes[i].checked = true;
				}
			}
		}
		document.addEventListener('change', function(e) {
			var t = e.target;
			if (t && (t.id === 'terms' || t.name === 'terms')) {
				userToggledTerms = true;
			}
		}, true);
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', ensureTermsChecked);
		} else {
			ensureTermsChecked();
		}
		if (window.jQuery) {
			window.jQuery(document.body).on('init_checkout updated_checkout', ensureTermsChecked);
		}
	})();
	</script>
	<?php
}, 30 );

/** 18+ Adult Purchaser notice above WooCommerce Place Order button on Checkout. */
add_action( 'woocommerce_review_order_before_submit', static function () {
	$priv_url  = esc_url( nexxi_kids_page_url( 'chinh-sach-bao-mat' ) );
	$terms_url = esc_url( nexxi_kids_page_url( 'dieu-khoan-su-dung' ) );
	echo '<p class="nexxi-checkout-adult-notice font-label-sm text-label-sm text-on-surface-variant mb-3 leading-relaxed" style="font-size:12.5px;color:#475569;margin:0 0 12px;">'
		. 'Bằng việc đặt hàng, Quý khách xác nhận mình <strong>từ đủ 18 tuổi trở lên</strong> và đồng ý với <a href="' . $priv_url . '" target="_blank" rel="noopener" style="color:#ae3115;text-decoration:underline;">Chính sách bảo mật</a> &amp; <a href="' . $terms_url . '" target="_blank" rel="noopener" style="color:#ae3115;text-decoration:underline;">Điều khoản mua hàng</a> của NEXXI.'
		. '</p>';
}, 5 );

/** Clarify Adult-Operated Retail & Wholesale E-Commerce positioning in Homepage Meta Description & Schema JSON-LD. */
function nexxi_kids_front_compliance_description( $desc ) {
	if ( is_front_page() ) {
		$trim = trim( (string) $desc );
		if ( '' === $trim || 0 === strcasecmp( $trim, 'Phong cách khác, giá trị khác' ) ) {
			return 'Thời trang trẻ em thiết kế NEXXI Kids (12–45kg): đầm bé gái, đồ bộ bé trai mềm mát. Bán lẻ & sỉ toàn quốc cho phụ huynh, đại lý 18+, đổi hàng 15 ngày.';
		}
	}
	return $desc;
}
add_filter( 'wpseo_metadesc', 'nexxi_kids_front_compliance_description', 20 );
add_filter( 'wpseo_opengraph_desc', 'nexxi_kids_front_compliance_description', 20 );
add_filter( 'wpseo_schema_website', static function ( $data ) {
	if ( is_array( $data ) && ( empty( $data['description'] ) || 0 === strcasecmp( trim( (string) $data['description'] ), 'Phong cách khác, giá trị khác' ) ) ) {
		$data['description'] = 'Thời trang trẻ em thiết kế NEXXI Kids (12–45kg): đầm bé gái, đồ bộ bé trai mềm mát. Bán lẻ & sỉ toàn quốc cho phụ huynh, đại lý 18+, đổi hàng 15 ngày.';
	}
	return $data;
}, 20 );

/** Wrap WooCommerce shortcode output in the Stitch commerce main. */
/**
 * Prevent orphaned 'error' notices from lingering in WC()->session when custom AJAX add_to_cart fails.
 * Stitch PDP & Shop cards do not render wc_print_notices(), so an unconsumed error notice in session
 * would otherwise trap subsequent "Mua ngay" -> /thanh-toan/ navigation on checkout/cart-errors.php.
 */
add_filter(
	'woocommerce_cart_redirect_after_error',
	static function ( $url, $product_id ) {
		$wc_ajax = isset( $_REQUEST['wc-ajax'] ) ? sanitize_key( wp_unslash( $_REQUEST['wc-ajax'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'add_to_cart' !== $wc_ajax || ! function_exists( 'WC' ) || ! WC()->session ) {
			return $url;
		}

		$err_notices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();
		$clean_msg   = '';
		if ( ! empty( $err_notices ) && is_array( $err_notices ) ) {
			$last_err = end( $err_notices );
			$raw_html = is_array( $last_err ) && isset( $last_err['notice'] ) ? (string) $last_err['notice'] : ( is_scalar( $last_err ) ? (string) $last_err : '' );
			if ( '' !== $raw_html ) {
				$raw_html  = preg_replace( '~<a\b[^>]*class="[^"]*button[^"]*"[^>]*>.*?</a>~is', '', $raw_html );
				$clean_msg = trim( preg_replace( '~\s+~u', ' ', html_entity_decode( wp_strip_all_tags( (string) $raw_html ), ENT_QUOTES, 'UTF-8' ) ) );
			}
		}

		if ( '' !== $clean_msg && ! headers_sent() ) {
			@header( 'X-Nexxi-ATC-Error: ' . rawurlencode( $clean_msg ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$all_notices = WC()->session->get( 'wc_notices', array() );
		if ( is_array( $all_notices ) && isset( $all_notices['error'] ) ) {
			unset( $all_notices['error'] );
			WC()->session->set( 'wc_notices', $all_notices );
		}

		return $url;
	},
	999,
	2
);

function nexxi_kids_stitch_commerce_main( $content, $heading = '' ) {
	$head = $heading ? '<div class="nexxi-stitch-commerce__heading"><span class="font-label-sm text-label-sm text-secondary">NEXXI KIDS BOUTIQUE</span><h1 class="font-display-lg text-display-lg-mobile md:text-display-lg text-primary">' . esc_html( $heading ) . '</h1></div>' : '';
	return '<main class="nexxi-stitch-commerce w-full pt-28 md:pt-36 bg-background min-h-screen"><div class="max-w-[1440px] mx-auto px-margin-mobile md:px-margin py-space-xl">' . $head . $content . '</div></main>';
}

/** Data handed to stitch-interactions.js. */
function nexxi_kids_stitch_config( $screen ) {
	$flash_end = nexxi_kids_flash_end();
	$sale_left = max( 0, $flash_end - time() );
	$config = array(
		'screen'       => $screen,
		'home'         => home_url( '/' ),
		'shop'         => nexxi_kids_shop_url(),
		'cart'         => nexxi_kids_stitch_url( 'cart.html' ),
		'checkout'     => nexxi_kids_stitch_url( 'checkout.html' ),
		'account'      => nexxi_kids_stitch_url( 'account.html' ),
		'wishlistPage' => nexxi_kids_page_url( 'yeu-thich' ),
		'saleEnd'      => $sale_left > 0 ? wp_date( 'c', $flash_end ) : '',
		'saleLeft'     => $sale_left,
		'ajaxAdd'      => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'add_to_cart' ) : '',
		'restUrl'      => esc_url_raw( rest_url( 'nexxi/v1/' ) ),
		'nonce'        => wp_create_nonce( 'wp_rest' ),
		'loggedIn'     => is_user_logged_in(),
		'wishlist'     => is_user_logged_in() && class_exists( 'Nexxi_Kids_Catalog' ) ? Nexxi_Kids_Catalog::wishlist_ids( get_current_user_id() ) : null,
		'sizeChart'    => class_exists( 'Nexxi_Kids_Catalog' ) ? Nexxi_Kids_Catalog::size_chart() : array(),
	);
	if ( 'product' === $screen ) {
		$product = nexxi_kids_current_product();
		if ( $product ) { $config['currentProduct'] = nexxi_kids_product_config( $product ); }
	}
	return $config;
}

/** Populate initial countdown digits in HTML so the clock shows accurate remaining time immediately. */
function nexxi_kids_stitch_countdown_digits( $html ) {
	$left = max( 0, nexxi_kids_flash_end() - time() );
	$hh   = str_pad( (string) floor( $left / 3600 ), 2, '0', STR_PAD_LEFT );
	$mm   = str_pad( (string) floor( ( $left % 3600 ) / 60 ), 2, '0', STR_PAD_LEFT );
	$ss   = str_pad( (string) ( $left % 60 ), 2, '0', STR_PAD_LEFT );
	$map  = array(
		'fs-hours'    => $hh,
		'fs-minutes'  => $mm,
		'fs-seconds'  => $ss,
		'hours-box'   => $hh,
		'minutes-box' => $mm,
		'seconds-box' => $ss,
		'pdp-hours'   => $hh,
		'pdp-minutes' => $mm,
		'pdp-seconds' => $ss,
	);
	foreach ( $map as $id => $val ) {
		$html = preg_replace( '~(<(?:span|div)\b[^>]*\bid="' . preg_quote( $id, '~' ) . '"[^>]*>)\s*\d+\s*(</(?:span|div)>)~', '${1}' . $val . '${2}', $html );
	}
	return $html;
}

function nexxi_kids_render_stitch( $screen ) {
	$shells = array( 'index' => 'index', 'shop' => 'shop', 'product' => 'product', 'flash-sale' => 'flash-sale', 'cart' => 'cart', 'checkout' => 'checkout', 'account' => 'account', 'login' => 'login', 'members' => 'members', 'page' => 'index', 'blog' => 'index', 'single' => 'index' );
	if ( ! isset( $shells[ $screen ] ) ) { return; }
	$GLOBALS['nexxi_stitch_screen'] = $screen; // Dùng bởi inc/performance.php để bỏ CSS/JS không cần trên màn hình này.
	if ( ! headers_sent() ) {
		if ( in_array( $screen, array( 'cart', 'checkout', 'account', 'login', 'members' ), true ) || is_user_logged_in() ) {
			nocache_headers();
			@header( 'X-LiteSpeed-Cache-Control: no-cache' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} else {
			@header( 'X-LiteSpeed-Cache-Control: public,max-age=' . ( function_exists( 'nexxi_perf_page_cache_ttl' ) ? nexxi_perf_page_cache_ttl() : 180 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	$file = get_template_directory() . '/assets/stitch/pages/' . $shells[ $screen ] . '.html';
	if ( ! is_readable( $file ) ) { return; }
	$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	// One header for every screen (the login screen is intentionally header-less).
	if ( 'index' !== $shells[ $screen ] && 'login' !== $screen ) {
		$shared = nexxi_kids_stitch_shared_header();
		if ( $shared ) {
			$html = false !== stripos( $html, '<header' ) ? nexxi_dom_set_outer( $html, '<header', $shared ) : preg_replace( '~(<body\b[^>]*>)~i', '$1' . str_replace( '$', '\$', $shared ), $html, 1 );
		}
	}
	$html = preg_replace( '~<title>.*?</title>~s', '', $html, 1 ); // wp_head() prints the WordPress title.

	// One footer for every screen as well, so a Studio edit applies site-wide.
	if ( 'index' !== $shells[ $screen ] && false !== stripos( $html, '<footer' ) ) {
		$footer = nexxi_studio_shared_footer();
		if ( $footer ) { $html = nexxi_dom_set_outer( $html, '<footer', $footer ); }
	}
	// NEXXI Studio edits on the pristine shell (screen modules may still replace dynamic parts).
	$html = nexxi_studio_header( $html );
	$html = nexxi_studio_apply_content( $html );

	switch ( $screen ) {
		case 'index':
			$html = nexxi_kids_stitch_home( $html );
			break;
		case 'shop':
			$html = nexxi_kids_stitch_shop( $html );
			break;
		case 'flash-sale':
			$start = strpos( $html, '// Live Countdown Timer Simulation' );
			$end = false !== $start ? strpos( $html, '// Category Filter Simulation', $start ) : false;
			if ( false !== $start && false !== $end ) { $html = substr( $html, 0, $start ) . substr( $html, $end ); }
			$html = nexxi_kids_stitch_flash( $html );
			break;
		case 'product':
			$html = nexxi_kids_stitch_product( $html );
			break;
		case 'login':
			$html = nexxi_kids_stitch_login_forms( $html );
			break;
		case 'cart':
			$html = nexxi_dom_replace_first_tag( $html, 'main', nexxi_kids_stitch_cart_content() );
			break;
		case 'checkout':
			$html = nexxi_dom_replace_first_tag( $html, 'main', nexxi_kids_stitch_commerce_main( do_shortcode( '[woocommerce_checkout]' ), 'Thanh Toán Đơn Hàng' ) );
			break;
		case 'account':
			$html = nexxi_kids_stitch_account( $html );
			break;
		case 'page':
			$html = nexxi_dom_replace_first_tag( $html, 'main', nexxi_kids_page_main() );
			break;
		case 'blog':
			$html = nexxi_dom_replace_first_tag( $html, 'main', nexxi_kids_blog_main() );
			break;
		case 'single':
			$html = nexxi_dom_replace_first_tag( $html, 'main', nexxi_kids_single_post_main() );
			break;
	}

	$html = nexxi_kids_stitch_lock_header_labels( $html );
	$html = nexxi_kids_stitch_header( $html, $screen );
	$html = nexxi_kids_stitch_footer( $html );
	$html = nexxi_studio_apply_late( $html );
	$html = nexxi_kids_stitch_countdown_digits( $html );

	$asset_url     = get_template_directory_uri() . '/assets/stitch';
	$compiled_tw   = nexxi_kids_stitch_compiled_tailwind_tag();
	$html          = str_replace( '{{STITCH_ASSET}}', esc_url( $asset_url ), $html );
	if ( '' !== $compiled_tw && ! is_customize_preview() ) {
		// Pre-compiled Tailwind CSS is already inlined in <head>; strip the 407KB synchronous JIT compiler script for instant rendering.
		$html = str_replace( '<script src="https://cdn.tailwindcss.com"></script>', '', $html );
		$html = preg_replace( '~<script\b[^>]*src="https://cdn\.tailwindcss\.com[^"]*"[^>]*>\s*</script>~i', '', $html );
		$html = preg_replace( '~<script id="tailwind-config">.*?</script>~s', '', $html );
	} else {
		$html = str_replace(
			'<script src="https://cdn.tailwindcss.com"></script>',
			'<script data-no-optimize="1" data-no-defer="1" src="' . esc_url( $asset_url . '/tailwind-play.js' ) . '"></script>',
			$html
		);
		$html = str_replace( 'https://cdn.tailwindcss.com', esc_url( $asset_url . '/tailwind-play.js' ), $html );
		$html = preg_replace_callback( '~(<script id="tailwind-config">)(.*?)(</script>)~s', static function ( $m ) {
			$px_to_rem = array(
				'"48px"' => '"2.75rem"',
				'"56px"' => '"3.2rem"',
				'"42px"' => '"2.625rem"',
				'"40px"' => '"2.35rem"',
				'"34px"' => '"2.125rem"',
				'"32px"' => '"1.875rem"',
				'"28px"' => '"1.65rem"',
				'"26px"' => '"1.55rem"',
				'"24px"' => '"1.45rem"',
				'"22px"' => '"1.375rem"',
				'"20px"' => '"1.2rem"',
				'"18px"' => '"1.0625rem"',
				'"16px"' => '"1rem"',
				'"15px"' => '"0.9375rem"',
				'"14px"' => '"0.875rem"',
				'"13px"' => '"0.8125rem"',
				'"12px"' => '"0.75rem"',
				'"11px"' => '"0.6875rem"',
			);
			$body = strtr( $m[2], $px_to_rem );
			return '<script id="tailwind-config" data-no-optimize="1" data-no-defer="1">window.tailwind=window.tailwind||{};' . $body . $m[3];
		}, $html, 1 );
	}
	$html = str_replace(
		'family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap',
		'family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=block',
		$html
	);
	$html = str_replace( array( '14 - 17kg', '14-17kg' ), array( '14 - 16kg', '14-16kg' ), $html );
	// Remove overscroll-behavior:none and scrollbar hiding from Stitch base styles so vertical touch/mouse scrolling works smoothly everywhere.
	$html = str_replace( array( 'body{overscroll-behavior:none;}', '::-webkit-scrollbar{display:none;}' ), '', $html );
	// Protect all Stitch <link>, <style>, and <script> tags from LiteSpeed Cache defer/delay/combine mangling.
	$html = preg_replace( '~<link\b(?![^>]*data-no-optimize)~i', '<link data-no-optimize="1"', $html );
	$html = preg_replace( '~<style\b(?![^>]*data-no-optimize)~i', '<style data-no-optimize="1"', $html );
	$html = preg_replace( '~<script\b(?![^>]*data-no-optimize)~i', '<script data-no-optimize="1" data-no-defer="1"', $html );
	// Any remaining static Stitch links point to the matching WordPress routes.
	foreach ( array( 'index.html', 'shop.html', 'product.html', 'flash-sale.html', 'cart.html', 'checkout.html', 'account.html', 'login.html', 'members.html' ) as $route ) {
		$html = str_replace( array( 'href="' . $route . '#', 'href="' . $route . '"' ), array( 'href="' . esc_url( nexxi_kids_stitch_url( $route ) ) . '#', 'href="' . esc_url( nexxi_kids_stitch_url( $route ) ) . '"' ), $html );
	}

	// Remove the older theme stylesheet so its selectors cannot override Stitch.
	wp_dequeue_style( 'nexxi-kids-style' );
	wp_dequeue_style( 'nexxi-kids-theme' );
	wp_dequeue_style( 'nexxi-kids-fonts' );
	wp_dequeue_script( 'nexxi-kids-theme' );
	$head_end = stripos( $html, '</head>' );
	$body_end = strripos( $html, '</body>' );
	// Hậu xử lý toàn bộ trang (font icon rút gọn, WebP...) — xem inc/performance.php.
	ob_start( function_exists( 'nexxi_perf_finalize_html' ) ? 'nexxi_perf_finalize_html' : null );
	if ( false === $head_end || false === $body_end ) { echo $html; ob_end_flush(); return; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo substr( $html, 0, $head_end ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	wp_head();
	$stitch_css = $asset_url . '/woocommerce-stitch.css?ver=' . filemtime( get_template_directory() . '/assets/stitch/woocommerce-stitch.css' );
	echo nexxi_kids_stitch_compiled_tailwind_tag() . nexxi_studio_stylesheet_tag( $stitch_css, get_template_directory() . '/assets/stitch/woocommerce-stitch.css' ) . '<style id="nexxi-shared-header-style" data-no-optimize="1">body>header{position:sticky!important;top:0!important;z-index:50!important}body.admin-bar>header{top:32px!important}@media (max-width:782px){body.admin-bar>header{top:46px!important}}@keyframes marqueeCute{0%{transform:translateX(0%)}100%{transform:translateX(-50%)}}.animate-marquee{display:flex;width:max-content;animation:marqueeCute 38s linear infinite}.animate-marquee:hover{animation-play-state:paused}.material-symbols-outlined{font-family:"Material Symbols Outlined"!important;font-weight:normal;font-style:normal;line-height:1;letter-spacing:normal;text-transform:none;display:inline-block;white-space:nowrap;word-wrap:normal;direction:ltr;-webkit-font-smoothing:antialiased;width:1em;height:1em;overflow:hidden;vertical-align:middle;flex-shrink:0}header img.h-8{height:2rem!important;width:auto!important;max-width:180px!important;object-fit:contain!important}h1 svg.w-8{width:2rem;height:2rem;display:inline-block;vertical-align:middle}body>header+main,header[data-nexxi-shared-header="1"]+main{padding-top:0!important;margin-top:0!important}body>header+main>[data-nxr="home-hero"],body>header+main>div>[data-nxr="home-hero"]{margin-top:0!important;padding:0!important}@media (min-width:1024px){body>header>div:last-child>div{height:4.5rem!important;flex-wrap:nowrap!important;gap:0.5rem!important}body>header nav{display:flex!important;flex-wrap:nowrap!important;white-space:nowrap!important;min-width:0!important;gap:0!important;padding:0.25rem!important}body>header nav>a{display:inline-flex!important;align-items:center!important;flex:0 0 auto!important;white-space:nowrap!important;line-height:1.1rem!important;padding:0.45rem 0.6rem!important}body>header nav>a span{white-space:nowrap!important}body>header [class*="md:flex"]{flex:0 1 13rem!important;width:13rem!important;min-width:9.5rem!important}body>header .flex.items-center.gap-space-sm{gap:0.5rem!important;flex-wrap:nowrap!important}body>header .w-10{width:2.25rem!important;height:2.25rem!important;flex:0 0 2.25rem!important}}</style></head>';
	echo substr( $html, $head_end + 7, $body_end - $head_end - 7 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo nexxi_kids_mobile_shell_ui( $screen ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	wp_footer();
	$js = get_template_directory() . '/assets/stitch/stitch-interactions.js';
	echo '<script data-no-optimize="1" data-no-defer="1">window.NexxiStitch=' . wp_json_encode( nexxi_kids_stitch_config( $screen ) ) . ';</script><script data-no-optimize="1" data-no-defer="1" src="' . esc_url( $asset_url . '/stitch-interactions.js?ver=' . NEXXI_KIDS_VERSION . ( is_readable( $js ) ? '.' . filemtime( $js ) : '' ) ) . '"></script>';
	echo substr( $html, $body_end ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	ob_end_flush();
}
