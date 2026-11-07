<?php
/**
 * Cart errors page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/cart-errors.php.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 3.5.0
 */

defined( 'ABSPATH' ) || exit;

$nexxi_problem_items = array();
if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() && function_exists( 'nexxi_kids_get_cart_item_stock_issue' ) ) {
	$nexxi_cart = WC()->cart;
	foreach ( $nexxi_cart->get_cart() as $nexxi_ckey => $nexxi_citem ) {
		$nexxi_issue = nexxi_kids_get_cart_item_stock_issue( $nexxi_citem, $nexxi_cart );
		if ( ! empty( $nexxi_issue ) ) {
			$nexxi_problem_items[ $nexxi_ckey ] = array(
				'cart_item' => $nexxi_citem,
				'product'   => isset( $nexxi_citem['data'] ) ? $nexxi_citem['data'] : null,
				'issue'     => $nexxi_issue,
			);
		}
	}
}
?>

<div class="nexxi-cart-errors-wrap">
	<?php wc_print_notices(); ?>

	<?php if ( ! empty( $nexxi_problem_items ) ) : ?>
		<div class="nexxi-cart-errors-list">
			<div class="nexxi-cart-errors-list__title">
				Sản phẩm cần kiểm tra lại trong giỏ hàng (<?php echo count( $nexxi_problem_items ); ?>):
			</div>
			<?php foreach ( $nexxi_problem_items as $nexxi_ckey => $nexxi_row ) :
				$nexxi_citem     = $nexxi_row['cart_item'];
				$nexxi_prod      = $nexxi_row['product'];
				$nexxi_issue     = $nexxi_row['issue'];
				$nexxi_title     = function_exists( 'nexxi_kids_cart_item_title' ) ? nexxi_kids_cart_item_title( $nexxi_citem ) : ( $nexxi_prod ? $nexxi_prod->get_name() : '' );
				$nexxi_permalink = ( $nexxi_prod && $nexxi_prod->exists() && $nexxi_prod->is_visible() ) ? $nexxi_prod->get_permalink( $nexxi_citem ) : '';
				$nexxi_thumb     = ( $nexxi_prod && $nexxi_prod->exists() ) ? $nexxi_prod->get_image( 'woocommerce_thumbnail', array( 'class' => 'nexxi-cart-errors-item__img', 'loading' => 'lazy' ) ) : '';
				?>
				<div class="nexxi-cart-errors-item">
					<?php if ( $nexxi_thumb ) : ?>
						<a class="nexxi-cart-errors-item__media" href="<?php echo esc_url( $nexxi_permalink ? $nexxi_permalink : wc_get_cart_url() ); ?>">
							<?php echo wp_kses_post( $nexxi_thumb ); ?>
						</a>
					<?php endif; ?>
					<div class="nexxi-cart-errors-item__body">
						<?php if ( $nexxi_permalink ) : ?>
							<a class="nexxi-cart-errors-item__name" href="<?php echo esc_url( $nexxi_permalink ); ?>"><?php echo esc_html( $nexxi_title ); ?></a>
						<?php else : ?>
							<span class="nexxi-cart-errors-item__name"><?php echo esc_html( $nexxi_title ); ?></span>
						<?php endif; ?>
						<?php
						if ( function_exists( 'nexxi_kids_cart_item_chips' ) ) {
							echo nexxi_kids_cart_item_chips( $nexxi_citem ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						if ( function_exists( 'nexxi_kids_render_cart_item_stock_badge' ) ) {
							echo nexxi_kids_render_cart_item_stock_badge( $nexxi_issue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</div>
					<div class="nexxi-cart-errors-item__actions">
						<?php if ( $nexxi_permalink ) : ?>
							<a class="nexxi-cart-errors-item__change" href="<?php echo esc_url( $nexxi_permalink ); ?>">Chọn size khác</a>
						<?php endif; ?>
						<a class="nexxi-cart-errors-item__remove" href="<?php echo esc_url( wc_get_cart_remove_url( $nexxi_ckey ) ); ?>">🗑️ Xóa khỏi giỏ</a>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<p class="nexxi-cart-errors-desc"><?php esc_html_e( 'There are some issues with the items in your cart. Please go back to the cart page and resolve these issues before checking out.', 'woocommerce' ); ?></p>

	<?php do_action( 'woocommerce_cart_has_errors' ); ?>

	<p class="nexxi-cart-errors-back"><a class="button wc-backward" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'Return to cart', 'woocommerce' ); ?></a></p>
</div>
