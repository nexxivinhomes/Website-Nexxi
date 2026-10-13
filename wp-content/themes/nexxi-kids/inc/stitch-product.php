<?php
/** Product screen: Stitch product layout rendered from the live WooCommerce product. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function nexxi_kids_current_product() {
	return function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;
}

/** Product + variation data for stitch-interactions.js. */
function nexxi_kids_product_config( WC_Product $product ) {
	$sizes = array();
	foreach ( Nexxi_Kids_Catalog::product_terms( $product, 'size' ) as $term ) {
		$n = Nexxi_Kids_Catalog::size_number( $term->slug );
		$sizes[] = array( 'slug' => $term->slug, 'name' => $term->name, 'number' => $n, 'weight' => Nexxi_Kids_Catalog::size_weight( $n ) );
	}
	$colors = array();
	foreach ( Nexxi_Kids_Catalog::product_terms( $product, 'mau-sac' ) as $term ) {
		$colors[] = array( 'slug' => $term->slug, 'name' => $term->name, 'hex' => Nexxi_Kids_Catalog::color_hex( $term ) );
	}
	$image = wp_get_attachment_image_url( $product->get_image_id(), 'full' );
	return array(
		'id'         => $product->get_id(),
		'type'       => $product->get_type(),
		'name'       => $product->get_name(),
		'url'        => $product->get_permalink(),
		'image'      => $image ? $image : wc_placeholder_img_src( 'full' ),
		'sizes'      => $product->is_type( 'variable' ) ? $sizes : array(),
		'colors'     => $product->is_type( 'variable' ) ? $colors : array(),
		'variations' => Nexxi_Kids_Catalog::variation_payload( $product ),
		'price'      => (float) wc_get_price_to_display( $product ),
		'regular'    => (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ),
		'inStock'    => $product->is_in_stock(),
		'max'        => $product->get_max_purchase_quantity(),
	);
}

function nexxi_kids_stitch_product( $html ) {
	$product = nexxi_kids_current_product();
	if ( ! $product ) { return $html; }
	$gallery_inner = nexxi_dom_get_inner( $html, 'nx:pdp-gallery' );
	$panel_inner = nexxi_dom_get_inner( $html, 'nx:pdp-panel' );

	$html = nexxi_dom_set_inner( $html, 'nx:pdp-meta', nexxi_kids_pdp_meta( $product ) );
	$html = nexxi_dom_set_inner( $html, 'nx:pdp-gallery', nexxi_kids_pdp_gallery( $product, nexxi_kids_tail_from( $gallery_inner, '<!-- Micro Feature Cards' ) ) );
	$html = nexxi_dom_set_inner( $html, 'nx:pdp-panel', nexxi_kids_pdp_panel( $product, nexxi_kids_tail_from( $panel_inner, '<!-- Boutique Service Commitments' ) ) );
	$html = nexxi_dom_set_inner( $html, 'nx:pdp-tabs', nexxi_kids_pdp_tabs( $product ) );
	$related = nexxi_kids_pdp_related( $product );
	$html = $related ? nexxi_dom_set_inner( $html, 'nx:pdp-related', $related ) : nexxi_dom_set_outer( $html, 'nx:pdp-related', '' );
	return nexxi_dom_remove( $html, 'nx:pdp-script' );
}

/** Keep a static Stitch block (from a comment marker to the end of the container). */
function nexxi_kids_tail_from( $inner, $marker ) {
	$pos = strpos( $inner, $marker );
	return false === $pos ? '' : substr( $inner, $pos );
}

function nexxi_kids_stars( $rating, $size = 18 ) {
	$out = '';
	for ( $i = 1; $i <= 5; $i++ ) {
		$fill = $rating >= $i - 0.25 ? 1 : 0;
		$out .= '<span class="material-symbols-outlined text-[' . (int) $size . 'px]" style="font-variation-settings: \'FILL\' ' . $fill . ';">' . ( $fill || $rating < $i - 0.75 ? 'star' : 'star_half' ) . '</span>';
	}
	return $out;
}

function nexxi_kids_pdp_meta( WC_Product $product ) {
	$cat = nexxi_kids_product_category( $product );
	$crumbs = '<a class="hover:text-secondary transition-colors flex items-center gap-1" href="' . esc_url( home_url( '/' ) ) . '"><span class="material-symbols-outlined text-[16px]">home</span> Trang Chủ</a><span class="opacity-50">/</span><a class="hover:text-secondary transition-colors" href="' . esc_url( nexxi_kids_shop_url() ) . '">Cửa Hàng</a><span class="opacity-50">/</span>';
	if ( $cat ) {
		if ( $cat->parent ) {
			$parent = get_term( $cat->parent, 'product_cat' );
			if ( $parent && ! is_wp_error( $parent ) ) { $crumbs .= '<a class="hover:text-secondary transition-colors" href="' . esc_url( get_term_link( $parent ) ) . '">' . esc_html( $parent->name ) . '</a><span class="opacity-50">/</span>'; }
		}
		$crumbs .= '<a class="hover:text-secondary transition-colors" href="' . esc_url( get_term_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a><span class="opacity-50">/</span>';
	}
	$crumbs .= '<span class="text-primary font-bold line-clamp-1">' . esc_html( $product->get_name() ) . '</span>';
	$meta = array();
	if ( $product->get_total_sales() >= 10 ) { $meta[] = '<span class="flex items-center gap-1 text-secondary font-bold"><span class="material-symbols-outlined text-[16px]">local_fire_department</span> Mẫu bán chạy</span>'; }
	if ( $product->get_sku() ) { $meta[] = '<span>Mã SKU: ' . esc_html( $product->get_sku() ) . '</span>'; }
	return '<div class="flex flex-wrap items-center justify-between gap-space-sm text-outline font-label-md text-label-md"><nav aria-label="Breadcrumb" class="flex items-center flex-wrap gap-space-xs text-on-surface-variant">' . $crumbs . '</nav><div class="hidden sm:flex items-center gap-space-md text-body-sm font-body-sm text-outline">' . implode( '<span>•</span>', $meta ) . '</div></div>';
}

function nexxi_kids_pdp_gallery( WC_Product $product, $features ) {
	$ids = array_values( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) );
	$main = $ids ? wp_get_attachment_image_url( $ids[0], 'full' ) : wc_placeholder_img_src( 'full' );
	$materials = nexxi_kids_product_attr_names( $product, 'chat-lieu' );
	$discount = Nexxi_Kids_Catalog::discount_percent( $product );
	ob_start(); ?>
<div class="relative w-full aspect-[4/5] bg-surface-container-low rounded-xl overflow-hidden shadow-[0_8px_24px_-4px_rgba(27,42,89,0.06)] group">
<img class="w-full h-full object-cover transition-all duration-700 group-hover:scale-105" id="mainProductImg" src="<?php echo esc_url( $main ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>">
<div class="absolute top-space-md left-space-md flex flex-col gap-space-xs items-start pointer-events-none">
<?php if ( $materials ) : ?><span class="bg-surface-container-lowest/90 backdrop-blur-md text-primary font-label-sm text-label-sm px-space-md py-space-xs rounded-full shadow-sm flex items-center gap-1.5 transform -rotate-2"><span class="material-symbols-outlined text-secondary text-[16px]">eco</span><?php echo esc_html( implode( ' · ', $materials ) ); ?></span><?php endif; ?>
<?php if ( $discount ) : ?><span class="bg-secondary-fixed text-on-secondary-fixed font-label-sm text-label-sm px-space-md py-space-xs rounded-full shadow-sm flex items-center gap-1.5 transform rotate-1"><span class="material-symbols-outlined text-secondary text-[16px]">sell</span>Ưu đãi đến -<?php echo (int) $discount; ?>%</span><?php endif; ?>
</div>
<div class="absolute top-space-md right-space-md flex flex-col gap-space-xs">
<button class="w-10 h-10 rounded-full bg-surface-container-lowest/90 backdrop-blur-md text-secondary hover:bg-secondary hover:text-on-secondary transition-all flex items-center justify-center shadow-md" <?php echo nexxi_kids_wishlist_attrs( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><span class="material-symbols-outlined text-[20px]">favorite_border</span></button>
<a class="w-10 h-10 rounded-full bg-surface-container-lowest/90 backdrop-blur-md text-primary hover:bg-primary hover:text-on-primary transition-all flex items-center justify-center shadow-md" href="<?php echo esc_url( $main ); ?>" target="_blank" rel="noopener" data-nexxi-zoom aria-label="Phóng to ảnh"><span class="material-symbols-outlined text-[20px]">zoom_in</span></a>
</div>
<div class="absolute bottom-space-md left-space-md right-space-md flex items-center justify-between bg-surface-container-lowest/80 backdrop-blur-md px-space-md py-space-xs rounded-full text-on-surface-variant font-label-sm text-label-sm"><span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px] text-secondary">check_circle</span> Ảnh thật chụp tại Studio NEXXI Baby</span><span class="text-outline"><?php echo count( $ids ) > 1 ? esc_html( count( $ids ) . ' ảnh' ) : 'Chạm để phóng to'; ?></span></div>
</div>
<?php if ( count( $ids ) > 1 ) : ?>
<div class="grid grid-cols-4 gap-space-sm sm:gap-space-md">
<?php foreach ( array_slice( $ids, 0, 8 ) as $index => $id ) :
	$caption = wp_get_attachment_caption( $id ); ?>
<button class="thumbnail-btn relative aspect-square rounded-lg overflow-hidden bg-surface-container <?php echo 0 === $index ? 'ring-2 ring-secondary-container' : 'opacity-70 hover:opacity-100'; ?> transition-all shadow-sm" type="button" data-nexxi-thumb="<?php echo esc_url( wp_get_attachment_image_url( $id, 'full' ) ); ?>" aria-label="Xem ảnh <?php echo (int) $index + 1; ?>">
<img class="w-full h-full object-cover" src="<?php echo esc_url( wp_get_attachment_image_url( $id, 'woocommerce_thumbnail' ) ); ?>" alt="" loading="lazy">
<?php if ( $caption ) : ?><span class="absolute bottom-1 inset-x-1 text-center bg-surface-container-lowest/90 rounded text-[10px] font-label-sm text-primary py-0.5 line-clamp-1"><?php echo esc_html( $caption ); ?></span><?php endif; ?>
</button>
<?php endforeach; ?>
</div>
<?php endif; ?>
	<?php
	return ob_get_clean() . $features;
}

function nexxi_kids_pdp_panel( WC_Product $product, $services ) {
	$config = nexxi_kids_product_config( $product );
	$cat = nexxi_kids_product_category( $product );
	$left = Nexxi_Kids_Catalog::stock_left( $product );
	$range = nexxi_kids_price_range( $product );
	$discount = Nexxi_Kids_Catalog::discount_percent( $product );
	$reviews = (int) $product->get_review_count();
	$rating = (float) $product->get_average_rating();
	$sold = (int) $product->get_total_sales();
	if ( ! $product->is_in_stock() ) {
		$stock = 'Tạm hết hàng';
	} elseif ( null !== $left && $left <= 10 ) {
		$stock = 'Chỉ còn ' . $left . ' chiếc';
	} else {
		$stock = 'Còn hàng – giao ngay';
	}
	$price = $range['min'] < $range['max'] ? 'Từ ' . nexxi_kids_money( $range['min'] ) : nexxi_kids_money( $range['min'] );
	$advisor_title = 'Nhập cân nặng &amp; chiều cao để mẹ an tâm vừa vặn';
	ob_start(); ?>
<div class="space-y-space-xs">
<div class="flex items-center gap-space-xs flex-wrap">
<?php if ( $cat ) : ?><span class="bg-secondary-fixed text-on-secondary-fixed font-label-sm text-label-sm px-space-sm py-0.5 rounded-full uppercase tracking-wider"><?php echo esc_html( $cat->name ); ?></span><?php endif; ?>
<span class="bg-tertiary-fixed text-on-tertiary-fixed font-label-sm text-label-sm px-space-sm py-0.5 rounded-full flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-secondary-container animate-pulse"></span><span data-nexxi-stock><?php echo esc_html( $stock ); ?></span></span>
</div>
<h1 class="font-headline-lg text-headline-lg text-primary tracking-tight leading-snug"><?php echo esc_html( $product->get_name() ); ?></h1>
<div class="flex items-center flex-wrap gap-space-sm pt-space-xs">
<?php if ( $reviews ) : ?><div class="flex items-center text-amber-500"><?php echo nexxi_kids_stars( $rating ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="ml-1 text-primary font-bold font-label-md text-label-md"><?php echo esc_html( number_format( $rating, 1 ) ); ?></span></div><span class="text-outline-variant">•</span>
<a class="font-label-md text-label-md text-secondary hover:underline" href="#danh-gia-tab" data-nexxi-tab-link="reviews"><?php echo (int) $reviews; ?> Đánh giá từ các mẹ</a>
<?php else : ?><a class="font-label-md text-label-md text-secondary hover:underline" href="#danh-gia-tab" data-nexxi-tab-link="reviews">Chưa có đánh giá – mẹ là người đầu tiên nhé!</a><?php endif; ?>
<?php if ( $sold ) : ?><span class="text-outline-variant">•</span><span class="font-body-sm text-body-sm text-outline"><?php echo esc_html( nexxi_kids_compact_number( $sold ) ); ?> Đã rước về tủ bé</span><?php endif; ?>
</div>
<?php if ( $product->get_short_description() ) : ?><div class="font-body-md text-body-md text-on-surface-variant pt-space-xs"><?php echo wp_kses_post( wpautop( $product->get_short_description() ) ); ?></div><?php endif; ?>
</div>
<div class="bg-surface-container-low rounded-lg p-space-md flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm">
<div>
<div class="flex items-baseline gap-space-xs flex-wrap">
<span class="font-display-lg text-display-lg text-secondary font-bold leading-none" data-nexxi-price><?php echo esc_html( $price ); ?></span>
<span class="font-body-md text-body-md text-outline line-through<?php echo $product->is_on_sale() && $range['regular'] > $range['min'] ? '' : ' hidden'; ?>" data-nexxi-regular><?php echo esc_html( nexxi_kids_money( $range['regular'] ) ); ?></span>
<span class="bg-secondary-container text-on-secondary font-label-sm text-label-sm px-2 py-0.5 rounded-full font-bold ml-1<?php echo $discount ? '' : ' hidden'; ?>" data-nexxi-discount>-<?php echo (int) $discount; ?>%</span>
</div>
<p class="font-body-sm text-body-sm text-on-surface-variant mt-1">Đã bao gồm VAT • Tặng kèm hộp quà xinh xắn</p>
</div>
<?php if ( $product->is_on_sale() ) : ?>
<div class="bg-surface-container-lowest px-space-md py-space-xs rounded-lg shadow-sm flex items-center gap-space-xs self-start sm:self-auto">
<span class="material-symbols-outlined text-secondary text-[20px] animate-bounce">alarm</span>
<div class="text-right"><p class="font-label-sm text-label-sm text-outline uppercase">Kết thúc sau</p>
<div class="flex items-center gap-1 font-label-md text-label-md text-primary font-mono font-bold"><span class="bg-surface-container px-1 py-0.5 rounded" id="pdp-hours">--</span>:<span class="bg-surface-container px-1 py-0.5 rounded" id="pdp-minutes">--</span>:<span class="bg-surface-container px-1 py-0.5 rounded" id="pdp-seconds">--</span></div></div>
</div>
<?php endif; ?>
</div>
<?php if ( $config['colors'] ) : ?>
<div class="space-y-space-xs">
<div class="flex justify-between items-center font-label-md text-label-md"><span class="text-primary font-bold">Màu Sắc: <span class="text-secondary font-semibold" id="selectedColorName">Chọn màu</span></span><span class="text-outline font-body-sm text-body-sm"><?php echo count( $config['colors'] ); ?> màu</span></div>
<div class="flex items-center gap-space-sm flex-wrap">
<?php foreach ( $config['colors'] as $color ) : ?>
<button class="color-btn group relative flex items-center gap-2 px-space-md py-space-xs rounded-full bg-surface-container-low hover:bg-surface-container transition-all" type="button" data-nexxi-color="<?php echo esc_attr( $color['slug'] ); ?>" data-name="<?php echo esc_attr( $color['name'] ); ?>" aria-pressed="false"><span class="w-4 h-4 rounded-full shadow-inner inline-block" style="background:<?php echo esc_attr( $color['hex'] ); ?>"></span><span class="font-label-md text-label-md text-on-surface-variant group-hover:text-primary"><?php echo esc_html( $color['name'] ); ?></span></button>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>
<?php if ( $config['sizes'] ) : ?>
<div class="space-y-space-xs">
<div class="flex justify-between items-center font-label-md text-label-md"><span class="text-primary font-bold">Kích Cỡ Cho Bé: <span class="text-secondary font-semibold" id="currentSelectedSizeBadge">Chọn size cho bé</span></span>
<button class="text-secondary hover:underline flex items-center gap-0.5 font-label-sm text-label-sm" type="button" data-nexxi-size-table-open><span class="material-symbols-outlined text-[16px]">straighten</span> Mở Bảng Size Đầy Đủ</button></div>
<div class="grid grid-cols-2 sm:grid-cols-4 gap-space-xs" data-nexxi-size-grid>
<?php foreach ( $config['sizes'] as $size ) : ?>
<button class="size-pill py-2.5 px-space-sm rounded-lg text-center bg-surface-container-low hover:bg-surface-container transition-all" type="button" data-nexxi-pill="<?php echo esc_attr( $size['slug'] ); ?>" data-number="<?php echo (int) $size['number']; ?>" aria-pressed="false"><div class="font-title-md text-title-md text-primary"><?php echo esc_html( $size['name'] ); ?></div><div class="font-body-sm text-body-sm text-outline"><?php echo esc_html( $size['weight'] ); ?></div></button>
<?php endforeach; ?>
</div>
<p class="hidden font-label-sm text-label-sm text-secondary" data-nexxi-size-hint>Mẹ chọn size cho bé trước khi thêm vào giỏ nhé!</p>
</div>
<div class="bg-secondary-fixed/40 rounded-xl p-space-md space-y-space-sm" id="sizeAdvisorModal" data-nexxi-size-advisor>
<div class="flex items-center justify-between"><div class="flex items-center gap-space-xs"><div class="w-8 h-8 rounded-full bg-secondary-container text-on-secondary flex items-center justify-center shadow-xs"><span class="material-symbols-outlined text-[18px]">smart_toy</span></div><div><h4 class="font-label-lg text-label-lg text-primary">Trợ Lý Gợi Ý Size Bé Yêu</h4><p class="font-body-sm text-body-sm text-outline"><?php echo $advisor_title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p></div></div><span class="material-symbols-outlined text-secondary text-[20px]">auto_awesome</span></div>
<div class="grid grid-cols-2 gap-space-sm pt-space-xs">
<label class="block"><span class="block font-label-sm text-label-sm text-on-surface-variant mb-1">Cân nặng bé (kg):</span><span class="relative flex items-center"><input class="w-full bg-surface-container-lowest px-space-md py-2 rounded-lg font-title-md text-title-md text-primary outline-none focus:ring-2 focus:ring-secondary-container" data-advisor-weight max="55" min="8" step="0.5" type="number" value="11" inputmode="decimal"><span class="absolute right-3 font-label-sm text-label-sm text-outline">kg</span></span></label>
<label class="block"><span class="block font-label-sm text-label-sm text-on-surface-variant mb-1">Chiều cao bé (cm):</span><span class="relative flex items-center"><input class="w-full bg-surface-container-lowest px-space-md py-2 rounded-lg font-title-md text-title-md text-primary outline-none focus:ring-2 focus:ring-secondary-container" data-advisor-height max="170" min="75" step="1" type="number" value="86" inputmode="numeric"><span class="absolute right-3 font-label-sm text-label-sm text-outline">cm</span></span></label>
</div>
<div class="bg-surface-container-lowest rounded-lg p-space-sm flex items-center justify-between gap-space-sm shadow-xs"><div class="flex items-center gap-space-xs"><span class="material-symbols-outlined text-secondary text-[22px]">thumb_up</span><div><p class="font-label-md text-label-md text-primary" data-advisor-title>Khuyên dùng: <strong class="text-secondary font-bold">Size 2</strong></p><p class="font-body-sm text-body-sm text-on-surface-variant" data-advisor-desc>Mẹ có thể chọn size lớn hơn nếu bé nằm giữa hai size.</p></div></div>
<button class="px-space-md py-1.5 rounded-full bg-secondary text-on-secondary font-label-sm text-label-sm hover:bg-secondary-container transition-all shrink-0" type="button" data-advisor-apply>Chọn size này</button></div>
</div>
<details class="bg-surface-container-low rounded-xl p-space-md font-body-md text-body-md" data-nexxi-size-table><summary class="font-label-md text-label-md text-primary cursor-pointer">Bảng size tham khảo 1–16</summary><div class="overflow-x-auto mt-space-sm"><table class="w-full text-center"><thead><tr><th class="py-1">Size</th><th>Cân nặng (kg)</th><th>Chiều cao (cm)</th></tr></thead><tbody>
<?php foreach ( Nexxi_Kids_Catalog::size_chart() as $n => $row ) : ?><tr class="border-b border-surface-container-high"><td class="py-1"><?php echo (int) $n; ?></td><td><?php echo (int) $row[0] . '–' . (int) $row[1]; ?></td><td><?php echo (int) $row[2]; ?></td></tr><?php endforeach; ?>
</tbody></table></div></details>
<?php endif; ?>
<div class="space-y-space-sm pt-space-xs">
<div class="flex items-center gap-space-sm">
<div class="flex items-center bg-surface-container-low rounded-full p-1 shadow-inner">
<button class="w-9 h-9 rounded-full bg-surface-container-lowest hover:bg-surface-container text-primary font-bold flex items-center justify-center transition-all" type="button" data-nexxi-qty="-1" aria-label="Giảm số lượng"><span class="material-symbols-outlined text-[18px]">remove</span></button>
<span class="w-10 text-center font-headline-sm text-headline-sm text-primary font-bold" id="productQtyDisplay" aria-live="polite">1</span>
<button class="w-9 h-9 rounded-full bg-surface-container-lowest hover:bg-surface-container text-primary font-bold flex items-center justify-center transition-all" type="button" data-nexxi-qty="1" aria-label="Tăng số lượng"><span class="material-symbols-outlined text-[18px]">add</span></button>
</div>
<button class="flex-1 bg-secondary-fixed text-on-secondary-fixed hover:bg-secondary-fixed-dim py-3.5 px-space-md rounded-full font-label-lg text-label-lg transition-all flex items-center justify-center gap-space-xs shadow-sm active:scale-98 disabled:opacity-50 disabled:cursor-not-allowed" type="button" data-nexxi-pdp-add <?php disabled( ! $product->is_in_stock() ); ?>><span class="material-symbols-outlined text-[20px]">add_shopping_cart</span> Thêm Vào Giỏ Hàng</button>
</div>
<button class="w-full bg-secondary-container hover:bg-secondary text-on-secondary py-4 px-space-lg rounded-full font-headline-sm text-headline-sm transition-all shadow-[0_12px_28px_-6px_rgba(253,106,73,0.4)] flex items-center justify-center gap-space-xs active:scale-98 disabled:opacity-50 disabled:cursor-not-allowed" type="button" data-nexxi-pdp-buy <?php disabled( ! $product->is_in_stock() ); ?>><span class="material-symbols-outlined text-[24px]">bolt</span> Mua Ngay - Giao Nhanh</button>
</div>
	<?php
	return ob_get_clean() . $services;
}

function nexxi_kids_pdp_tabs( WC_Product $product ) {
	$tab_active = 'tab-pill px-space-lg py-2.5 rounded-full bg-primary text-on-primary font-label-md text-label-md transition-all shadow-sm';
	$tab_idle = 'tab-pill px-space-lg py-2.5 rounded-full text-on-surface-variant hover:text-primary font-label-md text-label-md transition-all';
	$reviews_open = 'yes' === get_option( 'woocommerce_enable_reviews', 'yes' );
	$count = (int) $product->get_review_count();
	$rating = (float) $product->get_average_rating();

	// Specs: every visible attribute + weight/dimensions.
	$specs = array();
	foreach ( $product->get_attributes() as $attribute ) {
		if ( ! $attribute->get_visible() && ! $attribute->get_variation() ) { continue; }
		$values = $attribute->is_taxonomy() ? wp_list_pluck( $attribute->get_terms(), 'name' ) : $attribute->get_options();
		if ( 'pa_size' === $attribute->get_name() && $values ) {
			$numbers = array_map( array( 'Nexxi_Kids_Catalog', 'size_number' ), $values );
			$values = array( 'Size ' . min( $numbers ) . ( count( $numbers ) > 1 ? ' – ' . max( $numbers ) : '' ) );
		}
		$specs[] = array( wc_attribute_label( $attribute->get_name(), $product ), implode( ', ', $values ) );
	}
	if ( $product->has_weight() ) { $specs[] = array( 'Khối lượng', wc_format_weight( $product->get_weight() ) ); }
	$gallery = $product->get_gallery_image_ids();
	$side_image = $gallery ? wp_get_attachment_image_url( $gallery[0], 'large' ) : '';
	$description = $product->get_description();

	ob_start(); ?>
<div class="flex flex-wrap items-center gap-space-xs pb-space-md bg-surface-container-low/50 p-space-xs rounded-full max-w-fit mb-space-lg" role="tablist">
<button class="<?php echo esc_attr( $tab_active ); ?>" type="button" data-nexxi-tab="desc" role="tab" aria-selected="true">Mô Tả &amp; Phom Dáng</button>
<button class="<?php echo esc_attr( $tab_idle ); ?>" type="button" data-nexxi-tab="care" role="tab" aria-selected="false">Bảo Quản &amp; Giặt Là</button>
<?php if ( $reviews_open ) : ?><button class="<?php echo esc_attr( $tab_idle ); ?> flex items-center gap-1.5" id="danh-gia-tab" type="button" data-nexxi-tab="reviews" role="tab" aria-selected="false">Đánh Giá Từ Mẹ (<?php echo (int) $count; ?>)<?php if ( $count ) : ?><span class="bg-secondary-fixed text-on-secondary-fixed text-[11px] px-2 py-0.5 rounded-full font-bold"><?php echo esc_html( number_format( $rating, 1 ) ); ?> ★</span><?php endif; ?></button><?php endif; ?>
</div>
<div class="space-y-space-lg" id="tabContent-desc">
<div class="grid grid-cols-1 lg:grid-cols-12 gap-space-lg items-start">
<div class="<?php echo $side_image ? 'lg:col-span-7' : 'lg:col-span-12'; ?> space-y-space-md">
<div class="space-y-space-xs"><span class="font-label-sm text-label-sm text-secondary uppercase tracking-widest font-bold">Boutique Craftsmanship</span>
<h3 class="font-headline-md text-headline-md text-primary">Nâng niu từng nụ cười và bước nhảy chân sáo của con</h3>
<div class="font-body-lg text-body-lg text-on-surface-variant leading-relaxed space-y-space-sm nexxi-prose"><?php echo $description ? wp_kses_post( wpautop( do_shortcode( $description ) ) ) : '<p>Thông tin chi tiết về sản phẩm đang được NEXXI cập nhật.</p>'; ?></div></div>
<?php if ( $specs ) : ?><div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm pt-space-xs">
<?php foreach ( $specs as $spec ) : ?><div class="bg-surface-container-low p-space-md rounded-lg"><span class="font-label-sm text-label-sm text-outline"><?php echo esc_html( $spec[0] ); ?></span><p class="font-title-md text-title-md text-primary"><?php echo esc_html( $spec[1] ); ?></p></div><?php endforeach; ?>
</div><?php endif; ?>
</div>
<?php if ( $side_image ) : ?><div class="lg:col-span-5"><div class="relative rounded-xl overflow-hidden shadow-lg bg-surface-container"><img class="w-full h-80 object-cover" src="<?php echo esc_url( $side_image ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" loading="lazy"><div class="p-space-md bg-surface-container-low"><p class="font-label-md text-label-md text-primary">Từng đường may được NEXXI hoàn thiện tỉ mỉ</p><p class="font-body-sm text-body-sm text-on-surface-variant">Chất liệu chọn lọc, an toàn cho làn da nhạy cảm của bé.</p></div></div></div><?php endif; ?>
</div>
</div>
<div class="hidden space-y-space-lg" id="tabContent-care">
<div class="max-w-3xl space-y-space-md">
<h3 class="font-headline-md text-headline-md text-primary">Bí quyết giữ đồ của bé luôn mềm mịn như mây</h3>
<?php $care = get_post_meta( $product->get_id(), '_nexxi_care', true ); ?>
<p class="font-body-md text-body-md text-on-surface-variant"><?php echo esc_html( $care ? $care : 'Chất liệu tự nhiên rất lành tính với bé và càng giặt càng mềm mại nếu mẹ chăm sóc đúng cách:' ); ?></p>
<div class="grid grid-cols-1 md:grid-cols-3 gap-space-md pt-space-xs">
<?php foreach ( array( array( 'wash', '1. Giặt nhẹ nhàng', 'Giặt tay với nước mát hoặc cho vào túi giặt, chọn chế độ đồ em bé.' ), array( 'wb_sunny', '2. Phơi bóng râm', 'Tránh nắng gắt trực tiếp để sợi vải giữ độ mềm và màu sắc tươi lâu.' ), array( 'iron', '3. Ủi hơi nước', 'Ủi ở nhiệt độ vừa khi vải còn hơi ẩm để giữ phom dáng đẹp.' ) ) as $tip ) : ?>
<div class="bg-surface-container-low p-space-md rounded-xl space-y-space-xs"><div class="w-10 h-10 rounded-full bg-secondary-fixed flex items-center justify-center text-secondary"><span class="material-symbols-outlined text-[20px]"><?php echo esc_html( $tip[0] ); ?></span></div><h4 class="font-title-md text-title-md text-primary"><?php echo esc_html( $tip[1] ); ?></h4><p class="font-body-sm text-body-sm text-on-surface-variant"><?php echo esc_html( $tip[2] ); ?></p></div>
<?php endforeach; ?>
</div>
</div>
</div>
<?php if ( $reviews_open ) : ?><div class="hidden space-y-space-xl" id="tabContent-reviews"><?php echo nexxi_kids_pdp_reviews( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><?php endif; ?>
	<?php
	return ob_get_clean();
}

function nexxi_kids_pdp_reviews( WC_Product $product ) {
	$count = (int) $product->get_review_count();
	$rating = (float) $product->get_average_rating();
	$distribution = $product->get_rating_counts();
	$comments = get_comments( array( 'post_id' => $product->get_id(), 'status' => 'approve', 'type' => 'review', 'number' => 6 ) );
	ob_start(); ?>
<div class="bg-surface-container-low rounded-xl p-space-lg flex flex-col md:flex-row items-center justify-between gap-space-lg">
<div class="text-center md:text-left">
<div class="flex items-baseline justify-center md:justify-start gap-2"><span class="font-display-lg text-display-lg text-primary font-bold"><?php echo esc_html( $count ? number_format( $rating, 1 ) : '0.0' ); ?></span><span class="font-title-md text-title-md text-outline">/ 5.0</span></div>
<div class="flex items-center text-amber-500 my-1 justify-center md:justify-start"><?php echo nexxi_kids_stars( $rating, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
<p class="font-body-sm text-body-sm text-on-surface-variant"><?php echo $count ? esc_html( 'Dựa trên ' . $count . ' phản hồi từ các mẹ đã mua hàng' ) : 'Sản phẩm chưa có đánh giá nào'; ?></p>
</div>
<div class="flex flex-wrap gap-space-xs justify-center md:justify-end">
<?php for ( $star = 5; $star >= 1; $star-- ) : ?><span class="bg-surface-container-lowest px-space-md py-space-xs rounded-full font-label-md text-label-md text-primary shadow-xs"><?php echo (int) $star; ?> ★ (<?php echo isset( $distribution[ $star ] ) ? (int) $distribution[ $star ] : 0; ?>)</span><?php endfor; ?>
</div>
</div>
<?php if ( $comments ) : ?>
<div class="grid grid-cols-1 md:grid-cols-3 gap-space-lg">
<?php foreach ( $comments as $comment ) :
	$stars = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
	$words = preg_split( '~\s+~u', trim( $comment->comment_author ) );
	$initials = mb_strtoupper( mb_substr( $words[0], 0, 1 ) . ( count( $words ) > 1 ? mb_substr( end( $words ), 0, 1 ) : '' ) );
	$verified = wc_review_is_from_verified_owner( $comment->comment_ID ); ?>
<div class="bg-surface-container-lowest p-space-md rounded-xl space-y-space-sm shadow-sm flex flex-col justify-between"><div class="space-y-space-xs">
<div class="flex items-center justify-between gap-space-xs"><div class="flex items-center gap-space-xs"><div class="w-10 h-10 rounded-full bg-secondary-fixed flex items-center justify-center font-bold text-secondary shrink-0"><?php echo esc_html( $initials ); ?></div><div><h5 class="font-label-lg text-label-lg text-primary"><?php echo esc_html( $comment->comment_author ); ?></h5><span class="font-body-sm text-body-sm text-outline"><?php echo esc_html( ( $verified ? 'Đã mua hàng • ' : '' ) . get_comment_date( 'd/m/Y', $comment ) ); ?></span></div></div>
<div class="flex text-amber-500 shrink-0"><?php echo nexxi_kids_stars( $stars, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></div>
<p class="font-body-md text-body-md text-on-surface-variant leading-relaxed">“<?php echo esc_html( $comment->comment_content ); ?>”</p>
</div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php echo nexxi_kids_review_form( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php
	return ob_get_clean();
}

/** Stitch-styled review form posting to wp-comments-post.php (WooCommerce saves the rating). */
function nexxi_kids_review_form( WC_Product $product ) {
	if ( ! comments_open( $product->get_id() ) ) { return ''; }
	if ( 'yes' === get_option( 'woocommerce_review_rating_verification_required' ) && ! wc_customer_bought_product( '', get_current_user_id(), $product->get_id() ) ) {
		return '<p class="font-body-md text-body-md text-on-surface-variant">Chỉ khách hàng đã mua sản phẩm này mới có thể đánh giá.</p>';
	}
	$input = 'w-full bg-surface-container-lowest px-space-md py-2.5 rounded-lg font-body-md text-body-md text-on-surface outline-none ring-1 ring-outline-variant focus:ring-2 focus:ring-secondary-container';
	ob_start(); ?>
<form class="bg-surface-container-low rounded-xl p-space-lg space-y-space-md" action="<?php echo esc_url( site_url( '/wp-comments-post.php' ) ); ?>" method="post" data-nexxi-review-form>
<div><span class="font-label-sm text-label-sm text-secondary uppercase tracking-widest font-bold">Chia sẻ cảm nhận</span><h3 class="font-headline-sm text-headline-sm text-primary">Mẹ đánh giá sản phẩm này nhé</h3></div>
<fieldset class="flex items-center gap-1"><legend class="font-label-md text-label-md text-primary mb-1">Mức độ hài lòng *</legend>
<?php for ( $i = 1; $i <= 5; $i++ ) : ?><label class="cursor-pointer text-amber-500"><input class="sr-only" type="radio" name="rating" value="<?php echo (int) $i; ?>" required data-nexxi-star><span class="material-symbols-outlined text-[28px]" style="font-variation-settings: 'FILL' 0;" data-star-icon="<?php echo (int) $i; ?>">star</span></label><?php endfor; ?>
</fieldset>
<label class="block"><span class="block font-label-md text-label-md text-primary mb-1">Nhận xét của mẹ *</span><textarea class="<?php echo esc_attr( $input ); ?>" name="comment" rows="4" required maxlength="2000" placeholder="Chất vải, size, màu sắc... mẹ thấy thế nào?"></textarea></label>
<?php if ( ! is_user_logged_in() ) : ?><div class="grid grid-cols-1 sm:grid-cols-2 gap-space-sm"><label class="block"><span class="block font-label-md text-label-md text-primary mb-1">Tên hiển thị *</span><input class="<?php echo esc_attr( $input ); ?>" type="text" name="author" required autocomplete="name"></label><label class="block"><span class="block font-label-md text-label-md text-primary mb-1">Email *</span><input class="<?php echo esc_attr( $input ); ?>" type="email" name="email" required autocomplete="email"></label></div><?php endif; ?>
<input type="hidden" name="comment_post_ID" value="<?php echo (int) $product->get_id(); ?>"><input type="hidden" name="comment_parent" value="0">
<?php wp_comment_form_unfiltered_html_nonce(); ?>
<button class="bg-secondary-container hover:bg-secondary text-on-secondary px-space-lg py-3 rounded-full font-label-md text-label-md font-bold transition-all shadow-md flex items-center gap-1.5" type="submit"><span class="material-symbols-outlined text-[18px]">send</span> Gửi đánh giá</button>
<p class="font-body-sm text-body-sm text-outline">Đánh giá sẽ hiển thị sau khi NEXXI kiểm duyệt.</p>
</form>
	<?php
	return ob_get_clean();
}

function nexxi_kids_pdp_related( WC_Product $product ) {
	$ids = array_merge( $product->get_upsell_ids(), $product->get_cross_sell_ids() );
	if ( count( $ids ) < 3 ) { $ids = array_merge( $ids, wc_get_related_products( $product->get_id(), 6, $ids ) ); }
	$ids = array_slice( array_unique( array_map( 'absint', $ids ) ), 0, 3 );
	$cards = '';
	foreach ( $ids as $id ) {
		$item = wc_get_product( $id );
		if ( $item && $item->is_visible() ) { $cards .= nexxi_kids_card_related( $item ); }
	}
	if ( ! $cards ) { return ''; }
	$cat = nexxi_kids_product_category( $product );
	$more = $cat ? get_term_link( $cat ) : nexxi_kids_shop_url();
	return '<div class="flex flex-col sm:flex-row sm:items-end justify-between gap-space-xs"><div><span class="font-label-sm text-label-sm text-secondary uppercase tracking-widest font-bold">Gợi Ý Từ Stylist NEXXI</span><h2 class="font-headline-lg text-headline-lg text-primary tracking-tight">Mẹ Thường Phối Cùng (Mix &amp; Match Xinh Yêu)</h2><p class="font-body-md text-body-md text-on-surface-variant">Những mẫu đồng điệu giúp mẹ dễ dàng phối set xinh cho con dạo phố.</p></div><a class="bg-surface-container-lowest hover:bg-surface-container text-primary px-space-md py-space-xs rounded-full font-label-md text-label-md transition-all flex items-center gap-1 shadow-sm self-start sm:self-auto" href="' . esc_url( is_wp_error( $more ) ? nexxi_kids_shop_url() : $more ) . '">Xem Thêm Mẫu Cùng Loại <span class="material-symbols-outlined text-[18px]">arrow_forward</span></a></div><div class="grid grid-cols-1 sm:grid-cols-3 gap-space-md">' . $cards . '</div>';
}
