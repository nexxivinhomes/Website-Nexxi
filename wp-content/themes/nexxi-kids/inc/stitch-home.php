<?php
/** Home screen: live WooCommerce rails inside the original Stitch composition. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Category slugs in the order of the four Stitch "Chọn đồ theo độ tuổi" cards. */
function nexxi_kids_home_category_slugs() {
	return apply_filters( 'nexxi_kids_home_category_slugs', array( 'set-bo', 'dam-vay', 'quan-xinh', 'ao-thoi-trang' ) );
}

function nexxi_kids_stitch_home( $html ) {
	$hero_order  = nexxi_studio_hero_slide_order();
	$slides_html = '';
	$first_found = false;
	foreach ( $hero_order as $idx => $slot ) {
		$url = nexxi_studio_hero_slide_url( $slot );
		$cls = 'nexxi-hero-bg__slide';
		if ( '' === $url ) {
			$cls .= ' is-empty';
		} elseif ( ! $first_found ) {
			$cls .= ' is-active';
			$first_found = true;
		}
		$loading = ( 0 === $idx ) ? ' loading="eager" fetchpriority="high"' : ' loading="lazy"';
		$slides_html .= '<div class="' . esc_attr( $cls ) . '" data-hero-slide="' . (int) $idx . '" data-hero-slot="' . (int) $slot . '">'
			. '<img data-nxi="home-hero__bg' . (int) $slot . '" class="nexxi-hero-bg__img" src="' . esc_url( $url ) . '" alt="Banner NEXXI ' . (int) $slot . '"' . $loading . '>'
			. '</div>';
	}
	$html = nexxi_dom_set_attr( $html, 'data-nexxi-hero-slider', 'data-order', implode( ',', $hero_order ) );
	$html = nexxi_dom_set_inner( $html, '<div class="nexxi-hero-bg__slides">', $slides_html );
	// Hero CTAs, category cards and lookbook buttons.
	$html = nexxi_dom_set_attr( $html, 'nx:home-cta-shop', 'href', nexxi_kids_shop_url() );
	$html = nexxi_dom_set_attr( $html, 'nx:home-cta-account', 'href', wc_get_page_permalink( 'myaccount' ) );
	foreach ( nexxi_kids_home_category_slugs() as $index => $slug ) {
		$html = nexxi_dom_set_attr( $html, 'nx:home-cat-' . ( $index + 1 ), 'href', add_query_arg( 'cat_group', $slug, nexxi_kids_shop_url() ) );
	}
	$html = nexxi_dom_set_attr( $html, 'nx:home-lookbook-1', 'href', add_query_arg( 'cat_group', 'dam-vay', nexxi_kids_shop_url() ) );
	$html = nexxi_dom_set_attr( $html, 'nx:home-lookbook-2', 'href', add_query_arg( 'cat_group', 'set-bo', nexxi_kids_shop_url() ) );

	// Flash Sale rail.
	$sale = nexxi_kids_sale_products( 4 );
	$flash = '';
	foreach ( $sale as $product ) { $flash .= nexxi_kids_card_home_flash( $product ); }
	$html = nexxi_dom_set_inner( $html, 'nx:home-flash-grid', $flash ? $flash : nexxi_kids_empty_state( 'Flash Sale sắp quay trở lại', 'Mẹ quay lại sau để săn deal xinh cho bé nhé!', 'Xem tất cả sản phẩm', nexxi_kids_shop_url() ) );

	// Best sellers + category tabs.
	$best = nexxi_kids_query_products( array( 'limit' => 10, 'orderby' => 'popularity', 'order' => 'DESC' ) );
	$grid = '';
	foreach ( $best as $rank => $product ) { $grid .= nexxi_kids_card_home_bestseller( $product, $rank + 1 ); }
	$html = nexxi_dom_set_inner( $html, 'nx:home-bestsellers', $grid ? $grid : nexxi_kids_empty_state( 'Sản phẩm đang được cập nhật', 'Các mẫu mới sẽ sớm có mặt.' ) );
	$html = nexxi_dom_set_inner( $html, 'nx:home-tabs', nexxi_kids_home_tabs( $best ) );

	// Extra reference sections + size guide.
	$lookbook = nexxi_dom_get_outer( $html, 'data-nxr="home-lookbook"' );
	if ( $lookbook ) { $html = nexxi_dom_remove( $html, 'data-nxr="home-lookbook"' ); }
	$sections = nexxi_kids_home_commitments() . nexxi_kids_home_new_arrivals() . nexxi_kids_size_guide_section() . nexxi_kids_home_testimonials() . $lookbook;
	$main = nexxi_dom_locate( $html, '<main' );
	if ( $main ) {
		$html = substr( $html, 0, $main['inner_end'] ) . $sections . substr( $html, $main['inner_end'] );
	}
	return $html;
}

function nexxi_kids_home_tabs( array $products = array() ) {
	$url = add_query_arg( 'orderby', 'popularity', nexxi_kids_shop_url() );
	return '<a class="nexxi-btn-view-all group inline-flex items-center gap-2 px-space-lg py-2.5 rounded-full font-label-md text-label-md font-bold transition-all duration-300" href="' . esc_url( $url ) . '"><span class="material-symbols-outlined text-[18px] transition-transform duration-300 group-hover:scale-110">local_fire_department</span><span>Xem tất cả sản phẩm bán chạy →</span></a>';
}

function nexxi_kids_home_commitments() {
	$out = '<section class="w-full px-margin-mobile md:px-margin py-space-xl bg-surface-container-low/40" id="about"' . nexxi_studio_region_attr( 'home-commit' ) . '><div class="text-center mb-space-lg"><span class="font-label-sm text-label-sm text-secondary bg-secondary-fixed px-space-md py-1 rounded-full"' . nexxi_studio_attr( 'home-commit__t1' ) . '>' . nexxi_studio_t( 'home-commit__t1' ) . '</span><h2 class="font-headline-lg text-headline-lg text-primary mt-space-sm"' . nexxi_studio_attr( 'home-commit__t2' ) . '>' . nexxi_studio_t( 'home-commit__t2' ) . '</h2><p class="font-body-sm text-body-sm text-on-surface-variant mt-space-xs"' . nexxi_studio_attr( 'home-commit__t3' ) . '>' . nexxi_studio_t( 'home-commit__t3' ) . '</p></div><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-md">';
	for ( $n = 1; $n <= 4; $n++ ) {
		$k = 'home-commit__c' . $n;
		$out .= '<div class="bg-surface-container-lowest rounded-DEFAULT p-space-md text-center"><span class="material-symbols-outlined text-secondary text-[26px]"' . nexxi_studio_attr( $k . 'i' ) . '>' . nexxi_studio_t( $k . 'i' ) . '</span><h3 class="font-label-lg text-label-lg text-primary mt-space-xs"' . nexxi_studio_attr( $k . 't' ) . '>' . nexxi_studio_t( $k . 't' ) . '</h3><p class="font-body-sm text-body-sm text-on-surface-variant mt-1"' . nexxi_studio_attr( $k . 'd' ) . '>' . nexxi_studio_t( $k . 'd' ) . '</p></div>';
	}
	return $out . '</div></section>';
}

function nexxi_kids_home_new_arrivals() {
	$products = nexxi_kids_query_products( array( 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC' ) );
	if ( ! $products ) { return ''; }
	$cards = '';
	foreach ( $products as $product ) { $cards .= nexxi_kids_card_new_arrival( $product ); }
	return '<section class="w-full px-margin-mobile md:px-margin py-space-xl"' . nexxi_studio_region_attr( 'home-new' ) . '><div class="flex flex-wrap items-end justify-between gap-space-md mb-space-lg"><div><span class="inline-flex items-center gap-1 px-space-md py-1 rounded-full bg-secondary-fixed text-on-secondary-fixed font-label-sm text-label-sm uppercase mb-1.5"' . nexxi_studio_attr( 'home-new__t1' ) . '>' . nexxi_studio_t( 'home-new__t1' ) . '</span><h2 class="font-headline-lg text-headline-lg text-primary"' . nexxi_studio_attr( 'home-new__t2' ) . '>' . nexxi_studio_t( 'home-new__t2' ) . '</h2></div><a class="nexxi-btn-view-all group inline-flex items-center gap-2 px-space-lg py-2.5 rounded-full font-label-md text-label-md font-bold transition-all duration-300" href="' . esc_url( add_query_arg( 'orderby', 'date', nexxi_kids_shop_url() ) ) . '"><span class="material-symbols-outlined text-[18px] transition-transform duration-300 group-hover:rotate-12">auto_awesome</span><span' . nexxi_studio_attr( 'home-new__t3' ) . '>' . nexxi_studio_t( 'home-new__t3' ) . '</span></a></div><div class="nexxi-home-new-grid grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-space-md">' . $cards . '</div></section>';
}

/** Latest approved 4–5★ product reviews; Stitch sample quotes when the shop has none yet. */
function nexxi_kids_home_testimonials() {
	$quotes = array();
	$comments = get_comments( array( 'post_type' => 'product', 'status' => 'approve', 'number' => 3, 'meta_query' => array( array( 'key' => 'rating', 'value' => 4, 'compare' => '>=', 'type' => 'NUMERIC' ) ) ) );
	foreach ( $comments as $comment ) {
		$quotes[] = array( (int) get_comment_meta( $comment->comment_ID, 'rating', true ), wp_trim_words( $comment->comment_content, 30 ), $comment->comment_author, get_the_title( $comment->comment_post_ID ) );
	}
	if ( ! $quotes ) {
		$quotes = array(
			array( 5, 'Chất vải rất mềm, bé nhà mình mặc cả ngày vẫn thoải mái. Mẫu xinh đúng như hình.', 'Mẹ Thùy Chi', '' ),
			array( 5, 'Size chuẩn, đóng gói gọn gàng. Mình đã quay lại chọn thêm vài bộ cho bé.', 'Mẹ Mai Anh', '' ),
			array( 5, 'Màu sắc dịu và thiết kế đáng yêu. Bé rất thích chiếc váy mới này.', 'Mẹ Bảo Ngọc', '' ),
		);
	}
	$out = '<section class="w-full px-margin-mobile md:px-margin pb-space-xl"' . nexxi_studio_region_attr( 'home-reviews' ) . '><div class="text-center mb-space-lg"><span class="font-label-sm text-label-sm text-secondary"' . nexxi_studio_attr( 'home-reviews__t1' ) . '>' . nexxi_studio_t( 'home-reviews__t1' ) . '</span><h2 class="font-headline-lg text-headline-lg text-primary mt-space-xs"' . nexxi_studio_attr( 'home-reviews__t2' ) . '>' . nexxi_studio_t( 'home-reviews__t2' ) . '</h2></div><div class="grid grid-cols-1 md:grid-cols-3 gap-space-md">';
	foreach ( $quotes as $quote ) {
		$out .= '<div class="bg-surface-container-low rounded-DEFAULT p-space-md"><div class="text-secondary text-[15px]">' . str_repeat( '★', max( 1, min( 5, $quote[0] ) ) ) . '</div><p class="font-body-sm text-body-sm text-on-surface-variant mt-space-sm">“' . esc_html( $quote[1] ) . '”</p><p class="font-label-sm text-label-sm text-primary mt-space-md">' . esc_html( $quote[2] ) . ( $quote[3] ? ' <span class="text-outline font-normal">· ' . esc_html( $quote[3] ) . '</span>' : '' ) . '</p></div>';
	}
	return $out . '</div></section>';
}

/** Size guide section (#size-guide) with the 1–16 chart and the size advisor. */
function nexxi_kids_size_guide_section() {
	$chart = class_exists( 'Nexxi_Kids_Catalog' ) ? Nexxi_Kids_Catalog::size_chart() : array();
	$table = static function ( $rows ) {
		$out = '<table class="w-full text-center font-body-md text-body-md"><thead><tr class="bg-secondary-fixed text-on-secondary-fixed font-label-md text-label-md"><th class="py-2 rounded-l-full">Size</th><th class="py-2">Cân nặng (kg)</th><th class="py-2 rounded-r-full">Chiều cao (cm)</th></tr></thead><tbody>';
		foreach ( $rows as $size => $row ) {
			$out .= '<tr class="border-b border-surface-container-high transition-colors" data-size-row="' . (int) $size . '"><td class="py-2 font-label-lg text-label-lg text-primary">' . (int) $size . '</td><td class="py-2 text-on-surface-variant">' . (int) $row[0] . '–' . (int) $row[1] . '</td><td class="py-2 text-on-surface-variant">' . (int) $row[2] . '</td></tr>';
		}
		return $out . '</tbody></table>';
	};
	ob_start(); ?>
<section class="w-full px-margin-mobile md:px-margin py-space-xl scroll-mt-32" id="size-guide"<?php echo nexxi_studio_region_attr( 'home-size' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
<div class="rounded-3xl bg-surface-container-low p-space-md md:p-space-lg shadow-sm">
<div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md mb-space-lg">
<div><div class="inline-flex items-center gap-1.5 px-space-md py-1 rounded-full bg-secondary-fixed text-on-secondary-fixed font-label-sm text-label-sm uppercase mb-2"><span class="material-symbols-outlined text-[16px]">straighten</span><span data-nxe="home-size__t1"><?php echo nexxi_studio_t( 'home-size__t1' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></div>
<h2 class="font-headline-lg text-headline-lg text-primary" data-nxe="home-size__t2"><?php echo nexxi_studio_t( 'home-size__t2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
<p class="font-body-md text-body-md text-on-surface-variant mt-space-xs" data-nxe="home-size__t3"><?php echo nexxi_studio_t( 'home-size__t3' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p></div>
</div>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-space-lg items-start">
<div class="lg:col-span-4 bg-secondary-fixed/40 rounded-xl p-space-md space-y-space-sm" data-nexxi-size-advisor>
<div class="flex items-center justify-between"><div class="flex items-center gap-space-xs"><div class="w-8 h-8 rounded-full bg-secondary-container text-on-secondary flex items-center justify-center"><span class="material-symbols-outlined text-[18px]">smart_toy</span></div><div><h3 class="font-label-lg text-label-lg text-primary">Trợ Lý Gợi Ý Size Bé Yêu</h3><p class="font-body-sm text-body-sm text-outline">Nhập cân nặng &amp; chiều cao của bé</p></div></div><span class="material-symbols-outlined text-secondary text-[20px]">auto_awesome</span></div>
<div class="grid grid-cols-2 gap-space-sm pt-space-xs">
<label class="block"><span class="block font-label-sm text-label-sm text-on-surface-variant mb-1">Cân nặng bé (kg):</span><span class="relative flex items-center"><input class="w-full bg-surface-container-lowest px-space-md py-2 rounded-lg font-title-md text-title-md text-primary outline-none focus:ring-2 focus:ring-secondary-container" data-advisor-weight max="55" min="8" step="0.5" type="number" value="13" inputmode="decimal"><span class="absolute right-3 font-label-sm text-label-sm text-outline">kg</span></span></label>
<label class="block"><span class="block font-label-sm text-label-sm text-on-surface-variant mb-1">Chiều cao bé (cm):</span><span class="relative flex items-center"><input class="w-full bg-surface-container-lowest px-space-md py-2 rounded-lg font-title-md text-title-md text-primary outline-none focus:ring-2 focus:ring-secondary-container" data-advisor-height max="170" min="75" step="1" type="number" value="96" inputmode="numeric"><span class="absolute right-3 font-label-sm text-label-sm text-outline">cm</span></span></label>
</div>
<div class="bg-surface-container-lowest rounded-lg p-space-sm flex items-center gap-space-xs"><span class="material-symbols-outlined text-secondary text-[22px]">thumb_up</span><div><p class="font-label-md text-label-md text-primary" data-advisor-title>Khuyên dùng: <strong class="text-secondary font-bold">Size 3</strong></p><p class="font-body-sm text-body-sm text-on-surface-variant" data-advisor-desc>Mẹ có thể chọn size lớn hơn nếu bé nằm giữa hai size.</p></div></div>
<a class="w-full inline-flex items-center justify-center gap-1 px-space-md py-2.5 rounded-full bg-secondary text-on-secondary font-label-md text-label-md hover:bg-secondary-container transition-all" data-advisor-link href="<?php echo esc_url( nexxi_kids_shop_url() ); ?>"><span class="material-symbols-outlined text-[18px]">checkroom</span> Xem đồ vừa size của bé</a>
</div>
<div class="lg:col-span-8 bg-surface-container-lowest rounded-xl p-space-md shadow-sm grid grid-cols-1 md:grid-cols-2 gap-space-md overflow-x-auto">
<?php echo $table( array_slice( $chart, 0, 8, true ) ) . $table( array_slice( $chart, 8, null, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
</div>
</div>
</section>
	<?php
	return ob_get_clean();
}
