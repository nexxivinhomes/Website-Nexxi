<?php
/**
 * NEXXI Kids — Tối ưu tốc độ tải trang (frontend).
 *
 * 1. Font: gộp 2 bản Material Symbols (~5 MB) thành 1 bản chỉ chứa các icon thực sự dùng (~vài chục KB),
 *    bỏ trục opsz của Nunito Sans (giảm ~35%), thêm preconnect fonts.gstatic.com.
 * 2. Bỏ CSS/JS không dùng trên các màn hình Stitch (Gutenberg block CSS, WooCommerce CSS, gallery
 *    PhotoSwipe/FlexSlider/Zoom vì Stitch tự dựng gallery riêng) + bỏ script emoji.
 * 3. Ảnh: tạo đủ cỡ ảnh cho ảnh tải từ Pancake (trước đây chỉ có thumbnail 150px nên thẻ SP phải tải ảnh gốc),
 *    tự tạo bù cỡ ảnh cho ảnh cũ chạy ngầm, srcset cho banner, ưu tiên WebP cho ảnh mặc định của theme.
 * 4. Thời gian cache trang (LiteSpeed) cho khách: 1 giờ, tự rút ngắn đúng lúc Flash Sale kết thúc.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==========================================================================
 * 1. FONT
 * ========================================================================== */

/** Icon Material Symbols dùng trong theme (HTML shell + PHP + JS chèn động). */
function nexxi_perf_icon_base() {
	return array(
		'account_balance_wallet', 'account_circle', 'add', 'add_circle', 'add_location_alt', 'add_shopping_cart', 'alarm', 'alarm_on',
		'arrow_back', 'arrow_back_ios_new', 'arrow_forward', 'arrow_right_alt', 'auto_awesome', 'autorenew', 'badge', 'bolt', 'bookmark_added',
		'boy', 'cake', 'call', 'card_giftcard', 'celebration', 'chat', 'check', 'check_circle', 'checkroom', 'chevron_left', 'chevron_right',
		'child_care', 'close', 'confirmation_number', 'cottage', 'credit_card', 'dashboard', 'delete', 'delete_outline', 'directions_run',
		'download_for_offline', 'eco', 'edit_location_alt', 'edit_note', 'error', 'expand_less', 'expand_more', 'face_6', 'favorite',
		'favorite_border', 'featured_seasonal_and_gifts', 'file_download', 'format_image_left', 'forum', 'girl', 'handshake', 'heart_broken',
		'help', 'help_outline', 'high_res', 'holiday_village', 'home', 'home_pin', 'how_to_reg', 'info', 'inventory', 'inventory_2', 'iron',
		'keyboard_double_arrow_down', 'keyboard_double_arrow_up', 'local_fire_department', 'local_mall', 'local_offer', 'local_shipping',
		'location_on', 'lock', 'lock_clock', 'lock_reset', 'login', 'logout', 'loyalty', 'mail', 'manage_accounts', 'menu', 'menu_book',
		'military_tech', 'notifications_active', 'open_in_new', 'palette', 'payments', 'person', 'person_add', 'person_pin', 'phone_iphone',
		'photo_camera', 'pin_drop', 'published_with_changes', 'qr_code_2', 'redeem', 'remove', 'scale', 'schedule', 'search', 'sell', 'send',
		'settings', 'shield', 'shield_person', 'shopping_bag', 'shopping_basket', 'shopping_cart', 'smart_toy', 'sort', 'star', 'star_half',
		'stars', 'storefront', 'straighten', 'styler', 'support_agent', 'swap_horizontal_circle', 'sync', 'thumb_up', 'timer', 'toys', 'tune',
		'verified', 'verified_user', 'visibility', 'visibility_off', 'volunteer_activism', 'warning', 'wash', 'wb_sunny', 'zoom_in',
	);
}

/** Danh sách icon đầy đủ = cố định + các icon tự học được khi render (VD: icon nhập trong Studio). */
function nexxi_perf_icon_list( $html = '' ) {
	$base    = nexxi_perf_icon_base();
	$learned = get_option( 'nexxi_perf_icons_learned', array() );
	$learned = is_array( $learned ) ? $learned : array();
	if ( '' !== $html && preg_match_all( '~material-symbols-(?:outlined|rounded)[^>]*>\s*([a-z0-9_]{2,40})\s*<~', $html, $m ) ) {
		$new = array_diff( array_unique( $m[1] ), $base, $learned );
		if ( $new && count( $learned ) < 300 ) {
			$learned = array_values( array_unique( array_merge( $learned, $new ) ) );
			sort( $learned );
			update_option( 'nexxi_perf_icons_learned', $learned, true );
		}
	}
	$all = array_values( array_unique( array_merge( $base, $learned ) ) );
	sort( $all );
	return $all;
}

function nexxi_perf_optimize_fonts( $html ) {
	if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
		return $html; // Customizer: giữ font đầy đủ để xem trước icon mới nhập.
	}
	$icons = nexxi_perf_icon_list( $html );
	$url   = 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0..1,0&icon_names=' . implode( ',', $icons ) . '&display=block';

	// Gỡ toàn bộ thẻ <link> Material Symbols cũ (mỗi trang đang tải 2 bản: 3.9 MB + 1.1 MB).
	$html = preg_replace( '~<link\b[^>]*Material\+Symbols\+Outlined[^>]*>\s*~i', '', $html );
	// Nunito Sans: bỏ trục opsz (file nhẹ hơn ~35%, hiển thị gần như không đổi).
	$html = str_replace( 'Nunito+Sans:ital,opsz,wght@0,6..12,300..800;1,6..12,300..800', 'Nunito+Sans:ital,wght@0,300..800;1,300..800', $html );

	$inject = '';
	if ( false === stripos( $html, 'href="https://fonts.gstatic.com"' ) ) {
		$inject .= '<link data-no-optimize="1" rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
	}
	if ( false === stripos( $html, 'rel="preconnect" href="https://fonts.googleapis.com"' ) && false === stripos( $html, 'href="https://fonts.googleapis.com" rel="preconnect"' ) ) {
		$inject .= '<link data-no-optimize="1" rel="preconnect" href="https://fonts.googleapis.com">';
	}
	$inject .= '<link data-no-optimize="1" id="nexxi-material-symbols" rel="stylesheet" href="' . esc_url( $url ) . '">';

	$pos = stripos( $html, '<meta charset' );
	if ( false !== $pos ) {
		$end = strpos( $html, '>', $pos );
		if ( false !== $end ) {
			return substr( $html, 0, $end + 1 ) . $inject . substr( $html, $end + 1 );
		}
	}
	$head = stripos( $html, '</head>' );
	return false === $head ? $html : substr( $html, 0, $head ) . $inject . substr( $html, $head );
}

/* ==========================================================================
 * 2. BỎ CSS / JS KHÔNG DÙNG TRÊN MÀN HÌNH STITCH
 * ========================================================================== */

/** Các màn hình Stitch dựng toàn bộ giao diện riêng, không dùng markup WooCommerce / Gutenberg. */
function nexxi_perf_is_lean_screen() {
	$screen = isset( $GLOBALS['nexxi_stitch_screen'] ) ? (string) $GLOBALS['nexxi_stitch_screen'] : '';
	return in_array( $screen, array( 'index', 'shop', 'product', 'flash-sale' ), true );
}

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( empty( $GLOBALS['nexxi_stitch_screen'] ) ) {
			return;
		}
		// Gallery mặc định của WooCommerce (Stitch có gallery riêng) — không cần trên mọi màn hình Stitch.
		foreach ( array( 'zoom', 'wc-zoom', 'flexslider', 'wc-flexslider', 'photoswipe', 'wc-photoswipe', 'photoswipe-ui-default', 'wc-photoswipe-ui-default', 'wc-single-product' ) as $h ) {
			wp_dequeue_script( $h );
		}
		foreach ( array( 'photoswipe', 'photoswipe-default-skin', 'woocommerce_prettyPhoto_css' ) as $h ) {
			wp_dequeue_style( $h );
		}
		if ( ! nexxi_perf_is_lean_screen() ) {
			return;
		}
		foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles', 'wc-blocks-style', 'wc-blocks-vendors-style', 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general', 'woocommerce-inline' ) as $h ) {
			wp_dequeue_style( $h );
		}
		wp_dequeue_script( 'wc-add-to-cart-variation' );
	},
	999
);

// Script emoji của WordPress (~20 KB JS + CSS inline) — trình duyệt hiện đại tự hiển thị emoji.
add_action(
	'init',
	static function () {
		if ( is_admin() ) {
			return;
		}
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'rsd_link' );
		add_filter( 'emoji_svg_url', '__return_false' );
	}
);

/* ==========================================================================
 * 3. ẢNH
 * ========================================================================== */

/** Cỡ ảnh storefront thật sự dùng (thẻ SP, trang chi tiết, gallery). */
function nexxi_perf_store_image_sizes() {
	return array( 'thumbnail', 'medium', 'woocommerce_thumbnail', 'woocommerce_gallery_thumbnail', 'woocommerce_single' );
}

/** Lọc danh sách cỡ ảnh cần tạo về đúng các cỡ storefront. */
function nexxi_perf_filter_store_sizes( $sizes ) {
	$keep = array_flip( nexxi_perf_store_image_sizes() );
	return array_intersect_key( (array) $sizes, $keep );
}

/** Thiếu cỡ ảnh storefront nào (bỏ qua cỡ lớn hơn ảnh gốc). */
function nexxi_perf_missing_sizes( $attachment_id ) {
	$meta = wp_get_attachment_metadata( $attachment_id );
	if ( ! is_array( $meta ) || empty( $meta['width'] ) ) {
		return array();
	}
	$have    = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();
	$missing = array();
	foreach ( array( 'woocommerce_thumbnail', 'woocommerce_single' ) as $size ) {
		if ( isset( $have[ $size ] ) ) {
			continue;
		}
		$dim = function_exists( 'wc_get_image_size' ) ? wc_get_image_size( $size ) : array( 'width' => 'woocommerce_single' === $size ? 760 : 480 );
		if ( (int) $meta['width'] > (int) $dim['width'] + 20 ) {
			$missing[] = $size;
		}
	}
	return $missing;
}

/** Tạo bù cỡ ảnh còn thiếu cho 1 ảnh. */
function nexxi_perf_regenerate_sizes( $attachment_id ) {
	if ( ! function_exists( 'wp_update_image_subsizes' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
	if ( ! function_exists( 'wp_update_image_subsizes' ) || ! nexxi_perf_missing_sizes( $attachment_id ) ) {
		return false;
	}
	add_filter( 'intermediate_image_sizes_advanced', 'nexxi_perf_filter_store_sizes', 9999 );
	$res = wp_update_image_subsizes( $attachment_id );
	remove_filter( 'intermediate_image_sizes_advanced', 'nexxi_perf_filter_store_sizes', 9999 );
	return ! is_wp_error( $res );
}

// Chạy ngầm: mỗi 5 phút tạo bù cỡ ảnh cho tối đa ~12 ảnh SP (ảnh Pancake cũ chỉ có thumbnail 150px).
add_filter(
	'cron_schedules',
	static function ( $s ) {
		$s['nexxi_every_5m'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Mỗi 5 phút (NEXXI tạo cỡ ảnh)' );
		return $s;
	}
);
add_action(
	'init',
	static function () {
		if ( 'done' === get_option( 'nexxi_perf_regen_state' ) ) {
			if ( wp_next_scheduled( 'nexxi_perf_regen_images' ) ) {
				wp_clear_scheduled_hook( 'nexxi_perf_regen_images' );
			}
			return;
		}
		if ( ! wp_next_scheduled( 'nexxi_perf_regen_images' ) ) {
			wp_schedule_event( time() + 60, 'nexxi_every_5m', 'nexxi_perf_regen_images' );
		}
	},
	30
);
add_action(
	'nexxi_perf_regen_images',
	static function () {
		global $wpdb;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$cursor = (int) get_option( 'nexxi_perf_regen_cursor', 0 );
		// Ảnh được dùng làm ảnh đại diện SP / biến thể (ưu tiên phần ảnh hiển thị nhiều nhất).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT CAST(pm.meta_value AS UNSIGNED) AS aid FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type IN ('product','product_variation')
				 WHERE pm.meta_key = '_thumbnail_id' AND CAST(pm.meta_value AS UNSIGNED) > %d
				 ORDER BY aid ASC LIMIT 40",
				$cursor
			)
		);
		if ( empty( $ids ) ) {
			update_option( 'nexxi_perf_regen_state', 'done', false );
			return;
		}
		$start = microtime( true );
		$done  = 0;
		foreach ( $ids as $aid ) {
			$aid = (int) $aid;
			if ( $aid > 0 && nexxi_perf_regenerate_sizes( $aid ) ) {
				$done++;
			}
			update_option( 'nexxi_perf_regen_cursor', $aid, false );
			if ( $done >= 12 || microtime( true ) - $start > 40 ) {
				break;
			}
		}
		if ( $done > 0 ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}
	}
);

/** srcset + sizes cho ảnh banner theo URL (cache ID ảnh để không truy vấn lại). */
function nexxi_perf_srcset_attr_for_url( $url, $sizes = '100vw' ) {
	$url = trim( (string) $url );
	if ( '' === $url || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
		return '';
	}
	$key = 'nexxi_aid_' . md5( $url );
	$aid = get_transient( $key );
	if ( false === $aid ) {
		$aid = (int) attachment_url_to_postid( $url );
		set_transient( $key, $aid, WEEK_IN_SECONDS );
	}
	$aid = (int) $aid;
	if ( $aid <= 0 ) {
		return '';
	}
	$srcset = wp_get_attachment_image_srcset( $aid, 'full' );
	return $srcset ? ' srcset="' . esc_attr( $srcset ) . '" sizes="' . esc_attr( $sizes ) . '"' : '';
}

/** Dùng bản .webp (nếu có) cho ảnh mặc định của theme trong assets/stitch/images. */
function nexxi_perf_prefer_webp( $html ) {
	static $dir = null;
	if ( null === $dir ) {
		$dir = get_template_directory() . '/assets/stitch/images/';
	}
	return preg_replace_callback(
		'~(/assets/stitch/images/)([A-Za-z0-9_-]+)\.(?:jpe?g|png)\b~',
		static function ( $m ) use ( $dir ) {
			return is_readable( $dir . $m[2] . '.webp' ) ? $m[1] . $m[2] . '.webp' : $m[0];
		},
		$html
	);
}

/* ==========================================================================
 * 4. CACHE TRANG
 * ========================================================================== */

/** Số giây cache trang cho khách: 1 giờ, nhưng hết hạn đúng lúc Flash Sale kết thúc. */
function nexxi_perf_page_cache_ttl() {
	$ttl = HOUR_IN_SECONDS;
	if ( function_exists( 'nexxi_kids_flash_timer_state' ) ) {
		$st = nexxi_kids_flash_timer_state();
		if ( ! empty( $st['is_live'] ) && ! empty( $st['ts'] ) ) {
			$ttl = min( $ttl, max( 30, (int) $st['ts'] - time() ) );
		}
	}
	return (int) $ttl;
}

/** Hậu xử lý toàn bộ HTML của màn hình Stitch trước khi gửi về trình duyệt. */
function nexxi_perf_finalize_html( $html ) {
	if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '</head>' ) ) {
		return $html;
	}
	$html = nexxi_perf_optimize_fonts( $html );
	$html = nexxi_perf_prefer_webp( $html );
	return $html;
}
