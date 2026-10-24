<?php
/**
 * Shop + Flash Sale screens. Filtering uses WooCommerce's native query vars
 * (filter_{attribute}, min_price/max_price, orderby, product_cat), so the main
 * query, pagination and the rendered grid always agree.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Grouped product collections matching NEXXI's actual WooCommerce categories. */
function nexxi_kids_cat_groups() {
	return array(
		'dam-vay'       => array(
			'label' => 'BST Đầm & Chân Váy Hàn Quốc',
			'short' => '👗 Đầm & Chân Váy',
			'intro' => 'Các mẫu đầm kiểu, set váy lụa dập, chân váy xếp ly và quần váy xinh xắn dành cho bé (12–45kg).',
			'slugs' => array( 'dam', 'dam-kieu', 'dam-ngan-tay', 'dam-sat-nach', 'dam-thun', 'set-vay', 'chan-vay', 'vay-ngan', 'quan-vay' ),
		),
		'set-bo'        => array(
			'label' => 'BST Set Phối & Bộ Đồ Năng Động',
			'short' => '✨ Set Phối & Bộ Đồ',
			'intro' => 'Các mẫu set phối Hàn Quốc, bộ thun ngắn tay, dài tay, sát nách và bộ lửng mềm mịn thoải mái vận động.',
			'slugs' => array( 'set-phoi', 'bo-thun', 'bo-sat-nach', 'bo-ngan-tay', 'bo-lung', 'bo-dai-tay' ),
		),
		'ao-thoi-trang' => array(
			'label' => 'BST Áo Thun, Sơ Mi & Áo Khoác',
			'short' => '👕 Áo Thun & Áo Kiểu',
			'intro' => 'Các mẫu áo thun co giãn 4 chiều, áo kiểu, áo sơ mi, áo dài và áo khoác thời trang cho bé từ 12–45kg.',
			'slugs' => array( 'ao-thun', 'ao-thun-ngan-tay', 'ao-thun-co-be', 'ao-thun-dai-tay', 'ao-thun-sat-nach', 'ao-so-mi', 'ao-kieu', 'ao-kieu-sat-nach-ngan-tay', 'ao-khoac', 'ao-dai' ),
		),
		'quan-xinh'     => array(
			'label' => 'BST Quần Dài, Quần Lửng & Quần Short',
			'short' => '👖 Quần Dài & Quần Lửng',
			'intro' => 'Các mẫu quần dài ống rộng, quần legging, quần lửng kaki túi hộp và quần sọt khỏe khoắn năng động.',
			'slugs' => array( 'quan-dai', 'quan-lung', 'quan-sot', 'quan-vay' ),
		),
	);
}

/** Query vars the storefront keeps when building filter links. */
function nexxi_kids_shop_keys() {
	$keys = array( 'orderby', 'on_sale', 'min_price', 'max_price', 's', 'post_type', 'product_cat', 'cat_group' );
	foreach ( array_keys( class_exists( 'Nexxi_Kids_Catalog' ) ? Nexxi_Kids_Catalog::attributes() : array() ) as $slug ) { $keys[] = 'filter_' . $slug; $keys[] = 'query_type_' . $slug; }
	return $keys;
}

/** Current filter state from the request (sanitised). */
function nexxi_kids_shop_state() {
	static $state = null;
	if ( null !== $state ) { return $state; }
	$state = array();
	foreach ( nexxi_kids_shop_keys() as $key ) {
		if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$state[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}
	return $state;
}

/** Base URL for the current listing (category archive or shop page). */
function nexxi_kids_shop_base() {
	if ( function_exists( 'is_product_category' ) && is_product_category() && empty( $_GET['product_cat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$link = get_term_link( get_queried_object() );
		if ( ! is_wp_error( $link ) ) { return $link; }
	}
	return nexxi_kids_shop_url();
}

/** Build a listing URL from the current state plus changes (null removes a key). */
function nexxi_kids_shop_link( array $changes = array(), $base = null ) {
	$args = array_merge( nexxi_kids_shop_state(), $changes );
	$args = array_filter( $args, static function ( $value ) { return null !== $value && '' !== $value; } );
	return add_query_arg( array_map( 'rawurlencode', $args ), null === $base ? nexxi_kids_shop_base() : $base );
}

/** Chosen term slugs for an attribute filter. */
function nexxi_kids_chosen( $slug ) {
	$state = nexxi_kids_shop_state();
	return isset( $state[ 'filter_' . $slug ] ) ? array_values( array_filter( array_map( 'sanitize_title', explode( ',', $state[ 'filter_' . $slug ] ) ) ) ) : array();
}

/** Link that toggles one term in an attribute filter. */
function nexxi_kids_toggle_link( $slug, $term_slug, $single = false ) {
	$chosen = nexxi_kids_chosen( $slug );
	if ( in_array( $term_slug, $chosen, true ) ) {
		$chosen = array_diff( $chosen, array( $term_slug ) );
	} else {
		$chosen = $single ? array( $term_slug ) : array_merge( $chosen, array( $term_slug ) );
	}
	return nexxi_kids_shop_link( array( 'filter_' . $slug => $chosen ? implode( ',', $chosen ) : null ) );
}

/** Terms of a global attribute that have products. */
function nexxi_kids_attribute_terms( $slug ) {
	$taxonomy = wc_attribute_taxonomy_name( $slug );
	if ( ! taxonomy_exists( $taxonomy ) ) { return array(); }
	$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
	if ( is_wp_error( $terms ) ) { return array(); }
	if ( 'size' === $slug ) { usort( $terms, static function ( $a, $b ) { return Nexxi_Kids_Catalog::size_number( $a->slug ) <=> Nexxi_Kids_Catalog::size_number( $b->slug ); } ); }
	return $terms;
}

/** Products per page: 25 (5 × 5 grid) on the shop, 12 (4 × 3) on Flash Sale. */
function nexxi_kids_loop_per_page() {
	return isset( $_GET['on_sale'] ) ? 12 : 25; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}
add_filter( 'loop_shop_per_page', 'nexxi_kids_loop_per_page', 999 );

/** Default catalog ordering on the shop is newest products first ('date') to match the UI selector. */
add_filter( 'woocommerce_default_catalog_orderby', static function ( $default ) {
	if ( ! empty( $_GET['on_sale'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $default;
	}
	return 'date';
}, 999 );

/** Legacy ?sort= links from the earlier build map to WooCommerce ?orderby=; also enforce 25 items per page on shop and apply ?cat_group= filter. */
function nexxi_kids_legacy_sort( $query ) {
	if ( empty( $_GET['on_sale'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query->set( 'posts_per_page', 25 );
	}
	if ( ! empty( $_GET['cat_group'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$groups = nexxi_kids_cat_groups();
		$cg     = sanitize_key( wp_unslash( $_GET['cat_group'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $groups[ $cg ] ) ) {
			$tax_query   = (array) $query->get( 'tax_query' );
			$tax_query[] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => $groups[ $cg ]['slugs'],
				'operator' => 'IN',
			);
			$query->set( 'tax_query', $tax_query );
		}
	}
	$orderby_param = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$sort_param    = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( '' !== $sort_param && '' === $orderby_param ) {
		$map = array( 'price_asc' => 'price', 'price_desc' => 'price-desc', 'popular' => 'popularity' );
		if ( isset( $map[ $sort_param ] ) ) {
			$ordering = WC()->query->get_catalog_ordering_args( $map[ $sort_param ] );
			$query->set( 'orderby', $ordering['orderby'] );
			$query->set( 'order', $ordering['order'] );
			return;
		}
	}

	if ( ( '' === $orderby_param || 'date' === $orderby_param ) && empty( $_GET['on_sale'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query->set( 'orderby', array( 'date' => 'DESC', 'ID' => 'DESC' ) );
		$query->set( 'order', 'DESC' );
	}
}
add_action( 'woocommerce_product_query', 'nexxi_kids_legacy_sort', 999 );

/** Price bounds of the catalogue, rounded to 50.000₫ steps. */
function nexxi_kids_price_bounds() {
	global $wpdb;
	$row = $wpdb->get_row( "SELECT MIN(min_price) AS lo, MAX(max_price) AS hi FROM {$wpdb->wc_product_meta_lookup} l INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id WHERE p.post_type = 'product' AND p.post_status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$lo = $row && $row->lo ? floor( (float) $row->lo / 50000 ) * 50000 : 0;
	$hi = $row && $row->hi ? ceil( (float) $row->hi / 50000 ) * 50000 : 500000;
	return array( (int) $lo, (int) max( $hi, $lo + 50000 ) );
}

function nexxi_kids_short_money( $amount ) {
	return $amount >= 1000 ? number_format( $amount / 1000, 0, ',', '.' ) . 'k' : (string) $amount;
}

/** Products of the main query (already filtered/paginated by WooCommerce). */
function nexxi_kids_loop_products() {
	global $wp_query;
	$products = array();
	foreach ( (array) $wp_query->posts as $post ) {
		$product = wc_get_product( $post );
		if ( $product && $product->is_visible() ) { $products[] = $product; }
	}
	return $products;
}

function nexxi_kids_stitch_shop( $html ) {
	global $wp_query;
	$products = nexxi_kids_loop_products();
	$total = (int) $wp_query->found_posts;
	$state = nexxi_kids_shop_state();
	$term = function_exists( 'is_product_category' ) && is_product_category() ? get_queried_object() : null;

	$label = 'Tất Cả Sản Phẩm Cho Bé';
	$groups = nexxi_kids_cat_groups();
	$active_cg = ! empty( $state['cat_group'] ) && isset( $groups[ $state['cat_group'] ] ) ? $groups[ $state['cat_group'] ] : null;
	if ( $active_cg ) { $label = $active_cg['label']; }
	if ( $term instanceof WP_Term ) { $label = $term->name; }
	if ( ! empty( $state['s'] ) ) { $label = 'Kết quả cho “' . $state['s'] . '”'; }
	$html = nexxi_dom_set_inner( $html, 'nx:shop-breadcrumb', '<a class="hover:text-secondary transition-colors flex items-center gap-1" href="' . esc_url( home_url( '/' ) ) . '"><span class="material-symbols-outlined text-[16px]">home</span> Trang Chủ</a><span class="text-outline-variant font-body-sm">/</span><a class="hover:text-secondary transition-colors" href="' . esc_url( nexxi_kids_shop_url() ) . '">Cửa Hàng &amp; Sản Phẩm</a><span class="text-outline-variant font-body-sm">/</span><span class="text-primary font-bold bg-surface-container-high px-space-sm py-0.5 rounded-full">' . esc_html( $label ) . '</span>' );
	if ( $active_cg || $term instanceof WP_Term || ! empty( $state['s'] ) ) {
		$html = nexxi_dom_set_inner( $html, 'nx:shop-title', esc_html( $label ) . ' 🧸' );
		if ( $active_cg && ! empty( $active_cg['intro'] ) ) {
			$html = nexxi_dom_set_inner( $html, 'nx:shop-intro', esc_html( $active_cg['intro'] ) );
		} elseif ( $term instanceof WP_Term && $term->description ) {
			$html = nexxi_dom_set_inner( $html, 'nx:shop-intro', esc_html( wp_strip_all_tags( $term->description ) ) );
		}
	}

	$html = nexxi_dom_set_inner( $html, 'nx:shop-quickfilters', nexxi_kids_shop_quickfilters() );
	$html = nexxi_dom_set_inner( $html, 'nx:shop-sidebar', nexxi_kids_shop_sidebar() );
	$html = nexxi_dom_set_inner( $html, 'nx:shop-status', nexxi_kids_shop_status( count( $products ), $total ) );

	$cards = '';
	foreach ( $products as $product ) { $cards .= nexxi_kids_card_shop( $product ); }
	$html = nexxi_dom_set_inner( $html, 'nx:shop-grid', $cards ? $cards : nexxi_kids_empty_state( 'Chưa có mẫu phù hợp', 'Mẹ thử bỏ bớt bộ lọc hoặc chọn danh mục khác nhé.', 'Xem tất cả sản phẩm', nexxi_kids_shop_url() ) );
	$html = nexxi_dom_set_outer( $html, 'nx:shop-pagination', nexxi_kids_pagination() );
	return nexxi_dom_remove( $html, 'nx:shop-script' );
}

/** Sort options (Stitch labels) mapped to WooCommerce orderby. */
function nexxi_kids_sort_select( $class, array $labels ) {
	$state = nexxi_kids_shop_state();
	$current = isset( $state['orderby'] ) ? $state['orderby'] : ( ! empty( $state['on_sale'] ) ? ( isset( $labels['sale'] ) ? 'sale' : 'popularity' ) : 'date' );
	$out = '<select class="' . esc_attr( $class ) . '" data-nexxi-sort aria-label="Sắp xếp sản phẩm">';
	foreach ( $labels as $value => $label ) {
		$url = nexxi_kids_shop_link( array( 'orderby' => $value ) );
		$out .= '<option value="' . esc_url( $url ) . '"' . selected( $value === $current, true, false ) . '>' . esc_html( $label ) . '</option>';
	}
	return $out . '</select>';
}

function nexxi_kids_shop_quickfilters() {
	$age_active = 'bg-primary text-on-primary font-label-md text-label-md px-space-md py-1.5 rounded-full shadow-sm hover:scale-105 transition-all';
	$idle = 'bg-surface-container-lowest text-on-surface font-label-md text-label-md px-space-md py-1.5 rounded-full hover:bg-secondary-fixed hover:text-secondary transition-colors';
	$gender_active = 'bg-secondary text-on-secondary font-label-md text-label-md px-space-md py-1.5 rounded-full shadow-sm flex items-center gap-1';
	$style_active = 'shrink-0 bg-secondary-fixed text-on-secondary-fixed font-label-sm text-label-sm px-space-md py-1 rounded-full';
	$style_idle = 'shrink-0 bg-surface-container-lowest hover:bg-surface-container text-on-surface-variant font-label-sm text-label-sm px-space-md py-1 rounded-full transition-colors';

	$state = nexxi_kids_shop_state();
	$groups = nexxi_kids_cat_groups();
	$active_cg = isset( $state['cat_group'] ) ? $state['cat_group'] : '';
	$out = '<div class="flex flex-wrap items-center justify-between gap-space-md w-full"><div class="nexxi-mobile-scroll-row flex items-center gap-space-xs flex-wrap"><span class="font-label-sm text-label-sm text-outline mr-space-xs uppercase tracking-wider shrink-0">Bộ Sưu Tập:</span>';
	$out .= '<a class="' . esc_attr( ! $active_cg && ! ( function_exists( 'is_product_category' ) && is_product_category() ) ? $age_active : $idle ) . ' shrink-0" href="' . esc_url( nexxi_kids_shop_link( array( 'cat_group' => null, 'product_cat' => null ), nexxi_kids_shop_url() ) ) . '">Tất cả</a>';
	foreach ( $groups as $cg_key => $cg_info ) {
		$is_on = ( $active_cg === $cg_key );
		$cg_url = $is_on
			? nexxi_kids_shop_link( array( 'cat_group' => null ), nexxi_kids_shop_url() )
			: nexxi_kids_shop_link( array( 'cat_group' => $cg_key, 'product_cat' => null ), nexxi_kids_shop_url() );
		$out .= '<a class="' . esc_attr( $is_on ? $gender_active : $idle ) . ' shrink-0" href="' . esc_url( $cg_url ) . '">' . esc_html( $cg_info['short'] ) . '</a>';
	}
	$out .= '</div><div class="nexxi-mobile-scroll-row flex items-center gap-space-xs flex-wrap"><span class="font-label-sm text-label-sm text-outline mr-space-xs uppercase tracking-wider shrink-0">Danh Mục:</span>';
	$current = function_exists( 'is_product_category' ) && is_product_category() ? get_queried_object_id() : 0;
	$cats = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => true, 'exclude' => array( (int) get_option( 'default_product_cat' ) ) ) );
	foreach ( is_wp_error( $cats ) ? array() : $cats as $cat ) {
		$active = $current === $cat->term_id;
		$url = $active ? nexxi_kids_shop_link( array(), nexxi_kids_shop_url() ) : nexxi_kids_shop_link( array( 'product_cat' => null ), get_term_link( $cat ) );
		$out .= '<a class="' . esc_attr( $active ? $gender_active : $idle ) . ' shrink-0" href="' . esc_url( $url ) . '">' . ( $active ? '<span class="material-symbols-outlined text-[16px]">child_care</span> ' : '' ) . esc_html( $cat->name ) . '</a>';
	}
	$out .= '</div></div><div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-space-md pt-space-xs"><div class="flex items-center gap-space-xs overflow-x-auto pb-1 max-w-full"><span class="font-label-sm text-label-sm text-outline mr-space-xs shrink-0 uppercase tracking-wider">Kiểu dáng:</span>';
	$styles = nexxi_kids_chosen( 'kieu-dang' );
	foreach ( nexxi_kids_attribute_terms( 'kieu-dang' ) as $t ) {
		$out .= '<a class="' . esc_attr( in_array( $t->slug, $styles, true ) ? $style_active : $style_idle ) . '" href="' . esc_url( nexxi_kids_toggle_link( 'kieu-dang', $t->slug ) ) . '">' . esc_html( $t->name ) . '</a>';
	}
	$out .= '</div><div class="flex items-center gap-space-sm shrink-0 w-full md:w-auto justify-end"><span class="font-label-sm text-label-sm text-outline">Sắp xếp:</span><div class="relative inline-block">';
	$out .= nexxi_kids_sort_select( 'appearance-none bg-surface-container-lowest text-on-surface font-label-md text-label-md py-1.5 pl-space-md pr-space-xl rounded-full shadow-sm focus:outline-none cursor-pointer', array( 'date' => '✨ Mới nhất tuần này', 'popularity' => '🔥 Bán chạy nhất mẹ chọn', 'sale' => '🏷️ Ưu đãi tốt nhất', 'price' => 'Giá tăng dần', 'price-desc' => 'Giá giảm dần' ) );
	return $out . '<span class="material-symbols-outlined text-[18px] text-outline absolute right-space-sm top-1/2 -translate-y-1/2 pointer-events-none">expand_more</span></div></div></div>';
}

/** Checkbox filter group (size / material). */
function nexxi_kids_filter_checks( $slug, $title, $icon ) {
	$terms = nexxi_kids_attribute_terms( $slug );
	if ( ! $terms ) { return ''; }
	$chosen = nexxi_kids_chosen( $slug );
	$out = '<div class="space-y-space-sm pt-space-xs" data-nexxi-filter-group="' . esc_attr( $slug ) . '"><h3 class="font-headline-sm text-headline-sm text-primary flex items-center justify-between">' . esc_html( $title ) . '<span class="material-symbols-outlined text-[18px] text-outline">' . esc_html( $icon ) . '</span></h3><div class="space-y-space-xs pt-1">';
	foreach ( $terms as $index => $t ) {
		$on = in_array( $t->slug, $chosen, true );
		$extra = $index >= 6 && ! $on ? ' hidden" data-nexxi-more="' . esc_attr( $slug ) : '';
		$name = esc_html( $t->name );
		if ( 'size' === $slug ) {
			$weight = Nexxi_Kids_Catalog::size_weight( Nexxi_Kids_Catalog::size_number( $t->slug ) );
			$name .= ' <span class="font-body-sm text-body-sm ' . ( $on ? 'text-secondary/80' : 'text-outline' ) . ' font-normal">(' . esc_html( $weight ) . ')</span>';
		}
		$out .= '<label class="flex items-center justify-between p-space-xs rounded-lg ' . ( $on ? 'bg-secondary-fixed/40' : 'hover:bg-surface-container-low' ) . ' cursor-pointer transition-colors group' . $extra . '"><div class="flex items-center gap-space-sm"><input class="w-4 h-4 rounded text-secondary-container focus:ring-secondary cursor-pointer" type="checkbox" data-nexxi-filter="' . esc_attr( $slug ) . '" value="' . esc_attr( $t->slug ) . '"' . checked( $on, true, false ) . '><span class="font-body-md text-body-md ' . ( $on ? 'text-secondary font-bold' : 'text-on-surface font-semibold' ) . '">' . $name . '</span></div><span class="font-label-sm text-label-sm ' . ( $on ? 'font-bold text-secondary' : 'text-outline-variant group-hover:text-secondary' ) . '">' . (int) $t->count . '</span></label>';
	}
	if ( count( $terms ) > 6 ) { $out .= '<button class="font-label-sm text-label-sm text-secondary hover:underline px-space-xs" type="button" data-nexxi-more-toggle="' . esc_attr( $slug ) . '">Xem thêm ' . ( count( $terms ) - 6 ) . ' lựa chọn</button>'; }
	return $out . '</div></div>';
}

function nexxi_kids_shop_sidebar() {
	$state = nexxi_kids_shop_state();
	$reset = nexxi_kids_shop_link( array_fill_keys( array_diff( array_keys( $state ), array( 's', 'post_type', 'on_sale' ) ), null ) );
	$out = '<div class="nexxi-shop-filter-card bg-surface-container-lowest rounded-lg p-space-lg shadow-[0_8px_24px_-4px_rgba(27,42,89,0.05)] space-y-space-lg"><div class="flex items-center justify-between pb-space-xs"><button type="button" class="nexxi-mobile-filter-btn flex items-center gap-space-xs text-primary font-title-md text-title-md text-left" data-nexxi-mobile-filter-toggle aria-expanded="false"><span class="material-symbols-outlined text-secondary">tune</span><span>Bộ Lọc Cho Mẹ</span><span class="nexxi-mobile-filter-chevron material-symbols-outlined text-[18px] text-outline lg:hidden">expand_more</span></button><a class="font-label-sm text-label-sm text-secondary hover:underline cursor-pointer" href="' . esc_url( $reset ) . '">Thiết lập lại</a></div><div class="nexxi-mobile-filter-body space-y-space-lg" data-nexxi-mobile-filter-body>';
	$out .= nexxi_kids_filter_checks( 'size', 'Size & Cân Nặng Bé', 'straighten' );
	$out .= nexxi_kids_filter_checks( 'chat-lieu', 'Chất Liệu', 'checkroom' );

	$colors = nexxi_kids_attribute_terms( 'mau-sac' );
	if ( $colors ) {
		$chosen = nexxi_kids_chosen( 'mau-sac' );
		$out .= '<div class="space-y-space-sm pt-space-xs"><h3 class="font-headline-sm text-headline-sm text-primary flex items-center justify-between">Màu Sắc Kẹo Ngọt<span class="material-symbols-outlined text-[18px] text-outline">palette</span></h3><div class="grid grid-cols-2 gap-space-xs pt-1">';
		foreach ( $colors as $t ) {
			$on = in_array( $t->slug, $chosen, true );
			$out .= '<a class="flex items-center gap-2 p-1.5 rounded-full ' . ( $on ? 'bg-secondary-fixed text-on-secondary-fixed font-bold' : 'bg-surface-container-low hover:bg-surface-container' ) . ' text-left transition-all" href="' . esc_url( nexxi_kids_toggle_link( 'mau-sac', $t->slug ) ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '"><span class="w-4 h-4 rounded-full shadow-sm shrink-0" style="background:' . esc_attr( Nexxi_Kids_Catalog::color_hex( $t ) ) . '"></span><span class="font-body-sm text-body-sm ' . ( $on ? '' : 'text-on-surface' ) . ' truncate">' . esc_html( $t->name ) . '</span></a>';
		}
		$out .= '</div></div>';
	}

	list( $lo, $hi ) = nexxi_kids_price_bounds();
	$max = isset( $state['max_price'] ) ? min( $hi, max( $lo, (int) $state['max_price'] ) ) : $hi;
	$out .= '<div class="space-y-space-sm pt-space-xs"><div class="flex items-center justify-between"><h3 class="font-headline-sm text-headline-sm text-primary">Khoảng Giá</h3><span class="font-label-sm text-label-sm text-secondary font-bold" data-nexxi-price-label>' . esc_html( nexxi_kids_short_money( $lo ) . ' - ' . nexxi_kids_short_money( $max ) ) . ' đ</span></div><div class="space-y-2"><input class="w-full h-2 bg-surface-container rounded-lg appearance-none cursor-pointer accent-secondary-container" data-nexxi-price data-min="' . (int) $lo . '" max="' . (int) $hi . '" min="' . (int) $lo . '" step="10000" type="range" value="' . (int) $max . '" aria-label="Giá tối đa"><div class="flex items-center justify-between font-label-sm text-label-sm text-outline"><span>' . esc_html( number_format( $lo, 0, ',', '.' ) ) . 'đ</span><span>' . esc_html( number_format( $hi, 0, ',', '.' ) ) . 'đ</span></div></div></div></div></div>';

	$hotline = nexxi_kids_setting( 'nexxi_kids_hotline' );
	$hours   = nexxi_kids_setting( 'nexxi_kids_hours' );
	$chat    = nexxi_kids_setting( 'nexxi_kids_chat_url' );
	$chat    = $chat ? $chat : nexxi_kids_page_url( 'lien-he' );
	$badge_text = $hours ? 'Hỗ trợ ' . $hours : 'Tư vấn chọn size';
	$hotline_html = $hotline ? '<a class="mt-1 inline-flex items-center justify-center gap-1 w-full font-label-sm text-label-sm text-primary hover:text-secondary font-bold transition-colors" href="' . esc_url( nexxi_kids_tel( $hotline ) ) . '"><span class="material-symbols-outlined text-[15px] text-secondary">call</span>Hotline: ' . esc_html( $hotline ) . '</a>' : '';
	$out .= '<div class="relative bg-gradient-to-br from-secondary-fixed to-surface-container-lowest rounded-lg p-space-lg shadow-[0_8px_20px_rgba(253,106,73,0.12)] overflow-hidden"><div class="relative z-10 space-y-space-xs"><span class="inline-block bg-secondary text-on-secondary font-label-sm text-label-sm px-space-xs py-0.5 rounded-full">' . esc_html( $badge_text ) . '</span><h4 class="font-title-md text-title-md text-primary font-bold">Cần Tư Vấn Size Nhanh?</h4><p class="font-body-sm text-body-sm text-on-surface-variant leading-relaxed">Mẹ lăn tăn bé trộm vía bụ bẫm hoặc chiều cao chuẩn? Nhắn Zalo cho NEXXI để được tư vấn ngay!</p><a class="mt-space-sm w-full bg-secondary-container hover:bg-secondary text-on-secondary font-label-md text-label-md py-space-xs rounded-full flex items-center justify-center gap-space-xs shadow-md transition-all hover:scale-[1.02] active:scale-[0.98]" href="' . esc_url( $chat ) . '" target="_blank" rel="noopener"><span class="material-symbols-outlined text-[18px]">forum</span> Nhắn Tin Zalo Tư Vấn</a>' . $hotline_html . '</div><div class="absolute -bottom-6 -right-6 w-24 h-24 rounded-full bg-surface-container-lowest/60 blur-xl"></div></div>';
	return $out;
}

/** Count bar + active filter chips. */
function nexxi_kids_shop_status( $shown, $total ) {
	$chips = array();
	$term = function_exists( 'is_product_category' ) && is_product_category() ? get_queried_object() : null;
	if ( $term instanceof WP_Term ) { $chips[] = array( $term->name, nexxi_kids_shop_link( array(), nexxi_kids_shop_url() ) ); }
	$state = nexxi_kids_shop_state();
	$groups = nexxi_kids_cat_groups();
	if ( ! empty( $state['cat_group'] ) && isset( $groups[ $state['cat_group'] ] ) ) {
		$chips[] = array( $groups[ $state['cat_group'] ]['label'], nexxi_kids_shop_link( array( 'cat_group' => null ), nexxi_kids_shop_url() ) );
	}
	if ( ! empty( $state['s'] ) ) { $chips[] = array( '“' . $state['s'] . '”', nexxi_kids_shop_link( array( 's' => null, 'post_type' => null ) ) ); }
	foreach ( array_keys( Nexxi_Kids_Catalog::attributes() ) as $slug ) {
		foreach ( nexxi_kids_chosen( $slug ) as $value ) {
			$t = get_term_by( 'slug', $value, wc_attribute_taxonomy_name( $slug ) );
			if ( $t ) { $chips[] = array( 'size' === $slug ? $t->name . ' (' . Nexxi_Kids_Catalog::size_weight( Nexxi_Kids_Catalog::size_number( $t->slug ) ) . ')' : $t->name, nexxi_kids_toggle_link( $slug, $value ) ); }
		}
	}
	if ( isset( $state['max_price'] ) ) { $chips[] = array( 'Đến ' . nexxi_kids_money( (int) $state['max_price'] ), nexxi_kids_shop_link( array( 'max_price' => null, 'min_price' => null ) ) ); }

	$out = '<div class="flex items-center gap-space-xs font-body-md text-body-md text-on-surface-variant"><span>Hiển thị</span><strong class="text-primary font-bold">' . (int) $shown . '</strong><span>trên tổng số</span><strong class="text-secondary font-bold">' . (int) $total . '</strong><span>mẫu xinh xắn</span></div><div class="flex items-center gap-space-xs flex-wrap">';
	if ( ! $chips ) {
		return $out . '<span class="font-label-sm text-label-sm text-outline">Đang xem:</span><span class="inline-flex items-center bg-secondary-fixed text-on-secondary-fixed font-label-sm text-label-sm px-space-sm py-1 rounded-full">Tất cả sản phẩm</span></div>';
	}
	$out .= '<span class="font-label-sm text-label-sm text-outline">Đang chọn:</span>';
	foreach ( $chips as $chip ) {
		$out .= '<span class="inline-flex items-center gap-1 bg-secondary-fixed text-on-secondary-fixed font-label-sm text-label-sm px-space-sm py-1 rounded-full">' . esc_html( $chip[0] ) . ' <a class="hover:text-secondary flex items-center" href="' . esc_url( $chip[1] ) . '" aria-label="Bỏ lọc ' . esc_attr( $chip[0] ) . '"><span class="material-symbols-outlined text-[14px]">close</span></a></span>';
	}
	return $out . '<a class="font-label-sm text-label-sm text-outline hover:text-secondary underline ml-1" href="' . esc_url( nexxi_kids_shop_url() ) . '">Xóa tất cả</a></div>';
}

/** Stitch pagination (page window with ellipsis + next button). */
function nexxi_kids_pagination() {
	global $wp_query;
	$pages = max( 1, (int) $wp_query->max_num_pages );
	$page = max( 1, (int) get_query_var( 'paged' ) );
	$active = 'w-10 h-10 rounded-full bg-secondary-container text-on-secondary font-label-md text-label-md flex items-center justify-center shadow-[0_4px_12px_rgba(253,106,73,0.35)] font-bold';
	$idle = 'w-10 h-10 rounded-full bg-surface-container-lowest text-on-surface hover:bg-surface-container font-label-md text-label-md flex items-center justify-center shadow-sm transition-colors';
	$out = '<nav data-nx="shop-pagination" aria-label="Pagination" class="pt-space-xl flex flex-col sm:flex-row items-center justify-between gap-space-md"><div class="font-body-sm text-body-sm text-outline">Hiển thị trang ' . $page . ' trong ' . $pages . ' trang danh mục</div>';
	if ( $pages <= 1 ) { return $out . '</nav>'; }
	$out .= '<div class="flex items-center gap-space-xs">';
	if ( $page > 1 ) { $out .= '<a class="px-space-md h-10 rounded-full bg-surface-container-lowest hover:bg-surface-container text-primary font-label-md text-label-md flex items-center gap-1 shadow-sm transition-colors" href="' . esc_url( get_pagenum_link( $page - 1 ) ) . '"><span class="material-symbols-outlined text-[18px]">arrow_back</span><span>Trang Trước</span></a>'; }
	$last = 0;
	for ( $i = 1; $i <= $pages; $i++ ) {
		if ( 1 !== $i && $pages !== $i && abs( $i - $page ) > 1 ) { continue; }
		if ( $last && $i - $last > 1 ) { $out .= '<span class="px-1 text-outline font-bold">...</span>'; }
		$out .= $i === $page ? '<span class="' . esc_attr( $active ) . '" aria-current="page">' . $i . '</span>' : '<a class="' . esc_attr( $idle ) . '" href="' . esc_url( get_pagenum_link( $i ) ) . '">' . $i . '</a>';
		$last = $i;
	}
	if ( $page < $pages ) { $out .= '<a class="px-space-md h-10 rounded-full bg-surface-container-lowest hover:bg-surface-container text-primary font-label-md text-label-md flex items-center gap-1 shadow-sm transition-colors" href="' . esc_url( get_pagenum_link( $page + 1 ) ) . '"><span>Tiếp Theo</span><span class="material-symbols-outlined text-[18px]">arrow_forward</span></a>'; }
	return $out . '</div></nav>';
}

/** Flash Sale screen: live sale products, category chips, campaign stats. */
function nexxi_kids_stitch_flash( $html ) {
	global $wp_query;
	$html = nexxi_dom_remove( $html, 'data-nxr="flash-steps"' );
	$products = nexxi_kids_loop_products();
	$cards = '';
	foreach ( $products as $product ) { $cards .= nexxi_kids_card_flash_page( $product ); }
	$html = nexxi_dom_set_inner( $html, 'nx:flash-grid', $cards ? $cards : nexxi_kids_empty_state( 'Khung giờ này đã hết deal', 'Mẹ quay lại khung giờ sau hoặc xem các mẫu mới nhé.', 'Xem tất cả sản phẩm', nexxi_kids_shop_url() ) );

	// Category chips limited to categories that currently have Flash Sale products.
	$sale_products = nexxi_kids_flash_sale_parent_ids();
	$current = function_exists( 'is_product_category' ) && is_product_category() ? get_queried_object_id() : 0;
	$active = 'filter-btn active bg-primary text-on-primary px-space-md py-2 rounded-full transition-all shadow-sm flex items-center gap-1.5';
	$idle = 'filter-btn bg-surface-container hover:bg-surface-container-high text-on-surface-variant px-space-md py-2 rounded-full transition-all flex items-center gap-1';
	$chips = '<a class="' . esc_attr( $current ? $idle : $active ) . '" href="' . esc_url( nexxi_kids_flash_url() ) . '"><span>Tất Cả Deal Hot</span><span class="text-xs bg-secondary-container text-on-secondary px-1.5 py-0.5 rounded-full font-bold">' . count( $sale_products ) . '</span></a>';
	$cats = $sale_products ? wp_get_object_terms( $sale_products, 'product_cat' ) : array();
	$seen = array();
	foreach ( is_wp_error( $cats ) ? array() : $cats as $cat ) {
		if ( isset( $seen[ $cat->term_id ] ) ) { continue; }
		$seen[ $cat->term_id ] = true;
		$chips .= '<a class="' . esc_attr( $current === $cat->term_id ? $active : $idle ) . '" href="' . esc_url( add_query_arg( 'on_sale', '1', get_term_link( $cat ) ) ) . '"><span>' . esc_html( $cat->name ) . '</span></a>';
	}
	$html = nexxi_dom_set_inner( $html, 'nx:flash-filters', $chips );

	// Live campaign stats.
	$sold = 0; $left = 0;
	foreach ( $sale_products as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) { continue; }
		$sold += (int) $product->get_total_sales();
		$left += (int) Nexxi_Kids_Catalog::stock_left( $product );
	}
	$percent = ( $sold + $left ) > 0 ? (int) round( $sold / ( $sold + $left ) * 100 ) : 0;
	$html = nexxi_dom_set_inner( $html, 'nx:flash-claimed', '<span class="material-symbols-outlined text-[16px] text-secondary">local_mall</span> Đã chốt thành công: ' . esc_html( number_format( $sold, 0, ',', '.' ) . ' / ' . number_format( $sold + $left, 0, ',', '.' ) ) . ' món' );
	$html = nexxi_dom_set_inner( $html, 'nx:flash-claimed-percent', $percent . '% Suất Ưu Đãi' );
	$html = nexxi_dom_set_attr( $html, 'nx:flash-claimed-bar', 'style', 'width: ' . $percent . '%' );
	$html = nexxi_dom_set_inner( $html, 'nx:flash-ticker', '<span class="inline-block w-2 h-2 rounded-full bg-secondary-container animate-ping"></span><span class="font-label-sm text-label-sm">' . count( $sale_products ) . ' mẫu ưu đãi đang mở bán</span>' );
	$flash_ts = nexxi_kids_flash_end();
	if ( $flash_ts > time() ) {
		$end = new DateTimeImmutable( '@' . $flash_ts );
		$html = nexxi_dom_set_inner( $html, 'nx:flash-slot', 'Kết thúc lúc ' . esc_html( $end->setTimezone( wp_timezone() )->format( 'H:i - d/m' ) ) );
	} else {
		$html = nexxi_dom_set_inner( $html, 'nx:flash-slot', 'Chưa mở khung giờ Flash Sale' );
	}

	// Load more → next page of deals, or hide when everything is shown.
	$page = max( 1, (int) get_query_var( 'paged' ) );
	$remaining = max( 0, (int) $wp_query->found_posts - $page * nexxi_kids_loop_per_page() );
	if ( $remaining > 0 ) {
		$html = nexxi_dom_set_outer( $html, 'nx:flash-more', '<a class="bg-surface-container-lowest hover:bg-surface-container text-primary font-bold px-space-xl py-space-sm rounded-full shadow-sm hover:shadow-md transition-all flex items-center gap-2 font-label-lg text-label-lg" href="' . esc_url( get_pagenum_link( $page + 1 ) ) . '"><span>Xem Thêm ' . (int) $remaining . ' Deal Săn Giờ Vàng Đang Mở</span><span class="material-symbols-outlined text-[20px]">expand_more</span></a>' );
	} else {
		$html = nexxi_dom_remove( $html, 'nx:flash-more' );
	}

	// Sort select keeps the Stitch labels but uses WooCommerce ordering.
	$select = nexxi_dom_locate( $html, '<select' );
	if ( $select && $select['start'] > strpos( $html, 'data-nx="flash-filters"' ) ) {
		$replacement = nexxi_kids_sort_select( 'bg-surface-container-low text-primary font-bold px-space-md py-2 rounded-full outline-none focus:bg-surface-container cursor-pointer transition-colors border-none text-label-sm', array( 'popularity' => '🔥 Bán chạy nhất hôm nay', 'price' => '💰 Giá từ thấp đến cao', 'price-desc' => '💎 Giá từ cao đến thấp', 'date' => '✨ Deal mới lên kệ' ) );
		$html = substr( $html, 0, $select['start'] ) . $replacement . substr( $html, $select['end'] );
	}
	return $html;
}
