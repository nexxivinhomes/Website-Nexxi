<?php
/** WooCommerce cart data in the Stitch cart composition. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function nexxi_kids_stitch_cart_content() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return ''; }
	ob_start();
	$cart = WC()->cart;
	$count = $cart->get_cart_contents_count();
	$subtotal = (float) $cart->get_displayed_subtotal();
	$remaining = 0;
	$progress = 100;
	?>
	<main class="w-full pt-28 md:pt-36 bg-background min-h-screen"><div class="w-full px-margin-mobile md:px-margin py-space-lg max-w-[1440px] mx-auto">
		<nav aria-label="Breadcrumb" class="flex items-center gap-space-xs text-body-sm font-body-sm text-outline mb-space-md"><a class="hover:text-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">⌂ Trang Chủ</a><span>/</span><span class="text-primary font-bold">Giỏ Hàng Của Bé</span></nav>
		<div class="flex flex-wrap items-baseline justify-between gap-space-sm mb-space-lg"><div class="flex items-center gap-space-sm"><h1 class="font-display-lg text-display-lg-mobile md:text-display-lg text-primary tracking-tight">Giỏ Hàng Của Bé Yêu</h1><span class="bg-secondary-fixed text-on-secondary-fixed font-label-md text-label-md px-space-md py-1 rounded-full"><?php echo esc_html( $count ); ?> món tuyển chọn</span></div><a class="font-label-md text-label-md text-secondary hover:underline" href="<?php echo esc_url( nexxi_kids_stitch_url( 'shop.html' ) ); ?>">Tiếp tục mua sắm →</a></div>
		<?php wc_print_notices(); ?>
		<div class="bg-surface-container-lowest p-space-md rounded-lg shadow-sm mb-space-xl">
			<div class="flex items-center gap-space-sm mb-space-xs"><span class="material-symbols-outlined text-secondary">local_shipping</span><p class="font-label-lg text-label-lg text-primary"><strong class="text-secondary">Miễn Phí Ship Toàn Quốc</strong> cho đơn hàng của ba mẹ!</p></div>
			<div class="w-full h-3 bg-surface-container-high rounded-full overflow-hidden p-0.5"><div class="h-full bg-gradient-to-r from-secondary-container to-secondary rounded-full" style="width:<?php echo esc_attr( $progress ); ?>%"></div></div>
		</div>
		<?php if ( $cart->is_empty() ) : ?>
			<div class="bg-surface-container-lowest rounded-lg p-space-xl text-center"><p class="font-headline-md text-headline-md text-primary mb-space-lg">Giỏ hàng của bé đang trống.</p><a class="inline-flex bg-secondary-container text-on-secondary px-space-lg py-space-sm rounded-full" href="<?php echo esc_url( nexxi_kids_stitch_url( 'shop.html' ) ); ?>">Khám phá sản phẩm</a></div>
		<?php else :
			$combo_res  = function_exists( 'nexxi_kids_calculate_cart_combos' ) ? nexxi_kids_calculate_cart_combos( $cart ) : array();
			$has_combos = ! empty( $combo_res['enabled'] ) && ! empty( $combo_res['combos'] );
			?>
		<div class="grid grid-cols-1 lg:grid-cols-12 gap-gutter items-start">
			<div class="lg:col-span-8"><form class="woocommerce-cart-form bg-surface-container-lowest rounded-lg p-space-lg shadow-sm" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
				<?php if ( ! empty( $combo_res['enabled'] ) && ! empty( $combo_res['tiers'] ) ) : ?>
					<?php echo nexxi_kids_render_combo_bill_notice_html( 'cart_top' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
				<div class="hidden md:grid grid-cols-12 gap-space-sm pb-space-sm mb-space-md font-label-md text-label-md text-outline border-b border-surface-container-high"><div class="col-span-6">Tất cả sản phẩm (<?php echo esc_html( $count ); ?>)</div><div class="col-span-2 text-center">Đơn Giá</div><div class="col-span-2 text-center">Số Lượng</div><div class="col-span-2 text-right">Thành Tiền</div></div>

				<?php if ( $has_combos ) : ?>
					<!-- Hidden master quantity inputs synced automatically when split across Combo frames -->
					<div id="nexxi-cart-master-qtys" style="display:none;">
						<?php foreach ( $cart->get_cart() as $ci_key => $ci_val ) : ?>
							<input type="hidden" id="nx-master-qty-<?php echo esc_attr( $ci_key ); ?>" name="cart[<?php echo esc_attr( $ci_key ); ?>][qty]" value="<?php echo (int) $ci_val['quantity']; ?>">
						<?php endforeach; ?>
					</div>

					<div class="flex flex-col gap-space-lg">
						<?php foreach ( $combo_res['combos'] as $combo ) : ?>
							<!-- KHUNG COMBO SỐ LƯỢNG SẢN PHẨM -->
							<div class="nexxi-combo-frame" style="border:2px solid #fd6a49;border-radius:16px;background:linear-gradient(180deg,#fffaf8 0%,#ffffff 100%);padding:16px;box-shadow:0 6px 18px rgba(253,106,73,0.10);position:relative;">
								<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding-bottom:12px;margin-bottom:12px;border-bottom:1.5px dashed #ffdad2;">
									<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
										<span style="display:inline-flex;align-items:center;gap:5px;background:#fd6a49;color:#fff;font-weight:800;font-size:12.5px;padding:4px 12px;border-radius:999px;letter-spacing:0.02em;">
											🎁 <?php echo esc_html( $combo['badge_text'] ); ?>
										</span>
										<strong style="font-size:15px;color:#031444;">
											Khung Combo #<?php echo (int) $combo['index']; ?>: Gói <?php echo (int) $combo['qty']; ?> sản phẩm
										</strong>
									</div>
									<div style="font-size:13px;color:#0b6b2f;background:#e6f9ed;border:1px solid #86efac;padding:3px 11px;border-radius:999px;font-weight:700;">
										Đã giảm thêm: -<?php echo esc_html( nexxi_kids_money( $combo['discount'] ) ); ?> (Còn <?php echo esc_html( nexxi_kids_money( $combo['total_after'] ) ); ?>)
									</div>
								</div>

								<div class="flex flex-col gap-space-md">
									<?php foreach ( $combo['lines'] as $cline ) :
										$cart_item_key = $cline['cart_item_key'];
										$cart_item     = $cline['cart_item'];
										$product       = $cline['product'];
										if ( ! $product || ! $product->exists() ) { continue; }
										$permalink     = $product->is_visible() ? $product->get_permalink( $cart_item ) : '';
										$image         = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'w-full h-full object-cover', 'loading' => 'lazy' ) );
										$qty_in_combo  = (int) $cline['qty_in_combo'];
										$terms         = get_the_terms( $cart_item['product_id'], 'product_cat' );
										$category      = $terms && ! is_wp_error( $terms ) ? $terms[0]->name : 'NEXXI Kids';
										$title         = nexxi_kids_cart_item_title( $cart_item );
										?>
										<div class="bg-surface-container-lowest p-space-md rounded-DEFAULT border border-secondary-fixed/60 transition-all flex flex-col md:grid md:grid-cols-12 gap-space-md items-center">
											<div class="w-full md:col-span-6 flex items-center gap-space-md">
												<a class="w-20 h-24 rounded-DEFAULT bg-secondary-fixed/30 overflow-hidden shrink-0 relative" href="<?php echo esc_url( $permalink ); ?>">
													<?php echo wp_kses_post( $image ); ?>
													<span style="position:absolute;top:4px;left:4px;background:#fd6a49;color:#fff;font-size:9.5px;font-weight:800;padding:1px 6px;border-radius:999px;box-shadow:0 2px 4px rgba(0,0,0,0.15);">
														<?php echo esc_html( $combo['badge_text'] ); ?>
													</span>
												</a>
												<div class="flex flex-col gap-1 min-w-0">
													<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
														<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider"><?php echo esc_html( $category ); ?></span>
														<span style="display:inline-block;background:#fff0ed;color:#ae3115;border:1px solid #ffdad2;font-size:10.5px;font-weight:800;padding:1px 7px;border-radius:999px;">
															<?php echo esc_html( $combo['badge_text'] ); ?>
														</span>
													</div>
													<a class="font-headline-sm text-title-md text-primary hover:text-secondary" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
													<?php echo nexxi_kids_cart_item_chips( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												</div>
											</div>
											<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center font-label-md text-label-md text-on-surface">
												<span class="md:hidden text-outline">Đơn giá:</span>
												<?php echo wp_kses_post( $cart->get_product_price( $product ) ); ?>
											</div>
											<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center">
												<span class="md:hidden text-outline">Số lượng trong Combo:</span>
												<input class="qty nexxi-cart-qty nexxi-split-qty bg-surface-container rounded-full text-center font-label-md text-label-md text-primary"
													data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>"
													oninput="nexxiSyncSplitCartQty('<?php echo esc_js( $cart_item_key ); ?>')"
													aria-label="Số lượng <?php echo esc_attr( $product->get_name() ); ?>"
													type="number" min="0" max="<?php echo esc_attr( $product->get_max_purchase_quantity() ); ?>"
													value="<?php echo esc_attr( $qty_in_combo ); ?>">
											</div>
											<div class="w-full md:col-span-2 flex justify-between md:justify-end items-center gap-space-sm">
												<div class="text-right">
													<div class="text-xs text-outline line-through"><?php echo esc_html( nexxi_kids_money( $cline['line_subtotal'] ) ); ?></div>
													<span class="font-title-md text-title-md text-secondary font-bold"><?php echo esc_html( nexxi_kids_money( $cline['line_after_combo'] ) ); ?></span>
												</div>
												<a class="w-8 h-8 rounded-full hover:bg-error-container text-outline hover:text-error flex items-center justify-center" href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>" aria-label="Xóa <?php echo esc_attr( $product->get_name() ); ?> khỏi giỏ"><span class="material-symbols-outlined text-[18px]">delete_outline</span></a>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>

						<?php if ( ! empty( $combo_res['leftover_items'] ) ) : ?>
							<!-- SẢN PHẨM LẺ NGOÀI COMBO -->
							<div style="border:1.5px dashed #cbd5e1;border-radius:16px;background:#f8fafc;padding:16px;">
								<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding-bottom:10px;margin-bottom:12px;border-bottom:1px dashed #cbd5e1;">
									<strong style="font-size:14.5px;color:#031444;">
										🛍️ Sản phẩm lẻ (<?php echo (int) $combo_res['leftover_count']; ?> sản phẩm — Chưa đủ số lượng ghép thêm Combo)
									</strong>
									<?php if ( ! empty( $combo_res['next_tier_hint'] ) ) : ?>
										<span style="font-size:12px;color:#9a3412;background:#ffedd5;padding:3px 10px;border-radius:999px;font-weight:700;">
											💡 <?php echo esc_html( $combo_res['next_tier_hint'] ); ?>
										</span>
									<?php endif; ?>
								</div>
								<div class="flex flex-col gap-space-md">
									<?php foreach ( $combo_res['leftover_items'] as $litem ) :
										$cart_item_key = $litem['cart_item_key'];
										$cart_item     = $litem['cart_item'];
										$product       = $litem['product'];
										if ( ! $product || ! $product->exists() ) { continue; }
										$permalink     = $product->is_visible() ? $product->get_permalink( $cart_item ) : '';
										$image         = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'w-full h-full object-cover', 'loading' => 'lazy' ) );
										$qty_left      = (int) $litem['qty_leftover'];
										$terms         = get_the_terms( $cart_item['product_id'], 'product_cat' );
										$category      = $terms && ! is_wp_error( $terms ) ? $terms[0]->name : 'NEXXI Kids';
										$title         = nexxi_kids_cart_item_title( $cart_item );
										?>
										<div class="bg-surface-container-lowest p-space-md rounded-DEFAULT transition-all flex flex-col md:grid md:grid-cols-12 gap-space-md items-center">
											<div class="w-full md:col-span-6 flex items-center gap-space-md"><a class="w-20 h-24 rounded-DEFAULT bg-secondary-fixed/30 overflow-hidden shrink-0" href="<?php echo esc_url( $permalink ); ?>"><?php echo wp_kses_post( $image ); ?></a><div class="flex flex-col gap-1 min-w-0"><span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider"><?php echo esc_html( $category ); ?> · Sản phẩm lẻ</span><a class="font-headline-sm text-title-md text-primary hover:text-secondary" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a><?php echo nexxi_kids_cart_item_chips( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></div>
											<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center font-label-md text-label-md text-on-surface"><span class="md:hidden text-outline">Đơn giá:</span><?php echo wp_kses_post( $cart->get_product_price( $product ) ); ?></div>
											<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center">
												<span class="md:hidden text-outline">Số lượng lẻ:</span>
												<input class="qty nexxi-cart-qty nexxi-split-qty bg-surface-container rounded-full text-center font-label-md text-label-md text-primary"
													data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>"
													oninput="nexxiSyncSplitCartQty('<?php echo esc_js( $cart_item_key ); ?>')"
													aria-label="Số lượng <?php echo esc_attr( $product->get_name() ); ?>"
													type="number" min="0" max="<?php echo esc_attr( $product->get_max_purchase_quantity() ); ?>"
													value="<?php echo esc_attr( $qty_left ); ?>">
											</div>
											<div class="w-full md:col-span-2 flex justify-between md:justify-end items-center gap-space-sm"><span class="font-title-md text-title-md text-secondary font-bold"><?php echo esc_html( nexxi_kids_money( $litem['line_subtotal'] ) ); ?></span><a class="w-8 h-8 rounded-full hover:bg-error-container text-outline hover:text-error flex items-center justify-center" href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>" aria-label="Xóa <?php echo esc_attr( $product->get_name() ); ?> khỏi giỏ"><span class="material-symbols-outlined text-[18px]">delete_outline</span></a></div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>
					</div>
					<script>
					function nexxiSyncSplitCartQty(cartKey) {
						var inputs = document.querySelectorAll('.nexxi-split-qty[data-cart-key="' + cartKey + '"]');
						var sum = 0;
						inputs.forEach(function(inp) { sum += Math.max(0, parseInt(inp.value || '0', 10) || 0); });
						var master = document.getElementById('nx-master-qty-' + cartKey);
						if (master) master.value = sum;
					}
					</script>
				<?php else : ?>
				<div class="flex flex-col gap-space-md">
				<?php foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) :
					$product = $cart_item['data'];
					if ( ! $product || ! $product->exists() ) { continue; }
					$permalink = $product->is_visible() ? $product->get_permalink( $cart_item ) : '';
					$image = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'w-full h-full object-cover', 'loading' => 'lazy' ) );
					$quantity = $cart_item['quantity'];
					$terms = get_the_terms( $cart_item['product_id'], 'product_cat' );
					$category = $terms && ! is_wp_error( $terms ) ? $terms[0]->name : 'NEXXI Kids';
					$title = nexxi_kids_cart_item_title( $cart_item );
					?>
					<div class="bg-surface-container-low/40 hover:bg-surface-container-low p-space-md rounded-DEFAULT transition-all flex flex-col md:grid md:grid-cols-12 gap-space-md items-center">
						<div class="w-full md:col-span-6 flex items-center gap-space-md"><a class="w-20 h-24 rounded-DEFAULT bg-secondary-fixed/30 overflow-hidden shrink-0" href="<?php echo esc_url( $permalink ); ?>"><?php echo wp_kses_post( $image ); ?></a><div class="flex flex-col gap-1 min-w-0"><span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider"><?php echo esc_html( $category ); ?></span><a class="font-headline-sm text-title-md text-primary hover:text-secondary" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a><?php echo nexxi_kids_cart_item_chips( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></div>
						<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center font-label-md text-label-md text-on-surface"><span class="md:hidden text-outline">Đơn giá:</span><?php echo wp_kses_post( $cart->get_product_price( $product ) ); ?></div>
						<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center"><span class="md:hidden text-outline">Số lượng:</span><input class="qty nexxi-cart-qty bg-surface-container rounded-full text-center font-label-md text-label-md text-primary" aria-label="Số lượng <?php echo esc_attr( $product->get_name() ); ?>" type="number" min="0" max="<?php echo esc_attr( $product->get_max_purchase_quantity() ); ?>" name="cart[<?php echo esc_attr( $cart_item_key ); ?>][qty]" value="<?php echo esc_attr( $quantity ); ?>"></div>
						<div class="w-full md:col-span-2 flex justify-between md:justify-end items-center gap-space-sm"><span class="font-title-md text-title-md text-secondary font-bold"><?php echo wp_kses_post( $cart->get_product_subtotal( $product, $quantity ) ); ?></span><a class="w-8 h-8 rounded-full hover:bg-error-container text-outline hover:text-error flex items-center justify-center" href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>" aria-label="Xóa <?php echo esc_attr( $product->get_name() ); ?> khỏi giỏ"><span class="material-symbols-outlined text-[18px]">delete_outline</span></a></div>
					</div>
				<?php endforeach; ?>
				</div>
				<?php endif; ?>
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

/* ---------- Variation ("Phân loại") display in cart, checkout and orders ---------- */

/** Product title without the "– TRANG, 1" suffix WooCommerce bakes into variation names. */
function nexxi_kids_cart_item_title( $cart_item ) {
	$product = $cart_item['data'];
	if ( ! empty( $cart_item['variation_id'] ) ) {
		$parent = wc_get_product( $cart_item['product_id'] );
		if ( $parent ) { return $parent->get_name(); }
	}
	return $product ? $product->get_name() : '';
}

/** [ [label, value], ... ] for the chosen variation attributes of a cart item. */
function nexxi_kids_cart_item_variation( $cart_item ) {
	$rows = array();
	if ( empty( $cart_item['variation'] ) || ! is_array( $cart_item['variation'] ) ) { return $rows; }
	$product = $cart_item['data'];
	foreach ( $cart_item['variation'] as $key => $value ) {
		if ( '' === (string) $value ) { continue; }
		$name = 0 === strpos( $key, 'attribute_' ) ? substr( $key, 10 ) : $key;
		if ( taxonomy_exists( $name ) ) {
			$term = get_term_by( 'slug', $value, $name );
			$value = $term && ! is_wp_error( $term ) ? $term->name : $value;
		}
		$label = wc_attribute_label( $name, $product );
		$rows[] = array( nexxi_kids_attr_label( $label ), $value );
	}
	return $rows;
}

/** "Phân loại" chips for the Stitch cart row (variation + extra item data from plugins). */
function nexxi_kids_cart_item_chips( $cart_item ) {
	$rows = nexxi_kids_cart_item_variation( $cart_item );
	foreach ( (array) apply_filters( 'woocommerce_get_item_data', array(), $cart_item ) as $data ) {
		if ( empty( $data['hidden'] ) && isset( $data['key'] ) ) {
			$rows[] = array( wp_strip_all_tags( $data['key'] ), wp_strip_all_tags( isset( $data['display'] ) && '' !== $data['display'] ? $data['display'] : ( isset( $data['value'] ) ? $data['value'] : '' ) ) );
		}
	}
	if ( ! $rows ) { return ''; }
	$out = '<div class="nexxi-variant-chips flex flex-wrap items-center gap-1.5 pt-0.5"><span class="font-label-sm text-label-sm text-outline">Phân loại:</span>';
	foreach ( $rows as $row ) {
		$out .= '<span class="inline-flex items-center gap-1 bg-secondary-fixed/60 text-on-secondary-fixed rounded-full px-2.5 py-0.5 font-label-sm text-label-sm"><span class="opacity-70">' . esc_html( $row[0] ) . ':</span><strong class="font-bold">' . esc_html( $row[1] ) . '</strong></span>';
	}
	return $out . '</div>';
}

// New/edited variations keep a clean title; attributes are listed separately.
add_filter( 'woocommerce_product_variation_title_include_attributes', '__return_false' );
// Always list the chosen attributes (WooCommerce hides them when they appear in the title).
add_filter( 'woocommerce_is_attribute_in_product_name', '__return_false' );

/** Checkout review / mini cart: parent title, attributes rendered underneath by WooCommerce. */
function nexxi_kids_cart_item_name( $name, $cart_item ) {
	if ( empty( $cart_item['variation_id'] ) ) { return $name; }
	$title = nexxi_kids_cart_item_title( $cart_item );
	if ( '' === $title ) { return $name; }
	// Keep a link if WooCommerce rendered one (mini cart).
	if ( false !== strpos( $name, '<a ' ) && preg_match( '~<a [^>]*>~', $name, $m ) ) {
		return $m[0] . esc_html( $title ) . '</a>';
	}
	return esc_html( $title );
}
add_filter( 'woocommerce_cart_item_name', 'nexxi_kids_cart_item_name', 10, 2 );

/** Orders / emails: store the clean title, attributes are saved as item meta. */
function nexxi_kids_order_item_name( $item, $cart_item_key, $values ) {
	if ( ! empty( $values['variation_id'] ) ) {
		$title = nexxi_kids_cart_item_title( $values );
		if ( $title ) { $item->set_name( $title ); }
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'nexxi_kids_order_item_name', 20, 3 );
