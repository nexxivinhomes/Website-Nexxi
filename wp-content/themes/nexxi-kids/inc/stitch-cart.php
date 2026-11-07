<?php
/** WooCommerce cart data in the Stitch cart composition. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Inspect real stock / availability status of a cart item (matching WC_Cart::check_cart_item_stock 1-to-1,
 * including held stock from pending orders).
 *
 * @param array        $cart_item WooCommerce cart item array.
 * @param WC_Cart|null $cart      Optional WC_Cart instance.
 * @return array{type:string,available:int,message:string}|null Null if valid, or issue descriptor array.
 */
function nexxi_kids_get_cart_item_stock_issue( $cart_item, $cart = null ) {
	$product = ( is_array( $cart_item ) && isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ) ? $cart_item['data'] : null;
	if ( ! $product || ! $product->exists() || 'trash' === $product->get_status() ) {
		return array(
			'type'      => 'out_of_stock',
			'available' => 0,
			'message'   => '⚠️ Phân loại này vừa hết hàng — Vui lòng bấm 🗑️ xóa hoặc chọn size khác để thanh toán',
		);
	}

	if ( ! $product->is_in_stock() ) {
		return array(
			'type'      => 'out_of_stock',
			'available' => 0,
			'message'   => '⚠️ Phân loại này vừa hết hàng — Vui lòng bấm 🗑️ xóa hoặc chọn size khác để thanh toán',
		);
	}

	$cart_obj       = ( $cart instanceof WC_Cart ) ? $cart : ( function_exists( 'WC' ) && WC()->cart ? WC()->cart : null );
	$qty_in_cart_mp = $cart_obj ? $cart_obj->get_cart_item_quantities() : array();
	$managed_id     = $product->get_stock_managed_by_id();
	$total_item_qty = isset( $qty_in_cart_mp[ $managed_id ] )
		? (int) $qty_in_cart_mp[ $managed_id ]
		: max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );

	if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
		$raw_stock  = (int) $product->get_stock_quantity();
		$held_stock = 0;
		if ( function_exists( 'wc_get_held_stock_quantity' ) && function_exists( 'WC' ) && WC()->session ) {
			$order_awaiting = absint( WC()->session->get( 'order_awaiting_payment' ) );
			$session_oid    = $order_awaiting ? $order_awaiting : absint( WC()->session->get( 'store_api_draft_order', 0 ) );
			$held_stock     = max( 0, (int) wc_get_held_stock_quantity( $product, $session_oid ) );
		}
		$avail = max( 0, $raw_stock - $held_stock );

		if ( ! $product->has_enough_stock( $total_item_qty ) || $raw_stock < ( $held_stock + $total_item_qty ) ) {
			if ( $avail <= 0 ) {
				return array(
					'type'      => 'out_of_stock',
					'available' => 0,
					'message'   => '⚠️ Phân loại này vừa hết hàng — Vui lòng bấm 🗑️ xóa hoặc chọn size khác để thanh toán',
				);
			}
			return array(
				'type'      => 'insufficient_stock',
				'available' => $avail,
				'message'   => sprintf( '⚠️ Kho chỉ còn %d sản phẩm — Vui lòng giảm số lượng', $avail ),
			);
		}
	}

	return null;
}

/**
 * Render inline red alert badge below variation chips for an out-of-stock / insufficient-stock cart row.
 */
function nexxi_kids_render_cart_item_stock_badge( $stock_issue ) {
	if ( empty( $stock_issue ) || ! is_array( $stock_issue ) || empty( $stock_issue['message'] ) ) {
		return '';
	}
	return '<div class="nexxi-cart-stock-alert" style="margin-top:4px;display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:10px;padding:5px 10px;font-size:12px;font-weight:700;line-height:1.4;">' . esc_html( $stock_issue['message'] ) . '</div>';
}

/**
 * Render an always-visible [-][qty][+] stepper control for Cart items.
 */
function nexxi_kids_render_cart_qty_stepper( $cart_item_key, $product, $qty_val, $is_split = false, $stock_issue = null ) {
	$max_qty = $product ? (int) $product->get_max_purchase_quantity() : -1;
	if ( is_array( $stock_issue ) && isset( $stock_issue['available'] ) ) {
		$avail_cap = max( 0, (int) $stock_issue['available'] );
		$max_qty   = ( $max_qty > 0 ) ? min( $max_qty, $avail_cap ) : $avail_cap;
	}
	$disable_plus = ( ! $product || ! $product->is_in_stock() || 0 === $max_qty );
	$max_attr     = $max_qty > 0 ? (string) $max_qty : ( 0 === $max_qty ? '0' : '' );
	$p_name       = $product ? $product->get_name() : '';
	$plus_style   = 'width:30px;height:30px;border-radius:999px;border:none;background:#fd6a49;color:#ffffff;font-weight:800;font-size:17px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 1px 3px rgba(253,106,73,0.28);flex-shrink:0;user-select:none;transition:background .15s,color .15s;';
	if ( $disable_plus ) {
		$plus_style .= 'opacity:0.4;cursor:not-allowed;box-shadow:none;';
	}
	ob_start();
	?>
	<div class="nexxi-qty-stepper" style="display:inline-flex;align-items:center;background:#f3f1ed;border:1.5px solid #e4e2de;border-radius:999px;padding:2px;height:38px;box-sizing:border-box;">
		<button type="button"
			class="nexxi-qty-step-btn"
			onclick="window.nexxiStepCartQty && window.nexxiStepCartQty(this, -1)"
			aria-label="Giảm số lượng <?php echo esc_attr( $p_name ); ?>"
			style="width:30px;height:30px;border-radius:999px;border:none;background:#ffffff;color:#031444;font-weight:800;font-size:17px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 1px 2px rgba(3,20,68,0.08);flex-shrink:0;user-select:none;transition:background .15s,color .15s;">
			&minus;
		</button>
		<input class="qty nexxi-cart-qty<?php echo $is_split ? ' nexxi-split-qty' : ''; ?> bg-transparent text-center font-label-md text-label-md text-primary"
			data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>"
			<?php if ( $is_split ) : ?>
				oninput="window.nexxiSyncSplitCartQty && window.nexxiSyncSplitCartQty('<?php echo esc_js( $cart_item_key ); ?>')"
			<?php else : ?>
				name="cart[<?php echo esc_attr( $cart_item_key ); ?>][qty]"
			<?php endif; ?>
			aria-label="Số lượng <?php echo esc_attr( $p_name ); ?>"
			type="number"
			inputmode="numeric"
			min="0"
			<?php if ( '' !== $max_attr ) : ?>max="<?php echo esc_attr( $max_attr ); ?>"<?php endif; ?>
			value="<?php echo esc_attr( (int) $qty_val ); ?>"
			style="width:40px;min-height:30px;height:30px;border:none;background:transparent;text-align:center;font-weight:700;font-size:14px;color:#031444;padding:0;margin:0;outline:none;-moz-appearance:textfield;appearance:textfield;">
		<button type="button"
			class="nexxi-qty-step-btn"
			<?php if ( $disable_plus ) : ?>disabled aria-disabled="true"<?php else : ?>onclick="window.nexxiStepCartQty && window.nexxiStepCartQty(this, 1)"<?php endif; ?>
			aria-label="Tăng số lượng <?php echo esc_attr( $p_name ); ?>"
			style="<?php echo esc_attr( $plus_style ); ?>">
			&#43;
		</button>
	</div>
	<?php
	return ob_get_clean();
}

function nexxi_kids_stitch_cart_content() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return ''; }
	ob_start();
	$cart = WC()->cart;
	$req_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( 'GET' === $req_method && ! $cart->is_empty() ) {
		do_action( 'woocommerce_check_cart_items' );
	}
	$count = $cart->get_cart_contents_count();
	$subtotal = (float) $cart->get_displayed_subtotal();
	$remaining = 0;
	$progress = 100;
	?>
	<main id="nexxi-stitch-cart-main" class="w-full pt-28 md:pt-36 bg-background min-h-screen"><div class="w-full px-margin-mobile md:px-margin py-space-lg max-w-[1440px] mx-auto">
		<nav aria-label="Breadcrumb" class="flex items-center gap-space-xs text-body-sm font-body-sm text-outline mb-space-md"><a class="hover:text-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">⌂ Trang Chủ</a><span>/</span><span class="text-primary font-bold">Giỏ Hàng Của Bé</span></nav>
		<div class="flex flex-wrap items-baseline justify-between gap-space-sm mb-space-lg"><div class="flex items-center gap-space-sm"><h1 class="font-display-lg text-display-lg-mobile md:text-display-lg text-primary tracking-tight">Giỏ Hàng Của Bé Yêu</h1><span data-nexxi-cart-page-count class="bg-secondary-fixed text-on-secondary-fixed font-label-md text-label-md px-space-md py-1 rounded-full"><?php echo esc_html( $count ); ?> món tuyển chọn</span></div><a class="font-label-md text-label-md text-secondary hover:underline" href="<?php echo esc_url( nexxi_kids_stitch_url( 'shop.html' ) ); ?>">Tiếp tục mua sắm →</a></div>
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
				<div class="hidden md:grid grid-cols-12 gap-space-sm pb-space-sm mb-space-md font-label-md text-label-md text-outline border-b border-surface-container-high"><div class="col-span-6" data-nexxi-cart-all-count>Tất cả sản phẩm (<?php echo esc_html( $count ); ?>)</div><div class="col-span-2 text-center">Đơn Giá</div><div class="col-span-2 text-center">Số Lượng</div><div class="col-span-2 text-right">Thành Tiền</div></div>

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
										<span style="font-size:12px;color:#64748b;font-weight:600;">
											(Giá sau giảm SP/FS: <?php echo esc_html( nexxi_kids_money( $combo['subtotal'] ) ); ?>)
										</span>
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
										$has_item_sale = ! empty( $cline['reg_price'] ) && (float) $cline['reg_price'] > (float) $cline['unit_price'];
										$item_sale_pct = (int) ( $cline['prod_fs_pct'] ?? 0 );
										$stock_issue   = nexxi_kids_get_cart_item_stock_issue( $cart_item, $cart );
										$row_alert_css = $stock_issue ? 'border:1.5px solid #ef4444;background:#fff5f5;' : '';
										?>
										<div class="nexxi-cart-item-row bg-surface-container-lowest p-space-md rounded-DEFAULT border border-secondary-fixed/60 transition-all flex flex-col md:grid md:grid-cols-12 gap-space-md items-center" data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"<?php echo $row_alert_css ? ' style="' . esc_attr( $row_alert_css ) . '"' : ''; ?>>
											<div class="w-full md:col-span-6 flex items-center gap-space-md">
												<a class="w-20 h-24 rounded-DEFAULT bg-secondary-fixed/30 overflow-hidden shrink-0" href="<?php echo esc_url( $permalink ); ?>">
													<?php echo wp_kses_post( $image ); ?>
												</a>
												<div class="flex flex-col gap-1 min-w-0">
													<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
														<span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider"><?php echo esc_html( $category ); ?></span>
														<?php if ( $has_item_sale && $item_sale_pct > 0 ) : ?>
															<span style="display:inline-block;background:#f1f5f9;color:#475569;font-size:10.5px;font-weight:700;padding:1px 7px;border-radius:999px;">
																Giảm SP/FS -<?php echo (int) $item_sale_pct; ?>%
															</span>
														<?php endif; ?>
														<span style="display:inline-block;background:#fff0ed;color:#ae3115;border:1px solid #ffdad2;font-size:10.5px;font-weight:800;padding:1px 7px;border-radius:999px;">
															<?php echo esc_html( $combo['badge_text'] ); ?>
														</span>
													</div>
													<a class="font-headline-sm text-title-md text-primary hover:text-secondary" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
													<?php echo nexxi_kids_cart_item_chips( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
													<?php echo nexxi_kids_render_cart_item_stock_badge( $stock_issue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												</div>
											</div>
											<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center font-label-md text-label-md text-on-surface">
												<span class="md:hidden text-outline">Đơn giá:</span>
												<span class="font-bold text-primary"><?php echo esc_html( nexxi_kids_money( $cline['unit_price'] ) ); ?></span>
											</div>
											<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center">
												<span class="md:hidden text-outline">Số lượng trong Combo:</span>
												<?php echo nexxi_kids_render_cart_qty_stepper( $cart_item_key, $product, $qty_in_combo, true, $stock_issue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</div>
											<div class="w-full md:col-span-2 flex justify-between md:justify-end items-center gap-space-sm">
												<div class="text-right">
													<span class="font-title-md text-title-md text-secondary font-bold"><?php echo esc_html( nexxi_kids_money( $cline['line_after_combo'] ) ); ?></span>
												</div>
												<a class="nexxi-cart-remove-btn w-8 h-8 rounded-full hover:bg-error-container text-outline hover:text-error flex items-center justify-center transition-colors" href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>" data-nexxi-remove-key="<?php echo esc_attr( $cart_item_key ); ?>" data-nexxi-remove-qty="<?php echo (int) $qty_in_combo; ?>" aria-label="Xóa <?php echo esc_attr( $product->get_name() ); ?> khỏi giỏ"><span class="material-symbols-outlined text-[18px]">delete_outline</span></a>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>

						<?php if ( ! empty( $combo_res['leftover_items'] ) ) : ?>
							<!-- SẢN PHẨM LẺ NGOÀI COMBO -->
							<div class="nexxi-leftover-frame" style="border:1.5px dashed #cbd5e1;border-radius:16px;background:#f8fafc;padding:16px;">
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
										$has_left_sale = ! empty( $litem['reg_price'] ) && (float) $litem['reg_price'] > (float) $litem['unit_price'];
										$stock_issue   = nexxi_kids_get_cart_item_stock_issue( $cart_item, $cart );
										$row_alert_css = $stock_issue ? 'border:1.5px solid #ef4444;background:#fff5f5;' : '';
										?>
										<div class="nexxi-cart-item-row bg-surface-container-lowest p-space-md rounded-DEFAULT transition-all flex flex-col md:grid md:grid-cols-12 gap-space-md items-center" data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"<?php echo $row_alert_css ? ' style="' . esc_attr( $row_alert_css ) . '"' : ''; ?>>
											<div class="w-full md:col-span-6 flex items-center gap-space-md"><a class="w-20 h-24 rounded-DEFAULT bg-secondary-fixed/30 overflow-hidden shrink-0" href="<?php echo esc_url( $permalink ); ?>"><?php echo wp_kses_post( $image ); ?></a><div class="flex flex-col gap-1 min-w-0"><span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider"><?php echo esc_html( $category ); ?> · Sản phẩm lẻ</span><a class="font-headline-sm text-title-md text-primary hover:text-secondary" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a><?php echo nexxi_kids_cart_item_chips( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo nexxi_kids_render_cart_item_stock_badge( $stock_issue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></div>
											<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center font-label-md text-label-md text-on-surface">
												<span class="md:hidden text-outline">Đơn giá:</span>
												<div class="text-right md:text-center">
													<?php if ( $has_left_sale ) : ?>
														<div class="text-xs text-outline line-through"><?php echo esc_html( nexxi_kids_money( $litem['reg_price'] ) ); ?></div>
													<?php endif; ?>
													<div class="font-bold text-primary"><?php echo esc_html( nexxi_kids_money( $litem['unit_price'] ) ); ?></div>
												</div>
											</div>
											<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center">
												<span class="md:hidden text-outline">Số lượng lẻ:</span>
												<?php echo nexxi_kids_render_cart_qty_stepper( $cart_item_key, $product, $qty_left, true, $stock_issue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</div>
											<div class="w-full md:col-span-2 flex justify-between md:justify-end items-center gap-space-sm"><span class="font-title-md text-title-md text-secondary font-bold"><?php echo esc_html( nexxi_kids_money( $litem['line_subtotal'] ) ); ?></span><a class="nexxi-cart-remove-btn w-8 h-8 rounded-full hover:bg-error-container text-outline hover:text-error flex items-center justify-center transition-colors" href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>" data-nexxi-remove-key="<?php echo esc_attr( $cart_item_key ); ?>" data-nexxi-remove-qty="<?php echo (int) $qty_left; ?>" aria-label="Xóa <?php echo esc_attr( $product->get_name() ); ?> khỏi giỏ"><span class="material-symbols-outlined text-[18px]">delete_outline</span></a></div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>
					</div>
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
					$eff_row = function_exists( 'nexxi_kids_get_cart_item_effective_prices' ) ? nexxi_kids_get_cart_item_effective_prices( $cart_item ) : array( 'regular_price' => (float) $product->get_regular_price(), 'effective_price' => (float) $product->get_price(), 'on_sale' => false );
					$stock_issue   = nexxi_kids_get_cart_item_stock_issue( $cart_item, $cart );
					$row_alert_css = $stock_issue ? 'border:1.5px solid #ef4444;background:#fff5f5;' : '';
					?>
					<div class="nexxi-cart-item-row bg-surface-container-low/40 hover:bg-surface-container-low p-space-md rounded-DEFAULT transition-all flex flex-col md:grid md:grid-cols-12 gap-space-md items-center" data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"<?php echo $row_alert_css ? ' style="' . esc_attr( $row_alert_css ) . '"' : ''; ?>>
						<div class="w-full md:col-span-6 flex items-center gap-space-md"><a class="w-20 h-24 rounded-DEFAULT bg-secondary-fixed/30 overflow-hidden shrink-0" href="<?php echo esc_url( $permalink ); ?>"><?php echo wp_kses_post( $image ); ?></a><div class="flex flex-col gap-1 min-w-0"><span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider"><?php echo esc_html( $category ); ?></span><a class="font-headline-sm text-title-md text-primary hover:text-secondary" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a><?php echo nexxi_kids_cart_item_chips( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo nexxi_kids_render_cart_item_stock_badge( $stock_issue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></div>
						<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center font-label-md text-label-md text-on-surface">
							<span class="md:hidden text-outline">Đơn giá:</span>
							<div class="text-right md:text-center">
								<?php if ( ! empty( $eff_row['on_sale'] ) ) : ?>
									<div class="text-xs text-outline line-through"><?php echo esc_html( nexxi_kids_money( $eff_row['regular_price'] ) ); ?></div>
								<?php endif; ?>
								<div class="font-bold text-primary"><?php echo esc_html( nexxi_kids_money( $eff_row['effective_price'] ) ); ?></div>
							</div>
						</div>
						<div class="w-full md:col-span-2 flex justify-between md:justify-center items-center">
							<span class="md:hidden text-outline">Số lượng:</span>
							<?php echo nexxi_kids_render_cart_qty_stepper( $cart_item_key, $product, $quantity, false, $stock_issue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<div class="w-full md:col-span-2 flex justify-between md:justify-end items-center gap-space-sm"><span class="font-title-md text-title-md text-secondary font-bold"><?php echo esc_html( nexxi_kids_money( $eff_row['effective_price'] * (int) $quantity ) ); ?></span><a class="nexxi-cart-remove-btn w-8 h-8 rounded-full hover:bg-error-container text-outline hover:text-error flex items-center justify-center transition-colors" href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>" data-nexxi-remove-key="<?php echo esc_attr( $cart_item_key ); ?>" data-nexxi-remove-qty="<?php echo (int) $quantity; ?>" aria-label="Xóa <?php echo esc_attr( $product->get_name() ); ?> khỏi giỏ"><span class="material-symbols-outlined text-[18px]">delete_outline</span></a></div>
					</div>
				<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<style>
				.nexxi-cart-qty::-webkit-outer-spin-button,
				.nexxi-cart-qty::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
				.nexxi-cart-qty[type=number] { -moz-appearance: textfield; appearance: textfield; }
				.nexxi-qty-step-btn:not([disabled]):hover { filter: brightness(0.95); transform: scale(1.04); }
				.nexxi-qty-step-btn:not([disabled]):active { transform: scale(0.96); }
				#nexxi-stitch-cart-main.is-updating-cart .nexxi-stitch-cart-totals { opacity: 0.65; transition: opacity .15s ease; }
				</style>
				<script>
				window._nexxiPendingCartQty = window._nexxiPendingCartQty || {};
				window._nexxiCartInFlight = Boolean(window._nexxiCartInFlight);
				window._nexxiCartDirty = Boolean(window._nexxiCartDirty);

				window.nexxiSyncSplitCartQty = function(cartKey) {
					var inputs = document.querySelectorAll('.nexxi-split-qty[data-cart-key="' + cartKey + '"]');
					var sum = 0;
					inputs.forEach(function(inp) { sum += Math.max(0, parseInt(inp.value || '0', 10) || 0); });
					var master = document.getElementById('nx-master-qty-' + cartKey);
					if (master) master.value = sum;
					return sum;
				};

				window.nexxiSetPendingCartQty = function(cartKey, masterQty) {
					if (!cartKey) return;
					window._nexxiPendingCartQty = window._nexxiPendingCartQty || {};
					window._nexxiPendingCartQty[cartKey] = Math.max(0, parseInt(masterQty, 10) || 0);
					if (window._nexxiCartInFlight) {
						window._nexxiCartDirty = true;
					}
				};

				window.nexxiComputeLiveCartCount = function() {
					var masters = document.querySelectorAll('#nexxi-cart-master-qtys input[type="hidden"]');
					var total = 0;
					if (masters && masters.length > 0) {
						masters.forEach(function(m) {
							var id = m.getAttribute('id') || '';
							var k = id.indexOf('nx-master-qty-') === 0 ? id.slice(14) : '';
							if (k && window._nexxiPendingCartQty && Object.prototype.hasOwnProperty.call(window._nexxiPendingCartQty, k)) {
								total += Math.max(0, parseInt(window._nexxiPendingCartQty[k], 10) || 0);
							} else {
								total += Math.max(0, parseInt(m.value || '0', 10) || 0);
							}
						});
						return total;
					}
					var inputs = document.querySelectorAll('.woocommerce-cart-form .nexxi-cart-qty');
					inputs.forEach(function(inp) {
						var k = inp.getAttribute('data-cart-key') || '';
						if (k && !inp.classList.contains('nexxi-split-qty') && window._nexxiPendingCartQty && Object.prototype.hasOwnProperty.call(window._nexxiPendingCartQty, k)) {
							total += Math.max(0, parseInt(window._nexxiPendingCartQty[k], 10) || 0);
						} else {
							total += Math.max(0, parseInt(inp.value || '0', 10) || 0);
						}
					});
					return total;
				};

				window.nexxiUpdateLiveCartCounts = function(explicitCount) {
					var cnt = (typeof explicitCount === 'number') ? Math.max(0, explicitCount) : window.nexxiComputeLiveCartCount();
					document.querySelectorAll('[data-nexxi-cart-page-count]').forEach(function(el) {
						el.textContent = cnt + ' món tuyển chọn';
					});
					document.querySelectorAll('[data-nexxi-cart-all-count]').forEach(function(el) {
						el.textContent = 'Tất cả sản phẩm (' + cnt + ')';
					});
					document.querySelectorAll('[data-nexxi-cart-count]').forEach(function(el) {
						el.textContent = String(cnt);
					});
				};

				window.nexxiCollapseCartEl = function(el) {
					if (!el || el.classList.contains('is-removing')) return;
					el.classList.add('is-removing');
					var h = el.offsetHeight;
					el.style.pointerEvents = 'none';
					el.style.maxHeight = h + 'px';
					el.style.overflow = 'hidden';
					void el.offsetHeight;
					el.style.transition = 'opacity 160ms ease, transform 160ms ease, max-height 180ms ease, padding 180ms ease, margin 180ms ease, border-width 180ms ease';
					el.style.opacity = '0';
					el.style.transform = 'translateX(12px)';
					el.style.maxHeight = '0px';
					el.style.paddingTop = '0px';
					el.style.paddingBottom = '0px';
					el.style.marginTop = '0px';
					el.style.marginBottom = '0px';
					el.style.borderWidth = '0px';
					setTimeout(function() {
						if (el && el.parentNode) { el.style.display = 'none'; }
					}, 190);
				};

				window.nexxiCollapseCartRowAndEmptyFrame = function(rowEl) {
					if (!rowEl) return;
					window.nexxiCollapseCartEl(rowEl);
					var frame = rowEl.closest ? rowEl.closest('.nexxi-combo-frame, .nexxi-leftover-frame') : null;
					if (frame && !frame.classList.contains('is-removing')) {
						var aliveRows = frame.querySelectorAll('.nexxi-cart-item-row:not(.is-removing)');
						if (!aliveRows || aliveRows.length === 0) {
							window.nexxiCollapseCartEl(frame);
						}
					}
				};

				window.nexxiQueueCartSync = function(delayMs) {
					var mainEl = document.getElementById('nexxi-stitch-cart-main');
					if (mainEl) mainEl.classList.add('is-updating-cart');
					clearTimeout(window._nexxiCartAutoUpdateTimer);
					window._nexxiCartAutoUpdateTimer = setTimeout(function() {
						window._nexxiCartAutoUpdateTimer = null;
						if (window.nexxiAjaxUpdateCart) window.nexxiAjaxUpdateCart();
					}, typeof delayMs === 'number' ? delayMs : 250);
				};

				window.nexxiAjaxUpdateCart = function() {
					var form = document.querySelector('.woocommerce-cart-form');
					var mainEl = document.getElementById('nexxi-stitch-cart-main');
					if (!form || !mainEl) {
						window._nexxiCartInFlight = false;
						window._nexxiCartDirty = false;
						return;
					}
					if (window._nexxiCartInFlight) {
						window._nexxiCartDirty = true;
						return;
					}
					window._nexxiCartInFlight = true;
					window._nexxiCartDirty = false;
					mainEl.classList.add('is-updating-cart');

					var fd = new FormData(form);
					if (window._nexxiPendingCartQty) {
						Object.keys(window._nexxiPendingCartQty).forEach(function(k) {
							fd.set('cart[' + k + '][qty]', String(window._nexxiPendingCartQty[k]));
						});
					}
					if (window._nexxiLatestCartNonce) {
						fd.set('woocommerce-cart-nonce', window._nexxiLatestCartNonce);
					}
					fd.set('update_cart', 'Cập nhật giỏ hàng');

					fetch(form.getAttribute('action') || window.location.href, {
						method: 'POST',
						credentials: 'same-origin',
						body: fd
					})
					.then(function(res) {
						if (!res.ok) throw new Error('HTTP ' + res.status);
						return res.text();
					})
					.then(function(html) {
						var doc = new DOMParser().parseFromString(html, 'text/html');
						var freshNonce = doc.querySelector('input[name="woocommerce-cart-nonce"]');
						if (freshNonce && freshNonce.value) {
							window._nexxiLatestCartNonce = freshNonce.value;
							var curNonce = document.querySelector('.woocommerce-cart-form input[name="woocommerce-cart-nonce"]');
							if (curNonce) curNonce.value = freshNonce.value;
						}

						if (window._nexxiCartDirty) {
							window._nexxiCartInFlight = false;
							window.nexxiQueueCartSync(60);
							return;
						}

						window._nexxiPendingCartQty = {};
						window._nexxiCartInFlight = false;

						// Strip only success ".woocommerce-message", preserve ".woocommerce-error" and ".woocommerce-info"
						doc.querySelectorAll('.woocommerce-message').forEach(function(n) { n.remove(); });
						doc.querySelectorAll('.woocommerce-notices-wrapper').forEach(function(w) {
							if (!w.querySelector('.woocommerce-error, .woocommerce-info') && !w.textContent.trim()) {
								w.remove();
							}
						});
						var newMain = doc.getElementById('nexxi-stitch-cart-main') || doc.querySelector('main');
						if (newMain && mainEl) {
							mainEl.innerHTML = newMain.innerHTML;
						}
						// Sync header & mobile drawer cart count badge (including empty-cart 0 state)
						var newBadge = doc.querySelector('[data-nexxi-cart-count]');
						var finalCnt = newBadge ? newBadge.textContent.trim() : (doc.querySelector('.woocommerce-cart-form') ? null : '0');
						if (finalCnt !== null) {
							document.querySelectorAll('[data-nexxi-cart-count]').forEach(function(b) { b.textContent = finalCnt; });
						}
						mainEl.classList.remove('is-updating-cart');
						if (window.jQuery) {
							window.jQuery(document.body).trigger('updated_wc_div');
						}
					})
					.catch(function() {
						window._nexxiCartInFlight = false;
						window._nexxiCartDirty = false;
						if (mainEl) mainEl.classList.remove('is-updating-cart');
					});
				};

				window.nexxiRemoveCartRow = function(btn) {
					var key = btn ? btn.getAttribute('data-nexxi-remove-key') : '';
					if (!key) return false;
					var rowEl = btn.closest ? btn.closest('.nexxi-cart-item-row') : null;
					if (rowEl && rowEl.classList.contains('is-removing')) return true;

					var attrQty = Math.max(1, parseInt(btn.getAttribute('data-nexxi-remove-qty') || '1', 10) || 1);
					var rowInput = rowEl ? rowEl.querySelector('.nexxi-cart-qty') : null;
					var removeQty = rowInput ? Math.max(0, parseInt(rowInput.value || '0', 10) || attrQty) : attrQty;

					var master = document.getElementById('nx-master-qty-' + key);
					var nextMaster = 0;
					if (master) {
						if (rowInput) {
							rowInput.value = '0';
							nextMaster = window.nexxiSyncSplitCartQty ? window.nexxiSyncSplitCartQty(key) : 0;
						} else {
							var curM = (window._nexxiPendingCartQty && Object.prototype.hasOwnProperty.call(window._nexxiPendingCartQty, key))
								? window._nexxiPendingCartQty[key]
								: (parseInt(master.value || '0', 10) || 0);
							nextMaster = Math.max(0, curM - removeQty);
							master.value = String(nextMaster);
						}
						if (nextMaster === 0) {
							document.querySelectorAll('.nexxi-cart-qty[data-cart-key="' + key + '"]').forEach(function(inp) { inp.value = '0'; });
						}
					} else {
						var directInp = rowInput || document.querySelector('.woocommerce-cart-form input[name="cart[' + key + '][qty]"]');
						var curSingle = (window._nexxiPendingCartQty && Object.prototype.hasOwnProperty.call(window._nexxiPendingCartQty, key))
							? window._nexxiPendingCartQty[key]
							: (directInp ? (parseInt(directInp.value || '0', 10) || 0) : removeQty);
						nextMaster = rowInput ? 0 : Math.max(0, curSingle - removeQty);
						if (directInp) directInp.value = String(nextMaster);
					}

					window.nexxiSetPendingCartQty(key, nextMaster);
					if (rowEl) {
						window.nexxiCollapseCartRowAndEmptyFrame(rowEl);
					}
					window.nexxiUpdateLiveCartCounts();
					window.nexxiQueueCartSync(180);
					return true;
				};

				window.nexxiStepCartQty = function(btn, delta) {
					if (btn && btn.disabled) return;
					var wrap = btn && btn.closest ? btn.closest('.nexxi-qty-stepper') : null;
					var input = wrap ? wrap.querySelector('.nexxi-cart-qty') : null;
					if (!input) return;
					var cur = parseInt(input.value || '0', 10);
					if (isNaN(cur)) cur = 0;
					var minAttr = input.getAttribute('min');
					var maxAttr = input.getAttribute('max');
					if (delta > 0 && maxAttr === '0') return;
					var min = (minAttr !== null && minAttr !== '') ? parseInt(minAttr, 10) : 0;
					var max = (maxAttr !== null && maxAttr !== '' && parseInt(maxAttr, 10) > 0) ? parseInt(maxAttr, 10) : 9999;
					var next = (delta < 0 && maxAttr === '0') ? Math.max(min, cur + delta) : Math.max(min, Math.min(max, cur + delta));
					if (next === cur) return;
					input.value = next;

					var key = input.getAttribute('data-cart-key') || '';
					var masterQty = next;
					if (key && input.classList.contains('nexxi-split-qty') && window.nexxiSyncSplitCartQty) {
						masterQty = window.nexxiSyncSplitCartQty(key);
					}
					if (key) {
						window.nexxiSetPendingCartQty(key, masterQty);
					}
					if (next === 0) {
						var rowEl = input.closest ? input.closest('.nexxi-cart-item-row') : null;
						if (rowEl) window.nexxiCollapseCartRowAndEmptyFrame(rowEl);
					}
					input.dispatchEvent(new Event('input', { bubbles: true }));
					input.dispatchEvent(new Event('change', { bubbles: true }));
					var updateBtn = document.querySelector('.woocommerce-cart-form button[name="update_cart"]');
					if (updateBtn) updateBtn.disabled = false;
					window.nexxiUpdateLiveCartCounts();
					window.nexxiQueueCartSync(320);
				};

				if (!window._nexxiCartFormSubmitBound) {
					window._nexxiCartFormSubmitBound = true;
					document.addEventListener('click', function(e) {
						var rmBtn = e.target && e.target.closest ? e.target.closest('[data-nexxi-remove-key]') : null;
						if (rmBtn && document.getElementById('nexxi-stitch-cart-main')) {
							e.preventDefault();
							if (window.nexxiRemoveCartRow) window.nexxiRemoveCartRow(rmBtn);
							return;
						}
						var updBtn = e.target && e.target.closest ? e.target.closest('.woocommerce-cart-form button[name="update_cart"]') : null;
						if (updBtn) {
							e.preventDefault();
							clearTimeout(window._nexxiCartAutoUpdateTimer);
							window._nexxiCartAutoUpdateTimer = null;
							if (window.nexxiAjaxUpdateCart) window.nexxiAjaxUpdateCart();
						}
					});
					document.addEventListener('change', function(e) {
						if (!e.isTrusted) return;
						var qtyInp = e.target && e.target.closest ? e.target.closest('.woocommerce-cart-form .nexxi-cart-qty') : null;
						if (!qtyInp) return;
						var val = Math.max(0, parseInt(qtyInp.value || '0', 10) || 0);
						qtyInp.value = val;
						var key = qtyInp.getAttribute('data-cart-key') || '';
						var masterQty = val;
						if (key && qtyInp.classList.contains('nexxi-split-qty') && window.nexxiSyncSplitCartQty) {
							masterQty = window.nexxiSyncSplitCartQty(key);
						}
						if (key) window.nexxiSetPendingCartQty(key, masterQty);
						if (val === 0) {
							var rowEl = qtyInp.closest ? qtyInp.closest('.nexxi-cart-item-row') : null;
							if (rowEl) window.nexxiCollapseCartRowAndEmptyFrame(rowEl);
						}
						window.nexxiUpdateLiveCartCounts();
						window.nexxiQueueCartSync(320);
					});
				}
				</script>
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

/**
 * Format "(Màu: ..., Size: ...)" suffix for a product variation so WooCommerce stock/cart error notices
 * clearly identify which classification is out of stock when woocommerce_product_variation_title_include_attributes is false.
 */
function nexxi_kids_variation_label_suffix( $product ) {
	if ( ! ( $product instanceof WC_Product ) || ! $product->is_type( 'variation' ) ) {
		return '';
	}
	$attrs = $product->get_variation_attributes();
	if ( empty( $attrs ) || ! is_array( $attrs ) ) {
		return '';
	}
	$parts = array();
	foreach ( $attrs as $key => $value ) {
		if ( '' === (string) $value ) {
			continue;
		}
		$name = 0 === strpos( $key, 'attribute_' ) ? substr( $key, 10 ) : $key;
		if ( taxonomy_exists( $name ) ) {
			$term  = get_term_by( 'slug', $value, $name );
			$value = ( $term && ! is_wp_error( $term ) ) ? $term->name : $value;
		}
		$label = wc_attribute_label( $name, $product );
		if ( function_exists( 'nexxi_kids_attr_label' ) ) {
			$label = nexxi_kids_attr_label( $label );
		}
		$parts[] = $label . ': ' . $value;
	}
	return ! empty( $parts ) ? ' (' . implode( ', ', $parts ) . ')' : '';
}

/**
 * Full product title with variation classification suffix "(Màu: ..., Size: ...)" for error messages.
 */
function nexxi_kids_product_name_with_variation( $product ) {
	if ( ! ( $product instanceof WC_Product ) ) {
		return '';
	}
	if ( ! $product->is_type( 'variation' ) ) {
		return $product->get_name();
	}
	$parent = wc_get_product( $product->get_parent_id() );
	$base   = $parent ? $parent->get_name() : $product->get_name();
	$suffix = nexxi_kids_variation_label_suffix( $product );
	if ( '' !== $suffix && false === strpos( $base, $suffix ) ) {
		return $base . $suffix;
	}
	return $base;
}

add_action(
	'woocommerce_check_cart_items',
	static function () {
		$GLOBALS['nexxi_in_check_cart_items'] = true;
	},
	1
);

add_action(
	'woocommerce_check_cart_items',
	static function () {
		$GLOBALS['nexxi_in_check_cart_items'] = false;
	},
	999
);

add_filter(
	'woocommerce_product_variation_get_name',
	static function ( $name, $product ) {
		if ( ! empty( $GLOBALS['nexxi_in_check_cart_items'] ) && $product instanceof WC_Product ) {
			return nexxi_kids_product_name_with_variation( $product );
		}
		return $name;
	},
	20,
	2
);

/**
 * Inject variation classification "(Màu: ..., Size: ...)" into add-to-cart out-of-stock / insufficient-stock messages.
 */
function nexxi_kids_enrich_variation_stock_error_msg( $message, $product_data ) {
	if ( ! ( $product_data instanceof WC_Product ) || ! $product_data->is_type( 'variation' ) ) {
		return $message;
	}
	$plain_name = $product_data->get_name();
	$full_name  = nexxi_kids_product_name_with_variation( $product_data );
	if ( '' !== $plain_name && $full_name !== $plain_name && false === strpos( (string) $message, $full_name ) ) {
		$message = str_replace( $plain_name, $full_name, (string) $message );
	}
	return $message;
}
add_filter( 'woocommerce_cart_product_out_of_stock_message', 'nexxi_kids_enrich_variation_stock_error_msg', 20, 2 );
add_filter( 'woocommerce_cart_product_not_enough_stock_message', 'nexxi_kids_enrich_variation_stock_error_msg', 20, 2 );
add_filter( 'woocommerce_cart_product_cannot_add_another_message', 'nexxi_kids_enrich_variation_stock_error_msg', 20, 2 );
