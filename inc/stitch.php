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
			if ( isset( $routes[ $path ] ) && false === strpos( $open, 'data-href-fixed' ) ) { $open = nexxi_dom_tag_with_attr( $open, 'href', $routes[ $path ] ); }
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
				return nexxi_dom_tag_with_attr( nexxi_dom_tag_with_attr( $m[0], 'href', $routes[ $path ] ), 'aria-label', $label );
			}, $outer, 1 );
			return preg_replace( '~(<span\b[^>]*class="[^"]*rounded-full[^"]*")([^>]*>)\s*\d*\s*(</span>)~', '${1} ' . $badge[0] . '${2}' . (int) $badge[1] . '${3}', $outer, 1 );
		} );
	}

	// Account avatar → account link.
	$avatar = nexxi_dom_locate( $header, '<div class="w-8 h-8 rounded-full bg-primary' );
	if ( $avatar ) {
		$open = substr( $header, $avatar['start'], $avatar['open_end'] - $avatar['start'] );
		$open = preg_replace( '~^<div\b~', '<a', substr( $open, 0, -1 ) ) . ' href="' . esc_url( nexxi_kids_stitch_url( 'account.html' ) ) . '" aria-label="' . esc_attr( is_user_logged_in() ? 'Tài khoản của mẹ' : 'Đăng nhập / Đăng ký' ) . '">';
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

	return substr( $html, 0, $el['start'] ) . $header . substr( $html, $el['end'] );
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
		array( 'bé gái', nexxi_kids_category_url( 'be-gai' ) ),
		array( 'bé trai', nexxi_kids_category_url( 'be-trai' ) ),
		array( 'sơ sinh', nexxi_kids_category_url( 'so-sinh' ) ),
		array( 'flash sale', nexxi_kids_flash_url() ),
		array( 'size', home_url( '/#size-guide' ) ),
		array( 'đổi trả', nexxi_kids_page_url( 'doi-tra' ) ),
		array( 'bảo hành', nexxi_kids_page_url( 'doi-tra' ) . '#bao-hanh' ),
		array( 'tra cứu', $orders ),
		array( 'faq', nexxi_kids_page_url( 'lien-he' ) . '#faq' ),
		array( 'vận chuyển', nexxi_kids_page_url( 'van-chuyen' ) ),
		array( 'giao hàng', nexxi_kids_page_url( 'van-chuyen' ) ),
		array( 'liên hệ', nexxi_kids_page_url( 'lien-he' ) ),
		array( 'giới thiệu', nexxi_kids_page_url( 'gioi-thieu' ) ),
	);
	$footer = preg_replace_callback( '~(<a\b[^>]*>)(.*?)(</a>)~s', static function ( $m ) use ( $rules, $hotline, $chat ) {
		$label = function_exists( 'mb_strtolower' ) ? mb_strtolower( wp_strip_all_tags( $m[2] ) ) : strtolower( wp_strip_all_tags( $m[2] ) );
		if ( false !== strpos( $label, 'hotline' ) ) {
			return nexxi_dom_tag_with_attr( $m[1], 'href', nexxi_kids_tel( $hotline ) ) . 'Hotline: ' . esc_html( $hotline ) . $m[3];
		}
		if ( false !== strpos( $label, 'zalo' ) && $chat ) {
			$open = nexxi_dom_tag_with_attr( $m[1], 'href', $chat );
			return $open . 'Nhắn tin Zalo: ' . esc_html( $hotline ) . $m[3];
		}
		foreach ( $rules as $rule ) {
			if ( false !== strpos( $label, $rule[0] ) ) { return nexxi_dom_tag_with_attr( $m[1], 'href', $rule[1] ) . $m[2] . $m[3]; }
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
	$html = preg_replace_callback( '~<form\b[^>]*onsubmit="handleAuthSubmit\(event, \'(login|register)\'\)"[^>]*>.*?</form>~s', static function ( $match ) {
		$kind = $match[1];
		$form = $match[0];
		if ( 'login' === $kind ) {
			$form = str_replace( 'onsubmit="handleAuthSubmit(event, \'login\')"', 'method="post"', $form );
			$form = str_replace( 'id="login-account"', 'id="login-account" name="username" autocomplete="username"', $form );
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
	return $html;
}

/** Wrap WooCommerce shortcode output in the Stitch commerce main. */
function nexxi_kids_stitch_commerce_main( $content, $heading = '' ) {
	$head = $heading ? '<div class="nexxi-stitch-commerce__heading"><span class="font-label-sm text-label-sm text-secondary">NEXXI KIDS BOUTIQUE</span><h1 class="font-display-lg text-display-lg-mobile md:text-display-lg text-primary">' . esc_html( $heading ) . '</h1></div>' : '';
	return '<main class="nexxi-stitch-commerce w-full pt-28 md:pt-36 bg-background min-h-screen"><div class="max-w-[1440px] mx-auto px-margin-mobile md:px-margin py-space-xl">' . $head . $content . '</div></main>';
}

/** Data handed to stitch-interactions.js. */
function nexxi_kids_stitch_config( $screen ) {
	$config = array(
		'screen'       => $screen,
		'home'         => home_url( '/' ),
		'shop'         => nexxi_kids_shop_url(),
		'cart'         => nexxi_kids_stitch_url( 'cart.html' ),
		'checkout'     => nexxi_kids_stitch_url( 'checkout.html' ),
		'account'      => nexxi_kids_stitch_url( 'account.html' ),
		'wishlistPage' => nexxi_kids_page_url( 'yeu-thich' ),
		'saleEnd'      => nexxi_kids_flash_end() > time() ? wp_date( 'c', nexxi_kids_flash_end() ) : '',
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

function nexxi_kids_render_stitch( $screen ) {
	$shells = array( 'index' => 'index', 'shop' => 'shop', 'product' => 'product', 'flash-sale' => 'flash-sale', 'cart' => 'cart', 'checkout' => 'checkout', 'account' => 'account', 'login' => 'login', 'members' => 'members', 'page' => 'index' );
	if ( ! isset( $shells[ $screen ] ) ) { return; }
	if ( ! headers_sent() ) {
		nocache_headers();
		@header( 'X-LiteSpeed-Cache-Control: no-cache' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
	}

	$html = nexxi_kids_stitch_lock_header_labels( $html );
	$html = nexxi_kids_stitch_header( $html, $screen );
	$html = nexxi_kids_stitch_footer( $html );
	$html = nexxi_studio_apply_late( $html );

	$asset_url = get_template_directory_uri() . '/assets/stitch';
	$html = str_replace( '{{STITCH_ASSET}}', esc_url( $asset_url ), $html );
	$html = str_replace( 'https://cdn.tailwindcss.com', esc_url( $asset_url . '/tailwind-play.js' ), $html );
	$html = str_replace( array( '14 - 17kg', '14-17kg' ), array( '14 - 16kg', '14-16kg' ), $html );
	// Convert Tailwind config fontSize definitions from fixed px to fluid rem so all responsive variants scale with viewport.
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
		return $m[1] . strtr( $m[2], $px_to_rem ) . $m[3];
	}, $html, 1 );
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
	if ( false === $head_end || false === $body_end ) { echo $html; return; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo substr( $html, 0, $head_end ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	wp_head();
	$stitch_css = $asset_url . '/woocommerce-stitch.css?ver=' . filemtime( get_template_directory() . '/assets/stitch/woocommerce-stitch.css' );
	echo nexxi_studio_stylesheet_tag( $stitch_css, get_template_directory() . '/assets/stitch/woocommerce-stitch.css' ) . '<style id="nexxi-shared-header-style">@keyframes marqueeCute{0%{transform:translateX(0%)}100%{transform:translateX(-50%)}}.animate-marquee{display:flex;width:max-content;animation:marqueeCute 38s linear infinite}.animate-marquee:hover{animation-play-state:paused}header[data-nexxi-shared-header="1"]+main{padding-top:6.5rem!important}@media (min-width:1024px){body>header>div:nth-child(2)>div{height:4.5rem!important;flex-wrap:nowrap!important;gap:0.5rem!important}body>header nav{display:flex!important;flex-wrap:nowrap!important;white-space:nowrap!important;min-width:0!important;gap:0!important;padding:0.25rem!important}body>header nav>a{display:inline-flex!important;align-items:center!important;flex:0 0 auto!important;white-space:nowrap!important;line-height:1.1rem!important;padding:0.45rem 0.6rem!important}body>header nav>a span{white-space:nowrap!important}body>header [class*="md:flex"]{flex:0 1 13rem!important;width:13rem!important;min-width:9.5rem!important}body>header .flex.items-center.gap-space-sm{gap:0.5rem!important;flex-wrap:nowrap!important}body>header .w-10{width:2.25rem!important;height:2.25rem!important;flex:0 0 2.25rem!important}}</style></head>';
	echo substr( $html, $head_end + 7, $body_end - $head_end - 7 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	wp_footer();
	$js = get_template_directory() . '/assets/stitch/stitch-interactions.js';
	echo '<script>window.NexxiStitch=' . wp_json_encode( nexxi_kids_stitch_config( $screen ) ) . ';</script><script src="' . esc_url( $asset_url . '/stitch-interactions.js?ver=' . ( is_readable( $js ) ? filemtime( $js ) : NEXXI_KIDS_VERSION ) ) . '"></script>';
	echo substr( $html, $body_end ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
