<?php
/**
 * Plugin Name: NEXXI Kids Commerce
 * Description: Size recommendations, Pancake POS SKU & Variation Image Manager, and NEXXI Kids service details for WooCommerce.
 * Version: 1.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: nexxi-kids-commerce
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nexxi_Kids_Commerce {
	const VERSION = '1.0.0';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_shortcode( 'nexxi_size_guide', array( $this, 'size_guide' ) );
		add_action( 'woocommerce_single_product_summary', array( $this, 'product_service_note' ), 35 );
		add_action( 'woocommerce_before_cart', array( $this, 'cart_shipping_progress' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'checkout_service_note' ) );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'cart_size' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_size' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_size' ), 10, 4 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'buy_now_redirect' ) );
		add_filter( 'gettext', array( $this, 'shipping_label' ), 10, 3 );
	}

	public function cart_size( $cart_item_data, $product_id ) {
		if ( isset( $_REQUEST['nexxi_size'] ) ) {
			$size = absint( wp_unslash( $_REQUEST['nexxi_size'] ) );
			if ( $size >= 1 && $size <= 16 ) { $cart_item_data['nexxi_size'] = $size; }
		}
		if ( isset( $_REQUEST['nexxi_color'] ) ) {
			$color = sanitize_text_field( wp_unslash( $_REQUEST['nexxi_color'] ) );
			if ( '' !== $color ) { $cart_item_data['nexxi_color'] = mb_substr( $color, 0, 50 ); }
		}
		return $cart_item_data;
	}

	public function display_cart_size( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['nexxi_size'] ) ) {
			$item_data[] = array( 'key' => __( 'Size', 'nexxi-kids-commerce' ), 'value' => (string) absint( $cart_item['nexxi_size'] ) );
		}
		if ( ! empty( $cart_item['nexxi_color'] ) ) { $item_data[] = array( 'key' => __( 'Màu', 'nexxi-kids-commerce' ), 'value' => $cart_item['nexxi_color'] ); }
		return $item_data;
	}

	public function order_size( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['nexxi_size'] ) ) { $item->add_meta_data( __( 'Size', 'nexxi-kids-commerce' ), absint( $values['nexxi_size'] ), true ); }
		if ( ! empty( $values['nexxi_color'] ) ) { $item->add_meta_data( __( 'Màu', 'nexxi-kids-commerce' ), $values['nexxi_color'], true ); }
	}

	public function buy_now_redirect( $url ) {
		if ( isset( $_REQUEST['nexxi_buy_now'] ) && '1' === sanitize_text_field( wp_unslash( $_REQUEST['nexxi_buy_now'] ) ) ) {
			return wc_get_checkout_url();
		}
		return $url;
	}

	public function shipping_label( $translated, $text, $domain ) {
		return 'Shipment' === $text || 'Shipment' === $translated ? 'Giao hàng' : $translated;
	}

	public function assets() {
		wp_enqueue_style( 'nexxi-kids-commerce', plugin_dir_url( __FILE__ ) . 'assets/css/commerce.css', array(), self::VERSION );
		wp_enqueue_script( 'nexxi-kids-commerce', plugin_dir_url( __FILE__ ) . 'assets/js/size-guide.js', array(), self::VERSION, true );
		wp_localize_script( 'nexxi-kids-commerce', 'NexxiSizeGuide', array( 'labels' => array(
			'enter' => __( 'Bảng size áp dụng cho chiều cao 75–170 cm và cân nặng 8–55 kg.', 'nexxi-kids-commerce' ),
			'result' => __( 'Size gợi ý cho bé:', 'nexxi-kids-commerce' ),
			'hint' => __( 'Bảng size mang tính tham khảo. Nếu bé ở giữa hai size, mẹ có thể chọn size lớn hơn để bé thoải mái vận động.', 'nexxi-kids-commerce' ),
		) ) );
	}

	public function size_guide( $atts = array() ) {
		$atts = shortcode_atts( array( 'compact' => 'no' ), $atts, 'nexxi_size_guide' );
		$compact = 'yes' === $atts['compact'] ? ' nexxi-size-guide--compact' : '';
		$sizes = array(
			array( 1, 8, 10, 75 ), array( 2, 10, 12, 87 ), array( 3, 12, 14, 96 ), array( 4, 14, 16, 104 ),
			array( 5, 16, 18, 110 ), array( 6, 18, 20, 116 ), array( 7, 20, 22, 122 ), array( 8, 22, 24, 128 ),
			array( 9, 24, 27, 134 ), array( 10, 27, 30, 140 ), array( 11, 30, 33, 146 ), array( 12, 33, 36, 152 ),
			array( 13, 36, 40, 156 ), array( 14, 40, 45, 160 ), array( 15, 45, 50, 165 ), array( 16, 50, 55, 170 ),
		);
		ob_start(); ?>
		<div class="nexxi-size-guide<?php echo esc_attr( $compact ); ?>" data-nexxi-size-guide>
			<div class="nexxi-size-guide__fields">
				<label><?php esc_html_e( 'Chiều cao (cm)', 'nexxi-kids-commerce' ); ?><input type="number" min="75" max="170" step="1" inputmode="numeric" data-height placeholder="Ví dụ: 96"></label>
				<label><?php esc_html_e( 'Cân nặng (kg)', 'nexxi-kids-commerce' ); ?><input type="number" min="8" max="55" step="0.1" inputmode="decimal" data-weight placeholder="Ví dụ: 13"></label>
				<button class="nexxi-size-guide__submit" type="button" data-size-submit><?php esc_html_e( 'Gợi ý size', 'nexxi-kids-commerce' ); ?></button>
			</div>
			<div class="nexxi-size-guide__result" data-size-result aria-live="polite" hidden></div>
			<p class="nexxi-size-guide__hint"><?php esc_html_e( 'Bảng size mang tính chất tham khảo. Nếu bé nằm giữa hai size, mẹ cân nhắc chọn size lớn hơn.', 'nexxi-kids-commerce' ); ?></p>
			<div class="nexxi-size-guide__table-wrap"><table class="nexxi-size-guide__table"><thead><tr><th><?php esc_html_e( 'Size', 'nexxi-kids-commerce' ); ?></th><th><?php esc_html_e( 'Cân nặng (kg)', 'nexxi-kids-commerce' ); ?></th><th><?php esc_html_e( 'Chiều cao (cm)', 'nexxi-kids-commerce' ); ?></th></tr></thead><tbody>
			<?php foreach ( $sizes as $row ) : ?><tr><td><?php echo esc_html( (string) $row[0] ); ?></td><td><?php echo esc_html( $row[1] . '–' . $row[2] ); ?></td><td><?php echo esc_html( (string) $row[3] ); ?></td></tr><?php endforeach; ?>
			</tbody></table></div>
		</div>
		<?php return ob_get_clean();
	}

	public function product_service_note() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) { return; }
		echo '<div class="nexxi-product-perks"><span>✓ ' . esc_html__( 'Chất liệu mềm mại, thoải mái vận động', 'nexxi-kids-commerce' ) . '</span><span>↻ ' . esc_html__( 'Hỗ trợ 15 ngày đổi hàng', 'nexxi-kids-commerce' ) . '</span><span>⌂ ' . esc_html__( 'Miễn phí ship toàn quốc', 'nexxi-kids-commerce' ) . '</span></div>';
		if ( shortcode_exists( 'nexxi_size_guide' ) ) { echo '<details class="nexxi-size-details"><summary>' . esc_html__( 'Chưa biết chọn size? Xem gợi ý size', 'nexxi-kids-commerce' ) . '</summary>' . $this->size_guide( array( 'compact' => 'yes' ) ) . '</details>'; }
	}

	public function cart_shipping_progress() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return; }
		$threshold = (float) apply_filters( 'nexxi_kids_free_shipping_threshold', 0 );
		$total = (float) WC()->cart->get_displayed_subtotal();
		$remaining = max( 0, $threshold - $total );
		$progress = min( 100, $threshold > 0 ? ( $total / $threshold ) * 100 : 100 );
		$message = $remaining > 0 ? sprintf( __( 'Mua thêm %s để nhận ưu đãi miễn phí ship toàn quốc.', 'nexxi-kids-commerce' ), wp_strip_all_tags( wc_price( $remaining ) ) ) : __( 'Miễn phí ship toàn quốc cho đơn hàng!', 'nexxi-kids-commerce' );
		echo '<div class="nexxi-shipping-progress"><p>' . esc_html( $message ) . '</p><div class="nexxi-shipping-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) round( $progress ) ) . '"><span style="width:' . esc_attr( (string) $progress ) . '%"></span></div></div>';
	}

	public function checkout_service_note() {
		echo '<div class="nexxi-checkout-note">' . esc_html__( 'NEXXI sẽ liên hệ xác nhận đơn hàng và hỗ trợ mẹ chọn size nếu cần.', 'nexxi-kids-commerce' ) . '</div>';
	}
}

require_once __DIR__ . '/includes/class-catalog.php';
if ( ! file_exists( get_template_directory() . '/inc/pancake-sync-admin.php' ) ) {
	require_once __DIR__ . '/includes/class-pancake-sync.php';
}

add_action( 'plugins_loaded', function() {
	if ( class_exists( 'WooCommerce' ) ) {
		new Nexxi_Kids_Commerce();
		new Nexxi_Kids_Catalog();
		if ( class_exists( 'Nexxi_Pancake_Sync_Manager', false ) ) {
			Nexxi_Pancake_Sync_Manager::init();
		}
	}
} );
