<?php
/**
 * NEXXI Kids — Technical SEO, Indexability, XML Sitemap & On-Page Critical Engine (P0).
 *
 * Giải quyết triệt để ở cả tầng Database Migration 1 lần lẫn tầng Runtime Filters tức thì:
 * - P0 #1: Xóa vòng lặp Canonical <-> 301 Redirect, làm sạch Yoast Indexables & Breadcrumb mã POS cũ.
 * - P0 #3 & #4: Trả 410 Gone cho /hello-world/ & /sample-page/, chặn /author/* (301 về Trang chủ),
 *   ép noindex,follow và rút các trang tiện ích / danh mục rác khỏi XML Sitemap, xóa trùng Trang chủ
 *   ở post-sitemap.xml, tối ưu robots.txt.
 * - P0 #5: Công thức chuẩn Google SERP cho <title> (35–60 ký tự), <meta name="description"> (120–158 ký tự)
 *   và og:image dự phòng trên 100% nhóm trang, bảo toàn tuyên bố tuân thủ 18+ cho Meta Ads.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NEXXI_SEO_HOME_TITLE', 'Thời Trang Trẻ Em NEXXI Kids – Đầm Bé Gái & Đồ Bộ Bé Trai' );
define( 'NEXXI_SEO_HOME_DESC', 'Thời trang trẻ em thiết kế NEXXI Kids (12–45kg): đầm bé gái, đồ bộ bé trai mềm mát. Bán lẻ & sỉ toàn quốc cho phụ huynh, đại lý 18+, đổi hàng 15 ngày.' );

/* ==========================================================================
 * 1. TEXT, POLICY & CONTEXT HELPERS
 * ========================================================================== */

/**
 * Bóc sạch tiền tố mã lô POS cũ (VD: "2610.11", "2609.8", "2503.8") và chuẩn hóa khoảng trắng.
 *
 * @param string $title Tiêu đề gốc.
 * @return string
 */
function nexxi_seo_clean_product_title( $title ) {
	$clean = wp_strip_all_tags( (string) $title );
	$clean = html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$clean = preg_replace( '/^\s*\d{3,4}\.\d+\s*/u', '', $clean );
	$clean = preg_replace( '/\s+/u', ' ', trim( (string) $clean ) );
	return (string) $clean;
}

/**
 * Đồng bộ mọi cụm "đổi size 7 ngày" / "đổi trả trong vòng 7 ngày" trong meta description
 * về đúng chính sách thật "đổi hàng 15 ngày" của Website NEXXI.
 *
 * @param string $text Chuỗi mô tả.
 * @return string
 */
function nexxi_seo_normalize_return_policy_text( $text ) {
	$text = (string) $text;
	if ( '' === $text ) {
		return '';
	}
	$text = preg_replace(
		'/đổi\s*(?:size|trả|hàng)(?:\s*,\s*đổi\s*mẫu)?(?:\s+trong\s+vòng)?\s*0?7\s*ngày/iu',
		'đổi hàng 15 ngày',
		$text
	);
	return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
}

/**
 * Cắt chuỗi theo ranh giới từ tiếng Việt an toàn, không làm đứt chữ giữa chừng.
 *
 * @param string $text    Chuỗi đầu vào.
 * @param int    $max_len Độ dài tối đa (số ký tự UTF-8).
 * @param string $Ellipsis Hậu tố nếu bị cắt (mặc định rỗng cho <title>).
 * @return string
 */
function nexxi_seo_trim_chars( $text, $max_len, $ellipsis = '' ) {
	$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	$max  = max( 10, (int) $max_len - mb_strlen( $ellipsis, 'UTF-8' ) );
	if ( mb_strlen( $text, 'UTF-8' ) <= (int) $max_len ) {
		return $text;
	}
	$cut     = mb_substr( $text, 0, $max, 'UTF-8' );
	$last_sp = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
	if ( false !== $last_sp && $last_sp >= (int) floor( $max * 0.55 ) ) {
		$cut = mb_substr( $cut, 0, $last_sp, 'UTF-8' );
	}
	$cut = rtrim( $cut, " \t\n\r\0\x0B,;:-–—|+•/(" );
	return $cut . $ellipsis;
}

/**
 * Chuẩn hóa tên danh mục/thẻ sản phẩm (chuyển IN HOA TOÀN BỘ như "ĐẦM", "SET PHỐI" sang Title Case đẹp mắt).
 *
 * @param string $name Tên term gốc.
 * @return string
 */
function nexxi_seo_format_term_name( $name ) {
	$clean = nexxi_seo_clean_product_title( $name );
	$clean = trim( (string) preg_replace( '/^Lưu\s+trữ\s+/iu', '', $clean ) );
	if ( '' === $clean ) {
		return '';
	}
	$letters = preg_replace( '/[^\p{L}]/u', '', $clean );
	if ( '' !== $letters && mb_strtoupper( $letters, 'UTF-8' ) === $letters ) {
		$clean = mb_convert_case( mb_strtolower( $clean, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
	}
	return $clean;
}

/**
 * Ảnh OpenGraph thương hiệu mặc định có sẵn trong theme (< 150 KB).
 *
 * @return string
 */
function nexxi_seo_default_og_image() {
	return get_template_directory_uri() . '/assets/stitch/images/lookbook-dam-vay.jpg';
}

/**
 * Danh sách ID các trang tiện ích / trang rác cần noindex và loại khỏi XML Sitemap.
 *
 * @param bool $refresh Bỏ qua bộ nhớ đệm tĩnh trong request.
 * @return int[]
 */
function nexxi_seo_utility_page_ids( $refresh = false ) {
	static $cached = null;
	if ( null !== $cached && ! $refresh ) {
		return $cached;
	}

	$ids = array();
	if ( function_exists( 'wc_get_page_id' ) ) {
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $wc_page ) {
			$pid = (int) wc_get_page_id( $wc_page );
			if ( $pid > 0 ) {
				$ids[] = $pid;
			}
		}
	}

	foreach ( array( 'gio-hang', 'thanh-toan', 'tai-khoan', 'yeu-thich', 'sample-page' ) as $slug ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $page instanceof WP_Post ) {
			$ids[] = (int) $page->ID;
		}
	}

	$hello = get_page_by_path( 'hello-world', OBJECT, 'post' );
	if ( $hello instanceof WP_Post ) {
		$ids[] = (int) $hello->ID;
	}

	$cached = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	return $cached;
}

/**
 * Danh sách term_id mặc định (uncategorized, chua-phan-loai, default_product_cat) cần loại khỏi Sitemap & đặt noindex.
 *
 * @param bool $refresh Bỏ qua bộ nhớ đệm tĩnh trong request.
 * @return int[]
 */
function nexxi_seo_excluded_term_ids( $refresh = false ) {
	static $cached = null;
	if ( null !== $cached && ! $refresh ) {
		return $cached;
	}

	$tids = array();

	$uncat = get_term_by( 'slug', 'uncategorized', 'category' );
	if ( $uncat instanceof WP_Term ) {
		$tids[] = (int) $uncat->term_id;
	}
	$def_cat_id = (int) get_option( 'default_category' );
	if ( $def_cat_id > 0 ) {
		$def_cat = get_term( $def_cat_id, 'category' );
		if ( $def_cat instanceof WP_Term && in_array( $def_cat->slug, array( 'uncategorized', 'chua-phan-loai' ), true ) ) {
			$tids[] = $def_cat_id;
		}
	}

	if ( taxonomy_exists( 'product_cat' ) ) {
		$def_pcat = (int) get_option( 'default_product_cat' );
		if ( $def_pcat > 0 ) {
			$tids[] = $def_pcat;
		}
		foreach ( array( 'chua-phan-loai', 'uncategorized' ) as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$tids[] = (int) $term->term_id;
			}
		}
	}

	$cached = array_values( array_unique( array_filter( array_map( 'intval', $tids ) ) ) );
	return $cached;
}

/**
 * Kiểm tra ngữ cảnh request hiện tại có phải trang tiện ích, tác giả, tìm kiếm hoặc taxonomy rỗng/rác cần noindex,follow.
 *
 * @return bool
 */
function nexxi_seo_is_noindex_context() {
	if ( is_search() || is_author() || is_404() ) {
		return true;
	}
	if ( ( function_exists( 'is_cart' ) && is_cart() )
		|| ( function_exists( 'is_checkout' ) && is_checkout() )
		|| ( function_exists( 'is_account_page' ) && is_account_page() )
	) {
		return true;
	}
	if ( is_page( array( 'gio-hang', 'thanh-toan', 'tai-khoan', 'yeu-thich', 'sample-page' ) ) ) {
		return true;
	}
	if ( is_singular( 'post' ) ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post && 'hello-world' === $post->post_name ) {
			return true;
		}
	}
	if ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			if ( in_array( (int) $term->term_id, nexxi_seo_excluded_term_ids(), true ) ) {
				return true;
			}
			if ( in_array( $term->slug, array( 'uncategorized', 'chua-phan-loai' ), true ) ) {
				return true;
			}
			if ( (int) $term->count <= 0 ) {
				global $wp_query;
				if ( ! $wp_query || (int) $wp_query->found_posts <= 0 ) {
					return true;
				}
			}
		}
	}
	return false;
}

/* ==========================================================================
 * 2. DATABASE MIGRATION (ONE-TIME P0) & YOAST INDEXABLE RESET
 * ========================================================================== */

/**
 * Làm sạch tức thì file vật lý ABSPATH . 'llms.txt' trên ổ đĩa (do LiteSpeed phục vụ trực tiếp file tĩnh),
 * gỡ bỏ các dòng rác/tiện ích, cập nhật mô tả chuẩn 18+ cho Trang chủ và đặt lịch làm mới qua cron Yoast.
 */
function nexxi_seo_sanitize_physical_llms_txt() {
	if ( defined( 'ABSPATH' ) ) {
		$llms_file = ABSPATH . 'llms.txt';
		if ( is_file( $llms_file ) && is_writable( $llms_file ) ) {
			$raw = (string) @file_get_contents( $llms_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( '' !== $raw ) {
				if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
					$raw = substr( $raw, 3 );
				}
				$blocked_tokens = array(
					'/hello-world/',
					'/sample-page/',
					'/gio-hang/',
					'/thanh-toan/',
					'/tai-khoan/',
					'/yeu-thich/',
					'/uncategorized/',
					'/chua-phan-loai/',
					'/author/',
				);
				$kept_lines = array();
				foreach ( (array) preg_split( '/\R/u', $raw ) as $line ) {
					$drop = false;
					foreach ( $blocked_tokens as $token ) {
						if ( false !== stripos( $line, $token ) ) {
							$drop = true;
							break;
						}
					}
					if ( $drop ) {
						continue;
					}
					$line         = str_ireplace( 'Phong cách khác, giá trị khác', NEXXI_SEO_HOME_DESC, $line );
					$kept_lines[] = $line;
				}
				$cleaned = implode( "\n", $kept_lines );
				$cleaned = (string) preg_replace( "/\n{3,}/", "\n\n", $cleaned );
				$cleaned = rtrim( $cleaned ) . "\n";
				@file_put_contents( $llms_file, $cleaned ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	foreach ( array( 'wpseo_llms_txt_population', 'wpseo_populate_llms_txt' ) as $llms_hook ) {
		if ( has_action( $llms_hook ) && ! wp_next_scheduled( $llms_hook ) ) {
			wp_schedule_single_event( time() + 10, $llms_hook );
		}
	}
}

/**
 * Xóa sạch bảng bộ nhớ đệm wp_yoast_indexable, wp_yoast_indexable_hierarchy & Sitemap cache
 * để Yoast SEO tự động xây lại URL canonical, breadcrumb và dữ liệu /llms.txt mới nhất.
 */
function nexxi_seo_reset_yoast_indexables() {
	global $wpdb;

	$idx_table  = $wpdb->prefix . 'yoast_indexable';
	$hier_table = $wpdb->prefix . 'yoast_indexable_hierarchy';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $idx_table ) ) === $idx_table ) {
		$wpdb->query( "TRUNCATE TABLE `{$idx_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hier_table ) ) === $hier_table ) {
		$wpdb->query( "TRUNCATE TABLE `{$hier_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Xóa toàn bộ bộ đếm indexable và transient cache XML Sitemap của Yoast.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE 'wpseo_total_unindexed_%'
		    OR option_name LIKE '_transient_wpseo_total_unindexed_%'
		    OR option_name LIKE '_transient_timeout_wpseo_total_unindexed_%'
		    OR option_name LIKE '_transient_wpseo_sitemap_%'
		    OR option_name LIKE '_transient_timeout_wpseo_sitemap_%'"
	);

	delete_option( 'wpseo_indexables_indexation_completed' );
	delete_transient( 'wpseo_sitemap_cache_validator_global' );

	if ( class_exists( 'WPSEO_Sitemaps_Cache' ) && method_exists( 'WPSEO_Sitemaps_Cache', 'clear' ) ) {
		WPSEO_Sitemaps_Cache::clear();
	}

	// Làm sạch tức thì file vật lý llms.txt và đặt lịch cron Yoast tái tạo /llms.txt.
	nexxi_seo_sanitize_physical_llms_txt();
}

// Tự động dọn sạch bảng yoast_indexable mỗi khi Nexxi_SKU_SEO_Sync chạy đồng bộ hàng loạt.
add_action( 'add_option_nexxi_sku_seo_last_report', 'nexxi_seo_reset_yoast_indexables', 20, 0 );
add_action( 'update_option_nexxi_sku_seo_last_report', 'nexxi_seo_reset_yoast_indexables', 20, 0 );

/**
 * Làm mới dòng yoast_indexable của từng sản phẩm lẻ khi đồng bộ từ Pancake POS.
 *
 * @param int    $meta_id   ID bản ghi postmeta.
 * @param int    $object_id ID sản phẩm.
 * @param string $meta_key  Tên khóa meta.
 */
function nexxi_seo_invalidate_single_product_indexable( $meta_id, $object_id, $meta_key ) {
	if ( '_nexxi_sku_seo_ver' !== $meta_key || ! empty( $GLOBALS['nexxi_bulk_syncing'] ) ) {
		return;
	}
	$post_id = (int) $object_id;
	if ( $post_id <= 0 ) {
		return;
	}
	global $wpdb;
	$idx_table = $wpdb->prefix . 'yoast_indexable';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $idx_table ) ) === $idx_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$idx_table,
			array(
				'object_id'   => $post_id,
				'object_type' => 'post',
			),
			array( '%d', '%s' )
		);
	}
}
add_action( 'added_post_meta', 'nexxi_seo_invalidate_single_product_indexable', 20, 3 );
add_action( 'updated_post_meta', 'nexxi_seo_invalidate_single_product_indexable', 20, 3 );

/**
 * Migration 1 lần khắc phục toàn bộ các lỗi P0 #1, P0 #3, P0 #4, P0 #5 ở tầng cơ sở dữ liệu.
 */
function nexxi_seo_run_p0_migration() {
	global $wpdb;

	// 1. Xóa vĩnh viễn bài viết mẫu /hello-world/ và trang mẫu /sample-page/ (mọi trạng thái).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$trash_ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE ( post_type = 'post' AND post_name IN ('hello-world', 'hello-world__trashed') )
		    OR ( post_type = 'page' AND post_name IN ('sample-page', 'sample-page__trashed') )"
	);
	foreach ( (array) $trash_ids as $tid ) {
		$tid = (int) $tid;
		if ( $tid > 0 ) {
			wp_delete_post( $tid, true );
		}
	}

	// 2. Đặt noindex trong postmeta cho tất cả các trang tiện ích: Giỏ hàng, Thanh toán, Tài khoản, Yêu thích.
	$util_ids = array();
	if ( function_exists( 'wc_get_page_id' ) ) {
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $wc_key ) {
			$pid = (int) wc_get_page_id( $wc_key );
			if ( $pid > 0 ) {
				$util_ids[] = $pid;
			}
		}
	}
	foreach ( array( 'gio-hang', 'thanh-toan', 'tai-khoan', 'yeu-thich' ) as $slug ) {
		$p = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $p instanceof WP_Post ) {
			$util_ids[] = (int) $p->ID;
		}
	}
	foreach ( array_unique( array_filter( $util_ids ) ) as $uid ) {
		update_post_meta( $uid, '_yoast_wpseo_meta-robots-noindex', '1' );
		update_post_meta( $uid, '_yoast_wpseo_meta-robots-nofollow', '0' );
		update_post_meta( $uid, '_nexxi_seo_robots', 'noindex,follow' );
	}

	// 3. Cập nhật wpseo_titles: tắt Author Archives (xóa author-sitemap.xml & chống lộ user admin) + gỡ chữ "Lưu trữ ".
	$wpseo_titles = get_option( 'wpseo_titles' );
	if ( is_array( $wpseo_titles ) ) {
		$wpseo_titles['disable-author']       = true;
		$wpseo_titles['noindex-author-wpseo'] = true;

		foreach ( array( 'title-tax-product_cat', 'title-tax-product_tag', 'title-cat', 'title-tag', 'title-ptarchive-product' ) as $tkey ) {
			if ( ! empty( $wpseo_titles[ $tkey ] ) && is_string( $wpseo_titles[ $tkey ] ) ) {
				$wpseo_titles[ $tkey ] = trim( (string) preg_replace( '/^Lưu\s+trữ\s+/iu', '', $wpseo_titles[ $tkey ] ) );
			}
		}
		update_option( 'wpseo_titles', $wpseo_titles );
	}

	// 4. Đặt wpseo_noindex = 'noindex' cho uncategorized & chua-phan-loai; gỡ liên kết thừa chua-phan-loai.
	$tax_meta = get_option( 'wpseo_taxonomy_meta', array() );
	if ( ! is_array( $tax_meta ) ) {
		$tax_meta = array();
	}

	$cat_noindex_ids = array();
	$uncat_term      = get_term_by( 'slug', 'uncategorized', 'category' );
	if ( $uncat_term instanceof WP_Term ) {
		$cat_noindex_ids[] = (int) $uncat_term->term_id;
	}
	$def_cat_id = (int) get_option( 'default_category' );
	if ( $def_cat_id > 0 ) {
		$def_cat_obj = get_term( $def_cat_id, 'category' );
		if ( $def_cat_obj instanceof WP_Term && in_array( $def_cat_obj->slug, array( 'uncategorized', 'chua-phan-loai' ), true ) ) {
			$cat_noindex_ids[] = $def_cat_id;
		}
	}
	foreach ( array_unique( $cat_noindex_ids ) as $cid ) {
		if ( ! isset( $tax_meta['category'][ $cid ] ) || ! is_array( $tax_meta['category'][ $cid ] ) ) {
			$tax_meta['category'][ $cid ] = array();
		}
		$tax_meta['category'][ $cid ]['wpseo_noindex']   = 'noindex';
		$tax_meta['category'][ $cid ]['wpseo_canonical'] = '';
	}

	$pcat_default_ids = array();
	if ( taxonomy_exists( 'product_cat' ) ) {
		$def_pcat_id = (int) get_option( 'default_product_cat' );
		if ( $def_pcat_id > 0 ) {
			$pcat_default_ids[] = $def_pcat_id;
		}
		foreach ( array( 'chua-phan-loai', 'uncategorized' ) as $pslug ) {
			$pterm = get_term_by( 'slug', $pslug, 'product_cat' );
			if ( $pterm instanceof WP_Term ) {
				$pcat_default_ids[] = (int) $pterm->term_id;
			}
		}
		$pcat_default_ids = array_values( array_unique( array_filter( $pcat_default_ids ) ) );

		foreach ( $pcat_default_ids as $pcid ) {
			if ( ! isset( $tax_meta['product_cat'][ $pcid ] ) || ! is_array( $tax_meta['product_cat'][ $pcid ] ) ) {
				$tax_meta['product_cat'][ $pcid ] = array();
			}
			$tax_meta['product_cat'][ $pcid ]['wpseo_noindex']   = 'noindex';
			$tax_meta['product_cat'][ $pcid ]['wpseo_canonical'] = '';
		}

		// Gỡ chua-phan-loai khỏi mọi sản phẩm publish đã thuộc ít nhất 1 danh mục thật khác.
		if ( ! empty( $pcat_default_ids ) ) {
			$in_placeholders = implode( ',', array_fill( 0, count( $pcat_default_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$prod_ids_in_def = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
					 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					 WHERE p.post_type = 'product'
					   AND p.post_status = 'publish'
					   AND tt.taxonomy = 'product_cat'
					   AND tt.term_id IN ({$in_placeholders})",
					$pcat_default_ids
				)
			);
			$detached_any = false;
			foreach ( (array) $prod_ids_in_def as $prod_id ) {
				$prod_id  = (int) $prod_id;
				$all_cats = wp_get_object_terms( $prod_id, 'product_cat', array( 'fields' => 'ids' ) );
				if ( is_wp_error( $all_cats ) || empty( $all_cats ) ) {
					continue;
				}
				$real_cats = array_diff( array_map( 'intval', $all_cats ), $pcat_default_ids );
				if ( count( $real_cats ) >= 1 ) {
					wp_remove_object_terms( $prod_id, $pcat_default_ids, 'product_cat' );
					$detached_any = true;
				}
			}
			if ( $detached_any ) {
				wp_update_term_count_now( $pcat_default_ids, 'product_cat' );
			}
		}
	}
	update_option( 'wpseo_taxonomy_meta', $tax_meta );

	// 5. Xóa canonical cũ sai trong postmeta (nếu có) & chuẩn hóa "7 ngày" -> "15 ngày" trong meta description đã lưu.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		"DELETE FROM {$wpdb->postmeta}
		 WHERE meta_key IN ('_yoast_wpseo_canonical', '_nexxi_seo_canonical', 'rank_math_canonical_url')
		   AND ( meta_value LIKE '%?product_cat=%' OR meta_value REGEXP '/[0-9]{3,4}-[0-9]+' )"
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$desc_rows = $wpdb->get_results(
		"SELECT meta_id, meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key IN ('_nexxi_seo_desc', '_yoast_wpseo_metadesc', 'rank_math_description', '_nexxi_og_desc', '_yoast_wpseo_opengraph-description')
		   AND meta_value LIKE '%7 ngày%'"
	);
	foreach ( (array) $desc_rows as $drow ) {
		$fixed = nexxi_seo_normalize_return_policy_text( $drow->meta_value );
		if ( $fixed !== $drow->meta_value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => $fixed ),
				array( 'meta_id' => (int) $drow->meta_id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	// Xóa giá trị mặc định cũ của home-categories__t3 trong Studio nếu chưa chỉnh sửa riêng để H1 mới hiển thị chuẩn.
	$studio_saved = get_theme_mod( 'nexxi_studio', array() );
	if ( is_array( $studio_saved ) && isset( $studio_saved['home-categories__t3'] ) && 'Chọn Đồ Chuẩn Xinh Theo Độ Tuổi' === trim( (string) $studio_saved['home-categories__t3'] ) ) {
		unset( $studio_saved['home-categories__t3'] );
		set_theme_mod( 'nexxi_studio', $studio_saved );
	}

	// Reset bộ nhớ đệm danh sách ID trong request, xóa sạch bảng Yoast Indexables và làm sạch tức thì llms.txt.
	nexxi_seo_utility_page_ids( true );
	nexxi_seo_excluded_term_ids( true );
	nexxi_seo_reset_yoast_indexables();
	nexxi_seo_sanitize_physical_llms_txt();
}

/* ==========================================================================
 * 3. RUNTIME HTTP STATUS (410 GONE & AUTHOR 301)
 * ========================================================================== */

add_action(
	'template_redirect',
	static function () {
		if ( is_admin() ) {
			return;
		}

		$req_uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$req_path  = (string) wp_parse_url( $req_uri, PHP_URL_PATH );
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home_path && '/' !== $home_path ) {
			$base = rtrim( $home_path, '/' );
			if ( 0 === strpos( $req_path, $base ) ) {
				$req_path = substr( $req_path, strlen( $base ) );
			}
		}
		$norm_path = '/' . trim( strtolower( $req_path ), '/' );

		// Trả chuẩn HTTP 410 Gone cho trang mẫu mặc định WordPress để Google rút nhanh khỏi chỉ mục.
		if ( in_array( $norm_path, array( '/hello-world', '/sample-page' ), true ) ) {
			global $wp_query;
			if ( $wp_query instanceof WP_Query ) {
				$wp_query->set_404();
			}
			status_header( 410 );
			nocache_headers();
			if ( ! headers_sent() ) {
				header( 'Content-Type: text/html; charset=UTF-8' );
				header( 'X-Robots-Tag: noindex, nofollow', true );
			}
			$home_href = esc_url( home_url( '/' ) );
			$shop_href = esc_url( function_exists( 'nexxi_kids_shop_url' ) ? nexxi_kids_shop_url() : home_url( '/cua-hang/' ) );
			echo '<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
				. '<meta name="robots" content="noindex, nofollow">'
				. '<title>410 Gone – Nội dung không còn tồn tại | NEXXI Kids</title>'
				. '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#fbf9f5;color:#031444;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;text-align:center}.box{max-width:460px;background:#fff;padding:32px;border-radius:24px;box-shadow:0 12px 32px rgba(3,20,68,.06)}h1{font-size:22px;margin:0 0 10px}p{font-size:15px;color:#45464f;line-height:1.6;margin:0 0 20px}.btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}a{text-decoration:none;padding:10px 20px;border-radius:999px;font-weight:700;font-size:14px}.p{background:#ae3115;color:#fff}.s{background:#f5f3ef;color:#031444}</style>'
				. '</head><body><main class="box"><h1>Trang này đã bị gỡ bỏ vĩnh viễn (410)</h1>'
				. '<p>Đường dẫn mẫu không còn tồn tại trên hệ thống NEXXI Kids Fashion. Mời ba mẹ khám phá các thiết kế mới nhất cho bé yêu.</p>'
				. '<div class="btns"><a class="p" href="' . $home_href . '">Về Trang Chủ</a><a class="s" href="' . $shop_href . '">Xem Cửa Hàng</a></div>'
				. '</main></body></html>';
			exit;
		}

		// Chuyển hướng 301 mọi trang lưu trữ tác giả (/author/nexxi/, /?author=1) về Trang chủ.
		if ( is_author() ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	},
	1
);

/* ==========================================================================
 * 4. RUNTIME CANONICAL, OPENGRAPH URL & BREADCRUMBS (P0 #1)
 * ========================================================================== */

/**
 * Tính URL Canonical chuẩn xác từ dữ liệu thực tế hiện hành (loại bỏ hoàn toàn cache slug cũ).
 *
 * @param string $canonical Giá trị canonical đầu vào.
 * @return string
 */
function nexxi_seo_resolve_canonical_url( $canonical = '' ) {
	if ( is_front_page() ) {
		return home_url( '/' );
	}

	if ( is_singular( 'product' ) ) {
		$post_id = (int) get_queried_object_id();
		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}
	}

	if ( is_tax( 'product_cat' ) || is_tax( 'product_tag' ) || is_category() || is_tag() || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) && is_string( $link ) && '' !== $link ) {
				// Đảm bảo không bao giờ rơi về dạng query thô /?product_cat=... khi site dùng pretty permalinks.
				if ( false !== strpos( $link, '?' ) && '' !== (string) get_option( 'permalink_structure' ) ) {
					$wc_permalinks = (array) get_option( 'woocommerce_permalinks', array() );
					if ( 'product_cat' === $term->taxonomy ) {
						$base = ! empty( $wc_permalinks['category_base'] ) ? trim( (string) $wc_permalinks['category_base'], '/' ) : 'danh-muc-san-pham';
						$link = home_url( '/' . $base . '/' . $term->slug . '/' );
					} elseif ( 'product_tag' === $term->taxonomy ) {
						$base = ! empty( $wc_permalinks['tag_base'] ) ? trim( (string) $wc_permalinks['tag_base'], '/' ) : 'the-san-pham';
						$link = home_url( '/' . $base . '/' . $term->slug . '/' );
					}
				}
				$paged = max( 1, (int) get_query_var( 'paged' ) );
				if ( $paged > 1 && '' !== (string) get_option( 'permalink_structure' ) ) {
					$link = user_trailingslashit( trailingslashit( $link ) . 'page/' . $paged, 'paged' );
				}
				return $link;
			}
		}
	}

	if ( function_exists( 'is_shop' ) && is_shop() && ! is_search() ) {
		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/cua-hang/' );
		$paged    = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged > 1 && '' !== (string) get_option( 'permalink_structure' ) ) {
			return user_trailingslashit( trailingslashit( $shop_url ) . 'page/' . $paged, 'paged' );
		}
		return $shop_url;
	}

	if ( is_singular() ) {
		$post_id = (int) get_queried_object_id();
		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}
	}

	return (string) $canonical;
}
add_filter( 'wpseo_canonical', 'nexxi_seo_resolve_canonical_url', 99 );
add_filter( 'wpseo_opengraph_url', 'nexxi_seo_resolve_canonical_url', 99 );
add_filter(
	'get_canonical_url',
	static function ( $canonical_url, $post ) {
		if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
			return get_permalink( $post );
		}
		return $canonical_url;
	},
	99,
	2
);

/**
 * Làm sạch Breadcrumb của Yoast (và Schema BreadcrumbList đi kèm):
 * cập nhật đúng permalink mới và bóc sạch mã lô POS cũ (2610.11, 2609.8, 2503.8...).
 */
add_filter(
	'wpseo_breadcrumb_links',
	static function ( $links ) {
		if ( ! is_array( $links ) || empty( $links ) ) {
			return $links;
		}
		foreach ( $links as $idx => $item ) {
			if ( isset( $item['text'] ) && is_string( $item['text'] ) ) {
				$links[ $idx ]['text'] = nexxi_seo_clean_product_title( $item['text'] );
			}
		}
		if ( is_singular( 'product' ) ) {
			$post_id = (int) get_queried_object_id();
			if ( $post_id > 0 ) {
				$last_key                     = array_key_last( $links );
				$links[ $last_key ]['url']    = get_permalink( $post_id );
				$links[ $last_key ]['text']   = nexxi_seo_clean_product_title( get_the_title( $post_id ) );
			}
		}
		return $links;
	},
	99
);

/* ==========================================================================
 * 5. ROBOTS META, XML SITEMAPS & ROBOTS.TXT (P0 #3 & P0 #4)
 * ========================================================================== */

add_filter(
	'wpseo_robots',
	static function ( $robots ) {
		if ( nexxi_seo_is_noindex_context() ) {
			return 'noindex, follow';
		}
		return $robots;
	},
	99
);

add_filter(
	'wp_robots',
	static function ( $robots ) {
		if ( is_array( $robots ) && nexxi_seo_is_noindex_context() ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	},
	99
);

add_filter(
	'wpseo_exclude_from_sitemap_by_post_ids',
	static function ( $excluded_ids ) {
		$excluded_ids = is_array( $excluded_ids ) ? $excluded_ids : array();
		return array_values( array_unique( array_merge( array_map( 'intval', $excluded_ids ), nexxi_seo_utility_page_ids() ) ) );
	},
	99
);

add_filter(
	'wpseo_exclude_from_sitemap_by_term_ids',
	static function ( $excluded_term_ids ) {
		$excluded_term_ids = is_array( $excluded_term_ids ) ? $excluded_term_ids : array();
		return array_values( array_unique( array_merge( array_map( 'intval', $excluded_term_ids ), nexxi_seo_excluded_term_ids() ) ) );
	},
	99
);

// Loại bỏ hoàn toàn tác giả khỏi XML Sitemap của Yoast & WordPress Core.
add_filter( 'wpseo_sitemap_exclude_author', '__return_empty_array', 99 );
add_filter(
	'wp_sitemaps_add_provider',
	static function ( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	},
	99,
	2
);

// Ngăn post-sitemap.xml chèn trùng URL Trang chủ vào đầu danh sách (chỉ giữ Trang chủ ở page-sitemap.xml).
add_filter(
	'wpseo_sitemap_post_type_first_links',
	static function ( $links, $post_type ) {
		if ( 'post' !== $post_type || ! is_array( $links ) ) {
			return $links;
		}
		$home = trailingslashit( home_url( '/' ) );
		return array_values(
			array_filter(
				$links,
				static function ( $link ) use ( $home ) {
					$loc = isset( $link['loc'] ) ? trailingslashit( (string) $link['loc'] ) : '';
					return '' !== $loc && $loc !== $home;
				}
			)
		);
	},
	99,
	2
);

// Bộ lọc chốt chặn cuối cùng trên mọi mục XML Sitemap của Yoast.
add_filter(
	'wpseo_sitemap_entry',
	static function ( $entry, $type = '', $object = null ) {
		if ( ! is_array( $entry ) || empty( $entry['loc'] ) ) {
			return false;
		}
		$loc  = (string) $entry['loc'];
		$home = trailingslashit( home_url( '/' ) );

		// Chặn lặp Trang chủ trong post-sitemap.xml (chỉ cho phép khi object là page).
		if ( trailingslashit( $loc ) === $home ) {
			if ( is_object( $object ) && isset( $object->post_type ) && 'page' !== $object->post_type ) {
				return false;
			}
			if ( 'user' === $type || 'term' === $type ) {
				return false;
			}
		}

		$path = (string) wp_parse_url( $loc, PHP_URL_PATH );
		$norm = '/' . trim( strtolower( $path ), '/' ) . '/';

		$blocked_exact = array(
			'/hello-world/',
			'/sample-page/',
			'/gio-hang/',
			'/thanh-toan/',
			'/tai-khoan/',
			'/yeu-thich/',
			'/category/uncategorized/',
			'/danh-muc-san-pham/chua-phan-loai/',
			'/danh-muc-san-pham/uncategorized/',
		);
		if ( in_array( $norm, $blocked_exact, true ) || 0 === strpos( $norm, '/author/' ) ) {
			return false;
		}
		if ( false !== strpos( $loc, '?product_cat=' ) ) {
			return false;
		}

		return $entry;
	},
	99,
	3
);

/**
 * Làm gọn robots.txt: gộp các khối User-agent: * bị lặp, thêm Disallow tìm kiếm nội bộ,
 * giữ nguyên quyền truy cập cho mọi bot tìm kiếm & AI Crawlers và khai báo chuẩn Sitemap.
 *
 * @param string $output Nội dung robots.txt hiện tại.
 * @param string $public Trạng thái blog_public.
 * @return string
 */
function nexxi_seo_filter_robots_txt( $output, $public ) {
	if ( '0' === (string) $public ) {
		return $output;
	}

	$star_allows    = array( '/wp-admin/admin-ajax.php' );
	$star_disallows = array(
		'/wp-admin/',
		'/wp-content/uploads/wc-logs/',
		'/wp-content/uploads/woocommerce_transient_files/',
		'/wp-content/uploads/woocommerce_uploads/',
		'/*?add-to-cart=',
		'/*?*add-to-cart=',
		'/*?s=',
		'/*?*s=',
		'/search/',
	);
	$other_agents   = array();
	$sitemaps       = array();
	$current_agent  = '';

	$lines = preg_split( '/\R/u', (string) $output );
	foreach ( (array) $lines as $raw_line ) {
		$line = trim( (string) $raw_line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}
		if ( preg_match( '/^User-agent:\s*(.+)$/i', $line, $m ) ) {
			$current_agent = trim( $m[1] );
			if ( '*' !== $current_agent && ! isset( $other_agents[ $current_agent ] ) ) {
				$other_agents[ $current_agent ] = array();
			}
			continue;
		}
		if ( preg_match( '/^Sitemap:\s*(.+)$/i', $line, $m ) ) {
			$sm = trim( $m[1] );
			if ( '' !== $sm ) {
				$sitemaps[] = $sm;
			}
			continue;
		}
		if ( preg_match( '/^(Allow|Disallow):\s*(.*)$/i', $line, $m ) ) {
			$directive = ucfirst( strtolower( $m[1] ) );
			$val       = trim( $m[2] );
			if ( '' === $current_agent || '*' === $current_agent ) {
				if ( '' === $val ) {
					continue; // Bỏ dòng "Disallow:" rỗng dư thừa của Yoast block.
				}
				if ( 'Allow' === $directive ) {
					$star_allows[] = $val;
				} else {
					$star_disallows[] = $val;
				}
			} else {
				$other_agents[ $current_agent ][] = $directive . ': ' . $val;
			}
		}
	}

	$star_allows    = array_values( array_unique( $star_allows ) );
	$star_disallows = array_values( array_unique( $star_disallows ) );

	if ( empty( $sitemaps ) ) {
		$sitemaps[] = home_url( '/sitemap_index.xml' );
	} else {
		$sitemaps = array_values( array_unique( $sitemaps ) );
	}

	$out   = array();
	$out[] = 'User-agent: *';
	foreach ( $star_allows as $a ) {
		$out[] = 'Allow: ' . $a;
	}
	foreach ( $star_disallows as $d ) {
		$out[] = 'Disallow: ' . $d;
	}

	foreach ( $other_agents as $ua => $rules ) {
		if ( empty( $rules ) ) {
			continue;
		}
		$out[] = '';
		$out[] = 'User-agent: ' . $ua;
		foreach ( array_unique( $rules ) as $r ) {
			$out[] = $r;
		}
	}

	$out[] = '';
	foreach ( $sitemaps as $sm ) {
		$out[] = 'Sitemap: ' . $sm;
	}

	return implode( "\n", $out ) . "\n";
}
add_filter( 'robots_txt', 'nexxi_seo_filter_robots_txt', 99, 2 );
add_filter( 'robots_txt', 'nexxi_seo_filter_robots_txt', 999999, 2 );

/* ==========================================================================
 * 6. ON-PAGE FORMULAS: <title> (35–60), <meta description> (120–158) & OG (P0 #5)
 * ========================================================================== */

/**
 * Xây dựng <title> rút gọn chuẩn Google SERP (42–60 ký tự) cho trang chi tiết sản phẩm,
 * tách biệt hoàn toàn với tiêu đề dài 110–130 ký tự kiểu sàn Shopee.
 *
 * @param int    $post_id      ID sản phẩm.
 * @param string $fallback_raw Tiêu đề dự phòng (nếu dùng trong bài test).
 * @return string
 */
function nexxi_seo_build_product_title( $post_id, $fallback_raw = '' ) {
	$suffix = ' | NEXXI Kids'; // 13 ký tự.

	if ( $post_id > 0 ) {
		$custom = nexxi_seo_clean_product_title( (string) get_post_meta( $post_id, '_nexxi_seo_title', true ) );
		if ( '' === $custom ) {
			$custom = nexxi_seo_clean_product_title( (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ) );
		}
		if ( '' !== $custom && false === strpos( $custom, '%%' ) ) {
			$len = mb_strlen( $custom, 'UTF-8' );
			if ( $len >= 38 && $len <= 60 ) {
				return $custom;
			}
			// Nếu _nexxi_seo_title hơi dài (61–75 ký tự), bóc hậu tố thương hiệu rồi cắt gọn về <= 60 ký tự.
			if ( $len > 60 ) {
				$stripped = preg_replace( '/\s*[|\-–]\s*NEXXI(?:\s+Kids(?:\s+Fashion)?)?\s*$/iu', '', $custom );
				$stripped = trim( (string) $stripped );
				if ( '' !== $stripped ) {
					$fallback_raw = $stripped;
				}
			}
		}
	}

	$raw_name = '' !== $fallback_raw ? $fallback_raw : ( $post_id > 0 ? get_the_title( $post_id ) : '' );
	$core     = nexxi_seo_clean_product_title( $raw_name );
	if ( '' === $core ) {
		return 'Quần Áo Trẻ Em Thiết Kế Hàn Quốc | NEXXI Kids';
	}

	// Gỡ chữ NEXXI / NEXXI Kids lặp trong thân tên sản phẩm để nhường chỗ cho từ khóa mô tả.
	$core = preg_replace( '/\bNEXXI(?:\s+Kids(?:\s+Fashion)?)?\b/iu', '', $core );
	$core = preg_replace( '/\b(?:Hottrend|Hot\s*Trend|Siêu\s*Xinh|Cực\s*Xinh)\b/iu', '', (string) $core );
	$core = preg_replace( '/\s+/u', ' ', trim( (string) $core, " \t\n\r\0\x0B,;:-–—|+" ) );
	if ( '' === $core ) {
		return 'Quần Áo Trẻ Em Thiết Kế Hàn Quốc | NEXXI Kids';
	}

	$max_core = 60 - mb_strlen( $suffix, 'UTF-8' ); // 47 ký tự.

	// Nếu tên quá dài và có dấu phân tách mệnh đề phụ (" – ", " | ", ", "), ưu tiên vế chính nếu đủ độ dài >= 29 ký tự.
	if ( mb_strlen( $core, 'UTF-8' ) > $max_core ) {
		$parts = preg_split( '/\s*(?:[|–—]|(?<=\S)\s+-\s+|,)\s*/u', $core );
		if ( is_array( $parts ) && ! empty( $parts[0] ) ) {
			$lead = trim( $parts[0] );
			if ( mb_strlen( $lead, 'UTF-8' ) >= 29 && mb_strlen( $lead, 'UTF-8' ) <= $max_core ) {
				$core = $lead;
			} elseif ( mb_strlen( $lead, 'UTF-8' ) < 29 && isset( $parts[1] ) ) {
				$combined = trim( $lead . ' ' . trim( $parts[1] ) );
				$core     = nexxi_seo_trim_chars( $combined, $max_core );
			} else {
				$core = nexxi_seo_trim_chars( $core, $max_core );
			}
		} else {
			$core = nexxi_seo_trim_chars( $core, $max_core );
		}
	}

	// Nếu tên sản phẩm quá ngắn (< 29 ký tự => tổng < 42 ký tự), bổ sung thuộc tính ngành hàng thật.
	if ( mb_strlen( $core, 'UTF-8' ) < 29 ) {
		$low = mb_strtolower( $core, 'UTF-8' );
		if ( false === strpos( $low, 'bé' ) && false === strpos( $low, 'trẻ em' ) ) {
			$try = $core . ' Cho Bé Thiết Kế Đẹp';
		} else {
			$try = $core . ' Thiết Kế Chuẩn Hàn';
		}
		if ( mb_strlen( $try, 'UTF-8' ) <= $max_core ) {
			$core = $try;
		} elseif ( mb_strlen( $core . ' Thiết Kế Đẹp', 'UTF-8' ) <= $max_core ) {
			$core .= ' Thiết Kế Đẹp';
		}
	}

	$core = nexxi_seo_trim_chars( $core, $max_core );
	return $core . $suffix;
}

/**
 * Xây dựng <meta name="description"> (130–156 ký tự) cho trang chi tiết sản phẩm,
 * tự động chuẩn hóa "7 ngày" -> "đổi hàng 15 ngày".
 *
 * @param int $post_id ID sản phẩm.
 * @return string
 */
function nexxi_seo_build_product_desc( $post_id ) {
	if ( $post_id <= 0 ) {
		return '';
	}

	foreach ( array( '_nexxi_seo_desc', '_yoast_wpseo_metadesc' ) as $mkey ) {
		$raw = trim( (string) get_post_meta( $post_id, $mkey, true ) );
		if ( '' !== $raw && false === strpos( $raw, '%%' ) ) {
			$desc = nexxi_seo_clean_product_title( $raw );
			$desc = nexxi_seo_normalize_return_policy_text( $desc );
			$len  = mb_strlen( $desc, 'UTF-8' );
			if ( $len >= 120 && $len <= 158 ) {
				return $desc;
			}
			if ( $len > 158 ) {
				$tail_short = '. Chính hãng NEXXI Kids, đổi hàng 15 ngày.';
				$tail_long  = '. Chính hãng NEXXI Kids, miễn phí ship & đổi hàng 15 ngày.';
				$head       = rtrim( nexxi_seo_trim_chars( $desc, 156 - mb_strlen( $tail_short, 'UTF-8' ) ), '.! ' );
				$cand       = $head . $tail_short;
				if ( mb_strlen( $cand, 'UTF-8' ) < 120 && mb_strlen( $head . $tail_long, 'UTF-8' ) <= 158 ) {
					$cand = $head . $tail_long;
				}
				return $cand;
			}
			if ( $len >= 50 && $len < 120 ) {
				$base_d = rtrim( $desc, '.! ' );
				foreach ( array(
					'. Chính hãng NEXXI Kids, miễn phí ship toàn quốc & đổi hàng 15 ngày.',
					'. Chính hãng NEXXI Kids, miễn phí ship & đổi hàng 15 ngày.',
					'. Chính hãng NEXXI Kids, đổi hàng 15 ngày.',
				) as $suffix_d ) {
					$try_d = $base_d . $suffix_d;
					$tlen  = mb_strlen( $try_d, 'UTF-8' );
					if ( $tlen >= 120 && $tlen <= 158 ) {
						return $try_d;
					}
				}
			}
		}
	}

	$post  = get_post( $post_id );
	$title = nexxi_seo_clean_product_title( $post instanceof WP_Post ? $post->post_title : get_the_title( $post_id ) );
	$short = $post instanceof WP_Post ? nexxi_seo_clean_product_title( $post->post_excerpt ) : '';
	$short = nexxi_seo_normalize_return_policy_text( $short );

	$cta_tail = ' – vải mềm mát, miễn phí ship toàn quốc & đổi hàng 15 ngày tại NEXXI Kids.'; // 74 ký tự.
	if ( '' !== $short && mb_strlen( $short, 'UTF-8' ) >= 60 ) {
		$short_tail = ' Chính hãng NEXXI Kids, đổi hàng 15 ngày.'; // 41 ký tự.
		$lead       = nexxi_seo_trim_chars( $short, 155 - mb_strlen( $short_tail, 'UTF-8' ) );
		$candidate  = rtrim( $lead, '.! ' ) . '.' . $short_tail;
		if ( mb_strlen( $candidate, 'UTF-8' ) >= 125 && mb_strlen( $candidate, 'UTF-8' ) <= 158 ) {
			return $candidate;
		}
	}

	$max_lead = 155 - mb_strlen( $cta_tail, 'UTF-8' ); // 81 ký tự.
	$lead     = nexxi_seo_trim_chars( $title, $max_lead );
	if ( mb_strlen( $lead . $cta_tail, 'UTF-8' ) < 125 ) {
		$lead = $lead . ' thiết kế chuẩn Hàn cho bé (12–45kg)';
		$lead = nexxi_seo_trim_chars( $lead, $max_lead );
	}
	return $lead . $cta_tail;
}

/**
 * Xây dựng <title> gọn đẹp (38–60 ký tự, không có "Lưu trữ ") cho Danh mục & Thẻ sản phẩm.
 *
 * @param WP_Term $term Đối tượng danh mục/thẻ.
 * @return string
 */
function nexxi_seo_build_term_title( WP_Term $term ) {
	$name   = nexxi_seo_format_term_name( $term->name );
	$suffix = ' | NEXXI Kids'; // 13 ký tự.
	if ( '' === $name ) {
		return 'Danh Mục Quần Áo Trẻ Em Thiết Kế | NEXXI Kids';
	}

	$low      = mb_strtolower( $name, 'UTF-8' );
	$has_kids = ( false !== strpos( $low, 'bé' ) || false !== strpos( $low, 'trẻ em' ) );

	$candidates = $has_kids
		? array(
			$name . ' Thiết Kế Hàn Quốc' . $suffix,
			$name . ' Thiết Kế Đẹp' . $suffix,
			$name . ' Cao Cấp' . $suffix,
			$name . $suffix,
		)
		: array(
			$name . ' Cho Bé Thiết Kế Hàn Quốc' . $suffix,
			$name . ' Cho Bé Thiết Kế Đẹp' . $suffix,
			$name . ' Cho Bé' . $suffix,
			$name . $suffix,
		);

	foreach ( $candidates as $cand ) {
		$len = mb_strlen( $cand, 'UTF-8' );
		if ( $len >= 36 && $len <= 60 ) {
			return $cand;
		}
	}

	$trimmed = nexxi_seo_trim_chars( $name, 60 - mb_strlen( $suffix, 'UTF-8' ) );
	return $trimmed . $suffix;
}

/**
 * Xây dựng <meta name="description"> (130–155 ký tự) cho Danh mục & Thẻ sản phẩm.
 *
 * @param WP_Term $term Đối tượng danh mục/thẻ.
 * @return string
 */
function nexxi_seo_build_term_desc( WP_Term $term ) {
	// Ưu tiên meta description riêng của term trong Yoast nếu hợp lệ (120–158 ký tự).
	$tax_meta = get_option( 'wpseo_taxonomy_meta', array() );
	if ( is_array( $tax_meta ) && ! empty( $tax_meta[ $term->taxonomy ][ $term->term_id ]['wpseo_desc'] ) ) {
		$custom = nexxi_seo_clean_product_title( $tax_meta[ $term->taxonomy ][ $term->term_id ]['wpseo_desc'] );
		$custom = nexxi_seo_normalize_return_policy_text( $custom );
		$clen   = mb_strlen( $custom, 'UTF-8' );
		if ( $clen >= 120 && $clen <= 158 ) {
			return $custom;
		}
	}

	$name     = nexxi_seo_format_term_name( $term->name );
	$low_name = mb_strtolower( $name, 'UTF-8' );
	$has_kids = ( false !== strpos( $low_name, 'bé' ) || false !== strpos( $low_name, 'trẻ em' ) );
	$subject  = $has_kids ? $low_name : ( $low_name . ' cho bé' );

	$tpl_long  = sprintf(
		'Mua %s chính hãng NEXXI Kids thiết kế Hàn Quốc (12–45kg): chất vải mềm mát, lên phom xinh, miễn phí ship toàn quốc & đổi hàng 15 ngày.',
		$subject
	);
	if ( mb_strlen( $tpl_long, 'UTF-8' ) <= 156 ) {
		return $tpl_long;
	}

	$tpl_short = sprintf(
		'Khám phá %s NEXXI Kids (12–45kg): vải mềm mát, chuẩn phom Hàn, miễn phí ship toàn quốc & đổi hàng 15 ngày.',
		nexxi_seo_trim_chars( $subject, 45 )
	);
	return nexxi_seo_trim_chars( $tpl_short, 156 );
}

/**
 * Bản đồ <title> và <meta name="description"> riêng biệt cho các trang tĩnh của NEXXI Kids.
 *
 * @param string $slug Slug trang tĩnh.
 * @return array{title:string,desc:string}|null
 */
function nexxi_seo_static_page_meta( $slug ) {
	$map = array(
		'gioi-thieu'         => array(
			'title' => 'Giới Thiệu NEXXI Kids – Thời Trang Trẻ Em Thiết Kế Hàn Quốc',
			'desc'  => 'Tìm hiểu thương hiệu thời trang trẻ em thiết kế NEXXI Kids (12–45kg): cảm hứng Hàn Quốc, chất liệu mềm mát, phom chuẩn Việt, hỗ trợ đổi hàng 15 ngày.',
		),
		'lien-he'            => array(
			'title' => 'Liên Hệ Tư Vấn Size & Hỗ Trợ Khách Hàng | NEXXI Kids',
			'desc'  => 'Liên hệ NEXXI Kids qua Hotline/Zalo 0942 958 091 (8h–17h) để được tư vấn chọn size quần áo trẻ em 12–45kg, tra cứu đơn hàng và hợp tác đại lý sỉ lẻ.',
		),
		'doi-tra'            => array(
			'title' => 'Chính Sách Đổi Hàng Trong 15 Ngày Tiện Lợi | NEXXI Kids',
			'desc'  => 'Chính sách đổi hàng 15 ngày tại NEXXI Kids: hỗ trợ đổi size, đổi mẫu nhanh chóng và hoàn tiền 100% nếu sản phẩm lỗi hoặc không đúng mô tả trên toàn quốc.',
		),
		'van-chuyen'         => array(
			'title' => 'Chính Sách Vận Chuyển & Giao Hàng Toàn Quốc | NEXXI Kids',
			'desc'  => 'Chính sách vận chuyển toàn quốc của NEXXI Kids: miễn phí giao hàng, đóng gói chỉn chu, hỗ trợ kiểm tra đơn hàng và giao nhanh tận nơi cho phụ huynh.',
		),
		'chinh-sach-bao-mat' => array(
			'title' => 'Chính Sách Bảo Mật Thông Tin Khách Hàng | NEXXI Kids',
			'desc'  => 'Chính sách bảo mật dữ liệu khách hàng tại NEXXI Kids: cam kết an toàn thông tin phụ huynh và đối tác 18+, tuyệt đối không thu thập dữ liệu trẻ em.',
		),
		'dieu-khoan-su-dung' => array(
			'title' => 'Điều Khoản Sử Dụng & Mua Hàng Trực Tuyến | NEXXI Kids',
			'desc'  => 'Điều khoản mua sắm bán lẻ và bán sỉ tại NEXXI Kids dành cho phụ huynh, người giám hộ và đối tác từ đủ 18 tuổi trở lên, minh bạch quyền lợi đổi trả.',
		),
	);
	return isset( $map[ $slug ] ) ? $map[ $slug ] : null;
}

/**
 * Xác định <title> chuẩn SEO (35–60 ký tự) cho trang hiện tại.
 *
 * @param string $current_title Tiêu đề hiện tại.
 * @return string
 */
function nexxi_seo_resolve_title( $current_title = '' ) {
	if ( is_front_page() ) {
		return NEXXI_SEO_HOME_TITLE;
	}

	if ( function_exists( 'is_shop' ) && is_shop() && ! is_search() ) {
		if ( ! empty( $_GET['on_sale'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'Flash Sale Quần Áo Trẻ Em Ưu Đãi Hot | NEXXI Kids';
		}
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged > 1 ) {
			return 'Cửa Hàng Quần Áo Trẻ Em – Trang ' . $paged . ' | NEXXI Kids';
		}
		return 'Cửa Hàng Quần Áo Trẻ Em Thiết Kế Mới Nhất | NEXXI Kids';
	}

	if ( ( function_exists( 'is_product_category' ) && is_product_category() )
		|| ( function_exists( 'is_product_tag' ) && is_product_tag() )
		|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
	) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			return nexxi_seo_build_term_title( $term );
		}
	}

	if ( is_singular( 'product' ) ) {
		return nexxi_seo_build_product_title( (int) get_queried_object_id() );
	}

	if ( is_page() ) {
		$page = get_queried_object();
		if ( $page instanceof WP_Post ) {
			$static = nexxi_seo_static_page_meta( $page->post_name );
			if ( $static ) {
				return $static['title'];
			}
			$clean = nexxi_seo_clean_product_title( $page->post_title );
			if ( '' !== $clean ) {
				$cand = nexxi_seo_trim_chars( $clean, 47 ) . ' | NEXXI Kids';
				return $cand;
			}
		}
	}

	if ( is_singular( 'post' ) ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			$clean = nexxi_seo_clean_product_title( $post->post_title );
			if ( '' !== $clean ) {
				return nexxi_seo_trim_chars( $clean, 47 ) . ' | NEXXI Kids';
			}
		}
	}

	// Bóc chữ "Lưu trữ " nếu rơi vào bất kỳ archive phụ nào khác.
	if ( is_string( $current_title ) && '' !== $current_title ) {
		$cleaned = trim( (string) preg_replace( '/^Lưu\s+trữ\s+/iu', '', $current_title ) );
		return $cleaned;
	}

	return (string) $current_title;
}
add_filter( 'wpseo_title', 'nexxi_seo_resolve_title', 99 );
add_filter( 'pre_get_document_title', 'nexxi_seo_resolve_title', 99 );
add_filter( 'wpseo_opengraph_title', 'nexxi_seo_resolve_title', 99 );

/**
 * Xác định <meta name="description"> chuẩn SEO (120–158 ký tự) cho trang hiện tại.
 *
 * @param string $current_desc Mô tả hiện tại.
 * @return string
 */
function nexxi_seo_resolve_metadesc( $current_desc = '' ) {
	if ( is_front_page() ) {
		return NEXXI_SEO_HOME_DESC;
	}

	// Không cần ép mô tả quảng cáo trên các trang tiện ích noindex.
	if ( nexxi_seo_is_noindex_context() ) {
		return (string) $current_desc;
	}

	if ( function_exists( 'is_shop' ) && is_shop() && ! is_search() ) {
		if ( ! empty( $_GET['on_sale'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'Săn Flash Sale quần áo trẻ em thiết kế NEXXI Kids (12–45kg): đầm bé gái, đồ bộ bé trai giảm sâu chuẩn Hàn, miễn phí ship toàn quốc, đổi hàng 15 ngày.';
		}
		return 'Khám phá bộ sưu tập quần áo trẻ em NEXXI Kids mới nhất (12–45kg): đầm bé gái, set bộ bé trai chuẩn Hàn, vải mềm mát, miễn phí ship & đổi hàng 15 ngày.';
	}

	if ( ( function_exists( 'is_product_category' ) && is_product_category() )
		|| ( function_exists( 'is_product_tag' ) && is_product_tag() )
		|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
	) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			return nexxi_seo_build_term_desc( $term );
		}
	}

	if ( is_singular( 'product' ) ) {
		return nexxi_seo_build_product_desc( (int) get_queried_object_id() );
	}

	if ( is_page() ) {
		$page = get_queried_object();
		if ( $page instanceof WP_Post ) {
			$custom = nexxi_seo_normalize_return_policy_text( trim( (string) $current_desc ) );
			if ( mb_strlen( $custom, 'UTF-8' ) >= 120 && mb_strlen( $custom, 'UTF-8' ) <= 158 ) {
				return $custom;
			}
			$static = nexxi_seo_static_page_meta( $page->post_name );
			if ( $static ) {
				return $static['desc'];
			}
			$excerpt = nexxi_seo_clean_product_title( $page->post_excerpt ?: wp_trim_words( $page->post_content, 36, '' ) );
			if ( mb_strlen( $excerpt, 'UTF-8' ) >= 60 ) {
				$tail = ' Xem chi tiết tại NEXXI Kids Fashion.';
				return rtrim( nexxi_seo_trim_chars( $excerpt, 155 - mb_strlen( $tail, 'UTF-8' ) ), '.! ' ) . '.' . $tail;
			}
			return NEXXI_SEO_HOME_DESC;
		}
	}

	if ( is_singular( 'post' ) ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			$custom = nexxi_seo_normalize_return_policy_text( trim( (string) $current_desc ) );
			if ( mb_strlen( $custom, 'UTF-8' ) >= 120 && mb_strlen( $custom, 'UTF-8' ) <= 158 ) {
				return $custom;
			}
			$excerpt = nexxi_seo_clean_product_title( $post->post_excerpt ?: wp_trim_words( $post->post_content, 36, '' ) );
			if ( '' !== $excerpt ) {
				$tail = ' Cẩm nang chọn đồ cho bé từ NEXXI Kids.';
				return rtrim( nexxi_seo_trim_chars( $excerpt, 155 - mb_strlen( $tail, 'UTF-8' ) ), '.! ' ) . '.' . $tail;
			}
		}
	}

	$norm = nexxi_seo_normalize_return_policy_text( trim( (string) $current_desc ) );
	return '' !== $norm ? $norm : NEXXI_SEO_HOME_DESC;
}
add_filter( 'wpseo_metadesc', 'nexxi_seo_resolve_metadesc', 99 );
add_filter( 'wpseo_opengraph_desc', 'nexxi_seo_resolve_metadesc', 99 );
add_filter(
	'wpseo_schema_website',
	static function ( $data ) {
		if ( is_array( $data ) ) {
			$data['description'] = NEXXI_SEO_HOME_DESC;
		}
		return $data;
	},
	99
);

/**
 * Xác định URL ảnh OpenGraph tối ưu cho trang hiện tại (ảnh SP -> album/biến thể -> banner thương hiệu).
 *
 * @param string $current_img Ảnh OG hiện tại (nếu có).
 * @return string
 */
function nexxi_seo_resolve_og_image( $current_img = '' ) {
	$cur = trim( (string) $current_img );
	if ( '' !== $cur ) {
		return $cur;
	}

	if ( is_singular( 'product' ) ) {
		$pid     = (int) get_queried_object_id();
		$product = ( $pid > 0 && function_exists( 'wc_get_product' ) ) ? wc_get_product( $pid ) : null;
		if ( $product instanceof WC_Product ) {
			$img_id = (int) $product->get_image_id();
			if ( $img_id > 0 ) {
				$url = wp_get_attachment_image_url( $img_id, 'full' );
				if ( $url ) {
					return $url;
				}
			}
			foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
				$url = wp_get_attachment_image_url( (int) $gid, 'full' );
				if ( $url ) {
					return $url;
				}
			}
			if ( $product->is_type( 'variable' ) ) {
				foreach ( (array) $product->get_children() as $vid ) {
					$v_thumb = (int) get_post_meta( (int) $vid, '_thumbnail_id', true );
					if ( $v_thumb > 0 ) {
						$url = wp_get_attachment_image_url( $v_thumb, 'full' );
						if ( $url ) {
							return $url;
						}
					}
				}
			}
		}
	}

	if ( is_singular() ) {
		$thumb_id = (int) get_post_thumbnail_id( get_queried_object_id() );
		if ( $thumb_id > 0 ) {
			$url = wp_get_attachment_image_url( $thumb_id, 'full' );
			if ( $url ) {
				return $url;
			}
		}
	}

	return nexxi_seo_default_og_image();
}
add_filter( 'wpseo_opengraph_image', 'nexxi_seo_resolve_og_image', 99 );

// Đảm bảo Yoast SEO v14–v28 luôn nạp ảnh OG dự phòng khi container ảnh của trang/danh mục đang rỗng.
add_action(
	'wpseo_add_opengraph_images',
	static function ( $image_container ) {
		if ( ! is_object( $image_container ) ) {
			return;
		}
		if ( method_exists( $image_container, 'has_images' ) && $image_container->has_images() ) {
			return;
		}
		$fallback = nexxi_seo_resolve_og_image( '' );
		if ( '' === $fallback ) {
			return;
		}
		if ( method_exists( $image_container, 'add_image_by_url' ) ) {
			$image_container->add_image_by_url( $fallback );
		} elseif ( method_exists( $image_container, 'add_image' ) ) {
			$image_container->add_image( array( 'url' => $fallback ) );
		}
	},
	99
);

/**
 * Fallback trực tiếp tại wp_head khi môi trường không bật plugin Yoast SEO.
 */
add_action(
	'wp_head',
	static function () {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return;
		}
		// Trên trang chi tiết SP không có Yoast, Nexxi_Web_Product_Manager::output_seo_head() đã xuất thẻ cơ bản.
		if ( is_singular( 'product' ) && class_exists( 'Nexxi_Web_Product_Manager' ) ) {
			return;
		}

		$canonical = nexxi_seo_resolve_canonical_url( '' );
		$title     = nexxi_seo_resolve_title( '' );
		$desc      = nexxi_seo_resolve_metadesc( '' );
		$og_img    = nexxi_seo_resolve_og_image( '' );

		if ( '' !== $desc && ! nexxi_seo_is_noindex_context() ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		if ( '' !== $canonical && ! nexxi_seo_is_noindex_context() ) {
			remove_action( 'wp_head', 'rel_canonical' );
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
		}
		if ( ! nexxi_seo_is_noindex_context() ) {
			echo '<meta property="og:locale" content="vi_VN">' . "\n";
			echo '<meta property="og:type" content="website">' . "\n";
			echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
			if ( '' !== $desc ) {
				echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
			}
			if ( '' !== $canonical ) {
				echo '<meta property="og:url" content="' . esc_url( $canonical ) . '">' . "\n";
			}
			if ( '' !== $og_img ) {
				echo '<meta property="og:image" content="' . esc_url( $og_img ) . '">' . "\n";
			}
			echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
		}
	},
	3
);
