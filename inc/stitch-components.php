<?php
/**
 * Product card components. Markup is copied from the Stitch screens so every
 * dynamic card is visually identical to the approved design.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Wishlist heart button attributes. */
function nexxi_kids_wishlist_attrs( $id ) {
	return 'type="button" data-nexxi-wishlist="' . esc_attr( $id ) . '" aria-pressed="false" aria-label="' . esc_attr__( 'Thêm vào yêu thích', 'nexxi-kids' ) . '"';
}

/** Star icons (filled) used inside cards. */
function nexxi_kids_star_icon( $size = 16, $class = '' ) {
	return '<span class="material-symbols-outlined text-[' . (int) $size . 'px] ' . esc_attr( $class ) . '" style="font-variation-settings: \'FILL\' 1;">star</span>';
}

/** Quick size buttons (max 4, then "+N"). */
function nexxi_kids_card_sizes( array $card, $button_class, $limit = 4 ) {
	$out = '';
	foreach ( array_slice( $card['sizes'], 0, $limit ) as $size ) {
		$out .= '<button class="' . esc_attr( $button_class ) . '" type="button" data-nexxi-size="' . esc_attr( $size['slug'] ) . '" aria-pressed="false" title="' . esc_attr( $size['name'] ) . '">' . esc_html( $size['label'] ) . '</button>';
	}
	$more = count( $card['sizes'] ) - $limit;
	if ( $more > 0 ) { $out .= '<a class="font-label-sm text-label-sm text-outline hover:text-secondary ml-0.5" href="' . esc_url( $card['url'] ) . '">+' . (int) $more . '</a>'; }
	return $out;
}

/**
 * Unified product card component (Image 3 layout) used across Homepage
 * (Flash Sale, Best Sellers, New Arrivals), Shop/Category pages, Flash Sale page,
 * Wishlist page, and Product Detail related products.
 */
function nexxi_kids_card_unified( WC_Product $product ) {
	$c      = nexxi_kids_card_data( $product );
	$status = $c['soldPercent'] >= 80 ? 'Sắp Cháy Hàng' : ( $c['soldPercent'] >= 50 ? 'Bán Chạy' : 'Đang Mở Bán' );
	ob_start(); ?>
<article <?php echo nexxi_kids_card_attrs( $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="product-card bg-surface-container-lowest rounded-lg p-space-sm flex flex-col justify-between shadow-sm hover:shadow-lg transition-all group">
<div class="nexxi-shop-card-thumb relative w-full aspect-square rounded-DEFAULT overflow-hidden bg-surface-container-low mb-space-sm">
<a class="block w-full h-full" href="<?php echo esc_url( $c['url'] ); ?>"><img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" src="<?php echo esc_url( $c['image'] ); ?>" alt="<?php echo esc_attr( $c['name'] ); ?>" loading="lazy"></a>
<?php if ( $c['discount'] ) : ?><div class="absolute top-2 left-2 bg-secondary text-on-secondary font-label-sm text-label-sm font-bold px-2.5 py-1 rounded-full shadow-sm -rotate-3">-<?php echo (int) $c['discount']; ?>%</div><?php endif; ?>
<div class="absolute top-2 right-2 flex flex-col gap-1"><button class="w-8 h-8 rounded-full bg-surface-container-lowest/90 hover:bg-surface-container-lowest text-secondary flex items-center justify-center shadow-sm transition-transform active:scale-90" <?php echo nexxi_kids_wishlist_attrs( $c['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><span class="material-symbols-outlined text-[18px]">favorite</span></button></div>
<?php if ( null !== $c['left'] ) : ?><div class="absolute bottom-2 left-2 bg-surface-container-lowest/90 backdrop-blur-sm px-2 py-0.5 rounded-full font-label-sm text-label-sm text-secondary font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">local_fire_department</span> Còn <?php echo (int) $c['left']; ?> chiếc</div><?php endif; ?>
</div>
<div class="space-y-space-xs flex-1 flex flex-col justify-between">
<div>
<div class="flex items-center justify-between gap-1 text-body-sm text-outline mb-1">
<?php if ( $c['reviews'] ) : ?><span class="flex items-center gap-0.5 text-secondary font-bold shrink-0"><?php echo nexxi_kids_star_icon( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( number_format( $c['rating'], 1 ) ); ?> <span class="text-on-surface-variant font-normal">(<?php echo (int) $c['reviews']; ?>)</span></span><?php else : ?><span class="text-on-surface-variant shrink-0">Mẫu mới</span><?php endif; ?>
<span class="font-label-sm text-label-sm text-on-surface-variant truncate text-right"><?php echo esc_html( $c['material'] ? $c['material'] : $c['category'] ); ?></span>
</div>
<h3 class="font-title-md text-title-md text-primary font-bold line-clamp-2 group-hover:text-secondary transition-colors"><a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a></h3>
</div>
<div class="pt-space-xs space-y-space-xs">
<div class="flex items-baseline gap-2 flex-wrap"><span class="font-headline-sm text-headline-sm text-secondary font-bold"><?php echo esc_html( $c['priceFrom'] ); ?></span><?php if ( $c['regular'] ) : ?><span class="font-body-sm text-body-sm text-outline line-through"><?php echo esc_html( $c['regular'] ); ?></span><?php endif; ?></div>
<?php if ( $c['saving'] ) : ?><div class="font-label-sm text-label-sm text-on-tertiary-container bg-tertiary-fixed px-2 py-0.5 rounded-md inline-block">Tiết kiệm: <?php echo esc_html( $c['saving'] ); ?></div><?php endif; ?>
<div class="space-y-1 pt-1"><div class="flex justify-between font-label-sm text-label-sm text-on-surface-variant"><span>Đã bán <?php echo (int) $c['soldPercent']; ?>%</span><span class="text-secondary font-bold"><?php echo esc_html( $status ); ?></span></div><div class="w-full bg-surface-container h-2 rounded-full overflow-hidden"><div class="bg-secondary-container h-full rounded-full" style="width: <?php echo (int) $c['soldPercent']; ?>%"></div></div></div>
<div class="pt-2 flex items-center justify-between gap-1">
<div class="flex items-center gap-1 text-[11px] font-bold text-on-surface"><?php echo nexxi_kids_card_sizes( $c, 'w-7 h-7 rounded-full bg-surface-container hover:bg-primary hover:text-on-primary transition-colors flex items-center justify-center', 3 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
<button class="bg-secondary-container hover:bg-secondary text-on-secondary px-space-sm py-1.5 rounded-full font-label-md text-label-md transition-all shadow-sm flex items-center gap-1 shrink-0" type="button" data-nexxi-add <?php disabled( ! $c['inStock'] ); ?>><span class="material-symbols-outlined text-[16px]">add_shopping_cart</span><span><?php echo esc_html( $c['inStock'] ? 'Mua' : 'Hết' ); ?></span></button>
</div>
</div>
</div>
</article>
	<?php
	return ob_get_clean();
}

/** Shop grid card (unified layout). */
function nexxi_kids_card_shop( WC_Product $product ) {
	return nexxi_kids_card_unified( $product );
}

/** Home Flash Sale card (unified layout). */
function nexxi_kids_card_home_flash( WC_Product $product ) {
	return nexxi_kids_card_unified( $product );
}

/** Home best-seller card (unified layout). */
function nexxi_kids_card_home_bestseller( WC_Product $product, $rank = 0 ) {
	return nexxi_kids_card_unified( $product );
}

/** "Mẫu mới" card used on the home reference section (unified layout). */
function nexxi_kids_card_new_arrival( WC_Product $product ) {
	return nexxi_kids_card_unified( $product );
}

/** Flash Sale page card (unified layout). */
function nexxi_kids_card_flash_page( WC_Product $product ) {
	return nexxi_kids_card_unified( $product );
}

/** Accessory / related card (unified layout). */
function nexxi_kids_card_related( WC_Product $product ) {
	return nexxi_kids_card_unified( $product );
}

/** Empty-state block in Stitch style. */
function nexxi_kids_empty_state( $title, $text, $cta_label = '', $cta_url = '' ) {
	$out = '<div class="col-span-full bg-surface-container-lowest rounded-xl p-space-xl text-center shadow-sm"><span class="material-symbols-outlined text-secondary text-[40px]">child_care</span><p class="font-headline-sm text-headline-sm text-primary mt-space-sm">' . esc_html( $title ) . '</p><p class="font-body-md text-body-md text-on-surface-variant mt-space-xs">' . esc_html( $text ) . '</p>';
	if ( $cta_label ) { $out .= '<a class="inline-flex mt-space-md bg-secondary-container hover:bg-secondary text-on-secondary px-space-lg py-space-sm rounded-full font-label-md text-label-md transition-all" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_label ) . '</a>'; }
	return $out . '</div>';
}
