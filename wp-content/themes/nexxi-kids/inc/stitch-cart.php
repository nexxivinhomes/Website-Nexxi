<?php
/** WooCommerce cart data in the Stitch cart composition. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function nexxi_kids_stitch_cart_content() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return ''; }
	ob_start();
	$cart = WC()->cart;
	$count = $cart->get_cart_contents_count();
	$subtotal = (float) $cart->get_displayed_subtotal();
	$remaining = max( 0, 299000 - $subtotal );
	$progress = min( 100, 299000 > 0 ? round( $subtotal / 299000 * 100 ) : 100 );
	?>
	<main class="w-full pt-28 md:pt-36 bg-background min-h-screen"><div class="w-full px-margin-mobile md:px-margin py-space-lg max-w-[1440px] mx-auto">
		<nav aria-label="Breadcrumb" class="flex items-center gap-space-xs text-body-sm font-body-sm text-outline mb-space-md"><a class="hover:text-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">⌂ Trang Chủ</a><span>/</span><span class="text-primary font-bold">Giỏ Hàng Của Bé</span></nav>
		<div class="flex flex-wrap items-baseline justify-between gap-space-sm mb-space-lg"><div class="flex items-center gap-space-sm"><h1 class="font-display-lg text-display-lg-mobile md:text-display-lg text-primary tracking-tight">Giỏ Hàng Của Bé Yêu</h1><span class="bg-secondary-fixed text-on-secondary-fixed font-label-md text-label-md px-space-md py-1 rounded-full"><?php echo esc_html( $count ); ?> món tuyển chọn</span></div><a class="font-label-md text-label-md text-secondary hover:underline" href="<?php echo esc_url( nexxi_kids_stitch_url( 'shop.html' ) ); ?>">Tiếp tục mua sắm →</a></div>
		<?php wc_print_notices(); ?>
		<div class="bg-surface-container-lowest p-space-md rounded-lg shadow-sm mb-space-xl">
			<div class="flex items-center gap-space-sm mb-space-xs"><span class="material-symbols-outlined text-secondary">local_shipping</span><p class="font-label-lg text-label-lg text-primary"><?php echo $remaining > 0 ? 'Mua thêm ' . wp_kses_post( wc_price( $remaining ) ) . ' để nhận <strong class="text-secondary">Freeship Toàn Quốc</strong>' : '<strong class="text-secondary">Đơn hàng đã đạt mốc Freeship Toàn Quốc!</strong>'; ?></p></div>
			<div class="w-full h-3 bg-surface-container-high rounded-full overflow-hidden p-0.5"><div class="h-full bg-gradient-to-r from-secondary-container to-secondary rounded-full" style="width:<?php echo esc_attr( $progress ); ?>%"></div></div>
		</div>
		<?php if ( $cart->is_empty() ) : ?>
			<div class="bg-surface-container-lowest rounded-lg p-space-xl text-center"><p class="font-headline-md text-headline-md text-primary mb-space-lg">Giỏ hàng của bé đang trống.</p><a class="inline-flex bg-secondary-container text-on-secondary px-space-lg py-space-sm rounded-full" href="<?php echo esc_url( nexxi_kids_stitch_url( 'shop.html' ) ); ?>">Khám phá sản phẩm</a></div>
		<?php else : ?>
		<div class="grid grid-cols-1 lg:grid-cols-12 gap-gutter items-start">
			<div class="lg:col-span-8"><form class="woocommerce-cart-form bg-surface-container-lowest rounded-lg p-space-lg shadow-sm" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
				<div class="hidden md:grid grid-cols-12 gap-space-sm pb-space-sm mb-space-md font-label-md text-label-md text-outline border-b border-surface-container-high"><div class="col-span-6">Tất cả sản phẩm (<?php echo esc_html( $count ); ?>)</div><div class="col-span-2 text-center">Đơn Giá</div><div class="col-span-2 text-center">Số Lượng</div><div class="col-span-2 text-right">Thành Tiền</div></div>
				<div class="flex flex-col gap-space-md">
				<?php foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) :
					$product = $cart_item['data'];
					if ( ! $product || ! $product->exists() ) { continue; }
					$permalink = $product->is_visible() ? $product->get_permalink( $cart_item ) : '';
					$image = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'w-full h-full object-cover', 'loading' => 'lazy' ) );
					$quantity = $cart_item['quantity'];
					$terms = get_the_terms( $product->get_id(), 'product_cat' );
					$category = $terms && ! is_wp_error( $terms ) ? $terms[0]->name : 'NEXXI Kids';
					?>
					<div class="bg-surface-container-low/40 hover:bg-surface-container-low p-space-md rounded-DEFAULT transition-all flex flex-col md:grid md:grid-cols-12 gap-space-md items-center">
						<div class="w-full md:col-span-6 flex items-center gap-space-md"><a class="w-20 h-24 rounded-DEFAULT bg-secondary-fixed/30 overflow-hidden shrink-0" href="<?php echo esc_url( $permalink ); ?>"><?php echo wp_kses_post( $image ); ?></a><div class="flex flex-col gap-1 min-w-0"><span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider"><?php echo esc_html( $category ); ?></span><a class="font-headline-sm text-title-md text-primary hover:text-secondary" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $product->get_name() ); ?></a><div class="font-body-sm text-body-sm text-on-surface-variant"><?php echo wp_kses_post( wc_get_formatted_cart_item_data( $cart_item ) ); ?></div></div></div>
						<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center font-label-md text-label-md text-on-surface"><span class="md:hidden text-outline">Đơn giá:</span><?php echo wp_kses_post( $cart->get_product_price( $product ) ); ?></div>
						<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center"><span class="md:hidden text-outline">Số lượng:</span><input class="qty nexxi-cart-qty bg-surface-container rounded-full text-center font-label-md text-label-md text-primary" aria-label="Số lượng <?php echo esc_attr( $product->get_name() ); ?>" type="number" min="0" max="<?php echo esc_attr( $product->get_max_purchase_quantity() ); ?>" name="cart[<?php echo esc_attr( $cart_item_key ); ?>][qty]" value="<?php echo esc_attr( $quantity ); ?>"></div>
						<div class="w-full md:col-span-2 flex justify-between md:justify-end items-center gap-space-sm"><span class="font-title-md text-title-md text-secondary font-bold"><?php echo wp_kses_post( $cart->get_product_subtotal( $product, $quantity ) ); ?></span><a class="w-8 h-8 rounded-full hover:bg-error-container text-outline hover:text-error flex items-center justify-center" href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>" aria-label="Xóa <?php echo esc_attr( $product->get_name() ); ?> khỏi giỏ"><span class="material-symbols-outlined text-[18px]">delete_outline</span></a></div>
					</div>
				<?php endforeach; ?>
				</div>
				<div class="flex flex-wrap items-center justify-between gap-space-sm pt-space-lg"><div class="flex gap-space-xs"><input class="bg-surface-container-low px-space-md py-space-xs rounded-full font-body-md text-body-md" type="text" name="coupon_code" placeholder="Mã giảm giá" aria-label="Mã giảm giá"><button class="bg-secondary-fixed text-secondary hover:bg-secondary-container hover:text-on-secondary px-space-md py-space-xs rounded-full font-label-md text-label-md" type="submit" name="apply_coupon" value="Áp dụng">Áp dụng</button></div><button class="bg-surface-container text-primary hover:bg-primary hover:text-on-primary px-space-md py-space-xs rounded-full font-label-md text-label-md" type="submit" name="update_cart" value="Cập nhật giỏ hàng">Cập nhật giỏ hàng</button></div>
				<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
			</form></div>
			<aside class="lg:col-span-4"><div class="bg-surface-container-lowest rounded-lg p-space-lg shadow-sm nexxi-stitch-cart-totals"><h2 class="font-headline-md text-headline-md text-primary mb-space-md">Tóm Tắt Giỏ Hàng</h2><?php woocommerce_cart_totals(); ?></div></aside>
		</div>
		<?php endif; ?>
	</div></main>
	<?php
	return ob_get_clean();
}
