<?php
/**
 * Review order table — NEXXI Kids Compact Combo & Leftover Summary
 *
 * Keeps `WC()->cart` line items, Combo fee calculation (`nexxi_kids_apply_combo_promo_cart_fee`),
 * `WC_Order` creation, Pancake POS sync, and Meta Pixel tracking 100% intact while eliminating
 * duplicate product listings when items already belong to a Combo frame:
 *
 * - Case 1 (All items fit inside Combo frames): hides top individual product rows, `Tạm tính`,
 *   and the duplicate standalone Combo fee row; starts directly at `COMBO KHUYẾN MÃI` -> `Tổng`.
 * - Case 2 (Combo frames + Leftover single items): renders ONLY leftover items under
 *   `Sản phẩm lẻ | Tạm tính` (without `Ưu đãi Combo` chips), followed by `COMBO KHUYẾN MÃI` -> `Tổng`.
 * - Case 3 (No Combo achieved, e.g. 1 item): standard WooCommerce product rows + upsell hint -> `Tổng`.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 11.4.0
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['nexxi_combo_checkout_row_rendered'] = false;

$nexxi_combo_res      = function_exists( 'nexxi_kids_calculate_cart_combos' ) ? nexxi_kids_calculate_cart_combos( WC()->cart ) : array();
$nexxi_has_combos     = ! empty( $nexxi_combo_res['enabled'] ) && ! empty( $nexxi_combo_res['combos'] );
$nexxi_leftover_items = ( $nexxi_has_combos && ! empty( $nexxi_combo_res['leftover_items'] ) && is_array( $nexxi_combo_res['leftover_items'] ) )
	? $nexxi_combo_res['leftover_items']
	: array();
$nexxi_is_combo_only  = $nexxi_has_combos && empty( $nexxi_leftover_items );
$nexxi_is_combo_mixed = $nexxi_has_combos && ! empty( $nexxi_leftover_items );

$nexxi_visible_fees = array();
foreach ( WC()->cart->get_fees() as $nexxi_fee ) {
	if ( $nexxi_has_combos && function_exists( 'nexxi_kids_is_combo_promo_fee' ) && nexxi_kids_is_combo_promo_fee( $nexxi_fee ) ) {
		continue;
	}
	$nexxi_visible_fees[] = $nexxi_fee;
}

$nexxi_has_coupons     = ! empty( WC()->cart->get_coupons() );
$nexxi_has_shipping    = WC()->cart->needs_shipping() && WC()->cart->show_shipping();
$nexxi_has_taxes       = wc_tax_enabled() && ! WC()->cart->display_prices_including_tax();
$nexxi_has_adjustments = $nexxi_has_coupons || $nexxi_has_shipping || ! empty( $nexxi_visible_fees ) || $nexxi_has_taxes;

$nexxi_table_classes = 'shop_table woocommerce-checkout-review-order-table';
if ( $nexxi_is_combo_only ) {
	$nexxi_table_classes .= ' nexxi-review-combo-only';
} elseif ( $nexxi_is_combo_mixed ) {
	$nexxi_table_classes .= ' nexxi-review-combo-mixed';
}
?>
<table class="<?php echo esc_attr( $nexxi_table_classes ); ?>">
	<colgroup>
		<col class="nexxi-col-product" style="width:68%;">
		<col class="nexxi-col-total" style="width:32%;">
	</colgroup>

	<?php if ( ! $nexxi_is_combo_only ) : ?>
		<thead>
			<tr>
				<th class="product-name"><?php echo $nexxi_is_combo_mixed ? esc_html__( 'Sản phẩm lẻ', 'nexxi-kids' ) : esc_html__( 'Product', 'woocommerce' ); ?></th>
				<th class="product-total"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
			</tr>
		</thead>
	<?php endif; ?>

	<?php if ( $nexxi_is_combo_only ) : ?>
		<?php
		ob_start();
		do_action( 'woocommerce_review_order_before_cart_contents' );
		do_action( 'woocommerce_review_order_after_cart_contents' );
		$nexxi_extra_tbody = trim( (string) ob_get_clean() );
		if ( '' !== $nexxi_extra_tbody ) :
			?>
			<tbody><?php echo $nexxi_extra_tbody; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></tbody>
		<?php endif; ?>
	<?php elseif ( $nexxi_is_combo_mixed ) : ?>
		<tbody>
			<?php
			do_action( 'woocommerce_review_order_before_cart_contents' );

			foreach ( $nexxi_leftover_items as $nexxi_litem ) {
				$cart_item_key = isset( $nexxi_litem['cart_item_key'] ) ? (string) $nexxi_litem['cart_item_key'] : '';
				$cart_item     = isset( $nexxi_litem['cart_item'] ) && is_array( $nexxi_litem['cart_item'] ) ? $nexxi_litem['cart_item'] : array();
				$qty_leftover  = isset( $nexxi_litem['qty_leftover'] ) ? (int) $nexxi_litem['qty_leftover'] : 0;
				$_product      = isset( $nexxi_litem['product'] ) ? $nexxi_litem['product'] : ( isset( $cart_item['data'] ) ? $cart_item['data'] : null );
				$_product      = apply_filters( 'woocommerce_cart_item_product', $_product, $cart_item, $cart_item_key );
				$visible       = apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key );

				if ( $_product instanceof WC_Product && $_product->exists() && $qty_leftover > 0 && $visible ) {
					$leftover_cart_item                           = $cart_item;
					$leftover_cart_item['quantity']               = $qty_leftover;
					$leftover_cart_item['nexxi_skip_combo_badge'] = true;

					$cart_item_name = method_exists( WC()->cart, 'get_item_product_name' )
						? WC()->cart->get_item_product_name( $leftover_cart_item, $_product )
						: $_product->get_name();
					$product_name   = apply_filters( 'woocommerce_cart_item_name', $cart_item_name, $leftover_cart_item, $cart_item_key );
					?>
					<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item nexxi-leftover-cart-item', $leftover_cart_item, $cart_item_key ) ); ?>">
						<td class="product-name">
							<?php echo wp_kses_post( $product_name ) . '&nbsp;'; ?>
							<?php echo apply_filters( 'woocommerce_checkout_cart_item_quantity', ' <strong class="product-quantity">' . sprintf( '&times;&nbsp;%s', esc_html( $qty_leftover ) ) . '</strong>', $leftover_cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo wc_get_formatted_cart_item_data( $leftover_cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php do_action( 'woocommerce_checkout_after_cart_item_meta', $leftover_cart_item, $cart_item_key ); ?>
						</td>
						<td class="product-total">
							<?php echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $qty_leftover ), $leftover_cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
					</tr>
					<?php
				}
			}

			do_action( 'woocommerce_review_order_after_cart_contents' );
			?>
		</tbody>
	<?php else : ?>
		<tbody>
			<?php
			do_action( 'woocommerce_review_order_before_cart_contents' );

			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
				$visible  = apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key );

				if ( $_product instanceof WC_Product && $_product->exists() && $cart_item['quantity'] > 0 && $visible ) {
					$cart_item_name = method_exists( WC()->cart, 'get_item_product_name' )
						? WC()->cart->get_item_product_name( $cart_item, $_product )
						: $_product->get_name();
					$product_name   = apply_filters( 'woocommerce_cart_item_name', $cart_item_name, $cart_item, $cart_item_key );
					?>
					<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">
						<td class="product-name">
							<?php echo wp_kses_post( $product_name ) . '&nbsp;'; ?>
							<?php echo apply_filters( 'woocommerce_checkout_cart_item_quantity', ' <strong class="product-quantity">' . sprintf( '&times;&nbsp;%s', esc_html( $cart_item['quantity'] ) ) . '</strong>', $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php do_action( 'woocommerce_checkout_after_cart_item_meta', $cart_item, $cart_item_key ); ?>
						</td>
						<td class="product-total">
							<?php echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
					</tr>
					<?php
				}
			}

			do_action( 'woocommerce_review_order_after_cart_contents' );
			?>
		</tbody>
	<?php endif; ?>

	<tfoot>

		<?php
		if ( $nexxi_has_combos && function_exists( 'nexxi_kids_output_checkout_combo_row' ) ) {
			nexxi_kids_output_checkout_combo_row( $nexxi_is_combo_only && '' === ( $nexxi_extra_tbody ?? '' ) );
		}
		?>

		<?php if ( ! $nexxi_has_combos && $nexxi_has_adjustments ) : ?>
			<tr class="cart-subtotal">
				<th><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
				<td><?php wc_cart_totals_subtotal_html(); ?></td>
			</tr>
		<?php endif; ?>

		<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
			<tr class="cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
				<th><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
				<td><?php wc_cart_totals_coupon_html( $coupon ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( $nexxi_has_shipping ) : ?>

			<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>

			<?php wc_cart_totals_shipping_html(); ?>

			<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>

		<?php endif; ?>

		<?php foreach ( $nexxi_visible_fees as $fee ) : ?>
			<tr class="fee">
				<th><?php echo esc_html( $fee->name ); ?></th>
				<td><?php wc_cart_totals_fee_html( $fee ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( $nexxi_has_taxes ) : ?>
			<?php if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) : ?>
				<?php foreach ( WC()->cart->get_tax_totals() as $code => $tax ) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited ?>
					<tr class="tax-rate tax-rate-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
						<th><?php echo esc_html( $tax->label ); ?></th>
						<td><?php echo wp_kses_post( $tax->formatted_amount ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr class="tax-total">
					<th><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
					<td><?php wc_cart_totals_taxes_total_html(); ?></td>
				</tr>
			<?php endif; ?>
		<?php endif; ?>

		<?php do_action( 'woocommerce_review_order_before_order_total' ); ?>

		<tr class="order-total">
			<th><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
			<td><?php wc_cart_totals_order_total_html(); ?></td>
		</tr>

		<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>

	</tfoot>
</table>
<?php
$GLOBALS['nexxi_combo_checkout_row_rendered'] = false;
