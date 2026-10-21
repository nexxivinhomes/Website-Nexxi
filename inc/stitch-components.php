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

/** Shop grid card (shop.html → Card 1). */
function nexxi_kids_card_shop( WC_Product $product ) {
	$c = nexxi_kids_card_data( $product );
	$meta = trim( $c['category'] . ( $c['material'] ? ' • ' . $c['material'] : '' ) );
	ob_start(); ?>
<article <?php echo nexxi_kids_card_attrs( $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="group bg-surface-container-lowest rounded-2xl p-space-sm shadow-[0_8px_24px_-4px_rgba(27,42,89,0.05)] hover:shadow-[0_16px_36px_-6px_rgba(253,106,73,0.15)] transition-all duration-300 flex flex-col justify-between">
<div>
<div class="nexxi-shop-card-thumb relative w-full aspect-square rounded-xl overflow-hidden bg-surface-container-low mb-space-sm">
<a class="block w-full h-full" href="<?php echo esc_url( $c['url'] ); ?>"><img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" src="<?php echo esc_url( $c['image'] ); ?>" alt="<?php echo esc_attr( $c['name'] ); ?>" loading="lazy"></a>
<?php if ( $c['discount'] ) : ?><div class="absolute top-space-xs left-space-xs"><span class="bg-secondary-container text-on-secondary font-label-sm text-label-sm px-space-sm py-0.5 rounded-full shadow-sm rotate-[-3deg] inline-block font-bold">-<?php echo (int) $c['discount']; ?>% SALE</span></div>
<?php elseif ( $c['isNew'] ) : ?><div class="absolute top-space-xs left-space-xs"><span class="bg-primary text-on-primary font-label-sm text-label-sm px-space-sm py-0.5 rounded-full shadow-sm inline-block font-bold">Mới về</span></div><?php endif; ?>
<button class="absolute top-space-xs right-space-xs w-8 h-8 rounded-full bg-surface-container-lowest/90 backdrop-blur text-outline hover:text-secondary flex items-center justify-center shadow-sm hover:scale-110 transition-transform" <?php echo nexxi_kids_wishlist_attrs( $c['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><span class="material-symbols-outlined text-[18px]">favorite</span></button>
</div>
<div class="space-y-1 px-1">
<div class="flex items-center justify-between gap-1 text-outline">
<span class="font-label-sm text-label-sm uppercase tracking-wider text-secondary line-clamp-1"><?php echo esc_html( $meta ); ?></span>
<?php if ( $c['reviews'] ) : ?><div class="flex items-center gap-0.5 font-label-sm text-label-sm text-tertiary-fixed-dim shrink-0"><?php echo nexxi_kids_star_icon( 14, 'text-tertiary-fixed-dim' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="font-bold text-on-surface"><?php echo esc_html( number_format( $c['rating'], 1 ) ); ?></span></div><?php elseif ( $c['sold'] ) : ?><div class="flex items-center gap-1 font-label-sm text-label-sm shrink-0"><span class="text-outline font-body-sm">Đã bán <?php echo esc_html( nexxi_kids_compact_number( $c['sold'] ) ); ?></span></div><?php endif; ?>
</div>
<h3 class="font-title-md text-title-md text-primary font-bold group-hover:text-secondary transition-colors line-clamp-1"><a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a></h3>
<div class="flex items-baseline gap-space-xs flex-wrap">
<span class="font-headline-sm text-headline-sm text-secondary font-bold"><?php echo esc_html( $c['price'] ); ?></span>
<?php if ( $c['regular'] ) : ?><span class="font-body-sm text-body-sm text-outline line-through"><?php echo esc_html( $c['regular'] ); ?></span><?php endif; ?>
</div>
<?php if ( $c['sizes'] ) : ?><div class="pt-0.5 flex items-center gap-1 flex-wrap"><span class="font-label-sm text-label-sm text-outline">Size:</span><?php echo nexxi_kids_card_sizes( $c, 'w-6 h-6 rounded-full bg-surface-container-high hover:bg-primary hover:text-on-primary font-label-sm text-[10px] transition-colors flex items-center justify-center', 3 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><?php endif; ?>
</div>
</div>
<button class="mt-space-sm w-full bg-secondary-fixed hover:bg-secondary-container hover:text-on-secondary text-secondary font-label-md text-label-md py-space-xs rounded-full flex items-center justify-center gap-space-xs transition-all shadow-sm active:scale-95" type="button" data-nexxi-add <?php disabled( ! $c['inStock'] ); ?>>
<span class="material-symbols-outlined text-[18px]">add_shopping_cart</span>
<?php echo esc_html( $c['inStock'] ? 'Thêm Vào Giỏ' : 'Tạm hết hàng' ); ?>
</button>
</article>
	<?php
	return ob_get_clean();
}

/** Home Flash Sale card (index.html → Flash Sale item). */
function nexxi_kids_card_home_flash( WC_Product $product ) {
	$c = nexxi_kids_card_data( $product );
	$meta = trim( $c['category'] . ( $c['material'] ? ' • ' . $c['material'] : '' ) );
	$left = null === $c['left'] ? '' : ( $c['left'] <= 3 ? '⚡ Chỉ còn ' . $c['left'] . ' chiếc' : '⚡ Còn ' . $c['left'] . ' chiếc' );
	ob_start(); ?>
<div <?php echo nexxi_kids_card_attrs( $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="group bg-surface-container-lowest rounded-3xl p-3.5 shadow-sm hover:shadow-xl hover:-translate-y-1.5 transition-all duration-300 flex flex-col justify-between relative">
<?php if ( $c['discount'] ) : ?><div class="absolute top-5 left-5 z-10 px-2.5 py-1 rounded-full bg-secondary text-on-secondary font-label-sm text-label-sm shadow-md -rotate-6">-<?php echo (int) $c['discount']; ?>% 💖</div><?php endif; ?>
<button class="absolute top-5 right-5 z-10 w-9 h-9 rounded-full bg-surface-container-lowest/80 backdrop-blur text-outline hover:text-secondary flex items-center justify-center transition-all shadow-sm" <?php echo nexxi_kids_wishlist_attrs( $c['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><span class="material-symbols-outlined text-[20px]">favorite</span></button>
<div class="relative w-full aspect-square rounded-2xl overflow-hidden bg-surface-container-low mb-space-sm">
<img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" src="<?php echo esc_url( $c['image'] ); ?>" alt="<?php echo esc_attr( $c['name'] ); ?>" loading="lazy">
<div class="absolute inset-x-2 bottom-2 opacity-0 group-hover:opacity-100 transition-opacity duration-300"><a class="w-full py-2 rounded-full bg-primary/90 text-on-primary font-label-md text-label-md backdrop-blur shadow-md hover:bg-primary flex items-center justify-center gap-1.5" href="<?php echo esc_url( $c['url'] ); ?>"><span class="material-symbols-outlined text-[16px]">visibility</span> Xem Nhanh</a></div>
</div>
<div class="flex flex-col gap-1">
<span class="font-body-sm text-body-sm text-on-surface-variant"><?php echo esc_html( $meta ); ?></span>
<h4 class="font-title-md text-title-md text-primary font-bold group-hover:text-secondary transition-colors line-clamp-1"><a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a></h4>
<div class="flex items-baseline gap-2 mt-1"><span class="font-headline-sm text-headline-sm text-secondary font-bold"><?php echo esc_html( $c['priceFrom'] ); ?></span><?php if ( $c['regular'] ) : ?><span class="font-body-sm text-body-sm text-outline line-through"><?php echo esc_html( $c['regular'] ); ?></span><?php endif; ?></div>
<div class="mt-space-xs space-y-1">
<div class="w-full h-2 rounded-full bg-surface-container-high overflow-hidden"><div class="h-full bg-secondary-container rounded-full" style="width: <?php echo (int) $c['soldPercent']; ?>%;"></div></div>
<div class="flex justify-between items-center font-label-sm text-label-sm text-on-surface-variant text-[11px]"><span class="text-secondary font-bold"><?php echo esc_html( $left ); ?></span><span>Đã bán <?php echo esc_html( nexxi_kids_compact_number( $c['sold'] ) ); ?></span></div>
</div>
<button class="mt-space-sm w-full py-2.5 rounded-full bg-secondary-fixed hover:bg-secondary text-on-secondary-fixed hover:text-on-secondary font-label-md text-label-md font-bold flex items-center justify-center gap-1.5 transition-all shadow-sm" type="button" data-nexxi-add <?php disabled( ! $c['inStock'] ); ?>><span class="material-symbols-outlined text-[18px]">shopping_cart</span> <?php echo esc_html( $c['inStock'] ? 'Thêm Vào Giỏ' : 'Tạm hết hàng' ); ?></button>
</div>
</div>
	<?php
	return ob_get_clean();
}

/** Home best-seller card (index.html → #product-grid .product-card). */
function nexxi_kids_card_home_bestseller( WC_Product $product, $rank = 0 ) {
	$c = nexxi_kids_card_data( $product );
	$badge = $rank > 0 && $rank <= 3 ? 'Best Seller #' . $rank : ( $c['discount'] ? 'Giảm ' . $c['discount'] . '%' : 'Mẹ yêu thích' );
	$size_class = 'w-6 h-6 rounded-full bg-surface-container-low text-primary font-label-sm text-[10px] flex items-center justify-center font-bold hover:bg-secondary hover:text-on-secondary cursor-pointer transition-colors';
	ob_start(); ?>
<div <?php echo nexxi_kids_card_attrs( $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="product-card group bg-surface-container-lowest rounded-3xl p-3.5 shadow-sm hover:shadow-xl hover:-translate-y-1.5 transition-all duration-300 flex flex-col justify-between">
<div class="relative w-full rounded-2xl overflow-hidden bg-surface-container-low mb-space-sm aspect-square"><span class="absolute top-3 left-3 z-10 px-2 py-0.5 rounded-full bg-surface-container-lowest/90 backdrop-blur font-label-sm text-label-sm text-secondary font-bold"><?php echo esc_html( $badge ); ?></span>
<button class="absolute top-3 right-3 z-10 w-8 h-8 rounded-full bg-surface-container-lowest/90 backdrop-blur text-outline hover:text-secondary flex items-center justify-center transition-all shadow-sm" <?php echo nexxi_kids_wishlist_attrs( $c['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><span class="material-symbols-outlined text-[18px]">favorite</span></button>
<a href="<?php echo esc_url( $c['url'] ); ?>"><img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" src="<?php echo esc_url( $c['image'] ); ?>" alt="<?php echo esc_attr( $c['name'] ); ?>" loading="lazy"></a></div>
<div class="flex flex-col gap-1.5">
<div class="flex items-center justify-between">
<?php if ( $c['reviews'] ) : ?><div class="flex items-center text-secondary-container text-xs"><?php echo nexxi_kids_star_icon( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="font-bold text-primary ml-1 text-xs"><?php echo esc_html( number_format( $c['rating'], 1 ) ); ?></span><span class="text-outline text-[11px] ml-1">(<?php echo (int) $c['reviews']; ?> đánh giá)</span></div>
<?php else : ?><span class="font-label-sm text-label-sm text-on-surface-variant"><?php echo esc_html( $c['category'] ); ?></span><?php endif; ?>
<span class="font-label-sm text-label-sm text-on-surface-variant"><?php echo esc_html( $c['sold'] ? 'Đã bán ' . nexxi_kids_compact_number( $c['sold'] ) : 'Mới về' ); ?></span></div>
<h4 class="font-title-md text-title-md text-primary font-bold line-clamp-1 group-hover:text-secondary transition-colors"><a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a></h4>
<?php if ( $c['sizes'] ) : ?><div class="flex items-center gap-1.5 py-1"><span class="font-label-sm text-label-sm text-on-surface-variant text-[11px]">Size nhanh:</span><?php echo nexxi_kids_card_sizes( $c, $size_class ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><?php endif; ?>
<div class="flex items-center justify-between pt-1"><span class="font-headline-sm text-headline-sm text-primary font-bold"><?php echo esc_html( $c['priceFrom'] ); ?></span>
<button class="w-9 h-9 rounded-full bg-secondary-fixed hover:bg-secondary text-on-secondary-fixed hover:text-on-secondary flex items-center justify-center transition-all shadow-sm" type="button" data-nexxi-add aria-label="<?php esc_attr_e( 'Thêm vào giỏ', 'nexxi-kids' ); ?>" <?php disabled( ! $c['inStock'] ); ?>><span class="material-symbols-outlined text-[18px]">add_shopping_cart</span></button></div></div></div>
	<?php
	return ob_get_clean();
}

/** "Mẫu mới" card used on the home reference section. */
function nexxi_kids_card_new_arrival( WC_Product $product ) {
	$c = nexxi_kids_card_data( $product );
	ob_start(); ?>
<article <?php echo nexxi_kids_card_attrs( $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="group bg-surface-container-lowest rounded-DEFAULT p-space-sm shadow-sm hover:shadow-lg transition-all"><div class="relative aspect-[4/5] overflow-hidden rounded-lg bg-surface-container-low"><a href="<?php echo esc_url( $c['url'] ); ?>"><img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" src="<?php echo esc_url( $c['image'] ); ?>" alt="<?php echo esc_attr( $c['name'] ); ?>" loading="lazy"></a><button class="absolute top-2 right-2 w-8 h-8 rounded-full bg-surface-container-lowest/90 text-outline flex items-center justify-center" <?php echo nexxi_kids_wishlist_attrs( $c['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><span class="material-symbols-outlined text-[18px]">favorite</span></button></div><div class="p-space-xs"><span class="font-label-sm text-label-sm text-secondary">NEXXI KIDS</span><h3 class="font-title-md text-title-md text-primary mt-1 line-clamp-2"><a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a></h3><div class="flex items-center justify-between mt-space-sm"><span class="font-headline-sm text-headline-sm text-primary"><?php echo esc_html( $c['priceFrom'] ); ?></span><button class="w-8 h-8 rounded-full bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center" type="button" data-nexxi-add aria-label="<?php esc_attr_e( 'Thêm vào giỏ', 'nexxi-kids' ); ?>" <?php disabled( ! $c['inStock'] ); ?>><span class="material-symbols-outlined text-[17px]">add_shopping_cart</span></button></div></div></article>
	<?php
	return ob_get_clean();
}

/** Flash Sale page card (flash-sale.html → Card 1). */
function nexxi_kids_card_flash_page( WC_Product $product ) {
	$c = nexxi_kids_card_data( $product );
	$status = $c['soldPercent'] >= 80 ? 'Sắp Cháy Hàng' : ( $c['soldPercent'] >= 50 ? 'Bán Chạy' : 'Đang Mở Bán' );
	ob_start(); ?>
<div <?php echo nexxi_kids_card_attrs( $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="bg-surface-container-lowest rounded-lg p-space-sm flex flex-col justify-between shadow-sm hover:shadow-lg transition-all group">
<div class="relative w-full aspect-square rounded-DEFAULT overflow-hidden bg-surface-container-low mb-space-sm">
<a href="<?php echo esc_url( $c['url'] ); ?>"><img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" src="<?php echo esc_url( $c['image'] ); ?>" alt="<?php echo esc_attr( $c['name'] ); ?>" loading="lazy"></a>
<?php if ( $c['discount'] ) : ?><div class="absolute top-2 left-2 bg-secondary text-on-secondary font-label-sm text-label-sm font-bold px-2.5 py-1 rounded-full shadow-sm -rotate-3">-<?php echo (int) $c['discount']; ?>%</div><?php endif; ?>
<div class="absolute top-2 right-2 flex flex-col gap-1"><button class="w-8 h-8 rounded-full bg-surface-container-lowest/90 hover:bg-surface-container-lowest text-secondary flex items-center justify-center shadow-sm transition-transform active:scale-90" <?php echo nexxi_kids_wishlist_attrs( $c['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><span class="material-symbols-outlined text-[18px]">favorite</span></button></div>
<?php if ( null !== $c['left'] ) : ?><div class="absolute bottom-2 left-2 bg-surface-container-lowest/90 backdrop-blur-sm px-2 py-0.5 rounded-full font-label-sm text-label-sm text-secondary font-bold flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">local_fire_department</span> Còn <?php echo (int) $c['left']; ?> chiếc</div><?php endif; ?>
</div>
<div class="space-y-space-xs flex-1 flex flex-col justify-between">
<div>
<div class="flex items-center justify-between text-body-sm text-outline mb-1">
<?php if ( $c['reviews'] ) : ?><span class="flex items-center gap-0.5 text-secondary font-bold"><?php echo nexxi_kids_star_icon( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( number_format( $c['rating'], 1 ) ); ?> <span class="text-on-surface-variant font-normal">(<?php echo (int) $c['reviews']; ?>)</span></span><?php else : ?><span class="text-on-surface-variant">Mẫu mới</span><?php endif; ?>
<span class="font-label-sm text-label-sm text-on-surface-variant"><?php echo esc_html( $c['material'] ? $c['material'] : $c['category'] ); ?></span>
</div>
<h3 class="font-title-md text-title-md text-primary font-bold line-clamp-2 group-hover:text-secondary transition-colors"><a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a></h3>
</div>
<div class="pt-space-xs space-y-space-xs">
<div class="flex items-baseline gap-2"><span class="font-headline-sm text-headline-sm text-secondary font-bold"><?php echo esc_html( $c['priceFrom'] ); ?></span><?php if ( $c['regular'] ) : ?><span class="font-body-sm text-body-sm text-outline line-through"><?php echo esc_html( $c['regular'] ); ?></span><?php endif; ?></div>
<?php if ( $c['saving'] ) : ?><div class="font-label-sm text-label-sm text-on-tertiary-container bg-tertiary-fixed px-2 py-0.5 rounded-md inline-block">Tiết kiệm: <?php echo esc_html( $c['saving'] ); ?></div><?php endif; ?>
<div class="space-y-1 pt-1"><div class="flex justify-between font-label-sm text-label-sm text-on-surface-variant"><span>Đã bán <?php echo (int) $c['soldPercent']; ?>%</span><span class="text-secondary font-bold"><?php echo esc_html( $status ); ?></span></div><div class="w-full bg-surface-container h-2 rounded-full overflow-hidden"><div class="bg-secondary-container h-full rounded-full" style="width: <?php echo (int) $c['soldPercent']; ?>%"></div></div></div>
<div class="pt-2 flex items-center justify-between gap-1">
<div class="flex items-center gap-1 text-[11px] font-bold text-on-surface"><?php echo nexxi_kids_card_sizes( $c, 'w-7 h-7 rounded-full bg-surface-container hover:bg-primary hover:text-on-primary transition-colors flex items-center justify-center', 3 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
<button class="bg-secondary-container hover:bg-secondary text-on-secondary px-space-sm py-1.5 rounded-full font-label-md text-label-md transition-all shadow-sm flex items-center gap-1" type="button" data-nexxi-add <?php disabled( ! $c['inStock'] ); ?>><span class="material-symbols-outlined text-[16px]">add_shopping_cart</span><span><?php echo esc_html( $c['inStock'] ? 'Mua' : 'Hết' ); ?></span></button>
</div>
</div>
</div>
</div>
	<?php
	return ob_get_clean();
}

/** Accessory / related card (product.html → Accessory Showcase Card). */
function nexxi_kids_card_related( WC_Product $product ) {
	$c = nexxi_kids_card_data( $product );
	$excerpt = wp_strip_all_tags( $product->get_short_description() );
	ob_start(); ?>
<div <?php echo nexxi_kids_card_attrs( $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="bg-surface-container-lowest p-space-md rounded-xl space-y-space-sm shadow-sm group">
<div class="aspect-square rounded-lg overflow-hidden bg-surface-container relative"><a href="<?php echo esc_url( $c['url'] ); ?>"><img class="w-full h-full object-cover group-hover:scale-105 transition-all duration-500" src="<?php echo esc_url( $c['image'] ); ?>" alt="<?php echo esc_attr( $c['name'] ); ?>" loading="lazy"></a><span class="absolute top-2 left-2 bg-surface-container-lowest/90 px-2 py-0.5 rounded-full font-label-sm text-label-sm text-primary"><?php echo esc_html( $c['category'] ); ?></span></div>
<div>
<h4 class="font-title-md text-title-md text-primary group-hover:text-secondary transition-colors"><a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a></h4>
<?php if ( $excerpt ) : ?><p class="font-body-sm text-body-sm text-on-surface-variant line-clamp-2"><?php echo esc_html( $excerpt ); ?></p><?php endif; ?>
<div class="flex items-center justify-between mt-space-xs pt-space-xs"><span class="font-label-lg text-label-lg text-secondary font-bold"><?php echo esc_html( $c['priceFrom'] ); ?></span><button class="w-8 h-8 rounded-full bg-surface-container-low hover:bg-secondary hover:text-on-secondary flex items-center justify-center transition-all" type="button" data-nexxi-add aria-label="<?php esc_attr_e( 'Thêm vào giỏ', 'nexxi-kids' ); ?>" <?php disabled( ! $c['inStock'] ); ?>><span class="material-symbols-outlined text-[18px]">add</span></button></div>
</div>
</div>
	<?php
	return ob_get_clean();
}

/** Empty-state block in Stitch style. */
function nexxi_kids_empty_state( $title, $text, $cta_label = '', $cta_url = '' ) {
	$out = '<div class="col-span-full bg-surface-container-lowest rounded-xl p-space-xl text-center shadow-sm"><span class="material-symbols-outlined text-secondary text-[40px]">child_care</span><p class="font-headline-sm text-headline-sm text-primary mt-space-sm">' . esc_html( $title ) . '</p><p class="font-body-md text-body-md text-on-surface-variant mt-space-xs">' . esc_html( $text ) . '</p>';
	if ( $cta_label ) { $out .= '<a class="inline-flex mt-space-md bg-secondary-container hover:bg-secondary text-on-secondary px-space-lg py-space-sm rounded-full font-label-md text-label-md transition-all" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_label ) . '</a>'; }
	return $out . '</div>';
}
