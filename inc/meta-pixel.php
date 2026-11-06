<?php
/**
 * NEXXI Kids — Meta Pixel & Conversions API (Server-Side CAPI v21.0) cho WooCommerce.
 *
 * Giải quyết trọn bộ đo lường chuyển đổi chuẩn thương mại điện tử:
 * 1. Gắn mã cơ sở Meta Pixel (ưu tiên cao ở <head>, data-no-optimize/data-no-defer chống LiteSpeed JS Delay).
 * 2. Tự động khởi tạo & lưu giữ cookie first-party _fbc (từ ?fbclid=...) và _fbp ngay trên tên miền nexxi.vn
 *    bất chấp trình duyệt chặn quảng cáo (Cốc Cốc, Brave, AdBlock, Safari ITP).
 * 3. Chống nhân đôi PageView / init nếu quản trị viên còn sót snippet cũ trong WPCode; chỉ nhường quyền cho
 *    plugin bên thứ 3 khi plugin đó thực sự đã cấu hình Pixel ID hợp lệ.
 * 4. Đồng bộ 100% event_id khử trùng lặp (Deduplication) giữa Browser Pixel và Server-Side CAPI cho:
 *    - ViewContent      (xem chi tiết sản phẩm)
 *    - AddToCart        (thêm giỏ hàng qua AJAX hoặc form Mua ngay)
 *    - InitiateCheckout (vào trang thanh toán)
 *    - Purchase         (đặt hàng thành công — bắn tức thì từ máy chủ qua CAPI + dự phòng trang cảm ơn + gửi bù 7 ngày)
 * 5. Bảo mật tuyệt đối cho kho Git Public: KHÔNG lưu cứng Access Token trong mã nguồn; đọc động từ hằng số
 *    wp-config.php (NEXXI_META_CAPI_TOKEN) hoặc wp_options trong trang quản trị WordPress.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NEXXI_META_PIXEL_ID' ) ) {
	define( 'NEXXI_META_PIXEL_ID', '3331438223710770' );
}

if ( ! defined( 'NEXXI_META_CAPI_GRAPH_VERSION' ) ) {
	define( 'NEXXI_META_CAPI_GRAPH_VERSION', 'v21.0' );
}

/* ==========================================================================
 * 1. CẤU HÌNH ĐỘNG & KIỂM TRA XUNG ĐỘT PLUGIN
 * ========================================================================== */

/** Lấy Meta Pixel ID đang hoạt động (ưu tiên cấu hình trong WP Admin, mặc định 3331438223710770). */
function nexxi_meta_pixel_get_id() {
	$opt = preg_replace( '/\D+/', '', (string) get_option( 'nexxi_meta_pixel_id', '' ) );
	if ( '' !== $opt && strlen( $opt ) >= 10 ) {
		return $opt;
	}
	return NEXXI_META_PIXEL_ID;
}

/** Lấy Meta Conversions API Access Token (ưu tiên hằng số wp-config.php, sau đó đến wp_options). */
function nexxi_meta_capi_get_token() {
	if ( defined( 'NEXXI_META_CAPI_TOKEN' ) && is_string( NEXXI_META_CAPI_TOKEN ) && '' !== trim( NEXXI_META_CAPI_TOKEN ) ) {
		return trim( NEXXI_META_CAPI_TOKEN );
	}
	return trim( (string) get_option( 'nexxi_meta_capi_access_token', '' ) );
}

/** Lấy mã Test Event Code (ví dụ TEST12345) nếu đang bật chế độ thử nghiệm sự kiện máy chủ. */
function nexxi_meta_capi_get_test_code() {
	if ( defined( 'NEXXI_META_CAPI_TEST_CODE' ) && is_string( NEXXI_META_CAPI_TEST_CODE ) && '' !== trim( NEXXI_META_CAPI_TEST_CODE ) ) {
		return trim( NEXXI_META_CAPI_TEST_CODE );
	}
	return trim( (string) get_option( 'nexxi_meta_capi_test_code', '' ) );
}

/**
 * Kiểm tra xem có plugin Pixel bên thứ 3 nào ĐANG THỰC SỰ CẤU HÌNH PIXEL HỢP LỆ hay không.
 * Khắc phục triệt để lỗi SST-01: Nếu "Meta for WooCommerce" (WC_Facebookcommerce) đang bật rỗng
 * (chưa kết nối Pixel ID), trả về false để theme tự bắn đầy đủ ViewContent/AddToCart/InitiateCheckout/Purchase.
 */
function nexxi_meta_pixel_external_plugin_active() {
	if ( defined( 'PYS_VERSION' ) || defined( 'PYS_FREE_VERSION' ) ) {
		$pys_main = get_option( 'pys_facebook', array() );
		if ( is_array( $pys_main ) && ! empty( $pys_main['enabled'] ) && ! empty( $pys_main['pixel_id'] ) ) {
			return true;
		}
	}
	if ( class_exists( 'WC_Facebookcommerce' ) ) {
		$direct_id = preg_replace( '/\D+/', '', (string) get_option( 'wc_facebook_pixel_id', '' ) );
		if ( strlen( $direct_id ) >= 10 ) {
			return true;
		}
		$fb_cfg = get_option( 'facebook_config', array() );
		if ( is_array( $fb_cfg ) && ! empty( $fb_cfg['pixel_id'] ) ) {
			$cfg_id = preg_replace( '/\D+/', '', (string) $fb_cfg['pixel_id'] );
			if ( strlen( $cfg_id ) >= 10 ) {
				return true;
			}
		}
	}
	return false;
}

/* ==========================================================================
 * 2. FIRST-PARTY COOKIE (_fbc / _fbp) & THÔNG TIN MÁY KHÁCH
 * ========================================================================== */

/** Lấy địa chỉ IP thực của khách truy cập (ưu tiên Cloudflare / Reverse Proxy hợp lệ). */
function nexxi_meta_capi_client_ip() {
	$keys = array(
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_REAL_IP',
		'HTTP_X_FORWARDED_FOR',
		'REMOTE_ADDR',
	);
	foreach ( $keys as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}
		$raw   = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
		$parts = explode( ',', $raw );
		foreach ( $parts as $candidate ) {
			$ip = trim( $candidate );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}
	return '';
}

/** Lấy User-Agent của trình duyệt khách hàng. */
function nexxi_meta_capi_client_ua() {
	if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
		return substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 );
	}
	return '';
}

/** Lấy URL hiện tại của trang frontend đang xử lý. */
function nexxi_meta_capi_current_url() {
	if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
		$uri = sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );
		return home_url( $uri );
	}
	return home_url( '/' );
}

/** Xác định domain cookie cấp gốc (ví dụ .nexxi.vn) cho môi trường thật; trả chuỗi rỗng trên localhost/IP. */
function nexxi_meta_capi_cookie_domain() {
	$raw_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
	$host     = strtolower( trim( (string) preg_replace( '/:\d+$/', '', $raw_host ) ) );
	$host     = (string) preg_replace( '/^www\./i', '', $host );
	if (
		'' === $host
		|| 'localhost' === $host
		|| false === strpos( $host, '.' )
		|| false !== strpos( $host, ':' )
		|| filter_var( $host, FILTER_VALIDATE_IP )
		|| preg_match( '/\.(local|localhost|test|invalid)$/i', $host )
	) {
		return '';
	}
	return '.' . $host;
}

/**
 * Quét toàn bộ header Cookie thô để lấy giá trị hợp lệ mới nhất (creationTime lớn nhất) khi trình duyệt
 * đang giữ đồng thời 2 cookie trùng tên (_fbp hoặc _fbc) trong giai đoạn chuyển tiếp sang domain .nexxi.vn.
 */
function nexxi_meta_capi_pick_latest_fb_cookie( $cookie_name ) {
	$candidates = array();
	if ( ! empty( $_SERVER['HTTP_COOKIE'] ) && is_string( $_SERVER['HTTP_COOKIE'] ) ) {
		$pairs = explode( ';', wp_unslash( $_SERVER['HTTP_COOKIE'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( $pairs as $pair ) {
			$eq = strpos( $pair, '=' );
			if ( false === $eq ) {
				continue;
			}
			$k = trim( substr( $pair, 0, $eq ) );
			if ( $k !== $cookie_name ) {
				continue;
			}
			$v            = sanitize_text_field( rawurldecode( trim( substr( $pair, $eq + 1 ) ) ) );
			$candidates[] = $v;
		}
	}
	if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
		$candidates[] = sanitize_text_field( wp_unslash( (string) $_COOKIE[ $cookie_name ] ) );
	}

	$best_val = '';
	$best_ts  = -1;
	foreach ( $candidates as $cand ) {
		if ( 0 !== strpos( $cand, 'fb.' ) ) {
			continue;
		}
		$parts = explode( '.', $cand );
		if ( count( $parts ) < 4 || '' === $parts[3] ) {
			continue;
		}
		$ts = is_numeric( $parts[2] ) ? (int) $parts[2] : 0;
		if ( $ts >= $best_ts ) {
			$best_ts  = $ts;
			$best_val = $cand;
		}
	}
	return $best_val;
}

/** Chuẩn hóa giá trị _fbc (chuyển ngược __DOT__ của fbevents.js về dấu chấm . chuẩn Graph API CAPI). */
function nexxi_meta_capi_normalize_fbc( $fbc ) {
	$clean = trim( (string) $fbc );
	if ( '' === $clean || 0 !== strpos( $clean, 'fb.' ) ) {
		return '';
	}
	return str_replace( '__DOT__', '.', $clean );
}

/**
 * Khởi tạo giá trị first-party _fbc (từ ?fbclid=...) và _fbp trong RAM cho luồng CAPI PHP,
 * đồng thời CHỈ phát header Set-Cookie từ PHP trên các request động (POST / wc-ajax) để bảo vệ
 * tuyệt đối LiteSpeed Full-Page Cache trên các trang GET công khai (trên GET đã có inline JS ở <head> ghi cookie riêng từng trình duyệt).
 */
function nexxi_meta_capi_bootstrap_first_party_cookies() {
	if ( is_admin() || wp_doing_ajax() || defined( 'REST_REQUEST' ) || defined( 'DOING_CRON' ) ) {
		return;
	}
	$now_ms         = (int) round( microtime( true ) * 1000 );
	$expires        = time() + ( 90 * DAY_IN_SECONDS );
	$secure         = is_ssl();
	$cookie_domain  = nexxi_meta_capi_cookie_domain();
	$is_dynamic_req = ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' ) ) || ! empty( $_GET['wc-ajax'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// 1. Thu nhận fbclid từ URL quảng cáo Meta -> _fbc
	if ( ! empty( $_GET['fbclid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fbclid = preg_replace( '/[^A-Za-z0-9._\-]/', '', sanitize_text_field( wp_unslash( (string) $_GET['fbclid'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $fbclid && strlen( $fbclid ) <= 255 ) {
			$fbc                          = 'fb.1.' . $now_ms . '.' . $fbclid;
			$packed_fbc                   = 'fb.1.' . $now_ms . '.' . str_replace( '.', '__DOT__', $fbclid );
			$GLOBALS['nexxi_runtime_fbc'] = $fbc;
			if ( $is_dynamic_req && ! headers_sent() ) {
				$ck_opts = array(
					'expires'  => $expires,
					'path'     => '/',
					'secure'   => $secure,
					'httponly' => false,
					'samesite' => 'Lax',
				);
				if ( '' !== $cookie_domain ) {
					$ck_opts['domain'] = $cookie_domain;
				}
				setcookie( '_fbc', $packed_fbc, $ck_opts );
			}
		}
	}

	// 2. Đảm bảo luôn có _fbp first-party trong RAM (và ghi cookie nếu là request động chưa có _fbp)
	$existing_fbp = nexxi_meta_capi_pick_latest_fb_cookie( '_fbp' );
	if ( '' === $existing_fbp || 0 !== strpos( $existing_fbp, 'fb.' ) ) {
		$fbp                          = 'fb.1.' . $now_ms . '.' . wp_rand( 1000000000, 9999999999 );
		$GLOBALS['nexxi_runtime_fbp'] = $fbp;
		if ( $is_dynamic_req && ! headers_sent() ) {
			$ck_opts = array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => $secure,
				'httponly' => false,
				'samesite' => 'Lax',
			);
			if ( '' !== $cookie_domain ) {
				$ck_opts['domain'] = $cookie_domain;
			}
			setcookie( '_fbp', $fbp, $ck_opts );
		}
	}
}
add_action( 'init', 'nexxi_meta_capi_bootstrap_first_party_cookies', 1 );

/** Đọc giá trị _fbp hiện có của phiên khách hàng (ưu tiên bản mới nhất nếu trùng cookie chuyển tiếp). */
function nexxi_meta_capi_get_fbp() {
	$val = nexxi_meta_capi_pick_latest_fb_cookie( '_fbp' );
	if ( '' !== $val ) {
		return $val;
	}
	if ( ! empty( $GLOBALS['nexxi_runtime_fbp'] ) ) {
		return (string) $GLOBALS['nexxi_runtime_fbp'];
	}
	return '';
}

/** Đọc giá trị _fbc hiện có của phiên khách hàng (cookie đã giải mã __DOT__ hoặc tham số fbclid trực tiếp). */
function nexxi_meta_capi_get_fbc() {
	$val = nexxi_meta_capi_pick_latest_fb_cookie( '_fbc' );
	if ( '' !== $val ) {
		return nexxi_meta_capi_normalize_fbc( $val );
	}
	if ( ! empty( $GLOBALS['nexxi_runtime_fbc'] ) ) {
		return nexxi_meta_capi_normalize_fbc( (string) $GLOBALS['nexxi_runtime_fbc'] );
	}
	if ( ! empty( $_GET['fbclid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fbclid = preg_replace( '/[^A-Za-z0-9._\-]/', '', sanitize_text_field( wp_unslash( (string) $_GET['fbclid'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $fbclid ) {
			return 'fb.1.' . (int) round( microtime( true ) * 1000 ) . '.' . $fbclid;
		}
	}
	return '';
}

/* ==========================================================================
 * 3. HÀM CHUẨN HÓA SẢN PHẨM & BĂM SHA-256 USER DATA CHO CAPI
 * ========================================================================== */

/** Lấy mã định danh nội dung chuẩn (ưu tiên Mã SKU cha, dự phòng ID sản phẩm). */
function nexxi_meta_pixel_content_id( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return '';
	}
	$target = $product;
	if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
		$parent = wc_get_product( $product->get_parent_id() );
		if ( $parent ) {
			$target = $parent;
		}
	}
	$sku = trim( (string) $target->get_sku() );
	if ( '' === $sku && function_exists( 'nexxi_kids_effective_sku' ) ) {
		$sku = trim( (string) nexxi_kids_effective_sku( $target ) );
	}
	return '' !== $sku ? $sku : (string) $target->get_id();
}

/** Lấy giá bán thực tế sau giảm (Giảm Giá SP + Flash Sale) của sản phẩm. */
function nexxi_meta_pixel_effective_price( WC_Product $product ) {
	if ( $product->is_type( 'variation' ) && function_exists( 'nexxi_kids_get_cart_item_effective_prices' ) ) {
		$eff = nexxi_kids_get_cart_item_effective_prices( $product );
		if ( ! empty( $eff['effective_price'] ) && (float) $eff['effective_price'] > 0 ) {
			return (float) round( (float) $eff['effective_price'] );
		}
	}
	$target = $product;
	if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
		$parent = wc_get_product( $product->get_parent_id() );
		if ( $parent ) {
			$target = $parent;
		}
	}
	if ( function_exists( 'nexxi_kids_get_program_sale' ) ) {
		$prog = nexxi_kids_get_program_sale( $target, 'combined' );
		if ( ! empty( $prog['sale'] ) && (float) $prog['sale'] > 0 ) {
			return (float) round( (float) $prog['sale'] );
		}
	}
	return (float) round( (float) $product->get_price() );
}

/** Chuẩn hóa số điện thoại Việt Nam sang định dạng quốc tế chỉ gồm chữ số (ví dụ 0855503509 -> 84855503509). */
function nexxi_meta_capi_normalize_phone( $phone ) {
	$digits = preg_replace( '/\D+/', '', (string) $phone );
	if ( '' === $digits ) {
		return '';
	}
	if ( 0 === strpos( $digits, '00' ) ) {
		$digits = substr( $digits, 2 );
	}
	if ( 0 === strpos( $digits, '0' ) && strlen( $digits ) >= 10 && strlen( $digits ) <= 11 ) {
		$digits = '84' . substr( $digits, 1 );
	} elseif ( 9 === strlen( $digits ) && preg_match( '/^[35789]/', $digits ) ) {
		$digits = '84' . $digits;
	}
	return strlen( $digits ) >= 9 ? $digits : '';
}

/** Chuẩn hóa chuỗi địa danh (thành phố/tỉnh) theo chuẩn Meta CAPI: chữ thường, không dấu, không khoảng trắng. */
function nexxi_meta_capi_normalize_geo( $text ) {
	$clean = trim( (string) $text );
	if ( '' === $clean ) {
		return '';
	}
	if ( function_exists( 'remove_accents' ) ) {
		$clean = remove_accents( $clean );
	}
	$clean = function_exists( 'mb_strtolower' ) ? mb_strtolower( $clean, 'UTF-8' ) : strtolower( $clean );
	return preg_replace( '/[^a-z0-9]/', '', $clean );
}

/**
 * Xây dựng mảng user_data đã băm SHA-256 chuẩn Meta CAPI v21.0 (tối ưu điểm Event Match Quality).
 *
 * @param WC_Order|null $order     Đơn hàng WooCommerce (nếu là sự kiện Purchase).
 * @param array         $overrides Ghi đè IP/UA/fbp/fbc nếu có.
 * @return array
 */
function nexxi_meta_capi_build_user_data( $order = null, $overrides = array() ) {
	$user_data = array();

	$email      = '';
	$phone      = '';
	$first_name = '';
	$last_name  = '';
	$city       = '';
	$state      = '';
	$country    = 'vn';
	$ext_id     = '';
	$ip         = isset( $overrides['client_ip_address'] ) ? (string) $overrides['client_ip_address'] : '';
	$ua         = isset( $overrides['client_user_agent'] ) ? (string) $overrides['client_user_agent'] : '';
	$fbp        = isset( $overrides['fbp'] ) ? (string) $overrides['fbp'] : '';
	$fbc        = isset( $overrides['fbc'] ) ? (string) $overrides['fbc'] : '';

	if ( $order instanceof WC_Order ) {
		$email      = (string) $order->get_billing_email();
		$phone      = (string) $order->get_billing_phone();
		$first_name = (string) $order->get_billing_first_name();
		$last_name  = (string) $order->get_billing_last_name();
		$city       = (string) ( $order->get_billing_city() ?: $order->get_shipping_city() );
		$state      = (string) ( $order->get_billing_state() ?: $order->get_shipping_state() );
		$country    = (string) ( $order->get_billing_country() ?: 'vn' );
		if ( $order->get_customer_id() > 0 ) {
			$ext_id = (string) $order->get_customer_id();
		}
		if ( '' === $ip ) {
			$ip = (string) ( $order->get_meta( '_nexxi_client_ip', true ) ?: $order->get_customer_ip_address() );
		}
		if ( '' === $ua ) {
			$ua = (string) ( $order->get_meta( '_nexxi_client_ua', true ) ?: $order->get_customer_user_agent() );
		}
		if ( '' === $fbp ) {
			$fbp = (string) $order->get_meta( '_nexxi_fbp', true );
		}
		if ( '' === $fbc ) {
			$fbc = (string) $order->get_meta( '_nexxi_fbc', true );
		}
		if ( '' === $fbc ) {
			$session_entry = (string) $order->get_meta( '_wc_order_attribution_session_entry', true );
			if ( '' !== $session_entry && preg_match( '/[?&]fbclid=([^&#\s]+)/', $session_entry, $m ) ) {
				$fbclid = preg_replace( '/[^A-Za-z0-9._\-]/', '', rawurldecode( $m[1] ) );
				if ( '' !== $fbclid && strlen( $fbclid ) <= 255 ) {
					$created_dt = $order->get_date_created();
					$created_ts = $created_dt ? (int) $created_dt->getTimestamp() : time();
					$fbc        = 'fb.1.' . ( $created_ts * 1000 ) . '.' . $fbclid;
				}
			}
		}
	} else {
		if ( is_user_logged_in() ) {
			$u = wp_get_current_user();
			if ( $u && $u->ID > 0 ) {
				$email      = (string) $u->user_email;
				$first_name = (string) ( $u->first_name ?: get_user_meta( $u->ID, 'billing_first_name', true ) );
				$last_name  = (string) ( $u->last_name ?: get_user_meta( $u->ID, 'billing_last_name', true ) );
				$phone      = (string) get_user_meta( $u->ID, 'billing_phone', true );
				$city       = (string) get_user_meta( $u->ID, 'billing_city', true );
				$state      = (string) get_user_meta( $u->ID, 'billing_state', true );
				$ext_id     = (string) $u->ID;
			}
		}
		if ( '' === $ip ) {
			$ip = nexxi_meta_capi_client_ip();
		}
		if ( '' === $ua ) {
			$ua = nexxi_meta_capi_client_ua();
		}
		if ( '' === $fbp ) {
			$fbp = nexxi_meta_capi_get_fbp();
		}
		if ( '' === $fbc ) {
			$fbc = nexxi_meta_capi_get_fbc();
		}
	}

	// Email (em)
	$norm_email = strtolower( trim( $email ) );
	if ( '' !== $norm_email && is_email( $norm_email ) ) {
		$user_data['em'] = array( hash( 'sha256', $norm_email ) );
	}

	// Số điện thoại chuẩn hóa 84... (ph)
	$norm_phone = nexxi_meta_capi_normalize_phone( $phone );
	if ( '' !== $norm_phone ) {
		$user_data['ph'] = array( hash( 'sha256', $norm_phone ) );
		if ( '' === $ext_id ) {
			$ext_id = $norm_phone;
		}
	}

	// Tách Họ (ln) & Tên (fn) thông minh nếu khách nhập gộp cả họ tên vào 1 ô tiếng Việt
	$fn_clean = trim( preg_replace( '/\s+/u', ' ', $first_name ) );
	$ln_clean = trim( preg_replace( '/\s+/u', ' ', $last_name ) );
	if ( '' !== $fn_clean && '' === $ln_clean && false !== strpos( $fn_clean, ' ' ) ) {
		$parts    = explode( ' ', $fn_clean );
		$ln_clean = (string) array_shift( $parts );
		$fn_clean = implode( ' ', $parts );
	} elseif ( '' === $fn_clean && '' !== $ln_clean && false !== strpos( $ln_clean, ' ' ) ) {
		$parts    = explode( ' ', $ln_clean );
		$ln_clean = (string) array_shift( $parts );
		$fn_clean = implode( ' ', $parts );
	}
	if ( '' !== $fn_clean ) {
		$fn_low          = function_exists( 'mb_strtolower' ) ? mb_strtolower( $fn_clean, 'UTF-8' ) : strtolower( $fn_clean );
		$user_data['fn'] = array( hash( 'sha256', $fn_low ) );
	}
	if ( '' !== $ln_clean ) {
		$ln_low          = function_exists( 'mb_strtolower' ) ? mb_strtolower( $ln_clean, 'UTF-8' ) : strtolower( $ln_clean );
		$user_data['ln'] = array( hash( 'sha256', $ln_low ) );
	}

	// Thành phố (ct) & Tỉnh/Thành (st)
	$norm_ct = nexxi_meta_capi_normalize_geo( $city );
	if ( '' !== $norm_ct ) {
		$user_data['ct'] = array( hash( 'sha256', $norm_ct ) );
	}
	$norm_st = nexxi_meta_capi_normalize_geo( $state );
	if ( '' !== $norm_st ) {
		$user_data['st'] = array( hash( 'sha256', $norm_st ) );
	}

	// Quốc gia (country)
	$norm_country = strtolower( preg_replace( '/[^a-zA-Z]/', '', $country ) );
	if ( '' === $norm_country ) {
		$norm_country = 'vn';
	}
	$user_data['country'] = array( hash( 'sha256', $norm_country ) );

	// Mã định danh ngoài (external_id)
	if ( '' !== $ext_id ) {
		$user_data['external_id'] = array( hash( 'sha256', strtolower( trim( $ext_id ) ) ) );
	}

	// Trường kỹ thuật không băm (IP, User-Agent, fbp, fbc)
	if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		$user_data['client_ip_address'] = $ip;
	}
	if ( '' !== $ua ) {
		$user_data['client_user_agent'] = $ua;
	}
	if ( '' !== $fbp && 0 === strpos( $fbp, 'fb.' ) ) {
		$user_data['fbp'] = $fbp;
	}
	$fbc = nexxi_meta_capi_normalize_fbc( $fbc );
	if ( '' !== $fbc ) {
		$user_data['fbc'] = $fbc;
	}

	return $user_data;
}

/* ==========================================================================
 * 4. TRÌNH GỬI SỰ KIỆN SERVER-SIDE META CONVERSIONS API (CAPI v21.0)
 * ========================================================================== */

/**
 * Gửi trực tiếp 1 sự kiện chuẩn lên Meta Conversions API (Graph API v21.0).
 *
 * @param string $event_name       Tên sự kiện (Purchase, InitiateCheckout, AddToCart, PageView...).
 * @param string $event_id         ID sự kiện khử trùng lặp khớp với Browser Pixel.
 * @param array  $custom_data      Thông số thương mại điện tử (value, currency, content_ids...).
 * @param array  $user_data        Thông tin khách hàng đã chuẩn hóa/băm SHA-256.
 * @param string $event_source_url URL trang phát sinh sự kiện.
 * @param int    $event_time       Unix timestamp thời điểm diễn ra sự kiện (hỗ trợ gửi bù tối đa 7 ngày).
 * @param int    $timeout          Thời gian chờ HTTP tối đa (giây).
 * @return array{ok: bool, http_code: int, events_received: int, fbtrace_id: string, error: string}
 */
function nexxi_meta_capi_send_event( $event_name, $event_id, $custom_data = array(), $user_data = array(), $event_source_url = '', $event_time = 0, $timeout = 5 ) {
	$pixel_id = nexxi_meta_pixel_get_id();
	$token    = nexxi_meta_capi_get_token();
	if ( '' === $pixel_id || '' === $token ) {
		return array(
			'ok'              => false,
			'http_code'       => 0,
			'events_received' => 0,
			'fbtrace_id'      => '',
			'error'           => 'missing_token',
		);
	}

	$now = time();
	$ts  = (int) $event_time;
	// Meta CAPI cho phép gửi sự kiện website lùi tối đa 7 ngày (giữ nguyên mốc giờ gốc khi gửi bù đơn cũ).
	if ( $ts <= 0 || $ts > $now + 60 || $ts < ( $now - 7 * DAY_IN_SECONDS + 60 ) ) {
		$ts = $now;
	}

	if ( empty( $user_data ) ) {
		$user_data = nexxi_meta_capi_build_user_data();
	}

	$event_payload = array(
		'event_name'       => (string) $event_name,
		'event_time'       => $ts,
		'event_id'         => (string) $event_id,
		'action_source'    => 'website',
		'event_source_url' => '' !== $event_source_url ? esc_url_raw( $event_source_url ) : home_url( '/' ),
		'user_data'        => $user_data,
	);
	if ( ! empty( $custom_data ) ) {
		$event_payload['custom_data'] = $custom_data;
	}

	$body = array(
		'data'         => array( $event_payload ),
		'access_token' => $token,
	);

	$test_code = nexxi_meta_capi_get_test_code();
	if ( '' !== $test_code ) {
		$body['test_event_code'] = $test_code;
	}

	$endpoint = 'https://graph.facebook.com/' . NEXXI_META_CAPI_GRAPH_VERSION . '/' . rawurlencode( $pixel_id ) . '/events';
	$response = wp_remote_post(
		$endpoint,
		array(
			'timeout'     => max( 2, (int) $timeout ),
			'redirection' => 2,
			'httpversion' => '1.1',
			'blocking'    => true,
			'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'        => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'ok'              => false,
			'http_code'       => 0,
			'events_received' => 0,
			'fbtrace_id'      => '',
			'error'           => $response->get_error_message(),
		);
	}

	$code     = (int) wp_remote_retrieve_response_code( $response );
	$raw_body = (string) wp_remote_retrieve_body( $response );
	$decoded  = json_decode( $raw_body, true );
	$received = is_array( $decoded ) && isset( $decoded['events_received'] ) ? (int) $decoded['events_received'] : 0;
	$fbtrace  = is_array( $decoded ) && ! empty( $decoded['fbtrace_id'] ) ? (string) $decoded['fbtrace_id'] : '';
	$err_msg  = '';
	if ( is_array( $decoded ) && ! empty( $decoded['error']['message'] ) ) {
		$err_msg = (string) $decoded['error']['message'];
	} elseif ( 200 !== $code ) {
		$err_msg = 'HTTP ' . $code;
	}

	$ok = ( 200 === $code && $received >= 1 );
	if ( $ok ) {
		update_option( 'nexxi_meta_capi_last_success', array(
			'time'       => $now,
			'event_name' => (string) $event_name,
			'event_id'   => (string) $event_id,
			'fbtrace_id' => $fbtrace,
		), false );
	}

	return array(
		'ok'              => $ok,
		'http_code'       => $code,
		'events_received' => $received,
		'fbtrace_id'      => $fbtrace,
		'error'           => $err_msg,
	);
}

/** Đưa tác vụ CAPI vào hàng đợi chạy cuối vòng đời PHP (shutdown) để không làm chậm phản hồi của khách hàng. */
function nexxi_meta_capi_queue_job( callable $callback ) {
	if ( ! isset( $GLOBALS['nexxi_meta_capi_shutdown_jobs'] ) || ! is_array( $GLOBALS['nexxi_meta_capi_shutdown_jobs'] ) ) {
		$GLOBALS['nexxi_meta_capi_shutdown_jobs'] = array();
		register_shutdown_function( static function () {
			if ( empty( $GLOBALS['nexxi_meta_capi_shutdown_jobs'] ) || ! is_array( $GLOBALS['nexxi_meta_capi_shutdown_jobs'] ) ) {
				return;
			}
			// Giải phóng kết nối trình duyệt ngay lập tức trên LiteSpeed / PHP-FPM trước khi gọi API ngoài.
			if ( function_exists( 'litespeed_finish_request' ) ) {
				@litespeed_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( function_exists( 'fastcgi_finish_request' ) ) {
				@fastcgi_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			foreach ( $GLOBALS['nexxi_meta_capi_shutdown_jobs'] as $job ) {
				if ( is_callable( $job ) ) {
					try {
						call_user_func( $job );
					} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Bỏ qua ngoại lệ nền để không ảnh hưởng tiến trình shutdown.
					}
				}
			}
		} );
	}
	$GLOBALS['nexxi_meta_capi_shutdown_jobs'][] = $callback;
}

/** Xây dựng custom_data chuẩn cho đơn hàng WooCommerce (dùng chung cho cả Browser Pixel và Server CAPI). */
function nexxi_meta_pixel_build_order_custom_data( WC_Order $order ) {
	$cids      = array();
	$contents  = array();
	$num_items = 0;
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$qty = max( 1, (int) $item->get_quantity() );
		$p   = $item->get_product();
		$cid = $p ? nexxi_meta_pixel_content_id( $p ) : (string) $item->get_product_id();
		if ( '' !== $cid ) {
			$cids[]     = $cid;
			$unit_price = $qty > 0 ? (float) round( (float) $item->get_total() / $qty ) : 0.0;
			$contents[] = array(
				'id'         => $cid,
				'quantity'   => $qty,
				'item_price' => $unit_price,
			);
		}
		$num_items += $qty;
	}
	$curr = $order->get_currency() ? $order->get_currency() : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND' );
	return array(
		'content_ids'  => array_values( array_unique( array_filter( $cids ) ) ),
		'contents'     => $contents,
		'content_type' => 'product',
		'value'        => (float) round( (float) $order->get_total() ),
		'currency'     => $curr,
		'num_items'    => max( 1, $num_items ),
	);
}

/**
 * Gửi sự kiện Purchase của 1 đơn hàng WooCommerce lên Meta CAPI (chống gửi trùng bằng _nexxi_capi_purchase_sent).
 *
 * @param int|WC_Order $order_or_id Đơn hàng hoặc ID đơn hàng.
 * @param bool         $force       True nếu quản trị viên chủ động bấm nút "Gửi lại" trong WP Admin.
 * @return array{ok: bool, http_code: int, events_received: int, fbtrace_id: string, error: string}
 */
function nexxi_meta_capi_send_order_purchase( $order_or_id, $force = false ) {
	$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( absint( $order_or_id ) );
	if ( ! $order instanceof WC_Order ) {
		return array( 'ok' => false, 'http_code' => 0, 'events_received' => 0, 'fbtrace_id' => '', 'error' => 'invalid_order' );
	}
	if ( $order->has_status( array( 'failed', 'cancelled', 'refunded', 'trash', 'checkout-draft' ) ) ) {
		return array( 'ok' => false, 'http_code' => 0, 'events_received' => 0, 'fbtrace_id' => '', 'error' => 'excluded_status' );
	}
	if ( ! $force && '1' === (string) $order->get_meta( '_nexxi_capi_purchase_sent', true ) ) {
		return array(
			'ok'              => true,
			'http_code'       => 200,
			'events_received' => 1,
			'fbtrace_id'      => (string) $order->get_meta( '_nexxi_capi_purchase_fbtrace', true ),
			'error'           => 'already_sent',
		);
	}
	if ( '' === nexxi_meta_capi_get_token() ) {
		return array( 'ok' => false, 'http_code' => 0, 'events_received' => 0, 'fbtrace_id' => '', 'error' => 'missing_token' );
	}

	$event_id    = 'nexxi_order_' . $order->get_id();
	$custom_data = nexxi_meta_pixel_build_order_custom_data( $order );
	$user_data   = nexxi_meta_capi_build_user_data( $order );
	$source_url  = (string) $order->get_meta( '_nexxi_event_source_url', true );
	if ( '' === $source_url ) {
		$source_url = $order->get_checkout_order_received_url();
	}
	$created_dt = $order->get_date_created();
	$order_ts   = $created_dt ? (int) $created_dt->getTimestamp() : time();

	$res = nexxi_meta_capi_send_event( 'Purchase', $event_id, $custom_data, $user_data, $source_url, $order_ts, 6 );

	$log = array(
		'time'            => time(),
		'http_code'       => $res['http_code'],
		'events_received' => $res['events_received'],
		'fbtrace_id'      => $res['fbtrace_id'],
		'error'           => $res['error'],
	);
	$order->update_meta_data( '_nexxi_capi_purchase_log', $log );
	if ( $res['ok'] ) {
		$order->update_meta_data( '_nexxi_capi_purchase_sent', '1' );
		$order->update_meta_data( '_nexxi_capi_purchase_sent_at', (string) time() );
		$order->update_meta_data( '_nexxi_capi_purchase_fbtrace', $res['fbtrace_id'] );
	}
	$order->save_meta_data();

	return $res;
}

/** Ghi lại _fbp, _fbc, IP, User-Agent và URL đặt hàng vào Order Meta ngay lúc khởi tạo đơn hàng. */
function nexxi_meta_capi_attach_order_tracking_meta( $order ) {
	if ( is_numeric( $order ) ) {
		$order = wc_get_order( absint( $order ) );
	}
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$changed = false;
	$fbp     = nexxi_meta_capi_get_fbp();
	if ( '' !== $fbp && '' === (string) $order->get_meta( '_nexxi_fbp', true ) ) {
		$order->update_meta_data( '_nexxi_fbp', $fbp );
		$changed = true;
	}
	$fbc = nexxi_meta_capi_get_fbc();
	if ( '' !== $fbc && '' === (string) $order->get_meta( '_nexxi_fbc', true ) ) {
		$order->update_meta_data( '_nexxi_fbc', $fbc );
		$changed = true;
	}
	$ip = nexxi_meta_capi_client_ip();
	if ( '' !== $ip && '' === (string) $order->get_meta( '_nexxi_client_ip', true ) ) {
		$order->update_meta_data( '_nexxi_client_ip', $ip );
		$changed = true;
	}
	$ua = nexxi_meta_capi_client_ua();
	if ( '' !== $ua && '' === (string) $order->get_meta( '_nexxi_client_ua', true ) ) {
		$order->update_meta_data( '_nexxi_client_ua', $ua );
		$changed = true;
	}
	if ( '' === (string) $order->get_meta( '_nexxi_event_source_url', true ) ) {
		$ref = wp_get_referer();
		$url = $ref ? $ref : ( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/thanh-toan/' ) );
		$order->update_meta_data( '_nexxi_event_source_url', esc_url_raw( $url ) );
		$changed = true;
	}
	if ( $changed && $order->get_id() > 0 ) {
		$order->save_meta_data();
	}
}
add_action( 'woocommerce_checkout_create_order', 'nexxi_meta_capi_attach_order_tracking_meta', 20, 1 );
add_action( 'woocommerce_store_api_checkout_update_order_meta', 'nexxi_meta_capi_attach_order_tracking_meta', 20, 1 );

/** Đặt lịch bắn CAPI Purchase không chặn (non-blocking shutdown) ngay khi đơn hàng vừa tạo thành công. */
function nexxi_meta_capi_trigger_order_processed( $order_or_id ) {
	if ( nexxi_meta_pixel_external_plugin_active() || '' === nexxi_meta_capi_get_token() ) {
		return;
	}
	$order_id = $order_or_id instanceof WC_Order ? $order_or_id->get_id() : absint( $order_or_id );
	if ( $order_id <= 0 ) {
		return;
	}
	nexxi_meta_capi_attach_order_tracking_meta( $order_id );
	nexxi_meta_capi_queue_job( static function () use ( $order_id ) {
		nexxi_meta_capi_send_order_purchase( $order_id, false );
	} );
}
add_action( 'woocommerce_checkout_order_processed', 'nexxi_meta_capi_trigger_order_processed', 25, 1 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'nexxi_meta_capi_trigger_order_processed', 25, 1 );
add_action( 'woocommerce_thankyou', 'nexxi_meta_capi_trigger_order_processed', 20, 1 );

/* ==========================================================================
 * 5. MÃ CƠ SỞ BROWSER PIXEL Ở <HEAD> & SỰ KIỆN TMĐT Ở <FOOTER>
 * ========================================================================== */

/**
 * Xác thực và trả về đối tượng WC_Order hợp lệ trên trang Cảm ơn (order-received).
 * Chỉ trả về đơn hàng khi khớp order_key (hash_equals) hoặc đúng chủ tài khoản đang đăng nhập.
 *
 * @return WC_Order|null
 */
function nexxi_meta_pixel_resolve_thankyou_order() {
	if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() || ! function_exists( 'wc_get_order' ) ) {
		return null;
	}
	$order_id = absint( get_query_var( 'order-received' ) );
	$req_key  = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! $order_id && '' !== $req_key && function_exists( 'wc_get_order_id_by_order_key' ) ) {
		$order_id = absint( wc_get_order_id_by_order_key( $req_key ) );
	}
	$order = $order_id ? wc_get_order( $order_id ) : null;
	if ( ! $order instanceof WC_Order ) {
		return null;
	}
	$order_key   = (string) $order->get_order_key();
	$valid_key   = '' !== $req_key && '' !== $order_key && hash_equals( $order_key, $req_key );
	$valid_owner = is_user_logged_in() && (int) $order->get_customer_id() > 0 && get_current_user_id() === (int) $order->get_customer_id();
	if ( ! $valid_key && ! $valid_owner ) {
		return null;
	}
	return $order;
}

/**
 * Xây dựng mảng Advanced Matching + external_id đã băm SHA-256 một chiều cho Browser Pixel.
 * Chỉ kích hoạt trên trang động không cache (order-received đã xác thực hoặc phiên khách đã đăng nhập).
 *
 * @param WC_Order|null $order Đơn hàng đã xác thực (tùy chọn).
 * @return array<string,string>
 */
function nexxi_meta_pixel_get_browser_adv_matching( $order = null ) {
	if ( ! ( $order instanceof WC_Order ) ) {
		$order = nexxi_meta_pixel_resolve_thankyou_order();
	}
	if ( ! ( $order instanceof WC_Order ) && ! is_user_logged_in() ) {
		return array();
	}
	$capi_ud = nexxi_meta_capi_build_user_data( $order instanceof WC_Order ? $order : null );
	$am      = array();
	foreach ( array( 'em', 'ph', 'fn', 'ln', 'ct', 'st', 'external_id' ) as $k ) {
		if ( ! empty( $capi_ud[ $k ][0] ) && is_string( $capi_ud[ $k ][0] ) ) {
			$am[ $k ] = $capi_ud[ $k ][0];
		}
	}
	if ( ! empty( $am ) && ! empty( $capi_ud['country'][0] ) && is_string( $capi_ud['country'][0] ) ) {
		$am['country'] = $capi_ud['country'][0];
	}
	return $am;
}

/**
 * Xuất mã cơ sở Meta Pixel ở đầu <head> (priority 2):
 * - Khởi tạo cookie first-party _fbc / _fbp ngay trên domain .nexxi.vn (khớp chuẩn __DOT__ của fbevents.js).
 * - Tích hợp Advanced Matching SHA-256 + external_id đồng bộ với CAPI trên trang order-received / tài khoản đăng nhập.
 * - Tích hợp bộ lọc thông minh ngay trong hàm fbq để:
 *   (a) Chặn gọi trùng fbq('init', pixelId) và fbq('track', 'PageView') từ snippet cũ trong WPCode.
 *   (b) Tự động tách trường event_id trong object tham số thành đối tượng {eventID: ...} chuẩn Meta cho AJAX AddToCart.
 */
function nexxi_meta_pixel_print_head() {
	if ( is_admin() || wp_doing_ajax() || is_feed() || defined( 'REST_REQUEST' ) ) {
		return;
	}
	if ( function_exists( 'is_account_page' ) && is_account_page() && ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
		return;
	}
	if ( defined( 'NEXXI_META_PIXEL_PRINTED' ) ) {
		return;
	}
	define( 'NEXXI_META_PIXEL_PRINTED', true );
	$raw_pixel_id = nexxi_meta_pixel_get_id();
	$pixel_id     = esc_js( $raw_pixel_id );
	$adv_match    = nexxi_meta_pixel_get_browser_adv_matching();
	$init_call    = "fbq('init', '{$pixel_id}');";
	if ( ! empty( $adv_match ) ) {
		$GLOBALS['nexxi_meta_pixel_head_adv_matched'] = true;
		if ( ! headers_sent() ) {
			nocache_headers();
			@header( 'X-LiteSpeed-Cache-Control: no-cache' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$adv_json  = wp_json_encode( $adv_match, JSON_UNESCAPED_SLASHES );
		$init_call = "fbq('init', '{$pixel_id}', {$adv_json});";
	}
	?>
<!-- Meta Pixel & First-Party Attribution Code -->
<script data-no-optimize="1" data-no-defer="1">
!function(f,b,e,v,n,t,s){
	try {
		var m = (f.location.search || '').match(/[?&]fbclid=([^&#]+)/);
		var fbclid = m ? decodeURIComponent(m[1]).replace(/[^A-Za-z0-9._\-]/g, '') : '';
		var exp = new Date(Date.now() + 7776e6).toUTCString();
		var sec = f.location.protocol === 'https:' ? '; Secure' : '';
		var host = (f.location.hostname || '').replace(/^www\./i, '');
		var isLocal = !host || host === 'localhost' || /^\d+(\.\d+){3}$/.test(host) || host.indexOf(':') !== -1 || /\.(local|localhost|test|invalid)$/i.test(host);
		var dom = (!isLocal && host.indexOf('.') > 0) ? '; domain=.' + host : '';
		var setCk = function(k, val) {
			var base = k + '=' + val + '; expires=' + exp + '; path=/; SameSite=Lax' + sec;
			if (dom) {
				b.cookie = base + dom;
				if ((b.cookie || '').indexOf(k + '=' + val) === -1) b.cookie = base;
			} else {
				b.cookie = base;
			}
		};
		if (fbclid) {
			setCk('_fbc', 'fb.1.' + Date.now() + '.' + fbclid.replace(/\./g, '__DOT__'));
		}
		if (!/(?:^|;\s*)_fbp=fb\./.test(b.cookie || '')) {
			setCk('_fbp', 'fb.1.' + Date.now() + '.' + Math.floor(1e9 + Math.random() * 9e9));
		}
	} catch (err) {}
	if (f.fbq) return;
	var inited = {}, pvSent = false;
	n = f.fbq = function() {
		var a = Array.prototype.slice.call(arguments);
		if (a[0] === 'init' && a[1]) {
			var isEmptyInit = !a[2] || (typeof a[2] === 'object' && Object.keys(a[2]).length === 0);
			if (inited[a[1]] && isEmptyInit) return;
			inited[a[1]] = 1;
		} else if (a[0] === 'track' && a[1] === 'PageView') {
			var hasEid = a[3] && typeof a[3] === 'object' && a[3].eventID;
			if (pvSent && !hasEid) return;
			pvSent = true;
		} else if (a[0] === 'track' && a[2] && typeof a[2] === 'object' && a[2].event_id && !a[3]) {
			var eid = a[2].event_id;
			var clean = {};
			for (var k in a[2]) {
				if (Object.prototype.hasOwnProperty.call(a[2], k) && k !== 'event_id') clean[k] = a[2][k];
			}
			a[2] = clean;
			a[3] = { eventID: eid };
		}
		n.callMethod ? n.callMethod.apply(n, a) : n.queue.push(a);
	};
	if (!f._fbq) f._fbq = n;
	n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];
	t = b.createElement(e); t.async = !0; t.src = v;
	s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
}(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
<?php echo $init_call . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
fbq('track', 'PageView', {}, { eventID: 'nexxi_pv_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10) });
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id=<?php echo esc_attr( rawurlencode( $raw_pixel_id ) ); ?>&amp;ev=PageView&amp;noscript=1" alt=""
/></noscript>
<!-- End Meta Pixel Code -->
	<?php
}
add_action( 'wp_head', 'nexxi_meta_pixel_print_head', 2 );

/**
 * Ghi nhận sự kiện AddToCart vào phiên làm việc để gửi đồng thời qua:
 * 1. Browser Pixel (AJAX fragment nexxi-fbq-atc hoặc lần tải trang kế tiếp khi bấm Mua ngay).
 * 2. Server-Side CAPI (dùng chung event_id để Meta tự động khử trùng lặp 100%).
 */
function nexxi_meta_pixel_capture_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id ) {
	if ( nexxi_meta_pixel_external_plugin_active() || ! function_exists( 'WC' ) ) {
		return;
	}
	$target_id = $variation_id ? (int) $variation_id : (int) $product_id;
	$product   = wc_get_product( $target_id );
	if ( ! $product ) {
		return;
	}
	$qty      = max( 1, (int) $quantity );
	$unit     = nexxi_meta_pixel_effective_price( $product );
	$cid      = nexxi_meta_pixel_content_id( $product );
	$curr     = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND';
	$event_id = 'nexxi_atc_' . wp_generate_uuid4();

	$custom_data = array(
		'content_ids'  => array( $cid ),
		'contents'     => array(
			array(
				'id'         => $cid,
				'quantity'   => $qty,
				'item_price' => $unit,
			),
		),
		'content_name' => wp_strip_all_tags( $product->get_title() ),
		'content_type' => 'product',
		'value'        => (float) round( $unit * $qty ),
		'currency'     => $curr,
		'num_items'    => $qty,
	);

	// Đính kèm event_id để cả AJAX fragment (stitch-interactions.js) và hàng đợi session dùng chung với CAPI.
	$browser_event             = $custom_data;
	$browser_event['event_id'] = $event_id;

	$GLOBALS['nexxi_pending_fbq_atc'] = $browser_event;
	if ( WC()->session ) {
		$queue   = WC()->session->get( 'nexxi_fbq_atc_queue', array() );
		$queue   = is_array( $queue ) ? $queue : array();
		$queue[] = $browser_event;
		if ( count( $queue ) > 5 ) {
			$queue = array_slice( $queue, -5 );
		}
		WC()->session->set( 'nexxi_fbq_atc_queue', $queue );
	}

	// Gửi Server-Side CAPI AddToCart ở nền (shutdown) nếu đã cấu hình Access Token.
	if ( '' !== nexxi_meta_capi_get_token() ) {
		$user_data  = nexxi_meta_capi_build_user_data();
		$source_url = wp_get_referer() ? wp_get_referer() : $product->get_permalink();
		$now_ts     = time();
		nexxi_meta_capi_queue_job( static function () use ( $event_id, $custom_data, $user_data, $source_url, $now_ts ) {
			nexxi_meta_capi_send_event( 'AddToCart', $event_id, $custom_data, $user_data, $source_url, $now_ts, 4 );
		} );
	}
}
add_action( 'woocommerce_add_to_cart', 'nexxi_meta_pixel_capture_add_to_cart', 20, 4 );

/** Đính kèm payload AddToCart vào phản hồi AJAX add_to_cart (và xóa khỏi hàng đợi session để không bắn lại lần 2). */
function nexxi_meta_pixel_atc_fragment( $fragments ) {
	if ( ! empty( $GLOBALS['nexxi_pending_fbq_atc'] ) ) {
		$fragments['nexxi-fbq-atc'] = $GLOBALS['nexxi_pending_fbq_atc'];
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'nexxi_fbq_atc_queue', array() );
		}
	}
	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'nexxi_meta_pixel_atc_fragment', 20 );

/**
 * Xuất các sự kiện ViewContent, AddToCart (từ form POST), InitiateCheckout và Purchase ở chân trang.
 * Khắc phục SST-04: đảm bảo stub hàng đợi fbq luôn tồn tại và đồng bộ eventID 1-1 với Server-Side CAPI.
 */
function nexxi_meta_pixel_print_ecommerce_events() {
	if ( is_admin() || wp_doing_ajax() || nexxi_meta_pixel_external_plugin_active() || ! function_exists( 'WC' ) ) {
		return;
	}
	$calls = array();
	$curr  = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND';

	// A. Hàng đợi AddToCart từ thao tác POST form (ví dụ nút Mua Ngay chuyển thẳng sang Thanh toán)
	if ( WC()->session ) {
		$queued = WC()->session->get( 'nexxi_fbq_atc_queue', array() );
		if ( ! empty( $queued ) && is_array( $queued ) ) {
			foreach ( $queued as $ev ) {
				if ( is_array( $ev ) ) {
					$eid = ! empty( $ev['event_id'] ) ? (string) $ev['event_id'] : ( 'nexxi_atc_' . wp_generate_uuid4() );
					unset( $ev['event_id'] );
					$calls[] = array( 'AddToCart', $ev, array( 'eventID' => $eid ) );
				}
			}
			WC()->session->set( 'nexxi_fbq_atc_queue', array() );
		}
	}

	// B. ViewContent trên trang chi tiết sản phẩm (sinh eventID động ở trình duyệt để không bị dính LiteSpeed Cache 1h)
	if ( function_exists( 'is_product' ) && is_product() ) {
		$prod = function_exists( 'nexxi_kids_current_product' ) ? nexxi_kids_current_product() : wc_get_product( get_queried_object_id() );
		if ( $prod instanceof WC_Product ) {
			$vc_cid  = nexxi_meta_pixel_content_id( $prod );
			$vc_unit = nexxi_meta_pixel_effective_price( $prod );
			$calls[] = array(
				'ViewContent',
				array(
					'content_ids'  => array( $vc_cid ),
					'contents'     => array(
						array(
							'id'         => $vc_cid,
							'quantity'   => 1,
							'item_price' => $vc_unit,
						),
					),
					'content_name' => wp_strip_all_tags( $prod->get_name() ),
					'content_type' => 'product',
					'value'        => $vc_unit,
					'currency'     => $curr,
				),
				'__NEXXI_DYNAMIC_VC_EID__',
			);
		}
	}

	// C. Purchase trên trang hoàn tất đơn hàng (order-received) — đồng bộ eventID nexxi_order_{id} & external_id với CAPI
	$purchase_adv_match = array();
	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		$order = nexxi_meta_pixel_resolve_thankyou_order();
		if ( $order && ! $order->has_status( array( 'failed', 'cancelled', 'trash' ) ) ) {
			$purchase_oid       = (int) $order->get_id();
			$purchase_adv_match = nexxi_meta_pixel_get_browser_adv_matching( $order );
			$calls[]            = array(
				'Purchase',
				nexxi_meta_pixel_build_order_custom_data( $order ),
				array( 'eventID' => 'nexxi_order_' . $purchase_oid ),
				$purchase_oid,
			);
		}
	} elseif ( function_exists( 'is_checkout' ) && is_checkout() && WC()->cart && ! WC()->cart->is_empty() ) {
		// D. InitiateCheckout trên trang thanh toán (dùng chung eventID giữa Browser và Server CAPI)
		$cids      = array();
		$contents  = array();
		$num_items = 0;
		foreach ( WC()->cart->get_cart() as $ci ) {
			$qty = max( 1, (int) ( $ci['quantity'] ?? 1 ) );
			$p   = isset( $ci['data'] ) && $ci['data'] instanceof WC_Product ? $ci['data'] : null;
			$cid = $p ? nexxi_meta_pixel_content_id( $p ) : ( ! empty( $ci['product_id'] ) ? (string) $ci['product_id'] : '' );
			if ( '' !== $cid ) {
				$cids[]     = $cid;
				$u_price    = $p ? nexxi_meta_pixel_effective_price( $p ) : 0.0;
				$contents[] = array(
					'id'         => $cid,
					'quantity'   => $qty,
					'item_price' => $u_price,
				);
			}
			$num_items += $qty;
		}
		$val = (float) WC()->cart->get_total( 'edit' );
		if ( $val <= 0 ) {
			$val = (float) WC()->cart->get_subtotal();
		}
		$rounded_val = (float) round( $val );
		$sess_seed   = WC()->session ? (string) WC()->session->get_customer_id() : nexxi_meta_capi_client_ip();
		$ic_eid      = 'nexxi_ic_' . substr( md5( $sess_seed . '|' . round( microtime( true ) / 10 ) . '|' . $rounded_val ), 0, 16 );
		$ic_data     = array(
			'content_ids'  => array_values( array_unique( array_filter( $cids ) ) ),
			'contents'     => $contents,
			'content_type' => 'product',
			'value'        => $rounded_val,
			'currency'     => $curr,
			'num_items'    => max( 1, $num_items ),
		);

		$calls[] = array(
			'InitiateCheckout',
			$ic_data,
			array( 'eventID' => $ic_eid ),
		);

		// Bắn đồng thời InitiateCheckout qua Server-Side CAPI (chống gửi lặp khi F5 liên tục trong 60 giây).
		if ( '' !== nexxi_meta_capi_get_token() ) {
			$already_ic = false;
			$ic_sig     = md5( wp_json_encode( $ic_data['content_ids'] ) . '|' . $rounded_val );
			if ( WC()->session ) {
				$prev = WC()->session->get( 'nexxi_capi_last_ic', array() );
				if ( is_array( $prev ) && isset( $prev['sig'], $prev['ts'] ) && $prev['sig'] === $ic_sig && ( time() - (int) $prev['ts'] ) < 60 ) {
					$already_ic = true;
				} else {
					WC()->session->set( 'nexxi_capi_last_ic', array( 'sig' => $ic_sig, 'ts' => time() ) );
				}
			}
			if ( ! $already_ic ) {
				$user_data  = nexxi_meta_capi_build_user_data();
				$source_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : nexxi_meta_capi_current_url();
				$now_ts     = time();
				nexxi_meta_capi_queue_job( static function () use ( $ic_eid, $ic_data, $user_data, $source_url, $now_ts ) {
					nexxi_meta_capi_send_event( 'InitiateCheckout', $ic_eid, $ic_data, $user_data, $source_url, $now_ts, 4 );
				} );
			}
		}
	}

	if ( empty( $calls ) ) {
		return;
	}

	echo "<script data-no-optimize=\"1\" data-no-defer=\"1\">\n";
	echo "window.fbq=window.fbq||function(){(window.fbq.queue=window.fbq.queue||[]).push(arguments)};\n";
	if ( ! empty( $purchase_adv_match ) && empty( $GLOBALS['nexxi_meta_pixel_head_adv_matched'] ) ) {
		$px_id_js = wp_json_encode( nexxi_meta_pixel_get_id() );
		$am_json  = wp_json_encode( $purchase_adv_match, JSON_UNESCAPED_SLASHES );
		echo "window.fbq('init', {$px_id_js}, {$am_json});\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	foreach ( $calls as $c ) {
		$ev_name = wp_json_encode( $c[0] );
		$ev_data = wp_json_encode( $c[1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( isset( $c[2] ) && '__NEXXI_DYNAMIC_VC_EID__' === $c[2] ) {
			echo "window.fbq('track', {$ev_name}, {$ev_data}, {eventID: 'nexxi_vc_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10)});\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'Purchase' === $c[0] && ! empty( $c[3] ) ) {
			$ev_opts  = wp_json_encode( $c[2], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$guard_id = wp_json_encode( 'nexxi_fbq_pur_' . (int) $c[3] );
			echo "(function(){var k={$guard_id},done=false;try{done=!!(window.localStorage&&localStorage.getItem(k))||!!(window.sessionStorage&&sessionStorage.getItem(k));}catch(e){}if(!done){window.fbq('track',{$ev_name},{$ev_data},{$ev_opts});try{if(window.localStorage)localStorage.setItem(k,'1');if(window.sessionStorage)sessionStorage.setItem(k,'1');}catch(e){}}})();\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( ! empty( $c[2] ) && is_array( $c[2] ) ) {
			$ev_opts = wp_json_encode( $c[2], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			echo "window.fbq('track', {$ev_name}, {$ev_data}, {$ev_opts});\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo "window.fbq('track', {$ev_name}, {$ev_data});\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
	echo "</script>\n";
}
add_action( 'wp_footer', 'nexxi_meta_pixel_print_ecommerce_events', 40 );

/* ==========================================================================
 * 6. TRANG QUẢN TRỊ META PIXEL & CONVERSIONS API TRONG WP ADMIN
 * ========================================================================== */

/** Đăng ký menu quản trị tại Cài đặt -> Meta Pixel & CAPI và WooCommerce -> Meta Pixel & CAPI. */
function nexxi_meta_capi_register_admin_menus() {
	add_options_page(
		'Meta Pixel & Conversions API',
		'Meta Pixel & CAPI',
		'manage_options',
		'nexxi-meta-capi',
		'nexxi_meta_capi_render_admin_page'
	);
	if ( class_exists( 'WooCommerce' ) ) {
		add_submenu_page(
			'woocommerce',
			'Meta Pixel & Conversions API',
			'Meta Pixel & CAPI',
			'manage_options',
			'nexxi-meta-capi-wc',
			'nexxi_meta_capi_render_admin_page'
		);
	}
}
add_action( 'admin_menu', 'nexxi_meta_capi_register_admin_menus', 65 );

/** Hiển thị thông báo nhắc cấu hình CAPI Token ngay trong WP Admin nếu chưa nhập mã EAA... */
function nexxi_meta_capi_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( in_array( $page, array( 'nexxi-meta-capi', 'nexxi-meta-capi-wc' ), true ) ) {
		return;
	}
	if ( '' !== nexxi_meta_capi_get_token() ) {
		return;
	}
	$cfg_url = admin_url( 'options-general.php?page=nexxi-meta-capi' );
	?>
	<div class="notice notice-warning is-dismissible" style="border-left-color:#fd6a49;padding:12px 16px;">
		<p style="margin:0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
			<span>
				<strong>📡 NEXXI Meta Conversions API (Pixel <?php echo esc_html( nexxi_meta_pixel_get_id() ); ?>):</strong>
				Chưa nhập <strong>Mã truy cập (Access Token)</strong>. Dán mã <code>EAA...</code> để tự động gửi chuyển đổi <strong>Purchase</strong> từ máy chủ lên Meta (chống trình duyệt chặn quảng cáo) &amp; gửi bù các đơn COD chưa ghi nhận!
			</span>
			<a href="<?php echo esc_url( $cfg_url ); ?>" class="button button-primary" style="background:#fd6a49;border-color:#fd6a49;">⚙️ Cấu hình &amp; Dán mã EAA ngay</a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'nexxi_meta_capi_admin_notice' );

/** Che bớt thông tin SĐT hiển thị trên bảng quản trị để bảo vệ quyền riêng tư màn hình. */
function nexxi_meta_capi_mask_phone( $phone ) {
	$d = preg_replace( '/\D+/', '', (string) $phone );
	if ( strlen( $d ) < 7 ) {
		return '—';
	}
	return substr( $d, 0, 3 ) . '***' . substr( $d, -3 );
}

/** Giao diện & xử lý thao tác trang quản trị Meta Pixel & CAPI. */
function nexxi_meta_capi_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'nexxi-kids' ) );
	}

	$notice_html = '';

	if ( isset( $_POST['nexxi_meta_capi_action'] ) ) {
		check_admin_referer( 'nexxi_meta_capi_admin_action', 'nexxi_meta_capi_nonce' );
		$action = sanitize_key( wp_unslash( $_POST['nexxi_meta_capi_action'] ) );

		// 1. Lưu cấu hình (và tùy chọn bắn thử sự kiện kiểm tra)
		if ( in_array( $action, array( 'save_settings', 'save_and_test' ), true ) ) {
			$new_pixel = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['nexxi_meta_pixel_id'] ?? '' ) ) );
			if ( '' === $new_pixel ) {
				$new_pixel = NEXXI_META_PIXEL_ID;
			}
			update_option( 'nexxi_meta_pixel_id', $new_pixel, true );

			$submitted_token = trim( sanitize_textarea_field( wp_unslash( $_POST['nexxi_meta_capi_access_token'] ?? '' ) ) );
			$clear_token     = ! empty( $_POST['nexxi_meta_capi_clear_token'] );
			if ( $clear_token ) {
				update_option( 'nexxi_meta_capi_access_token', '', false );
			} elseif ( '' !== $submitted_token && 0 !== strpos( $submitted_token, '••' ) ) {
				update_option( 'nexxi_meta_capi_access_token', $submitted_token, false );
			}

			$test_code = preg_replace( '/[^A-Za-z0-9_\-]/', '', sanitize_text_field( wp_unslash( $_POST['nexxi_meta_capi_test_code'] ?? '' ) ) );
			update_option( 'nexxi_meta_capi_test_code', $test_code, false );

			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

			if ( 'save_and_test' === $action ) {
				$test_eid = 'nexxi_capi_admin_test_' . time();
				$res      = nexxi_meta_capi_send_event( 'PageView', $test_eid, array(), nexxi_meta_capi_build_user_data(), home_url( '/' ), time(), 8 );
				if ( $res['ok'] ) {
					$notice_html = '<div class="notice notice-success"><p><strong>✅ Lưu thành công &amp; Kết nối Meta CAPI hoạt động hoàn hảo!</strong> Máy chủ Meta đã nhận sự kiện <code>PageView</code> (<code>events_received: ' . (int) $res['events_received'] . '</code>, <code>fbtrace_id: ' . esc_html( $res['fbtrace_id'] ) . '</code>).</p></div>';
				} else {
					$notice_html = '<div class="notice notice-error"><p><strong>❌ Đã lưu cấu hình nhưng kiểm tra kết nối thất bại:</strong> ' . esc_html( $res['error'] ?: 'Không rõ lỗi' ) . ' (HTTP ' . (int) $res['http_code'] . '). Vui lòng kiểm tra lại chuỗi Access Token <code>EAA...</code>.</p></div>';
				}
			} else {
				$notice_html = '<div class="notice notice-success"><p><strong>✅ Đã lưu cấu hình Meta Pixel &amp; Conversions API và xóa bộ nhớ đệm LiteSpeed!</strong></p></div>';
			}
		}

		// 2. Tắt nhanh plugin rỗng Meta for WooCommerce đang gây xung đột
		if ( 'deactivate_fb_wc' === $action && current_user_can( 'activate_plugins' ) ) {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins( 'facebook-for-woocommerce/facebook-for-woocommerce.php' );
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$notice_html = '<div class="notice notice-success"><p><strong>✅ Đã ngừng kích hoạt plugin rỗng "Meta for WooCommerce" và làm mới bộ nhớ đệm!</strong></p></div>';
		}

		// 3. Gửi bù 1 đơn hàng cụ thể lên Meta CAPI
		if ( 'send_single_order' === $action && ! empty( $_POST['target_order_id'] ) ) {
			$oid = absint( $_POST['target_order_id'] );
			$res = nexxi_meta_capi_send_order_purchase( $oid, true );
			if ( $res['ok'] ) {
				$notice_html = '<div class="notice notice-success"><p><strong>✅ Đã gửi sự kiện Purchase của đơn #' . (int) $oid . ' lên Meta CAPI thành công!</strong> (<code>event_id: nexxi_order_' . (int) $oid . '</code>, <code>fbtrace_id: ' . esc_html( $res['fbtrace_id'] ) . '</code>).</p></div>';
			} else {
				$notice_html = '<div class="notice notice-error"><p><strong>❌ Không thể gửi đơn #' . (int) $oid . ':</strong> ' . esc_html( $res['error'] ) . '</p></div>';
			}
		}

		// 4. Gửi bù toàn bộ đơn chưa gửi trong 7 ngày gần nhất
		if ( 'backfill_7d' === $action && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array(
				'limit'        => 25,
				'type'         => 'shop_order',
				'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
				'date_created' => '>' . ( time() - 7 * DAY_IN_SECONDS ),
				'orderby'      => 'date',
				'order'        => 'ASC',
			) );
			$sent_cnt = 0;
			$skip_cnt = 0;
			$fail_cnt = 0;
			foreach ( $orders as $ord ) {
				if ( ! $ord instanceof WC_Order ) {
					continue;
				}
				if ( '1' === (string) $ord->get_meta( '_nexxi_capi_purchase_sent', true ) ) {
					$skip_cnt++;
					continue;
				}
				$r = nexxi_meta_capi_send_order_purchase( $ord, false );
				if ( $r['ok'] ) {
					$sent_cnt++;
				} else {
					$fail_cnt++;
				}
			}
			$notice_html = '<div class="notice ' . ( $fail_cnt > 0 ? 'notice-warning' : 'notice-success' ) . '"><p><strong>📤 Hoàn tất quét gửi bù đơn hàng 7 ngày qua:</strong> Đã gửi mới thành công <strong>' . (int) $sent_cnt . '</strong> đơn, bỏ qua <strong>' . (int) $skip_cnt . '</strong> đơn đã gửi trước đó' . ( $fail_cnt > 0 ? ', lỗi <strong>' . (int) $fail_cnt . '</strong> đơn' : '' ) . '.</p></div>';
		}
	}

	$pixel_id     = nexxi_meta_pixel_get_id();
	$token        = nexxi_meta_capi_get_token();
	$has_token    = '' !== $token;
	$masked_token = $has_token ? ( substr( $token, 0, 10 ) . '••••••••••••••••••••••••' . substr( $token, -6 ) ) : '';
	$const_token  = defined( 'NEXXI_META_CAPI_TOKEN' ) && '' !== trim( (string) NEXXI_META_CAPI_TOKEN );
	$test_code    = nexxi_meta_capi_get_test_code();
	$last_ok      = get_option( 'nexxi_meta_capi_last_success', array() );
	$fb_wc_empty  = class_exists( 'WC_Facebookcommerce' ) && ! nexxi_meta_pixel_external_plugin_active();

	$recent_orders = array();
	if ( function_exists( 'wc_get_orders' ) ) {
		$recent_orders = wc_get_orders( array(
			'limit'        => 10,
			'type'         => 'shop_order',
			'date_created' => '>' . ( time() - 7 * DAY_IN_SECONDS ),
			'orderby'      => 'date',
			'order'        => 'DESC',
		) );
	}
	?>
	<div class="wrap" style="max-width:1080px;">
		<h1 style="display:flex;align-items:center;gap:10px;font-weight:700;">
			<span>📡 NEXXI — Meta Pixel &amp; Conversions API (Server-Side CAPI)</span>
			<?php if ( $has_token ) : ?>
				<span style="background:#dcfce7;color:#166534;font-size:12px;padding:3px 10px;border-radius:999px;font-weight:700;">● Đã kết nối CAPI Token</span>
			<?php else : ?>
				<span style="background:#fef3c7;color:#92400e;font-size:12px;padding:3px 10px;border-radius:999px;font-weight:700;">⚠ Chưa nhập Access Token</span>
			<?php endif; ?>
		</h1>

		<?php echo $notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<?php if ( $fb_wc_empty ) : ?>
			<div class="notice notice-info" style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
				<span>ℹ️ Phát hiện plugin <strong>Meta for WooCommerce</strong> đang bật nhưng bỏ trống (chưa gắn Pixel). Giao diện NEXXI đã tự động bỏ qua xung đột này, nhưng bạn nên tắt hẳn plugin rỗng để nhẹ máy chủ.</span>
				<form method="post" style="margin:0;">
					<?php wp_nonce_field( 'nexxi_meta_capi_admin_action', 'nexxi_meta_capi_nonce' ); ?>
					<input type="hidden" name="nexxi_meta_capi_action" value="deactivate_fb_wc">
					<button type="submit" class="button">🧹 Tắt plugin Meta for WooCommerce</button>
				</form>
			</div>
		<?php endif; ?>

		<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px 24px;margin-top:16px;box-shadow:0 1px 2px rgba(0,0,0,.04);">
			<h2 style="margin-top:0;">1. Cấu hình Kết nối Trực tiếp Meta Conversions API (<?php echo esc_html( NEXXI_META_CAPI_GRAPH_VERSION ); ?>)</h2>
			<form method="post">
				<?php wp_nonce_field( 'nexxi_meta_capi_admin_action', 'nexxi_meta_capi_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nexxi_meta_pixel_id">Meta Pixel / Dataset ID</label></th>
						<td>
							<input name="nexxi_meta_pixel_id" id="nexxi_meta_pixel_id" type="text" class="regular-text" value="<?php echo esc_attr( $pixel_id ); ?>" placeholder="3331438223710770">
							<p class="description">ID Tập dữ liệu nhận cả sự kiện Trình duyệt (Pixel) lẫn Máy chủ (CAPI) kèm khử trùng lặp <code>event_id</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxi_meta_capi_access_token">Mã truy cập CAPI (Access Token)</label></th>
						<td>
							<?php if ( $const_token ) : ?>
								<p style="color:#166534;font-weight:600;">🔒 Đang sử dụng hằng số bảo mật <code>NEXXI_META_CAPI_TOKEN</code> từ <code>wp-config.php</code> (<?php echo esc_html( $masked_token ); ?>).</p>
							<?php else : ?>
								<textarea name="nexxi_meta_capi_access_token" id="nexxi_meta_capi_access_token" rows="3" class="large-text code" placeholder="Dán chuỗi mã bắt đầu bằng EAA... lấy tại Trình quản lý sự kiện -> Cài đặt -> API Chuyển đổi -> Tạo mã truy cập"></textarea>
								<?php if ( $has_token ) : ?>
									<p class="description" style="color:#166534;font-weight:600;">
										✓ Đang lưu mã: <code><?php echo esc_html( $masked_token ); ?></code> (Để trống ô trên nếu muốn giữ nguyên mã hiện tại, hoặc dán mã <code>EAA...</code> mới để thay thế).
									</p>
									<label style="display:inline-block;margin-top:4px;color:#b91c1c;">
										<input type="checkbox" name="nexxi_meta_capi_clear_token" value="1"> Xóa Access Token đang lưu
									</label>
								<?php else : ?>
									<p class="description">Mã được lưu riêng tư trong cơ sở dữ liệu hosting của bạn (không bao giờ lưu vào file code hay GitHub).</p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nexxi_meta_capi_test_code">Mã thử nghiệm sự kiện (Tùy chọn)</label></th>
						<td>
							<input name="nexxi_meta_capi_test_code" id="nexxi_meta_capi_test_code" type="text" class="regular-text" value="<?php echo esc_attr( $test_code ); ?>" placeholder="Để trống khi chạy thật (VD: TEST12345)">
							<p class="description">Chỉ điền mã <code>TEST...</code> khi bạn đang mở tab <em>Thử nghiệm sự kiện</em> trên Meta để kiểm tra trực tiếp; <strong>để trống khi chạy quảng cáo thật</strong>.</p>
						</td>
					</tr>
				</table>

				<?php if ( is_array( $last_ok ) && ! empty( $last_ok['time'] ) ) : ?>
					<p style="background:#f8fafc;border-left:4px solid #22c55e;padding:8px 12px;margin:8px 0 16px;">
						<strong>Lần gửi CAPI thành công gần nhất:</strong>
						Sự kiện <code><?php echo esc_html( (string) ( $last_ok['event_name'] ?? '' ) ); ?></code>
						(ID: <code><?php echo esc_html( (string) ( $last_ok['event_id'] ?? '' ) ); ?></code>,
						fbtrace_id: <code><?php echo esc_html( (string) ( $last_ok['fbtrace_id'] ?? '' ) ); ?></code>)
						lúc <strong><?php echo esc_html( wp_date( 'H:i:s d/m/Y', (int) $last_ok['time'] ) ); ?></strong>.
					</p>
				<?php endif; ?>

				<p class="submit" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:0;">
					<button type="submit" name="nexxi_meta_capi_action" value="save_and_test" class="button button-primary" style="background:#fd6a49;border-color:#fd6a49;">🧪 Lưu &amp; Bắn Thử Sự Kiện Kiểm Tra Lên Meta</button>
					<button type="submit" name="nexxi_meta_capi_action" value="save_settings" class="button">💾 Chỉ lưu cấu hình</button>
				</p>
			</form>
		</div>

		<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px 24px;margin-top:20px;box-shadow:0 1px 2px rgba(0,0,0,.04);">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
				<div>
					<h2 style="margin:0 0 4px;">2. Trạng thái CAPI Đơn hàng 7 ngày gần nhất &amp; Gửi bù đơn COD</h2>
					<p style="margin:0;color:#64748b;">Mỗi đơn hàng mới sẽ tự động bắn <code>Purchase</code> qua CAPI ngay khi đặt. Với các đơn phát sinh trước khi nhập Token (như đơn tối qua), bấm nút bên phải để gửi bù đúng mốc giờ đặt hàng gốc!</p>
				</div>
				<form method="post" style="margin:0;">
					<?php wp_nonce_field( 'nexxi_meta_capi_admin_action', 'nexxi_meta_capi_nonce' ); ?>
					<input type="hidden" name="nexxi_meta_capi_action" value="backfill_7d">
					<button type="submit" class="button button-primary" <?php disabled( ! $has_token ); ?>>📤 Gửi bù tất cả đơn chưa gửi (7 ngày qua)</button>
				</form>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Đơn hàng</th>
						<th>Thời gian đặt</th>
						<th>Khách hàng</th>
						<th>Giá trị</th>
						<th>TT</th>
						<th>Mã khử trùng (event_id)</th>
						<th>Trạng thái Meta CAPI</th>
						<th>Thao tác</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $recent_orders ) ) : ?>
						<tr><td colspan="8">Chưa có đơn hàng nào trong 7 ngày gần nhất.</td></tr>
					<?php else : ?>
						<?php foreach ( $recent_orders as $ord ) :
							if ( ! $ord instanceof WC_Order ) {
								continue;
							}
							$oid      = $ord->get_id();
							$dt       = $ord->get_date_created();
							$sent     = '1' === (string) $ord->get_meta( '_nexxi_capi_purchase_sent', true );
							$fbtrace  = (string) $ord->get_meta( '_nexxi_capi_purchase_fbtrace', true );
							$log      = $ord->get_meta( '_nexxi_capi_purchase_log', true );
							$err_last = is_array( $log ) && ! empty( $log['error'] ) ? (string) $log['error'] : '';
							?>
							<tr>
								<td><strong>#<?php echo esc_html( $ord->get_order_number() ); ?></strong> <span style="color:#64748b;font-size:12px;">(<?php echo esc_html( wc_get_order_status_name( $ord->get_status() ) ); ?>)</span></td>
								<td><?php echo esc_html( $dt ? $dt->date_i18n( 'H:i d/m/Y' ) : '—' ); ?></td>
								<td><?php echo esc_html( nexxi_meta_capi_mask_phone( $ord->get_billing_phone() ) ); ?></td>
								<td><strong><?php echo wp_kses_post( $ord->get_formatted_order_total() ); ?></strong></td>
								<td><?php echo esc_html( strtoupper( (string) $ord->get_payment_method() ) ); ?></td>
								<td><code>nexxi_order_<?php echo (int) $oid; ?></code></td>
								<td>
									<?php if ( $sent ) : ?>
										<span style="color:#166534;font-weight:700;">✅ Đã gửi CAPI</span>
										<?php if ( '' !== $fbtrace ) : ?>
											<br><small style="color:#64748b;">trace: <code><?php echo esc_html( $fbtrace ); ?></code></small>
										<?php endif; ?>
									<?php elseif ( '' !== $err_last ) : ?>
										<span style="color:#b91c1c;font-weight:600;">❌ Lỗi: <?php echo esc_html( $err_last ); ?></span>
									<?php else : ?>
										<span style="color:#92400e;font-weight:600;">⏳ Chưa gửi</span>
									<?php endif; ?>
								</td>
								<td>
									<form method="post" style="margin:0;">
										<?php wp_nonce_field( 'nexxi_meta_capi_admin_action', 'nexxi_meta_capi_nonce' ); ?>
										<input type="hidden" name="nexxi_meta_capi_action" value="send_single_order">
										<input type="hidden" name="target_order_id" value="<?php echo (int) $oid; ?>">
										<button type="submit" class="button button-small" <?php disabled( ! $has_token ); ?>>
											<?php echo $sent ? '↻ Gửi lại' : '📤 Gửi CAPI'; ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php
}

/** Tự động làm mới LiteSpeed Cache 1 lần khi bản nâng cấp Meta CAPI v21.0 được kích hoạt. */
add_action( 'init', static function () {
	$ver = '20261106_meta_capi_v21_2';
	if ( get_option( 'nexxi_meta_capi_lscache_ver' ) !== $ver ) {
		update_option( 'nexxi_meta_capi_lscache_ver', $ver, true );
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}
}, 25 );
